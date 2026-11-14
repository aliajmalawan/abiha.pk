<?php

declare(strict_types=1);

/*
 * Home page depth and motion (public/assets/abiha/home.js + the "Home page depth" part of theme.css): the hooks are on the
 * page, the script is loaded only there and contains no inline code, every effect is behind prefers-reduced-motion and the
 * tilt behind a fine pointer, and nothing crops a product picture.
 */

use App\Core\Database;
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
Fx::insert('banners', ['title' => 'Depth banner', 'subtitle' => 'Sub', 'eyebrow' => 'New', 'cta_label' => 'Shop', 'cta_url' => '/shop', 'image' => ImageStore::store(Fx::image(1200, 800), 'banners'), 'placement' => 'hero', 'show_text' => 1, 'is_active' => 1, 'sort_order' => 1]);
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
T::contains('<div class="hero-media">', $home, 'the hero picture sits in a clipping box');
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
