<?php

declare(strict_types=1);

namespace App\services;

use App\helpers\Logger;
use App\models\IssueAttachmentModel;
use App\models\QueueJobModel;
use App\models\SupportIssueModel;
use App\models\SupportRequestModel;
use App\config\Config;
use RuntimeException;
use Throwable;

final class QueueService
{
    private QueueJobModel $queueModel;
    private SupportRequestModel $requestModel;
    private SupportIssueModel $issueModel;
    private IssueAttachmentModel $attachmentModel;
    private EmailService $emailService;
    private JiraService $jiraService;
    private JiraAttachmentService $jiraAttachmentService;
    private ImageOptimizationService $optimizer;
    private LocalStorageService $storageService;

    public function __construct()
    {
        $this->queueModel = new QueueJobModel();
        $this->requestModel = new SupportRequestModel();
        $this->issueModel = new SupportIssueModel();
        $this->attachmentModel = new IssueAttachmentModel();
        $this->emailService = new EmailService();
        $this->jiraService = new JiraService();
        $this->jiraAttachmentService = new JiraAttachmentService();
        $this->optimizer = new ImageOptimizationService();
        $this->storageService = new LocalStorageService();
    }

    public function enqueue(string $jobType, int $requestId, array $payload = [], int $delaySeconds = 0, int $maxAttempts = 5): int
    {
        if ($this->shouldDispatchEmailImmediately($jobType)) {
            try {
                $this->executeJob([
                    'job_type' => $jobType,
                    'request_id' => $requestId,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ]);
                return 0;
            } catch (Throwable $exception) {
                Logger::error('Immediate email dispatch failed', [
                    'job_type' => $jobType,
                    'request_id' => $requestId,
                    'error' => $exception->getMessage(),
                ]);
                throw new RuntimeException('Immediate email dispatch failed: ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $this->queueModel->enqueue($jobType, $requestId, $payload, $delaySeconds, $maxAttempts);
    }

    public function process(array $jobTypes = [], int $limit = 25, ?array &$stats = null): void
    {
        $localStats = [
            'claimed' => 0,
            'completed' => 0,
            'retry' => 0,
            'failed' => 0,
            'errors' => [],
            'passes' => 0,
        ];

        // Process chained jobs in the same cron run (e.g. create_jira_ticket -> attach_file_to_jira)
        // so low-traffic sites do not wait for the next visit/cron tick.
        $remaining = max(1, $limit);
        $pass = 0;
        while ($remaining > 0 && $pass < 8) {
            $pass++;
            $localStats['passes'] = $pass;
            $jobs = $this->queueModel->claimJobs($jobTypes, $remaining);
            if ($jobs === []) {
                break;
            }

            $localStats['claimed'] += count($jobs);

            foreach ($jobs as $job) {
                $jobId = (int) $job['id'];
                $lockToken = (string) ($job['lock_token'] ?? '');
                try {
                    $this->executeJob($job);
                    $this->queueModel->markCompleted($jobId, $lockToken);
                    $localStats['completed']++;
                } catch (Throwable $exception) {
                    $attempts = (int) $job['attempts'] + 1;
                    $status = $this->queueModel->markRetryOrFailed(
                        $jobId,
                        $lockToken,
                        $attempts,
                        (int) $job['max_attempts'],
                        $exception->getMessage()
                    );
                    Logger::error('Queue job failed', ['job_id' => $jobId, 'job_type' => $job['job_type'], 'status' => $status, 'error' => $exception->getMessage()]);
                    $this->markAttachmentFailureIfNeeded((string) $job['job_type'], (string) $job['payload_json'], $status);
                    $localStats['errors'][] = [
                        'job_id' => $jobId,
                        'job_type' => (string) ($job['job_type'] ?? ''),
                        'status' => $status,
                        'error' => $exception->getMessage(),
                    ];
                    if ($status === 'failed') {
                        $localStats['failed']++;
                    } else {
                        $localStats['retry']++;
                    }
                    if ($status === 'failed') {
                        $this->notifyAdminFailure($job, $exception->getMessage());
                    }
                }
                $remaining--;
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        if ($stats !== null) {
            $stats = $localStats;
        }
    }

    private function executeJob(array $job): void
    {
        $type = (string) $job['job_type'];
        $requestId = (int) $job['request_id'];
        $payload = json_decode((string) $job['payload_json'], true) ?: [];
        $request = $this->findRequestOrFail($requestId);

        if ($type === 'send_confirmation_email') {
            $issues = $this->issueModel->findByRequestId($requestId);
            $confirmUrl = (string) ($payload['confirm_url'] ?? '');
            $submittedEmail = strtolower((string) ($request['submitted_email'] ?? ''));
            $verifiedEmail = strtolower((string) ($request['verified_email'] ?? ''));
            $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $authorizedEmails = array_values(array_unique(array_filter(array_map(
                static fn($v): string => strtolower(trim((string) $v)),
                (array) ($metadata['whmcsAuthorizedEmails'] ?? [])
            ))));
            if ($authorizedEmails === [] && $verifiedEmail !== '') {
                $authorizedEmails[] = $verifiedEmail;
            }
            $isEmailMatch = $submittedEmail !== '' && in_array($submittedEmail, $authorizedEmails, true);

            if ($isEmailMatch) {
                $frContent = $this->buildSimpleConfirmationContentFr($request, $issues, $confirmUrl);
                $enContent = $this->buildSimpleConfirmationContent($request, $issues, $confirmUrl);
            } else {
                $frContent = $this->buildMismatchConfirmationContentFr($request, $issues, $confirmUrl);
                $enContent = $this->buildMismatchConfirmationContent($request, $issues, $confirmUrl);
            }

            $this->emailService->send(
                (string) $request['confirmation_sent_to'],
                'Action Required: Support Request Confirmation',
                $this->wrapEmailHtmlBilingual(
                    'Demande de confirmation',
                    $frContent,
                    'Confirmation request',
                    $enContent
                ),
                true
            );
            return;
        }
        if ($type === 'send_confirmation_reminder') {
            if ((string) $request['status'] !== 'pending_confirmation') {
                return;
            }
            $confirmUrl = (string) ($payload['confirm_url'] ?? '');
            $enContent = '<p style="margin:0 0 12px 0;">This is a reminder to confirm your support request.</p>' .
                '<ul style="margin:0 0 18px 18px;padding:0;color:#00001A;">' .
                '<li style="margin-bottom:8px;">Please confirm this request by clicking the button below.</li>' .
                '<li>This confirmation link is valid for 24 hours from submission.</li>' .
                '</ul>' .
                $this->buttonHtml($confirmUrl, 'Confirm');
            $frContent = '<p style="margin:0 0 12px 0;">Ceci est un rappel pour confirmer votre demande de support.</p>' .
                '<ul style="margin:0 0 18px 18px;padding:0;color:#00001A;">' .
                '<li style="margin-bottom:8px;">Veuillez confirmer cette demande en cliquant sur le bouton ci-dessous.</li>' .
                '<li>Ce lien de confirmation est valide pendant 24 heures après la soumission.</li>' .
                '</ul>' .
                $this->buttonHtml($confirmUrl, 'Confirmer');
            $this->emailService->send(
                (string) $request['confirmation_sent_to'],
                'Reminder: Support Request Confirmation',
                $this->wrapEmailHtmlBilingual(
                    'Rappel: confirmation de la demande de support',
                    $frContent,
                    'Reminder: Support Request Confirmation',
                    $enContent
                ),
                true
            );
            return;
        }
        if ($type === 'create_jira_ticket') {
            $issues = $this->issueModel->findByRequestId($requestId);
            $attachments = $this->attachmentModel->findByRequestId($requestId);
            $issueKey = $this->jiraService->createIssue($request, $issues, $attachments);
            $this->requestModel->updateJira($requestId, $issueKey, 'Open');
            $this->enqueue('send_ticket_created_email', $requestId, ['issue_key' => $issueKey], 0);
            $this->enqueue('send_admin_request_summary_email', $requestId, [], 0, 5);

            foreach ($this->attachmentModel->listForJiraAttachmentByRequest($requestId) as $attachment) {
                $this->attachmentModel->markJiraStatus((int) $attachment['id'], 'queued_for_upload');
                $this->enqueue('attach_file_to_jira', $requestId, ['attachment_id' => (int) $attachment['id'], 'issue_key' => $issueKey], 0, 5);
            }
            return;
        }
        if ($type === 'send_ticket_created_email') {
            $jiraIssueKey = htmlspecialchars((string) ($request['jira_issue_key'] ?: ($payload['issue_key'] ?? '')), ENT_QUOTES, 'UTF-8');
            $requestId = htmlspecialchars((string) ($request['public_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            $enContent = '<p style="margin:0 0 14px 0;">Thank you for contacting our support team.</p>' .
                '<p style="margin:0 0 14px 0;">Your request has been successfully received and is now being reviewed. Our team is currently working on it and will get back to you as soon as possible.</p>' .
                '<p style="margin:0 0 14px 0;"><strong>Support Ticket Details:</strong><br>' .
                'Ticket Number: ' . $jiraIssueKey . '<br>' .
                'Request ID: ' . $requestId . '</p>' .
                '<p style="margin:0 0 14px 0;">Any files you submitted have been securely attached to your ticket in our system.</p>' .
                '<p style="margin:0 0 14px 0;">If you have any questions or need to provide additional information, please feel free to contact our support team at any time.</p>';

            $frContent = '<p style="margin:0 0 14px 0;">Merci d\'avoir contacté notre équipe de support.</p>' .
                '<p style="margin:0 0 14px 0;">Votre demande a bien été reçue et est en cours d\'examen. Notre équipe y travaille actuellement et vous répondra dans les plus brefs délais.</p>' .
                '<p style="margin:0 0 14px 0;"><strong>Détails du ticket de support :</strong><br>' .
                'Numéro de ticket : ' . $jiraIssueKey . '<br>' .
                'ID de la demande : ' . $requestId . '</p>' .
                '<p style="margin:0 0 14px 0;">Tous les fichiers que vous avez soumis ont été joints en toute sécurité à votre ticket dans notre système.</p>' .
                '<p style="margin:0 0 14px 0;">Si vous avez des questions ou si vous avez besoin de fournir des informations supplémentaires, n\'hésitez pas à contacter notre équipe de support à tout moment.</p>';

            $this->emailService->send(
                (string) $request['submitted_email'],
                'We’ve received your support request / Nous avons reçu votre demande de support',
                $this->wrapEmailHtmlBilingual(
                    'Nous avons reçu votre demande de support',
                    $frContent,
                    'We’ve received your support request',
                    $enContent
                ),
                true
            );
            return;
        }
        if ($type === 'send_ticket_completed_email') {
            $content = '<p>Your support request has been marked as <strong>Done</strong>.</p>' .
                '<p><strong>Jira Ticket:</strong> ' . htmlspecialchars((string) ($request['jira_issue_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
            $this->emailService->send(
                (string) $request['submitted_email'],
                'Support ticket completed',
                $this->wrapEmailHtml('Ticket Completed', $content),
                true
            );
            return;
        }
        if ($type === 'send_ticket_status_email') {
            $jiraStatus = (string) ($payload['jira_status'] ?? $request['jira_status'] ?? 'Updated');
            $subject = 'Support request status update: ' . $jiraStatus;
            $body = '<p>Your support request status was updated.</p>' .
                '<p><strong>Status:</strong> ' . htmlspecialchars($jiraStatus, ENT_QUOTES, 'UTF-8') . '<br>' .
                '<strong>Jira Ticket:</strong> ' . htmlspecialchars((string) ($request['jira_issue_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
                '<strong>Request ID:</strong> ' . htmlspecialchars((string) ($request['public_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
            if (strtolower($jiraStatus) === 'done') {
                $body .= '<p>The support request is done. If you need additional changes, reply with details.</p>';
            } elseif (strtolower($jiraStatus) === 'client review') {
                $body .= '<p>Your support request is ready for client review.</p>';
            } else {
                $body .= '<p>We wanted to keep you informed of the latest progress.</p>';
            }
            $this->emailService->send((string) $request['submitted_email'], $subject, $this->wrapEmailHtml('Support Status Update', $body), true);
            return;
        }
        if ($type === 'send_admin_request_summary_email') {
            $issues = $this->issueModel->findByRequestId($requestId);
            $attachments = $this->attachmentModel->findByRequestId($requestId);
            $subject = 'New support request submitted: ' . ($request['public_id'] ?? $requestId);
            $body = $this->buildAdminSummaryEmailBody($request, $issues, $attachments);
            foreach ($this->adminEmails() as $adminEmail) {
                $this->emailService->send($adminEmail, $subject, $this->wrapEmailHtml('Support Request Summary', $body), true);
            }
            return;
        }
        if ($type === 'optimize_attachment') {
            $attachment = $this->attachmentModel->findById((int) ($payload['attachment_id'] ?? 0));
            if (!$attachment) {
                throw new RuntimeException('Attachment not found for optimization.');
            }
            $this->attachmentModel->markOptimizationStatus((int) $attachment['id'], 'queued_for_optimization');
            if ((string) $attachment['category'] === 'website_screenshot') {
                $result = $this->optimizer->optimize((string) $attachment['temp_path']);
                $this->attachmentModel->updateAfterOptimization(
                    (int) $attachment['id'],
                    pathinfo((string) $result['optimized_path'], PATHINFO_BASENAME),
                    (string) $result['mime_type'],
                    (string) $result['extension'],
                    (int) $result['size_optimized'],
                    (string) $result['optimized_path']
                );
            } else {
                $this->attachmentModel->markOptimizationStatus((int) $attachment['id'], 'optimized');
            }
            $this->enqueue('move_attachment_to_local_storage', $requestId, ['attachment_id' => (int) $attachment['id']], 0, 5);
            return;
        }
        if ($type === 'move_attachment_to_local_storage') {
            $attachment = $this->attachmentModel->findById((int) ($payload['attachment_id'] ?? 0));
            if (!$attachment) {
                throw new RuntimeException('Attachment not found for storage move.');
            }
            $latestRequest = $this->findRequestOrFail($requestId);
            $sourcePath = (string) ($attachment['file_path'] ?: $attachment['temp_path']);
            $storedPath = $this->storageService->moveToRequestStorage(
                $requestId, 
                $sourcePath, 
                (string) $attachment['extension'], 
                (string) ($attachment['stored_name'] ?? null),
                (string) ($latestRequest['created_at'] ?? null)
            );
            $this->attachmentModel->markStoredLocal((int) $attachment['id'], $storedPath);
            $this->enqueue('cleanup_temp_file', $requestId, ['attachment_id' => (int) $attachment['id']], 0, 3);

            $latestRequest = $this->findRequestOrFail($requestId);
            if (!empty($latestRequest['jira_issue_key'])) {
                $this->attachmentModel->markJiraStatus((int) $attachment['id'], 'queued_for_upload');
                $this->enqueue('attach_file_to_jira', $requestId, [
                    'attachment_id' => (int) $attachment['id'],
                    'issue_key' => (string) $latestRequest['jira_issue_key'],
                ], 0, 5);
            }
            return;
        }
        if ($type === 'cleanup_temp_file') {
            $attachment = $this->attachmentModel->findById((int) ($payload['attachment_id'] ?? 0));
            if (!$attachment) {
                return;
            }
            $tempPath = (string) ($attachment['temp_path'] ?? '');
            $this->storageService->deleteFileIfExists($tempPath);
            $this->attachmentModel->updateTempPath((int) $attachment['id'], null);
            return;
        }
        if ($type === 'attach_file_to_jira') {
            $attachment = $this->attachmentModel->findById((int) ($payload['attachment_id'] ?? 0));
            if (!$attachment) {
                throw new RuntimeException('Attachment not found for Jira upload.');
            }
            $issueKey = (string) ($payload['issue_key'] ?? $request['jira_issue_key'] ?? '');
            if ($issueKey === '') {
                throw new RuntimeException('Jira issue key missing for attachment upload.');
            }
            $this->jiraAttachmentService->upload($issueKey, $attachment);
            $this->attachmentModel->markJiraAttached((int) $attachment['id']);
            return;
        }
        if ($type === 'cleanup_expired_local_file') {
            $attachment = $this->attachmentModel->findById((int) ($payload['attachment_id'] ?? 0));
            if (!$attachment) {
                return;
            }
            if ($this->storageService->deleteFileIfExists((string) ($attachment['file_path'] ?? null))) {
                $this->attachmentModel->markDeleted((int) $attachment['id']);
            }
            return;
        }

        throw new RuntimeException('Unsupported job type: ' . $type);
    }

    private function findRequestOrFail(int $requestId): array
    {
        $stmt = \App\database\Database::getConnection()->prepare('SELECT * FROM support_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new RuntimeException('Request not found for queue item.');
        }
        return $request;
    }

    private function notifyAdminFailure(array $job, string $error): void
    {
        try {
            if ((string) ($job['job_type'] ?? '') === 'create_jira_ticket') {
                $request = $this->findRequestOrFail((int) $job['request_id']);
                $subject = 'Warning: Jira ticket not created for support request';
                $warning = '<div style="border:1px solid #ffbfbf;border-radius:6px;padding:10px;background:#fff0f0;color:#7a1f1f;font-size:14px;margin-bottom:14px;">' .
                    'Jira ticket was not created after retry attempts. Please review this support request manually.' .
                    '</div>';
                $summary = $this->buildAdminSummaryEmailBody($request, $this->issueModel->findByRequestId((int) $job['request_id']), $this->attachmentModel->findByRequestId((int) $job['request_id']));
                foreach ($this->adminEmails() as $adminEmail) {
                    $this->emailService->send($adminEmail, $subject, $this->wrapEmailHtml('Jira Creation Warning', $warning . $summary), true);
                }
                return;
            }
            Logger::error('Queue job failed permanently (generic admin email disabled)', [
                'job_type' => (string) ($job['job_type'] ?? ''),
                'request_id' => (int) ($job['request_id'] ?? 0),
                'error' => $error,
            ]);
        } catch (Throwable $exception) {
            Logger::error('Admin notification failed', ['error' => $exception->getMessage()]);
        }
    }

    private function adminEmails(): array
    {
        $settings = get_option('kgr_setting', []);
        $emailsRaw = (string) ($settings['general']['admin_emails'] ?? '');
        if (trim($emailsRaw) === '') {
            $emailsRaw = (string) Config::get('ADMIN_EMAILS', Config::get('ADMIN_EMAIL', ''));
        }
        $parts = array_filter(array_map('trim', explode(',', $emailsRaw)));
        $valid = [];
        foreach ($parts as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = strtolower($email);
            }
        }
        return array_values(array_unique($valid));
    }

    private function buildAdminSummaryEmailBody(array $request, array $issues, array $attachments): string
    {
        $baseUrl = rtrim((string) Config::get('APP_BASE_URL', ''), '/');
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $submittedBy = $this->submittedDisplayName($request);
        $html = '';
        $html .= '<p style="margin:0 0 16px 0;">A support request has been confirmed and is ready for operations review.</p>';
        $jiraBaseUrl = rtrim((string) Config::getJiraValue('JIRA_BASE_URL', ''), '/');
        $jiraIssueKey = (string) ($request['jira_issue_key'] ?? '');
        if ($jiraIssueKey !== '' && $jiraBaseUrl !== '') {
            $jiraLink = $jiraBaseUrl . '/browse/' . rawurlencode($jiraIssueKey);
            $html .= '<p style="margin:0 0 16px 0;"><strong>Jira Ticket:</strong> <a href="' . htmlspecialchars($jiraLink, ENT_QUOTES, 'UTF-8') . '" style="color:#00001A;">Open ticket ' . htmlspecialchars($jiraIssueKey, ENT_QUOTES, 'UTF-8') . '</a></p>';
        } else {
            $html .= '<div style="border:1px solid #ffbfbf;border-radius:6px;padding:10px;background:#fff0f0;color:#7a1f1f;font-size:14px;margin:0 0 16px 0;">Jira ticket was not created yet. Please pay attention to this support request.</div>';
        }
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e4;border-radius:8px;background:#ffffff;">';
        $html .= '<tr><td style="padding:14px 16px;font-size:14px;line-height:1.55;color:#00001A;">' .
            '<strong>Request ID:</strong> ' . htmlspecialchars((string) ($request['public_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>';

        if (!empty($metadata['title'])) {
            $html .= '<strong>Title:</strong> ' . htmlspecialchars((string) $metadata['title'], ENT_QUOTES, 'UTF-8') . '<br>';
        }

        $html .= '<strong>Website:</strong> ' . htmlspecialchars((string) ($request['website_domain'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Submitted email:</strong> ' . htmlspecialchars((string) ($request['submitted_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Verified email:</strong> ' . htmlspecialchars((string) ($request['verified_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Client (WHMCS Owner):</strong> ' . htmlspecialchars((string) ($request['client_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Submitted by:</strong> ' . htmlspecialchars($submittedBy, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars((string) ($request['submitted_email'] ?? ''), ENT_QUOTES, 'UTF-8') . ')<br>' .
            '<strong>Company:</strong> ' . htmlspecialchars((string) ($request['client_company'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Client Status on WHMCS:</strong> ' . htmlspecialchars((string) ($metadata['whmcsClientStatus'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Submitted at:</strong> ' . htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') .
            '</td></tr></table>';
        $html .= '<h3 style="margin:22px 0 10px;font-size:16px;color:#00001A;">Issues</h3><ul style="padding-left:18px;margin:0;">';
        foreach ($issues as $index => $issue) {
            $html .= '<li style="margin-bottom:12px;list-style:none;background:#ffffff;border:1px solid #e4e4e4;border-radius:8px;padding:10px 12px;">' .
                '<strong>Issue ' . ($index + 1) . ':</strong> ' .
                htmlspecialchars((string) ($issue['issue_type'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Urgency:</strong> ' . htmlspecialchars((string) ($issue['urgency_level'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>URL:</strong> ' . htmlspecialchars((string) ($issue['page_url'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Description:</strong> ' . nl2br(htmlspecialchars((string) ($issue['description'] ?? ''), ENT_QUOTES, 'UTF-8')) .
                '</li>';
        }
        $html .= '</ul>';
        $hasLinks = false;
        foreach ($attachments as $attachment) {
            $relativePath = (string) ($attachment['file_path'] ?: $attachment['temp_path'] ?? '');
            if ($relativePath !== '') {
                $hasLinks = true;
                break;
            }
        }
        if ($hasLinks) {
            $html .= '<h3 style="margin:22px 0 10px;font-size:16px;color:#00001A;">Attachments</h3><ul style="padding-left:18px;margin:0;">';
        }
        foreach ($attachments as $attachment) {
            $relativePath = (string) ($attachment['file_path'] ?: $attachment['temp_path'] ?? '');
            if ($relativePath === '') {
                continue;
            }
            $attachmentId = (int) ($attachment['id'] ?? 0);
            $downloadUrl = $this->buildAdminAttachmentDownloadUrl($attachmentId);
            $label = htmlspecialchars((string) ($attachment['original_name'] ?? 'file'), ENT_QUOTES, 'UTF-8');
            $html .= '<li style="margin-bottom:6px;">' .
                ($downloadUrl !== ''
                    ? '<a href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#00001A;text-decoration:underline;">' . $label . '</a>'
                    : $label) .
                '</li>';
        }
        if ($hasLinks) {
            $html .= '</ul>';
        }
        
        return $html;
    }

    private function markAttachmentFailureIfNeeded(string $jobType, string $payloadJson, string $queueStatus): void
    {
        if ($queueStatus !== 'failed') {
            return;
        }
        $payload = json_decode($payloadJson, true) ?: [];
        $attachmentId = (int) ($payload['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            return;
        }
        if (in_array($jobType, ['optimize_attachment', 'move_attachment_to_local_storage', 'cleanup_temp_file'], true)) {
            $this->attachmentModel->markOptimizationStatus($attachmentId, 'failed');
        }
        if (in_array($jobType, ['attach_file_to_jira'], true)) {
            $this->attachmentModel->markJiraStatus($attachmentId, 'failed');
        }
    }

    private function buildAdminAttachmentDownloadUrl(int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }
        if (!function_exists('admin_url') || !function_exists('wp_nonce_url')) {
            return '';
        }

        $base = admin_url('admin-ajax.php?action=kgr_download_attachment&attachment_id=' . $attachmentId);
        return wp_nonce_url($base, 'kgr_download_attachment_' . $attachmentId, 'nonce');
    }

    private function buildSimpleConfirmationContent(array $request, array $issues, string $confirmUrl): string
    {
        $ownerName = $this->ownerDisplayName($request);
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $title = (string) ($metadata['title'] ?? '');

        $content = '<p style="margin:0 0 14px 0;">Hi ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p style="margin:0 0 14px 0;">We have received your support request and we need your confirmation before processing.</p>';

        $content .= '<div style="margin:20px 0;padding:15px;background:#f9f9f9;border-radius:8px;border:1px solid #e4e4e4;">';
        $content .= '<strong>Request Details:</strong><br>';
        if ($title !== '') {
            $content .= 'Title: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '<br>';
            if (!empty($issues[0]['description'])) {
                $content .= 'Description: ' . nl2br(htmlspecialchars((string) $issues[0]['description'], ENT_QUOTES, 'UTF-8')) . '<br>';
            }
        }
        $content .= 'Website: ' . htmlspecialchars((string) ($request['website_domain'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>';
        $content .= 'Company: ' . htmlspecialchars((string) ($request['client_company'] ?? ''), ENT_QUOTES, 'UTF-8');
        $content .= '</div>';

        $content .= '<ul style="margin:30px 0 18px 18px;padding:0;color:#00001A;">' .
            '<li style="margin-bottom:8px;">Please confirm this request by clicking the button below.</li>' .
            '<li>This confirmation link is valid for 24 hours.</li>' .
            '</ul>';
        $content .= $this->buttonHtml($confirmUrl, 'Confirm');
        return $content;
    }

    private function buildSimpleConfirmationContentFr(array $request, array $issues, string $confirmUrl): string
    {
        $ownerName = $this->ownerDisplayName($request);
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $title = (string) ($metadata['title'] ?? '');

        $content = '<p style="margin:0 0 14px 0;">Bonjour ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p style="margin:0 0 14px 0;">Nous avons bien reçu votre demande de support et nous avons besoin de votre confirmation avant de la traiter.</p>';

        $content .= '<div style="margin:20px 0;padding:15px;background:#f9f9f9;border-radius:8px;border:1px solid #e4e4e4;">';
        $content .= '<strong>Détails de la demande:</strong><br>';
        if ($title !== '') {
            $content .= 'Titre: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '<br>';
            if (!empty($issues[0]['description'])) {
                $content .= 'Description: ' . nl2br(htmlspecialchars((string) $issues[0]['description'], ENT_QUOTES, 'UTF-8')) . '<br>';
            }
        }
        $content .= 'Site web: ' . htmlspecialchars((string) ($request['website_domain'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>';
        $content .= 'Entreprise: ' . htmlspecialchars((string) ($request['client_company'] ?? ''), ENT_QUOTES, 'UTF-8');
        $content .= '</div>';

        $content .= '<ul style="margin:30px 0 18px 18px;padding:0;color:#00001A;">' .
            '<li style="margin-bottom:8px;">Veuillez confirmer cette demande en cliquant sur le bouton ci-dessous.</li>' .
            '<li>Ce lien de confirmation est valide pendant 24 heures.</li>' .
            '</ul>';
        $content .= $this->buttonHtml($confirmUrl, 'Confirmer');
        return $content;
    }

    private function buildMismatchConfirmationContent(array $request, array $issues, string $confirmUrl): string
    {
        $ownerName = $this->ownerDisplayName($request);
        $senderName = $this->submittedDisplayName($request);
        $nameParts = preg_split('/\s+/', trim($senderName), 2) ?: [];
        $firstName = (string) ($nameParts[0] ?? '');
        $lastName = (string) ($nameParts[1] ?? '');

        $content = '<p style="margin:0 0 14px 0;">Hi ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p style="margin:0 0 14px 0;">A support request was submitted for your website using an email address different from the account owner email.</p>';
        $content .= '<p style="margin:0 0 14px 0;">Please review the details below and confirm only if this request is legitimate.</p>';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Sender Information</h3>';
        $content .= '<p>' .
            '<strong>Email:</strong> ' . htmlspecialchars((string) ($request['submitted_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>First name:</strong> ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Last name:</strong> ' . htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8') .
            '</p>';
        $content .= '<hr style="border:none;border-top:1px solid #ddd;">';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Request Details</h3>';
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $title = (string) ($metadata['title'] ?? '');

        $content .= '<p>' .
            '<strong>Request ID:</strong> ' . htmlspecialchars((string) ($request['public_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>';
        if ($title !== '') {
            $content .= '<strong>Title:</strong> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '<br>';
        }
        $content .= '<strong>Website:</strong> ' . htmlspecialchars((string) ($request['website_domain'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Company:</strong> ' . htmlspecialchars((string) ($request['client_company'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Submitted at:</strong> ' . htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') .
            '</p>';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Issues</h3><ul style="padding-left:18px;">';
        foreach ($issues as $index => $issue) {
            $content .= '<li style="margin-bottom:10px;">' .
                '<strong>Issue ' . ($index + 1) . ':</strong> ' . htmlspecialchars((string) ($issue['issue_type'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Urgency:</strong> ' . htmlspecialchars((string) ($issue['urgency_level'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Page:</strong> ' . htmlspecialchars((string) ($issue['page_url'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Description:</strong> ' . nl2br(htmlspecialchars((string) ($issue['description'] ?? ''), ENT_QUOTES, 'UTF-8')) .
                '</li>';
        }
        $content .= '</ul>';
        $content .= '<ul style="margin:50px 0 18px 18px;padding:0;color:#00001A;">' .
            '<li style="margin-bottom:8px;">Please confirm this request by clicking the button below.</li>' .
            '<li>This confirmation link is valid for 24 hours.</li>' .
            '</ul>';
        $content .= $this->buttonHtml($confirmUrl, 'Confirm');
        $content .= '<div style="text-align:center;"><div style="border:1px solid #ceff00;border-radius:5px;padding:10px;text-align:center;color:#727200;background:#efffd7;font-size:15px;display:inline-block;margin-top:14px;">' .
            'If this request was not authorized by your team, please do not confirm it.' .
            '</div></div>';
        return $content;
    }

    private function buildMismatchConfirmationContentFr(array $request, array $issues, string $confirmUrl): string
    {
        $ownerName = $this->ownerDisplayName($request);
        $senderName = $this->submittedDisplayName($request);
        $nameParts = preg_split('/\s+/', trim($senderName), 2) ?: [];
        $firstName = (string) ($nameParts[0] ?? '');
        $lastName = (string) ($nameParts[1] ?? '');

        $content = '<p style="margin:0 0 14px 0;">Bonjour ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p style="margin:0 0 14px 0;">Une demande de support a été soumise pour votre site web avec une adresse courriel différente de celle du propriétaire du compte.</p>';
        $content .= '<p style="margin:0 0 14px 0;">Veuillez vérifier les détails ci-dessous et confirmer uniquement si cette demande est légitime.</p>';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Informations sur l’expéditeur</h3>';
        $content .= '<p>' .
            '<strong>Courriel:</strong> ' . htmlspecialchars((string) ($request['submitted_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Prénom:</strong> ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Nom:</strong> ' . htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8') .
            '</p>';
        $content .= '<hr style="border:none;border-top:1px solid #ddd;">';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Détails de la demande</h3>';
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $title = (string) ($metadata['title'] ?? '');

        $content .= '<p>' .
            '<strong>ID de demande:</strong> ' . htmlspecialchars((string) ($request['public_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>';
        if ($title !== '') {
            $content .= '<strong>Titre:</strong> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '<br>';
        }
        $content .= '<strong>Site web:</strong> ' . htmlspecialchars((string) ($request['website_domain'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Entreprise:</strong> ' . htmlspecialchars((string) ($request['client_company'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Date de soumission:</strong> ' . htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') .
            '</p>';
        $content .= '<h3 style="margin:20px 0 10px;font-size:16px;">Problèmes</h3><ul style="padding-left:18px;">';
        foreach ($issues as $index => $issue) {
            $content .= '<li style="margin-bottom:10px;">' .
                '<strong>Problème ' . ($index + 1) . ':</strong> ' . htmlspecialchars((string) ($issue['issue_type'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Urgence:</strong> ' . htmlspecialchars((string) ($issue['urgency_level'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Page:</strong> ' . htmlspecialchars((string) ($issue['page_url'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '<br><strong>Description:</strong> ' . nl2br(htmlspecialchars((string) ($issue['description'] ?? ''), ENT_QUOTES, 'UTF-8')) .
                '</li>';
        }
        $content .= '</ul>';
        $content .= '<ul style="margin:50px 0 18px 18px;padding:0;color:#00001A;">' .
            '<li style="margin-bottom:8px;">Veuillez confirmer cette demande en cliquant sur le bouton ci-dessous.</li>' .
            '<li>Ce lien de confirmation est valide pendant 24 heures.</li>' .
            '</ul>';
        $content .= $this->buttonHtml($confirmUrl, 'Confirmer');
        $content .= '<div style="text-align:center;"><div style="border:1px solid #ceff00;border-radius:5px;padding:10px;text-align:center;color:#727200;background:#efffd7;font-size:15px;display:inline-block;margin-top:14px;">' .
            'Si cette demande n’a pas été autorisée par votre équipe, veuillez ne pas la confirmer.' .
            '</div></div>';
        return $content;
    }

    private function ownerDisplayName(array $request): string
    {
        $name = trim((string) ($request['client_name'] ?? ''));
        return $name !== '' ? $name : 'Client';
    }

    private function submittedDisplayName(array $request): string
    {
        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) {
            return 'Unknown Sender';
        }
        $firstName = trim((string) ($metadata['submittedFirstName'] ?? ''));
        $lastName = trim((string) ($metadata['submittedLastName'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        return $name !== '' ? $name : 'Unknown Sender';
    }

    private function buttonHtml(string $url, string $label): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<p style="margin:20px 0;text-align:center;">' .
            '<a href="' . $safeUrl . '" style="display:inline-block;background:#00001A;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:600;">' .
            $safeLabel .
            '</a></p>';
    }

    private function wrapEmailHtml(string $title, string $contentHtml): string
    {
        $logoUrl = (string) Config::get('EMAIL_LOGO_URL', 'https://via.placeholder.com/160x48?text=Kanguru+Logo');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
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
                    <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Kanguru Logo" style="max-height:44px;width:auto;">
                  </td>
                  <td align="right" valign="middle" style="font-size:12px;color:#00001A;letter-spacing:0.2px;">' . htmlspecialchars($dateTime, ENT_QUOTES, 'UTF-8') . '</td>
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

    private function wrapEmailHtmlBilingual(string $frTitle, string $frContentHtml, string $enTitle, string $enContentHtml): string
    {
        $logoUrl = (string) Config::get('EMAIL_LOGO_URL', 'https://via.placeholder.com/160x48?text=Kanguru+Logo');
        $dateTime = date('Y-m-d H:i:s');

        return '<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Support Request Confirmation</title></head>
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
                    <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Kanguru Logo" style="max-height:44px;width:auto;">
                  </td>
                  <td align="right" valign="middle" style="font-size:12px;color:#00001A;letter-spacing:0.2px;">' . htmlspecialchars($dateTime, ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f5;border-radius:12px;border:1px solid #ececec;">
                <tr>
                  <td style="padding:26px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px 0;">
                      <tr>
                        <td align="left" valign="middle">
                          <h2 style="margin:0;font-size:21px;line-height:1.3;color:#00001A;">' . htmlspecialchars($frTitle, ENT_QUOTES, 'UTF-8') . '</h2>
                        </td>
                        <td align="right" valign="middle" style="font-size:11px;color:#666;white-space:nowrap;padding-left:10px;">
                          English version below
                        </td>
                      </tr>
                    </table>
                    <section lang="fr">' . $frContentHtml . '</section>
                    <hr style="border:none;border-top:1px solid #ddd;margin:24px 0 16px;">
                    <p style="margin:0;font-size:13px;line-height:1.5;color:#00001A;">Équipe de support: <a href="mailto:support@kanguru.ca" style="color:#00001A;text-decoration:underline;">support@kanguru.ca</a></p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 20px 0 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f5;border-radius:12px;border:1px solid #ececec;">
                <tr>
                  <td style="padding:26px;">
                    <h2 style="margin:0 0 16px 0;font-size:21px;line-height:1.3;color:#00001A;">' . htmlspecialchars($enTitle, ENT_QUOTES, 'UTF-8') . '</h2>
                    <section lang="en">' . $enContentHtml . '</section>
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

    private function shouldDispatchEmailImmediately(string $jobType): bool
    {
        if (!$this->isLocalEnv()) {
            return false;
        }
        return in_array($jobType, [
            'send_confirmation_email',
            'send_ticket_created_email',
            'send_ticket_completed_email',
            'send_ticket_status_email',
            'send_admin_request_summary_email',
        ], true);
    }

    private function isLocalEnv(): bool
    {
        $env = strtolower((string) Config::get('APP_ENV', 'production'));
        return in_array($env, ['local', 'development', 'dev', 'testing'], true);
    }
}
