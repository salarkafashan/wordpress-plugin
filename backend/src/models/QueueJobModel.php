<?php

declare(strict_types=1);

namespace App\models;

final class QueueJobModel extends BaseModel
{
    public function enqueue(string $type, int $requestId, array $payload = [], int $delaySeconds = 0, int $maxAttempts = 5): int
    {
        $nextRunAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $stmt = $this->db->prepare('INSERT INTO ticket_queue (
            request_id, job_type, payload_json, status, attempts, max_attempts, last_error, next_run_at, locked_at, lock_token, processed_at, created_at, updated_at
        ) VALUES (
            :request_id, :job_type, :payload_json, :status, :attempts, :max_attempts, :last_error, :next_run_at, :locked_at, :lock_token, :processed_at, :created_at, :updated_at
        )');
        $stmt->execute([
            'request_id' => $requestId,
            'job_type' => $type,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'last_error' => null,
            'next_run_at' => $nextRunAt,
            'locked_at' => null,
            'lock_token' => null,
            'processed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function claimJobs(array $jobTypes = [], int $limit = 20): array
    {
        $sql = 'SELECT * FROM ticket_queue
            WHERE status IN ("pending", "retry")
              AND next_run_at <= :now
              AND (locked_at IS NULL OR locked_at <= :stale_lock)';
        $params = [
            'now' => date('Y-m-d H:i:s'),
            'stale_lock' => date('Y-m-d H:i:s', time() - 300),
        ];
        if ($jobTypes !== []) {
            $placeholders = [];
            foreach ($jobTypes as $index => $type) {
                $key = ':type_' . $index;
                $placeholders[] = $key;
                $params[ltrim($key, ':')] = $type;
            }
            $sql .= ' AND job_type IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll() ?: [];

        $claimed = [];
        foreach ($jobs as $job) {
            $token = bin2hex(random_bytes(16));
            $update = $this->db->prepare('UPDATE ticket_queue
                SET status = :status, locked_at = :locked_at, lock_token = :lock_token, updated_at = :updated_at
                WHERE id = :id
                  AND status IN ("pending", "retry")
                  AND (lock_token IS NULL OR locked_at IS NULL OR locked_at <= :stale_lock)');
            $update->execute([
                'status' => 'processing',
                'locked_at' => date('Y-m-d H:i:s'),
                'lock_token' => $token,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $job['id'],
                'stale_lock' => date('Y-m-d H:i:s', time() - 300),
            ]);
            if ($update->rowCount() === 1) {
                $job['lock_token'] = $token;
                $claimed[] = $job;
            }
        }
        return $claimed;
    }

    public function markCompleted(int $id, string $lockToken): void
    {
        $stmt = $this->db->prepare('UPDATE ticket_queue
            SET status = :status, processed_at = :processed_at, locked_at = NULL, lock_token = NULL, updated_at = :updated_at
            WHERE id = :id AND lock_token = :lock_token');
        $stmt->execute([
            'status' => 'completed',
            'processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
            'lock_token' => $lockToken,
        ]);
    }

    public function markRetryOrFailed(int $id, string $lockToken, int $attempts, int $maxAttempts, string $lastError): string
    {
        if ($attempts >= $maxAttempts) {
            $stmt = $this->db->prepare('UPDATE ticket_queue
                SET status = :status, attempts = :attempts, last_error = :last_error, processed_at = :processed_at, locked_at = NULL, lock_token = NULL, updated_at = :updated_at
                WHERE id = :id AND lock_token = :lock_token');
            $stmt->execute([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $lastError,
                'processed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
                'lock_token' => $lockToken,
            ]);
            return 'failed';
        }

        $delay = (int) pow(2, min($attempts, 8)) * 60;
        $stmt = $this->db->prepare('UPDATE ticket_queue
            SET status = :status, attempts = :attempts, last_error = :last_error, next_run_at = :next_run_at, locked_at = NULL, lock_token = NULL, updated_at = :updated_at
            WHERE id = :id AND lock_token = :lock_token');
        $stmt->execute([
            'status' => 'retry',
            'attempts' => $attempts,
            'last_error' => $lastError,
            'next_run_at' => date('Y-m-d H:i:s', time() + $delay),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
            'lock_token' => $lockToken,
        ]);
        return 'retry';
    }
}
