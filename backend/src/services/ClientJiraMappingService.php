<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use App\helpers\Logger;
use App\helpers\Validator;
use App\models\ClientJiraMapModel;
use App\database\Database;

final class ClientJiraMappingService
{
    private ClientJiraMapModel $model;

    public function __construct()
    {
        $this->model = new ClientJiraMapModel();
    }

    public function getMappingByWhmcsClientId(int $whmcsClientId): ?array
    {
        if ($whmcsClientId <= 0) {
            return null;
        }
        return $this->model->findActiveByWhmcsClientId($whmcsClientId);
    }

    public function getMappingByWebsiteUrl(string $websiteUrl): ?array
    {
        $normalized = Validator::normalizeDomainInput($websiteUrl);
        if ($normalized === '') {
            return null;
        }
        return $this->model->findActiveByWebsiteUrl($normalized);
    }

    public function upsertMapping(array $input): int
    {
        $whmcsClientId = (int) ($input['whmcs_client_id'] ?? 0);
        $projectKey = trim((string) ($input['jira_project_key'] ?? ''));
        if ($whmcsClientId <= 0) {
            throw new \InvalidArgumentException('whmcs_client_id is required.');
        }
        if ($projectKey === '') {
            throw new \InvalidArgumentException('jira_project_key is required.');
        }

        $row = [
            'whmcs_client_id' => $whmcsClientId,
            'jira_project_id' => trim((string) ($input['jira_project_id'] ?? '')) ?: null,
            'jira_project_key' => $projectKey,
            'jira_project_name' => trim((string) ($input['jira_project_name'] ?? '')) ?: null,
            'jira_board_id' => trim((string) ($input['jira_board_id'] ?? '')) ?: null,
            'jira_space_name' => trim((string) ($input['jira_space_name'] ?? '')) ?: null,
            'website_url' => Validator::normalizeDomainInput((string) ($input['website_url'] ?? '')) ?: null,
            'client_company_name' => trim((string) ($input['client_company_name'] ?? '')) ?: null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'mapping_source' => trim((string) ($input['mapping_source'] ?? 'manual')) ?: 'manual',
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        $id = $this->model->upsertByWhmcsClientId($row);
        return $id;
    }

    public function deactivateMapping(int $whmcsClientId, string $notes = 'Deactivated manually'): bool
    {
        return $this->model->deactivateByWhmcsClientId($whmcsClientId, $notes);
    }

    public function listIncompleteMappings(int $limit = 100): array
    {
        return $this->model->listIncomplete($limit);
    }

    public function listRequestsMissingMapping(int $limit = 25): array
    {
        $routingMode = strtolower(trim((string) Config::getJiraValue('JIRA_ROUTING_MODE', 'support_space')));
        if ($routingMode !== 'client_mapped') {
            return [];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT r.id, r.public_id, r.client_whmcs_id, r.website_domain, r.submitted_email, r.status, r.updated_at
            FROM support_requests r
            LEFT JOIN client_jira_maps m
                ON m.whmcs_client_id = r.client_whmcs_id
               AND m.is_active = 1
            WHERE r.status = "confirmed"
              AND (r.jira_issue_key IS NULL OR r.jira_issue_key = "")
              AND (
                    r.client_whmcs_id IS NULL
                    OR r.client_whmcs_id <= 0
                    OR m.id IS NULL
                    OR m.jira_project_key IS NULL
                    OR m.jira_project_key = ""
              )
            ORDER BY r.updated_at ASC
            LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function logMissingMappingAudit(int $limit = 25): array
    {
        $rows = $this->listRequestsMissingMapping($limit);

        foreach ($rows as $row) {
            Logger::error('Jira mapping missing for confirmed support request', [
                'request_id' => (int) ($row['id'] ?? 0),
                'public_id' => (string) ($row['public_id'] ?? ''),
                'client_whmcs_id' => (int) ($row['client_whmcs_id'] ?? 0),
                'website_domain' => (string) ($row['website_domain'] ?? ''),
                'submitted_email' => (string) ($row['submitted_email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ]);
        }

        return [
            'checked_limit' => $limit,
            'missing_count' => count($rows),
            'rows' => $rows,
        ];
    }
}
