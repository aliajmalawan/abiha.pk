<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON-lines logger with automatic redaction of secrets and personal data.
 */
final class Logger
{
    private const SENSITIVE_KEYS = '/pass(word)?|secret|token|api[_-]?key|authorization|cookie|signature|card|cvv|cvc|pan|otp|hash|session/i';

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEYS, $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null);
            }
            return $out;
        }
        if (is_string($value)) {
            // Card-number-like digit runs and bearer tokens.
            $value = (string) preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[redacted-number]', $value);
            $value = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[redacted]', $value);
            if ($key !== null && preg_match('/email/i', $key)) {
                $value = self::maskEmail($value);
            }
        }
        return $value;
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'msg' => self::redact($message),
            'ctx' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
