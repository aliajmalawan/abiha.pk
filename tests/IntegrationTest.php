<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Uploads;
use App\Core\ValidationException;
use App\Mail\Mailer;
use App\Services\ActivityLogger;
use App\Services\Cart;
use App\Services\ImageStore;
use App\Services\Navigation;
use App\Services\Wishlist;
use App\Support\CatalogCache;
use App\Support\Img;
use App\Support\Menus;
use App\Support\Seo;
use App\Support\Settings;
use App\Support\SiteSettings;

$db = Database::get();
reset_db();

/* ---------------- Schema / base data ---------------- */
T::$current = 'Schema';
T::eq(7, (int) $db->value('SELECT COUNT(*) FROM roles'), 'seven roles from 002_base_data');
T::eq(17, (int) $db->value('SELECT COUNT(*) FROM permissions'), 'seventeen permissions');
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM roles WHERE slug = "customer" AND is_staff = 1'), 'customer is not a staff role');
T::ok((bool) $db->value("SHOW TABLES LIKE 'rate_limits'") && (bool) $db->value("SHOW TABLES LIKE 'email_outbox'"), 'runtime tables (003)');

/* ---------------- Settings ---------------- */
T::$current = 'Settings';
T::eq('dflt', Settings::get('contact.phone', 'dflt'), 'missing setting -> default');
Settings::put('contact.phone', '+92 300 0000000');
Settings::put('payments.enabled', ['cod']);
Settings::put('seo.allow_indexing', false);
Settings::put('cod.note', null);
T::eq('+92 300 0000000', Settings::get('contact.phone'), 'string setting');
T::eq(['cod'], Settings::get('payments.enabled'), 'array setting (JSON)');
T::eq(false, Settings::get('seo.allow_indexing', true), 'false is a value, not "missing"');
T::eq('x', Settings::get('cod.note', 'x'), 'stored null counts as not set');
T::eq('["cod"]', $db->value('SELECT `value` FROM settings WHERE `key` = ?', ['payments.enabled']), 'stored as JSON like Laravel');
$cacheFile = STORAGE_PATH . '/cache/settings-' . Config::get('db.name') . '.php';
T::ok(is_file($cacheFile), 'file cache written (named after the test database)');
$db->exec("UPDATE settings SET `value` = '\"changed behind our back\"' WHERE `key` = 'contact.phone'");
Settings::forgetMemory();
T::eq('+92 300 0000000', Settings::get('contact.phone'), 'served from the file cache');
Settings::put('contact.phone', '+92 321 1111111');
T::ok(!is_file($cacheFile) || Settings::get('contact.phone') === '+92 321 1111111', 'put() invalidates the cache');
Settings::forgetMemory();
T::eq('+92 321 1111111', Settings::get('contact.phone'), 'fresh value after put()');
Settings::forget('contact.phone');
T::eq(null, Settings::get('contact.phone'), 'forget()');
T::eq('dev', setting('nope', 'dev'), 'setting() helper');

/* ---------------- SiteSettings ---------------- */
T::$current = 'SiteSettings';
Settings::put('site.name', 'Abiha Apparel');
Settings::put('currency.symbol', 'Rs.');
SiteSettings::apply();
T::eq('Abiha Apparel', cfg('abiha.name'), 'site.name overrides abiha.name');
T::eq('Rs. 1,000', money(1000), 'currency.symbol is used by money()');
Settings::forget('site.name');
Settings::forget('currency.symbol');
SiteSettings::apply();
T::eq('ABIHA', cfg('abiha.name'), 'defaults come back when the setting is removed');
T::eq('PKR 1,000', money(1000), 'default symbol PKR');
T::eq(null, SiteSettings::asset('site.logo'), 'no uploaded logo -> null');
Settings::put('site.logo', 'branding/missing.png');
T::eq(null, SiteSettings::logoPath(), 'a logo setting whose file is missing is ignored');
T::ok(str_contains(logo(76), 'assets/brand/abiha-logo-full-300.png') && str_contains(logo(76), 'width="76" height="55"') && str_contains(logo(76), 'alt="ABIHA®"'), 'default logo artwork (small fallback, 1160:836 ratio)');
T::ok(str_contains(logo(160), 'abiha-logo-full-600.png') && str_contains(logo(76, true), 'loading="lazy"') && str_contains(logo(76), '--logo-w: 76px'), 'logo sizes and lazy loading');

