<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use App\helpers\Logger;

final class CaptchaService
{
    public function verify(array $payload, string $ip, string $context = 'submit'): array
    {
        $provider = strtolower((string) Config::get('CAPTCHA_PROVIDER', 'none'));
        if ($provider === '' || $provider === 'none') {
            return ['success' => true, 'provider' => 'none'];
        }

        $token = $this->extractToken($payload);
        if ($token === '') {
            return [
                'success' => false,
                'message' => 'Captcha verification could not start. Please refresh the page and try again.',
                'provider' => $provider,
                'errors' => ['missing-input-response'],
            ];
        }

        if ($provider === 'cloudflare') {
            return $this->verifyCloudflare($token, $ip);
        }
        if ($provider === 'google') {
            return $this->verifyGoogle($token, $ip, $context);
        }

        return ['success' => false, 'message' => 'Unsupported CAPTCHA provider: ' . $provider, 'provider' => $provider];
    }

    private function extractToken(array $payload): string
    {
        $token = $payload['captcha_token']
            ?? $payload['cf-turnstile-response']
            ?? $payload['g-recaptcha-response']
            ?? '';
        return trim((string) $token);
    }

    private function verifyCloudflare(string $token, string $ip): array
    {
        $secret = trim((string) Config::get('CLOUDFLARE_TURNSTILE_SECRET_KEY'));
        if ($secret === '') {
            return [
                'success' => false,
                'provider' => 'cloudflare',
                'message' => 'Captcha is not configured correctly. Please contact support.',
                'errors' => ['missing-input-secret'],
            ];
        }

        $response = $this->postForm('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'cloudflare',
                'message' => $this->captchaFailureMessage('cloudflare', $response['error-codes'] ?? []),
                'errors' => $response['error-codes'] ?? [],
            ];
        }
        return ['success' => true, 'provider' => 'cloudflare'];
    }

    private function verifyGoogle(string $token, string $ip, string $context): array
    {
        $type = strtolower(trim((string) Config::get('GOOGLE_RECAPTCHA_TYPE', 'classic')));
        if ($type === 'enterprise') {
            return $this->verifyGoogleEnterprise($token, $ip, $context);
        }

        return $this->verifyGoogleClassic($token, $ip, $context);
    }

    private function verifyGoogleClassic(string $token, string $ip, string $context): array
    {
        $secret = trim((string) Config::get('GOOGLE_RECAPTCHA_SECRET_KEY'));
        if ($secret === '') {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA is not configured correctly. Please contact support.',
                'errors' => ['missing-input-secret'],
            ];
        }

        $response = $this->postForm('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        Logger::info('Google classic captcha verify result', [
            'success' => !empty($response['success']),
            'errors' => $response['error-codes'] ?? [],
            'score' => $response['score'] ?? null,
            'action' => $response['action'] ?? null,
            'hostname' => $response['hostname'] ?? null,
        ]);
        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => $this->captchaFailureMessage('google', $response['error-codes'] ?? []),
                'errors' => $response['error-codes'] ?? [],
            ];
        }

        $minScore = (float) Config::get('GOOGLE_RECAPTCHA_MIN_SCORE', 0.5);
        $expectedAction = (string) Config::get('GOOGLE_RECAPTCHA_EXPECTED_ACTION', 'submit');
        if (isset($response['score']) && (float) $response['score'] < $minScore) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA marked this submission as suspicious. Please try again, or contact support if it keeps happening.',
                'errors' => ['score-too-low'],
            ];
        }
        if (($context === 'submit') && isset($response['action']) && $response['action'] !== '' && $response['action'] !== $expectedAction) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA returned an unexpected action. Please refresh the page and try again.',
                'errors' => ['action-mismatch'],
            ];
        }

        return ['success' => true, 'provider' => 'google'];
    }

    private function verifyGoogleEnterprise(string $token, string $ip, string $context): array
    {
        $siteKey = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_SITE_KEY'));
        $projectId = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_PROJECT_ID'));
        $apiKey = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_API_KEY'));
        $expectedAction = 'submit'; // Force exactly 'submit' for Enterprise

        if ($siteKey === '' || $projectId === '' || $apiKey === '') {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA Enterprise is not configured correctly. Please contact support.',
                'errors' => ['missing-enterprise-config'],
            ];
        }

        $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);

        Logger::info('Preparing Google reCAPTCHA Enterprise assessment request', [
            'google_type' => 'enterprise',
            'project_id' => $projectId,
            'enterprise_site_key_last_6' => substr($siteKey, -6),
            'api_key_last_6' => substr($apiKey, -6),
            'token_length' => strlen($token),
            'expected_action' => $expectedAction,
        ]);

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => wp_json_encode([
                'event' => [
                    'token' => $token,
                    'siteKey' => $siteKey,
                    'expectedAction' => $expectedAction,
                ],
            ]),
            'timeout' => (int) Config::get('CAPTCHA_VERIFY_TIMEOUT', 10),
        ]);

        if (is_wp_error($response)) {
            Logger::error('Google reCAPTCHA Enterprise request failed', ['error' => $response->get_error_message()]);
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Captcha verification service could not be reached. Please try again.',
                'errors' => ['request-failed'],
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) $body, true);

        if ($status < 200 || $status >= 300 || (isset($decoded['error']) && is_array($decoded['error']))) {
            $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            Logger::error('Google reCAPTCHA Enterprise API error response', [
                'http_status' => $status,
                'code' => $err['code'] ?? null,
                'message' => $err['message'] ?? null,
                'status' => $err['status'] ?? null,
                'details' => $err['details'] ?? null,
            ]);

            return [
                'success' => false,
                'provider' => 'google',
                'message' => $this->googleEnterpriseApiErrorMessage($err),
                'errors' => [(string) ($err['status'] ?? 'enterprise-api-error')],
            ];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Invalid response from Google reCAPTCHA Enterprise.',
                'errors' => ['invalid-response'],
            ];
        }

        $tokenProperties = is_array($decoded['tokenProperties'] ?? null) ? $decoded['tokenProperties'] : [];
        $riskAnalysis = is_array($decoded['riskAnalysis'] ?? null) ? $decoded['riskAnalysis'] : [];

        Logger::info('Google reCAPTCHA Enterprise verify result', [
            'valid' => $tokenProperties['valid'] ?? null,
            'invalid_reason' => $tokenProperties['invalidReason'] ?? null,
            'action' => $tokenProperties['action'] ?? null,
            'hostname' => $tokenProperties['hostname'] ?? null,
            'score' => $riskAnalysis['score'] ?? null,
            'reasons' => $riskAnalysis['reasons'] ?? [],
        ]);

        if (empty($tokenProperties['valid'])) {
            $reason = (string) ($tokenProperties['invalidReason'] ?? 'invalid-input-response');
            return [
                'success' => false,
                'provider' => 'google',
                'message' => $this->captchaFailureMessage('google', [$reason]),
                'errors' => [$reason],
            ];
        }

        $action = (string) ($tokenProperties['action'] ?? '');
        if ($context === 'submit' && $action !== '' && $action !== $expectedAction) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA returned an unexpected action. Please refresh the page and try again.',
                'errors' => ['action-mismatch'],
            ];
        }

        $minScore = (float) Config::get('GOOGLE_RECAPTCHA_MIN_SCORE', 0.5);
        if (isset($riskAnalysis['score']) && (float) $riskAnalysis['score'] < $minScore) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Google reCAPTCHA marked this submission as suspicious. Please try again, or contact support if it keeps happening.',
                'errors' => ['score-too-low'],
            ];
        }

        return ['success' => true, 'provider' => 'google'];
    }

    private function captchaFailureMessage(string $provider, array $errors): string
    {
        $errors = array_values(array_map('strval', $errors));
        if (in_array('missing-input-secret', $errors, true) || in_array('invalid-input-secret', $errors, true)) {
            return ucfirst($provider) . ' captcha secret is not valid. Please contact support.';
        }
        if (in_array('missing-input-response', $errors, true)) {
            return 'Captcha verification is missing. Please refresh the page and try again.';
        }
        if (in_array('invalid-input-response', $errors, true)) {
            return 'Captcha verification expired or was rejected. Please refresh the page and submit the form again.';
        }
        if (in_array('EXPIRED', $errors, true) || in_array('DUPE', $errors, true)) {
            return 'Captcha verification expired. Please submit the form again.';
        }
        if (in_array('MALFORMED', $errors, true) || in_array('INVALID_REASON_UNSPECIFIED', $errors, true)) {
            return 'Captcha verification was rejected. Please refresh the page and submit the form again.';
        }
        if (in_array('bad-request', $errors, true) || in_array('captcha-invalid-response', $errors, true)) {
            return 'Captcha verification returned an invalid response. Please try again.';
        }
        if (in_array('timeout-or-duplicate', $errors, true)) {
            return 'Captcha verification expired. Please submit the form again.';
        }
        if (in_array('captcha-request-failed', $errors, true)) {
            return 'Captcha verification service could not be reached. Please try again in a moment.';
        }

        return 'Captcha validation failed. Please refresh the page and try again.';
    }

    private function googleEnterpriseApiErrorMessage(array $error): string
    {
        $status = (string) ($error['status'] ?? '');
        if (in_array($status, ['PERMISSION_DENIED', 'UNAUTHENTICATED'], true)) {
            return 'Google reCAPTCHA Enterprise API credentials were rejected. Please contact support.';
        }
        if ($status === 'NOT_FOUND' || $status === 'INVALID_ARGUMENT') {
            return 'Google reCAPTCHA Enterprise project or site key is not valid. Please contact support.';
        }

        return 'Google reCAPTCHA Enterprise verification failed. Please try again.';
    }

    private function postForm(string $url, array $data): array
    {
        $timeout = (int) Config::get('CAPTCHA_VERIFY_TIMEOUT', 10);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            return ['success' => false, 'error-codes' => ['captcha-request-failed']];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['success' => false, 'error-codes' => ['captcha-invalid-response']];
    }


}
