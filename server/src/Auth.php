<?php
// JWT 认证 - 轻量自实现（不依赖 firebase/php-jwt 时也可用）
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth
{
    public static function sign(array $payload, string $secret, int $expires): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $expires;
        return JWT::encode($payload, $secret, 'HS256');
    }

    public static function verify(string $token, string $secret): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array)$decoded;
        } catch (Exception $e) {
            return null;
        }
    }

    /** 从 Authorization 头或 token URL 解析出用户 */
    public static function userFromRequest(string $secret): ?array
    {
        $token = null;
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
        if (!$token && isset($_GET['token'])) $token = $_GET['token'];
        if (!$token) return null;
        $payload = self::verify($token, $secret);
        return $payload ?: null;
    }

    public static function hashPassword(string $password): string
    { return password_hash($password, PASSWORD_BCRYPT); }

    public static function verifyPassword(string $password, string $hash): bool
    { return password_verify($password, $hash); }
}