/* ---------------- Menus ---------------- */
T::$current = 'Menus';
T::eq(3, count(Menus::get('header')), 'default header links');
T::eq('http://localhost/abiha.pk/shop?sort=newest', Menus::get('header')[0]['href'], 'absolute href');
$help = Menus::get('footer_help');
T::eq('http://localhost/abiha.pk/login', end($help)['href'], 'guests: "My account" goes to sign-in');
Settings::put('menu.header', [['label' => 'Bad', 'url' => 'javascript:alert(1)'], ['label' => 'Insta', 'url' => 'https://instagram.com/x'], ['label' => '', 'url' => '/x']]);
T::eq([['label' => 'Insta', 'url' => 'https://instagram.com/x', 'href' => 'https://instagram.com/x']], Menus::get('header'), 'saved list: unsafe and empty rows dropped');
Settings::put('menu.footer_shop', []);
T::eq([], Menus::get('footer_shop'), 'a saved empty list shows no links');

/* ---------------- Auth ---------------- */
T::$current = 'Auth';
reset_db();
$customer = Fx::user('customer', ['email' => 'sana@example.test']);
$admin = Fx::user('admin');
$staff = Fx::user('staff');
$content = Fx::user('content_manager');
$noRole = Fx::user('customer', ['role_id' => null]);
T::eq(null, Auth::user(), 'guest');
T::eq(null, Auth::attempt('sana@example.test', 'wrong'), 'wrong password');
T::eq(null, Auth::attempt('nobody@example.test', Fx::PASSWORD), 'unknown email');
$row = Auth::attempt('SANA@Example.test ', Fx::PASSWORD);
T::eq((int) $customer['id'], (int) ($row['id'] ?? 0), 'attempt() is case-insensitive on email');
Auth::login($row);
$u = Auth::user();
T::ok($u !== null && $u['role'] === 'customer' && $u['is_staff'] === false && $u['permissions'] === [], 'customer shape');
T::ok(!isset($u['password']) && !isset($u['remember_token']), 'no password in Auth::user()');
T::ok(Auth::check() && !Auth::isStaff() && !Auth::can('orders.view'), 'customer is not staff');
T::ok($db->value('SELECT last_login_at FROM users WHERE id = ?', [$customer['id']]) !== null, 'last_login_at stamped');
Auth::actingAs($staff);
T::ok(Auth::isStaff() && Auth::can('orders.view') && Auth::can('payments.verify') && !Auth::can('products.manage') && !Auth::isSuperAdmin(), 'Order Manager permissions');
Auth::actingAs($content);
T::ok(Auth::can('content.manage') && Auth::can('messages.manage') && !Auth::can('settings.manage'), 'Content Manager permissions');
Auth::actingAs($admin);
T::ok(Auth::isSuperAdmin() && Auth::can('staff.manage') && Auth::can('anything.at.all'), 'Super Admin holds every permission');
Auth::actingAs($noRole);
T::ok(Auth::check() && !Auth::isStaff() && Auth::user()['role'] === null, 'a user without a role is a customer');
// Disabled account loses access on its next request.
Auth::actingAs($staff);
$db->exec('UPDATE users SET is_active = 0 WHERE id = ?', [$staff['id']]);
Auth::forget();
T::ok(Auth::user() === null && Auth::wasDisabled(), 'disabled account is signed out');
T::eq(null, Auth::attempt($staff['email'], Fx::PASSWORD), 'disabled account cannot sign in');
$db->exec('UPDATE users SET is_active = 1, deleted_at = ? WHERE id = ?', [now(), $staff['id']]);
T::eq(null, Auth::attempt($staff['email'], Fx::PASSWORD), 'soft-deleted account cannot sign in');
// Password change signs out other sessions.
Auth::actingAs($customer);
$db->exec('UPDATE users SET password = ? WHERE id = ?', [Auth::hashPassword('NewPassword9'), $customer['id']]);
Auth::forget();
T::eq(null, Auth::user(), 'a session from before the password change is signed out');
Auth::actingAs(Auth::find((int) $customer['id']));
$db->exec('UPDATE users SET password = ? WHERE id = ?', [Auth::hashPassword('Other1234'), $customer['id']]);
Auth::refreshPasswordFingerprint();
T::ok(Auth::user() !== null, 'refreshPasswordFingerprint() keeps the current session');
// Legacy cost-10 hash is upgraded on login.
$db->exec('UPDATE users SET password = ? WHERE id = ?', ['$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', $customer['id']]);
T::ok(Auth::attempt('sana@example.test', 'password') !== null, 'Laravel-made hash signs in');
T::ok(str_starts_with((string) $db->value('SELECT password FROM users WHERE id = ?', [$customer['id']]), '$2y$12$'), 'and is rehashed at cost 12');
// Login keeps the guest cart token and wishlist for merging, and rotates the CSRF token.
Auth::actingAs(null);
Session::put('cart_token', 'tok');
Session::put('wishlist_ids', [5]);
$before = csrf_token();
Auth::login(Auth::find((int) $customer['id']));
T::ok(Session::get('cart_token') === 'tok' && Session::get('wishlist_ids') === [5], 'guest cart token and wishlist survive login');
T::ok(csrf_token() !== $before, 'new CSRF token after login');
Auth::logout();
T::ok(Auth::user() === null && Session::get('cart_token') === null, 'logout clears the session');
T::ok(Auth::userCan(Auth::present($admin), 'x.y') && !Auth::userCan(Auth::present($customer), 'orders.view'), 'userCan() for listings');

