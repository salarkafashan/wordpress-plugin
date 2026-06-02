<?php
/**
 * Asset enqueue helper.
 *
 * @package SupportRequestFrontend
 */

namespace SupportRequestFrontend\Includes;

use App\config\Config;
use App\helpers\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Enqueue Support Request frontend assets.
 */
final class EnqueueSupportRequestAssets
{
	private static function asset_version(string $relative_path): string
	{
		$full_path = KGR_PLUGIN_PATH . ltrim($relative_path, '/');
		$mtime = file_exists($full_path) ? (string) filemtime($full_path) : '';
		return $mtime !== '' ? KGR_PLUGIN_VERSION . '.' . $mtime : KGR_PLUGIN_VERSION;
	}

	/**
	 * Track whether assets were already enqueued.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Enqueue scripts/styles and localize runtime config.
	 *
	 * @return void
	 */
	public static function enqueue(): void
	{
		if (self::$enqueued) {
			return;
		}

		wp_enqueue_style(
			'kgr-support-request-form',
			KGR_PLUGIN_URL . 'assets/css/support-request-form.css',
			array(),
			self::asset_version('assets/css/support-request-form.css')
		);

		wp_enqueue_script(
			'alpinejs',
			'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
			array(),
			'3.12.0',
			false
		);

		$captchaProvider = strtolower((string) Config::get('CAPTCHA_PROVIDER', 'none'));
		$cloudflareSiteKey = trim((string) Config::get('CLOUDFLARE_TURNSTILE_SITE_KEY', ''));
		$googleRecaptchaType = strtolower(trim((string) Config::get('GOOGLE_RECAPTCHA_TYPE', 'classic')));
		$googleSiteKey = $googleRecaptchaType === 'enterprise'
			? trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_SITE_KEY', ''))
			: trim((string) Config::get('GOOGLE_RECAPTCHA_SITE_KEY', ''));

		if ($captchaProvider === 'cloudflare' && $cloudflareSiteKey !== '') {
			wp_enqueue_script(
				'kgr-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				array(),
				null,
				true
			);
		}
		if ($captchaProvider === 'google' && $googleSiteKey !== '') {
			$googleScript = $googleRecaptchaType === 'enterprise'
				? 'https://www.google.com/recaptcha/enterprise.js?render=' . rawurlencode($googleSiteKey)
				: 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($googleSiteKey);
			wp_enqueue_script(
				'kgr-recaptcha',
				$googleScript,
				array(),
				null,
				true
			);
		}

		wp_enqueue_script(
			'kgr-support-request-form-data',
			KGR_PLUGIN_URL . 'assets/js/support-request-form.data.js',
			array(),
			self::asset_version('assets/js/support-request-form.data.js'),
			true
		);

		wp_enqueue_script(
			'kgr-support-request-form-security',
			KGR_PLUGIN_URL . 'assets/js/support-request-form.security.js',
			array_values(array_filter(array(
				'kgr-support-request-form-data',
				$captchaProvider === 'google' && $googleSiteKey !== '' ? 'kgr-recaptcha' : '',
				$captchaProvider === 'cloudflare' && $cloudflareSiteKey !== '' ? 'kgr-turnstile' : '',
			))),
			self::asset_version('assets/js/support-request-form.security.js'),
			true
		);

		wp_enqueue_script(
			'kgr-support-request-form-ui',
			KGR_PLUGIN_URL . 'assets/js/support-request-form.ui.js',
			array('kgr-support-request-form-data', 'kgr-support-request-form-security'),
			self::asset_version('assets/js/support-request-form.ui.js'),
			true
		);

		$settings = get_option('kgr_setting', array());
		$general = is_array($settings) && isset($settings['general']) && is_array($settings['general']) ? $settings['general'] : array();
		$qaHintsEnabled = isset($general['qa_hints_enabled']) && (string) $general['qa_hints_enabled'] === '1';

		$config = array(
			'validateWebsiteEndpoint' => home_url('/validate-website'),
			'submitRequestEndpoint' => home_url('/submit-request'),
			'requestTimeoutMs' => 25000,
			'validateRequestTimeoutMs' => 12000,
			'maxNonWebsiteUploadMb' => 10,
			'maxIssueScreenshots' => 2,
			'maxScreenshotMb' => 1,
			'captchaProvider' => $captchaProvider,
			'cloudflareSiteKey' => $cloudflareSiteKey,
			'googleSiteKey' => $googleSiteKey,
			'googleRecaptchaType' => $googleRecaptchaType,
			'googleRecaptchaAction' => $googleRecaptchaType === 'enterprise' ? 'submit' : (string) Config::get('GOOGLE_RECAPTCHA_EXPECTED_ACTION', 'submit'),
			'honeypotFieldName' => (string) Config::get('HONEYPOT_FIELD_NAME', 'company_website'),
			'qaHintsEnabled' => $qaHintsEnabled,
			'captchaDebug' => Config::getBool('CAPTCHA_DEBUG', false),
			'i18n' => array(
				'next' => __('Next', 'kanguru-support'),
				'loading' => __('Loading...', 'kanguru-support'),
				'back' => __('Back', 'kanguru-support'),
				'sending' => __('Sending your request...', 'kanguru-support'),
				'submit' => __('Submit request', 'kanguru-support'),
				'websiteNotFound' => __("We couldn't find your website address. Double check the spelling or reach out to us if you're stuck.", 'kanguru-support'),
				'genericError' => __('Something went wrong. Please try again or refresh the page.', 'kanguru-support'),
				'filesNeedReselect' => __('If your browser cleared file selections, please reselect them before sending again.', 'kanguru-support'),
				'screenshotRule' => __('You can add up to 2 photos (max 1MB each) to show us the problem.', 'kanguru-support'),
				'nonWebsiteUploadRule' => __('Accepted: images, PDF, Word, Excel, ZIP. Max total 10MB.', 'kanguru-support'),
				'descriptionMinMessage' => __("Please tell us a bit more so we can help you better (at least 20 characters).", 'kanguru-support'),
			),
		);

		if (Config::getBool('CAPTCHA_DEBUG', false)) {
			Logger::info('Frontend captcha config enqueued', [
				'provider' => $captchaProvider,
				'google_type' => $googleRecaptchaType,
				'site_key' => Logger::mask($googleSiteKey),
				'action' => $config['googleRecaptchaAction'],
			]);
		}

		/**
		 * Filter frontend runtime config before it is localized to JS.
		 *
		 * @param array $config Runtime config.
		 */
		$config = apply_filters('kgr_frontend_config', $config);


		wp_localize_script('kgr-support-request-form-ui', 'KGR_CONFIG', $config);

		// Alpine.js recommends/requires defer in most setups
		add_filter('script_loader_tag', function ($tag, $handle) {
			if ('alpinejs' !== $handle) {
				return $tag;
			}
			return str_replace(' src', ' defer src', $tag);
		}, 10, 2);

		self::$enqueued = true;
	}
}
