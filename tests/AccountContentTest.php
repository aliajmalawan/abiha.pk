<?php

declare(strict_types=1);

/*
 * Slice A: auth, password reset, customer account (profile, password, addresses, orders), content pages (FAQ, About,
 * CMS pages, contact, shipping & returns), newsletter, sitemap and robots. Ports the intent of the old
 * CustomerAccountTest, AccountSecurityTest, FaqPageTest, AboutPageTest, SeoTest (sitemap/robots) and contact/newsletter tests.
 */

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Mail\Mailer;
use App\Support\Settings;

Kernel::$extraRoutes = [];
reset_db();
$db = Database::get();
$loc = static fn ($r): string => (string) ($r->headers['Location'] ?? '');
$userBy = static fn (string $email): ?array => $db->one('SELECT * FROM users WHERE email = ?', [$email]);

/* ---------------- Registration ---------------- */
T::$current = 'A register';
$reg = ['name' => 'Sana Malik', 'email' => 'Sana@Example.com', 'phone' => '0300 1234567', 'password' => 'Sup3rSecret!', 'password_confirmation' => 'Sup3rSecret!', 'role' => 'admin', 'is_active' => 0];
$r = http('GET', '/register', [], false);
T::eq(200, $r->status, 'register page');
T::contains('<h1 class="!text-3xl text-center">Create your account</h1>', $r->body, 'register heading');
T::contains('<meta name="robots" content="noindex,nofollow">', $r->body, 'auth pages noindex');
$r = http('POST', '/register', $reg);
T::eq(303, $r->status, 'register redirects');
T::eq(route('account.dashboard'), $loc($r), 'to the account');
$u = $userBy('sana@example.com');
T::ok($u !== null, 'email stored lower-case');
T::eq('customer', $db->value('SELECT slug FROM roles WHERE id = ?', [$u['role_id']]), 'role is always customer');
T::eq(1, (int) $u['is_active'], 'active');
T::ok($u['password'] !== 'Sup3rSecret!' && password_verify('Sup3rSecret!', $u['password']), 'password hashed');
T::eq((int) $u['id'], Auth::id(), 'signed in after registering');
T::eq('Welcome to ABIHA®. Your account is ready.', App\Core\Session::get('_flash_new')['status'] ?? null, 'welcome flash');
T::eq(200, http('GET', '/account')->status, 'dashboard reachable');
T::eq(303, http('GET', '/register')->status, 'guest-only page redirects a signed-in user');

http('POST', '/logout');
T::ok(!Auth::check(), 'logout');
$r = http('POST', '/register', ['name' => 'Someone Else'] + $reg, false);
T::contains('has already been taken', (string) (App\Core\Session::get('_flash_new')['errors']['email'] ?? ''), 'duplicate email refused');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM users WHERE email = 'sana@example.com'"), 'still one account');
http('POST', '/register', ['email' => 'other@example.com', 'password_confirmation' => 'different1A'] + $reg, false);
T::ok($userBy('other@example.com') === null, 'mismatched confirmation refused');
http('POST', '/register', ['email' => 'weak@example.com', 'password' => 'short', 'password_confirmation' => 'short'] + $reg, false);
T::eq('The password field must be at least 8 characters.', App\Core\Session::get('_flash_new')['errors']['password'] ?? null, 'weak password refused');

