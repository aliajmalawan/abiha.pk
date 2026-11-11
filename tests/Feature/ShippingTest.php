<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingMethod;
use App\Services\Shipping\CourierException;
use App\Services\Shipping\CourierManager;
use App\Services\Shipping\CourierProvider;
use App\Services\Shipping\Shipment;
use App\Services\Shipping\TrackingResult;
use App\Services\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** TEST DOUBLE ONLY: a stand-in courier so the integration layer can be exercised. It is never registered in the app. */
class FakeCourier implements CourierProvider
{
    public static bool $fail = false;

    public function code(): string { return 'fakecourier'; }
    public function label(): string { return 'Fake Courier'; }
    public function isConfigured(): bool { return config('shipping.fake_ready', true); }

    public function createShipment(Order $order): Shipment
    {
        if (self::$fail) {
            throw new CourierException('The courier could not be reached.');
        }

        return new Shipment('FC-'.$order->number, 'https://courier.example/t/FC-'.$order->number, 'BK-1');
    }

    public function track(string $trackingNumber): TrackingResult
    {
        return new TrackingResult('In transit', [['at' => null, 'status' => 'In transit', 'location' => null]]);
    }
}

class ShippingTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        FakeCourier::$fail = false;
    }

    private function placed(array $over = []): Order
    {
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload($over));

        return Order::latest('id')->firstOrFail();
    }

    /* ---------- charges, thresholds, province/city rules ---------- */

    public function test_city_beats_province_beats_the_method_default_for_the_charge(): void
    {
        $m = ShippingMethod::create(['code' => 'standard', 'name' => 'Standard', 'cost' => 300, 'is_active' => true]);
        $m->rules()->create(['province' => 'Punjab', 'cost' => 250]);
        $m->rules()->create(['province' => 'Punjab', 'city' => 'Lahore', 'cost' => 150]);
        $m->rules()->create(['province' => 'Sindh', 'cost' => 400, 'is_available' => false]);
        $s = app(ShippingService::class);
        $m = $m->fresh('rules');

        $this->assertSame(150, $s->quote($m, 1000, 'Punjab', 'lahore'));      // city rule, case-insensitive
        $this->assertSame(250, $s->quote($m, 1000, 'Punjab', 'Multan'));      // province rule
        $this->assertSame(300, $s->quote($m, 1000, 'Balochistan', 'Quetta')); // default
        $this->assertNull($s->quote($m, 1000, 'Sindh', 'Karachi'));           // blocked destination
    }

    public function test_free_shipping_threshold_applies_after_discounts_and_a_rule_can_override_it(): void
    {
        $m = ShippingMethod::create(['code' => 'standard', 'name' => 'Standard', 'cost' => 300, 'free_over' => 5000, 'is_active' => true]);
        $m->rules()->create(['province' => 'Punjab', 'cost' => 250, 'free_over' => 2000]);
        $s = app(ShippingService::class);
        $m = $m->fresh('rules');

        $this->assertSame(300, $s->quote($m, 4999, 'Sindh', 'Karachi'));
        $this->assertSame(0, $s->quote($m, 5000, 'Sindh', 'Karachi'));
        $this->assertSame(250, $s->quote($m, 1999, 'Punjab', 'Lahore'));
        $this->assertSame(0, $s->quote($m, 2000, 'Punjab', 'Lahore'));        // the rule's own threshold
    }

    public function test_checkout_charges_the_rule_cost_and_ignores_a_cost_sent_by_the_browser(): void
    {
        $m = $this->shipping(300);
        $m->rules()->create(['province' => 'Punjab', 'city' => 'Lahore', 'cost' => 150]);

        $order = $this->placed(['shipping_cost' => 0]);
        $this->assertSame(150, $order->shipping_cost);
        $this->assertSame(1150, $order->total);
    }

    /* ---------- estimated delivery information ---------- */

    public function test_a_destination_estimate_beats_the_method_estimate_and_is_saved_on_the_order(): void
    {
        $m = $this->shipping(250);
        $m->update(['eta' => '4-6 working days']);
        $m->rules()->create(['province' => 'Punjab', 'city' => 'Lahore', 'cost' => 250, 'eta' => '1-2 working days']);
        $s = app(ShippingService::class);
        $m = $m->fresh('rules');

        $this->assertSame('1-2 working days', $s->etaFor($m, 'Punjab', 'Lahore'));
        $this->assertSame('4-6 working days', $s->etaFor($m, 'Sindh', 'Karachi'));

        $order = $this->placed();
        $this->assertSame('1-2 working days', $order->shipping_eta);
        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))->assertSee('Estimated delivery')->assertSee('1-2 working days');
    }

    public function test_no_estimate_is_shown_when_none_was_entered(): void
    {
        $this->shipping(250);
        $order = $this->placed();
        $this->assertNull($order->shipping_eta);
        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))->assertDontSee('Estimated delivery');
    }

    public function test_the_checkout_page_carries_the_estimates_for_the_live_preview(): void
    {
        $m = $this->shipping(250);
        $m->update(['eta' => '3-5 working days']);
        $m->rules()->create(['province' => 'Sindh', 'cost' => 400, 'eta' => '5-7 working days']);
        $this->addToCart($this->product());

        $this->get(route('checkout'))->assertOk()->assertSee('3-5 working days')->assertSee('5-7 working days');
    }

    public function test_admin_can_set_a_delivery_time_on_a_rule(): void
    {
        $m = $this->shipping(250);
        $this->actingAs($this->admin)->post(route('admin.shipping.rules.store', $m->id), ['province' => 'Sindh', 'cost' => 400, 'eta' => '<b>5-7</b> working days', 'is_available' => 1])->assertSessionHasNoErrors();
        $this->assertSame('5-7 working days', $m->rules()->firstOrFail()->eta);
        $this->actingAs($this->admin)->get(route('admin.shipping.edit', $m->id))->assertOk()->assertSee('5-7 working days');
    }

    /* ---------- manual shipping management ---------- */

    public function test_staff_record_the_courier_and_tracking_by_hand_and_the_customer_sees_exactly_that(): void
    {
        $this->shipping(250);
        $order = $this->placed();

        $this->actingAs($this->admin)->patch(route('admin.orders.tracking', $order), ['courier' => 'Local Rider <i>Co</i>', 'tracking_number' => 'AB-123', 'tracking_url' => 'https://example.com/t/AB-123'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(['Local Rider Co', 'AB-123'], [$order->courier, $order->tracking_number]);

        auth()->logout();
        $this->flushSession();
        $this->post(route('track.lookup'), ['number' => $order->number, 'email' => $order->email])->assertSee('Local Rider Co')->assertSee('AB-123')->assertSee('https://example.com/t/AB-123', false);
    }

    public function test_a_tracking_link_must_be_http_or_https(): void
    {
        $this->shipping(250);
        $order = $this->placed();
        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com/x'] as $bad) {
            $this->actingAs($this->admin)->patch(route('admin.orders.tracking', $order), ['tracking_url' => $bad])->assertSessionHasErrors('tracking_url');
        }
        $this->assertNull($order->fresh()->tracking_url);
    }

    public function test_nothing_is_invented_when_no_tracking_exists(): void
    {
        $this->shipping(250);
        $order = $this->placed();
        $page = $this->post(route('track.lookup'), ['number' => $order->number, 'email' => $order->email]);
        $page->assertOk()->assertDontSee('Tracking number')->assertDontSee('In transit')->assertDontSee('Out for delivery');
    }

    public function test_only_staff_with_the_order_permission_can_save_shipping_details(): void
    {
        $this->shipping(250);
        $order = $this->placed();
        auth()->logout();
        $this->flushSession();
        $this->actingAs($this->userWithRole('customer'))->patch(route('admin.orders.tracking', $order), ['tracking_number' => 'X'])->assertForbidden();
        $this->assertNull($order->fresh()->tracking_number);
    }

    /* ---------- courier integration layer ---------- */

    public function test_no_courier_is_registered_and_the_admin_sees_no_booking_option(): void
    {
        $this->assertSame([], config('shipping.couriers'));
        $this->assertCount(0, app(CourierManager::class)->all());

        $this->shipping(250);
        $order = $this->placed();
        $order->update(['status' => 'confirmed']);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()->assertDontSee('Book shipment');
        $this->actingAs($this->admin)->post(route('admin.orders.shipment', $order), ['courier' => 'anything'])->assertNotFound();
        $this->assertNull($order->fresh()->tracking_number);
    }

    public function test_a_registered_courier_that_is_not_configured_stays_unavailable(): void
    {
        config(['shipping.couriers' => [FakeCourier::class], 'shipping.fake_ready' => false]);
        $this->assertCount(1, app(CourierManager::class)->all());
        $this->assertCount(0, app(CourierManager::class)->available());
        config(['shipping.couriers' => ['Not\\A\\Class', \stdClass::class]]);
        $this->assertCount(0, app(CourierManager::class)->all());
    }

    public function test_a_configured_courier_books_a_shipment_and_the_tracking_comes_from_the_courier(): void
    {
        config(['shipping.couriers' => [FakeCourier::class]]);
        $this->shipping(250);
        $order = $this->placed();
        $order->update(['status' => 'confirmed']);

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('Book shipment')->assertSee('Fake Courier');
        $this->actingAs($this->admin)->post(route('admin.orders.shipment', $order), ['courier' => 'fakecourier'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['Fake Courier', 'FC-'.$order->number, 'BK-1'], [$order->courier, $order->tracking_number, $order->courier_reference]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.shipment_booked']);

        $this->actingAs($this->admin)->post(route('admin.orders.shipment', $order), ['courier' => 'fakecourier'])->assertSessionHasErrors('shipment');   // not booked twice
    }

    public function test_a_courier_failure_shows_a_clear_message_and_changes_nothing(): void
    {
        config(['shipping.couriers' => [FakeCourier::class]]);
        FakeCourier::$fail = true;
        $this->shipping(250);
        $order = $this->placed();
        $order->update(['status' => 'confirmed']);

        $this->actingAs($this->admin)->post(route('admin.orders.shipment', $order), ['courier' => 'fakecourier'])->assertSessionHasErrors('shipment');
        $this->assertNull($order->fresh()->tracking_number);
    }

    public function test_a_pending_or_cancelled_order_cannot_be_booked(): void
    {
        config(['shipping.couriers' => [FakeCourier::class]]);
        $this->shipping(250);
        $order = $this->placed();                                                  // still "pending"

        $this->actingAs($this->admin)->post(route('admin.orders.shipment', $order), ['courier' => 'fakecourier'])->assertSessionHasErrors('shipment');
        $this->assertNull($order->fresh()->tracking_number);
    }
}
