<?php

declare(strict_types=1);

namespace App\services;

use App\helpers\Security;
use App\helpers\Logger;
use App\helpers\Validator;
use App\models\IssueAttachmentModel;
use App\models\SupportIssueModel;
use App\models\SupportRequestModel;
use App\config\Config;
use RuntimeException;
use Throwable;

final class SupportRequestService
{
    private array $websiteIssueTypes = ['Content change', 'Image replacement', 'Form problem', 'Performance issue', 'Other'];
    private int $minDescriptionChars = 20;
    private array $urgencyLevels = [
        'Minor issue',
        'Some users affected',
        'Important functionality broken',
        'Website unusable',
    ];

    private SupportRequestModel $requestModel;
    private SupportIssueModel $issueModel;
    private IssueAttachmentModel $attachmentModel;
    private WhmcsService $whmcsService;
    private UploadService $uploadService;
    private QueueService $queueService;
    private IssueCategorizationService $categorizationService;

    public function __construct()
    {
        $this->requestModel = new SupportRequestModel();
        $this->issueModel = new SupportIssueModel();
        $this->attachmentModel = new IssueAttachmentModel();
        $this->whmcsService = new WhmcsService();
        $this->uploadService = new UploadService();
        $this->queueService = new QueueService();
        $this->categorizationService = new IssueCategorizationService();
    }

    public function preview(array $payload): array
    {
        [$sanitized, $errors] = $this->validatePayload($payload);
        if ($errors !== []) {
            return ['valid' => false, 'errors' => $errors];
        }

        $domain = $this->resolveDomainForHash($sanitized);
        $hash = $this->duplicateHash($sanitized['email'], $domain, $sanitized['issues']);
        $duplicate = $this->requestModel->findByDuplicateHash($hash, 6);

        return [
            'valid' => true,
            'duplicate_warning' => $duplicate !== null ? 'A similar request was submitted recently. Please check if your issue is already reported.' : null,
            'preview' => [
                'email' => $sanitized['email'],
                'service_type' => $sanitized['service_type'],
                'website' => $domain,
                'issues' => $sanitized['issues'],
                'title' => $sanitized['title'],
                'message' => $sanitized['message'],
            ],
        ];
    }

