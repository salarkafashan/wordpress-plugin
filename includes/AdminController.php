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
                }
            }

            foreach ($fields as $key => $value) {
                $rawValue = trim((string) $value);
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
        wp_send_json_success(['message' => 'Settings saved successfully']);
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
                $relative = ltrim((string) ($att['file_path'] ?: $att['temp_path'] ?: ''), '/');
                if ($relative !== '') {
                    $att['file_url'] = KGR_PLUGIN_URL . 'backend/' . $relative;
                } else {
                    $att['file_url'] = '';
                }
            }
            $issue['attachments'] = $attachments;
        }

        wp_send_json_success([
            'request' => $request,
            'issues' => $issues
        ]);
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
        $sampleProjectIds = array_values(array_filter(array_slice(array_map(
            static fn(array $r): string => sanitize_text_field((string) ($r['jira_project_id'] ?? '')),
            $rows
        ), 0, 5)));

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
                // Preserve existing whmcs mapping ownership when project is already mapped.
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

        // Mark catalog rows that disappeared from current Jira API response as inactive.
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

        // Closed/archived catalog projects should not be retained in local mapping catalog.
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

            $path = '/internal-api/v1/lookup/user-by-email';
            $payload = ['email' => 'healthcheck+' . time() . '@example.com'];
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

            [$statusCode, $body] = self::http_post_json($url, $headers, $rawJsonBody);
            $decoded = json_decode((string) $body, true);
            if ($statusCode >= 200 && $statusCode < 300) {
                wp_send_json_success(['message' => 'WHMCS credentials are valid.']);
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

        [$statusCode, $body] = self::http_post_form('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
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

        $siteKey = trim((string) Config::get('GOOGLE_RECAPTCHA_SITE_KEY', ''));
        $secret = trim((string) Config::get('GOOGLE_RECAPTCHA_SECRET_KEY', ''));
        if ($siteKey === '' || $secret === '') {
            wp_send_json_error(['message' => 'Google site key/secret is incomplete.'], 422);
        }

        [$statusCode, $body] = self::http_post_form('https://www.google.com/recaptcha/api/siteverify', [
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
        $sensitive = ['api_key', 'api_token', 'password', 'secret', 'auth', 'pass'];
        foreach ($sensitive as $word) {
            if (stripos($key, $word) !== false)
                return true;
        }
        return false;
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
        ];
        return in_array($tab . ':' . $key, $pairs, true);
    }

    private static function http_post_json(string $url, array $headers, string $rawBody): array
    {
        if (!function_exists('curl_init')) {
            return [0, 'cURL is unavailable'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') {
            return [0, $error !== '' ? $error : 'Unknown transport error'];
        }
        return [$status, (string) $body];
    }

    private static function http_post_form(string $url, array $data): array
    {
        if (!function_exists('curl_init')) {
            return [0, 'cURL is unavailable'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') {
            return [0, $error !== '' ? $error : 'Unknown transport error'];
        }
        return [$status, (string) $body];
    }

    public static function encrypt(string $value): string
    {
        if (empty($value))
            return '';
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_kgr_key';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', hash('sha256', $key, true), 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $value): string
    {
        if (empty($value))
            return '';
        $data = base64_decode($value);
        $iv_len = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_len);
        $encrypted = substr($data, $iv_len);
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_kgr_key';
        return (string) openssl_decrypt($encrypted, 'aes-256-cbc', hash('sha256', $key, true), 0, $iv);
    }
}
