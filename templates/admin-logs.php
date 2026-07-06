<?php
/**
 * Admin Logs Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$selectedLevel = sanitize_key((string) ($_GET['level'] ?? 'all'));
$selectedLevel = in_array($selectedLevel, ['all', 'error', 'warning', 'info'], true) ? $selectedLevel : 'all';
$search = sanitize_text_field((string) ($_GET['s'] ?? ''));
$summary = \SupportRequestFrontend\Includes\AdminController::get_log_summary();
$entries = \SupportRequestFrontend\Includes\AdminController::get_log_entries($selectedLevel, $search, 500);
$downloadUrl = wp_nonce_url(admin_url('admin.php?page=kgr-logs&download=1'), 'kgr_download_logs');
?>

<div class="kgr-admin-wrap" x-data="{ clearing: false, toast: '' }">
    <div class="kgr-admin-header">
        <h1>
            <img src="<?php echo KGR_PLUGIN_URL . 'assets/img/kanguru-menu-icon.svg'; ?>" class="kgr-header-logo"
                alt="Kanguru Icon">
            Kanguru <span>Support</span>
        </h1>
        <div class="kgr-admin-badge">Logs</div>
    </div>

    <div class="kgr-admin-stats">
        <div class="kgr-stat-card is-danger">
            <div class="kgr-stat-label">Errors</div>
            <div class="kgr-stat-value"><?php echo (int) $summary['error']; ?></div>
        </div>
        <div class="kgr-stat-card is-warning">
            <div class="kgr-stat-label">Warnings</div>
            <div class="kgr-stat-value"><?php echo (int) $summary['warning']; ?></div>
        </div>
        <div class="kgr-stat-card is-accent">
            <div class="kgr-stat-label">Info</div>
            <div class="kgr-stat-value"><?php echo (int) $summary['info']; ?></div>
        </div>
        <div class="kgr-stat-card">
            <div class="kgr-stat-label">Log File Size</div>
            <div class="kgr-stat-value"><?php echo esc_html(size_format((int) $summary['size'])); ?></div>
            <p class="kgr-admin-help"><?php echo esc_html((string) $summary['path']); ?></p>
        </div>
    </div>

    <div class="kgr-table-wrap">
        <div class="kgr-table-header" style="align-items:flex-end; gap:16px; flex-wrap:wrap;">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="page" value="kgr-logs">
                <div class="kgr-admin-field" style="margin-bottom:0; min-width:180px;">
                    <label>Level</label>
                    <select name="level">
                        <option value="all" <?php selected($selectedLevel, 'all'); ?>>All</option>
                        <option value="error" <?php selected($selectedLevel, 'error'); ?>>Error</option>
                        <option value="warning" <?php selected($selectedLevel, 'warning'); ?>>Warning</option>
                        <option value="info" <?php selected($selectedLevel, 'info'); ?>>Info</option>
                    </select>
                </div>
                <div class="kgr-admin-field" style="margin-bottom:0; min-width:280px;">
                    <label>Search</label>
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search message or context">
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="submit" class="kgr-btn kgr-btn--primary">Filter</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=kgr-logs')); ?>" class="kgr-btn">Reset</a>
                </div>
            </form>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <!-- <a href="<?php echo esc_url($downloadUrl); ?>" class="kgr-btn">Download Log</a> -->
                <button
                    type="button"
                    class="kgr-btn"
                    :disabled="clearing"
                    @click="
                        if (!confirm('Clear the plugin log file?')) return;
                        clearing = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'kgr_clear_logs', nonce: '<?php echo wp_create_nonce('kgr_admin_nonce'); ?>' })
                        })
                        .then(res => res.json())
                        .then(res => {
                            toast = (res.data && res.data.message) ? res.data.message : (res.success ? 'Logs cleared.' : 'Failed to clear logs.');
                            setTimeout(() => { window.location.reload(); }, 600);
                        })
                        .catch(() => {
                            toast = 'Failed to clear logs.';
                            setTimeout(() => toast = '', 8000);
                        })
                        .finally(() => clearing = false);
                    "
                >
                    <span x-show="!clearing">Clear Log</span>
                    <span x-show="clearing">Clearing...</span>
                </button>
            </div>
        </div>

        <table class="kgr-table">
            <thead>
                <tr>
                    <th style="width:170px;">Time</th>
                    <th style="width:100px;">Level</th>
                    <th>Message</th>
                    <th>Context</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entries === []): ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding:3rem;">No log entries found for the current filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $level = strtolower((string) $entry['level']);
                        $badgeClass = 'kgr-badge--medium';
                        if ($level === 'error') {
                            $badgeClass = 'kgr-badge--high';
                        } elseif ($level === 'warning') {
                            $badgeClass = 'kgr-badge--medium';
                        } elseif ($level === 'info') {
                            $badgeClass = 'kgr-badge--low';
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $entry['timestamp']); ?></td>
                            <td><span class="kgr-badge <?php echo esc_attr($badgeClass); ?>"><?php echo esc_html((string) $entry['level']); ?></span></td>
                            <td style="white-space:pre-wrap;"><?php echo esc_html((string) $entry['message']); ?></td>
                            <td style="white-space:pre-wrap; font-family:Consolas, monospace; font-size:12px;"><?php echo esc_html((string) $entry['context_raw']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <template x-if="toast">
        <div class="kgr-toast" x-transition>
            <span x-text="toast"></span>
        </div>
    </template>
</div>
