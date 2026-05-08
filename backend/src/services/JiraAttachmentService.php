<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use CURLFile;
use RuntimeException;

final class JiraAttachmentService
{
    public function upload(string $issueKey, array $attachment): void
    {
        $relativePath = (string) ($attachment['file_path'] ?: $attachment['temp_path'] ?? '');
        $absolutePath = BASE_PATH . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Attachment file not available for Jira upload.');
        }

        $baseUrl = rtrim((string) Config::getJiraValue('JIRA_BASE_URL', ''), '/');
        $url = $baseUrl . '/rest/api/3/issue/' . rawurlencode($issueKey) . '/attachments';
        $token = (string) Config::getJiraValue('JIRA_API_TOKEN', '');
        $user = (string) Config::getJiraValue('JIRA_API_USER', '');
        if ($baseUrl === '' || $token === '' || $user === '') {
            throw new RuntimeException('Jira attachment upload credentials are not configured.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($user . ':' . $token),
                'X-Atlassian-Token: no-check',
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile(
                    $absolutePath,
                    (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                    (string) ($attachment['stored_name'] ?? $attachment['original_name'] ?? basename($absolutePath))
                ),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('Jira attachment upload failed: ' . ($error ?: 'HTTP ' . $status));
        }
    }
}
