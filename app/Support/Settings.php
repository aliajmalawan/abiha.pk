<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/**
 * Key/value site settings editable from the admin dashboard (table `settings`, value = JSON), the port of the old
 * App\Models\Setting:
 *
 *   Settings::get('contact.phone', 'default')   a stored null counts as "not set" and returns the default
 *   Settings::put('contact.phone', '+92 ...')   any JSON-encodable value; clears the caches
 *   Settings::forget('key'), Settings::all()
 *
 * All rows are loaded once per request (in-process) and cached in a PHP file under storage/cache/ named after the
 * database, so the test database never shares a cache with the real site. put()/forget() rewrite the database row and
 * drop both caches. Safe when the table does not exist yet (fresh install): every get() returns its default.
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $all = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $file = self::cacheFile();
        if (is_file($file)) {
            $data = @include $file;
            if (is_array($data)) {
                return self::$all = $data;
            }
        }
        try {
            $rows = Database::get()->all('SELECT `key`, `value` FROM settings');
        } catch (\Throwable) {
            return self::$all = [];                     // table not migrated yet / database down: defaults everywhere
        }
        $all = [];
        foreach ($rows as $r) {
            $all[(string) $r['key']] = $r['value'] === null ? null : json_decode((string) $r['value'], true);
        }
        self::writeCache($all);
        return self::$all = $all;
    }

    public static function put(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Database::get()->exec(
            'INSERT INTO settings (`key`, `value`, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $json, now(), now()]
        );
        self::flush();
    }

    /** @param array<string,mixed> $values */
    public static function putMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::put((string) $k, $v);
        }
    }

    public static function forget(string $key): void
    {
        Database::get()->exec('DELETE FROM settings WHERE `key` = ?', [$key]);
        self::flush();
    }

    /** Drops the in-process and the file cache (next get() reads the database). */
    public static function flush(): void
    {
        self::$all = null;
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /** In-process cache only (tests switch databases). */
    public static function forgetMemory(): void
    {
        self::$all = null;
    }

    private static function cacheFile(): string
    {
        $db = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) \App\Core\Config::get('db.name'));
        return STORAGE_PATH . '/cache/settings-' . $db . '.php';
    }

    private static function writeCache(array $all): void
    {
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::cacheFile() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, '<?php return ' . var_export($all, true) . ";\n", LOCK_EX) !== false) {
            @rename($tmp, self::cacheFile());
        }
    }
}
