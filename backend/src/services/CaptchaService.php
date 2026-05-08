<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;

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
            return ['success' => false, 'message' => 'Captcha token is required.', 'provider' => $provider];
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
        $secret = (string) Config::get('CLOUDFLARE_TURNSTILE_SECRET_KEY');
        $response = $this->postForm('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'cloudflare',
                'message' => 'Captcha validation failed.',
                'errors' => $response['error-codes'] ?? [],
            ];
        }
        return ['success' => true, 'provider' => 'cloudflare'];
    }

    private function verifyGoogle(string $token, string $ip, string $context): array
    {
        $secret = (string) Config::get('GOOGLE_RECAPTCHA_SECRET_KEY');
        $response = $this->postForm('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'google',
                'message' => 'Captcha validation failed.',
                'errors' => $response['error-codes'] ?? [],
            ];
        }

        $minScore = (float) Config::get('GOOGLE_RECAPTCHA_MIN_SCORE', 0.5);
        $expectedAction = (string) Config::get('GOOGLE_RECAPTCHA_EXPECTED_ACTION', 'support_submit');
        if (isset($response['score']) && (float) $response['score'] < $minScore) {
            return ['success' => false, 'provider' => 'google', 'message' => 'Captcha score too low.'];
        }
        if (($context === 'submit') && isset($response['action']) && $response['action'] !== '' && $response['action'] !== $expectedAction) {
            return ['success' => false, 'provider' => 'google', 'message' => 'Captcha action mismatch.'];
        }

        return ['success' => true, 'provider' => 'google'];
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
