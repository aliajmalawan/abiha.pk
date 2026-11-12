<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Money;
use App\Core\Request;
use App\Core\Router;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Support\FaviconCutout;
use App\Support\HtmlSanitizer;
use App\Support\LogoCutout;
use App\Support\Menus;
use App\Support\Seo;

/* ---------------- Config ---------------- */
T::$current = 'Config';
T::eq('ABIHA', Config::get('abiha.name'), 'abiha config is loaded (app/Config/abiha.php)');
T::eq('PKR', Config::get('abiha.currency.code'), 'dot access into app/Config/abiha.php');
T::eq(0, Config::get('abiha.currency.decimals'), 'currency has no decimals');
T::eq(30, Config::get('abiha.admin_idle_minutes'), 'admin idle minutes default');
T::eq(7, count(Config::get('abiha.provinces')), 'the seven provinces/territories');
T::eq(['cod', 'bank_transfer'], Config::get('payments.enabled'), 'payments enabled default');
T::eq(null, Config::get('payments.card.driver'), 'card payments have no driver by default');
T::eq([], Config::get('shipping.couriers'), 'no courier registered');
T::eq(6, count(Config::get('faq.groups')), 'FAQ groups ported');
T::eq('fallback', Config::get('abiha.nope.deeper', 'fallback'), 'missing key returns default');
T::eq('fallback', cfg('nope', 'fallback'), 'cfg() helper');
T::ok(Config::has('abiha.contact.email') && !Config::has('abiha.nope'), 'Config::has');
T::eq('Asia/Karachi', date_default_timezone_get(), 'business timezone like the old app');
T::ok(!Config::isProduction(), 'tests are not production');
// Production safety checks on a copy of the configuration.
$saved = ['key' => Config::get('key'), 'url' => Config::get('url'), 'https' => Config::get('https'), 'db' => Config::get('db'), 'mail' => Config::get('mail')];
Config::set('key', 'CHANGE_ME');
Config::set('https', false);
Config::set('db.user', 'root');
Config::set('mail.driver', 'outbox');
$p = implode(' ', Config::productionProblems());
T::ok(str_contains($p, 'APP_KEY') && str_contains($p, 'https') && str_contains($p, 'DB_USERNAME') && str_contains($p, 'MAIL_DRIVER'), 'weak production config is refused');
Config::set('key', str_repeat('a1', 32));
Config::set('https', true);
Config::set('db.user', 'abiha_user');
Config::set('db.pass', 'a-long-database-password');
Config::set('mail.driver', 'smtp');
T::eq([], Config::productionProblems(), 'a sound production config passes');
foreach ($saved as $k => $v) {
    Config::set($k, $v);
}

/* ---------------- Money / helpers ---------------- */
T::$current = 'Money & helpers';
T::eq('PKR 1,200', money(1200), 'money() prints whole rupees like the old views');
T::eq('PKR 0', money(null), 'money(null)');
T::eq('PKR 2,499', money('2499'), 'money() from a string');
T::eq(2499, Money::parse('2,499'), 'parse with comma');
T::eq(2499, Money::parse('2499.00'), 'parse .00');
T::eq(null, Money::parse('24.99'), 'fractional rupees refused');
T::eq(null, Money::parse('-5'), 'negative refused');
T::eq([34, 33, 33], Money::allocate(100, [1, 1, 1]), 'allocate keeps the total');
T::eq(150, Money::percentOf(1500, 10), 'percentOf');
T::eq('&lt;b&gt;&quot;x&quot;&#039;', e('<b>"x"\''), 'e() escapes');
T::eq('', e(null), 'e(null)');
T::eq('http://localhost/abiha.pk', url('/'), 'url("/") without trailing slash (Laravel)');
T::eq('http://localhost/abiha.pk/shop?sort=newest', url('/shop', ['sort' => 'newest', 'empty' => '']), 'url() with query, empty values dropped');
T::eq('/abiha.pk/shop', path_url('shop'), 'path_url');
T::ok(str_starts_with(asset('assets/abiha/abiha.css'), '/abiha.pk/assets/abiha/abiha.css?v='), 'asset() is versioned');
T::ok(str_starts_with(asset('assets/abiha/abiha.css', true), 'http://localhost/abiha.pk/assets/'), 'asset(..., true) is absolute');
T::eq('Hello wor...', str_limit('<p>Hello   world again</p>', 9), 'str_limit strips tags and whitespace');
T::eq('t-shirts-polo', slugify('T-Shirts & Polo'), 'slugify') ;
T::ok(str_contains(csrf_field(), 'name="_token"') && str_contains(csrf_field(), csrf_token()), 'csrf_field');
T::eq('<input type="hidden" name="_method" value="PATCH">', method_field('patch'), 'method_field');
$svg = icon('heart', 18, 'text-gold');
T::ok(str_starts_with($svg, '<svg width="18" height="18" stroke-width="1.75" aria-hidden="true" focusable="false" class="text-gold"'), 'icon() rewrites the opening tag');
T::ok(!str_contains($svg, 'lucide') && !str_contains($svg, '<!--') && str_contains($svg, '<path'), 'icon() drops the class/comment, keeps the shapes');
T::ok(str_contains(icon('mail', 16), '<rect'), 'icon() keeps child dimensions (mail rect)');
T::eq('', icon('no-such-icon'), 'unknown icon prints nothing');
T::ok(str_contains(icon('chevron-down', 18, ['class' => 'transition-transform', 'x-bind:class' => "open && 'rotate-180'"]), 'x-bind:class="open &amp;&amp; &#039;rotate-180&#039;"'), 'icon() extra attributes');
T::ok(str_contains(carrier_logo('dhl', 22), 'alt="DHL"') && str_contains(carrier_logo('fedex'), 'alt="FEDEX"') && carrier_logo('tcs') === '', 'carrier logos');
T::eq('08:15', dt('2026-11-12 08:15:00', 'H:i'), 'dt() shows stored business time unchanged');
T::eq(19, strlen(now()), 'now() is a SQL datetime');

