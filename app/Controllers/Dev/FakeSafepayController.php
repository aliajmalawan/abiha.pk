<?php

declare(strict_types=1);

namespace App\Controllers\Dev;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * LOCAL TEST ONLY. A stand-in for Safepay's servers so the card flow can be tried on a developer's own computer
 * (http://localhost/...). It exists only when the site address is localhost and APP_ENV is not "production"; on a live
 * site every route here answers 404 and the routes are not even registered (app/routes/dev.php).
 *
 * It is NOT a payment provider: no money moves, no card number is ever stored (the form fields are read once to pick
 * "approved" or "declined" and then dropped). The shop still goes through its real code: it creates the payment here,
 * sends the customer to this page, and only trusts the SIGNED notice plus the confirmation call back to this server.
 */
final class FakeSafepayController extends Controller
{
    public const APPROVED = '4111111111111111';
    public const DECLINED = '4000000000000002';

    /** True only on a developer's own computer. */
    public static function allowed(): bool
    {
        $host = strtolower((string) parse_url((string) Config::get('url'), PHP_URL_HOST));
        return !Config::isProduction() && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function guard(Request $request): void
    {
        if (!self::allowed()) {
            throw new HttpException(404);
        }
    }

    /** The shop proves it knows the secret key, like the real API requires. */
    private function authorised(Request $request): bool
    {
        $secret = (string) Config::get('payments.card.secret');
        return $secret !== '' && hash_equals($secret, (string) $request->header('X-SFPY-MERCHANT-SECRET'));
    }

    public function token(Request $request): Response
    {
        $this->guard($request);
        return $this->authorised($request) ? Response::json(['data' => 'dev-tbt-' . bin2hex(random_bytes(6))]) : Response::json(['status' => ['errors' => ['bad key']]], 401);
    }

    public function create(Request $request): Response
    {
        $this->guard($request);
        if (!$this->authorised($request)) {
            return Response::json(['status' => ['errors' => ['bad key']]], 401);
        }
        $body = json_decode($request->body(), true);
        $amount = is_array($body) ? (int) ($body['amount'] ?? 0) : 0;
        $order = is_array($body) ? (string) ($body['order_id'] ?? '') : '';
        if ($amount <= 0 || $order === '') {
            return Response::json(['status' => ['errors' => ['bad request']]], 400);
        }
        $token = 'track_dev_' . bin2hex(random_bytes(8));
        $this->save($token, ['amount' => $amount, 'order_id' => $order, 'state' => 'TRACKER_STARTED']);
        return Response::json(['data' => ['tracker' => ['token' => $token, 'state' => 'TRACKER_STARTED']], 'status' => ['errors' => [], 'message' => 'success']], 201);
    }

    public function metadata(Request $request): Response
    {
        $this->guard($request);
        return Response::json(['status' => ['message' => 'success']]);
    }

    public function report(Request $request): Response
    {
        $this->guard($request);
        if (!$this->authorised($request)) {
            return Response::json(['status' => ['errors' => ['bad key']]], 401);
        }
        $t = $this->load((string) ($request->params['token'] ?? ''));
        if (!$t) {
            return Response::json(['ok' => false], 404);
        }
        return Response::json(['ok' => true, 'data' => [
            'token' => $request->params['token'], 'state' => $t['state'],
            'purchase_totals' => ['quote_amount' => ['currency' => 'PKR', 'amount' => $t['amount']]],
        ]]);
    }

    /** The "Safepay" page where the customer would type the card. */
    public function page(Request $request): Response
    {
        $this->guard($request);
        $tracker = (string) ($request->query['tracker'] ?? '');
        $t = $this->load($tracker);
        if (!$t) {
            throw new HttpException(404);
        }
        return Response::html($this->render($tracker, $t, (string) ($request->query['redirect_url'] ?? ''), (string) ($request->query['cancel_url'] ?? ''), ''));
    }

    public function pay(Request $request): Response
    {
        $this->guard($request);
        $tracker = (string) ($request->post['tracker'] ?? '');
        $back = $this->safeReturn((string) ($request->post['redirect_url'] ?? ''));
        $cancel = $this->safeReturn((string) ($request->post['cancel_url'] ?? '')) ?: $back;
        $t = $this->load($tracker);
        if (!$t) {
            throw new HttpException(404);
        }
        if (isset($request->post['cancel'])) {
            return Response::redirect($cancel ?: (string) Config::get('url'));
        }
        $card = preg_replace('/\D+/', '', (string) ($request->post['card_number'] ?? ''));
        if ($card !== self::APPROVED && $card !== self::DECLINED) {
            return Response::html($this->render($tracker, $t, $back, $cancel, 'Use one of the two test cards shown below.'), 422);
        }
        $paid = $card === self::APPROVED;
        $t['state'] = $paid ? 'TRACKER_ENDED' : 'TRACKER_REVERSED';
        $this->save($tracker, $t);
        $this->notify($tracker, $t, $paid);                         // card data is dropped here; nothing was stored
        return Response::redirect($back ?: (string) Config::get('url'));
    }

    /** Send the signed notice to the shop, exactly as Safepay would. */
    private function notify(string $tracker, array $t, bool $paid): void
    {
        $event = [
            'token' => 'evt_dev_' . bin2hex(random_bytes(6)), 'version' => '2.0.0', 'type' => $paid ? 'payment.succeeded' : 'payment.failed',
            'data' => ['tracker' => $tracker, 'state' => $t['state'], 'amount' => $t['amount'], 'currency' => 'PKR', 'metadata' => ['order_id' => $t['order_id']]],
        ];
        $raw = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha512', $raw, (string) Config::get('payments.card.webhook_secret'));
        $ch = curl_init(rtrim((string) Config::get('url'), '/') . '/webhooks/payments/card');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-SFPY-SIGNATURE: ' . $sig]]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function safeReturn(string $url): string
    {
        $base = rtrim((string) Config::get('url'), '/');
        return $url !== '' && str_starts_with($url, $base . '/') ? $url : '';
    }

    private function render(string $tracker, array $t, string $redirect, string $cancel, string $error): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $amount = number_format($t['amount'] / 100);
        $err = $error !== '' ? '<p style="color:#b00020;font-weight:600">' . $e($error) . '</p>' : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Local test payment page</title><style>body{font-family:Inter,Arial,sans-serif;background:#F8F6F1;margin:0;padding:24px}main{max-width:440px;margin:auto;background:#fff;border:1px solid #eadfc8;border-radius:12px;padding:24px}'
            . 'label{display:block;font-size:13px;margin:12px 0 4px}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:8px;font-size:16px}'
            . 'button{margin-top:16px;width:100%;padding:12px;border:0;border-radius:8px;background:#101010;color:#fff;font-size:16px;cursor:pointer}.t{background:#fff3cd;border:1px solid #e6c200;padding:10px;border-radius:8px;font-size:13px}'
            . 'a,.x{background:none;color:#555;text-decoration:underline}</style></head><body><main>'
            . '<p class="t"><strong>LOCAL TEST PAGE</strong>: this is not Safepay and no money moves. It only lets you try the shop\'s card flow on your own computer.</p>'
            . '<h2>Order ' . $e($t['order_id']) . '</h2><p>Amount: <strong>PKR ' . $e($amount) . '</strong></p>' . $err
            . '<form method="POST" action="' . $e(rtrim((string) Config::get('url'), '/') . '/dev/safepay/embedded/pay') . '" autocomplete="off">'
            . '<input type="hidden" name="tracker" value="' . $e($tracker) . '"><input type="hidden" name="redirect_url" value="' . $e($redirect) . '"><input type="hidden" name="cancel_url" value="' . $e($cancel) . '">'
            . '<label for="n">Name on card</label><input id="n" name="name" value="Test Customer">'
            . '<label for="c">Card number</label><input id="c" name="card_number" inputmode="numeric" placeholder="4111 1111 1111 1111">'
            . '<label for="x">Expiry</label><input id="x" name="expiry" value="12/30"><label for="v">CVV</label><input id="v" name="cvv" value="123">'
            . '<button type="submit">Pay PKR ' . $e($amount) . '</button><button type="submit" name="cancel" value="1" class="x">Cancel</button></form>'
            . '<p style="font-size:13px;color:#555">Test cards: <code>' . self::APPROVED . '</code> = approved, <code>' . self::DECLINED . '</code> = declined. Any expiry in the future and any CVV.</p>'
            . '</main></body></html>';
    }

    private function dir(): string
    {
        $d = STORAGE_PATH . '/dev/safepay';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    private function load(string $token): ?array
    {
        if (!preg_match('/^track_dev_[a-f0-9]{16}$/', $token)) {
            return null;
        }
        $f = $this->dir() . '/' . $token . '.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) ? $d : null;
    }

    private function save(string $token, array $data): void
    {
        file_put_contents($this->dir() . '/' . $token . '.json', json_encode($data));
    }
}
