<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Validator;
use App\Support\Settings;

reset_db();
$db = Database::get();

// Probe routes for the foundation (tests only).
Kernel::$extraRoutes = [static function (Router $r): void {
    $echo = static fn (Request $q) => Response::json(['method' => $q->method, 'input' => array_diff_key($q->post, ['_token' => 1, '_method' => 1]), 'params' => $q->params]);
    $r->post('/_t/echo', $echo);
    $r->put('/_t/echo', $echo);
    $r->patch('/_t/echo/{key}', $echo);
    $r->delete('/_t/echo/{key}', $echo);
    $r->post('/_t/hook', $echo, ['nocsrf']);
    $r->get('/_t/account', static fn () => 'account page', ['auth']);
    $r->get('/admin/_t/area', static fn () => 'admin page', ['auth', 'staff']);
    $r->get('/admin/_t/orders', static fn () => 'orders page', ['auth', 'perm:orders.view']);
    $r->get('/admin/_t/staff', static fn () => 'staff page', ['perm:staff.manage']);
    $r->get('/_t/guest', static fn () => 'guest page', ['guest']);
    $r->post('/_t/form', static function (Request $q) {
        Validator::make($q->post, ['email' => 'required|email', 'name' => 'required'])->validated();
        App\Core\Session::flash('status', 'Saved.');
        return Response::redirect('/_t/account');
    });
    $r->get('/_t/boom', static function () {
        throw new RuntimeException('secret detail');
    });
    $r->get('/_t/throttled', static fn () => 'ok', ['throttle:newsletter']);
    $r->get('/account/_t', static fn () => 'private', ['auth']);
}];

$customer = Fx::user('customer');
$admin = Fx::user('admin');
$staff = Fx::user('staff');
$content = Fx::user('content_manager');

/* ---------------- Store layout / chrome ---------------- */
T::$current = 'HTTP layout';
Settings::put('contact.phone', '+92 300 1234567');
Settings::put('contact.email', 'hello@example.test');
$top = Fx::category(['name' => 'Sportswear', 'slug' => 'sportswear']);
Fx::category(['name' => 'T-shirts', 'slug' => 't-shirts', 'parent_id' => $top['id']]);
Fx::page(['title' => 'About us', 'slug' => 'about', 'show_in_footer' => 1]);
App\Support\CatalogCache::bump();
$r = http('GET', '/', [], false);
$b = $r->body;
T::eq(200, $r->status, 'home renders');
T::contains('class="footer mt-auto"', $b, 'home inside the store layout');
T::contains('<meta name="csrf-token" content="' . csrf_token() . '">', $b, 'csrf meta with the session token');
T::contains('<title>ABIHA® | Premium Ecommerce</title>', $b, 'default title');
T::contains('<meta name="robots" content="noindex,nofollow">', $b, 'noindex outside production');
T::contains('<link rel="canonical" href="http://localhost/abiha.pk">', $b, 'canonical');
T::ok(str_contains($b, 'assets/abiha/app.min.css?v=') || str_contains($b, 'assets/abiha/abiha.css?v='), 'stylesheet (the built file, or the two sources when the build is stale)');
T::contains('<script type="module" src="/abiha.pk/assets/abiha/abiha.js?v=', $b, 'module script');
T::contains('"cartDrawerUrl":"http://localhost/abiha.pk/cart/drawer"', $b, 'window.ABIHA config');
T::contains('"currency":"PKR","cartCount":0,"wishlistIds":[]', $b, 'currency, cart count, wishlist ids');
preg_match('/<script nonce="([^"]+)">/', $b, $nm);
T::ok(isset($nm[1]) && str_contains($r->headers['Content-Security-Policy'] ?? '', "'nonce-" . $nm[1] . "'"), 'inline script nonce matches the CSP');
T::eq(1, substr_count($b, '<script nonce='), 'only one inline script (the nonce\'d config)');
T::ok(!preg_match('/\son[a-z]+="/i', $b), 'no inline event handlers');
$csp = $r->headers['Content-Security-Policy'] ?? '';
T::ok(str_contains($csp, "default-src 'self'") && str_contains($csp, "font-src 'self'") && str_contains($csp, "frame-ancestors 'self'") && str_contains($csp, "object-src 'none'"), 'CSP like the old site');
T::eq('nosniff', $r->headers['X-Content-Type-Options'] ?? null, 'nosniff');
T::eq('SAMEORIGIN', $r->headers['X-Frame-Options'] ?? null, 'frame options');
T::eq('noindex, nofollow', $r->headers['X-Robots-Tag'] ?? null, 'X-Robots-Tag outside production');
T::contains('class="announce"', $b, 'announce bar');
T::contains('<a href="tel:+923001234567" class="announce__link">', $b, 'phone link');
T::contains('mailto:hello@example.test', $b, 'email link');
T::contains('id="mega-menu"', $b, 'mega menu');
T::contains('megaCat: ' . $top['id'], $b, 'mega menu opens on the first category');
T::contains('href="http://localhost/abiha.pk/category/t-shirts"', $b, 'sub-category link');
T::contains('x-data="searchBox(\'http://localhost/abiha.pk/search/suggest\')"', $b, 'search overlay');
T::contains('aria-label="Sign in"', $b, 'guest sees sign in');
T::contains('class="footer mt-auto"', $b, 'footer');
T::ok(!str_contains($b, 'footer__news'), 'no newsletter strip on the home page');
T::contains('<h2 class="footer__h">Company</h2>', $b, 'footer pages column');
T::contains('href="http://localhost/abiha.pk/pages/about"', $b, 'footer page link');
T::contains('alt="DHL"', $b, 'courier logos');
T::contains('ABIHA®. All rights reserved.', $b, 'copyright');
T::contains('x-show="$store.drawer.open"', $b, 'cart drawer');
T::contains('role="alertdialog"', $b, 'confirm dialog');
T::contains('x-show="$store.quick.open"', $b, 'quick view');

