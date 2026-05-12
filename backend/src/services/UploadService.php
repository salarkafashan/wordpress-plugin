<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use RuntimeException;

final class UploadService
{
    private array $websiteImageAllowed = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    private array $websiteImageAndZipAllowed = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'zip'];
    private array $serviceAllowed = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    public function stageWebsiteIssueFiles(array $filesByIssue, array $issues): array
    {
        $maxPerFile = 1 * 1024 * 1024;
        $staged = [];
        foreach ($issues as $issueIndex => $issue) {
            $files = $filesByIssue[$issueIndex] ?? [];
            $issueType = (string) ($issue['issue_type'] ?? '');
            $validFiles = array_values(array_filter($files, static fn(array $file): bool => ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK));

            if (in_array($issueType, ['Content change', 'Form problem', 'Performance issue', 'Other'], true)) {
                if (count($validFiles) > 2) {
                    throw new RuntimeException('Maximum 2 screenshots per issue are allowed.');
                }
            }

            if (in_array($issueType, ['Image replacement', 'Other'], true) && count($validFiles) === 0) {
                throw new RuntimeException('Screenshots are required for this issue type.');
            }

            foreach ($validFiles as $file) {
                $allowedExtensions = $this->websiteImageAllowed;
                if ($issueType === 'Image replacement') {
                    $allowedExtensions = $this->websiteImageAndZipAllowed;
                } elseif ($issueType === 'Content change') {
                    $allowedExtensions = $this->serviceAllowed;
                }
                $sizeLimit = $issueType === 'Image replacement' ? null : $maxPerFile;
                $meta = $this->validateAndStoreTemp($file, $allowedExtensions, $sizeLimit, 'website_screenshot');
                $meta['issue_index'] = (int) $issueIndex;
                $staged[] = $meta;
            }
        }
        return $staged;
    }

    public function stageNonWebsiteFiles(array $files): array
    {
        $maxTotal = 10 * 1024 * 1024;
        $total = 0;
        $staged = [];
        foreach ($files as $file) {
            if (((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_OK) {
                continue;
            }
            $meta = $this->validateAndStoreTemp($file, $this->serviceAllowed, 10 * 1024 * 1024, 'service_attachment');
            $total += $meta['file_size_original'];
            if ($total > $maxTotal) {
                throw new RuntimeException('Total attachment size exceeds 10MB.');
            }
            $staged[] = $meta;
        }
        return $staged;
    }

    private function validateAndStoreTemp(array $file, array $allowedExtensions, ?int $maxBytes, string $category): array
    {
        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Invalid file type uploaded.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || ($maxBytes !== null && $size > $maxBytes)) {
            throw new RuntimeException('Uploaded file size exceeds allowed limit.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid uploaded file payload.');
        }

        $mimeType = $this->detectMimeType($tmpName);
        if (!$this->isMimeAllowedForExtension($mimeType, $extension)) {
            throw new RuntimeException('Uploaded file mime type does not match extension.');
        }

        $storageRoot = rtrim((string) Config::get('STORAGE_PATH', 'storage'), '/');
        $tempDirectory = BASE_PATH . '/' . $storageRoot . '/temp/' . date('Y/m');
        if (!is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0775, true);
        }

        $storedName = bin2hex(random_bytes(18)) . '.' . $extension;
        $target = $tempDirectory . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Unable to move uploaded file to temporary storage.');
        }

        $hash = hash_file('sha256', $target) ?: '';
        if ($hash === '') {
            throw new RuntimeException('Unable to hash uploaded file.');
        }

        return [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'category' => $category,
            'temp_path' => str_replace(BASE_PATH . '/', '', $target),
            'file_size_original' => filesize($target) ?: $size,
            'sha256_hash' => $hash,
        ];
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $path) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    private function isMimeAllowedForExtension(string $mimeType, string $extension): bool
    {
        $allowed = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'avif' => ['image/avif'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        ];
        return isset($allowed[$extension]) && in_array($mimeType, $allowed[$extension], true);
    }
}
