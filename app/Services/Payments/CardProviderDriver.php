<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Request;

/**
 * What a card provider (any hosted-checkout gateway) must supply. The shop itself never sees, stores or
 * transmits a card number or CVV: the customer enters card details on the PROVIDER's hosted page.
 *
 * A driver is a small class written against the provider's own documentation, once the business has a merchant
 * account. None ships with the app, because merchant credentials and provider terms are the owner's to supply.
 * Name it in .env (CARD_PAYMENT_DRIVER=App\Services\Payments\Drivers\YourProvider) with CARD_API_KEY,
 * CARD_API_SECRET and CARD_WEBHOOK_SECRET; read them with cfg('payments.card.key') etc.
 */
interface CardProviderDriver
{
    /** Short provider name for the admin screen, e.g. the gateway's brand. */
    public function name(): string;

    /**
     * Create a hosted checkout session for this order (an orders row) and return where to send the customer plus the
     * provider's transaction/session id. The AMOUNT must come from $order['total'] (server side), never from the browser.
     *
     * @param string $returnUrl where the provider sends the customer back (the order confirmation page)
     */
    public function createCheckout(array $order, string $returnUrl): CardCheckout;

    /**
     * Handle the provider's server-to-server notification. MUST verify the signature with the webhook secret
     * (see WebhookSignature, over $request->body()) AND confirm the transaction with the provider's API before returning
     * an event. Return null for anything unverifiable. Never trust the customer's browser redirect.
     */
    public function parseWebhook(Request $request): ?VerifiedPayment;
}
