<?php

namespace App\helpers;

use App\config\Config;

final class Logger
{
    public static function info($message, $context = [])
    {
        if (Config::getBool('CAPTCHA_DEBUG', false) || Config::getBool('APP_DEBUG', false)) {
            self::write('INFO', $message, $context);
        }
    }

    public static function warning($message, $context = [])
    {
        self::write('WARNING', $message, $context);
    }

    public static function mask($value)
    {
        $val = trim((string) $value);
        $len = strlen($val);
        if ($len === 0) {
            return array('exists' => false, 'length' => 0, 'first4' => '', 'last6' => '');
        }
        return array(
            'exists' => true,
            'length' => $len,
            'first4' => substr($val, 0, 4),
            'last6' => $len >= 6 ? substr($val, -6) : $val,
        );
    }

    public static function error($message, $context = [])
    {
        self::write('ERROR', $message, $context);
    }

    public static function getPath(): string
    {
        return BASE_PATH . '/' . ltrim((string) Config::get('LOG_PATH', 'logs/app.log'), '/');
    }

    public static function clear(): void
    {
        $path = self::getPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, '');
    }

    private static function write($level, $message, $context = [])
    {
        $path = self::getPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            (!empty($context)) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        file_put_contents($path, $line, FILE_APPEND);
    }
}
