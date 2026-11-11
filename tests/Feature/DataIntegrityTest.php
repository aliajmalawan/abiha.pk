<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Schema shape (tables, keys, indexes, soft deletes) and the money/stock/history rules that depend on it. */
class DataIntegrityTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
    }

    private function fkTargets(string $table): array
    {
        return collect(Schema::getForeignKeys($table))->map(fn ($fk) => $fk['columns'][0].'→'.$fk['foreign_table'])->sort()->values()->all();
    }

    private function hasUnique(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['unique'] || $i['primary']) && $i['columns'] === $columns);
    }

    public function test_every_required_table_exists(): void
    {
        $tables = ['users', 'roles', 'permissions', 'categories', 'products', 'product_images', 'product_variants', 'attributes', 'attribute_values',
            'stock_movements', 'carts', 'cart_items', 'wishlists', 'wishlist_items', 'addresses', 'orders', 'order_items', 'order_addresses',
            'payments', 'payment_transactions', 'shipping_methods', 'shipments', 'shipment_events', 'coupons', 'coupon_usages', 'reviews', 'banners',
            'collections', 'pages', 'settings', 'newsletters', 'contact_messages', 'notifications', 'activity_logs'];
        foreach ($tables as $t) {
            $this->assertTrue(Schema::hasTable($t), "missing table $t");
        }
        $this->assertFalse(Schema::hasTable('inventory_movements'), 'the ledger is called stock_movements');
    }

    public function test_foreign_keys_protect_the_relationships_that_matter(): void
    {
        $this->assertContains('order_id→orders', $this->fkTargets('order_items'));
        $this->assertContains('product_id→products', $this->fkTargets('order_items'));
        $this->assertContains('variant_id→product_variants', $this->fkTargets('order_items'));
        $this->assertContains('order_id→orders', $this->fkTargets('payments'));
        $this->assertContains('order_id→orders', $this->fkTargets('payment_transactions'));
        $this->assertContains('shipment_id→shipments', $this->fkTargets('shipment_events'));
        $this->assertContains('order_id→orders', $this->fkTargets('shipments'));
        $this->assertContains('product_id→products', $this->fkTargets('product_variants'));
        $this->assertContains('product_id→products', $this->fkTargets('stock_movements'));
        $this->assertContains('user_id→users', $this->fkTargets('addresses'));
        $this->assertContains('order_id→orders', $this->fkTargets('coupon_usages'));
        $this->assertContains('coupon_id→coupons', $this->fkTargets('coupon_usages'));
        $this->assertContains('role_id→roles', $this->fkTargets('users'));
    }

    public function test_unique_constraints_stop_duplicates(): void
    {
        $this->assertTrue($this->hasUnique('users', ['email']));
        $this->assertTrue($this->hasUnique('products', ['slug']));
        $this->assertTrue($this->hasUnique('products', ['sku']));
        $this->assertTrue($this->hasUnique('product_variants', ['sku']));
        $this->assertTrue($this->hasUnique('orders', ['number']));
        $this->assertTrue($this->hasUnique('coupons', ['code']));
        $this->assertTrue($this->hasUnique('shipping_methods', ['code']));
        $this->assertTrue($this->hasUnique('payments', ['provider', 'reference']));
        $this->assertTrue($this->hasUnique('coupon_usages', ['order_id']));
    }

    public function test_tables_have_timestamps_and_archivable_records_use_soft_deletes(): void
    {
        foreach (['users', 'products', 'orders', 'shipments', 'categories', 'collections', 'coupons'] as $t) {
            $this->assertTrue(Schema::hasColumns($t, ['created_at', 'updated_at']), "$t timestamps");
        }
        foreach (['users', 'products', 'categories', 'collections', 'coupons'] as $t) {
            $this->assertTrue(Schema::hasColumn($t, 'deleted_at'), "$t soft deletes");
        }
        $this->assertFalse(Schema::hasColumn('orders', 'deleted_at'), 'orders are never deleted');
    }

    public function test_variants_support_size_colour_sku_and_their_own_stock(): void
    {
        $this->assertTrue(Schema::hasColumns('product_variants', ['sku', 'stock', 'price']));
        $this->assertTrue(Schema::hasTable('attribute_value_product_variant'));
        [$p, $v] = $this->variantProduct();
        $this->assertSame(3, $v['S']->fresh()->stock);
        $this->assertSame(4, $v['L']->fresh()->stock);
        $this->assertNotSame($v['S']->sku, $v['L']->sku);                                       // each variant has its own SKU
        $this->assertSame(['Black', 'S'], $v['S']->attributeValues()->pluck('value')->sort()->values()->all());   // size + colour
        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\ProductVariant::create(['product_id' => $p->id, 'sku' => $v['S']->sku, 'stock' => 1]);   // a duplicate SKU is refused by the database
    }

    public function test_the_last_unit_can_only_be_bought_once_and_stock_never_goes_negative(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 1]);

        $this->addToCart($p)->assertOk();
        $this->post(route('checkout.store'), $this->checkoutPayload(['email' => 'first@example.com']));
        $this->assertSame(0, $p->fresh()->stock);

        auth()->logout();
        $this->flushSession();
        $this->addToCart($p)->assertStatus(422);                                              // already gone
        $this->post(route('checkout.store'), $this->checkoutPayload(['email' => 'second@example.com']));
        $this->assertSame(1, Order::count());
        $this->assertSame(0, $p->fresh()->stock);
    }

    public function test_a_failed_order_leaves_no_partial_rows_and_stock_is_unchanged(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 5]);
        $this->addToCart($p, null, 2);
        $p->update(['stock' => 1]);                                                           // stock drops after the bag was filled

        $this->post(route('checkout.store'), $this->checkoutPayload());
        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(1, $p->fresh()->stock);
        $this->assertDatabaseMissing('stock_movements', ['reason' => 'sale']);
    }

    public function test_stock_changes_are_written_to_the_ledger_with_the_order(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'stock' => 5]);
        $this->addToCart($p, null, 2);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();

        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'order_id' => $order->id, 'change' => -2, 'balance_after' => 3, 'reason' => 'sale']);
    }

    public function test_deleting_a_product_never_deletes_order_history(): void
    {
        $this->shipping(250);
        $p = $this->product(['price' => 1000, 'name' => 'Archived Dress']);
        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();

        $p->delete();                                                                         // soft delete
        $this->assertSoftDeleted('products', ['id' => $p->id]);
        $order->refresh();
        $this->assertSame('Archived Dress', $order->items()->firstOrFail()->name);            // the name and price are snapshots
        $this->assertSame(1000, $order->items()->firstOrFail()->unit_price);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Archived Dress');
    }

    public function test_deleting_a_category_or_coupon_that_orders_depend_on_is_refused(): void
    {
        $this->shipping(250);
        $coupon = $this->coupon(['code' => 'KEEP']);
        $cat = \App\Models\Category::create(['name' => 'Held', 'slug' => 'held', 'is_active' => true]);
        $p = $this->product(['price' => 1000, 'category_id' => $cat->id]);
        $this->addToCart($p);
        $this->post(route('cart.coupon'), ['code' => 'KEEP']);
        $this->post(route('checkout.store'), $this->checkoutPayload());

        $this->actingAs($this->admin)->delete(route('admin.coupons.destroy', $coupon))->assertSessionHasErrors();
        $this->assertNull($coupon->fresh()->deleted_at);
        $this->delete(route('admin.categories.destroy', $cat->id))->assertSessionHasErrors();
        $this->assertNull($cat->fresh()->deleted_at);
    }

    /* ---------- shipments, shipment events, payment transactions ---------- */

    public function test_shipment_timeline_records_what_staff_really_did(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();
        $this->assertNull($order->shipment);                                                   // nothing is invented before staff act

        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->patch(route('admin.orders.tracking', $order), ['courier' => 'Some Courier', 'tracking_number' => 'T-1']);
        $this->patch(route('admin.orders.status', $order), ['status' => 'shipped']);

        $shipment = $order->fresh()->shipment;
        $this->assertSame(['Some Courier', 'T-1', 'shipped'], [$shipment->courier, $shipment->tracking_number, $shipment->status]);
        $this->assertNotNull($shipment->shipped_at);
        $events = ShipmentEvent::where('shipment_id', $shipment->id)->orderBy('id')->pluck('status')->all();
        $this->assertSame(['details_updated', 'shipped'], $events);
        $this->assertSame(['staff', 'staff'], ShipmentEvent::pluck('source')->all());           // never "courier": no courier is connected
    }

    public function test_payment_history_records_each_step_of_the_money(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'bank_transfer']));
        $order = Order::firstOrFail();
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-77']);
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => 1250])->assertSessionHasNoErrors();

        $rows = $order->paymentTransactions()->get();
        $this->assertSame(['initiated', 'transfer_reported', 'verified'], $rows->pluck('type')->all());
        $this->assertSame(['pending', 'pending', 'paid'], $rows->pluck('status')->all());
        $this->assertSame('TXN-77', $rows[1]->reference);
        $this->assertSame($this->admin->id, $rows[2]->user_id);
        $this->assertSame(1250, $rows[2]->amount);
    }

    /* ---------- notifications ---------- */

    public function test_staff_are_alerted_to_new_orders_and_transfers_but_customers_are_not(): void
    {
        $manager = $this->userWithRole('manager');
        $customer = $this->userWithRole('customer');
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'bank_transfer']));
        $order = Order::firstOrFail();
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-1']);

        $kinds = $this->admin->notifications()->get()->map(fn ($n) => $n->data['kind'])->sort()->values()->all();
        $this->assertSame(['new_order', 'payment_reported'], $kinds);
        $this->assertSame(0, $customer->notifications()->count());
        $this->assertSame(1, $manager->notifications()->where('data', 'like', '%new_order%')->count());   // can process orders
    }

    public function test_a_notification_failure_never_blocks_an_order(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        \Illuminate\Support\Facades\Schema::drop('notifications');                              // simulate the alert store being broken

        $this->post(route('checkout.store'), $this->checkoutPayload())->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    public function test_staff_see_and_clear_only_their_own_alerts(): void
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();
        $mine = $this->admin->notifications()->firstOrFail();
        $other = $this->userWithRole('admin');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('1 unread');
        $this->get(route('admin.notifications.index'))->assertOk()->assertSee('New order '.$order->number);
        $this->actingAs($other)->post(route('admin.notifications.read', $mine->id))->assertNotFound();   // not theirs
        $this->assertNull($mine->fresh()->read_at);

        $this->actingAs($this->admin)->post(route('admin.notifications.read', $mine->id))->assertRedirect('/admin/orders/'.$order->number);
        $this->assertNotNull($mine->fresh()->read_at);
    }
}
