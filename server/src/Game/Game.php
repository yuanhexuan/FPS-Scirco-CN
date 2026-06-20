<?php
// 游戏核心：物理/射击/投掷物/回合制
require_once __DIR__ . '/weapons.php';

class Game
{
    public $room;
    public $state = 'warmup'; // warmup | buying | playing | roundEnd | matchEnd
    public $roundTime = 115;
    public $bombPlanted = false;
    public $bombPos = null;
    public $bombTimer = 40;
    public $bombPlanter = null;
    public $defuser = null;
    public $defuseProgress = 0;
    public $rounds = ['CT'=>0, 'T'=>0];
    public $bullets = [];
    public $grenades = [];
    public $fires = [];
    public $killFeed = [];
    public $mapName = 'de_dust2x';
    public $obstacles = [
        ['x'=>0,   'y'=>0, 'z'=>0,   'w'=>8,  'h'=>3,  'd'=>8],
        ['x'=>-25, 'y'=>0, 'z'=>0,   'w'=>10, 'h'=>3,  'd'=>4],
        ['x'=>25,  'y'=>0, 'z'=>0,   'w'=>10, 'h'=>3,  'd'=>4],
        ['x'=>0,   'y'=>0, 'z'=>-25, 'w'=>4,  'h'=>3,  'd'=>10],
        ['x'=>0,   'y'=>0, 'z'=>25,  'w'=>4,  'h'=>3,  'd'=>10],
        ['x'=>-12, 'y'=>0, 'z'=>15,  'w'=>6,  'h'=>2.5,'d'=>6],
        ['x'=>12,  'y'=>0, 'z'=>-15, 'w'=>6,  'h'=>2.5,'d'=>6],
    ];
    public $bombSites = [
        ['id'=>'A', 'x'=>-12, 'z'=>15, 'radius'=>5],
        ['id'=>'B', 'x'=>12,  'z'=>-15, 'radius'=>5],
    ];
    public $weapons;
    public $cfg;
    public $lastTick;
    public $buyTimer = null;

    public function __construct(Room $room, array $cfg)
    {
        $this->room = $room;
        $this->cfg = $cfg['GAME'];
        $this->weapons = require __DIR__ . '/weapons.php';
        $this->roundTime = $this->cfg['ROUND_TIME'];
        $this->bombTimer = $this->cfg['BOMB_EXPLODE'];
        $this->lastTick = microtime(true) * 1000;
    }

    public function startRound(): void
    {
        $this->state = 'buying';
        $this->roundTime = $this->cfg['ROUND_TIME'];
        $this->bombPlanted = false;
        $this->bombPos = null;
        $this->bombPlanter = null;
        $this->defuser = null;
        $this->defuseProgress = 0;
        $this->bullets = [];
        $this->grenades = [];
        $this->fires = [];
        $this->killFeed = [];

        $isFirst = $this->rounds['CT'] === 0 && $this->rounds['T'] === 0;
        foreach ($this->room->players as $p) {
            $sx = $p->team === 'CT' ? -18 : 18;
            $sz = $p->team === 'CT' ? -18 : 18;
            $p->respawn(['x'=>$sx + (mt_rand(-200,200)/100), 'z'=>$sz + (mt_rand(-200,200)/100)]);
            if (!$isFirst) $p->money += 1400;
        }

        if ($this->buyTimer) \Workerman\Lib\Timer::del($this->buyTimer);
        $this->buyTimer = \Workerman\Lib\Timer::add($this->cfg['BUY_TIME'], function() {
            if ($this->state === 'buying') $this->state = 'playing';
        }, [], false);

        $this->broadcastState();
    }

    public function tick(): void
    {
        $now = microtime(true) * 1000;
        $dt = min(0.1, ($now - $this->lastTick) / 1000);
        $this->lastTick = $now;

        if ($this->state === 'playing' || $this->state === 'buying') {
            $this->roundTime = max(0, $this->roundTime - $dt);
            $this->updatePlayers($dt);
            $this->updateGrenades();
            $this->updateFires($dt);
            $this->checkBomb($dt);
            if ($this->state === 'playing') $this->checkRoundEnd();
        }
    }

