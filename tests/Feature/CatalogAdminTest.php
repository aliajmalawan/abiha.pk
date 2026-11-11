<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use Database\Seeders\AttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Admin Made', 'sku' => 'ADM-1', 'price' => 2500, 'stock' => 7, 'is_active' => 1], $over);
    }

    public function test_creating_a_simple_product_records_opening_stock_in_the_ledger(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $p = Product::where('sku', 'ADM-1')->firstOrFail();
        $this->assertSame(7, $p->stock);
        $this->assertSame('admin-made', $p->slug);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'change' => 7, 'balance_after' => 7, 'reason' => 'initial']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.created']);
    }

    public function test_editing_stock_creates_an_adjustment_movement_and_keeps_balance_consistent(): void
    {
        $p = $this->product(['stock' => 10]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $p), $this->payload(['name' => $p->name, 'sku' => $p->sku, 'price' => $p->price, 'stock' => 4]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(4, $p->fresh()->stock);
        $m = InventoryMovement::where('product_id', $p->id)->latest('id')->first();
        $this->assertSame(-6, $m->change);
        $this->assertSame(4, $m->balance_after);
        $this->assertSame('adjustment', $m->reason);
    }

    public function test_validation_rejects_bad_prices_and_duplicate_skus(): void
    {
        $this->product(['sku' => 'TAKEN']);

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['sku' => 'TAKEN']))->assertSessionHasErrors('sku');
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['price' => -5]))->assertSessionHasErrors('price');
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['price' => 100, 'compare_price' => 50]))->assertSessionHasErrors('compare_price');
        $this->assertSame(1, Product::count());
    }

    public function test_variant_product_saves_options_and_enforces_unique_combinations(): void
    {
        $size = Attribute::where('slug', 'size')->first()->values()->where('value', 'M')->first();
        $color = Attribute::where('slug', 'color')->first()->values()->where('value', 'Black')->first();

        $res = $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload([
            'has_variants' => 1,
            'variants' => [
                ['sku' => 'ADM-1-M-BK', 'stock' => 3, 'is_active' => 1, 'options' => [$size->id, $color->id]],
                ['sku' => 'ADM-1-M-BK2', 'stock' => 2, 'is_active' => 1, 'options' => [$color->id, $size->id]],   // same combination again
            ],
        ]));
        $res->assertSessionHasErrors('variants.1.options');
        $this->assertSame(0, Product::count());                                  // atomic: nothing saved

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload([
            'has_variants' => 1, 'stock' => null,
            'variants' => [['sku' => 'ADM-1-M-BK', 'stock' => 3, 'price' => 2700, 'is_active' => 1, 'options' => [$size->id, $color->id]]],
        ]))->assertSessionHasNoErrors();

        $p = Product::where('sku', 'ADM-1')->with('variants')->firstOrFail();
        $v = $p->variants->first();
        $this->assertSame('M', $v->size);
        $this->assertSame('Black', $v->color);
        $this->assertSame(2700, $v->effective_price);
        $this->assertSame(3, $v->stock);
        $this->assertSame(0, $p->stock);                                          // variant products hold no product-level stock
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $v->id, 'change' => 3, 'reason' => 'initial']);
    }

    public function test_images_are_validated_stored_and_removable(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload([
            'images' => [UploadedFile::fake()->image('a.jpg', 600, 600), UploadedFile::fake()->image('b.png', 600, 600)],
        ]))->assertSessionHasNoErrors();
        $p = Product::where('sku', 'ADM-1')->with('images')->first();
        $this->assertCount(2, $p->images);
        Storage::disk('public')->assertExists($p->images->first()->path);

        // non-images and oversized files are refused
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['sku' => 'ADM-2', 'images' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]]))->assertSessionHasErrors('images.0');

        $gone = $p->images->first();
        $this->actingAs($this->admin)->put(route('admin.products.update', $p), $this->payload(['remove_images' => [$gone->id]]))->assertSessionHasNoErrors()->assertRedirect();
        Storage::disk('public')->assertMissing($gone->path);
        $this->assertCount(1, $p->fresh()->images);
    }

    public function test_deleting_a_product_keeps_order_history(): void
    {
        $this->shipping();
        $p = $this->product(['stock' => 5]);
        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = \App\Models\Order::firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.products.destroy', $p))->assertRedirect();

        $this->assertSoftDeleted($p);
        $this->assertSame($p->name, $order->fresh()->items->first()->name);
    }

    public function test_category_tree_cannot_loop_and_used_categories_cannot_be_deleted(): void
    {
        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $b = Category::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);
        $this->product(['category_id' => $b->id]);

        // A cannot be moved under its own child
        $this->actingAs($this->admin)->put(route('admin.categories.update', $a), ['name' => 'A', 'parent_id' => $b->id])->assertSessionHasErrors('parent_id');
        $this->assertNull($a->fresh()->parent_id);

        $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $a))->assertSessionHasErrors('delete');   // has a child
        $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $b))->assertSessionHasErrors('delete');   // has a product
        $this->assertDatabaseHas('categories', ['id' => $a->id]);
    }

    public function test_inventory_adjustments_cannot_go_negative_and_are_logged(): void
    {
        $p = $this->product(['stock' => 3]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust'), ['target' => 'product', 'id' => $p->id, 'mode' => 'remove', 'quantity' => 5, 'reason' => 'adjustment'])->assertSessionHasErrors('inventory');
        $this->assertSame(3, $p->fresh()->stock);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust'), ['target' => 'product', 'id' => $p->id, 'mode' => 'add', 'quantity' => 10, 'reason' => 'restock', 'note' => 'PO 12'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.inventory.adjust'), ['target' => 'product', 'id' => $p->id, 'mode' => 'set', 'quantity' => 8, 'reason' => 'adjustment'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(8, $p->fresh()->stock);
        $this->assertSame([10, -5], InventoryMovement::where('product_id', $p->id)->orderBy('id')->pluck('change')->all());
        // the ledger always adds up to the balance
        $this->assertSame(8 - 3, (int) InventoryMovement::where('product_id', $p->id)->sum('change'));
    }

    public function test_coupon_admin_validates_percent_and_protects_redeemed_coupons(): void
    {
        $this->actingAs($this->admin)->post(route('admin.coupons.store'), ['code' => 'big', 'type' => 'percent', 'value' => 150])->assertSessionHasErrors('value');
        $this->actingAs($this->admin)->post(route('admin.coupons.store'), ['code' => 'spring', 'type' => 'percent', 'value' => 15, 'per_customer_limit' => 1, 'is_active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('coupons', ['code' => 'SPRING', 'per_customer_limit' => 1]);

        $used = $this->exhaustedCoupon('REDEEMED');
        $this->actingAs($this->admin)->delete(route('admin.coupons.destroy', $used))->assertSessionHasErrors('delete');
    }

    public function test_banner_links_must_be_site_paths_or_https_urls(): void
    {
        $this->actingAs($this->admin)->post(route('admin.banners.store'), ['placement' => 'hero', 'title' => 'x', 'cta_url' => 'javascript:alert(1)'])->assertSessionHasErrors('cta_url');
        $this->actingAs($this->admin)->post(route('admin.banners.store'), ['placement' => 'hero', 'title' => 'x', 'cta_url' => '/shop'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_pages_publish_to_the_storefront_and_unpublished_ones_are_hidden(): void
    {
        $this->actingAs($this->admin)->post(route('admin.pages.store'), ['title' => 'Our Story', 'body' => '<p>Hello</p><script>alert(1)</script>', 'is_published' => 1, 'show_in_footer' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.pages.store'), ['title' => 'Draft Page', 'body' => 'x'])->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('page', 'our-story'))->assertOk()->assertSee('Hello')->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('page', 'draft-page'))->assertNotFound();
        $this->get(route('home'))->assertSee('Our Story');
    }

    public function test_settings_are_saved_and_shown_in_the_footer(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__contact__email' => 'hello@example.com', 's__announcement__text' => 'Free gift', 's__social__instagram' => 'not a url'])
            ->assertSessionHasErrors('s__social__instagram');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__contact__email' => 'hello@example.com', 's__announcement__text' => 'Free gift'])->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('home'))->assertSee('hello@example.com')->assertSee('Free gift');
    }
}
