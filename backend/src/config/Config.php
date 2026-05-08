<?php

declare(strict_types=1);

namespace App\config;

use SupportRequestFrontend\Includes\AdminController;

final class Config
{
    private static array $values = [];
    private static ?array $db_settings = null;

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || substr($trimmed, 0, 1) === '#') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            self::$values[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    private static function loadFromDb(): void
    {
        if (self::$db_settings !== null)
            return;

        $settings = get_option('kgr_setting', []);
        self::$db_settings = [];

        if (!is_array($settings))
            return;

        foreach ($settings as $tab => $fields) {
            if (!is_array($fields))
                continue;
            foreach ($fields as $key => $value) {
                $configKey = strtoupper($key);

                // Decrypt if it's sensitive
                if (method_exists(AdminController::class, 'decrypt') && self::is_encrypted($value)) {
                    $decrypted = (string) AdminController::decrypt((string) $value);
                    if ($decrypted !== '') {
                        $value = $decrypted;
                    }
                }

                self::$db_settings[$configKey] = $value;
            }
        }
    }

    private static function is_encrypted(string $value): bool
    {
        // Simple heuristic for base64 encoded string from AdminController::encrypt
        return (bool) preg_match('/^[a-zA-Z0-9\/+]*={0,2}$/', $value) && strlen($value) > 32;
    }

    public static function get(string $key, $default = null)
    {
        self::loadFromDb();
        $key = strtoupper($key);

        // Core environment settings MUST come from .env/environment for security
        if (in_array($key, ['APP_ENV', 'WHMCS_BYPASS_LOCAL'], true)) {
            return self::$values[$key] ?? $_ENV[$key] ?? $default;
        }

        return self::$db_settings[$key] ?? self::$values[$key] ?? $_ENV[$key] ?? $default;
    }

    public static function getEnvValue(string $key, $default = null)
    {
        $key = strtoupper($key);
        return self::$values[$key] ?? $_ENV[$key] ?? $default;
    }

    /**
     * Resolve Jira setting values with strict priority:
     * 1) WP settings option (jira tab)
     * 2) environment (.env / $_ENV)
     */
    public static function getJiraValue(string $key, $default = null)
    {
        $normalizedKey = strtoupper($key);
        $optionField = strtolower($normalizedKey);
        $settings = get_option('kgr_setting', []);
        if (is_array($settings) && isset($settings['jira']) && is_array($settings['jira'])) {
            $jira = $settings['jira'];
            if (array_key_exists($optionField, $jira)) {
                $dbValue = $jira[$optionField];
                if (is_string($dbValue)) {
                    $dbValue = trim($dbValue);
                }

                // Sensitive Jira values are encrypted in settings storage.
                if (is_string($dbValue) && $dbValue !== '' && self::isJiraSensitiveField($optionField) && method_exists(AdminController::class, 'decrypt')) {
                    $decrypted = AdminController::decrypt($dbValue);
                    if ($decrypted !== '') {
                        $dbValue = $decrypted;
                    }
                }

                if ((string) $dbValue !== '') {
                    return $dbValue;
                }
            }
        }

        return self::$values[$normalizedKey] ?? $_ENV[$normalizedKey] ?? $default;
    }

    /**
     * Resolve WHMCS setting values with strict priority:
     * 1) WP settings option (whmcs tab)
     * 2) environment (.env / $_ENV)
     */
    public static function getWhmcsValue(string $key, $default = null)
    {
        $normalizedKey = strtoupper($key);
        $optionField = strtolower($normalizedKey);
        $settings = get_option('kgr_setting', []);
        if (is_array($settings) && isset($settings['whmcs']) && is_array($settings['whmcs'])) {
            $whmcs = $settings['whmcs'];
            if (array_key_exists($optionField, $whmcs)) {
                $dbValue = $whmcs[$optionField];
                if (is_string($dbValue)) {
                    $dbValue = trim($dbValue);
                }

                if (is_string($dbValue) && $dbValue !== '' && self::isWhmcsSensitiveField($optionField) && method_exists(AdminController::class, 'decrypt')) {
                    $decrypted = (string) AdminController::decrypt($dbValue);
                    if ($decrypted !== '') {
                        $dbValue = $decrypted;
                    }
                }

                if ((string) $dbValue !== '') {
                    return $dbValue;
                }
            }
        }

        return self::$values[$normalizedKey] ?? $_ENV[$normalizedKey] ?? $default;
    }

    /**
     * Debug helper for Jira settings resolution without exposing secrets.
     *
     * @param array<int, string> $keys
     * @return array<string, array<string, mixed>>
     */
    public static function getJiraDiagnostics(array $keys): array
    {
        self::loadFromDb();
        $settings = get_option('kgr_setting', []);
        $jiraSettings = is_array($settings) && isset($settings['jira']) && is_array($settings['jira']) ? $settings['jira'] : [];
        $diagnostics = [];

        foreach ($keys as $key) {
            $normalizedKey = strtoupper($key);
            $optionField = strtolower($normalizedKey);
            $dbHasKey = array_key_exists($optionField, $jiraSettings);
            $rawDbValue = $dbHasKey ? $jiraSettings[$optionField] : '';
            $dbValue = is_scalar($rawDbValue) ? trim((string) $rawDbValue) : '';
            if ($dbValue !== '' && self::isJiraSensitiveField($optionField) && method_exists(AdminController::class, 'decrypt')) {
                $decrypted = (string) AdminController::decrypt($dbValue);
                if ($decrypted !== '') {
                    $dbValue = $decrypted;
                }
            }

            $envValue = trim((string) (self::$values[$normalizedKey] ?? $_ENV[$normalizedKey] ?? ''));
            $resolvedValue = trim((string) self::getJiraValue($normalizedKey, ''));
            $source = 'default';
            if ($dbValue !== '') {
                $source = 'db';
            } elseif ($envValue !== '') {
                $source = 'env';
            }

            $diagnostics[$normalizedKey] = [
                'source' => $source,
                'db_has_key' => $dbHasKey,
                'db_non_empty' => $dbValue !== '',
                'env_non_empty' => $envValue !== '',
                'resolved_non_empty' => $resolvedValue !== '',
                'resolved_length' => strlen($resolvedValue),
                'resolved_preview' => self::maskDiagnosticValue($normalizedKey, $resolvedValue),
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<int, string> $keys
     * @return array{values: array<string, string>, missing: array<int, string>}
     */
    public static function getRequiredJiraValues(array $keys): array
    {
        $values = [];
        $missing = [];

        foreach ($keys as $key) {
            $value = trim((string) self::getJiraValue($key, ''));
            $values[$key] = $value;
            if ($value === '') {
                $missing[] = $key;
            }
        }

        return [
            'values' => $values,
            'missing' => $missing,
        ];
    }

    private static function isJiraSensitiveField(string $field): bool
    {
        return strpos($field, 'token') !== false || strpos($field, 'secret') !== false || strpos($field, 'password') !== false;
    }

    private static function isWhmcsSensitiveField(string $field): bool
    {
        return strpos($field, 'token') !== false || strpos($field, 'secret') !== false || strpos($field, 'password') !== false || strpos($field, 'api_key') !== false;
    }

    private static function maskDiagnosticValue(string $key, string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($key === 'JIRA_BASE_URL') {
            $host = (string) parse_url($value, PHP_URL_HOST);
            return $host !== '' ? $host : '[invalid-url]';
        }
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($value, -2);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) self::get($key, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }
}
