<?php

declare(strict_types=1);

/*
 * Slice S (storefront browse): home page builder, shop / category / collection listings, search + suggestions,
 * product page (variants, specs, reviews, JSON-LD), quick view, wishlist. Ports the intent of the old
 * HomepageBuilderTest, HomeLayoutTest, AccountBannerTest, QaStorefrontTest, ProductDetailsTest, SeoTest (catalogue part).
 */

use App\Core\Config;
use App\Core\Database;
use App\Services\Catalog;
use App\Services\Wishlist;
use App\Support\CatalogCache;
use App\Support\Settings;

$db = Database::get();

function s_names(string $url): array
{
    $r = http('GET', $url);
    preg_match_all('#<h3 class="product-card__title"><a href="[^"]*/products/[^"]*">([^<]+)</a>#', $r->body, $m);
    return array_map('html_entity_decode', $m[1]);
}

function s_attr(string $slug, string $value, ?string $hex = null): int
{
    $db = Database::get();
    $aid = $db->value('SELECT id FROM attributes WHERE slug = ?', [$slug]) ?? Fx::insert('attributes', ['name' => ucfirst($slug), 'slug' => $slug, 'created_at' => now()]);
    return (int) ($db->value('SELECT id FROM attribute_values WHERE attribute_id = ? AND value = ?', [$aid, $value])
        ?? Fx::insert('attribute_values', ['attribute_id' => $aid, 'value' => $value, 'color_hex' => $hex, 'created_at' => now()]));
}

/** Old BuildsCatalog::variantProduct(): S (inherits 2000, 3 in stock), M (sold out), L (2500, 4 in stock), all Black. */
function s_variant_product(array $over = []): array
{
    $p = Fx::product($over + ['price' => 2000, 'stock' => 0]);
    $black = s_attr('color', 'Black', '#101010');
    $v = [];
    foreach (['S' => [null, 3], 'M' => [null, 0], 'L' => [2500, 4]] as $size => [$price, $stock]) {
        $v[$size] = Fx::variant((int) $p['id'], ['sku' => $p['sku'] . '-' . $size, 'price' => $price, 'stock' => $stock]);
        foreach ([s_attr('size', $size), $black] as $av) {
            Fx::insert('attribute_value_product_variant', ['product_variant_id' => $v[$size]['id'], 'attribute_value_id' => $av]);
        }
    }
    return [Database::get()->one('SELECT * FROM products WHERE id = ?', [$p['id']]), $v];
}

function s_catalogue(): array
{
    $women = Fx::category(['name' => 'Women', 'slug' => 'women']);
    $dresses = Fx::category(['name' => 'Dresses', 'slug' => 'dresses', 'parent_id' => $women['id']]);
    $men = Fx::category(['name' => 'Men', 'slug' => 'men']);
    $p = [];
    $p['sale'] = Fx::product(['name' => 'Alpha Sale Top', 'slug' => 'alpha-sale-top', 'price' => 500, 'compare_price' => 800, 'category_id' => $women['id'], 'sku' => 'AL-1', 'short_description' => 'Breezy cotton']);
    $p['soldout'] = Fx::product(['name' => 'Bravo Sold Out', 'slug' => 'bravo-sold-out', 'price' => 1500, 'stock' => 0, 'category_id' => $women['id'], 'sku' => 'BR-1']);
    $p['men'] = Fx::product(['name' => 'Charlie Coat', 'slug' => 'charlie-coat', 'price' => 3000, 'category_id' => $men['id'], 'sku' => 'CH-1']);
    $p['dress'] = Fx::product(['name' => 'Delta Dress', 'slug' => 'delta-dress', 'price' => 2200, 'category_id' => $dresses['id'], 'sku' => 'DE-1']);
    [$p['variant']] = s_variant_product(['name' => 'Echo Variant Tee', 'slug' => 'echo-variant-tee', 'category_id' => $men['id']]);
    $p['hidden'] = Fx::product(['name' => 'Foxtrot Hidden', 'slug' => 'foxtrot-hidden', 'price' => 700, 'is_active' => 0, 'category_id' => $men['id']]);
    $p['percent'] = Fx::product(['name' => 'Golf 100%_Off Special', 'slug' => 'golf-percent-special', 'price' => 900, 'category_id' => $men['id'], 'sku' => 'GO-1']);
    CatalogCache::bump();
    return $p;
}

function s_canon(array $a): array
{
    sort($a);
    return $a;
}

