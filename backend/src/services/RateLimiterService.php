<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use App\database\Database;

final class RateLimiterService
{
    private object $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function check(string $key): bool
    {
        $window = Config::getInt('RATE_LIMIT_WINDOW_SECONDS', 300);
        $max = Config::getInt('RATE_LIMIT_MAX_REQUESTS', 20);
        $now = time();
        $windowStart = $now - $window;

        $cleanup = $this->db->prepare('DELETE FROM rate_limits WHERE created_at_unix < :window_start');
        $cleanup->execute(['window_start' => $windowStart]);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE limiter_key = :limiter_key AND created_at_unix >= :window_start');
        $countStmt->execute(['limiter_key' => $key, 'window_start' => $windowStart]);
        $count = (int) $countStmt->fetchColumn();
        if ($count >= $max) {
            return false;
        }

        $insert = $this->db->prepare('INSERT INTO rate_limits (limiter_key, created_at_unix) VALUES (:limiter_key, :created_at_unix)');
        $insert->execute(['limiter_key' => $key, 'created_at_unix' => $now]);
        return true;
    }
}
