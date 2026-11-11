<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Support\Menus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Admin-editable menus, the shipments list and the payment history page. */
class MenusShipmentsPaymentHistoryTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
    }

    /* ---------- menus ---------- */

    public function test_the_default_links_show_in_the_header_and_footer_until_the_owner_edits_them(): void
    {
        $html = $this->get(route('home'))->getContent();
        foreach (['New In', 'Sale', 'Track order', 'Shipping &amp; returns', 'Contact us'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString(url('/track-order'), $html);
    }

    public function test_saved_menus_replace_the_defaults_in_the_header_and_the_footer(): void
    {
        $this->actingAs($this->admin)->put(route('admin.menus.update'), [
            'header' => [['label' => 'Lookbook', 'url' => '/shop?sort=newest'], ['label' => 'Partner', 'url' => 'https://example.com/hello']],
            'footer_shop' => [['label' => 'Everything', 'url' => '/shop']],
            'footer_help' => [['label' => 'Talk to us', 'url' => '/contact']],
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->flushSession();
        $html = $this->get(route('home'))->getContent();
        foreach (['Lookbook', 'Partner', 'Everything', 'Talk to us', url('/shop?sort=newest'), 'https://example.com/hello'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('>Track order<', $html);                           // the old default is gone
        $this->assertStringNotContainsString('Track your order', $html);
    }

    public function test_dangerous_or_malformed_addresses_are_refused_and_never_printed(): void
    {
        foreach (['javascript:alert(1)', '//evil.example/x', 'data:text/html,x', 'ftp://example.com', 'shop', '/has space'] as $bad) {
            $this->actingAs($this->admin)->put(route('admin.menus.update'), ['header' => [['label' => 'X', 'url' => $bad]]])->assertSessionHasErrors('header.0.url');
        }
        $this->assertFalse(Menus::safe('javascript:alert(1)'));
        $this->assertTrue(Menus::safe('/shop?sale=1'));
        $this->assertTrue(Menus::safe('https://example.com/a'));

        // even a bad value that got into the database some other way is skipped at print time
        Setting::put('menu.header', [['label' => 'Evil', 'url' => 'javascript:alert(1)'], ['label' => 'Fine', 'url' => '/shop']]);
        $html = $this->get(route('home'))->getContent();
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString('Fine', $html);
    }

    public function test_labels_are_plain_text_and_lists_are_capped(): void
    {
        $this->actingAs($this->admin)->put(route('admin.menus.update'), ['header' => [['label' => '<b>Bold</b> Sale', 'url' => '/shop']]])->assertSessionHasNoErrors();
        $this->assertSame('Bold Sale', Menus::rows('header')[0]['label']);

        $many = array_fill(0, 13, ['label' => 'A', 'url' => '/shop']);
        $this->put(route('admin.menus.update'), ['header' => $many])->assertSessionHasErrors('header');
        $this->put(route('admin.menus.update'), ['header' => [['label' => str_repeat('x', 41), 'url' => '/shop']]])->assertSessionHasErrors('header.0.label');
    }

    public function test_an_empty_list_shows_no_links_and_restore_brings_the_defaults_back(): void
    {
        $this->actingAs($this->admin)->put(route('admin.menus.update'), ['header' => []])->assertSessionHasNoErrors();
        $this->assertSame([], Menus::get('header'));

        $this->put(route('admin.menus.update'), ['restore' => 'header'])->assertSessionHasNoErrors();
        $this->assertSame(['New In', 'Sale', 'Track order'], array_column(Menus::get('header'), 'label'));
        $this->put(route('admin.menus.update'), ['restore' => 'nonsense'])->assertStatus(422);
    }

    public function test_the_menu_editor_is_only_for_people_who_manage_content(): void
    {
        $this->actingAs($this->admin)->get(route('admin.menus.edit'))->assertOk()->assertSee('Header links')->assertSee('Footer: Help column');
        $this->actingAs($this->userWithRole('content_manager'))->get(route('admin.menus.edit'))->assertOk();
        $this->actingAs($this->userWithRole('inventory_manager'))->get(route('admin.menus.edit'))->assertForbidden();
        $this->actingAs($this->userWithRole('inventory_manager'))->put(route('admin.menus.update'), ['header' => []])->assertForbidden();
        $this->actingAs($this->userWithRole('customer'))->get(route('admin.menus.edit'))->assertForbidden();
    }

    /* ---------- shipments ---------- */

    private function placed(string $payment = 'cod'): Order
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => $payment]));
        auth()->logout();
        $this->flushSession();

        return Order::latest('id')->firstOrFail();
    }

    public function test_the_shipments_list_shows_courier_tracking_and_status_and_filters(): void
    {
        $order = $this->placed();
        $this->actingAs($this->admin)->get(route('admin.shipments.index'))->assertOk()->assertSee('No shipments yet.');

        $this->patch(route('admin.orders.tracking', $order), ['courier' => 'Swift Rider', 'tracking_number' => 'SR-123', 'tracking_url' => 'https://example.com/t/SR-123']);
        $this->get(route('admin.shipments.index'))->assertOk()->assertSee($order->number)->assertSee('Swift Rider')->assertSee('SR-123')->assertSee('Pending');

        $this->get(route('admin.shipments.index', ['q' => 'SR-123']))->assertSee($order->number);
        $this->get(route('admin.shipments.index', ['q' => 'nothing-like-this']))->assertSee('No shipments yet.');
        $this->get(route('admin.shipments.index', ['status' => 'delivered']))->assertSee('No shipments yet.');
        $this->get(route('admin.shipments.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_the_shipments_list_is_for_order_staff_only(): void
    {
        $this->actingAs($this->userWithRole('staff'))->get(route('admin.shipments.index'))->assertOk();
        $this->actingAs($this->userWithRole('product_manager'))->get(route('admin.shipments.index'))->assertForbidden();
    }

    public function test_the_order_page_lists_the_shipment_timeline_and_payment_history(): void
    {
        $order = $this->placed('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-42']);
        $this->actingAs($this->admin)->patch(route('admin.orders.tracking', $order), ['courier' => 'Swift Rider', 'tracking_number' => 'SR-9']);

        $this->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Payment history (2)')->assertSee('Transfer reported')->assertSee('TXN-42')
            ->assertSee('Shipment timeline (1)')->assertSee('Courier details saved by staff');
    }

    /* ---------- payment history ---------- */

    public function test_the_payment_history_page_lists_every_event_and_filters_them(): void
    {
        $order = $this->placed('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-77']);
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total])->assertSessionHasNoErrors();

        $this->get(route('admin.payments.transactions'))->assertOk()
            ->assertSee('Payment history')->assertSee($order->number)->assertSee('Transfer reported')->assertSee('Verified')->assertSee('TXN-77');
        $this->get(route('admin.payments.transactions', ['status' => 'paid']))->assertSee('Verified')->assertDontSee('Transfer reported');
        $this->get(route('admin.payments.transactions', ['provider' => 'card']))->assertSee('No payment records yet.');
        $this->get(route('admin.payments.transactions', ['q' => 'TXN-77']))->assertSee($order->number);
        $this->get(route('admin.payments.transactions', ['provider' => 'paypal']))->assertSessionHasErrors('provider');
    }

    public function test_the_payment_history_needs_the_verify_payments_permission(): void
    {
        $this->actingAs($this->userWithRole('staff'))->get(route('admin.payments.transactions'))->assertOk();            // the Order Manager verifies payments
        $this->actingAs($this->userWithRole('content_manager'))->get(route('admin.payments.transactions'))->assertForbidden();
        $this->actingAs($this->userWithRole('inventory_manager'))->get(route('admin.payments.transactions'))->assertForbidden();
    }
}