/* ---------------- listing filters, sorting, search ---------------- */
T::$current = 'Storefront shop filters';
reset_db();
$p = s_catalogue();
$all = s_names('/shop');
T::eq(6, count($all), 'the shop lists every active product');
T::ok(!in_array('Foxtrot Hidden', $all, true), 'inactive products are never listed');
T::eq(['Alpha Sale Top'], s_names('/shop?min=500&max=500'), 'price filter is inclusive');
T::eq(['Charlie Coat'], s_names('/shop?min=3000'), 'min price');
T::eq(s_canon(['Alpha Sale Top', 'Golf 100%_Off Special']), s_canon(s_names('/shop?max=900')), 'max price');
T::ok(in_array('Echo Variant Tee', s_names('/shop?min=1900&max=2100'), true), 'base price of a variant product matches');
T::ok(in_array('Echo Variant Tee', s_names('/shop?min=2400&max=2600'), true), 'a variant price matches');
T::ok(!in_array('Echo Variant Tee', s_names('/shop?min=2600&max=2900'), true), 'no price in range');
$in = s_names('/shop?availability=in_stock');
T::ok(!in_array('Bravo Sold Out', $in, true) && in_array('Echo Variant Tee', $in, true) && count($in) === 5, 'in-stock filter');
T::eq(['Alpha Sale Top'], s_names('/shop?sale=1'), 'sale filter');
T::eq(['Echo Variant Tee'], s_names('/shop?size[]=L'), 'size filter');
T::eq(['Echo Variant Tee'], s_names('/shop?size[]=S&size[]=XL'), 'any selected size');
T::eq(['Echo Variant Tee'], s_names('/shop?color[]=Black'), 'colour filter');
T::eq([], s_names('/shop?color[]=Pink'), 'unknown colour');
T::eq(['Alpha Sale Top'], s_names('/category/women?sale=1&max=1000&availability=in_stock'), 'filters combine (AND)');
T::eq(['Echo Variant Tee'], s_names('/category/men?size[]=L&availability=in_stock&min=1000'), 'filters combine on a category');
$db->exec("UPDATE product_variants SET is_active = 0 WHERE sku LIKE '%-L'");
CatalogCache::bump();
T::eq([], s_names('/shop?size[]=L'), 'an inactive variant no longer matches');
$db->exec("UPDATE product_variants SET is_active = 1");
CatalogCache::bump();

T::$current = 'Storefront sorting';
$asc = s_names('/shop?sort=price_asc');
T::eq('Alpha Sale Top', $asc[0], 'cheapest first');
T::eq('Charlie Coat', end($asc), 'dearest last');
T::eq('Charlie Coat', s_names('/shop?sort=price_desc')[0], 'dearest first');
$byName = s_names('/shop?sort=name');
$sorted = $byName;
sort($sorted, SORT_STRING | SORT_FLAG_CASE);
T::eq($sorted, $byName, 'name sort');
T::eq('Golf 100%_Off Special', s_names('/shop?sort=newest')[0], 'newest first');
$db->exec("UPDATE products SET sold_count = 50 WHERE slug = 'bravo-sold-out'");
T::eq('Bravo Sold Out', s_names('/shop?sort=popular')[0], 'best selling first');
$html = http('GET', '/shop')->body;
preg_match('#<select id="sort".*?</select>#s', $html, $m);
foreach (['Latest', 'Price: low to high', 'Price: high to low', 'Popularity', 'Name A–Z'] as $label) {
    T::contains('>' . $label . '<', $m[0] ?? '', "sort menu offers {$label}");
}

