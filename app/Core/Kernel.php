<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * One HTTP request, start to finish. Used by public/index.php and by the in-process HTTP tests, so tests exercise
 * exactly what the browser gets (global middleware, router, error pages, security headers).
 *
 * Global ("web" group) behaviour, as in the old bootstrap/app.php:
 *  - session start, admin-edited site settings applied (SiteSettings::apply)
 *  - a signed-in account that was disabled or deleted is signed out and sent to /login with
 *    "This account has been disabled. Please contact us if you think this is a mistake." (EnsureAccountActive)
 *  - broad per-visitor rate limit "web-general" (240/min)
 *  - security headers on every response (Security::headers)
 */
final class Kernel
{
    /** @var list<callable(Router):void> Extra route definitions (tests only). Run before app/routes.php. */
    public static array $extraRoutes = [];

    public static function handle(Request $request): Response
    {
        Security::resetNonce();
        View::reset();
        \App\Support\Seo::reset();
        View::share('currentPath', $request->path);
        View::share('request', $request);
        // Routes first, so error pages can always build links (route('home'), route('shop')...).
        $router = new Router();
        foreach (self::$extraRoutes as $define) {
            $define($router);
        }
        require APP_PATH . '/routes.php';
        try {
            Session::start($request);
            \App\Support\SiteSettings::apply();

            if (Auth::user() === null && Auth::wasDisabled()) {
                Session::flash('errors', ['email' => 'This account has been disabled. Please contact us if you think this is a mistake.']);
                throw new RedirectException('/login');
            }
            if (PHP_SAPI !== 'cli') {
                RateLimiter::throttle('web-general', $request);
            }

            $response = $router->dispatch($request);
        } catch (\Throwable $e) {
            $response = ErrorHandler::toResponse($e, $request);
        }
        Security::headers($response, $request);
        return $response;
    }
}
