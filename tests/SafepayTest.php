<?php

declare(strict_types=1);

/*
 * Safepay card driver. The network is replaced by a stub (SafepayDriver::$http), so these tests check what we SEND
 * (amount in paisa, secret header, hosted-page address) and what we ACCEPT back (signature, confirmation with Safepay,
 * amount check, never paid on an unconfirmed or forged notice). Real sandbox keys are still needed for the final proof.
 */

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Services\OrderService;
use App\Services\Payments\CardGateway;
use App\Services\Payments\Drivers\SafepayDriver;
use App\Support\Settings;

T::$current = 'Safepay driver';
reset_db();
$db = Database::get();
$origCard = Config::get('payments.card');
Config::set('payments.card', ['driver' => SafepayDriver::class, 'key' => 'sec_test_key', 'secret' => 'secret-test-value', 'webhook_secret' => 'webhook-secret-0123456789', 'environment' => 'sandbox']);
Settings::put('payments.enabled', ['cod', 'bank_transfer', 'card']);

$calls = [];
$state = 'TRACKER_ENDED';
$reportCode = 200;
$reportPaisa = 125000;
SafepayDriver::$http = static function (string $method, string $url, array $headers, ?array $json) use (&$calls, &$state, &$reportCode, &$reportPaisa): array {
    $calls[] = compact('method', 'url', 'headers', 'json');
    if (str_ends_with($url, '/client/passport/v1/token')) {
        return [200, ['data' => 'tbt-token-123']];
    }
    if (str_ends_with($url, '/order/payments/v3/')) {
        return [201, ['data' => ['tracker' => ['token' => 'track_abc123']], 'status' => ['message' => 'success']]];
    }
    if (str_contains($url, '/metadata')) {
        return [200, []];
    }
    if (str_contains($url, '/reporter/api/v1/payments/')) {
        return [$reportCode, ['data' => ['token' => 'track_abc123', 'state' => $state, 'purchase_totals' => ['quote_amount' => ['currency' => 'PKR', 'amount' => $reportPaisa]]]]];
    }
    return [404, []];
};

http('GET', '/');   // loads the routes, so route() can build the return address
$card = new CardGateway();
T::ok($card->isAvailable() && $card->missing() === [], 'card is available once the driver and the three keys are set');

$id = Fx::insert('orders', ['number' => 'ABH-SAFE-0001', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'card', 'shipping_method_name' => 'Standard', 'email' => 'buyer@example.com', 'subtotal' => 1000, 'total' => 1250, 'access_token' => str_repeat('b', 40), 'created_at' => now()]);
$order = OrderService::find($id);

// ---- what we send
$url = $card->initiate($order);
T::eq('https://sandbox.api.getsafepay.com', 'https://' . parse_url($url, PHP_URL_HOST), 'sandbox sends the customer to the sandbox page');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
T::eq(['tbt-token-123', 'track_abc123', 'ABH-SAFE-0001', 'sandbox'], [$q['tbt'] ?? null, $q['tracker'] ?? null, $q['order_id'] ?? null, $q['environment'] ?? null], 'the hosted page address carries token, tracker, order and environment');
T::contains('/order/ABH-SAFE-0001/thank-you', (string) ($q['redirect_url'] ?? ''), 'the customer comes back to the order page');
$create = array_values(array_filter($calls, static fn ($c) => str_ends_with($c['url'], '/order/payments/v3/')))[0] ?? null;
T::eq(125000, $create['json']['amount'] ?? null, 'PKR 1,250 is sent as 125000 (lowest unit), taken from the order total');
T::eq(['PKR', 'CYBERSOURCE', 'payment'], [$create['json']['currency'] ?? null, $create['json']['intent'] ?? null, $create['json']['mode'] ?? null], 'currency, intent and mode');
T::ok(in_array('X-SFPY-MERCHANT-SECRET: secret-test-value', $create['headers'] ?? [], true), 'the secret key goes in the header');
T::ok(!str_contains(json_encode($create['json']), 'secret-test-value'), 'and never in the body');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM payments WHERE order_id = ? AND provider = 'card' AND reference = 'track_abc123' AND status = 'pending'", [$id]), 'a pending payment is recorded with the tracker');
T::eq('pending', $db->value('SELECT payment_status FROM orders WHERE id = ?', [$id]), 'the order is not paid by the redirect');

