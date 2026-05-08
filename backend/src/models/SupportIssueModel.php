<?php

declare(strict_types=1);

namespace App\models;

final class SupportIssueModel extends BaseModel
{
    public function createMany(int $requestId, array $issues): array
    {
        $ids = [];
        $sql = 'INSERT INTO support_issues (
            request_id, issue_type, urgency_level, page_url, description,
            current_content, new_content, suggested_issue_type, created_at
        ) VALUES (
            :request_id, :issue_type, :urgency_level, :page_url, :description,
            :current_content, :new_content, :suggested_issue_type, :created_at
        )';
        $stmt = $this->db->prepare($sql);

        foreach ($issues as $issue) {
            $stmt->execute([
                'request_id' => $requestId,
                'issue_type' => $issue['issue_type'],
                'urgency_level' => $issue['urgency_level'],
                'page_url' => $issue['page_url'],
                'description' => $issue['description'],
                'current_content' => $issue['current_content'] ?? null,
                'new_content' => $issue['new_content'] ?? null,
                'suggested_issue_type' => $issue['suggested_issue_type'] ?? 'Other',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ids[] = (int) $this->db->lastInsertId();
        }
        return $ids;
    }

    public function findByRequestId(int $requestId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_issues WHERE request_id = :request_id ORDER BY id ASC');
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll() ?: [];
    }
}
