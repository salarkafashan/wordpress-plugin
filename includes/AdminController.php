<?php
/**
 * Admin Panel for Kanguru Support
 * 
 * Provides a high-end interface using modern CSS and Alpine.js
 * 
 * Primary Color: #00001a
 * Accent Color: #1becab
 */

namespace SupportRequestFrontend\Includes;

use App\helpers\Logger;
use App\config\Config;
use App\services\ClientJiraMappingService;
use App\services\JiraService;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminController
{
    private static $parent_slug = 'kgr-support';

    public static function register(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_pages']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_kgr_save_settings', [__CLASS__, 'ajax_save_settings']);
        add_action('wp_ajax_kgr_get_tickets', [__CLASS__, 'ajax_get_tickets']);
        add_action('wp_ajax_kgr_get_ticket_details', [__CLASS__, 'ajax_get_ticket_details']);
        add_action('wp_ajax_kgr_download_attachment', [__CLASS__, 'ajax_download_attachment']);
        add_action('wp_ajax_kgr_get_mapping_health', [__CLASS__, 'ajax_get_mapping_health']);
        add_action('wp_ajax_kgr_get_client_mappings', [__CLASS__, 'ajax_get_client_mappings']);
        add_action('wp_ajax_kgr_save_client_mapping', [__CLASS__, 'ajax_save_client_mapping']);
        add_action('wp_ajax_kgr_deactivate_client_mapping', [__CLASS__, 'ajax_deactivate_client_mapping']);
        add_action('wp_ajax_kgr_search_jira_projects', [__CLASS__, 'ajax_search_jira_projects']);
        add_action('wp_ajax_kgr_fetch_jira_spaces_now', [__CLASS__, 'ajax_fetch_jira_spaces_now']);
        add_action('wp_ajax_kgr_test_jira_credentials', [__CLASS__, 'ajax_test_jira_credentials']);
        add_action('wp_ajax_kgr_test_whmcs_credentials', [__CLASS__, 'ajax_test_whmcs_credentials']);
        add_action('wp_ajax_kgr_test_cloudflare_credentials', [__CLASS__, 'ajax_test_cloudflare_credentials']);
        add_action('wp_ajax_kgr_test_google_credentials', [__CLASS__, 'ajax_test_google_credentials']);
        add_action('admin_head', [__CLASS__, 'inject_menu_icon_styles']);
        add_action('admin_notices', [__CLASS__, 'render_jira_token_notice']);
    }

    public static function inject_menu_icon_styles(): void
    {
        $icon_url = KGR_PLUGIN_URL . 'assets/img/kanguru-menu-icon.svg';
        ?>
        <style>
            #adminmenu .toplevel_page_kgr-support .wp-menu-image:before {
                content: '' !important;
                display: block;
                width: 20px;
                height: 20px;
                background-color: rgba(240, 245, 250, 0.6);
                -webkit-mask: url('<?php echo $icon_url; ?>') no-repeat center;
                mask: url('<?php echo $icon_url; ?>') no-repeat center;
                -webkit-mask-size: contain;
                mask-size: contain;
                opacity: 0.9;
                margin: -2px auto 0 auto;
            }

            #adminmenu .toplevel_page_kgr-support:hover .wp-menu-image:before,
            #adminmenu .toplevel_page_kgr-support.wp-has-current-submenu .wp-menu-image:before,
            #adminmenu .toplevel_page_kgr-support.current .wp-menu-image:before {
                background-color: #fff !important;
                opacity: 1;
                margin: -2px auto 0 auto;
            }
        </style>
        <?php
    }

    public static function add_pages(): void
    {
        add_menu_page(
            __('Kanguru Support', 'knaguru-support'),
            __('Kanguru Support', 'knaguru-support'),
            'manage_options',
            self::$parent_slug,
            [__CLASS__, 'render_tickets_page'],
            'dashicons-sos',
            30
        );

        add_submenu_page(
            self::$parent_slug,
            __('Ticket Management', 'knaguru-support'),
            __('Tickets', 'knaguru-support'),
            'manage_options',
            self::$parent_slug,
            [__CLASS__, 'render_tickets_page']
        );

        add_submenu_page(
            self::$parent_slug,
            __('Settings', 'knaguru-support'),
            __('Settings', 'knaguru-support'),
            'manage_options',
            'kgr-setting',
            [__CLASS__, 'render_settings_page']
        );

        add_submenu_page(
            self::$parent_slug,
            __('Usage Guide', 'knaguru-support'),
            __('Usage Guide', 'knaguru-support'),
            'manage_options',
            'kgr-guide',
            [__CLASS__, 'render_guide_page']
        );
    }

    public static function enqueue_assets($hook): void
    {
        // Broad check for our plugin pages to ensure styles/scripts load correctly
        if (strpos($hook, 'kgr-') === false && strpos($hook, 'kanguru-') === false) {
            return;
        }

        wp_enqueue_style('kgr-admin-css', KGR_PLUGIN_URL . 'assets/css/admin.css', [], KGR_PLUGIN_VERSION);
        wp_enqueue_script('alpinejs', 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', [], '3.12.0', false);
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ('alpinejs' !== $handle)
                return $tag;
            return str_replace(' src', ' defer src', $tag);
        }, 10, 2);
    }

    public static function render_settings_page(): void
    {
        $template = KGR_PLUGIN_PATH . 'templates/admin-settings.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    public static function render_tickets_page(): void
    {
        $template = KGR_PLUGIN_PATH . 'templates/admin-tickets.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    public static function render_guide_page(): void
    {
        include KGR_PLUGIN_PATH . 'templates/admin-guide.php';
    }

    public static function ajax_save_settings(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $form_data = $_POST['settings'] ?? [];
        if (!is_array($form_data) || $form_data === []) {
            // Fallback for forms that post grouped tab arrays directly.
            $form_data = [];
            foreach (['whmcs', 'jira', 'captcha'] as $tabKey) {
                if (isset($_POST[$tabKey]) && is_array($_POST[$tabKey])) {
                    $form_data[$tabKey] = $_POST[$tabKey];
                }
            }
        }
        $sanitized = [];
        $existing = get_option('kgr_setting', []);

        // Dynamic sanitization based on tabs
        foreach ($form_data as $tab => $fields) {
            if (!is_array($fields))
                continue;
                
            if ($tab === 'general') {
                $validateEmails = static function(string $str): bool {
                    $emails = array_filter(array_map('trim', explode(',', $str)));
                    if ($emails === []) return false;
                    foreach ($emails as $email) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
                    }
                    return true;
                };

                if (empty($fields['admin_emails']) || !$validateEmails((string)$fields['admin_emails'])) {
                    wp_send_json_error(['message' => 'Invalid or missing admin email address(es).']);
                }
                
                if (($fields['testing_mode'] ?? 'off') === 'on') {
                    if (empty($fields['test_emails']) || !$validateEmails((string)$fields['test_emails'])) {
                        wp_send_json_error(['message' => 'Invalid or missing sandbox recipient email address(es).']);
                    }
                } else {
                    // QA hints must be disabled when sandbox mode is off.
                    $fields['qa_hints_enabled'] = '0';
                }
            }

            foreach ($fields as $key => $value) {
                $rawValue = trim((string) $value);
                if ($tab === 'jira' && $key === 'jira_api_token_expires_on' && $rawValue !== '') {
                    $dt = \DateTime::createFromFormat('Y-m-d', $rawValue);
                    if (!$dt || $dt->format('Y-m-d') !== $rawValue) {
                        wp_send_json_error(['message' => 'Invalid Jira token expiry date. Use YYYY-MM-DD.']);
                    }
                }
                if ($tab === 'captcha' && $key === 'google_recaptcha_type' && !in_array($rawValue, ['classic', 'enterprise'], true)) {
                    $rawValue = 'classic';
                    $value = $rawValue;
                }
                $existingValue = '';
                if (is_array($existing) && isset($existing[$tab]) && is_array($existing[$tab]) && isset($existing[$tab][$key])) {
                    $existingValue = (string) $existing[$tab][$key];
                }

                if (self::should_preserve_on_empty((string) $tab, (string) $key) && $rawValue === '' && $existingValue !== '') {
                    $sanitized[$tab][$key] = $existingValue;
                    continue;
                }

                if (self::is_sensitive_key($key)) {
                    $value = self::encrypt($rawValue);
                }
                $sanitized[$tab][$key] = sanitize_text_field($value);
            }
        }

        update_option('kgr_setting', $sanitized);
        if (isset($sanitized['jira'])) {
            delete_option('kgr_jira_token_alert_state');
            self::run_jira_token_health_check('settings_save');
        }
        wp_send_json_success(['message' => 'Settings saved successfully']);
    }

    public static function run_jira_token_health_check(string $trigger = 'manual'): array
    {
        $settings = get_option('kgr_setting', []);
        $jira = is_array($settings) && isset($settings['jira']) && is_array($settings['jira']) ? $settings['jira'] : [];
        $tokenRaw = (string) ($jira['jira_api_token'] ?? '');
        $expiresOnRaw = trim((string) ($jira['jira_api_token_expires_on'] ?? ''));
        $expiresOn = self::normalize_jira_token_expiry_date($expiresOnRaw);

        $token = self::decrypt($tokenRaw);
        if ($token === '' && $tokenRaw !== '') {
            $token = $tokenRaw;
        }

        $health = [
            'checked_at' => current_time('mysql'),
            'trigger' => $trigger,
            'status' => 'unknown',
            'expires_on' => $expiresOn,
            'days_left' => null,
            'last_validated_at' => '',
            'message' => '',
        ];

        if ($token === '') {
            $health['status'] = 'missing';
            $health['message'] = 'Jira API token is missing.';
            update_option('kgr_jira_token_health', $health, false);
            self::send_jira_token_alert_if_needed($health);
            return $health;
        }

        if ($expiresOn !== '') {
            $today = new \DateTimeImmutable(wp_date('Y-m-d'));
            $expiry = \DateTimeImmutable::createFromFormat('Y-m-d', $expiresOn);
            if ($expiry instanceof \DateTimeImmutable) {
                $diff = (int) $today->diff($expiry)->format('%r%a');
                $health['days_left'] = $diff;
                if ($diff < 0) {
                    $health['status'] = 'expired';
                    $health['message'] = 'Jira API token expiry date is in the past.';
                } elseif ($diff <= 30) {
                    $health['status'] = 'expiring_soon';
                    $health['message'] = 'Jira API token expires soon.';
                } else {
                    $health['status'] = 'valid';
                }
            }
        } else {
            $health['status'] = 'expiring_soon';
            $health['message'] = 'Jira API token expiry date is not set.';
        }

        // Validate credentials against Jira to detect revoked/invalid tokens early.
        try {
            $rows = (new JiraService())->searchProjects('', 1, ['trigger' => 'token_health_check']);
            if (is_array($rows)) {
                $health['last_validated_at'] = current_time('mysql');
                if ($health['status'] === 'unknown') {
                    $health['status'] = 'valid';
                }
            }
        } catch (Throwable $exception) {
            $health['status'] = 'auth_failed';
            $health['message'] = 'Jira token validation failed: ' . $exception->getMessage();
        }

        update_option('kgr_jira_token_health', $health, false);
        self::send_jira_token_alert_if_needed($health);
        return $health;
    }

    public static function render_jira_token_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = is_object($screen) ? (string) ($screen->id ?? '') : '';
        if ($screenId !== '' && strpos($screenId, 'kgr') === false && strpos($screenId, 'kanguru') === false) {
            return;
        }

        $health = get_option('kgr_jira_token_health', []);
        if (!is_array($health) || $health === []) {
            return;
        }

        $status = (string) ($health['status'] ?? '');
        if (!in_array($status, ['expiring_soon', 'expired', 'auth_failed', 'missing'], true)) {
            return;
        }

        $daysLeft = $health['days_left'];
        $message = (string) ($health['message'] ?? 'Jira token needs attention.');
        if (is_numeric($daysLeft)) {
            $message .= ' Days left: ' . (int) $daysLeft . '.';
        }

        echo '<div class="notice notice-error"><p><strong>Kanguru Support Jira Token:</strong> ' . esc_html($message) . '</p></div>';
    }

    private static function send_jira_token_alert_if_needed(array $health): void
    {
        $status = (string) ($health['status'] ?? '');
        if (!in_array($status, ['expiring_soon', 'expired', 'auth_failed', 'missing'], true)) {
            return;
        }

        $settings = get_option('kgr_setting', []);
        $emailsRaw = is_array($settings) && isset($settings['general']['admin_emails']) ? (string) $settings['general']['admin_emails'] : '';
        $emails = array_values(array_filter(array_map('trim', explode(',', $emailsRaw)), static function (string $email): bool {
            return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        }));
        if ($emails === []) {
            return;
        }

        $daysLeft = isset($health['days_left']) && is_numeric($health['days_left']) ? (int) $health['days_left'] : null;
        $bucket = $status;
        if ($status === 'expiring_soon') {
            $thresholds = [30, 14, 7, 3, 1, 0];
            $selected = 30;
            if ($daysLeft !== null) {
                foreach ($thresholds as $t) {
                    if ($daysLeft <= $t) {
                        $selected = $t;
                    }
                }
            }
            $bucket = 'expiring_' . $selected;
        }

        $state = get_option('kgr_jira_token_alert_state', []);
        if (!is_array($state)) {
            $state = [];
        }
        if (!empty($state[$bucket])) {
            return;
        }

        $subject = '[Kanguru Support] Jira Token Alert: ' . strtoupper(str_replace('_', ' ', $status));
        $expiresOn = (string) ($health['expires_on'] ?? '');
        $healthMessage = (string) ($health['message'] ?? '');
        $checkedAt = (string) ($health['checked_at'] ?? '');
        $actionUrl = 'https://id.atlassian.com/manage-profile/security/api-tokens';

        $content = '<p style="margin:0 0 12px 0;"><strong>Jira token status:</strong> ' . esc_html($status) . '</p>';
        if ($daysLeft !== null) {
            $content .= '<p style="margin:0 0 12px 0;"><strong>Days left:</strong> ' . esc_html((string) $daysLeft) . '</p>';
        }
        $content .= '<p style="margin:0 0 12px 0;"><strong>Expires on:</strong> ' . esc_html($expiresOn) . '</p>';
        $content .= '<p style="margin:0 0 12px 0;"><strong>Message:</strong> ' . esc_html($healthMessage) . '</p>';
        $content .= '<p style="margin:0 0 12px 0;"><strong>Checked at:</strong> ' . esc_html($checkedAt) . '</p>';
        $content .= '<p style="margin:0 0 14px 0;">Jira API token is expiring soon. To keep receiving Jira tickets from the Kanguru Support plugin, please generate a new API token and update the credentials in the WordPress admin panel.</p>';
        $content .= '<p style="margin:0;text-align:center;"><a href="' . esc_url($actionUrl) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:10px 14px;background:#00001A;color:#fff;text-decoration:none;border-radius:4px;font-weight:700;">Get Atlassian API Token Now</a></p>';
        $body = self::wrap_admin_email_html('Jira Token Alert', $content);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        foreach ($emails as $email) {
            wp_mail($email, $subject, $body, $headers);
        }
        $state[$bucket] = current_time('mysql');
        update_option('kgr_jira_token_alert_state', $state, false);
    }

    private static function wrap_admin_email_html(string $title, string $contentHtml): string
    {
        $logoUrl = (string) Config::get('EMAIL_LOGO_URL', 'https://via.placeholder.com/160x48?text=Kanguru+Logo');
        $safeTitle = esc_html($title);
        $dateTime = date('Y-m-d H:i:s');

        return '<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $safeTitle . '</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#00001A;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:26px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="680" cellspacing="0" cellpadding="0" style="max-width:680px;width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding:0 20px 16px 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="left" valign="middle">
                    <img src="' . esc_url($logoUrl) . '" alt="Kanguru Logo" style="max-height:44px;width:auto;">
                  </td>
                  <td align="right" valign="middle" style="font-size:12px;color:#00001A;letter-spacing:0.2px;">' . esc_html($dateTime) . '</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f5;border-radius:12px;border:1px solid #ececec;">
                <tr>
                  <td style="padding:26px;">
                    <h2 style="margin:0 0 16px 0;font-size:21px;line-height:1.3;color:#00001A;">' . $safeTitle . '</h2>
                    ' . $contentHtml . '
                    <hr style="border:none;border-top:1px solid #ddd;margin:24px 0 16px;">
                    <p style="margin:0;font-size:13px;line-height:1.5;color:#00001A;">Support Team: <a href="mailto:support@kanguru.ca" style="color:#00001A;text-decoration:underline;">support@kanguru.ca</a></p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    public static function ajax_get_tickets(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');

        global $wpdb;
        $prefix = $wpdb->prefix . 'kgr_support_';

        $limit = 25;
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * $limit;
        $search = sanitize_text_field($_GET['s'] ?? '');
        $sort_by = sanitize_key($_GET['orderby'] ?? 'created_at');
        $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $where .= " AND (client_name LIKE %s OR client_company LIKE %s OR submitted_email LIKE %s OR public_id LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $allowed_sort = ['created_at', 'priority', 'status'];
        if (!in_array($sort_by, $allowed_sort))
            $sort_by = 'created_at';

        $query = "SELECT r.* FROM {$prefix}request r $where ORDER BY $sort_by $order LIMIT $offset, $limit";
        if (!empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
            $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}request $where", ...$params));
        } else {
            $rows = $wpdb->get_results($query, ARRAY_A);
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}request $where");
        }

        // Enrich with issue data
        foreach ($rows as &$row) {
            $issue = $wpdb->get_row($wpdb->prepare("SELECT issue_type, urgency_level FROM {$prefix}issue WHERE request_id = %d LIMIT 1", $row['id']), ARRAY_A);
            $row['issue_type'] = $issue['issue_type'] ?? 'N/A';
            $row['priority'] = $issue['urgency_level'] ?? 'medium';
        }

        wp_send_json_success([
            'tickets' => $rows,
            'pagination' => [
                'total' => (int) $total,
                'pages' => ceil($total / $limit),
                'current' => $page
            ]
        ]);
    }

    public static function ajax_get_ticket_details(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        $id = (int) $_GET['id'];

        global $wpdb;
        $prefix = $wpdb->prefix . 'kgr_support_';

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}request WHERE id = %d", $id), ARRAY_A);
        if (!$request)
            wp_send_json_error(['message' => 'Ticket not found']);

        $issues = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prefix}issue WHERE request_id = %d", $id), ARRAY_A);
        foreach ($issues as &$issue) {
            $attachments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prefix}attachment WHERE issue_id = %d", $issue['id']), ARRAY_A);
            foreach ($attachments as &$att) {
                $attachmentId = (int) ($att['id'] ?? 0);
                $att['file_url'] = $attachmentId > 0
                    ? wp_nonce_url(
                        admin_url('admin-ajax.php?action=kgr_download_attachment&attachment_id=' . $attachmentId),
                        'kgr_download_attachment_' . $attachmentId,
                        'nonce'
                    )
                    : '';
            }
            $issue['attachments'] = $attachments;
        }

        wp_send_json_success([
            'request' => $request,
            'issues' => $issues
        ]);
    }

    public static function ajax_download_attachment(): void
    {
        if (!current_user_can('manage_options')) {
            status_header(403);
            wp_die('Unauthorized');
        }

        $attachmentId = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;
        if ($attachmentId <= 0) {
            status_header(400);
            wp_die('Invalid attachment ID.');
        }

        $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'kgr_download_attachment_' . $attachmentId)) {
            status_header(403);
            wp_die('Invalid security token.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kgr_support_attachment';
        $attachment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $attachmentId),
            ARRAY_A
        );
        if (!$attachment) {
            status_header(404);
            wp_die('Attachment not found.');
        }

        $relativePath = (string) ($attachment['file_path'] ?: $attachment['temp_path'] ?: '');
        if ($relativePath === '') {
            status_header(404);
            wp_die('Attachment file path not found.');
        }

        $baseStorage = realpath(KGR_BACKEND_PATH . 'storage');
        $absolutePath = realpath(KGR_BACKEND_PATH . ltrim($relativePath, '/'));
        if ($baseStorage === false || $absolutePath === false || !is_file($absolutePath)) {
            status_header(404);
            wp_die('Attachment file not found.');
        }

        $baseStorageNormalized = rtrim(str_replace('\\', '/', $baseStorage), '/') . '/';
        $absoluteNormalized = str_replace('\\', '/', $absolutePath);
        if (strpos($absoluteNormalized, $baseStorageNormalized) !== 0) {
            status_header(403);
            wp_die('Access denied.');
        }

        $originalName = (string) ($attachment['original_name'] ?? basename($absolutePath));
        $filename = sanitize_file_name($originalName);
        if ($filename === '') {
            $filename = basename($absolutePath);
        }

        $mimeType = (string) ($attachment['mime_type'] ?? '');
        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $detected = $finfo ? finfo_file($finfo, $absolutePath) : false;
            if ($finfo) {
                finfo_close($finfo);
            }
            $mimeType = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        }

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow', true);
        readfile($absolutePath);
        exit;
    }

    public static function ajax_get_mapping_health(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'kgr_support_';
        $mapTable = $prefix . 'client_jira_map';
        $requestTable = $prefix . 'request';

        $health = [
            'active_mappings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable} WHERE is_active = 1"),
            'inactive_mappings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable} WHERE is_active = 0"),
            'incomplete_mappings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable} WHERE is_active = 1 AND (jira_project_key IS NULL OR jira_project_key = '')"),
            'unmapped_spaces' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable} WHERE is_active = 1 AND whmcs_client_id IS NULL AND mapping_source = 'jira_catalog'"),
            'missing_mapping_requests' => (int) $wpdb->get_var(
                "SELECT COUNT(*) 
                 FROM {$requestTable} r
                 LEFT JOIN {$mapTable} m ON m.whmcs_client_id = r.client_whmcs_id AND m.is_active = 1
                 WHERE r.status = 'confirmed'
                   AND (r.jira_issue_key IS NULL OR r.jira_issue_key = '')
                   AND (
                        r.client_whmcs_id IS NULL
                        OR r.client_whmcs_id <= 0
                        OR m.id IS NULL
                        OR m.jira_project_key IS NULL
                        OR m.jira_project_key = ''
                   )"
            ),
            'catalog_last_synced_at' => (string) get_option('kgr_jira_catalog_last_synced_at', ''),
            'catalog_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mapTable} WHERE is_active = 1 AND mapping_source = 'jira_catalog'"),
        ];

        wp_send_json_success($health);
    }

    public static function ajax_get_client_mappings(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'kgr_support_';
        $mapTable = $prefix . 'client_jira_map';

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = sanitize_text_field($_GET['s'] ?? '');
        $status = sanitize_text_field($_GET['status'] ?? 'all');
        $mappingType = sanitize_text_field($_GET['mapping_type'] ?? 'all');

        $where = 'WHERE 1=1';
        $args = [];

        if ($search !== '') {
            $where .= ' AND (
                CAST(whmcs_client_id AS CHAR) LIKE %s
                OR jira_project_key LIKE %s
                OR jira_project_name LIKE %s
                OR website_url LIKE %s
                OR client_company_name LIKE %s
            )';
            $term = '%' . $wpdb->esc_like($search) . '%';
            $args = [$term, $term, $term, $term, $term];
        }
        if ($status === 'active') {
            $where .= ' AND is_active = 1';
        } elseif ($status === 'inactive') {
            $where .= ' AND is_active = 0';
        }

        if ($mappingType === 'catalog') {
            $where .= " AND mapping_source = 'jira_catalog' AND (whmcs_client_id IS NULL OR whmcs_client_id <= 0)";
        } elseif ($mappingType === 'mapped') {
            $where .= ' AND (whmcs_client_id IS NOT NULL AND whmcs_client_id > 0)';
        }

        $listSql = "SELECT * FROM {$mapTable} {$where} ORDER BY updated_at DESC LIMIT {$offset}, {$limit}";
        $countSql = "SELECT COUNT(*) FROM {$mapTable} {$where}";

        if (!empty($args)) {
            $rows = $wpdb->get_results($wpdb->prepare($listSql, ...$args), ARRAY_A);
            $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$args));
        } else {
            $rows = $wpdb->get_results($listSql, ARRAY_A);
            $total = (int) $wpdb->get_var($countSql);
        }

        $missingRequests = (new ClientJiraMappingService())->listRequestsMissingMapping(25);

        wp_send_json_success([
            'rows' => $rows,
            'pagination' => [
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $limit)),
                'current' => $page,
            ],
            'missing_requests' => $missingRequests,
        ]);
    }

    public static function ajax_save_client_mapping(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $payload = [
                'whmcs_client_id' => (int) ($_POST['whmcs_client_id'] ?? 0),
                'jira_project_id' => sanitize_text_field($_POST['jira_project_id'] ?? ''),
                'jira_project_key' => sanitize_text_field($_POST['jira_project_key'] ?? ''),
                'jira_project_name' => sanitize_text_field($_POST['jira_project_name'] ?? ''),
                'jira_board_id' => sanitize_text_field($_POST['jira_board_id'] ?? ''),
                'jira_space_name' => sanitize_text_field($_POST['jira_space_name'] ?? ''),
                'website_url' => sanitize_text_field($_POST['website_url'] ?? ''),
                'client_company_name' => sanitize_text_field($_POST['client_company_name'] ?? ''),
                'mapping_source' => sanitize_text_field($_POST['mapping_source'] ?? 'manual'),
                'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1,
            ];
            $id = (new ClientJiraMappingService())->upsertMapping($payload);
            wp_send_json_success(['message' => 'Mapping saved.', 'id' => $id]);
        } catch (Throwable $exception) {
            Logger::error('Failed to save client Jira mapping', ['error' => $exception->getMessage()]);
            wp_send_json_error(['message' => $exception->getMessage()], 422);
        }
    }

    public static function ajax_deactivate_client_mapping(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $whmcsClientId = (int) ($_POST['whmcs_client_id'] ?? 0);
        if ($whmcsClientId <= 0) {
            wp_send_json_error(['message' => 'Invalid WHMCS client ID.'], 422);
        }

        $notes = sanitize_textarea_field($_POST['notes'] ?? 'Deactivated from admin settings');
        $ok = (new ClientJiraMappingService())->deactivateMapping($whmcsClientId, $notes);
        if (!$ok) {
            wp_send_json_error(['message' => 'Unable to deactivate mapping.'], 500);
        }
        wp_send_json_success(['message' => 'Mapping deactivated.']);
    }

    public static function ajax_search_jira_projects(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $q = sanitize_text_field($_GET['q'] ?? '');
            $service = new JiraService();
            $rows = $service->searchProjects($q, 50);
            wp_send_json_success(['rows' => $rows]);
        } catch (Throwable $exception) {
            Logger::error('Failed to search Jira projects', ['error' => $exception->getMessage()]);
            wp_send_json_error(['message' => 'Unable to fetch Jira projects right now.'], 500);
        }
    }

    /**
     * Manual trigger to fetch Jira spaces/projects immediately from admin UI.
     */
    public static function ajax_fetch_jira_spaces_now(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $jiraConfig = Config::getRequiredJiraValues(['JIRA_BASE_URL', 'JIRA_API_USER', 'JIRA_API_TOKEN']);
            if ($jiraConfig['missing'] !== []) {
                Logger::error('Manual Jira spaces fetch blocked by missing settings', [
                    'missing' => $jiraConfig['missing'],
                ]);
                wp_send_json_error([
                    'message' => 'Jira settings are incomplete. Missing: ' . implode(', ', $jiraConfig['missing']) . '. Save in settings or .env.',
                ], 422);
            }

            $stats = [];
            $rows = self::sync_jira_catalog($stats, 'manual_fetch');

            if (($stats['persisted_rows'] ?? 0) <= 0) {
                $fetchedCount = (int) ($stats['fetched_count'] ?? 0);
                Logger::error('Manual Jira spaces fetch completed with zero persisted rows', [
                    'fetched_count' => $fetchedCount,
                    'inserted_count' => (int) ($stats['inserted_count'] ?? 0),
                    'updated_count' => (int) ($stats['updated_count'] ?? 0),
                    'failed_count' => (int) ($stats['failed_count'] ?? 0),
                    'table_exists' => !empty($stats['table_exists']),
                    'table' => (string) ($stats['table'] ?? ''),
                    'last_db_error' => (string) ($stats['last_db_error'] ?? ''),
                ]);
                $message = $fetchedCount <= 0
                    ? 'Jira API returned zero projects/spaces. Check Jira permissions for the API user.'
                    : 'Jira API returned data, but nothing was persisted. Check logs for DB errors.';
                wp_send_json_error([
                    'message' => $message,
                    'stats' => $stats,
                ], 500);
            }

            wp_send_json_success([
                'message' => 'Jira spaces fetched successfully.',
                'count' => count($rows),
                'last_synced_at' => (string) get_option('kgr_jira_catalog_last_synced_at', ''),
                'stats' => $stats,
            ]);
        } catch (Throwable $exception) {
            Logger::error('Manual Jira spaces fetch failed', ['error' => $exception->getMessage()]);
            wp_send_json_error(['message' => 'Failed to fetch Jira spaces.'], 500);
        }
    }

    public static function sync_jira_catalog(?array &$stats = null, string $trigger = 'unknown'): array
    {
        return AdminJiraCatalogService::sync($stats, $trigger);
    }

    public static function ajax_test_jira_credentials(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $service = new JiraService();
            $rows = $service->searchProjects('', 1, ['trigger' => 'manual_test_jira']);
            if (count($rows) > 0) {
                wp_send_json_success(['message' => 'Jira credentials are valid and can access projects.']);
                return;
            }
            wp_send_json_error(['message' => 'Jira responded, but no projects are visible to this account.'], 422);
        } catch (Throwable $exception) {
            Logger::error('Jira credentials test failed', ['error' => $exception->getMessage()]);
            wp_send_json_error(['message' => 'Jira test failed: ' . $exception->getMessage()], 500);
        }
    }

    public static function ajax_test_whmcs_credentials(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $baseUrl = rtrim((string) Config::getWhmcsValue('WHMCS_API_BASE_URL', ''), '/');
            $apiKey = trim((string) Config::getWhmcsValue('WHMCS_API_KEY', ''));
            $secret = trim((string) Config::getWhmcsValue('WHMCS_API_TOKEN', ''));
            if ($baseUrl === '' || $apiKey === '' || $secret === '') {
                wp_send_json_error(['message' => 'WHMCS settings are incomplete.'], 422);
            }

            /*
             * Use the same WHMCS lookup route as the public website validator.
             * The remote API may not expose the email health-check route, and a
             * missing service result still proves that auth and routing worked.
             */
            $path = '/internal-api/v1/lookup/service-by-url';
            $payload = ['url' => 'credential-check.invalid'];
            $rawJsonBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $signature = hash_hmac('sha256', $apiKey . '.' . $timestamp . '.' . $nonce . '.' . $rawJsonBody, $secret);
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-KEY: ' . $apiKey,
                'X-TIMESTAMP: ' . $timestamp,
                'X-NONCE: ' . $nonce,
                'X-SIGNATURE: ' . $signature,
                'Content-Length: ' . strlen($rawJsonBody),
            ];
            $url = $baseUrl . $path;

            [$statusCode, $body] = AdminHttpClient::postJson($url, $headers, $rawJsonBody);
            $decoded = json_decode((string) $body, true);
            if ($statusCode >= 200 && $statusCode < 300) {
                wp_send_json_success(['message' => 'WHMCS credentials are valid and the lookup endpoint responded.']);
                return;
            }
            if (in_array($statusCode, [404, 422], true)) {
                wp_send_json_success(['message' => 'WHMCS credentials are valid and the lookup endpoint responded.']);
                return;
            }
            $errorMessage = is_array($decoded) && !empty($decoded['message']) ? (string) $decoded['message'] : 'Unexpected WHMCS response.';
            wp_send_json_error(['message' => 'WHMCS test failed (HTTP ' . $statusCode . '): ' . $errorMessage], 422);
        } catch (Throwable $exception) {
            Logger::error('WHMCS credentials test failed', ['error' => $exception->getMessage()]);
            wp_send_json_error(['message' => 'WHMCS test failed: ' . $exception->getMessage()], 500);
        }
    }

    public static function ajax_test_cloudflare_credentials(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $siteKey = trim((string) Config::get('CLOUDFLARE_TURNSTILE_SITE_KEY', ''));
        $secret = trim((string) Config::get('CLOUDFLARE_TURNSTILE_SECRET_KEY', ''));
        if ($siteKey === '' || $secret === '') {
            wp_send_json_error(['message' => 'Cloudflare site key/secret is incomplete.'], 422);
        }

        [$statusCode, $body] = AdminHttpClient::postForm('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => 'kgr-test-invalid-token',
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ]);
        $decoded = json_decode((string) $body, true);
        $errors = is_array($decoded) && isset($decoded['error-codes']) && is_array($decoded['error-codes']) ? $decoded['error-codes'] : [];

        if ($statusCode >= 200 && $statusCode < 300 && !in_array('invalid-input-secret', $errors, true)) {
            wp_send_json_success(['message' => 'Cloudflare keys look valid (site key present, secret accepted).']);
            return;
        }

        wp_send_json_error(['message' => 'Cloudflare key test failed. Check site key/secret.'], 422);
    }

    public static function ajax_test_google_credentials(): void
    {
        check_ajax_referer('kgr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $type = strtolower(trim((string) Config::get('GOOGLE_RECAPTCHA_TYPE', 'classic')));
        if ($type === 'enterprise') {
            $projectId = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_PROJECT_ID', ''));
            $siteKey = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_SITE_KEY', ''));
            $apiKey = trim((string) Config::get('GOOGLE_RECAPTCHA_ENTERPRISE_API_KEY', ''));
            if ($projectId === '' || $siteKey === '' || $apiKey === '') {
                wp_send_json_error(['message' => 'Google Enterprise project ID/site key/API key is incomplete.'], 422);
            }

            $rawBody = (string) json_encode([
                'event' => [
                    'token' => 'kgr-test-invalid-token',
                    'siteKey' => $siteKey,
                    'expectedAction' => (string) Config::get('GOOGLE_RECAPTCHA_EXPECTED_ACTION', 'submit'),
                    'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
                    'userIpAddress' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                ],
            ], JSON_UNESCAPED_SLASHES);
            [$statusCode, $body] = AdminHttpClient::postJson(
                'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey),
                ['Content-Type: application/json; charset=utf-8'],
                $rawBody
            );
            $decoded = json_decode((string) $body, true);
            if ($statusCode >= 200 && $statusCode < 300 && is_array($decoded) && isset($decoded['tokenProperties'])) {
                wp_send_json_success(['message' => 'Google Enterprise keys look valid (assessment endpoint accepted the request).']);
                return;
            }

            $errorMessage = is_array($decoded) && isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'Unexpected Google Enterprise response.';
            wp_send_json_error(['message' => 'Google Enterprise key test failed: ' . $errorMessage], 422);
            return;
        }

        $siteKey = trim((string) Config::get('GOOGLE_RECAPTCHA_SITE_KEY', ''));
        $secret = trim((string) Config::get('GOOGLE_RECAPTCHA_SECRET_KEY', ''));
        if ($siteKey === '' || $secret === '') {
            wp_send_json_error(['message' => 'Google classic site key/secret key is incomplete.'], 422);
        }

        [$statusCode, $body] = AdminHttpClient::postForm('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => 'kgr-test-invalid-token',
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ]);
        $decoded = json_decode((string) $body, true);
        $errors = is_array($decoded) && isset($decoded['error-codes']) && is_array($decoded['error-codes']) ? $decoded['error-codes'] : [];

        if ($statusCode >= 200 && $statusCode < 300 && !in_array('invalid-input-secret', $errors, true)) {
            wp_send_json_success(['message' => 'Google keys look valid (site key present, secret accepted).']);
            return;
        }

        wp_send_json_error(['message' => 'Google key test failed. Check site key/secret.'], 422);
    }

    public static function get_dashboard_stats(): array
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'kgr_support_';

        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}request WHERE status = 'pending_confirmation'");

        $in_progress = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}request WHERE status IN ('confirmed', 'ticket_created') AND (jira_status IS NULL OR jira_status != %s)",
            'Done'
        ));

        $missing_jira = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}request WHERE status IN ('confirmed', 'ticket_created') AND (jira_issue_key IS NULL OR jira_issue_key = '')");

        $categories = $wpdb->get_results($wpdb->prepare(
            "SELECT i.issue_type as name, COUNT(*) as count 
             FROM {$prefix}issue i 
             JOIN {$prefix}request r ON i.request_id = r.id 
             WHERE YEAR(r.created_at) = %d 
             GROUP BY i.issue_type",
            date('Y')
        ), ARRAY_A);

        return [
            'pending' => (int) $pending,
            'in_progress' => (int) $in_progress,
            'missing_jira' => (int) $missing_jira,
            'categories' => $categories
        ];
    }

    private static function is_sensitive_key(string $key): bool
    {
        if ($key === 'jira_api_token_expires_on') {
            return false;
        }
        $sensitive = ['api_key', 'api_token', 'password', 'secret', 'auth', 'pass'];
        foreach ($sensitive as $word) {
            if (stripos($key, $word) !== false)
                return true;
        }
        return false;
    }

    public static function normalize_jira_token_expiry_date(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $isDate = \DateTime::createFromFormat('Y-m-d', $raw);
        if ($isDate && $isDate->format('Y-m-d') === $raw) {
            return $raw;
        }

        $decoded = trim(self::decrypt($raw));
        if ($decoded !== '') {
            $isDecodedDate = \DateTime::createFromFormat('Y-m-d', $decoded);
            if ($isDecodedDate && $isDecodedDate->format('Y-m-d') === $decoded) {
                return $decoded;
            }
        }

        return '';
    }

    private static function should_preserve_on_empty(string $tab, string $key): bool
    {
        $pairs = [
            'whmcs:whmcs_api_key',
            'jira:jira_api_token',
            'jira:jira_webhook_secret',
            'whmcs:whmcs_api_token',
            'captcha:cloudflare_turnstile_site_key',
            'captcha:cloudflare_turnstile_secret_key',
            'captcha:google_recaptcha_site_key',
            'captcha:google_recaptcha_secret_key',
            'captcha:google_recaptcha_enterprise_site_key',
            'captcha:google_recaptcha_enterprise_api_key',
        ];
        return in_array($tab . ':' . $key, $pairs, true);
    }

    private static function http_post_json(string $url, array $headers, string $rawBody): array
    {
        return AdminHttpClient::postJson($url, $headers, $rawBody);
    }

    private static function http_post_form(string $url, array $data): array
    {
        return AdminHttpClient::postForm($url, $data);
    }

    public static function encrypt(string $value): string
    {
        return AdminCrypto::encrypt($value);
    }

    public static function decrypt(string $value): string
    {
        return AdminCrypto::decrypt($value);
    }
}