T::$current = 'Storefront categories & collections';
T::eq(s_canon(['Alpha Sale Top', 'Bravo Sold Out', 'Delta Dress']), s_canon(s_names('/category/women')), 'a category includes its sub-categories');
T::eq(['Delta Dress'], s_names('/category/dresses'), 'sub-category');
Fx::category(['name' => 'Secret', 'slug' => 'secret', 'is_active' => 0]);
T::eq(404, http('GET', '/category/secret')->status, 'inactive category is 404');
T::eq(404, http('GET', '/category/nope')->status, 'unknown category is 404');
$col = Fx::insert('collections', ['name' => 'Eid Edit', 'slug' => 'eid', 'is_active' => 1, 'created_at' => now()]);
Fx::insert('collection_product', ['collection_id' => $col, 'product_id' => $p['sale']['id']]);
Fx::insert('collection_product', ['collection_id' => $col, 'product_id' => $p['men']['id']]);
Fx::insert('collections', ['name' => 'Old', 'slug' => 'old', 'is_active' => 0, 'created_at' => now()]);
T::eq(s_canon(['Alpha Sale Top', 'Charlie Coat']), s_canon(s_names('/collections/eid')), 'collection lists its products');
T::eq(404, http('GET', '/collections/old')->status, 'inactive collection is 404');
$r = http('GET', '/shop?category=women&sort=newest');
T::eq(301, $r->status, '/shop?category=x redirects permanently');
T::eq(route('category', 'women') . '?sort=newest', $r->headers['Location'] ?? '', 'to the clean category URL keeping the other parameters');
T::eq(200, http('GET', '/shop?category=does-not-exist')->status, 'an unknown ?category is just the shop');
$cat = http('GET', '/category/dresses')->body;
T::contains('"@type":"BreadcrumbList"', $cat, 'category breadcrumbs as structured data');
T::ok((bool) preg_match('#<nav aria-label="Breadcrumb".*?Women.*?Dresses.*?</nav>#s', $cat), 'visible breadcrumb Home / Women / Dresses');
T::contains('"@type":"CollectionPage"', http('GET', '/collections/eid')->body, 'listing pages describe their products as an ItemList');

T::$current = 'Storefront pagination';
Config::set('abiha.pagination.products', 2);
$page1 = html_entity_decode(http('GET', '/shop?sort=price_asc&max=3000')->body);
T::ok((bool) preg_match('#href="[^"]*sort=price_asc[^"]*page=2#', $page1), 'page links keep the filters');
T::contains('aria-label="Pagination"', $page1, 'pagination nav');
$seen = [];
foreach ([1, 2, 3, 4] as $n) {
    $seen = array_merge($seen, s_names("/shop?sort=price_asc&page={$n}"));
}
T::eq(6, count($seen), 'every product on some page');
T::eq(6, count(array_unique($seen)), 'no product repeated or skipped');
$past = http('GET', '/shop?page=50');
T::eq(200, $past->status, 'a page past the end is fine');
T::ok(!str_contains($past->body, 'product-card'), 'and shows no cards');
T::contains('No products found', http('GET', '/shop?min=99999')->body, 'friendly empty state');
Config::set('abiha.pagination.products', 12);
$html = http('GET', '/shop?sale=1&availability=in_stock&size[]=L&min=100&max=900&sort=price_desc')->body;
T::ok((bool) preg_match('#name="sale" value="1"[^>]*checked#', $html), 'sale box checked');
T::ok((bool) preg_match('#name="availability" value="in_stock"[^>]*checked#', $html), 'availability checked');
T::ok((bool) preg_match('#name="size\[\]" value="L"[^>]*checked#', $html), 'size checked');
T::ok((bool) preg_match('#name="min"[^>]*value="100"#', $html), 'min kept');
T::ok((bool) preg_match('#<option value="price_desc"[^>]*selected#', $html), 'sort selected');

T::$current = 'Storefront hostile input';
foreach (['min=abc', 'max=-5', 'min=9999999999999999999999', 'min[]=1', 'sort=;DROP TABLE products', 'sort[]=x', 'color=Black', 'availability=out_of_stock', 'sale=maybe',
    'q=' . str_repeat('a', 101), 'q[]=a', 'page=-1', 'page=abc', 'page[]=1', 'page=99999999999', 'category=../../etc/passwd', 'category[]=x', 'color[]=' . str_repeat('x', 500),
    'q=' . urlencode("' OR 1=1 --"), 'q=' . urlencode('<script>alert(1)</script>')] as $qs) {
    parse_str($qs, $in);
    $r = http('GET', '/shop', $in);
    T::ok($r->status < 500, "/shop?{$qs} -> {$r->status}");
    T::ok(!str_contains($r->body, '<script>alert(1)</script>'), "{$qs} not reflected unescaped");
}
$html = http('GET', '/shop', ['q' => '"><img src=x onerror=alert(1)>'])->body;
T::ok(!str_contains($html, '<img src=x onerror'), 'search term escaped');

