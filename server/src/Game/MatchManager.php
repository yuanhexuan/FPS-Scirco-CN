<?php
// 房间全局匹配管理器
class MatchManager
{
    private static $instance = null;
    public $rooms = [];

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function createRoom(string $name, int $maxPlayers, array $cfg): Room
    {
        $id = bin2hex(random_bytes(8));
        $room = new Room($id, $name, $maxPlayers, $cfg);
        $this->rooms[$id] = $room;
        return $room;
    }

    public function getRoom(string $id): ?Room
    {
        return $this->rooms[$id] ?? null;
    }

    public function getOrCreateRoom(string $id, string $name, int $maxPlayers, array $cfg): Room
    {
        if (isset($this->rooms[$id])) return $this->rooms[$id];
        return $this->createRoom($name, $maxPlayers, $cfg);
    }

    public function removeRoom(string $id): void
    {
        unset($this->rooms[$id]);
    }

    public function listPublicRooms(): array
    {
        $out = [];
        foreach ($this->rooms as $r) {
            if (!$r->isPrivate) $out[] = $r->toPublic();
        }
        return $out;
    }

    public function listAllOnline(): array
    {
        $out = [];
        foreach ($this->rooms as $r) {
            foreach ($r->players as $p) $out[] = $p->toLobby();
        }
        return $out;
    }
}
