<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Real environment variables take precedence over the file.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                // Strip trailing inline comments on unquoted values.
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return self::$values[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
