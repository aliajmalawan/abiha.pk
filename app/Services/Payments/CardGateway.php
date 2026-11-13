<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Debit/credit card payments through a hosted provider checkout. Provider independent: it works with whatever
 * CardProviderDriver is named in cfg('payments.card.driver') (.env CARD_PAYMENT_DRIVER).
 *
 * With no driver or no credentials it is DISABLED: it is not offered at checkout, cannot be switched on, and the
 * admin screen lists exactly what is missing (missing()). It never pretends a payment happened.
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
        $driver = Config::get('payments.card.driver');
        if (!is_string($driver) || $driver === '' || !class_exists($driver) || !is_subclass_of($driver, CardProviderDriver::class)) {
            $missing[] = 'A provider driver (CARD_PAYMENT_DRIVER) that implements App\Services\Payments\CardProviderDriver';
        }
        foreach (['key' => 'CARD_API_KEY', 'secret' => 'CARD_API_SECRET', 'webhook_secret' => 'CARD_WEBHOOK_SECRET'] as $k => $env) {
            if (trim((string) Config::get("payments.card.$k")) === '') {
                $missing[] = $env;
            }
        }
        return $missing;
    }

    public function driver(): ?CardProviderDriver
    {
        $class = Config::get('payments.card.driver');
        return is_string($class) && $class !== '' && class_exists($class) && is_subclass_of($class, CardProviderDriver::class) ? new $class() : null;
    }

    /** Create the hosted checkout, remember the provider's transaction reference, and return the URL to send the customer to. */
    public function initiate(array $order): string
    {
        $driver = $this->driver();
        if (!$driver || $this->missing() !== []) {
            throw new HttpException(503);
        }
        $checkout = $driver->createCheckout($order, route('order.confirmation', ['number' => $order['number'], 'token' => $order['access_token']]));

        // A pending record with the provider's reference. The order is NOT paid until the verified webhook says so.
        $db = Database::get();
        $existing = $db->one("SELECT id FROM payments WHERE provider = 'card' AND reference = ?", [$checkout->reference]);
        // The order was created with an empty pending payment row: use it instead of leaving it behind.
        $existing ??= $db->one("SELECT id FROM payments WHERE order_id = ? AND provider = 'card' AND reference IS NULL AND status = 'pending' ORDER BY id LIMIT 1", [(int) $order['id']]);
        if ($existing) {
            $db->exec("UPDATE payments SET order_id = ?, reference = ?, amount = ?, status = 'pending', updated_at = ? WHERE id = ?", [(int) $order['id'], $checkout->reference, (int) $order['total'], now(), $existing['id']]);
            $paymentId = (int) $existing['id'];
        } else {
            $paymentId = $db->insert("INSERT INTO payments (order_id, provider, reference, status, amount, created_at, updated_at) VALUES (?, 'card', ?, 'pending', ?, ?, ?)",
                [(int) $order['id'], $checkout->reference, (int) $order['total'], now(), now()]);
        }
        PaymentService::record($order, 'card', 'initiated', 'pending', $checkout->reference, (int) $order['total'], $paymentId);
        $db->exec("UPDATE orders SET payment_status = 'pending', updated_at = ? WHERE id = ?", [now(), (int) $order['id']]);

        return $checkout->url;
    }

    public function handleWebhook(Request $request): ?VerifiedPayment
    {
        $driver = $this->driver();
        if (!$driver || $this->missing() !== []) {
            return null;                                   // not configured: nothing can be verified
        }
        return $driver->parseWebhook($request);
    }
}
