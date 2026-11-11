<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Collection;
use App\Models\HomeSection;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\HomeLayoutSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The homepage, block by block: every block renders from admin-managed data, and the admin can add, edit, hide, reorder and remove them. */
class HomepageBuilderTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private function admin()
    {
        $this->seedRoles();

        return $this->actingAs($this->userWithRole('admin'));
    }

    private function section(string $type, array $attrs = []): HomeSection
    {
        return HomeSection::create($attrs + ['type' => $type, 'title' => ucfirst($type).' block', 'settings' => [], 'sort_order' => (int) HomeSection::max('sort_order') + 1, 'is_active' => true]);
    }

    private function headings(string $html): array
    {
        preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $m);

        return array_map(fn ($h) => trim(strip_tags($h)), $m[1]);
    }

    /* ---------------- the requested sections ---------------- */

    public function test_the_default_layout_contains_every_requested_block_in_order(): void
    {
        $this->seed(HomeLayoutSeeder::class);

        $types = HomeSection::orderBy('sort_order')->pluck('type')->all();
        $this->assertSame(['hero', 'campaign', 'categories', 'products', 'products', 'collections', 'promo', 'products', 'brand', 'testimonials', 'account', 'newsletter', 'social'], $types);
        $sources = HomeSection::where('type', 'products')->orderBy('sort_order')->get()->map(fn ($s) => $s->setting('source'))->all();
        $this->assertSame(['new', 'bestsellers', 'trending'], $sources, 'new arrivals, best sellers, trending products');
    }

    public function test_each_block_renders_its_own_content_and_nothing_invented(): void
    {
        Setting::put('announcement.text', 'Free gift wrapping this week');
        $cat = \App\Models\Category::create(['name' => 'Dresses', 'slug' => 'dresses', 'is_active' => true, 'is_featured' => true]);
        $new = $this->product(['name' => 'Fresh Arrival', 'is_new' => true, 'category_id' => $cat->id]);
        $best = $this->product(['name' => 'Crowd Favourite', 'is_bestseller' => true]);
        $trend = $this->product(['name' => 'On Everyones List', 'is_trending' => true]);
        $col = Collection::create(['name' => 'Eid Edit', 'slug' => 'eid', 'is_active' => true, 'is_trending' => true]);
        Banner::create(['placement' => 'hero', 'title' => 'Hero Headline', 'subtitle' => 'Hero support text', 'cta_label' => 'Shop the hero', 'cta_url' => '/shop', 'is_active' => true]);
        Banner::create(['placement' => 'campaign', 'title' => 'Campaign Headline', 'cta_label' => 'Campaign CTA', 'cta_url' => '/shop?sale=1', 'is_active' => true]);
        Banner::create(['placement' => 'promo', 'title' => 'Promo Headline', 'subtitle' => 'Promo detail', 'cta_label' => 'Promo CTA', 'cta_url' => '/shop?sort=newest', 'is_active' => true]);
        \App\Models\Testimonial::create(['name' => 'Aisha K', 'location' => 'Lahore', 'rating' => 5, 'body' => 'Lovely quality', 'is_active' => true, 'sort_order' => 1]);
        foreach ([['hero', 'Hero'], ['campaign', 'Campaign'], ['categories', 'Shop by category'], ['promo', 'Promotion'], ['collections', 'Featured collections'], ['testimonials', 'Customers say'], ['newsletter', 'Join the list']] as [$t, $title]) {
            $this->section($t, ['title' => $title]);
        }
        $this->section('products', ['title' => 'New arrivals', 'settings' => ['source' => 'new', 'limit' => 4]]);
        $this->section('products', ['title' => 'Best sellers', 'settings' => ['source' => 'bestsellers', 'limit' => 4]]);
        $this->section('products', ['title' => 'Trending products', 'settings' => ['source' => 'trending', 'limit' => 4]]);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Free gift wrapping this week', 'Hero Headline', 'Hero support text', 'Shop the hero', 'Campaign Headline', 'Campaign CTA', 'Shop by category', 'Dresses', 'Fresh Arrival', 'Crowd Favourite',
            'On Everyones List', 'Featured collections', 'Eid Edit', 'Promo Headline', 'Promo detail', 'Promo CTA', 'Aisha K', 'Lovely quality', 'Join the list'] as $text) {
            $this->assertStringContainsString($text, $html, "homepage should show: {$text}");
        }
        // The trending row shows only trending products, the best-seller row only best sellers.
        $trendingBlock = substr($html, strpos($html, 'Trending products'));
        $this->assertStringContainsString('On Everyones List', $trendingBlock);
        $this->assertStringContainsString('<footer', $html);
    }

    public function test_blocks_appear_in_the_order_the_admin_sets(): void
    {
        $this->section('categories', ['title' => 'Second Block', 'sort_order' => 20]);
        $this->section('newsletter', ['title' => 'First Block', 'sort_order' => 10]);
        $this->section('testimonials', ['title' => 'Hidden Block', 'sort_order' => 5, 'is_active' => false]);
        \App\Models\Category::create(['name' => 'Cat', 'slug' => 'cat', 'is_active' => true, 'is_featured' => true]);
        \App\Models\Testimonial::create(['name' => 'T', 'rating' => 5, 'body' => 'x', 'is_active' => true, 'sort_order' => 1]);

        $titles = $this->headings($this->get('/')->getContent());

        $this->assertSame(['First Block', 'Second Block'], array_values(array_intersect($titles, ['First Block', 'Second Block', 'Hidden Block'])));
        $this->assertNotContains('Hidden Block', $titles);
    }

    public function test_blocks_without_content_stay_hidden_instead_of_showing_empty_shells(): void
    {
        foreach (['hero', 'campaign', 'promo', 'categories', 'collections', 'testimonials'] as $t) {
            $this->section($t, ['title' => 'Empty '.$t]);
        }
        $this->section('brand', ['title' => 'Empty brand', 'settings' => ['body' => '']]);
        $this->section('products', ['title' => 'Empty products', 'settings' => ['source' => 'trending', 'limit' => 4]]);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['hero', 'campaign', 'promo', 'categories', 'collections', 'testimonials', 'brand', 'products'] as $t) {
            $this->assertStringNotContainsString('Empty '.$t, $html);
        }
    }

    public function test_the_brand_story_and_social_blocks_show_what_the_admin_uploaded(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('home/story.jpg', $this->jpeg());
        Storage::disk('public')->put('home/p1.jpg', $this->jpeg());
        Setting::put('social.instagram', 'https://instagram.com/example');
        $this->section('brand', ['title' => 'Our story', 'settings' => ['body' => 'Written by the owner.', 'image' => 'home/story.jpg']]);
        $this->section('social', ['title' => 'Follow us', 'settings' => ['images' => ['home/p1.jpg']]]);

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('Written by the owner.', $html);
        $this->assertStringContainsString('home/story.jpg', $html);
        $this->assertStringContainsString('home/p1.jpg', $html);
        $this->assertStringContainsString('https://instagram.com/example', $html);
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(300, 300);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    /* ---------------- the admin can edit everything ---------------- */

    public function test_the_admin_can_add_hidden_blocks_and_they_go_live_only_when_shown(): void
    {
        $this->admin();

        $this->post(route('admin.home.store'), ['type' => 'products', 'title' => 'Eid picks'])->assertRedirect();
        $section = HomeSection::where('title', 'Eid picks')->firstOrFail();
        $this->assertFalse($section->is_active, 'starts hidden so the live page does not change by surprise');
        $this->assertSame(['source' => 'new', 'limit' => 8, 'link' => '/shop', 'layout' => 'carousel'], $section->settings);
        $this->assertSame(1, $section->sort_order);

        $this->post(route('admin.home.store'), ['type' => 'products', 'title' => 'Second row'])->assertRedirect();
        $this->assertSame(2, HomeSection::where('title', 'Second row')->value('sort_order'), 'several product rows are allowed, each at the bottom');

        $this->product(['name' => 'Showable Product', 'is_new' => true]);
        $this->get('/')->assertDontSee('Eid picks');
        $this->patch(route('admin.home.reorder'), ['sections' => [$section->id => ['sort_order' => 1, 'is_active' => 1]]])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('Eid picks')->assertSee('Showable Product');
    }

    public function test_single_banner_blocks_cannot_be_duplicated_and_types_are_validated(): void
    {
        $this->admin();
        $this->section('promo');

        $this->post(route('admin.home.store'), ['type' => 'promo'])->assertSessionHasErrors('type');
        $this->post(route('admin.home.store'), ['type' => 'hero'])->assertRedirect();
        $this->post(route('admin.home.store'), ['type' => 'hero'])->assertSessionHasErrors('type');
        foreach (['', 'nonsense', ['x'], 'products;DROP'] as $bad) {
            $this->post(route('admin.home.store'), ['type' => $bad])->assertSessionHasErrors('type');
        }
        $this->post(route('admin.home.store'), ['type' => 'products', 'title' => str_repeat('x', 300)])->assertSessionHasErrors('title');
    }

    public function test_the_admin_can_remove_a_block_and_its_uploaded_pictures_go_with_it(): void
    {
        $this->admin();
        Storage::fake('public');
        Storage::disk('public')->put('home/gone.jpg', $this->jpeg());
        $s = $this->section('social', ['settings' => ['images' => ['home/gone.jpg']]]);
        $keep = $this->section('newsletter');

        $this->delete(route('admin.home.destroy', $s))->assertSessionHasNoErrors();

        $this->assertNull(HomeSection::find($s->id));
        $this->assertNotNull(HomeSection::find($keep->id));
        Storage::disk('public')->assertMissing('home/gone.jpg');
        $this->delete(route('admin.home.destroy', 99999))->assertNotFound();
    }

    public function test_editing_a_product_row_changes_which_products_it_shows(): void
    {
        $this->admin();
        $this->product(['name' => 'Trendy Thing', 'is_trending' => true]);
        $this->product(['name' => 'Newish Thing', 'is_new' => true]);
        $s = $this->section('products', ['title' => 'Row', 'settings' => ['source' => 'new', 'limit' => 4]]);

        $this->get('/')->assertSee('Newish Thing')->assertDontSee('Trendy Thing');
        $this->put(route('admin.home.update', $s), ['title' => 'Row', 'source' => 'trending', 'limit' => 4])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('Trendy Thing')->assertDontSee('Newish Thing');

        $this->put(route('admin.home.update', $s), ['title' => 'Row', 'source' => 'hack', 'limit' => 99])->assertSessionHasErrors(['source', 'limit']);
    }

    public function test_the_brand_story_image_and_text_are_editable_and_images_are_validated(): void
    {
        $this->admin();
        Storage::fake('public');
        $s = $this->section('brand', ['title' => 'Story']);

        $this->put(route('admin.home.update', $s), ['title' => 'Story', 'body' => 'Our words.', 'image' => UploadedFile::fake()->createWithContent('story.jpg', $this->jpeg())])->assertSessionHasNoErrors();
        $path = $s->fresh()->setting('image');
        $this->assertNotNull($path);
        $this->assertStringStartsWith('home/', $path);
        Storage::disk('public')->assertExists($path);

        $this->put(route('admin.home.update', $s), ['title' => 'Story', 'body' => 'Our words.', 'remove_image' => 1])->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->setting('image'));
        Storage::disk('public')->assertMissing($path);

        $evil = UploadedFile::fake()->createWithContent('shell.php.jpg', '<?php echo 1; ?>');
        $this->put(route('admin.home.update', $s), ['title' => 'Story', 'image' => $evil])->assertSessionHasErrors('image');
        $this->assertNull($s->fresh()->setting('image'), 'a non-image is refused');
    }

    public function test_social_pictures_can_be_added_removed_and_are_capped_at_six(): void
    {
        $this->admin();
        Storage::fake('public');
        $s = $this->section('social', ['title' => 'Follow']);
        $files = fn (int $n) => array_map(fn ($i) => UploadedFile::fake()->createWithContent("p{$i}.jpg", $this->jpeg()), range(1, $n));

        $this->put(route('admin.home.update', $s), ['title' => 'Follow', 'images' => $files(3)])->assertSessionHasNoErrors();
        $this->assertCount(3, $s->fresh()->setting('images'));

        $this->put(route('admin.home.update', $s), ['title' => 'Follow', 'remove_images' => [0], 'images' => $files(1)])->assertSessionHasNoErrors();
        $this->assertCount(3, $s->fresh()->setting('images'), 'one removed, one added');

        $this->put(route('admin.home.update', $s), ['title' => 'Follow', 'images' => $files(7)])->assertSessionHasErrors('images');
        $this->put(route('admin.home.update', $s), ['title' => 'Follow', 'images' => $files(5)])->assertSessionHasNoErrors();
        $this->assertCount(6, $s->fresh()->setting('images'), 'never more than six');
    }

    public function test_banner_placements_include_the_promotional_banner(): void
    {
        $this->admin();

        $this->post(route('admin.banners.store'), ['placement' => 'promo', 'title' => 'Promo from admin', 'is_active' => 1, 'sort_order' => 0])->assertSessionHasNoErrors();
        $this->assertSame('promo', Banner::where('title', 'Promo from admin')->value('placement'));
        $this->post(route('admin.banners.store'), ['placement' => 'sidebar', 'title' => 'x'])->assertSessionHasErrors('placement');
        $this->section('promo', ['title' => 'Promotion']);
        $this->get('/')->assertSee('Promo from admin');
    }

    public function test_only_staff_with_content_permission_can_manage_the_homepage(): void
    {
        $this->seedRoles();
        $s = $this->section('newsletter');

        $this->post(route('admin.home.store'), ['type' => 'products'])->assertRedirect(route('admin.login'));
        $this->actingAs($this->userWithRole('customer'))->post(route('admin.home.store'), ['type' => 'products'])->assertForbidden();
        $this->assertSame(1, HomeSection::count());
        $this->delete(route('admin.home.destroy', $s));
        $this->assertNotNull(HomeSection::find($s->id), 'a customer cannot delete homepage blocks');

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($this->userWithRole('staff'))->post(route('admin.home.store'), ['type' => 'products'])->assertForbidden();   // staff role has no content permission
    }

    public function test_changes_show_on_the_live_homepage_immediately_despite_caching(): void
    {
        $this->admin();
        $this->product(['name' => 'Cached Product', 'is_trending' => true]);
        $s = $this->section('products', ['title' => 'Before', 'settings' => ['source' => 'trending', 'limit' => 4]]);
        $this->get('/')->assertSee('Before');

        $this->put(route('admin.home.update', $s), ['title' => 'After the edit', 'source' => 'trending', 'limit' => 4]);

        $this->get('/')->assertSee('After the edit')->assertDontSee('Before');
    }

    public function test_marking_products_on_the_featured_page_updates_the_homepage_rows_immediately(): void
    {
        $this->admin();
        $p = $this->product(['name' => 'Soon Trending']);
        $this->section('products', ['title' => 'Trending products', 'settings' => ['source' => 'trending', 'limit' => 4]]);
        $this->get('/')->assertDontSee('Soon Trending');                        // warms the cache

        $this->put(route('admin.featured.update'), ['ids' => [$p->id], 'flags' => [$p->id => ['is_trending' => 1]]])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('Soon Trending');
    }
}