    private function updatePlayers(float $dt): void
    {
        $MS = $this->cfg['MAP_SIZE'];
        foreach ($this->room->players as $p) {
            if (!$p->alive) continue;
            $p->pos['x'] += $p->vel['x'] * $dt;
            $p->pos['z'] += $p->vel['z'] * $dt;
            $p->vel['y'] += $this->cfg['GRAVITY'] * $dt;
            $p->pos['y'] += $p->vel['y'] * $dt;
            if ($p->pos['y'] <= 0) { $p->pos['y'] = 0; $p->vel['y'] = 0; $p->onGround = true; }
            else $p->onGround = false;
            $p->pos['x'] = max(-$MS, min($MS, $p->pos['x']));
            $p->pos['z'] = max(-$MS, min($MS, $p->pos['z']));
            // AABB 掩体碰撞
            foreach ($this->obstacles as $o) {
                $dx = $p->pos['x'] - $o['x']; $dz = $p->pos['z'] - $o['z'];
                $ox = ($o['w']/2 + $this->cfg['PLAYER_RADIUS']) - abs($dx);
                $oz = ($o['d']/2 + $this->cfg['PLAYER_RADIUS']) - abs($dz);
                if ($ox > 0 && $oz > 0) {
                    if ($ox < $oz) $p->pos['x'] += $dx > 0 ? $ox : -$ox;
                    else $p->pos['z'] += $dz > 0 ? $oz : -$oz;
                }
            }
        }
    }

    public function spawnBullet(Player $shooter): ?array
    {
        $shot = $shooter->shoot($this->weapons);
        if (!$shot) return null;
        $yaw = $shooter->rot['yaw']; $pitch = $shooter->rot['pitch'];
        $dx = sin($yaw) * cos($pitch);
        $dz = cos($yaw) * cos($pitch);
        $dy = sin($pitch);
        $spread = $shot['spread'];
        $sx = (mt_rand(-1000, 1000) / 1000) * $spread;
        $sy = (mt_rand(-1000, 1000) / 1000) * $spread;
        $sz = (mt_rand(-1000, 1000) / 1000) * $spread;
        $dir = ['x'=>$dx + $sx, 'y'=>$dy + $sy, 'z'=>$dz + $sz];
        $len = sqrt($dir['x']**2 + $dir['y']**2 + $dir['z']**2) ?: 1;
        $dir['x'] /= $len; $dir['y'] /= $len; $dir['z'] /= $len;
        $bullet = [
            'id' => bin2hex(random_bytes(6)),
            'ownerId' => $shooter->id, 'team' => $shooter->team,
            'weapon' => $shot['weapon'],
            'pos' => ['x'=>$shooter->pos['x'] + $dir['x'] * 0.5, 'y'=>$shooter->pos['y'] + 1.6, 'z'=>$shooter->pos['z'] + $dir['z'] * 0.5],
            'dir' => $dir, 'speed' => $shot['speed'] ?? 1200, 'drop' => $shot['drop'] ?? 0,
            'damage' => $shot['damage'], 'createdAt' => microtime(true) * 1000,
        ];
        $this->bullets[] = $bullet;
        $this->rayCastForBullet($bullet, $shooter);
        // 限制历史
        if (count($this->bullets) > 60) $this->bullets = array_slice($this->bullets, -30);
        return $bullet;
    }

    private function rayCastForBullet(array $bullet, Player $shooter): void
    {
        $maxDist = 120;
        $closest = null; $closestDist = $maxDist; $closestPart = 'body';
        foreach ($this->room->players as $p) {
            if (!$p->alive || $p->id === $shooter->id || $p->team === $shooter->team) continue;
            $dx = $p->pos['x'] - $bullet['pos']['x'];
            $dz = $p->pos['z'] - $bullet['pos']['z'];
            $dy = $p->pos['y'] + 1.7 - $bullet['pos']['y'];
            $t = $dx * $bullet['dir']['x'] + $dy * $bullet['dir']['y'] + $dz * $bullet['dir']['z'];
            if ($t < 0 || $t > $maxDist) continue;
            $px = $bullet['pos']['x'] + $bullet['dir']['x'] * $t;
            $py = $bullet['pos']['y'] + $bullet['dir']['y'] * $t;
            $pz = $bullet['pos']['z'] + $bullet['dir']['z'] * $t;
            $distX = sqrt(pow($px - $p->pos['x'], 2) + pow($pz - $p->pos['z'], 2));
            $distY = abs($py - ($p->pos['y'] + 1.7));
            $headY = $p->pos['y'] + 1.65;
            $isHead = abs($py - $headY) < 0.2 && $distX < 0.25;
            if ($isHead && $t < $closestDist) { $closest = $p; $closestDist = $t; $closestPart = 'head'; }
            elseif ($distX < $this->cfg['PLAYER_RADIUS'] + 0.05 && $distY < 0.85 && $t < $closestDist) {
                $closest = $p; $closestDist = $t; $closestPart = 'body';
            }
        }
        $blocked = false;
        foreach ($this->obstacles as $o) {
            $td = $this->aabbRayDist($o, $bullet['pos'], $bullet['dir']);
            if ($td !== null && $td < $closestDist) { $blocked = true; break; }
        }
        if ($closest && !$blocked) {
            $w = $this->weapons[$bullet['weapon']];
            $isHead = $closestPart === 'head';
            $dmg = $closest->takeDamage($w['damage'], $shooter->id, $bullet['weapon'], $isHead ? 'head' : 'body', $this->weapons);
            $this->room->broadcast(['type'=>'hit', 'shooter'=>$shooter->id, 'target'=>$closest->id, 'damage'=>$dmg, 'part'=>$closestPart]);
            if (!$closest->alive) $this->onKill($shooter, $closest, $bullet['weapon'], $isHead);
        }
    }