/* ---------------- Router & route() ---------------- */
T::$current = 'Router';
$r = new Router();
$hit = null;
$r->get('/category/{slug}', function (Request $q) use (&$hit) { $hit = 'get:' . $q->params['slug']; return 'ok'; }, [], 'category');
$r->get('/customers/{id:\d+}', function (Request $q) use (&$hit) { $hit = 'cust:' . $q->params['id']; return 'ok'; }, [], 'customers.show');
$r->patch('/cart/items/{key}', function (Request $q) use (&$hit) { $hit = 'patch:' . $q->params['key']; return 'ok'; }, ['nocsrf'], 'cart.update');
$r->delete('/cart/items/{key}', function (Request $q) use (&$hit) { $hit = 'delete:' . $q->params['key']; return 'ok'; }, ['nocsrf'], 'cart.remove');
$r->put('/account/profile', function () use (&$hit) { $hit = 'put'; return 'ok'; }, ['nocsrf'], 'account.profile.update');
$r->group('/admin', ['nocsrf'], function (Router $g) use (&$hit) {
    $g->post('/home', function () use (&$hit) { $hit = 'group'; return 'ok'; }, [], 'admin.home.store');
});
$r->dispatch(Request::create('GET', '/category/shirts'));
T::eq('get:shirts', $hit, 'GET with a parameter');
$r->dispatch(Request::create('PATCH', '/cart/items/5-7'));
T::eq('patch:5-7', $hit, 'PATCH route');
$r->dispatch(Request::create('POST', '/cart/items/5-0', ['_method' => 'DELETE']));
T::eq('delete:5-0', $hit, 'POST with _method=DELETE reaches the DELETE route');
$r->dispatch(Request::create('POST', '/account/profile', ['_method' => 'put']));
T::eq('put', $hit, '_method is case-insensitive');
$r->dispatch(Request::create('POST', '/admin/home'));
T::eq('group', $hit, 'group prefix');
$r->dispatch(Request::create('HEAD', '/category/x'));
T::eq('get:x', $hit, 'HEAD is served by GET routes');
T::throws(HttpException::class, fn () => $r->dispatch(Request::create('GET', '/customers/abc')), 'parameter pattern is enforced', 'not found');
$r->dispatch(Request::create('GET', '/customers/42'));
T::eq('cust:42', $hit, 'parameter pattern matches');
try {
    $r->dispatch(Request::create('POST', '/category/shirts'));
    T::ok(false, '405 expected');
} catch (HttpException $e) {
    T::ok($e->status === 405 && ($e->headers['Allow'] ?? '') === 'GET', '405 with Allow header');
}
T::eq('http://localhost/abiha.pk/category/shirts', route('category', ['slug' => 'shirts']), 'route() by name');
T::eq('http://localhost/abiha.pk/category/shirts', route('category', 'shirts'), 'route() with a single value');
T::eq('http://localhost/abiha.pk/category/polo', route('category', ['id' => 3, 'name' => 'Polo', 'slug' => 'polo', 'parent_id' => null]), 'route() with a database row uses its slug');
T::eq('http://localhost/abiha.pk/customers/42?tab=orders', route('customers.show', ['id' => 42, 'tab' => 'orders']), 'route() with pattern + query');
T::eq('/abiha.pk/cart/items/1-2', route('cart.remove', ['key' => '1-2'], false), 'relative route()');
T::throws(InvalidArgumentException::class, fn () => route('nope'), 'unknown route name throws');
View::share('currentPath', '/category/shirts');
T::ok(route_is('category') && !route_is('cart.update'), 'route_is()');
T::ok(route_is('cart.*') === false && route_is('category', 'cart.update'), 'route_is() with several names');
View::share('currentPath', '/cart/items/9');
T::ok(route_is('cart.*'), 'route_is() with a prefix wildcard');
T::ok($r->has('category') && !$r->has('home'), 'Router::has()');
$req = Request::create('GET', '/admin/orders');
T::ok($req->is('admin/*') && $req->is('admin', 'admin/*') && !$req->is('account/*'), 'Request::is()');

