<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Settings;

class CashOnDeliveryGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'cod';
    }

    public function label(): string
    {
        return 'Cash on Delivery';
    }

    /** Customer-facing text; editable in Admin -> Payments (setting cod.note). */
    public function description(): string
    {
        return (string) (Settings::get('cod.note') ?: 'Pay in cash when your order arrives.');
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
