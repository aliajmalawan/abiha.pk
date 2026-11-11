<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Database\Seeders\AttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Admin product management: create, edit, delete and everything a product carries. */
class ProductManagementTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(AttributeSeeder::class);
        $this->admin = $this->userWithRole('admin');
        Storage::fake('public');
    }

    private function data(array $over = []): array
    {
        return array_merge(['name' => 'Silk Kurta', 'sku' => 'KUR-1', 'price' => 4500, 'stock' => 6, 'is_active' => 1], $over);
    }

    private function create(array $over = []): Product
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->data($over))->assertSessionHasNoErrors();

        return Product::where('sku', $over['sku'] ?? 'KUR-1')->with('images')->firstOrFail();
    }

    private function img(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 800);
    }

    /* ---------- create / edit / delete with every field ---------- */

    public function test_a_product_is_created_with_all_of_its_details(): void
    {
        $cat = Category::create(['name' => 'Kurtas', 'slug' => 'kurtas', 'is_active' => true]);
        $p = $this->create([
            'slug' => 'silk-kurta-gold', 'category_id' => $cat->id, 'short_description' => 'Hand finished.', 'description' => '<p>Soft <strong>silk</strong>.</p><script>alert(1)</script>',
            'price' => 4500, 'compare_price' => 6000, 'low_stock_threshold' => 2, 'badge' => 'Limited',
            'meta_title' => 'Silk Kurta | ABIHA', 'meta_description' => 'A silk kurta.',
            'is_featured' => 1, 'is_new' => 1, 'is_bestseller' => 1,
        ]);

        $this->assertSame(['silk-kurta-gold', 'KUR-1', $cat->id, 4500, 6000, 6, 2, 'Limited'], [$p->slug, $p->sku, $p->category_id, $p->price, $p->compare_price, $p->stock, $p->low_stock_threshold, $p->badge]);
        $this->assertSame(['Silk Kurta | ABIHA', 'A silk kurta.'], [$p->meta_title, $p->meta_description]);
        $this->assertTrue($p->is_active && $p->is_featured && $p->is_new && $p->is_bestseller);
        $this->assertStringNotContainsString('<script', $p->description);              // descriptions are sanitised
        $this->assertStringContainsString('<strong>silk</strong>', $p->description);
    }

    public function test_a_product_can_be_edited_and_the_changes_show_in_the_shop(): void
    {
        $p = $this->create();
        $this->put(route('admin.products.update', $p), $this->data(['name' => 'Silk Kurta Deluxe', 'price' => 5000, 'compare_price' => 7000, 'meta_title' => 'Deluxe', 'is_new' => 1]))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['Silk Kurta Deluxe', 5000, 7000, 'Deluxe', true], [$p->name, $p->price, $p->compare_price, $p->meta_title, $p->is_new]);
        $this->get(route('product', $p))->assertOk()->assertSee('Silk Kurta Deluxe')->assertSee('5,000')->assertSee('<title>Deluxe', false);
    }

    public function test_sale_pricing_needs_an_original_price_above_the_price(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->data(['price' => 5000, 'compare_price' => 4000]))->assertSessionHasErrors('compare_price');
        $this->post(route('admin.products.store'), $this->data(['price' => 5000, 'compare_price' => 5000]))->assertSessionHasErrors('compare_price');
        $this->assertSame(0, Product::count());
        $p = $this->create(['price' => 3000, 'compare_price' => 4000]);
        $this->assertSame(25, $p->discount_percent);                                      // shown as "Save 25%"
    }

    public function test_the_sku_and_slug_must_be_unique_and_the_slug_is_made_from_the_name_when_blank(): void
    {
        $a = $this->create();
        $this->post(route('admin.products.store'), $this->data(['name' => 'Other']))->assertSessionHasErrors('sku');
        $this->post(route('admin.products.store'), $this->data(['name' => 'Other', 'sku' => 'KUR-2', 'slug' => $a->slug]))->assertSessionHasErrors('slug');
        $b = $this->create(['name' => 'Silk Kurta', 'sku' => 'KUR-3']);                    // same name, blank slug → made unique
        $this->assertNotSame($a->slug, $b->slug);
        $this->post(route('admin.products.store'), $this->data(['sku' => 'KUR-4', 'slug' => 'Bad Slug!']))->assertSessionHasErrors('slug');
    }

    public function test_deleting_archives_the_product_removes_it_from_the_shop_and_keeps_orders(): void
    {
        $this->shipping(250);
        $p = $this->create(['name' => 'Doomed Kurta']);
        auth()->logout();
        $this->flushSession();
        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.products.destroy', $p))->assertRedirect(route('admin.products.index'));
        $this->assertSoftDeleted('products', ['id' => $p->id]);
        $this->get(route('product', $p->slug))->assertNotFound();
        $this->get(route('shop'))->assertDontSee('Doomed Kurta');
        $this->get(route('admin.products.index'))->assertDontSee('Doomed Kurta');
        $this->assertSame('Doomed Kurta', $order->items()->firstOrFail()->name);
    }

    public function test_visibility_featured_and_new_arrival_flags_control_where_a_product_appears(): void
    {
        $p = $this->create(['name' => 'Hidden Kurta', 'is_active' => 0]);
        $this->get(route('shop'))->assertDontSee('Hidden Kurta');
        $this->get(route('product', $p->slug))->assertNotFound();

        $this->actingAs($this->admin)->put(route('admin.products.update', $p), $this->data(['name' => 'Hidden Kurta', 'is_active' => 1, 'is_new' => 1, 'is_featured' => 1]));
        $p->refresh();
        $this->assertTrue($p->is_new && $p->is_featured && $p->is_active);
        $this->get(route('shop'))->assertSee('Hidden Kurta');
        $this->get(route('product', $p->slug))->assertOk()->assertSee('New');

        $this->put(route('admin.products.update', $p), $this->data(['name' => 'Hidden Kurta', 'is_active' => 1]));       // flags unticked
        $p->refresh();
        $this->assertFalse($p->is_new || $p->is_featured);
    }

    public function test_the_admin_list_searches_and_filters(): void
    {
        $this->create(['name' => 'Findable Kurta', 'sku' => 'FIND-1']);
        $this->create(['name' => 'Hidden Shawl', 'sku' => 'HID-1', 'is_active' => 0]);
        $this->get(route('admin.products.index', ['q' => 'Findable']))->assertSee('Findable Kurta')->assertDontSee('Hidden Shawl');
        $this->get(route('admin.products.index', ['q' => 'HID-1']))->assertSee('Hidden Shawl');                      // SKU search
        $this->get(route('admin.products.index', ['status' => 'inactive']))->assertSee('Hidden Shawl')->assertDontSee('Findable Kurta');
    }

    /* ---------- variants: size, colour, SKU, stock ---------- */

    public function test_size_and_colour_variants_keep_their_own_sku_price_and_stock_and_can_be_edited(): void
    {
        $size = fn ($v) => Attribute::where('slug', 'size')->first()->values()->where('value', $v)->firstOrFail();
        $color = fn ($v) => Attribute::where('slug', 'color')->first()->values()->where('value', $v)->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->data([
            'has_variants' => 1, 'stock' => null,
            'variants' => [
                ['sku' => 'KUR-1-S-BK', 'stock' => 4, 'is_active' => 1, 'options' => [$size('S')->id, $color('Black')->id]],
                ['sku' => 'KUR-1-L-BK', 'stock' => 9, 'price' => 4900, 'is_active' => 1, 'options' => [$size('L')->id, $color('Black')->id]],
            ],
        ]))->assertSessionHasNoErrors();
        $p = Product::where('sku', 'KUR-1')->with('allVariants')->firstOrFail();
        $this->assertCount(2, $p->allVariants);
        $s = $p->allVariants->firstWhere('sku', 'KUR-1-S-BK');
        $l = $p->allVariants->firstWhere('sku', 'KUR-1-L-BK');
        $this->assertSame([4500, 4900], [$s->effective_price, $l->effective_price]);
        $this->assertSame([4, 9], [$s->stock, $l->stock]);

        // edit one variant's stock, hide another, and remove nothing else
        $this->put(route('admin.products.update', $p), $this->data([
            'has_variants' => 1, 'stock' => null,
            'variants' => [
                ['id' => $s->id, 'sku' => 'KUR-1-S-BK', 'stock' => 10, 'is_active' => 1, 'options' => [$size('S')->id, $color('Black')->id]],
                ['id' => $l->id, 'sku' => 'KUR-1-L-BK', 'stock' => 9, 'price' => 4900, 'options' => [$size('L')->id, $color('Black')->id]],   // not available
            ],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(10, $s->fresh()->stock);
        $this->assertFalse($l->fresh()->is_active);
        $this->get(route('product', $p->slug))->assertOk();
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $s->id, 'change' => 6]);                          // +6 logged
    }

    public function test_a_variant_sku_cannot_clash_with_another_product(): void
    {
        $this->create(['sku' => 'TAKEN']);
        $v = Attribute::where('slug', 'size')->first()->values()->first();
        $this->post(route('admin.products.store'), $this->data(['sku' => 'KUR-9', 'has_variants' => 1, 'stock' => null, 'variants' => [['sku' => 'TAKEN', 'stock' => 1, 'options' => [$v->id]]]]))
            ->assertSessionHasErrors('variants.0.sku');
    }

    /* ---------- images: upload, validation, ordering ---------- */

    public function test_several_images_can_be_uploaded_at_once_and_keep_the_upload_order(): void
    {
        $p = $this->create(['images' => [$this->img('one.jpg'), $this->img('two.png'), $this->img('three.jpg')]]);
        $this->assertCount(3, $p->images);
        $this->assertSame([1, 2, 3], $p->images->pluck('sort_order')->all());
        foreach ($p->images as $i) {
            Storage::disk('public')->assertExists($i->path);
            $this->assertNotSame('one.jpg', basename($i->path));                          // random stored name
        }
    }

    public function test_bad_images_are_refused_and_nothing_is_saved(): void
    {
        $real = function (string $name, string $content) {
            $path = tempnam(sys_get_temp_dir(), 'img');
            file_put_contents($path, $content);

            return new UploadedFile($path, $name, null, null, true);
        };
        $post = fn (array $images, string $sku) => $this->actingAs($this->admin)->post(route('admin.products.store'), $this->data(['sku' => $sku, 'images' => $images]));

        $post([$real('shell.jpg', '<?php system($_GET["c"]);')], 'BAD-1')->assertSessionHasErrors('images.0');         // a script disguised as a picture
        $post([$real('page.html', '<html></html>')], 'BAD-2')->assertSessionHasErrors('images.0');
        $post([UploadedFile::fake()->image('huge.jpg')->size(5000)], 'BAD-3')->assertSessionHasErrors('images.0');       // over 4 MB
        $post(array_map(fn () => $this->img(), range(1, 13)), 'BAD-4')->assertSessionHasErrors('images');                // more than 12
        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_photo_order_can_be_changed_and_the_first_becomes_the_main_photo(): void
    {
        $p = $this->create(['images' => [$this->img('a.jpg'), $this->img('b.jpg'), $this->img('c.jpg')]]);
        [$a, $b, $c] = $p->images->all();

        $this->put(route('admin.products.update', $p), $this->data(['image_order' => [$c->id, $a->id, $b->id]]))->assertSessionHasNoErrors();
        $p = $p->fresh('images');
        $this->assertSame([$c->id, $a->id, $b->id], $p->images->pluck('id')->all());
        $this->assertSame($c->path, $p->imagePath(0));                                       // the storefront's main photo follows the order
        $this->assertSame($a->path, $p->imagePath(1));                                       // hover photo
    }

    public function test_reordering_ignores_foreign_ids_keeps_unlisted_photos_and_survives_removal(): void
    {
        $p = $this->create(['images' => [$this->img('a.jpg'), $this->img('b.jpg'), $this->img('c.jpg')]]);
        $other = $this->create(['sku' => 'KUR-2', 'images' => [$this->img('x.jpg')]]);
        [$a, $b, $c] = $p->images->all();

        $this->put(route('admin.products.update', $p), $this->data(['image_order' => [$other->images->first()->id, $c->id], 'remove_images' => [$b->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$c->id, $a->id], $p->fresh('images')->images->pluck('id')->all());     // c first, a after it, b gone
        $this->assertSame([1, 2], $p->fresh('images')->images->pluck('sort_order')->all());       // numbered without gaps
        $this->assertCount(1, $other->fresh('images')->images);                                    // the other product is untouched
        $this->assertSame(1, $other->images->first()->sort_order);
    }

    public function test_new_photos_join_after_the_existing_ones_and_the_total_is_capped_at_twelve(): void
    {
        $p = $this->create(['images' => [$this->img('a.jpg'), $this->img('b.jpg')]]);
        $first = $p->images->first();
        $this->put(route('admin.products.update', $p), $this->data(['images' => [$this->img('c.jpg')]]))->assertSessionHasNoErrors();
        $p = $p->fresh('images');
        $this->assertCount(3, $p->images);
        $this->assertSame($first->id, $p->images->first()->id);

        $this->put(route('admin.products.update', $p), $this->data(['images' => array_map(fn () => $this->img(), range(1, 10))]))->assertSessionHasErrors('images');   // 3 + 10 > 12
        $this->assertCount(3, $p->fresh('images')->images);
    }

    public function test_alt_text_is_saved_as_plain_text_and_falls_back_to_the_product_name(): void
    {
        $p = $this->create(['images' => [$this->img('a.jpg'), $this->img('b.jpg')]]);
        [$a, $b] = $p->images->all();
        $this->put(route('admin.products.update', $p), $this->data(['image_alts' => [$a->id => 'Front <b>view</b>', $b->id => '']]))->assertSessionHasNoErrors();
        $this->assertSame('Front view', $a->fresh()->alt);
        $this->assertSame('Silk Kurta', $b->fresh()->alt);
    }

    public function test_the_edit_form_shows_the_ordering_controls_and_upload_checks(): void
    {
        $p = $this->create(['images' => [$this->img('a.jpg'), $this->img('b.jpg')]]);
        $this->get(route('admin.products.edit', $p))->assertOk()
            ->assertSee('imageOrder(', false)->assertSee('name="image_order[]"', false)->assertSee('Make main')->assertSee('uploadCheck(', false);
    }

    public function test_only_staff_with_the_products_permission_can_manage_products(): void
    {
        $this->actingAs($this->userWithRole('customer'))->post(route('admin.products.store'), $this->data())->assertForbidden();
        $this->actingAs($this->userWithRole('staff'))->post(route('admin.products.store'), $this->data())->assertForbidden();         // staff process orders, not the catalogue
        $this->assertSame(0, Product::count());
    }
}