/* ---------------- Validator ---------------- */
T::$current = 'Validator';
$v = Validator::make(['name' => '  Sana ', 'email' => 'bad', 'qty' => '3', 'agree' => 'on', 'note' => ''], [
    'name' => 'required|string|min:2|max:120', 'email' => 'required|email', 'qty' => 'required|integer|min:1|max:99',
    'agree' => 'boolean', 'note' => 'nullable|string|max:10', 'missing' => 'required',
]);
T::eq('The email field must be a valid email address.', $v->errors()['email'] ?? null, 'Laravel email message');
T::eq('The missing field is required.', $v->errors()['missing'] ?? null, 'Laravel required message');
T::ok(!isset($v->errors()['name']) && !isset($v->errors()['qty']), 'valid fields have no error');
$ok = Validator::make(['name' => '  Sana ', 'qty' => '3', 'agree' => 'on', 'note' => ''], ['name' => 'required|string', 'qty' => 'required|integer', 'agree' => 'boolean', 'note' => 'nullable|string', 'other' => 'nullable'])->validated();
T::eq(['name' => 'Sana', 'qty' => 3, 'agree' => true, 'note' => null, 'other' => null], $ok, 'trimmed, typed, empty -> null');
$m = Validator::make(['password' => 'abc', 'password_confirmation' => 'abd', 'name' => 'x', 'qty' => '120', 'size' => 'XXXL', 'phone' => '12345'], [
    'password' => 'required|confirmed', 'name' => 'min:2', 'qty' => 'integer|max:99', 'size' => 'in:S,M,L', 'phone' => 'pk_phone',
])->errors();
T::eq('The password field confirmation does not match.', $m['password'] ?? null, 'confirmed');
T::eq('The name field must be at least 2 characters.', $m['name'] ?? null, 'min (string)');
T::eq('The qty field must not be greater than 99.', $m['qty'] ?? null, 'max (number)');
T::eq('The selected size is invalid.', $m['size'] ?? null, 'in');
T::eq('Enter a valid Pakistani phone number, for example 0300 1234567 or +92 300 1234567.', $m['phone'] ?? null, 'Pakistani phone rule');
T::ok(Validator::pakistaniPhone('+92 300 1234567') && Validator::pakistaniPhone('0300-1234567') && Validator::pakistaniPhone('042 35761234') && !Validator::pakistaniPhone('0300 12345'), 'phone normalisation');
$c = Validator::make(['email' => 'x'], ['email' => 'required|email'], ['email.email' => 'Custom :attribute.'], ['email' => 'email address'])->errors();
T::eq('Custom email address.', $c['email'], 'custom message and attribute name');
$pw = Validator::make(['password' => 'abcdefgh'], ['password' => 'required|min:8|password_letters|password_numbers'])->errors();
T::eq('The password field must contain at least one number.', $pw['password'] ?? null, 'password strength messages');
T::throws(ValidationException::class, fn () => Validator::make([], ['a' => 'required', 'b' => 'required'])->validated(), 'validated() throws', 'The a field is required. (and 1 more error)');
T::eq(['x' => 'm'], ValidationException::withMessages(['x' => ['m', 'n']])->errors, 'withMessages takes the first message');
T::ok(Validator::make(['u' => '/shop?sale=1'], ['u' => 'link'])->passes() && Validator::make(['u' => '//evil.example'], ['u' => 'link'])->fails(), 'link rule');
T::ok(Validator::make(['a' => '2026-01-01', 'b' => '2025-12-31'], ['a' => 'date', 'b' => 'nullable|date|after_or_equal:a'])->fails(), 'after_or_equal');

