<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Contract for real online providers. Implementations MUST:
 *  - build the provider checkout and return the URL to redirect the customer to;
 *  - in handleWebhook(): verify the provider's signature/secret AND confirm the transaction
 *    with the provider's server-to-server API before returning a VerifiedPayment.
 *    Return null for anything unverifiable. Never trust the browser redirect alone.
 */
interface OnlinePaymentGateway extends PaymentGateway
{
    public function initiate(Order $order): string;

    public function handleWebhook(Request $request): ?VerifiedPayment;
}
