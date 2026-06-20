<?php
// SQLite 数据库 - PDO 单例
class Database
{
    private static $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
        $path = $dataDir . '/fps.db';
        $isNew = !file_exists($path);
        self::$pdo = new PDO('sqlite:' . $path);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate();
        return self::$pdo;
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stats (
            player_id INTEGER PRIMARY KEY,
            kills INTEGER DEFAULT 0, deaths INTEGER DEFAULT 0,
            assists INTEGER DEFAULT 0, headshots INTEGER DEFAULT 0,
            wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0,
            games_played INTEGER DEFAULT 0, play_seconds INTEGER DEFAULT 0,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS weapon_prefs (
            player_id INTEGER, weapon_key TEXT NOT NULL, kills INTEGER DEFAULT 0,
            PRIMARY KEY (player_id, weapon_key),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS matches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            map_name TEXT, winner TEXT,
            rounds_ct INTEGER DEFAULT 0, rounds_t INTEGER DEFAULT 0,
            ended_at INTEGER DEFAULT (strftime('%s','now'))
        )");
    }

    public static function createPlayer($username, $passwordHash): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare('INSERT INTO players (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, $passwordHash]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO stats (player_id) VALUES (?)')->execute([$id]);
        return $id;
    }

    public static function findPlayerByUsername($username)
    {
        $stmt = self::pdo()->prepare('SELECT * FROM players WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getPlayerStats(int $playerId)
    {
        $stmt = self::pdo()->prepare('SELECT p.username, s.* FROM stats s
            JOIN players p ON p.id = s.player_id WHERE s.player_id = ?');
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $kd = $row['deaths'] > 0 ? $row['kills'] / $row['deaths'] : $row['kills'];
        return ['username' => $row['username'], 'kills' => (int)$row['kills'],
            'deaths' => (int)$row['deaths'], 'assists' => (int)$row['assists'],
            'headshots' => (int)$row['headshots'], 'wins' => (int)$row['wins'],
            'losses' => (int)$row['losses'], 'games_played' => (int)$row['games_played'],
            'play_seconds' => (int)$row['play_seconds'], 'kd' => round($kd, 2)];
    }

    public static function getAllStats(int $limit = 50): array
    {
        $stmt = self::pdo()->prepare('SELECT p.username, s.kills, s.deaths, s.assists,
            s.headshots, s.wins, s.losses, s.games_played, s.play_seconds
            FROM stats s JOIN players p ON p.id = s.player_id
            ORDER BY s.kills DESC LIMIT ?');
        $stmt->execute([$limit]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kd = $r['deaths'] > 0 ? round($r['kills'] / $r['deaths'], 2) : (int)$r['kills'];
            $out[] = ['username' => $r['username'], 'kills' => (int)$r['kills'],
                'deaths' => (int)$r['deaths'], 'assists' => (int)$r['assists'],
                'headshots' => (int)$r['headshots'], 'wins' => (int)$r['wins'],
                'losses' => (int)$r['losses'], 'games_played' => (int)$r['games_played'],
                'play_seconds' => (int)$r['play_seconds'], 'kd' => $kd];
        }
        return $out;
    }

    public static function addKill(int $pid, string $weaponKey, bool $isHead): void
    {
        $pdo = self::pdo();
        $pdo->prepare('UPDATE stats SET kills = kills + 1, headshots = headshots + ? WHERE player_id = ?')
            ->execute([$isHead ? 1 : 0, $pid]);
        $chk = $pdo->prepare('SELECT 1 FROM weapon_prefs WHERE player_id = ? AND weapon_key = ?');
        $chk->execute([$pid, $weaponKey]);
        if ($chk->fetchColumn()) {
            $pdo->prepare('UPDATE weapon_prefs SET kills = kills + 1 WHERE player_id = ? AND weapon_key = ?')
                ->execute([$pid, $weaponKey]);
        } else {
            $pdo->prepare('INSERT INTO weapon_prefs (player_id, weapon_key, kills) VALUES (?, ?, 1)')
                ->execute([$pid, $weaponKey]);
        }
    }

    public static function addDeath(int $pid): void
    { self::pdo()->prepare('UPDATE stats SET deaths = deaths + 1 WHERE player_id = ?')->execute([$pid]); }
    public static function addAssist(int $pid): void
    { self::pdo()->prepare('UPDATE stats SET assists = assists + 1 WHERE player_id = ?')->execute([$pid]); }
    public static function addWin(int $pid): void
    { self::pdo()->prepare('UPDATE stats SET wins = wins + 1, games_played = games_played + 1 WHERE player_id = ?')->execute([$pid]); }
    public static function addLoss(int $pid): void
    { self::pdo()->prepare('UPDATE stats SET losses = losses + 1, games_played = games_played + 1 WHERE player_id = ?')->execute([$pid]); }
    public static function addPlayTime(int $pid, int $seconds): void
    { self::pdo()->prepare('UPDATE stats SET play_seconds = play_seconds + ? WHERE player_id = ?')->execute([$seconds, $pid]); }

    public static function getWeaponsPref(int $pid): array
    {
        $stmt = self::pdo()->prepare('SELECT weapon_key, kills FROM weapon_prefs WHERE player_id = ? ORDER BY kills DESC');
        $stmt->execute([$pid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createMatch(string $map, string $winner, int $ct, int $t): int
    {
        $stmt = self::pdo()->prepare('INSERT INTO matches (map_name, winner, rounds_ct, rounds_t) VALUES (?, ?, ?, ?)');
        $stmt->execute([$map, $winner, $ct, $t]);
        return (int)self::pdo()->lastInsertId();
    }
}