    private function aabbRayDist(array $o, array $origin, array $dir)
    {
        $mnX = $o['x'] - $o['w']/2; $mxX = $o['x'] + $o['w']/2;
        $mnZ = $o['z'] - $o['d']/2; $mxZ = $o['z'] + $o['d']/2;
        $tmin = -INF; $tmax = INF;
        foreach ([['x',$mnX,$mxX], ['z',$mnZ,$mxZ]] as $axis) {
            [$k, $mn, $mx] = $axis;
            $oa = $origin[$k]; $da = $dir[$k];
            if (abs($da) < 1e-8) { if ($oa < $mn || $oa > $mx) return null; }
            else {
                $t1 = ($mn - $oa) / $da; $t2 = ($mx - $oa) / $da;
                if ($t1 > $t2) [$t1, $t2] = [$t2, $t1];
                $tmin = max($tmin, $t1); $tmax = min($tmax, $t2);
                if ($tmin > $tmax) return null;
            }
        }
        if ($tmax < 0) return null;
        return $tmin >= 0 ? $tmin : 0.01;
    }

    public function spawnGrenade(Player $thrower, string $type): ?array
    {
        if (!$thrower->throwGrenade($type, $this->weapons)) return null;
        $yaw = $thrower->rot['yaw']; $pitch = $thrower->rot['pitch'];
        $dir = [
            'x' => sin($yaw) * cos($pitch),
            'y' => sin($pitch) + 0.2,
            'z' => cos($yaw) * cos($pitch),
        ];
        $g = [
            'id' => bin2hex(random_bytes(6)),
            'ownerId' => $thrower->id, 'team' => $thrower->team, 'type' => $type,
            'pos' => ['x'=>$thrower->pos['x'], 'y'=>$thrower->pos['y']+1.5, 'z'=>$thrower->pos['z']],
            'vel' => ['x'=>$dir['x']*25, 'y'=>$dir['y']*25+5, 'z'=>$dir['z']*25],
            'landed' => false,
            'explodeAt' => (microtime(true) * 1000) + $this->weapons[$type]['fuse'],
        ];
        $this->grenades[] = $g;
        $this->room->broadcast(['type'=>'grenade', 'grenade'=>$g]);
        return $g;
    }

    private function updateGrenades(): void
    {
        $now = microtime(true) * 1000;
        $remaining = [];
        foreach ($this->grenades as $g) {
            if (!$g['landed']) {
                $g['vel']['y'] += $this->cfg['GRAVITY'] * 0.05;
                $g['pos']['x'] += $g['vel']['x'] * 0.05;
                $g['pos']['y'] += $g['vel']['y'] * 0.05;
                $g['pos']['z'] += $g['vel']['z'] * 0.05;
                if ($g['pos']['y'] <= 0) { $g['pos']['y'] = 0; $g['vel']['y'] *= -0.3; $g['vel']['x'] *= 0.7; $g['vel']['z'] *= 0.7; if (abs($g['vel']['y']) < 1) $g['landed'] = true; }
            }
            if ($now >= $g['explodeAt']) $this->explodeGrenade($g);
            else $remaining[] = $g;
        }
        $this->grenades = $remaining;
    }

