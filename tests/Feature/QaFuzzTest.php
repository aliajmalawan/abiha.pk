<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Collection;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\HomeSection;
use App\Models\Newsletter;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/**
 * Invalid-input sweep: EVERY route that changes data is hit, as a guest, a customer and an administrator, with empty,
 * oversized, wrongly-typed, hostile and out-of-range input. The only thing asserted is the thing that must never happen:
 * a server error (HTTP 5xx). Each request runs inside its own rolled-back transaction, so a DELETE cannot remove the
 * record the next request needs. Adding a route automatically adds it to this sweep.
 */
class QaFuzzTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private const FIELDS = ['name', 'email', 'password', 'password_confirmation', 'current_password', 'email_password', 'phone', 'whatsapp', 'address', 'city', 'province', 'postal_code', 'label',
        'quantity', 'product_id', 'variant_id', 'code', 'status', 'price', 'compare_price', 'stock', 'sku', 'slug', 'title', 'subtitle', 'eyebrow', 'body', 'description', 'short_description',
        'note', 'type', 'value', 'min_subtotal', 'max_discount', 'usage_limit', 'per_customer_limit', 'starts_at', 'expires_at', 'is_active', 'sort_order', 'cost', 'free_over', 'rating',
        'tracking_number', 'tracking_url', 'reference', 'amount', 'method', 'role', 'role_id', 'category_id', 'parent_id', 'cta_url', 'cta_label', 'image', 'images', 'variants', 'options',
        'ids', 'settings', 'cities', 'provinces', 'restock', 'percent', 'from', 'to', 'q', 'payment_method', 'shipping_method', 'message', 'subject', 'url', 'meta_title', 'meta_description'];

    private array $skipped = [];

    /** A little of everything, so every route parameter has something real to point at. */
    private function world(): array
    {
        $this->seed();                                                       // roles, attributes and the sample catalogue/commerce/CMS data
        $customer = $this->userWithRole('customer', ['email' => 'fuzz-customer@example.com']);
        $admin = $this->userWithRole('admin', ['email' => 'fuzz-admin@example.com']);
        $product = Product::query()->first() ?? $this->product();
        $this->shipping();
        [$order] = $this->placeGuestOrder();
        $this->flushSession();
        $order->update(['user_id' => $customer->id]);

        return [
            'customer' => $customer, 'admin' => $admin, 'product' => $product, 'order' => $order,
            'address' => $customer->addresses()->create([ 'label' => 'Home', 'name' => 'A B', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'House 1 Street 2']),
        ];
    }

    private function value(string $param, string $uri, array $w): string|int|null
    {
        $admin = str_starts_with($uri, 'admin/');
        $first = fn (string $model) => $model::query()->value($admin ? 'id' : 'id');

        return match ($param) {
            'address' => $w['address']->id,
            'order' => $w['order']->number,
            'product' => $admin ? $w['product']->id : $w['product']->slug,
            'attribute' => Attribute::query()->value('id'),
            'value' => AttributeValue::query()->value('id'),
            'banner' => Banner::query()->value('id'),
            'category' => Category::query()->value('id'),
            'collection' => Collection::query()->value('id'),
            'coupon' => Coupon::query()->value('id'),
            'section' => HomeSection::query()->value('id'),
            'message' => ($m = ContactMessage::query()->value('id')) ?: ContactMessage::create(['name' => 'A', 'email' => 'a@b.co', 'message' => 'Hello there'])->id,
            'newsletter' => ($n = Newsletter::query()->value('id')) ?: Newsletter::create(['email' => 'n@example.com'])->id,
            'page' => Page::query()->value('id'),
            'review' => ($r = Review::query()->value('id')) ?: Review::create(['product_id' => $w['product']->id, 'name' => 'R', 'rating' => 5, 'body' => 'Lovely thing'])->id,
            'rule' => ShippingRule::query()->value('id'),
            'method', 'shipping' => ShippingMethod::query()->value('id'),
            'testimonial' => Testimonial::query()->value('id'),
            'user' => $w['customer']->id,
            'id' => $w['customer']->id,
            'key' => $w['product']->id.'-0',
            'provider' => 'cod',
            default => null,
        };
    }

    /** @return array<string,array> payload name => data */
    private function payloads(): array
    {
        $long = str_repeat('x', 3000);
        $evil = "\0\u{202E}<script>alert(1)</script>' OR 1=1 -- \${7*7} {{7*7}} ../../etc/passwd";
        $set = fn ($v) => array_fill_keys(self::FIELDS, $v);

        return [
            'empty' => [],
            'long strings' => $set($long),
            'arrays' => $set(['a', 'b']),
            'nested arrays' => $set([['x' => ['y' => 1]]]),
            'hostile text' => $set($evil),
            'nulls' => $set(null),
            'huge numbers' => $set('99999999999999999999999999'),
            'negative' => $set(-1),
            'floats' => $set(1.5e308),
            'booleans' => $set(true),
        ];
    }

    private function actors(array $w): array
    {
        return ['guest' => null, 'customer' => $w['customer'], 'admin' => $w['admin']];
    }

    public function test_no_route_that_changes_data_returns_a_server_error_for_hostile_input(): void
    {
        $w = $this->world();
        $failures = [];
        $requests = 0;

        foreach (Route::getRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['GET', 'HEAD']));
            $uri = $route->uri();
            if (! $methods || str_starts_with($uri, '_') || str_starts_with($uri, 'up') || str_starts_with($uri, 'webhooks/') || str_starts_with($uri, 'storage/') || $uri === 'logout') {
                continue;                                                   // webhooks have their own signature tests; logout would end the actor mid-run
            }
            $method = $methods[0];

            $path = '/'.$uri;
            $unresolved = false;
            foreach ($route->parameterNames() as $param) {
                $v = $this->value($param, $uri, $w);
                if ($v === null) {
                    $unresolved = true;
                    break;
                }
                $path = preg_replace('/\{'.$param.'\??\}/', (string) $v, $path);
            }
            if ($unresolved) {
                $this->skipped[] = $method.' '.$uri;
                continue;
            }

            foreach ($this->actors($w) as $actorName => $actor) {
                foreach ($this->payloads() as $payloadName => $payload) {
                    foreach ([false, true] as $json) {
                        if ($json && ! in_array($payloadName, ['empty', 'arrays', 'hostile text', 'nulls', 'floats', 'huge numbers', 'negative', 'booleans'], true)) {
                            continue;
                        }
                        DB::beginTransaction();
                        try {
                            Auth::forgetGuards();
                            $this->flushSession();
                            $this->app['env'] = 'testing';
                            $actor ? $this->actingAs($actor) : null;
                            $res = $json ? $this->json($method, $path, $payload) : $this->call($method, $path, $payload);
                            $requests++;
                            if ($res->getStatusCode() >= 500) {
                                $failures[] = sprintf('%s /%s as %s with %s%s → %d', $method, $uri, $actorName, $payloadName, $json ? ' (JSON)' : '', $res->getStatusCode()).' '.mb_substr((string) ($res->exception?->getMessage() ?? ''), 0, 160);
                            } elseif (! $json && $res->isRedirection() && in_array($payloadName, ['arrays', 'nested arrays', 'hostile text', 'long strings'], true)) {
                                // A failed form redirects back and re-renders the page with the old input: that page must survive it too.
                                $after = $this->followRedirects($res);
                                $requests++;
                                if ($after->getStatusCode() >= 500) {
                                    $failures[] = sprintf('%s /%s as %s with %s, then the page it redirects to → %d', $method, $uri, $actorName, $payloadName, $after->getStatusCode());
                                }
                            }
                        } catch (\Throwable $e) {
                            $failures[] = sprintf('%s /%s as %s with %s → %s: %s', $method, $uri, $actorName, $payloadName, class_basename($e), mb_substr($e->getMessage(), 0, 120));
                        } finally {
                            DB::rollBack();
                        }
                    }
                }
            }
        }

        fwrite(STDERR, sprintf("\n  fuzzed %d requests across the routes; skipped (no sample record): %s\n", $requests, implode(', ', $this->skipped) ?: 'none'));
        $this->assertGreaterThan(1500, $requests, 'the sweep really ran');
        $this->assertSame([], array_slice(array_unique($failures), 0, 40), count(array_unique($failures)).' server errors, first 40 shown');
    }
