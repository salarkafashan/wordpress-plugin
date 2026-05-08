<?php
/**
 * Admin Usage Guide Template
 * 
 * Internal documentation for the Kanguru Team.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="kgr-admin-wrap" x-data="kgrGuide">
    <div class="kgr-admin-header">
        <h1>
            <img src="<?php echo KGR_PLUGIN_URL . 'assets/img/kanguru-menu-icon.svg'; ?>" class="kgr-header-logo"
                alt="Kanguru Icon">
            Kanguru <span>Support Guide</span>
        </h1>
        <div class="kgr-admin-badge">Team Documentation</div>
    </div>

    <!-- Shortcode Section -->
    <div class="kgr-admin-card" style="margin-bottom: 2rem; border-radius: 8px;">
        <h3 style="margin-top:0;"><i class="dashicons dashicons-shortcode" style="color:var(--kgr-admin-accent);"></i>
            Deployment</h3>
        <p class="kgr-admin-help">To deploy the Kanguru Support form on any internal portal or client-facing page, use
            the shortcode below.</p>

        <div class="kgr-shortcode-box" @click="copyShortcode" :class="{'is-copied': copied}">
            <code>[support_request_form]</code>
            <div class="kgr-copy-hint">
                <span x-show="!copied"><i class="dashicons dashicons-admin-page"></i> Click to Copy</span>
                <span x-show="copied" style="color:var(--kgr-admin-accent);"><i class="dashicons dashicons-yes"></i>
                    Copied!</span>
            </div>
        </div>
    </div>

    <!-- Main Explanation Section -->
    <div class="kgr-admin-grid">
        <div class="kgr-admin-card" style="border-radius: 8px;">
            <h3><i class="dashicons dashicons-admin-plugins"></i> The Kanguru Ecosystem</h3>
            <p>This plugin is a core component of the Kanguru internal workflow. It was developed specifically to bridge
                our client billing data with our technical execution pipeline, ensuring that every minute of our team's
                work is billable and verified.</p>

            <div class="kgr-guide-feature">
                <div class="kgr-guide-icon"><i class="dashicons dashicons-cloud"></i></div>
                <div class="kgr-guide-text">
                    <strong>WHMCS Verification</strong>
                    <p>The system connects to Kanguru's WHMCS API to validate that the request is coming from an active
                        client with a valid service plan assigned to their account.</p>
                </div>
            </div>

            <div class="kgr-guide-feature">
                <div class="kgr-guide-icon"><i class="dashicons dashicons-external"></i></div>
                <div class="kgr-guide-text">
                    <strong>Direct Jira Injection</strong>
                    <p>After the client owner confirms the request, the system creates a Jira ticket and uploads attachments. Admin is notified with the Jira link on success, or a failure warning if ticket creation fails</p>
                </div>
            </div>
        </div>

        <div class="kgr-admin-card" style="border-radius: 8px;">
            <h3><i class="dashicons dashicons-email-alt"></i> Notifications</h3>
            <p>The plugin currently sends these automated emails:</p>

            <ul class="kgr-guide-list">
                <li>
                    <strong>Confirmation Email (Client Owner):</strong> Sent after submission. The request stays
                    pending until the owner confirms using the email link.
                </li>
                <li>
                    <strong>Confirmation Reminder (Client Owner):</strong> Sent about 6 hours later if the request is
                    still not confirmed.
                </li>
                <li>
                    <strong>Ticket Created (Requester):</strong> Sent after owner confirmation and successful Jira
                    ticket creation.
                </li>
                <li>
                    <strong>Status Update (Requester):</strong> Sent when Jira webhook status updates are configured
                    and a watched Jira status change occurs.
                </li>
                <li>
                    <strong>Ticket Completed (Requester):</strong> Sent when the request is marked done.
                </li>
                <li>
                    <strong>Admin Summary (Admin Emails):</strong> Sent after Jira ticket creation, including request
                    details and Jira link when available.
                </li>
                <li>
                    <strong>Admin Failure Warning (Admin Emails):</strong> Sent if Jira ticket creation fails after
                    queue retries.
                </li>
                <li>
                    <strong>Generic Queue Failure (Fallback Admin Email):</strong> Sent for permanent queue failures
                    outside the Jira-creation-specific warning flow.
                </li>
            </ul>
        </div>
    </div>

    <!-- Security Section -->
    <div class="kgr-admin-card" style="margin-top: 2rem; border-radius: 8px;">
        <h3><i class="dashicons dashicons-shield"></i> Kanguru Security Standards</h3>
        <p>This plugin adheres to Kanguru's strict security protocols. Every external request is protected by our global
            Captcha provider (Cloudflare or Google), and sensitive client data is encrypted using AES-256 before being
            stored in our local database tables.</p>
    </div>
</div>

<style>
    .kgr-shortcode-box {
        background: var(--kgr-admin-primary);
        color: white;
        padding: 1.5rem 2rem;
        border-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid transparent;
        margin-top: 1rem;
        position: relative;
        user-select: none;
    }

    .kgr-shortcode-box:hover {
        background: #000033;
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
    }

    .kgr-shortcode-box.is-copied {
        border-color: var(--kgr-admin-accent);
    }

    .kgr-shortcode-box code {
        font-size: 1.25rem;
        background: transparent;
        color: var(--kgr-admin-accent);
    }

    .kgr-copy-hint {
        font-weight: 600;
        font-size: 0.9rem;
        opacity: 0.8;
    }

    .kgr-guide-feature {
        display: flex;
        gap: 1.5rem;
        margin-top: 1.5rem;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 8px;
    }

    .kgr-guide-icon {
        font-size: 1.5rem;
        color: var(--kgr-admin-primary);
    }

    .kgr-guide-text strong {
        display: block;
        margin-bottom: 0.25rem;
    }

    .kgr-guide-text p {
        margin: 0;
        font-size: 0.9rem;
        color: var(--kgr-admin-text-muted);
    }

    .kgr-guide-list {
        list-style: none;
        padding: 0;
    }

    .kgr-guide-list li {
        padding-left: 1.5rem;
        position: relative;
        margin-bottom: 1rem;
        font-size: 0.95rem;
    }

    .kgr-guide-list li::before {
        content: "→";
        position: absolute;
        left: 0;
        color: var(--kgr-admin-accent);
        font-weight: bold;
    }
</style>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('kgrGuide', () => ({
            copied: false,
            shortcode: '[support_request_form]',

            copyShortcode() {
                // Updated copy logic for better reliability
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(this.shortcode).then(() => {
                        this.triggerFeedback();
                    });
                } else {
                    // Fallback for non-https/unsupported browsers
                    const textArea = document.createElement("textarea");
                    textArea.value = this.shortcode;
                    document.body.appendChild(textArea);
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        this.triggerFeedback();
                    } catch (err) { }
                    document.body.removeChild(textArea);
                }
            },

            triggerFeedback() {
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            }
        }));
    });
</script>
