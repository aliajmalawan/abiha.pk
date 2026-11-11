<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * Debit/credit card payments through a hosted provider checkout. Provider independent: it works with whatever
 * CardProviderDriver is named in config('payments.card.driver').
 *
 * With no driver or no credentials it is DISABLED: it is not offered at checkout, cannot be switched on, and the
 * admin screen lists exactly what is missing. It never pretends a payment happened.
 */
class CardGateway implements OnlinePaymentGateway
{
    public function code(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Debit / Credit Card';
    }

    public function description(): string
    {
        return 'Pay securely by card on our payment provider\'s page. We never see or store your card details.';
    }

    public function isAvailable(): bool
    {
        return $this->missing() === [] && $this->driver() !== null;
    }

    /** What still has to be set up (empty when ready). Used by the admin screen and the docs. @return list<string> */
    public function missing(): array
    {
        $missing = [];
        $driver = config('payments.card.driver');
        if (! is_string($driver) || $driver === '' || ! class_exists($driver) || ! is_subclass_of($driver, CardProviderDriver::class)) {
            $missing[] = 'A provider driver (CARD_PAYMENT_DRIVER) that implements App\Services\Payments\CardProviderDriver';
        }
        foreach (['key' => 'CARD_API_KEY', 'secret' => 'CARD_API_SECRET', 'webhook_secret' => 'CARD_WEBHOOK_SECRET'] as $k => $env) {
            if (trim((string) config("payments.card.$k")) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public function driver(): ?CardProviderDriver
    {
        $class = config('payments.card.driver');

        return is_string($class) && $class !== '' && class_exists($class) && is_subclass_of($class, CardProviderDriver::class) ? app($class) : null;
    }

    /** Create the hosted checkout, remember the provider's transaction reference, and return the URL to send the customer to. */
    public function initiate(Order $order): string
    {
        $driver = $this->driver();
        abort_unless($driver && $this->missing() === [], 503);

        $checkout = $driver->createCheckout($order, route('order.confirmation', ['order' => $order, 'token' => $order->access_token]));

        // A pending record with the provider's reference. The order is NOT paid until the verified webhook says so.
        $payment = Payment::updateOrCreate(
            ['provider' => 'card', 'reference' => $checkout->reference],
            ['order_id' => $order->id, 'amount' => $order->total, 'status' => 'pending'],
        );
        PaymentTransaction::record($order, 'card', 'initiated', 'pending', $checkout->reference, (int) $order->total, $payment);
        $order->update(['payment_status' => 'pending']);

        return $checkout->url;
    }

    public function handleWebhook(Request $request): ?VerifiedPayment
    {
        $driver = $this->driver();
        if (! $driver || $this->missing() !== []) {
            return null;                                   // not configured: nothing can be verified
        }

        return $driver->parseWebhook($request);
    }
}
