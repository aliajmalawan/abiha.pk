<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection plus an Origin/Referer same-origin check.
 */
final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::put('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $request): void
    {
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            $expected = parse_url((string) Config::get('url'));
            $given = parse_url($origin);
            $sameHost = ($given['host'] ?? '') === ($expected['host'] ?? '')
                && ($given['scheme'] ?? '') === ($expected['scheme'] ?? '')
                && ($given['port'] ?? null) === ($expected['port'] ?? null);
            if (!$sameHost) {
                throw new HttpException(403, 'Cross-site request blocked.');
            }
        }
        $sent = (string) ($request->post['_token'] ?? $request->header('X-CSRF-Token') ?? '');
        $known = Session::get('_csrf');
        if (!is_string($known) || $sent === '' || !hash_equals($known, $sent)) {
            throw new HttpException(419);
        }
    }
}
