<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
    }

    private function advanceTo(Order $order, string $final): void
    {
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $s) {
            if ($order->fresh()->canTransitionTo($s)) {            // skip steps already passed (e.g. payment verification confirms the order)
                app(OrderService::class)->changeStatus($order->fresh(), $s, $this->admin);
            }
            if ($s === $final) {
                break;
            }
        }
    }

    /* ---------------- returns & refunds ---------------- */

    public function test_only_delivered_orders_can_be_returned(): void
    {
        [$order] = $this->placeGuestOrder();

        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'returned'])->assertSessionHasErrors('status');
        $this->advanceTo($order, 'delivered');
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'returned', 'note' => 'Wrong size'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('returned', $order->fresh()->status);
        // final state
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    }

    public function test_return_restocks_only_when_the_box_is_ticked(): void
    {
        [$a, $pa] = $this->placeGuestOrder('cod', 1000, 2);
        $this->assertSame(8, $pa->fresh()->stock);
        $this->advanceTo($a, 'delivered');
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $a), ['status' => 'returned'])->assertSessionHasNoErrors();
        $this->assertSame(8, $pa->fresh()->stock);                                         // goods not inspected yet: no restock

        $this->flushSession();
        [$b, $pb] = $this->placeGuestOrder('cod', 1000, 3);
        $this->advanceTo($b, 'delivered');
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $b), ['status' => 'returned', 'restock' => 1])->assertSessionHasNoErrors();
        $this->assertSame(10, $pb->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $pb->id, 'order_id' => $b->id, 'change' => 3, 'reason' => 'return']);
    }

    public function test_refund_is_a_record_that_needs_a_paid_cancelled_or_returned_order(): void
    {
        [$order] = $this->placeGuestOrder('bank_transfer');

        // unpaid: nothing to refund
        $this->actingAs($this->admin)->post(route('admin.orders.payment.refund', $order))->assertSessionHasErrors('payment');

        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total])->assertSessionHasNoErrors();
        // paid but still active: cancel/return first
        $this->actingAs($this->admin)->post(route('admin.orders.payment.refund', $order))->assertSessionHasErrors('payment');

        $this->advanceTo($order, 'delivered');
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'returned'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.orders.payment.refund', $order), ['note' => 'Bank ref 123'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.refund_recorded']);
        $this->actingAs($this->admin)->post(route('admin.orders.payment.refund', $order))->assertSessionHasErrors('payment');   // not twice
    }

    /* ---------------- dashboard ---------------- */

    public function test_dashboard_totals_exclude_cancelled_and_returned_orders(): void
    {
        [$keep] = $this->placeGuestOrder();                        // total 2,250
        $this->flushSession();
        [$cancel] = $this->placeGuestOrder('cod', 5000, 1);        // total 5,250 → cancelled
        app(OrderService::class)->changeStatus($cancel, 'cancelled', $this->admin);

        $res = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $res->assertSee('Total revenue')->assertSee('PKR 2,250')->assertDontSee('PKR 7,500');
        $this->assertSame(2250, (int) $res->viewData('stats')['revenue']);
        $this->assertSame(2, $res->viewData('stats')['orders']);          // total orders counts every order
        $this->assertSame(1, $res->viewData('stats')['pending']);
        $res->assertSee('Monthly revenue')->assertSee('<svg', false);
        $this->assertCount(12, $res->viewData('months'));
    }

    public function test_dashboard_low_stock_counts_simple_and_variant_products(): void
    {
        $this->product(['stock' => 2, 'low_stock_threshold' => 3]);            // low
        $this->product(['stock' => 50]);                                       // fine
        $this->product(['stock' => 0]);                                        // sold out
        [$vp, $v] = $this->variantProduct();                                   // S has 3 (low at threshold 3), L has 4
        $vp->update(['low_stock_threshold' => 3]);

        $stats = $this->actingAs($this->admin)->get(route('admin.dashboard'))->viewData('stats');
        $this->assertSame(2, $stats['low_stock']);
        $this->assertSame(1, $stats['out_of_stock']);                         // only the stock-0 product: a variant product with stock elsewhere is still sellable
    }

    /* ---------------- exports ---------------- */

    public function test_orders_export_streams_csv_honours_filters_and_neutralises_formulas(): void
    {
        [$a] = $this->placeGuestOrder('cod', 1000, 1, ['name' => '=HYPERLINK("http://x")']);
        $this->flushSession();
        [$b] = $this->placeGuestOrder('bank_transfer', 3000, 1);

        $all = $this->actingAs($this->admin)->get(route('admin.orders.export'));
        $all->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $all->streamedContent();
        $this->assertStringContainsString($a->number, $csv);
        $this->assertStringContainsString($b->number, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);                  // formula neutralised
        $this->assertStringStartsWith("\xEF\xBB\xBFOrder,Date,Status", $csv);

        $filtered = $this->actingAs($this->admin)->get(route('admin.orders.export', ['method' => 'bank_transfer']))->streamedContent();
        $this->assertStringContainsString($b->number, $filtered);
        $this->assertStringNotContainsString($a->number, $filtered);
        $this->assertDatabaseHas('activity_logs', ['action' => 'orders.exported']);
    }

    public function test_exports_require_the_right_permission(): void
    {
        $customer = $this->userWithRole('customer');
        $staff = $this->userWithRole('staff');

        $this->actingAs($customer)->get(route('admin.orders.export'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.orders.export'))->assertOk();                 // staff hold orders.view
        $this->actingAs($staff)->get(route('admin.customers.export'))->assertForbidden();       // but not customers.manage
        $this->actingAs($staff)->get(route('admin.reports.index'))->assertForbidden();          // nor reports.view
        $this->actingAs($this->userWithRole('manager'))->get(route('admin.reports.index'))->assertOk();
    }

    public function test_customer_and_inventory_exports(): void
    {
        $this->placeGuestOrder();
        $cust = User::factory()->create(['name' => 'Export Me']);
        $cust->role_id = \App\Models\Role::where('slug', 'customer')->value('id');
        $cust->save();

        $csv = $this->actingAs($this->admin)->get(route('admin.customers.export'))->streamedContent();
        $this->assertStringContainsString($cust->email, $csv);
        $this->assertStringNotContainsString($this->admin->email, $csv);                        // staff are not customers

        $stock = $this->actingAs($this->admin)->get(route('admin.inventory.export'))->streamedContent();
        $this->assertStringContainsString('SKU', $stock);
        $this->assertStringContainsString('On hand', $stock);

        $ledger = $this->actingAs($this->admin)->get(route('admin.inventory.ledger.export'))->streamedContent();
        $this->assertStringContainsString('Balance after', $ledger);
        $this->assertStringContainsString('sale', $ledger);
    }

    /* ---------------- reports ---------------- */

    private function datedOrders(): array
    {
        [$a] = $this->placeGuestOrder('cod', 1000, 2);              // 2,250
        $this->flushSession();
        [$b] = $this->placeGuestOrder('bank_transfer', 3000, 1);    // 3,250
        $this->flushSession();
        [$c] = $this->placeGuestOrder('cod', 9000, 1);              // 9,250 → cancelled, must never count
        app(OrderService::class)->changeStatus($c, 'cancelled', $this->admin);

        Order::whereKey($a->id)->update(['created_at' => '2025-03-10 10:00:00']);
        Order::whereKey($b->id)->update(['created_at' => '2025-03-25 15:00:00', 'payment_status' => 'paid']);
        Order::whereKey($c->id)->update(['created_at' => '2025-04-02 09:00:00']);

        return [$a, $b, $c];
    }

    public function test_sales_report_groups_by_day_month_and_year_and_excludes_cancelled(): void
    {
        $this->datedOrders();
        $q = ['type' => 'sales', 'from' => '2025-01-01', 'to' => '2025-12-31'];

        $month = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['period' => 'month']))->assertOk()->viewData('sales');
        $this->assertCount(1, $month);                                                         // April's only order was cancelled
        $this->assertSame('2025-03', $month[0]->period);
        $this->assertSame([2, 5500, 5500, 3250], [$month[0]->orders, $month[0]->revenue, $month[0]->gross + $month[0]->shipping, $month[0]->collected]);

        $day = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['period' => 'day']))->viewData('sales');
        $this->assertSame(['2025-03-10', '2025-03-25'], $day->pluck('period')->all());

        $year = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['period' => 'year']))->viewData('sales');
        $this->assertSame('2025', $year[0]->period);
        $this->assertSame(5500, $year[0]->revenue);
    }

    public function test_product_customer_revenue_and_order_reports(): void
    {
        [$a, $b] = $this->datedOrders();
        $q = ['from' => '2025-01-01', 'to' => '2025-12-31'];

        $products = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => 'products']))->viewData('products');
        $this->assertCount(2, $products);                                                      // cancelled order's product excluded
        $this->assertSame(3000, $products->first()->revenue);                                   // sorted by revenue

        $customers = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => 'customers']))->viewData('customers');
        $this->assertSame(1, $customers->count());                                              // both orders used the same checkout email
        $this->assertSame([2, 5500], [$customers[0]->orders, $customers[0]->spent]);

        $rev = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => 'revenue']))->viewData('breakdown');
        $this->assertSame(['bank_transfer', 'cod'], collect($rev['method'])->pluck('label')->sort()->values()->all());
        $this->assertContains('cancelled', collect($rev['status'])->pluck('label')->all());     // nothing hidden in the status view

        $orders = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => 'orders', 'status' => 'pending']))->viewData('orders');
        $this->assertCount(2, $orders);
    }

    public function test_report_csv_exports_and_range_limit(): void
    {
        $this->datedOrders();
        $q = ['from' => '2025-01-01', 'to' => '2025-12-31', 'export' => 'csv'];

        $csv = $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => 'sales', 'period' => 'month']))->assertOk()->streamedContent();
        $this->assertStringContainsString('2025-03,2,', $csv);
        foreach (['products' => 'Units sold', 'customers' => 'Total spent', 'revenue' => 'Payment method', 'orders' => 'Order,Date'] as $type => $needle) {
            $this->assertStringContainsString($needle, $this->actingAs($this->admin)->get(route('admin.reports.index', $q + ['type' => $type]))->streamedContent());
        }

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['type' => 'sales', 'from' => '2000-01-01', 'to' => '2026-01-01']))->assertStatus(422);
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['type' => 'sales', 'from' => '2025-05-01', 'to' => '2025-01-01']))->assertSessionHasErrors('to');
        $this->assertDatabaseHas('activity_logs', ['action' => 'report.exported']);
    }

    public function test_timezone_is_pakistan_so_todays_sales_match_the_business_day(): void
    {
        $this->assertSame('Asia/Karachi', config('app.timezone'));
    }
}
