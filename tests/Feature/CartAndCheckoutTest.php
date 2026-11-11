<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class CartAndCheckoutTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_adds_a_product_and_reports_the_count(): void
    {
        $p = $this->product(['price' => 1500]);

        $this->addToCart($p, null, 2)->assertOk()->assertJson(['ok' => true, 'count' => 2]);
        $this->get(route('cart'))->assertOk()->assertSee('3,000');
    }

    public function test_cannot_add_more_than_the_stock(): void
    {
        $p = $this->product(['stock' => 2]);

        $this->addToCart($p, null, 3)->assertStatus(422)->assertJson(['ok' => false]);
        $this->addToCart($p, null, 2)->assertOk();
        $this->addToCart($p, null, 1)->assertStatus(422);   // already holds all available
    }

    public function test_sold_out_product_cannot_be_added(): void
    {
        $this->addToCart($this->product(['stock' => 0]))->assertStatus(422);
    }

    public function test_variant_products_need_a_variant_and_use_variant_price_and_stock(): void
    {
        [$p, $v] = $this->variantProduct();

        $this->addToCart($p)->assertStatus(422);                      // no variant chosen
        $this->addToCart($p, $v['M']->id)->assertStatus(422);         // M is sold out
        $this->addToCart($p, $v['L']->id, 2)->assertOk();             // L overrides the price
        $this->addToCart($p, $v['L']->id, 3)->assertStatus(422);      // only 4 in stock, 2 already in bag

        $this->get(route('cart'))->assertSee('5,000');                // 2 × 2,500, not 2 × 2,000
    }

    public function test_a_variant_of_another_product_is_rejected(): void
    {
        [, $v] = $this->variantProduct();
        $other = $this->product(['has_variants' => true]);

        $this->addToCart($other, $v['S']->id)->assertStatus(422);
    }

    public function test_browser_cannot_set_prices(): void
    {
        $p = $this->product(['price' => 1000]);
        $this->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => 1, 'price' => 1, 'unit_price' => 1])->assertOk();

        $summary = app(\App\Services\Cart\CartService::class)->summary();
        $this->assertSame(1000, $summary->subtotal);
    }

    public function test_percent_coupon_applies_respects_minimum_and_cap(): void
    {
        $this->shipping(0);
        $this->coupon(['value' => 10, 'min_subtotal' => 2000, 'max_discount' => 150]);
        $p = $this->product(['price' => 1000, 'stock' => 10]);

        $this->addToCart($p, null, 1);
        $this->post(route('cart.coupon'), ['code' => 'test10'])->assertSessionHasErrors('coupon');    // below minimum

        $this->addToCart($p, null, 2);                                                                // subtotal 3000
        $this->post(route('cart.coupon'), ['code' => 'test10'])->assertSessionHasNoErrors();

        $s = app(\App\Services\Cart\CartService::class)->summary();
        $this->assertSame(150, $s->discount);                                                         // 10% = 300, capped at 150
        $this->assertSame(2850, $s->total);
    }

    public function test_expired_inactive_and_exhausted_coupons_are_rejected(): void
    {
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p);

        $this->coupon(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->exhaustedCoupon('USED');

        foreach (['OLD', 'OFF', 'USED', 'NOPE'] as $code) {
            $this->post(route('cart.coupon'), ['code' => $code])->assertSessionHasErrors('coupon');
        }
    }

    public function test_free_shipping_threshold(): void
    {
        $this->shipping(250, 3000);
        $p = $this->product(['price' => 1000, 'stock' => 10]);

        $this->addToCart($p, null, 2);
        $this->assertSame(250, app(\App\Services\Cart\CartService::class)->summary()->shipping);

        $this->addToCart($p, null, 1);
        $this->assertSame(0, app(\App\Services\Cart\CartService::class)->summary()->shipping);
    }

    public function test_cod_checkout_creates_an_order_decrements_stock_and_snapshots_prices(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 5, 'name' => 'Snapshot Item']);
        $this->addToCart($p, null, 2);

        $res = $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::with('items')->firstOrFail();
        $res->assertRedirect(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]));

        $this->assertSame(2250, $order->total);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(3, $p->fresh()->stock);
        $this->assertSame(1000, $order->items->first()->unit_price);

        // history is immutable: later price changes do not touch the order
        $p->update(['price' => 9999, 'name' => 'Renamed']);
        $this->assertSame('Snapshot Item', $order->fresh()->items->first()->name);
        $this->assertSame(1000, $order->fresh()->items->first()->unit_price);

        $this->assertSame(0, app(\App\Services\Cart\CartService::class)->count());    // bag emptied
    }

    public function test_checkout_aborts_when_stock_disappears_after_adding(): void
    {
        $this->shipping();
        $p = $this->product(['stock' => 2]);
        $this->addToCart($p, null, 2);
        $p->update(['stock' => 1]);                                                    // someone else bought one

        $this->post(route('checkout.store'), $this->checkoutPayload())->assertRedirect(route('cart'));
        $this->assertSame(0, Order::count());
        $this->assertSame(1, $p->fresh()->stock);                                      // nothing decremented
    }

    public function test_coupon_use_is_counted_and_enforced_at_checkout(): void
    {
        $this->shipping(0);
        $c = $this->coupon(['usage_limit' => 1]);
        $p = $this->product(['price' => 1000, 'stock' => 10]);

        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'TEST10']);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $this->assertSame(1, $c->usages()->count());
        $this->assertSame(900, Order::first()->total);

        // limit reached → second customer cannot reuse it
        $this->flushSession();
        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'TEST10'])->assertSessionHasErrors('coupon');
    }

    public function test_unknown_or_disabled_payment_method_is_rejected(): void
    {
        $this->shipping();
        $this->addToCart($this->product());

        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'jazzcash']))->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_validates_the_customer_fields(): void
    {
        $this->shipping();
        $this->addToCart($this->product());

        $this->post(route('checkout.store'), $this->checkoutPayload(['phone' => 'abc', 'province' => 'Atlantis', 'address' => 'short', 'email' => 'nope']))
            ->assertSessionHasErrors(['phone', 'province', 'address', 'email']);
    }

    public function test_cancelling_an_order_restocks_items(): void
    {
        $this->shipping();
        $p = $this->product(['stock' => 5]);
        $this->addToCart($p, null, 2);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();
        $this->assertSame(3, $p->fresh()->stock);

        app(\App\Services\OrderService::class)->cancel($order);

        $this->assertSame(5, $p->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_deactivated_products_drop_out_of_the_bag(): void
    {
        $p = $this->product();
        $this->addToCart($p);
        Product::whereKey($p->id)->update(['is_active' => false]);

        $this->assertTrue(app(\App\Services\Cart\CartService::class)->summary()->isEmpty());
    }
}
