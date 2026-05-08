<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use RuntimeException;

final class LocalStorageService
{
    public function moveToRequestStorage(int $requestId, string $tempPath, string $extension, ?string $forcedTargetName = null, ?string $createdAt = null): string
    {
        $storageRoot = rtrim((string) Config::get('STORAGE_PATH', 'storage'), '/');
        $source = BASE_PATH . '/' . ltrim($tempPath, '/');
        if (!is_file($source)) {
            throw new RuntimeException('Temporary file not found for move.');
        }

        $datePiece = $createdAt ? date('Y/m', strtotime($createdAt)) : date('Y/m');
        $targetDir = BASE_PATH . '/' . $storageRoot . '/requests/' . $datePiece . '/request_' . $requestId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        $normalizedExt = strtolower($extension);
        $targetName = $forcedTargetName ?? (bin2hex(random_bytes(16)) . '.' . $normalizedExt);
        $targetPath = $targetDir . '/' . $targetName;

        if (!@rename($source, $targetPath)) {
            if (!copy($source, $targetPath)) {
                throw new RuntimeException('Unable to move file to permanent request storage.');
            }
            @unlink($source);
        }

        return str_replace(BASE_PATH . '/', '', $targetPath);
    }

    public function deleteFileIfExists(?string $relativePath): bool
    {
        if (!is_string($relativePath) || trim($relativePath) === '') {
            return true;
        }
        $path = BASE_PATH . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }
}
