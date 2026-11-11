<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Every dashboard number comes from the database. These tests change the data and check the numbers move with it. */
class DashboardOverviewTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        $this->shipping(250);
    }

    private function buy(int $price, int $qty, string $name = 'Dash Dress', string $email = 'b@example.com'): Order
    {
        auth()->logout();
        $this->flushSession();
        $p = \App\Models\Product::firstWhere('name', $name) ?? $this->product(['name' => $name, 'price' => $price, 'stock' => 100]);
        $this->addToCart($p, null, $qty);
        $this->post(route('checkout.store'), $this->checkoutPayload(['email' => $email]));

        return Order::latest('id')->firstOrFail();
    }

    private function stats(): array
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->viewData('stats');
    }

    public function test_an_empty_shop_shows_zeros_not_made_up_numbers(): void
    {
        $s = $this->stats();
        foreach (['revenue', 'orders', 'units_sold', 'avg_order', 'pending', 'completed', 'products', 'low_stock'] as $k) {
            $this->assertSame(0, (int) $s[$k], $k);
        }
        $res = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('No orders yet.');
        $this->assertCount(0, $res->viewData('recentOrders'));
        $this->assertCount(0, $res->viewData('topAllTime'));
    }

    public function test_every_headline_figure_follows_the_orders_and_catalogue(): void
    {
        $this->buy(1000, 2, 'Alpha');                         // 2,000 + 250 shipping = 2,250
        $b = $this->buy(3000, 1, 'Beta');                     // 3,250
        $this->userWithRole('customer');
        $this->userWithRole('customer');

        $s = $this->stats();
        $this->assertSame(5500, $s['revenue']);                // total revenue = what orders add up to
        $this->assertSame(3, $s['units_sold']);                // total sales = items sold
        $this->assertSame(2, $s['orders']);
        $this->assertSame(2750, $s['avg_order']);
        $this->assertSame(2, $s['pending']);
        $this->assertSame(0, $s['completed']);
        $this->assertSame(2, $s['customers']);                 // staff are not customers
        $this->assertSame(2, $s['products']);
        $this->assertSame(0, $s['collected']);                 // nothing is paid yet

        $o = app(OrderService::class);
        $o->changeStatus($b, 'confirmed', $this->admin);
        $o->changeStatus($b->fresh(), 'processing', $this->admin);
        $o->changeStatus($b->fresh(), 'shipped', $this->admin);
        $o->changeStatus($b->fresh(), 'delivered', $this->admin);
        $s = $this->stats();
        $this->assertSame([1, 1], [$s['pending'], $s['completed']]);
    }

    public function test_cancelled_orders_leave_revenue_sales_and_best_sellers(): void
    {
        $this->buy(1000, 2, 'Gamma');
        $gone = $this->buy(5000, 4, 'Delta');
        app(OrderService::class)->changeStatus($gone, 'cancelled', $this->admin);

        $res = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $s = $res->viewData('stats');
        $this->assertSame([2250, 2, 2], [$s['revenue'], $s['units_sold'], $s['orders']]);
        $this->assertSame(['Gamma'], $res->viewData('topAllTime')->pluck('name')->all());
        $res->assertDontSee('Delta');
    }

    public function test_best_sellers_are_ranked_by_units_sold(): void
    {
        $this->buy(100, 5, 'Cheap');                                   // many units, small money
        $this->buy(9000, 1, 'Pricey');                                 // one unit, big money
        $this->buy(100, 2, 'Cheap');

        $top = $this->actingAs($this->admin)->get(route('admin.dashboard'))->viewData('topAllTime');
        $this->assertSame(['Cheap', 'Pricey'], $top->pluck('name')->all());
        $this->assertSame([7, 1], $top->pluck('units')->all());
    }

    public function test_low_stock_and_out_of_stock_follow_the_inventory(): void
    {
        $this->product(['stock' => 2, 'low_stock_threshold' => 5]);
        $this->product(['stock' => 0]);
        $this->product(['stock' => 40]);

        $s = $this->stats();
        $this->assertSame([3, 1, 1], [$s['products'], $s['low_stock'], $s['out_of_stock']]);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Low-stock products')->assertSee('Out of stock');
    }

    public function test_recent_orders_are_the_newest_and_charts_use_real_figures(): void
    {
        $first = $this->buy(1000, 1, 'Eta', 'one@example.com');
        $second = $this->buy(2000, 1, 'Theta', 'two@example.com');

        $res = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame([$second->number, $first->number], $res->viewData('recentOrders')->pluck('number')->all());
        $res->assertSee($second->number)->assertSee('Recent orders');

        $months = $res->viewData('months');
        $this->assertCount(12, $months);
        $this->assertSame(1250 + 2250, (int) $months->last()->revenue);                   // this month holds both orders
        $this->assertSame(0, (int) $months->first()->revenue);
        $today = $res->viewData('daily')->firstWhere('period', now()->format('Y-m-d'));
        $this->assertSame(3500, (int) $today->revenue);
        $this->assertSame(2, (int) $today->orders);

        $html = $res->getContent();
        $this->assertStringContainsString('data-chart=', $html);                          // the chart is fed from this data
        $this->assertStringContainsString('3,500', $html);
    }

    public function test_a_customer_cannot_see_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }
}
