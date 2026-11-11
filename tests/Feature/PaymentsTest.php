<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\CardCheckout;
use App\Services\Payments\CardGateway;
use App\Services\Payments\CardProviderDriver;
use App\Services\Payments\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** TEST DOUBLE ONLY: stands in for a provider so the shared card pipeline can be exercised. It is never registered in the app. */
class FakeCardDriver implements CardProviderDriver
{
    public function name(): string { return 'Fake'; }

    public function createCheckout(Order $order, string $returnUrl): CardCheckout
    {
        return new CardCheckout('https://provider.example/pay/'.$order->number, 'TXN-'.$order->number);
    }

    public function parseWebhook(Request $request): ?VerifiedPayment
    {
        if ($request->header('X-Sig') !== 'valid') {
            return null;
        }

        return new VerifiedPayment($request->input('order'), $request->input('ref'), (int) $request->input('amount'), $request->input('state') === 'paid', ['state' => $request->input('state')], $request->input('state') === 'pending');
    }
}

class PaymentsTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        Storage::fake('local');
    }

    private function order(string $method, int $price = 1000): Order
    {
        $this->shipping(250);
        $this->addToCart($this->product(['price' => $price, 'stock' => 10]), null, 1);
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => $method]));

        return Order::latest('id')->firstOrFail();
    }

    private function configureCard(): void
    {
        config(['payments.card' => ['driver' => FakeCardDriver::class, 'key' => 'test-key', 'secret' => 'test-secret', 'webhook_secret' => 'test-hook']]);
        Setting::put('payments.enabled', ['cod', 'bank_transfer', 'card']);
    }

    /* ---------- Cash on delivery ---------- */

    public function test_cod_order_is_recorded_unpaid_and_staff_mark_it_paid_after_collecting_cash(): void
    {
        $order = $this->order('cod');
        $this->assertSame(['cod', 'unpaid', 'pending'], [$order->payment_method, $order->payment_status, $order->status]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'cod', 'status' => 'pending']);

        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => 1250, 'note' => 'Cash collected by rider'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(['paid', 'confirmed'], [$order->payment_status, $order->status]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'cod', 'status' => 'paid', 'verified_by' => $this->admin->id]);
    }

    /* ---------- Bank transfer ---------- */

    public function test_bank_transfer_is_hidden_until_details_exist_and_then_shows_them(): void
    {
        $this->shipping();
        $this->addToCart($this->product());
        $this->get(route('checkout'))->assertDontSee('Bank Transfer');

        $order = $this->order('bank_transfer');
        $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]))->assertOk()->assertSee('TEST BANK, account 0000-0000');
    }

    public function test_a_receipt_can_be_uploaded_and_is_kept_privately_and_never_marks_the_order_paid(): void
    {
        $order = $this->order('bank_transfer');

        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'proof' => UploadedFile::fake()->image('receipt.png', 300, 300)])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('awaiting_verification', $order->payment_status);
        $payment = $order->payments()->firstOrFail();
        $this->assertNotNull($payment->proof_path);
        $this->assertStringStartsWith('payment-proofs/'.$order->id.'/', $payment->proof_path);
        $this->assertStringNotContainsString('receipt', $payment->proof_path);            // never the customer's file name
        Storage::disk('local')->assertExists($payment->proof_path);
        Storage::disk('public')->assertMissing($payment->proof_path);                     // not web-accessible
        $this->assertSame('pending', $payment->status);
    }

    public function test_a_pdf_receipt_with_a_reference_is_accepted(): void
    {
        $order = $this->order('bank_transfer');
        $pdf = UploadedFile::fake()->createWithContent('slip.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'TXN-5521', 'proof' => $pdf])->assertSessionHasNoErrors();
        $payment = $order->payments()->firstOrFail();
        $this->assertSame('TXN-5521', $payment->reference);
        $this->assertStringEndsWith('.pdf', $payment->proof_path);
    }

    public function test_receipts_of_the_wrong_type_or_size_are_refused(): void
    {
        $order = $this->order('bank_transfer');
        $post = fn ($proof) => $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'proof' => $proof]);

        // REAL files (not the framework's fakes, which trust the name): the type is read from the content.
        $real = function (string $name, string $content) {
            $path = tempnam(sys_get_temp_dir(), 'prf');
            file_put_contents($path, $content);

            return new UploadedFile($path, $name, null, null, true);
        };
        $post($real('evil.png', '<?php echo "x"; system($_GET[1]);'))->assertSessionHasErrors('proof');   // a script disguised as a picture
        $post($real('evil.pdf', '<html><script>alert(1)</script></html>'))->assertSessionHasErrors('proof');   // html disguised as a pdf
        $post($real('page.html', '<html><script>alert(1)</script></html>'))->assertSessionHasErrors('proof');
        $post(UploadedFile::fake()->image('huge.jpg')->size(5000))->assertSessionHasErrors('proof');                              // over 4 MB
        $this->assertSame(0, Payment::whereNotNull('proof_path')->count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_a_reference_or_a_receipt_is_required_and_a_reference_cannot_be_reused_on_another_order(): void
    {
        $order = $this->order('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token])->assertSessionHasErrors();
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'DUP-1'])->assertSessionHasNoErrors();

        $other = $this->order('bank_transfer');
        $this->post(route('order.payment-reference', $other), ['token' => $other->access_token, 'reference' => 'DUP-1'])->assertSessionHasErrors('reference');
        $this->assertSame('unpaid', $other->fresh()->payment_status);
    }

    public function test_only_the_order_owner_link_can_submit_a_receipt(): void
    {
        $order = $this->order('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['reference' => 'X-1', 'proof' => UploadedFile::fake()->image('a.png')])->assertNotFound();
        $this->post(route('order.payment-reference', $order), ['token' => 'wrong', 'reference' => 'X-1'])->assertNotFound();
    }

    public function test_staff_can_open_the_receipt_and_the_public_cannot(): void
    {
        $order = $this->order('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'proof' => UploadedFile::fake()->image('r.png')]);
        $payment = $order->payments()->firstOrFail();
        $url = route('admin.orders.payment.proof', [$order, $payment]);

        auth()->logout();
        $this->flushSession();
        $this->assertNotSame(200, $this->get($url)->status());                                  // guests are turned away
        $this->actingAs($this->userWithRole('customer'))->get($url)->assertForbidden();

        $res = $this->actingAs($this->admin)->get($url)->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('View receipt');
    }

    public function test_staff_verification_is_the_only_way_a_transfer_becomes_paid(): void
    {
        $order = $this->order('bank_transfer');
        $this->post(route('order.payment-reference', $order), ['token' => $order->access_token, 'reference' => 'R-9', 'proof' => UploadedFile::fake()->image('r.jpg')]);
        $this->assertNotSame('paid', $order->fresh()->payment_status);

        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total])->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'verified_by' => $this->admin->id]);
    }

    /* ---------- Card: disabled until configured ---------- */

    public function test_card_is_disabled_without_a_driver_and_credentials_and_says_what_is_missing(): void
    {
        $card = app(CardGateway::class);
        $this->assertFalse($card->isAvailable());
        $this->assertCount(4, $card->missing());

        $this->actingAs($this->admin)->get(route('admin.payments.edit'))->assertOk()
            ->assertSee('Card payments are switched off')->assertSee('CARD_API_KEY')->assertSee('CARD_WEBHOOK_SECRET')->assertSee('webhooks/payments/card');

        $this->shipping();
        $this->addToCart($this->product());
        $this->get(route('checkout'))->assertDontSee('Debit / Credit Card');
    }

    public function test_a_disabled_card_cannot_be_enabled_ordered_or_confirmed_by_webhook(): void
    {
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['cod', 'card']])->assertSessionHasErrors('enabled');
        $this->assertNotContains('card', (array) Setting::get('payments.enabled'));

        $this->shipping();
        $this->addToCart($this->product());
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'card']))->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::count());

        $this->postJson('/webhooks/payments/card', ['order' => 'ABH-1', 'ref' => 'x', 'amount' => 1, 'state' => 'paid'], ['X-Sig' => 'valid'])->assertStatus(400);
    }

    public function test_partial_configuration_keeps_card_disabled(): void
    {
        config(['payments.card' => ['driver' => FakeCardDriver::class, 'key' => 'k', 'secret' => '', 'webhook_secret' => '']]);
        $this->assertFalse(app(CardGateway::class)->isAvailable());
        config(['payments.card' => ['driver' => 'Nope\\Missing', 'key' => 'k', 'secret' => 's', 'webhook_secret' => 'w']]);
        $this->assertFalse(app(CardGateway::class)->isAvailable());
        config(['payments.card' => ['driver' => \stdClass::class, 'key' => 'k', 'secret' => 's', 'webhook_secret' => 'w']]);
        $this->assertFalse(app(CardGateway::class)->isAvailable());                    // a class that is not a CardProviderDriver
    }

    /* ---------- Card: configured (through a test double) ---------- */

    public function test_a_configured_card_can_be_enabled_and_checkout_hands_off_without_marking_paid(): void
    {
        $this->configureCard();
        $this->actingAs($this->admin)->put(route('admin.payments.update'), ['enabled' => ['cod', 'card']])->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();

        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->get(route('checkout'))->assertSee('Debit / Credit Card');
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'card']))->assertRedirect('https://provider.example/pay/'.Order::firstOrFail()->number);

        $order = Order::firstOrFail();
        $this->assertSame('pending', $order->payment_status);                          // never paid by the redirect
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'card', 'reference' => 'TXN-'.$order->number, 'status' => 'pending', 'amount' => 1250]);
    }

    public function test_card_webhook_handles_pending_failed_and_paid_and_never_downgrades(): void
    {
        $this->configureCard();
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'card']));
        $order = Order::firstOrFail();
        $hook = fn (array $d, string $sig = 'valid') => $this->postJson('/webhooks/payments/card', $d + ['order' => $order->number, 'amount' => 1250], ['X-Sig' => $sig]);

        $hook(['ref' => 'T1', 'state' => 'paid'], 'forged')->assertStatus(400);        // bad signature changes nothing
        $this->assertSame('pending', $order->fresh()->payment_status);

        $hook(['ref' => 'T1', 'state' => 'pending'])->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);

        $hook(['ref' => 'T1', 'state' => 'failed'])->assertOk();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['reference' => 'T1', 'status' => 'failed']);

        $hook(['ref' => 'T2', 'state' => 'paid', 'amount' => 5])->assertStatus(422);   // wrong amount is never accepted
        $this->assertSame('failed', $order->fresh()->payment_status);

        $hook(['ref' => 'T2', 'state' => 'paid'])->assertOk();                        // a successful retry
        $order->refresh();
        $this->assertSame(['paid', 'confirmed'], [$order->payment_status, $order->status]);

        $hook(['ref' => 'T2', 'state' => 'failed'])->assertOk();                       // a late failure must not undo a paid order
        $hook(['ref' => 'T1', 'state' => 'pending'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['reference' => 'T2', 'status' => 'paid']);
    }

    public function test_a_failed_card_payment_can_be_retried_from_the_confirmation_page(): void
    {
        $this->configureCard();
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'card']));
        $order = Order::firstOrFail();
        $this->postJson('/webhooks/payments/card', ['order' => $order->number, 'ref' => 'T1', 'amount' => 1250, 'state' => 'failed'], ['X-Sig' => 'valid'])->assertOk();

        $page = $this->get(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]));
        $page->assertSee('did not go through')->assertSee('Try card payment again');
        $this->post(route('order.pay', $order), ['token' => $order->access_token])->assertRedirect('https://provider.example/pay/'.$order->number);
        $this->post(route('order.pay', $order))->assertNotFound();                                  // needs the private link
    }

    public function test_no_card_data_is_accepted_or_stored_by_the_shop(): void
    {
        $this->configureCard();
        $this->shipping(250);
        $this->addToCart($this->product(['price' => 1000]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => 'card', 'card_number' => '4111111111111111', 'cvv' => '123', 'expiry' => '12/30']));

        foreach (['payments', 'orders', 'order_addresses'] as $table) {
            $this->assertStringNotContainsString('4111111111111111', json_encode(\DB::table($table)->get()), "$table must not hold a card number");
        }
        $this->assertFalse(\Schema::hasColumn('payments', 'card_number'));
        $this->assertFalse(\Schema::hasColumn('payments', 'cvv'));
    }
}