/* ---------------- Error pages ---------------- */
T::$current = 'HTTP errors';
$r = http('GET', '/no-such-page');
T::eq(404, $r->status, '404');
T::contains('Page not found', $r->body, '404 page');
T::contains('class="footer mt-auto"', $r->body, '404 inside the store layout');
T::contains('footer__news', $r->body, 'newsletter strip on other pages');
T::contains('<title>Page not found | ABIHA®</title>', $r->body, '404 title');
T::eq(404, http('GET', '/no-such-page-' . bin2hex(random_bytes(3)))->status, 'unknown routes answer 404');
$r = http('GET', '/_t/boom');
T::eq(500, $r->status, '500');
T::ok(str_contains($r->body, 'Something went wrong') && !str_contains($r->body, 'secret detail'), '500 page hides details');
$r = http('GET', '/_t/boom', [], null, ['json' => true]);
T::ok($r->status === 500 && $r->body === '{"message":"Server Error"}', 'JSON 500 like Laravel');
T::eq(405, http('GET', '/_t/echo')->status, '405 for the wrong method');
T::eq(405, http('GET', '/_t/echo/1', [], null, ['json' => true])->status, '405 for a GET on a PATCH/DELETE-only path');

/* ---------------- CSRF / methods / JSON ---------------- */
T::$current = 'HTTP CSRF';
foreach (['POST' => '/_t/echo', 'PUT' => '/_t/echo', 'PATCH' => '/_t/echo/1', 'DELETE' => '/_t/echo/1'] as $m => $p) {
    T::eq(419, http($m, $p, ['a' => 1], null, ['csrf' => false])->status, "{$m} without a token is 419");
    T::eq(419, http($m, $p, ['_token' => str_repeat('a', 64)], null, ['csrf' => false])->status, "{$m} with a wrong token is 419");
    T::eq(200, http($m, $p, ['a' => 1])->status, "{$m} with the token passes");
}
T::eq(200, http('PATCH', '/_t/echo/1', [], null, ['csrf' => false, 'headers' => ['X-CSRF-TOKEN' => csrf_token()]])->status, 'X-CSRF-TOKEN header accepted');
T::eq(403, http('POST', '/_t/echo', [], null, ['headers' => ['Origin' => 'https://evil.example']])->status, 'cross-site Origin refused');
T::eq(200, http('POST', '/_t/hook', [], null, ['csrf' => false])->status, 'nocsrf routes (webhooks)');
$r = http('POST', '/_t/echo/7', ['_method' => 'DELETE', 'x' => 'y']);
T::eq('{"method":"DELETE","input":{"x":"y"},"params":{"key":"7"}}', $r->body, '_method spoofing reaches DELETE');
$r = http('GET', '/_t/form');
T::eq(419, http('POST', '/_t/form', [], null, ['csrf' => false])->status, 'form needs a token');
T::contains('Your session expired', http('POST', '/_t/form', [], null, ['csrf' => false])->body, '419 page');

