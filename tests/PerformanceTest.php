<?php

declare(strict_types=1);

/* Fast loading: compressed responses, the single minified stylesheet and pre-compressed assets, deferred visitor counting, Server-Timing. */

use App\Core\Database;
use App\Core\Response;
use App\Services\Analytics;
use App\Support\Assets;

T::$current = 'Performance';
reset_db();
$db = Database::get();

// ---- responses are gzip-compressed when worthwhile
$html = '<!doctype html><html><body>' . str_repeat('<p class="product-card">Premium sportswear</p>', 200) . '</body></html>';
$_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';
$r = Response::html($html)->compress();
T::eq('gzip', $r->headers['Content-Encoding'] ?? null, 'a big page is gzip-compressed');
T::eq($html, gzdecode($r->body), 'the compressed body is exactly the page');
T::ok(strlen($r->body) < strlen($html) / 5, 'and at least 5 times smaller');
T::eq((string) strlen($r->body), $r->headers['Content-Length'] ?? null, 'Content-Length matches the compressed body');
T::contains('Accept-Encoding', $r->headers['Vary'] ?? '', 'caches are told the answer depends on Accept-Encoding');
T::ok(!isset(Response::html('<p>tiny</p>')->compress()->headers['Content-Encoding']), 'a tiny page is left alone');
T::ok(!isset((new Response(str_repeat('x', 5000), 200, ['Content-Type' => 'image/png']))->compress()->headers['Content-Encoding']), 'images are not re-compressed');
T::ok(!isset((new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'br']))->compress()->headers['Content-Encoding']) || ((new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'br']))->compress()->headers['Content-Encoding'] === 'br'), 'an already encoded response is not encoded twice');
T::eq('application/json; charset=UTF-8', Response::json(['a' => str_repeat('b', 3000)])->compress()->headers['Content-Type'], 'JSON keeps its type');
T::eq('gzip', Response::json(['a' => str_repeat('b', 3000)])->compress()->headers['Content-Encoding'] ?? null, 'JSON is compressed too');
$_SERVER['HTTP_ACCEPT_ENCODING'] = '';
T::ok(!isset(Response::html($html)->compress()->headers['Content-Encoding']), 'no gzip support = plain page');
unset($_SERVER['HTTP_ACCEPT_ENCODING']);

// ---- Server-Timing outside production (how long PHP took, how many queries)
$st = (string) (http('GET', '/', [], false)->headers['Server-Timing'] ?? '');
T::ok(preg_match('/^app;dur=[\d.]+, db;desc="\d+ queries"$/', $st) === 1, 'Server-Timing shows time and query count');

// ---- one minified stylesheet + pre-compressed copies
$dir = PUBLIC_PATH . '/assets/abiha';
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/console.php') . ' assets:build 2>&1', $out, $code);
T::eq(0, $code, 'assets:build runs');
T::contains('app.min.css', implode("\n", $out), 'it reports the built files');
$min = (string) file_get_contents($dir . '/app.min.css');
$theme = (string) file_get_contents($dir . '/theme.css');
T::ok(str_contains($min, '.pdp-video__play') && str_contains($min, '.product-card'), 'app.min.css contains the base styles and the theme');
T::ok(!str_contains($min, '/*'), 'comments are stripped');
T::ok(strlen($min) < strlen((string) file_get_contents($dir . '/abiha.css')) + strlen($theme), 'and it is smaller than the two sources together');
T::ok(!str_starts_with($min, "\xEF\xBB\xBF"), 'no byte-order mark at the start');
(new ReflectionProperty(Assets::class, 'fresh'))->setValue(null, null);   // look at the files again after the build
$store = http('GET', '/', [], false)->body;
T::contains('assets/abiha/app.min.css', $store, 'pages load the single built stylesheet');
T::ok(!str_contains($store, 'assets/abiha/theme.css'), 'and not the two separate files');
$prev = filemtime($dir . '/theme.css');
touch($dir . '/theme.css', time() + 5);
$ref = new ReflectionProperty(Assets::class, 'fresh');
$ref->setValue(null, null);
T::eq(false, Assets::builtCss(), 'when theme.css is newer than the build, pages fall back to the sources (never stale)');
touch($dir . '/theme.css', $prev);
$ref->setValue(null, null);
T::eq(true, Assets::builtCss(), 'a fresh build is used');
$ht = (string) file_get_contents(PUBLIC_PATH . '/.htaccess');
T::ok(!str_contains($ht, '.gz'), '.htaccess does not rewrite assets to .gz copies (hosts that also compress would send them twice and browsers fail to decode them)');
T::contains('immutable', $ht, 'versioned CSS/JS are cached as immutable');

// ---- counting a visit never delays the page
Analytics::$force = true;
Analytics::$deferred = true;
$db->exec('DELETE FROM page_views');
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
http('GET', '/shop', [], false, ['headers' => ['User-Agent' => $ua]]);
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM page_views'), 'while the page is built, the visit is only remembered');
Analytics::runDeferred();
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM page_views'), 'after the page is sent it is written');
Analytics::runDeferred();
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM page_views'), 'and written only once');
Analytics::$deferred = false;
Analytics::$force = null;
