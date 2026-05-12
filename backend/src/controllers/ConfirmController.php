<?php

declare(strict_types=1);

namespace App\controllers;

use App\helpers\Logger;
use App\services\SupportRequestService;
use Throwable;

final class ConfirmController
{
    public function handle(): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $tokenPreview = $token !== '' ? substr($token, 0, 10) . '...' : '[empty]';

        try {
            Logger::info('Confirmation endpoint hit', [
                'token_preview' => $tokenPreview,
                'has_wpdb' => isset($GLOBALS['wpdb']),
                'has_get_option' => function_exists('get_option'),
            ]);

            $service = new SupportRequestService();
            $result = $service->confirm($token);

            Logger::info('Confirmation endpoint result', [
                'token_preview' => $tokenPreview,
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
            ]);

            http_response_code($result['success'] ? 200 : 400);
            header('Content-Type: text/html; charset=utf-8');
            $html = $this->renderConfirmationPage(
                !empty($result['success']),
                (string) ($result['message'] ?? '')
            );
            echo $html;

            // Return UI to the user first, then continue heavy queue work.
            $this->finishResponseEarly($html);
            if (!empty($result['success'])) {
                $service->processPostConfirmQueue();
            }
        } catch (Throwable $exception) {
            Logger::error('Confirmation endpoint failed', [
                'token_preview' => $tokenPreview,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderConfirmationPage(false, 'An unexpected error occurred while confirming this request.');
        }
    }

    private function renderConfirmationPage(bool $success, string $message): string
    {
        $title = $success ? 'Request Confirmed' : 'Confirmation Failed';
        $icon = $success ? '&#10003;' : '!';
        $statusClass = $success ? 'kgr-state--success' : 'kgr-state--error';
        $backUrl = '/';
        if (function_exists('remove_query_arg')) {
            $backUrl = (string) remove_query_arg(['kgr_api', 'kgr_confirm_token', 'token']);
            if ($backUrl === '') {
                $backUrl = '/';
            }
        }

        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Support Confirmation</title>
  <style>
    body{margin:0;font-family:Inter,Arial,sans-serif;background:#f6f7fb;color:#0f172a}
    .kgr-wrap{max-width:760px;margin:48px auto;padding:0 16px}
    .kgr-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 8px 24px rgba(2,6,23,.06);padding:32px}
    .kgr-state{text-align:center}
    .kgr-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:700;margin:0 auto 14px}
    .kgr-state--success .kgr-icon{background:#ecfdf5;color:#047857}
    .kgr-state--error .kgr-icon{background:#fef2f2;color:#b91c1c}
    .kgr-title{margin:0 0 10px 0;font-size:28px;line-height:1.2}
    .kgr-text{margin:0 auto;max-width:580px;font-size:16px;line-height:1.5;color:#334155}
    .kgr-actions{margin-top:22px}
    .kgr-btn{display:inline-block;text-decoration:none;background:#00001A;color:#fff;padding:11px 16px;border-radius:8px;font-weight:600}
  </style>
</head>
<body>
  <div class="kgr-wrap">
    <div class="kgr-card">
      <div class="kgr-state ' . $statusClass . '">
        <div class="kgr-icon" aria-hidden="true">' . $icon . '</div>
        <h1 class="kgr-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
        <p class="kgr-text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
        <div class="kgr-actions">
          <a class="kgr-btn" href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">Back to support form</a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>';
    }

    private function finishResponseEarly(string $html): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        if (!headers_sent()) {
            header('Connection: close');
            header('Content-Length: ' . strlen($html));
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        ignore_user_abort(true);
    }
}
