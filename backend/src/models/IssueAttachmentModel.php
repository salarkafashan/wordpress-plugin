<?php

declare(strict_types=1);

namespace App\models;

final class IssueAttachmentModel extends BaseModel
{
    public function createMany(array $attachments): void
    {
        if ($attachments === []) {
            return;
        }
        $sql = 'INSERT INTO issue_attachments (
            request_id, issue_id, original_name, stored_name, mime_type, extension, category,
            temp_path, file_path, file_size_original, file_size_optimized, optimization_status,
            jira_attachment_status, sha256_hash, is_screenshot, retention_delete_at, created_at, updated_at
        ) VALUES (
            :request_id, :issue_id, :original_name, :stored_name, :mime_type, :extension, :category,
            :temp_path, :file_path, :file_size_original, :file_size_optimized, :optimization_status,
            :jira_attachment_status, :sha256_hash, :is_screenshot, :retention_delete_at, :created_at, :updated_at
        )';
        $stmt = $this->db->prepare($sql);
        foreach ($attachments as $attachment) {
            $stmt->execute($attachment);
        }
    }

    public function findByRequestId(int $requestId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM issue_attachments WHERE request_id = :request_id ORDER BY id ASC');
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM issue_attachments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function updateAfterOptimization(int $id, string $storedName, string $mimeType, string $extension, int $optimizedSize, string $filePath): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET stored_name = :stored_name,
                mime_type = :mime_type,
                extension = :extension,
                file_size_optimized = :file_size_optimized,
                file_path = :file_path,
                optimization_status = :optimization_status,
                updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'file_size_optimized' => $optimizedSize,
            'file_path' => $filePath,
            'optimization_status' => 'optimized',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markStoredLocal(int $id, string $filePath): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET file_path = :file_path,
                optimization_status = :optimization_status,
                updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'file_path' => $filePath,
            'optimization_status' => 'stored_local',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markOptimizationStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET optimization_status = :optimization_status, updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'optimization_status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markJiraAttached(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET jira_attachment_status = :jira_attachment_status, updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'jira_attachment_status' => 'attached_to_jira',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markJiraStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET jira_attachment_status = :jira_attachment_status, updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'jira_attachment_status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function markFailed(int $id, string $statusField, string $failedValue): void
    {
        $allowed = ['optimization_status', 'jira_attachment_status'];
        if (!in_array($statusField, $allowed, true)) {
            return;
        }
        $stmt = $this->db->prepare("UPDATE issue_attachments SET {$statusField} = :value, updated_at = :updated_at WHERE id = :id");
        $stmt->execute([
            'value' => $failedValue,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function updateTempPath(int $id, ?string $tempPath): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments SET temp_path = :temp_path, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'temp_path' => $tempPath,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function recentDuplicateHash(string $hash, int $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM issue_attachments
            WHERE request_id = :request_id AND sha256_hash = :sha256_hash
            ORDER BY id DESC LIMIT 1');
        $stmt->execute([
            'request_id' => $requestId,
            'sha256_hash' => $hash,
        ]);
        return $stmt->fetch() ?: null;
    }

    public function expiredForCleanup(string $now, int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT * FROM issue_attachments
            WHERE optimization_status IN ("stored_local", "attached_to_jira", "failed", "expired")
              AND retention_delete_at <= :now
            ORDER BY id ASC LIMIT ' . (int) $limit);
        $stmt->execute(['now' => $now]);
        return $stmt->fetchAll() ?: [];
    }

    public function markDeleted(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE issue_attachments
            SET optimization_status = :optimization_status, jira_attachment_status = :jira_attachment_status, temp_path = NULL, file_path = NULL, updated_at = :updated_at
            WHERE id = :id');
        $stmt->execute([
            'optimization_status' => 'deleted',
            'jira_attachment_status' => 'deleted',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function listForJiraAttachmentByRequest(int $requestId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM issue_attachments
            WHERE request_id = :request_id
              AND optimization_status IN ("optimized", "stored_local")
              AND jira_attachment_status IN ("pending", "failed", "stored_local", "optimized")
              AND file_path IS NOT NULL
            ORDER BY id ASC');
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll() ?: [];
    }
}
