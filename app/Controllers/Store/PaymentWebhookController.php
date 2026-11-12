<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrderMailer;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentService;

/**
 * POST /webhooks/payments/{provider} (CSRF-exempt, throttle:webhooks).
 * Only providers that implement OnlinePaymentGateway are served; anything else is a 404.
 * The gateway itself verifies the signature (see WebhookSignature) and calls the provider API.
 * Responses are deliberately terse: they never say WHY a request was refused.
 */
final class PaymentWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function handle(Request $request): Response
    {
        $provider = (string) ($request->params['provider'] ?? '');
        $gateway = PaymentManager::online($provider);
        if (!$gateway) {
            throw new HttpException(404);
        }
        // Real provider notifications are small. Refuse big bodies before doing any work.
        if (strlen($request->body()) > self::MAX_BODY_BYTES) {
            return Response::text('Payload too large', 413);
        }

        $event = $gateway->handleWebhook($request);   // null = signature/API verification failed
        if (!$event) {
            // Log that it happened (for investigation) but never the body or headers: they may hold secrets.
            Logger::warning('Rejected payment webhook', ['provider' => $provider, 'ip' => $request->ip()]);
            return Response::text('Invalid', 400);
        }

        $db = Database::get();
        $before = $db->value('SELECT payment_status FROM orders WHERE number = ?', [$event->orderNumber]);
        $ok = PaymentService::recordVerified($provider, $event);
        if ($ok && $event->paid && $before !== 'paid') {                // only the first successful notice sends the email
            $order = $db->one('SELECT * FROM orders WHERE number = ?', [$event->orderNumber]);
            if ($order && $order['payment_status'] === 'paid') {
                OrderMailer::paid($order);
            }
        }
        return $ok ? Response::text('OK', 200) : Response::text('Rejected', 422);
    }
}
