<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\Cart\CartException;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class OrderProcessingTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
    }

    /** Place a guest order through the real checkout. */
    private function order(string $method = 'cod', int $price = 1000, int $qty = 2, ?string $coupon = null): array
    {
        $this->shipping(250);
        $p = $this->product(['price' => $price, 'stock' => 10]);
        $this->addToCart($p, null, $qty);
        if ($coupon) {
            $this->post(route('cart.coupon'), ['code' => $coupon]);
        }
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => $method]));

        return [Order::latest('id')->firstOrFail(), $p];
    }

    public function test_totals_are_computed_by_the_server_and_ignore_submitted_amounts(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 10]);
        $this->addToCart($p, null, 2);

        $this->post(route('checkout.store'), $this->checkoutPayload(['total' => 1, 'subtotal' => 1, 'shipping_cost' => 0, 'discount' => 99999, 'unit_price' => 1]));

        $o = Order::firstOrFail();
        $this->assertSame([2000, 0, 250, 2250], [$o->subtotal, $o->discount, $o->shipping_cost, $o->total]);
    }

    public function test_order_stores_a_separate_address_snapshot(): void
    {
        [$order] = $this->order();

        $this->assertDatabaseHas('order_addresses', ['order_id' => $order->id, 'type' => 'shipping', 'city' => 'Lahore', 'province' => 'Punjab']);
        $this->assertSame('Test Buyer', $order->name);          // accessor over the snapshot
        $this->assertSame('buyer@example.com', $order->email);
    }

    public function test_coupon_usage_is_recorded_and_released_on_cancellation(): void
    {
        $c = $this->coupon(['code' => 'ONCE', 'per_customer_limit' => 1, 'min_subtotal' => 0]);
        [$order] = $this->order('cod', 1000, 2, 'ONCE');

        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $c->id, 'order_id' => $order->id, 'discount_amount' => 200]);
        $this->assertSame(1800 + 250, $order->total);

        // same email cannot use it again
        $this->flushSession();
        $this->addToCart($this->product(['stock' => 5]));
        $this->post(route('cart.coupon'), ['code' => 'ONCE']);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $this->assertSame(1, $c->usages()->count());                                   // second order placed WITHOUT the coupon (limit hit)
        $this->assertNull(Order::latest('id')->first()->coupon_code);

        // cancelling frees the redemption
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, $c->usages()->count());
    }

    public function test_status_transitions_are_enforced(): void
    {
        [$order] = $this->order();

        // pending → delivered is not allowed
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $order->fresh()->status);

        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $s) {
            $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => $s])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame('delivered', $order->fresh()->status);

        // final state: nothing further, and a delivered order cannot be cancelled
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->assertSame(5, $order->history()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.status_changed', 'user_id' => $this->admin->id]);
    }

    public function test_cancelling_restocks_through_the_ledger(): void
    {
        [$order, $p] = $this->order('cod', 1000, 3);
        $this->assertSame(7, $p->fresh()->stock);

        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Customer called'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(10, $p->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'order_id' => $order->id, 'change' => 3, 'reason' => 'cancellation']);
        $this->assertSame(0, (int) $p->fresh()->sold_count);
        // ledger reconciles: -3 (sale) +3 (cancellation)
        $this->assertSame(0, (int) \App\Models\InventoryMovement::where('product_id', $p->id)->where('order_id', $order->id)->sum('change'));
    }

    public function test_cancelling_twice_does_not_restock_twice(): void
    {
        [$order, $p] = $this->order('cod', 1000, 2);

        app(OrderService::class)->changeStatus($order, 'cancelled', $this->admin);
        try {
            app(OrderService::class)->changeStatus($order->fresh(), 'cancelled', $this->admin);
            $this->fail('second cancellation should be refused');
        } catch (CartException) {
        }
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_staff_can_verify_a_bank_transfer_only_for_the_exact_amount(): void
    {
        [$order] = $this->order('bank_transfer');
        $this->assertSame('unpaid', $order->payment_status);

        // customer reports the transfer: awaiting verification, NOT paid
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-998877'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);

        // wrong amount refused
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => 100])->assertSessionHasErrors('payment');
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);

        // exact amount accepted: records who verified, confirms the order
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total, 'reference' => 'TXN-998877'])->assertSessionHasNoErrors()->assertRedirect();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'verified_by' => $this->admin->id, 'reference' => 'TXN-998877']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.verified']);

        // cannot be verified twice, and a paid order is no longer customer-cancellable
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order))->assertSessionHasErrors('payment');
        $this->assertFalse($order->isCancellable());
    }

    public function test_customer_reference_submission_needs_the_secret_token_and_never_marks_paid(): void
    {
        [$order] = $this->order('bank_transfer');

        $this->post(route('order.payment-reference', $order), ['reference' => 'X-1'])->assertNotFound();
        $this->post(route('order.payment-reference', $order), ['token' => 'wrong', 'reference' => 'X-1'])->assertNotFound();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_online_payments_cannot_be_marked_paid_by_hand(): void
    {
        [$order] = $this->order('cod');
        $order->update(['payment_method' => 'jazzcash']);

        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order))->assertSessionHasErrors('payment');
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_cancelled_orders_cannot_be_marked_paid(): void
    {
        [$order] = $this->order('bank_transfer');
        app(OrderService::class)->changeStatus($order, 'cancelled', $this->admin);

        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order->fresh()))->assertSessionHasErrors('payment');
    }

    public function test_staff_can_reject_a_transfer_that_never_arrived(): void
    {
        [$order] = $this->order('bank_transfer');

        $this->actingAs($this->admin)->post(route('admin.orders.payment.reject', $order), ['note' => 'Not in statement'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_only_users_with_the_payment_permission_can_verify(): void
    {
        [$order] = $this->order('bank_transfer');
        // The Order Manager role does verify payments, so use a custom role that can process orders but not verify them.
        $role = \App\Models\Role::create(['slug' => 'ops', 'name' => 'Ops', 'is_staff' => true]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('slug', ['dashboard.view', 'orders.view', 'orders.manage'])->pluck('id'));
        $staff = $this->userWithRole('ops');

        $this->actingAs($staff)->get(route('admin.orders.show', $order))->assertOk();
        $this->actingAs($staff)->post(route('admin.orders.payment.verify', $order))->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_tracking_link_must_be_a_url(): void
    {
        [$order] = $this->order();

        $this->actingAs($this->admin)->patch(route('admin.orders.tracking', $order), ['tracking_number' => 'TRK1', 'tracking_url' => 'javascript:alert(1)'])->assertSessionHasErrors('tracking_url');
        $this->actingAs($this->admin)->patch(route('admin.orders.tracking', $order), ['tracking_number' => 'TRK1', 'tracking_url' => 'https://courier.example/TRK1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('TRK1', $order->fresh()->tracking_number);
    }

    public function test_admin_order_search_finds_by_number_email_and_name(): void
    {
        [$order] = $this->order();

        foreach ([$order->number, 'buyer@example', 'Test Buyer', '03001234567'] as $q) {
            $this->actingAs($this->admin)->get(route('admin.orders.index', ['q' => $q]))->assertOk()->assertSee($order->number);
        }
        $this->actingAs($this->admin)->get(route('admin.orders.index', ['q' => 'nobody-here']))->assertDontSee($order->number);
    }

    public function test_concurrent_style_oversell_is_refused_at_the_inventory_layer(): void
    {
        $p = $this->product(['stock' => 1]);
        $inv = app(\App\Services\Inventory\InventoryService::class);

        $inv->adjust($p, -1, 'sale');                                                  // first buyer takes the last unit
        $this->expectException(\App\Services\Inventory\InsufficientStockException::class);
        $inv->adjust(Product::find($p->id), -1, 'sale');                               // second buyer is refused
    }

    /** SQLite ignores varchar lengths; MySQL does not. Guard the longest status values against the schema. */
    public function test_status_values_fit_their_columns(): void
    {
        $this->assertLessThanOrEqual(30, max(array_map('strlen', array_keys(Order::PAYMENT_STATUSES))));
        $this->assertLessThanOrEqual(20, max(array_map('strlen', array_keys(Order::STATUSES))));
    }
}