/* ---------------- Validation redirect ---------------- */
T::$current = 'HTTP validation';
$r = http('POST', '/_t/form', ['email' => 'bad', 'name' => 'Sana', 'password' => 'secret', 'tags' => ['x']], null, ['headers' => ['Referer' => 'http://localhost/abiha.pk/contact']]);
T::ok($r->status === 303 && $r->headers['Location'] === 'http://localhost/abiha.pk/contact', 'redirect back to the form');
Session::ageFlash();
T::eq('The email field must be a valid email address.', error('email'), 'errors flashed');
T::eq('Sana', old('name'), 'old input flashed');
T::eq('', old('password'), 'passwords are never kept');
T::eq('', old('tags'), 'arrays are not kept outside the admin');
$r = http('POST', '/_t/form', ['email' => 'bad'], null, ['json' => true]);
T::eq(422, $r->status, 'JSON 422');
T::eq('{"message":"The email field must be a valid email address. (and 1 more error)","errors":{"email":["The email field must be a valid email address."],"name":["The name field is required."]}}', $r->body, 'Laravel JSON error shape');
$r = http('POST', '/_t/form', ['email' => 'a@example.test', 'name' => 'A']);
T::eq(303, $r->status, 'valid form redirects');
Session::ageFlash();
T::eq('Saved.', flash('status'), 'status flash');

/* ---------------- Middleware ---------------- */
T::$current = 'HTTP middleware';
$r = http('GET', '/_t/account', [], false);
T::ok($r->status === 303 && $r->headers['Location'] === 'http://localhost/abiha.pk/login', 'auth: guests go to /login');
T::eq('/_t/account', Session::get('intended'), 'intended URL remembered');
$r = http('GET', '/admin/_t/area', [], false);
T::eq('http://localhost/abiha.pk/admin/login', $r->headers['Location'] ?? null, 'auth under /admin: staff sign-in');
T::eq(401, http('GET', '/_t/account', [], false, ['json' => true])->status, 'JSON guests get 401');
T::eq(200, http('GET', '/_t/account', [], $customer)->status, 'signed-in customer');
$r = http('GET', '/admin/_t/area', [], $customer);
T::ok($r->status === 403 && str_contains($r->body, 'You do not have access'), 'customers cannot enter the admin area (403)');
T::eq(200, http('GET', '/admin/_t/area', [], $staff)->status, 'staff enter the admin area');
T::eq(200, http('GET', '/admin/_t/orders', [], $staff)->status, 'perm granted through the role');
T::eq(403, http('GET', '/admin/_t/orders', [], $content)->status, 'perm missing -> 403');
T::eq(403, http('GET', '/admin/_t/staff', [], $staff)->status, 'Order Manager cannot manage staff');
T::eq(200, http('GET', '/admin/_t/staff', [], $admin)->status, 'Super Admin can');
T::eq(303, http('GET', '/admin/_t/staff', [], false)->status, 'perm alone also requires sign-in');
$r = http('GET', '/_t/guest', [], $customer);
T::eq('http://localhost/abiha.pk/account', $r->headers['Location'] ?? null, 'guest-only page: customer to /account');
$r = http('GET', '/_t/guest', [], $staff);
T::eq('http://localhost/abiha.pk/admin', $r->headers['Location'] ?? null, 'guest-only page: staff to /admin');
T::eq(200, http('GET', '/_t/guest', [], false)->status, 'guest-only page for guests');
// Admin idle timeout.
http('GET', '/admin/_t/area', [], $staff);
Session::put('admin_last_activity', time() - 31 * 60);
$r = http('GET', '/admin/_t/area');
T::ok($r->status === 303 && $r->headers['Location'] === 'http://localhost/abiha.pk/admin/login' && Auth::user() === null, 'idle staff session is signed out');
Session::ageFlash();
T::eq('You were signed out after 30 minutes of inactivity. Please sign in again.', error('email'), 'idle message');
// Disabled account on its next request.
http('GET', '/_t/account', [], $customer);
$db->exec('UPDATE users SET is_active = 0 WHERE id = ?', [$customer['id']]);
$r = http('GET', '/_t/account');
T::ok($r->status === 303 && $r->headers['Location'] === 'http://localhost/abiha.pk/login', 'disabled account is sent to /login');
Session::ageFlash();
T::eq('This account has been disabled. Please contact us if you think this is a mistake.', error('email'), 'disabled message');
$db->exec('UPDATE users SET is_active = 1 WHERE id = ?', [$customer['id']]);
// Named rate limiter.
for ($i = 0; $i < 5; $i++) {
    http('GET', '/_t/throttled', [], false);
}
$r = http('GET', '/_t/throttled', [], false);
T::ok($r->status === 429 && isset($r->headers['Retry-After']) && str_contains($r->body, 'Too many attempts'), 'throttle:newsletter (5/min) -> 429 page');
// Private pages are never cached.
$r = http('GET', '/account/_t', [], $customer);
T::eq('no-store, private', $r->headers['Cache-Control'] ?? null, 'private pages: no-store');