T::$current = 'Storefront search';
T::eq(['Charlie Coat'], s_names('/shop?q=CHARLIE'), 'case-insensitive name');
T::eq(['Delta Dress'], s_names('/shop?q=DE-1'), 'sku');
T::eq(['Alpha Sale Top'], s_names('/shop?q=breezy'), 'short description');
T::eq(['Delta Dress'], s_names('/shop?q=' . urlencode('  delta  ')), 'spaces ignored');
T::eq(['Golf 100%_Off Special'], s_names('/shop?q=' . urlencode('%')), '% is literal');
T::eq([], s_names('/shop?q=' . urlencode('1_0')), '_ is literal');
T::eq([], s_names('/shop?q=foxtrot'), 'inactive never found');
T::eq(['Echo Variant Tee'], s_names('/shop?q=tee&size[]=L'), 'search + filter');
T::contains('Search results', http('GET', '/shop?q=coat')->body, 'search title');
$j = json_decode(http('GET', '/search/suggest?q=a', [], null, ['json' => true])->body, true);
T::eq(['results' => []], $j, 'under 2 characters: nothing');
$j = json_decode(http('GET', '/search/suggest', ['q' => 'delta'], null, ['json' => true])->body, true);
T::eq(1, count($j['results'] ?? []), 'one suggestion');
T::eq('Delta Dress', $j['results'][0]['name'] ?? null, 'suggestion name');
T::ok(str_ends_with($j['results'][0]['url'] ?? '', '/products/delta-dress'), 'suggestion url');
T::eq('PKR 2,200', $j['results'][0]['price'] ?? null, 'suggestion price');
foreach (range(1, 8) as $i) {
    Fx::product(['name' => 'Zulu Many ' . $i]);
}
T::eq(6, count(json_decode(http('GET', '/search/suggest', ['q' => 'zulu'])->body, true)['results']), 'capped at six');
T::eq([], json_decode(http('GET', '/search/suggest', ['q' => 'foxtrot'])->body, true)['results'], 'inactive never suggested');

/* ---------------- product page ---------------- */
T::$current = 'Storefront product page';
reset_db();
Settings::put('delivery.info', 'Delivery takes the days the owner writes.');
Settings::put('returns.policy', 'Returns accepted as the owner writes.');
$women = Fx::category(['name' => 'Women', 'slug' => 'women']);
$dresses = Fx::category(['name' => 'Dresses', 'slug' => 'dresses', 'parent_id' => $women['id']]);
$scarf = Fx::product(['name' => 'Silk Scarf', 'slug' => 'silk-scarf', 'sku' => 'SCARF-1', 'price' => 1800, 'compare_price' => 2400, 'stock' => 8, 'description' => '<p>A soft scarf.</p><script>alert(1)</script>', 'category_id' => $dresses['id'],
    'specifications' => json_encode([['label' => 'Fabric', 'value' => '100% linen'], ['label' => 'Care', 'value' => 'Machine wash cold']])]);
