<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The product page: specifications, size guide, and the database-driven variant data behind the size/colour selectors. */
class ProductDetailsTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private function admin()
    {
        $this->seedRoles();

        return $this->actingAs($this->userWithRole('admin'));
    }

    private function payload(Product $p, array $over = []): array
    {
        return $over + ['name' => $p->name, 'slug' => $p->slug, 'sku' => $p->sku, 'price' => $p->price, 'stock' => 5, 'is_active' => 1];
    }

    private function specs(string $html): array
    {
        preg_match_all('#<th scope="row"[^>]*>(.*?)</th><td[^>]*>(.*?)</td>#s', $html, $m);

        return array_combine(array_map('html_entity_decode', $m[1]), array_map('html_entity_decode', $m[2]));
    }

    /* ---------------- specifications ---------------- */

    public function test_staff_can_save_specifications_and_customers_see_them_as_a_table(): void
    {
        $this->admin();
        $p = $this->product(['name' => 'Linen Shirt', 'slug' => 'linen-shirt']);

        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => [
            ['label' => 'Fabric', 'value' => '100% linen'], ['label' => 'Care', 'value' => 'Machine wash cold'],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([['label' => 'Fabric', 'value' => '100% linen'], ['label' => 'Care', 'value' => 'Machine wash cold']], $p->fresh()->specifications);
        $html = $this->get('/products/linen-shirt')->getContent();
        $this->assertStringContainsString('Specifications', $html);
        $this->assertSame(['Fabric' => '100% linen', 'Care' => 'Machine wash cold'], $this->specs($html), 'rows appear in the order staff entered them');
    }

    public function test_specifications_are_cleaned_capped_and_validated(): void
    {
        $this->admin();
        $p = $this->product(['slug' => 'spec-clean']);

        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => [
            ['label' => '<b>Fit</b>', 'value' => 'Regular <script>alert(1)</script>'],
            ['label' => '', 'value' => 'orphan value is dropped'],
            ['label' => 'orphan label is dropped', 'value' => ''],
            ['label' => '  ', 'value' => '  '],
        ]]))->assertSessionHasNoErrors();

        $saved = $p->fresh()->specifications;
        $this->assertCount(1, $saved);
        $this->assertSame('Fit', $saved[0]['label']);
        $this->assertStringNotContainsString('<', $saved[0]['value']);
        $this->assertStringNotContainsString('<script', $this->get('/products/spec-clean')->getContent() === '' ? '' : implode('', array_column($saved, 'value')));

        $tooMany = array_map(fn ($i) => ['label' => "L{$i}", 'value' => "V{$i}"], range(1, 26));
        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => $tooMany]))->assertSessionHasErrors('specifications');
        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => [['label' => str_repeat('x', 61), 'value' => 'v']]]))->assertSessionHasErrors('specifications.0.label');
        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => 'not an array']))->assertSessionHasErrors('specifications');
    }

    public function test_clearing_every_row_hides_the_specifications_section(): void
    {
        $this->admin();
        $p = $this->product(['slug' => 'spec-hide']);
        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => [['label' => 'Fabric', 'value' => 'Cotton']]]));
        $this->assertStringContainsString('Specifications', $this->get('/products/spec-hide')->getContent());

        $this->put(route('admin.products.update', $p), $this->payload($p, ['specifications' => []]))->assertSessionHasNoErrors();

        $this->assertNull($p->fresh()->specifications);
        $this->assertStringNotContainsString('Specifications', $this->get('/products/spec-hide')->getContent());
    }

    public function test_the_admin_form_shows_existing_rows_and_a_new_product_has_none(): void
    {
        $this->admin();
        $p = $this->product(['slug' => 'spec-form']);
        $p->update(['specifications' => [['label' => 'Origin', 'value' => 'Handmade']]]);

        $edit = $this->get(route('admin.products.edit', $p))->getContent();
        $this->assertStringContainsString('Specifications', $edit);
        $this->assertStringContainsString('Handmade', $edit);
        $this->assertStringContainsString('specifications[${i}][label]', $edit, 'rows post as specifications[n][label]');
        $this->assertStringContainsString('No specifications yet.', $this->get(route('admin.products.create'))->getContent());
    }

    public function test_specifications_also_reach_search_engines_as_structured_data_without_inventing_any(): void
    {
        $with = $this->product(['slug' => 'ld-with']);
        $with->update(['specifications' => [['label' => 'Fabric', 'value' => 'Linen']]]);
        $without = $this->product(['slug' => 'ld-without']);

        $this->assertStringContainsString('"additionalProperty":[{"@type":"PropertyValue","name":"Fabric","value":"Linen"}]', $this->get('/products/ld-with')->getContent());
        $this->assertStringNotContainsString('additionalProperty', $this->get('/products/ld-without')->getContent());
    }

    /* ---------------- size guide ---------------- */

    public function test_the_size_guide_link_and_dialog_appear_only_when_the_owner_wrote_one_and_the_product_has_sizes(): void
    {
        [$vp] = $this->variantProduct();
        $vp->update(['slug' => 'sized']);
        $plain = $this->product(['slug' => 'unsized']);

        $none = $this->get('/products/sized')->getContent();
        $this->assertStringNotContainsString('Size guide', $none, 'nothing is shown until a guide exists (nothing is pre-filled)');

        Setting::put('size.guide', '<table><tr><th>Size</th><th>Chest (in)</th></tr><tr><td>S</td><td>36</td></tr></table>');
        $html = $this->get('/products/sized')->getContent();

        $this->assertStringContainsString('@click="$dispatch(\'open-size-guide\')">Size guide</button>', $html);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="size-guide-title"', $html);
        $this->assertStringContainsString('x-trap="open"', $html);
        $this->assertStringContainsString('<td>36</td>', $html);
        $this->assertMatchesRegularExpression('#<template x-if="sizes.length">.*?open-size-guide.*?</template>#s', $html, 'the link lives inside the size selector, which is only drawn for products that have sizes');
        $this->assertStringNotContainsString('open-size-guide\')">Size guide', $this->get('/products/sized/quick-view')->getContent(), 'the compact quick view leaves it out');
    }

    public function test_the_size_guide_is_sanitised_and_can_be_edited_from_settings(): void
    {
        $this->admin();
        [$vp] = $this->variantProduct();
        $vp->update(['slug' => 'sized2']);

        $this->put(route('admin.settings.update'), ['s__size__guide' => '<p onclick="x()">Measure around the fullest part.</p><script>alert(1)</script><table><tr><td>M</td></tr></table>'])->assertSessionHasNoErrors();

        $html = $this->get('/products/sized2')->getContent();
        $this->assertStringContainsString('Measure around the fullest part.', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('onclick="x()"', $html);
        $this->put(route('admin.settings.update'), ['s__size__guide' => str_repeat('x', 20001)])->assertSessionHasErrors('s__size__guide');
        $this->put(route('admin.settings.update'), ['s__size__guide' => ''])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Size guide', $this->get('/products/sized2')->getContent());
    }

    /* ---------------- variants are database driven ---------------- */

    public function test_the_selector_data_comes_from_the_database_variants(): void
    {
        [$vp, $v] = $this->variantProduct();                      // S inherits 2,000 (3 in stock), M sold out, L costs 2,500 (4 in stock)
        $vp->update(['slug' => 'variant-data', 'compare_price' => 2600]);
        $v['L']->update(['compare_price' => 3000]);

        $payload = $vp->fresh()->load('variants')->variantPayload();
        $by = collect($payload['variants'])->keyBy('size');

        $this->assertTrue($payload['hasVariants']);
        $this->assertSame([2000, 2000, 2500], [$by['S']['price'], $by['M']['price'], $by['L']['price']], 'a variant without its own price inherits the product price');
        $this->assertSame([3, 0, 4], [$by['S']['stock'], $by['M']['stock'], $by['L']['stock']]);
        $this->assertSame($v['L']->sku, $by['L']['sku']);
        $this->assertSame(3000, $by['L']['compare']);
        $this->assertSame('Black', $by['L']['color']);
        $this->assertCount(3, array_unique(array_column($payload['variants'], 'sku')), 'every variant has its own SKU');

        $html = $this->get('/products/variant-data')->getContent();
        foreach ([$v['S']->sku, $v['M']->sku, $v['L']->sku] as $sku) {
            $this->assertStringContainsString($sku, $html, 'the page ships each variant SKU to the selector');
        }
    }

    public function test_inactive_variants_are_not_offered_and_a_variant_deactivated_later_leaves_the_bag(): void
    {
        [$vp, $v] = $this->variantProduct();
        $this->shipping();
        $this->addToCart($vp, $v['L']->id, 1)->assertOk();

        $v['L']->update(['is_active' => false]);

        $this->assertNotContains($v['L']->sku, array_column($vp->fresh()->load('variants')->variantPayload()['variants'], 'sku'));
        $this->addToCart($vp, $v['L']->id)->assertStatus(422);
        $this->post(route('checkout.store'), $this->checkoutPayload())->assertRedirect();
        $this->assertSame(0, \App\Models\Order::count(), 'a variant that is no longer sold cannot be ordered');
    }

    public function test_an_unavailable_variant_cannot_be_bought_by_any_route(): void
    {
        [$vp, $v] = $this->variantProduct();                      // M is sold out
        $this->shipping();

        $this->addToCart($vp, $v['M']->id)->assertStatus(422);
        $this->postJson(route('cart.add'), ['product_id' => $vp->id, 'variant_id' => $v['M']->id, 'quantity' => 1, 'buy_now' => true])->assertStatus(422);
        $this->assertSame(0, CartItem::count());

        $this->addToCart($vp, $v['S']->id, 3)->assertOk();        // S has exactly 3: all of them can be bought, not more
        $this->addToCart($vp, $v['S']->id, 1)->assertStatus(422);
        $v['S']->update(['stock' => 1]);                          // stock falls while it sits in the bag
        $this->post(route('checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = \App\Models\Order::first();
        $this->assertTrue($order === null || (int) \App\Models\OrderItem::where('order_id', $order->id)->sum('quantity') <= 1, 'never more than what is in stock');
        $this->assertGreaterThanOrEqual(0, $v['S']->fresh()->stock);
    }

    /* ---------------- the rest of the page ---------------- */

    public function test_the_page_shows_every_requested_element(): void
    {
        Storage::fake('public');
        Setting::put('delivery.info', 'Delivery takes the days the owner writes.');
        Setting::put('returns.policy', 'Returns accepted as the owner writes.');
        $p = $this->product(['name' => 'Silk Scarf', 'slug' => 'silk-scarf', 'sku' => 'SCARF-1', 'price' => 1800, 'compare_price' => 2400, 'stock' => 8, 'description' => '<p>A soft scarf.</p>']);
        foreach ([1, 2] as $i) {
            Storage::disk('public')->put("products/s{$i}.jpg", $this->jpeg());
            ProductImage::create(['product_id' => $p->id, 'path' => "products/s{$i}.jpg", 'alt' => "Scarf view {$i}", 'sort_order' => $i]);
        }
        Review::create(['product_id' => $p->id, 'name' => 'Aisha', 'rating' => 5, 'body' => 'Lovely', 'is_approved' => true]);
        $this->product(['name' => 'Related Thing', 'category_id' => null]);

        $html = $this->get('/products/silk-scarf')->assertOk()->getContent();

        foreach (['Silk Scarf', 'SKU:', 'SCARF-1', 'PKR 1,800', 'PKR 2,400', 'Save 25%', 'In stock', 'A soft scarf.', 'Delivery takes the days the owner writes.', 'Returns accepted as the owner writes.',
            'Add to bag', 'Buy now', 'Toggle wishlist', 'Quantity', 'Customer reviews', 'Lovely', 'Scarf view 1', 'products/s2.jpg'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the product page should show: {$needle}");
        }
    }

    public function test_the_gallery_supports_several_images_zoom_and_keyboard_selection(): void
    {
        Storage::fake('public');
        $p = $this->product(['slug' => 'gallery-product']);
        foreach ([1, 2, 3] as $i) {
            Storage::disk('public')->put("products/g{$i}.jpg", $this->jpeg());
            ProductImage::create(['product_id' => $p->id, 'path' => "products/g{$i}.jpg", 'alt' => "G{$i}", 'sort_order' => $i]);
        }

        $html = $this->get('/products/gallery-product')->getContent();

        $this->assertSame(3, preg_match_all('#role="tab"#', $html), 'a thumbnail tab per image');
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('transform:scale(2)', $html, 'hover zoom');
        $this->assertStringContainsString('Show image 3', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'only the main photo is prioritised');
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(400, 500);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }
}