/* ---------------- Login / logout ---------------- */
T::$current = 'A login';
$cust = Fx::user('customer', ['email' => 'login@example.com']);
$r = http('GET', '/login', [], false);
T::contains('<a class="link inline-block py-2" href="' . route('password.request') . '">Forgot password?</a>', $r->body, 'login form');
$r = http('POST', '/login', ['email' => 'login@example.com', 'password' => 'wrong-password'], false);
T::eq('These credentials do not match our records.', App\Core\Session::get('_flash_new')['errors']['email'] ?? null, 'wrong password');
T::ok(!Auth::check(), 'still a guest');
App\Core\Session::put('intended', '/account/orders');
$r = http('POST', '/login', ['email' => 'login@example.com', 'password' => Fx::PASSWORD]);
T::eq(url('/account/orders'), $loc($r), 'intended redirect');
T::eq((int) $cust['id'], Auth::id(), 'signed in');
T::ok($db->value('SELECT last_login_at FROM users WHERE id = ?', [$cust['id']]) !== null, 'last_login_at stamped');
http('POST', '/logout');
T::eq(url('/login'), $loc(http('GET', '/account', [], false)), 'account needs login');
$staff = Fx::user('admin');
App\Core\Session::forget('intended');
$r = http('POST', '/login', ['email' => $staff['email'], 'password' => Fx::PASSWORD], false);
T::eq(route('admin.dashboard'), $loc($r), 'staff land in the admin');
http('POST', '/logout');
$off = Fx::user('customer', ['is_active' => 0]);
http('POST', '/login', ['email' => $off['email'], 'password' => Fx::PASSWORD], false);
T::ok(!Auth::check(), 'inactive account cannot sign in');
$gone = Fx::user('customer', ['deleted_at' => now()]);
http('POST', '/login', ['email' => $gone['email'], 'password' => Fx::PASSWORD], false);
T::ok(!Auth::check(), 'archived account cannot sign in');
// per email+IP lockout after 5 failures (route limiter reset first)
$db->exec('DELETE FROM rate_limits');
for ($i = 0; $i < 5; $i++) {
    http('POST', '/login', ['email' => 'lock@example.com', 'password' => 'nope'], false);
}
http('POST', '/login', ['email' => 'lock@example.com', 'password' => 'nope'], false);
T::contains('Too many attempts. Try again in', (string) (App\Core\Session::get('_flash_new')['errors']['email'] ?? ''), 'email+IP lockout');
$db->exec('DELETE FROM rate_limits');

