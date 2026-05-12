<?php
/**
 * Jira catalog sync service used by admin and cron.
 *
 * @package SupportRequestFrontend
 */

namespace SupportRequestFrontend\Includes;

use App\helpers\Logger;
use App\services\JiraService;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminJiraCatalogService
{
    /**
     * @param array<string, mixed>|null $stats
     * @return array<int, array<string, mixed>>
     */
    public static function sync(?array &$stats = null, string $trigger = 'unknown'): array
    {
        global $wpdb;
        $mapTable = $wpdb->prefix . 'kgr_support_client_jira_map';
        $rows = (new JiraService())->searchProjects('', 200, [
            'trigger' => $trigger,
        ]);
        $now = current_time('mysql');
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mapTable)) === $mapTable;
        $insertedCount = 0;
        $updatedCount = 0;
        $failedCount = 0;

        foreach ($rows as $row) {
            $projectId = sanitize_text_field((string) ($row['jira_project_id'] ?? ''));
            if ($projectId === '') {
                $failedCount++;
                Logger::error('Jira catalog row skipped: missing jira_project_id', ['row' => $row]);
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$mapTable} WHERE jira_project_id = %s LIMIT 1",
                $projectId
            ));

            $payload = [
                'whmcs_client_id' => null,
                'jira_project_id' => $projectId,
                'jira_project_key' => sanitize_text_field((string) ($row['jira_project_key'] ?? '')),
                'jira_project_name' => sanitize_text_field((string) ($row['jira_project_name'] ?? '')),
                'jira_board_id' => sanitize_text_field((string) ($row['jira_board_id'] ?? '')),
                'jira_space_name' => sanitize_text_field((string) ($row['jira_space_name'] ?? '')),
                'is_active' => 1,
                'mapping_source' => 'jira_catalog',
                'notes' => 'Synced from Jira catalog',
                'updated_at' => $now,
            ];

            if ($existing) {
                $existingRow = $wpdb->get_row($wpdb->prepare("SELECT whmcs_client_id, mapping_source FROM {$mapTable} WHERE id = %d", (int) $existing), ARRAY_A);
                if (!empty($existingRow['whmcs_client_id'])) {
                    $payload['whmcs_client_id'] = (int) $existingRow['whmcs_client_id'];
                }
                if (!empty($existingRow['mapping_source']) && $existingRow['mapping_source'] !== 'jira_catalog') {
                    $payload['mapping_source'] = $existingRow['mapping_source'];
                }
                $updated = $wpdb->update($mapTable, $payload, ['id' => (int) $existing]);
                if ($updated === false) {
                    $failedCount++;
                    Logger::error('Jira catalog row update failed', [
                        'jira_project_id' => $projectId,
                        'table' => $mapTable,
                        'last_db_error' => (string) $wpdb->last_error,
                    ]);
                } else {
                    $updatedCount++;
                }
            } else {
                $payload['created_at'] = $now;
                $inserted = $wpdb->insert($mapTable, $payload);
                if ($inserted === false) {
                    $failedCount++;
                    Logger::error('Jira catalog row insert failed', [
                        'jira_project_id' => $projectId,
                        'table' => $mapTable,
                        'last_db_error' => (string) $wpdb->last_error,
                        'payload' => $payload,
                    ]);
                } else {
                    $insertedCount++;
                }
            }
        }

        $currentIds = array_values(array_filter(array_map(
            static fn(array $r): string => sanitize_text_field((string) ($r['jira_project_id'] ?? '')),
            $rows
        )));
        if (!empty($currentIds)) {
            $placeholders = implode(',', array_fill(0, count($currentIds), '%s'));
            $sql = "UPDATE {$mapTable}
                    SET is_active = 0, updated_at = %s
                    WHERE mapping_source = 'jira_catalog'
                      AND whmcs_client_id IS NULL
                      AND jira_project_id NOT IN ({$placeholders})";
            $args = array_merge([$now], $currentIds);
            $wpdb->query($wpdb->prepare($sql, ...$args));
        }

        $wpdb->query(
            "DELETE FROM {$mapTable}
             WHERE mapping_source = 'jira_catalog'
               AND whmcs_client_id IS NULL
               AND (
                    is_active = 0
                    OR UPPER(COALESCE(jira_project_name, '')) LIKE '[CLOSED]%'
                    OR UPPER(COALESCE(jira_project_name, '')) LIKE '[ARCHIVED]%'
                    OR LOWER(COALESCE(jira_space_name, '')) IN ('archived', 'closed')
               )"
        );

        update_option('kgr_jira_catalog_last_synced_at', $now, false);

        $tableTotalRows = 0;
        $tableCatalogRows = 0;
        if ($tableExists) {
            $tableTotalRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable}");
            $tableCatalogRows = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$mapTable} WHERE mapping_source = 'jira_catalog'"
            );
        }

        $stats = [
            'trigger' => $trigger,
            'table' => $mapTable,
            'table_exists' => $tableExists,
            'fetched_count' => count($rows),
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'persisted_rows' => $insertedCount + $updatedCount,
            'failed_count' => $failedCount,
            'last_db_error' => (string) $wpdb->last_error,
            'table_total_rows' => $tableTotalRows,
            'table_catalog_rows' => $tableCatalogRows,
            'synced_at' => $now,
        ];

        return $rows;
    }
}
