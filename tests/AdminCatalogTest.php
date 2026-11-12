<?php

declare(strict_types=1);

/*
 * Slice M1: admin catalogue and content (ports the intent of the old CatalogAdminTest, ProductManagementTest,
 * HomepageBuilderTest, MenusShipmentsPaymentHistoryTest (menus), BrandingCardsTest, SettingsShippingMarketingTest
 * (settings, sale pricing, featured flags), AccountBannerTest (admin part) and the role checks of AdminRolesTest).
 */

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\Img;
use App\Support\Menus;
use App\Support\Settings;

reset_db();
$db = Database::get();

/** Like http() but with uploaded files: $files = ['images' => [file, file], 'image' => file] (Fx::image() arrays). */
function m1(string $method, string $path, array $input = [], array|false|null $user = null, array $files = []): Response
{
    if ($user === false) {
        Auth::actingAs(null);
    } elseif (is_array($user)) {
        Auth::actingAs($user);
    }
    Auth::forget();
    Session::ageFlash();
    $method = strtoupper($method);
    if ($method !== 'GET') {
        $input['_token'] ??= Csrf::token();
    }
    $phpFiles = [];
    foreach ($files as $field => $f) {
        if (isset($f['tmp_name'])) {
            $phpFiles[$field] = $f;
        } else {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                $phpFiles[$field][$k] = array_column($f, $k);
            }
        }
    }
    [$path, $qs] = array_pad(explode('?', $path, 2), 2, '');
    parse_str($qs, $q);
    $req = Request::create($method, $path, $input, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'text/html'], $phpFiles);
    if ($q) {
        $req = new Request($req->method, $req->path, $q + $req->query, $req->post, $phpFiles, $req->server, []);
    }
    return Kernel::handle($req);
}

/** The errors the last request flashed (what the next page would show). */
function m1_errors(): array
{
    Session::ageFlash();
    return Session::getFlash('errors', []) ?: [];
}

/** A fake upload whose content is not an image. */
function m1_fake(string $name, string $content): array
{
    $file = BASE_PATH . '/tests/.tmp/fake-' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($file, $content);
    return ['name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
}

$admin = Fx::user('admin');
$sizeM = (int) $db->value("SELECT v.id FROM attribute_values v JOIN attributes a ON a.id = v.attribute_id WHERE a.slug = 'size' AND v.value = 'M'");
$sizeS = (int) $db->value("SELECT v.id FROM attribute_values v JOIN attributes a ON a.id = v.attribute_id WHERE a.slug = 'size' AND v.value = 'S'");
$black = (int) $db->value("SELECT v.id FROM attribute_values v JOIN attributes a ON a.id = v.attribute_id WHERE a.slug = 'color' AND v.value = 'Black'");
$payload = static fn (array $over = []): array => array_merge(['name' => 'Admin Made', 'sku' => 'ADM-1', 'price' => 2500, 'stock' => 7, 'is_active' => 1], $over);
$prod = static fn (string $sku) => $db->one('SELECT * FROM products WHERE sku = ?', [$sku]);

/* ---------------- products: create, ledger, validation ---------------- */
T::$current = 'M1 products';
$r = m1('POST', '/admin/products', $payload(), $admin);
$p = $prod('ADM-1');
T::eq(303, $r->status, 'store redirects');
T::ok($p !== null && str_ends_with($r->headers['Location'] ?? '', '/admin/products/' . $p['id'] . '/edit'), 'redirects to the edit page');
T::eq(7, (int) $p['stock'], 'opening stock saved');
T::eq('admin-made', $p['slug'], 'slug made from the name');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND `change` = 7 AND balance_after = 7 AND reason = 'initial'", [$p['id']]), 'opening stock in the ledger');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM activity_logs WHERE action = 'product.created'"), 'activity log');