/* ---------------- HtmlSanitizer ---------------- */
T::$current = 'HtmlSanitizer';
$clean = HtmlSanitizer::clean('<p onclick="x()">Hi <a href="javascript:alert(1)">bad</a> <a href="https://ok.example">ok</a><script>alert(1)</script><img src="x" onerror="y"><div><b>bold</b></div></p>');
T::ok(!str_contains($clean, 'onclick') && !str_contains($clean, 'javascript') && !str_contains($clean, 'script') && !str_contains($clean, 'onerror'), 'dangerous markup removed');
T::ok(str_contains($clean, '<a href="https://ok.example" rel="noopener noreferrer nofollow">ok</a>') && str_contains($clean, '<b>bold</b>'), 'safe markup kept');
T::eq('', HtmlSanitizer::clean('  '), 'empty input');
T::ok(!str_contains(HtmlSanitizer::clean('<a href="java&#09;script:alert(1)">x</a>'), 'href'), 'obfuscated scheme removed');

/* ---------------- Menus / Seo (pure parts) ---------------- */
T::$current = 'Menus & Seo';
T::ok(Menus::safe('/shop?sale=1') && Menus::safe('https://instagram.com/abiha') && !Menus::safe('javascript:alert(1)') && !Menus::safe('//evil.example') && !Menus::safe('data:text/html,x'), 'Menus::safe');
T::ok(Seo::isPrivatePath('/account/orders') && Seo::isPrivatePath('/cart') && Seo::isPrivatePath('/admin') && !Seo::isPrivatePath('/shop') && !Seo::isPrivatePath('/'), 'private paths');
T::ok(Seo::hasViewParams(['sort' => 'newest']) && !Seo::hasViewParams(['page' => '2']) && !Seo::hasViewParams(['utm_source' => 'x']), 'view parameters');
T::eq('Hello world & more', Seo::plain('<p>Hello <b>world</b> &amp; more</p><script>bad()</script>'), 'Seo::plain');
Seo::reset();
Seo::breadcrumbs([['Home', 'http://x/'], ['Shirts', '']]);
Seo::schema(['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => '</script><b>']);
$ld = Seo::jsonLd();
T::ok(str_contains($ld, '"@type":"BreadcrumbList"') && str_contains($ld, '"position":2') && !str_contains($ld, '</script><b>'), 'JSON-LD with breadcrumbs, HTML-safe');
Seo::reset();

/* ---------------- Passwords ---------------- */
T::$current = 'Passwords';
// The hash Laravel's UserFactory used for "password" ($2y$10$, bcrypt) and a fresh Laravel-style $2y$12$ hash.
$laravelHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
T::ok(Auth::verifyPassword('password', $laravelHash), 'a Laravel bcrypt hash verifies');
T::ok(!Auth::verifyPassword('Password', $laravelHash), 'wrong password fails');
T::ok(Auth::verifyPassword('password', str_replace('$2y$', '$2b$', $laravelHash)), '$2b$ variant verifies');
$h = Auth::hashPassword('Secret123!');
T::ok(str_starts_with($h, '$2y$12$') && strlen($h) === 60, 'new hashes are bcrypt cost 12 (what Laravel writes)');
T::ok(Auth::needsRehash($laravelHash) && !Auth::needsRehash($h), 'cost-10 hashes are upgraded on login');
T::ok(!Auth::verifyPassword('x', ''), 'empty hash never verifies');

/* ---------------- Logo / favicon cut-outs (pure GD) ---------------- */
T::$current = 'Cut-outs';
$img = imagecreatetruecolor(64, 48);
imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
imagefilledrectangle($img, 20, 14, 44, 34, imagecolorallocate($img, 210, 160, 50));       // gold artwork
imagefilledrectangle($img, 28, 20, 36, 28, imagecolorallocate($img, 0, 0, 0));           // black letter inside
$out = LogoCutout::apply($img);
T::ok($out !== $img, 'black background is cut out');
T::eq(127, (imagecolorat($out, 1, 1) >> 24) & 127, 'corner becomes transparent');
T::eq(0, (imagecolorat($out, 32, 24) >> 24) & 127, 'black inside the artwork stays opaque');
T::eq(0, (imagecolorat($out, 22, 16) >> 24) & 127, 'artwork stays opaque');
$white = imagecreatetruecolor(32, 32);
imagefill($white, 0, 0, imagecolorallocate($white, 255, 200, 0));
T::ok(LogoCutout::apply($white) === $white, 'non-black corners: unchanged');
$fav = imagecreatetruecolor(32, 32);
imagefill($fav, 0, 0, imagecolorallocate($fav, 255, 255, 255));
imagefilledellipse($fav, 16, 16, 18, 18, imagecolorallocate($fav, 20, 20, 20));
$cut = FaviconCutout::apply($fav);
T::ok($cut !== $fav && ((imagecolorat($cut, 0, 0) >> 24) & 127) === 127 && ((imagecolorat($cut, 16, 16) >> 24) & 127) === 0, 'favicon white background cut out, artwork kept');
