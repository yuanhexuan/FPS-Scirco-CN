<?php
// 配置文件 - 环境变量 + 游戏参数
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
        putenv(trim($k) . '=' . trim($v, " \t\"'"));
    }
}

return [
    'PORT'        => (int)($_ENV['PORT'] ?? 3000),
    'JWT_SECRET'  => $_ENV['JWT_SECRET'] ?? 'fps-scirco-default-secret-change-me',
    'JWT_EXPIRES' => 7 * 24 * 3600,
    'CORS_ORIGIN' => $_ENV['CORS_ORIGIN'] ?? '*',
    'GAME' => [
        'TICK_RATE'    => 20,
        'MAP_SIZE'     => 50,
        'PLAYER_HEIGHT'=> 1.7,
        'PLAYER_RADIUS'=> 0.35,
        'GRAVITY'      => -25,
        'JUMP_VEL'     => 8.2,
        'WALK_SPEED'   => 4.2,
        'RUN_SPEED'    => 6.5,
        'CROUCH_SPEED' => 2.5,
        'MAX_HEALTH'   => 100,
        'MAX_ARMOR'    => 100,
        'ROUND_TIME'   => 115,
        'BOMB_EXPLODE' => 40,
        'BOMB_DEFUSE'  => 5,
        'FRAGS_TO_WIN' => 13,
        'BUY_TIME'     => 15,
    ],
    'STATIC_DIR'  => realpath(__DIR__ . '/../../client/dist') ?: (__DIR__ . '/../../client/dist'),
];