Fx::insert('reviews', ['product_id' => $scarf['id'], 'name' => 'Aisha', 'rating' => 5, 'body' => 'Lovely', 'is_approved' => 1, 'created_at' => now()]);
Fx::insert('reviews', ['product_id' => $scarf['id'], 'name' => 'Bina', 'rating' => 3, 'body' => 'Fine', 'is_approved' => 1, 'created_at' => now()]);
Fx::insert('reviews', ['product_id' => $scarf['id'], 'name' => 'Cyra', 'rating' => 1, 'body' => 'Unapproved one', 'is_approved' => 0, 'created_at' => now()]);
Fx::product(['name' => 'Related Thing', 'category_id' => $dresses['id']]);
CatalogCache::bump();
$r = http('GET', '/products/silk-scarf');
$b = $r->body;
T::eq(200, $r->status, 'product page renders');
foreach (['Silk Scarf', 'SKU:', 'SCARF-1', 'PKR 1,800', 'PKR 2,400', 'Save 25%', 'In stock', 'A soft scarf.', 'Delivery takes the days the owner writes.', 'Returns accepted as the owner writes.',
    'Add to bag', 'Buy now', 'Toggle wishlist', 'Quantity', 'Customer reviews', 'Lovely', 'You may also like', 'Related Thing'] as $needle) {
    T::contains($needle, $b, "product page shows {$needle}");
}
T::ok(!str_contains($b, 'Unapproved one'), 'unapproved reviews hidden');
T::ok(!str_contains($b, '<script>alert(1)'), 'description is sanitised');
preg_match_all('#<th scope="row"[^>]*>(.*?)</th><td[^>]*>(.*?)</td>#s', $b, $m);
T::eq(['Fabric', 'Care'], array_map('html_entity_decode', $m[1]), 'specifications table in order');
T::contains('"additionalProperty":[{"@type":"PropertyValue","name":"Fabric","value":"100% linen"}', $b, 'specs in JSON-LD');
T::contains('"aggregateRating":{"@type":"AggregateRating","ratingValue":4', $b, 'rating from approved reviews only');
T::contains('"reviewCount":2', $b, 'review count');
T::contains('"@type":"Offer"', $b, 'simple product: Offer');
T::contains('"availability":"https://schema.org/InStock"', $b, 'in stock');
T::contains('<meta property="og:type" content="product">', $b, 'og:type product');
T::ok(!str_contains($b, '"brand"'), 'no invented brand');
preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $b, $ld);
$crumbs = null;
foreach ($ld[1] as $json) {
    $d = json_decode($json, true);
    if (($d['@type'] ?? '') === 'BreadcrumbList') {
        $crumbs = array_column($d['itemListElement'], 'name');
    }
}
T::eq(['Home', 'Women', 'Dresses', 'Silk Scarf'], $crumbs, 'breadcrumb trail through the parent category');
T::contains('<a class="link" href="' . route('login') . '">Sign in</a> to write a review.', $b, 'guests are asked to sign in');
T::eq(404, http('GET', '/products/does-not-exist')->status, 'unknown product 404');
$hidden = Fx::product(['slug' => 'hidden-one', 'is_active' => 0]);
T::eq(404, http('GET', '/products/hidden-one')->status, 'inactive product 404');
T::eq(404, http('GET', '/products/hidden-one/quick-view')->status, 'inactive quick view 404');
$evil = Fx::product(['name' => 'Evil </script><script>alert(1)</script>', 'slug' => 'evil']);
T::ok(!preg_match('#</script><script>alert#', http('GET', '/products/evil')->body), 'JSON-LD cannot break out of its script tag');
$sample = Fx::product(['slug' => 'demo-item', 'is_sample' => 1]);
T::contains('noindex', http('GET', '/products/demo-item')->body, 'sample products are noindex');

T::$current = 'Storefront variants';
[$vp, $v] = s_variant_product(['slug' => 'variant-data', 'compare_price' => 2600]);
$db->exec('UPDATE product_variants SET compare_price = 3000 WHERE id = ?', [$v['L']['id']]);
$card = Catalog::product('variant-data');
$payload = Catalog::variantPayload($card);
$by = array_column($payload['variants'], null, 'size');
T::ok($payload['hasVariants'], 'payload hasVariants');
T::eq([2000, 2000, 2500], [$by['S']['price'], $by['M']['price'], $by['L']['price']], 'a variant without its own price inherits the product price');
T::eq([3, 0, 4], [$by['S']['stock'], $by['M']['stock'], $by['L']['stock']], 'stock per variant');
T::eq(3000, $by['L']['compare'], 'variant compare price');
T::eq('Black', $by['L']['color'], 'colour from attribute values');
T::eq(2000, $card['display_price'], 'display price = cheapest variant');
T::ok($card['price_varies'], 'price varies');
$b = http('GET', '/products/variant-data')->body;
foreach ($v as $row) {
    T::contains($row['sku'], $b, 'each variant SKU is shipped to the selector');
}
T::contains('x-data="productForm({ productId: ' . $vp['id'] . ', payload: JSON.parse(', $b, 'productForm component like @js');
T::contains('"@type":"AggregateOffer"', $b, 'variant product: AggregateOffer');
T::contains('"lowPrice":2000,"highPrice":2500,"offerCount":3', $b, 'price range');
T::ok(!str_contains($b, 'Size guide'), 'no size guide until the owner writes one');
Settings::put('size.guide', '<table><tr><td>36</td></tr></table><script>x()</script>');
$b = http('GET', '/products/variant-data')->body;
T::contains('@click="$dispatch(\'open-size-guide\')">Size guide</button>', $b, 'size guide link');
T::contains('aria-labelledby="size-guide-title"', $b, 'size guide dialog');
T::contains('<td>36</td>', $b, 'size guide content');
T::ok(!str_contains($b, '<script>x()'), 'size guide sanitised');
$q = http('GET', '/products/variant-data/quick-view');
T::eq(200, $q->status, 'quick view fragment');
T::ok(!str_contains($q->body, '<html'), 'quick view has no layout');
T::ok(!str_contains($q->body, 'open-size-guide\')">Size guide'), 'compact quick view leaves the size guide out');
T::contains('View full details', $q->body, 'quick view links to the page');
$db->exec('UPDATE product_variants SET is_active = 0 WHERE id = ?', [$v['L']['id']]);
T::ok(!in_array($v['L']['sku'], array_column(Catalog::variantPayload(Catalog::product('variant-data'))['variants'], 'sku'), true), 'inactive variants are not offered');

