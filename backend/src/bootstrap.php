<?php

declare(strict_types=1);

use App\config\Config;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

Config::load(BASE_PATH . '/.env');
date_default_timezone_set(Config::get('APP_TIMEZONE', 'America/Toronto'));

/*
 * Public endpoint scripts can run outside normal WP execution path.
 * Ensure WordPress core is loaded so $wpdb/get_option are available.
 */
if (!function_exists('get_option') || !isset($GLOBALS['wpdb'])) {
    $probeDir = BASE_PATH;
    for ($i = 0; $i < 8; $i++) {
        $candidate = rtrim($probeDir, '/\\') . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (is_file($candidate)) {
            require_once $candidate;
            break;
        }
        $parent = dirname($probeDir);
        if ($parent === $probeDir) {
            break;
        }
        $probeDir = $parent;
    }
}
