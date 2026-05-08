<?php

declare(strict_types=1);

namespace App\services;

use App\helpers\Logger;
use RuntimeException;

final class ImageOptimizationService
{
    public function optimize(string $relativeTempPath): array
    {
        $source = BASE_PATH . '/' . ltrim($relativeTempPath, '/');
        if (!is_file($source)) {
            throw new RuntimeException('Image source file not found.');
        }

        $mimeType = $this->detectMimeType($source);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [
                'optimized_path' => $relativeTempPath,
                'mime_type' => $mimeType,
                'extension' => strtolower(pathinfo($source, PATHINFO_EXTENSION)),
                'size_original' => filesize($source) ?: 0,
                'size_optimized' => filesize($source) ?: 0,
                'compression_ratio' => 0.0,
                'optimizer' => 'skipped_non_image',
            ];
        }

        $sizeOriginal = filesize($source) ?: 0;
        $targetPath = preg_replace('/\.[a-z0-9]+$/i', '.webp', $source) ?: ($source . '.webp');
        $optimized = false;
        $optimizer = 'fallback_copy';

        if (class_exists('Imagick')) {
            $optimized = $this->optimizeWithImagick($source, $targetPath);
            $optimizer = $optimized ? 'imagick' : 'imagick_failed';
        }

        if (!$optimized && function_exists('imagecreatefromjpeg')) {
            $optimized = $this->optimizeWithGd($source, $mimeType, $targetPath);
            $optimizer = $optimized ? 'gd' : 'gd_failed';
        }

        if (!$optimized) {
            $targetPath = $source;
        } elseif ($targetPath !== $source && is_file($source)) {
            @unlink($source);
        }

        $sizeOptimized = filesize($targetPath) ?: $sizeOriginal;
        $ratio = $sizeOriginal > 0 ? round((1 - ($sizeOptimized / $sizeOriginal)) * 100, 2) : 0.0;
        Logger::info('Image optimization completed', [
            'source' => str_replace(BASE_PATH . '/', '', $source),
            'target' => str_replace(BASE_PATH . '/', '', $targetPath),
            'size_original' => $sizeOriginal,
            'size_optimized' => $sizeOptimized,
            'compression_ratio_percent' => $ratio,
            'optimizer' => $optimizer,
        ]);

        return [
            'optimized_path' => str_replace(BASE_PATH . '/', '', $targetPath),
            'mime_type' => $this->detectMimeType($targetPath),
            'extension' => strtolower(pathinfo($targetPath, PATHINFO_EXTENSION)),
            'size_original' => $sizeOriginal,
            'size_optimized' => $sizeOptimized,
            'compression_ratio' => $ratio,
            'optimizer' => $optimizer,
        ];
    }

    private function optimizeWithImagick(string $source, string $targetPath): bool
    {
        try {
            $imagick = new \Imagick($source);
            if (method_exists($imagick, 'autoOrient')) {
                $imagick->autoOrient();
            }
            $imagick->stripImage();
            $imagick->setImageCompressionQuality(72);
            $imagick->setImageFormat('webp');
            $imagick->writeImage($targetPath);
            $imagick->clear();
            $imagick->destroy();
            return is_file($targetPath);
        } catch (\Throwable $exception) {
            Logger::error('Imagick optimization failed', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    private function optimizeWithGd(string $source, string $mimeType, string $targetPath): bool
    {
        try {
            if ($mimeType === 'image/jpeg') {
                $image = @imagecreatefromjpeg($source);
            } elseif ($mimeType === 'image/png') {
                $image = @imagecreatefrompng($source);
            } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($source);
            } else {
                return false;
            }

            if (!$image) {
                return false;
            }
            if (!function_exists('imagewebp')) {
                return false;
            }
            imagewebp($image, $targetPath, 72);
            imagedestroy($image);
            return is_file($targetPath);
        } catch (\Throwable $exception) {
            Logger::error('GD optimization failed', ['error' => $exception->getMessage()]);
            return false;
        }
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
}
