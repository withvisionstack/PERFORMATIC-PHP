<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function jwt_encode(array $payload, int $ttl = 3600): string
{
    $secret = getenv('JWT_SECRET') ?: 'dev-secret-mude-em-producao';
    $now = time();
    $data = array_merge($payload, [
        'iat' => $now,
        'exp' => $now + $ttl,
    ]);
    return JWT::encode($data, $secret, 'HS256');
}

function jwt_decode(string $token): ?array
{
    $secret = getenv('JWT_SECRET') ?: 'dev-secret-mude-em-producao';
    try {
        return (array) JWT::decode($token, new Key($secret, 'HS256'));
    } catch (Throwable $e) {
        return null;
    }
}

function current_user(): ?array
{
    // Authorization: Bearer primeiro (SPA/mobile)
    $token = bearer_token();

    // Se não houver, tenta cookie HttpOnly (browser)
    if ($token === null) {
        $token = cookie('auth_token');
    }

    if ($token === null) {
        return null;
    }

    return jwt_decode($token);
}
