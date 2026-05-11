<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\helpers\Http;
use App\helpers\Logger;
use App\helpers\Security;
use App\helpers\Validator;
use App\config\Config;
use App\services\CaptchaService;
use App\services\HoneypotService;
use App\services\RateLimiterService;
use App\services\SupportRequestService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Logger::error('Submit request rejected: invalid method', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    ]);
    Http::json(['success' => false, 'message' => 'Method not allowed'], 405);
    exit;
}

function normalizeWebsiteIssueFiles(array $allFiles): array
{
    $grouped = [];
    if (!isset($allFiles['issues']['name']) || !is_array($allFiles['issues']['name'])) {
        return $grouped;
    }
    $issueInput = $allFiles['issues'];
    foreach ($issueInput['name'] as $issueIndex => $issueFieldSet) {
        $grouped[(int) $issueIndex] = [];
        if (!isset($issueFieldSet['screenshots']) || !is_array($issueFieldSet['screenshots'])) {
            continue;
        }
        foreach ($issueFieldSet['screenshots'] as $fileIndex => $name) {
            $grouped[(int) $issueIndex][] = [
                'name' => $name,
                'type' => $issueInput['type'][$issueIndex]['screenshots'][$fileIndex] ?? '',
                'tmp_name' => $issueInput['tmp_name'][$issueIndex]['screenshots'][$fileIndex] ?? '',
                'error' => $issueInput['error'][$issueIndex]['screenshots'][$fileIndex] ?? UPLOAD_ERR_NO_FILE,
                'size' => $issueInput['size'][$issueIndex]['screenshots'][$fileIndex] ?? 0,
            ];
        }
    }
    return $grouped;
}

function normalizeFlatFiles(array $fileInput): array
{
    $files = [];
    if ($fileInput === [] || !isset($fileInput['name']) || !is_array($fileInput['name'])) {
        return $files;
    }
    foreach ($fileInput['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $fileInput['type'][$index] ?? '',
            'tmp_name' => $fileInput['tmp_name'][$index] ?? '',
            'error' => $fileInput['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $fileInput['size'][$index] ?? 0,
        ];
    }
    return $files;
}

$payload = $_POST;
$traceId = 'sub-' . bin2hex(random_bytes(6));
$payload['_trace_id'] = $traceId;
$ip = Security::getIp();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/submit-request.php'));
$projectBasePath = dirname(dirname($scriptName));
$projectBasePath = $projectBasePath === DIRECTORY_SEPARATOR ? '' : rtrim($projectBasePath, '/');
$payload['_app_base_url'] = $scheme . '://' . $host . $projectBasePath;

if (!isset($payload['issues'])) {
    $payload['issues'] = [];
}
if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
    $payload['metadata'] = [
        'browser' => '',
        'userAgent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'operatingSystem' => '',
        'screenResolution' => '',
        'language' => '',
        'timezone' => '',
    ];
}
if (($payload['service_type'] ?? '') === 'Website' && empty($payload['selected_domain']) && !empty($payload['website_url'])) {
    $payload['selected_domain'] = Validator::normalizeDomainInput((string) $payload['website_url']);
}

try {
    $rateKey = 'submit:' . $ip;
    if (!(new RateLimiterService())->check($rateKey)) {
        Logger::error('Submit request rejected: rate limit exceeded', [
            'trace_id' => $traceId,
            'ip' => $ip,
        ]);
        Http::json(['success' => false, 'message' => 'Too many requests. Please try again later.'], 429);
        exit;
    }

    $honeypot = (new HoneypotService())->verify($payload);
    if (empty($honeypot['success'])) {
        Logger::error('Submit request rejected: honeypot validation failed', [
            'trace_id' => $traceId,
            'ip' => $ip,
        ]);
        Http::json(['success' => false, 'message' => $honeypot['message'] ?? 'Spam validation failed.'], 422);
        exit;
    }

    Logger::info('Captcha submit diagnostics', [
        'trace_id' => $traceId,
        'provider' => Config::get('CAPTCHA_PROVIDER', 'none'),
        'google_type' => Config::get('GOOGLE_RECAPTCHA_TYPE', 'classic'),
        'has_captcha_token' => !empty($payload['captcha_token'] ?? null),
        'has_g_recaptcha_response' => !empty($payload['g-recaptcha-response'] ?? null),
        'token_length' => strlen((string) ($payload['captcha_token'] ?? $payload['g-recaptcha-response'] ?? '')),
    ]);

    $captcha = (new CaptchaService())->verify($payload, $ip, 'submit');
    if (empty($captcha['success'])) {
        Logger::error('Submit request rejected: captcha validation failed', [
            'trace_id' => $traceId,
            'ip' => $ip,
            'provider' => $captcha['provider'] ?? 'unknown',
            'errors' => $captcha['errors'] ?? [],
        ]);
        Http::json(['success' => false, 'message' => $captcha['message'] ?? 'Captcha validation failed.'], 422);
        exit;
    }
} catch (\Throwable $exception) {
    Logger::error('Submit request security checks failed', [
        'trace_id' => $traceId,
        'ip' => $ip,
        'error' => $exception->getMessage(),
    ]);
    Http::json(['success' => false, 'message' => 'Security validation failed. Please try again.'], 500);
    exit;
}

try {
    $websiteFiles = normalizeWebsiteIssueFiles($_FILES);
    $nonWebsiteFiles = normalizeFlatFiles($_FILES['attachments'] ?? []);

} catch (\Throwable $exception) {
    Logger::error('Submit request file normalization failed', [
        'trace_id' => $traceId,
        'error' => $exception->getMessage(),
    ]);
    Http::json(['success' => false, 'message' => 'Submit failed while preparing files.'], 500);
    exit;
}

try {
    $result = (new SupportRequestService())->submit($payload, $websiteFiles, $nonWebsiteFiles);
    $statusCode = (int) ($result['status_code'] ?? 200);
    if ($statusCode >= 400) {
        Logger::error('Submit request failed', [
            'trace_id' => $traceId,
            'status_code' => $statusCode,
            'error_keys' => array_keys($result['errors'] ?? []),
        ]);
    }
    Http::json($result, $statusCode);
} catch (\Throwable $exception) {
    Logger::error('Submit request endpoint failed', [
        'trace_id' => $traceId,
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json(['success' => false, 'message' => 'Submit failed.', 'error' => $exception->getMessage()], 500);
}
