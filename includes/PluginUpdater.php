<?php

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginUpdater
{
    private static bool $booted = false;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!is_admin()) {
            return;
        }

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
            KGR_PLUGIN_SLUG
        );

        // Use GitHub release assets (uploaded ZIP files) for production updates.
        $updateChecker->getVcsApi()->enableReleaseAssets('/kanguru-support-.*\.zip($|[?&#])/i');
    }
}

