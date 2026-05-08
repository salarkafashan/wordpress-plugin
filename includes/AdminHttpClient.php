<?php
/**
 * Admin HTTP helpers.
 *
 * @package SupportRequestFrontend
 */

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminHttpClient
{
    public static function postJson(string $url, array $headers, string $rawBody): array
    {
        if (!function_exists('curl_init')) {
            return [0, 'cURL is unavailable'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') {
            return [0, $error !== '' ? $error : 'Unknown transport error'];
        }
        return [$status, (string) $body];
    }

    public static function postForm(string $url, array $data): array
    {
        if (!function_exists('curl_init')) {
            return [0, 'cURL is unavailable'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') {
            return [0, $error !== '' ? $error : 'Unknown transport error'];
        }
        return [$status, (string) $body];
    }
}