/* ---------------- Password reset ---------------- */
T::$current = 'A password reset';
$fu = Fx::user('customer', ['email' => 'forgot@example.com']);
Mailer::$sent = [];
http('POST', '/forgot-password', ['email' => 'ghost@example.com'], false);
$a = App\Core\Session::get('_flash_new')['status'] ?? null;
http('POST', '/forgot-password', ['email' => 'forgot@example.com'], false);
$b = App\Core\Session::get('_flash_new')['status'] ?? null;
T::eq('If an account exists for that email, a reset link is on its way.', $a, 'generic answer for an unknown email');
T::eq($a, $b, 'same answer for a real account');
T::eq(1, count(Mailer::$sent), 'one email, only to the real account');
T::eq('Reset Password Notification', Mailer::$sent[0]['subject'] ?? null, 'reset subject');
preg_match('#/reset-password/([a-f0-9]{64})\?email=([^"&]+)#', Mailer::$sent[0]['html'] ?? '', $m);
$token = $m[1] ?? '';
T::ok($token !== '' && urldecode($m[2] ?? '') === 'forgot@example.com', 'link carries token and email');
T::ok(!str_contains((string) $db->value('SELECT token FROM password_reset_tokens WHERE email = ?', ['forgot@example.com']), $token), 'only a hash is stored');
http('POST', '/forgot-password', ['email' => 'forgot@example.com'], false);
T::eq(1, count(Mailer::$sent), 'second link within 60 s is throttled');
$r = http('GET', '/reset-password/' . $token . '?email=forgot@example.com', [], false);
T::contains('value="' . $token . '"', $r->body, 'reset form carries the token');
T::contains('value="forgot@example.com"', $r->body, 'and the email');
$new = ['email' => 'forgot@example.com', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'];
http('POST', '/reset-password', ['token' => 'wrong'] + $new, false);
T::eq('This password reset token is invalid.', App\Core\Session::get('_flash_new')['errors']['email'] ?? null, 'wrong token');
T::ok(password_verify(Fx::PASSWORD, (string) $db->value('SELECT password FROM users WHERE id = ?', [$fu['id']])), 'password unchanged');
http('POST', '/reset-password', ['token' => $token, 'password' => 'weak', 'password_confirmation' => 'weak'] + $new, false);
T::ok(isset(App\Core\Session::get('_flash_new')['errors']['password']), 'weak new password refused');
$r = http('POST', '/reset-password', ['token' => $token] + $new, false);
T::eq(route('login'), $loc($r), 'reset redirects to sign in');
T::ok(password_verify('NewPassw0rd!', (string) $db->value('SELECT password FROM users WHERE id = ?', [$fu['id']])), 'password changed');
http('POST', '/reset-password', ['token' => $token, 'password' => 'Another1Pass!', 'password_confirmation' => 'Another1Pass!'] + $new, false);
T::ok(isset(App\Core\Session::get('_flash_new')['errors']['email']), 'token is single use');
$db->exec("UPDATE password_reset_tokens SET created_at = ? WHERE email = 'forgot@example.com'", [date('Y-m-d H:i:s', time() - 7200)]);
T::eq('token', App\Services\PasswordBroker::reset('forgot@example.com', $token, 'X1yyyyyyy'), 'expired token');
$db->exec('DELETE FROM rate_limits');

/* ---------------- Profile & password ---------------- */
T::$current = 'A profile';
$p = Fx::user('customer', ['email' => 'p@example.com']);
http('GET', '/account', [], $p);                                   // ages the old input of the previous section
$r = http('GET', '/account/profile');
T::contains('value="p@example.com"', $r->body, 'profile form filled (' . $r->status . ' ' . ($r->headers['Location'] ?? '') . ')');
T::contains('aria-current="page" class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold whitespace-nowrap no-underline bg-ink text-white"', $r->body, 'active account nav item');
http('PUT', '/account/profile', ['name' => 'New Name', 'email' => 'p@example.com', 'phone' => '0300 1234567']);
T::eq('New Name', $db->value('SELECT name FROM users WHERE id = ?', [$p['id']]), 'name updated');
http('PUT', '/account/profile', ['name' => 'New Name', 'email' => 'changed@example.com']);
T::eq('Enter your current password to change your email address.', App\Core\Session::get('_flash_new')['errors']['email_password'] ?? null, 'email change needs the password');
http('PUT', '/account/profile', ['name' => 'New Name', 'email' => 'changed@example.com', 'email_password' => 'wrong']);
T::eq('The password is incorrect.', App\Core\Session::get('_flash_new')['errors']['email_password'] ?? null, 'wrong current password');
http('PUT', '/account/profile', ['name' => 'New Name', 'email' => 'changed@example.com', 'email_password' => Fx::PASSWORD]);
T::eq('changed@example.com', $db->value('SELECT email FROM users WHERE id = ?', [$p['id']]), 'email changed with the password');
http('PUT', '/account/password', ['current_password' => 'wrong', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456']);
T::eq('The password is incorrect.', App\Core\Session::get('_flash_new')['errors']['current_password'] ?? null, 'password change needs the current one');
http('PUT', '/account/password', ['current_password' => Fx::PASSWORD, 'password' => 'Brand-New-9pw', 'password_confirmation' => 'Brand-New-9pw']);
T::ok(password_verify('Brand-New-9pw', (string) $db->value('SELECT password FROM users WHERE id = ?', [$p['id']])), 'password changed');
T::eq((int) $p['id'], Auth::id(), 'this session stays signed in');

/* ---------------- Addresses ---------------- */
T::$current = 'A addresses';
$u = Fx::user('customer');
$addr = ['label' => 'Home', 'name' => 'Sana Malik', 'phone' => '+92 300 1234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'House 1, Street 2, Model Town'];
http('POST', '/account/addresses', ['phone' => '12345'] + $addr, $u);
T::eq('Enter a valid Pakistani phone number, for example 0300 1234567 or +92 300 1234567.', App\Core\Session::get('_flash_new')['errors']['phone'] ?? null, 'phone rule');
http('POST', '/account/addresses', ['address' => 'short'] + $addr);
T::eq('Please enter your complete address (house, street, area).', App\Core\Session::get('_flash_new')['errors']['address'] ?? null, 'address.min message');
http('POST', '/account/addresses', $addr);
$first = $db->one('SELECT * FROM addresses WHERE user_id = ?', [$u['id']]);
T::eq('03001234567', $first['phone'] ?? null, 'phone normalised');
T::eq(1, (int) ($first['is_default'] ?? 0), 'first address is the default');
http('POST', '/account/addresses', ['label' => 'Work', 'city' => 'Karachi', 'province' => 'Sindh', 'is_default' => 1] + $addr);
T::eq('Karachi', $db->value('SELECT city FROM addresses WHERE user_id = ? AND is_default = 1', [$u['id']]), 'new default');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1', [$u['id']]), 'one default');
http('PUT', '/account/addresses/' . $first['id'], ['city' => 'Multan'] + $addr);
T::eq('Multan', $db->value('SELECT city FROM addresses WHERE id = ?', [$first['id']]), 'address updated');
$r = http('GET', '/account/addresses');
T::contains('action="' . route('account.addresses.update', ['address' => $first['id']]) . '"', $r->body, 'edit form posts to the id');
$other = Fx::user('customer');
T::eq(403, http('DELETE', '/account/addresses/' . $first['id'], [], $other)->status, 'someone else cannot delete it');
T::eq(403, http('PUT', '/account/addresses/' . $first['id'], $addr, $other)->status, 'or edit it');
T::eq(404, http('DELETE', '/account/addresses/999999', [], $u)->status, 'unknown address 404');
$default = $db->value('SELECT id FROM addresses WHERE user_id = ? AND is_default = 1', [$u['id']]);
http('DELETE', '/account/addresses/' . $default, [], $u);
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1', [$u['id']]), 'another address takes over as default');

/* ---------------- Orders ---------------- */
T::$current = 'A orders';
$prod = Fx::product(['stock' => 5, 'sold_count' => 1]);
$order = static function (array $user, array $over = []) use ($prod): array {
    $id = Fx::insert('orders', $over + ['number' => 'ABH-T-' . strtoupper(bin2hex(random_bytes(3))), 'user_id' => $user['id'], 'email' => $user['email'], 'status' => 'pending', 'payment_status' => 'unpaid',
        'payment_method' => 'cod', 'shipping_method_name' => 'Standard', 'subtotal' => 1000, 'shipping_cost' => 250, 'total' => 1250, 'access_token' => bin2hex(random_bytes(20)), 'created_at' => now(), 'updated_at' => now()]);
    Fx::insert('order_addresses', ['order_id' => $id, 'type' => 'shipping', 'name' => 'Sana', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'House 1, Street 2']);
    Fx::insert('order_items', ['order_id' => $id, 'product_id' => $prod['id'], 'name' => 'History Dress', 'sku' => 'H-1', 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000]);
    return Database::get()->one('SELECT * FROM orders WHERE id = ?', [$id]);
};
$buyer = Fx::user('customer');
$o = $order($buyer);
$r = http('GET', '/account/orders', [], $buyer);
T::contains($o['number'], $r->body, 'order listed');
T::contains('1 line', $r->body, 'line count');
$r = http('GET', '/account/orders/' . $o['number']);
T::contains('History Dress', $r->body, 'order detail items');
T::contains('1,250', $r->body, 'order total');
T::contains('Cancel order', $r->body, 'pending unpaid order can be cancelled');
T::contains($o['number'], http('GET', '/account')->body, 'dashboard shows recent orders');
$intruder = Fx::user('customer');
T::eq(403, http('GET', '/account/orders/' . $o['number'], [], $intruder)->status, 'not someone else\'s order');
T::eq(403, http('POST', '/account/orders/' . $o['number'] . '/cancel', [], $intruder)->status, 'nor cancel it');
T::ok(!str_contains(http('GET', '/account/orders')->body, $o['number']), 'not in someone else\'s list');
T::eq(404, http('GET', '/account/orders/ABH-NOPE', [], $buyer)->status, 'unknown order 404');
$r = http('POST', '/account/orders/' . $o['number'] . '/cancel', [], $buyer);
T::eq('cancelled', $db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]), 'order cancelled');
T::eq(6, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$prod['id']]), 'stock restored');
T::eq(0, (int) $db->value('SELECT sold_count FROM products WHERE id = ?', [$prod['id']]), 'sold count given back');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND reason = 'cancellation' AND `change` = 1", [$o['id']]), 'ledger entry');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND status = 'cancelled'", [$o['id']]), 'history entry');
T::eq(403, http('POST', '/account/orders/' . $o['number'] . '/cancel', [], $buyer)->status, 'cannot cancel twice');
$paid = $order($buyer, ['payment_status' => 'paid']);
T::ok(!str_contains(http('GET', '/account/orders/' . $paid['number'])->body, 'Cancel order'), 'paid order has no cancel button');
T::eq(403, http('POST', '/account/orders/' . $paid['number'] . '/cancel')->status, 'paid order cannot be cancelled');
$shipped = $order($buyer, ['status' => 'shipped']);
T::eq(403, http('POST', '/account/orders/' . $shipped['number'] . '/cancel')->status, 'shipped order cannot be cancelled');
foreach (['/account', '/account/orders', '/account/addresses', '/account/profile'] as $path) {
    T::eq(url('/login'), $loc(http('GET', $path, [], false)), "{$path} requires login");
}

