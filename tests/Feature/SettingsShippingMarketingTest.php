<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class SettingsShippingMarketingTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        Storage::fake('public');
    }

    /* ---------------- city-wise shipping ---------------- */

    private function method(): ShippingMethod
    {
        $m = $this->shipping(250, 5000);
        $m->rules()->create(['province' => 'Punjab', 'cost' => 300]);
        $m->rules()->create(['province' => 'Punjab', 'city' => 'Lahore', 'cost' => 150, 'free_over' => 1500]);
        $m->rules()->create(['province' => 'Sindh', 'city' => 'Karachi', 'cost' => 0, 'is_available' => false]);

        return $m->fresh('rules');
    }

    public function test_city_rule_beats_province_rule_beats_default(): void
    {
        $m = $this->method();
        $svc = app(ShippingService::class);

        $this->assertSame(150, $svc->quote($m, 1000, 'Punjab', 'Lahore'));
        $this->assertSame(150, $svc->quote($m, 1000, 'punjab', '  LAHORE '));       // case/space insensitive
        $this->assertSame(0, $svc->quote($m, 1500, 'Punjab', 'Lahore'));            // city rule's own free-over
        $this->assertSame(300, $svc->quote($m, 1000, 'Punjab', 'Multan'));          // province rule
        $this->assertSame(250, $svc->quote($m, 1000, 'Balochistan', 'Quetta'));     // method default
        $this->assertSame(0, $svc->quote($m, 5000, 'Balochistan', 'Quetta'));       // method free-over
        $this->assertNull($svc->quote($m, 1000, 'Sindh', 'Karachi'));               // not offered
        $this->assertSame(250, $svc->quote($m, 1000, 'Sindh', 'Hyderabad'));        // only Karachi is blocked
    }

    public function test_checkout_charges_the_destination_rate_and_refuses_unavailable_cities(): void
    {
        $this->method();
        $p = $this->product(['price' => 1000, 'stock' => 20]);

        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload(['province' => 'Punjab', 'city' => 'lahore']))->assertSessionHasNoErrors();
        $this->assertSame(150, Order::latest('id')->first()->shipping_cost);
        $this->assertSame(1150, Order::latest('id')->first()->total);

        $this->flushSession();
        $this->addToCart($p);
        $this->post(route('checkout.store'), $this->checkoutPayload(['province' => 'Punjab', 'city' => 'Multan']));
        $this->assertSame(300, Order::latest('id')->first()->shipping_cost);

        // blocked destination: no order, bag untouched, clear message
        $this->flushSession();
        $this->addToCart($p);
        $before = Order::count();
        $this->post(route('checkout.store'), $this->checkoutPayload(['province' => 'Sindh', 'city' => 'Karachi']))->assertRedirect(route('cart'))->assertSessionHasErrors('cart');
        $this->assertSame($before, Order::count());
        $this->assertSame(1, app(\App\Services\Cart\CartService::class)->count());
    }

    public function test_the_submitted_shipping_amount_is_ignored(): void
    {
        $this->method();
        $this->addToCart($this->product(['price' => 1000, 'stock' => 5]));

        $this->post(route('checkout.store'), $this->checkoutPayload(['province' => 'Punjab', 'city' => 'Lahore', 'shipping_cost' => 0, 'total' => 1]));
        $this->assertSame(150, Order::first()->shipping_cost);
    }

    public function test_admin_manages_shipping_rules_without_duplicates(): void
    {
        $m = $this->shipping(250);
        $url = route('admin.shipping.rules.store', $m->id);

        $this->actingAs($this->admin)->post($url, ['cost' => 100])->assertSessionHasErrors('city');                                   // needs a destination
        $this->actingAs($this->admin)->post($url, ['province' => 'Atlantis', 'cost' => 100])->assertSessionHasErrors('province');
        $this->actingAs($this->admin)->post($url, ['city' => 'Lahore', 'province' => 'Punjab', 'cost' => 100, 'is_available' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->admin)->post($url, ['city' => 'LAHORE', 'province' => 'Punjab', 'cost' => 200, 'is_available' => 1])->assertSessionHasErrors('city');   // duplicate
        $this->assertSame(1, $m->rules()->count());

        $rule = $m->rules()->first();
        $this->actingAs($this->admin)->get(route('admin.shipping.edit', $m->id))->assertOk()->assertSee('Lahore');
        $this->actingAs($this->admin)->delete(route('admin.shipping.rules.destroy', $rule))->assertSessionHasNoErrors();
        $this->assertSame(0, $m->rules()->count());
    }

    /* ---------------- payment settings ---------------- */

    public function test_enabled_payment_methods_drive_checkout(): void
    {
        $this->shipping();
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['bank_transfer'], 'bank_details' => "Bank X\nAcct 123"])->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();                                                        // now behave as a shopper
        $this->addToCart($this->product());
        $page = $this->get(route('checkout'))->assertOk();
        $page->assertSee('Bank Transfer')->assertDontSee('Cash on Delivery');

        // a disabled method cannot be used even if posted by hand
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'cod']))->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::count());
    }

    public function test_bank_transfer_needs_account_details_and_unknown_methods_are_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['bank_transfer']])->assertSessionHasErrors('bank_details');
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['jazzcash']])->assertSessionHasErrors('enabled.0');
        $this->assertNull(Setting::get('payments.enabled'));
    }

    public function test_cod_message_is_editable_and_shown_at_checkout(): void
    {
        $this->shipping();
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['cod'], 'cod_note' => 'Keep exact change ready'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('admin.payments.edit'))->assertOk()->assertSee('Card payments are switched off');

        auth()->logout();
        $this->flushSession();
        $this->addToCart($this->product());
        $this->get(route('checkout'))->assertSee('Keep exact change ready');
    }

    /* ---------------- general settings ---------------- */

    public function test_site_name_and_currency_flow_to_the_storefront(): void
    {
        $this->product(['name' => 'Silk Scarf', 'price' => 4500]);
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__name' => 'Abiha Test', 's__currency__symbol' => 'Rs.', 's__currency__code' => 'pkr', 's__seo__allow_indexing' => 1])->assertSessionHasNoErrors();

        $this->assertSame('PKR', Setting::get('currency.code'));                      // normalised to upper-case
        $this->get(route('home'))->assertSee('Abiha Test®');
        $shop = $this->get(route('shop'))->assertOk();
        $shop->assertSee('Rs. 4,500')->assertSee('Shop | Abiha Test®', false);
    }

    public function test_tax_is_recorded_as_the_inclusive_portion_without_changing_the_total(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__tax__enabled' => 1, 's__tax__rate' => 10, 's__tax__label' => 'GST'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();

        [$order] = $this->placeGuestOrder('cod', 1000, 2);                           // subtotal 2,000 + shipping 250

        $this->assertSame(2250, $order->total);                                       // unchanged
        $this->assertSame(182, $order->tax_amount);                                   // 2000 × 10 / 110
        $this->assertEquals(10.0, (float) $order->tax_rate);
        $this->assertSame('GST', $order->tax_label);

        $this->actingAs($this->admin)->get(route('admin.orders.invoice', $order))->assertSee('Includes GST');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__tax__rate' => 150])->assertSessionHasErrors('s__tax__rate');
    }

    public function test_orders_have_no_tax_when_tax_is_off(): void
    {
        [$order] = $this->placeGuestOrder();

        $this->assertSame(0, $order->tax_amount);
        $this->assertNull($order->tax_label);
    }

    public function test_search_engines_are_blocked_outside_production_and_when_the_owner_says_so(): void
    {
        $this->get(route('home'))->assertSee('noindex', false);                       // testing env

        $this->app['env'] = 'production';
        Setting::put('seo.allow_indexing', true);
        $this->get(route('home'))->assertDontSee('noindex', false);

        Setting::put('seo.allow_indexing', false);
        $this->get(route('home'))->assertSee('noindex', false);
    }

    public function test_logo_and_favicon_uploads_are_validated_and_used(): void
    {
        $bad = $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')]);
        $bad->assertSessionHasErrors('s__site__logo');                                // SVG can carry scripts: refused
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__logo' => UploadedFile::fake()->create('x.php', 5, 'application/x-php')])->assertSessionHasErrors('s__site__logo');

        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            's__site__logo' => UploadedFile::fake()->image('logo.png', 400, 300),
            's__site__favicon' => UploadedFile::fake()->image('fav.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $logo = Setting::get('site.logo');
        Storage::disk('public')->assertExists($logo);
        $this->get(route('home'))->assertSee($logo, false)->assertSee(Setting::get('site.favicon'), false);

        // removing it returns to the supplied artwork and deletes the file
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['remove_s__site__logo' => 1])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($logo);
        $this->get(route('home'))->assertSee('abiha-logo-full-600.png', false);
    }

    public function test_the_brand_logo_and_the_favicon_are_uploaded_separately(): void
    {
        $url = route('admin.settings.update');
        $this->actingAs($this->admin)->get(route('admin.settings.edit'))->assertOk()->assertSee('Brand logo')->assertSee('Favicon icon');

        $this->put($url, ['s__site__logo' => UploadedFile::fake()->image('logo.png', 400, 300)])->assertSessionHasNoErrors();
        $logo = Setting::get('site.logo');
        $this->assertNotNull($logo);
        $this->assertNull(Setting::get('site.favicon'), 'uploading only the logo leaves the favicon alone');

        $this->put($url, ['s__site__favicon' => UploadedFile::fake()->image('fav.png', 64, 64)])->assertSessionHasNoErrors();
        $this->assertSame($logo, Setting::get('site.logo'), 'uploading only the favicon leaves the logo alone');
        $fav = Setting::get('site.favicon');
        $this->assertNotNull($fav);
        $this->assertNotSame($logo, $fav);

        $this->put($url, ['remove_s__site__favicon' => 1])->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('site.favicon'));
        $this->assertSame($logo, Setting::get('site.logo'), 'removing the favicon keeps the logo');
    }
    public function test_contact_details_and_footer_text_appear_on_the_site(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            's__contact__email' => 'hello@example.com', 's__contact__whatsapp' => '+92 300 1234567', 's__contact__address' => '1 Sample Road', 's__footer__text' => 'Considered pieces.',
            's__social__tiktok' => 'https://tiktok.com/@abiha', 's__social__youtube' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('s__social__youtube');                              // non-URL refused
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            's__contact__email' => 'hello@example.com', 's__contact__whatsapp' => '+92 300 1234567', 's__contact__address' => '1 Sample Road', 's__footer__text' => 'Considered pieces.',
            's__social__tiktok' => 'https://tiktok.com/@abiha',
        ])->assertSessionHasNoErrors();

        $this->get(route('home'))->assertSee('Considered pieces.')->assertSee('https://wa.me/923001234567', false)->assertSee('TikTok');
        $this->get(route('contact'))->assertSee('1 Sample Road')->assertSee('hello@example.com');
    }

    /* ---------------- marketing ---------------- */

    public function test_bulk_sale_discounts_once_and_ending_it_restores_prices(): void
    {
        $plain = $this->product(['price' => 1000]);
        $onSale = $this->product(['price' => 2000, 'compare_price' => 2500]);
        [$vp, $variants] = $this->variantProduct();                                   // base 2,000; L has its own price 2,500

        $this->actingAs($this->admin)->post(route('admin.discounts.apply'), ['scope' => 'all', 'percent' => 20])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([800, 1000], [$plain->fresh()->price, $plain->fresh()->compare_price]);
        $this->assertSame([2000, 2500], [$onSale->fresh()->price, $onSale->fresh()->compare_price]);   // already on sale: never compounded
        $this->assertSame([1600, 2000], [$vp->fresh()->price, $vp->fresh()->compare_price]);
        $this->assertSame([2000, 2500], [$variants['L']->fresh()->price, $variants['L']->fresh()->compare_price]);

        // applying twice does not stack
        $this->actingAs($this->admin)->post(route('admin.discounts.apply'), ['scope' => 'all', 'percent' => 20]);
        $this->assertSame(800, $plain->fresh()->price);

        $this->actingAs($this->admin)->post(route('admin.discounts.clear'), ['scope' => 'all'])->assertSessionHasNoErrors();
        $this->assertSame([1000, null], [$plain->fresh()->price, $plain->fresh()->compare_price]);
        $this->assertSame([2500, null], [$variants['L']->fresh()->price, $variants['L']->fresh()->compare_price]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'discount.applied']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'discount.cleared']);
    }

    public function test_sale_can_target_one_category_and_validates_input(): void
    {
        $cat = Category::create(['name' => 'Tops', 'slug' => 'tops']);
        $in = $this->product(['price' => 1000, 'category_id' => $cat->id]);
        $out = $this->product(['price' => 1000]);

        $this->actingAs($this->admin)->post(route('admin.discounts.apply'), ['scope' => 'category', 'category_id' => $cat->id, 'percent' => 50])->assertSessionHasNoErrors();
        $this->assertSame(500, $in->fresh()->price);
        $this->assertSame(1000, $out->fresh()->price);

        foreach ([0, 95, 'abc'] as $bad) {
            $this->actingAs($this->admin)->post(route('admin.discounts.apply'), ['scope' => 'all', 'percent' => $bad])->assertSessionHasErrors('percent');
        }
        $this->actingAs($this->admin)->post(route('admin.discounts.apply'), ['scope' => 'category', 'percent' => 10])->assertSessionHasErrors('category_id');
        // the storefront shows the sale
        $this->get(route('product', $in))->assertSee('500');
    }

    public function test_featured_flags_save_only_for_the_products_on_the_submitted_page(): void
    {
        $a = $this->product(['is_featured' => false]);
        $b = $this->product(['is_featured' => true, 'is_new' => true]);
        $c = $this->product(['is_featured' => true]);                                  // not on the submitted page

        $this->actingAs($this->admin)->put(route('admin.featured.update'), [
            'ids' => [$a->id, $b->id],
            'flags' => [$a->id => ['is_featured' => 1, 'is_bestseller' => 1]],          // b: everything unticked
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($a->fresh()->is_featured && $a->fresh()->is_bestseller);
        $this->assertFalse($b->fresh()->is_featured || $b->fresh()->is_new);
        $this->assertTrue($c->fresh()->is_featured);                                    // untouched
        $this->actingAs($this->admin)->get(route('admin.featured.index'))->assertOk();
        $this->actingAs($this->admin)->put(route('admin.featured.update'), ['ids' => [999999]])->assertSessionHasErrors('ids.0');
    }

    /* ---------------- staff sign-in ---------------- */

    public function test_staff_sign_in_page_is_separate_and_guests_are_sent_there(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));          // customers keep their own page
        $this->get(route('admin.login'))->assertOk()->assertSee('Staff area');
    }

    public function test_admin_login_accepts_staff_only_with_one_generic_error(): void
    {
        $staff = $this->userWithRole('manager', ['password' => 'Secret123456']);
        $customer = $this->userWithRole('customer', ['password' => 'Secret123456']);
        $blocked = $this->userWithRole('admin', ['password' => 'Secret123456', 'is_active' => false]);

        $this->post(route('admin.login.store'), ['email' => $customer->email, 'password' => 'Secret123456'])->assertSessionHasErrors('email');
        $this->assertGuest();                                                           // valid customer credentials never open /admin
        $this->post(route('admin.login.store'), ['email' => $blocked->email, 'password' => 'Secret123456'])->assertSessionHasErrors('email');
        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'Secret123456'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staff);
        $this->assertNotNull($staff->fresh()->last_login_at);
    }

    public function test_signed_in_users_visiting_a_login_page_go_to_the_right_place(): void
    {
        $this->actingAs($this->admin)->get(route('admin.login'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->userWithRole('customer'))->get(route('login'))->assertRedirect(route('account.dashboard'));
    }

    public function test_admin_login_is_rate_limited(): void
    {
        $staff = $this->userWithRole('admin', ['password' => 'Secret123456']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'nope']);
        }

        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'Secret123456'])->assertSessionHasErrors('email');
        $this->assertGuest();                                                           // even the right password is refused while locked out
    }
}
