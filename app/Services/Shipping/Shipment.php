<?php

namespace App\Services\Shipping;

/** What a courier returned when a shipment was booked. */
final class Shipment
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $reference = null,      // the courier's booking id
        public readonly ?string $labelUrl = null,
    ) {
    }
}
