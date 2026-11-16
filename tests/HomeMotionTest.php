<?php

declare(strict_types=1);

/*
 * Home page depth and motion (public/assets/abiha/home.js + the "Home page depth" part of theme.css): the hooks are on the
 * page, the script is loaded only there and contains no inline code, every effect is behind prefers-reduced-motion and the
 * tilt behind a fine pointer, and nothing crops a product picture.
 */

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;
use App\Support\Img;
use App\Services\ImageStore;
use App\Support\CatalogCache;

T::$current = 'Home page depth';
reset_db();
$db = Database::get();
$cat = Fx::category(['name' => 'Depth Wear', 'slug' => 'depth-wear', 'is_featured' => 1]);
$p = Fx::product(['price' => 2500, 'stock' => 5, 'is_new' => 1, 'category_id' => $cat['id']]);
Fx::product(['price' => 3000, 'stock' => 5, 'is_new' => 1, 'category_id' => $cat['id']]);
Fx::insert('testimonials', ['name' => 'Sara A', 'location' => 'Karachi', 'rating' => 5, 'body' => 'Great fit', 'is_active' => 1, 'sort_order' => 1]);
$db->exec('DELETE FROM home_sections');
$mk = static function (string $type, array $settings = [], array $attrs = []) use ($db): int {
    return Fx::insert('home_sections', $attrs + ['type' => $type, 'title' => ucfirst($type) . ' block', 'settings' => json_encode($settings), 'sort_order' => (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_sections'), 'is_active' => 1]);
};
$phonePic = ImageStore::store(Fx::image(900, 1200), 'banners');
Fx::insert('banners', ['title' => 'Depth banner', 'subtitle' => 'Sub', 'eyebrow' => 'New', 'cta_label' => 'Shop', 'cta_url' => '/shop', 'image' => ImageStore::store(Fx::image(1200, 800), 'banners'), 'mobile_image' => $phonePic, 'placement' => 'hero', 'show_text' => 1, 'is_active' => 1, 'sort_order' => 1]);
$mk('hero');
Fx::insert('banners', ['title' => 'Campaign banner', 'subtitle' => 'Sub', 'cta_label' => 'See', 'cta_url' => '/shop', 'image' => ImageStore::store(Fx::image(1200, 800), 'banners'), 'placement' => 'campaign', 'show_text' => 1, 'is_active' => 1, 'sort_order' => 2]);
$mk('campaign');
$mk('categories');
$mk('products', ['source' => 'new', 'layout' => 'carousel']);
$mk('products', ['source' => 'new', 'layout' => 'grid']);
$mk('brand', ['body' => 'Our story.']);
$mk('testimonials');
$mk('account');
CatalogCache::bump();

$home = http('GET', '/')->body;
T::contains('class="home-3d"', $home, 'the home page is wrapped for the depth styles');
T::contains('assets/abiha/home.js', $home, 'the home script is loaded');
T::contains('<section class="relative" data-hero', $home, 'the hero carries its hook');
T::contains('<div class="hero-media', $home, 'the hero picture sits in a clipping box');
T::contains('class="hero-depth', $home, 'the hero text card has a depth wrapper');
T::contains('class="hero-card"', $home, 'and the card itself is unchanged');
T::ok(substr_count($home, 'data-tilt') >= 4, 'category tiles and product cards tilt');
T::contains('class="home-tile group" data-reveal data-tilt', $home, 'a category tile tilts');
T::contains('class="product-card group" data-tilt', $home, 'a product card in the carousel tilts');
T::contains('class="product-card group" data-reveal="data-reveal" data-tilt', $home, 'a product card in the grid tilts and reveals');
T::contains('data-tilt data-parallax', $home, 'the story picture tilts and has a scroll parallax');
T::ok(preg_match('/<b data-count="(\d+)">\1<\/b>/', $home) === 1, 'a story figure counts up to the number that is already printed');
T::contains('<li class="acct__tile" data-tilt>', $home, 'account tiles tilt');
T::contains('<section class="section--dark on-dark relative overflow-hidden" data-parallax>', $home, 'the campaign band has a scroll parallax');
T::contains('<div class="campaign-media" aria-hidden="true"><picture>', $home, 'its photo sits in a clipping box');
T::contains('<div class="campaign-frame" aria-hidden="true"></div>', $home, 'with a thin frame');

foreach (['/shop', '/products/' . $p['slug'], '/cart'] as $path) {
    T::ok(!str_contains(http('GET', $path)->body, 'assets/abiha/home.js'), "the home script is not loaded on {$path}");
}
T::ok(!str_contains(http('GET', '/shop')->body, 'data-tilt'), 'shop cards do not tilt (the depth belongs to the home page)');

$js = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/home.js');
T::ok(filesize(PUBLIC_PATH . '/assets/abiha/home.js') < 12000, 'the script is small');
T::contains("matchMedia('(prefers-reduced-motion: reduce)')", $js, 'the script does nothing for people who asked for less motion');
T::contains("'(hover: hover) and (pointer: fine)'", $js, 'tilt only with a mouse or trackpad');
T::ok(!str_contains($js, 'eval(') && !str_contains($js, 'innerHTML'), 'no eval and no HTML injection');
T::ok(!str_contains($home, 'onmousemove') && !str_contains($home, 'onscroll'), 'no inline event handlers on the page');

$css = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/theme.css');
T::contains('.home-3d{overflow-x:clip}', $css, 'tilted cards can never cause sideways scrolling');
T::ok(preg_match('/@media \(prefers-reduced-motion:no-preference\)\{[^@]*\.home-3d \.hero-depth\{transform:perspective/', $css) === 1, 'the hero depth only moves for people who allow motion');
T::ok(preg_match('/@media \(hover:hover\) and \(pointer:fine\) and \(prefers-reduced-motion:no-preference\)\{[^@]*\.home-3d \[data-tilt\]/', $css) === 1, 'tilt is for fine pointers and allowed motion only');
T::ok(!preg_match('/\.home-3d[^{]*\{[^}]*gradient\(/', $css), 'no gradients (brand rule): light is a blurred white disc');
T::ok(!preg_match('/\.home-3d[^{]*(home-tile__img|home-col__img|product-card__media|story-media|promo-media)[^{]*\{[^}]*object-fit:cover/', $css), 'no product, tile, story or promo picture is cropped by the depth styles');
T::ok(str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/abiha/app.min.css'), '.home-3d'), 'the built stylesheet carries the depth layer');

// ---- phones: one slim contact line, a poster hero, magazine-sized headings, one small Quick add on touch
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.announce__contact\{width:100%;flex-wrap:nowrap;justify-content:space-between/', $css) === 1, 'the top bar is one line on phones');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-media\{height:clamp\(480px,70vh,640px\)/', $css) === 1, 'the hero is a full-height poster on phones');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-copy\{position:absolute;inset:auto 0 0 0/', $css) === 1, 'with the words on a panel inside the picture');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-card\{[^}]*background:rgba\(16,16,16,\.82\)/', $css) === 1, 'the panel is dark glass in the brand black');
T::contains('<div class="hero-copy', $home, 'the hero text wrapper carries its hook');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d section h2,\.home-3d \.acct__title\{font-size:2rem/', $css) === 1, 'every section heading is 2rem on phones');
T::ok(preg_match('/@media \(max-width:1279px\)\{\s*\.home-3d \.hero-depth::after\{display:none\}/', $css) === 1, 'the offset frame is a desktop-only detail');
T::ok(preg_match('/@media \(hover:none\)\{[^@]*\.product-card__media>div\.absolute>\.btn--light\{display:none\}/', $css) === 1, 'touch screens get no quick-view eye on cards');

// ---- the phone picture of a banner: phones get the tall picture, everything else the wide one
T::contains('<source media="(max-width:639px)"', $home, 'the hero offers phones their own picture');
T::contains(Img::url($phonePic), $home, 'that is the uploaded portrait file');
T::contains('hero-img--m', $home, 'and the picture is marked so it is centred, not cropped to the wide focus point');
T::contains('<div class="hero-media hero-media--m">', $home, 'the picture box knows it holds a phone picture');
T::contains('<div class="hero-copy hero-copy--poster xl:absolute', $home, 'and the words are written over the picture on phones');
T::ok(preg_match('/<h1 class="hero-title hx" style="--d:320ms"><span class="hero-w" style="--i:0">Depth<\/span> <span class="hero-w" style="--i:1">banner<\/span><\/h1>/', $home) === 1, 'the headline is split into words for the word-by-word entrance');
T::contains('<div class="hx hero-cta" style="--d:800ms"><a href=', $home, 'the button wrapper carries its hook');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-media--m img\{height:auto;aspect-ratio:auto;object-fit:contain/', $css) === 1, 'on phones the whole phone picture shows, nothing is cropped');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-copy--poster \.hero-card\{[^}]*background:transparent/', $css) === 1, 'the words sit on the picture with no panel or band behind them');
T::ok(preg_match('/@media \(max-width:639px\)\{[^@]*\.home-3d \.hero-copy--poster \.hero-sub\{display:block/', $css) === 1, 'the supporting text is shown on phones too');
T::contains('<p class="hero-sub hx" style="--d:640ms">Sub</p>', $home, 'and it is in the page');
T::ok(preg_match('/@media \(max-width:639px\) and \(prefers-reduced-motion:no-preference\)\{[^@]*\.hero-w\{opacity:0/', $css) === 1, 'words start hidden only for people who allow motion (with reduced motion they are simply there)');
$outside = (string) preg_replace('/@media \(max-width:639px\) and \(prefers-reduced-motion:no-preference\)\{.*?\n\}/s', '', $css);
T::ok(!preg_match('/\.hero-w\{[^}]*opacity:0/', $outside), 'no hidden words outside that block');
$wide = ImageStore::store(Fx::image(1200, 800), 'banners');
T::ok(!str_contains(Img::picture($wide), 'media='), 'a banner without a phone picture renders exactly as before');
T::ok(!str_contains(Img::picture($wide, '', '100vw', false, false, [], 'banners/does-not-exist.jpg'), 'media='), 'a missing phone file is ignored');
T::ok(preg_match('/<picture><source media="\(max-width:639px\)" type="image\/webp" srcset="[^"]+" sizes="100vw"><source media="\(max-width:639px\)" srcset="[^"]+"><source type="image\/webp"/', Img::picture($wide, '', '100vw', false, false, [], $phonePic)) === 1, 'the phone sources come first, then the wide ones, then the <img> fallback');

// ---- the admin uploads and removes it like any other picture
$admin = Fx::user('admin');
function hm_send(string $method, string $path, array $input, array $files, array $user): App\Core\Response
{
    Auth::actingAs($user);
    Auth::forget();
    Session::ageFlash();
    Database::get()->exec('DELETE FROM rate_limits');
    $input['_token'] ??= Csrf::token();
    if ($method !== 'POST') {
        $input['_method'] = $method;
    }
    return Kernel::handle(Request::create('POST', $path, $input, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'text/html'], $files));
}
T::contains('name="mobile_image"', http('GET', '/admin/banners/create', [], $admin)->body, 'the banner form has the phone picture field');
T::contains('Phone picture (portrait)', http('GET', '/admin/banners/create', [], $admin)->body, 'with a clear label');
$r = hm_send('POST', '/admin/banners', ['placement' => 'hero', 'title' => 'Phone hero', 'is_active' => 1], ['image' => Fx::image(1600, 700), 'mobile_image' => Fx::image(900, 1200, 'png')], $admin);
T::ok(in_array($r->status, [302, 303], true), 'a banner with both pictures is saved');
$ban = $db->one("SELECT * FROM banners WHERE title = 'Phone hero'");
T::ok($ban && str_starts_with((string) $ban['mobile_image'], 'banners/') && Img::exists($ban['mobile_image']), 'the phone picture is stored under banners/');
T::ok(str_contains(http('GET', '/admin/banners/' . $ban['id'] . '/edit', [], $admin)->body, 'name="remove_mobile_image"'), 'the edit form can remove it');
$old = (string) $ban['mobile_image'];
$r = hm_send('PUT', '/admin/banners/' . $ban['id'], ['placement' => 'hero', 'title' => 'Phone hero', 'is_active' => 1, 'remove_mobile_image' => 1], [], $admin);
T::ok(in_array($r->status, [302, 303], true), 'removing saves');
T::eq(null, $db->value('SELECT mobile_image FROM banners WHERE id = ?', [$ban['id']]), 'the phone picture is gone from the banner');
T::ok(!Img::exists($old), 'and its file is deleted');
T::ok(Img::exists((string) $db->value('SELECT image FROM banners WHERE id = ?', [$ban['id']])), 'the wide picture is untouched');
Auth::actingAs(null);
