<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function placeOrder(?User $user = null, string $email = 'buyer@example.com'): Order
    {
        $this->shipping();
        $p = $this->product(['stock' => 20]);
        $client = $user ? $this->actingAs($user) : $this;   // sign in BEFORE filling the bag (account cart)
        $client->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => 1]);
        $client->post(route('checkout.store'), $this->checkoutPayload(['email' => $email]));

        return Order::latest('id')->firstOrFail();
    }

    public function test_registration_always_creates_a_customer(): void
    {
        $this->post(route('register'), [
            'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'role' => 'admin', 'is_active' => 1,
        ])->assertRedirect();

        $user = User::where('email', 'eve@example.com')->firstOrFail();
        $this->assertSame('customer', $user->role?->slug);
        $this->assertFalse($user->isAdmin());
        $this->assertAuthenticatedAs($user);
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $this->post(route('register'), ['name' => 'Eve', 'email' => 'e@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    }

    public function test_a_customer_cannot_see_or_cancel_someone_elses_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->placeOrder($owner);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('account.orders.show', $order))->assertForbidden();
        $this->actingAs($intruder)->post(route('account.orders.cancel', $order))->assertForbidden();
        $this->actingAs($owner)->get(route('account.orders.show', $order))->assertOk()->assertSee($order->number);
    }

    public function test_a_customer_cannot_edit_someone_elses_address(): void
    {
        $a = User::factory()->create();
        $address = $a->addresses()->create(['name' => 'A', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'Some long address']);
        $b = User::factory()->create();

        $this->actingAs($b)->delete(route('account.addresses.destroy', $address))->assertForbidden();
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    public function test_order_confirmation_needs_the_secret_token(): void
    {
        $order = $this->placeOrder();

        $this->get(route('order.confirmation', $order))->assertNotFound();
        $this->get(route('order.confirmation', ['order' => $order, 'token' => 'wrong']))->assertNotFound();
        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))->assertOk()->assertSee($order->number);
    }

    public function test_guest_tracking_requires_matching_order_number_and_email(): void
    {
        $order = $this->placeOrder(null, 'Real@Example.com');

        $this->post(route('track.lookup'), ['number' => $order->number, 'email' => 'other@example.com'])->assertSessionHasErrors('number');
        $this->from(route('track'))->followingRedirects()->post(route('track.lookup'), ['number' => 'ABH-NOPE', 'email' => 'real@example.com'])->assertSee('could not find an order');
        $this->post(route('track.lookup'), ['number' => $order->number, 'email' => 'real@example.com'])->assertOk()->assertSee('Order '.$order->number);
    }

    public function test_guest_wishlist_merges_into_the_account_on_login(): void
    {
        $p = $this->product();
        $this->postJson(route('wishlist.toggle', $p))->assertOk()->assertJson(['added' => true]);

        $user = User::factory()->create(['password' => 'Secret123']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123'])->assertRedirect();

        $this->assertTrue($user->wishlist->items()->where('product_id', $p->id)->exists());
    }

    public function test_wishlist_is_persistent_and_toggles_off(): void
    {
        $user = User::factory()->create();
        $p = $this->product();

        $this->actingAs($user)->postJson(route('wishlist.toggle', $p))->assertJson(['added' => true, 'count' => 1]);
        $this->actingAs($user)->postJson(route('wishlist.toggle', $p))->assertJson(['added' => false, 'count' => 0]);
    }

    public function test_wishlist_move_to_cart_removes_the_item(): void
    {
        $user = User::factory()->create();
        $p = $this->product();
        $this->actingAs($user)->postJson(route('wishlist.toggle', $p));

        $this->actingAs($user)->post(route('wishlist.move', $p))->assertSessionHasNoErrors();

        $this->assertSame(1, app(\App\Services\Cart\CartService::class)->count());
        $this->assertFalse($user->wishlist->items()->where('product_id', $p->id)->exists());
    }

    public function test_inactive_users_cannot_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'Secret123', 'is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'wrong', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'Secret123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'])
            ->assertSessionHasNoErrors();
    }

    public function test_account_pages_require_login(): void
    {
        foreach (['account.dashboard', 'account.orders', 'account.addresses', 'account.profile'] as $r) {
            $this->get(route($r))->assertRedirect(route('login'));
        }
    }

    public function test_password_reset_does_not_reveal_whether_an_email_exists(): void
    {
        $a = $this->post(route('password.email'), ['email' => 'ghost@example.com']);
        $b = $this->post(route('password.email'), ['email' => User::factory()->create()->email]);

        $a->assertSessionHas('status');
        $this->assertSame(session('status'), $a->getSession()->get('status'));
        $b->assertSessionHas('status');
    }
}
