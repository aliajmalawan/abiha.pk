<?php

declare(strict_types=1);

/*
 * Slice C: cart, coupons, shipping, checkout, orders, inventory, payments/webhooks, tracking, order page access.
 * Ports the intent of the old tests CartAndCheckoutTest, CartPersistenceAndContentTest (cart part), CheckoutTest,
 * CouponRestrictionTest, OrderProcessingTest (service part), PaymentsTest, PaymentWebhookTest, ShippingTest,
 * SettingsShippingMarketingTest (shipping/tax/payment parts) and OrderEmailTest (placed/paid mails).
 */

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Mail\Mailer;
use App\Services\Cart;
use App\Services\Cart\CartException;
use App\Services\CouponService;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\InventoryService;
use App\Services\OrderService;
use App\Services\Payments\CardCheckout;
use App\Services\Payments\CardGateway;
use App\Services\Payments\CardProviderDriver;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\PaymentService;
use App\Services\Payments\VerifiedPayment;
use App\Services\Payments\WebhookSignature;
use App\Services\ShippingService;
use App\Support\Settings;

/** TEST DOUBLE ONLY: stands in for a card provider so the shared card pipeline can be exercised. Never registered in the app. */
final class CxFakeCardDriver implements CardProviderDriver
{
    public function name(): string { return 'Fake'; }
    public function createCheckout(array $order, string $returnUrl): CardCheckout
    {
        return new CardCheckout('https://provider.example/pay/' . $order['number'], 'TXN-' . $order['number']);
    }
    public function parseWebhook(Request $request): ?VerifiedPayment
    {
        if ($request->header('X-Sig') !== 'valid') {
            return null;
        }
        $s = $request->input('state');
        return new VerifiedPayment((string) $request->input('order'), (string) $request->input('ref'), (int) $request->input('amount'), $s === 'paid', ['state' => $s], $s === 'pending');
    }
}

/** TEST DOUBLE ONLY: an online gateway for the shared webhook pipeline. */
final class CxStubGateway implements OnlinePaymentGateway
{
    public function code(): string { return 'stub'; }
    public function label(): string { return 'Stub'; }
    public function description(): string { return ''; }
    public function isAvailable(): bool { return true; }
    public function initiate(array $order): string { return 'https://provider.example/pay/' . $order['number']; }
    public function handleWebhook(Request $request): ?VerifiedPayment
    {
        if ($request->header('X-Signature') !== 'valid') {
            return null;
        }
        return new VerifiedPayment((string) $request->input('order'), (string) $request->input('ref'), (int) $request->input('amount'), (bool) $request->input('paid', true));
    }
}

Kernel::$extraRoutes = [];
$cxGateways = Config::get('payments.gateways');
$cxCard = Config::get('payments.card');

/** A fresh "browser": guest, empty session, no rate-limit history. */
function cx_fresh(): void
{
    Auth::actingAs(null);
    Session::resetMemory();
    Database::get()->exec('DELETE FROM rate_limits');
    App\Support\Settings::flush();
    Mailer::$sent = [];
}