/** Query strings are input too: every page, as guest and admin, with hostile filter/search/sort/page values. */
    public function test_no_page_returns_a_server_error_for_hostile_query_strings(): void
    {
        $w = $this->world();
        $failures = [];
        $requests = 0;
        $keys = ['q', 'page', 'sort', 'min', 'max', 'availability', 'color', 'size', 'sale', 'category', 'status', 'payment_status', 'from', 'to', 'range', 'group', 'type', 'per_page', 'order', 'dir', 'role', 'active', 'token', 'email', 'number', 'reviews_page'];
        $kinds = ['empty' => [], 'long' => '%s', 'array' => ['a', 'b'], 'nested' => [['x' => 1]], 'hostile' => "\0'\"<script>alert(1)</script>%00 OR 1=1", 'huge' => '99999999999999999999', 'negative' => '-1', 'date' => '9999-99-99', 'unicode' => "\u{202E}😀"];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_starts_with($uri, '_') || str_starts_with($uri, 'up') || str_starts_with($uri, 'storage/') || str_contains($uri, 'export') && false) {
                continue;
            }
            $path = '/'.$uri;
            foreach ($route->parameterNames() as $param) {
                $v = $this->value($param, $uri, $w) ?? ($param === 'token' ? 'abc' : null);
                if ($v === null) {
                    continue 2;
                }
                $path = preg_replace('/\{'.$param.'\??\}/', (string) $v, $path);
            }
            foreach (['guest' => null, 'admin' => $w['admin']] as $actorName => $actor) {
                foreach ($kinds as $kind => $val) {
                    $query = $kind === 'empty' ? [] : array_fill_keys($keys, $kind === 'long' ? str_repeat('x', 3000) : $val);
                    try {
                        Auth::forgetGuards();
                        $this->flushSession();
                        $actor ? $this->actingAs($actor) : null;
                        $res = $this->call('GET', $path, $query);
                        $requests++;
                        if ($res->getStatusCode() >= 500) {
                            $failures[] = sprintf('GET /%s as %s with %s → %d', $uri, $actorName, $kind, $res->getStatusCode());
                        }
                    } catch (\Throwable $e) {
                        $failures[] = sprintf('GET /%s as %s with %s → %s: %s', $uri, $actorName, $kind, class_basename($e), mb_substr($e->getMessage(), 0, 120));
                    }
                }
            }
        }

        fwrite(STDERR, "\n  fuzzed {$requests} GET requests\n");
        $this->assertGreaterThan(800, $requests);
        $this->assertSame([], array_slice(array_unique($failures), 0, 40), count(array_unique($failures)).' server errors, first 40 shown');
    }
