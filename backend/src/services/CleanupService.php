<?php

declare(strict_types=1);

namespace App\services;

use App\helpers\Logger;
use App\models\IssueAttachmentModel;
use App\models\SupportRequestModel;
use Throwable;

final class CleanupService
{
    private SupportRequestModel $requestModel;
    private IssueAttachmentModel $attachmentModel;

    public function __construct()
    {
        $this->requestModel = new SupportRequestModel();
        $this->attachmentModel = new IssueAttachmentModel();
    }

    public function purgeOldTerminalRequests(int $months = 8, int $limit = 200): array
    {
        $requests = $this->requestModel->oldTerminalRequests($months, $limit);
        $deletedRequests = 0;
        $deletedFiles = 0;
        $failedFiles = 0;

        foreach ($requests as $request) {
            $requestId = (int) $request['id'];
            $attachments = $this->attachmentModel->findByRequestId($requestId);
            $seen = [];
            foreach ($attachments as $attachment) {
                $paths = array_filter([
                    (string) ($attachment['file_path'] ?? ''),
                    (string) ($attachment['temp_path'] ?? ''),
                ]);
                foreach ($paths as $relativePath) {
                    if ($relativePath === '' || isset($seen[$relativePath])) {
                        continue;
                    }
                    $seen[$relativePath] = true;

                    $absolutePath = BASE_PATH . '/' . ltrim($relativePath, '/');
                    if (!is_file($absolutePath)) {
                        continue;
                    }
                    try {
                        if (@unlink($absolutePath)) {
                            $deletedFiles++;
                        } else {
                            $failedFiles++;
                            Logger::error('Failed to remove attachment file during cleanup', [
                                'request_id' => $requestId,
                                'file_path' => $relativePath,
                            ]);
                        }
                    } catch (Throwable $exception) {
                        $failedFiles++;
                        Logger::error('Cleanup attachment deletion exception', [
                            'request_id' => $requestId,
                            'file_path' => $relativePath,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            $this->requestModel->deleteById($requestId);
            $deletedRequests++;
        }

        return [
            'evaluated_requests' => count($requests),
            'deleted_requests' => $deletedRequests,
            'deleted_files' => $deletedFiles,
            'failed_file_deletions' => $failedFiles,
        ];
    }
}
