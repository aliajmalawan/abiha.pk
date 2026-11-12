<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Result of a provider-confirmed payment event (already signature- and API-verified).
 * Three outcomes: paid, failed, or still pending (e.g. the provider is waiting for the bank/3-D Secure).
 */
final class VerifiedPayment
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $reference,   // the provider's transaction id
        public readonly int $amount,         // whole PKR as confirmed by the provider
        public readonly bool $paid,          // false = not paid (failed, unless $pending)
        public readonly array $payload = [], // keep free of card data: never put a card number or CVV here
        public readonly bool $pending = false,
    ) {
    }

    public function status(): string
    {
        return $this->paid ? 'paid' : ($this->pending ? 'pending' : 'failed');
    }
}
