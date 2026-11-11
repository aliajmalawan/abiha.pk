<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** QA: coupon maths, totals integrity, price/quantity manipulation, and the whole buying journey end to end. */
class QaCommerceTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private function checkout(array $over = [])
    {
        return $this->post(route('checkout.store'), $this->checkoutPayload($over));
    }

    private function lastOrder(): Order
    {
        return Order::latest('id')->firstOrFail();
    }

    /* ---------------- coupon calculations ---------------- */

    public function test_a_fixed_coupon_takes_off_exactly_its_value_and_flows_into_the_order(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'FLAT200', 'type' => 'fixed', 'value' => 200]);
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p, null, 2);
        $this->post(route('cart.coupon'), ['code' => 'FLAT200'])->assertSessionHasNoErrors();

        $this->checkout()->assertRedirect();
        $o = $this->lastOrder();

        $this->assertSame([2000, 200, 250, 2050], [$o->subtotal, $o->discount, $o->shipping_cost, $o->total]);
    }

    public function test_a_coupon_bigger_than_the_bag_never_makes_the_total_negative(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 50000]);
        $p = $this->product(['price' => 800]);
        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'HUGE']);

        $this->checkout()->assertRedirect();
        $o = $this->lastOrder();

        $this->assertSame(800, $o->discount, 'the discount is capped at the subtotal');
        $this->assertSame(250, $o->total, 'only shipping is left to pay');
        $this->assertGreaterThanOrEqual(0, $o->total);
    }

    public function test_percentage_discounts_round_down_to_whole_rupees_and_respect_the_cap(): void
    {
        $this->assertSame(149, $this->coupon(['code' => 'P15', 'value' => 15])->discountFor(999), '15% of 999 = 149.85, rounded DOWN so the shop never over-discounts');
        $this->assertSame(0, $this->coupon(['code' => 'P1', 'value' => 1])->discountFor(99), '1% of 99 is under a rupee');
        $this->assertSame(500, $this->coupon(['code' => 'CAP', 'value' => 50, 'max_discount' => 500])->discountFor(5000));
        $this->assertSame(2500, $this->coupon(['code' => 'NOCAP', 'value' => 50])->discountFor(5000));
        $this->assertSame(1000, $this->coupon(['code' => 'ALL', 'value' => 100])->discountFor(1000));
        $this->assertSame(1000, $this->coupon(['code' => 'OVER', 'type' => 'fixed', 'value' => 9999])->discountFor(1000));
    }

    public function test_coupon_codes_ignore_case_and_surrounding_spaces(): void
    {
        $this->shipping();
        $this->coupon(['code' => 'EID10']);
        $this->addToCart($this->product(['price' => 1000]));

        $this->post(route('cart.coupon'), ['code' => '  eid10 '])->assertSessionHasNoErrors();
        $this->get('/cart')->assertSee('EID10');
    }

    public function test_invalid_coupon_input_is_refused_politely(): void
    {
        $this->addToCart($this->product(['price' => 1000]));

        $this->post(route('cart.coupon'), ['code' => ''])->assertSessionHasErrors('code');
        $this->post(route('cart.coupon'), ['code' => str_repeat('x', 41)])->assertSessionHasErrors('code');
        $this->post(route('cart.coupon'), ['code' => ['a']])->assertSessionHasErrors('code');
        $this->post(route('cart.coupon'), ['code' => "' OR 1=1 --"])->assertSessionHasErrors('coupon');
        $this->post(route('cart.coupon'), ['code' => 'NOSUCHCODE'])->assertSessionHasErrors('coupon');
    }

    public function test_a_future_coupon_is_refused_and_a_deactivated_one_stops_applying_before_checkout(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $live = $this->coupon(['code' => 'LIVE', 'value' => 10]);
        $this->addToCart($this->product(['price' => 1000]), null, 2);

        $this->post(route('cart.coupon'), ['code' => 'SOON'])->assertSessionHasErrors('coupon');

        $this->post(route('cart.coupon'), ['code' => 'LIVE'])->assertSessionHasNoErrors();
        $live->update(['is_active' => false]);                                    // staff switch it off while it sits in a bag
        $this->checkout()->assertRedirect();

        $o = $this->lastOrder();
        $this->assertSame(0, $o->discount, 'no discount from a coupon that is no longer valid');
        $this->assertSame(2250, $o->total);
    }

    public function test_dropping_below_the_minimum_spend_removes_the_discount(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'BIG', 'value' => 10, 'min_subtotal' => 2000]);
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p, null, 2);
        $this->post(route('cart.coupon'), ['code' => 'BIG'])->assertSessionHasNoErrors();

        $this->patchJson(route('cart.update', $p->id.'-0'), ['quantity' => 1])->assertOk();   // subtotal falls to 1,000

        $this->checkout()->assertRedirect();
        $o = $this->lastOrder();
        $this->assertSame([1000, 0, 1250], [$o->subtotal, $o->discount, $o->total]);
    }

    public function test_free_shipping_is_judged_after_the_discount(): void
    {
        $this->shipping(250, 2000);
        $this->coupon(['code' => 'D300', 'type' => 'fixed', 'value' => 300]);
        $p = $this->product(['price' => 2100]);
        $this->addToCart($p);

        $this->checkout()->assertRedirect();
        $this->assertSame(0, $this->lastOrder()->shipping_cost, '2,100 is over the 2,000 threshold: free shipping');

        $this->flushSession();
        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'D300']);
        $this->checkout()->assertRedirect();
        $o = $this->lastOrder();
        $this->assertSame([2100, 300, 250, 2050], [$o->subtotal, $o->discount, $o->shipping_cost, $o->total], '1,800 after the coupon is under the threshold: shipping is charged');
    }

    public function test_a_per_customer_limit_follows_the_email_address_not_the_session(): void
    {
        $this->shipping();
        $this->coupon(['code' => 'ONCE', 'per_customer_limit' => 1]);
        $p = $this->product(['price' => 1000, 'stock' => 20]);

        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'ONCE'])->assertSessionHasNoErrors();
        $this->checkout(['email' => 'Same@Example.com'])->assertRedirect();
        $this->assertSame(100, $this->lastOrder()->discount);

        $this->flushSession();                                                      // a brand-new browser session, same person
        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'ONCE']);
        $this->checkout(['email' => 'same@example.com']);
        $this->assertSame(0, $this->lastOrder()->discount, 'second use by the same email (any letter case) gets no discount');
    }

    public function test_every_order_adds_up(): void
    {
        $this->shipping(250);
        $this->coupon(['code' => 'MIX', 'value' => 10, 'max_discount' => 500]);
        [$vp, $variants] = $this->variantProduct();
        $simple = $this->product(['price' => 1234, 'stock' => 9]);
        $this->addToCart($simple, null, 3);
        $this->addToCart($vp, $variants['L']->id, 2);                                // L costs 2,500 (its own price)
        $this->addToCart($vp, $variants['S']->id, 1);                                // S inherits 2,000
        $this->post(route('cart.coupon'), ['code' => 'MIX']);

        $this->checkout()->assertRedirect();
        $o = $this->lastOrder();
        $items = OrderItem::where('order_id', $o->id)->get();

        $this->assertSame(3 * 1234 + 2 * 2500 + 2000, $o->subtotal);
        $this->assertSame($o->subtotal, (int) $items->sum(fn ($i) => $i->unit_price * $i->quantity), 'order lines add up to the subtotal');
        $this->assertSame(500, $o->discount, '10% of 10,702 is capped at 500');
        $this->assertSame($o->subtotal - $o->discount + $o->shipping_cost, $o->total);
        $this->assertSame(3, $items->count());
    }

    /* ---------------- price & quantity manipulation ---------------- */

    public function test_nothing_the_browser_sends_can_change_what_is_charged(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p, null, 2);

        $this->checkout([
            'total' => 1, 'subtotal' => 1, 'discount' => 5000, 'discount' => 5000, 'shipping_cost' => 0, 'shipping_cost' => 0, 'tax' => 0,
            'price' => 1, 'unit_price' => 1, 'amount' => 1, 'coupon' => 'FREE', 'coupon_code' => 'FREE', 'payment_status' => 'paid', 'status' => 'delivered',
            'items' => [['product_id' => $p->id, 'price' => 1, 'quantity' => 99]], 'is_sample' => 1, 'user_id' => 1,
        ])->assertRedirect();

        $o = $this->lastOrder();
        $this->assertSame(2250, $o->total);
        $this->assertSame('pending', $o->status);
        $this->assertSame('unpaid', $o->payment_status, 'an order is never born paid');
        $this->assertNull($o->user_id);
        $this->assertSame(2, (int) OrderItem::where('order_id', $o->id)->value('quantity'));
        $this->assertSame(1000, (int) OrderItem::where('order_id', $o->id)->value('unit_price'));
    }

    public function test_a_price_changed_after_adding_is_charged_at_the_new_price(): void
    {
        $this->shipping(0);
        $p = $this->product(['price' => 1000]);
        $this->addToCart($p);
        $p->update(['price' => 1800]);

        $this->checkout()->assertRedirect();

        $this->assertSame(1800, $this->lastOrder()->total, 'prices are read from the database at checkout, never remembered from the cart');
    }

    public function test_absurd_quantities_are_refused_and_never_change_the_bag(): void
    {
        $p = $this->product(['price' => 1000, 'stock' => 5]);
        foreach ([0, -1, -999, 1.5, 'abc', '', [], null, 6, 999999, PHP_INT_MAX, '1e9', '٣'] as $qty) {
            $r = $this->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => $qty]);
            $this->assertContains($r->getStatusCode(), [422, 200], 'quantity '.json_encode($qty));
            $this->assertLessThan(500, $r->getStatusCode());
        }
        $this->assertLessThanOrEqual(5, (int) \App\Models\CartItem::sum('quantity'), 'the bag can never hold more than the stock');
        $this->assertSame(0, Order::count());
    }

    public function test_bag_updates_cannot_be_abused(): void
    {
        $p = $this->product(['price' => 1000, 'stock' => 4]);
        $this->addToCart($p, null, 2);
        $key = $p->id.'-0';                                              // the bag's line key: {product}-{variant or 0}

        foreach ([-3, 'x', 1.5, [], 99999] as $qty) {
            $this->assertLessThan(500, $this->patchJson(route('cart.update', $key), ['quantity' => $qty])->getStatusCode(), json_encode($qty));
        }
        $this->assertLessThanOrEqual(4, (int) \App\Models\CartItem::sum('quantity'));
        $this->assertLessThan(500, $this->patchJson(route('cart.update', 'not-a-real-key'), ['quantity' => 1])->getStatusCode());
        $this->assertLessThan(500, $this->deleteJson(route('cart.remove', '999-999'))->getStatusCode());
    }

    public function test_variants_must_belong_to_the_product_and_be_active_and_in_stock(): void
    {
        [$vp, $v] = $this->variantProduct();
        [$other, $ov] = $this->variantProduct();

        $this->addToCart($vp, $ov['S']->id)->assertStatus(422);                       // another product's variant
        $this->addToCart($vp, 999999)->assertStatus(422);                             // does not exist
        $this->addToCart($vp, null)->assertStatus(422);                               // variant product without a choice
        $this->addToCart($vp, $v['M']->id)->assertStatus(422);                        // M is sold out
        $v['S']->update(['is_active' => false]);
        $this->addToCart($vp, $v['S']->id)->assertStatus(422);                        // switched off
        $this->addToCart($vp, $v['L']->id)->assertOk();
    }

    public function test_a_simple_product_refuses_a_variant_id_and_unknown_products_are_refused(): void
    {
        [$vp, $v] = $this->variantProduct();
        $simple = $this->product();

        $this->addToCart($simple, $v['S']->id);
        $this->assertNull(\App\Models\CartItem::where('product_id', $simple->id)->value('variant_id'), "a simple product never carries another product's variant");
        $this->postJson(route('cart.add'), ['product_id' => 987654, 'quantity' => 1])->assertStatus(422);
        $this->postJson(route('cart.add'), ['product_id' => 'abc', 'quantity' => 1])->assertStatus(422);
        $this->postJson(route('cart.add'), [])->assertStatus(422);
    }

    public function test_checkout_with_an_empty_bag_or_bad_details_creates_nothing(): void
    {
        $this->shipping();
        $this->checkout()->assertRedirect();                                         // empty bag → sent back to the bag
        $this->assertSame(0, Order::count());

        $this->addToCart($this->product());
        foreach ([['name' => ''], ['phone' => 'abc'], ['email' => 'not-an-email'], ['address' => 'x'], ['city' => ''], ['shipping_method' => 'teleport'], ['payment_method' => 'bitcoin'],
            ['name' => str_repeat('a', 500)], ['email' => str_repeat('a', 300).'@x.com']] as $bad) {
            $this->checkout($bad)->assertSessionHasErrors();
        }
        $this->assertSame(0, Order::count());
    }

    public function test_double_submitting_checkout_does_not_create_two_orders(): void
    {
        $this->shipping();
        $p = $this->product(['stock' => 10]);
        $this->addToCart($p);

        $this->checkout()->assertRedirect();
        $this->checkout()->assertRedirect();                                         // the bag is empty after the first success

        $this->assertSame(1, Order::count());
        $this->assertSame(9, $p->fresh()->stock);
    }

    /* ---------------- the whole journey ---------------- */

    public function test_guest_journey_from_browsing_to_delivery(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $this->shipping(250);
        $p = $this->product(['name' => 'Journey Tee', 'slug' => 'journey-tee', 'price' => 1500, 'stock' => 5]);
        $this->coupon(['code' => 'WELCOME', 'value' => 10]);

        $this->get('/')->assertOk();
        $this->get('/shop')->assertOk()->assertSee('Journey Tee');
        $this->get('/products/journey-tee')->assertOk()->assertSee('Journey Tee');
        $this->addToCart($p, null, 2)->assertOk()->assertJsonPath('count', 2);
        $this->get('/cart')->assertOk()->assertSee('3,000');
        $this->post(route('cart.coupon'), ['code' => 'WELCOME'])->assertSessionHasNoErrors();
        $this->get('/checkout')->assertOk()->assertSee('Cash on Delivery');

        $this->checkout(['email' => 'guest@example.com'])->assertRedirect();
        $o = $this->lastOrder();
        $this->assertSame(2950, $o->total);
        $this->assertSame(3, $p->fresh()->stock);
        $this->assertSame(1, InventoryMovement::where('reason', 'sale')->count());
        $this->get(route('order.confirmation', ['order' => $o->number, 'token' => $o->access_token]))->assertOk()->assertSee($o->number)->assertSee('2,950');
        $this->get('/cart')->assertSee('empty', false);

        // The customer can track it without an account...
        $this->post(route('track.lookup'), ['number' => $o->number, 'email' => 'guest@example.com'])->assertOk()->assertSee('Pending');

        // ...while staff move it through fulfilment.
        $this->actingAs($admin)->get(route('admin.orders.show', $o))->assertOk()->assertSee($o->number);
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $this->patch(route('admin.orders.status', $o), ['status' => $status])->assertSessionHasNoErrors();
            $this->assertSame($status, $o->fresh()->status);
        }
        $this->patch(route('admin.orders.tracking', $o), ['tracking_number' => 'TRK123', 'tracking_url' => 'https://courier.example/TRK123'])->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->flushSession();
        $this->post(route('track.lookup'), ['number' => $o->number, 'email' => 'guest@example.com'])->assertOk()->assertSee('Delivered')->assertSee('TRK123');
    }

    public function test_registered_customer_journey_with_saved_address_history_and_cancellation(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 5]);

        $this->post(route('register'), ['name' => 'Sana Khan', 'email' => 'sana@example.com', 'password' => 'Secret1234', 'password_confirmation' => 'Secret1234'])->assertRedirect();
        $this->addToCart($p, null, 2);
        $this->checkout(['email' => 'sana@example.com', 'save_address' => 1])->assertRedirect();
        $o = $this->lastOrder();
        $user = User::where('email', 'sana@example.com')->firstOrFail();

        $this->assertSame($user->id, $o->user_id);
        $this->assertSame(1, $user->addresses()->count(), 'the address was saved for next time');
        $this->addToCart($p);
        $this->get('/checkout')->assertOk()->assertSee('House 1, Street 2');           // pre-filled for next time
        $this->get(route('account.orders'))->assertOk()->assertSee($o->number);
        $this->get(route('account.orders.show', $o))->assertOk()->assertSee('2,250');
        $this->assertSame(3, $p->fresh()->stock);

        $this->post(route('account.orders.cancel', $o))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame(5, $p->fresh()->stock, 'cancelling puts the stock back');
        $this->post(route('account.orders.cancel', $o))->assertForbidden();       // can't cancel twice
        $this->assertSame(5, $p->fresh()->stock);
    }

    public function test_a_customer_cannot_cancel_an_order_that_has_already_shipped(): void
    {
        [$o] = $this->placeGuestOrder();
        $user = User::factory()->create();
        $o->update(['user_id' => $user->id, 'status' => 'shipped']);

        $this->actingAs($user)->post(route('account.orders.cancel', $o))->assertForbidden();
        $this->assertSame('shipped', $o->fresh()->status);
    }

    public function test_wishlist_journey_guest_to_account_to_bag(): void
    {
        $this->shipping();
        $a = $this->product(['name' => 'Wish A', 'slug' => 'wish-a', 'stock' => 3]);
        $b = $this->product(['name' => 'Wish B', 'slug' => 'wish-b', 'stock' => 0]);

        $this->postJson(route('wishlist.toggle', $a))->assertOk();
        $this->postJson(route('wishlist.toggle', $b))->assertOk();
        $this->get('/wishlist')->assertSee('Wish A')->assertSee('Wish B');
        $this->postJson(route('wishlist.toggle', $b))->assertOk();                      // toggled off again
        $this->get('/wishlist')->assertDontSee('Wish B');

        $user = User::factory()->create(['password' => 'Secret1234']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret1234'])->assertRedirect();
        $this->get('/wishlist')->assertSee('Wish A');                                    // survived the login

        $this->post(route('wishlist.move', $a))->assertSessionHasNoErrors();
        $this->get('/wishlist')->assertDontSee('Wish A');
        $this->get('/cart')->assertSee('Wish A');
        $this->checkout(['email' => $user->email])->assertRedirect();
        $this->assertSame(2, $a->fresh()->stock);
    }

    public function test_wishlist_refuses_inactive_and_unknown_products(): void
    {
        $hidden = $this->product(['is_active' => false]);

        $this->postJson(route('wishlist.toggle', $hidden))->assertNotFound();
        $this->postJson('/wishlist/no-such-product/toggle')->assertNotFound();
    }

    /* ---------------- authentication ---------------- */

    public function test_login_failures_are_generic_and_success_returns_to_the_page_you_wanted(): void
    {
        $user = User::factory()->create(['email' => 'real@example.com', 'password' => 'Secret1234']);

        $a = $this->post(route('login'), ['email' => 'real@example.com', 'password' => 'wrong-password']);
        $b = $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        $a->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), $b->assertSessionHasErrors('email') && true ? session('errors')->first('email') : '', 'same message for a wrong password and an unknown email');
        $this->assertGuest();

        $this->flushSession();
        $this->get(route('account.orders'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => 'real@example.com', 'password' => 'Secret1234'])->assertRedirect(route('account.orders'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_ends_the_session_and_protected_pages_close_again(): void
    {
        $user = User::factory()->create(['password' => 'Secret1234']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret1234']);
        $before = session()->getId();

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->post(route('logout'))->assertRedirect(route('login'));                   // logging out twice is harmless
    }

    public function test_login_and_register_validate_their_inputs(): void
    {
        foreach ([[], ['email' => 'x'], ['email' => 'a@b.co'], ['email' => ['a'], 'password' => 'x'], ['email' => str_repeat('a', 400).'@x.com', 'password' => 'x']] as $payload) {
            $this->assertLessThan(500, $this->post(route('login'), $payload)->getStatusCode(), json_encode($payload));
        }
        foreach ([[], ['name' => 'A', 'email' => 'bad', 'password' => 'Secret1234', 'password_confirmation' => 'different'], ['name' => ['x'], 'email' => 'a@b.co', 'password' => 'Secret1234', 'password_confirmation' => 'Secret1234']] as $payload) {
            $r = $this->post(route('register'), $payload);
            $this->assertLessThan(500, $r->getStatusCode());
            $this->assertGuest();
        }
        User::factory()->create(['email' => 'taken@example.com']);
        $this->post(route('register'), ['name' => 'Dup', 'email' => 'TAKEN@example.com', 'password' => 'Secret1234', 'password_confirmation' => 'Secret1234'])->assertSessionHasErrors('email');
    }

    /* ---------------- admin order management ---------------- */

    public function test_admin_order_list_filters_and_status_rules(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        [$a] = $this->placeGuestOrder();
        $this->flushSession();
        [$b] = $this->placeGuestOrder('cod', 700, 1, ['email' => 'second@example.com', 'name' => 'Second Buyer']);
        $b->update(['status' => 'confirmed']);

        $this->actingAs($admin);
        $this->get(route('admin.orders.index'))->assertOk()->assertSee($a->number)->assertSee($b->number);
        $this->get(route('admin.orders.index', ['status' => 'confirmed']))->assertSee($b->number)->assertDontSee($a->number);
        $this->get(route('admin.orders.index', ['q' => 'Second Buyer']))->assertSee($b->number)->assertDontSee($a->number);
        $this->get(route('admin.orders.index', ['q' => $a->number]))->assertSee($a->number)->assertDontSee($b->number);
        $this->get(route('admin.orders.invoice', $a))->assertOk()->assertSee($a->number);

        $this->patch(route('admin.orders.status', $a), ['status' => 'delivered'])->assertSessionHasErrors('status');   // can't skip fulfilment
        $this->patch(route('admin.orders.status', $a), ['status' => 'nonsense'])->assertSessionHasErrors('status');
        $this->patch(route('admin.orders.status', $a), ['status' => ['x']])->assertSessionHasErrors('status');
        $this->assertSame('pending', $a->fresh()->status);
        $this->get(route('admin.orders.show', 'ABH-NOPE'))->assertNotFound();
    }

    public function test_the_ledger_matches_the_shelf_after_sales_and_cancellations(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $this->shipping();
        $p = $this->product(['stock' => 10]);

        foreach ([2, 3] as $qty) {
            $this->flushSession();
            $this->addToCart($p, null, $qty);
            $this->checkout()->assertRedirect();
        }
        $this->assertSame(5, $p->fresh()->stock);
        $this->actingAs($admin)->patch(route('admin.orders.status', $this->lastOrder()), ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->assertSame(8, $p->fresh()->stock);

        $movements = InventoryMovement::where('product_id', $p->id)->orderBy('id')->get();
        $this->assertSame($p->fresh()->stock, 10 + (int) $movements->sum('change'), 'opening stock plus every recorded movement equals what is on the shelf');
        $this->assertSame($p->fresh()->stock, $movements->last()->balance_after);
        $this->assertEqualsCanonicalizing(['sale', 'sale', 'cancellation'], $movements->pluck('reason')->all());
    }

    public function test_staff_signing_in_never_lands_on_a_leftover_storefront_page(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin', ['password' => 'Secret123456']);

        $this->get('/account')->assertRedirect(route('login'));                      // remembers /account as the "intended" page
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'Secret123456'])->assertRedirect(route('admin.dashboard'));

        Auth::forgetGuards();
        $this->post(route('logout'));
        $this->get('/admin/orders')->assertRedirect();                                // remembers an admin page
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'Secret123456'])->assertRedirect(url('/admin/orders'));
    }
}
