<?php

declare(strict_types=1);

namespace App\models;

final class ClientJiraMapModel extends BaseModel
{
    private function findByWhmcsClientId(int $whmcsClientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_jira_maps WHERE whmcs_client_id = :whmcs_client_id LIMIT 1');
        $stmt->execute(['whmcs_client_id' => $whmcsClientId]);
        return $stmt->fetch() ?: null;
    }

    public function findActiveByWhmcsClientId(int $whmcsClientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_jira_maps WHERE whmcs_client_id = :whmcs_client_id AND is_active = 1 LIMIT 1');
        $stmt->execute(['whmcs_client_id' => $whmcsClientId]);
        return $stmt->fetch() ?: null;
    }

    public function findActiveByWebsiteUrl(string $websiteUrl): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_jira_maps WHERE website_url = :website_url AND is_active = 1 LIMIT 1');
        $stmt->execute(['website_url' => strtolower($websiteUrl)]);
        return $stmt->fetch() ?: null;
    }

    public function upsertByWhmcsClientId(array $row): int
    {
        $jiraProjectId = (string) ($row['jira_project_id'] ?? '');
        if ($jiraProjectId !== '') {
            $existingByProject = $this->findByJiraProjectId($jiraProjectId);
            if ($existingByProject) {
                $this->updateById((int) $existingByProject['id'], $row);
                return (int) $existingByProject['id'];
            }
        }

        $existing = $this->findByWhmcsClientId((int) $row['whmcs_client_id']);
        if ($existing) {
            $this->updateByWhmcsClientId((int) $row['whmcs_client_id'], $row);
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare('INSERT INTO client_jira_maps (
            whmcs_client_id, jira_project_id, jira_project_key, jira_project_name, jira_board_id,
            jira_space_name, website_url, client_company_name, is_active, mapping_source, notes, created_at, updated_at
        ) VALUES (
            :whmcs_client_id, :jira_project_id, :jira_project_key, :jira_project_name, :jira_board_id,
            :jira_space_name, :website_url, :client_company_name, :is_active, :mapping_source, :notes, :created_at, :updated_at
        )');
        $stmt->execute([
            'whmcs_client_id' => (int) $row['whmcs_client_id'],
            'jira_project_id' => $row['jira_project_id'] ?? null,
            'jira_project_key' => (string) $row['jira_project_key'],
            'jira_project_name' => $row['jira_project_name'] ?? null,
            'jira_board_id' => $row['jira_board_id'] ?? null,
            'jira_space_name' => $row['jira_space_name'] ?? null,
            'website_url' => $row['website_url'] ?? null,
            'client_company_name' => $row['client_company_name'] ?? null,
            'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 1,
            'mapping_source' => $row['mapping_source'] ?? 'manual',
            'notes' => $row['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateByWhmcsClientId(int $whmcsClientId, array $row): bool
    {
        $stmt = $this->db->prepare('UPDATE client_jira_maps SET
            jira_project_id = :jira_project_id,
            jira_project_key = :jira_project_key,
            jira_project_name = :jira_project_name,
            jira_board_id = :jira_board_id,
            jira_space_name = :jira_space_name,
            website_url = :website_url,
            client_company_name = :client_company_name,
            is_active = :is_active,
            mapping_source = :mapping_source,
            notes = :notes,
            updated_at = :updated_at
            WHERE whmcs_client_id = :whmcs_client_id');
        return $stmt->execute([
            'jira_project_id' => $row['jira_project_id'] ?? null,
            'jira_project_key' => (string) $row['jira_project_key'],
            'jira_project_name' => $row['jira_project_name'] ?? null,
            'jira_board_id' => $row['jira_board_id'] ?? null,
            'jira_space_name' => $row['jira_space_name'] ?? null,
            'website_url' => $row['website_url'] ?? null,
            'client_company_name' => $row['client_company_name'] ?? null,
            'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 1,
            'mapping_source' => $row['mapping_source'] ?? 'manual',
            'notes' => $row['notes'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
            'whmcs_client_id' => $whmcsClientId,
        ]);
    }

    private function findByJiraProjectId(string $jiraProjectId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_jira_maps WHERE jira_project_id = :jira_project_id LIMIT 1');
        $stmt->execute(['jira_project_id' => $jiraProjectId]);
        return $stmt->fetch() ?: null;
    }

    private function updateById(int $id, array $row): bool
    {
        $stmt = $this->db->prepare('UPDATE client_jira_maps SET
            whmcs_client_id = :whmcs_client_id,
            jira_project_id = :jira_project_id,
            jira_project_key = :jira_project_key,
            jira_project_name = :jira_project_name,
            jira_board_id = :jira_board_id,
            jira_space_name = :jira_space_name,
            website_url = :website_url,
            client_company_name = :client_company_name,
            is_active = :is_active,
            mapping_source = :mapping_source,
            notes = :notes,
            updated_at = :updated_at
            WHERE id = :id');
        return $stmt->execute([
            'whmcs_client_id' => (int) ($row['whmcs_client_id'] ?? 0) ?: null,
            'jira_project_id' => $row['jira_project_id'] ?? null,
            'jira_project_key' => (string) $row['jira_project_key'],
            'jira_project_name' => $row['jira_project_name'] ?? null,
            'jira_board_id' => $row['jira_board_id'] ?? null,
            'jira_space_name' => $row['jira_space_name'] ?? null,
            'website_url' => $row['website_url'] ?? null,
            'client_company_name' => $row['client_company_name'] ?? null,
            'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 1,
            'mapping_source' => $row['mapping_source'] ?? 'manual',
            'notes' => $row['notes'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function deactivateByWhmcsClientId(int $whmcsClientId, string $notes = ''): bool
    {
        $stmt = $this->db->prepare('UPDATE client_jira_maps
            SET is_active = 0, notes = :notes, updated_at = :updated_at
            WHERE whmcs_client_id = :whmcs_client_id');
        return $stmt->execute([
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
            'whmcs_client_id' => $whmcsClientId,
        ]);
    }

    public function listIncomplete(int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_jira_maps
            WHERE is_active = 1
              AND (jira_project_key IS NULL OR jira_project_key = "")
            ORDER BY updated_at ASC
            LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
