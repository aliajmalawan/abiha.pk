<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\User;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\VerifiedPayment;
use App\Services\Payments\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Hand-rolled gateway used only to exercise the shared webhook pipeline (no real provider exists yet). */
class SecretCheckingGateway implements OnlinePaymentGateway
{
    public function code(): string { return 'hardening'; }
    public function label(): string { return 'Hardening'; }
    public function description(): string { return ''; }
    public function isAvailable(): bool { return true; }
    public function initiate(Order $order): string { return 'https://provider.example/'.$order->number; }

    public function handleWebhook(Request $request): ?VerifiedPayment
    {
        $ts = (int) $request->header('X-Timestamp');
        if (! WebhookSignature::verifyHmac($request->getContent(), (string) $request->header('X-Signature'), 'whsec_test_secret', 'sha256', $ts)) {
            return null;
        }
        $d = json_decode($request->getContent(), true);

        return new VerifiedPayment($d['order'], $d['ref'], (int) $d['amount'], true, ['ref' => $d['ref']]);
    }
}

class HardeningTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    /* ---------------- security headers & CSP ---------------- */

    public function test_every_page_sends_the_browser_security_headers(): void
    {
        foreach ([route('home'), route('shop'), route('login'), route('admin.login'), route('cart.drawer')] as $url) {
            $h = $this->get($url)->headers;
            $this->assertSame('nosniff', $h->get('X-Content-Type-Options'), $url);
            $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'), $url);
            $this->assertSame('strict-origin-when-cross-origin', $h->get('Referrer-Policy'), $url);
            $this->assertStringContainsString('camera=()', $h->get('Permissions-Policy'), $url);
            $this->assertSame('same-origin', $h->get('Cross-Origin-Opener-Policy'), $url);
            $this->assertNotEmpty($h->get('Content-Security-Policy'), $url);
        }
    }

    public function test_csp_is_strict_and_the_nonce_matches_the_pages_inline_script(): void
    {
        $res = $this->get(route('home'));
        $csp = $res->headers->get('Content-Security-Policy');

        preg_match("/script-src ([^;]+)/", $csp, $m);
        $scriptSrc = $m[1];
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc, 'inline scripts must need the nonce');
        $this->assertStringNotContainsString('*', $scriptSrc);
        $this->assertStringNotContainsString('http:', $csp);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $n);
        $this->assertNotEmpty($n[1] ?? null);
        $res->assertSee('nonce="'.$n[1].'"', false);                                     // our inline script carries it
        $this->assertGreaterThanOrEqual(2, substr_count($res->getContent(), 'nonce="'.$n[1].'"'), 'inline config script and @vite assets');
    }

    public function test_the_nonce_is_new_for_every_request(): void
    {
        $nonces = collect(range(1, 4))->map(function () {
            preg_match("/'nonce-([^']+)'/", $this->get(route('home'))->headers->get('Content-Security-Policy'), $m);

            return $m[1];
        });

        $this->assertCount(4, $nonces->unique());
        $this->assertGreaterThanOrEqual(20, strlen($nonces->first()));
    }

    public function test_the_admin_area_gets_the_same_csp(): void
    {
        $this->seedRoles();
        $csp = $this->actingAs($this->userWithRole('admin'))->get(route('admin.dashboard'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_no_view_contains_inline_event_handlers_or_javascript_urls(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $f) {
            $src = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($f->getPathname()));
            if (preg_match('/\s(on[a-z]+)\s*=\s*"/i', preg_replace('/\s@[a-z.:-]+=/i', ' ', $src)) || preg_match('/href\s*=\s*"javascript:/i', $src)) {
                $offenders[] = $f->getFilename();
            }
        }
        $this->assertSame([], $offenders, 'A strict CSP would block these: '.implode(', ', $offenders));
    }

    public function test_hsts_is_sent_only_in_production_over_https(): void
    {
        $this->assertNull($this->get(route('home'))->headers->get('Strict-Transport-Security'));            // testing env

        $this->app['env'] = 'production';
        config(['app.url' => 'https://abiha.pk']);
        $this->get('http://abiha.pk/')->assertHeaderMissing('Strict-Transport-Security');                    // never over plain http
        $secure = $this->get('https://abiha.pk/');
        $this->assertStringContainsString('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('upgrade-insecure-requests', $secure->headers->get('Content-Security-Policy'));
    }

    public function test_csp_is_skipped_only_while_the_vite_dev_server_runs(): void
    {
        $hot = public_path('hot');
        $this->assertFileDoesNotExist($hot);
        try {
            File::put($hot, 'http://localhost:5173');
            $this->assertNull($this->get(route('home'))->headers->get('Content-Security-Policy'));
        } finally {
            File::delete($hot);
        }
        $this->assertNotNull($this->get(route('home'))->headers->get('Content-Security-Policy'));
    }

    public function test_private_pages_are_never_cached(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $admin = $this->userWithRole('admin');

        foreach ([route('login'), route('register'), route('cart'), route('cart.drawer')] as $url) {
            $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'), $url);
        }
        $this->assertStringContainsString('no-store', $this->actingAs($user)->get(route('account.dashboard'))->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $this->actingAs($admin)->get(route('admin.orders.index'))->headers->get('Cache-Control'));
    }

    /* ---------------- webhook verification ---------------- */

    public function test_hmac_signatures_are_verified_strictly(): void
    {
        $body = '{"order":"ABH-1","amount":2250}';
        $secret = 'whsec_test_secret';
        $hex = hash_hmac('sha256', $body, $secret);
        $b64 = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->assertTrue(WebhookSignature::verifyHmac($body, $hex, $secret));
        $this->assertTrue(WebhookSignature::verifyHmac($body, strtoupper($hex), $secret));
        $this->assertTrue(WebhookSignature::verifyHmac($body, 'sha256='.$hex, $secret));
        $this->assertTrue(WebhookSignature::verifyHmac($body, $b64, $secret));

        $this->assertFalse(WebhookSignature::verifyHmac($body.' ', $hex, $secret), 'tampered body');
        $this->assertFalse(WebhookSignature::verifyHmac(str_replace('2250', '1', $body), $hex, $secret), 'amount changed after signing');
        $this->assertFalse(WebhookSignature::verifyHmac($body, $hex, 'other-secret'), 'wrong secret');
        $this->assertFalse(WebhookSignature::verifyHmac($body, '', $secret), 'missing signature');
        $this->assertFalse(WebhookSignature::verifyHmac($body, 'not-a-signature!!', $secret));
        $this->assertFalse(WebhookSignature::verifyHmac($body, $hex, ''), 'an unconfigured secret must never verify');
        $this->assertFalse(WebhookSignature::verifyHmac($body, hash_hmac('sha256', $body, ''), ''), 'even a signature made with an empty secret');
    }

    public function test_signed_timestamps_stop_replayed_webhooks(): void
    {
        $body = '{"order":"ABH-1"}';
        $secret = 'whsec_test_secret';
        $sign = fn (int $ts) => hash_hmac('sha256', $ts.'.'.$body, $secret);

        $now = time();
        $this->assertTrue(WebhookSignature::verifyHmac($body, $sign($now), $secret, 'sha256', $now));
        $this->assertTrue(WebhookSignature::verifyHmac($body, $sign($now - 200), $secret, 'sha256', $now - 200));
        $this->assertFalse(WebhookSignature::verifyHmac($body, $sign($now - 3600), $secret, 'sha256', $now - 3600), 'a captured request replayed an hour later');
        $this->assertFalse(WebhookSignature::verifyHmac($body, $sign($now + 3600), $secret, 'sha256', $now + 3600), 'timestamp from the future');
        $this->assertFalse(WebhookSignature::verifyHmac($body, $sign($now - 3600), $secret, 'sha256', $now), 'old signature with a fresh timestamp header');
    }

    private function webhookOrder(int $total = 2250): Order
    {
        config(['payments.gateways.hardening' => SecretCheckingGateway::class]);

        return Order::create(['number' => 'ABH-HARD-1', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'hardening', 'shipping_method_name' => 'Std',
            'email' => 't@example.com', 'subtotal' => $total, 'total' => $total, 'access_token' => str_repeat('b', 40)]);
    }

    private function signed(array $payload, ?int $ts = null, string $secret = 'whsec_test_secret'): array
    {
        $ts ??= time();
        $body = json_encode($payload);

        return [$body, ['X-Timestamp' => (string) $ts, 'X-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret), 'Content-Type' => 'application/json']];
    }

    public function test_the_webhook_endpoint_marks_paid_only_for_a_correctly_signed_fresh_request(): void
    {
        $order = $this->webhookOrder();
        $payload = ['order' => $order->number, 'ref' => 'TX1', 'amount' => 2250];

        [$body, $headers] = $this->signed($payload, null, 'attacker-guess');
        $this->call('POST', '/webhooks/payments/hardening', [], [], [], $this->serverHeaders($headers), $body)->assertStatus(400)->assertSee('Invalid');
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        [$body, $headers] = $this->signed($payload, time() - 7200);
        $this->call('POST', '/webhooks/payments/hardening', [], [], [], $this->serverHeaders($headers), $body)->assertStatus(400);        // replay
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        [$body, $headers] = $this->signed($payload);
        $this->call('POST', '/webhooks/payments/hardening', [], [], [], $this->serverHeaders($headers), $body)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_valid_signature_cannot_change_the_amount_paid(): void
    {
        $order = $this->webhookOrder(2250);
        [$body, $headers] = $this->signed(['order' => $order->number, 'ref' => 'TX2', 'amount' => 1]);        // genuinely signed, but underpays

        $this->call('POST', '/webhooks/payments/hardening', [], [], [], $this->serverHeaders($headers), $body)->assertStatus(422);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_oversized_webhook_bodies_are_refused_before_any_work(): void
    {
        $this->webhookOrder();
        $huge = json_encode(['order' => 'x', 'pad' => str_repeat('A', 70000)]);

        $this->call('POST', '/webhooks/payments/hardening', [], [], [], ['CONTENT_TYPE' => 'application/json'], $huge)->assertStatus(413);
    }

    public function test_webhook_failures_never_explain_why_and_unknown_providers_look_like_404(): void
    {
        $this->webhookOrder();

        $r = $this->call('POST', '/webhooks/payments/hardening', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame('Invalid', $r->getContent());                                       // no "bad signature" / "timestamp too old" hints
        $this->postJson('/webhooks/payments/cod', [])->assertNotFound();                      // offline methods have no webhook
        $this->postJson('/webhooks/payments/nonexistent', [])->assertNotFound();
        $this->get('/webhooks/payments/hardening')->assertStatus(405);                        // POST only
    }

    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $server;
    }

    /* ---------------- secrets & environment ---------------- */

    public function test_env_is_never_called_outside_config_files(): void
    {
        $offenders = [];
        foreach (['app', 'routes', 'resources', 'bootstrap', 'database'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $f) {
                if (! preg_match('/\.php$/', $f->getFilename())) {
                    continue;
                }
                if (preg_match('/(?<![\w>:])env\s*\(/', File::get($f->getPathname()))) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname());
                }
            }
        }
        // env() outside config/ returns null once configuration is cached, which is how secrets end up misread or hard-coded.
        $this->assertSame([], $offenders);
    }

    public function test_secrets_never_appear_in_rendered_pages(): void
    {
        $this->seedRoles();
        $secrets = ['APP-KEY-SENTINEL-123456789012345', 'DB-PASSWORD-SENTINEL-987', 'MAIL-PASSWORD-SENTINEL-555', 'PROVIDER-SECRET-SENTINEL-777'];
        config(['app.key' => 'base64:'.base64_encode($secrets[0]), 'database.connections.mysql.password' => $secrets[1],
            'mail.mailers.smtp.password' => $secrets[2], 'services.provider.secret' => $secrets[3]]);
        $admin = $this->userWithRole('admin');
        $this->shipping();
        $this->addToCart($this->product());

        $pages = [route('home'), route('shop'), route('cart'), route('checkout'), route('login'), route('contact')];
        foreach ($pages as $url) {
            $html = $this->get($url)->getContent();
            foreach ($secrets as $s) {
                $this->assertStringNotContainsString($s, $html, "{$url} leaked a secret");
                $this->assertStringNotContainsString(base64_encode($s), $html);
            }
        }
        foreach ([route('admin.settings.edit'), route('admin.payments.edit'), route('admin.dashboard')] as $url) {
            $html = $this->actingAs($admin)->get($url)->getContent();
            foreach ($secrets as $s) {
                $this->assertStringNotContainsString($s, $html, "{$url} leaked a secret");
            }
        }
    }

    public function test_only_non_secret_values_are_sent_to_the_browser(): void
    {
        $html = $this->get(route('home'))->getContent();
        preg_match('/window\.ABIHA = (\{.*?\});/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertEqualsCanonicalizing(['currency', 'cartCount', 'wishlistIds', 'cartDrawerUrl'], array_keys($config));
    }

    public function test_sensitive_files_are_not_reachable_over_http(): void
    {
        foreach (['/.env', '/.env.example', '/.git/config', '/composer.json', '/composer.lock', '/artisan', '/storage/logs/laravel.log', '/vendor/autoload.php', '/phpunit.xml', '/database/database.sqlite'] as $path) {
            $this->assertContains($this->get($path)->getStatusCode(), [403, 404], "{$path} must not be served");
        }
        $this->assertFileDoesNotExist(public_path('.env'));
        $this->assertStringContainsString('.env', File::get(base_path('.gitignore')));
    }

    public function test_example_env_contains_no_real_secrets(): void
    {
        $lines = collect(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES));

        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'REDIS_PASSWORD'] as $key) {
            $value = trim((string) $lines->first(fn ($l) => str_starts_with($l, $key.'=')), " \t");
            $value = trim(substr($value, strlen($key) + 1), " \t\"'");
            $this->assertContains($value, ['', 'null', 'NULL'], "{$key} in .env.example must be empty");
        }
        $this->assertTrue($lines->contains('APP_DEBUG=false') || $lines->contains('APP_DEBUG=true'));
    }

    public function test_error_pages_do_not_reveal_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $res = $this->get('/definitely/not/a/page');
        $res->assertNotFound();
        foreach (['vendor/laravel', 'Stack trace', 'APP_KEY', 'DB_HOST', base_path()] as $needle) {
            $this->assertStringNotContainsString($needle, $res->getContent());
        }
    }

    /* ---------------- deployment checker ---------------- */

    public function test_security_check_flags_bank_transfer_without_account_details(): void
    {
        config(['payments.enabled' => ['cod', 'bank_transfer']]);

        $this->artisan('abiha:security-check')->expectsOutputToContain('hidden at checkout until you add them');
    }

    public function test_bank_transfer_is_not_offered_to_customers_until_account_details_exist(): void
    {
        config(['payments.enabled' => ['cod', 'bank_transfer']]);
        $this->shipping();
        $this->addToCart($this->product());

        $this->get('/checkout')->assertOk()->assertSee('Cash on Delivery')->assertDontSee('Bank Transfer');
        $this->post(route('checkout.store'), array_merge($this->checkoutPayload(), ['payment_method' => 'bank_transfer']))->assertSessionHasErrors();   // cannot be forced either

        \App\Models\Setting::put('bank.details', 'Sample Bank, account 1234 (test)');
        $this->get('/checkout')->assertSee('Bank Transfer');
    }

    public function test_security_check_fails_loudly_on_an_unsafe_configuration(): void
    {
        config(['app.debug' => true, 'app.key' => '']);

        $this->artisan('abiha:security-check')->expectsOutputToContain('APP_DEBUG is ON')->expectsOutputToContain('APP_KEY is missing')->assertFailed();
    }

    public function test_security_check_passes_on_a_safe_configuration(): void
    {
        $this->seedRoles();
        $this->userWithRole('admin');
        config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://abiha.pk',
            'session.secure' => true, 'session.encrypt' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'hashing.bcrypt.rounds' => 12, 'payments.enabled' => ['cod']]);

        $this->artisan('abiha:security-check')->expectsOutputToContain('APP_DEBUG is off')->expectsOutputToContain('administrator')->assertSuccessful();
    }

    public function test_security_check_probe_catches_a_publicly_readable_env_file(): void
    {
        config(['app.url' => 'https://abiha.pk']);

        Http::fake(['https://abiha.pk/.env' => Http::response('APP_KEY=base64:secret', 200), '*' => Http::response('Not found', 404)]);
        $this->artisan('abiha:security-check', ['--probe' => true])->expectsOutputToContain('/.env IS PUBLICLY READABLE')->assertFailed();

    }

    public function test_security_check_probe_is_quiet_when_nothing_sensitive_is_served(): void
    {
        config(['app.url' => 'https://abiha.pk', 'payments.enabled' => ['cod']]);
        Http::fake(['*' => Http::response('Not found', 404)]);

        $this->artisan('abiha:security-check', ['--probe' => true])->doesntExpectOutputToContain('PUBLICLY READABLE');
    }
}