    private function explodeGrenade(array $g): void
    {
        $w = $this->weapons[$g['type']];
        if ($g['type'] === 'he') {
            foreach ($this->room->players as $p) {
                if (!$p->alive) continue;
                $dist = sqrt(pow($p->pos['x']-$g['pos']['x'],2)+pow($p->pos['z']-$g['pos']['z'],2));
                if ($dist < $w['radius']) {
                    $dmg = (int)round($w['damage'] * (1 - $dist / $w['radius']));
                    $p->takeDamage($dmg, $g['ownerId'], $g['type'], 'body', $this->weapons);
                    if (!$p->alive) $this->onKill($this->room->getPlayerById($g['ownerId']), $p, $g['type'], false);
                }
            }
            $this->room->broadcast(['type'=>'explode', 'kind'=>'he', 'pos'=>$g['pos'], 'radius'=>$w['radius']]);
        } elseif ($g['type'] === 'flash') {
            foreach ($this->room->players as $p) {
                if (!$p->alive) continue;
                $dist = sqrt(pow($p->pos['x']-$g['pos']['x'],2)+pow($p->pos['z']-$g['pos']['z'],2));
                if ($dist < 18) $p->blindUntil = max($p->blindUntil, (microtime(true)*1000) + (int)($w['blind'] * (1 - $dist/18)));
            }
            $this->room->broadcast(['type'=>'explode', 'kind'=>'flash', 'pos'=>$g['pos']]);
        } elseif ($g['type'] === 'smoke') {
            $this->room->broadcast(['type'=>'explode', 'kind'=>'smoke', 'pos'=>$g['pos'], 'duration'=>15000]);
        } elseif ($g['type'] === 'molotov') {
            $this->fires[] = ['pos'=>['x'=>$g['pos']['x'],'y'=>0,'z'=>$g['pos']['z']], 'radius'=>$w['radius'], 'damage'=>$w['damage'], 'until'=>(microtime(true)*1000) + $w['duration']];
            $this->room->broadcast(['type'=>'explode', 'kind'=>'molotov', 'pos'=>$g['pos']]);
        }
    }

    private function updateFires(float $dt): void
    {
        $now = microtime(true) * 1000;
        $this->fires = array_values(array_filter($this->fires, fn($f) => $f['until'] > $now));
        foreach ($this->fires as $f) {
            foreach ($this->room->players as $p) {
                if (!$p->alive) continue;
                $dist = sqrt(pow($p->pos['x']-$f['pos']['x'],2) + pow($p->pos['z']-$f['pos']['z'],2));
                if ($dist < $f['radius']) $p->takeDamage((int)($f['damage'] * $dt), 0, 'molotov', 'body', $this->weapons);
            }
        }
    }

    public function tryPlantBomb(Player $player): bool
    {
        if ($player->team !== 'T' || $this->bombPlanted) return false;
        foreach ($this->bombSites as $s) {
            $dist = sqrt(pow($player->pos['x']-$s['x'],2) + pow($player->pos['z']-$s['z'],2));
            if ($dist < $s['radius']) {
                $this->bombPlanted = true;
                $this->bombPos = ['x'=>$s['x'], 'y'=>0.1, 'z'=>$s['z'], 'site'=>$s['id']];
                $this->bombPlanter = $player->id;
                $this->bombTimer = $this->cfg['BOMB_EXPLODE'];
                $this->room->broadcast(['type'=>'bombPlanted', 'site'=>$s['id'], 'pos'=>$this->bombPos]);
                return true;
            }
        }
        return false;
    }

    public function tryStartDefuse(Player $player): bool
    {
        if ($player->team !== 'CT' || !$this->bombPlanted || $this->defuser) return false;
        $dist = sqrt(pow($player->pos['x']-$this->bombPos['x'],2) + pow($player->pos['z']-$this->bombPos['z'],2));
        if ($dist > 3) return false;
        $this->defuser = $player->id;
        $this->defuseProgress = 0;
        $this->room->broadcast(['type'=>'defusing', 'playerId'=>$player->id]);
        return true;
    }

    public function stopDefuse(Player $player): void
    {
        if ($this->defuser === $player->id) {
            $this->defuser = null;
            $this->defuseProgress = 0;
            $this->room->broadcast(['type'=>'defuseStop', 'playerId'=>$player->id]);
        }
    }

    private function checkBomb(float $dt): void
    {
        if (!$this->bombPlanted) return;
        $this->bombTimer -= $dt;
        if ($this->defuser) {
            $this->defuseProgress += $dt;
            if ($this->defuseProgress >= $this->cfg['BOMB_DEFUSE']) {
                $this->endRound('CT', 'defuse'); return;
            }
        }
        if ($this->bombTimer <= 0) $this->endRound('T', 'bomb');
    }

