<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened native PHP session: strict mode, HttpOnly + SameSite=Lax cookie,
 * Secure under HTTPS, files in storage/sessions (outside the web root), ID rotation on privilege change.
 * In the CLI (tests, console) an in-memory array is used instead.
 *
 * Flash data: Session::flash('status', 'Saved.') is readable on the NEXT request via Session::getFlash('status')
 * (or the flash()/old()/errors helpers); Session::flashNow() is readable in the current request.
 */
final class Session
{
    private static bool $started = false;
    /** @var array<string,mixed> In-memory store used by CLI/tests. */
    private static array $memory = [];

    public static function start(Request $request): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }
        $dir = STORAGE_PATH . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        ini_set('session.save_path', $dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        $idle = 60 * (int) Config::get('session.lifetime', 120);
        ini_set('session.gc_maxlifetime', (string) $idle);
        session_name((string) Config::get('session.cookie', 'abiha_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Config::get('base_path') ?: '') . '/',
            'secure' => $request->isHttps() || Config::isProduction(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        // Idle timeout (SESSION_LIFETIME minutes without a request), like Laravel's session lifetime.
        $now = time();
        if (isset($_SESSION['_last']) && $now - (int) $_SESSION['_last'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;

        self::ageFlash();
    }

    /** Start of a request: last request's flash becomes readable, older flash is dropped. (Tests call it between requests.) */
    public static function ageFlash(): void
    {
        $s = &self::store();
        $s['_flash_old'] = $s['_flash_new'] ?? [];
        $s['_flash_new'] = [];
    }

    private static function &store(): array
    {
        if (PHP_SAPI === 'cli') {
            return self::$memory;
        }
        return $_SESSION;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $s = &self::store();
        $s[$key] = $value;
    }

    public static function forget(string $key): void
    {
        $s = &self::store();
        unset($s[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = self::get($key, $default);
        self::forget($key);
        return $v;
    }

    /** New session id, keeping data. Call on login/logout/privilege change. */
    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::put('_csrf', null);
    }

    public static function destroy(): void
    {
        $s = &self::store();
        $s = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $s = &self::store();
        $s['_flash_new'][$key] = $value;
    }

    /** Flash visible only to the current request (render without redirect). */
    public static function flashNow(string $key, mixed $value): void
    {
        $s = &self::store();
        $s['_flash_old'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return self::store()['_flash_old'][$key] ?? $default;
    }

    public static function reflash(): void
    {
        $s = &self::store();
        $s['_flash_new'] = array_merge($s['_flash_old'] ?? [], $s['_flash_new'] ?? []);
    }

    public static function resetMemory(): void
    {
        self::$memory = [];
    }
}
