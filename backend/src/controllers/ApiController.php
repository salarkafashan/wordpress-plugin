<?php

declare(strict_types=1);

namespace App\controllers;

use App\helpers\Http;
use App\helpers\Logger;
use App\helpers\Security;
use App\config\Config;
use App\services\CaptchaService;
use App\services\HoneypotService;
use App\services\RateLimiterService;
use App\services\SupportRequestService;
use Throwable;

final class ApiController
{
    private SupportRequestService $supportRequestService;
    private RateLimiterService $rateLimiter;
    private CaptchaService $captchaService;
    private HoneypotService $honeypotService;

    public function __construct()
    {
        $this->supportRequestService = new SupportRequestService();
        $this->rateLimiter = new RateLimiterService();
        $this->captchaService = new CaptchaService();
        $this->honeypotService = new HoneypotService();
    }

    public function handle(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        if (Http::method() === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $ip = Security::getIp();
        if (!$this->rateLimiter->check('api:' . $ip)) {
            Http::json(['success' => false, 'message' => 'Too many requests. Please try again later.'], 429);
            return;
        }

        $action = $_GET['action'] ?? $_POST['action'] ?? 'csrf';
        if ($action === 'csrf' && Http::method() === 'GET') {
            $token = Security::csrfToken();
            Http::json(['success' => true, 'csrf_token' => $token]);
            return;
        }

        if ($action === 'domains' && Http::method() === 'POST') {
            $payload = Http::body();
            $email = (string) ($payload['email'] ?? '');
            $result = $this->supportRequestService->domainsByEmail($email);
            Http::json($result, (int) ($result['status_code'] ?? 200));
            return;
        }

        if ($action === 'suggest-issue-type' && Http::method() === 'POST') {
            $payload = Http::body();
            $description = (string) ($payload['description'] ?? '');
            $result = $this->supportRequestService->suggestIssueType($description);
            Http::json($result, (int) ($result['status_code'] ?? 200));
            return;
        }

        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!Security::verifyCsrf(is_string($csrf) ? $csrf : null)) {
            Http::json(['success' => false, 'message' => 'Invalid CSRF token.'], 419);
            return;
        }

        $payload = Http::body();
        if (isset($payload['issues']) && is_string($payload['issues'])) {
            $decoded = json_decode($payload['issues'], true);
            if (is_array($decoded)) {
                $payload['issues'] = $decoded;
            }
        }
        if (isset($payload['metadata']) && is_string($payload['metadata'])) {
            $decoded = json_decode($payload['metadata'], true);
            if (is_array($decoded)) {
                $payload['metadata'] = $decoded;
            }
        }
        try {
            if ($action === 'preview') {
                if (Config::getBool('CAPTCHA_ENFORCE_PREVIEW', false)) {
                    $this->logCaptchaDiagnostics($payload, 'preview');
                    $captcha = $this->captchaService->verify($payload, $ip, 'preview');
                    if (empty($captcha['success'])) {
                        Http::json(['success' => false, 'message' => $captcha['message'] ?? 'Captcha validation failed.'], 422);
                        return;
                    }
                }
                $result = $this->supportRequestService->preview($payload);
                Http::json(['success' => $result['valid']] + $result, $result['valid'] ? 200 : 422);
                return;
            }

            if ($action === 'submit') {
                $honeypot = $this->honeypotService->verify($payload);
                if (empty($honeypot['success'])) {
                    Http::json(['success' => false, 'message' => $honeypot['message'] ?? 'Spam validation failed.'], 422);
                    return;
                }
                $this->logCaptchaDiagnostics($payload, 'submit');
                $captcha = $this->captchaService->verify($payload, $ip, 'submit');
                if (empty($captcha['success'])) {
                    Http::json(['success' => false, 'message' => $captcha['message'] ?? 'Captcha validation failed.'], 422);
                    return;
                }
                $filesByIssue = $this->normalizeWebsiteIssueFiles($_FILES);
                $nonWebsiteFiles = $this->normalizeFlatFiles($_FILES['attachments'] ?? []);
                $result = $this->supportRequestService->submit($payload, $filesByIssue, $nonWebsiteFiles);
                Http::json($result, (int) ($result['status_code'] ?? 200));
                return;
            }

            Http::json(['success' => false, 'message' => 'Unknown action.'], 404);
        } catch (Throwable $exception) {
            Http::json(['success' => false, 'message' => 'Request failed.', 'error' => $exception->getMessage()], 500);
        }
    }

    private function logCaptchaDiagnostics(array $payload, string $context): void
    {
        Logger::info('Captcha submit diagnostics', [
            'trace_id' => $payload['_trace_id'] ?? null,
            'context' => $context,
            'provider' => Config::get('CAPTCHA_PROVIDER', 'none'),
            'google_type' => Config::get('GOOGLE_RECAPTCHA_TYPE', 'classic'),
            'has_captcha_token' => !empty($payload['captcha_token'] ?? null),
            'has_g_recaptcha_response' => !empty($payload['g-recaptcha-response'] ?? null),
            'token_length' => strlen((string) ($payload['captcha_token'] ?? $payload['g-recaptcha-response'] ?? '')),
        ]);
    }

    private function normalizeWebsiteIssueFiles(array $allFiles): array
    {
        $grouped = [];
        if (isset($allFiles['attachments']['name']) && is_array($allFiles['attachments']['name']) && is_array(reset($allFiles['attachments']['name']))) {
            $fileInput = $allFiles['attachments'];
            foreach ($fileInput['name'] as $issueIndex => $names) {
                $grouped[(int) $issueIndex] = [];
                if (!is_array($names)) {
                    continue;
                }
                foreach ($names as $fileIndex => $name) {
                    $grouped[(int) $issueIndex][] = [
                        'name' => $name,
                        'type' => $fileInput['type'][$issueIndex][$fileIndex] ?? '',
                        'tmp_name' => $fileInput['tmp_name'][$issueIndex][$fileIndex] ?? '',
                        'error' => $fileInput['error'][$issueIndex][$fileIndex] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $fileInput['size'][$issueIndex][$fileIndex] ?? 0,
                    ];
                }
            }
        }

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

    private function normalizeFlatFiles(array $fileInput): array
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
}
