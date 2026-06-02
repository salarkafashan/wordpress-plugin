<?php
/**
 * Plugin Name: Kanguru Support
 * Description: Integrated multi-step support request system with WHMCS backend.
 * Version: 1.1.6
 * Update URI: https://github.com/salarkafashan/wordpress-plugin
 * Author: Kanguru Team
 * Text Domain: kanguru-support
 */

if (!defined('ABSPATH')) {
	exit;
}

// 1. Setup Constants
define('KGR_PLUGIN_FILE', __FILE__);
define('KGR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('KGR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KGR_PLUGIN_VERSION', '1.1.6');
define('KGR_PLUGIN_UPDATE_URI', 'https://github.com/salarkafashan/wordpress-plugin');
define('KGR_PLUGIN_SLUG', 'kanguru-support');
define('KGR_PLUGIN_MIGRATION_OPTION', 'kgr_support_plugin_migration_version');

// 2. Define Backend Path
define('KGR_BACKEND_PATH', KGR_PLUGIN_PATH . 'backend/');

// 3. Load the Backend System (Autoloader & Config)
if (file_exists(KGR_BACKEND_PATH . 'src/bootstrap.php')) {
	require_once KGR_BACKEND_PATH . 'src/bootstrap.php';
}

// 4. Load WordPress UI Components
require_once KGR_PLUGIN_PATH . 'includes/EnqueueSupportRequestAssets.php';
require_once KGR_PLUGIN_PATH . 'includes/ShortcodeSupportRequest.php';
require_once KGR_PLUGIN_PATH . 'includes/DatabaseManager.php';
require_once KGR_PLUGIN_PATH . 'includes/AdminController.php';
require_once KGR_PLUGIN_PATH . 'includes/AdminCrypto.php';
require_once KGR_PLUGIN_PATH . 'includes/AdminHttpClient.php';
require_once KGR_PLUGIN_PATH . 'includes/AdminJiraCatalogService.php';
require_once KGR_PLUGIN_PATH . 'includes/CronController.php';
require_once KGR_PLUGIN_PATH . 'includes/PluginUpdater.php';

// 4.1 Register Admin Panel & Database Hooks
add_action('plugins_loaded', function () {
	load_plugin_textdomain(
		'kanguru-support',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);

	\SupportRequestFrontend\Includes\DatabaseManager::maybe_upgrade();
	\SupportRequestFrontend\Includes\DatabaseManager::maybe_run_plugin_migrations(KGR_PLUGIN_VERSION, KGR_PLUGIN_MIGRATION_OPTION);
	\SupportRequestFrontend\Includes\AdminController::register();
	\SupportRequestFrontend\Includes\CronController::register();
	\SupportRequestFrontend\Includes\PluginUpdater::register();
});

register_activation_hook(KGR_PLUGIN_FILE, function () {
	\SupportRequestFrontend\Includes\DatabaseManager::install();
	\SupportRequestFrontend\Includes\CronController::schedule_events();
	add_rewrite_rule('^validate-website/?$', 'index.php?kgr_api=validate', 'top');
	add_rewrite_rule('^submit-request/?$', 'index.php?kgr_api=submit', 'top');
	add_rewrite_rule('^confirm-request/?$', 'index.php?kgr_api=confirm', 'top');
	flush_rewrite_rules();
});

register_deactivation_hook(KGR_PLUGIN_FILE, function () {
	\SupportRequestFrontend\Includes\CronController::clear_events();
	flush_rewrite_rules();
});

// 5. Register the Shortcode [support_request_form]
add_action('plugins_loaded', function () {
	\SupportRequestFrontend\Includes\ShortcodeSupportRequest::register();
});

// 5.1 Prevent WordPress from corrupting Alpine.js attributes within our shortcode
add_filter('no_texturize_shortcodes', function ($shortcodes) {
	$shortcodes[] = 'support_request_form';
	return $shortcodes;
});

// 5.2 Plugin list links customization:
// - Add Settings link next to Activate/Deactivate actions
// - Hide "Check for updates" row meta link for this plugin
add_filter('plugin_action_links_' . plugin_basename(KGR_PLUGIN_FILE), function ($links) {
	$settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=kgr-support-settings')) . '">Settings</a>';

	$ordered = [];
	$inserted = false;
	foreach ($links as $key => $html) {
		$ordered[$key] = $html;
		if ($key === 'deactivate') {
			$ordered['settings'] = $settingsLink;
			$inserted = true;
		}
	}

	if (!$inserted) {
		$ordered = array_merge(['settings' => $settingsLink], $ordered);
	}

	return $ordered;
});

add_filter('plugin_row_meta', function ($meta, $pluginFile) {
	if ($pluginFile !== plugin_basename(KGR_PLUGIN_FILE)) {
		return $meta;
	}

	$filtered = [];
	foreach ($meta as $item) {
		if (stripos((string) $item, 'Check for updates') !== false) {
			continue;
		}
		$filtered[] = $item;
	}
	return $filtered;
}, 10, 2);

// 6. Register API Endpoints for WordPress
add_action('init', function () {
	add_rewrite_rule('^validate-website/?$', 'index.php?kgr_api=validate', 'top');
	add_rewrite_rule('^submit-request/?$', 'index.php?kgr_api=submit', 'top');
	add_rewrite_rule('^confirm-request/?$', 'index.php?kgr_api=confirm', 'top');
});

add_filter('query_vars', function ($vars) {
	$vars[] = 'kgr_api';
	return $vars;
});

// 7. Route API requests to the /backend/public folder
add_action('template_redirect', function () {
	$api_action = get_query_var('kgr_api');

	if ($api_action === 'validate') {
		$file = KGR_BACKEND_PATH . 'public/validate-website.php';
		if (file_exists($file)) {
			require_once $file;
			exit;
		}
	}

	if ($api_action === 'submit') {
		$file = KGR_BACKEND_PATH . 'public/submit-request.php';
		if (file_exists($file)) {
			require_once $file;
			exit;
		}
	}

	if ($api_action === 'confirm') {
		$file = KGR_BACKEND_PATH . 'public/confirm.php';
		if (file_exists($file)) {
			require_once $file;
			exit;
		}
	}
});
