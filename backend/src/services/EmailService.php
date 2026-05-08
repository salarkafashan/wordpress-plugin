<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use RuntimeException;

final class EmailService
{
    /**
     * @param string|array $to
     */
    public function send($to, string $subject, string $body, bool $isHtml = true): void
    {
        $settings = get_option('kgr_setting', []);
        $isTesting = ($settings['general']['testing_mode'] ?? 'off') === 'on';
        $testEmailsRaw = (string) ($settings['general']['test_emails'] ?? '');
        
        if ($isTesting && trim($testEmailsRaw) !== '') {
            $parts = array_filter(array_map('trim', explode(',', $testEmailsRaw)));
            $testEmails = [];
            foreach ($parts as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false) {
                    $testEmails[] = $e;
                }
            }
            if ($testEmails !== []) {
                $subject = '[TEST MODE] ' . $subject;
                $to = implode(', ', $testEmails);
            }
        }

        $headers = [];
        if ($isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        // We use WordPress wp_mail which handles SMTP configurations through plugins like WP Mail SMTP
        $sent = wp_mail($to, $subject, $body, $headers);

        if (!$sent) {
            throw new RuntimeException("Failed to send email to {$to} via wp_mail.");
        }
    }
}
