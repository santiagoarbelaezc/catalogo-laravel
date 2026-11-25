<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class JwtService
{
    private string $secret;

    public function __construct()
    {
        $this->secret = trim((string) config('catalogo.jwt.secret', ''));
    }

    public function generateToken(User|array $user, int $ttlSeconds = 86400): string
    {
        if ($this->secret === '' || $this->secret === 'tu_clave_secreta') {
            throw new RuntimeException('JWT_SECRET no está configurado de forma segura en .env');
        }

        $id = is_array($user) ? $user['id'] : $user->id;
        $email = is_array($user) ? $user['email'] : $user->email;
        $username = is_array($user) ? $user['username'] : $user->username;
        $role = is_array($user) ? $user['role'] : $user->role;

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'id' => $id,
            'email' => $email,
            'username' => $username,
            'role' => $role,
            'exp' => time() + $ttlSeconds,
        ]);

        $b64Header = $this->base64UrlEncode($header);
        $b64Payload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $this->secret, true);
        $b64Signature = $this->base64UrlEncode($signature);

        return "{$b64Header}.{$b64Payload}.{$b64Signature}";
    }

    public function verifyToken(string $token): array
    {
        if ($this->secret === '' || $this->secret === 'tu_clave_secreta') {
            throw new RuntimeException('Error de configuración de seguridad en el servidor', 500);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Token con formato inválido', 401);
        }

        [$b64Header, $b64Payload, $b64Signature] = $parts;

        $header = json_decode($this->base64UrlDecode($b64Header) ?: '', true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new RuntimeException('Algoritmo de token no soportado o inválido', 401);
        }

        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $this->secret, true)
        );

        if ($b64Signature === '' || !hash_equals($expectedSig, $b64Signature)) {
            throw new RuntimeException('Firma de token inválida', 401);
        }

        $payload = json_decode($this->base64UrlDecode($b64Payload) ?: '', true);
        if (!is_array($payload)) {
            throw new RuntimeException('Token inválido', 401);
        }

        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || $payload['exp'] < time()) {
            throw new RuntimeException('Sesión expirada, inicia sesión nuevamente', 401);
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}

