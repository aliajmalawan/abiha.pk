<?php

namespace App\Services\Payments;

/**
 * Helpers every online-payment gateway should use to authenticate a provider's webhook. A webhook endpoint is
 * public, so anyone can POST to it: the ONLY thing that makes a request trustworthy is a valid signature made
 * with a secret only we and the provider know (kept in .env, never in code or the database).
 *
 * Use together with the provider's server-to-server API (re-fetch the transaction and compare amount and status)
 * before returning a VerifiedPayment from OnlinePaymentGateway::handleWebhook().
 */
final class WebhookSignature
{
    /**
     * Verify an HMAC signature over the RAW request body (never over parsed/re-encoded JSON).
     *
     * @param  string  $signature  as sent by the provider: hex or base64, with or without an algo prefix like "sha256="
     * @param  int|null  $timestamp  unix time from the signed payload/header; when given it is part of the signed
     *                               message ("{timestamp}.{payload}") and must be within $toleranceSeconds, which
     *                               stops an old captured request from being replayed
     */
    public static function verifyHmac(string $payload, string $signature, string $secret, string $algo = 'sha256', ?int $timestamp = null, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || $signature === '') {
            return false;                                        // an unconfigured secret must never "verify" anything
        }
        if ($timestamp !== null && abs(time() - $timestamp) > $toleranceSeconds) {
            return false;                                        // too old (replay) or too far in the future (clock tricks)
        }

        $message = $timestamp !== null ? $timestamp.'.'.$payload : $payload;
        $expected = hash_hmac($algo, $message, $secret, true);

        $signature = preg_replace('/^'.preg_quote($algo, '/').'=/i', '', trim($signature)) ?? '';
        $candidates = [];
        if (ctype_xdigit($signature)) {
            $candidates[] = hex2bin(strlen($signature) % 2 ? '0'.$signature : $signature) ?: '';
        }
        $b64 = base64_decode($signature, true);
        if ($b64 !== false) {
            $candidates[] = $b64;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {   // constant-time: no timing side channel
                return true;
            }
        }

        return false;
    }
}