Config::set('payments.card', ['environment' => 'production'] + Config::get('payments.card'));
$liveUrl = $card->initiate($order);
T::ok(str_starts_with($liveUrl, 'https://getsafepay.com/embedded/?'), 'production uses the live hosted page');
T::ok(str_starts_with(array_values(array_filter($calls, static fn ($c) => str_contains($c['url'], 'api.getsafepay.com/order/payments/v3/') && !str_contains($c['url'], 'sandbox')))[0]['url'] ?? '', 'https://api.getsafepay.com/'), 'and the live API host');
Config::set('payments.card', ['environment' => 'sandbox'] + Config::get('payments.card'));

// ---- webhooks
function sp_hook(array $body, ?string $secret = 'webhook-secret-0123456789', bool $reencode = false): App\Core\Response
{
    Auth::forget();
    $raw = $reencode ? json_encode($body, JSON_UNESCAPED_SLASHES) : json_encode($body, JSON_PRETTY_PRINT);
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($secret !== null) {
        $server['HTTP_X_SFPY_SIGNATURE'] = hash_hmac('sha512', $raw, $secret);
    }
    return Kernel::handle(new Request('POST', '/webhooks/payments/card', [], $body, [], $server, [], (string) $raw));
}
$event = ['token' => 'evt_1', 'type' => 'payment.succeeded', 'version' => '2.0.0', 'data' => ['tracker' => 'track_abc123', 'state' => 'TRACKER_ENDED', 'amount' => 125000, 'metadata' => ['order_id' => 'ABH-SAFE-0001']]];
$status = static fn () => [$db->value('SELECT payment_status FROM orders WHERE id = ?', [$id]), $db->value('SELECT status FROM orders WHERE id = ?', [$id])];

T::eq(400, sp_hook($event, null)->status, 'a notice with no signature is refused');
T::eq(400, sp_hook($event, 'wrong-secret')->status, 'a notice signed with the wrong secret is refused');
T::eq(['pending', 'pending'], $status(), 'and nothing changes');
$unknown = $event;
$unknown['data']['tracker'] = 'track_unknown';
T::eq(400, sp_hook($unknown)->status, 'a signed notice for a payment we never created is refused');

$reportCode = 500;
T::eq(400, sp_hook($event)->status, 'if Safepay cannot confirm the payment, the notice is not accepted (Safepay will retry)');
T::eq(['pending', 'pending'], $status(), 'the order stays unpaid');
$reportCode = 200;

$reportPaisa = 5000;
T::eq(422, sp_hook($event)->status, 'a confirmed amount that differs from the order total is refused');
T::eq('pending', $status()[0], 'the order stays unpaid');
$reportPaisa = 125000;

$state = 'TRACKER_STARTED';
T::eq(200, sp_hook($event)->status, 'a payment still in progress is accepted as pending');
T::eq('pending', $status()[0], 'and is not marked paid');
T::eq('track_abc123', $db->value("SELECT reference FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$id]), 'the payment record keeps the tracker');

$state = 'TRACKER_VOIDED';
T::eq(200, sp_hook(['type' => 'payment.failed'] + $event)->status, 'a failed payment is recorded');
T::eq('failed', $status()[0], 'as failed');

$state = 'TRACKER_ENDED';
T::eq(200, sp_hook($event, 'webhook-secret-0123456789', true)->status, 'a confirmed payment is accepted (signature over the re-encoded JSON also works)');
T::eq(['paid', 'confirmed'], $status(), 'the order becomes paid and confirmed');
T::eq(200, sp_hook($event)->status, 'a repeated notice is harmless');
$state = 'TRACKER_REVERSED';
sp_hook(['type' => 'payment.failed'] + $event);
T::eq('paid', $status()[0], 'a late failed notice never undoes a paid order');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'paid'", [$id]), 'one paid payment');

// ---- configuration
Config::set('payments.card', ['driver' => SafepayDriver::class, 'key' => '', 'secret' => '', 'webhook_secret' => '', 'environment' => 'sandbox']);
T::ok(!(new CardGateway())->isAvailable() && count((new CardGateway())->missing()) === 3, 'without keys card stays off and names the three missing values');
T::contains("form-action 'self' https://getsafepay.com https://sandbox.api.getsafepay.com", Kernel::handle(Request::create('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1']))->headers['Content-Security-Policy'] ?? '', 'the page security policy lets the checkout form go to Safepay');

SafepayDriver::$http = null;
Config::set('payments.card', $origCard);
Settings::forget('payments.enabled');
