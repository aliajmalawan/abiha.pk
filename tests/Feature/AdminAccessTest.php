<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.products.index'))->assertRedirect(route('admin.login'));
    }

    public function test_customers_cannot_enter_the_admin_area(): void
    {
        $customer = $this->userWithRole('customer');

        foreach (['admin.dashboard', 'admin.products.index', 'admin.orders.index', 'admin.settings.edit'] as $route) {
            $this->actingAs($customer)->get(route($route))->assertForbidden();
        }
    }

    public function test_a_user_with_no_role_is_not_staff(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_staff_role_only_reaches_what_its_permissions_allow(): void
    {
        $staff = $this->userWithRole('staff');

        // allowed (the Order Manager): dashboard, orders, messages
        foreach (['admin.dashboard', 'admin.orders.index', 'admin.messages.index'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertOk();
        }
        // forbidden: catalogue, inventory, reviews, coupons, shipping, content, settings, staff, logs, customers
        foreach (['admin.products.index', 'admin.inventory.index', 'admin.reviews.index', 'admin.categories.index', 'admin.coupons.index', 'admin.shipping.index', 'admin.pages.index',
            'admin.banners.index', 'admin.settings.edit', 'admin.staff.index', 'admin.logs.index', 'admin.customers.index'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }
    }

    public function test_manager_has_everything_except_staff_management(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($manager)->get(route('admin.settings.edit'))->assertOk();
        $this->actingAs($manager)->get(route('admin.staff.index'))->assertForbidden();
    }

    public function test_every_admin_page_renders_for_an_administrator(): void
    {
        $admin = $this->userWithRole('admin');
        $this->product();
        $this->shipping();
        $this->coupon();
        \Database\Seeders\AttributeSeeder::class;
        $this->seed(\Database\Seeders\AttributeSeeder::class);
        $this->seed(\Database\Seeders\SampleCmsSeeder::class);

        $routes = ['admin.dashboard', 'admin.products.index', 'admin.products.create', 'admin.attributes.index', 'admin.categories.index', 'admin.categories.create',
            'admin.collections.index', 'admin.collections.create', 'admin.inventory.index', 'admin.inventory.ledger', 'admin.orders.index', 'admin.coupons.index',
            'admin.coupons.create', 'admin.shipping.index', 'admin.shipping.create', 'admin.customers.index', 'admin.reviews.index', 'admin.messages.index',
            'admin.newsletter.index', 'admin.pages.index', 'admin.pages.create', 'admin.banners.index', 'admin.banners.create', 'admin.testimonials.index',
            'admin.testimonials.create', 'admin.home.index', 'admin.settings.edit', 'admin.staff.index', 'admin.logs.index'];
        foreach ($routes as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk();
        }
        $section = \App\Models\HomeSection::where('type', 'products')->first();
        $this->actingAs($admin)->get(route('admin.home.edit', $section))->assertOk();

        // pages that need a record: product edit, order detail + invoice, customer, review/message detail
        $product = $this->product();
        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->addToCart($product);
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'bank_transfer']));
        $order = \App\Models\Order::firstOrFail();
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->assertSee($order->number);
        $this->actingAs($admin)->get(route('admin.orders.invoice', $order))->assertOk();
        $customer = $this->userWithRole('customer');
        $this->actingAs($admin)->get(route('admin.customers.show', $customer->id))->assertOk();
        $this->actingAs($admin)->get(route('admin.categories.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.coupons.edit', \App\Models\Coupon::firstOrFail()->id))->assertOk();
    }

    public function test_inactive_staff_are_locked_out(): void
    {
        $admin = $this->userWithRole('admin', ['is_active' => false]);

        // A disabled account is signed out on its next request and sent to the login page with an explanation.
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_login_lands_in_the_admin_area(): void
    {
        $admin = $this->userWithRole('admin', ['password' => 'Secret123456']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'Secret123456'])->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_the_last_administrator_cannot_be_demoted_and_you_cannot_change_yourself(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('admin');
        $customerRole = Role::where('slug', 'customer')->value('id');

        $this->actingAs($admin)->patch(route('admin.staff.update', $admin), ['role_id' => $customerRole])->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch(route('admin.staff.update', $other), ['role_id' => $customerRole])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('customer', $other->fresh()->role->slug);
    }

    public function test_granting_staff_access_needs_an_existing_account_and_is_logged(): void
    {
        $admin = $this->userWithRole('admin');
        $person = $this->userWithRole('customer');
        $staffRole = Role::where('slug', 'staff')->value('id');

        $this->actingAs($admin)->post(route('admin.staff.grant'), ['email' => 'ghost@example.com', 'role_id' => $staffRole])->assertSessionHasErrors('email');
        $this->actingAs($admin)->post(route('admin.staff.grant'), ['email' => $person->email, 'role_id' => $staffRole])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($person->fresh()->isAdmin());
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.granted', 'user_id' => $admin->id]);
    }

    public function test_create_admin_command_makes_a_staff_account_without_default_credentials(): void
    {
        $this->artisan('abiha:create-admin', ['email' => 'owner@example.com', '--password' => 'a-very-strong-pass'])->assertSuccessful();
        $user = \App\Models\User::where('email', 'owner@example.com')->first();
        $this->assertTrue($user->isSuperAdmin());

        $this->artisan('abiha:create-admin', ['email' => 'weak@example.com', '--password' => 'short'])->assertFailed();
        $this->assertNull(\App\Models\User::where('email', 'weak@example.com')->first());
    }
}
