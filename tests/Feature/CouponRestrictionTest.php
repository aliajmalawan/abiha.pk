<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** A coupon limited to chosen categories or products only discounts matching items, and the server enforces it. */
class CouponRestrictionTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private function cat(string $name, ?int $parent = null): Category
    {
        return Category::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => true, 'parent_id' => $parent]);
    }

    private function summary()
    {
        return app(CartService::class)->summary();
    }

    public function test_a_category_coupon_discounts_only_items_in_that_category(): void
    {
        $this->shipping(0);
        $lawn = $this->cat('Lawn');
        $silk = $this->cat('Silk');
        $a = $this->product(['price' => 2000, 'category_id' => $lawn->id]);
        $b = $this->product(['price' => 3000, 'category_id' => $silk->id]);
        $coupon = $this->coupon(['code' => 'LAWN10', 'value' => 10]);
        $coupon->categories()->attach($lawn->id);

        $this->addToCart($a);
        $this->addToCart($b);
        $this->post(route('cart.coupon'), ['code' => 'LAWN10'])->assertSessionHasNoErrors();

        $s = $this->summary();
        $this->assertSame(5000, $s->subtotal);
        $this->assertSame(200, $s->discount);                      // 10% of the 2,000 lawn item only, not of 5,000
        $this->assertSame(4800, $s->total);
    }

    public function test_a_listed_category_includes_its_sub_categories(): void
    {
        $this->shipping(0);
        $parent = $this->cat('Women');
        $child = $this->cat('Lawn Suits', $parent->id);
        $p = $this->product(['price' => 4000, 'category_id' => $child->id]);
        $coupon = $this->coupon(['code' => 'WOMEN', 'value' => 25]);
        $coupon->categories()->attach($parent->id);

        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'WOMEN'])->assertSessionHasNoErrors();
        $this->assertSame(1000, $this->summary()->discount);
    }

    public function test_a_product_coupon_discounts_only_the_listed_products(): void
    {
        $this->shipping(0);
        $a = $this->product(['price' => 1000]);
        $b = $this->product(['price' => 1000]);
        $coupon = $this->coupon(['code' => 'ONLYA', 'type' => 'fixed', 'value' => 400]);
        $coupon->products()->attach($a->id);

        $this->addToCart($a);
        $this->addToCart($b);
        $this->post(route('cart.coupon'), ['code' => 'ONLYA'])->assertSessionHasNoErrors();
        $this->assertSame(400, $this->summary()->discount);
    }

    public function test_the_discount_never_exceeds_the_eligible_part_of_the_bag(): void
    {
        $this->shipping(0);
        $a = $this->product(['price' => 300]);
        $b = $this->product(['price' => 5000]);
        $coupon = $this->coupon(['code' => 'BIGFIX', 'type' => 'fixed', 'value' => 1000]);
        $coupon->products()->attach($a->id);

        $this->addToCart($a);
        $this->addToCart($b);
        $this->post(route('cart.coupon'), ['code' => 'BIGFIX'])->assertSessionHasNoErrors();
        $this->assertSame(300, $this->summary()->discount);        // capped at the 300 eligible item, not the whole bag
    }

    public function test_a_restricted_coupon_is_refused_when_nothing_in_the_bag_qualifies(): void
    {
        $this->shipping(0);
        $lawn = $this->cat('Lawn');
        $p = $this->product(['price' => 2000]);
        $coupon = $this->coupon(['code' => 'LAWNONLY']);
        $coupon->categories()->attach($lawn->id);

        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'LAWNONLY'])->assertSessionHasErrors('coupon');
        $this->assertNull($this->summary()->coupon);
        $this->assertSame(0, $this->summary()->discount);
    }

    public function test_a_coupon_is_dropped_when_the_qualifying_item_leaves_the_bag(): void
    {
        $this->shipping(0);
        $lawn = $this->cat('Lawn');
        $a = $this->product(['price' => 2000, 'category_id' => $lawn->id]);
        $b = $this->product(['price' => 1000]);
        $coupon = $this->coupon(['code' => 'LAWN20', 'value' => 20]);
        $coupon->categories()->attach($lawn->id);

        $this->addToCart($a);
        $this->addToCart($b);
        $this->post(route('cart.coupon'), ['code' => 'LAWN20'])->assertSessionHasNoErrors();
        $this->assertSame(400, $this->summary()->discount);

        $key = app(CartService::class)->lines()->first(fn ($l) => $l->product->id === $a->id)->key;
        $this->deleteJson(route('cart.remove', $key))->assertOk();
        $s = $this->summary();
        $this->assertSame(0, $s->discount);
        $this->assertNotNull($s->couponError);                     // the customer is told why it came off
    }

    public function test_checkout_charges_the_restricted_discount_and_ignores_totals_sent_by_the_browser(): void
    {
        $this->shipping(250);
        $lawn = $this->cat('Lawn');
        $a = $this->product(['price' => 2000, 'category_id' => $lawn->id]);
        $b = $this->product(['price' => 3000]);
        $coupon = $this->coupon(['code' => 'LAWN10', 'value' => 10]);
        $coupon->categories()->attach($lawn->id);

        $this->addToCart($a);
        $this->addToCart($b);
        $this->post(route('cart.coupon'), ['code' => 'LAWN10']);
        $this->post(route('checkout.store'), $this->checkoutPayload(['discount' => 5000, 'total' => 1, 'coupon' => 'LAWN10']));

        $order = Order::firstOrFail();
        $this->assertSame([5000, 200, 250, 5050], [$order->subtotal, $order->discount, $order->shipping_cost, $order->total]);
    }

    public function test_an_unrestricted_coupon_still_applies_to_the_whole_bag(): void
    {
        $this->shipping(0);
        $this->coupon(['code' => 'ALL10', 'value' => 10]);
        $this->addToCart($this->product(['price' => 2000]));
        $this->addToCart($this->product(['price' => 3000]));
        $this->post(route('cart.coupon'), ['code' => 'ALL10'])->assertSessionHasNoErrors();
        $this->assertSame(500, $this->summary()->discount);
    }

    public function test_the_admin_can_save_restrictions_and_cannot_save_ids_that_do_not_exist(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $cat = $this->cat('Lawn');
        $p = $this->product();
        $data = ['code' => 'RESTRICT', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 0];

        $this->actingAs($admin)->post(route('admin.coupons.store'), $data + ['categories' => [$cat->id], 'products' => [$p->id]])->assertSessionHasNoErrors();
        $coupon = \App\Models\Coupon::where('code', 'RESTRICT')->firstOrFail();
        $this->assertSame([$cat->id], $coupon->categories()->pluck('categories.id')->all());
        $this->assertSame([$p->id], $coupon->products()->pluck('products.id')->all());
        $this->get(route('admin.coupons.edit', $coupon))->assertOk()->assertSee('Only for these categories')->assertSee('Only for these products');

        $this->post(route('admin.coupons.store'), ['code' => 'BADIDS'] + $data + ['categories' => [99999]])->assertSessionHasErrors('categories.0');
        $this->assertNull(\App\Models\Coupon::where('code', 'BADIDS')->first());

        $this->put(route('admin.coupons.update', $coupon), $data + ['categories' => [], 'products' => []])->assertSessionHasNoErrors();
        $this->assertFalse($coupon->fresh()->isRestricted());
    }
}
