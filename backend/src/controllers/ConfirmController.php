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
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Support Confirmation</title></head><body>';
            echo '<h1>' . ($result['success'] ? 'Confirmed' : 'Confirmation Failed') . '</h1>';
            echo '<p>' . htmlspecialchars((string) ($result['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
            echo '</body></html>';
        } catch (Throwable $exception) {
            Logger::error('Confirmation endpoint failed', [
                'token_preview' => $tokenPreview,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Support Confirmation</title></head><body>';
            echo '<h1>Confirmation Failed</h1>';
            echo '<p>An unexpected error occurred while confirming this request.</p>';
            echo '</body></html>';
        }
    }
}
