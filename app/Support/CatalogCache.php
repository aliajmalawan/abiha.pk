<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Short-lived file cache for storefront data that is expensive to build but changes only when the admin (or an order)
 * changes the catalogue (navigation categories, home sections, listings...). Port of the old App\Support\CatalogCache.
 *
 *   $tree = CatalogCache::remember('nav.categories', 600, fn () => ...);   // value must be var_export-able (arrays/scalars)
 *   CatalogCache::bump();      // after ANY save/delete of products, variants, images, categories, collections, banners,
 *                              // home sections, testimonials, pages or reviews (the old model "saved/deleted" hooks)
 *
 * Every key includes a version number; bump() changes it, which instantly orphans every cached entry. The TTL is only a
 * safety net. Files live in storage/cache/catalog-<database>/, so the test database never shares entries with the site.
 * Deliberately NOT cached: carts, wishlists, prices at checkout, anything per customer.
 */
final class CatalogCache
{
    private static ?string $version = null;
    /** @var array<string,mixed> in-process copies for this request */
    private static array $memo = [];
    public static bool $enabled = true;

    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }
        $file = self::dir() . '/version';
        $v = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if ($v === '') {
            $v = self::stamp();
            self::write($file, $v);
        }
        return self::$version = $v;
    }

    public static function bump(): void
    {
        self::$version = self::stamp();
        self::$memo = [];
        self::write(self::dir() . '/version', self::$version);
        // Remove stale entry files (the version file stays).
        foreach (glob(self::dir() . '/*.cache.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    public static function remember(string $key, int $ttlSeconds, callable $build): mixed
    {
        if (!self::$enabled) {
            return $build();
        }
        $full = self::version() . '.' . $key;
        if (array_key_exists($full, self::$memo)) {
            return self::$memo[$full];
        }
        $file = self::dir() . '/' . sha1($full) . '.cache.php';
        if (is_file($file)) {
            $data = @include $file;
            if (is_array($data) && ($data['expires'] ?? 0) >= time() && ($data['key'] ?? null) === $full) {
                return self::$memo[$full] = $data['value'];
            }
        }
        $value = $build();
        self::write($file, '<?php return ' . var_export(['key' => $full, 'expires' => time() + $ttlSeconds, 'value' => $value], true) . ";\n");
        return self::$memo[$full] = $value;
    }

    /** Tests: forget the in-process state (the files are per database). */
    public static function reset(): void
    {
        self::$version = null;
        self::$memo = [];
    }

    private static function dir(): string
    {
        return STORAGE_PATH . '/cache/catalog-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) Config::get('db.name'));
    }

    private static function stamp(): string
    {
        return str_replace('.', '', (string) microtime(true)) . random_int(10, 99);
    }

    private static function write(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
