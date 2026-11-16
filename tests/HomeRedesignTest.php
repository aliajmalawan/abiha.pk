<?php

declare(strict_types=1);

/*
 * Home page redesign: the trust strip (built only from the shop's own settings), the category tiles that borrow a real product photo
 * when no category has a picture, full rows in the tile grid, and the warm-black band for the second product row.
 */

use App\Core\Database;
use App\Services\ImageStore;
use App\Support\CatalogCache;
use App\Support\Settings;

T::$current = 'Home redesign';
reset_db();
$db = Database::get();
$db->exec('DELETE FROM home_sections');
$mk = static function (string $type, array $settings = [], array $attrs = []) use ($db): int {
    return Fx::insert('home_sections', $attrs + ['type' => $type, 'title' => ucfirst($type) . ' block', 'settings' => json_encode($settings), 'sort_order' => (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_sections'), 'is_active' => 1]);
};
Settings::put('payments.enabled', ['cod']);
Fx::insert('banners', ['placement' => 'hero', 'title' => 'Redesign hero', 'subtitle' => 'Sub', 'cta_label' => 'Shop', 'cta_url' => '/shop', 'image' => ImageStore::store(Fx::image(1200, 800), 'banners'), 'is_active' => 1, 'sort_order' => 1]);
$mk('hero');
$mk('categories', ['limit' => 12], ['title' => 'Shop by category']);
$mk('products', ['source' => 'new', 'layout' => 'carousel'], ['title' => 'First row']);
$mk('products', ['source' => 'new', 'layout' => 'carousel'], ['title' => 'Second row']);
$mk('products', ['source' => 'new', 'layout' => 'carousel'], ['title' => 'Third row']);
$mk('brand', ['body' => 'Our story in a few words.'], ['title' => 'Our story']);

// categories: none featured, none with a picture; products live in sub-categories
$top = [];
foreach (['Tops', 'Bottoms', 'Bags'] as $i => $name) {
    $top[$name] = Fx::category(['name' => $name, 'slug' => strtolower($name), 'is_featured' => 0, 'sort_order' => $i + 1]);
}
$sub = Fx::category(['name' => 'Tees', 'slug' => 'tees', 'parent_id' => $top['Tops']['id'], 'is_featured' => 0]);
$p1 = Fx::product(['name' => 'Plain Tee', 'category_id' => $sub['id'], 'is_new' => 1, 'price' => 2100]);
$p2 = Fx::product(['name' => 'Gym Shorts', 'category_id' => $top['Bottoms']['id'], 'is_new' => 1, 'price' => 2300]);
foreach ([$p1, $p2] as $p) {
    $db->exec('INSERT INTO product_images (product_id, path, alt, sort_order, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)', [$p['id'], ImageStore::store(Fx::image(600, 800), 'products'), $p['name'], now(), now()]);
}
CatalogCache::bump();
$home = http('GET', '/')->body;
T::contains('Shop by category', $home, 'with nothing featured the category block still appears');
T::contains('>Tops</h3>', $home, 'a top-level category whose products are in a sub-category is listed');
T::contains('>Bottoms</h3>', $home, 'and one with its own products');
T::ok(!str_contains($home, '>Bags</h3>'), 'a category with no products is not listed');
T::ok(substr_count($home, '<span class="home-tile__count">1 piece</span>') === 2, 'each tile says how many real pieces it holds');
$tile = substr($home, (int) strpos($home, 'class="home-tile group"'), 900);
T::contains('/storage/products/', $tile, 'the tile shows the photo of one of its own products');
T::contains('<picture>', $tile, 'as a normal responsive picture');

// the picture of a category wins over the borrowed product photo
$own = ImageStore::store(Fx::image(600, 800), 'categories');
$db->exec('UPDATE categories SET image = ? WHERE id = ?', [$own, $top['Tops']['id']]);
CatalogCache::bump();
T::contains(basename($own, '.jpg'), http('GET', '/')->body, 'a category picture uploaded by the owner is used instead');
$db->exec('UPDATE categories SET image = NULL WHERE id = ?', [$top['Tops']['id']]);

// featured categories still decide when the owner picks some
$db->exec('UPDATE categories SET is_featured = 1 WHERE id = ?', [$top['Bags']['id']]);
CatalogCache::bump();
$feat = http('GET', '/')->body;
T::ok(str_contains($feat, '>Bags</h3>') && !str_contains($feat, '>Bottoms</h3>'), 'once the owner features categories, only those are shown');
$db->exec('UPDATE categories SET is_featured = 0 WHERE id = ?', [$top['Bags']['id']]);

// full rows: 6 tiles are 3 x 2, 5 are one row of five, 4 are four across
$grid = static function (int $n) use ($db): string {
    $db->exec("DELETE FROM categories WHERE slug LIKE 'rx-%'");
    for ($i = 1; $i <= $n; $i++) {
        $c = Fx::category(['name' => "Rx{$i}", 'slug' => "rx-{$i}", 'is_featured' => 1, 'sort_order' => 100 + $i]);
        Fx::product(['name' => "Rx piece {$i}", 'category_id' => $c['id']]);
    }
    CatalogCache::bump();
    $h = http('GET', '/')->body;
    preg_match('/<div data-reveal-group class="grid grid-cols-2 (lg:grid-cols-\d) gap-3 sm:gap-6">\s*<a href="[^"]*category\/rx-1"/', $h, $m);
    return $m[1] ?? 'none';
};
T::eq('lg:grid-cols-3', $grid(6), 'six tiles: three across, two rows');
T::eq('lg:grid-cols-5', $grid(5), 'five tiles: one row of five');
T::eq('lg:grid-cols-4', $grid(4), 'four tiles: four across');
$db->exec("DELETE FROM categories WHERE slug LIKE 'rx-%'");
CatalogCache::bump();

// the trust strip
$home = http('GET', '/')->body;
T::ok(strpos($home, 'class="home-assure"') > strpos($home, 'Redesign hero') && strpos($home, 'class="home-assure"') < strpos($home, 'Shop by category'), 'the strip sits between the hero and the first section');
T::contains('Pay by Cash on Delivery', $home, 'it names the payment methods that are switched on');
T::ok(!str_contains(substr($home, (int) strpos($home, 'home-assure')), 'Debit / Credit Card</strong>') && !str_contains(strstr($home, 'Pay by Cash on Delivery', true) . 'Pay by Cash on Delivery', 'Pay by Cash on Delivery or'), 'and only those');
T::contains('href="' . route('track') . '" class="home-assure__title">Track your order</a>', $home, 'the order tracking line is a link to the tracking page');
T::contains('Secure checkout', $home, 'with the facts about the site');
T::ok(!str_contains($home, 'Delivery by'), 'no courier is named while none is active');
$db->exec("DELETE FROM shipping_methods");
Fx::insert('shipping_methods', ['code' => 'rx1', 'name' => 'Swift Rider', 'cost' => 250, 'is_active' => 1, 'is_sample' => 0, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('shipping_methods', ['code' => 'rx2', 'name' => 'Fast Post', 'cost' => 300, 'is_active' => 1, 'is_sample' => 0, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
Fx::insert('shipping_methods', ['code' => 'rx3', 'name' => 'Hidden Courier', 'cost' => 300, 'is_active' => 0, 'is_sample' => 0, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
CatalogCache::bump();
$home = http('GET', '/')->body;
T::contains('Delivery by Swift Rider or Fast Post', $home, 'active couriers are named');
T::ok(!str_contains($home, 'Hidden Courier'), 'and inactive ones are not');
Settings::put('payments.enabled', []);
CatalogCache::bump();
T::ok(!str_contains(http('GET', '/')->body, 'Pay by '), 'with no payment method on, the payment line disappears');
T::ok(!str_contains($home, 'home-trust'), 'still no invented trust card');

// the warm-black band: white, dark, ivory
$home = http('GET', '/')->body;
$a = (int) strpos($home, 'First row');
$b = (int) strpos($home, 'Second row');
$c = (int) strpos($home, 'Third row');
T::ok(str_contains(substr($home, $a - 400, 400), 'class="section "') || str_contains(substr($home, $a - 400, 400), 'class="section  "') || !str_contains(substr($home, $a - 400, 400), 'section--dark'), 'the first product row is on white');
T::contains('section--dark on-dark', substr($home, $b - 400, 400), 'the second product row is on the warm-black band');
T::contains('section--ivory', substr($home, $c - 400, 400), 'the third is on ivory');

$css = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/theme.css');
T::contains('--abiha-warm-black:#15130F', $css, 'the warm black is a token');
T::contains('--abiha-sand:#F1ECE0', $css, 'and so is the sand');
T::ok(!preg_match('/\.home-(3d|assure)[^{]*\{[^}]*gradient\(/', preg_replace('/\/\*.*?\*\//s', '', $css)), 'no gradients in the redesign');
T::ok(str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/abiha/app.min.css'), '.home-assure'), 'the built stylesheet carries the redesign');
T::contains('warm black `#15130F`', (string) file_get_contents(BASE_PATH . '/docs/BRAND.md'), 'the brand rules list the two new tones');

// ---- 3D motion extras (the marquee needs three categories that really have products)
$hats = Fx::category(['name' => 'Hats', 'slug' => 'hats', 'is_featured' => 0, 'sort_order' => 9]);
Fx::product(['name' => 'Sun Hat', 'category_id' => $hats['id'], 'is_new' => 1]);
CatalogCache::bump();
$home = http('GET', '/')->body;
T::contains('<div class="home-progress" aria-hidden="true"></div>', $home, 'the scroll progress line is on the page');
T::contains('class="hero-scroll"', $home, 'the hero has a scroll cue');
T::contains('id="after-hero"', $home, 'that points at the strip under the hero');
T::contains('data-reveal-group>', $home, 'the strip items reveal one after another');
T::contains('<section class="home-marquee" data-parallax', $home, 'the category marquee is on the page');
$names = ['Tops', 'Bottoms'];
foreach ($names as $n) {
    T::ok(substr_count($home, '>' . $n . '</a>') === 2, "{$n} appears in the marquee twice (the second copy closes the loop)");
}
T::ok(substr_count($home, 'class="home-marquee__set" aria-hidden="true"') === 1, 'only the second copy is hidden from screen readers');
T::ok(substr_count($home, 'tabindex="-1"') >= 2, 'and its links are out of the tab order');
$db->exec("UPDATE categories SET is_active = 0 WHERE slug IN ('tops', 'bottoms', 'bags')");   // only Hats is left: fewer than three
CatalogCache::bump();
T::ok(!str_contains(http('GET', '/')->body, 'home-marquee__track'), 'with fewer than three categories there is no marquee');
$db->exec("UPDATE categories SET is_active = 1 WHERE slug IN ('tops', 'bottoms', 'bags')");
CatalogCache::bump();

$css = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/theme.css');
T::ok(preg_match('/@media \(prefers-reduced-motion:no-preference\)\{\s*\.home-marquee__track\{[^}]*animation:marquee/', $css) === 1, 'the marquee only moves for people who allow motion');
T::ok(preg_match('/\.home-marquee__set\[aria-hidden="true"\]\{display:none\}/', $css) === 1, 'without motion the second copy is hidden and the names simply wrap');
T::ok(preg_match('/@media \(prefers-reduced-motion:reduce\)\{\.home-progress\{display:none\}/', $css) === 1, 'the progress line is hidden with reduced motion');
T::ok(preg_match('/@media \(prefers-reduced-motion:no-preference\)\{\s*html\.js \.home-3d \.home-tile\.is-visible/', $css) === 1, 'the 3D entrances are for people who allow motion');
T::ok(preg_match('/@media \(min-width:768px\)\{\s*\.hero-scroll\{position:absolute/', $css) === 1, 'the scroll cue is for tablets and up');
$js = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/home.js');
T::contains("'--sp'", $js, 'the script sets the scroll progress');
T::ok(!preg_match('/\.home-(marquee|progress|3d \.section--dark)[^{]*\{[^}]*gradient\(/', preg_replace('/\/\*.*?\*\//s', '', $css)), 'still no gradients');
