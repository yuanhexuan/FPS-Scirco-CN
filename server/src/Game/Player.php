<?php
// 玩家实体 - 位置/状态/武器/伤害
class Player
{
    public $id;
    public $username;
    public $team;          // 'CT' | 'T'
    public $connection;    // Workerman Connection
    public $pos = ['x'=>0,'y'=>0,'z'=>0];
    public $vel = ['x'=>0,'y'=>0,'z'=>0];
    public $rot = ['yaw'=>0,'pitch'=>0];
    public $onGround = false;
    public $crouching = false;
    public $scoping = false;
    public $alive = true;
    public $health = 100;
    public $armor = 0;
    public $money = 800;
    public $currentWeapon = 'usp';
    public $inventory = ['usp','knife'];
    public $ammo = [];   // weaponKey => ['magazine','reserve']
    public $grenades = ['smoke'=>1,'flash'=>1,'he'=>1,'molotov'=>0];
    public $reloading = false;
    public $lastShotAt = 0;
    public $blindUntil = 0;
    public $roundKills = 0;
    public $roundHeadshots = 0;
    public $roundAssists = 0;
    public $damageDealtTo = []; // victimId => amount
    public $joinTime;

    public function __construct(int $id, string $username, string $team, $connection, array $weapons)
    {
        $this->id = $id;
        $this->username = $username;
        $this->team = $team;
        $this->connection = $connection;
        $this->joinTime = time();

        $sx = $team === 'CT' ? -18 : 18;
        $sz = $team === 'CT' ? -18 : 18;
        $this->pos = [
            'x' => $sx + (mt_rand(-200, 200) / 100),
            'y' => 0,
            'z' => $sz + (mt_rand(-200, 200) / 100),
        ];
        $this->rot['yaw'] = $team === 'CT' ? 0 : M_PI;
        $this->currentWeapon = $team === 'CT' ? 'usp' : 'glock';
        $this->inventory = [$this->currentWeapon, 'knife'];

        foreach ($weapons as $k => $w) {
            if (!empty($w['grenade'])) continue;
            $this->ammo[$k] = ['magazine'=>$w['magazine'], 'reserve'=>$w['magazine'] * 2];
        }
    }

    public function takeDamage(int $amount, int $attackerId, string $weaponKey, string $hitPart, array $weapons): int
    {
        if (!$this->alive) return 0;
        $dmg = $amount;
        if (isset($weapons[$weaponKey])) {
            $w = $weapons[$weaponKey];
            if ($hitPart === 'head' && isset($w['headshot'])) $dmg = $w['headshot'];
            if ($this->armor > 0) {
                $pen = $w['armorPen'] ?? 0.5;
                $absorb = min($this->armor, (int)($dmg * (1 - $pen)));
                $this->armor -= $absorb;
                $dmg -= $absorb;
            }
        }
        $dmg = max(0, (int)round($dmg));
        $this->health -= $dmg;
        if ($attackerId && $attackerId !== $this->id) {
            $this->damageDealtTo[$attackerId] = ($this->damageDealtTo[$attackerId] ?? 0) + $dmg;
        }
        if ($this->health <= 0) { $this->health = 0; $this->alive = false; }
        return $dmg;
    }

    public function buyWeapon(string $weaponKey, array $weapons): array
    {
        $w = $weapons[$weaponKey] ?? null;
        if (!$w) return ['ok'=>false, 'msg'=>'武器不存在'];
        if ($this->money < $w['price']) return ['ok'=>false, 'msg'=>'金币不足'];
        if (!empty($w['grenade'])) {
            $this->grenades[$weaponKey] = ($this->grenades[$weaponKey] ?? 0) + 1;
        } else {
            if (in_array($weaponKey, $this->inventory)) return ['ok'=>false, 'msg'=>'已持有'];
            $this->inventory[] = $weaponKey;
            $this->ammo[$weaponKey] = ['magazine'=>$w['magazine'], 'reserve'=>$w['magazine'] * 2];
        }
        $this->money -= $w['price'];
        return ['ok'=>true, 'money'=>$this->money];
    }

    public function selectWeapon(string $weaponKey, array $weapons): void
    {
        if (in_array($weaponKey, $this->inventory)) {
            $this->currentWeapon = $weaponKey;
        } elseif (isset($weapons[$weaponKey]['grenade']) && ($this->grenades[$weaponKey] ?? 0) > 0) {
            $this->currentWeapon = $weaponKey;
        }
    }