    public function submit(array $payload, array $filesByIssue, array $nonWebsiteFiles = []): array
    {
        $traceId = (string) ($payload['_trace_id'] ?? ('sr-' . bin2hex(random_bytes(6))));

        [$sanitized, $errors] = $this->validatePayload($payload);
        if ($errors !== []) {
            Logger::error('Support submission validation failed', [
                'trace_id' => $traceId,
                'error_keys' => array_keys($errors),
            ]);
            return ['success' => false, 'errors' => $errors, 'status_code' => 422];
        }

        $domain = $this->resolveDomainForHash($sanitized);
        $duplicateHash = $this->duplicateHash($sanitized['email'], $domain, $sanitized['issues']);
        $duplicate = $this->requestModel->findByDuplicateHash($duplicateHash, 6);
        if ($duplicate && empty($sanitized['allow_duplicate'])) {
            Logger::error('Support submission blocked by duplicate guard', [
                'trace_id' => $traceId,
                'domain' => $domain,
            ]);
            return [
                'success' => false,
                'status_code' => 409,
                'message' => 'A similar request was submitted recently. Please check if your issue is already reported.',
                'requires_duplicate_override' => true,
            ];
        }

        try {
            $verification = $this->verifyClient($sanitized['email'], $sanitized['service_type'], $domain);
        } catch (Throwable $exception) {
            Logger::error('Support submission client verification exception', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Client verification failed: ' . $exception->getMessage(), 0, $exception);
        }
        if (!$verification['valid']) {
            Logger::error('Support submission client verification rejected', [
                'trace_id' => $traceId,
                'reason' => $verification['message'] ?? null,
            ]);
            return [
                'success' => false,
                'status_code' => 403,
                'message' => $verification['message'],
            ];
        }

        $stagedWebsiteFiles = [];
        $stagedServiceFiles = [];
        try {
            if ($sanitized['service_type'] === 'Website') {
                $screenshotErrors = $this->validateWebsiteScreenshotRequirements($sanitized['issues'], $filesByIssue);
                if ($screenshotErrors !== []) {
                    Logger::error('Support submission screenshot requirement validation failed', [
                        'trace_id' => $traceId,
                        'error_keys' => array_keys($screenshotErrors),
                    ]);
                    return [
                        'success' => false,
                        'status_code' => 422,
                        'errors' => $screenshotErrors,
                    ];
                }
                $stagedWebsiteFiles = $this->uploadService->stageWebsiteIssueFiles($filesByIssue, $sanitized['issues']);
            } else {
                $stagedServiceFiles = $this->uploadService->stageNonWebsiteFiles($nonWebsiteFiles);
            }
        } catch (Throwable $exception) {
            Logger::error('Support submission file staging failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);
            return [
                'success' => false,
                'status_code' => 422,
                'message' => $exception->getMessage(),
                'errors' => ['attachments' => $exception->getMessage()],
            ];
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $publicId = strtoupper(bin2hex(random_bytes(6)));
        $baseUrl = (string) ($payload['_app_base_url'] ?? \App\config\Config::get('APP_BASE_URL', ''));
        $baseUrl = rtrim($baseUrl, '/');
        $confirmationUrl = $baseUrl . '/?kgr_api=confirm&token=' . urlencode($token);
        if (function_exists('home_url')) {
            $confirmationUrl = add_query_arg(
                [
                    'kgr_api' => 'confirm',
                    'token' => $token,
                ],
                (string) home_url('/')
            );
        }
        $returnPageUrl = trim((string) ($payload['return_page_url'] ?? ''));
        if ($returnPageUrl !== '' && filter_var($returnPageUrl, FILTER_VALIDATE_URL) !== false) {
            $glue = strpos($returnPageUrl, '?') !== false ? '&' : '?';
            $confirmationUrl = $returnPageUrl . $glue . 'kgr_confirm_token=' . urlencode($token);
        }

        try {

            $submittedName = trim(($sanitized['first_name'] ?? '') . ' ' . ($sanitized['last_name'] ?? ''));
            $ownerName = trim((string) ($verification['client']['name'] ?? ''));

            // Prioritize business name from form if provided, otherwise use WHMCS company name
            $finalCompany = $sanitized['client_company'] !== ''
                ? $sanitized['client_company']
                : (string) ($verification['client']['company'] ?? '');

            $requestId = $this->requestModel->create([
                'public_id' => $publicId,
                'client_id' => $verification['client']['whmcs_client_id'],
                'client_whmcs_id' => $verification['client']['whmcs_client_id'],
                'client_name' => $ownerName !== '' ? $ownerName : $submittedName,
                'client_company' => $finalCompany,
                'submitted_email' => $sanitized['email'],
                'verified_email' => $verification['client']['email'],
                'website_domain' => $domain,
                'status' => 'pending_confirmation',
                'duplicate_hash' => $duplicateHash,
                'duplicate_override' => !empty($sanitized['allow_duplicate']) ? 1 : 0,
                'metadata_json' => json_encode(array_merge($sanitized['metadata'], [
                    'submittedFirstName' => $sanitized['first_name'] ?? '',
                    'submittedLastName' => $sanitized['last_name'] ?? '',
                    'whmcsClientStatus' => (string) ($verification['client']['status'] ?? ''),
                    'whmcsOwnerEmail' => (string) ($verification['client']['owner_email'] ?? ($verification['client']['email'] ?? '')),
                    'whmcsContactEmails' => array_values((array) ($verification['client']['contact_emails'] ?? [])),
                    'whmcsAuthorizedEmails' => array_values((array) ($verification['client']['authorized_emails'] ?? [])),
                    'submittedEmailAuthorized' => !empty($verification['submitted_email_authorized']),
                    'title' => $sanitized['title'] ?? '',
                ]), JSON_UNESCAPED_SLASHES),
                'confirmation_token_hash' => $tokenHash,
                'confirmation_sent_to' => $verification['confirmation_email'],
                'confirmation_expires_at' => date('Y-m-d H:i:s', time() + (24 * 3600)),
                'confirmed_at' => null,
                'jira_issue_key' => null,
                'jira_status' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $issues = $sanitized['issues'];
            if ($sanitized['service_type'] !== 'Website') {
                $issues = [
                    [
                        'issue_type' => $sanitized['service_type'] . ' request',
                        'urgency_level' => 'Minor issue',
                        'page_url' => $sanitized['website_url'] ?: 'https://service.local/request',
                        'description' => $sanitized['message'],
                        'current_content' => null,
                        'new_content' => null,
                        'suggested_issue_type' => $this->categorizationService->suggest($sanitized['message'])['suggested_issue_type'],
                    ]
                ];
            }

            $issueIds = $this->issueModel->createMany($requestId, $issues);
            $attachmentRows = $this->buildAttachmentRows($requestId, $issueIds, $stagedWebsiteFiles, $stagedServiceFiles, $sanitized['issues']);
            $this->attachmentModel->createMany($attachmentRows);

            $attachments = $this->attachmentModel->findByRequestId($requestId);
            foreach ($attachments as $attachment) {
                $this->queueService->enqueue('optimize_attachment', $requestId, ['attachment_id' => (int) $attachment['id']], 0, 5);
            }

            $this->queueService->enqueue('send_confirmation_email', $requestId, ['confirm_url' => $confirmationUrl], 0);
            $this->queueService->enqueue('send_confirmation_reminder', $requestId, ['confirm_url' => $confirmationUrl], 6 * 3600);

            $confirmationEmail = $verification['confirmation_email'] ?? $sanitized['email'];
            return [
                'success' => true,
                'status_code' => 201,
                'message' => "A confirmation email has been sent to $confirmationEmail Please check your inbox and click the link to finalize your submission.",
                'confirmation_email' => $confirmationEmail,
                'request_id' => $publicId,
            ];
        } catch (Throwable $exception) {
            Logger::error('Support submission persistence failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Failed to save support request: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function confirm(string $token): array
    {
        $token = trim($token);
        $tokenPreview = $token !== '' ? substr($token, 0, 10) . '...' : '[empty]';
        Logger::info('Support confirm started', [
            'token_preview' => $tokenPreview,
        ]);

        if ($token === '') {
            Logger::error('Support confirm rejected: empty token');
            return ['success' => false, 'message' => 'Invalid confirmation token.'];
        }

        $tokenHash = hash('sha256', $token);
        $request = $this->requestModel->findByTokenHash($tokenHash);
        if (!$request) {
            Logger::error('Support confirm rejected: token not found', [
                'token_preview' => $tokenPreview,
            ]);
            return ['success' => false, 'message' => 'Invalid confirmation token.'];
        }
        if ((string) $request['status'] !== 'pending_confirmation') {
            Logger::info('Support confirm rejected: already processed', [
                'request_id' => (int) $request['id'],
                'status' => (string) $request['status'],
            ]);
            return ['success' => false, 'message' => 'This request is already processed.'];
        }
        if (strtotime((string) $request['confirmation_expires_at']) < time()) {
            $this->requestModel->updateStatus((int) $request['id'], 'expired');
            Logger::info('Support confirm rejected: link expired', [
                'request_id' => (int) $request['id'],
                'expires_at' => (string) $request['confirmation_expires_at'],
            ]);
            return ['success' => false, 'message' => 'Confirmation link has expired.'];
        }

        try {
            $this->requestModel->updateStatus((int) $request['id'], 'confirmed');
            $this->queueService->enqueue('create_jira_ticket', (int) $request['id']);
            $this->triggerQueueProcessingAsync();

            Logger::info('Support confirm completed', [
                'request_id' => (int) $request['id'],
                'public_id' => (string) ($request['public_id'] ?? ''),
                'queue_job' => 'create_jira_ticket',
            ]);
            return [
                'success' => true,
                'message' => 'Support request confirmed. Ticket creation is now queued.',
                'request_id' => (int) $request['id'],
            ];
        } catch (Throwable $exception) {
            Logger::error('Support confirm failed during persistence/queue', [
                'request_id' => (int) $request['id'],
                'public_id' => (string) ($request['public_id'] ?? ''),
                'error' => $exception->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Could not finalize confirmation. Please contact support.'];
        }
    }

    private function triggerQueueProcessingAsync(): void
    {
        if (!function_exists('wp_remote_post') || !function_exists('home_url')) {
            return;
        }

        // Fire WP-Cron in background so confirmation stays fast while queue keeps moving.
        $cronUrl = add_query_arg(
            ['doing_wp_cron' => sprintf('%.22F', microtime(true))],
            home_url('/wp-cron.php')
        );

        wp_remote_post($cronUrl, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    private function processCriticalQueueNow(): void
    {
        try {
            // Keep confirmation reasonably responsive while guaranteeing core workflow
            // on environments where WP-Cron is unreliable (low-traffic websites).
            // This includes the attachment pipeline so Jira receives actual files.
            $this->queueService->process([
                'create_jira_ticket',
                'send_ticket_created_email',
                'optimize_attachment',
                'move_attachment_to_local_storage',
                'attach_file_to_jira',
                'cleanup_temp_file',
            ], 20);
        } catch (Throwable $queueException) {
            Logger::error('Support confirm critical queue fallback failed', [
                'error' => $queueException->getMessage(),
            ]);
        }
    }

    public function processPostConfirmQueue(): void
    {
        $this->processCriticalQueueNow();
    }

    public function queuePendingMaintenance(): array
    {
        $expiredCount = $this->requestModel->expireOldPending();
        return ['expired_marked' => $expiredCount];
    }

    public function domainsByEmail(string $email): array
    {
        $email = strtolower(Security::sanitizeString($email));
        if (!Validator::email($email)) {
            return ['success' => false, 'status_code' => 422, 'message' => 'Valid email is required.'];
        }

        if ($this->isLocalEnv()) {
            return [
                'success' => true,
                'status_code' => 200,
                'client_name' => 'Local Test Client',
                'email' => $email,
                'domains' => ['example.com', 'www.example.com'],
                'bypassed' => true,
            ];
        }

        $client = $this->whmcsService->safeFindClientByEmail($email);
        if (!$client || empty($client['domains'])) {
            return [
                'success' => false,
                'status_code' => 404,
                'message' => 'We could not find your information. Please contact our support team.',
            ];
        }

        return [
            'success' => true,
            'status_code' => 200,
            'client_name' => $client['name'],
            'email' => $client['email'],
            'domains' => array_values($client['domains']),
            'services' => $client['services'] ?? [],
        ];
    }

    public function suggestIssueType(string $description): array
    {
        $suggestion = $this->categorizationService->suggest($description);
        return [
            'success' => true,
            'status_code' => 200,
            'description' => $description,
            'suggested_issue_type' => $suggestion['suggested_issue_type'],
            'confidence' => $suggestion['confidence'],
        ];
    }

    private function verifyClient(string $email, string $serviceType, string $domain): array
    {
        $normalizedDomain = Validator::normalizeDomainInput($domain);

        if ($this->isLocalEnv()) {
            return [
                'valid' => true,
                'client' => [
                    'whmcs_client_id' => 0,
                    'name' => 'Local Test Client',
                    'email' => $email,
                    'domains' => $normalizedDomain !== '' ? [$normalizedDomain] : [],
                ],
                'confirmation_email' => $email,
                'bypassed' => true,
            ];
        }

        if ($serviceType !== 'Website') {
            $clientByEmail = $this->whmcsService->safeFindClientByEmail($email);
            if ($clientByEmail) {
                return ['valid' => true, 'client' => $clientByEmail, 'confirmation_email' => $clientByEmail['email']];
            }
            return ['valid' => false, 'message' => 'We could not find your information. Please contact our support team.'];
        }

        $clientByDomain = $this->whmcsService->safeFindClientByDomain($normalizedDomain);
        if ($clientByDomain) {
            $verifiedEmail = strtolower((string) ($clientByDomain['owner_email'] ?? ($clientByDomain['email'] ?? '')));
            $submittedEmail = strtolower($email);
            $authorizedEmails = array_values(array_unique(array_filter(array_map(
                static fn($v): string => strtolower(trim((string) $v)),
                (array) ($clientByDomain['authorized_emails'] ?? [])
            ))));
            if ($authorizedEmails === [] && $verifiedEmail !== '') {
                $authorizedEmails[] = $verifiedEmail;
            }

            $submittedAuthorized = in_array($submittedEmail, $authorizedEmails, true);
            $confirmationEmail = $submittedAuthorized
                ? $submittedEmail
                : ($verifiedEmail !== '' ? $verifiedEmail : $submittedEmail);
            return [
                'valid' => true,
                'client' => $clientByDomain,
                'confirmation_email' => $confirmationEmail,
                'email_match' => $submittedAuthorized,
                'submitted_email_authorized' => $submittedAuthorized,
            ];
        }

        return ['valid' => false, 'message' => 'We could not verify your website information. Please contact our support team.'];
    }

    private function validatePayload(array $payload): array
    {
        $errors = [];
        $email = strtolower(Security::sanitizeString((string) ($payload['email'] ?? '')));
        $serviceType = Security::sanitizeString((string) ($payload['service_type'] ?? 'Website'));
        $selectedDomain = Validator::normalizeDomainInput((string) ($payload['selected_domain'] ?? ''));
        $websiteUrl = Security::sanitizeString((string) ($payload['website_url'] ?? ''));
        $websiteDomain = Validator::normalizeDomainInput($websiteUrl);
        $title = trim((string) ($payload['title'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));

        if (!Validator::email($email)) {
            $errors['email'] = 'Valid email is required.';
        }
        $firstName = Security::sanitizeString((string) ($payload['first_name'] ?? ''));
        $lastName = Security::sanitizeString((string) ($payload['last_name'] ?? ''));
        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if ($serviceType === '') {
            $errors['service_type'] = 'Service type is required.';
        }
        if ($serviceType === 'Website' && $selectedDomain === '' && $websiteDomain === '') {
            $errors['selected_domain'] = 'Please select your website domain.';
        }
        if ($selectedDomain === '' && $websiteDomain !== '') {
            $selectedDomain = $websiteDomain;
        }

        $clientCompany = Security::sanitizeString((string) ($payload['client_company'] ?? ''));

        $issues = $payload['issues'] ?? [];
        $sanitizedIssues = [];
        if ($serviceType === 'Website') {
            if (!is_array($issues) || $issues === []) {
                $errors['issues'] = 'At least one issue is required.';
            }

            foreach ($issues as $index => $issue) {
                $issueType = Security::sanitizeString($issue['issue_type'] ?? '');
                $urgency = Security::sanitizeString($issue['urgency_level'] ?? ($issue['urgency'] ?? ''));
                $urlRaw = Security::sanitizeString($issue['page_url'] ?? ($issue['url'] ?? ''));
                $url = Validator::normalizeUrlInput($urlRaw);
                $description = trim((string) ($issue['description'] ?? ''));
                $changeDetails = trim((string) ($issue['change_details'] ?? ($issue['current_content'] ?? '')));
                $imageDetails = trim((string) ($issue['image_details'] ?? ($issue['new_content'] ?? '')));
                $normalizedDescription = '';
                $currentContent = null;
                $newContent = null;

                if ($issueType === '') {
                    $errors["issues.$index.issue_type"] = 'Issue type is required.';
                } elseif (!in_array($issueType, $this->websiteIssueTypes, true)) {
                    $errors["issues.$index.issue_type"] = 'Invalid issue type.';
                }
                if (!in_array($urgency, $this->urgencyLevels, true)) {
                    $errors["issues.$index.urgency_level"] = 'Invalid urgency level.';
                }
                if (!Validator::url($url)) {
                    $errors["issues.$index.page_url"] = 'Valid URL is required.';
                } elseif ($selectedDomain !== '' && Validator::normalizeDomainInput($url) !== $selectedDomain) {
                    $errors["issues.$index.page_url"] = 'Issue URL must match selected website domain.';
                }

                if ($issueType === 'Content change') {
                    if (strlen($changeDetails) < $this->minDescriptionChars) {
                        $errors["issues.$index.change_details"] = 'Change details are required (minimum 20 characters).';
                    }
                    $normalizedDescription = $changeDetails;
                    $currentContent = $changeDetails !== '' ? $changeDetails : null;
                } elseif ($issueType === 'Image replacement') {
                    if (strlen($imageDetails) < $this->minDescriptionChars) {
                        $errors["issues.$index.image_details"] = 'Image details are required (minimum 20 characters).';
                    }
                    $normalizedDescription = $imageDetails;
                    $newContent = $imageDetails !== '' ? $imageDetails : null;
                } else {
                    if (strlen($description) < $this->minDescriptionChars) {
                        $errors["issues.$index.description"] = 'Description is required (minimum 20 characters).';
                    }
                    $normalizedDescription = $description;
                }

                $sanitizedIssues[] = [
                    'issue_type' => $issueType,
                    'urgency_level' => $urgency,
                    'page_url' => $url,
                    'description' => $normalizedDescription,
                    'current_content' => $currentContent,
                    'new_content' => $newContent,
                    'suggested_issue_type' => $this->categorizationService->suggest($normalizedDescription)['suggested_issue_type'],
                ];
            }
        } else {
            if (strlen($message) < $this->minDescriptionChars) {
                $errors['message'] = 'Message is required (minimum 20 characters).';
            }
            $title = $title !== '' ? $title : substr($message, 0, 120);
            $sanitizedIssues[] = [
                'issue_type' => $serviceType . ' request',
                'urgency_level' => 'Minor issue',
                'page_url' => $websiteUrl !== '' ? $websiteUrl : 'https://service.local/request',
                'description' => $message,
                'current_content' => null,
                'new_content' => null,
                'suggested_issue_type' => $this->categorizationService->suggest($message)['suggested_issue_type'],
            ];
        }

        $metadata = $payload['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata = [
            'browser' => Security::sanitizeString($metadata['browser'] ?? ''),
            'userAgent' => Security::sanitizeString($metadata['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'operatingSystem' => Security::sanitizeString($metadata['operatingSystem'] ?? ''),
            'screenResolution' => Security::sanitizeString($metadata['screenResolution'] ?? ''),
            'language' => Security::sanitizeString($metadata['language'] ?? ''),
            'timezone' => Security::sanitizeString($metadata['timezone'] ?? ''),
        ];

        $sanitized = [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'service_type' => $serviceType,
            'client_company' => $clientCompany,
            'selected_domain' => $selectedDomain,
            'website_url' => $websiteUrl,
            'title' => $title,
            'message' => $message,
            'issues' => $sanitizedIssues,
            'metadata' => $metadata,
            'allow_duplicate' => !empty($payload['allow_duplicate']) ? 1 : 0,
        ];
        return [$sanitized, $errors];
    }

    private function duplicateHash(string $email, string $domain, array $issues): string
    {
        $descriptions = array_map(static fn(array $issue): string => strtolower(trim((string) $issue['description'])), $issues);
        sort($descriptions);
        return hash('sha256', strtolower($email) . '|' . strtolower($domain) . '|' . implode('|', $descriptions));
    }

    private function resolveDomainForHash(array $sanitized): string
    {
        if ($sanitized['service_type'] === 'Website') {
            return Validator::normalizeDomainInput((string) ($sanitized['selected_domain'] ?: $sanitized['website_url']));
        }
        return 'service-' . strtolower($sanitized['service_type']);
    }

    private function validateWebsiteScreenshotRequirements(array $issues, array $filesByIssue): array
    {
        $errors = [];

        foreach ($issues as $index => $issue) {
            $issueType = (string) ($issue['issue_type'] ?? '');
            if ($issueType !== 'Other') {
                continue;
            }

            $files = $filesByIssue[(int) $index] ?? [];
            $validFiles = array_values(array_filter(
                $files,
                static fn(array $file): bool => ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK
            ));

            if (count($validFiles) === 0) {
                $errors["issues.$index.screenshots"] = 'Please upload at least one screenshot for "Other" issues.';
            }
        }

        return $errors;
    }

    private function buildAttachmentRows(int $requestId, array $issueIds, array $websiteFiles, array $serviceFiles, array $websiteIssues): array
    {
        $rows = [];
        $seenHashes = [];
        $retentionDeleteAt = date('Y-m-d H:i:s', strtotime('+3 months'));
        $createdAt = date('Y-m-d H:i:s');

        foreach ($websiteFiles as $file) {
            if (isset($seenHashes[$file['sha256_hash']])) {
                continue;
            }
            $seenHashes[$file['sha256_hash']] = true;
            $issueId = $issueIds[(int) $file['issue_index']] ?? null;
            if (!$issueId) {
                continue;
            }
            $issueType = (string) ($websiteIssues[(int) $file['issue_index']]['issue_type'] ?? '');
            $isImageReplacement = $issueType === 'Image replacement';
            $rows[] = [
                'request_id' => $requestId,
                'issue_id' => $issueId,
                'original_name' => $file['original_name'],
                'stored_name' => $file['stored_name'],
                'mime_type' => $file['mime_type'],
                'extension' => $file['extension'],
                'category' => $isImageReplacement ? 'website_image_replacement_attachment' : 'website_screenshot',
                'temp_path' => $file['temp_path'],
                'file_path' => null,
                'file_size_original' => $file['file_size_original'],
                'file_size_optimized' => null,
                'optimization_status' => 'uploaded_temp',
                'jira_attachment_status' => 'pending',
                'sha256_hash' => $file['sha256_hash'],
                'is_screenshot' => $isImageReplacement ? 0 : 1,
                'retention_delete_at' => $retentionDeleteAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $fallbackIssueId = $issueIds[0] ?? null;
        foreach ($serviceFiles as $file) {
            if (isset($seenHashes[$file['sha256_hash']])) {
                continue;
            }
            $seenHashes[$file['sha256_hash']] = true;
            if (!$fallbackIssueId) {
                continue;
            }
            $rows[] = [
                'request_id' => $requestId,
                'issue_id' => $fallbackIssueId,
                'original_name' => $file['original_name'],
                'stored_name' => $file['stored_name'],
                'mime_type' => $file['mime_type'],
                'extension' => $file['extension'],
                'category' => 'service_attachment',
                'temp_path' => $file['temp_path'],
                'file_path' => null,
                'file_size_original' => $file['file_size_original'],
                'file_size_optimized' => null,
                'optimization_status' => 'uploaded_temp',
                'jira_attachment_status' => 'pending',
                'sha256_hash' => $file['sha256_hash'],
                'is_screenshot' => 0,
                'retention_delete_at' => $retentionDeleteAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        return $rows;
    }

    private function isLocalEnv(): bool
    {
        return $this->shouldBypassWhmcs();
    }

    private function shouldBypassWhmcs(): bool
    {
        // By default, we are always in production unless explicitly overridden in .env
        $env = strtolower((string) Config::get('APP_ENV', 'production'));
        $bypassKeys = ['local', 'development', 'dev', 'testing'];

        if (!in_array($env, $bypassKeys, true)) {
            return false;
        }

        return Config::getBool('WHMCS_BYPASS_LOCAL', false);
    }
}
