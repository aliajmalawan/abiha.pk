<?php

declare(strict_types=1);

/*
 * Slice M2: admin sign-in, dashboard, orders, shipments, shipping methods/rules, payment settings + history, customers,
 * staff, coupons, messages, newsletter, reports, activity log, alerts and the permission matrix of these routes.
 * Ports the intent of the old AdminAccessTest, AdminOperationsTest, AdminRolesTest, DashboardOverviewTest,
 * MenusShipmentsPaymentHistoryTest (shipments/payment history), SettingsShippingMarketingTest (rules, payments, sign-in),
 * PaymentsTest (receipt access, verification), DataIntegrityTest (timeline, alerts), BrandingTest (charts), QaCommerceTest
 * (sign-in redirect), CouponRestrictionTest / CatalogAdminTest (coupon admin) and CartPersistenceAndContentTest (messages).
 */

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Support\Settings;

reset_db();
$db = Database::get();

/** A fresh guest "browser" with no rate-limit history. */
function ms_fresh(): void
{
    Auth::actingAs(null);
    Session::resetMemory();
    Database::get()->exec('DELETE FROM rate_limits');
    Settings::flush();
}

/** Guest order through the real cart + checkout (shipping 250). Returns [order (OrderService::find), product]. */
function ms_order(string $method = 'cod', int $price = 1000, int $qty = 1, array $over = [], ?array $product = null): array
{
    ms_fresh();
    $db = Database::get();
    if (!$db->value("SELECT id FROM shipping_methods WHERE code = 'standard'")) {
        Fx::insert('shipping_methods', ['code' => 'standard', 'name' => 'Standard', 'cost' => 250, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    if ($method === 'bank_transfer' && !Settings::get('bank.details')) {
        Settings::put('bank.details', 'TEST BANK, account 0000-0000 (test data)');
    }
    $p = $product ?? Fx::product(['price' => $price, 'stock' => 10]);
    http('POST', '/cart/items', ['product_id' => $p['id'], 'quantity' => $qty], false, ['json' => true]);
    http('POST', '/checkout', array_merge([
        'name' => 'Test Buyer', 'phone' => '03001234567', 'email' => 'buyer@example.com', 'province' => 'Punjab', 'city' => 'Lahore',
        'address' => 'House 1, Street 2, Sample Town', 'shipping_method' => 'standard', 'payment_method' => $method,
    ], $over));
    $id = (int) $db->value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
    Auth::actingAs(null);
    return [OrderService::find($id), $p];
}

function ms_err(string $field): string
{
    $e = Session::get('_flash_new')['errors'][$field] ?? '';
    return is_array($e) ? (string) reset($e) : (string) $e;
}

function ms_status(): ?string
{
    return Session::get('_flash_new')['status'] ?? null;
}

function ms_val(string $sql, array $p = []): mixed
{
    return Database::get()->value($sql, $p);
}

function ms_role(string $slug): int
{
    return (int) ms_val('SELECT id FROM roles WHERE slug = ?', [$slug]);
}

$admin = Fx::user('admin');

/* ---------------- staff sign-in ---------------- */
T::$current = 'admin login';
ms_fresh();
$r = http('GET', '/admin', [], false);
T::eq('http://localhost/abiha.pk/admin/login', $r->headers['Location'] ?? null, 'guests are sent to the staff sign-in');
$r = http('GET', '/admin/login', [], false);
T::ok($r->status === 200 && str_contains($r->body, 'Staff area') && str_contains($r->body, 'Staff sign in | ABIHA® Admin'), 'sign-in page renders');
$staffU = Fx::user('manager');
$custU = Fx::user('customer');
$blocked = Fx::user('admin', ['is_active' => 0]);
foreach ([[$custU['email'], Fx::PASSWORD], [$blocked['email'], Fx::PASSWORD], [$staffU['email'], 'wrong']] as [$em, $pw]) {
    ms_fresh();
    http('POST', '/admin/login', ['email' => $em, 'password' => $pw], false);
    T::eq('These credentials do not match a staff account.', ms_err('email'), "one generic error for {$em}");
    T::ok(!Auth::check(), 'still a guest');
}
ms_fresh();
$r = http('POST', '/admin/login', ['email' => $staffU['email'], 'password' => Fx::PASSWORD], false);
T::eq('http://localhost/abiha.pk/admin', $r->headers['Location'] ?? null, 'staff land on the dashboard');
T::eq((int) $staffU['id'], Auth::id(), 'signed in');
T::ok(ms_val('SELECT last_login_at FROM users WHERE id = ?', [$staffU['id']]) !== null, 'last_login_at stamped');
$r = http('GET', '/admin/login');
T::eq('http://localhost/abiha.pk/admin', $r->headers['Location'] ?? null, 'signed-in staff skip the sign-in page');
// intended page: admin pages yes, storefront leftovers no
ms_fresh();
http('GET', '/admin/orders', [], false);
$r = http('POST', '/admin/login', ['email' => $staffU['email'], 'password' => Fx::PASSWORD]);
T::eq('http://localhost/abiha.pk/admin/orders', $r->headers['Location'] ?? null, 'back to the admin page they were after');
ms_fresh();
http('GET', '/account', [], false);
$r = http('POST', '/admin/login', ['email' => $staffU['email'], 'password' => Fx::PASSWORD]);
T::eq('http://localhost/abiha.pk/admin', $r->headers['Location'] ?? null, 'never to a leftover storefront page');
// lockout: 5 failures, then even the right password is refused
ms_fresh();
for ($i = 0; $i < 5; $i++) {
    http('POST', '/admin/login', ['email' => $staffU['email'], 'password' => 'nope'], false);
}
http('POST', '/admin/login', ['email' => $staffU['email'], 'password' => Fx::PASSWORD]);
T::ok(str_starts_with(ms_err('email'), 'Too many attempts.') && !Auth::check(), 'admin sign-in is rate limited');
ms_fresh();

/* ---------------- permission matrix ---------------- */
T::$current = 'permission matrix';
$pages = ['/admin' => 'dashboard', '/admin/orders' => 'orders', '/admin/shipments' => 'orders', '/admin/payments' => 'settings', '/admin/shipping' => 'shipping',
    '/admin/customers' => 'customers', '/admin/coupons' => 'coupons', '/admin/messages' => 'messages', '/admin/newsletter' => 'messages', '/admin/reports' => 'reports',
    '/admin/staff' => 'staff', '/admin/activity' => 'logs', '/admin/payment-history' => 'payments', '/admin/notifications' => 'dashboard'];
$reach = static function (array $user) use ($pages): array {
    $ok = [];
    foreach (array_keys($pages) as $url) {
        ms_fresh();
        $s = http('GET', $url, [], $user)->status;
        T::ok(in_array($s, [200, 403], true), "{$url} answered {$s}");
        if ($s === 200) {
            $ok[] = $url;
        }
    }
    return $ok;
};
T::eq(array_keys($pages), $reach($admin), 'Super Admin reaches every page');
$m = $reach(Fx::user('manager'));
T::ok(!in_array('/admin/staff', $m, true) && count($m) === count($pages) - 1, 'Admin (manager) reaches everything except staff');
T::eq(['/admin', '/admin/orders', '/admin/shipments', '/admin/messages', '/admin/newsletter', '/admin/payment-history', '/admin/notifications'], $reach(Fx::user('staff')), 'Order Manager');
T::eq(['/admin', '/admin/notifications'], $reach(Fx::user('inventory_manager')), 'Inventory Manager');
$cm = $reach(Fx::user('content_manager'));
T::ok(in_array('/admin/messages', $cm, true) && !in_array('/admin/orders', $cm, true) && !in_array('/admin/coupons', $cm, true), 'Content Manager');
ms_fresh();
T::eq(403, http('GET', '/admin', [], $custU)->status, 'customers get 403');
T::eq(403, http('GET', '/admin/orders/export', [], Fx::user('product_manager'))->status, 'export needs orders.view');
T::eq(405, http('POST', '/admin/orders/export', [], Fx::user('product_manager'))->status, 'wrong verb is no back door');
T::eq(403, http('POST', '/admin/coupons', ['code' => 'FREE', 'type' => 'percent', 'value' => 100], Fx::user('content_manager'))->status, 'action refused by the server');
T::eq(0, (int) ms_val('SELECT COUNT(*) FROM coupons'), 'nothing created');
ms_fresh();

/* ---------------- dashboard ---------------- */
T::$current = 'dashboard';
$d = App\Controllers\Admin\DashboardController::data();
foreach (['revenue', 'orders', 'units_sold', 'avg_order', 'pending', 'completed', 'products', 'low_stock'] as $k) {
    T::eq(0, (int) $d['stats'][$k], "empty shop: {$k} is 0");
}
$html = http('GET', '/admin', [], $admin)->body;
T::contains('No orders yet.', $html, 'empty recent orders');
[$keep] = ms_order('cod', 1000, 2);                 // 2,250
[$gone] = ms_order('cod', 5000, 1);                 // 5,250 -> cancelled
OrderService::changeStatus($gone, 'cancelled', $admin);
Fx::product(['stock' => 2, 'low_stock_threshold' => 5]);
Fx::product(['stock' => 0]);
$d = App\Controllers\Admin\DashboardController::data();
T::eq([2250, 2, 2, 1, 0], [$d['stats']['revenue'], $d['stats']['units_sold'], $d['stats']['orders'], $d['stats']['pending'], $d['stats']['collected']], 'figures exclude cancelled orders');
T::eq([1, 1], [$d['stats']['low_stock'], $d['stats']['out_of_stock']], 'low / out of stock');
T::eq(12, count($d['months']), '12 months');
T::eq(2250, (int) end($d['months'])['revenue'], 'this month holds the order');
T::eq([$gone['number'], $keep['number']], array_column($d['recentOrders'], 'number'), 'newest orders first');
T::eq(1, count($d['topAllTime']), 'cancelled product is not a best seller');
ms_fresh();
$r = http('GET', '/admin', [], $admin);
$html = $r->body;
T::ok($r->status === 200 && str_contains($html, 'Total revenue') && str_contains($html, 'PKR 2,250') && !str_contains($html, 'PKR 7,500'), 'revenue shown');
preg_match_all('/<canvas data-chart="([^"]+)"[^>]*role="img" aria-label="([^"]+)"/', $html, $mm);
T::eq(2, count($mm[1]), 'two Chart.js canvases');
$c0 = json_decode(html_entity_decode($mm[1][0] ?? ''), true) ?: [];
$c1 = json_decode(html_entity_decode($mm[1][1] ?? ''), true) ?: [];
T::ok(count($c0['values'] ?? []) === 12 && count($c1['values'] ?? []) === 30 && ($c0['symbol'] ?? '') === 'PKR' && array_sum($c0['values']) > 0, 'chart data: 12 months, 30 days, real figures');
T::eq(1, preg_match_all('#<script[^>]+admin-charts#', $html), 'chart script included once');
T::ok((bool) preg_match('#<script type="module" src="[^"]+admin-charts\.js[^"]*" nonce="([^"]+)"#', $html, $nm) && str_contains($r->headers['Content-Security-Policy'] ?? '', "'nonce-" . $nm[1] . "'"), 'chart script carries the page nonce');
T::ok((bool) preg_match('#<noscript>\s*<svg#', $html) && str_contains($html, 'View as table'), 'no-JS fallback and table');
T::ok(!str_contains(http('GET', '/', [], false)->body, 'admin-charts'), 'storefront never loads Chart.js');
ms_fresh();

/* ---------------- orders: list, detail, invoice, export ---------------- */
T::$current = 'orders list';
$r = http('GET', '/admin/orders', [], $admin);
T::ok($r->status === 200 && str_contains($r->body, $keep['number']) && str_contains($r->body, 'Cancelled (1)'), 'list with status counts');
$r = http('GET', '/admin/orders?status=cancelled');
T::ok(str_contains($r->body, $gone['number']) && !str_contains($r->body, $keep['number']), 'status filter');
$r = http('GET', '/admin/orders?q=' . urlencode($keep['number']));
T::ok(str_contains($r->body, $keep['number']) && !str_contains($r->body, $gone['number']), 'search by number');
T::ok(str_contains(http('GET', '/admin/orders?q=Test%20Buyer')->body, $keep['number']), 'search by customer name');
http('GET', '/admin/orders?status=bogus');
T::ok(ms_err('status') !== '', 'invalid filter refused');
$r = http('GET', '/admin/orders/' . $keep['number']);
T::ok($r->status === 200 && str_contains($r->body, 'Order ' . $keep['number']) && str_contains($r->body, 'Guest checkout') && str_contains($r->body, 'Update status'), 'detail page');
T::eq(404, http('GET', '/admin/orders/ABH-NOPE')->status, 'unknown order 404');
$r = http('GET', '/admin/orders/' . $keep['number'] . '/invoice');
T::ok($r->status === 200 && str_contains($r->body, 'Invoice ' . $keep['number']) && str_contains($r->body, 'window.print'), 'printable invoice');
[$fx] = ms_order('cod', 1000, 1, ['name' => '=HYPERLINK("http://x")']);
$r = http('GET', '/admin/orders/export', [], $admin);
T::ok(str_starts_with($r->body, "\xEF\xBB\xBFOrder,Date,Status") && str_contains($r->body, $fx['number']) && str_contains($r->body, "'=HYPERLINK"), 'CSV export, formulas neutralised');
T::ok(str_contains($r->headers['Content-Type'] ?? '', 'text/csv'), 'csv type');
$r = http('GET', '/admin/orders/export?method=bank_transfer');
T::ok(!str_contains($r->body, $fx['number']), 'export honours filters');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'orders.exported'"), 'export logged');
ms_fresh();

/* ---------------- orders: status machine, tracking, shipments ---------------- */
T::$current = 'order status machine';
[$o, $p] = ms_order('cod', 1000, 2);
T::eq(8, (int) ms_val('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'stock taken');
$url = '/admin/orders/' . $o['number'];
http('PATCH', $url . '/status', ['status' => 'returned'], $admin);
T::ok(ms_err('status') !== '' && ms_val('SELECT status FROM orders WHERE id = ?', [$o['id']]) === 'pending', 'pending cannot be returned');
foreach (['confirmed', 'processing'] as $s) {
    http('PATCH', $url . '/status', ['status' => $s]);
}
http('PATCH', $url . '/tracking', ['courier' => '<b>Swift</b> Rider', 'tracking_number' => 'SR-123', 'tracking_url' => 'https://example.com/t/SR-123']);
T::eq('Shipping details saved.', ms_status(), 'tracking saved');
$sh = $db->one('SELECT * FROM shipments WHERE order_id = ?', [$o['id']]);
T::eq(['Swift Rider', 'SR-123', 'pending'], [$sh['courier'], $sh['tracking_number'], $sh['status']], 'shipment record, tags stripped');
http('PATCH', $url . '/tracking', ['tracking_url' => 'javascript:alert(1)']);
T::ok(ms_err('tracking_url') !== '', 'tracking link must be http(s)');
http('PATCH', $url . '/status', ['status' => 'shipped', 'note' => 'Handed over']);
T::eq('Order status updated.', ms_status(), 'status flash');
T::eq(['details_updated', 'shipped'], $db->column('SELECT status FROM shipment_events WHERE shipment_id = ? ORDER BY id', [$sh['id']]), 'timeline records what staff did');
$r = http('GET', '/admin/shipments');
T::ok(str_contains($r->body, $o['number']) && str_contains($r->body, 'Swift Rider') && str_contains($r->body, 'SR-123'), 'shipments list');
T::ok(str_contains(http('GET', '/admin/shipments?q=SR-123')->body, $o['number']), 'shipments search');
T::contains('No shipments yet.', http('GET', '/admin/shipments?status=delivered')->body, 'shipments filter');
http('GET', '/admin/shipments?status=bogus');
T::ok(ms_err('status') !== '', 'invalid shipment status refused');
$r = http('GET', $url);
T::ok(str_contains($r->body, 'Shipment timeline (2)') && str_contains($r->body, 'Courier details saved by staff'), 'timeline on the order');
http('PATCH', $url . '/status', ['status' => 'delivered']);
http('PATCH', $url . '/status', ['status' => 'returned', 'note' => 'Wrong size']);
T::eq(8, (int) ms_val('SELECT stock FROM products WHERE id = ?', [$p['id']]), 'return without the box: no restock');
T::eq('returned', ms_val('SELECT status FROM orders WHERE id = ?', [$o['id']]), 'returned');
http('PATCH', $url . '/status', ['status' => 'delivered']);
T::ok(ms_err('status') !== '', 'returned is final');
[$o2, $p2] = ms_order('cod', 1000, 3);
foreach (['confirmed', 'shipped', 'delivered'] as $s) {
    OrderService::changeStatus($o2, $s, $admin);
}
http('PATCH', '/admin/orders/' . $o2['number'] . '/status', ['status' => 'returned', 'restock' => 1], $admin);
T::eq(10, (int) ms_val('SELECT stock FROM products WHERE id = ?', [$p2['id']]), 'ticked box restocks');
T::ok((bool) ms_val("SELECT 1 FROM stock_movements WHERE order_id = ? AND reason = 'return' AND `change` = 3", [$o2['id']]), 'ledger entry');
[$o3, $p3] = ms_order('cod', 1000, 1);
http('PATCH', '/admin/orders/' . $o3['number'] . '/status', ['status' => 'cancelled'], $admin);
T::eq(10, (int) ms_val('SELECT stock FROM products WHERE id = ?', [$p3['id']]), 'cancel restocks');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'order.status_changed' AND subject_id = ?", [$o3['id']]), 'status change logged');
T::eq(403, http('PATCH', '/admin/orders/' . $o3['number'] . '/status', ['status' => 'confirmed'], Fx::user('content_manager'))->status, 'orders.manage required');
ms_fresh();

/* ---------------- payments: verify, reject, refund, receipt ---------------- */
T::$current = 'payments';
[$bt] = ms_order('bank_transfer', 1000, 1);
$tmp = BASE_PATH . '/tests/.tmp/receipt-' . bin2hex(random_bytes(3)) . '.png';
$img = imagecreatetruecolor(20, 20);
imagepng($img, $tmp);
PaymentService::submitTransferReference($bt, 'TXN-42', ['name' => 'r.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
$pay = $db->one("SELECT * FROM payments WHERE order_id = ? AND provider = 'bank_transfer'", [$bt['id']]);
$proof = '/admin/orders/' . $bt['number'] . '/payment/' . $pay['id'] . '/proof';
T::ok(http('GET', $proof, [], false)->status !== 200, 'guests cannot open the receipt');
T::eq(403, http('GET', $proof, [], $custU)->status, 'customers cannot');
T::eq(403, http('GET', $proof, [], Fx::user('content_manager'))->status, 'staff without payments.verify cannot');
$r = http('GET', $proof, [], $admin);
T::ok($r->status === 200 && ($r->headers['X-Content-Type-Options'] ?? '') === 'nosniff' && str_contains($r->headers['Content-Security-Policy'] ?? '', 'sandbox'), 'staff stream the private receipt');
T::eq(404, http('GET', '/admin/orders/' . $keep['number'] . '/payment/' . $pay['id'] . '/proof')->status, 'receipt only under its own order');
$r = http('GET', '/admin/orders/' . $bt['number']);
T::ok(str_contains($r->body, 'View receipt') && str_contains($r->body, 'Payment history (2)') && str_contains($r->body, 'Transfer reported') && str_contains($r->body, 'TXN-42'), 'payment block');
$burl = '/admin/orders/' . $bt['number'] . '/payment/';
http('POST', $burl . 'refund');
T::ok(ms_err('payment') !== '', 'unpaid: nothing to refund');
http('POST', $burl . 'verify', ['amount' => (int) $bt['total'] - 1]);
T::ok(str_contains(ms_err('payment'), 'must equal the order total'), 'amount must match');
http('POST', $burl . 'verify', ['amount' => (int) $bt['total']]);
T::eq('Payment verified.', ms_status(), 'verified');
T::eq(['paid', 'confirmed'], array_values($db->one('SELECT payment_status, status FROM orders WHERE id = ?', [$bt['id']])), 'paid + confirmed');
T::eq((int) $admin['id'], (int) ms_val("SELECT verified_by FROM payments WHERE order_id = ? AND status = 'paid'", [$bt['id']]), 'who verified');
T::eq(['initiated', 'transfer_reported', 'verified'], $db->column('SELECT type FROM payment_transactions WHERE order_id = ? ORDER BY id', [$bt['id']]), 'payment history');
http('POST', $burl . 'reject');
T::ok(ms_err('payment') !== '', 'a paid order cannot be rejected');
http('POST', $burl . 'refund');
T::ok(str_contains(ms_err('payment'), 'Cancel the order'), 'refund needs cancel/return first');
OrderService::changeStatus($bt, 'cancelled', $admin);
http('POST', $burl . 'refund', ['note' => 'Bank ref 123']);
T::ok(ms_status() === 'Refund recorded.' && ms_val('SELECT payment_status FROM orders WHERE id = ?', [$bt['id']]) === 'refunded', 'refund recorded');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'payment.refund_recorded'"), 'refund logged');
[$bt2] = ms_order('bank_transfer', 1000, 1);
http('POST', '/admin/orders/' . $bt2['number'] . '/payment/reject', ['note' => 'Nothing arrived'], $admin);
T::ok(ms_status() === 'Payment marked as not received.' && ms_val('SELECT payment_status FROM orders WHERE id = ?', [$bt2['id']]) === 'failed', 'rejected');
T::eq(403, http('POST', '/admin/orders/' . $bt2['number'] . '/payment/verify', [], Fx::user('inventory_manager'))->status, 'payments.verify required');
// payment history page
$r = http('GET', '/admin/payment-history', [], $admin);
T::ok(str_contains($r->body, $bt['number']) && str_contains($r->body, 'Transfer reported') && str_contains($r->body, 'Verified') && str_contains($r->body, 'TXN-42'), 'payment history page');
$r = http('GET', '/admin/payment-history?status=paid');
T::ok(str_contains($r->body, 'Verified') && !str_contains($r->body, 'Transfer reported'), 'status filter');
T::contains('No payment records yet.', http('GET', '/admin/payment-history?provider=card')->body, 'provider filter');
http('GET', '/admin/payment-history?provider=paypal');
T::ok(ms_err('provider') !== '', 'unknown provider refused');
ms_fresh();

/* ---------------- shipping methods + rules ---------------- */
T::$current = 'shipping';
http('POST', '/admin/shipping', ['name' => 'Express', 'code' => 'Bad Code', 'cost' => 400], $admin);
T::ok(ms_err('code') !== '', 'code format');
http('POST', '/admin/shipping', ['name' => 'Express', 'code' => 'standard', 'cost' => 400]);
T::ok(str_contains(ms_err('code'), 'already been taken'), 'code unique');
http('POST', '/admin/shipping', ['name' => 'Express', 'code' => 'express', 'cost' => 400, 'eta' => '1-2 days', 'is_active' => 1]);
$mid = (int) ms_val("SELECT id FROM shipping_methods WHERE code = 'express'");
T::ok($mid > 0 && ms_status() === 'Shipping method created.', 'method created');
$rurl = "/admin/shipping/{$mid}/rules";
http('POST', $rurl, ['cost' => 100]);
T::eq('Choose a province, a city, or both.', ms_err('city'), 'rule needs a destination');
http('POST', $rurl, ['province' => 'Atlantis', 'cost' => 100]);
T::ok(ms_err('province') !== '', 'unknown province');
http('POST', $rurl, ['city' => 'Lahore', 'province' => 'Punjab', 'cost' => 100, 'eta' => '<b>Next</b> day', 'is_available' => 1]);
T::eq('Shipping rule added.', ms_status(), 'rule added');
http('POST', $rurl, ['city' => 'LAHORE', 'province' => 'Punjab', 'cost' => 200, 'is_available' => 1]);
T::ok(str_contains(ms_err('city'), 'already exists'), 'duplicate refused (case-insensitive)');
T::eq(['Next day'], $db->column('SELECT eta FROM shipping_rules WHERE shipping_method_id = ?', [$mid]), 'one rule, plain-text eta');
$r = http('GET', "/admin/shipping/{$mid}/edit");
T::ok(str_contains($r->body, 'Lahore, Punjab') && str_contains($r->body, 'City &amp; province rules'), 'rules under the edit form');
$rid = (int) ms_val('SELECT id FROM shipping_rules WHERE shipping_method_id = ?', [$mid]);
http('DELETE', "/admin/shipping-rules/{$rid}");
T::eq(0, (int) ms_val('SELECT COUNT(*) FROM shipping_rules WHERE shipping_method_id = ?', [$mid]), 'rule removed');
$std = (int) ms_val("SELECT id FROM shipping_methods WHERE code = 'standard'");
http('DELETE', "/admin/shipping/{$std}");
T::ok(str_contains(ms_err('delete'), 'Deactivate it instead'), 'a method orders used cannot be deleted');
http('DELETE', "/admin/shipping/{$mid}");
T::eq(0, (int) ms_val('SELECT COUNT(*) FROM shipping_methods WHERE id = ?', [$mid]), 'unused method deleted');
T::contains('Standard', http('GET', '/admin/shipping')->body, 'list');
ms_fresh();

/* ---------------- payment settings ---------------- */
T::$current = 'payment settings';
$r = http('GET', '/admin/payments', [], $admin);
T::ok(str_contains($r->body, 'Card payments are switched off') && str_contains($r->body, 'CARD_API_KEY') && str_contains($r->body, 'webhooks/payments/card'), 'card status read-only');
Settings::forget('payments.enabled');
http('PUT', '/admin/payments', ['enabled' => ['bank_transfer'], 'bank_details' => '']);
T::ok(ms_err('bank_details') !== '', 'bank transfer needs details');
http('PUT', '/admin/payments', ['enabled' => ['jazzcash']]);
T::ok(ms_err('enabled.0') !== '', 'unknown method refused');
http('PUT', '/admin/payments', ['enabled' => ['cod', 'card']]);
T::ok(str_contains(ms_err('enabled'), 'is not set up yet'), 'unconfigured card cannot be enabled');
Settings::flush();
T::eq(null, Settings::get('payments.enabled'), 'nothing saved on errors');
http('PUT', '/admin/payments', ['enabled' => ['cod', 'bank_transfer'], 'cod_note' => 'Keep exact change ready', 'bank_details' => "Bank X\nAcct 123"]);
Settings::flush();
T::ok(ms_status() === 'Payment settings saved.' && Settings::get('payments.enabled') === ['cod', 'bank_transfer'] && Settings::get('cod.note') === 'Keep exact change ready', 'saved');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'payments.settings_updated'"), 'logged');
ms_fresh();

/* ---------------- customers ---------------- */
T::$current = 'customers';
$buyer = Fx::user('customer', ['name' => 'Export Me']);
Fx::insert('addresses', ['user_id' => $buyer['id'], 'name' => 'Export Me', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'Street 9', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
$r = http('GET', '/admin/customers', [], $admin);
T::ok(str_contains($r->body, $buyer['email']) && !str_contains($r->body, $admin['email']), 'staff are not customers');
T::ok(str_contains(http('GET', '/admin/customers?q=Export')->body, $buyer['email']), 'search');
$r = http('GET', '/admin/customers/' . $buyer['id']);
T::ok(str_contains($r->body, 'Street 9') && str_contains($r->body, 'Block sign-in'), 'detail with addresses');
T::eq(404, http('GET', '/admin/customers/' . $admin['id'])->status, 'a staff account is not a customer page');
http('PATCH', '/admin/customers/' . $buyer['id'] . '/active');
T::ok(ms_status() === 'Customer blocked from signing in.' && (int) ms_val('SELECT is_active FROM users WHERE id = ?', [$buyer['id']]) === 0, 'blocked');
T::contains('Blocked', http('GET', '/admin/customers?status=blocked')->body, 'blocked filter');
http('PATCH', '/admin/customers/' . $buyer['id'] . '/active');
T::eq(1, (int) ms_val('SELECT is_active FROM users WHERE id = ?', [$buyer['id']]), 'unblocked');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'customer.blocked' AND subject_id = ?", [$buyer['id']]), 'logged');
$csv = http('GET', '/admin/customers/export')->body;
T::ok(str_contains($csv, $buyer['email']) && !str_contains($csv, $admin['email']) && str_contains($csv, 'Last sign-in'), 'customer CSV');
T::eq(403, http('GET', '/admin/customers/export', [], Fx::user('staff'))->status, 'customers.manage required');
ms_fresh();

/* ---------------- staff ---------------- */
T::$current = 'staff';
$person = Fx::user('customer');
$staffRole = ms_role('staff');
$r = http('GET', '/admin/staff', [], $admin);
T::ok(str_contains($r->body, 'Inventory Manager') && str_contains($r->body, 'Content Manager') && str_contains($r->body, '(you)'), 'staff page');
http('POST', '/admin/staff', ['email' => 'ghost@example.com', 'role_id' => $staffRole]);
T::ok(str_contains(ms_err('email'), 'No account with that email'), 'only existing accounts');
T::eq(404, http('POST', '/admin/staff', ['email' => $person['email'], 'role_id' => ms_role('customer')])->status, 'grant only staff roles');
http('POST', '/admin/staff', ['email' => strtoupper($person['email']), 'role_id' => $staffRole]);
T::eq($staffRole, (int) ms_val('SELECT role_id FROM users WHERE id = ?', [$person['id']]), 'access granted');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'staff.granted' AND user_id = ?", [$admin['id']]), 'grant logged');
http('PATCH', '/admin/staff/' . $admin['id'], ['role_id' => ms_role('customer')]);
T::eq('You cannot change your own role.', ms_err('role'), 'cannot change yourself');
$db->exec("UPDATE users u JOIN roles r ON r.id = u.role_id SET u.is_active = 0 WHERE r.slug = 'admin' AND u.id <> ?", [$admin['id']]);
$other = Fx::user('admin');
http('PATCH', '/admin/staff/' . $other['id'], ['role_id' => ms_role('customer')]);
T::eq('', ms_err('role'), 'another admin can be demoted while two are active');
$last = Fx::user('admin', ['is_active' => 0]);
http('PATCH', '/admin/staff/' . $last['id'], ['role_id' => ms_role('customer')]);
T::eq('At least one active administrator must remain.', ms_err('role'), 'the last active administrator is protected');
http('PATCH', '/admin/staff/' . $person['id'], ['role_id' => ms_role('customer')]);
T::eq('customer', ms_val('SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$person['id']]), 'access revoked');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'staff.role_changed'"), 'role change logged');
$mgr = Fx::user('manager');
T::eq(403, http('PATCH', '/admin/staff/' . $admin['id'], ['role_id' => ms_role('customer')], $mgr)->status, 'an Admin (manager) cannot change roles');
T::eq(403, http('POST', '/admin/staff', ['email' => $mgr['email'], 'role_id' => ms_role('admin')], $mgr)->status, 'nor escalate themselves');
T::eq('manager', ms_val('SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$mgr['id']]), 'role unchanged');
ms_fresh();

/* ---------------- coupons ---------------- */
T::$current = 'coupons';
$cat = Fx::category();
$cp = Fx::product(['category_id' => $cat['id']]);
http('POST', '/admin/coupons', ['code' => 'big', 'type' => 'percent', 'value' => 150], $admin);
T::ok(ms_err('value') !== '', 'percent max 100');
http('POST', '/admin/coupons', ['code' => 'BADIDS', 'type' => 'fixed', 'value' => 150, 'categories' => [99999]]);
T::ok(ms_err('categories.0') !== '', 'unknown category refused');
http('POST', '/admin/coupons', ['code' => 'x', 'type' => 'fixed', 'value' => 10, 'starts_at' => '2026-05-02T10:00', 'expires_at' => '2026-05-01T10:00']);
T::ok(ms_err('expires_at') !== '', 'expiry after start');
http('POST', '/admin/coupons', ['code' => 'spring', 'type' => 'percent', 'value' => 15, 'per_customer_limit' => 1, 'is_active' => 1, 'categories' => [$cat['id']], 'products' => [$cp['id']]]);
$cid = (int) ms_val("SELECT id FROM coupons WHERE code = 'SPRING'");
T::ok($cid > 0 && ms_status() === 'Coupon created.', 'created, code upper-cased');
T::eq([1, 1], [(int) ms_val('SELECT COUNT(*) FROM coupon_category WHERE coupon_id = ?', [$cid]), (int) ms_val('SELECT COUNT(*) FROM coupon_product WHERE coupon_id = ?', [$cid])], 'restrictions saved');
T::ok(http('GET', "/admin/coupons/{$cid}/edit")->status === 200 && str_contains(http('GET', '/admin/coupons')->body, 'SPRING'), 'edit + list');
http('PUT', "/admin/coupons/{$cid}", ['code' => 'SPRING', 'type' => 'percent', 'value' => 20, 'categories' => [], 'products' => []]);
T::eq([20, 0], [(int) ms_val('SELECT value FROM coupons WHERE id = ?', [$cid]), (int) ms_val('SELECT COUNT(*) FROM coupon_category WHERE coupon_id = ?', [$cid])], 'updated, restrictions cleared');
Fx::insert('coupon_usages', ['coupon_id' => $cid, 'order_id' => $keep['id'], 'email' => 'buyer@example.com', 'discount_amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
http('DELETE', "/admin/coupons/{$cid}");
T::ok(str_contains(ms_err('delete'), 'redeemed') && ms_val('SELECT deleted_at FROM coupons WHERE id = ?', [$cid]) === null, 'a redeemed coupon is kept');
ms_fresh();

/* ---------------- messages + newsletter ---------------- */
T::$current = 'messages and newsletter';
$mid = Fx::insert('contact_messages', ['name' => 'Ayesha', 'email' => 'a@example.com', 'subject' => 'Sizes', 'message' => '<script>alert(1)</script> Hello', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
T::contains('font-bold', http('GET', '/admin/messages?status=new', [], $admin)->body, 'new message listed');
$r = http('GET', "/admin/messages/{$mid}");
T::ok(str_contains($r->body, '&lt;script&gt;alert(1)&lt;/script&gt;') && ms_val('SELECT status FROM contact_messages WHERE id = ?', [$mid]) === 'read', 'shown as plain text and marked read');
http('PATCH', "/admin/messages/{$mid}", ['status' => 'replied']);
T::eq('replied', ms_val('SELECT status FROM contact_messages WHERE id = ?', [$mid]), 'marked replied');
http('PATCH', "/admin/messages/{$mid}", ['status' => 'spam']);
T::ok(ms_err('status') !== '', 'unknown status refused');
http('DELETE', "/admin/messages/{$mid}");
T::ok(!ms_val('SELECT 1 FROM contact_messages WHERE id = ?', [$mid]) && (bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'message.deleted'"), 'deleted and logged');
Fx::insert('newsletters', ['email' => '=cmd@example.com', 'subscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('newsletters', ['email' => 'ok@example.com', 'subscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('newsletters', ['email' => 'gone@example.com', 'subscribed_at' => now(), 'unsubscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
T::contains('(2 active)', http('GET', '/admin/newsletter')->body, 'active count');
$csv = http('GET', '/admin/newsletter/export')->body;
T::ok(str_starts_with($csv, "email,subscribed_at") && str_contains($csv, "'=cmd@example.com") && str_contains($csv, 'ok@example.com') && !str_contains($csv, 'gone@'), 'CSV of active subscribers, formulas neutralised');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'newsletter.exported'"), 'export logged');
$nid = (int) ms_val("SELECT id FROM newsletters WHERE email = 'ok@example.com'");
http('DELETE', "/admin/newsletter/{$nid}");
T::eq('Subscriber removed.', ms_status(), 'subscriber removed');
ms_fresh();

/* ---------------- reports ---------------- */
T::$current = 'reports';
$db->exec('DELETE FROM orders');
[$ra] = ms_order('cod', 1000, 2);              // 2,250
[$rb] = ms_order('bank_transfer', 3000, 1);    // 3,250
[$rc] = ms_order('cod', 9000, 1);              // 9,250 -> cancelled
OrderService::changeStatus($rc, 'cancelled', $admin);
$db->exec("UPDATE orders SET created_at = '2025-03-10 10:00:00' WHERE id = ?", [$ra['id']]);
$db->exec("UPDATE orders SET created_at = '2025-03-25 15:00:00', payment_status = 'paid' WHERE id = ?", [$rb['id']]);
$db->exec("UPDATE orders SET created_at = '2025-04-02 09:00:00' WHERE id = ?", [$rc['id']]);
$month = App\Services\Admin\ReportService::sales('month', '2025-01-01', '2025-12-31');
T::eq([['2025-03', 2, 5500, 3250]], array_map(static fn ($r) => [$r['period'], $r['orders'], $r['revenue'], $r['collected']], $month), 'by month, cancelled excluded');
T::eq(['2025-03-10', '2025-03-25'], array_column(App\Services\Admin\ReportService::sales('day', '2025-01-01', '2025-12-31'), 'period'), 'by day');
T::eq(1, count(App\Services\Admin\ReportService::customers('2025-01-01', '2025-12-31')), 'customers by checkout email');
$q = 'from=2025-01-01&to=2025-12-31';
$r = http('GET', "/admin/reports?type=sales&period=month&{$q}", [], $admin);
T::ok($r->status === 200 && str_contains($r->body, '2025-03') && str_contains($r->body, 'data-chart'), 'sales report with chart');
T::contains('3,000', http('GET', "/admin/reports?type=products&{$q}")->body, 'product report');
T::contains('Registered', str_replace('Guest', 'Registered', http('GET', "/admin/reports?type=customers&{$q}")->body), 'customer report');
T::contains('Cancelled', http('GET', "/admin/reports?type=revenue&{$q}")->body, 'nothing hidden in the status view');
T::contains($ra['number'], http('GET', "/admin/reports?type=orders&status=pending&{$q}")->body, 'orders report');
T::contains('2025-03,2,', http('GET', "/admin/reports?type=sales&period=month&export=csv&{$q}")->body, 'sales CSV');
foreach (['products' => 'Units sold', 'customers' => 'Total spent', 'revenue' => 'Payment method', 'orders' => 'Order,Date'] as $t => $needle) {
    T::contains($needle, http('GET', "/admin/reports?type={$t}&export=csv&{$q}")->body, "{$t} CSV");
}
T::eq(422, http('GET', '/admin/reports?type=sales&from=2000-01-01&to=2026-01-01')->status, 'range limited to 5 years');
http('GET', '/admin/reports?type=sales&from=2025-05-01&to=2025-01-01');
T::ok(ms_err('to') !== '', 'to after from');
T::ok((bool) ms_val("SELECT 1 FROM activity_logs WHERE action = 'report.exported'"), 'export logged');
ms_fresh();

/* ---------------- activity log ---------------- */
T::$current = 'activity log';
$r = http('GET', '/admin/activity?q=report', [], $admin);
T::ok(str_contains($r->body, 'report.exported') && !str_contains($r->body, 'staff.granted'), 'filter by action');
T::ok(str_contains(http('GET', '/admin/activity?user=' . $admin['id'])->body, 'report.exported'), 'filter by person');
T::eq(403, http('GET', '/admin/activity', [], Fx::user('staff'))->status, 'logs.view required');
ms_fresh();

/* ---------------- alerts ---------------- */
T::$current = 'notifications';
$db->exec('DELETE FROM notifications');
[$no] = ms_order('cod', 1000, 1);
$mine = $db->one("SELECT * FROM notifications WHERE notifiable_id = ? AND notifiable_type = 'App\\\\Models\\\\User'", [$admin['id']]);
T::ok($mine !== null, 'new order alert for the admin');
$other = Fx::user('admin');
T::contains('1 unread', http('GET', '/admin', [], $admin)->body, 'bell shows unread count');
T::contains('New order ' . $no['number'], http('GET', '/admin/notifications')->body, 'listed');
T::eq(404, http('POST', '/admin/notifications/' . $mine['id'] . '/read', [], $other)->status, 'not theirs');
T::eq(null, ms_val('SELECT read_at FROM notifications WHERE id = ?', [$mine['id']]), 'still unread');
$r = http('POST', '/admin/notifications/' . $mine['id'] . '/read', [], $admin);
T::eq('http://localhost/abiha.pk/admin/orders/' . $no['number'], $r->headers['Location'] ?? null, 'opens the order');
T::ok(ms_val('SELECT read_at FROM notifications WHERE id = ?', [$mine['id']]) !== null, 'marked read');
http('POST', '/admin/notifications/read-all', [], $other);
T::eq(0, (int) ms_val('SELECT COUNT(*) FROM notifications WHERE notifiable_id = ? AND read_at IS NULL', [$other['id']]), 'read all');

ms_fresh();
reset_state();