/* ---------------- reviews ---------------- */
T::$current = 'Storefront reviews';
$customer = Fx::user('customer', ['name' => 'Rubina']);
$r = http('POST', '/products/silk-scarf/reviews', ['rating' => 5, 'body' => 'Wonderful scarf, really.'], false);
T::ok(str_ends_with($r->headers['Location'] ?? '', '/login'), 'guests must sign in to review');
T::contains('Write a review', http('GET', '/products/silk-scarf', [], $customer)->body, 'signed-in customer sees the form');
$r = http('POST', '/products/silk-scarf/reviews', ['rating' => 9, 'body' => 'short'], $customer);
T::ok(in_array($r->status, [302, 303], true), 'invalid review redirects back');
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM reviews WHERE user_id = ?', [$customer['id']]), 'invalid review not saved');
http('POST', '/products/silk-scarf/reviews', ['rating' => 4, 'title' => 'Nice', 'body' => 'Wonderful scarf, really.'], $customer);
$row = $db->one('SELECT * FROM reviews WHERE user_id = ?', [$customer['id']]);
T::eq(4, (int) ($row['rating'] ?? 0), 'review saved');
T::eq(0, (int) $row['is_approved'], 'waits for moderation');
T::eq(0, (int) $row['is_verified_purchase'], 'no purchase, not verified');
T::eq('Rubina', $row['name'], 'name from the account');
T::contains('Thank you. Your review will appear once it has been approved.', http('GET', '/products/silk-scarf')->body, 'thank-you message flashed');
$orderId = Fx::insert('orders', ['number' => 'ABH-T-1', 'user_id' => $customer['id'], 'email' => $customer['email'], 'status' => 'delivered', 'payment_method' => 'cod', 'shipping_method_name' => 'Standard', 'subtotal' => 1800, 'total' => 1800, 'access_token' => str_repeat('a', 40), 'created_at' => now()]);
Fx::insert('order_items', ['order_id' => $orderId, 'product_id' => $scarf['id'], 'name' => 'Silk Scarf', 'sku' => 'SCARF-1', 'unit_price' => 1800, 'quantity' => 1, 'total' => 1800, 'created_at' => now()]);
http('POST', '/products/silk-scarf/reviews', ['rating' => 5, 'body' => 'Even better the second time.'], $customer);
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM reviews WHERE user_id = ?', [$customer['id']]), 'one review per customer per product (updated)');
T::eq(1, (int) $db->value('SELECT is_verified_purchase FROM reviews WHERE user_id = ?', [$customer['id']]), 'verified purchase after an order');
T::ok(!str_contains(http('GET', '/products/silk-scarf', [], $customer)->body, 'Write a review'), 'no second form once reviewed');

/* ---------------- wishlist ---------------- */
T::$current = 'Storefront wishlist';
App\Auth\Auth::actingAs(null);
App\Core\Session::resetMemory();
$r = http('POST', '/wishlist/silk-scarf/toggle', [], false, ['json' => true]);
$j = json_decode($r->body, true);
T::eq(200, $r->status, 'guest toggle');
T::eq(['ok' => true, 'added' => true, 'count' => 1, 'message' => 'Saved to your wishlist.'], $j, 'JSON like the old controller');
T::contains('Silk Scarf', http('GET', '/wishlist')->body, 'guest wishlist page lists it');
T::contains('Sign in to keep your wishlist across devices.', http('GET', '/wishlist')->body, 'guest hint');
T::contains('noindex', http('GET', '/wishlist')->body, 'wishlist is noindex');
$j = json_decode(http('POST', '/wishlist/silk-scarf/toggle', [], null, ['json' => true])->body, true);
T::eq(false, $j['added'], 'second toggle removes');
T::contains('Nothing saved yet', http('GET', '/wishlist')->body, 'empty state');
T::eq(404, http('POST', '/wishlist/hidden-one/toggle', [], null, ['json' => true])->status, 'hidden products cannot be saved');
http('POST', '/wishlist/silk-scarf/toggle', [], null, ['json' => true]);
http('POST', '/wishlist/hidden-one/toggle', [], null, ['json' => true]);
$buyer = Fx::user('customer');
App\Auth\Auth::actingAs($buyer);
Wishlist::mergeGuest((int) $buyer['id']);
T::eq([(int) $scarf['id']], Wishlist::ids(), 'guest list merged into the account on login');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM wishlists WHERE user_id = ?', [$buyer['id']]), 'one wishlists row');
$j = json_decode(http('POST', '/wishlist/silk-scarf/toggle', [], $buyer, ['json' => true])->body, true);
T::eq(false, $j['added'], 'signed-in toggle removes from the table');
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM wishlist_items'), 'row deleted');
$r = http('POST', '/wishlist/silk-scarf/toggle', [], $buyer);
T::ok(in_array($r->status, [302, 303], true), 'HTML toggle redirects back');
T::contains('Move to bag', http('GET', '/wishlist', [], $buyer)->body, 'move-to-bag button on the wishlist page');
$r = http('POST', '/wishlist/variant-data/move-to-cart', [], $buyer);
T::ok(str_ends_with($r->headers['Location'] ?? '', '/products/variant-data'), 'variant products go to the product page');

