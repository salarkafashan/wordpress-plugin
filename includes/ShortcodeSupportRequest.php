<?php
/**
 * Shortcode registration.
 *
 * @package SupportRequestFrontend
 */

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Register and render support request shortcode.
 */
final class ShortcodeSupportRequest
{

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_shortcode('support_request_form', array(__CLASS__, 'render'));
	}

	/**
	 * Render shortcode template.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render(array $atts = array()): string
	{
		EnqueueSupportRequestAssets::enqueue();

		$atts = shortcode_atts(
			array(
				'class' => '',
			),
			$atts,
			'support_request_form'
		);

		$wrapper_class = 'kgr-widget';
		if (!empty($atts['class'])) {
			$wrapper_class .= ' ' . sanitize_html_class((string) $atts['class']);
		}

		ob_start();
		$template_path = KGR_PLUGIN_PATH . 'templates/form-support-request.php';
		if (file_exists($template_path)) {
			include $template_path;
		}
		return (string) ob_get_clean();
	}
}