/** A failed form redirects back and re-fills itself from the flashed input. Array-valued input must not crash that page. */
    public function test_forms_survive_array_values_after_a_failed_submission(): void
    {
        $this->seedRoles();
        $this->shipping();
        $this->addToCart($this->product());
        $arr = ['a', 'b'];
        $cases = [
            ['register', ['name' => $arr, 'email' => $arr, 'password' => $arr, 'phone' => $arr], '/register'],
            ['login', ['email' => $arr, 'password' => $arr], '/login'],
            ['contact.send', ['name' => $arr, 'email' => $arr, 'message' => $arr, 'phone' => $arr], '/contact'],
            ['checkout.store', $this->checkoutPayload(['name' => $arr, 'address' => $arr, 'city' => $arr, 'phone' => $arr, 'email' => $arr, 'postal_code' => $arr]), '/checkout'],
            ['track.lookup', ['number' => $arr, 'email' => $arr], '/track-order'],
            ['password.email', ['email' => $arr], '/forgot-password'],
            ['newsletter', ['email' => $arr], '/'],
        ];

        foreach ($cases as [$route, $data, $from]) {
            $res = $this->from($from)->followingRedirects()->post(route($route), $data);
            $this->assertLessThan(500, $res->getStatusCode(), "{$route}: the page it returns to crashed");
            $res->assertDontSee('Array', false);
        }

        $user = User::factory()->create(['password' => 'Secret1234']);
        $this->actingAs($user);
        foreach ([['account.profile.update', 'put', '/account/profile'], ['account.addresses.store', 'post', '/account/addresses']] as [$route, $method, $from]) {
            if (! \Illuminate\Support\Facades\Route::has($route)) {
                continue;
            }
            $res = $this->from($from)->followingRedirects()->{$method}(route($route), ['name' => $arr, 'phone' => $arr, 'address' => $arr, 'city' => $arr, 'email' => $arr]);
            $this->assertLessThan(500, $res->getStatusCode(), $route);
        }
    }
}
