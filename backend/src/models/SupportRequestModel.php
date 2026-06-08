<?php

declare(strict_types=1);

namespace App\models;

final class SupportRequestModel extends BaseModel
{
    public function create(array $request): int
    {
        $sql = 'INSERT INTO support_requests (
            public_id, client_id, client_whmcs_id, submitted_email, verified_email, confirmation_sent_to,
            client_name, client_company, website_domain, status, duplicate_hash, duplicate_override,
            metadata_json, confirmation_token_hash, confirmation_expires_at, jira_issue_key, jira_status,
            confirmed_at, created_at, updated_at
        ) VALUES (
            :public_id, :client_id, :client_whmcs_id, :submitted_email, :verified_email, :confirmation_sent_to,
            :client_name, :client_company, :website_domain, :status, :duplicate_hash, :duplicate_override,
            :metadata_json, :confirmation_token_hash, :confirmation_expires_at, :jira_issue_key, :jira_status,
            :confirmed_at, :created_at, :updated_at
        )';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($request);
        return (int) $this->db->lastInsertId();
    }

    public function findByDuplicateHash(string $hash, int $hours): ?array
    {
        $sql = 'SELECT * FROM support_requests WHERE duplicate_hash = :hash AND created_at >= :since_time ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'hash' => $hash,
            'since_time' => date('Y-m-d H:i:s', time() - ($hours * 3600)),
        ]);
        return $stmt->fetch() ?: null;
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_requests WHERE confirmation_token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => $tokenHash]);
        return $stmt->fetch() ?: null;
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_requests WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function updateStatus(int $id, string $status): void
    {
        $sql = 'UPDATE support_requests SET status = :status, updated_at = :updated_at';
        $params = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id];
        if ($status === 'confirmed') {
            $sql .= ', confirmed_at = :confirmed_at';
            $params['confirmed_at'] = date('Y-m-d H:i:s');
        }
        $sql .= ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function updateJira(int $id, string $issueKey, string $jiraStatus): void
    {
        $stmt = $this->db->prepare('UPDATE support_requests SET jira_issue_key = :issue_key, jira_status = :jira_status, status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'issue_key' => $issueKey,
            'jira_status' => $jiraStatus,
            'status' => 'ticket_created',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function refreshConfirmation(int $id, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare('UPDATE support_requests SET confirmation_token_hash = :token_hash, confirmation_expires_at = :expires_at, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markCompletedByIssueKey(string $issueKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_requests WHERE jira_issue_key = :issue_key LIMIT 1');
        $stmt->execute(['issue_key' => $issueKey]);
        $request = $stmt->fetch();
        if (!$request) {
            return null;
        }
        $this->updateStatus((int) $request['id'], 'completed');
        $update = $this->db->prepare('UPDATE support_requests SET jira_status = :jira_status WHERE id = :id');
        $update->execute(['jira_status' => 'Done', 'id' => $request['id']]);
        return $request;
    }

    public function pendingReminderCandidates(int $hours): array
    {
        $sql = 'SELECT * FROM support_requests
            WHERE status = "pending_confirmation"
            AND created_at <= :reminder_threshold
            AND created_at > :expiry_threshold
            AND id NOT IN (
                SELECT request_id FROM ticket_queue WHERE job_type = "send_confirmation_reminder"
            )';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'reminder_threshold' => date('Y-m-d H:i:s', time() - ($hours * 3600)),
            'expiry_threshold' => date('Y-m-d H:i:s', time() - (24 * 3600)),
        ]);
        return $stmt->fetchAll() ?: [];
    }

    public function expireOldPending(): int
    {
        $stmt = $this->db->prepare('UPDATE support_requests SET status = "expired", updated_at = :updated_at WHERE status = "pending_confirmation" AND created_at <= :expiry_threshold');
        $stmt->execute([
            'updated_at' => date('Y-m-d H:i:s'),
            'expiry_threshold' => date('Y-m-d H:i:s', time() - (24 * 3600)),
        ]);
        return $stmt->rowCount();
    }

    public function getStats(): array
    {
        $counts = [
            'pending_confirmations' => $this->scalar('SELECT COUNT(*) FROM support_requests WHERE status = "pending_confirmation"'),
            'unconfirmed_requests' => $this->scalar('SELECT COUNT(*) FROM support_requests WHERE status IN ("pending_confirmation", "expired")'),
            'queued_jobs' => $this->scalar('SELECT COUNT(*) FROM ticket_queue WHERE status IN ("pending", "retry")'),
            'failed_jobs' => $this->scalar('SELECT COUNT(*) FROM ticket_queue WHERE status = "failed"'),
        ];
        $recentStmt = $this->db->query('SELECT id, public_id, submitted_email, website_domain, status, jira_issue_key, created_at FROM support_requests ORDER BY id DESC LIMIT 20');
        $counts['recent_requests'] = $recentStmt->fetchAll() ?: [];
        return $counts;
    }

    public function oldTerminalRequests(int $months = 8, int $limit = 200): array
    {
        $sql = 'SELECT id, public_id, status, jira_status, updated_at
            FROM support_requests
            WHERE (
                status IN ("completed", "canceled", "cancelled", "done")
                OR lower(coalesce(jira_status, \'\')) IN ("done", "canceled", "cancelled")
            )
            AND updated_at <= :before_time
            ORDER BY id ASC
            LIMIT ' . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'before_time' => date('Y-m-d H:i:s', strtotime('-' . $months . ' months')),
        ]);
        return $stmt->fetchAll() ?: [];
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM support_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }
}