/* ---------------- FAQ / About / pages ---------------- */
T::$current = 'A content pages';
Settings::put('payments.enabled', ['cod']);
Fx::page(['title' => 'FAQ', 'slug' => 'faq', 'body' => '']);
$b = http('GET', '/pages/faq', [], false)->body;
foreach (['Ordering', 'Payment', 'Shipping &amp; delivery', 'Returns &amp; refunds', 'Your account', 'Help &amp; contact'] as $g) {
    T::contains('>' . $g . '</h2>', $b, "FAQ group {$g}");
}
T::contains('href="#faq-shipping-delivery"', $b, 'anchors like Str::slug');
T::contains('How can I track my order?', $b, 'FAQ question');
T::eq(1, preg_match_all('/<h1[\s>]/', $b), 'one h1');
T::contains('"@type":"FAQPage"', $b, 'FAQPage JSON-LD');
T::ok(!preg_match('/\{(payments|shipping|track|contact|login|register|forgot|privacy|phone|email)\}/', $b), 'every token replaced');
T::contains('Right now: Cash on Delivery.', $b, 'only switched-on payment methods');
T::contains('href="' . route('page.shipping') . '"', $b, 'shipping link');
T::ok(!str_contains($b, 'You can also call us on'), 'no phone sentence without a phone');
Settings::put('contact.phone', '+92 300 1234567');
Settings::put('contact.email', 'help@example.com');
$b = http('GET', '/pages/faq')->body;
T::contains('tel:+923001234567', $b, 'phone used');
T::contains('mailto:help@example.com', $b, 'email used');
$db->exec("UPDATE pages SET body = '<p>Our own words.</p>' WHERE slug = 'faq'");
$b = http('GET', '/pages/faq')->body;
T::ok(str_contains($b, 'Our own words.') && !str_contains($b, 'How can I track my order?'), 'owner text replaces the built-in FAQ');

