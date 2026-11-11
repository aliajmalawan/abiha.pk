<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * No real provider exists yet. This test double stands in for one ONLY to prove that the shared
 * webhook pipeline (signature gate → amount check → idempotency) never marks an order paid unless verified.
 */
class StubOnlineGateway implements OnlinePaymentGateway
{
    public function code(): string { return 'stub'; }
    public function label(): string { return 'Stub'; }
    public function description(): string { return ''; }
    public function isAvailable(): bool { return true; }
    public function initiate(Order $order): string { return 'https://provider.example/pay/'.$order->number; }

    public function handleWebhook(Request $request): ?VerifiedPayment
    {
        if ($request->header('X-Signature') !== 'valid') {
            return null;                                    // signature verification failed
        }

        return new VerifiedPayment($request->input('order'), $request->input('ref'), (int) $request->input('amount'), (bool) $request->input('paid', true), $request->all());
    }
}

class PaymentWebhookTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.gateways.stub' => StubOnlineGateway::class]);
    }

    private function onlineOrder(int $total = 2250): Order
    {
        return Order::create([
            'number' => 'ABH-TEST-0001', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'stub',
            'shipping_method_name' => 'Standard', 'email' => 't@example.com',
            'subtotal' => $total, 'total' => $total, 'access_token' => str_repeat('a', 40),
        ]);
    }

    public function test_unknown_provider_is_a_404(): void
    {
        $this->postJson('/webhooks/payments/jazzcash', [])->assertNotFound();
        $this->postJson('/webhooks/payments/cod', [])->assertNotFound();           // offline gateways have no webhook
    }

    public function test_invalid_signature_never_marks_an_order_paid(): void
    {
        $order = $this->onlineOrder();

        $this->postJson('/webhooks/payments/stub', ['order' => $order->number, 'ref' => 'X1', 'amount' => 2250])->assertStatus(400);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_verified_payment_with_the_right_amount_marks_the_order_paid(): void
    {
        $order = $this->onlineOrder();

        $this->postJson('/webhooks/payments/stub', ['order' => $order->number, 'ref' => 'X1', 'amount' => 2250], ['X-Signature' => 'valid'])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'stub', 'reference' => 'X1', 'status' => 'paid']);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $order = $this->onlineOrder();

        $this->postJson('/webhooks/payments/stub', ['order' => $order->number, 'ref' => 'X2', 'amount' => 1], ['X-Signature' => 'valid'])->assertStatus(422);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_duplicate_webhooks_are_idempotent(): void
    {
        $order = $this->onlineOrder();
        $body = ['order' => $order->number, 'ref' => 'X3', 'amount' => 2250];

        $this->postJson('/webhooks/payments/stub', $body, ['X-Signature' => 'valid'])->assertOk();
        $this->postJson('/webhooks/payments/stub', $body, ['X-Signature' => 'valid'])->assertOk();

        $this->assertSame(1, $order->payments()->where('reference', 'X3')->count());
    }

    public function test_a_failed_payment_event_is_recorded_as_failed(): void
    {
        $order = $this->onlineOrder();

        $this->postJson('/webhooks/payments/stub', ['order' => $order->number, 'ref' => 'X4', 'amount' => 2250, 'paid' => false], ['X-Signature' => 'valid'])->assertOk();

        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_a_provider_cannot_pay_an_order_that_belongs_to_another_method(): void
    {
        $order = $this->onlineOrder();
        $order->update(['payment_method' => 'cod']);

        $this->postJson('/webhooks/payments/stub', ['order' => $order->number, 'ref' => 'X5', 'amount' => 2250], ['X-Signature' => 'valid'])->assertStatus(422);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
