<?php
/**
 * Kanguru Support - Modern Admin & Database Migration
 * 
 * This file handles table creation, database migration from SQLite to MySQL (WPDB),
 * and defines the high-end Admin UI for settings and tickets.
 */

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
    exit;
}

final class DatabaseManager
{
    private const SCHEMA_VERSION = '1.6.1';

    private static $tables = [
        'request' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                public_id varchar(20) NOT NULL,
                client_id bigint(20) DEFAULT NULL,
                client_whmcs_id bigint(20) DEFAULT NULL,
                submitted_email varchar(255) NOT NULL,
                verified_email varchar(255) DEFAULT NULL,
                confirmation_sent_to varchar(255) DEFAULT NULL,
                client_name varchar(255) DEFAULT NULL,
                client_company varchar(255) DEFAULT NULL,
                website_domain varchar(255) DEFAULT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending_confirmation',
                duplicate_hash varchar(64) DEFAULT NULL,
                duplicate_override tinyint(1) NOT NULL DEFAULT 0,
                metadata_json longtext DEFAULT NULL,
                confirmation_token_hash varchar(64) DEFAULT NULL,
                confirmation_expires_at datetime DEFAULT NULL,
                jira_issue_key varchar(50) DEFAULT NULL,
                jira_status varchar(50) DEFAULT NULL,
                confirmed_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY status (status),
                KEY duplicate_hash (duplicate_hash)
            ) %s;",
        'issue' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                request_id bigint(20) NOT NULL,
                issue_type varchar(100) NOT NULL,
                urgency_level varchar(100) NOT NULL,
                page_url text DEFAULT NULL,
                description longtext NOT NULL,
                current_content longtext DEFAULT NULL,
                new_content longtext DEFAULT NULL,
                suggested_issue_type varchar(100) DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY request_id (request_id)
            ) %s;",
        'attachment' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                request_id bigint(20) NOT NULL,
                issue_id bigint(20) NOT NULL,
                original_name varchar(255) NOT NULL,
                stored_name varchar(255) NOT NULL,
                mime_type varchar(100) DEFAULT NULL,
                extension varchar(10) DEFAULT NULL,
                category varchar(50) DEFAULT NULL,
                temp_path text DEFAULT NULL,
                file_path text DEFAULT NULL,
                file_size_original bigint(20) DEFAULT NULL,
                file_size_optimized bigint(20) DEFAULT NULL,
                optimization_status varchar(50) DEFAULT 'pending',
                jira_attachment_status varchar(50) DEFAULT 'pending',
                sha256_hash varchar(64) DEFAULT NULL,
                is_screenshot tinyint(1) NOT NULL DEFAULT 0,
                retention_delete_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY request_id (request_id),
                KEY issue_id (issue_id)
            ) %s;",
        'queue' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                request_id bigint(20) DEFAULT NULL,
                job_type varchar(100) NOT NULL,
                payload_json longtext DEFAULT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                attempts int(11) NOT NULL DEFAULT 0,
                max_attempts int(11) NOT NULL DEFAULT 5,
                last_error text DEFAULT NULL,
                next_run_at datetime DEFAULT NULL,
                locked_at datetime DEFAULT NULL,
                lock_token varchar(64) DEFAULT NULL,
                processed_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY next_run_at (next_run_at)
            ) %s;",
        'client_cache' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                whmcs_client_id bigint(20) NOT NULL,
                email varchar(255) NOT NULL,
                first_name varchar(255) DEFAULT NULL,
                last_name varchar(255) DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY whmcs_client_id (whmcs_client_id),
                KEY email (email)
            ) %s;",
        'client_domain' => "
            CREATE TABLE %s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                client_id bigint(20) NOT NULL,
                domain varchar(255) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY client_id (client_id),
                KEY domain (domain)
            ) %s;",
        'client_jira_map' => "
            CREATE TABLE %s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                whmcs_client_id bigint(20) unsigned DEFAULT NULL,
                jira_project_id varchar(100) DEFAULT NULL,
                jira_project_key varchar(50) NOT NULL,
                jira_project_name varchar(255) DEFAULT NULL,
                jira_board_id varchar(100) DEFAULT NULL,
                jira_space_name varchar(255) DEFAULT NULL,
                website_url varchar(255) DEFAULT NULL,
                client_company_name varchar(255) DEFAULT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                mapping_source varchar(50) NOT NULL DEFAULT 'manual',
                notes text DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY jira_project_id (jira_project_id),
                KEY whmcs_client_id (whmcs_client_id),
                KEY jira_project_key (jira_project_key),
                KEY website_url (website_url),
                KEY is_active (is_active)
            ) %s;",
        'rate_limit' => "
            CREATE TABLE %s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                limiter_key varchar(191) NOT NULL,
                created_at_unix bigint(20) NOT NULL,
                PRIMARY KEY  (id),
                KEY limiter_key (limiter_key),
                KEY created_at_unix (created_at_unix),
                KEY limiter_key_time (limiter_key, created_at_unix)
            ) %s;"
    ];

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // Always run idempotent migrations with dbDelta. Do not drop tables on version updates.
        $current_db_version = (string) get_option('kgr_support_db_version', '0');
        foreach (self::$tables as $table_name => $sql_template) {
            $full_table_name = $wpdb->prefix . 'kgr_support_' . $table_name;
            $sql = sprintf($sql_template, $full_table_name, $charset_collate);
            dbDelta($sql);
        }
        self::migrate_client_jira_map_structure();

        if (version_compare($current_db_version, self::SCHEMA_VERSION, '<')) {
            update_option('kgr_support_db_version', self::SCHEMA_VERSION);
        }
    }

    public static function maybe_upgrade(): void
    {
        $current = (string) get_option('kgr_support_db_version', '0');
        if (version_compare($current, self::SCHEMA_VERSION, '<')) {
            self::install();
        }
    }

    /**
     * Run plugin-version migrations after code updates.
     * Uses a dedicated option so schema versioning can remain independent.
     */
    public static function maybe_run_plugin_migrations(string $targetVersion, string $optionName = 'kgr_support_plugin_migration_version'): void
    {
        $target = trim($targetVersion);
        if ($target === '') {
            return;
        }

        $installed = (string) get_option($optionName, '0.0.0');
        if (version_compare($installed, $target, '>=')) {
            return;
        }

        self::run_plugin_migrations($installed, $target);
        update_option($optionName, $target, false);
    }

    private static function run_plugin_migrations(string $fromVersion, string $targetVersion): void
    {
        // Keep table/index creation idempotent via dbDelta and structure migration helper.
        self::install();

        // Example placeholder for future incremental migrations:
        // if (version_compare($fromVersion, '1.1.2', '<')) {
        //     // Migration steps for 1.1.2
        // }
    }

    public static function rebuild(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        foreach (array_keys(self::$tables) as $table_name) {
            $full_table_name = $wpdb->prefix . 'kgr_support_' . $table_name;
            $wpdb->query("DROP TABLE IF EXISTS $full_table_name");
        }

        foreach (self::$tables as $table_name => $sql_template) {
            $full_table_name = $wpdb->prefix . 'kgr_support_' . $table_name;
            $sql = sprintf($sql_template, $full_table_name, $charset_collate);
            dbDelta($sql);
        }

        update_option('kgr_support_db_version', self::SCHEMA_VERSION);
    }

    /**
     * Ensure client Jira map table supports unmapped Jira spaces (NULL whmcs_client_id).
     */
    private static function migrate_client_jira_map_structure(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kgr_support_client_jira_map';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        // Drop old unique key if it exists.
        $indexRows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexes = array_map(static fn(array $r): string => (string) ($r['Key_name'] ?? ''), $indexRows ?: []);
        if (in_array('whmcs_client_id', $indexes, true)) {
            // Ignore failure if key type differs; dbDelta may recreate intended keys.
            $wpdb->query("ALTER TABLE {$table} DROP INDEX whmcs_client_id");
        }

        // Ensure nullable client id and required indexes for mixed mapped/unmapped rows.
        $wpdb->query("ALTER TABLE {$table} MODIFY whmcs_client_id bigint(20) unsigned NULL");
        if (!in_array('jira_project_id', $indexes, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY jira_project_id (jira_project_id)");
        }
        // Non-unique lookup index for mapped rows.
        $indexRows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexes = array_map(static fn(array $r): string => (string) ($r['Key_name'] ?? ''), $indexRows ?: []);
        if (!in_array('whmcs_client_id', $indexes, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY whmcs_client_id (whmcs_client_id)");
        }
    }
}
