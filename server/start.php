<?php
/**
 * FPS · scirco.cn - Workerman 单端口入口
 *
 * 一个 Worker 同时处理：
 *   - HTTP      (注册/登录/房间列表/战绩/静态文件)
 *   - WebSocket (实时游戏)
 *
 * 只需放行一个端口（默认 3000），通过宝塔 Nginx 反代到 443 即可。
 *
 * 用法:
 *   php start.php start
 *   php start.php start -d   (守护)
 *   php start.php stop
 *   php start.php restart
 *   php start.php status
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Game/MatchManager.php';
require_once __DIR__ . '/src/Game/Room.php';
require_once __DIR__ . '/src/Game/Player.php';
require_once __DIR__ . '/src/Game/Game.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

$cfg = require __DIR__ . '/src/config.php';

// ============================================================
// 单 Worker 同时支持 HTTP + WebSocket
// ============================================================
$wsWorker = new Worker('http://0.0.0.0:' . $cfg['PORT']);
$wsWorker->name = 'FPS-Server';
$wsWorker->count = 1;

// WebSocket 握手时校验 token
$wsWorker->onWebSocketConnect = function (TcpConnection $conn, $req) use ($cfg) {
    $token = null;
    // 优先从 query string
    $qs = $req->uri()->getQuery();
    if ($qs) {
        parse_str($qs, $q);
        $token = $q['token'] ?? null;
    }
    // 备选：从 Sec-WebSocket-Protocol 头
    if (!$token) $token = $req->header('sec-websocket-protocol');
    // 备选：Authorization
    if (!$token) {
        $auth = $req->header('authorization');
        if ($auth && stripos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if (!$token) { echo "[WS] rejected: no token\n"; $conn->close(); return; }
    $payload = Auth::verify($token, $cfg['JWT_SECRET']);
    if (!$payload) { echo "[WS] rejected: bad token\n"; $conn->close(); return; }
    $conn->user = ['id'=>(int)$payload['id'], 'username'=>$payload['username']];
};

$wsWorker->onMessage = function (TcpConnection $conn, $data) use ($cfg) {
    // HTTP 请求（普通 fetch）
    if ($data instanceof Request) {
        handleHttp($conn, $data, $cfg);
        return;
    }
    // WebSocket 消息
    if (!isset($conn->user)) { $conn->close(); return; }
    $msg = json_decode($data, true);
    if (!is_array($msg) || !isset($msg['type'])) return;
    $user = $conn->user;
    $mm = MatchManager::getInstance();
    handleWs($conn, $msg, $user, $mm, $cfg);
};

$wsWorker->onClose = function (TcpConnection $conn) {
    if (isset($conn->room) && isset($conn->user)) {
        $conn->room->removePlayer($conn->user['id']);
    }
};

function handleHttp(TcpConnection $conn, Request $req, array $cfg): void
{
    $path = $req->path();
    $method = $req->method();
    $secret = $cfg['JWT_SECRET'];

    $cors = [
        'Access-Control-Allow-Origin' => $cfg['CORS_ORIGIN'],
        'Access-Control-Allow-Methods' => 'GET,POST,OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
    ];
    if ($method === 'OPTIONS') { $conn->send(new Response(204, $cors, '')); return; }

    $json = fn(int $code, array $data) => new Response($code, array_merge(['Content-Type'=>'application/json; charset=utf-8'], $cors), json_encode($data, JSON_UNESCAPED_UNICODE));

    if (strpos($path, '/api/') === 0) {
        $body = $req->post() ?: [];
        if (empty($body) && in_array($method, ['POST','PUT'])) {
            $raw = $req->rawBody();
            if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded)) $body = $decoded; }
        }
        $resp = handleApi($method, $path, $body, $secret, $cfg);
        $conn->send($resp); return;
    }
    if ($path === '/health') { $conn->send($json(200, ['ok'=>true, 'at'=>time()*1000, 'version'=>'2.0.0'])); return; }

    // 静态文件 / SPA fallback
    $staticDir = $cfg['STATIC_DIR'];
    if (is_dir($staticDir)) {
        $target = $path === '/' ? '/index.html' : $path;
        $file = realpath($staticDir . $target);
        if ($file && strpos($file, realpath($staticDir)) === 0 && is_file($file)) {
            $mime = mime_content_type($file) ?: 'application/octet-stream';
            $conn->send(new Response(200, ['Content-Type'=>$mime], file_get_contents($file))); return;
        }
        $idx = $staticDir . '/index.html';
        if (is_file($idx)) { $conn->send(new Response(200, ['Content-Type'=>'text/html'], file_get_contents($idx))); return; }
    }
    $conn->send(new Response(404, ['Content-Type'=>'text/plain; charset=utf-8'], 'Server running. Deploy client/dist/ first.'));
}

function handleApi(string $method, string $path, array $body, string $secret, array $cfg): Response
{
    $json = fn(int $code, array $data) => new Response($code, ['Content-Type'=>'application/json; charset=utf-8'], json_encode($data, JSON_UNESCAPED_UNICODE));

    if ($path === '/api/auth/register' && $method === 'POST') {
        $u = trim($body['username'] ?? '');
        $p = $body['password'] ?? '';
        if (strlen($u) < 3 || strlen($p) < 6) return $json(400, ['error'=>'用户名至少 3 位，密码至少 6 位']);
        if (Database::findPlayerByUsername($u)) return $json(409, ['error'=>'用户名已存在']);
        $id = Database::createPlayer($u, Auth::hashPassword($p));
        $token = Auth::sign(['id'=>$id, 'username'=>$u], $secret, $cfg['JWT_EXPIRES']);
        return $json(200, ['token'=>$token, 'user'=>['id'=>$id, 'username'=>$u]]);
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        $u = trim($body['username'] ?? '');
        $p = $body['password'] ?? '';
        $player = Database::findPlayerByUsername($u);
        if (!$player || !Auth::verifyPassword($p, $player['password_hash'])) return $json(401, ['error'=>'用户名或密码错误']);
        $token = Auth::sign(['id'=>(int)$player['id'], 'username'=>$player['username']], $secret, $cfg['JWT_EXPIRES']);
        return $json(200, ['token'=>$token, 'user'=>['id'=>(int)$player['id'], 'username'=>$player['username']]]);
    }
    if ($path === '/api/auth/me' && $method === 'GET') {
        $u = Auth::userFromRequest($secret);
        if (!$u) return $json(401, ['error'=>'未登录']);
        return $json(200, ['user'=>['id'=>(int)$u['id'], 'username'=>$u['username']], 'stats'=>Database::getPlayerStats((int)$u['id'])]);
    }
    if ($path === '/api/lobby/rooms' && $method === 'GET') {
        return $json(200, ['rooms'=>MatchManager::getInstance()->listPublicRooms()]);
    }
    if ($path === '/api/lobby/online' && $method === 'GET') {
        return $json(200, ['players'=>MatchManager::getInstance()->listAllOnline()]);
    }
    if ($path === '/api/lobby/rooms' && $method === 'POST') {
        $u = Auth::userFromRequest($secret);
        if (!$u) return $json(401, ['error'=>'未登录']);
        $name = trim($body['name'] ?? '我的房间');
        $max = min(32, max(2, (int)($body['maxPlayers'] ?? 16)));
        $isPrivate = !empty($body['isPrivate']);
        $room = MatchManager::getInstance()->createRoom($name, $max, $cfg);
        $room->isPrivate = $isPrivate;
        return $json(200, ['roomId'=>$room->id, 'room'=>$room->toPublic()]);
    }
    if (preg_match('#^/api/lobby/rooms/([^/]+)$#', $path, $m) && $method === 'GET') {
        $room = MatchManager::getInstance()->getRoom($m[1]);
        if (!$room) return $json(404, ['error'=>'房间不存在']);
        return $json(200, $room->toPublic());
    }
    if ($path === '/api/stats' && $method === 'GET') {
        return $json(200, ['stats'=>Database::getAllStats(50)]);
    }
    if (preg_match('#^/api/stats/(\d+)$#', $path, $m) && $method === 'GET') {
        $id = (int)$m[1];
        $stats = Database::getPlayerStats($id);
        if (!$stats) return $json(404, ['error'=>'不存在']);
        return $json(200, ['stats'=>$stats, 'weapons'=>Database::getWeaponsPref($id)]);
    }
    return $json(404, ['error'=>'Not Found']);
}

function handleWs(TcpConnection $conn, array $msg, array $user, MatchManager $mm, array $cfg): void
{
    switch ($msg['type']) {
        case 'joinRoom': {
            $rid = $msg['roomId'] ?? '';
            $rname = $msg['roomName'] ?? '默认房间';
            $room = $mm->getOrCreateRoom($rid, $rname, 16, $cfg);
            $room->addPlayer($user['id'], $user['username'], $conn);
            $conn->room = $room;
            break;
        }
        case 'setTeam': {
            if (!isset($conn->room)) return;
            $conn->room->setTeam($user['id'], ($msg['team'] ?? 'CT') === 'T' ? 'T' : 'CT');
            break;
        }
        case 'startMatch': {
            if (!isset($conn->room)) { $conn->send(json_encode(['type'=>'errorMsg', 'msg'=>'未加入房间'])); return; }
            $res = $conn->room->startMatch();
            if (!$res['ok']) $conn->send(json_encode(['type'=>'errorMsg', 'msg'=>$res['msg']]));
            break;
        }
        case 'chat': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $text = mb_substr((string)($msg['text'] ?? ''), 0, 500);
            if ($text !== '') $conn->room->handleChat($conn->player, $text);
            break;
        }
        case 'pm': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->handlePrivateMessage($conn->player, (int)($msg['toPlayerId'] ?? 0), mb_substr((string)($msg['text'] ?? ''), 0, 500));
            break;
        }
        case 'invite': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->invitePlayer($conn->player, (int)($msg['toPlayerId'] ?? 0));
            break;
        }
        case 'input': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $p = $conn->player;
            if (isset($msg['pos']) && is_array($msg['pos'])) $p->pos = $msg['pos'];
            if (isset($msg['vel']) && is_array($msg['vel'])) $p->vel = $msg['vel'];
            if (isset($msg['yaw']))   $p->rot['yaw']   = (float)$msg['yaw'];
            if (isset($msg['pitch'])) $p->rot['pitch'] = (float)$msg['pitch'];
            if (isset($msg['crouching'])) $p->crouching = (bool)$msg['crouching'];
            if (isset($msg['scoping']))   $p->scoping = (bool)$msg['scoping'];
            if (isset($msg['onGround']))  $p->onGround = (bool)$msg['onGround'];
            if (!empty($msg['jump']) && $p->onGround && $p->alive) { $p->vel['y'] = 8.2; $p->onGround = false; }
            break;
        }
        case 'shoot': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $bullet = $conn->room->game->spawnBullet($conn->player);
            if ($bullet) $conn->room->broadcast(['type'=>'bullet', 'bullet'=>$bullet, 'shooterId'=>$conn->player->id, 'weapon'=>$conn->player->currentWeapon]);
            break;
        }
        case 'reload': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->player->reload($conn->room->game->weapons);
            break;
        }
        case 'selectWeapon': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->player->selectWeapon((string)($msg['weapon'] ?? 'usp'), $conn->room->game->weapons);
            break;
        }
        case 'buy': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $res = $conn->player->buyWeapon((string)($msg['weapon'] ?? ''), $conn->room->game->weapons);
            $conn->send(json_encode(['type'=>'buyResult', 'ok'=>$res['ok'], 'msg'=>$res['msg'] ?? '', 'money'=>$res['money'] ?? $conn->player->money]));
            break;
        }
        case 'throwGrenade': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->game->spawnGrenade($conn->player, (string)($msg['type'] ?? 'he'));
            break;
        }
        case 'plantBomb': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->game->tryPlantBomb($conn->player);
            break;
        }
        case 'startDefuse': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->game->tryStartDefuse($conn->player);
            break;
        }
        case 'stopDefuse': {
            if (!isset($conn->room) || !isset($conn->player)) return;
            $conn->room->game->stopDefuse($conn->player);
            break;
        }
    }
}

Worker::runAll();
