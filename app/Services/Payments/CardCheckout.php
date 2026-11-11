<?php

namespace App\Services\Payments;

/** A created hosted-checkout session: where to send the customer, and the provider's transaction reference. */
final class CardCheckout
{
    public function __construct(public readonly string $url, public readonly string $reference)
    {
    }
}
