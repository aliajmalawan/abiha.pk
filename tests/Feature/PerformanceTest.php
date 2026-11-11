<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\HomeSection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageStore;
use App\Support\Img;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function jpegBytes(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 120, 160));
        ob_start();
        imagejpeg($im, null, 85);

        return (string) ob_get_clean();
    }

    private function storeImage(string $path, int $w = 1800, int $h = 2250): string
    {
        Storage::disk('public')->put($path, $this->jpegBytes($w, $h));
        Img::generate($path);

        return $path;
    }

    /** A product with a category, two images and (optionally) variants, as a real catalogue entry looks. */
    private function richProduct(Category $cat, int $i, bool $variants = false): Product
    {
        $p = $variants ? $this->variantProduct()[0] : $this->product(['name' => "Rich {$i}", 'slug' => "rich-{$i}"]);
        $p->update(['category_id' => $cat->id, 'is_featured' => true, 'is_new' => true, 'is_bestseller' => true, 'is_trending' => true]);
        foreach ([1, 2] as $n) {
            ProductImage::create(['product_id' => $p->id, 'path' => "products/p{$i}-{$n}.jpg", 'alt' => $p->name, 'sort_order' => $n]);
        }

        return $p;
    }

    private function countQueries(string $url): int
    {
        Cache::flush();                                    // measure the cold path: that is where an N+1 hides
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /* ---------------- responsive images & WebP ---------------- */

    public function test_uploads_get_webp_variants_that_are_smaller_than_the_original_and_never_upscaled(): void
    {
        $path = app(ImageStore::class)->store(UploadedFile::fake()->createWithContent('big.jpg', $this->jpegBytes(2000, 1500)), 'products');

        $this->assertSame([320, 480, 640, 960, 1280, 1600], Img::widths($path));
        foreach (Img::widths($path) as $w) {
            $bytes = Storage::disk('public')->get(Img::variantPath($path, $w));
            $info = getimagesizefromstring($bytes);
            $this->assertSame(IMAGETYPE_WEBP, $info[2]);
            $this->assertSame($w, $info[0]);
            $this->assertSame((int) round(1500 * $w / 2000), $info[1], 'aspect ratio is preserved');
        }
        $this->assertLessThan(Storage::disk('public')->size($path), Storage::disk('public')->size(Img::variantPath($path, 1600)));

        $small = app(ImageStore::class)->store(UploadedFile::fake()->createWithContent('small.jpg', $this->jpegBytes(500, 400)), 'products');
        $this->assertSame([320, 480], Img::widths($small), 'only widths below the original are generated');
    }

    public function test_png_transparency_survives_in_the_webp_variants(): void
    {
        $im = imagecreatetruecolor(900, 900);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 10, 20, 30, 127));
        ob_start();
        imagepng($im);
        Storage::disk('public')->put('logos/t.png', (string) ob_get_clean());

        Img::generate('logos/t.png');

        $webp = imagecreatefromstring(Storage::disk('public')->get(Img::variantPath('logos/t.png', 320)));
        $this->assertGreaterThan(110, (imagecolorat($webp, 5, 5) >> 24) & 0x7F, 'a fully transparent pixel stays (almost) fully transparent: lossy WebP quantises alpha slightly');
    }

    public function test_deleting_an_image_removes_every_variant(): void
    {
        $path = $this->storeImage('products/x.jpg');
        $this->assertNotEmpty(Img::widths($path));

        Img::delete($path);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Img::widths($path));
    }

    public function test_the_picture_component_serves_webp_with_srcset_dimensions_and_lazy_loading(): void
    {
        $path = $this->storeImage('products/a.jpg', 1800, 2250);
        $html = Blade::render('<x-picture :path="$p" alt="Front" sizes="50vw" class="size-full" />', ['p' => $path]);

        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertMatchesRegularExpression('#srcset="[^"]*-w320\.webp\?v=\d+ 320w, [^"]*-w480\.webp\?v=\d+ 480w#', $html);
        $this->assertStringContainsString('width="1800" height="2250"', $html, 'natural size reserves the space: no layout shift');
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringContainsString('alt="Front"', $html);
        $this->assertStringContainsString('class="size-full"', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*products/a\.jpg\?v=\d+"#', $html, 'the original is the fallback for browsers without WebP');
        $this->assertStringNotContainsString('fetchpriority', $html);
    }

    public function test_the_lcp_image_is_eager_and_high_priority_and_old_images_still_render(): void
    {
        $path = $this->storeImage('products/a.jpg');
        $hero = Blade::render('<x-picture :path="$p" alt="" :priority="true" />', ['p' => $path]);
        $this->assertStringContainsString('loading="eager"', $hero);
        $this->assertStringContainsString('fetchpriority="high"', $hero);

        Storage::disk('public')->put('products/legacy.jpg', $this->jpegBytes(900, 900));      // uploaded before variants existed
        $legacy = Blade::render('<x-picture :path="$p" alt="Old" />', ['p' => 'products/legacy.jpg']);
        $this->assertStringNotContainsString('<source', $legacy);
        $this->assertStringContainsString('<img src=', $legacy);
    }

    public function test_the_supplied_logo_is_served_as_small_webp_sizes_with_a_png_fallback(): void
    {
        $html = $this->get('/')->getContent();
        preg_match('#<picture>\s*<source type="image/webp" sizes="76px" srcset="([^"]+)">\s*<img src="([^"]+)" alt="[^"]*" width="76" height="55"[^>]*loading="eager"#', $html, $m);

        $this->assertNotEmpty($m, 'the header logo is a WebP <picture> with explicit dimensions and eager loading');
        $this->assertStringContainsString('abiha-logo-full-240.webp 240w', $m[1]);
        $this->assertStringEndsWith('abiha-logo-full-300.png', $m[2], 'small PNG fallback, not the 220 KB one');
        foreach (['full-240', 'full-360', 'full-480', 'full-640'] as $v) {
            $this->assertFileExists(public_path("assets/brand/abiha-logo-{$v}.webp"));
            $this->assertLessThan(40_000, filesize(public_path("assets/brand/abiha-logo-{$v}.webp")));
        }
        $this->assertStringContainsString('loading="lazy"', substr($html, strpos($html, '<footer')), 'the footer logo is below the fold');
    }

    public function test_the_backfill_command_builds_variants_for_images_that_have_none(): void
    {
        Storage::disk('public')->put('products/legacy.jpg', $this->jpegBytes(1400, 1000));
        Storage::disk('public')->put('products/legacy2.png', $this->jpegBytes(700, 700));
        $this->assertSame([], Img::widths('products/legacy.jpg'));

        $this->artisan('abiha:images-optimize')->expectsOutputToContain('Variants created for 2 image(s)')->assertSuccessful();

        $this->assertSame([320, 480, 640, 960, 1280], Img::widths('products/legacy.jpg'));
        $this->artisan('abiha:images-optimize')->expectsOutputToContain('Variants created for 0 image(s); 2 already had them')->assertSuccessful();
    }

    public function test_the_product_gallery_is_server_rendered_with_only_the_first_image_prioritised(): void
    {
        $cat = Category::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $p = $this->richProduct($cat, 1);
        $this->storeImage('products/p1-1.jpg');
        $this->storeImage('products/p1-2.jpg');

        $html = $this->get('/products/'.$p->slug)->getContent();
        preg_match('#aspect-\[4/5\].*?</div>#s', substr($html, strpos($html, 'x-data="gallery(')), $gallery);

        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'exactly one LCP candidate');
        $this->assertSame(1, preg_match_all('#<img src="[^"]*p1-1\.jpg\?v=\d+"[^>]*fetchpriority="high"#', $html), 'and it is the first photo, present in the HTML (not built by JavaScript)');
        $this->assertMatchesRegularExpression('#<img src="[^"]*p1-2\.jpg\?v=\d+"[^>]*loading="lazy"#', $html);
    }

    public function test_the_hero_image_is_the_priority_image_on_the_home_page(): void
    {
        $this->storeImage('banners/h.jpg');
        \App\Models\Banner::create(['placement' => 'hero', 'title' => 'Hello', 'image' => 'banners/h.jpg', 'is_active' => true]);
        HomeSection::create(['type' => 'hero', 'is_active' => true, 'sort_order' => 1]);

        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('#<img src="[^"]*banners/h\.jpg\?v=\d+"[^>]*fetchpriority="high"#', $html);
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
    }

    public function test_first_row_of_shop_cards_load_eagerly_and_the_rest_lazily(): void
    {
        $cat = Category::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        foreach (range(1, 6) as $i) {
            $this->richProduct($cat, $i);
            $this->storeImage("products/p{$i}-1.jpg", 800, 1000);
        }

        $html = $this->get('/shop')->getContent();
        preg_match_all('#<img src="[^"]*products/p\d-1\.jpg(?:\?v=\d+)?"[^>]*>#', $html, $m);

        $eager = array_filter($m[0], fn ($t) => str_contains($t, 'loading="eager"'));
        $this->assertCount(4, $eager);
        $this->assertCount(2, array_filter($m[0], fn ($t) => str_contains($t, 'loading="lazy"')));
    }

    /* ---------------- database efficiency ---------------- */

    public function test_page_query_counts_do_not_grow_with_the_size_of_the_catalogue(): void
    {
        $cat = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        $col = Collection::create(['name' => 'Edit', 'slug' => 'edit', 'is_active' => true, 'is_trending' => true]);
        HomeSection::create(['type' => 'products', 'settings' => ['source' => 'featured', 'limit' => 12], 'is_active' => true, 'sort_order' => 1]);
        HomeSection::create(['type' => 'products', 'settings' => ['source' => 'bestsellers', 'limit' => 12], 'is_active' => true, 'sort_order' => 2]);
        HomeSection::create(['type' => 'categories', 'settings' => ['limit' => 4], 'is_active' => true, 'sort_order' => 3]);
        $cat->update(['is_featured' => true]);

        $seed = function (int $from, int $to) use ($cat, $col) {
            for ($i = $from; $i <= $to; $i++) {
                $p = $this->richProduct($cat, $i, $i % 3 === 0);
                $col->products()->syncWithoutDetaching([$p->id]);
            }
        };
        $urls = ['/', '/shop', '/shop?sort=price_asc&min=10&max=99999', '/category/shirts', '/collections/edit'];

        $seed(1, 3);
        $small = array_map(fn ($u) => $this->countQueries($u), $urls);
        $seed(4, 30);
        $large = array_map(fn ($u) => $this->countQueries($u), $urls);

        foreach ($urls as $k => $u) {
            $this->assertLessThanOrEqual($small[$k], $large[$k], "{$u}: queries grew from {$small[$k]} to {$large[$k]} as products went from 3 to 30 (N+1)");
            $this->assertLessThanOrEqual(30, $large[$k], "{$u} uses {$large[$k]} queries");
        }

        // "Related products" are picked at random, so compare like with like: a category holding only simple products.
        $plain = Category::create(['name' => 'Plain', 'slug' => 'plain', 'is_active' => true]);
        $target = $this->richProduct($plain, 500);
        foreach (range(501, 506) as $i) {
            $this->richProduct($plain, $i);
        }
        $one = $this->countQueries('/products/'.$target->slug);
        foreach (range(600, 630) as $i) {
            $this->richProduct($plain, $i);
        }
        $this->assertSame($one, $this->countQueries('/products/'.$target->slug), 'the product page must not scale with the number of other products');
    }

    public function test_the_product_page_does_not_load_every_review(): void
    {
        $p = $this->product();
        foreach (range(1, 40) as $i) {
            \App\Models\Review::create(['product_id' => $p->id, 'name' => 'R'.$i, 'rating' => 4, 'body' => 'Good '.$i, 'is_approved' => true]);
        }

        $html = $this->get('/products/'.$p->slug)->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'data-review'), 'only one page of reviews is rendered');
        $this->assertStringContainsString('40 reviews', $html, 'the count and average still cover all of them');
        $this->assertStringContainsString('reviews_page=2', $html);
        $this->assertSame(10, substr_count($this->get('/products/'.$p->slug.'?reviews_page=4')->getContent(), 'data-review'));
        $this->assertMatchesRegularExpression('/text-5xl[^>]*>4</', $html, 'average of all 40 reviews, not just the page shown');

        $queries = function () use ($p) {
            $this->get('/products/'.$p->slug)->assertOk();                  // warm the caches first (saving a review clears them)
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/products/'.$p->slug)->assertOk();

            return count(DB::getQueryLog());
        };
        $before = $queries();
        foreach (range(41, 120) as $i) {
            \App\Models\Review::create(['product_id' => $p->id, 'name' => 'R'.$i, 'rating' => 5, 'body' => 'More '.$i, 'is_approved' => true]);
        }
        $this->assertSame($before, $queries(), 'query count is independent of the number of reviews');
    }

    public function test_listing_pages_are_paginated_and_each_page_holds_a_fixed_number_of_products(): void
    {
        config(['abiha.pagination.products' => 5]);
        $cat = Category::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        foreach (range(1, 12) as $i) {
            $this->richProduct($cat, $i);
        }

        $page1 = $this->get('/shop')->getContent();
        $page3 = $this->get('/shop?page=3')->getContent();

        $this->assertSame(5, substr_count($page1, '<article class="product-card'));
        $this->assertSame(2, substr_count($page3, '<article class="product-card'));
        $this->assertStringContainsString('?page=2', $page1);
        $this->assertNotSame(
            preg_match_all('#/products/(rich-\d+)#', $page1, $a) ? array_unique($a[1]) : [],
            preg_match_all('#/products/(rich-\d+)#', $page3, $b) ? array_unique($b[1]) : []
        );
    }

    public function test_foreign_keys_and_filter_columns_are_indexed(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('MySQL indexes foreign keys automatically; SQLite does not. Verified in the MySQL run.');
        }
        $indexed = fn (string $table, array $cols) => collect(DB::select($this->indexSql($table)))->contains(fn ($row) => $this->indexCovers($row, $cols));

        $this->assertTrue($indexed('product_images', ['product_id']));
        $this->assertTrue($indexed('product_variants', ['product_id']));
        $this->assertTrue($indexed('order_items', ['order_id']));
        $this->assertTrue($indexed('products', ['category_id']));
        $this->assertTrue($indexed('reviews', ['product_id']));
    }

    private function indexSql(string $table): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "SELECT name, (SELECT group_concat(name) FROM pragma_index_info(il.name)) AS cols FROM pragma_index_list('{$table}') il"
            : "SELECT index_name AS name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$table}' GROUP BY index_name";
    }

    private function indexCovers($row, array $cols): bool
    {
        return str_starts_with((string) (((array) $row)['cols'] ?? ''), implode(',', $cols));
    }

    /* ---------------- caching ---------------- */

    public function test_storefront_data_is_cached_and_a_catalogue_change_refreshes_it_at_once(): void
    {
        $cat = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        HomeSection::create(['type' => 'products', 'settings' => ['source' => 'featured', 'limit' => 8], 'is_active' => true, 'sort_order' => 1]);
        $first = $this->richProduct($cat, 1);

        DB::enableQueryLog();
        $this->get('/')->assertSee('Rich 1');
        $cold = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->get('/')->assertSee('Rich 1');
        $warm = count(DB::getQueryLog());
        $this->assertLessThan($cold, $warm, "the warm request ({$warm}) must need fewer queries than the cold one ({$cold})");

        $second = $this->richProduct($cat, 2);                       // admin adds a product: no waiting for a timer
        $this->get('/')->assertSee('Rich 2');

        $second->update(['is_active' => false]);                      // admin hides it: it disappears immediately
        $this->get('/')->assertDontSee('Rich 2');

        $cat->update(['name' => 'Renamed Category']);
        $this->get('/')->assertSee('Renamed Category');
    }

    public function test_stock_changes_through_the_inventory_ledger_refresh_the_cached_pages(): void
    {
        $cat = Category::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        HomeSection::create(['type' => 'products', 'settings' => ['source' => 'featured', 'limit' => 8], 'is_active' => true, 'sort_order' => 1]);
        $p = $this->richProduct($cat, 1);
        $p->update(['stock' => 1]);

        $this->get('/')->assertDontSee('Sold out');
        app(\App\Services\Inventory\InventoryService::class)->adjust($p->fresh(), -1, 'sale');

        $this->get('/')->assertSee('Sold out');
    }

    public function test_carts_and_prices_are_never_served_from_the_shared_cache(): void
    {
        $p = $this->product(['price' => 1000]);
        $this->shipping();
        $this->addToCart($p, null, 2)->assertSuccessful();
        $this->get('/cart')->assertSee('2,000');

        $p->update(['price' => 1500]);                                // a price change must show in the bag immediately

        $this->get('/cart')->assertSee('3,000')->assertDontSee('2,000');
    }

    /* ---------------- assets & delivery ---------------- */

    public function test_built_assets_are_minified_and_fonts_are_preloaded(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! file_exists($manifest)) {
            $this->markTestSkipped('Run `npm run build` first.');
        }
        foreach (glob(public_path('build/assets/*.{js,css}'), GLOB_BRACE) as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString('sourceMappingURL', $src, basename($file).' must not ship a source map reference');
            $lines = max(1, substr_count($src, "\n"));
            $this->assertGreaterThan(500, strlen($src) / $lines, basename($file).' looks unminified (short lines)');
        }

        $html = $this->get('/')->getContent();
        $this->assertSame(2, preg_match_all('#<link rel="preload" href="[^"]+\.woff2" as="font" type="font/woff2" crossorigin>#', $html), 'both variable fonts are preloaded (crossorigin is required for fonts)');
    }

    public function test_pages_load_no_third_party_assets_and_scripts_do_not_block_rendering(): void
    {
        $html = $this->get('/')->getContent();
        $host = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('#<(?:script|link|img)\b[^>]*(?:src|href)="(https?://[^"]+)"#', $html, $m);
        foreach ($m[1] as $url) {
            $this->assertSame($host, parse_url($url, PHP_URL_HOST), "third-party request: {$url}");
        }
        preg_match_all('#<script\b[^>]*\bsrc="[^"]+"[^>]*>#', $html, $scripts);
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('type="module"', $tag, 'module scripts are deferred by the browser');
        }
    }

    public function test_the_web_server_config_compresses_and_caches_static_files(): void
    {
        $htaccess = File::get(public_path('.htaccess'));

        $this->assertStringContainsString('mod_deflate', $htaccess);
        $this->assertStringContainsString('text/css', $htaccess);
        $this->assertStringContainsString('application/javascript', $htaccess);
        $this->assertMatchesRegularExpression('#build/assets/.*\s+Header set Cache-Control "public, max-age=31536000, immutable"#s', $htaccess);
        $this->assertStringContainsString('/storage/', $htaccess);
        $this->assertStringNotContainsString('no-cache', $htaccess);
    }

    public function test_private_pages_stay_uncacheable_while_the_sitemap_and_robots_are_cacheable(): void
    {
        $this->assertStringContainsString('no-store', $this->get('/cart')->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $this->get('/sitemap.xml')->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $this->get('/robots.txt')->headers->get('Cache-Control'));
    }

    public function test_layout_has_no_render_blocking_inline_work_and_pages_stay_small(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertLessThan(150_000, strlen($html), 'an empty home page should be well under 150 KB of HTML');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $inline);
        foreach ($inline[1] as $js) {
            $this->assertLessThan(1500, strlen($js), 'inline scripts stay tiny; the logic lives in the cached bundle');
        }
    }
}