$kit = Fx::category(['name' => 'Kitwear', 'slug' => 'kitwear']);
Fx::category(['name' => 'Emptywear', 'slug' => 'emptywear']);
Fx::product(['category_id' => $kit['id']]);
Fx::page(['title' => 'About us', 'slug' => 'about-us', 'body' => '']);
$b = http('GET', '/pages/about-us')->body;
foreach (['Performance', 'Comfort', 'Style', 'How shopping with us works', 'We would love to hear from you', 'Pay by Cash on Delivery.'] as $t) {
    T::contains($t, $b, "About: {$t}");
}
$grid = substr($b, (int) strpos($b, 'about-cats'), 1500);
T::ok(str_contains($grid, 'Kitwear') && !str_contains($grid, 'Emptywear'), 'only categories with products');
T::contains('href="' . route('track') . '"', $b, 'track link');

Fx::page(['title' => 'Our story', 'slug' => 'our-story', 'body' => '<p>Hello</p><script>alert(1)</script>']);
$b = http('GET', '/pages/our-story')->body;
T::ok(str_contains($b, 'Hello') && !str_contains($b, '<script>alert(1)</script>'), 'CMS page sanitised');
Fx::page(['title' => 'Draft', 'slug' => 'draft-page', 'is_published' => 0]);
T::eq(404, http('GET', '/pages/draft-page')->status, 'unpublished page 404');
Fx::page(['title' => 'Privacy', 'slug' => 'privacy', 'is_sample' => 1]);
T::contains('SAMPLE CONTENT: replace before launch', http('GET', '/pages/privacy')->body, 'sample flag outside production');

Fx::insert('shipping_methods', ['code' => 'dhl', 'name' => 'DHL', 'cost' => 250, 'free_over' => 5000, 'eta' => '2-4 days', 'is_active' => 1]);
Fx::insert('shipping_methods', ['code' => 'off', 'name' => 'Hidden Method', 'cost' => 1, 'is_active' => 0]);
Settings::put('delivery.info', "Line one\nLine <two>");
$b = http('GET', '/shipping-returns')->body;
T::contains('DHL</span>', $b, 'active method listed');
T::contains('(free over 5,000)', $b, 'free over');
T::ok(!str_contains($b, 'Hidden Method'), 'inactive method hidden');
T::contains('Line one<br />' . "\n" . 'Line &lt;two&gt;', $b, 'delivery text escaped with line breaks');
T::contains('Our return policy will be published soon.', $b, 'returns placeholder');