m1('PUT', '/admin/products/' . $p['id'], $payload(['stock' => 4]), $admin);
$m = $db->one('SELECT * FROM stock_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$p['id']]);
T::ok((int) $prod('ADM-1')['stock'] === 4 && (int) $m['change'] === -3 && $m['reason'] === 'adjustment', 'editing stock writes an adjustment');

$before = (int) $db->value('SELECT COUNT(*) FROM products');
m1('POST', '/admin/products', $payload(), $admin);
T::ok(isset(m1_errors()['sku']), 'duplicate SKU refused');
m1('POST', '/admin/products', $payload(['sku' => 'ADM-2', 'price' => -5]), $admin);
T::ok(isset(m1_errors()['price']), 'negative price refused');
m1('POST', '/admin/products', $payload(['sku' => 'ADM-2', 'price' => 100, 'compare_price' => 50]), $admin);
T::eq('The compare price field must be greater than 100.', m1_errors()['compare_price'] ?? null, 'compare price must exceed price');
m1('POST', '/admin/products', $payload(['sku' => 'ADM-2', 'slug' => 'admin-made']), $admin);
T::ok(isset(m1_errors()['slug']), 'duplicate slug refused');
m1('POST', '/admin/products', $payload(['sku' => 'ADM-2', 'slug' => 'Bad Slug!']), $admin);
T::ok(isset(m1_errors()['slug']), 'malformed slug refused');
T::eq($before, (int) $db->value('SELECT COUNT(*) FROM products'), 'nothing saved on errors');

$cat = Fx::category(['name' => 'Kurtas', 'slug' => 'kurtas']);
m1('POST', '/admin/products', $payload(['name' => 'Silk Kurta', 'sku' => 'KUR-1', 'category_id' => $cat['id'], 'description' => '<p>Soft <strong>silk</strong>.</p><script>alert(1)</script>',
    'compare_price' => 6000, 'badge' => 'Limited', 'meta_title' => 'Silk | ABIHA', 'is_featured' => 1, 'is_new' => 1,
    'specifications' => [['label' => 'Fabric', 'value' => '<b>Silk</b>'], ['label' => '', 'value' => 'dropped']]]), $admin);
$k = $prod('KUR-1');
T::ok($k && (int) $k['category_id'] === (int) $cat['id'] && (int) $k['compare_price'] === 6000 && $k['badge'] === 'Limited' && (int) $k['is_featured'] === 1 && (int) $k['is_bestseller'] === 0, 'all fields saved');
T::ok(!str_contains((string) $k['description'], '<script') && str_contains((string) $k['description'], '<strong>silk</strong>'), 'description sanitised');
T::eq([['label' => 'Fabric', 'value' => 'Silk']], json_decode((string) $k['specifications'], true), 'specifications cleaned');
m1('PUT', '/admin/products/' . $k['id'], $payload(['name' => 'Silk Kurta', 'sku' => 'KUR-1']), $admin);
T::ok((int) $prod('KUR-1')['is_featured'] === 0 && (int) $prod('KUR-1')['is_new'] === 0, 'unticked flags are cleared');

$list = m1('GET', '/admin/products?q=KUR-1', [], $admin)->body;
T::ok(str_contains($list, 'Silk Kurta') && !str_contains($list, '>Admin Made<'), 'list search by SKU');
T::contains('class="act act--edit"', $list, 'Edit pill');
T::contains('class="act act--del"', $list, 'Delete pill');
m1('PUT', '/admin/products/' . $p['id'], $payload(['stock' => 4, 'is_active' => 0]), $admin);
$list = m1('GET', '/admin/products?status=inactive', [], $admin)->body;
T::ok(str_contains($list, 'Admin Made') && !str_contains($list, 'Silk Kurta'), 'status filter');

/* ---------------- variants ---------------- */
T::$current = 'M1 variants';
$n = (int) $db->value('SELECT COUNT(*) FROM products');
m1('POST', '/admin/products', $payload(['sku' => 'VAR-1', 'has_variants' => 1, 'variants' => [
    ['sku' => 'VAR-1-M-BK', 'stock' => 3, 'is_active' => 1, 'options' => [$sizeM, $black]],
    ['sku' => 'VAR-1-M-BK2', 'stock' => 2, 'is_active' => 1, 'options' => [$black, $sizeM]],
]]), $admin);
T::ok(isset(m1_errors()['variants.1.options']), 'duplicate option combination refused');
T::eq($n, (int) $db->value('SELECT COUNT(*) FROM products'), 'atomic: nothing saved');
m1('POST', '/admin/products', $payload(['sku' => 'VAR-2', 'has_variants' => 1, 'stock' => '', 'variants' => [['sku' => 'KUR-1', 'stock' => 1, 'options' => [$sizeM]]]]), $admin);
T::ok(isset(m1_errors()['variants.0.sku']), 'variant SKU clashing with a product SKU refused');
m1('POST', '/admin/products', $payload(['sku' => 'VAR-1', 'has_variants' => 1, 'stock' => '', 'variants' => [
    ['sku' => 'VAR-1-S', 'stock' => 4, 'is_active' => 1, 'options' => [$sizeS, $black]],
    ['sku' => 'VAR-1-M', 'stock' => 9, 'price' => 2700, 'is_active' => 1, 'options' => [$sizeM, $black]],
]]), $admin);
$vp = $prod('VAR-1');
$vs = $db->all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order', [$vp['id']]);
T::ok(count($vs) === 2 && (int) $vs[1]['price'] === 2700 && $vs[0]['price'] === null && (int) $vs[1]['stock'] === 9, 'variants saved with own price and stock');
T::eq(0, (int) $vp['stock'], 'variant products hold no product stock');
T::eq(2, (int) $db->value('SELECT COUNT(*) FROM attribute_value_product_variant WHERE product_variant_id = ?', [$vs[0]['id']]), 'options linked');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM stock_movements WHERE variant_id = ? AND `change` = 4 AND reason = 'initial'", [$vs[0]['id']]), 'variant opening stock in the ledger');
m1('PUT', '/admin/products/' . $vp['id'], $payload(['sku' => 'VAR-1', 'has_variants' => 1, 'stock' => '', 'variants' => [
    ['id' => $vs[0]['id'], 'sku' => 'VAR-1-S', 'stock' => 10, 'is_active' => 1, 'options' => [$sizeS, $black]],
    ['id' => $vs[1]['id'], 'sku' => 'VAR-1-M', 'stock' => 9, 'price' => 2700, 'options' => [$sizeM, $black]],
]]), $admin);
T::eq([], m1_errors(), 'variant edit has no errors');
T::eq(10, (int) $db->value('SELECT stock FROM product_variants WHERE id = ?', [$vs[0]['id']]), 'variant stock edited');
T::eq(0, (int) $db->value('SELECT is_active FROM product_variants WHERE id = ?', [$vs[1]['id']]), 'unticked variant hidden');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM stock_movements WHERE variant_id = ? AND `change` = 6', [$vs[0]['id']]), '+6 logged');
$form = m1('GET', '/admin/products/' . $vp['id'] . '/edit', [], $admin)->body;
T::contains('VAR-1-S', $form, 'edit form seeds the variant rows');
T::contains('class="pf-bar lg:col-span-2 no-print"', $form, 'sticky save bar');
T::contains('Save changes', $form, 'save button text');

/* ---------------- photos ---------------- */
T::$current = 'M1 images';
m1('POST', '/admin/products', $payload(['name' => 'Photo Kurta', 'sku' => 'IMG-1']), $admin, ['images' => [Fx::image(600, 800), Fx::image(600, 800, 'png'), Fx::image(600, 800)]]);
$ip = $prod('IMG-1');
$imgs = $db->all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$ip['id']]);
T::eq([1, 2, 3], array_map(static fn ($i) => (int) $i['sort_order'], $imgs), 'three photos in upload order');
T::ok(Img::exists($imgs[0]['path']) && str_starts_with($imgs[0]['path'], 'products/') && basename($imgs[0]['path']) !== 'photo.jpg', 'stored under a random name');
T::eq('Photo Kurta', $imgs[0]['alt'], 'alt defaults to the product name');

