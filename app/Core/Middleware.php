<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * Route middleware (port of the old Laravel stack):
 *
 *   (every unsafe request)  CSRF check for POST/PUT/PATCH/DELETE unless the route has 'nocsrf'.
 *   auth         signed in (Auth::user() already drops disabled/deleted accounts and sessions whose password changed).
 *                Guests are sent to /admin/login for /admin URLs, otherwise to /login; a GET remembers the URL as "intended".
 *   guest        only when signed out; signed-in staff go to /admin, customers to /account.
 *   staff        signed in + a staff role (roles.is_staff = 1), else 403 "You do not have access to the admin area."
 *                Also the admin idle timeout (cfg('abiha.admin_idle_minutes'), default 30): an unattended admin session is
 *                signed out and sent to /admin/login with an "email" error.
 *   perm:<slug>  staff + the role holds the permission (Auth::can), else 403. Implies auth + staff.
 *   throttle:<name>  a named rate limiter from RateLimiter::LIMITS (e.g. throttle:login, throttle:cart-add).
 *   nocsrf       skip the CSRF check (signed provider webhooks only).
 *
 * Order matters like in Laravel: list 'auth' before 'perm:x' (perm implies auth + staff anyway).
 */
final class Middleware
{
    /** @param list<string> $names */
    public static function run(array $names, Request $request): void
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !in_array('nocsrf', $names, true)) {
            Csrf::verify($request);
        }
        $staffChecked = false;
        foreach ($names as $name) {
            [$kind, $arg] = array_pad(explode(':', $name, 2), 2, null);
            switch ($kind) {
                case 'auth':
                    self::auth($request);
                    break;
                case 'guest':
                    self::guest();
                    break;
                case 'staff':
                    if (!$staffChecked) {
                        self::staff($request);
                        $staffChecked = true;
                    }
                    break;
                case 'perm':
                    if (!$staffChecked) {
                        self::staff($request);
                        $staffChecked = true;
                    }
                    if (!Auth::can((string) $arg)) {
                        throw new HttpException(403, 'You do not have permission to do that.');
                    }
                    break;
                case 'throttle':
                    RateLimiter::throttle((string) $arg, $request);
                    break;
                case 'nocsrf':
                    break;
                default:
                    throw new \LogicException("Unknown middleware {$name}");
            }
        }
    }

    public static function isAdminPath(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private static function auth(Request $request): void
    {
        if (Auth::check()) {
            return;
        }
        if ($request->method === 'GET' && !$request->wantsJson()) {
            Session::put('intended', $request->path . ($request->query ? '?' . http_build_query($request->query) : ''));
        }
        if ($request->wantsJson()) {
            throw new HttpException(401, 'Please sign in to continue.');
        }
        throw new RedirectException(self::isAdminPath($request->path) ? '/admin/login' : '/login');
    }

    private static function guest(): void
    {
        if (Auth::check()) {
            throw new RedirectException(Auth::isStaff() ? '/admin' : '/account');
        }
    }

    private static function staff(Request $request): void
    {
        self::auth($request);
        if (!Auth::isStaff()) {
            throw new HttpException(403, 'You do not have access to the admin area.');
        }
        // Idle timeout: a laptop left open on a shared desk must not stay signed in to the back office.
        $minutes = (int) Config::get('abiha.admin_idle_minutes', 30);
        $last = (int) Session::get('admin_last_activity', 0);
        if ($last > 0 && time() - $last > $minutes * 60) {
            Auth::logout();
            Session::flash('errors', ['email' => 'You were signed out after ' . $minutes . ' minutes of inactivity. Please sign in again.']);
            if ($request->method === 'GET') {
                Session::put('intended', $request->path);
            }
            throw new RedirectException('/admin/login');
        }
        Session::put('admin_last_activity', time());
    }
}
