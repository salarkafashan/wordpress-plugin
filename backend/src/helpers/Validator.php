<?php

declare(strict_types=1);

namespace App\helpers;

final class Validator
{
    public static function email(?string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function url(?string $value): bool
    {
        $normalized = self::normalizeUrlInput((string) $value);
        return $normalized !== '' && filter_var($normalized, FILTER_VALIDATE_URL) !== false;
    }

    public static function domainFromUrl(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        return strtolower(preg_replace('/^www\./', '', $host));
    }

    public static function normalizeDomainInput(string $input): string
    {
        $value = strtolower(trim($input));
        if ($value === '') {
            return '';
        }

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $host = (string) parse_url($value, PHP_URL_HOST);
        if ($host === '') {
            $fallback = preg_replace('#^https?://#', '', strtolower(trim($input)));
            $fallback = explode('/', $fallback)[0] ?? '';
            $fallback = explode(':', $fallback)[0] ?? '';
            $host = $fallback;
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim($host, '.');
        return $host;
    }

    public static function isValidDomainInput(string $input): bool
    {
        $domain = self::normalizeDomainInput($input);
        return $domain !== '' && (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain);
    }

    public static function fileExtension(string $fileName): string
    {
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    }

    public static function normalizeUrlInput(string $input): string
    {
        $value = trim($input);
        if ($value === '') {
            return '';
        }

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $validated = filter_var($value, FILTER_VALIDATE_URL);
        return $validated !== false ? (string) $validated : '';
    }
}