/* ---------------- Contact & newsletter ---------------- */
T::$current = 'A contact newsletter';
$ok = ['name' => 'Sam', 'email' => 'sam@example.com', 'message' => 'Where is my order please?'];
$r = http('POST', '/contact', $ok, false);
T::eq(route('contact'), $loc($r), 'contact redirects');
T::eq('new', $db->value("SELECT status FROM contact_messages WHERE email = 'sam@example.com'"), 'message saved');
http('POST', '/contact', $ok + ['website' => 'http://spam.example']);
T::ok(isset(App\Core\Session::get('_flash_new')['errors']['website']), 'honeypot');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM contact_messages'), 'bot message not saved');
$db->exec('DELETE FROM rate_limits');
$r = http('POST', '/newsletter', ['email' => 'Fan@Example.com'], false, ['json' => true]);
T::eq(200, $r->status, 'newsletter JSON ok');
T::eq(['ok' => true, 'message' => 'Thank you for subscribing.'], json_decode($r->body, true), 'newsletter JSON body');
http('POST', '/newsletter', ['email' => 'fan@example.com'], false, ['json' => true]);
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM newsletters'), 'lower-cased, no duplicate');
$db->exec("UPDATE newsletters SET unsubscribed_at = ? WHERE email = 'fan@example.com'", [now()]);
http('POST', '/newsletter', ['email' => 'fan@example.com'], false, ['json' => true]);
T::ok($db->value("SELECT unsubscribed_at FROM newsletters WHERE email = 'fan@example.com'") === null, 're-subscribed');
T::eq(422, http('POST', '/newsletter', ['email' => 'nope'], false, ['json' => true])->status, 'invalid email 422');
for ($i = 0; $i < 3; $i++) {
    $last = http('POST', '/newsletter', ['email' => 'x@example.com'], false, ['json' => true]);
}
T::eq(429, $last->status, 'newsletter throttle (5/min)');
$db->exec('DELETE FROM rate_limits');

/* ---------------- Sitemap & robots ---------------- */
T::$current = 'A sitemap robots';
$live = Fx::product(['name' => 'Live One', 'slug' => 'live-one']);
Fx::insert('product_images', ['product_id' => $live['id'], 'path' => 'products/live.jpg', 'alt' => 'Live one front', 'sort_order' => 0]);
Fx::product(['name' => 'Draft', 'slug' => 'draft-product', 'is_active' => 0]);
Fx::product(['name' => 'Demo', 'slug' => 'demo-product', 'is_sample' => 1]);
Fx::category(['name' => 'Hidden', 'slug' => 'hidden-cat', 'is_active' => 0]);
reset_state();
$r = http('GET', '/sitemap.xml', [], false);
T::eq('application/xml; charset=utf-8', $r->headers['Content-Type'] ?? null, 'sitemap content type');
T::contains('max-age=3600', $r->headers['Cache-Control'] ?? '', 'sitemap cacheable');
T::ok(simplexml_load_string($r->body) !== false, 'well-formed XML');
foreach (['/products/live-one', '/category/kitwear', '/pages/our-story', '/shipping-returns'] as $path) {
    T::contains('<loc>' . url($path) . '</loc>', $r->body, "sitemap lists {$path}");
}
foreach (['draft-product', 'demo-product', 'hidden-cat', 'draft-page', '/pages/privacy', '/account', '/login'] as $nope) {
    T::ok(!str_contains($r->body, $nope), "sitemap leaves out {$nope}");
}
T::contains('<image:title>Live one front</image:title>', $r->body, 'image title');
T::contains('<lastmod>', $r->body, 'lastmod');
$db->exec("UPDATE products SET slug = 'second-slug' WHERE id = ?", [$live['id']]);
App\Support\CatalogCache::bump();
T::contains('/products/second-slug', http('GET', '/sitemap.xml')->body, 'sitemap follows catalogue changes');
$r = http('GET', '/robots.txt');
T::eq('text/plain; charset=utf-8', $r->headers['Content-Type'] ?? null, 'robots content type');
T::eq("User-agent: *\nDisallow: /\n", $r->body, 'everything blocked outside production');
$env = Config::get('env');
Config::set('env', 'production');
$b = http('GET', '/robots.txt')->body;
foreach (['Disallow: /admin', 'Disallow: /account', 'Disallow: /cart', 'Disallow: /checkout', 'Disallow: /order', 'Disallow: /*?*q=', 'Sitemap: ' . url('/sitemap.xml')] as $line) {
    T::contains($line, $b, "robots: {$line}");
}
T::ok(!str_contains($b, "Disallow: /\n"), 'production does not block the site');
Settings::put('seo.allow_indexing', false);
T::contains("Disallow: /\n", http('GET', '/robots.txt')->body, 'indexing off blocks again');
Settings::put('seo.allow_indexing', true);
T::ok(!str_contains(http('GET', '/pages/faq')->body, 'noindex'), 'FAQ indexable on production');
Config::set('env', $env);
T::ok(!is_file(PUBLIC_PATH . '/robots.txt'), 'no static robots.txt shadows the generated one');
reset_db();
