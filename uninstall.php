<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1) Unschedule plugin cron hooks.
$cronHooks = [
    'kgr_daily_cleanup_event',
    'kgr_hourly_queue_event',
    'kgr_client_jira_mapping_audit_event',
    'kgr_jira_catalog_sync_event',
];
foreach ($cronHooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// 2) Drop plugin tables.
$tableLike = $wpdb->esc_like($wpdb->prefix . 'kgr_support_') . '%';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $tableLike));
if (is_array($tables)) {
    foreach ($tables as $table) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        if ($safeTable !== '') {
            $wpdb->query("DROP TABLE IF EXISTS `{$safeTable}`");
        }
    }
}

// 3) Delete known options plus any plugin-prefixed options.
$knownOptions = [
    'kgr_setting',
    'kgr_support_db_version',
    'kgr_support_plugin_migration_version',
    'kgr_jira_catalog_last_synced_at',
    'kgr_jira_token_health',
    'kgr_jira_token_alert_state',
];
foreach ($knownOptions as $optionName) {
    delete_option($optionName);
    delete_site_option($optionName);
}

$prefixedOptionLike = $wpdb->esc_like('kgr_') . '%';
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $prefixedOptionLike
    )
);

if (is_multisite()) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
            $prefixedOptionLike
        )
    );
}

// 4) Remove plugin-managed data folders/files under backend/.
$pluginRoot = plugin_dir_path(__FILE__);
$cleanupTargets = [
    $pluginRoot . 'backend/storage',
    $pluginRoot . 'backend/logs',
    $pluginRoot . 'backend/uploads',
    $pluginRoot . 'backend/database',
    $pluginRoot . 'backend/.env',
];

$deleteRecursively = static function (string $path) use (&$deleteRecursively): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $deleteRecursively($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};

foreach ($cleanupTargets as $target) {
    $realRoot = realpath($pluginRoot);
    $realTarget = realpath($target);

    // If target no longer resolves (already deleted), still attempt direct unlink/rmdir safely.
    if ($realTarget === false) {
        if (file_exists($target)) {
            $deleteRecursively($target);
        }
        continue;
    }

    if ($realRoot !== false) {
        $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $normalizedTarget = str_replace('\\', '/', $realTarget);
        if (strpos($normalizedTarget, $normalizedRoot) !== 0) {
            continue;
        }
    }

    $deleteRecursively($realTarget);
}