$n = (int) $db->value('SELECT COUNT(*) FROM products');
m1('POST', '/admin/products', $payload(['sku' => 'BAD-1']), $admin, ['images' => [m1_fake('shell.jpg', '<?php echo 1;')]]);
T::eq('The images.0 field must be an image.', m1_errors()['images.0'] ?? null, 'a script disguised as a picture is refused');
m1('POST', '/admin/products', $payload(['sku' => 'BAD-2']), $admin, ['images' => array_map(static fn () => Fx::image(50, 50), range(1, 13))]);
T::ok(isset(m1_errors()['images']), 'more than 12 photos refused');
T::eq($n, (int) $db->value('SELECT COUNT(*) FROM products'), 'nothing saved');

[$a, $b, $c] = $imgs;
m1('PUT', '/admin/products/' . $ip['id'], $payload(['name' => 'Photo Kurta', 'sku' => 'IMG-1', 'image_order' => [$c['id'], $a['id'], $b['id']], 'image_alts' => [$a['id'] => 'Front <b>view</b>', $c['id'] => '']]), $admin);
T::eq([(int) $c['id'], (int) $a['id'], (int) $b['id']], array_map('intval', $db->column('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$ip['id']])), 'photo order changed (first = main)');
T::eq('Front view', $db->value('SELECT alt FROM product_images WHERE id = ?', [$a['id']]), 'alt text is plain text');
T::eq('Photo Kurta', $db->value('SELECT alt FROM product_images WHERE id = ?', [$c['id']]), 'empty alt falls back to the name');
m1('PUT', '/admin/products/' . $ip['id'], $payload(['name' => 'Photo Kurta', 'sku' => 'IMG-1', 'remove_images' => [$b['id']]]), $admin);
T::ok(!Img::exists($b['path']), 'removed photo file deleted');
T::eq([1, 2], array_map('intval', $db->column('SELECT sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order', [$ip['id']])), 'renumbered without gaps');
m1('PUT', '/admin/products/' . $ip['id'], $payload(['name' => 'Photo Kurta', 'sku' => 'IMG-1']), $admin, ['images' => array_map(static fn () => Fx::image(50, 50), range(1, 11))]);
T::eq('A product can have at most 12 photos. Remove some first.', m1_errors()['images'] ?? null, 'total capped at 12');
$form = m1('GET', '/admin/products/' . $ip['id'] . '/edit', [], $admin)->body;
T::ok(str_contains($form, 'imageOrder(') && str_contains($form, 'name="image_order[]"') && str_contains($form, 'Make main') && str_contains($form, 'uploadCheck('), 'ordering controls and upload checks');
T::contains('class="pf-drop"', $form, 'dropzone');
T::contains('Recommended size: 1200 × 1500 px', $form, 'size note');

/* ---------------- delete ---------------- */
T::$current = 'M1 delete';
$r = m1('DELETE', '/admin/products/' . $ip['id'], [], $admin);
T::ok(str_ends_with($r->headers['Location'] ?? '', '/admin/products'), 'back to the list');
T::ok($db->value('SELECT deleted_at FROM products WHERE id = ?', [$ip['id']]) !== null, 'soft deleted');
T::ok(!str_contains(m1('GET', '/admin/products', [], $admin)->body, 'Photo Kurta'), 'gone from the admin list');
T::eq(404, m1('GET', '/admin/products/' . $ip['id'] . '/edit', [], $admin)->status, 'deleted product cannot be edited');

/* ---------------- categories, collections ---------------- */
T::$current = 'M1 categories';
$ca = Fx::category(['name' => 'A', 'slug' => 'a']);
$cb = Fx::category(['name' => 'B', 'slug' => 'b', 'parent_id' => $ca['id']]);
Fx::product(['category_id' => $cb['id']]);
m1('PUT', '/admin/categories/' . $ca['id'], ['name' => 'A', 'parent_id' => $cb['id']], $admin);
T::ok(isset(m1_errors()['parent_id']), 'a category cannot move under its own child');
T::eq(null, $db->value('SELECT parent_id FROM categories WHERE id = ?', [$ca['id']]), 'parent unchanged');
m1('DELETE', '/admin/categories/' . $ca['id'], [], $admin);
T::eq('This category has sub-categories. Move or delete them first.', m1_errors()['delete'] ?? null, 'category with children kept');
m1('DELETE', '/admin/categories/' . $cb['id'], [], $admin);
T::ok(isset(m1_errors()['delete']), 'category with products kept');
m1('POST', '/admin/categories', ['name' => 'Summer Lawn', 'is_active' => 1], $admin);
$new = $db->one("SELECT * FROM categories WHERE name = 'Summer Lawn'");
T::ok($new && $new['slug'] === 'summer-lawn' && (int) $new['is_active'] === 1 && (int) $new['sort_order'] === 0, 'category created with a slug');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM activity_logs WHERE action = 'admin.categories.created'"), 'category log');
T::contains('Summer Lawn', m1('GET', '/admin/categories', [], $admin)->body, 'category index lists it');
m1('DELETE', '/admin/categories/' . $new['id'], [], $admin);
T::ok($db->value('SELECT deleted_at FROM categories WHERE id = ?', [$new['id']]) !== null, 'empty category soft deleted');

$p1 = Fx::product(['name' => 'Coll One']);
$p2 = Fx::product(['name' => 'Coll Two']);
m1('POST', '/admin/collections', ['name' => 'Eid Edit', 'products' => [$p1['id'], $p2['id']], 'is_active' => 1], $admin, ['image' => Fx::image(300, 300)]);
$col = $db->one("SELECT * FROM collections WHERE slug = 'eid-edit'");
T::ok($col && Img::exists((string) $col['image']) && str_starts_with((string) $col['image'], 'collections/'), 'collection with picture');
T::eq(2, (int) $db->value('SELECT COUNT(*) FROM collection_product WHERE collection_id = ?', [$col['id']]), 'product picker saved');
m1('PUT', '/admin/collections/' . $col['id'], ['name' => 'Eid Edit', 'products' => [$p2['id']], 'remove_image' => 1], $admin);
T::eq([(int) $p2['id']], array_map('intval', $db->column('SELECT product_id FROM collection_product WHERE collection_id = ?', [$col['id']])), 'picker synced');
T::ok($db->value('SELECT image FROM collections WHERE id = ?', [$col['id']]) === null && !Img::exists((string) $col['image']), 'picture removed');
m1('POST', '/admin/collections', ['name' => 'X', 'products' => [999999]], $admin);
T::ok(isset(m1_errors()['products.0']), 'unknown product refused');
T::contains('Coll Two', m1('GET', '/admin/collections/' . $col['id'] . '/edit', [], $admin)->body, 'picker lists products');

/* ---------------- attributes ---------------- */
T::$current = 'M1 attributes';
m1('POST', '/admin/attributes', ['name' => 'Material', 'slug' => 'material', 'type' => 'select'], $admin);
$mat = $db->one("SELECT * FROM attributes WHERE slug = 'material'");
T::ok($mat !== null, 'attribute added');
m1('POST', '/admin/attributes/' . $mat['id'] . '/values', ['value' => 'Silk'], $admin);
m1('POST', '/admin/attributes/' . $mat['id'] . '/values', ['value' => 'Silk'], $admin);
T::ok(isset(m1_errors()['value']), 'duplicate value refused');
$sizeAttr = (int) $db->value("SELECT id FROM attributes WHERE slug = 'size'");
m1('DELETE', '/admin/attributes/' . $sizeAttr, [], $admin);
T::ok(isset(m1_errors()['value']), 'size cannot be deleted');
m1('DELETE', '/admin/attribute-values/' . $sizeS, [], $admin);
T::ok(str_contains((string) (m1_errors()['value'] ?? ''), 'is used by product variants'), 'value in use kept');
m1('DELETE', '/admin/attributes/' . $mat['id'], [], $admin);
T::eq(null, $db->one('SELECT id FROM attributes WHERE id = ?', [$mat['id']]), 'attribute deleted');
T::contains('Colour swatches', m1('GET', '/admin/attributes', [], $admin)->body, 'attributes page');

/* ---------------- inventory ---------------- */
T::$current = 'M1 inventory';
$ip3 = Fx::product(['name' => 'Stocky', 'stock' => 3]);
m1('POST', '/admin/inventory/adjust', ['target' => 'product', 'id' => $ip3['id'], 'mode' => 'remove', 'quantity' => 5, 'reason' => 'adjustment'], $admin);
T::eq('Insufficient stock: Stocky has 3 available.', m1_errors()['inventory'] ?? null, 'cannot go negative');
m1('POST', '/admin/inventory/adjust', ['target' => 'product', 'id' => $ip3['id'], 'mode' => 'add', 'quantity' => 10, 'reason' => 'restock', 'note' => 'PO 12'], $admin);
m1('POST', '/admin/inventory/adjust', ['target' => 'product', 'id' => $ip3['id'], 'mode' => 'set', 'quantity' => 8, 'reason' => 'adjustment'], $admin);
T::eq(8, (int) $db->value('SELECT stock FROM products WHERE id = ?', [$ip3['id']]), 'balance');
T::eq([10, -5], array_map('intval', $db->column('SELECT `change` FROM stock_movements WHERE product_id = ? ORDER BY id', [$ip3['id']])), 'ledger rows');
m1('POST', '/admin/inventory/adjust', ['target' => 'product', 'id' => $vp['id'], 'mode' => 'add', 'quantity' => 1, 'reason' => 'restock'], $admin);
T::ok(isset(m1_errors()['inventory']), 'variant products are adjusted per variant');
T::contains('Size: S / Colour: Black', m1('GET', '/admin/inventory?q=VAR-1', [], $admin)->body, 'variant label');
$led = m1('GET', '/admin/inventory/ledger', [], $admin)->body;
T::ok(str_contains($led, 'PO 12') && str_contains($led, 'Restock'), 'ledger page');
$csv = m1('GET', '/admin/inventory/export', [], $admin);
T::ok(str_contains($csv->headers['Content-Type'] ?? '', 'text/csv') && str_contains($csv->body, 'Stocky'), 'stock CSV');
T::contains('PO 12', m1('GET', '/admin/inventory/ledger/export', [], $admin)->body, 'ledger CSV');

/* ---------------- banners, pages, testimonials ---------------- */
T::$current = 'M1 content';
m1('POST', '/admin/banners', ['placement' => 'hero', 'title' => 'x', 'cta_url' => 'javascript:alert(1)'], $admin);
T::ok(isset(m1_errors()['cta_url']), 'banner link must be a path or http(s)');
m1('POST', '/admin/banners', ['placement' => 'sidebar', 'title' => 'x'], $admin);
T::ok(isset(m1_errors()['placement']), 'unknown placement refused');
$r = m1('POST', '/admin/banners', ['placement' => 'promo', 'title' => 'Promo from admin', 'cta_url' => '/shop', 'is_active' => 1], $admin, ['image' => Fx::image(800, 300)]);
$ban = $db->one("SELECT * FROM banners WHERE title = 'Promo from admin'");
T::ok($ban && $ban['placement'] === 'promo' && str_starts_with((string) $ban['image'], 'banners/') && (int) $ban['show_text'] === 0, 'banner saved (unticked show_text = 0)');
T::ok(str_ends_with($r->headers['Location'] ?? '', '/admin/banners'), 'back to the banner list');
m1('DELETE', '/admin/banners/' . $ban['id'], [], $admin);
T::ok($db->one('SELECT id FROM banners WHERE id = ?', [$ban['id']]) === null && !Img::exists((string) $ban['image']), 'banner and picture deleted');

m1('POST', '/admin/pages', ['title' => 'Our Story', 'body' => '<p>Hello</p><script>alert(1)</script>', 'is_published' => 1, 'show_in_footer' => 1], $admin);
$pg = $db->one("SELECT * FROM pages WHERE slug = 'our-story'");
T::ok($pg && !str_contains((string) $pg['body'], '<script') && str_contains((string) $pg['body'], '<p>Hello</p>') && (int) $pg['is_published'] === 1, 'page saved and sanitised');
m1('POST', '/admin/pages', ['title' => 'Our Story', 'slug' => 'our-story'], $admin);
T::ok(isset(m1_errors()['slug']), 'page slug unique');
m1('POST', '/admin/testimonials', ['name' => 'Ayesha', 'rating' => 7, 'body' => 'Great'], $admin);
T::ok(isset(m1_errors()['rating']), 'rating 1-5');
m1('POST', '/admin/testimonials', ['name' => 'Ayesha', 'rating' => 5, 'body' => 'Great', 'is_active' => 1], $admin);
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM testimonials WHERE name = 'Ayesha' AND is_sample = 0"), 'testimonial saved');
T::contains('Ayesha', m1('GET', '/admin/testimonials', [], $admin)->body, 'testimonial list');

/* ---------------- homepage builder ---------------- */
T::$current = 'M1 homepage';
$db->exec('DELETE FROM home_sections');
m1('POST', '/admin/home', ['type' => 'products', 'title' => 'Eid picks'], $admin);
$hs = $db->one("SELECT * FROM home_sections WHERE title = 'Eid picks'");
T::ok($hs && (int) $hs['is_active'] === 0 && (int) $hs['sort_order'] === 1, 'new block starts hidden at the bottom');
T::eq(['source' => 'new', 'limit' => 8, 'link' => '/shop', 'layout' => 'carousel'], json_decode((string) $hs['settings'], true), 'product row defaults');
m1('POST', '/admin/home', ['type' => 'promo'], $admin);
m1('POST', '/admin/home', ['type' => 'promo'], $admin);
T::ok(isset(m1_errors()['type']), 'single banner block cannot be duplicated');
foreach (['', 'nonsense', ['x']] as $bad) {
    m1('POST', '/admin/home', ['type' => $bad], $admin);
    T::ok(isset(m1_errors()['type']), 'bad type refused');
}
m1('POST', '/admin/home', ['type' => 'account', 'title' => 'Another'], $admin);
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM home_sections WHERE type = 'account' AND title = 'Another'"), 'account banner block can be added');
m1('PATCH', '/admin/home', ['sections' => [$hs['id'] => ['sort_order' => 3, 'is_active' => 1]]], $admin);
T::ok((int) $db->value('SELECT is_active FROM home_sections WHERE id = ?', [$hs['id']]) === 1 && (int) $db->value('SELECT sort_order FROM home_sections WHERE id = ?', [$hs['id']]) === 3, 'layout saved');
m1('PUT', '/admin/home/' . $hs['id'], ['title' => 'Row', 'source' => 'hack', 'limit' => 99], $admin);
$e = m1_errors();
T::ok(isset($e['source'], $e['limit']), 'source and limit validated');
m1('PUT', '/admin/home/' . $hs['id'], ['title' => 'Row', 'source' => 'trending', 'limit' => 4, 'tabs' => 1], $admin);
$hs2 = $db->one('SELECT * FROM home_sections WHERE id = ?', [$hs['id']]);
T::ok($hs2['title'] === 'Row' && json_decode((string) $hs2['settings'], true)['source'] === 'trending' && json_decode((string) $hs2['settings'], true)['tabs'] === true, 'product row edited');
$brand = Fx::insert('home_sections', ['type' => 'brand', 'title' => 'Story', 'settings' => '[]', 'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
m1('PUT', '/admin/home/' . $brand, ['title' => 'Story', 'body' => 'Our words.'], $admin, ['image' => Fx::image(400, 300)]);
$bimg = json_decode((string) $db->value('SELECT settings FROM home_sections WHERE id = ?', [$brand]), true)['image'] ?? null;
T::ok(is_string($bimg) && str_starts_with($bimg, 'home/') && Img::exists($bimg), 'brand image stored');
m1('PUT', '/admin/home/' . $brand, ['title' => 'Story', 'remove_image' => 1], $admin);
T::ok(!isset(json_decode((string) $db->value('SELECT settings FROM home_sections WHERE id = ?', [$brand]), true)['image']) && !Img::exists($bimg), 'brand image removed');
m1('PUT', '/admin/home/' . $brand, ['title' => 'Story'], $admin, ['image' => m1_fake('shell.php.jpg', '<?php echo 1; ?>')]);
T::ok(isset(m1_errors()['image']), 'non-image refused');
$social = Fx::insert('home_sections', ['type' => 'social', 'title' => 'Follow', 'settings' => '[]', 'sort_order' => 10, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
$pics = static fn (int $n) => array_map(static fn () => Fx::image(120, 120), range(1, $n));
m1('PUT', '/admin/home/' . $social, ['title' => 'Follow'], $admin, ['images' => $pics(3)]);
m1('PUT', '/admin/home/' . $social, ['title' => 'Follow', 'remove_images' => [0]], $admin, ['images' => $pics(1)]);
T::eq(3, count(json_decode((string) $db->value('SELECT settings FROM home_sections WHERE id = ?', [$social]), true)['images'] ?? []), 'one removed, one added');
m1('PUT', '/admin/home/' . $social, ['title' => 'Follow'], $admin, ['images' => $pics(7)]);
T::ok(isset(m1_errors()['images']), 'more than six refused');
m1('PUT', '/admin/home/' . $social, ['title' => 'Follow'], $admin, ['images' => $pics(5)]);
$socialPics = json_decode((string) $db->value('SELECT settings FROM home_sections WHERE id = ?', [$social]), true)['images'] ?? [];
T::eq(6, count($socialPics), 'never more than six');
m1('DELETE', '/admin/home/' . $social, [], $admin);
T::ok($db->one('SELECT id FROM home_sections WHERE id = ?', [$social]) === null && !Img::exists($socialPics[0]), 'block and its pictures removed');
T::eq(404, m1('DELETE', '/admin/home/99999', [], $admin)->status, 'unknown block 404');
$hi = m1('GET', '/admin/home', [], $admin)->body;
T::ok(str_contains($hi, 'Homepage layout') && str_contains($hi, 'Account sign-up banner') && str_contains($hi, 'via Banners'), 'homepage manager page');

/* ---------------- menus ---------------- */
T::$current = 'M1 menus';
m1('PUT', '/admin/menus', ['header' => [['label' => '<b>Bold</b> Sale', 'url' => '/shop'], ['label' => 'Partner', 'url' => 'https://example.com/hello']], 'footer_shop' => [['label' => 'Everything', 'url' => '/shop']], 'footer_help' => []], $admin);
T::eq(['Bold Sale', 'Partner'], array_column(Menus::rows('header'), 'label'), 'labels plain text, saved in order');
T::eq([], Menus::get('footer_help'), 'an empty list shows no links');
foreach (['javascript:alert(1)', '//evil.example/x', 'data:text/html,x', 'ftp://example.com', 'shop', '/has space'] as $bad) {
    m1('PUT', '/admin/menus', ['header' => [['label' => 'X', 'url' => $bad]]], $admin);
    T::ok(isset(m1_errors()['header.0.url']), "refused: {$bad}");
}
m1('PUT', '/admin/menus', ['header' => array_fill(0, 13, ['label' => 'A', 'url' => '/shop'])], $admin);
T::ok(isset(m1_errors()['header']), 'at most 12 links');
m1('PUT', '/admin/menus', ['header' => [['label' => str_repeat('x', 41), 'url' => '/shop']]], $admin);
T::ok(isset(m1_errors()['header.0.label']), 'label max 40');
m1('PUT', '/admin/menus', ['restore' => 'header'], $admin);
T::eq(['New In', 'Sale', 'Track order'], array_column(Menus::get('header'), 'label'), 'restore brings the defaults back');
T::eq(422, m1('PUT', '/admin/menus', ['restore' => 'nonsense'], $admin)->status, 'unknown list 422');
$mp = m1('GET', '/admin/menus', [], $admin)->body;
T::ok(str_contains($mp, 'Header links') && str_contains($mp, 'Footer: Help column'), 'menu editor');

/* ---------------- reviews ---------------- */
T::$current = 'M1 reviews';
$rp = Fx::product(['name' => 'Reviewed']);
$rv = Fx::insert('reviews', ['product_id' => $rp['id'], 'name' => 'Sana', 'rating' => 4, 'body' => 'Lovely fabric', 'is_approved' => 0, 'is_sample' => 1, 'created_at' => now(), 'updated_at' => now()]);
T::contains('Lovely fabric', m1('GET', '/admin/reviews', [], $admin)->body, 'pending review listed');
m1('PATCH', '/admin/reviews/' . $rv . '/approve', [], $admin);
T::ok((int) $db->value('SELECT is_approved FROM reviews WHERE id = ?', [$rv]) === 1 && (int) $db->value('SELECT is_sample FROM reviews WHERE id = ?', [$rv]) === 0, 'published');
T::contains('Lovely fabric', m1('GET', '/admin/reviews?status=approved', [], $admin)->body, 'published tab');
m1('PATCH', '/admin/reviews/' . $rv . '/unapprove', [], $admin);
T::eq(0, (int) $db->value('SELECT is_approved FROM reviews WHERE id = ?', [$rv]), 'hidden again');
m1('DELETE', '/admin/reviews/' . $rv, [], $admin);
T::eq(null, $db->one('SELECT id FROM reviews WHERE id = ?', [$rv]), 'deleted');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM activity_logs WHERE action = 'review.deleted'"), 'review log');

/* ---------------- featured flags, sale pricing ---------------- */
T::$current = 'M1 marketing';
$fa = Fx::product(['is_featured' => 0]);
$fb = Fx::product(['is_featured' => 1, 'is_new' => 1]);
$fc = Fx::product(['is_featured' => 1]);
m1('PUT', '/admin/featured', ['ids' => [$fa['id'], $fb['id']], 'flags' => [$fa['id'] => ['is_featured' => 1, 'is_bestseller' => 1]]], $admin);
$row = static fn ($p) => $db->one('SELECT * FROM products WHERE id = ?', [$p['id']]);
T::ok((int) $row($fa)['is_featured'] === 1 && (int) $row($fa)['is_bestseller'] === 1, 'ticked flags saved');
T::ok((int) $row($fb)['is_featured'] === 0 && (int) $row($fb)['is_new'] === 0, 'unticked flags cleared');
T::eq(1, (int) $row($fc)['is_featured'], 'products not on the page untouched');
m1('PUT', '/admin/featured', ['ids' => [999999]], $admin);
T::ok(isset(m1_errors()['ids.0']), 'unknown id refused');
T::eq(200, m1('GET', '/admin/featured', [], $admin)->status, 'featured page');

$db->exec('UPDATE products SET deleted_at = ? WHERE deleted_at IS NULL', [now()]);   // a clean catalogue for the sale checks
$plain = Fx::product(['price' => 1000]);
$onSale = Fx::product(['price' => 2000, 'compare_price' => 2500]);
$vprod = Fx::product(['price' => 2000, 'has_variants' => 1]);
$vl = Fx::variant((int) $vprod['id'], ['price' => 2500]);
$price = static fn (string $t, int $id) => $db->one("SELECT price, compare_price FROM {$t} WHERE id = ?", [$id]);
m1('POST', '/admin/discounts/apply', ['scope' => 'all', 'percent' => 20], $admin);
T::eq(['price' => 800, 'compare_price' => 1000], $price('products', (int) $plain['id']), '20% applied');
T::eq(['price' => 2000, 'compare_price' => 2500], $price('products', (int) $onSale['id']), 'already on sale: never compounded');
T::eq(['price' => 2000, 'compare_price' => 2500], $price('product_variants', (int) $vl['id']), 'variant with its own price discounted');
m1('POST', '/admin/discounts/apply', ['scope' => 'all', 'percent' => 20], $admin);
T::eq(800, (int) $price('products', (int) $plain['id'])['price'], 'applying twice does not stack');
m1('POST', '/admin/discounts/clear', ['scope' => 'all'], $admin);
T::eq(['price' => 1000, 'compare_price' => null], $price('products', (int) $plain['id']), 'sale ended, price restored');
T::eq(['price' => 2500, 'compare_price' => null], $price('product_variants', (int) $vl['id']), 'variant restored');
T::ok((int) $db->value("SELECT COUNT(*) FROM activity_logs WHERE action IN ('discount.applied', 'discount.cleared')") >= 2, 'logged');
foreach ([0, 95, 'abc'] as $bad) {
    m1('POST', '/admin/discounts/apply', ['scope' => 'all', 'percent' => $bad], $admin);
    T::ok(isset(m1_errors()['percent']), 'bad percent refused');
}
m1('POST', '/admin/discounts/apply', ['scope' => 'category', 'percent' => 10], $admin);
T::eq('The category id field is required when scope is category.', m1_errors()['category_id'] ?? null, 'category required for a category sale');
$tops = Fx::category(['name' => 'Tops', 'slug' => 'tops']);
$in = Fx::product(['price' => 1000, 'category_id' => $tops['id']]);
m1('POST', '/admin/discounts/apply', ['scope' => 'category', 'category_id' => $tops['id'], 'percent' => 50], $admin);
T::ok((int) $price('products', (int) $in['id'])['price'] === 500 && (int) $price('products', (int) $plain['id'])['price'] === 1000, 'category-only sale');
T::contains('Sale pricing', m1('GET', '/admin/discounts', [], $admin)->body, 'discounts page');

/* ---------------- settings, branding ---------------- */
T::$current = 'M1 settings';
$sp = m1('GET', '/admin/settings', [], $admin)->body;
foreach (['id="g-brand-logo"', 'id="g-favicon-icon"', 'name="s__site__logo"', 'name="s__site__favicon"', 'Save brand logo', 'Save favicon icon', 'Default favicon', 'class="brandbox"'] as $needle) {
    T::contains($needle, $sp, 'settings page: ' . $needle);
}
m1('PUT', '/admin/settings', ['s__contact__email' => 'hello@example.com', 's__social__instagram' => 'not a url'], $admin);
T::ok(isset(m1_errors()['s__social__instagram']), 'bad URL refused');
T::eq(null, Settings::get('contact.email'), 'nothing saved on errors');
m1('PUT', '/admin/settings', ['s__contact__email' => 'hello@example.com', 's__announcement__text' => 'Free gift', 's__site__name' => 'Abiha Test', 's__currency__code' => 'pkr', 's__seo__allow_indexing' => 1, 's__tax__rate' => '17'], $admin);
T::ok(Settings::get('contact.email') === 'hello@example.com' && Settings::get('announcement.text') === 'Free gift', 'settings saved');
T::eq('PKR', Settings::get('currency.code'), 'currency code upper-cased');
T::ok(is_int(Settings::get('tax.rate')) || is_float(Settings::get('tax.rate')), 'number stored as a number');
T::eq(17.0, (float) Settings::get('tax.rate'), 'tax rate value');
T::eq(true, Settings::get('seo.allow_indexing'), 'checkbox stored');
m1('PUT', '/admin/settings', ['s__site__logo' => 'x'], $admin, ['s__site__logo' => m1_fake('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]);
T::ok(isset(m1_errors()['s__site__logo']), 'SVG logo refused');
m1('PUT', '/admin/settings', [], $admin, ['s__site__logo' => Fx::image(400, 300, 'png')]);
$logo = Settings::get('site.logo');
T::ok(is_string($logo) && str_starts_with($logo, 'branding/') && Img::exists($logo), 'logo stored');
T::eq(null, Settings::get('site.favicon'), 'uploading only the logo leaves the favicon alone');
m1('PUT', '/admin/settings', [], $admin, ['s__site__favicon' => Fx::image(64, 64, 'png')]);
$fav = Settings::get('site.favicon');
T::ok(is_string($fav) && $fav !== $logo && Settings::get('site.logo') === $logo, 'favicon stored separately');
m1('PUT', '/admin/settings', ['remove_s__site__favicon' => 1], $admin);
T::ok(Settings::get('site.favicon') === null && !Img::exists($fav) && Settings::get('site.logo') === $logo, 'favicon removed, logo kept');
m1('PUT', '/admin/settings', ['remove_s__site__logo' => 1], $admin);
T::ok(Settings::get('site.logo') === null && !Img::exists($logo), 'logo removed: back to the supplied artwork');

/* ---------------- permissions ---------------- */
T::$current = 'M1 permissions';
$roles = ['customer' => Fx::user('customer'), 'staff' => Fx::user('staff'), 'product_manager' => Fx::user('product_manager'),
    'inventory_manager' => Fx::user('inventory_manager'), 'content_manager' => Fx::user('content_manager'), 'manager' => Fx::user('manager')];
$expect = [
    '/admin/products' => ['product_manager', 'manager'], '/admin/categories' => ['product_manager', 'manager'], '/admin/attributes' => ['product_manager', 'manager'],
    '/admin/featured' => ['product_manager', 'manager'], '/admin/inventory' => ['inventory_manager', 'manager'], '/admin/reviews' => ['product_manager', 'manager'],
    '/admin/home' => ['content_manager', 'manager'], '/admin/menus' => ['content_manager', 'manager'], '/admin/banners' => ['content_manager', 'manager'],
    '/admin/pages' => ['content_manager', 'manager'], '/admin/testimonials' => ['content_manager', 'manager'], '/admin/settings' => ['manager'],
    '/admin/discounts' => ['manager'], '/admin/collections' => ['product_manager', 'manager'],
];
foreach ($expect as $url => $allowed) {
    foreach ($roles as $slug => $u) {
        $st = m1('GET', $url, [], $u)->status;
        T::eq(in_array($slug, $allowed, true) ? 200 : 403, $st, "{$slug} on {$url}");
    }
}
$count = (int) $db->value('SELECT COUNT(*) FROM products');
T::eq(403, m1('POST', '/admin/products', $payload(['sku' => 'NOPE']), $roles['staff'])->status, 'order staff cannot create products');
T::eq(403, m1('POST', '/admin/home', ['type' => 'products'], $roles['customer'])->status, 'customer cannot add blocks');
T::eq($count, (int) $db->value('SELECT COUNT(*) FROM products'), 'nothing created');
$g = m1('GET', '/admin/products', [], false);
T::ok($g->status === 303 && str_ends_with($g->headers['Location'] ?? '', '/admin/login'), 'guests go to the admin login');
$side = m1('GET', '/admin/products', [], $admin)->body;
foreach (['admin.categories.index', 'admin.home.index', 'admin.settings.edit', 'admin.menus.edit', 'admin.discounts.index'] as $name) {
    T::contains('href="' . route($name) . '"', $side, "sidebar links to {$name}");
}
