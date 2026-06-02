<?php
/**
 * Admin Tickets Template
 * 
 * High-end dashboard using Alpine.js for data tables and details.
 */

if (!defined('ABSPATH')) {
    exit;
}

$stats = \SupportRequestFrontend\Includes\AdminController::get_dashboard_stats();
?>

<div class="kgr-admin-wrap" x-data="kgrTickets">
    <div class="kgr-admin-header">
        <h1>
            <img src="<?php echo KGR_PLUGIN_URL . 'assets/img/kanguru-menu-icon.svg'; ?>" class="kgr-header-logo"
                alt="Kanguru Icon">
            Kanguru <span>Support</span>
        </h1>
        <div class="kgr-admin-badge">Ticket Management</div>
    </div>

    <!-- Stats Summary Section -->
    <div class="kgr-admin-stats">
        <div class="kgr-stat-card">
            <div class="kgr-stat-label">Pending Confirmation</div>
            <div class="kgr-stat-value"><?php echo number_format($stats['pending']); ?></div>
            <p class="kgr-admin-help">Awaiting user email verification</p>
        </div>
        <div class="kgr-stat-card">
            <div class="kgr-stat-label">In Progress</div>
            <div class="kgr-stat-value"><?php echo number_format($stats['in_progress']); ?></div>
            <p class="kgr-admin-help">Active in Jira (Not Done)</p>
        </div>
        <div class="kgr-stat-card is-warning">
            <div class="kgr-stat-label">Missing in Jira</div>
            <div class="kgr-stat-value"><?php echo number_format($stats['missing_jira']); ?></div>
            <p class="kgr-admin-help" style="color: #92400e;">Confirmed but no Jira ticket found</p>
        </div>
        <div class="kgr-stat-card is-accent">
            <div class="kgr-stat-label"><?php echo date('Y'); ?> Categories</div>
            <div class="kgr-stat-value"><?php echo array_sum(array_column($stats['categories'], 'count')); ?></div>
            <div class="kgr-stat-categories">
                <?php foreach ($stats['categories'] as $cat): ?>
                    <span class="kgr-category-pill"><?php echo esc_html($cat['name']); ?>:
                        <?php echo (int) $cat['count']; ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tickets Table Section -->
    <div class="kgr-table-wrap">
        <div class="kgr-table-header">
            <div class="kgr-table-actions">
                <span class="kgr-admin-help" x-text="'Showing ' + pagination.total + ' tickets'"></span>
            </div>
            <div class="kgr-search-box">
                <i class="dashicons dashicons-search"></i>
                <input type="text" x-model.debounce.500ms="search" placeholder="Search by name, company, email...">
            </div>
        </div>

        <table class="kgr-table">
            <thead>
                <tr>
                    <th @click="sort('public_id')" style="cursor:pointer">ID <i class="dashicons"
                            :class="getSortIcon('public_id')"></i></th>
                    <th>Client / Company</th>
                    <th>Sender Info</th>
                    <th>Issue Type</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th @click="sort('created_at')" style="cursor:pointer">Created <i class="dashicons"
                            :class="getSortIcon('created_at')"></i></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="t in tickets" :key="t.id">
                    <tr @click="viewDetails(t.id)" style="cursor:pointer;">
                        <td class="kgr-data-value" x-text="'#' + t.public_id"></td>
                        <td>
                            <div style="font-weight:700" x-text="t.client_name"></div>
                            <div class="kgr-admin-help" x-text="t.client_company"></div>
                        </td>
                        <td>
                            <div x-text="t.client_name"></div>
                            <div class="kgr-admin-help" x-text="t.submitted_email"></div>
                        </td>
                        <td>
                            <span x-text="t.issue_type"></span>
                        </td>
                        <td>
                            <span class="kgr-status-pill" x-text="formatStatus(t.status || 'pending')"></span>
                        </td>
                        <td>
                            <span class="kgr-badge" :class="'kgr-badge--' + priorityClass(t.priority)"
                                x-text="formatPriority(t.priority)"></span>
                        </td>
                        <td x-text="formatDate(t.created_at)"></td>
                        <td>
                            <button @click.stop="viewDetails(t.id)" class="kgr-btn"
                                style="background:transparent; color: var(--kgr-admin-primary); padding: 5px;">
                                <i class="dashicons dashicons-visibility"></i>
                            </button>
                        </td>
                    </tr>
                </template>
                <tr x-show="loading">
                    <td colspan="7" style="text-align:center; padding: 4rem;">Loading tickets...</td>
                </tr>
                <tr x-show="!loading && tickets.length === 0">
                    <td colspan="7" style="text-align:center; padding: 4rem;">No tickets found matching your criteria.
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="kgr-pagination" x-show="pagination.pages > 1">
            <div class="kgr-admin-help">
                Page <span x-text="pagination.current"></span> of <span x-text="pagination.pages"></span>
            </div>
            <div style="display:flex; gap: 8px;">
                <button class="kgr-btn" :disabled="pagination.current <= 1" @click="page--">Previous</button>
                <button class="kgr-btn kgr-btn--primary" :disabled="pagination.current >= pagination.pages"
                    @click="page++">Next</button>
            </div>
        </div>
    </div>

    <!-- Side Panel Details -->
    <div class="kgr-side-panel-overlay" x-show="showDetails" x-transition.opacity @click="showDetails = false" x-cloak>
        <div class="kgr-side-panel" x-show="showDetails" x-transition.scale.origin.right @click.stop x-cloak>
            <div class="kgr-panel-header">
                <h3>Ticket Details <span x-text="'#' + (detailData.request?.public_id || '')"></span></h3>
                <button @click="showDetails = false"
                    style="background:transparent; border:none; color:white; cursor:pointer">
                    <i class="dashicons dashicons-no-alt"></i>
                </button>
            </div>
            <div class="kgr-panel-content">
                <template x-if="detailsLoading">
                    <div>
                        <div class="kgr-panel-section">
                            <h4>Loading details...</h4>
                            <div style="display:flex; flex-direction:column; gap: 12px;">
                                <div style="height:16px; border-radius:6px; background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%); background-size:200% 100%; animation:kgrSkeleton 1.2s ease-in-out infinite;"></div>
                                <div style="height:16px; width:80%; border-radius:6px; background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%); background-size:200% 100%; animation:kgrSkeleton 1.2s ease-in-out infinite;"></div>
                                <div style="height:16px; width:65%; border-radius:6px; background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%); background-size:200% 100%; animation:kgrSkeleton 1.2s ease-in-out infinite;"></div>
                            </div>
                        </div>
                        <div class="kgr-panel-section">
                            <div style="height:120px; border-radius:8px; background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%); background-size:200% 100%; animation:kgrSkeleton 1.2s ease-in-out infinite;"></div>
                        </div>
                    </div>
                </template>
                <template x-if="detailData.request">
                    <div>
                        <div class="kgr-panel-section">
                            <h4>Client Information</h4>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Name</div>
                                <div class="kgr-data-value" x-text="detailData.request.client_name"></div>
                            </div>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Company</div>
                                <div class="kgr-data-value" x-text="detailData.request.client_company"></div>
                            </div>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">WHMCS ID</div>
                                <div class="kgr-data-value" x-text="detailData.request.client_whmcs_id"></div>
                            </div>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Email</div>
                                <div class="kgr-data-value" x-text="detailData.request.submitted_email"></div>
                            </div>
                        </div>

                        <div class="kgr-panel-section">
                            <h4>Status & Jira</h4>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Support Status</div>
                                <div class="kgr-data-value"><span class="kgr-status-pill"
                                        x-text="formatStatus(detailData.request.status)"></span></div>
                            </div>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Jira Key</div>
                                <div class="kgr-data-value"><strong
                                        x-text="detailData.request.jira_issue_key || 'N/A'"></strong></div>
                            </div>
                            <div class="kgr-data-row">
                                <div class="kgr-data-label">Jira Status</div>
                                <div class="kgr-data-value" x-text="detailData.request.jira_status || 'N/A'"></div>
                            </div>
                        </div>

                        <div class="kgr-panel-section">
                            <h4>Issues Requested</h4>
                            <template x-for="issue in detailData.issues" :key="issue.id">
                                <div
                                    style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--kgr-admin-border);">
                                    <div style="font-weight:700; color: var(--kgr-admin-primary); margin-bottom: 0.5rem;"
                                        x-text="issue.issue_type"></div>
                                    <div style="font-size:0.9rem; white-space: pre-wrap;" x-text="issue.description">
                                    </div>
                                    <template x-if="issue.current_content">
                                        <div style="font-size:0.85rem; margin-top: 0.5rem;">
                                            <strong>Change details:</strong>
                                            <div style="white-space: pre-wrap;" x-text="issue.current_content"></div>
                                        </div>
                                    </template>
                                    <template x-if="issue.new_content">
                                        <div style="font-size:0.85rem; margin-top: 0.5rem;">
                                            <strong>Image details:</strong>
                                            <div style="white-space: pre-wrap;" x-text="issue.new_content"></div>
                                        </div>
                                    </template>

                                    <template x-if="issue.attachments?.length > 0">
                                        <div style="margin-top:1rem;">
                                            <div class="kgr-admin-help">Attachments:</div>
                                            <div style="display:flex; gap: 8px; margin-top: 4px;">
                                                <template x-for="att in issue.attachments" :key="att.id">
                                                    <a :href="att.file_url" target="_blank" class="kgr-category-pill"
                                                        style="text-decoration:none">
                                                        <i class="dashicons dashicons-paperclip"
                                                            style="font-size:12px"></i> <span
                                                            x-text="att.original_name"></span>
                                                    </a>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('kgrTickets', () => ({
            tickets: [],
            loading: false,
            search: '',
            page: 1,
            orderBy: 'created_at',
            order: 'DESC',
            pagination: { total: 0, pages: 1, current: 1 },
            showDetails: false,
            detailsLoading: false,
            detailData: {},

            init() {
                this.$watch('page', () => this.fetchTickets());
                this.$watch('search', () => { this.page = 1; this.fetchTickets(); });
                this.$watch('orderBy', () => this.fetchTickets());
                this.$watch('order', () => this.fetchTickets());
                this.fetchTickets();
            },

            fetchTickets() {
                this.loading = true;
                const params = new URLSearchParams({
                    action: 'kgr_get_tickets',
                    paged: this.page,
                    s: this.search,
                    orderby: this.orderBy,
                    order: this.order,
                    nonce: '<?php echo wp_create_nonce('kgr_admin_nonce'); ?>'
                });

                fetch(ajaxurl + '?' + params.toString())
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.tickets = res.data.tickets;
                            this.pagination = res.data.pagination;
                        }
                    })
                    .finally(() => this.loading = false);
            },

            viewDetails(id) {
                this.showDetails = true;
                this.detailsLoading = true;
                this.detailData = {};

                const params = new URLSearchParams({
                    action: 'kgr_get_ticket_details',
                    id: id,
                    nonce: '<?php echo wp_create_nonce('kgr_admin_nonce'); ?>'
                });

                fetch(ajaxurl + '?' + params.toString())
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.detailData = res.data;
                        }
                    })
                    .finally(() => {
                        this.detailsLoading = false;
                    });
            },

            sort(col) {
                if (this.orderBy === col) {
                    this.order = this.order === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    this.orderBy = col;
                    this.order = 'DESC';
                }
            },

            getSortIcon(col) {
                if (this.orderBy !== col) return 'dashicons-sort';
                return this.order === 'ASC' ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
            },

            formatDate(dateStr) {
                return new Date(dateStr).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
            },

            formatPriority(raw) {
                const value = String(raw || '').toLowerCase().trim();
                if (value === 'high' || value.includes('critical') || value.includes('urgent')) return 'High';
                if (value === 'low' || value.includes('minor') || value.includes('small')) return 'Low';
                return 'Medium';
            },

            priorityClass(raw) {
                const value = String(raw || '').toLowerCase().trim();
                if (value === 'high' || value.includes('critical') || value.includes('urgent')) return 'high';
                if (value === 'low' || value.includes('minor') || value.includes('small')) return 'low';
                return 'medium';
            },

            formatStatus(raw) {
                const value = String(raw || '').trim();
                if (!value) {
                    return 'Pending';
                }

                return value
                    .replace(/[_-]+/g, ' ')
                    .replace(/\s+/g, ' ')
                    .toLowerCase()
                    .replace(/\b\w/g, (match) => match.toUpperCase());
            }
        }));
    });
</script>
<style>
    @keyframes kgrSkeleton {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
</style>
