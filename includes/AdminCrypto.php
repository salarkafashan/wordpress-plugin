<?php
/**
 * Admin crypto helpers.
 *
 * @package SupportRequestFrontend
 */

namespace SupportRequestFrontend\Includes;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminCrypto
{
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_kgr_key';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', hash('sha256', $key, true), 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $data = base64_decode($value);
        if ($data === false) {
            return '';
        }
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        if (!is_int($ivLen) || $ivLen <= 0 || strlen($data) <= $ivLen) {
            return '';
        }
        $iv = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        if ($iv === '' || strlen($iv) !== $ivLen || $encrypted === '') {
            return '';
        }
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_kgr_key';
        return (string) openssl_decrypt($encrypted, 'aes-256-cbc', hash('sha256', $key, true), 0, $iv);
    }
}
