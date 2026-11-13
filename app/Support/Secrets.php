<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Encrypts small secrets (payment provider keys) before they go into the database: AES-256-GCM, key derived from APP_KEY.
 * Anyone who copies only the database cannot read them. If APP_KEY changes the old values cannot be read any more and
 * decrypt() returns '' (the admin simply enters the keys again).
 */
final class Secrets
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the value.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored): string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return hash('sha256', 'abiha-secrets|' . (string) Config::get('key'), true);
    }
}
