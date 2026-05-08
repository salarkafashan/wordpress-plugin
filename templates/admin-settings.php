<?php
/**
 * Admin Settings Template
 * 
 * Uses Alpine.js for tab switching and dynamic interactions.
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = get_option('kgr_setting', []);
$routing_mode = $settings['jira']['jira_routing_mode'] ?? 'support_space';
$support_space_label = $settings['jira']['jira_support_space_label'] ?? 'Support & Service Requests';
$support_space_id = $settings['jira']['jira_support_space_id'] ?? ($settings['jira']['jira_support_project_key'] ?? ($settings['jira']['jira_project_key'] ?? 'KSR'));
$support_project_key = $settings['jira']['jira_support_project_key'] ?? $support_space_id;
$support_issue_type = $settings['jira']['jira_issue_type'] ?? 'support_ticket';
$has_whmcs_key = !empty($settings['whmcs']['whmcs_api_key']);
$has_whmcs_token = !empty($settings['whmcs']['whmcs_api_token']);
$has_jira_token = !empty($settings['jira']['jira_api_token']);
$has_jira_webhook_secret = !empty($settings['jira']['jira_webhook_secret']);
$has_cf_site_key = !empty($settings['captcha']['cloudflare_turnstile_site_key']);
$has_cf_secret = !empty($settings['captcha']['cloudflare_turnstile_secret_key']);
$has_google_site_key = !empty($settings['captcha']['google_recaptcha_site_key']);
$has_google_secret = !empty($settings['captcha']['google_recaptcha_secret_key']);
?>

<div class="kgr-admin-wrap" x-data="kgrSettings">
    <div class="kgr-admin-header">
        <h1>
            <img src="<?php echo KGR_PLUGIN_URL . 'assets/img/kanguru-menu-icon.svg'; ?>" class="kgr-header-logo"
                alt="Kanguru Icon">
            Kanguru <span>Support</span>
        </h1>
        <div class="kgr-admin-badge">v
            <?php echo KGR_PLUGIN_VERSION; ?>
        </div>
    </div>

    <div class="kgr-admin-tabs">
        <button class="kgr-admin-tab" :class="tab === 'general' && 'is-active'" @click="tab = 'general'">General</button>
        <button class="kgr-admin-tab" :class="tab === 'whmcs' && 'is-active'" @click="tab = 'whmcs'">WHMCS API</button>
        <button class="kgr-admin-tab" :class="tab === 'jira' && 'is-active'" @click="tab = 'jira'">Jira
            Integration</button>
        <button class="kgr-admin-tab" :class="tab === 'captcha' && 'is-active'" @click="tab = 'captcha'">Security /
            Captcha</button>
        <button class="kgr-admin-tab" :class="tab === 'mappings' && 'is-active'" @click="tab = 'mappings'; maybeLoadMappings();">Mappings</button>
    </div>

    <div class="kgr-admin-card">
        <form @submit.prevent="saveSettings">
            <!-- GENERAL TAB -->
            <div x-show="tab === 'general'" class="kgr-admin-grid">
                <div>
                    <h3>General Settings</h3>
                    <p class="kgr-admin-help">Manage the primary plugin settings and environments.</p>

                    <div class="kgr-admin-field">
                        <label>Admin Email Address(es)</label>
                        <input type="text" name="general[admin_emails]" 
                               value="<?php echo esc_attr($settings['general']['admin_emails'] ?? ''); ?>" 
                               placeholder="admin@example.com" required>
                        <p class="kgr-admin-help">Using commas you can add multiple recipients.</p>
                        <p x-show="generalErrors.admin_emails" x-text="generalErrors.admin_emails" style="color:#d9534f; font-size:12px; margin:4px 0 0 0;"></p>
                    </div>
                </div>
                <div>
                    <h3> sandbox Environment</h3>
                    <p class="kgr-admin-help">Safely route app emails to sandbox destinations for testing.</p>

                    <div class="kgr-admin-field">
                        <label> sandbox Mode</label>
                        <select name="general[testing_mode]" x-model="testingMode">
                            <option value="off">Off (Production)</option>
                            <option value="on">On (sandbox)</option>
                        </select>
                        <p class="kgr-admin-help">When sandbox is active, all emails (confirmation, admin notifications, etc.) securely route to the test recipients below.</p>
                    </div>

                    <div class="kgr-admin-field" x-show="testingMode === 'on'" x-transition>
                        <label>Sandbox Recipient Emails</label>
                        <input type="text" name="general[test_emails]" 
                               value="<?php echo esc_attr($settings['general']['test_emails'] ?? ''); ?>" 
                               placeholder="test@kanguru.ca" 
                               :required="testingMode === 'on'">
                        <p class="kgr-admin-help">Using commas you can add multiple recipients.</p>
                        <p x-show="generalErrors.test_emails" x-text="generalErrors.test_emails" style="color:#d9534f; font-size:12px; margin:4px 0 0 0;"></p>
                    </div>
                </div>
            </div>

            <!-- WHMCS TAB -->
            <div x-show="tab === 'whmcs'" class="kgr-admin-grid" style="display: none;">
                <div>
                    <h3>Connection Settings</h3>
                    <p class="kgr-admin-help">Configure your WHMCS API endpoint and authentication.</p>

                    <div class="kgr-admin-field">
                        <label>WHMCS API URL</label>
                        <input type="url" name="whmcs[whmcs_api_base_url]"
                            value="<?php echo esc_attr($settings['whmcs']['whmcs_api_base_url'] ?? ''); ?>"
                            placeholder="https://kgr360.com">
                    </div>

                    <div class="kgr-admin-field">
                        <label>API Key (System)</label>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <span x-text="credentialStatus('whmcs_api_key', <?php echo $has_whmcs_key ? 'true' : 'false'; ?>)"></span>
                            <button type="button" class="kgr-btn" @click="toggleCredentialEdit('whmcs_api_key')">
                                <span x-text="credentialEditing.whmcs_api_key ? 'Cancel' : 'Replace key'"></span>
                            </button>
                        </div>
                        <input type="password" name="whmcs[whmcs_api_key]" x-show="credentialEditing.whmcs_api_key"
                            value="" autocomplete="new-password" placeholder="Enter a new WHMCS API key">
                        <span class="kgr-admin-help">Click Replace key, enter new value, then click Save All Settings. If hidden, current key stays unchanged.</span>
                    </div>

                    <div class="kgr-admin-field">
                        <label>Secret Token</label>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <span x-text="credentialStatus('whmcs_api_token', <?php echo $has_whmcs_token ? 'true' : 'false'; ?>)"></span>
                            <button type="button" class="kgr-btn" @click="toggleCredentialEdit('whmcs_api_token')">
                                <span x-text="credentialEditing.whmcs_api_token ? 'Cancel' : 'Replace token'"></span>
                            </button>
                            <button type="button" class="kgr-btn" :disabled="testingWhmcs" @click="testWhmcsCredentials()">
                                <span x-show="!testingWhmcs">Test WHMCS Credentials</span>
                                <span x-show="testingWhmcs">Testing...</span>
                            </button>
                        </div>
                        <input type="password" name="whmcs[whmcs_api_token]" x-show="credentialEditing.whmcs_api_token"
                            value="" autocomplete="new-password" placeholder="Enter a new WHMCS token">
                        <span class="kgr-admin-help">Click Replace token, enter new value, then click Save All Settings. If hidden, current token stays unchanged.</span>
                    </div>
                </div>
            </div>

            <!-- JIRA TAB -->
            <div x-show="tab === 'jira'" class="kgr-admin-grid" style="display: none;">
                <div>
                    <h3>Atlassian Configuration</h3>
                    <div class="kgr-admin-field">
                        <label>Routing Mode</label>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="kgr-btn" :class="routingMode === 'support_space' ? 'kgr-btn--primary' : ''" @click="routingMode = 'support_space'">Jira Support Space</button>
                            <button type="button" class="kgr-btn" :class="routingMode === 'client_mapped' ? 'kgr-btn--primary' : ''" @click="routingMode = 'client_mapped'">Client Mapped Spaces</button>
                        </div>
                        <input type="hidden" name="jira[jira_routing_mode]" :value="routingMode">
                        <p class="kgr-admin-help">
                            <strong>Jira Support Space:</strong> all support tickets are routed to one shared Jira support project.
                            This mode is best when your team triages everything in a central queue.
                            <br><br>
                            <strong>Client Mapped Spaces:</strong> each ticket is routed to that client's Jira project/space.
                            This requires an active WHMCS client-to-Jira mapping for each client before tickets can be created.
                        </p>
                    </div>
                    <div class="kgr-admin-field">
                        <label>Jira Base URL</label>
                        <input type="url" name="jira[jira_base_url]"
                            value="<?php echo esc_attr($settings['jira']['jira_base_url'] ?? ''); ?>"
                            placeholder="https://kanguru.atlassian.net">
                    </div>

                    <div x-show="routingMode === 'support_space'" class="kgr-admin-field">
                        <label>Support Space Label</label>
                        <input type="text" name="jira[jira_support_space_label]"
                            value="<?php echo esc_attr($support_space_label); ?>"
                            placeholder="Support & Service Requests">
                        <p class="kgr-admin-help">Default: <code>Support &amp; Service Requests</code></p>
                    </div>

                    <div x-show="routingMode === 'support_space'" class="kgr-admin-field">
                        <label>Support Space ID (Project Key)</label>
                        <input type="text" name="jira[jira_support_space_id]"
                            value="<?php echo esc_attr($support_space_id); ?>"
                            placeholder="KSR">
                        <p class="kgr-admin-help">Default: <code>KSR</code></p>
                    </div>

                    <input type="hidden" name="jira[jira_support_project_key]"
                        value="<?php echo esc_attr($support_project_key); ?>">

                    <div x-show="routingMode === 'support_space'" class="kgr-admin-field">
                        <label>Support Issue Type</label>
                        <input type="text" name="jira[jira_issue_type]"
                            value="<?php echo esc_attr($support_issue_type); ?>"
                            placeholder="support_ticket">
                        <p class="kgr-admin-help">Default: <code>support_ticket</code></p>
                    </div>

                    <div x-show="routingMode === 'support_space'" class="kgr-admin-field">
                        <label>Support Space Info</label>
                        <div class="kgr-admin-help">
                            <strong>Space:</strong> <span x-text="supportSpaceLabelPreview()"></span>
                            <br>
                            <strong>Space ID (Project Key):</strong> <span x-text="supportSpaceIdPreview()"></span> (default: KSR)
                            <br>
                            <strong>Issue Type:</strong> <span x-text="supportIssueTypePreview()"></span> (default: support_ticket)
                        </div>
                    </div>

                    <div x-show="routingMode === 'client_mapped'" class="kgr-admin-field">
                        <label>Fallback Project Key (Optional)</label>
                        <input type="text" name="jira[jira_project_key]"
                            value="<?php echo esc_attr($settings['jira']['jira_project_key'] ?? ''); ?>"
                            placeholder="KSR">
                        <p class="kgr-admin-help">Used only for backward compatibility; mapped mode expects explicit client mappings.</p>
                    </div>

                    <div class="kgr-admin-field">
                        <label>API User (Email)</label>
                        <input type="email" name="jira[jira_api_user]"
                            value="<?php echo esc_attr($settings['jira']['jira_api_user'] ?? ''); ?>">
                    </div>
                </div>
                <div>
                    <h3>Auth & Webhooks</h3>
                    <div class="kgr-admin-field">
                        <label>API Token</label>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <span x-text="credentialStatus('jira_api_token', <?php echo $has_jira_token ? 'true' : 'false'; ?>)"></span>
                            <button type="button" class="kgr-btn" @click="toggleCredentialEdit('jira_api_token')">
                                <span x-text="credentialEditing.jira_api_token ? 'Cancel' : 'Replace token'"></span>
                            </button>
                        </div>
                        <input type="password" name="jira[jira_api_token]" x-show="credentialEditing.jira_api_token"
                            value="" autocomplete="new-password" placeholder="Enter a new Jira API token">
                        <span class="kgr-admin-help">Click Replace token, enter new value, then click Save All Settings. If hidden, current token stays unchanged.</span>
                    </div>
                    <div class="kgr-admin-field">
                        <label>Webhook Secret</label>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <span x-text="credentialStatus('jira_webhook_secret', <?php echo $has_jira_webhook_secret ? 'true' : 'false'; ?>)"></span>
                            <button type="button" class="kgr-btn" @click="toggleCredentialEdit('jira_webhook_secret')">
                                <span x-text="credentialEditing.jira_webhook_secret ? 'Cancel' : 'Replace secret'"></span>
                            </button>
                            <button type="button" class="kgr-btn" :disabled="testingJira" @click="testJiraCredentials()">
                                <span x-show="!testingJira">Test Jira Credentials</span>
                                <span x-show="testingJira">Testing...</span>
                            </button>
                        </div>
                        <input type="password" name="jira[jira_webhook_secret]" x-show="credentialEditing.jira_webhook_secret"
                            value="" autocomplete="new-password" placeholder="Enter a new Jira webhook secret">
                        <span class="kgr-admin-help">Click Replace secret, enter new value, then click Save All Settings. If hidden, current secret stays unchanged.</span>
                    </div>
                </div>
            </div>

            <!-- CAPTCHA TAB -->
            <div x-show="tab === 'captcha'" class="kgr-admin-grid" style="display: none;">
                <div>
                    <h3>Global Security</h3>
                    <div class="kgr-admin-field">
                        <label>Active Captcha Provider</label>
                        <select name="captcha[captcha_provider]">
                            <option value="cloudflare" <?php selected($settings['captcha']['captcha_provider'] ?? 'cloudflare', 'cloudflare'); ?>>Cloudflare Turnstile (Recommended)</option>
                            <option value="google" <?php selected($settings['captcha']['captcha_provider'] ?? 'cloudflare', 'google'); ?>>Google reCAPTCHA v3</option>
                            <option value="none" <?php selected($settings['captcha']['captcha_provider'] ?? 'cloudflare', 'none'); ?>>None (Not Recommended)</option>
                        </select>
                        <p class="kgr-admin-help">Choose which provider to use for bot protection.</p>
                    </div>

                    <div class="kgr-admin-field">
                        <label>Enforce Preview Captcha</label>
                        <select name="captcha[captcha_enforce_preview]">
                            <option value="false" <?php selected($settings['captcha']['captcha_enforce_preview'] ?? 'false', 'false'); ?>>Only on Submit (Recommended)</option>
                            <option value="true" <?php selected($settings['captcha']['captcha_enforce_preview'] ?? 'false', 'true'); ?>>Every step</option>
                        </select>
                    </div>
                </div>
                <div>
                    <h3>Provider Details</h3>
                    <div>
                        <h4 style="margin-top:0">Cloudflare Turnstile</h4>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <button type="button" class="kgr-btn" :disabled="testingCloudflare" @click="testCloudflareCredentials()">
                                <span x-show="!testingCloudflare">Test Cloudflare Keys</span>
                                <span x-show="testingCloudflare">Testing...</span>
                            </button>
                        </div>
                        <div class="kgr-admin-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="kgr-admin-field">
                                <label>Site Key</label>
                                <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                                    <span x-text="credentialStatus('cloudflare_turnstile_site_key', <?php echo $has_cf_site_key ? 'true' : 'false'; ?>)"></span>
                                    <button type="button" class="kgr-btn" @click="toggleCredentialEdit('cloudflare_turnstile_site_key')">
                                        <span x-text="credentialEditing.cloudflare_turnstile_site_key ? 'Cancel' : 'Replace key'"></span>
                                    </button>
                                </div>
                                <input type="text" name="captcha[cloudflare_turnstile_site_key]" x-show="credentialEditing.cloudflare_turnstile_site_key" value="" placeholder="Enter a new Cloudflare site key">
                            </div>
                            <div class="kgr-admin-field">
                                <label>Secret Key</label>
                                <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                                    <span x-text="credentialStatus('cloudflare_turnstile_secret_key', <?php echo $has_cf_secret ? 'true' : 'false'; ?>)"></span>
                                    <button type="button" class="kgr-btn" @click="toggleCredentialEdit('cloudflare_turnstile_secret_key')">
                                        <span x-text="credentialEditing.cloudflare_turnstile_secret_key ? 'Cancel' : 'Replace secret'"></span>
                                    </button>
                                </div>
                                <input type="password" name="captcha[cloudflare_turnstile_secret_key]" x-show="credentialEditing.cloudflare_turnstile_secret_key" value="" autocomplete="new-password" placeholder="Enter a new Cloudflare secret key">
                            </div>
                        </div>

                        <h4 style="margin-top:1.5rem">Google reCAPTCHA v3</h4>
                        <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <button type="button" class="kgr-btn" :disabled="testingGoogle" @click="testGoogleCredentials()">
                                <span x-show="!testingGoogle">Test Google Keys</span>
                                <span x-show="testingGoogle">Testing...</span>
                            </button>
                        </div>
                        <div class="kgr-admin-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="kgr-admin-field">
                                <label>Site Key</label>
                                <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                                    <span x-text="credentialStatus('google_recaptcha_site_key', <?php echo $has_google_site_key ? 'true' : 'false'; ?>)"></span>
                                    <button type="button" class="kgr-btn" @click="toggleCredentialEdit('google_recaptcha_site_key')">
                                        <span x-text="credentialEditing.google_recaptcha_site_key ? 'Cancel' : 'Replace key'"></span>
                                    </button>
                                </div>
                                <input type="text" name="captcha[google_recaptcha_site_key]" x-show="credentialEditing.google_recaptcha_site_key" value="" placeholder="Enter a new Google site key">
                            </div>
                            <div class="kgr-admin-field">
                                <label>Secret Key</label>
                                <div class="kgr-admin-help" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                                    <span x-text="credentialStatus('google_recaptcha_secret_key', <?php echo $has_google_secret ? 'true' : 'false'; ?>)"></span>
                                    <button type="button" class="kgr-btn" @click="toggleCredentialEdit('google_recaptcha_secret_key')">
                                        <span x-text="credentialEditing.google_recaptcha_secret_key ? 'Cancel' : 'Replace secret'"></span>
                                    </button>
                                </div>
                                <input type="password" name="captcha[google_recaptcha_secret_key]" x-show="credentialEditing.google_recaptcha_secret_key" value="" autocomplete="new-password" placeholder="Enter a new Google secret key">
                            </div>
                        </div>
                        <div class="kgr-admin-field">
                            <label>Minimum Score (0.1 - 1.0)</label>
                            <input type="number" step="0.1" name="captcha[google_recaptcha_min_score]"
                                value="<?php echo esc_attr($settings['captcha']['google_recaptcha_min_score'] ?? '0.5'); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- MAPPINGS TAB -->
            <div x-show="tab === 'mappings'" class="kgr-admin-grid" style="display: none;">
                <div style="grid-column: 1 / -1;">
                    <div class="kgr-admin-field">
                        <label>Routing Mode</label>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="kgr-btn" :class="routingMode === 'support_space' ? 'kgr-btn--primary' : ''" @click="routingMode = 'support_space'">Jira Support Space</button>
                            <button type="button" class="kgr-btn" :class="routingMode === 'client_mapped' ? 'kgr-btn--primary' : ''" @click="routingMode = 'client_mapped'; maybeLoadMappings();">Client Mapped Spaces</button>
                        </div>
                        <input type="hidden" name="jira[jira_routing_mode]" :value="routingMode">
                        <p class="kgr-admin-help">
                            <strong>Jira Support Space:</strong> routes all support tickets to the shared support project.
                            <br>
                            <strong>Client Mapped Spaces:</strong> routes each ticket to a client-specific Jira space/project based on mapping.
                            If mappings are missing or incorrect, ticket creation will fail safely until corrected.
                        </p>
                    </div>
                </div>

                <div style="grid-column: 1 / -1;" x-show="routingMode === 'support_space'">
                    <div class="kgr-stat-card is-accent">
                        <div class="kgr-stat-label">Active Support Space</div>
                        <div class="kgr-stat-value" x-text="supportSpaceIdPreview()"></div>
                        <p class="kgr-admin-help">
                            <strong x-text="supportSpaceLabelPreview()"></strong>
                            <br>
                            Issue Type: <span x-text="supportIssueTypePreview()"></span>
                        </p>
                    </div>
                </div>

                <div style="grid-column: 1 / -1;" x-show="routingMode === 'client_mapped'">
                    <h3>Mapping Health</h3>
                    <p class="kgr-admin-help">Use this section to monitor routing readiness. Tickets route by WHMCS client ID. The Jira catalog is only a source list; it does not map clients automatically.</p>
                    <div class="kgr-admin-grid" style="grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 10px;">
                        <div class="kgr-stat-card">
                            <div class="kgr-stat-label">Active Client Mappings</div>
                            <div class="kgr-stat-value" x-text="mappingHealth.active_mappings || 0"></div>
                            <p class="kgr-admin-help">Clients currently mapped to a Jira Space.</p>
                        </div>
                        <div class="kgr-stat-card is-danger">
                            <div class="kgr-stat-label">Blocked Confirmed Tickets</div>
                            <div class="kgr-stat-value" x-text="mappingHealth.missing_mapping_requests || 0"></div>
                            <p class="kgr-admin-help">Confirmed tickets that cannot be sent to Jira because mapping is missing.</p>
                        </div>
                        <div class="kgr-stat-card is-warning">
                            <div class="kgr-stat-label">Unmapped Jira Spaces</div>
                            <div class="kgr-stat-value" x-text="mappingHealth.unmapped_spaces || 0"></div>
                            <p class="kgr-admin-help">Available Jira Spaces in catalog that are not linked to a WHMCS client.</p>
                        </div>
                        <div class="kgr-stat-card is-accent">
                            <div class="kgr-stat-label">Synced Jira Spaces</div>
                            <div class="kgr-stat-value" x-text="mappingHealth.catalog_count || 0"></div>
                            <p class="kgr-admin-help">Catalog rows currently available for mapping.</p>
                            <p class="kgr-admin-help" x-text="'Last sync: ' + (mappingHealth.catalog_last_synced_at || 'never')"></p>
                        </div>
                    </div>
                    <div style="display:flex; gap: 8px; margin-top: 10px;">
                        <button type="button" class="kgr-btn" :disabled="healthRefreshing" @click="loadMappingHealth(true)">
                            <span x-show="!healthRefreshing">Refresh Health</span>
                            <span x-show="healthRefreshing">Refreshing...</span>
                        </button>
                        <button type="button" class="kgr-btn" :disabled="spacesFetching" @click="fetchJiraSpacesNow()">
                            <span x-show="!spacesFetching">Fetch Spaces Now</span>
                            <span x-show="spacesFetching">Fetching...</span>
                        </button>
                        <button type="button" class="kgr-btn" @click="startCreateMapping()"><span class="dashicons dashicons-update" style="font-size:17px; margin-top: 3px; height:14px; margin-right:4px;"></span>Map Manually</button>
                    </div>
                    <p class="kgr-admin-help">Fetch Spaces Now updates the local list of active Jira Spaces from Jira API. It does not create client mappings automatically.</p>
                </div>

                <div x-show="routingMode === 'client_mapped'">
                    <h3>Unmapped Confirmed Requests</h3>
                    <p class="kgr-admin-help">These requests cannot create Jira tickets until mapping exists.</p>
                    <div style="max-height: 260px; overflow:auto; border:1px solid #e5e7eb; border-radius:8px;">
                        <table class="kgr-table" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    <th>WHMCS ID</th>
                                    <th>Website</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="r in missingRequests" :key="r.id">
                                    <tr>
                                        <td x-text="r.public_id"></td>
                                        <td x-text="r.client_whmcs_id || '-'"></td>
                                        <td x-text="r.website_domain || '-'"></td>
                                        <td><button type="button" class="kgr-btn" @click="startCreateFromMissing(r)">Map</button></td>
                                    </tr>
                                </template>
                                <tr x-show="!mappingLoading && missingRequests.length === 0">
                                    <td colspan="4" style="text-align:center;">No currently blocked confirmed requests.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="grid-column: 1 / -1;" x-show="routingMode === 'client_mapped'">
                    <h3>Mappings</h3>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <input type="text" x-model="mappingSearch" @input.debounce.300ms="loadMappings()" placeholder="Search by WHMCS ID, Jira key, company, website...">
                        <select x-model="mappingType" @change="mappingPage = 1; loadMappings()">
                            <option value="all">All Rows</option>
                            <option value="mapped">Mapped Clients</option>
                            <option value="catalog">Catalog (Unmapped Jira Spaces)</option>
                        </select>
                        <select x-model="mappingStatus" @change="mappingPage = 1; loadMappings()">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div style="max-height: 520px; overflow:auto; border:1px solid #e5e7eb; border-radius:8px;">
                        <table class="kgr-table" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>WHMCS ID</th>
                                    <th>Company</th>
                                    <th>Website</th>
                                    <th>Jira Project</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="m in mappingRows" :key="m.id">
                                    <tr>
                                        <td x-text="m.whmcs_client_id"></td>
                                        <td x-text="m.client_company_name || '-'"></td>
                                        <td x-text="m.website_url || '-'"></td>
                                        <td>
                                            <strong x-text="m.jira_project_key"></strong>
                                            <div class="kgr-admin-help" x-text="m.jira_project_name || ''"></div>
                                        </td>
                                        <td x-text="String(m.is_active) === '1' ? 'Active' : 'Inactive'"></td>
                                        <td x-text="m.updated_at"></td>
                                        <td><button type="button" class="kgr-btn" @click="editMapping(m)">Edit</button></td>
                                    </tr>
                                </template>
                                <tr x-show="!mappingLoading && mappingRows.length === 0">
                                    <td colspan="7" style="text-align:center;">No mappings found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
                        <button type="button" class="kgr-btn" :disabled="mappingPage <= 1" @click="mappingPage--; loadMappings()">Previous</button>
                        <span class="kgr-admin-help" x-text="'Page ' + mappingPage + ' / ' + mappingPages"></span>
                        <button type="button" class="kgr-btn" :disabled="mappingPage >= mappingPages" @click="mappingPage++; loadMappings()">Next</button>
                    </div>
                </div>
            </div>

            <div class="kgr-side-panel-overlay" x-cloak x-show="mappingDrawerOpen" x-transition.opacity.duration.200ms @click.self="closeMappingDrawer()">
                <aside class="kgr-side-panel"
                    x-transition:enter="transition ease-out duration-250"
                    x-transition:enter-start="transform translate-x-full"
                    x-transition:enter-end="transform translate-x-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="transform translate-x-0"
                    x-transition:leave-end="transform translate-x-full">
                    <div class="kgr-panel-header">
                        <h2>Manual Mapping</h2>
                        <button type="button" class="kgr-panel-close" @click="closeMappingDrawer()">&times;</button>
                    </div>
                    <div class="kgr-panel-body" style="padding:18px;">
                        <p class="kgr-admin-help">Please get the client info from WHMCS platform before saving the mapping.</p>
                        <div class="kgr-admin-field">
                            <label>WHMCS Client ID</label>
                            <input type="number" x-model="mappingForm.whmcs_client_id" placeholder="e.g., 12345">
                        </div>
                        <div class="kgr-admin-field">
                            <label>Client Company Name</label>
                            <input type="text" x-model="mappingForm.client_company_name" placeholder="Client company">
                        </div>
                        <div class="kgr-admin-field">
                            <label>Website URL / Domain</label>
                            <input type="text" x-model="mappingForm.website_url" placeholder="example.com">
                        </div>
                        <div class="kgr-admin-field" style="position:relative;">
                            <label>Jira Project Search</label>
                            <input type="text" x-model="jiraProjectQuery" @input="searchJiraProjects()" placeholder="Type project key or name...">
                            <div x-show="jiraProjectSearching" class="kgr-admin-help">Searching Jira projects...</div>
                            <div x-show="jiraProjectResults.length > 0" style="position:absolute; z-index:20; background:#fff; border:1px solid #ddd; border-radius:8px; width:100%; max-height:220px; overflow:auto;">
                                <template x-for="p in jiraProjectResults" :key="p.jira_project_id + '-' + p.jira_project_key">
                                    <button type="button" @click="selectJiraProject(p)" style="display:block; width:100%; text-align:left; padding:8px 10px; border:none; background:#fff; cursor:pointer;">
                                        <strong x-text="p.jira_project_key"></strong>
                                        <span x-text="' - ' + p.jira_project_name"></span>
                                        <span class="kgr-admin-help" x-show="p.jira_space_name" x-text="' (' + p.jira_space_name + ')'"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div class="kgr-admin-field">
                            <label>Selected Jira Project Key</label>
                            <input type="text" x-model="mappingForm.jira_project_key" placeholder="SUP" readonly>
                        </div>
                        <div class="kgr-admin-field">
                            <label>Selected Jira Project Name</label>
                            <input type="text" x-model="mappingForm.jira_project_name" readonly>
                        </div>
                        <div class="kgr-admin-field">
                            <label>Mapping Source</label>
                            <input type="text" x-model="mappingForm.mapping_source" placeholder="manual">
                        </div>
                        <div class="kgr-admin-field">
                            <label>Notes</label>
                            <textarea x-model="mappingForm.notes" placeholder="Optional notes"></textarea>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="kgr-btn kgr-btn--primary" :disabled="mappingSaving" @click="saveMapping()">
                                <span x-show="!mappingSaving">Save Mapping</span>
                                <span x-show="mappingSaving">Saving...</span>
                            </button>
                            <button type="button" class="kgr-btn" x-show="mappingForm.whmcs_client_id" @click="deactivateMapping()">Deactivate</button>
                            <button type="button" class="kgr-btn" @click="resetMappingForm()">Reset</button>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="kgr-admin-actions">
                <button type="submit" class="kgr-btn kgr-btn--primary" :disabled="saving">
                    <span x-show="!saving">Save All Settings</span>
                    <span x-show="saving">Saving...</span>
                </button>
            </div>
        </form>
    </div>

    <template x-if="toast">
        <div class="kgr-toast" x-transition>
            <span x-text="toast"></span>
        </div>
    </template>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('kgrSettings', () => ({
            tab: 'general',
            testingMode: '<?php echo esc_js($settings['general']['testing_mode'] ?? 'off'); ?>',
            routingMode: '<?php echo esc_js($routing_mode); ?>',
            saving: false,
            toast: '',
            generalErrors: {
                admin_emails: '',
                test_emails: ''
            },
            mappingLoaded: false,
            mappingLoading: false,
            mappingSaving: false,
            mappingDrawerOpen: false,
            healthRefreshing: false,
            spacesFetching: false,
            testingJira: false,
            testingWhmcs: false,
            testingCloudflare: false,
            testingGoogle: false,
            mappingHealth: {},
            mappingRows: [],
            missingRequests: [],
            mappingSearch: '',
            mappingStatus: 'all',
            mappingType: 'all',
            mappingPage: 1,
            mappingPages: 1,
            jiraProjectQuery: '',
            jiraProjectResults: [],
            jiraProjectSearching: false,
            jiraSearchTimer: null,
            credentialEditing: {
                whmcs_api_key: false,
                whmcs_api_token: false,
                jira_api_token: false,
                jira_webhook_secret: false,
                cloudflare_turnstile_site_key: false,
                cloudflare_turnstile_secret_key: false,
                google_recaptcha_site_key: false,
                google_recaptcha_secret_key: false
            },
            mappingForm: {
                whmcs_client_id: '',
                jira_project_id: '',
                jira_project_key: '',
                jira_project_name: '',
                jira_board_id: '',
                jira_space_name: '',
                website_url: '',
                client_company_name: '',
                mapping_source: 'manual',
                notes: '',
                is_active: 1
            },
            adminNonce: '<?php echo wp_create_nonce('kgr_admin_nonce'); ?>',
            init() {
                this.$watch('tab', (value) => {
                    if (value === 'mappings') {
                        this.maybeLoadMappings();
                    }
                });
                this.$watch('routingMode', (value) => {
                    if (value === 'client_mapped') {
                        this.maybeLoadMappings();
                    }
                });
            },
            supportProjectKeyPreview() {
                const field = document.querySelector('[name="jira[jira_support_project_key]"]');
                const val = field ? field.value.trim() : '';
                return val || 'KSR';
            },
            supportSpaceLabelPreview() {
                const field = document.querySelector('[name="jira[jira_support_space_label]"]');
                const val = field ? field.value.trim() : '';
                return val || 'Support & Service Requests';
            },
            supportSpaceIdPreview() {
                const idField = document.querySelector('[name="jira[jira_support_space_id]"]');
                const idVal = idField ? idField.value.trim() : '';
                if (idVal) {
                    return idVal;
                }
                const projectField = document.querySelector('[name="jira[jira_support_project_key]"]');
                const projectVal = projectField ? projectField.value.trim() : '';
                return projectVal || 'KSR';
            },
            supportIssueTypePreview() {
                const field = document.querySelector('[name="jira[jira_issue_type]"]');
                const val = field ? field.value.trim() : '';
                return val || 'support_ticket';
            },
            credentialStatus(field, hasValue) {
                if (this.credentialEditing[field]) {
                    return 'Editing new value (not saved yet)';
                }
                return hasValue ? 'Saved value is active' : 'No value saved';
            },
            toggleCredentialEdit(field) {
                this.credentialEditing[field] = !this.credentialEditing[field];
                if (!this.credentialEditing[field]) {
                    const input = document.querySelector(`[name="${this.resolveCredentialFieldName(field)}"]`);
                    if (input) {
                        input.value = '';
                    }
                }
            },
            resolveCredentialFieldName(field) {
                const map = {
                    whmcs_api_key: 'whmcs[whmcs_api_key]',
                    whmcs_api_token: 'whmcs[whmcs_api_token]',
                    jira_api_token: 'jira[jira_api_token]',
                    jira_webhook_secret: 'jira[jira_webhook_secret]',
                    cloudflare_turnstile_site_key: 'captcha[cloudflare_turnstile_site_key]',
                    cloudflare_turnstile_secret_key: 'captcha[cloudflare_turnstile_secret_key]',
                    google_recaptcha_site_key: 'captcha[google_recaptcha_site_key]',
                    google_recaptcha_secret_key: 'captcha[google_recaptcha_secret_key]'
                };
                return map[field] || '';
            },
            testJiraCredentials() {
                this.testingJira = true;
                const data = new URLSearchParams({ action: 'kgr_test_jira_credentials', nonce: this.adminNonce });
                fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data })
                    .then(res => res.json())
                    .then(res => {
                        this.toast = (res.data && res.data.message) ? res.data.message : (res.success ? 'Jira credentials are valid.' : 'Jira test failed.');
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => { this.toast = 'Jira test failed.'; setTimeout(() => this.toast = '', 7000); })
                    .finally(() => this.testingJira = false);
            },
            testWhmcsCredentials() {
                this.testingWhmcs = true;
                const data = new URLSearchParams({ action: 'kgr_test_whmcs_credentials', nonce: this.adminNonce });
                fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data })
                    .then(res => res.json())
                    .then(res => {
                        this.toast = (res.data && res.data.message) ? res.data.message : (res.success ? 'WHMCS credentials are valid.' : 'WHMCS test failed.');
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => { this.toast = 'WHMCS test failed.'; setTimeout(() => this.toast = '', 7000); })
                    .finally(() => this.testingWhmcs = false);
            },
            testCloudflareCredentials() {
                this.testingCloudflare = true;
                const data = new URLSearchParams({ action: 'kgr_test_cloudflare_credentials', nonce: this.adminNonce });
                fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data })
                    .then(res => res.json())
                    .then(res => {
                        this.toast = (res.data && res.data.message) ? res.data.message : (res.success ? 'Cloudflare keys are valid.' : 'Cloudflare key test failed.');
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => { this.toast = 'Cloudflare key test failed.'; setTimeout(() => this.toast = '', 7000); })
                    .finally(() => this.testingCloudflare = false);
            },
            testGoogleCredentials() {
                this.testingGoogle = true;
                const data = new URLSearchParams({ action: 'kgr_test_google_credentials', nonce: this.adminNonce });
                fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data })
                    .then(res => res.json())
                    .then(res => {
                        this.toast = (res.data && res.data.message) ? res.data.message : (res.success ? 'Google keys are valid.' : 'Google key test failed.');
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => { this.toast = 'Google key test failed.'; setTimeout(() => this.toast = '', 7000); })
                    .finally(() => this.testingGoogle = false);
            },
            saveSettings(e) {
                this.saving = true;
                const formData = new FormData(e.target);
                
                this.generalErrors.admin_emails = '';
                this.generalErrors.test_emails = '';
                let hasError = false;

                const validateEmails = (str) => {
                    const emails = str.split(',').map(s => s.trim()).filter(s => s);
                    if (emails.length === 0) return false;
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    for (let e of emails) {
                        if (!emailRegex.test(e)) return false;
                    }
                    return true;
                };

                const adminEmails = formData.get('general[admin_emails]');
                if (!adminEmails || !validateEmails(adminEmails)) {
                    this.generalErrors.admin_emails = 'Please enter a valid email address (or comma-separated addresses).';
                    hasError = true;
                }

                if (this.testingMode === 'on') {
                    const testEmails = formData.get('general[test_emails]');
                    if (!testEmails || !validateEmails(testEmails)) {
                        this.generalErrors.test_emails = 'Please enter a valid test email address (or comma-separated addresses).';
                        hasError = true;
                    }
                }

                if (hasError) {
                    this.saving = false;
                    this.toast = 'Please fix the errors in the General tab.';
                    this.tab = 'general';
                    return;
                }

                const data = new URLSearchParams();

                // Normalize posted fields into settings[tab][field] so backend receives a stable structure.
                for (const [key, value] of formData.entries()) {
                    const match = key.match(/^([a-zA-Z0-9_]+)\[([a-zA-Z0-9_]+)\]$/);
                    if (match) {
                        const tab = match[1];
                        const field = match[2];
                        data.append(`settings[${tab}][${field}]`, value);
                    } else {
                        data.append(key, value);
                    }
                }

                // Add action and nonce
                data.append('action', 'kgr_save_settings');
                data.append('nonce', '<?php echo wp_create_nonce('kgr_admin_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data
                })
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Settings saved';
                        } else {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Failed to save settings.';
                        }
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => {
                        this.toast = 'Failed to save settings.';
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .finally(() => this.saving = false);
            },
            maybeLoadMappings() {
                if (this.mappingLoaded) {
                    return;
                }
                this.mappingLoaded = true;
                this.loadMappingHealth(false);
                this.loadMappings();
            },
            loadMappingHealth(showToast = true) {
                this.healthRefreshing = true;
                const params = new URLSearchParams({
                    action: 'kgr_get_mapping_health',
                    nonce: this.adminNonce
                });
                fetch(ajaxurl + '?' + params.toString())
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.mappingHealth = res.data || {};
                            if (showToast) {
                                this.toast = 'Mapping health refreshed.';
                                setTimeout(() => this.toast = '', 7000);
                            }
                        } else if (showToast) {
                            this.toast = 'Failed to refresh mapping health.';
                            setTimeout(() => this.toast = '', 7000);
                        }
                    })
                    .catch(() => {
                        if (showToast) {
                            this.toast = 'Failed to refresh mapping health.';
                            setTimeout(() => this.toast = '', 7000);
                        }
                    })
                    .finally(() => this.healthRefreshing = false);
            },
            loadMappings() {
                this.mappingLoading = true;
                const params = new URLSearchParams({
                    action: 'kgr_get_client_mappings',
                    nonce: this.adminNonce,
                    paged: this.mappingPage,
                    s: this.mappingSearch,
                    status: this.mappingStatus,
                    mapping_type: this.mappingType
                });
                fetch(ajaxurl + '?' + params.toString())
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.mappingRows = res.data.rows || [];
                            this.missingRequests = res.data.missing_requests || [];
                            const p = res.data.pagination || {};
                            this.mappingPages = p.pages || 1;
                            this.mappingPage = p.current || 1;
                        }
                    })
                    .finally(() => this.mappingLoading = false);
            },
            startCreateMapping() {
                this.routingMode = 'client_mapped';
                this.maybeLoadMappings();
                this.resetMappingForm();
                this.mappingDrawerOpen = true;
                this.toast = 'Manual mapping form is ready.';
                setTimeout(() => this.toast = '', 7000);
                setTimeout(() => {
                    const input = document.querySelector('.kgr-side-panel input[type="number"]');
                    if (input) {
                        input.focus();
                    }
                }, 120);
            },
            startCreateFromMissing(row) {
                this.resetMappingForm();
                this.mappingForm.whmcs_client_id = row.client_whmcs_id || '';
                this.mappingForm.website_url = row.website_domain || '';
                this.mappingDrawerOpen = true;
            },
            editMapping(row) {
                this.mappingForm = {
                    whmcs_client_id: row.whmcs_client_id || '',
                    jira_project_id: row.jira_project_id || '',
                    jira_project_key: row.jira_project_key || '',
                    jira_project_name: row.jira_project_name || '',
                    jira_board_id: row.jira_board_id || '',
                    jira_space_name: row.jira_space_name || '',
                    website_url: row.website_url || '',
                    client_company_name: row.client_company_name || '',
                    mapping_source: row.mapping_source || 'manual',
                    notes: row.notes || '',
                    is_active: Number(row.is_active || 0)
                };
                this.jiraProjectQuery = row.jira_project_key || '';
                this.mappingDrawerOpen = true;
            },
            closeMappingDrawer() {
                this.mappingDrawerOpen = false;
            },
            resetMappingForm() {
                this.mappingForm = {
                    whmcs_client_id: '',
                    jira_project_id: '',
                    jira_project_key: '',
                    jira_project_name: '',
                    jira_board_id: '',
                    jira_space_name: '',
                    website_url: '',
                    client_company_name: '',
                    mapping_source: 'manual',
                    notes: '',
                    is_active: 1
                };
                this.jiraProjectQuery = '';
                this.jiraProjectResults = [];
            },
            searchJiraProjects() {
                if (this.jiraSearchTimer) {
                    clearTimeout(this.jiraSearchTimer);
                }
                const q = (this.jiraProjectQuery || '').trim();
                if (q.length < 1) {
                    this.jiraProjectResults = [];
                    return;
                }
                this.jiraSearchTimer = setTimeout(() => {
                    this.jiraProjectSearching = true;
                    const params = new URLSearchParams({
                        action: 'kgr_search_jira_projects',
                        nonce: this.adminNonce,
                        q: q
                    });
                    fetch(ajaxurl + '?' + params.toString())
                        .then(res => res.json())
                        .then(res => {
                            if (res.success) {
                                this.jiraProjectResults = res.data.rows || [];
                            } else {
                                this.jiraProjectResults = [];
                            }
                        })
                        .finally(() => this.jiraProjectSearching = false);
                }, 300);
            },
            selectJiraProject(p) {
                this.mappingForm.jira_project_id = p.jira_project_id || '';
                this.mappingForm.jira_project_key = p.jira_project_key || '';
                this.mappingForm.jira_project_name = p.jira_project_name || '';
                this.mappingForm.jira_space_name = p.jira_space_name || '';
                this.mappingForm.jira_board_id = p.jira_board_id || '';
                this.jiraProjectQuery = (p.jira_project_key || '') + ' - ' + (p.jira_project_name || '');
                this.jiraProjectResults = [];
            },
            saveMapping() {
                this.mappingSaving = true;
                const data = new URLSearchParams({
                    action: 'kgr_save_client_mapping',
                    nonce: this.adminNonce,
                    whmcs_client_id: String(this.mappingForm.whmcs_client_id || ''),
                    jira_project_id: String(this.mappingForm.jira_project_id || ''),
                    jira_project_key: String(this.mappingForm.jira_project_key || ''),
                    jira_project_name: String(this.mappingForm.jira_project_name || ''),
                    jira_board_id: String(this.mappingForm.jira_board_id || ''),
                    jira_space_name: String(this.mappingForm.jira_space_name || ''),
                    website_url: String(this.mappingForm.website_url || ''),
                    client_company_name: String(this.mappingForm.client_company_name || ''),
                    mapping_source: String(this.mappingForm.mapping_source || 'manual'),
                    notes: String(this.mappingForm.notes || ''),
                    is_active: String(this.mappingForm.is_active ? 1 : 0)
                });
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data
                })
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Mapping saved.';
                            this.loadMappingHealth(false);
                            this.loadMappings();
                            this.mappingDrawerOpen = false;
                        } else {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Failed to save mapping.';
                        }
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => {
                        this.toast = 'Failed to save mapping.';
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .finally(() => this.mappingSaving = false);
            },
            deactivateMapping() {
                if (!this.mappingForm.whmcs_client_id) {
                    return;
                }
                const data = new URLSearchParams({
                    action: 'kgr_deactivate_client_mapping',
                    nonce: this.adminNonce,
                    whmcs_client_id: String(this.mappingForm.whmcs_client_id),
                    notes: String(this.mappingForm.notes || 'Deactivated from admin settings')
                });
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data
                })
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Mapping deactivated.';
                            this.loadMappingHealth(false);
                            this.loadMappings();
                            this.mappingDrawerOpen = false;
                        } else {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Failed to deactivate mapping.';
                        }
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => {
                        this.toast = 'Failed to deactivate mapping.';
                        setTimeout(() => this.toast = '', 7000);
                    });
            },
            fetchJiraSpacesNow() {
                this.spacesFetching = true;
                const data = new URLSearchParams({
                    action: 'kgr_fetch_jira_spaces_now',
                    nonce: this.adminNonce
                });
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data
                })
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Jira spaces fetched successfully.';
                            this.loadMappingHealth(false);
                        } else {
                            this.toast = (res.data && res.data.message) ? res.data.message : 'Failed to fetch Jira spaces.';
                        }
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .catch(() => {
                        this.toast = 'Failed to fetch Jira spaces.';
                        setTimeout(() => this.toast = '', 7000);
                    })
                    .finally(() => this.spacesFetching = false);
            }
        }));
    });
</script>