/* ---------------- home page builder ---------------- */
T::$current = 'Storefront home builder';
reset_db();
$db->exec('DELETE FROM home_sections');
$sec = static function (string $type, array $attrs = []) use ($db): int {
    $settings = $attrs['settings'] ?? [];
    unset($attrs['settings']);
    return Fx::insert('home_sections', $attrs + ['type' => $type, 'title' => ucfirst($type) . ' block', 'settings' => json_encode($settings), 'sort_order' => (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_sections'), 'is_active' => 1, 'created_at' => now()]);
};
foreach (['hero', 'campaign', 'promo', 'categories', 'collections', 'testimonials'] as $t) {
    $sec($t, ['title' => 'Empty ' . $t]);
}
$sec('brand', ['title' => 'Empty brand', 'settings' => ['body' => '']]);
$sec('products', ['title' => 'Empty products', 'settings' => ['source' => 'trending', 'limit' => 4]]);
CatalogCache::bump();
$h = http('GET', '/');
T::eq(200, $h->status, 'home renders with empty blocks');
foreach (['hero', 'campaign', 'promo', 'categories', 'collections', 'testimonials', 'brand', 'products'] as $t) {
    T::ok(!str_contains($h->body, 'Empty ' . $t), "empty {$t} block stays hidden");
}
T::eq(1, preg_match_all('/<h1[\s>]/', $h->body), 'exactly one h1 without a hero');

$db->exec('DELETE FROM home_sections');
$cat = Fx::category(['name' => 'Dresses', 'slug' => 'dresses', 'is_featured' => 1]);
Fx::product(['name' => 'Fresh Arrival', 'is_new' => 1, 'category_id' => $cat['id']]);
Fx::product(['name' => 'Crowd Favourite', 'is_bestseller' => 1]);
Fx::product(['name' => 'On Everyones List', 'is_trending' => 1]);
Fx::product(['name' => 'Big Deal', 'price' => 600, 'compare_price' => 1200]);
$colA = Fx::insert('collections', ['name' => 'Eid Edit', 'slug' => 'eid', 'is_active' => 1, 'is_trending' => 1, 'created_at' => now()]);
Fx::insert('banners', ['placement' => 'hero', 'title' => 'Hero Headline', 'subtitle' => 'Hero support text', 'cta_label' => 'Shop the hero', 'cta_url' => '/shop', 'is_active' => 1]);
Fx::insert('banners', ['placement' => 'campaign', 'title' => 'Campaign Headline', 'cta_label' => 'Campaign CTA', 'cta_url' => '/shop?sale=1', 'is_active' => 1]);
Fx::insert('banners', ['placement' => 'promo', 'title' => 'Promo Headline', 'subtitle' => 'Promo detail', 'cta_label' => 'Promo CTA', 'cta_url' => '/shop?sort=newest', 'is_active' => 1]);
Fx::insert('testimonials', ['name' => 'Aisha K', 'location' => 'Lahore', 'rating' => 5, 'body' => 'Lovely quality', 'is_active' => 1, 'sort_order' => 1]);
foreach ([['hero', 'Hero'], ['campaign', 'Campaign'], ['categories', 'Shop by category'], ['promo', 'Promotion'], ['collections', 'Featured collections'], ['testimonials', 'Customers say'], ['newsletter', 'Join the list'], ['account', 'Join ABIHA today']] as [$t, $title]) {
    $sec($t, ['title' => $title]);
}
$sec('products', ['title' => 'New arrivals', 'settings' => ['source' => 'new', 'limit' => 4]]);
$sec('products', ['title' => 'Best sellers', 'settings' => ['source' => 'bestsellers', 'limit' => 4, 'layout' => 'grid']]);
$sec('products', ['title' => 'Trending products', 'settings' => ['source' => 'trending', 'limit' => 4, 'layout' => 'lookbook']]);
$sec('brand', ['title' => 'Our story', 'settings' => ['body' => 'Written by the owner.']]);
$sec('testimonials', ['title' => 'Hidden Block', 'is_active' => 0]);
CatalogCache::bump();
$h = http('GET', '/', [], false)->body;
foreach (['Hero Headline', 'Hero support text', 'Shop the hero', 'Campaign Headline', 'Campaign CTA', 'Shop by category', 'Dresses', 'Shop now', 'Fresh Arrival', 'Crowd Favourite',
    'On Everyones List', 'Featured collections', 'Eid Edit', 'Promo Headline', 'Promo detail', 'Promo CTA', 'Big Deal', '−50%', 'Aisha K', 'Lovely quality', 'Join the list', 'Written by the owner.',
    'Join ABIHA today', 'Save favourites', 'Track every order', 'home-look', 'snap-x', 'aria-label="Next"', 'lg:grid-cols-4', '"@type":"Organization"', '"@type":"WebSite"'] as $text) {
    T::contains($text, $h, "home shows {$text}");
}
T::ok(!str_contains($h, 'Hidden Block'), 'hidden blocks are not shown');
T::ok(!str_contains($h, 'home-trust'), 'no invented trust card');
T::eq(1, preg_match_all('/<h1[\s>]/', $h), 'the hero supplies the only h1');
T::contains('min-h-[440px]', $h, 'hero without picture is the black block');
T::ok(strpos($h, 'Hero Headline') < strpos($h, 'Campaign Headline') && strpos($h, 'Campaign Headline') < strpos($h, 'Shop by category'), 'blocks in admin order');
$trending = substr($h, (int) strpos($h, 'Trending products'));
T::contains('On Everyones List', $trending, 'trending row shows trending products');
T::ok(!str_contains(http('GET', '/', [], Fx::user('customer'))->body, 'Join ABIHA today'), 'account banner hidden for signed-in customers');

T::$current = 'Storefront home tabs';
$db->exec('DELETE FROM home_sections');
$a = Fx::product(['name' => 'Tab Alpha', 'is_new' => 1]);
Fx::insert('collection_product', ['collection_id' => $colA, 'product_id' => $a['id']]);
Fx::insert('collections', ['name' => 'Empty Edit', 'slug' => 'empty-edit', 'is_active' => 1, 'created_at' => now()]);
$sid = $sec('products', ['title' => 'New Arrivals', 'settings' => ['source' => 'new', 'tabs' => true]]);
CatalogCache::bump();
$h = http('GET', '/', [], false)->body;
T::contains('role="tablist"', $h, 'tabs shown');
T::contains('>All<', $h, 'All tab');
T::contains('>Eid Edit<', $h, 'collection tab');
T::ok(!str_contains($h, 'Empty Edit'), 'collections without products get no tab');
T::contains(route('collection', 'eid'), $h, 'tab links to the collection');
$db->exec('UPDATE home_sections SET title = ? WHERE id = ?', ['After the edit', $sid]);
CatalogCache::bump();
T::contains('After the edit', http('GET', '/')->body, 'changes show immediately after a cache bump');

T::$current = 'Storefront image fit';
$css = (string) file_get_contents(PUBLIC_PATH . '/assets/abiha/abiha.css');
foreach (['.product-card__media img', '.home-tile__img img', '.home-col__img img'] as $sel) {
    T::ok((bool) preg_match('/' . preg_quote($sel, '/') . '\{[^}]*object-fit:contain/', $css), "{$sel} shows the whole picture");
}
foreach (['product', 'sections/promo', 'sections/brand', 'sections/social', 'partials/quick-view'] as $view) {
    T::ok(!str_contains((string) file_get_contents(APP_PATH . "/Views/store/{$view}.php"), 'object-cover'), "{$view} never crops");
}
reset_state();
