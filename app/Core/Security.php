<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Browser-side defences applied to every response (port of the old SecurityHeaders middleware).
 *
 * CSP: scripts may only come from this site or carry the per-request nonce (Security::nonce(), used by the one inline
 * <script> in the layouts), so an injected <script> or inline event handler cannot run. 'unsafe-eval' is required by the
 * standard Alpine.js build in public/assets/abiha/abiha.js (it compiles x-data expressions), exactly as on the old site.
 */
final class Security
{
    /** Extra origins allowed as form-action targets (hosted payment pages), e.g. 'https://pay.example'. */
    public const PAYMENT_FORM_ORIGINS = ['https://getsafepay.com', 'https://sandbox.api.getsafepay.com'];   // Safepay hosted card page

    /** Private/transactional pages: never stored by the browser or a shared cache (back button after logout, proxies). */
    private const NO_STORE = ['#^/admin(/|$)#', '#^/account(/|$)#', '#^/checkout$#', '#^/order/#', '#^/login$#', '#^/register$#',
        '#^/forgot-password$#', '#^/reset-password(/|$)#', '#^/cart$#', '#^/cart/drawer$#'];

    private static ?string $nonce = null;

    /** Per-request CSP nonce for the inline <script> in the layouts. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(18));
    }

    /** Tests: a new request gets a new nonce. */
    public static function resetNonce(): void
    {
        self::$nonce = null;
    }

    public static function csp(): string
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . self::nonce() . "' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "media-src 'self' https:",
            "frame-src https://www.youtube-nocookie.com https://player.vimeo.com",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            'form-action ' . implode(' ', array_merge(["'self'"], self::PAYMENT_FORM_ORIGINS)),
            "base-uri 'self'",
            "object-src 'none'",
        ];
        if (Config::isProduction()) {
            $csp[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $csp);
    }

    public static function headers(Response $response, Request $request): void
    {
        $defaults = [
            'Content-Security-Policy' => self::csp(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isHttps() && Config::isProduction()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach (self::NO_STORE as $re) {
            if (preg_match($re, $request->path)) {
                $defaults['Cache-Control'] = 'no-store, private';
                break;
            }
        }
        // Same decision as the meta robots tag, but also covers non-HTML responses and pages that fail to render.
        if (\App\Support\Seo::indexingDisabled() || \App\Support\Seo::isPrivatePath($request->path)) {
            $defaults['X-Robots-Tag'] = 'noindex, nofollow';
        }
        foreach ($defaults as $k => $v) {
            if (!isset($response->headers[$k])) {
                $response->headers[$k] = $v;
            }
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
