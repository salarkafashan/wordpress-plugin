<?php

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginUpdater
{
    private static bool $booted = false;
    private static $updateChecker = null;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $autoload = KGR_PLUGIN_PATH . 'vendor/autoload.php';
        if (!is_file($autoload)) {
            return;
        }

        require_once $autoload;

        if (!class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
            return;
        }

        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            KGR_PLUGIN_UPDATE_URI,
            KGR_PLUGIN_FILE,
            KGR_PLUGIN_SLUG,
            12 // Check every 12 hours (default is longer), so users see updates sooner.
        );

        // Keep stable branch explicit for fallback checks.
        $updateChecker->setBranch('main');

        // Use GitHub release assets (uploaded ZIP files) for production updates.
        $updateChecker->getVcsApi()->enableReleaseAssets('/kanguru-support-.*\.zip($|[?&#])/i');
        self::$updateChecker = $updateChecker;

        // Add a lightweight manual check trigger for admins:
        // /wp-admin/plugins.php?kgr_force_update_check=1
        add_action('admin_init', [__CLASS__, 'maybeForceUpdateCheck']);
    }

    public static function maybeForceUpdateCheck(): void
    {
        if (!is_admin() || !current_user_can('update_plugins')) {
            return;
        }
        if (empty($_GET['kgr_force_update_check'])) {
            return;
        }
        if (!self::$updateChecker || !method_exists(self::$updateChecker, 'checkForUpdates')) {
            return;
        }
        self::$updateChecker->checkForUpdates();
    }
}

