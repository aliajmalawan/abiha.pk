<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter persisted in MySQL (table rate_limits), so limits apply across PHP workers.
 *
 * Named limiters (the old AppServiceProvider::defineRateLimiters, one counter PER ACTION, never shared):
 *   route middleware 'throttle:login'  or  RateLimiter::throttle('login', $request)
 * Keyed by the signed-in user when there is one (so one noisy account cannot lock out a shared IP), otherwise by IP,
 * exactly as before. Exceeding a limit throws HttpException 429 with Retry-After.
 *
 * Low-level API for custom keys (e.g. the per email+IP login lockout):
 *   RateLimiter::tooMany($key, 5, 60), RateLimiter::hit($key, 60), RateLimiter::availableIn($key, 60), RateLimiter::clear($key, 60)
 */
final class RateLimiter
{
    /** name => [max per minute, 'user' (user id or IP) | 'ip'] */
    public const LIMITS = [
        'web-general' => [240, 'user'],
        'account' => [60, 'user'],
        'cart-write' => [120, 'user'],
        'search' => [60, 'user'],
        'password-reset' => [5, 'ip'],
        'webhooks' => [120, 'ip'],
        'login' => [10, 'ip'],
        'admin-login' => [10, 'ip'],
        'register' => [10, 'ip'],
        'forgot-password' => [5, 'ip'],
        'contact' => [5, 'ip'],
        'newsletter' => [5, 'ip'],
        'track-order' => [10, 'ip'],
        'checkout' => [10, 'user'],
        'coupon' => [10, 'user'],
        'cart-add' => [60, 'user'],
        'review' => [6, 'user'],
        'payment-reference' => [10, 'user'],
    ];

    /** Applies a named limiter to this request (throws 429 when exceeded). */
    public static function throttle(string $name, Request $request): void
    {
        if (!isset(self::LIMITS[$name])) {
            throw new \LogicException("Unknown rate limiter {$name}");
        }
        [$max, $by] = self::LIMITS[$name];
        $uid = $by === 'user' ? \App\Auth\Auth::id() : null;
        $key = $name . '|' . ($uid ? 'u' . $uid : 'ip' . $request->ip());
        self::enforce($key, $max, 60);
    }

    /** Records a hit and throws 429 when more than $max hits happen in the window. */
    public static function enforce(string $key, int $max, int $windowSeconds): void
    {
        if (self::attempt($key, $max, $windowSeconds) === false) {
            $retry = self::availableIn($key, $windowSeconds);
            throw new HttpException(429, 'Too Many Attempts.', ['Retry-After' => (string) $retry]);
        }
    }

    /** Records a hit; false when the limit is now exceeded. */
    public static function attempt(string $key, int $max, int $windowSeconds): bool
    {
        return self::hit($key, $windowSeconds) <= $max;
    }

    /** Records a hit and returns the number of hits in the current window. */
    public static function hit(string $key, int $windowSeconds = 60): int
    {
        $db = Database::get();
        $window = self::window($windowSeconds);
        $b = self::bucket($key, $windowSeconds);
        $db->exec('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$b, $window]);
        if (random_int(1, 200) === 1) {
            $db->exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400 * 2]);
        }
        return (int) $db->value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$b, $window]);
    }

    public static function attempts(string $key, int $windowSeconds = 60): int
    {
        return (int) Database::get()->value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [self::bucket($key, $windowSeconds), self::window($windowSeconds)]);
    }

    public static function tooMany(string $key, int $max, int $windowSeconds = 60): bool
    {
        return self::attempts($key, $windowSeconds) >= $max;
    }

    /** Seconds until the current window ends. */
    public static function availableIn(string $key, int $windowSeconds = 60): int
    {
        return max(1, self::window($windowSeconds) + $windowSeconds - time());
    }

    public static function clear(string $key, int $windowSeconds = 60): void
    {
        Database::get()->exec('DELETE FROM rate_limits WHERE bucket = ?', [self::bucket($key, $windowSeconds)]);
    }

    private static function window(int $windowSeconds): int
    {
        return intdiv(time(), $windowSeconds) * $windowSeconds;
    }

    private static function bucket(string $key, int $windowSeconds): string
    {
        return substr(hash('sha256', $key), 0, 40) . ':' . $windowSeconds;
    }
}
