# Support System (PHP 8 + SQLite + WHMCS + Jira)

## Overview

This project now uses a **fully asynchronous attachment pipeline**:
- HTTP submit request does only: validate, store temp files, create DB records, queue jobs, return response.
- Heavy tasks (image optimization, storage move, Jira attachment upload, cleanup) are handled by cron workers.

This keeps request latency low and prevents timeout-heavy inline file processing.

## Installation Commands

```bash
cd /home/customer/www/support-system
cp .env.example .env
php scripts/install.php
chmod -R 775 database logs storage uploads
```

Windows PowerShell equivalent:

```powershell
Copy-Item .env.example .env
php scripts/install.php
```

## Required `.env` values

```env
APP_TIMEZONE=America/Toronto
DB_PATH=database/support.sqlite
STORAGE_PATH=storage
ATTACHMENT_RETENTION_MONTHS=3
```

Also configure WHMCS API lookup credentials, Jira, SMTP, CAPTCHA, admin credentials.

WHMCS lookup now uses signed API calls (no direct WHMCS DB connection):

```env
WHMCS_API_BASE_URL=https://kgr360.com
WHMCS_API_KEY=site-a
WHMCS_API_TOKEN=your-shared-secret
```

Recommended Jira webhook status notification config:

```env
JIRA_NOTIFY_STATUSES=Done,Client Review
ADMIN_EMAILS=admin@example.com,ops@example.com
```

## Async Attachment Pipeline

### Stage 1: temporary local storage
- Path pattern: `storage/temp/YYYY/MM/`
- Files are validated (extension + server-side MIME + size), hashed (SHA256), randomized name, stored as temp.
- Attachment DB row is created with status `uploaded_temp`.

### Stage 2: permanent request storage
- Optimized/validated files move to:
  - `storage/requests/YYYY/MM/request_{id}/`
- Attachment status becomes `stored_local`.

### Jira upload (async)
- Jira issue is created first.
- Each file is attached through separate queue jobs (`attach_file_to_jira`).
- On success, status becomes `attached_to_jira`.
- Jira keeps its own copy; local deletion later does **not** affect Jira files.

## Attachment categories and limits

### Website screenshots
- Allowed: `jpg`, `jpeg`, `png`, `webp`
- Max: 2 per issue
- Max size: 1MB each before optimization

### Non-website attachments
- Allowed: `jpg`, `jpeg`, `png`, `webp`, `pdf`, `doc`, `docx`, `xls`, `xlsx`, `zip`
- Max total per request: 10MB

## Image optimization

Implemented as pluggable service:
- Primary: `Imagick` (auto-orient + strip metadata + compress to WebP)
- Fallback: GD-based optimization when available
- Logs include original size, optimized size, compression ratio

File: `src/services/ImageOptimizationService.php`

## Database schema updates

Attachment table includes:
- `original_name`, `stored_name`, `mime_type`, `extension`, `category`
- `temp_path`, `file_path`
- `file_size_original`, `file_size_optimized`
- `optimization_status`, `jira_attachment_status`
- `sha256_hash`, `retention_delete_at`

Queue table supports:
- `status`, `attempts`, `last_error`, `next_run_at`
- `locked_at`, `lock_token`, `processed_at`

See: `src/database/schema.sql`

## Queue job types

- `optimize_attachment`
- `move_attachment_to_local_storage`
- `attach_file_to_jira`
- `cleanup_temp_file`
- `cleanup_expired_local_file`
- `send_admin_request_summary_email`
- `send_ticket_status_email`
- plus existing email/ticket jobs

All jobs use retries with exponential backoff and lock-safe claiming.

## Cron workers

Run these in SiteGround cron:

```cron
* * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processAttachmentOptimization.php
* * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processLocalFileMoves.php
* * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processJiraAttachments.php
* * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processAdminNotifications.php
0 3 * * * /usr/local/bin/php /home/customer/www/support-system/worker/processAttachmentCleanup.php

*/5 * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processQueue.php
*/5 * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processEmails.php
*/5 * * * * /usr/local/bin/php /home/customer/www/support-system/worker/processJiraTickets.php
30 3 * * * /usr/local/bin/php /home/customer/www/support-system/worker/processCleanup.php
```

Cron purpose summary:
- `processAttachmentOptimization.php`: optimizes staged screenshots in small batches.
- `processLocalFileMoves.php`: moves optimized/staged files from temp to permanent request storage and cleans temp files.
- `processJiraAttachments.php`: uploads local files to Jira attachments endpoint after ticket exists.
- `processAdminNotifications.php`: sends admin summary emails (with links, no binary attachments) after submit.
- `processAttachmentCleanup.php`: queues + executes deletion for files past retention period.
- `processQueue.php`: general queue processing (confirmation emails, reminders, Jira ticket creation, notifications).
- `processEmails.php`: email-specific queue jobs.
- `send_ticket_status_email` is triggered by Jira webhook when status is in `JIRA_NOTIFY_STATUSES`.
- `processJiraTickets.php`: Jira ticket creation queue jobs.
- `processCleanup.php`: old request cleanup (done/canceled historical records and leftover files).

## Retention and cleanup policy

- Temp files are removed after successful move (`cleanup_temp_file`).
- Permanent local files are retained for 3 months (`retention_delete_at`).
- Cleanup worker queues `cleanup_expired_local_file` and deletes local files safely.
- Deletion does not run for files needed in active retries.

## Security controls

- Server-side MIME validation + extension validation
- Randomized storage names
- SHA256 per file (dedupe support)
- Upload size and count caps
- No executable upload types
- Path traversal-safe path handling
- Rate limiting + CAPTCHA + honeypot still active

## Email behavior

- No binary attachments are sent via email.
- Emails include summary + Jira ticket ID (when available).
- Mentions that files were uploaded to Jira.
- Admin summary emails are sent through queue job `send_admin_request_summary_email` to `ADMIN_EMAILS` (comma-separated).
- Client status emails are sent via webhook-triggered queue job when Jira status matches `JIRA_NOTIFY_STATUSES`.

## SiteGround deployment notes

1. Upload project to hosting account.
2. Ensure writable directories:
   - `database/`
   - `logs/`
   - `storage/`
3. Run install command.
4. Set cron jobs exactly as above.
5. Configure Jira webhook:
   - `https://your-domain.com/public/webhook-jira.php`
   - Header: `X-Webhook-Secret: <JIRA_WEBHOOK_SECRET>`
   - Ensure Jira sends issue status-change events (including `Done`, `Client Review`).

## Frontend shortcode plugin

The WordPress shortcode frontend is in:
- `support-request-frontend/`

Use shortcode:

```txt
[support_request_form]
```

## Local frontend browser test page (no WordPress)

To preview and test the same frontend UI without WordPress:
- Open: `http://localhost/support-system/public/test-frontend.php`

It uses:
- Frontend CSS/JS from `support-request-frontend/assets/...`
- Backend adapters:
  - `public/validate-website.php`
  - `public/submit-request.php`

These endpoints are for local testing convenience and call the same backend services.
