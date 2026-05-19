<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Support;

final class Encryption
{
    private const METHOD = 'aes-256-gcm';
    private const PREFIX = 'sk1$';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            // Legacy unencrypted value — return as-is (and the settings page will re-encrypt on next save).
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Encrypted secret is corrupt');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }

    private static function key(): string
    {
        $source = (defined('AUTH_KEY') && AUTH_KEY !== '') ? AUTH_KEY : wp_salt('auth');
        return hash('sha256', 'skwirrel-gavilar|' . $source, true);
    }
}
