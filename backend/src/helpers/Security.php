<?php

declare(strict_types=1);

namespace App\helpers;

use App\config\Config;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('SUPPORTSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    public static function sanitizeString(?string $value): string
    {
        return trim(strip_tags((string) $value));
    }

    public static function getIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return trim(explode(',', $ip)[0]);
    }

    public static function basicAdminAuth(): bool
    {
        $expectedUser = (string) Config::get('ADMIN_USER');
        $expectedPass = (string) Config::get('ADMIN_PASS');
        $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
        $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

        if ($providedUser === '' && $providedPass === '') {
            $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($authHeader, 'basic ') === 0) {
                $encoded = trim(substr($authHeader, 6));
                $decoded = base64_decode($encoded, true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$providedUser, $providedPass] = explode(':', $decoded, 2);
                }
            }
        }

        return $expectedUser !== '' && $expectedPass !== '' &&
            hash_equals($expectedUser, $providedUser) &&
            hash_equals($expectedPass, $providedPass);
    }
}
