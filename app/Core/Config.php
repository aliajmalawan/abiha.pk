<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration with dot access: Config::get('abiha.name'), Config::get('db.host'), cfg('payments.enabled').
 *
 *  - Runtime settings (URL, database, mail, session, storage) come from .env (see .env.example) and are built below.
 *  - Business defaults live in app/Config/<name>.php (plain arrays, the port of the old config/*.php files) and are
 *    available under their file name: abiha.*, payments.*, shipping.*, faq.*.
 *  - Admin-edited values (site name, currency symbol, mail sender) are applied on top by App\Support\SiteSettings::apply().
 *
 * Production refuses to boot with missing or placeholder secrets, so a misconfigured deploy fails loudly
 * instead of running insecurely.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $c = [];

    public static function boot(): void
    {
        $env = (string) Env::get('APP_ENV', 'production');
        $url = rtrim((string) Env::get('APP_URL', 'http://localhost/abiha.pk'), '/');
        $parts = parse_url($url) ?: [];

        self::$c = [
            'env' => $env,
            'debug' => $env !== 'production' && self::bool(Env::get('APP_DEBUG', 'false')),
            'url' => $url,
            'base_path' => rtrim($parts['path'] ?? '', '/'),
            'https' => ($parts['scheme'] ?? 'http') === 'https',
            'key' => (string) Env::get('APP_KEY', ''),
            'timezone' => (string) Env::get('BUSINESS_TIMEZONE', 'Asia/Karachi'),
            'trusted_proxy_hops' => (int) Env::get('TRUSTED_PROXY_HOPS', '0'),
            'db' => [
                'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
                'port' => (int) Env::get('DB_PORT', '3306'),
                'name' => (string) Env::get('DB_DATABASE', 'abiha_pk'),
                'user' => (string) Env::get('DB_USERNAME', 'root'),
                'pass' => (string) Env::get('DB_PASSWORD', ''),
            ],
            'mail' => [
                'driver' => (string) Env::get('MAIL_DRIVER', 'outbox'),
                'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
                'from_name' => (string) Env::get('MAIL_FROM_NAME', 'ABIHA'),
                'admin_notify' => (string) Env::get('MAIL_ADMIN_NOTIFY', ''),
                'smtp_host' => (string) Env::get('SMTP_HOST', ''),
                'smtp_port' => (int) Env::get('SMTP_PORT', '587'),
                'smtp_encryption' => (string) Env::get('SMTP_ENCRYPTION', 'tls'),
                'smtp_user' => (string) Env::get('SMTP_USERNAME', ''),
                'smtp_pass' => (string) Env::get('SMTP_PASSWORD', ''),
            ],
            'session' => [
                // Idle lifetime in minutes (Laravel's SESSION_LIFETIME, default 120).
                'lifetime' => max(5, (int) Env::get('SESSION_LIFETIME', '120')),
                'cookie' => 'abiha_session',
            ],
            'filesystems' => [
                // The "public" disk: uploads (products/, banners/, branding/, home/). Default storage/app/public, exposed at
                // public/storage (symlink or junction). Hosts without symlinks: PUBLIC_DISK_ROOT = a folder inside the web root.
                'public_root' => rtrim((string) (Env::get('PUBLIC_DISK_ROOT') ?: STORAGE_PATH . '/app/public'), '/\\'),
                'public_url' => rtrim((string) (Env::get('PUBLIC_DISK_URL') ?: $url . '/storage'), '/'),
                // The "local" (private) disk: payment proofs etc. Never served directly.
                'private_root' => STORAGE_PATH . '/app/private',
            ],
            'uploads' => [
                'max_image_bytes' => (int) Env::get('UPLOAD_MAX_IMAGE_BYTES', (string) (8 * 1024 * 1024)),
                'max_proof_bytes' => (int) Env::get('UPLOAD_MAX_PROOF_BYTES', (string) (5 * 1024 * 1024)),
            ],
        ];

        foreach (glob(APP_PATH . '/Config/*.php') ?: [] as $file) {
            $data = require $file;
            if (is_array($data)) {
                self::$c[basename($file, '.php')] = $data;
            }
        }

        if ($env === 'production') {
            $problems = self::productionProblems();
            if ($problems) {
                error_log('[config] ' . implode(' ', $problems));
                if (PHP_SAPI !== 'cli') {
                    http_response_code(500);
                }
                exit("Configuration error. See server log.\n");
            }
        }
    }

    /**
     * What is unsafe about this configuration for a live site (empty list = fine). Also used by `console.php preflight`.
     *
     * @return list<string>
     */
    public static function productionProblems(): array
    {
        $p = [];
        $key = (string) self::$c['key'];
        if (strlen($key) < 32 || str_contains($key, 'CHANGE_ME')) {
            $p[] = 'APP_KEY must be a random value of at least 32 characters (php bin/console.php setup generates one).';
        }
        if (!self::$c['https']) {
            $p[] = 'APP_URL must use https:// in production.';
        }
        if (self::$c['db']['user'] === 'root' || self::$c['db']['user'] === '' || str_contains(self::$c['db']['user'], 'CHANGE_ME')) {
            $p[] = 'DB_USERNAME must be a least-privilege database user, not root.';
        }
        if (self::$c['db']['pass'] === '' || str_contains(self::$c['db']['pass'], 'CHANGE_ME')) {
            $p[] = 'DB_PASSWORD must be set.';
        }
        if (self::$c['mail']['driver'] !== 'smtp') {
            $p[] = 'MAIL_DRIVER must be smtp in production (password-reset and order emails must be delivered).';
        }
        $enabled = (array) (self::$c['payments']['enabled'] ?? []);
        if (in_array('card', $enabled, true) && strlen((string) (self::$c['payments']['card']['webhook_secret'] ?? '')) < 16) {
            $p[] = 'Card payments are enabled but CARD_WEBHOOK_SECRET is missing or too short.';
        }
        return $p;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$c;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, "\0missing") !== "\0missing";
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$c;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = $ref[$segment] ?? [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function isProduction(): bool
    {
        return self::$c['env'] === 'production';
    }

    public static function bool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
