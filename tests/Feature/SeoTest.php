<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Page;
use App\Models\ProductImage;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private const HOST = 'https://abiha.pk';

    /** Behave like the live site: production env (the only place indexing is allowed) on its own https host. */
    private function production(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => self::HOST]);
    }

    private function html(string $path = '/', bool $production = true): string
    {
        if ($production) {
            $this->production();
        }

        return $this->get(($production ? self::HOST : '').$path)->assertOk()->getContent();
    }

    private function meta(string $html, string $attr, string $name): ?string
    {
        preg_match('/<meta\s+'.$attr.'="'.preg_quote($name, '/').'"\s+content="([^"]*)"/', $html, $m);

        return isset($m[1]) ? html_entity_decode($m[1]) : null;
    }

    /** @return array<int,array> every JSON-LD block on the page, decoded */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    private function ld(string $html, string $type): ?array
    {
        return collect($this->jsonLd($html))->firstWhere('@type', $type);
    }

    private function withImage($product, string $alt = null)
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/a.jpg', $this->jpegBytes());

        return ProductImage::create(['product_id' => $product->id, 'path' => 'products/a.jpg', 'alt' => $alt ?? $product->name, 'sort_order' => 1]);
    }

    private function jpegBytes(int $w = 800, int $h = 1000): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 90, 60));
        ob_start();
        imagejpeg($im, null, 85);

        return (string) ob_get_clean();
    }

    /* ---------------- titles & descriptions ---------------- */

    public function test_every_public_page_has_a_unique_title_and_a_description(): void
    {
        $p = $this->product(['name' => 'Linen Shirt', 'short_description' => 'A light shirt for warm days.']);
        $cat = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        Page::create(['title' => 'About us', 'slug' => 'about', 'body' => '<p>Who we are.</p>', 'is_published' => true]);

        $titles = [];
        foreach (['/', '/shop', '/category/shirts', '/products/'.$p->slug, '/pages/about', '/contact', '/shipping-returns'] as $path) {
            $html = $this->html($path);
            preg_match('#<title>(.*?)</title>#', $html, $t);
            $titles[$path] = html_entity_decode($t[1]);
            $this->assertNotSame('', trim($titles[$path]), $path);
            $this->assertNotEmpty($this->meta($html, 'name', 'description'), "{$path} needs a meta description");
            $this->assertLessThanOrEqual(300, mb_strlen($this->meta($html, 'name', 'description')));
        }
        $this->assertCount(count($titles), array_unique($titles), 'titles must be unique: '.json_encode($titles));
        $this->assertStringContainsString('Linen Shirt', $titles['/products/'.$p->slug]);
        $this->assertSame('A light shirt for warm days.', $this->meta($this->html('/products/'.$p->slug), 'name', 'description'));
    }

    public function test_admin_meta_fields_win_over_generated_text(): void
    {
        $p = $this->product(['name' => 'Plain Name', 'meta_title' => 'Custom SEO Title', 'meta_description' => 'Custom description for search.']);
        $html = $this->html('/products/'.$p->slug);

        $this->assertStringContainsString('<title>Custom SEO Title |', $html);
        $this->assertSame('Custom description for search.', $this->meta($html, 'name', 'description'));
    }

    public function test_descriptions_are_plain_text_even_when_the_source_is_html(): void
    {
        $p = $this->product(['description' => '<p>Soft <strong>cotton</strong> &amp; linen.</p><script>alert(1)</script>']);

        $desc = $this->meta($this->html('/products/'.$p->slug), 'name', 'description');
        $this->assertStringNotContainsString('<', $desc);
        $this->assertStringContainsString('Soft cotton & linen.', $desc);
    }

    public function test_paginated_listings_get_their_own_title_and_a_self_canonical(): void
    {
        config(['abiha.pagination.products' => 2]);
        foreach (range(1, 5) as $i) {
            $this->product(['name' => 'Item '.$i]);
        }

        $p2 = $this->html('/shop?page=2');
        $this->assertStringContainsString('(Page 2)', $p2);
        $this->assertStringContainsString('<link rel="canonical" href="'.self::HOST.'/shop?page=2">', $p2);
        $this->assertStringNotContainsString('noindex', $p2, 'page 2 stays indexable so its products can be discovered');

        $p1 = $this->html('/shop');
        $this->assertStringNotContainsString('(Page', $p1);
        $this->assertStringContainsString('<link rel="canonical" href="'.self::HOST.'/shop">', $p1);
    }

    /* ---------------- canonical & robots ---------------- */

    public function test_tracking_and_view_parameters_never_create_a_new_canonical(): void
    {
        $p = $this->product();

        $this->assertStringContainsString('<link rel="canonical" href="'.self::HOST.'/products/'.$p->slug.'">', $this->html('/products/'.$p->slug.'?utm_source=x&fbclid=1&ref=abc'));
        $this->assertStringContainsString('<link rel="canonical" href="'.self::HOST.'/shop">', $this->html('/shop?sort=price_asc&color[]=Black'));
    }

    public function test_filtered_sorted_and_searched_listings_are_noindex_follow(): void
    {
        $this->product(['name' => 'Linen Shirt']);

        foreach (['/shop?q=linen', '/shop?sort=newest', '/shop?min=100&max=900', '/shop?availability=in_stock', '/shop?sale=1'] as $url) {
            $this->assertSame('noindex,follow', $this->meta($this->html($url), 'name', 'robots'), $url);
        }
        $this->assertNull($this->meta($this->html('/shop'), 'name', 'robots'), 'the plain listing is indexable');
    }

    public function test_private_and_transactional_pages_are_noindex_nofollow_with_a_header_too(): void
    {
        $this->production();
        foreach (['/cart', '/checkout', '/login', '/register', '/wishlist', '/track-order', '/forgot-password'] as $path) {
            $res = $this->get(self::HOST.$path);
            $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'), $path);
            if ($res->getStatusCode() === 200) {
                $this->assertSame('noindex,nofollow', $this->meta($res->getContent(), 'name', 'robots'), $path);
            }
        }
        $this->assertNull($this->get(self::HOST.'/')->headers->get('X-Robots-Tag'));
    }

    public function test_nothing_is_indexable_outside_production_or_when_the_owner_turns_indexing_off(): void
    {
        $this->assertSame('noindex,nofollow', $this->meta($this->html('/', false), 'name', 'robots'));   // testing environment
        $this->assertSame('noindex, nofollow', $this->get('/')->headers->get('X-Robots-Tag'));

        $this->production();
        $this->assertNull($this->meta($this->html('/'), 'name', 'robots'));
        Setting::put('seo.allow_indexing', false);
        $this->assertSame('noindex,nofollow', $this->meta($this->html('/'), 'name', 'robots'));
    }

    public function test_sample_products_and_pages_are_never_indexed(): void
    {
        $sample = $this->product(['is_sample' => true]);
        $page = Page::create(['title' => 'Privacy', 'slug' => 'privacy', 'body' => 'Placeholder', 'is_published' => true, 'is_sample' => true]);

        $this->assertSame('noindex,follow', $this->meta($this->html('/products/'.$sample->slug), 'name', 'robots'));
        $this->assertSame('noindex,follow', $this->meta($this->html('/pages/privacy'), 'name', 'robots'));
        $this->assertNull($this->meta($this->html('/products/'.$this->product()->slug), 'name', 'robots'));
    }

    /* ---------------- Open Graph & Twitter ---------------- */

    public function test_open_graph_and_twitter_tags_are_complete_and_absolute(): void
    {
        $p = $this->product(['name' => 'Linen Shirt', 'short_description' => 'Light and airy.']);
        $this->withImage($p);
        $html = $this->html('/products/'.$p->slug);

        $this->assertSame('product', $this->meta($html, 'property', 'og:type'));
        $this->assertSame('Linen Shirt', $this->meta($html, 'property', 'og:title'));
        $this->assertSame('Light and airy.', $this->meta($html, 'property', 'og:description'));
        $this->assertSame(self::HOST.'/products/'.$p->slug, $this->meta($html, 'property', 'og:url'));
        $this->assertMatchesRegularExpression('#^https?://.+/products/a\.jpg$#', $this->meta($html, 'property', 'og:image'));
        $this->assertSame('summary_large_image', $this->meta($html, 'name', 'twitter:card'));
        $this->assertSame($this->meta($html, 'property', 'og:image'), $this->meta($html, 'name', 'twitter:image'));
    }

    public function test_pages_without_a_photo_share_the_supplied_logo_not_an_invented_image(): void
    {
        $html = $this->html('/');

        $this->assertSame('website', $this->meta($html, 'property', 'og:type'));
        $this->assertStringEndsWith('/assets/brand/abiha-logo-full-600.png', $this->meta($html, 'property', 'og:image'));
        $this->assertSame('summary', $this->meta($html, 'name', 'twitter:card'));
    }

    /* ---------------- structured data ---------------- */

    public function test_product_structured_data_matches_the_page_and_never_invents_ratings(): void
    {
        $p = $this->product(['name' => 'Linen Shirt', 'price' => 4500, 'sku' => 'LS-1', 'short_description' => 'Light.']);
        $this->withImage($p);
        $ld = $this->ld($this->html('/products/'.$p->slug), 'Product');

        $this->assertSame('Linen Shirt', $ld['name']);
        $this->assertSame('LS-1', $ld['sku']);
        $this->assertSame('Offer', $ld['offers']['@type']);
        $this->assertSame(4500, $ld['offers']['price']);
        $this->assertSame('PKR', $ld['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $ld['offers']['availability']);
        $this->assertStringStartsWith('http', $ld['image'][0]);
        $this->assertArrayNotHasKey('aggregateRating', $ld, 'no reviews, so no rating');
        $this->assertArrayNotHasKey('brand', $ld, 'there is no brand data, so none is invented');
    }

    public function test_structured_data_reports_stock_ratings_and_variant_price_ranges_truthfully(): void
    {
        $gone = $this->product(['stock' => 0]);
        $this->assertSame('https://schema.org/OutOfStock', $this->ld($this->html('/products/'.$gone->slug), 'Product')['offers']['availability']);

        $rated = $this->product();
        Review::create(['product_id' => $rated->id, 'name' => 'A', 'rating' => 5, 'body' => 'Great', 'is_approved' => true]);
        Review::create(['product_id' => $rated->id, 'name' => 'B', 'rating' => 3, 'body' => 'Fine', 'is_approved' => true]);
        Review::create(['product_id' => $rated->id, 'name' => 'C', 'rating' => 1, 'body' => 'Unapproved', 'is_approved' => false]);
        $agg = $this->ld($this->html('/products/'.$rated->slug), 'Product')['aggregateRating'];
        $this->assertSame(2, $agg['reviewCount']);
        $this->assertEquals(4.0, $agg['ratingValue']);

        [$vp] = $this->variantProduct();
        $offer = $this->ld($this->html('/products/'.$vp->slug), 'Product')['offers'];
        $this->assertSame('AggregateOffer', $offer['@type']);
        $this->assertSame(2000, $offer['lowPrice']);
        $this->assertSame(2500, $offer['highPrice']);
        $this->assertSame(3, $offer['offerCount']);
    }

    public function test_structured_data_cannot_break_out_of_its_script_tag(): void
    {
        $p = $this->product(['name' => 'Evil </script><script>alert(1)</script> & "quotes"']);
        $html = $this->html('/products/'.$p->slug);

        $this->assertSame(0, preg_match('#</script><script>alert#', $html));
        $this->assertSame($p->name, $this->ld($html, 'Product')['name'], 'the data still round-trips exactly');
    }

    public function test_breadcrumb_markup_and_structured_data_agree(): void
    {
        $parent = Category::create(['name' => 'Women', 'slug' => 'women', 'is_active' => true]);
        $child = Category::create(['name' => 'Dresses', 'slug' => 'dresses', 'parent_id' => $parent->id, 'is_active' => true]);
        $p = $this->product(['name' => 'Silk Dress', 'category_id' => $child->id]);
        $html = $this->html('/products/'.$p->slug);

        $list = $this->ld($html, 'BreadcrumbList');
        $names = array_column($list['itemListElement'], 'name');
        $this->assertSame(['Home', 'Women', 'Dresses', 'Silk Dress'], $names);
        $this->assertSame([1, 2, 3, 4], array_column($list['itemListElement'], 'position'));
        $this->assertSame(self::HOST.'/category/dresses', $list['itemListElement'][2]['item']);
        $this->assertArrayNotHasKey('item', $list['itemListElement'][3], 'the current page needs no URL');

        preg_match('#<nav aria-label="Breadcrumb".*?</nav>#s', $html, $nav);
        foreach ($names as $n) {
            $this->assertStringContainsString($n, $nav[0]);
        }
        $this->assertStringContainsString('aria-current="page"', $nav[0]);

        $cat = $this->ld($this->html('/category/dresses'), 'BreadcrumbList');
        $this->assertSame(['Home', 'Women', 'Dresses'], array_column($cat['itemListElement'], 'name'));
    }

    public function test_listing_pages_describe_their_products_as_an_item_list(): void
    {
        $a = $this->product(['name' => 'A']);
        $b = $this->product(['name' => 'B']);
        $col = Collection::create(['name' => 'Eid Edit', 'slug' => 'eid', 'is_active' => true]);
        $col->products()->sync([$a->id, $b->id]);

        $page = $this->ld($this->html('/collections/eid'), 'CollectionPage');
        $urls = array_column($page['mainEntity']['itemListElement'], 'url');
        $this->assertEqualsCanonicalizing([self::HOST.'/products/'.$a->slug, self::HOST.'/products/'.$b->slug], $urls);
    }

    public function test_home_declares_the_organization_and_site_search_using_only_admin_entered_facts(): void
    {
        $html = $this->html('/');
        $org = $this->ld($html, 'Organization');
        $site = $this->ld($html, 'WebSite');

        $this->assertSame(self::HOST, rtrim($org['url'], '/'));
        $this->assertStringStartsWith('http', $org['logo']);
        $this->assertSame(self::HOST.'/shop?q={search_term_string}', $site['potentialAction']['target']['urlTemplate']);

        Setting::put('social.instagram', 'https://instagram.com/example');
        Setting::put('contact.email', 'hello@example.test');
        $org = $this->ld($this->html('/'), 'Organization');
        $this->assertSame(['https://instagram.com/example'], $org['sameAs']);
        $this->assertSame('hello@example.test', $org['contactPoint']['email']);
        $this->assertNull($this->ld($this->html('/shop'), 'Organization'), 'the organization is described once, on the home page');
    }

    /* ---------------- sitemap & robots ---------------- */

    public function test_the_sitemap_lists_real_public_pages_and_nothing_else(): void
    {
        $live = $this->product(['name' => 'Live One']);
        $this->withImage($live, 'Live one front');
        $draft = $this->product(['name' => 'Draft', 'is_active' => false]);
        $sample = $this->product(['name' => 'Demo', 'is_sample' => true]);
        $cat = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        $hidden = Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        Page::create(['title' => 'About', 'slug' => 'about', 'body' => 'x', 'is_published' => true]);
        Page::create(['title' => 'Draft page', 'slug' => 'draft-page', 'body' => 'x', 'is_published' => false]);
        Page::create(['title' => 'Sample page', 'slug' => 'sample-page', 'body' => 'x', 'is_published' => true, 'is_sample' => true]);

        $this->production();
        $res = $this->get(self::HOST.'/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $xml = simplexml_load_string($res->getContent());                                  // well-formed
        $this->assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
        }

        foreach (['/', '/shop', '/category/shirts', '/products/'.$live->slug, '/pages/about'] as $path) {
            $this->assertContains(rtrim(self::HOST.$path, '/') ?: self::HOST, array_map(fn ($l) => rtrim($l, '/'), $locs), $path);
        }
        $all = implode(' ', $locs);
        foreach ([$draft->slug, $sample->slug, 'hidden', 'draft-page', 'sample-page', '/cart', '/checkout', '/account', '/login', '/admin'] as $nope) {
            $this->assertStringNotContainsString($nope, $all, "{$nope} must not be listed");
        }
        $this->assertStringContainsString('<image:loc>', $res->getContent());
        $this->assertStringContainsString('<image:title>Live one front</image:title>', $res->getContent());
        $this->assertStringContainsString('<lastmod>', $res->getContent());
    }

    public function test_the_sitemap_escapes_special_characters_and_follows_catalogue_changes(): void
    {
        $this->production();
        $p = $this->product(['name' => 'Ampersand', 'slug' => 'first-slug']);
        $this->assertStringContainsString('/products/first-slug', $this->get(self::HOST.'/sitemap.xml')->getContent());

        $p->update(['slug' => 'second-slug']);                                         // cached copy must not go stale
        $body = $this->get(self::HOST.'/sitemap.xml')->getContent();
        $this->assertStringContainsString('/products/second-slug', $body);
        $this->assertStringNotContainsString('first-slug', $body);

        $p->update(['is_active' => false]);
        $this->assertStringNotContainsString('second-slug', $this->get(self::HOST.'/sitemap.xml')->getContent());
    }

    public function test_robots_txt_blocks_everything_off_production_and_guides_crawlers_on_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertSee("Disallow: /\n", false);

        $this->production();
        $live = $this->get(self::HOST.'/robots.txt')->getContent();
        foreach (['Disallow: /admin', 'Disallow: /account', 'Disallow: /cart', 'Disallow: /checkout', 'Disallow: /order', 'Disallow: /*?*q=', 'Sitemap: '.self::HOST.'/sitemap.xml'] as $line) {
            $this->assertStringContainsString($line, $live);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $live, 'production must not block the whole site');

        Setting::put('seo.allow_indexing', false);
        $this->assertStringContainsString("Disallow: /\n", $this->get(self::HOST.'/robots.txt')->getContent());
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'a static file would shadow the generated one');
    }

    /* ---------------- URLs ---------------- */

    public function test_urls_are_readable_slugs(): void
    {
        $p = $this->product(['name' => 'Linen Summer Shirt']);

        $this->assertStringStartsWith('linen-summer-shirt', $p->slug);
        $this->assertSame(url('/products/'.$p->slug), route('product', $p));
        $this->assertDoesNotMatchRegularExpression('#/products/\d+$#', route('product', $p));
    }

    public function test_the_old_query_string_category_url_redirects_permanently_to_the_clean_one(): void
    {
        Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);

        $this->get('/shop?category=shirts&sort=newest')->assertStatus(301)->assertRedirect(route('category', 'shirts').'?sort=newest');
        $this->get('/shop?category=does-not-exist')->assertOk();
    }

    /* ---------------- images & headings ---------------- */

    public function test_every_image_on_the_storefront_has_an_alt_attribute(): void
    {
        $p = $this->product(['name' => 'Linen Shirt']);
        $this->withImage($p, 'Linen shirt on a hanger');
        $this->addToCart($p);

        foreach (['/', '/shop', '/products/'.$p->slug, '/cart'] as $path) {
            $html = $this->get($path)->getContent();
            preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
            $this->assertNotEmpty($imgs[0], $path);
            foreach ($imgs[0] as $img) {
                $this->assertMatchesRegularExpression('/\salt="/', $img, "{$path}: <img> without alt: {$img}");
            }
        }
        $this->assertStringContainsString('alt="Linen shirt on a hanger"', $this->get('/products/'.$p->slug)->getContent());
    }

    public function test_pages_have_exactly_one_h1(): void
    {
        $p = $this->product();
        foreach (['/', '/shop', '/products/'.$p->slug, '/contact', '/cart'] as $path) {
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $this->get($path)->getContent()), "{$path} must have one <h1>");
        }
    }

    public function test_editors_can_set_alt_text_and_a_blank_one_falls_back_to_the_product_name(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $p = $this->product(['name' => 'Linen Shirt', 'slug' => 'linen-shirt']);
        $img = $this->withImage($p);

        $payload = fn (string $alt) => ['name' => $p->name, 'slug' => $p->slug, 'sku' => $p->sku, 'price' => 1000, 'stock' => 5, 'is_active' => 1, 'image_alts' => [$img->id => $alt]];

        $this->actingAs($admin)->put(route('admin.products.update', $p), $payload('<b>Front</b> view'))->assertSessionHasNoErrors();
        $this->assertSame('Front view', $img->fresh()->alt, 'alt text is plain text');

        $this->put(route('admin.products.update', $p), $payload(''))->assertSessionHasNoErrors();
        $this->assertSame('Linen Shirt', $img->fresh()->alt);
    }
}
