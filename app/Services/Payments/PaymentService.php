<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Uploads;
use App\Services\ActivityLogger;
use App\Services\Cart\CartException;
use App\Services\OrderService;

/**
 * Payment records (port of the old App\Services\Payments\PaymentService + PaymentTransaction::record).
 * An order becomes paid ONLY by recordVerified() (a provider-verified webhook) or verifyManually() (staff confirmed
 * the bank statement / the cash). Nothing here simulates a payment.
 *
 * $order is an orders row (array with at least 'id'); $staff a users row or a user id.
 */
final class PaymentService
{
    /** Receipt uploads: real content type => stored extension (never the customer's file name). */
    public const PROOF_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    public const PROOF_MAX_BYTES = 4096 * 1024;

    /**
     * Record a PROVIDER-verified payment event (called only from the webhook after the gateway verified
     * signature + provider API). Idempotent, and rejects any amount that differs from the order total.
     */
    public static function recordVerified(string $provider, VerifiedPayment $event): bool
    {
        return Database::get()->transaction(static function (Database $db) use ($provider, $event): bool {
            $order = $db->one('SELECT * FROM orders WHERE number = ? FOR UPDATE', [$event->orderNumber]);
            if (!$order || $order['payment_method'] !== $provider) {
                Logger::warning('Payment webhook for unknown order / provider mismatch', ['provider' => $provider, 'order' => $event->orderNumber]);
                return false;
            }
            if ($event->paid && $event->amount !== (int) $order['total']) {
                Logger::error('Payment amount mismatch, order NOT marked paid', ['order' => $order['number'], 'expected' => $order['total'], 'got' => $event->amount]);
                return false;
            }

            $status = $event->status();
            $payment = $db->one('SELECT * FROM payments WHERE provider = ? AND reference = ? LIMIT 1', [$provider, $event->reference]);
            if ($payment && $payment['status'] === 'paid') {
                return true;                                   // duplicate webhook, or a late "failed"/"pending" after success: never downgrade
            }
            if ($order['payment_status'] === 'paid') {
                return true;                                   // already settled by another transaction
            }
            if ($payment && $status === 'pending' && $payment['status'] === 'failed') {
                return true;                                   // an out-of-order "pending" must not undo a failure
            }

            $payload = json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $verifiedAt = $status === 'pending' ? null : now();
            if ($payment) {
                $db->exec('UPDATE payments SET order_id = ?, amount = ?, payload = ?, status = ?, verified_at = ?, updated_at = ? WHERE id = ?',
                    [(int) $order['id'], $event->amount, $payload, $status, $verifiedAt, now(), $payment['id']]);
                $paymentId = (int) $payment['id'];
            } else {
                $paymentId = $db->insert('INSERT INTO payments (order_id, provider, reference, status, amount, payload, verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int) $order['id'], $provider, $event->reference, $status, $event->amount, $payload, $verifiedAt, now(), now()]);
            }

            $newStatus = $status === 'paid' && $order['status'] === 'pending' ? 'confirmed' : $order['status'];
            $db->exec('UPDATE orders SET payment_status = ?, status = ?, updated_at = ? WHERE id = ?',
                [$status === 'paid' ? 'paid' : ($status === 'pending' ? 'pending' : 'failed'), $newStatus, now(), $order['id']]);
            $note = ['paid' => "Payment verified via {$provider}", 'pending' => "Payment pending at {$provider}", 'failed' => "Payment failed via {$provider}"][$status];
            OrderService::addHistory((int) $order['id'], $newStatus, $note);
            self::record($order, $provider, 'provider_event', $status, $event->reference, $event->amount, $paymentId, null, $event->payload);
            return true;
        });
    }

    /**
     * Customer tells us they sent a bank transfer: a reference, a screenshot/PDF of the receipt, or both.
     * This does NOT mark the order paid; staff must verify it against the bank statement.
     * The proof ($_FILES entry) is stored on the PRIVATE disk (never web-accessible) under a random name.
     *
     * @throws CartException
     */
    public static function submitTransferReference(array $order, ?string $reference, ?array $proof = null): void
    {
        $reference = $reference !== null ? trim($reference) : null;
        if (($reference === null || $reference === '') && !$proof) {
            throw new CartException('Enter your bank reference or upload a photo of the receipt.');
        }
        if ($order['payment_method'] !== 'bank_transfer' || $order['payment_status'] === 'paid') {
            throw new CartException('This order does not need a transfer confirmation.');
        }
        $db = Database::get();
        if ($reference && $db->value("SELECT 1 FROM payments WHERE provider = 'bank_transfer' AND reference = ? AND order_id <> ? LIMIT 1", [$reference, (int) $order['id']])) {
            throw new CartException('That reference was already used for another order. Please check it and try again.');
        }

        $stored = $proof ? Uploads::storePrivate($proof, 'payment-proofs/' . (int) $order['id'], self::PROOF_TYPES, self::PROOF_MAX_BYTES, 'proof') : null;

        try {
            $db->transaction(static function (Database $db) use ($order, $reference, $stored): void {
                $payment = $db->one("SELECT * FROM payments WHERE order_id = ? AND provider = 'bank_transfer' ORDER BY id DESC LIMIT 1", [(int) $order['id']]);
                $old = $payment['proof_path'] ?? null;
                if ($payment) {
                    $db->exec("UPDATE payments SET reference = COALESCE(?, reference), proof_path = COALESCE(?, proof_path), proof_submitted_at = COALESCE(?, proof_submitted_at), status = 'pending', updated_at = ? WHERE id = ?",
                        [$reference ?: null, $stored, $stored ? now() : null, now(), $payment['id']]);
                    $paymentId = (int) $payment['id'];
                } else {
                    $paymentId = $db->insert("INSERT INTO payments (order_id, provider, reference, proof_path, proof_submitted_at, status, amount, created_at, updated_at) VALUES (?, 'bank_transfer', ?, ?, ?, 'pending', ?, ?, ?)",
                        [(int) $order['id'], $reference ?: null, $stored, $stored ? now() : null, (int) $order['total'], now(), now()]);
                }
                $db->exec("UPDATE orders SET payment_status = 'awaiting_verification', updated_at = ? WHERE id = ?", [now(), (int) $order['id']]);
                OrderService::addHistory((int) $order['id'], (string) $order['status'], 'Customer reported bank transfer' . ($stored ? ' with proof of payment' : '') . ', awaiting verification');
                self::record($order, 'bank_transfer', 'transfer_reported', 'pending', $reference ?: null, (int) $order['total'], $paymentId, null, $stored ? ['proof_uploaded' => true] : null);
                if ($stored && $old) {
                    Uploads::deletePrivate($old);
                }
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Uploads::deletePrivate($stored);
            }
            throw $e;
        }
    }

    /**
     * Staff confirm money was actually received (bank statement checked, or cash collected on delivery).
     * The amount must match the order total exactly. Recorded with who verified it and when.
     *
     * @throws CartException
     */
    public static function verifyManually(array|int $order, array|int $staff, ?string $reference = null, ?int $amount = null, ?string $note = null): void
    {
        $orderId = is_array($order) ? (int) $order['id'] : $order;
        $staffId = is_array($staff) ? (int) $staff['id'] : $staff;
        Database::get()->transaction(static function (Database $db) use ($orderId, $staffId, $reference, $amount, $note): void {
            $order = $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                throw new CartException('Order not found.');
            }
            if (!in_array($order['payment_method'], ['bank_transfer', 'cod'], true)) {
                throw new CartException('Online payments are verified automatically by the provider and cannot be confirmed by hand.');
            }
            if ($order['payment_status'] === 'paid') {
                throw new CartException('This order is already marked as paid.');
            }
            if ($order['status'] === 'cancelled') {
                throw new CartException('A cancelled order cannot be marked as paid.');
            }
            if (($amount ?? (int) $order['total']) !== (int) $order['total']) {
                throw new CartException('The amount received must equal the order total (' . Money::symbol() . ' ' . number_format((int) $order['total']) . ').');
            }

            $payment = $db->one('SELECT * FROM payments WHERE order_id = ? AND provider = ? ORDER BY id DESC LIMIT 1', [$orderId, $order['payment_method']]);
            $payload = json_encode(['verified_manually' => true, 'note' => $note], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ref = $reference ?: ($payment['reference'] ?? null);
            if ($payment) {
                $db->exec("UPDATE payments SET reference = ?, status = 'paid', amount = ?, verified_by = ?, verified_at = ?, payload = ?, updated_at = ? WHERE id = ?",
                    [$ref, (int) $order['total'], $staffId, now(), $payload, now(), $payment['id']]);
                $paymentId = (int) $payment['id'];
            } else {
                $paymentId = $db->insert("INSERT INTO payments (order_id, provider, reference, status, amount, verified_by, verified_at, payload, created_at, updated_at) VALUES (?, ?, ?, 'paid', ?, ?, ?, ?, ?, ?)",
                    [$orderId, $order['payment_method'], $ref, (int) $order['total'], $staffId, now(), $payload, now(), now()]);
            }
            $newStatus = $order['status'] === 'pending' ? 'confirmed' : $order['status'];
            $db->exec("UPDATE orders SET payment_status = 'paid', status = ?, updated_at = ? WHERE id = ?", [$newStatus, now(), $orderId]);
            OrderService::addHistory($orderId, $newStatus, 'Payment verified by staff' . ($note ? ": {$note}" : ''), $staffId);
            self::record($order, $order['payment_method'], 'verified', 'paid', $ref, (int) $order['total'], $paymentId, $staffId, $note ? ['note' => $note] : null);
            ActivityLogger::log('payment.verified', 'Order', $orderId, ['method' => $order['payment_method'], 'reference' => $reference]);
        });
    }

    /**
     * Record that a refund was paid back to the customer. This ONLY records it: it never moves money.
     *
     * @throws CartException
     */
    public static function recordRefund(array|int $order, array|int $staff, ?string $note = null): void
    {
        $orderId = is_array($order) ? (int) $order['id'] : $order;
        $staffId = is_array($staff) ? (int) $staff['id'] : $staff;
        Database::get()->transaction(static function (Database $db) use ($orderId, $staffId, $note): void {
            $order = $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order || $order['payment_status'] !== 'paid') {
                throw new CartException('Only a paid order can be marked as refunded.');
            }
            if (!in_array($order['status'], ['cancelled', 'returned'], true)) {
                throw new CartException('Cancel the order or mark it as returned before recording a refund.');
            }
            $db->exec("UPDATE payments SET status = 'refunded', updated_at = ? WHERE order_id = ? AND status = 'paid'", [now(), $orderId]);
            $db->exec("UPDATE orders SET payment_status = 'refunded', updated_at = ? WHERE id = ?", [now(), $orderId]);
            OrderService::addHistory($orderId, $order['status'], 'Refund recorded by staff' . ($note ? ": {$note}" : ''), $staffId);
            $last = $db->value('SELECT id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
            self::record($order, $order['payment_method'], 'refund_recorded', 'refunded', null, (int) $order['total'], $last ? (int) $last : null, $staffId, $note ? ['note' => $note] : null);
            ActivityLogger::log('payment.refund_recorded', 'Order', $orderId, ['amount' => (int) $order['total'], 'note' => $note]);
        });
    }

    /** Staff reject a reported transfer (e.g. nothing arrived). @throws CartException */
    public static function rejectManually(array|int $order, array|int $staff, ?string $note = null): void
    {
        $orderId = is_array($order) ? (int) $order['id'] : $order;
        $staffId = is_array($staff) ? (int) $staff['id'] : $staff;
        $db = Database::get();
        $order = $db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order || $order['payment_status'] === 'paid') {
            throw new CartException('A paid order cannot be rejected. Cancel or refund it instead.');
        }
        $db->exec("UPDATE payments SET status = 'failed', updated_at = ? WHERE order_id = ? AND status = 'pending'", [now(), $orderId]);
        $db->exec("UPDATE orders SET payment_status = 'failed', updated_at = ? WHERE id = ?", [now(), $orderId]);
        OrderService::addHistory($orderId, $order['status'], 'Payment not received' . ($note ? ": {$note}" : ''), $staffId);
        $last = $db->value('SELECT id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        self::record($order, $order['payment_method'], 'rejected', 'failed', null, (int) $order['total'], $last ? (int) $last : null, $staffId, $note ? ['note' => $note] : null);
        ActivityLogger::log('payment.rejected', 'Order', $orderId, ['note' => $note]);
    }

    /**
     * One line in an order's payment history (payment_transactions, append-only). Old PaymentTransaction::record().
     * The payload must never hold card data.
     */
    public static function record(array $order, string $provider, string $type, string $status, ?string $reference = null, ?int $amount = null, ?int $paymentId = null, array|int|null $by = null, ?array $payload = null): int
    {
        $byId = is_array($by) ? (int) $by['id'] : $by;
        return Database::get()->insert(
            'INSERT INTO payment_transactions (order_id, payment_id, provider, type, status, reference, amount, payload, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $order['id'], $paymentId, $provider, $type, $status, $reference, $amount ?? (int) $order['total'],
                $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $byId, now()]
        );
    }
}
