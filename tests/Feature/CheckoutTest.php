<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Rules\PakistaniPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_pakistani_phone_numbers_are_accepted_in_common_formats(): void
    {
        foreach (['03001234567', '0300 1234567', '0300-1234567', '+92 300 1234567', '+923001234567', '923001234567', '00923001234567', '(0300) 123 4567',
            '042 35761234', '021-34567890', '0512345678'] as $ok) {
            $this->assertTrue(PakistaniPhone::isValid($ok), "should accept $ok");
        }
    }

    public function test_foreign_short_long_and_junk_numbers_are_rejected(): void
    {
        foreach (['abc', '', '12345', '0300123456', '030012345678', '+14155550123', '+442071838750', '00000000000', '1234567890123', '0300 12345 67890', '03x01234567', '+92-300-123456', '0123456789'] as $bad) {
            $this->assertFalse(PakistaniPhone::isValid($bad), "should reject $bad");
        }
        $this->assertFalse(PakistaniPhone::isValid(['03001234567']));
        $this->assertFalse(PakistaniPhone::isValid(null));
    }

    public function test_checkout_rejects_a_bad_phone_and_stores_a_good_one_in_one_shape(): void
    {
        $this->shipping();
        $this->addToCart($this->product());

        $this->post(route('checkout.store'), $this->checkoutPayload(['phone' => '+1 415 555 0123']))->assertSessionHasErrors('phone');
        $this->assertSame(0, Order::count());

        $this->post(route('checkout.store'), $this->checkoutPayload(['phone' => '+92 300 1234567']))->assertSessionHasNoErrors();
        $this->assertSame('03001234567', Order::firstOrFail()->shippingAddress->phone);
    }

    public function test_every_field_of_the_form_is_saved_on_the_order(): void
    {
        $this->shipping();
        $this->addToCart($this->product());

        $this->post(route('checkout.store'), $this->checkoutPayload([
            'name' => 'Ayesha Khan', 'email' => 'Ayesha@Example.com', 'province' => 'Sindh', 'city' => 'Karachi',
            'address' => 'Flat 4, Block B, Clifton', 'postal_code' => '75600', 'notes' => 'Please call before delivery',
        ]));

        $order = Order::with('shippingAddress')->firstOrFail();
        $this->assertSame('ayesha@example.com', $order->email);
        $this->assertSame('Please call before delivery', $order->notes);
        $this->assertSame(['Ayesha Khan', 'Sindh', 'Karachi', 'Flat 4, Block B, Clifton', '75600'],
            [$order->shippingAddress->name, $order->shippingAddress->province, $order->shippingAddress->city, $order->shippingAddress->address, $order->shippingAddress->postal_code]);
    }

    public function test_required_fields_are_enforced_and_notes_and_postal_code_are_optional(): void
    {
        $this->shipping();
        $this->addToCart($this->product());

        $this->post(route('checkout.store'), $this->checkoutPayload(['name' => '', 'phone' => '', 'email' => '', 'province' => '', 'city' => '', 'address' => '']))
            ->assertSessionHasErrors(['name', 'phone', 'email', 'province', 'city', 'address']);
        $this->post(route('checkout.store'), $this->checkoutPayload(['postal_code' => '<script>']))->assertSessionHasErrors('postal_code');
        $this->post(route('checkout.store'), $this->checkoutPayload(['notes' => str_repeat('x', 501)]))->assertSessionHasErrors('notes');
        $this->assertSame(0, Order::count());

        $this->post(route('checkout.store'), $this->checkoutPayload(['postal_code' => null, 'notes' => null]))->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    public function test_prices_discounts_shipping_and_totals_sent_by_the_browser_are_ignored(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p, null, 2);

        $this->post(route('checkout.store'), $this->checkoutPayload([
            'total' => 1, 'subtotal' => 1, 'discount' => 5000, 'shipping_cost' => 0, 'shipping' => 0, 'price' => 1, 'unit_price' => 1,
            'items' => [['product_id' => $p->id, 'price' => 1, 'quantity' => 1]], 'coupon' => 'FREE', 'coupon_id' => 1, 'payment_status' => 'paid', 'status' => 'delivered',
        ]));

        $order = Order::with('items')->firstOrFail();
        $this->assertSame(2000, $order->subtotal);
        $this->assertSame(0, $order->discount);
        $this->assertSame(250, $order->shipping_cost);
        $this->assertSame(2250, $order->total);
        $this->assertSame(1000, $order->items->first()->unit_price);
        $this->assertSame(2, $order->items->first()->quantity);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->coupon_id);
    }

    public function test_shipping_cost_comes_from_the_chosen_method_not_from_the_request(): void
    {
        $this->shipping(250);
        \App\Models\ShippingMethod::create(['code' => 'express', 'name' => 'Express', 'cost' => 600, 'is_active' => true]);
        $this->addToCart($this->product(['price' => 1000]));

        $this->post(route('checkout.store'), $this->checkoutPayload(['shipping_method' => 'express', 'shipping_cost' => 1]));
        $this->assertSame(600, Order::firstOrFail()->shipping_cost);
    }

    public function test_coupon_is_applied_from_the_checkout_page_and_what_was_typed_is_kept(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);
        $this->addToCart($this->product(['price' => 1000]));

        $this->post(route('cart.coupon'), ['code' => 'save10', 'from' => 'checkout', 'name' => 'Ayesha Khan', 'city' => 'Lahore', 'phone' => '03001234567', '_token' => 'x'])
            ->assertRedirect(route('checkout'));
        $page = $this->get(route('checkout'));
        $page->assertOk()->assertSee('SAVE10')->assertSee('Ayesha Khan')->assertSee('Lahore');

        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();
        $this->assertSame(100, $order->discount);
        $this->assertSame(1150, $order->total);
        $this->assertSame('SAVE10', $order->coupon_code);
    }

    public function test_a_wrong_coupon_on_the_checkout_page_shows_an_error_and_the_total_is_unchanged(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));

        $this->post(route('cart.coupon'), ['code' => 'NOPE', 'from' => 'checkout', 'name' => 'Ayesha Khan'])
            ->assertRedirect(route('checkout'))->assertSessionHasErrors('coupon');
        $this->get(route('checkout'))->assertSee('Ayesha Khan');

        $this->post(route('cart.coupon'), ['code' => '', 'from' => 'checkout'])->assertSessionHasErrors('coupon');
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $this->assertSame(1250, Order::firstOrFail()->total);
    }

    public function test_a_coupon_can_be_removed_from_the_checkout_page(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'SAVE10']);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('cart.coupon'), ['code' => 'SAVE10']);

        $this->delete(route('cart.coupon.remove'), ['from' => 'checkout'])->assertRedirect(route('checkout'));
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $this->assertSame(0, Order::firstOrFail()->discount);
    }

    public function test_the_checkout_page_lists_the_form_fields_methods_and_summary(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000, 'name' => 'Summary Dress']));

        $this->get(route('checkout'))->assertOk()
            ->assertSee(['name="name"', 'name="phone"', 'name="email"', 'name="province"', 'name="city"', 'name="address"', 'name="postal_code"', 'name="notes"',
                'name="shipping_method"', 'name="payment_method"', 'name="code"', 'Order summary', 'Summary Dress', 'Total payable', 'Place order'], false);
    }

    public function test_an_empty_bag_cannot_reach_checkout_or_place_an_order(): void
    {
        $this->shipping();
        $this->get(route('checkout'))->assertRedirect(route('cart'));
        $this->post(route('checkout.store'), $this->checkoutPayload())->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_the_confirmation_page_shows_the_order_and_needs_the_private_link(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000, 'name' => 'Confirmed Dress']));
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();

        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))
            ->assertOk()->assertSee($order->number)->assertSee('Confirmed Dress')->assertSee('1,250');
        $this->get(route('order.confirmation', ['order' => $order]))->assertNotFound();
        $this->get(route('order.confirmation', ['order' => $order, 'token' => 'wrong']))->assertNotFound();
    }
}
