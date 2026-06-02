<?php
/**
 * Cron Controller for Kanguru Support
 * 
 * Handles WordPress cron schedules and background background tasks.
 */

namespace SupportRequestFrontend\Includes;

use App\config\Config;
use App\helpers\Logger;
use App\services\ClientJiraMappingService;
use App\services\QueueService;
use SupportRequestFrontend\Includes\AdminController;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class CronController
{
    private static $cleanup_hook = 'kgr_daily_cleanup_event';
    private static $queue_hook = 'kgr_hourly_queue_event';
    private static $mapping_audit_hook = 'kgr_client_jira_mapping_audit_event';
    private static $jira_catalog_sync_hook = 'kgr_jira_catalog_sync_event';

    public static function register(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_custom_schedules']);

        // Hook internal methods to WP Cron events
        add_action(self::$cleanup_hook, [__CLASS__, 'do_daily_cleanup']);
        add_action(self::$queue_hook, [__CLASS__, 'process_queue']);
        add_action(self::$mapping_audit_hook, [__CLASS__, 'run_mapping_audit']);
        add_action(self::$jira_catalog_sync_hook, [__CLASS__, 'run_jira_catalog_sync']);

        // Keep schedule definitions aligned after deployments without requiring plugin reactivation.
        self::ensure_event_schedule(self::$queue_hook, self::get_queue_recurrence());
        self::ensure_event_schedule(self::$mapping_audit_hook, 'daily');
        self::ensure_event_schedule(self::$jira_catalog_sync_hook, 'daily');
    }

    /**
     * Schedule tasks on plugin activation
     */
    public static function schedule_events(): void
    {
        // Activation hook can run before register(); ensure custom interval exists.
        add_filter('cron_schedules', [__CLASS__, 'add_custom_schedules']);

        if (!wp_next_scheduled(self::$cleanup_hook)) {
            wp_schedule_event(time(), 'daily', self::$cleanup_hook);
        }

        $queueRecurrence = self::get_queue_recurrence();
        if (!wp_next_scheduled(self::$queue_hook)) {
            wp_schedule_event(time(), $queueRecurrence, self::$queue_hook);
        }

        // Queue recurrence is configurable to reduce WP-Cron overhead on low-traffic sites.
        self::ensure_event_schedule(self::$queue_hook, $queueRecurrence);

        // Requested frequency: every 24 hours for mapping audit and catalog sync.
        self::ensure_event_schedule(self::$mapping_audit_hook, 'daily');
        self::ensure_event_schedule(self::$jira_catalog_sync_hook, 'daily');
    }

    /**
     * Clear schedules on plugin deactivation
     */
    public static function clear_events(): void
    {
        wp_clear_scheduled_hook(self::$cleanup_hook);
        wp_clear_scheduled_hook(self::$queue_hook);
        wp_clear_scheduled_hook(self::$mapping_audit_hook);
        wp_clear_scheduled_hook(self::$jira_catalog_sync_hook);
    }

    public static function add_custom_schedules(array $schedules): array
    {
        if (!isset($schedules['kgr_every_minute'])) {
            $schedules['kgr_every_minute'] = [
                'interval' => 60,
                'display' => __('Every Minute (Kanguru Support)', 'kanguru-support'),
            ];
        }
        if (!isset($schedules['kgr_every_5_minutes'])) {
            $schedules['kgr_every_5_minutes'] = [
                'interval' => 300,
                'display' => __('Every 5 Minutes (Kanguru Support)', 'kanguru-support'),
            ];
        }
        if (!isset($schedules['kgr_every_15_minutes'])) {
            $schedules['kgr_every_15_minutes'] = [
                'interval' => 900,
                'display' => __('Every 15 Minutes (Kanguru Support)', 'kanguru-support'),
            ];
        }
        if (!isset($schedules['kgr_every_30_minutes'])) {
            $schedules['kgr_every_30_minutes'] = [
                'interval' => 1800,
                'display' => __('Every 30 Minutes (Kanguru Support)', 'kanguru-support'),
            ];
        }
        return $schedules;
    }

    private static function get_queue_recurrence(): string
    {
        $default = 'kgr_every_15_minutes';
        $settings = get_option('kgr_setting', []);
        $saved = '';
        if (is_array($settings) && isset($settings['general']) && is_array($settings['general'])) {
            $saved = sanitize_key((string) ($settings['general']['queue_cron_interval'] ?? ''));
        }

        $candidate = $saved !== '' ? $saved : $default;
        $candidate = (string) apply_filters('kgr_queue_cron_recurrence', $candidate);

        $schedules = wp_get_schedules();
        return isset($schedules[$candidate]) ? $candidate : $default;
    }

    private static function ensure_event_schedule(string $hook, string $recurrence): void
    {
        $currentRecurrence = '';

        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event($hook);
            $currentRecurrence = is_object($event) ? (string) ($event->schedule ?? '') : '';
        }

        if ($currentRecurrence !== '' && $currentRecurrence !== $recurrence) {
            wp_clear_scheduled_hook($hook);
        }

        if (!wp_next_scheduled($hook)) {
            $scheduled = wp_schedule_event(time() + 60, $recurrence, $hook);
            if (is_wp_error($scheduled)) {
                self::logCronFailure($hook, 'Failed to schedule cron event', [
                    'recurrence' => $recurrence,
                    'wp_error' => $scheduled->get_error_message(),
                ]);
                return;
            }
            if ($scheduled === false) {
                self::logCronFailure($hook, 'Failed to schedule cron event', [
                    'recurrence' => $recurrence,
                ]);
            }
        }
    }

    /**
     * Task: Cleanup unverified/expired requests
     */
    public static function do_daily_cleanup(): void
    {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'kgr_support_request';

            // 1. Expire unverified requests older than 24 hours
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status = 'expired' WHERE status = 'pending_confirmation' AND created_at <= %s",
                date('Y-m-d H:i:s', time() - (24 * 3600))
            ));

            // 2. Optional: Permanent delete for requests older than 30 days
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE status IN ('expired', 'rejected') AND created_at <= %s",
                date('Y-m-d H:i:s', time() - (30 * 24 * 3600))
            ));
        } catch (Throwable $exception) {
            self::logCronFailure(self::$cleanup_hook, 'Daily cleanup failed', [], $exception);
        }
    }

    /**
     * Task: Process the background job queue (Jira syncs, Email retries)
     */
    public static function process_queue(): void
    {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'kgr_support_queue';
            $now = current_time('mysql');
            $pendingTotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'retry')");
            $dueNow = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'retry') AND next_run_at IS NOT NULL AND next_run_at <= %s",
                    $now
                )
            );

            $queue = new QueueService();
            $stats = [];
            $queue->process([], 25, $stats);

            if ($pendingTotal > 0 && $dueNow === 0) {
                self::logCronFailure(self::$queue_hook, 'Queue cron found pending jobs but none are due yet', [
                    'pending_total' => $pendingTotal,
                    'due_now' => $dueNow,
                    'now' => $now,
                ]);
            }

            if ($pendingTotal > 0 && ($stats['claimed'] ?? 0) === 0 && $dueNow > 0) {
                self::logCronFailure(self::$queue_hook, 'Queue cron found due jobs but could not claim any', [
                    'pending_total' => $pendingTotal,
                    'due_now' => $dueNow,
                    'stats' => $stats,
                ]);
            }

            if (!empty($stats['errors'])) {
                self::logCronFailure(self::$queue_hook, 'Queue cron processed jobs with errors', [
                    'stats' => $stats,
                ]);
            }
        } catch (Throwable $exception) {
            self::logCronFailure(self::$queue_hook, 'Queue processing failed', [], $exception);
        }
    }

    /**
     * Task: report confirmed support requests missing WHMCS->Jira mapping.
     * This is intentionally non-destructive and safe to run daily.
     */
    public static function run_mapping_audit(): void
    {
        try {
            $service = new ClientJiraMappingService();
            $service->logMissingMappingAudit(50);
        } catch (Throwable $exception) {
            self::logCronFailure(self::$mapping_audit_hook, 'Mapping audit failed', [], $exception);
        }
    }

    /**
     * Task: keep Jira project catalog fresh for the mapping typeahead UI.
     */
    public static function run_jira_catalog_sync(): void
    {
        try {
            AdminController::run_jira_token_health_check('cron_daily');
            $required = Config::getRequiredJiraValues(['JIRA_BASE_URL', 'JIRA_API_USER', 'JIRA_API_TOKEN']);
            if ($required['missing'] !== []) {
                self::logCronFailure(self::$jira_catalog_sync_hook, 'Jira catalog sync blocked: Jira settings missing', [
                    'missing' => $required['missing'],
                ]);
                return;
            }

            $stats = [];
            AdminController::sync_jira_catalog($stats, 'cron_daily');

            if ((int) ($stats['fetched_count'] ?? 0) <= 0) {
                self::logCronFailure(self::$jira_catalog_sync_hook, 'Jira catalog sync returned zero projects/spaces', [
                    'stats' => $stats,
                ]);
                return;
            }
            if ((int) ($stats['persisted_rows'] ?? 0) <= 0) {
                self::logCronFailure(self::$jira_catalog_sync_hook, 'Jira catalog sync persisted zero rows', [
                    'stats' => $stats,
                ]);
                return;
            }
            if ((int) ($stats['failed_count'] ?? 0) > 0) {
                self::logCronFailure(self::$jira_catalog_sync_hook, 'Jira catalog sync completed with row-level failures', [
                    'stats' => $stats,
                ]);
            }
        } catch (Throwable $exception) {
            self::logCronFailure(self::$jira_catalog_sync_hook, 'Jira catalog sync failed', [], $exception);
        }
    }

    /**
     * Expose cron scheduling health for admin diagnostics.
     *
     * @return array<string, mixed>
     */
    public static function get_cron_health(): array
    {
        $nextJiraCatalog = wp_next_scheduled(self::$jira_catalog_sync_hook);
        $nextMappingAudit = wp_next_scheduled(self::$mapping_audit_hook);

        return [
            'jira_catalog_hook' => self::$jira_catalog_sync_hook,
            'jira_catalog_next_ts' => $nextJiraCatalog ?: 0,
            'jira_catalog_next_local' => $nextJiraCatalog ? wp_date('Y-m-d H:i:s', (int) $nextJiraCatalog) : '',
            'mapping_audit_hook' => self::$mapping_audit_hook,
            'mapping_audit_next_ts' => $nextMappingAudit ?: 0,
            'mapping_audit_next_local' => $nextMappingAudit ? wp_date('Y-m-d H:i:s', (int) $nextMappingAudit) : '',
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
        ];
    }

    /**
     * Centralized cron failure logger.
     */
    private static function logCronFailure(string $event, string $message, array $context = [], ?Throwable $exception = null): void
    {
        $payload = array_merge([
            'event' => $event,
        ], $context);

        if ($exception !== null) {
            $payload['error'] = $exception->getMessage();
            $payload['exception'] = get_class($exception);
            $payload['code'] = (int) $exception->getCode();
        }

        Logger::error($message, $payload);
        error_log('Kanguru Support cron error [' . $event . ']: ' . ($payload['error'] ?? $message));
    }
}
