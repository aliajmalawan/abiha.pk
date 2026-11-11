<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** CSRF, authorization, rate limiting, sessions, passwords and host-header protection. */
class AccessControlTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * Sign in as another user mid-test. A real browser session belongs to ONE user, and the test kernel keeps one
     * app instance alive (so the guard caches its user), so reset both to behave like a fresh request.
     */
    private function as(User $user): static
    {
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $this->actingAs($user);
    }

    /* ---------------- CSRF ---------------- */

    /** Laravel skips CSRF checks when the environment is "testing", so switch it off to test the real behaviour. */
    private function enforceCsrf(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_state_changing_requests_without_a_csrf_token_are_rejected(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();
        $admin = $this->userWithRole('admin');
        $p = $this->product();

        foreach ([
            ['post', route('login'), []], ['post', route('register'), []], ['post', route('contact.send'), []], ['post', route('newsletter'), []],
            ['post', route('cart.add'), ['product_id' => $p->id]], ['post', route('checkout.store'), []], ['post', route('wishlist.toggle', $p), []],
            ['post', route('admin.login.store'), []], ['post', route('password.email'), []],
        ] as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertStatus(419);
        }
        foreach ([['put', route('account.profile.update')], ['put', route('account.password.update')], ['post', route('logout')]] as [$method, $url]) {
            $this->actingAs($user)->{$method}($url, [])->assertStatus(419);
        }
        foreach ([['post', route('admin.products.store')], ['post', route('admin.discounts.apply')], ['post', route('admin.staff.grant')], ['put', route('admin.settings.update')], ['post', route('admin.inventory.adjust')]] as [$method, $url]) {
            $this->actingAs($admin)->{$method}($url, [])->assertStatus(419);
        }
    }

    public function test_a_valid_token_is_accepted_and_a_wrong_one_is_not(): void
    {
        $this->enforceCsrf();
        $p = $this->product(['stock' => 3]);

        $this->withSession(['_token' => 'right-token'])->postJson(route('cart.add'), ['product_id' => $p->id], ['X-CSRF-TOKEN' => 'right-token'])->assertOk();
        $this->withSession(['_token' => 'right-token'])->postJson(route('cart.add'), ['product_id' => $p->id], ['X-CSRF-TOKEN' => 'forged'])->assertStatus(419);
        $this->withSession(['_token' => 'right-token'])->post(route('contact.send'), ['_token' => 'forged'])->assertStatus(419);
    }

    public function test_only_payment_webhooks_are_exempt_from_csrf(): void
    {
        $this->enforceCsrf();

        $this->postJson('/webhooks/payments/unknown', [])->assertNotFound();                 // reached the controller (no 419), then 404: unknown provider
        $this->assertSame(['webhooks/payments/*'], $this->exemptCsrfPaths());
    }

    private function exemptCsrfPaths(): array
    {
        $src = File::get(base_path('bootstrap/app.php'));
        preg_match("/validateCsrfTokens\(except: \[(.*?)\]\)/s", $src, $m);

        return array_map(fn ($s) => trim($s, " '\""), explode(',', $m[1] ?? ''));
    }

    public function test_every_post_form_in_the_views_carries_a_csrf_field(): void
    {
        $missing = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = File::get($file->getPathname());
            $posts = preg_match_all('/<form\b[^>]*method="(?:POST|post)"/', $src);
            $tokens = preg_match_all('/@csrf\b|name="_token"/', $src);
            if ($posts > $tokens) {
                $missing[] = $file->getFilename()." ({$posts} forms, {$tokens} tokens)";
            }
        }
        $this->assertSame([], $missing, 'POST forms without @csrf: '.implode(', ', $missing));
    }

    public function test_javascript_requests_send_the_csrf_header(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("'X-CSRF-TOKEN': csrf()", $js);
        $this->assertStringContainsString('meta[name="csrf-token"]', $js);
        $this->get(route('home'))->assertSee('<meta name="csrf-token"', false);
    }

    /* ---------------- authorization & RBAC ---------------- */

    public function test_every_admin_route_requires_staff_login_and_a_specific_permission(): void
    {
        $open = ['admin.login', 'admin.login.store'];
        $problems = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin') || in_array($route->getName(), $open, true)) {
                continue;
            }
            $checked++;
            $mw = $route->gatherMiddleware();
            $hasPerm = collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
            if (! in_array('auth', $mw, true) || ! in_array('admin', $mw, true) || ! $hasPerm) {
                $problems[] = $route->methods()[0].' '.$route->uri();
            }
        }
        $this->assertGreaterThan(60, $checked);
        $this->assertSame([], $problems, 'Admin routes missing auth/admin/can: '.implode(', ', $problems));
    }

    public function test_every_customer_area_route_requires_login(): void
    {
        $problems = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'account'))
            ->reject(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->uri())->all();

        $this->assertSame([], $problems);
    }

    public function test_permissions_are_enforced_per_role_with_no_escalation_path(): void
    {
        $staff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $victim = $this->userWithRole('customer');
        $staffRole = \App\Models\Role::where('slug', 'staff')->value('id');

        $this->actingAs($staff)->post(route('admin.staff.grant'), ['email' => $victim->email, 'role_id' => $staffRole])->assertForbidden();
        $this->actingAs($manager)->post(route('admin.staff.grant'), ['email' => $victim->email, 'role_id' => $staffRole])->assertForbidden();
        $this->actingAs($manager)->patch(route('admin.staff.update', $staff), ['role_id' => \App\Models\Role::where('slug', 'admin')->value('id')])->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.products.destroy', $this->product()))->assertForbidden();
        $this->assertFalse($victim->fresh()->isAdmin());
    }

    public function test_a_customer_can_only_touch_their_own_records(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $address = $b->addresses()->create(['name' => 'B', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'Private house, Lahore']);
        $this->shipping();
        $p = $this->product(['stock' => 20]);
        $this->actingAs($b)->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => 3]);
        $this->actingAs($b)->post(route('checkout.store'), $this->checkoutPayload());
        $order = Order::firstOrFail();

        $this->as($a)->get(route('account.orders.show', $order))->assertForbidden();
        $this->actingAs($a)->post(route('account.orders.cancel', $order))->assertForbidden();
        $this->actingAs($a)->put(route('account.addresses.update', $address), ['name' => 'Hacked', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Karachi', 'address' => 'Somewhere else entirely'])->assertForbidden();
        $this->actingAs($a)->delete(route('account.addresses.destroy', $address))->assertForbidden();
        $this->assertSame('Private house, Lahore', $address->fresh()->address);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_cart_line_keys_cannot_reach_another_visitors_cart(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $p = Product::query()->find($this->product(['stock' => 9])->id);
        $this->actingAs($b)->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => 4]);

        $this->actingAs($a)->patchJson(route('cart.update', $p->id.'-0'), ['quantity' => 1]);          // same key, A's own (empty) cart
        $this->actingAs($a)->deleteJson(route('cart.remove', $p->id.'-0'));

        $this->assertSame(4, (int) \App\Models\CartItem::whereHas('cart', fn ($c) => $c->where('user_id', $b->id))->value('quantity'));
    }

    public function test_blocked_accounts_lose_access_on_their_next_request(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123456'])->assertRedirect();
        $this->get(route('account.dashboard'))->assertOk();

        User::whereKey($user->id)->update(['is_active' => false]);                 // staff block the customer
        \Illuminate\Support\Facades\Auth::forgetGuards();                           // the next request loads the user afresh, as in real life

        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ---------------- rate limiting ---------------- */

    private function hammer(callable $request, int $allowed, string $label): void
    {
        for ($i = 1; $i <= $allowed; $i++) {
            $this->assertNotSame(429, $request()->getStatusCode(), "{$label}: request {$i} of {$allowed} was throttled too early");
        }
        $this->assertSame(429, $request()->getStatusCode(), "{$label}: request ".($allowed + 1).' should be throttled');
    }

    public function test_public_forms_are_rate_limited(): void
    {
        $this->hammer(fn () => $this->post(route('contact.send'), []), 5, 'contact');
        $this->hammer(fn () => $this->postJson(route('newsletter'), ['email' => 'a@example.com']), 5, 'newsletter');
        $this->hammer(fn () => $this->post(route('password.email'), ['email' => 'a@example.com']), 5, 'forgot password');
        $this->hammer(fn () => $this->post(route('password.update'), []), 5, 'reset password');
        $this->hammer(fn () => $this->post(route('track.lookup'), ['number' => 'X', 'email' => 'a@example.com']), 10, 'order tracking');
        $this->hammer(fn () => $this->get(route('search.suggest', ['q' => 'te'])), 60, 'search suggestions');
    }

    public function test_login_and_registration_are_rate_limited_per_ip(): void
    {
        $this->hammer(fn () => $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'x']), 10, 'login');
        $this->hammer(fn () => $this->post(route('register'), []), 10, 'register');
    }

    public function test_repeated_wrong_passwords_lock_out_even_the_right_password(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123456'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_account_and_cart_writes_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->hammer(fn () => $this->put(route('account.profile.update'), ['name' => 'A B', 'email' => $user->email]), 60, 'account');
        $this->hammer(fn () => $this->patchJson(route('cart.update', '1-0'), ['quantity' => 1]), 120, 'cart updates');
    }

    public function test_there_is_a_broad_ceiling_for_any_single_visitor(): void
    {
        $this->hammer(fn () => $this->get(route('track')), 240, 'general');
    }

    /* ---------------- sessions ---------------- */

    public function test_session_id_changes_on_login_and_the_old_session_is_dead_after_logout(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        $this->get(route('home'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123456'])->assertRedirect();
        $this->assertNotSame($before, session()->getId(), 'session fixation: id must change at login');
        $token = session()->token();

        $this->post(route('logout'));
        $this->assertGuest();
        $this->assertNotSame($token, session()->token());                           // CSRF token rotated too
    }

    public function test_changing_a_password_elsewhere_signs_this_session_out(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123456']);
        $this->get(route('account.dashboard'))->assertOk();

        // e.g. the owner resets their password from another device or via the reset email
        $user->forceFill(['password' => 'BrandNew987654'])->save();
        \Illuminate\Support\Facades\Auth::forgetGuards();                           // the next request loads the user afresh, as in real life

        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_changing_your_own_password_keeps_you_signed_in_here(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123456']);

        $this->put(route('account.password.update'), ['current_password' => 'Secret123456', 'password' => 'BrandNew987654', 'password_confirmation' => 'BrandNew987654'])->assertSessionHasNoErrors();

        $this->get(route('account.dashboard'))->assertOk();
        $this->assertTrue(Hash::check('BrandNew987654', $user->fresh()->password));
    }

    public function test_session_cookie_is_httponly_samesite_and_secure_over_https(): void
    {
        $cookie = collect($this->get(route('home'))->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));

        config(['session.secure' => true]);
        $secure = collect($this->get('https://localhost/')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($secure->isSecure());

        $this->assertTrue(config('session.encrypt') === true || env('SESSION_ENCRYPT', true) === true);
        $this->assertStringContainsString("env('SESSION_ENCRYPT', true)", File::get(config_path('session.php')));
    }

    public function test_staff_sessions_expire_when_idle(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertEqualsWithDelta(time(), session('admin.last_activity'), 5);

        $this->withSession(['admin.last_activity' => time() - 31 * 60])->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->withSession(['admin.last_activity' => time() - 5 * 60])->get(route('admin.dashboard'))->assertOk();   // active use is fine
    }

    /* ---------------- passwords ---------------- */

    public function test_passwords_are_stored_as_bcrypt_hashes_and_never_in_plain_text(): void
    {
        $this->post(route('register'), ['name' => 'Hash Test', 'email' => 'hash@example.com', 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456']);
        $stored = User::where('email', 'hash@example.com')->firstOrFail()->password;

        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertNotSame('Secret123456', $stored);
        $this->assertSame('bcrypt', Hash::info($stored)['algoName']);
        $this->assertTrue(Hash::check('Secret123456', $stored));
        $this->assertFalse(Hash::needsRehash($stored));
        $this->assertArrayNotHasKey('password', User::where('email', 'hash@example.com')->firstOrFail()->toArray());       // never serialised
        $this->assertGreaterThanOrEqual(12, (int) preg_replace('/\D/', '', (string) collect(file(base_path('.env.example')))->first(fn ($l) => str_starts_with($l, 'BCRYPT_ROUNDS='))));
    }

    public function test_password_rules_are_enforced_everywhere_a_password_is_set(): void
    {
        $bad = ['short1' => 'Ab1', 'letters only' => 'abcdefghij', 'digits only' => '1234567890', 'too long for bcrypt' => str_repeat('Aa1', 30)];
        foreach ($bad as $label => $pw) {
            $this->post(route('register'), ['name' => 'X Y', 'email' => 'x'.md5($label).'@example.com', 'password' => $pw, 'password_confirmation' => $pw])->assertSessionHasErrors('password');
        }
        $user = User::factory()->create(['password' => 'Secret123456']);
        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'Secret123456', 'password' => str_repeat('Aa1', 30), 'password_confirmation' => str_repeat('Aa1', 30)])->assertSessionHasErrors('password');
        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'Secret123456', 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456'])->assertSessionHasErrors('password');   // must differ
    }

    public function test_changing_the_sign_in_email_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456', 'email' => 'old@example.com']);

        $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com'])->assertSessionHasErrors('email_password');
        $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com', 'email_password' => 'wrong-pass'])->assertSessionHasErrors('email_password');
        $this->assertSame('old@example.com', $user->fresh()->email);

        $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com', 'email_password' => 'Secret123456'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $user->fresh()->email);

        $this->actingAs($user->fresh())->put(route('account.profile.update'), ['name' => 'Name Only', 'email' => 'new@example.com'])->assertSessionHasNoErrors();   // no password needed for other edits
    }

    public function test_password_reset_does_not_reveal_accounts_and_tokens_are_single_use(): void
    {
        $user = User::factory()->create(['password' => 'Secret123456']);
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'BrandNew987654', 'password_confirmation' => 'BrandNew987654'])->assertRedirect(route('login'));
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'Another987654', 'password_confirmation' => 'Another987654'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('BrandNew987654', $user->fresh()->password));

        $this->post(route('password.email'), ['email' => 'ghost@example.com'])->assertSessionHas('status');
    }

    /* ---------------- host header ---------------- */

    public function test_in_production_only_our_own_host_is_served(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://abiha.pk']);

        // The host goes in the URL: the test client derives HTTP_HOST from it (and from app.url for relative URLs).
        $this->get('https://evil.example/')->assertStatus(400);
        $this->get('https://abiha.pk.evil.example/')->assertStatus(400);                 // look-alike suffix
        $this->get('https://evil-abiha.pk/')->assertStatus(400);                          // look-alike prefix
        $this->get('https://abiha.pk/')->assertOk();
        $this->get('https://www.abiha.pk/')->assertOk();
    }
}
