<?php
require_once __DIR__ . '/Player.php';
require_once __DIR__ . '/Game.php';

// 房间：玩家 + 游戏 + 聊天
class Room
{
    public static $playersById = []; // id => Player 全局索引
    public $id;
    public $name;
    public $maxPlayers = 16;
    public $isPrivate = false;
    public $password = null;
    public $players = [];
    public $spectators = [];
    public $game;
    public $cfg;
    public $createdAt;
    public $tickTimer = null;
    public $weapons;
    public $lastSnapshot = 0;

    public function __construct(string $id, string $name, int $maxPlayers, array $cfg)
    {
        $this->id = $id;
        $this->name = $name;
        $this->maxPlayers = $maxPlayers;
        $this->cfg = $cfg;
        $this->weapons = require __DIR__ . '/weapons.php';
        $this->game = new Game($this, $cfg);
        $this->createdAt = time();
    }

    public function addPlayer(int $pid, string $username, $conn): Player
    {
        $team = $this->pickTeam();
        $p = new Player($pid, $username, $team, $conn, $this->weapons);
        $this->players[] = $p;
        self::$playersById[$pid] = $p;
        $conn->room = $this;
        $conn->player = $p;
        $this->broadcastLobby();
        $conn->send(json_encode(['type'=>'joinedRoom', 'roomId'=>$this->id, 'team'=>$team, 'player'=>$p->toPublic()]));
        if ($this->game->state === 'playing' || $this->game->state === 'buying') $p->alive = false;
        return $p;
    }

    public function removePlayer(int $pid): void
    {
        foreach ($this->players as $i => $p) {
            if ($p->id === $pid) {
                array_splice($this->players, $i, 1);
                unset(self::$playersById[$pid]);
                break;
            }
        }
        $this->broadcastLobby();
        if (count($this->players) === 0 && $this->tickTimer) {
            \Workerman\Lib\Timer::del($this->tickTimer);
            $this->tickTimer = null;
            MatchManager::getInstance()->removeRoom($this->id);
        }
    }

    public function setTeam(int $pid, string $team): void
    {
        foreach ($this->players as $p) if ($p->id === $pid) { $p->team = $team; break; }
        $this->broadcastLobby();
    }

    public function startMatch(): array
    {
        if (count($this->players) < 2) return ['ok'=>false, 'msg'=>'至少需要 2 名玩家'];
        if ($this->game->state === 'buying' || $this->game->state === 'playing') return ['ok'=>false, 'msg'=>'比赛已进行'];
        $this->game->rounds = ['CT'=>0, 'T'=>0];
        $this->game->startRound();
        if ($this->tickTimer) \Workerman\Lib\Timer::del($this->tickTimer);
        $this->tickTimer = \Workerman\Lib\Timer::add(0.05, function() {
            $this->game->tick();
            $now = microtime(true) * 1000;
            if ($now - $this->lastSnapshot > 50) {
                $this->broadcast($this->game->getSnapshot());
                $this->lastSnapshot = $now;
            }
        });
        return ['ok'=>true];
    }

    public function stopMatch(): void
    {
        if ($this->tickTimer) \Workerman\Lib\Timer::del($this->tickTimer);
        $this->tickTimer = null;
        $this->game->state = 'warmup';
        $this->game->broadcastState();
    }

    public function handleChat(Player $from, string $text): void
    {
        $this->broadcast(['type'=>'chat', 'from'=>$from->username, 'team'=>$from->team, 'text'=>$text, 'at'=>(int)(microtime(true)*1000)]);
    }

    public function handlePrivateMessage(Player $from, int $toPid, string $text): void
    {
        $to = $this->getPlayerById($toPid);
        if (!$to || !$to->connection) return;
        $to->connection->send(json_encode(['type'=>'pm', 'from'=>$from->username, 'fromId'=>$from->id, 'text'=>$text, 'at'=>(int)(microtime(true)*1000)]));
    }

    public function invitePlayer(Player $from, int $toPid): void
    {
        $to = $this->getPlayerById($toPid);
        if (!$to || !$to->connection) return;
        $to->connection->send(json_encode(['type'=>'invite', 'from'=>$from->username, 'fromId'=>$from->id, 'roomId'=>$this->id, 'roomName'=>$this->name, 'at'=>(int)(microtime(true)*1000)]));
    }

    public function getPlayerById(int $id): ?Player
    {
        foreach ($this->players as $p) if ($p->id === $id) return $p;
        return null;
    }

    public function pickTeam(): string
    {
        $ct = 0; $t = 0;
        foreach ($this->players as $p) { if ($p->team === 'CT') $ct++; elseif ($p->team === 'T') $t++; }
        return $ct <= $t ? 'CT' : 'T';
    }

    public function broadcastLobby(): void
    {
        $payload = [
            'type' => 'lobby',
            'roomId' => $this->id, 'name' => $this->name,
            'players' => array_map(fn($p) => [
                'id'=>$p->id, 'username'=>$p->username, 'team'=>$p->team, 'alive'=>$p->alive,
                'kills'=>$p->roundKills, 'headshots'=>$p->roundHeadshots, 'assists'=>$p->roundAssists,
            ], $this->players),
            'maxPlayers' => $this->maxPlayers,
            'state' => $this->game->state,
            'rounds' => $this->game->rounds,
        ];
        $this->broadcast($payload);
    }

    public function broadcast(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ($this->players as $p) {
            if ($p->connection) $p->connection->send($json);
        }
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'playersCount' => count($this->players), 'maxPlayers' => $this->maxPlayers,
            'isPrivate' => $this->isPrivate, 'state' => $this->game->state,
            'rounds' => $this->game->rounds,
        ];
    }
}
