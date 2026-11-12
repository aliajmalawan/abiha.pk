<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Settings;

class BankTransferGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return 'Bank Transfer';
    }

    public function description(): string
    {
        return 'Transfer the total to our bank account. Your order is processed once the payment is verified.';
    }

    /** Offered at checkout only once the owner has entered real account details (we never invent bank details). */
    public function isAvailable(): bool
    {
        return trim($this->instructions()) !== '';
    }

    /** Account details shown after ordering (setting bank.details, edited in Admin -> Payments). */
    public function instructions(): string
    {
        return (string) Settings::get('bank.details', '');
    }
}
