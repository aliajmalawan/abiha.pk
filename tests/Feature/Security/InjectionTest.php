<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Cross-site scripting, SQL injection and mass assignment. */
class InjectionTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    /** Substrings that must NEVER survive sanitising. */
    private const FORBIDDEN = ['onerror', 'onclick', 'onload', 'onmouseover', 'onfocus', 'javascript:', 'vbscript:', '<script', '<iframe', '<svg', '<style', '<object', '<embed', '<form', 'data:text/html', 'srcdoc', 'expression('];

    public static function xssPayloads(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'mixed case + tab' => ['<a href=" jAvA'."\t".'scRipt:alert(1)">x</a>'],
            'entity encoded' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'hex entity' => ['<a href="&#x6A;avascript&colon;alert(1)">x</a>'],
            'data uri' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'protocol relative' => ['<a href="//evil.example/p.js">x</a>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'object/embed' => ['<object data="x.swf"></object><embed src="x.swf">'],
            'style block' => ['<style>@import "https://evil.example/x.css"</style>'],
            'style attr' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'event on p' => ['<p onmouseover="alert(1)">hover</p>'],
            'form + input' => ['<form action="https://evil.example"><input name=pw autofocus onfocus=alert(1)></form>'],
            'comment break-out' => ['<!--><script>alert(1)</script>-->'],
            'math/mglyph mXSS' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
            'nested broken tags' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'null byte' => ["<img src=x on\0error=alert(1)>"],
            'unclosed' => ['<a href="javascript:alert(1)'],
            'img javascript src' => ['<img src="javascript:alert(1)">'],
            'base/meta/link' => ['<base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example"><link rel=stylesheet href=//evil.example/x.css>'],
        ];
    }

    /** @dataProvider xssPayloads */
    #[\PHPUnit\Framework\Attributes\DataProvider('xssPayloads')]
    public function test_sanitizer_neutralises_every_payload(string $payload): void
    {
        $clean = strtolower(HtmlSanitizer::clean('<p>before</p>'.$payload.'<p>after</p>'));

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString($needle, $clean, "'{$needle}' survived in: {$clean}");
        }
        // Text BEFORE the payload is always kept. Text after an unclosed <svg>/<object>/<math>/<a href="javascript: is
        // legitimately swallowed by it (browsers do the same), so we only assert the safe direction there.
        $this->assertStringContainsString('before', $clean);
    }

    public function test_sanitizer_keeps_legitimate_rich_text(): void
    {
        $html = '<h3>Care</h3><p>Wash <strong>cold</strong> &amp; <em>dry flat</em>.</p><ul><li>One</li><li>Two</li></ul><a href="https://example.com/guide" title="Guide">guide</a> <a href="/shop">shop</a> <a href="mailto:hi@example.com">mail</a>';
        $clean = HtmlSanitizer::clean($html);

        foreach (['<h3>Care</h3>', '<strong>cold</strong>', '<em>dry flat</em>', '<li>One</li>', 'href="https://example.com/guide"', 'href="/shop"', 'href="mailto:hi@example.com"'] as $keep) {
            $this->assertStringContainsString($keep, $clean);
        }
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);   // external links cannot reach window.opener
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function test_stored_xss_in_cms_pages_is_removed_on_save_and_on_render(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $evil = '<p>Hello</p><a href="javascript:alert(1)" onclick="alert(2)">click</a><img src=x onerror=alert(3)><script>alert(4)</script>';

        $this->actingAs($admin)->post(route('admin.pages.store'), ['title' => 'Evil Page', 'body' => $evil, 'is_published' => 1])->assertSessionHasNoErrors();
        $stored = \App\Models\Page::where('slug', 'evil-page')->firstOrFail();
        $this->assertStringNotContainsString('onclick', $stored->body);              // clean in the database…
        $this->assertStringNotContainsString('<script', $stored->body);

        auth()->logout();
        $stored->update(['body' => $evil]);                                          // …and even a dirty legacy row is cleaned when rendered
        $html = $this->get(route('page', 'evil-page'))->assertOk()->getContent();
        $this->assertStringContainsString('Hello', $html);
        foreach (['onclick', 'onerror', 'javascript:alert', '<script>alert(4)'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_stored_xss_in_product_descriptions_is_removed(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $evil = '<p>Soft cotton</p><img src=x onerror=alert(1)><a href="JaVaScRiPt:alert(2)">more</a>';

        $this->actingAs($admin)->post(route('admin.products.store'), ['name' => 'Xss Tee', 'sku' => 'XSS-1', 'price' => 1000, 'stock' => 3, 'description' => $evil, 'is_active' => 1])->assertSessionHasNoErrors();
        $p = Product::where('sku', 'XSS-1')->firstOrFail();
        $this->assertStringNotContainsString('onerror', (string) $p->description);

        $p->update(['description' => $evil]);                                        // simulate a row written before sanitising existed
        auth()->logout();
        $html = $this->get(route('product', $p))->assertOk()->getContent();
        $this->assertStringContainsString('Soft cotton', $html);
        $this->assertStringNotContainsString('onerror=alert', $html);
        $this->assertStringNotContainsString('javascript:alert', strtolower($html));
    }

    public function test_user_supplied_text_is_always_escaped_on_output(): void
    {
        $this->seedRoles();
        $boom = '<img src=x onerror=alert(1)>';
        $p = $this->product(['name' => $boom.' Tee', 'short_description' => $boom, 'stock' => 4]);
        Review::create(['product_id' => $p->id, 'name' => $boom, 'rating' => 5, 'title' => $boom, 'body' => $boom.' review', 'is_approved' => true]);
        $this->shipping();
        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload(['name' => $boom, 'notes' => $boom, 'address' => 'House 1, '.$boom]));
        $order = Order::firstOrFail();

        foreach ([route('shop'), route('product', $p), route('cart'), route('cart.drawer'), route('shop', ['q' => $boom])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html, "unescaped markup on {$url}");
        }
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $this->get(route('product', $p))->getContent());

        $admin = $this->userWithRole('admin');
        foreach ([route('admin.orders.show', $order), route('admin.orders.index'), route('admin.products.index'), route('admin.reviews.index', ['status' => 'approved'])] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('<img src=x onerror=alert(1)>', false);
        }
        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_reflected_input_is_escaped(): void
    {
        $this->get(route('shop', ['q' => '<script>alert(1)</script>']))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->post(route('track.lookup'), ['number' => '"><script>alert(1)</script>', 'email' => 'a@example.com'])->assertSessionHasErrors();
        $this->followingRedirects()->from(route('track'))->post(route('track.lookup'), ['number' => '"><script>alert(1)</script>', 'email' => 'a@example.com'])->assertDontSee('"><script>alert(1)</script>', false);
    }

    public function test_json_embedded_in_pages_cannot_break_out_of_its_script_tag(): void
    {
        $p = $this->product(['name' => 'Evil </script><script>alert(1)</script> Name', 'description' => 'd']);

        $html = $this->get(route('product', $p))->assertOk()->getContent();
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    }

    /* ---------------- SQL injection ---------------- */

    public static function sqlPayloads(): array
    {
        return [["' OR '1'='1"], ["'; DROP TABLE products; --"], ["' UNION SELECT email, password FROM users --"], ['" OR ""="'], ["1) OR (1=1"], ['%\' OR 1=1 -- '], ["\\"], ["0x27 OR 1=1"], ["' AND SLEEP(5) --"]];
    }

    /** @dataProvider sqlPayloads */
    #[\PHPUnit\Framework\Attributes\DataProvider('sqlPayloads')]
    public function test_public_inputs_are_parameterised(string $payload): void
    {
        $this->seedRoles();
        $this->product(['name' => 'Real Tee', 'stock' => 5]);
        $products = Product::count();
        $users = User::count();

        $started = microtime(true);
        $res = $this->get(route('shop', ['q' => $payload]));
        $res->assertOk();
        $this->assertSame(0, $res->viewData('products')->total(), 'a payload must match nothing, not everything');

        foreach ([
            route('shop', ['color' => [$payload], 'size' => [$payload]]),
            route('search.suggest', ['q' => $payload]),
            route('shop', ['category' => $payload]),
        ] as $url) {
            $this->assertLessThan(500, $this->get($url)->getStatusCode(), "server error for {$url}");
        }
        foreach (['sort' => $payload, 'min' => $payload, 'max' => $payload, 'availability' => $payload] as $param => $value) {
            $this->assertLessThan(500, $this->get(route('shop', [$param => $value]))->getStatusCode(), "server error for ?{$param}");
        }
        $this->assertLessThan(500, $this->post(route('track.lookup'), ['number' => $payload, 'email' => 'a@example.com'])->getStatusCode());
        $this->assertLessThan(500, $this->post(route('cart.coupon'), ['code' => $payload])->getStatusCode());
        $this->assertLessThan(500, $this->post(route('login'), ['email' => $payload, 'password' => $payload])->getStatusCode());

        $this->assertSame($products, Product::count());
        $this->assertSame($users, User::count());
        $this->assertLessThan(4.0, microtime(true) - $started, 'SLEEP() payload must not run');
    }

    public function test_admin_search_and_filter_inputs_are_parameterised(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $this->placeGuestOrder();
        $count = Order::count();
        $payload = "' OR 1=1 -- ";

        foreach (['admin.orders.index', 'admin.products.index', 'admin.customers.index', 'admin.reviews.index', 'admin.messages.index', 'admin.inventory.index', 'admin.logs.index', 'admin.newsletter.index', 'admin.coupons.index', 'admin.pages.index'] as $route) {
            $this->assertLessThan(500, $this->actingAs($admin)->get(route($route, ['q' => $payload, 'status' => $payload, 'payment' => $payload, 'method' => $payload, 'reason' => $payload]))->getStatusCode(), $route);
        }
        $res = $this->actingAs($admin)->get(route('admin.orders.index', ['q' => $payload]))->assertOk();
        $this->assertSame(0, $res->viewData('orders')->total());
        $this->assertSame($count, Order::count());
    }

    public function test_like_wildcards_are_treated_literally(): void
    {
        $this->product(['name' => 'Plain Tee', 'stock' => 3]);

        foreach (['%', '_', '%%', '\\'] as $term) {
            $this->assertSame(0, $this->get(route('shop', ['q' => $term]))->viewData('products')->total(), "wildcard {$term} must not match everything");
        }
    }

    public function test_sort_and_filter_parameters_are_whitelisted_not_interpolated(): void
    {
        $this->product(['stock' => 3]);

        $this->get(route('shop', ['sort' => 'price; DROP TABLE products']))->assertSessionHasErrors('sort');
        $this->get(route('shop', ['sort' => 'id desc, (select sleep(5))']))->assertSessionHasErrors('sort');
        $this->assertSame(1, Product::count());
    }

    /* ---------------- mass assignment ---------------- */

    public function test_privileged_fields_cannot_be_set_through_profile_checkout_or_registration(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $adminRole = \App\Models\Role::where('slug', 'admin')->value('id');

        $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'Ok Name', 'email' => $user->email, 'role_id' => $adminRole, 'is_active' => 0, 'password' => 'Hacked12345', 'email_verified_at' => now()])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNull($user->role_id);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->isAdmin());
        $this->assertSame('Ok Name', $user->name);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Hacked12345', $user->password));

        auth()->logout();
        $this->flushSession();
        [$order] = $this->placeGuestOrder('cod', 1000, 1, ['user_id' => $user->id, 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 1, 'access_token' => 'x', 'coupon_id' => 99]);
        $this->assertNull($order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(1250, $order->total);

        $this->post(route('register'), ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456', 'role_id' => $adminRole, 'role' => 'admin', 'is_active' => 1]);
        $this->assertSame('customer', User::where('email', 'new@example.com')->firstOrFail()->role?->slug);
    }

    public function test_category_and_review_inputs_cannot_overwrite_protected_columns(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $p = $this->product();

        $this->actingAs($user)->post(route('reviews.store', $p), ['rating' => 5, 'body' => 'Lovely piece, truly.', 'is_approved' => 1, 'user_id' => 999, 'is_verified_purchase' => 1, 'product_id' => 999, 'name' => 'Staff Member'])->assertSessionHasNoErrors();

        $r = Review::firstOrFail();
        $this->assertFalse($r->is_approved);                                                   // customers cannot self-approve
        $this->assertSame($user->id, $r->user_id);
        $this->assertSame($p->id, $r->product_id);
        $this->assertSame($user->name, $r->name);
        $this->assertFalse((bool) $r->is_verified_purchase);
        $this->assertSame(0, Category::count());
    }
}
