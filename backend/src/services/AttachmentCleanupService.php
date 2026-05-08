<?php

declare(strict_types=1);

namespace App\services;

use App\models\IssueAttachmentModel;

final class AttachmentCleanupService
{
    private IssueAttachmentModel $attachmentModel;
    private QueueService $queueService;

    public function __construct()
    {
        $this->attachmentModel = new IssueAttachmentModel();
        $this->queueService = new QueueService();
    }

    public function queueExpiredCleanupJobs(int $limit = 300): array
    {
        $attachments = $this->attachmentModel->expiredForCleanup(date('Y-m-d H:i:s'), $limit);
        $queued = 0;
        foreach ($attachments as $attachment) {
            $this->queueService->enqueue(
                'cleanup_expired_local_file',
                (int) $attachment['request_id'],
                ['attachment_id' => (int) $attachment['id']],
                0,
                3
            );
            $queued++;
        }
        return ['expired_found' => count($attachments), 'cleanup_jobs_queued' => $queued];
    }
}
