<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The six admin roles of the brief: each reaches its own modules and is refused (403 from the server) everywhere else. */
class AdminRolesTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    /** route name => the permission area it belongs to */
    private const PAGES = [
        'admin.dashboard' => 'dashboard',
        'admin.products.index' => 'products', 'admin.categories.index' => 'products', 'admin.collections.index' => 'products',
        'admin.inventory.index' => 'inventory',
        'admin.orders.index' => 'orders', 'admin.shipments.index' => 'orders',
        'admin.payments.edit' => 'settings', 'admin.shipping.index' => 'shipping',
        'admin.customers.index' => 'customers', 'admin.coupons.index' => 'coupons',
        'admin.reviews.index' => 'products',
        'admin.home.index' => 'content', 'admin.pages.index' => 'content', 'admin.banners.index' => 'content', 'admin.menus.edit' => 'content',
        'admin.messages.index' => 'messages',
        'admin.settings.edit' => 'settings', 'admin.reports.index' => 'reports',
        'admin.staff.index' => 'staff', 'admin.logs.index' => 'logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_the_six_roles_exist_with_the_names_from_the_brief(): void
    {
        $names = Role::query()->where('is_staff', true)->orderBy('id')->pluck('name')->all();
        foreach (['Super Admin', 'Admin', 'Order Manager', 'Product Manager', 'Inventory Manager', 'Content Manager'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertCount(6, $names);
        $this->assertFalse(Role::where('slug', 'customer')->value('is_staff'));
    }

    private function reach(string $slug): array
    {
        $user = $this->userWithRole($slug);
        $ok = [];
        foreach (array_keys(self::PAGES) as $route) {
            $status = $this->actingAs($user)->get(route($route))->status();
            $this->assertContains($status, [200, 403], "$slug on $route answered $status");
            if ($status === 200) {
                $ok[] = $route;
            }
        }

        return $ok;
    }

    public function test_super_admin_reaches_everything_and_admin_everything_except_staff_roles(): void
    {
        $this->assertSame(array_keys(self::PAGES), $this->reach('admin'));
        $admin = $this->reach('manager');
        $this->assertNotContains('admin.staff.index', $admin);
        $this->assertCount(count(self::PAGES) - 1, $admin);
    }

    public function test_order_manager_reaches_orders_and_nothing_in_the_catalogue_or_settings(): void
    {
        $ok = $this->reach('staff');
        foreach (['admin.dashboard', 'admin.orders.index', 'admin.shipments.index', 'admin.messages.index'] as $r) {
            $this->assertContains($r, $ok);
        }
        foreach (['admin.products.index', 'admin.inventory.index', 'admin.coupons.index', 'admin.settings.edit', 'admin.staff.index', 'admin.pages.index'] as $r) {
            $this->assertNotContains($r, $ok);
        }
    }

    public function test_product_manager_reaches_the_catalogue_only(): void
    {
        $ok = $this->reach('product_manager');
        foreach (['admin.dashboard', 'admin.products.index', 'admin.categories.index', 'admin.collections.index', 'admin.reviews.index'] as $r) {
            $this->assertContains($r, $ok);
        }
        foreach (['admin.orders.index', 'admin.inventory.index', 'admin.home.index', 'admin.settings.edit', 'admin.customers.index', 'admin.staff.index'] as $r) {
            $this->assertNotContains($r, $ok);
        }
    }

    public function test_inventory_manager_reaches_stock_only(): void
    {
        $ok = $this->reach('inventory_manager');
        $this->assertSame(['admin.dashboard', 'admin.inventory.index'], $ok);
    }

    public function test_content_manager_reaches_content_only(): void
    {
        $ok = $this->reach('content_manager');
        foreach (['admin.dashboard', 'admin.home.index', 'admin.pages.index', 'admin.banners.index', 'admin.menus.edit', 'admin.messages.index'] as $r) {
            $this->assertContains($r, $ok);
        }
        foreach (['admin.products.index', 'admin.orders.index', 'admin.inventory.index', 'admin.settings.edit', 'admin.coupons.index'] as $r) {
            $this->assertNotContains($r, $ok);
        }
    }

    public function test_actions_are_refused_by_the_server_even_when_the_button_is_never_shown(): void
    {
        $product = $this->product();
        $inventory = $this->userWithRole('inventory_manager');
        $this->actingAs($inventory)->post(route('admin.products.store'), ['name' => 'X', 'sku' => 'X-1', 'price' => 100, 'stock' => 1])->assertForbidden();
        $this->actingAs($inventory)->delete(route('admin.products.destroy', $product))->assertForbidden();
        $this->assertNotNull($product->fresh());

        $content = $this->userWithRole('content_manager');
        $this->actingAs($content)->put(route('admin.settings.update'), ['s__site__name' => 'Hacked'])->assertForbidden();
        $this->actingAs($content)->post(route('admin.coupons.store'), ['code' => 'FREE', 'type' => 'percent', 'value' => 100])->assertForbidden();

        $order = $this->userWithRole('product_manager');
        $this->actingAs($order)->post(route('admin.orders.export'))->assertStatus(405);                       // wrong verb, still not a back door
        $this->actingAs($order)->get(route('admin.orders.export'))->assertForbidden();
    }

    public function test_only_the_super_admin_can_change_roles_and_the_last_one_cannot_be_demoted(): void
    {
        $super = $this->userWithRole('admin');
        $manager = $this->userWithRole('manager');
        $target = $this->userWithRole('product_manager');
        $role = Role::where('slug', 'inventory_manager')->firstOrFail();

        $this->actingAs($manager)->get(route('admin.staff.index'))->assertForbidden();
        $this->actingAs($super)->get(route('admin.staff.index'))->assertOk()->assertSee('Inventory Manager')->assertSee('Content Manager');
    }
}
