<?php

namespace App\Services\Payments;

use App\Models\Setting;

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

    /** Customer-facing text; editable in Admin → Payments. */
    public function description(): string
    {
        return (string) (Setting::get('cod.note') ?: 'Pay in cash when your order arrives.');
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
