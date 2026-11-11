<?php

namespace Tests\Feature;

use App\Mail\OrderMail;
use App\Mail\StaffOrderMail;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The order emails: what is sent, to whom, with what, and that a mail failure never harms an order. */
class OrderEmailTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        $this->shipping(250);
    }

    private function place(array $over = []): Order
    {
        $this->addToCart($this->product(['price' => 1000, 'name' => 'Mail Kurta']));
        $this->post(route('checkout.store'), $this->checkoutPayload($over + ['email' => 'Buyer@Example.com']));

        return Order::latest('id')->firstOrFail();
    }

    public function test_a_confirmation_email_goes_to_the_customer_when_the_order_is_placed(): void
    {
        Mail::fake();
        $order = $this->place();

        Mail::assertSent(OrderMail::class, function (OrderMail $m) use ($order) {
            return $m->hasTo('buyer@example.com') && $m->kind === 'placed' && $m->order->is($order);
        });
        Mail::assertSentCount(1);                                                                // no notify address set: nobody else is mailed
    }

    public function test_the_email_shows_the_real_order_and_nothing_invented(): void
    {
        $order = $this->place(['notes' => 'x']);
        $mail = new OrderMail($order, 'placed');

        $mail->assertSeeInHtml('Order <strong>'.$order->number.'</strong>', false);
        $mail->assertSeeInHtml('Mail Kurta');
        $mail->assertSeeInHtml('PKR 1,250');                                                      // 1,000 + 250 shipping
        $mail->assertSeeInHtml('Cash on Delivery');
        $mail->assertSeeInHtml('House 1, Street 2, Sample Town');
        $mail->assertSeeInHtml(route('order.confirmation', ['order' => $order, 'token' => $order->access_token]), false);
        $mail->assertSeeInText('Total: PKR 1,250');
        $mail->assertDontSeeInHtml('Tracking number');                                           // nothing shipped, so no courier details are made up
        $mail->assertDontSeeInHtml('Estimated delivery');                                        // none was configured
        $this->assertStringContainsString('received', $mail->envelope()->subject);
    }

    public function test_a_bank_transfer_email_carries_the_account_details_and_the_reference_to_quote(): void
    {
        Mail::fake();
        $order = $this->place(['payment_method' => 'bank_transfer']);

        Mail::assertSent(OrderMail::class, function (OrderMail $m) use ($order) {
            $m->assertSeeInHtml('TEST BANK, account 0000-0000');
            $m->assertSeeInHtml('quote <strong>'.$order->number.'</strong>', false);

            return true;
        });
    }

    public function test_the_owner_can_also_be_told_about_new_orders(): void
    {
        Mail::fake();
        Setting::put('mail.notify', 'owner@example.com');
        $order = $this->place();

        Mail::assertSent(StaffOrderMail::class, fn (StaffOrderMail $m) => $m->hasTo('owner@example.com') && $m->order->is($order));
        Mail::assertSent(OrderMail::class, 1);
    }

    public function test_shipped_delivered_cancelled_and_returned_send_an_email_but_quiet_steps_do_not(): void
    {
        $order = $this->place();
        Mail::fake();
        $this->actingAs($this->admin);

        foreach (['confirmed', 'processing'] as $quiet) {
            $this->patch(route('admin.orders.status', $order), ['status' => $quiet]);
        }
        Mail::assertNothingSent();

        $this->patch(route('admin.orders.tracking', $order), ['courier' => 'Swift Rider', 'tracking_number' => 'SR-77', 'tracking_url' => 'https://example.com/t/SR-77']);
        $this->patch(route('admin.orders.status', $order), ['status' => 'shipped']);
        Mail::assertSent(OrderMail::class, function (OrderMail $m) {
            if ($m->kind !== 'shipped') {
                return false;
            }
            $m->assertSeeInHtml('Swift Rider');
            $m->assertSeeInHtml('SR-77');
            $m->assertSeeInHtml('https://example.com/t/SR-77', false);

            return $m->hasTo('buyer@example.com');
        });

        $this->patch(route('admin.orders.status', $order), ['status' => 'delivered']);
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === 'delivered');
        Mail::assertSentCount(2);
    }

    public function test_a_cancelled_order_email_goes_out_for_staff_and_for_customer_cancellations(): void
    {
        $order = $this->place();
        Mail::fake();
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled']);
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === 'cancelled');

        $customer = $this->userWithRole('customer', ['email' => 'mine@example.com']);
        $this->actingAs($customer);
        $this->addToCart($this->product(['price' => 500]));
        $this->post(route('checkout.store'), $this->checkoutPayload(['email' => 'mine@example.com']));
        $mine = Order::latest('id')->firstOrFail();
        Mail::fake();
        $this->post(route('account.orders.cancel', $mine))->assertSessionHasNoErrors();
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === 'cancelled' && $m->hasTo('mine@example.com'));
    }

    public function test_marking_a_payment_as_received_sends_one_payment_email(): void
    {
        $order = $this->place(['payment_method' => 'bank_transfer']);
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total])->assertSessionHasNoErrors();

        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === 'paid' && $m->hasTo('buyer@example.com'));
        Mail::assertSentCount(1);

        $this->post(route('admin.orders.payment.verify', $order), ['amount' => $order->total])->assertSessionHasErrors('payment');   // already paid: refused
        Mail::assertSentCount(1);                                                                // and no second email
    }

    public function test_a_mail_failure_never_blocks_the_order_or_the_status_change(): void
    {
        config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $order = $this->place();

        $this->assertNotNull($order->number, 'the order was saved even though SMTP is unreachable');
        $this->assertSame(1, Order::count());
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => 'confirmed', 'note' => 'ok'])->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_emails_go_through_the_sender_name_and_address_saved_in_the_settings(): void
    {
        Setting::put('mail.from_name', 'ABIHA Store');
        Setting::put('mail.from_address', 'orders@abiha.pk');
        \App\Support\SiteSettings::apply();
        $this->assertSame('orders@abiha.pk', config('mail.from.address'));

        Setting::put('contact.email', 'help@abiha.pk');
        $order = $this->place();
        $envelope = (new OrderMail($order, 'placed'))->envelope();
        $this->assertSame('help@abiha.pk', $envelope->replyTo[0]->address);
    }
}
