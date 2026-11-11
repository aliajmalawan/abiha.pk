<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** QA: product filters, sorting, search and navigation, tested through real HTTP requests. */
class QaStorefrontTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private Category $women;
    private Category $men;
    private Category $dresses;

    /** A small but varied catalogue: sale, sold-out, variants with their own price, inactive, wildcard characters in a name. */
    private function seedCatalogue(): array
    {
        $this->women = Category::create(['name' => 'Women', 'slug' => 'women', 'is_active' => true]);
        $this->dresses = Category::create(['name' => 'Dresses', 'slug' => 'dresses', 'parent_id' => $this->women->id, 'is_active' => true]);
        $this->men = Category::create(['name' => 'Men', 'slug' => 'men', 'is_active' => true]);

        $p = [];
        $p['sale'] = $this->product(['name' => 'Alpha Sale Top', 'slug' => 'alpha-sale-top', 'price' => 500, 'compare_price' => 800, 'category_id' => $this->women->id, 'sku' => 'AL-1', 'short_description' => 'Breezy cotton']);
        $p['soldout'] = $this->product(['name' => 'Bravo Sold Out', 'slug' => 'bravo-sold-out', 'price' => 1500, 'stock' => 0, 'category_id' => $this->women->id, 'sku' => 'BR-1']);
        $p['men'] = $this->product(['name' => 'Charlie Coat', 'slug' => 'charlie-coat', 'price' => 3000, 'category_id' => $this->men->id, 'sku' => 'CH-1']);
        $p['dress'] = $this->product(['name' => 'Delta Dress', 'slug' => 'delta-dress', 'price' => 2200, 'category_id' => $this->dresses->id, 'sku' => 'DE-1']);
        [$p['variant']] = $this->variantProduct();           // base 2000; S 3 in stock, M 0, L 2500 with 4 in stock
        $p['variant']->update(['name' => 'Echo Variant Tee', 'slug' => 'echo-variant-tee', 'category_id' => $this->men->id]);
        $p['hidden'] = $this->product(['name' => 'Foxtrot Hidden', 'slug' => 'foxtrot-hidden', 'price' => 700, 'is_active' => false, 'category_id' => $this->men->id]);
        $p['percent'] = $this->product(['name' => 'Golf 100%_Off Special', 'slug' => 'golf-percent-special', 'price' => 900, 'category_id' => $this->men->id, 'sku' => 'GO-1']);

        return $p;
    }

    /** Names of the product cards on a listing, in page order. */
    private function names(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match_all('#<h3 class="product-card__title"><a href="[^"]*/products/[^"]*">([^<]+)</a>#', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    /* ---------------- product filters ---------------- */

    public function test_the_unfiltered_shop_lists_every_active_product_and_never_inactive_ones(): void
    {
        $this->seedCatalogue();
        $names = $this->names('/shop');

        $this->assertCount(6, $names);
        $this->assertNotContains('Foxtrot Hidden', $names);
    }

    public function test_price_filter_is_inclusive_and_matches_variant_prices(): void
    {
        $this->seedCatalogue();

        $this->assertEqualsCanonicalizing(['Alpha Sale Top'], $this->names('/shop?min=500&max=500'));
        $this->assertEqualsCanonicalizing(['Charlie Coat'], $this->names('/shop?min=3000'));
        $this->assertEqualsCanonicalizing(['Alpha Sale Top', 'Golf 100%_Off Special'], $this->names('/shop?max=900'));
        // The variant product's base price is 2000 and its L variant costs 2500: both ranges find it.
        $this->assertContains('Echo Variant Tee', $this->names('/shop?min=1900&max=2100'));
        $this->assertContains('Echo Variant Tee', $this->names('/shop?min=2400&max=2600'));
        $this->assertNotContains('Echo Variant Tee', $this->names('/shop?min=2600&max=2900'));
        $this->assertSame([], $this->names('/shop?min=5000'));
    }

    public function test_in_stock_filter_hides_sold_out_products(): void
    {
        $this->seedCatalogue();
        $names = $this->names('/shop?availability=in_stock');

        $this->assertNotContains('Bravo Sold Out', $names);
        $this->assertContains('Echo Variant Tee', $names, 'a variant product with some stock is in stock');
        $this->assertCount(5, $names);
    }

    public function test_a_variant_product_with_every_variant_out_of_stock_is_filtered_out(): void
    {
        $p = $this->seedCatalogue();
        $p['variant']->variants()->update(['stock' => 0]);

        $this->assertNotContains('Echo Variant Tee', $this->names('/shop?availability=in_stock'));
        $this->assertContains('Echo Variant Tee', $this->names('/shop'), 'still listed, just not in the in-stock view');
    }

    public function test_sale_filter_shows_only_discounted_products(): void
    {
        $this->seedCatalogue();

        $this->assertSame(['Alpha Sale Top'], $this->names('/shop?sale=1'));
    }

    public function test_size_and_colour_filters_use_active_variants_only(): void
    {
        $p = $this->seedCatalogue();

        $this->assertSame(['Echo Variant Tee'], $this->names('/shop?size[]=L'));
        $this->assertSame(['Echo Variant Tee'], $this->names('/shop?size[]=S&size[]=XL'), 'any selected size matches');
        $this->assertSame(['Echo Variant Tee'], $this->names('/shop?color[]=Black'));
        $this->assertSame([], $this->names('/shop?size[]=XL'));
        $this->assertSame([], $this->names('/shop?color[]=Pink'));

        $p['variant']->allVariants()->where('sku', 'like', '%-L')->update(['is_active' => false]);
        $this->assertSame([], $this->names('/shop?size[]=L'), 'an inactive variant no longer matches');
    }

    public function test_filters_combine_with_and_semantics(): void
    {
        $this->seedCatalogue();

        $this->assertSame(['Alpha Sale Top'], $this->names('/category/women?sale=1&max=1000&availability=in_stock'));
        $this->assertSame([], $this->names('/category/women?sale=1&min=1000'));
        $this->assertSame(['Echo Variant Tee'], $this->names('/category/men?size[]=L&availability=in_stock&min=1000'));
    }

    public function test_sorting_orders_the_results_correctly(): void
    {
        $this->seedCatalogue();

        $asc = $this->names('/shop?sort=price_asc');
        $this->assertSame('Alpha Sale Top', $asc[0]);
        $this->assertSame('Charlie Coat', end($asc));
        $desc = $this->names('/shop?sort=price_desc');
        $this->assertSame('Charlie Coat', $desc[0]);
        $this->assertSame('Alpha Sale Top', end($desc));
        $byName = $this->names('/shop?sort=name');
        $sorted = $byName;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $byName);
        $this->assertSame('Golf 100%_Off Special', $this->names('/shop?sort=newest')[0], 'newest first');
        Product::where('slug', 'bravo-sold-out')->update(['sold_count' => 50]);
        $this->assertSame('Bravo Sold Out', $this->names('/shop?sort=popular')[0], 'best selling first');
    }

    public function test_category_pages_include_subcategories_and_hide_inactive_categories(): void
    {
        $this->seedCatalogue();

        $this->assertEqualsCanonicalizing(['Alpha Sale Top', 'Bravo Sold Out', 'Delta Dress'], $this->names('/category/women'));
        $this->assertSame(['Delta Dress'], $this->names('/category/dresses'));

        $hidden = Category::create(['name' => 'Secret', 'slug' => 'secret', 'is_active' => false]);
        $this->get('/category/secret')->assertNotFound();
        $this->get('/category/nope')->assertNotFound();
    }

    public function test_collections_list_their_products_and_inactive_ones_are_404(): void
    {
        $p = $this->seedCatalogue();
        $c = Collection::create(['name' => 'Eid Edit', 'slug' => 'eid', 'is_active' => true]);
        $c->products()->sync([$p['sale']->id, $p['men']->id]);
        Collection::create(['name' => 'Old', 'slug' => 'old', 'is_active' => false]);

        $this->assertEqualsCanonicalizing(['Alpha Sale Top', 'Charlie Coat'], $this->names('/collections/eid'));
        $this->get('/collections/old')->assertNotFound();
    }

    public function test_pagination_keeps_the_filters_and_covers_every_product_exactly_once(): void
    {
        config(['abiha.pagination.products' => 2]);
        $this->seedCatalogue();

        $page1 = $this->get('/shop?sort=price_asc&max=3000')->getContent();
        $this->assertStringContainsString('page=2', $page1);
        $this->assertMatchesRegularExpression('#href="[^"]*sort=price_asc[^"]*page=2|href="[^"]*page=2[^"]*sort=price_asc#', html_entity_decode($page1), 'page links keep the sort');

        $seen = [];
        foreach ([1, 2, 3, 4] as $page) {
            $seen = array_merge($seen, $this->names("/shop?sort=price_asc&page={$page}"));
        }
        $this->assertCount(6, $seen);
        $this->assertCount(6, array_unique($seen), 'no product is repeated or skipped across pages');
    }

    public function test_hostile_and_malformed_filter_values_never_cause_a_server_error(): void
    {
        $this->seedCatalogue();
        $bad = [
            'min=abc', 'max=-5', 'min=9999999999999999999999', 'min=1.5', 'min[]=1', 'sort=;DROP TABLE products', 'sort[]=x', 'color=Black', 'size=L',
            'availability=out_of_stock', 'sale=maybe', 'q='.str_repeat('a', 101), 'q[]=a', 'page=-1', 'page=abc', 'page[]=1', 'page=99999999999',
            'category=../../etc/passwd', 'category[]=x', 'color[]='.str_repeat('x', 500), "q=%00", 'q='.urlencode("' OR 1=1 --"), 'q='.urlencode('<script>alert(1)</script>'),
        ];

        foreach ($bad as $qs) {
            $res = $this->get('/shop?'.$qs);
            $this->assertLessThan(500, $res->getStatusCode(), "/shop?{$qs} returned {$res->getStatusCode()}");
            $this->assertStringNotContainsString('<script>alert(1)</script>', $res->getContent(), "{$qs} reflected unescaped");
            $this->assertStringNotContainsString('Stack trace', $res->getContent());
        }
    }

    public function test_a_page_past_the_end_and_an_empty_result_show_a_friendly_state(): void
    {
        $this->seedCatalogue();

        $past = $this->get('/shop?page=50')->assertOk();
        $this->assertStringNotContainsString('product-card', $past->getContent());
        $this->get('/shop?min=99999')->assertOk()->assertSee('No products', false);
    }

    public function test_the_filter_form_marks_the_active_selections(): void
    {
        $this->seedCatalogue();
        $html = $this->get('/shop?sale=1&availability=in_stock&size[]=L&min=100&max=900&sort=price_desc')->getContent();

        $this->assertMatchesRegularExpression('#name="sale" value="1"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="availability" value="in_stock"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="size\[\]" value="L"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="min"[^>]*value="100"#', $html);
        $this->assertMatchesRegularExpression('#<option value="price_desc"[^>]*selected#', $html);
    }

    /* ---------------- search ---------------- */

    public function test_search_matches_name_sku_and_description_case_insensitively(): void
    {
        $this->seedCatalogue();

        $this->assertSame(['Charlie Coat'], $this->names('/shop?q=charlie'));
        $this->assertSame(['Charlie Coat'], $this->names('/shop?q=CHARLIE'));
        $this->assertSame(['Delta Dress'], $this->names('/shop?q=DE-1'));
        $this->assertSame(['Alpha Sale Top'], $this->names('/shop?q=breezy'));
        $this->assertSame([], $this->names('/shop?q=nothingmatches'));
        $this->assertSame(['Delta Dress'], $this->names('/shop?q='.urlencode('  delta  ')), 'surrounding spaces are ignored');
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->seedCatalogue();

        $this->assertSame(['Golf 100%_Off Special'], $this->names('/shop?q='.urlencode('100%_Off')));
        $this->assertSame(['Golf 100%_Off Special'], $this->names('/shop?q='.urlencode('%')), 'a lone % matches only the product that really contains a % sign');
        $this->assertSame([], $this->names('/shop?q='.urlencode('1_0')), 'an underscore is not a single-character wildcard (it would match "100")');
    }

    public function test_search_never_returns_inactive_products_and_can_combine_with_filters(): void
    {
        $this->seedCatalogue();

        $this->assertSame([], $this->names('/shop?q=foxtrot'));
        $this->assertSame(['Echo Variant Tee'], $this->names('/shop?q=tee&size[]=L'));
        $this->assertSame([], $this->names('/shop?q=charlie&max=100'));
    }

    public function test_live_suggestions_return_a_small_safe_json_list(): void
    {
        $this->seedCatalogue();

        $this->getJson('/search/suggest?q=a')->assertOk()->assertExactJson(['results' => []]);          // under 2 characters
        $this->getJson('/search/suggest')->assertOk()->assertExactJson(['results' => []]);

        $json = $this->getJson('/search/suggest?q=delta')->assertOk()->json('results');
        $this->assertCount(1, $json);
        $this->assertSame('Delta Dress', $json[0]['name']);
        $this->assertStringEndsWith('/products/delta-dress', $json[0]['url']);
        $this->assertArrayHasKey('price', $json[0]);

        foreach (range(1, 10) as $i) {
            $this->product(['name' => 'Zulu Many '.$i]);
        }
        $this->assertCount(6, $this->getJson('/search/suggest?q=zulu')->json('results'), 'capped at six suggestions');
        $this->assertSame([], $this->getJson('/search/suggest?q=foxtrot')->json('results'), 'inactive products are never suggested');
        foreach (["'", '"', '%%', '\\', '<b>', str_repeat('x', 500)] as $q) {
            $this->assertLessThan(500, $this->getJson('/search/suggest?q='.urlencode($q))->getStatusCode(), $q);
        }
    }

    public function test_the_search_term_is_shown_back_escaped(): void
    {
        $this->seedCatalogue();
        $html = $this->get('/shop?q='.urlencode('"><img src=x onerror=alert(1)>'))->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;img src=x onerror', $html);
    }

    /* ---------------- navigation ---------------- */

    public function test_header_and_footer_link_to_real_pages_and_every_internal_link_resolves(): void
    {
        $p = $this->seedCatalogue();
        \App\Models\Page::create(['title' => 'About', 'slug' => 'about', 'body' => '<p>About</p>', 'is_published' => true]);
        $this->shipping();
        $this->addToCart($p['sale']);

        $queue = ['/', '/shop', '/category/women', '/category/dresses', '/collections/none-yet', '/products/alpha-sale-top', '/cart', '/wishlist', '/contact', '/track-order', '/shipping-returns', '/login', '/register', '/pages/about'];
        $seen = [];
        $dead = [];
        while ($queue) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $res = $this->get($path);
            if ($path === '/collections/none-yet') {
                $res->assertNotFound();                                   // a missing page is a real 404, not a 500
                continue;
            }
            if ($res->getStatusCode() >= 300) {
                $dead[] = "{$path} → {$res->getStatusCode()}";
                continue;
            }
            preg_match_all('~<a\b[^>]*\shref="([^"#]+)"~', $res->getContent(), $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                if (str_starts_with($href, url('/'))) {
                    $href = substr($href, strlen(url('/')));
                }
                if (! str_starts_with($href, '/') || str_starts_with($href, '//') || str_contains($href, '/admin') || str_starts_with($href, '/logout')
                    || str_contains($href, '/build/') || str_contains($href, '/storage/') || str_contains($href, 'sitemap') || str_contains($href, 'robots')) {
                    continue;
                }
                $queue[] = rtrim(explode('?', $href)[0], '/') ?: '/';
            }
        }

        $this->assertSame([], $dead, 'broken internal links: '.implode(', ', $dead));
        $this->assertGreaterThan(14, count($seen), 'the crawl actually followed links');
    }

    public function test_the_header_lists_active_top_level_categories_only(): void
    {
        $this->seedCatalogue();
        Category::create(['name' => 'Retired Range', 'slug' => 'retired', 'is_active' => false]);
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('/category/women', $html);
        $this->assertStringContainsString('/category/men', $html);
        $this->assertStringNotContainsString('/category/retired', $html);
        $this->assertStringContainsString('aria-label="Primary"', $html);
    }

    public function test_unknown_urls_give_a_branded_404_and_wrong_methods_a_405(): void
    {
        $this->get('/no/such/page')->assertNotFound()->assertSee('Skip to content', false);
        $this->get('/products/does-not-exist')->assertNotFound();
        $this->get('/products/foxtrot-hidden-nope')->assertNotFound();
        $this->post('/shop')->assertStatus(405);
        $this->get('/cart/items')->assertStatus(405);
    }

    public function test_inactive_products_are_unreachable_everywhere(): void
    {
        $p = $this->seedCatalogue();

        $this->get('/products/foxtrot-hidden')->assertNotFound();
        $this->get('/products/foxtrot-hidden/quick-view')->assertNotFound();
        $this->addToCart($p['hidden'])->assertStatus(422);
        $this->assertStringNotContainsString('foxtrot-hidden', $this->get('/sitemap.xml')->getContent());
    }

    public function test_the_sort_menu_offers_latest_price_both_ways_and_popularity_and_every_option_works(): void
    {
        $this->seedCatalogue();
        $html = $this->get('/shop')->getContent();

        preg_match('#<select id="sort".*?</select>#s', $html, $m);
        foreach (['Latest', 'Price: low to high', 'Price: high to low', 'Popularity'] as $label) {
            $this->assertStringContainsString('>'.$label.'<', $m[0], "the sort menu must offer: {$label}");
        }
        preg_match_all('#<option value="([a-z_]+)"#', $m[0], $values);
        foreach ($values[1] as $value) {
            $this->assertCount(6, $this->names('/shop?sort='.$value), "sort={$value} lists every product");
        }
        $this->assertSame('Golf 100%_Off Special', $this->names('/shop?sort=newest')[0], 'Latest puts the newest product first');
    }
}