    private function checkRoundEnd(): void
    {
        $aliveCT = 0; $aliveT = 0;
        foreach ($this->room->players as $p) {
            if (!$p->alive) continue;
            if ($p->team === 'CT') $aliveCT++;
            elseif ($p->team === 'T') $aliveT++;
        }
        if ($aliveT === 0 && !$this->bombPlanted) $this->endRound('CT', 'elimT');
        elseif ($aliveCT === 0) $this->endRound('T', 'elimCT');
        elseif ($this->roundTime <= 0 && !$this->bombPlanted) $this->endRound('CT', 'time');
    }

    public function endRound(string $winner, string $reason): void
    {
        $this->state = 'roundEnd';
        $this->rounds[$winner]++;
        // 助攻判定
        foreach ($this->room->players as $p) {
            foreach ($p->damageDealtTo as $victimId => $dmg) {
                $v = $this->room->getPlayerById($victimId);
                if ($v && !$v->alive) { $p->roundAssists++; Database::addAssist($p->id); }
            }
            if ($p->team === $winner) { $p->money += 3250; }
            else { $p->money += 1400; }
        }
        $this->room->broadcast([
            'type' => 'roundEnd', 'winner' => $winner, 'reason' => $reason,
            'rounds' => $this->rounds, 'bombPlanted' => $this->bombPlanted, 'defuser' => $this->defuser,
        ]);
        if ($this->rounds['CT'] >= $this->cfg['FRAGS_TO_WIN'] || $this->rounds['T'] >= $this->cfg['FRAGS_TO_WIN']) {
            $this->state = 'matchEnd';
            $this->room->broadcast(['type'=>'matchEnd', 'winner'=>$winner, 'rounds'=>$this->rounds]);
            Database::createMatch($this->mapName, $winner, $this->rounds['CT'], $this->rounds['T']);
            foreach ($this->room->players as $p) {
                if ($p->team === $winner) Database::addWin($p->id); else Database::addLoss($p->id);
                Database::addPlayTime($p->id, max(1, time() - $p->joinTime));
            }
            return;
        }
        \Workerman\Lib\Timer::add(5, function() { if ($this->state === 'roundEnd') $this->startRound(); }, [], false);
    }

    public function onKill(?Player $killer, Player $victim, string $weapon, bool $isHead): void
    {
        if (!$killer) return;
        $killer->roundKills++;
        if ($isHead) $killer->roundHeadshots++;
        Database::addKill($killer->id, $weapon, $isHead);
        Database::addDeath($victim->id);
        foreach ($this->room->players as $p) {
            if ($p->id !== $killer->id && $p->team === $killer->team && ($p->damageDealtTo[$victim->id] ?? 0) > 20) {
                $p->roundAssists++;
            }
        }
        $kill = [
            'type' => 'kill', 'killer' => $killer->username, 'killerId' => $killer->id,
            'victim' => $victim->username, 'victimId' => $victim->id,
            'weapon' => $weapon, 'headshot' => $isHead, 'team' => $killer->team,
            'at' => (int)(microtime(true) * 1000),
        ];
        $this->killFeed[] = $kill;
        if (count($this->killFeed) > 20) array_shift($this->killFeed);
        $this->room->broadcast($kill);
    }

    public function broadcastState(): void
    {
        $this->room->broadcast([
            'type' => 'gameState',
            'state' => $this->state,
            'roundTime' => $this->roundTime,
            'rounds' => $this->rounds,
            'bombPlanted' => $this->bombPlanted,
            'bombPos' => $this->bombPos,
            'bombTimer' => $this->bombTimer,
            'killFeed' => array_slice($this->killFeed, -10),
        ]);
    }

    public function getSnapshot(): array
    {
        return [
            'type' => 'snapshot',
            'players' => array_map(fn($p) => $p->toPublic(), $this->room->players),
            'bullets' => array_slice($this->bullets, -30),
            'grenades' => $this->grenades,
            'fires' => $this->fires,
            'state' => $this->state,
            'roundTime' => $this->roundTime,
            'rounds' => $this->rounds,
            'bombPlanted' => $this->bombPlanted,
            'bombPos' => $this->bombPos,
            'bombTimer' => $this->bombTimer,
            'defuser' => $this->defuser,
            'defuseProgress' => $this->defuseProgress,
        ];
    }
}
