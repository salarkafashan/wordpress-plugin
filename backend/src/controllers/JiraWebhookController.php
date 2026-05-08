<?php

declare(strict_types=1);

namespace App\controllers;

use App\config\Config;
use App\helpers\Http;
use App\models\SupportRequestModel;
use App\services\QueueService;
use App\database\Database;

final class JiraWebhookController
{
    public function handle(): void
    {
        $secret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
        if (!hash_equals((string) Config::getJiraValue('JIRA_WEBHOOK_SECRET', ''), (string) $secret)) {
            Http::json(['success' => false, 'message' => 'Unauthorized webhook.'], 401);
            return;
        }

        $payload = Http::body();
        $issueKey = $payload['issue']['key'] ?? null;
        $statusName = $payload['issue']['fields']['status']['name'] ?? '';
        if (!$issueKey || $statusName === '') {
            Http::json(['success' => true, 'message' => 'Event ignored.']);
            return;
        }

        $statusName = trim((string) $statusName);
        $allowedStatuses = $this->allowedNotifyStatuses();
        if (!in_array(strtolower($statusName), $allowedStatuses, true)) {
            Http::json(['success' => true, 'message' => 'Status not configured for notifications.']);
            return;
        }

        $requestModel = new SupportRequestModel();
        $request = $this->findRequestByIssueKey((string) $issueKey);
        if (!$request) {
            Http::json(['success' => false, 'message' => 'No request mapped to Jira issue.'], 404);
            return;
        }

        if (strtolower($statusName) === 'done') {
            $requestModel->updateStatus((int) $request['id'], 'completed');
        } else {
            $requestModel->updateStatus((int) $request['id'], 'in_progress');
        }
        $this->updateJiraStatus((int) $request['id'], $statusName);

        $queue = new QueueService();
        $queue->enqueue('send_ticket_status_email', (int) $request['id'], ['jira_status' => $statusName], 0, 5);
        Http::json(['success' => true, 'message' => 'Request updated and status notification queued.']);
    }

    private function allowedNotifyStatuses(): array
    {
        $raw = (string) Config::getJiraValue('JIRA_NOTIFY_STATUSES', 'Done,Client Review');
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique(array_map(static fn(string $status): string => strtolower($status), $parts)));
    }

    private function findRequestByIssueKey(string $issueKey): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM support_requests WHERE jira_issue_key = :issue_key LIMIT 1');
        $stmt->execute(['issue_key' => $issueKey]);
        return $stmt->fetch() ?: null;
    }

    private function updateJiraStatus(int $requestId, string $jiraStatus): void
    {
        $stmt = Database::getConnection()->prepare('UPDATE support_requests SET jira_status = :jira_status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'jira_status' => $jiraStatus,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $requestId,
        ]);
    }
}
