<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Services\Payments\CardCheckout;
use App\Services\Payments\CardProviderDriver;
use App\Services\Payments\VerifiedPayment;
use App\Services\Payments\WebhookSignature;

/**
 * Safepay (getsafepay.com) hosted card checkout. Written from Safepay's own documentation and its official PHP and
 * WooCommerce code. The customer types the card on Safepay's page; this site never sees a card number.
 *
 *   .env:  CARD_PAYMENT_DRIVER=App\Services\Payments\Drivers\SafepayDriver
 *          CARD_ENVIRONMENT=sandbox            (sandbox while testing, production when live)
 *          CARD_API_KEY=<public key, sec_...>  CARD_API_SECRET=<secret key>  CARD_WEBHOOK_SECRET=<endpoint shared secret>
 *
 * Money: Safepay amounts are in the lowest unit, so PKR 1,250 is sent as 125000.
 * Webhook URL to enter in the Safepay dashboard: https://abiha.pk/webhooks/payments/card
 */
final class SafepayDriver implements CardProviderDriver
{
    /** Tests replace this to avoid the network: fn(string $method, string $url, array $headers, ?array $json): array{0:int,1:array} */
    public static ?\Closure $http = null;

    public function name(): string
    {
        return 'Safepay';
    }

    public function createCheckout(array $order, string $returnUrl): CardCheckout
    {
        $api = self::apiBase();
        $body = [
            'merchant_api_key' => (string) Config::get('payments.card.key'),
            'intent' => 'CYBERSOURCE',
            'mode' => 'payment',
            'currency' => 'PKR',
            'amount' => (int) $order['total'] * 100,                 // from the database, never from the browser
            'order_id' => (string) $order['number'],
            'source' => 'custom',
            'metadata' => ['order_id' => (string) $order['number']],
        ];

        [$code, $passport] = $this->call('POST', $api . '/client/passport/v1/token', $body);
        $tbt = $passport['data'] ?? null;
        if (!in_array($code, [200, 201], true) || !is_string($tbt) || $tbt === '') {
            Logger::error('Safepay: could not get a checkout token', ['http' => $code]);
            throw new HttpException(503);
        }
        [$code, $tracker] = $this->call('POST', $api . '/order/payments/v3/', $body);
        $token = $tracker['data']['tracker']['token'] ?? null;
        if ($code !== 201 || !is_string($token) || $token === '') {
            Logger::error('Safepay: could not create the payment', ['http' => $code]);
            throw new HttpException(503);
        }
        // Best effort: attach the order number to the payment so it shows in the Safepay dashboard.
        $this->call('POST', $api . '/order/payments/v3/' . rawurlencode($token) . '/metadata', ['data' => ['source' => 'abiha.pk', 'order_id' => (string) $order['number']]]);

        $url = self::checkoutBase() . '/embedded/?' . http_build_query([
            'tbt' => $tbt,
            'tracker' => $token,
            'order_id' => (string) $order['number'],
            'environment' => self::environment(),
            'source' => 'custom',
            'redirect_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ]);
        return new CardCheckout($url, $token);
    }

    public function parseWebhook(Request $request): ?VerifiedPayment
    {
        $raw = $request->body();
        $signature = (string) $request->header('x-sfpy-signature');
        $secret = (string) Config::get('payments.card.webhook_secret');
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return null;
        }
        // Safepay's own samples sign either the raw body or the re-encoded JSON, so accept the HMAC of either.
        $reencoded = json_encode($event, JSON_UNESCAPED_SLASHES);
        if (!WebhookSignature::verifyHmac($raw, $signature, $secret, 'sha512')
            && !($reencoded !== false && WebhookSignature::verifyHmac($reencoded, $signature, $secret, 'sha512'))) {
            return null;
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $tracker = is_string($data['tracker'] ?? null) ? $data['tracker'] : (is_array($data['tracker'] ?? null) ? (string) ($data['tracker']['token'] ?? '') : '');
        if ($tracker === '') {
            return null;
        }
        // Which order is this? Our own record of the tracker decides; nothing in the notice is trusted for that.
        $number = Database::get()->value("SELECT o.number FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.provider = 'card' AND p.reference = ? LIMIT 1", [$tracker]);
        if (!is_string($number) || $number === '') {
            return null;
        }

        // Never trust the notice alone: ask Safepay for the state of this payment.
        [$code, $res] = $this->call('GET', self::apiBase() . '/reporter/api/v1/payments/' . rawurlencode($tracker), null);
        $t = is_array($res['data']['tracker'] ?? null) ? $res['data']['tracker'] : (is_array($res['data'] ?? null) ? $res['data'] : []);
        $state = (string) ($t['state'] ?? '');
        if ($code !== 200 || $state === '') {
            Logger::warning('Safepay: could not confirm a payment', ['http' => $code]);
            return null;                                              // Safepay retries, so a short outage costs nothing
        }
        $paisa = $t['purchase_totals']['quote_amount']['amount'] ?? $t['charge']['amount'] ?? $data['amount'] ?? null;
        $paid = $state === 'TRACKER_ENDED';
        if ($paid && !is_numeric($paisa)) {
            return null;
        }
        $failed = in_array($state, ['TRACKER_REVERSED', 'TRACKER_VOIDED'], true) || (string) ($event['type'] ?? '') === 'payment.failed';
        return new VerifiedPayment(
            $number,
            $tracker,
            is_numeric($paisa) ? (int) round(((float) $paisa) / 100) : 0,
            $paid,
            ['provider' => 'safepay', 'state' => $state, 'event' => (string) ($event['type'] ?? '')],
            !$paid && !$failed,
        );
    }

    public static function environment(): string
    {
        return strtolower((string) Config::get('payments.card.environment')) === 'production' ? 'production' : 'sandbox';
    }

    public static function apiBase(): string
    {
        return self::environment() === 'production' ? 'https://api.getsafepay.com' : 'https://sandbox.api.getsafepay.com';
    }

    public static function checkoutBase(): string
    {
        return self::environment() === 'production' ? 'https://getsafepay.com' : 'https://sandbox.api.getsafepay.com';
    }

    /** @return array{0:int,1:array} HTTP status and decoded JSON (empty array when the reply is not JSON) */
    private function call(string $method, string $url, ?array $json): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-SFPY-MERCHANT-SECRET: ' . (string) Config::get('payments.card.secret')];
        if (self::$http !== null) {
            return (self::$http)($method, $url, $headers, $json);
        }
        if (!function_exists('curl_init')) {
            return [0, []];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
        }
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = is_string($out) ? json_decode($out, true) : null;
        return [$code, is_array($decoded) ? $decoded : []];
    }
}