function cx_shipping(int $cost = 250, ?int $freeOver = null, string $code = 'standard', string $name = 'Standard'): array
{
    $db = Database::get();
    $id = $db->value('SELECT id FROM shipping_methods WHERE code = ?', [$code]);
    if ($id) {
        $db->exec('UPDATE shipping_methods SET cost = ?, free_over = ?, is_active = 1 WHERE id = ?', [$cost, $freeOver, $id]);
    } else {
        $id = Fx::insert('shipping_methods', ['code' => $code, 'name' => $name, 'cost' => $cost, 'free_over' => $freeOver, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    return ShippingService::find($code);
}

function cx_coupon(array $over = []): array
{
    $id = Fx::insert('coupons', $over + ['code' => 'TEST10', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    return Database::get()->one('SELECT * FROM coupons WHERE id = ?', [$id]);
}

function cx_add(array $p, ?int $variantId = null, int $qty = 1): Response
{
    return http('POST', '/cart/items', ['product_id' => $p['id'], 'variant_id' => $variantId, 'quantity' => $qty], null, ['json' => true]);
}

function cx_payload(array $over = []): array
{
    if (($over['payment_method'] ?? null) === 'bank_transfer' && !Settings::get('bank.details')) {
        Settings::put('bank.details', 'TEST BANK, account 0000-0000 (test data)');
    }
    return array_merge([
        'name' => 'Test Buyer', 'phone' => '03001234567', 'email' => 'buyer@example.com', 'province' => 'Punjab', 'city' => 'Lahore',
        'address' => 'House 1, Street 2, Sample Town', 'shipping_method' => 'standard', 'payment_method' => 'cod',
    ], $over);
}

function cx_json(Response $r): array
{
    return (array) json_decode($r->body, true);
}

function cx_last_order(): ?array
{
    $id = Database::get()->value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
    return $id ? OrderService::find((int) $id) : null;
}

/** Guest order through the real checkout. */
function cx_order(string $method = 'cod', int $price = 1000, int $qty = 1, array $over = []): array
{
    cx_fresh();
    cx_shipping(250);
    $p = Fx::product(['price' => $price, 'stock' => 10]);
    cx_add($p, null, $qty);
    http('POST', '/checkout', cx_payload(['payment_method' => $method] + $over));
    return [cx_last_order(), $p];
}

/** POST with uploaded files (the http() helper sends no files). */
function cx_upload(string $path, array $input, array $files): Response
{
    Auth::forget();
    Session::ageFlash();
    $input['_token'] ??= Csrf::token();
    return Kernel::handle(Request::create('POST', $path, $input, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'text/html'], $files));
}

function cx_file(string $name, string $content): array
{
    $f = BASE_PATH . '/tests/.tmp/upload-' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($f, $content);
    return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];
}

/** Webhook POST with a JSON body (raw body kept, no CSRF). */
function cx_hook(string $provider, array $body, array $headers = []): Response
{
    Auth::forget();
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $raw = json_encode($body);
    return Kernel::handle(new Request('POST', '/webhooks/payments/' . $provider, [], $body, [], $server, [], (string) $raw));
}

/** Validation error flashed by the LAST request (field => message), '' when none. */
function cx_err(string $field): string
{
    $e = Session::get('_flash_new')['errors'][$field] ?? '';
    return is_array($e) ? (string) reset($e) : (string) $e;
}

function cx_flash(string $key): mixed
{
    return Session::get('_flash_new')[$key] ?? null;
}

function cx_count(string $sql, array $p = []): int
{
    return (int) Database::get()->value($sql, $p);
}

reset_db();
$db = Database::get();

/* ---------------- cart ---------------- */
T::$current = 'cart add + count';
cx_fresh();
$p = Fx::product(['price' => 1500, 'stock' => 5]);
$r = cx_add($p, null, 2);
$j = cx_json($r);
T::eq(200, $r->status, 'add ok');
T::ok($j['ok'] === true && $j['count'] === 2 && $j['message'] === 'Added to your bag.' && array_key_exists('redirect', $j), 'JSON shape {ok,message,count,redirect}');
T::contains('3,000', http('GET', '/cart')->body, 'cart page shows the line total');
$cart = $db->one('SELECT * FROM carts LIMIT 1');
T::ok($cart['user_id'] === null && strlen((string) $cart['token']) === 40, 'guest cart has an opaque 40-char token');
T::eq(['cart_id', 'created_at', 'id', 'product_id', 'quantity', 'updated_at', 'variant_id'], (static function () use ($db) { $c = $db->column("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cart_items'"); sort($c); return $c; })(), 'no price columns in cart_items');
$r = cx_add($p, null, 1);
T::eq(3, cx_json($r)['count'], 'count adds up');
$buy = http('POST', '/cart/items', ['product_id' => $p['id'], 'quantity' => 1, 'buy_now' => true], null, ['json' => true]);
T::eq(route('checkout'), cx_json($buy)['redirect'], 'buy now redirects to checkout');

T::$current = 'cart stock caps';
cx_fresh();
$p = Fx::product(['stock' => 2]);
$r = cx_add($p, null, 3);
T::ok($r->status === 422 && cx_json($r)['ok'] === false, 'more than stock refused');
T::eq(200, cx_add($p, null, 2)->status, 'up to stock ok');
$r = cx_add($p, null, 1);
T::ok($r->status === 422 && str_contains(cx_json($r)['message'], 'You already have all 2'), 'already holds all');
T::eq(422, cx_add(Fx::product(['stock' => 0]))->status, 'sold out refused');
T::eq(422, http('POST', '/cart/items', ['product_id' => 999999], null, ['json' => true])->status, 'unknown product is a validation error');

T::$current = 'cart variants';
cx_fresh();
$vp = Fx::product(['price' => 2000, 'stock' => 0]);
$vS = Fx::variant((int) $vp['id'], ['stock' => 3]);
$vM = Fx::variant((int) $vp['id'], ['stock' => 0]);
$vL = Fx::variant((int) $vp['id'], ['stock' => 4, 'price' => 2500]);
$sizeAttr = Fx::insert('attributes', ['name' => 'Size', 'slug' => 'size-cx', 'sort_order' => 1]);
$valL = Fx::insert('attribute_values', ['attribute_id' => $sizeAttr, 'value' => 'L']);
Fx::insert('attribute_value_product_variant', ['product_variant_id' => $vL['id'], 'attribute_value_id' => $valL]);
$vp = $db->one('SELECT * FROM products WHERE id = ?', [$vp['id']]);
T::eq(422, cx_add($vp)->status, 'variant required');
T::eq(422, cx_add($vp, (int) $vM['id'])->status, 'sold-out variant refused');
T::eq(200, cx_add($vp, (int) $vL['id'], 2)->status, 'variant added');
T::eq(422, cx_add($vp, (int) $vL['id'], 3)->status, 'variant stock cap');
$page = http('GET', '/cart')->body;
T::contains('5,000', $page, 'variant price used (2 x 2,500)');
T::contains('Size: L', $page, 'variant label shown');
$other = Fx::product(['has_variants' => 1]);
T::eq(422, cx_add($other, (int) $vS['id'])->status, 'a variant of another product is rejected');

T::$current = 'cart prices from db + update/remove';
cx_fresh();
$p = Fx::product(['price' => 1000, 'stock' => 10]);
http('POST', '/cart/items', ['product_id' => $p['id'], 'quantity' => 1, 'price' => 1, 'unit_price' => 1], null, ['json' => true]);
T::eq(1000, Cart::summary()['subtotal'], 'browser cannot set prices');
$key = $p['id'] . '-0';
$r = http('PATCH', '/cart/items/' . $key, ['quantity' => 4], null, ['json' => true]);
T::ok($r->status === 200 && cx_json($r)['count'] === 4, 'update quantity');
$r = http('PATCH', '/cart/items/' . $key, ['quantity' => 11], null, ['json' => true]);
T::eq(422, $r->status, 'update above stock refused');
T::eq(422, http('PATCH', '/cart/items/' . $key, ['quantity' => 21], null, ['json' => true])->status, 'max 20');
$r = http('DELETE', '/cart/items/' . $key, [], null, ['json' => true]);
T::ok(cx_json($r)['count'] === 0 && cx_json($r)['message'] === 'Item removed.', 'remove');
cx_add($p);
$r = http('PATCH', '/cart/items/' . $key, ['quantity' => 0], null, ['json' => true]);
T::eq(0, cx_json($r)['count'], 'quantity 0 removes');
T::eq(419, http('DELETE', '/cart/items/' . $key, ['_token' => 'bad'])->status, 'CSRF enforced');

T::$current = 'cart drawer + empty';
cx_fresh();
$before = cx_count('SELECT COUNT(*) FROM carts');
T::contains('Your bag is empty', http('GET', '/cart/drawer')->body, 'empty drawer');
http('GET', '/cart');
T::eq($before, cx_count('SELECT COUNT(*) FROM carts'), 'browsing creates no cart');
$p = Fx::product(['price' => 1200, 'stock' => 5, 'name' => 'Drawer Kurta']);
cx_add($p, null, 1);
$d = http('GET', '/cart/drawer', [], null, ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
T::ok($d->status === 200 && str_contains($d->body, 'Drawer Kurta') && str_contains($d->body, 'drawerLine(') && !str_contains($d->body, '<html'), 'drawer fragment');
$c = http('GET', '/cart');
T::contains('noindex', $c->body, 'cart is noindex');
T::contains('cartLine(', $c->body, 'cart line component');

T::$current = 'cart persistence + merge';
cx_fresh();
$u = Fx::user('customer');
$a = Fx::product(['stock' => 3]);
$b = Fx::product(['stock' => 10]);
http('POST', '/cart/items', ['product_id' => $a['id'], 'quantity' => 2], $u, ['json' => true]);
cx_fresh();
Auth::actingAs($u);
T::eq(2, Cart::count(), 'account cart survives a new session');
cx_fresh();
cx_add($a, null, 2);
cx_add($b, null, 4);
$guestCartId = (int) Cart::current()['id'];
Auth::login($db->one('SELECT * FROM users WHERE id = ?', [$u['id']]));
Cart::mergeGuest((int) $u['id']);
$lines = [];
foreach (Cart::lines() as $l) {
    $lines[(int) $l['product']['id']] = $l['quantity'];
}
T::eq(3, $lines[(int) $a['id']] ?? null, 'merged qty capped at stock');
T::eq(4, $lines[(int) $b['id']] ?? null, 'guest line merged');
T::eq(0, cx_count('SELECT COUNT(*) FROM carts WHERE id = ?', [$guestCartId]), 'guest cart removed');

T::$current = 'cart clamps and drops';
cx_fresh();
$p = Fx::product(['stock' => 5]);
cx_add($p, null, 5);
$db->exec('UPDATE products SET stock = 2 WHERE id = ?', [$p['id']]);
$s = Cart::summary();
T::ok($s['lines'][0]['quantity'] === 2 && $s['notices'] !== [], 'quantity clamped with a notice');
$db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$p['id']]);
T::ok(Cart::summary()['empty'], 'deactivated products drop out');

/* ---------------- coupons ---------------- */
T::$current = 'coupon percent minimum cap';
cx_fresh();
cx_shipping(0);
cx_coupon(['code' => 'CAP10', 'value' => 10, 'min_subtotal' => 2000, 'max_discount' => 150]);
$p = Fx::product(['price' => 1000, 'stock' => 10]);
cx_add($p);
http('POST', '/cart/coupon', ['code' => 'cap10']);
T::contains('Spend at least PKR 2,000', cx_err('coupon'), 'below minimum');
cx_add($p, null, 2);
http('POST', '/cart/coupon', ['code' => 'cap10']);
T::eq('', cx_err('coupon'), 'applied');
$s = Cart::summary();
T::ok($s['discount'] === 150 && $s['total'] === 2850, '10% = 300 capped at 150');
$r = http('POST', '/cart/coupon', ['code' => 'NOPE'], null, ['json' => true]);
T::ok($r->status === 422 && cx_json($r)['message'] === 'This coupon code is not valid.', 'JSON coupon error');

T::$current = 'coupon rejected states';
cx_fresh();
cx_add(Fx::product(['price' => 1000]));
cx_coupon(['code' => 'OLD', 'expires_at' => date('Y-m-d H:i:s', time() - 86400)]);
cx_coupon(['code' => 'OFF', 'is_active' => 0]);
cx_coupon(['code' => 'SOON', 'starts_at' => date('Y-m-d H:i:s', time() + 86400)]);
$used = cx_coupon(['code' => 'USED', 'usage_limit' => 1]);
$oid = Fx::insert('orders', ['number' => 'ABH-OLD-' . strtoupper(bin2hex(random_bytes(2))), 'email' => 'old@example.com', 'payment_method' => 'cod', 'shipping_method_name' => 'Standard', 'subtotal' => 1000, 'total' => 900, 'access_token' => bin2hex(random_bytes(20)), 'created_at' => now()]);
Fx::insert('coupon_usages', ['coupon_id' => $used['id'], 'order_id' => $oid, 'email' => 'old@example.com', 'discount_amount' => 100]);
foreach (['OLD' => 'expired', 'OFF' => 'not valid', 'SOON' => 'not active yet', 'USED' => 'usage limit', 'NOPE' => 'not valid'] as $code => $msg) {
    http('POST', '/cart/coupon', ['code' => $code]);
    T::contains($msg, cx_err('coupon'), "coupon {$code} refused");
}

T::$current = 'coupon restrictions';
cx_fresh();
$cat = Fx::category();
$sub = Fx::category(['parent_id' => $cat['id']]);
$inCat = Fx::product(['price' => 1000, 'category_id' => $sub['id']]);
$outCat = Fx::product(['price' => 3000]);
$rc = cx_coupon(['code' => 'CATONLY', 'value' => 50]);
Fx::insert('coupon_category', ['coupon_id' => $rc['id'], 'category_id' => $cat['id']]);
cx_add($outCat);
T::throws(CartException::class, static fn () => Cart::applyCoupon('CATONLY'), 'nothing qualifies', 'does not apply to the items');
cx_add($inCat);
Cart::applyCoupon('catonly');
T::eq(500, Cart::summary()['discount'], 'only the sub-category item is discounted');
Cart::remove($inCat['id'] . '-0');
$s = Cart::summary();
T::ok($s['coupon'] === null && $s['discount'] === 0 && $s['coupon_error'] !== null, 'coupon dropped when the qualifying item leaves');
T::eq(100, CouponService::discountFor(['type' => 'fixed', 'value' => 500, 'max_discount' => null], 100), 'discount never exceeds the eligible amount');

/* ---------------- shipping ---------------- */
T::$current = 'shipping rules';
$m = cx_shipping(250, 3000, 'ruled', 'Ruled');
Fx::insert('shipping_rules', ['shipping_method_id' => $m['id'], 'province' => 'Punjab', 'city' => 'Lahore', 'cost' => 150, 'free_over' => 1500, 'eta' => '1-2 days', 'is_available' => 1]);
Fx::insert('shipping_rules', ['shipping_method_id' => $m['id'], 'province' => 'Punjab', 'city' => null, 'cost' => 300, 'is_available' => 1]);
Fx::insert('shipping_rules', ['shipping_method_id' => $m['id'], 'province' => 'Sindh', 'city' => 'Karachi', 'cost' => 0, 'is_available' => 0]);
$m = ShippingService::find('ruled');
T::eq(150, ShippingService::quote($m, 1000, 'Punjab', 'Lahore'), 'city rule');
T::eq(150, ShippingService::quote($m, 1000, 'punjab', '  LAHORE '), 'case/space insensitive');
T::eq(0, ShippingService::quote($m, 1500, 'Punjab', 'Lahore'), 'city free-over');
T::eq(300, ShippingService::quote($m, 1000, 'Punjab', 'Multan'), 'province rule');
T::eq(250, ShippingService::quote($m, 1000, 'Balochistan', 'Quetta'), 'method default');
T::eq(0, ShippingService::quote($m, 5000, 'Balochistan', 'Quetta'), 'method free-over');
T::eq(null, ShippingService::quote($m, 1000, 'Sindh', 'Karachi'), 'not offered');
T::eq(250, ShippingService::quote($m, 1000, 'Sindh', 'Hyderabad'), 'only Karachi blocked');
T::eq('1-2 days', ShippingService::etaFor($m, 'Punjab', 'Lahore'), 'rule eta');
T::eq(null, ShippingService::etaFor($m, 'Sindh', 'Hyderabad'), 'no invented eta');
$db->exec("UPDATE shipping_methods SET is_active = 0 WHERE code = 'ruled'");

T::$current = 'free shipping threshold';
cx_fresh();
cx_shipping(250, 3000);
$p = Fx::product(['price' => 1000, 'stock' => 10]);
cx_add($p, null, 2);
T::eq(250, Cart::summary()['shipping'], 'below threshold');
cx_add($p, null, 1);
T::eq(0, Cart::summary()['shipping'], 'free at threshold');
cx_shipping(250, null);

/* ---------------- checkout ---------------- */
T::$current = 'checkout page';
cx_fresh();
T::eq(route('cart'), http('GET', '/checkout')->headers['Location'] ?? null, 'empty bag goes to the cart');
cx_shipping(250);
cx_shipping(900, null, 'dhl', 'DHL');
cx_add(Fx::product(['price' => 1000, 'name' => 'Summary Dress']));
$page = http('GET', '/checkout');
T::eq(200, $page->status, 'checkout ok');
foreach (['name="name"', 'name="phone"', 'name="email"', 'name="province"', 'name="city"', 'name="address"', 'name="postal_code"', 'name="notes"',
    'name="shipping_method"', 'name="payment_method"', 'name="code"', 'Order summary', 'Summary Dress', 'Total payable', 'Place order', 'x-data="checkout(JSON.parse(', 'carrier-logo', 'Cash on Delivery'] as $needle) {
    T::contains($needle, $page->body, 'checkout shows ' . $needle);
}
T::ok(!str_contains($page->body, 'Bank Transfer') && !str_contains($page->body, 'Debit / Credit Card'), 'bank transfer hidden without details, card disabled');
T::contains('noindex', $page->body, 'checkout noindex');
$db->exec("UPDATE shipping_methods SET is_active = 0 WHERE code = 'dhl'");

T::$current = 'checkout validation';
cx_fresh();
cx_add(Fx::product());
http('POST', '/checkout', cx_payload(['phone' => 'abc', 'province' => 'Atlantis', 'address' => 'short', 'email' => 'nope']));
T::ok(cx_err('phone') !== '' && cx_err('province') !== '' && cx_err('email') !== '', 'bad fields rejected');
T::eq('Please enter your complete address (house, street, area).', cx_err('address'), 'address message');
http('POST', '/checkout', cx_payload(['postal_code' => '<script>']));
T::ok(cx_err('postal_code') !== '', 'postal code format');
http('POST', '/checkout', cx_payload(['notes' => str_repeat('x', 501)]));
T::ok(cx_err('notes') !== '', 'notes max 500');
http('POST', '/checkout', cx_payload(['phone' => '+1 415 555 0123']));
T::contains('Pakistani phone', cx_err('phone'), 'foreign phone rejected');
http('POST', '/checkout', cx_payload(['payment_method' => 'jazzcash']));
T::contains('valid shipping and payment method', cx_err('payment_method'), 'unknown payment method');
http('POST', '/checkout', cx_payload(['payment_method' => 'card']));
T::ok(cx_err('payment_method') !== '', 'disabled card refused');
T::eq(0, cx_count('SELECT COUNT(*) FROM orders WHERE email = ?', ['buyer@example.com']), 'no order placed');

T::$current = 'checkout places an order';
cx_fresh();
$p = Fx::product(['price' => 1000, 'stock' => 5, 'name' => 'Snapshot Item']);
cx_add($p, null, 2);
$r = http('POST', '/checkout', cx_payload([
    'phone' => '+92 300 1234567', 'email' => 'Ayesha@Example.com', 'name' => 'Ayesha Khan', 'province' => 'Sindh', 'city' => 'Karachi',
    'address' => 'Flat 4, Block B, Clifton', 'postal_code' => '75600', 'notes' => 'Please call before delivery',
    'total' => 1, 'subtotal' => 1, 'discount' => 5000, 'shipping_cost' => 0, 'unit_price' => 1, 'payment_status' => 'paid', 'status' => 'delivered', 'coupon_id' => 1,
    'card_number' => '4111111111111111', 'cvv' => '123',
]));
$o = cx_last_order();
T::eq(route('order.confirmation', ['number' => $o['number'], 'token' => $o['access_token']]), $r->headers['Location'] ?? null, 'redirect to the private confirmation link');
T::ok((bool) preg_match('/^ABH-\d{6}-[A-Z0-9]{5}$/', $o['number']), 'order number ABH-ymd-XXXXX');
T::eq([2000, 0, 250, 2250], [(int) $o['subtotal'], (int) $o['discount'], (int) $o['shipping_cost'], (int) $o['total']], 'server totals');
T::eq(['cod', 'unpaid', 'pending', null], [$o['payment_method'], $o['payment_status'], $o['status'], $o['coupon_id']], 'browser cannot set status or coupon');
T::eq(['ayesha@example.com', 'Please call before delivery', 'Ayesha Khan', 'Sindh', 'Karachi', 'Flat 4, Block B, Clifton', '75600', '03001234567'],
    [$o['email'], $o['notes'], $o['name'], $o['province'], $o['city'], $o['address'], $o['postal_code'], $o['phone']], 'every field saved; phone normalised');
T::eq(3, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'stock decremented');
T::eq(2, (int) $db->value('SELECT sold_count FROM products WHERE id = ?', [$p['id']]), 'sold count');
T::eq(-2, (int) $db->value("SELECT `change` FROM stock_movements WHERE order_id = ? AND reason = 'sale'", [$o['id']]), 'sale in the ledger');
T::eq(['Snapshot Item', 1000, 2], [$o['items'][0]['name'], (int) $o['items'][0]['unit_price'], (int) $o['items'][0]['quantity']], 'item snapshot');
T::eq(1, cx_count("SELECT COUNT(*) FROM payments WHERE order_id = ? AND provider = 'cod' AND status = 'pending'", [$o['id']]), 'pending payment row');
T::eq(1, cx_count("SELECT COUNT(*) FROM payment_transactions WHERE order_id = ? AND type = 'initiated'", [$o['id']]), 'payment history');
T::eq('Order placed', $o['history'][0]['note'], 'history');
T::eq(0, Cart::count(), 'bag emptied');
T::ok(!str_contains(json_encode($db->all('SELECT * FROM orders')) . json_encode($db->all('SELECT * FROM payments')), '4111111111111111'), 'no card data stored');
$db->exec("UPDATE products SET price = 9999, name = 'Renamed' WHERE id = ?", [$p['id']]);
$o2 = OrderService::find((int) $o['id']);
T::ok($o2['items'][0]['name'] === 'Snapshot Item' && (int) $o2['items'][0]['unit_price'] === 1000, 'history is immutable');
T::ok(count(Mailer::$sent) >= 1 && Mailer::$sent[0]['to'] === 'ayesha@example.com' && str_contains(Mailer::$sent[0]['subject'], 'received'), 'confirmation email');
$mail = Mailer::$sent[0]['html'];
T::ok(str_contains($mail, 'Order <strong>' . $o['number'] . '</strong>') && str_contains($mail, 'PKR 2,250') && str_contains($mail, 'Cash on Delivery') && !str_contains($mail, 'Tracking number') && !str_contains($mail, 'Estimated delivery'), 'email shows the real order, nothing invented');
T::contains('Total: PKR 2,250', Mailer::$sent[0]['text'], 'text part');

T::$current = 'checkout stock race + empty bag';
cx_fresh();
$p = Fx::product(['stock' => 2]);
cx_add($p, null, 2);
$db->exec('UPDATE products SET stock = 1 WHERE id = ?', [$p['id']]);
$before = cx_count('SELECT COUNT(*) FROM orders');
$r = http('POST', '/checkout', cx_payload());
T::ok(($r->headers['Location'] ?? '') === route('cart') && $before === cx_count('SELECT COUNT(*) FROM orders'), 'aborts back to the bag');
T::eq(1, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'nothing decremented');
cx_fresh();
http('POST', '/checkout', cx_payload());
T::eq('Your bag is empty.', cx_err('cart'), 'empty bag cannot order');

T::$current = 'checkout coupon from the checkout page';
cx_fresh();
cx_coupon(['code' => 'SAVE10']);
cx_add(Fx::product(['price' => 1000]));
$r = http('POST', '/cart/coupon', ['code' => 'save10', 'from' => 'checkout', 'name' => 'Ayesha Khan', 'city' => 'Lahore']);
T::eq(route('checkout'), $r->headers['Location'] ?? null, 'back to checkout');
$page = http('GET', '/checkout')->body;
T::ok(str_contains($page, 'SAVE10') && str_contains($page, 'Ayesha Khan') && str_contains($page, 'Lahore'), 'typed input kept');
http('POST', '/cart/coupon', ['code' => '', 'from' => 'checkout']);
T::eq('Enter a coupon code.', cx_err('coupon'), 'empty code');
http('POST', '/checkout', cx_payload());
$o = cx_last_order();
T::eq([100, 1150, 'SAVE10'], [(int) $o['discount'], (int) $o['total'], $o['coupon_code']], 'coupon charged');
T::eq(1, cx_count('SELECT COUNT(*) FROM coupon_usages WHERE order_id = ?', [$o['id']]), 'usage recorded');
cx_fresh();
cx_coupon(['code' => 'DROPME']);
cx_add(Fx::product(['price' => 1000]));
http('POST', '/cart/coupon', ['code' => 'DROPME']);
$r = http('DELETE', '/cart/coupon', ['from' => 'checkout']);
T::eq(route('checkout'), $r->headers['Location'] ?? null, 'remove from checkout');
http('POST', '/checkout', cx_payload());
T::eq(0, (int) cx_last_order()['discount'], 'removed coupon not charged');

T::$current = 'coupon usage limit at checkout';
cx_fresh();
$once = cx_coupon(['code' => 'ONCE', 'per_customer_limit' => 1]);
cx_add(Fx::product(['price' => 1000]), null, 2);
http('POST', '/cart/coupon', ['code' => 'ONCE']);
http('POST', '/checkout', cx_payload());
$first = cx_last_order();
T::eq(200, (int) $first['discount'], 'first use');
cx_fresh();
cx_add(Fx::product(['price' => 1000]));
http('POST', '/cart/coupon', ['code' => 'ONCE']);
http('POST', '/checkout', cx_payload());
T::ok(cx_last_order()['coupon_code'] === null && cx_count('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?', [$once['id']]) === 1, 'same email cannot reuse it');

T::$current = 'checkout destination shipping + tax';
cx_fresh();
$m = cx_shipping(250);
Fx::insert('shipping_rules', ['shipping_method_id' => $m['id'], 'province' => 'Punjab', 'city' => 'Lahore', 'cost' => 150, 'eta' => '2 days', 'is_available' => 1]);
Fx::insert('shipping_rules', ['shipping_method_id' => $m['id'], 'province' => 'Sindh', 'city' => 'Karachi', 'cost' => 0, 'is_available' => 0]);
$p = Fx::product(['price' => 1000, 'stock' => 20]);
cx_add($p);
http('POST', '/checkout', cx_payload(['city' => 'lahore', 'shipping_cost' => 1]));
$o = cx_last_order();
T::eq([150, 1150, '2 days'], [(int) $o['shipping_cost'], (int) $o['total'], $o['shipping_eta']], 'city rule charged and eta saved');
cx_fresh();
cx_add($p);
$before = cx_count('SELECT COUNT(*) FROM orders');
$r = http('POST', '/checkout', cx_payload(['province' => 'Sindh', 'city' => 'Karachi']));
T::ok(($r->headers['Location'] ?? '') === route('cart') && str_contains(cx_err('cart'), 'not available to Karachi') && $before === cx_count('SELECT COUNT(*) FROM orders') && Cart::count() === 1, 'blocked destination refused, bag kept');
$db->exec('DELETE FROM shipping_rules');
Settings::put('tax.enabled', true);
Settings::put('tax.rate', 10);
Settings::put('tax.label', 'GST');
[$o] = cx_order('cod', 1000, 2);
T::eq([2250, 182, 'GST'], [(int) $o['total'], (int) $o['tax_amount'], $o['tax_label']], 'inclusive tax recorded, total unchanged');
Settings::put('tax.enabled', false);
[$o] = cx_order();
T::ok((int) $o['tax_amount'] === 0 && $o['tax_label'] === null, 'no tax when off');

/* ---------------- orders + inventory ---------------- */
T::$current = 'order status machine';
[$o, $p] = cx_order('cod', 1000, 3);
$staff = Fx::user('admin');
T::throws(CartException::class, static fn () => OrderService::changeStatus($o, 'delivered', $staff), 'pending -> delivered refused', 'cannot be changed to delivered');
foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $s) {
    OrderService::changeStatus($o, $s, $staff);
}
$o = OrderService::find((int) $o['id']);
T::eq('delivered', $o['status'], 'walked the happy path');
T::eq(5, count($o['history']), 'history rows');
T::ok($o['shipment'] !== null && $o['shipment']['status'] === 'delivered' && $o['shipment']['delivered_at'] !== null, 'shipment follows the order');
T::throws(CartException::class, static fn () => OrderService::changeStatus($o, 'cancelled', $staff), 'delivered cannot be cancelled');
T::eq(1, cx_count("SELECT COUNT(*) FROM activity_logs WHERE action = 'order.status_changed' AND subject_id = ? AND user_id IS NULL", [$o['id']]) >= 0 ? 1 : 0, 'activity logged');
T::ok(cx_count("SELECT COUNT(*) FROM activity_logs WHERE action = 'order.status_changed' AND subject_type = 'App\\\\Models\\\\Order' AND subject_id = ?", [$o['id']]) === 4, 'four status changes logged');

T::$current = 'cancel restocks once and frees the coupon';
cx_fresh();
cx_coupon(['code' => 'FREEME']);
cx_shipping(250);
$p = Fx::product(['price' => 1000, 'stock' => 10]);
cx_add($p, null, 3);
http('POST', '/cart/coupon', ['code' => 'FREEME']);
http('POST', '/checkout', cx_payload());
$o = cx_last_order();
T::eq(7, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'sold 3');
OrderService::cancel($o, null);
T::eq(10, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'restocked');
T::eq(0, (int) $db->value('SELECT sold_count FROM products WHERE id = ?', [$p['id']]), 'sold count back');
T::eq(0, (int) $db->value('SELECT SUM(`change`) FROM stock_movements WHERE order_id = ?', [$o['id']]), 'ledger reconciles');
T::eq(1, cx_count("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND reason = 'cancellation' AND `change` = 3", [$o['id']]), 'cancellation movement');
T::eq(0, cx_count('SELECT COUNT(*) FROM coupon_usages WHERE order_id = ?', [$o['id']]), 'coupon redemption released');
T::throws(CartException::class, static fn () => OrderService::cancel($o), 'cannot cancel twice', 'can no longer be cancelled');
T::eq(10, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'no double restock');
[$paidOrder] = cx_order();
PaymentService::verifyManually($paidOrder, $staff, null, (int) $paidOrder['total']);
T::throws(CartException::class, static fn () => OrderService::cancel($paidOrder), 'paid order not self-cancellable');

T::$current = 'inventory service';
$p = Fx::product(['stock' => 2]);
T::throws(InsufficientStockException::class, static fn () => InventoryService::adjust($p, -3, 'sale'), 'oversell refused', 'has 2 available');
T::eq(2, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'unchanged');
T::eq(5, InventoryService::adjust($p, 3, 'restock', null, $staff, 'Delivery'), 'restock returns balance');
T::eq(12, InventoryService::setLevel($p, 12, $staff, 'Stock-take'), 'setLevel');
$mv = $db->one('SELECT * FROM stock_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$p['id']]);
T::ok((int) $mv['change'] === 7 && (int) $mv['balance_after'] === 12 && $mv['reason'] === 'adjustment' && (int) $mv['user_id'] === (int) $staff['id'], 'setLevel records the difference');
T::eq(12, InventoryService::setLevel($p, 12), 'same level writes nothing');
T::throws(InsufficientStockException::class, static fn () => InventoryService::setLevel($p, -1), 'negative refused');
$v = Fx::variant((int) $p['id'], ['stock' => 1]);
InventoryService::adjust($v, -1, 'sale');
$mv = $db->one('SELECT * FROM stock_movements ORDER BY id DESC LIMIT 1');
T::ok((int) $mv['variant_id'] === (int) $v['id'] && (int) $mv['product_id'] === (int) $p['id'] && (int) $mv['balance_after'] === 0, 'variant movement');

/* ---------------- payments ---------------- */
T::$current = 'cod verified by staff only';
[$o] = cx_order('cod');
T::throws(CartException::class, static fn () => PaymentService::verifyManually($o, $staff, null, 1), 'exact amount required', 'must equal the order total');
PaymentService::verifyManually($o, $staff, null, (int) $o['total'], 'Cash collected by rider');
$o = OrderService::find((int) $o['id']);
T::eq(['paid', 'confirmed'], [$o['payment_status'], $o['status']], 'paid + confirmed');
T::eq(1, cx_count("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'paid' AND verified_by = ?", [$o['id'], $staff['id']]), 'verified by staff');
T::throws(CartException::class, static fn () => PaymentService::verifyManually($o, $staff), 'already paid', 'already marked as paid');
[$c] = cx_order('cod');
OrderService::changeStatus($c, 'cancelled', $staff);
T::throws(CartException::class, static fn () => PaymentService::verifyManually($c, $staff), 'cancelled cannot be paid', 'cancelled order');

T::$current = 'bank transfer';
Settings::forget('bank.details');
cx_fresh();
cx_add(Fx::product());
T::ok(!str_contains(http('GET', '/checkout')->body, 'Bank Transfer'), 'hidden without details');
[$o] = cx_order('bank_transfer');
$conf = http('GET', '/order/' . $o['number'] . '/thank-you?token=' . $o['access_token']);
T::ok($conf->status === 200 && str_contains($conf->body, 'TEST BANK, account 0000-0000') && str_contains($conf->body, 'Submit payment details'), 'confirmation shows bank details and the form');
T::ok(str_contains(Mailer::$sent[0]['html'] ?? '', 'TEST BANK, account 0000-0000') && str_contains(Mailer::$sent[0]['html'] ?? '', 'quote <strong>' . $o['number'] . '</strong>'), 'email carries the bank details');
$path = '/order/' . $o['number'] . '/payment-reference';
cx_upload($path, ['token' => $o['access_token']], []);
T::eq('Enter your bank reference or upload a photo of the receipt.', cx_err('reference'), 'reference or receipt required');
foreach (['evil.png' => '<?php echo "x"; system($_GET[1]);', 'evil.pdf' => '<html><script>alert(1)</script></html>', 'page.html' => '<html><script>alert(1)</script></html>'] as $n => $content) {
    cx_upload($path, ['token' => $o['access_token']], ['proof' => cx_file($n, $content)]);
    T::eq('The receipt must be a JPG, PNG or PDF file.', cx_err('proof'), "disguised {$n} refused");
}
$big = Fx::image(10, 10, 'jpg');
file_put_contents($big['tmp_name'], str_repeat('x', 5000 * 1024), FILE_APPEND);
clearstatcache();
cx_upload($path, ['token' => $o['access_token']], ['proof' => $big]);
T::eq('The receipt must be 4 MB or smaller.', cx_err('proof'), 'over 4 MB refused');
T::eq('unpaid', $db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]), 'still unpaid');
T::eq(404, cx_upload($path, ['reference' => 'X-1'], [])->status, 'needs the private link');
T::eq(404, cx_upload($path, ['token' => 'wrong', 'reference' => 'X-1'], [])->status, 'wrong token 404');
$r = cx_upload($path, ['token' => $o['access_token']], ['proof' => Fx::image(300, 300, 'png')]);
T::eq('Thanks. We will verify your transfer and update your order.', cx_flash('status'), 'receipt accepted');
$pay = $db->one("SELECT * FROM payments WHERE order_id = ? AND provider = 'bank_transfer'", [$o['id']]);
T::ok(str_starts_with((string) $pay['proof_path'], 'payment-proofs/' . $o['id'] . '/') && !str_contains($pay['proof_path'], 'photo') && is_file(App\Core\Uploads::privatePath($pay['proof_path'])), 'stored privately under a random name');
T::ok($pay['status'] === 'pending' && $pay['proof_submitted_at'] !== null, 'payment pending with a date');
T::eq('awaiting_verification', $db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]), 'awaiting verification, not paid');
T::ok(cx_count("SELECT COUNT(*) FROM notifications WHERE data LIKE '%payment_reported%'") >= 1, 'staff alerted');
[$o2] = cx_order('bank_transfer');
cx_upload('/order/' . $o2['number'] . '/payment-reference', ['token' => $o2['access_token'], 'reference' => 'DUP-1', 'proof' => null], ['proof' => cx_file('slip.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")]);
$pay2 = $db->one("SELECT * FROM payments WHERE order_id = ? AND provider = 'bank_transfer'", [$o2['id']]);
T::ok($pay2['reference'] === 'DUP-1' && str_ends_with((string) $pay2['proof_path'], '.pdf'), 'pdf + reference accepted');
[$o3] = cx_order('bank_transfer');
cx_upload('/order/' . $o3['number'] . '/payment-reference', ['token' => $o3['access_token'], 'reference' => 'DUP-1'], []);
T::contains('already used for another order', cx_err('reference'), 'reference cannot be reused');
PaymentService::verifyManually($o2, $staff, null, (int) $o2['total']);
T::eq('paid', $db->value('SELECT payment_status FROM orders WHERE id = ?', [$o2['id']]), 'staff verification is the way to paid');
PaymentService::rejectManually($o3, $staff, 'Nothing arrived');
T::eq('failed', $db->value('SELECT payment_status FROM orders WHERE id = ?', [$o3['id']]), 'reject');

T::$current = 'confirmation access';
[$o] = cx_order('cod', 1000, 1);
$url = '/order/' . $o['number'] . '/thank-you';
$r = http('GET', $url . '?token=' . $o['access_token']);
T::ok($r->status === 200 && str_contains($r->body, $o['number']) && str_contains($r->body, '1,250') && str_contains($r->body, 'Cash on Delivery'), 'shown with the private link');
T::eq(404, http('GET', $url)->status, 'no token 404');
T::eq(404, http('GET', $url . '?token=wrong')->status, 'wrong token 404');
T::eq(404, http('GET', $url . '?token[]=x')->status, 'array token 404');
$owner = Fx::user('customer');
cx_fresh();
Auth::actingAs($owner);
cx_add(Fx::product(['price' => 500]));
http('POST', '/checkout', cx_payload(['email' => $owner['email']]), $owner);
$mine = cx_last_order();
T::eq((int) $owner['id'], (int) $mine['user_id'], 'order belongs to the customer');
T::eq(200, http('GET', '/order/' . $mine['number'] . '/thank-you', [], $owner)->status, 'owner sees it without a token');
T::eq(404, http('GET', '/order/' . $mine['number'] . '/thank-you', [], Fx::user('customer'))->status, 'another customer does not');

/* ---------------- card + webhooks ---------------- */
T::$current = 'card disabled until configured';
$card = new CardGateway();
T::ok(!$card->isAvailable() && count($card->missing()) === 4, 'disabled, says what is missing');
Config::set('payments.card', ['driver' => 'CxFakeCardDriver', 'key' => 'k', 'secret' => '', 'webhook_secret' => '']);
T::ok(!(new CardGateway())->isAvailable(), 'partial config stays disabled');
Config::set('payments.card', ['driver' => 'stdClass', 'key' => 'k', 'secret' => 's', 'webhook_secret' => 'w']);
T::ok(!(new CardGateway())->isAvailable(), 'non-driver class refused');
Config::set('payments.card', $cxCard);
T::eq(400, cx_hook('card', ['order' => 'ABH-1', 'ref' => 'x', 'amount' => 1, 'state' => 'paid'], ['X-Sig' => 'valid'])->status, 'unconfigured card webhook verifies nothing');
T::eq(404, cx_hook('jazzcash', [])->status, 'unknown provider 404');
T::eq(404, cx_hook('cod', [])->status, 'offline gateway has no webhook');

T::$current = 'card configured (test double)';
Config::set('payments.card', ['driver' => 'CxFakeCardDriver', 'key' => 'test-key', 'secret' => 'test-secret', 'webhook_secret' => 'test-hook']);
Settings::put('payments.enabled', ['cod', 'bank_transfer', 'card']);
cx_fresh();
cx_shipping(250);
cx_add(Fx::product(['price' => 1000]));
T::contains('Debit / Credit Card', http('GET', '/checkout')->body, 'card offered');
$r = http('POST', '/checkout', cx_payload(['payment_method' => 'card']));
$o = cx_last_order();
T::eq('https://provider.example/pay/' . $o['number'], $r->headers['Location'] ?? null, 'hands off to the provider');
T::eq('pending', $o['payment_status'], 'never paid by the redirect');
T::eq(1, cx_count("SELECT COUNT(*) FROM payments WHERE order_id = ? AND provider = 'card' AND reference = ? AND status = 'pending' AND amount = 1250", [$o['id'], 'TXN-' . $o['number']]), 'pending payment with reference');
$hook = static fn (array $d, string $sig = 'valid') => cx_hook('card', $d + ['order' => $o['number'], 'amount' => 1250], ['X-Sig' => $sig]);
$st = static fn () => $db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]);
T::eq(400, $hook(['ref' => 'T1', 'state' => 'paid'], 'forged')->status, 'forged signature');
T::eq('pending', $st(), 'unchanged');
T::eq(200, $hook(['ref' => 'T1', 'state' => 'pending'])->status, 'pending event');
T::eq(200, $hook(['ref' => 'T1', 'state' => 'failed'])->status, 'failed event');
T::eq('failed', $st(), 'failed recorded');
$conf = http('GET', '/order/' . $o['number'] . '/thank-you?token=' . $o['access_token'])->body;
T::ok(str_contains($conf, 'did not go through') && str_contains($conf, 'Try card payment again'), 'retry offered');
T::eq('https://provider.example/pay/' . $o['number'], http('POST', '/order/' . $o['number'] . '/pay', ['token' => $o['access_token']])->headers['Location'] ?? null, 'retry hands off');
T::eq(404, http('POST', '/order/' . $o['number'] . '/pay')->status, 'retry needs the private link');
T::eq(422, $hook(['ref' => 'T2', 'state' => 'paid', 'amount' => 5])->status, 'wrong amount refused');
Mailer::$sent = [];
T::eq(200, $hook(['ref' => 'T2', 'state' => 'paid'])->status, 'paid event');
T::eq(['paid', 'confirmed'], [$st(), $db->value('SELECT status FROM orders WHERE id = ?', [$o['id']])], 'paid + confirmed');
T::eq(1, count(array_filter(Mailer::$sent, static fn ($m) => str_contains($m['subject'], 'Payment received'))), 'one payment email');
$hook(['ref' => 'T2', 'state' => 'paid']);
$hook(['ref' => 'T2', 'state' => 'failed']);
$hook(['ref' => 'T1', 'state' => 'pending']);
T::eq('paid', $st(), 'never downgraded');
T::eq(1, count(array_filter(Mailer::$sent, static fn ($m) => str_contains($m['subject'], 'Payment received'))), 'no repeat email');
T::eq(1, cx_count("SELECT COUNT(*) FROM payments WHERE reference = 'T2'"), 'idempotent');
Config::set('payments.card', $cxCard);
Settings::forget('payments.enabled');

T::$current = 'generic webhook pipeline';
Config::set('payments.gateways', $cxGateways + ['stub' => 'CxStubGateway']);
$sid = Fx::insert('orders', ['number' => 'ABH-TEST-0001', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'stub', 'shipping_method_name' => 'Standard', 'email' => 't@example.com', 'subtotal' => 2250, 'total' => 2250, 'access_token' => str_repeat('a', 40), 'created_at' => now()]);
T::eq(400, cx_hook('stub', ['order' => 'ABH-TEST-0001', 'ref' => 'X1', 'amount' => 2250])->status, 'invalid signature');
T::eq(422, cx_hook('stub', ['order' => 'ABH-TEST-0001', 'ref' => 'X2', 'amount' => 1], ['X-Signature' => 'valid'])->status, 'amount mismatch');
T::eq(200, cx_hook('stub', ['order' => 'ABH-TEST-0001', 'ref' => 'X1', 'amount' => 2250], ['X-Signature' => 'valid'])->status, 'verified');
T::eq(200, cx_hook('stub', ['order' => 'ABH-TEST-0001', 'ref' => 'X1', 'amount' => 2250], ['X-Signature' => 'valid'])->status, 'duplicate ok');
T::eq(1, cx_count("SELECT COUNT(*) FROM payments WHERE order_id = ? AND reference = 'X1' AND status = 'paid'", [$sid]), 'one paid payment');
$db->exec("UPDATE orders SET payment_method = 'cod', payment_status = 'unpaid' WHERE id = ?", [$sid]);
T::eq(422, cx_hook('stub', ['order' => 'ABH-TEST-0001', 'ref' => 'X5', 'amount' => 2250], ['X-Signature' => 'valid'])->status, 'cannot pay another method');
T::eq(413, cx_hook('stub', ['pad' => str_repeat('x', 70000)])->status, 'large body refused');
Config::set('payments.gateways', $cxGateways);
$body = '{"a":1}';
$sig = hash_hmac('sha256', $body, 'secret');
T::ok(WebhookSignature::verifyHmac($body, 'sha256=' . $sig, 'secret') && !WebhookSignature::verifyHmac($body, $sig, '') && !WebhookSignature::verifyHmac($body . ' ', $sig, 'secret'), 'HMAC helper');
T::ok(!WebhookSignature::verifyHmac($body, hash_hmac('sha256', (time() - 999) . '.' . $body, 's'), 's', 'sha256', time() - 999), 'replay window');

/* ---------------- tracking ---------------- */
T::$current = 'track order';
[$o] = cx_order('cod', 1000, 1);
T::eq(200, http('GET', '/track-order')->status, 'form');
cx_fresh();
http('POST', '/track-order', ['number' => $o['number'], 'email' => 'wrong@example.com']);
T::eq('We could not find an order matching those details.', cx_err('number'), 'same message for a wrong email');
OrderService::setTracking($o, 'SR-77', 'https://example.com/t/SR-77', $staff, 'Swift Rider');
$r = http('POST', '/track-order', ['number' => strtolower($o['number']), 'email' => 'BUYER@example.com']);
T::ok($r->status === 200 && str_contains($r->body, 'Order ' . $o['number']) && str_contains($r->body, 'Tracking number: <strong>SR-77</strong>') && str_contains($r->body, 'Swift Rider') && str_contains($r->body, 'Order placed'), 'found with tracking and updates');
T::eq(422, http('POST', '/track-order', ['number' => '', 'email' => ''], null, ['json' => true])->status, 'validation');

