<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/payments/{provider}
 * Only providers that implement OnlinePaymentGateway are served; anything else is a 404.
 * The gateway itself verifies the signature (see WebhookSignature) and calls the provider API.
 * Responses are deliberately terse: they never say WHY a request was refused.
 */
class PaymentWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, string $provider, PaymentManager $payments, PaymentService $service): Response
    {
        $gateway = $payments->online($provider);
        abort_unless($gateway, 404);

        // Real provider notifications are small. Refuse big bodies before doing any work.
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response('Payload too large', 413);
        }

        $event = $gateway->handleWebhook($request);   // null = signature/API verification failed
        if (! $event) {
            // Log that it happened (for investigation) but never the body or headers: they may hold secrets.
            Log::warning('Rejected payment webhook', ['provider' => $provider, 'ip' => $request->ip()]);

            return response('Invalid', 400);
        }

        $before = \App\Models\Order::query()->where('number', $event->orderNumber)->value('payment_status');
        $ok = $service->recordVerified($provider, $event);
        if ($ok && $event->paid && $before !== 'paid') {                // only the first successful notice sends the email, not repeats
            $order = \App\Models\Order::query()->where('number', $event->orderNumber)->first();
            if ($order && $order->payment_status === 'paid') {
                \App\Services\OrderMailer::paid($order);
            }
        }

        return $ok ? response('OK', 200) : response('Rejected', 422);
    }
}
