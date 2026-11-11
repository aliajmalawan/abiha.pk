<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defences, applied to every web response.
 *
 * CSP: scripts may only come from this site or carry the per-request nonce, so an injected <script> or inline
 * event handler cannot run. 'unsafe-eval' is required by the standard Alpine.js build (it compiles x-data
 * expressions); the strict alternative is Alpine's CSP build, which needs every expression refactored.
 * Skipped while the Vite dev server (public/hot) is running, because HMR injects inline code.
 */
class SecurityHeaders
{
    /** Pages that must never be stored by the browser or a shared cache (back button after logout, proxies). */
    private const NO_STORE = ['admin', 'admin/*', 'account', 'account/*', 'checkout', 'order/*', 'login', 'register', 'forgot-password', 'reset-password/*', 'cart', 'cart/drawer'];

    public function handle(Request $request, Closure $next): Response
    {
        app(\App\Support\Seo::class)->reset();
        $nonce = Vite::useCspNonce();                 // also stamps the nonce onto @vite script/style tags

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! file_exists(public_path('hot'))) {
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-ancestors 'self'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ];
            if (app()->isProduction()) {
                $csp[] = 'upgrade-insecure-requests';
            }
            $headers->set('Content-Security-Policy', implode('; ', $csp));
        }

        // Tell browsers to use HTTPS only, and only once we are actually being served over it.
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is(...self::NO_STORE)) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        // Same decision as the meta robots tag, but also covers non-HTML responses (and pages that fail to render).
        if (\App\Support\Seo::indexingDisabled() || \App\Support\Seo::isPrivatePath($request)) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