    public function reload(array $weapons): void
    {
        if ($this->reloading || !$this->alive) return;
        $w = $weapons[$this->currentWeapon] ?? null;
        if (!$w || !empty($w['grenade']) || !empty($w['melee'])) return;
        $mag = $this->ammo[$this->currentWeapon] ?? null;
        if (!$mag || $mag['magazine'] >= $w['magazine'] || $mag['reserve'] <= 0) return;
        $this->reloading = true;
        $weaponKey = $this->currentWeapon;
        $reloadTime = $w['reloadTime'];
        $pid = $this->id;
        // Workerman 异步定时
        \Workerman\Lib\Timer::add($reloadTime / 1000, function() use ($weaponKey, $w, &$mag, $pid) {
            $player = Room::$playersById[$pid] ?? null;
            if (!$player) return;
            $mag = $player->ammo[$weaponKey] ?? null;
            if (!$mag) { $player->reloading = false; return; }
            $need = $w['magazine'] - $mag['magazine'];
            $take = min($need, $mag['reserve']);
            $player->ammo[$weaponKey]['magazine'] += $take;
            $player->ammo[$weaponKey]['reserve']  -= $take;
            $player->reloading = false;
        }, [], false);
    }

    public function canShoot(array $weapons): bool
    {
        if (!$this->alive || $this->reloading) return false;
        $w = $weapons[$this->currentWeapon] ?? null;
        if (!$w || !empty($w['grenade']) || !empty($w['melee'])) return false;
        $mag = $this->ammo[$this->currentWeapon] ?? null;
        if (!$mag || $mag['magazine'] <= 0) return false;
        if (microtime(true) * 1000 - $this->lastShotAt < $w['fireRate']) return false;
        return true;
    }

    public function shoot(array $weapons): ?array
    {
        if (!$this->canShoot($weapons)) return null;
        $w = $weapons[$this->currentWeapon];
        $this->ammo[$this->currentWeapon]['magazine'] -= 1;
        $this->lastShotAt = microtime(true) * 1000;
        $speed = sqrt(pow($this->vel['x'], 2) + pow($this->vel['z'], 2));
        $moveFactor = min(1, $speed / 5);
        $spread = $w['spread'] + ($w['moveSpread'] ?? 0) * $moveFactor + ($this->onGround ? 0 : ($w['airSpread'] ?? 0));
        if ($this->scoping && !empty($w['scope'])) $spread *= 0.12;
        return [
            'weapon' => $this->currentWeapon,
            'spread' => $spread,
            'speed'  => $w['bulletSpeed'] ?? 1200,
            'drop'   => $w['bulletDrop'] ?? 0,
            'damage' => $w['damage'],
        ];
    }

    public function throwGrenade(string $g, array $weapons): bool
    {
        if (!$this->alive) return false;
        $w = $weapons[$g] ?? null;
        if (!$w || empty($w['grenade'])) return false;
        if (($this->grenades[$g] ?? 0) <= 0) return false;
        $this->grenades[$g] -= 1;
        return true;
    }

    public function respawn(array $spawn): void
    {
        $this->pos = ['x'=>$spawn['x'], 'y'=>0, 'z'=>$spawn['z']];
        $this->vel = ['x'=>0,'y'=>0,'z'=>0];
        $this->health = 100; $this->armor = 0; $this->alive = true;
        $this->currentWeapon = $this->team === 'CT' ? 'usp' : 'glock';
        $this->roundKills = 0; $this->roundHeadshots = 0; $this->roundAssists = 0;
        $this->damageDealtTo = [];
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id, 'username' => $this->username, 'team' => $this->team,
            'pos' => $this->pos, 'vel' => $this->vel,
            'yaw' => $this->rot['yaw'], 'pitch' => $this->rot['pitch'],
            'health' => $this->health, 'armor' => $this->armor, 'money' => $this->money,
            'alive' => $this->alive, 'currentWeapon' => $this->currentWeapon,
            'ammo' => $this->ammo, 'scoping' => $this->scoping,
            'reloading' => $this->reloading, 'crouching' => $this->crouching,
            'onGround' => $this->onGround,
            'roundKills' => $this->roundKills, 'roundHeadshots' => $this->roundHeadshots,
        ];
    }

    public function toLobby(): array
    {
        return ['id'=>$this->id, 'username'=>$this->username, 'team'=>$this->team, 'alive'=>$this->alive];
    }
}
