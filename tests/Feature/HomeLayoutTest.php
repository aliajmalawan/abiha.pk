<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Collection;
use App\Models\HomeSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The boutique home layout: banner, category tiles, product rows (carousel / grid / shop the look) and collection tabs. */
class HomeLayoutTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HomeSection::query()->delete();
    }

    private function block(string $type, array $settings = [], string $title = 'Block'): HomeSection
    {
        return HomeSection::create(['type' => $type, 'title' => $title, 'settings' => $settings, 'sort_order' => (int) HomeSection::max('sort_order') + 1, 'is_active' => true]);
    }

    private function home(): string
    {
        return $this->get(route('home'))->assertOk()->getContent();
    }

    public function test_the_carousel_is_the_default_product_layout_and_every_card_is_a_link(): void
    {
        $this->product(['name' => 'Rail Kurta', 'is_new' => true]);
        $this->block('products', ['source' => 'new', 'limit' => 8], 'New Arrivals');

        $html = $this->home();
        $this->assertStringContainsString('data-reveal-group', $html);
        $this->assertStringContainsString('aria-label="Next"', $html);                   // arrows for the carousel
        $this->assertStringContainsString('snap-x', $html);
        $this->assertStringContainsString('Rail Kurta', $html);
        $this->assertStringContainsString(route('product', \App\Models\Product::firstWhere('name', 'Rail Kurta')), $html);
    }

    public function test_the_grid_layout_has_no_carousel_arrows(): void
    {
        $this->product(['name' => 'Grid Kurta', 'is_new' => true]);
        $this->block('products', ['source' => 'new', 'layout' => 'grid'], 'Grid row');

        $html = $this->home();
        $this->assertStringContainsString('Grid Kurta', $html);
        $this->assertStringNotContainsString('aria-label="Next"', $html);
        $this->assertStringContainsString('lg:grid-cols-4', $html);
    }

    public function test_shop_the_look_shows_tall_pictures_with_name_and_price(): void
    {
        $this->product(['name' => 'Look Kurta', 'price' => 6500, 'is_featured' => true]);
        $this->block('products', ['source' => 'featured', 'layout' => 'lookbook'], 'Shop the look');

        $html = $this->home();
        $this->assertStringContainsString('home-look', $html);
        $this->assertStringContainsString('Look Kurta', $html);
        $this->assertStringContainsString('PKR 6,500', $html);
    }

    public function test_collection_tabs_list_only_real_collections_that_have_products(): void
    {
        $a = $this->product(['name' => 'Tab Alpha', 'is_new' => true]);
        $b = $this->product(['name' => 'Tab Beta', 'is_new' => true]);
        $c = $this->product(['name' => 'Tab Plain', 'is_new' => true]);
        $one = Collection::create(['name' => 'Siyaah Edit', 'slug' => 'siyaah-edit', 'is_active' => true]);
        $two = Collection::create(['name' => 'Roselle Edit', 'slug' => 'roselle-edit', 'is_active' => true]);
        Collection::create(['name' => 'Empty Edit', 'slug' => 'empty-edit', 'is_active' => true]);          // no products: no tab
        $one->products()->attach($a->id);
        $two->products()->attach($b->id);
        $this->block('products', ['source' => 'new', 'tabs' => true], 'New Arrivals');

        $html = $this->home();
        $this->assertStringContainsString('role="tablist"', $html);
        foreach (['>All<', '>Siyaah Edit<', '>Roselle Edit<'] as $tab) {
            $this->assertStringContainsString($tab, $html);
        }
        $this->assertStringNotContainsString('Empty Edit', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString(route('collection', $one), $html);                               // "View all" of a tab goes to that collection
    }

    public function test_tabs_are_not_shown_when_there_is_nothing_to_tab_between(): void
    {
        $this->product(['name' => 'Solo', 'is_new' => true]);
        $this->block('products', ['source' => 'new', 'tabs' => true], 'New Arrivals');
        $this->assertStringNotContainsString('role="tablist"', $this->home());                           // no collections: no tabs
    }

    public function test_category_tiles_link_to_the_category_with_a_shop_now_link(): void
    {
        $cat = Category::create(['name' => 'Formal Wear', 'slug' => 'formal-wear', 'is_active' => true, 'is_featured' => true]);
        $this->product(['category_id' => $cat->id]);
        $this->block('categories', ['limit' => 4], 'Shop by category');

        $html = $this->home();
        $this->assertStringContainsString('Formal Wear', $html);
        $this->assertStringContainsString('Shop now', $html);
        $this->assertStringContainsString(route('category', $cat), $html);
    }

    public function test_the_hero_is_a_full_width_banner_with_the_admin_text_and_one_button(): void
    {
        Banner::create(['placement' => 'hero', 'title' => 'Siyaah Collection', 'subtitle' => 'Dark and elegant.', 'cta_label' => 'Shop now', 'cta_url' => '/shop', 'is_active' => true, 'sort_order' => 1]);
        $this->block('hero');

        $html = $this->home();
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        $this->assertStringContainsString('Siyaah Collection', $html);
        $this->assertStringContainsString('min-h-[440px]', $html);
        $this->assertStringContainsString('home-btn', $html);
        $this->assertStringNotContainsString('home-arch', $html);                                         // the arch showcase of the previous theme is gone
    }

    public function test_the_home_page_uses_the_standard_white_header_and_no_invented_trust_card(): void
    {
        $this->block('products', ['source' => 'latest'], 'Latest');
        $this->product();
        $html = $this->home();
        $this->assertStringNotContainsString('header--dark', $html);
        $this->assertStringNotContainsString('home-trust', $html);
    }

    public function test_a_banner_that_already_has_its_own_design_is_shown_whole_with_only_the_button_over_it(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('banners/designed.png', (function () {
            $im = imagecreatetruecolor(1909, 824);
            ob_start();
            imagepng($im);

            return ob_get_clean();
        })());
        Banner::create(['placement' => 'hero', 'title' => 'Premium Fitness Wear', 'subtitle' => 'Should not be printed over the picture', 'cta_label' => 'Shop now', 'cta_url' => '/shop',
            'image' => 'banners/designed.png', 'show_text' => false, 'is_active' => true, 'sort_order' => 1]);
        $this->block('hero');

        $html = $this->home();
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), 'the headline is still the page heading, for search engines and screen readers');
        $this->assertStringContainsString('<h1 class="sr-only">Premium Fitness Wear</h1>', $html);
        $this->assertStringNotContainsString('bg-black/40', $html, 'no dark veil over a finished design');
        $this->assertStringNotContainsString('min-h-[440px]', $html, 'not forced into a fixed-height crop');
        $this->assertStringNotContainsString('Should not be printed over the picture', $html);
        $this->assertStringContainsString('w-full h-auto', $html);                                      // natural proportions
        $this->assertMatchesRegularExpression('#<a href="[^"]*/shop" class="block" aria-label="Shop now">#', $html);   // the picture is the link
        $this->assertStringContainsString('>Shop now</span>', $html);                                  // and the button is drawn over it
    }

    public function test_a_banner_with_a_plain_photo_shows_its_text_in_a_card_over_the_picture_by_default(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('banners/plain.png', (function () {
            $im = imagecreatetruecolor(1600, 700);
            ob_start();
            imagepng($im);

            return ob_get_clean();
        })());
        $b = Banner::create(['placement' => 'hero', 'title' => 'Plain Photo Banner', 'subtitle' => 'Printed over it.', 'image' => 'banners/plain.png', 'is_active' => true, 'sort_order' => 1]);
        $this->assertTrue($b->fresh()->show_text, 'new banners print their text over the picture unless told otherwise');
        $this->block('hero');

        $html = $this->home();
        $this->assertStringContainsString('hero-card', $html, 'the words sit in a white card');
        $this->assertStringContainsString('Printed over it.', $html);
        $this->assertStringContainsString('Plain Photo Banner</h1>', $html);
        $this->assertStringNotContainsString('bg-black/40', $html, 'the picture is never darkened');
        $this->assertStringNotContainsString('min-h-[440px]', $html, 'and never cropped to a fixed height');
        $this->assertStringNotContainsString('<h1 class="sr-only">', $html);
    }

    public function test_the_banner_form_has_the_option_and_saves_it(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $data = ['placement' => 'hero', 'title' => 'Designed banner', 'sort_order' => 1, 'is_active' => 1];

        $this->actingAs($admin)->get(route('admin.banners.create'))->assertOk()->assertSee('Show the headline, text and button on top of the picture');
        $this->post(route('admin.banners.store'), $data)->assertSessionHasNoErrors();                          // box left unticked
        $this->assertFalse(Banner::where('title', 'Designed banner')->firstOrFail()->show_text);

        $banner = Banner::where('title', 'Designed banner')->firstOrFail();
        $this->put(route('admin.banners.update', $banner), $data + ['show_text' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($banner->fresh()->show_text);
    }

    /* ---------- admin ---------- */

    public function test_the_admin_can_choose_the_layout_and_tabs_and_bad_layouts_are_refused(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $s = $this->block('products', ['source' => 'new', 'limit' => 8, 'layout' => 'carousel'], 'New Arrivals');

        $this->actingAs($admin)->put(route('admin.home.update', $s), ['title' => 'New Arrivals', 'source' => 'new', 'limit' => 8, 'layout' => 'lookbook', 'tabs' => 1])->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame('lookbook', $s->setting('layout'));
        $this->assertTrue((bool) $s->setting('tabs'));

        $this->put(route('admin.home.update', $s), ['title' => 'New Arrivals', 'source' => 'new', 'layout' => 'lookbook'])->assertSessionHasNoErrors();   // box unticked
        $this->assertFalse((bool) $s->fresh()->setting('tabs'));

        $this->put(route('admin.home.update', $s), ['title' => 'x', 'source' => 'new', 'layout' => '<script>'])->assertSessionHasErrors('layout');
        $this->get(route('admin.home.edit', $s))->assertOk()->assertSee('Shop the look')->assertSee('Show tabs for the collections');
    }
}