/* ---------------- Cart / Wishlist / Navigation stubs ---------------- */
T::$current = 'Cart & wishlist stubs';
reset_db();
$u = Fx::user('customer');
$p1 = Fx::product();
$p2 = Fx::product();
T::eq(0, Cart::count(), 'guest without a cart');
$cartId = Fx::insert('carts', ['token' => str_repeat('g', 40), 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('cart_items', ['cart_id' => $cartId, 'product_id' => $p1['id'], 'quantity' => 2]);
Fx::insert('cart_items', ['cart_id' => $cartId, 'product_id' => $p2['id'], 'quantity' => 3]);
T::eq(0, Cart::count(), 'another guest\'s cart is not mine');
Session::put('cart_token', str_repeat('g', 40));
T::eq(5, Cart::count(), 'guest cart: sum of quantities');
$userCart = Fx::insert('carts', ['user_id' => $u['id'], 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('cart_items', ['cart_id' => $userCart, 'product_id' => $p1['id'], 'quantity' => 1]);
Auth::actingAs($u);
T::eq(1, Cart::count(), 'signed in: the user\'s own cart');
T::eq([], Wishlist::ids(), 'empty wishlist');
$w = Fx::insert('wishlists', ['user_id' => $u['id'], 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('wishlist_items', ['wishlist_id' => $w, 'product_id' => $p2['id']]);
Fx::insert('wishlist_items', ['wishlist_id' => $w, 'product_id' => $p1['id']]);
T::eq([(int) $p2['id'], (int) $p1['id']], Wishlist::ids(), 'user wishlist ids');
Auth::actingAs(null);
Session::put('wishlist_ids', ['7', 9]);
T::eq([7, 9], Wishlist::ids(), 'guest wishlist from the session');

T::$current = 'Navigation';
$top = Fx::category(['name' => 'Sportswear', 'slug' => 'sportswear', 'sort_order' => 2]);
$top2 = Fx::category(['name' => 'Teamsport', 'slug' => 'teamsport', 'sort_order' => 1]);
Fx::category(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => 0]);
Fx::category(['name' => 'Gone', 'slug' => 'gone', 'deleted_at' => now()]);
Fx::category(['name' => 'Polo', 'slug' => 'polo', 'parent_id' => $top['id'], 'sort_order' => 2]);
Fx::category(['name' => 'T-shirts', 'slug' => 't-shirts', 'parent_id' => $top['id'], 'sort_order' => 1]);
Fx::category(['name' => 'Off', 'slug' => 'off', 'parent_id' => $top['id'], 'is_active' => 0]);
CatalogCache::bump();
$nav = Navigation::categories();
T::eq(['teamsport', 'sportswear'], array_column($nav, 'slug'), 'active roots in sort order');
T::eq(['t-shirts', 'polo'], array_column($nav[1]['children'], 'slug'), 'active children in sort order');
Fx::category(['name' => 'New root', 'slug' => 'new-root']);
T::eq(2, count(Navigation::categories()), 'cached until the catalogue changes');
CatalogCache::bump();
T::eq(3, count(Navigation::categories()), 'CatalogCache::bump() refreshes');

/* ---------------- CatalogCache ---------------- */
T::$current = 'CatalogCache';
$calls = 0;
$build = function () use (&$calls) { $calls++; return ['n' => $calls]; };
CatalogCache::remember('k', 60, $build);
CatalogCache::reset();                 // a new request: in-process memo gone, file stays
T::eq(['n' => 1], CatalogCache::remember('k', 60, $build), 'served from the file');
T::eq(1, $calls, 'built once');
CatalogCache::bump();
T::eq(['n' => 2], CatalogCache::remember('k', 60, $build), 'rebuilt after bump()');
CatalogCache::remember('ttl', 0, $build);
CatalogCache::reset();
sleep(1);
CatalogCache::remember('ttl', 0, $build);
T::eq(4, $calls, 'expired entries are rebuilt');

/* ---------------- ActivityLogger ---------------- */
T::$current = 'ActivityLogger';
Auth::actingAs($u);
$id = ActivityLogger::log('product.updated', 'Product', $p1['id'], ['price' => [2500, 2400]]);
$log = $db->one('SELECT * FROM activity_logs WHERE id = ?', [$id]);
T::ok($log['subject_type'] === 'App\\Models\\Product' && (int) $log['subject_id'] === (int) $p1['id'] && (int) $log['user_id'] === (int) $u['id'], 'Laravel morph class name and user kept');
T::eq(['price' => [2500, 2400]], json_decode((string) $log['properties'], true), 'properties as JSON');
T::eq(['price' => ['2500', 2400]], ActivityLogger::diff(['price' => '2500', 'name' => 'A', 'updated_at' => 1], ['price' => 2400, 'name' => 'A', 'updated_at' => 2]), 'diff() ignores unchanged and hidden fields');
Auth::actingAs(null);

/* ---------------- ImageStore / Img ---------------- */
T::$current = 'ImageStore';
$path = ImageStore::store(Fx::image(1200, 800, 'jpg'), 'products', 'image');
T::ok((bool) preg_match('#^products/[A-Za-z0-9]{40}\.jpg$#', $path), 'random 40-char name, extension from the real type');
T::ok(is_file(Img::path($path)) && str_starts_with(Img::path($path), BASE_PATH . '/tests/.tmp/public'), 'stored on the (test) public disk');
T::eq([320, 480, 640, 960], Img::widths($path), 'WebP variants smaller than the original');
T::eq([1200, 800], Img::dimensions($path), 'dimensions');
T::ok(str_contains(media($path, 450), '-w480.webp?v='), 'media() picks the smallest variant >= width');
T::ok(str_contains(media($path), '.jpg?v=') && str_starts_with(media($path), 'http://localhost/abiha.pk/storage/products/'), 'media() without width = original under /storage');
T::ok(substr_count(media_srcset($path), 'w,') === 3 && str_ends_with(media_srcset($path), '960w'), 'srcset');
$pic = Img::picture($path, 'Alt "x"', '50vw', false, false, ['class' => 'size-full']);
T::ok(str_contains($pic, '<source type="image/webp"') && str_contains($pic, 'width="1200" height="800"') && str_contains($pic, 'loading="lazy"') && str_contains($pic, 'class="size-full"') && str_contains($pic, 'alt="Alt &quot;x&quot;"'), 'picture markup');
$big = ImageStore::store(Fx::image(3000, 1000, 'png'), 'banners', 'image');
T::eq([2400, 800], Img::dimensions($big), 'longest edge limited to 2400 px');
T::ok(str_ends_with($big, '.png'), 'PNG stays PNG');
// A polyglot: valid JPEG followed by PHP code. The re-encoded file must not contain the payload or EXIF.
$poly = Fx::image(300, 300, 'jpg');
file_put_contents($poly['tmp_name'], '<?php echo "pwned"; ?>', FILE_APPEND);
$clean = ImageStore::store($poly, 'products', 'image');
T::ok(!str_contains((string) file_get_contents(Img::path($clean)), '<?php'), 'smuggled code is gone after re-encoding');
$fake = Fx::image(10, 10, 'jpg');
file_put_contents($fake['tmp_name'], 'GIF89a not an image');
T::throws(ValidationException::class, fn () => ImageStore::store($fake, 'products', 'image'), 'non-image refused', 'not a valid JPG, PNG or WebP image');
$logoUpload = Fx::image(400, 200, 'jpg', function ($im) {
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    imagefilledrectangle($im, 120, 60, 280, 140, imagecolorallocate($im, 210, 160, 50));
});
$logoPath = ImageStore::store($logoUpload, 'branding', 'logo', 1600, true);
$li = imagecreatefrompng(Img::path($logoPath));
T::ok(str_ends_with($logoPath, '.png') && ((imagecolorat($li, 2, 2) >> 24) & 127) === 127, 'logo on black: background cut out, saved as PNG');
$favUpload = Fx::image(64, 64, 'png', function ($im) {
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledellipse($im, 32, 32, 30, 30, imagecolorallocate($im, 20, 20, 20));
});
$favPath = ImageStore::storeIcon($favUpload, 'branding', 'favicon');
$fi = imagecreatefrompng(Img::path($favPath));
T::ok(((imagecolorat($fi, 0, 0) >> 24) & 127) === 127, 'favicon: white background cut out');
$ico = Fx::image(16, 16, 'png');
file_put_contents($ico['tmp_name'], "\x00\x00\x01\x00" . str_repeat("\x01", 200));
T::ok(str_ends_with(ImageStore::storeIcon($ico, 'branding', 'favicon'), '.ico'), 'genuine ICO accepted as is');
Settings::put('site.logo', $logoPath);
T::ok(str_contains(logo(76), 'logo--own') && str_contains(logo(76), '--logo-ar: 400 / 200') && str_contains(logo(76), '/storage/branding/'), 'uploaded logo used with its own aspect ratio');
T::ok(SiteSettings::asset('site.logo') !== null, 'SiteSettings::asset for the uploaded logo');
Settings::forget('site.logo');
Img::delete($path);
T::ok(!is_file(Img::path($path)) && Img::widths($path) === [], 'Img::delete removes the original and variants');
T::throws(InvalidArgumentException::class, fn () => Img::path('../.env'), 'path traversal refused');

/* ---------------- Private uploads ---------------- */
T::$current = 'Uploads';
$proof = Fx::image(300, 300, 'jpg');
$rel = Uploads::storePrivate($proof, 'payment-proofs/12', ['image/jpeg' => 'jpg', 'application/pdf' => 'pdf'], 5 * 1024 * 1024, 'proof');
T::ok((bool) preg_match('#^payment-proofs/12/[a-f0-9]{32}\.jpg$#', $rel) && is_file(Uploads::privatePath($rel)), 'proof stored on the private disk');
$txt = Fx::image(10, 10, 'jpg');
file_put_contents($txt['tmp_name'], 'plain text');
T::throws(ValidationException::class, fn () => Uploads::storePrivate($txt, 'x', ['image/jpeg' => 'jpg'], 1000000, 'proof'), 'wrong type refused', 'must be a file of type');
Uploads::deletePrivate($rel);
T::ok(!is_file(Uploads::privatePath($rel)), 'deletePrivate');

/* ---------------- RateLimiter ---------------- */
T::$current = 'RateLimiter';
$req = App\Core\Request::create('POST', '/login');
for ($i = 0; $i < 10; $i++) {
    RateLimiter::throttle('login', $req);
}
T::throws(HttpException::class, fn () => RateLimiter::throttle('login', $req), '11th login attempt in a minute is 429', 'Too Many Attempts.');
RateLimiter::throttle('register', $req);
T::ok(true, 'limiters do not share counters');
T::ok(!RateLimiter::tooMany('k', 2) && RateLimiter::hit('k') === 1 && RateLimiter::hit('k') === 2 && RateLimiter::tooMany('k', 2), 'custom keys');
RateLimiter::clear('k');
T::eq(0, RateLimiter::attempts('k'), 'clear()');

/* ---------------- Mailer ---------------- */
T::$current = 'Mailer';
@mkdir(APP_PATH . '/Views/emails', 0775, true);
$tpl = APP_PATH . '/Views/emails/_foundation_test.php';
file_put_contents($tpl, '<?php $subject = "Hello " . $name; $footer = "Footer note"; ?><tr><td style="padding:8px">Hi <?= e($name) ?> <a href="https://x.example/o">order</a></td></tr>');
try {
    [$subject, $html, $text] = Mailer::render('_foundation_test', ['name' => 'Sana <b>']);
    T::eq('Hello Sana <b>', $subject, 'subject from the template');
    T::ok(str_contains($html, 'Hi Sana &lt;b&gt;') && str_contains($html, '#C99A35') && str_contains($html, 'assets/brand/abiha-logo-full-300.png') && str_contains($html, 'Footer note'), 'wrapped in the ABIHA email shell');
    T::ok(str_contains($text, 'order (https://x.example/o)'), 'plain-text part with link targets');
    T::ok(Mailer::sendNow('a@example.test', '_foundation_test', ['name' => 'A']) && Mailer::$sent[0]['to'] === 'a@example.test', 'sendNow (array driver)');
    Mailer::queue('b@example.test', '_foundation_test', ['name' => 'B']);
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'pending'"), 'queued');
    T::eq(1, Mailer::flush(), 'flush sends due mail');
    T::eq('sent', $db->value('SELECT status FROM email_outbox LIMIT 1'), 'marked sent');
} finally {
    @unlink($tpl);
}

/* ---------------- Seo with settings ---------------- */
T::$current = 'Seo';
Settings::put('seo.allow_indexing', true);
T::ok(Seo::indexingDisabled(), 'never indexable outside production');
Config::set('env', 'production');
T::ok(!Seo::indexingDisabled(), 'production + allowed = indexable');
Settings::put('seo.allow_indexing', false);
T::ok(Seo::indexingDisabled(), 'owner switched indexing off');
Settings::put('seo.allow_indexing', true);
Seo::reset();
T::eq(null, Seo::robots(App\Core\Request::create('GET', '/shop')), 'clean listing indexable');
T::eq('noindex,follow', Seo::robots(App\Core\Request::create('GET', '/shop', ['sort' => 'newest'])), 'sorted listing: noindex,follow');
T::eq('noindex,nofollow', Seo::robots(App\Core\Request::create('GET', '/checkout')), 'checkout: noindex,nofollow');
Seo::noindex();
T::eq('noindex,follow', Seo::robots(App\Core\Request::create('GET', '/products/x')), 'page asked for noindex');
Seo::reset();
T::eq('http://localhost/abiha.pk/shop?page=2', Seo::canonicalUrl(App\Core\Request::create('GET', '/shop', ['page' => '2', 'utm_source' => 'x'])), 'canonical keeps ?page=N only');
T::eq('http://localhost/abiha.pk/shop', Seo::canonicalUrl(App\Core\Request::create('GET', '/shop', ['page' => '2', 'sort' => 'x'])), 'filtered: canonical is the clean listing');
Config::set('env', 'testing');
Settings::put('contact.email', 'shop@example.test');
$org = Seo::organization();
T::ok($org['@type'] === 'Organization' && $org['contactPoint']['email'] === 'shop@example.test' && !isset($org['sameAs']), 'Organization uses only entered facts');