/* ---------------- Chrome for a signed-in user ---------------- */
T::$current = 'HTTP chrome (signed in)';
$p = Fx::product();
$cart = Fx::insert('carts', ['user_id' => $customer['id'], 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('cart_items', ['cart_id' => $cart, 'product_id' => $p['id'], 'quantity' => 2]);
$w = Fx::insert('wishlists', ['user_id' => $customer['id'], 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('wishlist_items', ['wishlist_id' => $w, 'product_id' => $p['id']]);
$b = http('GET', '/', [], $customer)->body;
T::contains('"cartCount":2,"wishlistIds":[' . $p['id'] . ']', $b, 'cart count and wishlist ids for the user');
T::contains('aria-label="My account"', $b, 'signed in: account link');
T::contains('href="http://localhost/abiha.pk/account" class="btn btn--outline btn--block">My account</a>', $b, 'mobile drawer account button');
Settings::put('announcement.text', 'Eid sale');
Settings::put('announcement.url', '/shop?sale=1');
T::contains('<p class="announce__text"><a href="/shop?sale=1" class="hover:underline">Eid sale</a></p>', http('GET', '/')->body, 'announcement');
Settings::put('seo.title', 'ABIHA Store');
T::contains('<title>ABIHA Store</title>', http('GET', '/')->body, 'owner default title');

/* ---------------- Admin layout ---------------- */
T::$current = 'Admin layout';
App\Core\View::reset();
App\Core\View::share('currentPath', '/admin');
App\Core\View::share('request', Request::create('GET', '/admin'));
Auth::actingAs($staff);
$router = new Router();
require APP_PATH . '/routes.php';
$router->get('/admin/orders', static fn () => '', [], 'admin.orders.index');
$router->get('/admin/staff', static fn () => '', [], 'admin.staff.index');
$html = App\Core\View::render('foundation/home', ['title' => 'Dashboard'], 'layouts/admin');
T::contains('<title>Dashboard | ABIHA® Admin</title>', $html, 'admin title');
T::contains('data-keep-scroll="admin-nav"', $html, 'sidebar keeps its scroll');
T::contains(' Orders</a>', $html, 'Order Manager sees Orders');
T::ok(!str_contains($html, 'Staff &amp; roles'), 'no Staff & roles without staff.manage');
T::ok(!str_contains($html, ' Products</a>'), 'links to unbuilt pages are left out');
T::contains('Test staff · Order Manager', $html, 'name and role');
T::contains('<meta name="robots" content="noindex,nofollow">', $html, 'admin is noindex');
Auth::actingAs($admin);
T::contains('Staff &amp; roles', App\Core\View::render('foundation/home', ['title' => 'x'], 'layouts/admin'), 'Super Admin sees Staff & roles');

Kernel::$extraRoutes = [];
Auth::actingAs(null);
