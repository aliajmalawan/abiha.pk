<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for storefront data that is expensive to build but changes only when the admin (or an order)
 * changes the catalogue. Every key includes a version number; any save/delete of catalogue data bumps it
 * (see AppServiceProvider), which instantly orphans every cached entry. The TTL is only a safety net.
 *
 * Deliberately NOT cached: carts, wishlists, prices at checkout, anything per-customer. Money is always recalculated
 * from the database on the server.
 */
class CatalogCache
{
    public static function version(): string
    {
        return (string) Cache::rememberForever('catalog.version', fn () => self::stamp());
    }

    public static function bump(): void
    {
        Cache::forever('catalog.version', self::stamp());
    }

    public static function remember(string $key, int $ttlSeconds, Closure $build): mixed
    {
        return Cache::remember('catalog.'.self::version().'.'.$key, $ttlSeconds, $build);
    }

    private static function stamp(): string
    {
        return str_replace('.', '', (string) microtime(true)).random_int(10, 99);
    }
}
