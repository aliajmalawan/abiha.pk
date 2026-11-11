<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The customer account end to end: register, sign in/out, forgot/reset, profile, addresses, orders, tracking, wishlist. */
class CustomerAccountTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function register(array $over = []): void
    {
        $this->post(route('register'), array_merge([
            'name' => 'Sana Malik', 'email' => 'sana@example.com', 'phone' => '0300 1234567',
            'password' => 'Sup3rSecret!', 'password_confirmation' => 'Sup3rSecret!',
        ], $over));
    }

    public function test_register_signs_in_and_creates_a_customer_with_a_hashed_password(): void
    {
        $this->register();
        $user = User::where('email', 'sana@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('customer', $user->role->slug);
        $this->assertNotSame('Sup3rSecret!', $user->password);
        $this->assertTrue(Hash::check('Sup3rSecret!', $user->password));
        $this->get(route('account.dashboard'))->assertOk();
    }

    public function test_registration_refuses_a_duplicate_email_and_a_mismatched_password(): void
    {
        $this->register();
        auth()->logout();
        $this->register(['name' => 'Someone Else']);                                         // same email again
        $this->assertSame(1, User::where('email', 'sana@example.com')->count());

        $this->register(['email' => 'other@example.com', 'password_confirmation' => 'different1A']);
        $this->assertNull(User::where('email', 'other@example.com')->first());
    }

    public function test_login_and_logout(): void
    {
        $u = $this->userWithRole('customer', ['email' => 'login@example.com', 'password' => 'Sup3rSecret!']);

        $this->post('/login', ['email' => 'login@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post('/login', ['email' => 'login@example.com', 'password' => 'Sup3rSecret!']);
        $this->assertAuthenticatedAs($u);

        $this->post(route('logout'));
        $this->assertGuest();
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_forgot_and_reset_password_end_to_end_with_a_single_use_token(): void
    {
        Notification::fake();
        $u = $this->userWithRole('customer', ['email' => 'forgot@example.com', 'password' => 'OldPassw0rd!']);

        $this->post(route('password.email'), ['email' => 'forgot@example.com'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('password.update'), ['token' => 'wrong', 'email' => 'forgot@example.com', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OldPassw0rd!', $u->fresh()->password));

        $this->post(route('password.update'), ['token' => $token, 'email' => 'forgot@example.com', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');

        $this->post(route('password.update'), ['token' => $token, 'email' => 'forgot@example.com', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewPassw0rd!', $u->fresh()->password));

        $this->post(route('password.update'), ['token' => $token, 'email' => 'forgot@example.com', 'password' => 'Another1Pass!', 'password_confirmation' => 'Another1Pass!'])->assertSessionHasErrors('email');   // token is used up
        $this->assertTrue(Hash::check('NewPassw0rd!', $u->fresh()->password));
    }

    public function test_profile_can_be_updated_and_changing_the_email_needs_the_current_password(): void
    {
        $u = $this->userWithRole('customer', ['email' => 'p@example.com', 'password' => 'Sup3rSecret!']);
        $this->actingAs($u)->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'p@example.com', 'phone' => '0300 1234567'])->assertSessionHasNoErrors();
        $this->assertSame('New Name', $u->fresh()->name);

        $this->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'changed@example.com'])->assertSessionHasErrors('email_password');
        $this->assertSame('p@example.com', $u->fresh()->email);

        $this->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'changed@example.com', 'email_password' => 'Sup3rSecret!'])->assertSessionHasNoErrors();
        $this->assertSame('changed@example.com', $u->fresh()->email);
    }

    public function test_password_change(): void
    {
        $u = $this->userWithRole('customer', ['password' => 'Sup3rSecret!']);
        $this->actingAs($u)->put(route('account.password.update'), ['current_password' => 'Sup3rSecret!', 'password' => 'Brand-New-9pw', 'password_confirmation' => 'Brand-New-9pw'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Brand-New-9pw', $u->fresh()->password));
    }

    public function test_address_book_add_edit_default_and_delete_with_pakistani_phone_rules(): void
    {
        $u = $this->userWithRole('customer');
        $addr = ['label' => 'Home', 'name' => 'Sana Malik', 'phone' => '+92 300 1234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'House 1, Street 2, Model Town'];

        $this->actingAs($u)->post(route('account.addresses.store'), ['phone' => '12345'] + $addr)->assertSessionHasErrors('phone');
        $this->post(route('account.addresses.store'), $addr)->assertSessionHasNoErrors();
        $first = $u->addresses()->firstOrFail();
        $this->assertSame('03001234567', $first->phone);
        $this->assertTrue($first->is_default);                                               // the first address becomes the default

        $this->post(route('account.addresses.store'), ['label' => 'Work', 'city' => 'Karachi', 'province' => 'Sindh', 'is_default' => 1] + $addr);
        $this->assertSame('Karachi', $u->addresses()->where('is_default', true)->firstOrFail()->city);
        $this->assertSame(1, $u->addresses()->where('is_default', true)->count());

        $this->put(route('account.addresses.update', $first), ['city' => 'Multan'] + $addr)->assertSessionHasNoErrors();
        $this->assertSame('Multan', $first->fresh()->city);

        $default = $u->addresses()->where('is_default', true)->firstOrFail();
        $this->delete(route('account.addresses.destroy', $default));
        $this->assertSame(1, $u->addresses()->where('is_default', true)->count());           // another one takes over
    }

    public function test_order_history_details_and_tracking_show_the_customers_own_orders_only(): void
    {
        $this->shipping(250);
        $u = $this->userWithRole('customer', ['email' => 'buyer@example.com']);
        $other = $this->userWithRole('customer', ['email' => 'other@example.com']);
        $this->actingAs($u);
        $this->addToCart($this->product(['name' => 'History Dress', 'price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();
        $this->assertSame($u->id, $order->user_id);

        $this->get(route('account.orders'))->assertOk()->assertSee($order->number);
        $this->get(route('account.orders.show', $order))->assertOk()->assertSee('History Dress')->assertSee('1,250');
        $this->get(route('account.dashboard'))->assertOk()->assertSee($order->number);

        $this->actingAs($other)->get(route('account.orders.show', $order))->assertForbidden();
        $this->get(route('account.orders'))->assertDontSee($order->number);

        auth()->logout();
        $this->flushSession();
        $this->post(route('track.lookup'), ['number' => $order->number, 'email' => 'buyer@example.com'])->assertOk()->assertSee('History Dress');   // guest tracking
        $this->post(route('track.lookup'), ['number' => $order->number, 'email' => 'wrong@example.com'])->assertSessionHasErrors();
    }

    public function test_wishlist_add_remove_and_it_persists_for_the_account(): void
    {
        $u = $this->userWithRole('customer');
        $p = $this->product(['name' => 'Wish Dress']);
        $this->actingAs($u)->postJson(route('wishlist.toggle', $p))->assertOk();
        $this->get(route('wishlist'))->assertOk()->assertSee('Wish Dress');

        auth()->logout();
        $this->flushSession();
        $this->actingAs($u)->get(route('wishlist'))->assertSee('Wish Dress');                // still there in a new session

        $this->postJson(route('wishlist.toggle', $p))->assertOk();
        $this->get(route('wishlist'))->assertDontSee('Wish Dress');
    }

    public function test_an_archived_customer_cannot_sign_in_but_their_orders_remain(): void
    {
        $this->shipping(250);
        $u = $this->userWithRole('customer', ['email' => 'gone@example.com', 'password' => 'Sup3rSecret!']);
        $this->actingAs($u);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['email' => 'gone@example.com']));
        $order = Order::firstOrFail();
        auth()->logout();
        $this->flushSession();

        $u->delete();                                                                         // soft delete: archived, not erased
        $this->assertSoftDeleted('users', ['id' => $u->id]);
        $this->post('/login', ['email' => 'gone@example.com', 'password' => 'Sup3rSecret!'])->assertSessionHasErrors();
        $this->assertGuest();

        $order->refresh();
        $this->assertSame($u->id, $order->user_id);                                           // the order still points at the archived account
        $this->assertSame('gone@example.com', $order->user->email);
        $this->assertSame(1, $order->items()->count());
        $this->assertNotNull($order->shippingAddress);
    }
}
