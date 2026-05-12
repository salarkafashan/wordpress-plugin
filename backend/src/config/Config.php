<?php

namespace App\config;

use App\helpers\Logger;
use SupportRequestFrontend\Includes\AdminController;

final class Config
{
    private static $values = [];
    private static $db_settings = null;

    public static function load($envFile)
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

    private static function loadFromDb()
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

                // Decrypt sensitive settings by field name. Some encrypted values
                // include non-base64 URL/key-like characters in the IV+ciphertext
                // bundle, so a format heuristic can leave encrypted secrets in use.
                if (self::isSensitiveField((string) $key) && is_string($value) && $value !== '') {
                    $decrypted = self::decryptSettingValue((string) $value);
                    if ($decrypted !== '') {
                        $value = $decrypted;
                    }
                }

                self::$db_settings[$configKey] = $value;
            }
        }
    }

    private static function is_encrypted($value)
    {
        // Simple heuristic for base64 encoded string from AdminController::encrypt
        return (bool) preg_match('/^[a-zA-Z0-9\/+]*={0,2}$/', $value) && strlen($value) > 32;
    }

    private static function isSensitiveField(string $field): bool
    {
        return strpos($field, 'token') !== false
            || strpos($field, 'secret') !== false
            || strpos($field, 'password') !== false
            || strpos($field, 'api_key') !== false
            || strpos($field, 'auth') !== false
            || strpos($field, 'pass') !== false;
    }

    private static function decryptSettingValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (method_exists(AdminController::class, 'decrypt')) {
            $decrypted = (string) AdminController::decrypt($value);
            if ($decrypted !== '') {
                return $decrypted;
            }
        }

        $data = base64_decode($value);
        if ($data === false) {
            return '';
        }

        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        if (!is_int($ivLen) || $ivLen <= 0 || strlen($data) <= $ivLen) {
            return '';
        }

        $iv = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        if ($iv === '' || strlen($iv) !== $ivLen || $encrypted === '') {
            return '';
        }

        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_kgr_key';
        return (string) openssl_decrypt($encrypted, 'aes-256-cbc', hash('sha256', $key, true), 0, $iv);
    }

    public static function get($key, $default = null)
    {
        self::loadFromDb();
        $key = strtoupper($key);

        // Core environment settings MUST come from .env/environment for security
        if (in_array($key, ['APP_ENV', 'WHMCS_BYPASS_LOCAL'], true)) {
            return self::$values[$key] ?? $_ENV[$key] ?? $default;
        }

        $value = self::$db_settings[$key] ?? self::$values[$key] ?? $_ENV[$key] ?? $default;

        $debug = self::$values['CAPTCHA_DEBUG'] ?? $_ENV['CAPTCHA_DEBUG'] ?? '';
        if (in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
            $traceKeys = [
                'CAPTCHA_PROVIDER',
                'GOOGLE_RECAPTCHA_TYPE',
                'GOOGLE_RECAPTCHA_SITE_KEY',
                'GOOGLE_RECAPTCHA_SECRET_KEY',
                'GOOGLE_RECAPTCHA_ENTERPRISE_PROJECT_ID',
                'GOOGLE_RECAPTCHA_ENTERPRISE_SITE_KEY',
                'GOOGLE_RECAPTCHA_ENTERPRISE_API_KEY',
                'GOOGLE_RECAPTCHA_MIN_SCORE',
            ];
            if (in_array($key, $traceKeys, true)) {
                $source = 'default';
                if (isset(self::$db_settings[$key]))
                    $source = 'db';
                elseif (isset(self::$values[$key]) || isset($_ENV[$key]))
                    $source = 'env';

                Logger::info("Config read trace: {$key}", [
                    'source' => $source,
                    'masked_value' => Logger::mask($value),
                ]);
            }
        }

        return $value;
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

    public static function getBool($key, $default = false)
    {
        $value = strtolower((string) self::get($key, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt($key, $default = 0)
    {
        return (int) self::get($key, $default);
    }
}
