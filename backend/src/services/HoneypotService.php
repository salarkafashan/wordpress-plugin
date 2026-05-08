<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;

final class HoneypotService
{
    public function verify(array $payload): array
    {
        if (!Config::getBool('HONEYPOT_ENABLED', true)) {
            return ['success' => true];
        }

        $fieldName = (string) Config::get('HONEYPOT_FIELD_NAME', 'company_website');
        $honeypotValue = trim((string) ($payload[$fieldName] ?? ''));
        if ($honeypotValue !== '') {
            return ['success' => false, 'message' => 'Spam validation failed.'];
        }

        $startedAt = (int) ($payload['form_started_at'] ?? 0);
        if ($startedAt <= 0) {
            return ['success' => false, 'message' => 'Spam validation failed.'];
        }

        $elapsed = time() - $startedAt;
        $min = Config::getInt('HONEYPOT_MIN_SECONDS', 2);
        $max = Config::getInt('HONEYPOT_MAX_SECONDS', 7200);
        if ($elapsed < $min || $elapsed > $max) {
            return ['success' => false, 'message' => 'Spam validation failed.'];
        }

        return ['success' => true];
    }
}
