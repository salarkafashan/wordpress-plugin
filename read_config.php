<?php
require_once __DIR__ . '/backend/src/bootstrap.php';
use App\config\Config;

$keys = [
    'CAPTCHA_PROVIDER',
    'GOOGLE_RECAPTCHA_TYPE',
    'GOOGLE_RECAPTCHA_ENTERPRISE_PROJECT_ID',
    'GOOGLE_RECAPTCHA_ENTERPRISE_SITE_KEY',
    'GOOGLE_RECAPTCHA_ENTERPRISE_API_KEY',
    'GOOGLE_RECAPTCHA_SITE_KEY',
    'GOOGLE_RECAPTCHA_SECRET_KEY',
    'GOOGLE_RECAPTCHA_EXPECTED_ACTION'
];

$results = [];
foreach ($keys as $key) {
    $val = (string) Config::get($key, '');
    if (strpos($key, 'KEY') !== false || strpos($key, 'SECRET') !== false) {
        $results[$key] = $val !== '' ? '...' . substr($val, -6) : '[EMPTY]';
    } else {
        $results[$key] = $val !== '' ? $val : '[EMPTY]';
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
