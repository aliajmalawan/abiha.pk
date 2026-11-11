<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Cart\CartException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PaymentService
{
    /**
     * Record a PROVIDER-verified payment event (called only from the webhook after the gateway verified
     * signature + provider API). Idempotent, and rejects any amount that differs from the order total.
     */
    public function recordVerified(string $provider, VerifiedPayment $event): bool
    {
        return DB::transaction(function () use ($provider, $event) {
            $order = Order::query()->where('number', $event->orderNumber)->lockForUpdate()->first();
            if (! $order || $order->payment_method !== $provider) {
                Log::warning('Payment webhook for unknown order / provider mismatch', ['provider' => $provider, 'order' => $event->orderNumber]);

                return false;
            }

            if ($event->paid && $event->amount !== (int) $order->total) {
                Log::alert('Payment amount mismatch, order NOT marked paid', ['order' => $order->number, 'expected' => $order->total, 'got' => $event->amount]);

                return false;
            }

            $status = $event->status();
            $payment = Payment::query()->firstOrNew(['provider' => $provider, 'reference' => $event->reference]);
            if ($payment->exists && $payment->status === 'paid') {
                return true;                                   // duplicate webhook, or a late "failed"/"pending" after success: never downgrade
            }
            if ($order->payment_status === 'paid') {
                return true;                                   // already settled by another transaction
            }
            if ($payment->exists && $status === 'pending' && $payment->status === 'failed') {
                return true;                                   // an out-of-order "pending" must not undo a failure
            }

            $payment->fill([
                'order_id' => $order->id, 'amount' => $event->amount, 'payload' => $event->payload,
                'status' => $status, 'verified_at' => $status === 'pending' ? null : now(),
            ])->save();

            $order->update([
                'payment_status' => $status === 'paid' ? 'paid' : ($status === 'pending' ? 'pending' : 'failed'),
                'status' => $status === 'paid' && $order->status === 'pending' ? 'confirmed' : $order->status,
            ]);
            $note = ['paid' => "Payment verified via {$provider}", 'pending' => "Payment pending at {$provider}", 'failed' => "Payment failed via {$provider}"][$status];
            OrderStatusHistory::create(['order_id' => $order->id, 'status' => $order->status, 'note' => $note]);
            PaymentTransaction::record($order, $provider, 'provider_event', $status, $event->reference, $event->amount, $payment, null, $event->payload);
            return true;
        });
    }

    /**
     * Customer tells us they sent a bank transfer: a reference, a screenshot/PDF of the receipt, or both.
     * This does NOT mark the order paid; staff must verify it against the bank statement.
     * The proof is stored on the PRIVATE disk (never web-accessible) under a random name.
     */
    public function submitTransferReference(Order $order, ?string $reference, ?UploadedFile $proof = null): void
    {
        $reference = $reference !== null ? trim($reference) : null;
        if (($reference === null || $reference === '') && ! $proof) {
            throw new CartException('Enter your bank reference or upload a photo of the receipt.');
        }
        if ($order->payment_method !== 'bank_transfer' || $order->payment_status === 'paid') {
            throw new CartException('This order does not need a transfer confirmation.');
        }
        if ($reference && Payment::query()->where('provider', 'bank_transfer')->where('reference', $reference)->where('order_id', '!=', $order->id)->exists()) {
            throw new CartException('That reference was already used for another order. Please check it and try again.');
        }

        $stored = $proof ? $proof->storeAs('payment-proofs/'.$order->id, bin2hex(random_bytes(16)).'.'.$this->proofExtension($proof), 'local') : null;

        try {
            DB::transaction(function () use ($order, $reference, $stored) {
                $payment = $order->payments()->where('provider', 'bank_transfer')->latest('id')->first() ?? new Payment(['order_id' => $order->id, 'provider' => 'bank_transfer', 'amount' => $order->total]);
                $old = $payment->proof_path;
                $payment->fill(array_filter(['reference' => $reference ?: null, 'proof_path' => $stored, 'proof_submitted_at' => $stored ? now() : null], fn ($v) => $v !== null) + ['status' => 'pending'])->save();
                $order->update(['payment_status' => 'awaiting_verification']);
                OrderStatusHistory::create(['order_id' => $order->id, 'status' => $order->status, 'note' => 'Customer reported bank transfer'.($stored ? ' with proof of payment' : '').', awaiting verification']);
                PaymentTransaction::record($order, 'bank_transfer', 'transfer_reported', 'pending', $reference ?: null, (int) $order->total, $payment, null, $stored ? ['proof_uploaded' => true] : null);
                if ($stored && $old) {
                    Storage::disk('local')->delete($old);
                }
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    /** The extension is chosen from the file's real content, never from the name the customer gave it. */
    private function proofExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'jpg',
        };
    }
    /**
     * Staff confirm money was actually received (bank statement checked, or cash collected on delivery).
     * The amount must match the order total exactly. Recorded with who verified it and when.
     *
     * @throws CartException
     */
    public function verifyManually(Order $order, User $staff, ?string $reference = null, ?int $amount = null, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $staff, $reference, $amount, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->payment_method, ['bank_transfer', 'cod'], true)) {
                throw new CartException('Online payments are verified automatically by the provider and cannot be confirmed by hand.');
            }
            if ($order->payment_status === 'paid') {
                throw new CartException('This order is already marked as paid.');
            }
            if ($order->status === 'cancelled') {
                throw new CartException('A cancelled order cannot be marked as paid.');
            }
            if (($amount ?? $order->total) !== (int) $order->total) {
                throw new CartException('The amount received must equal the order total ('.config('abiha.currency.symbol').' '.number_format($order->total).').');
            }

            $payment = $order->payments()->where('provider', $order->payment_method)->latest('id')->first() ?? new Payment(['order_id' => $order->id, 'provider' => $order->payment_method]);
            $payment->fill([
                'reference' => $reference ?: $payment->reference, 'status' => 'paid', 'amount' => $order->total,
                'verified_by' => $staff->id, 'verified_at' => now(),
                'payload' => ['verified_manually' => true, 'note' => $note],
            ])->save();

            $order->update(['payment_status' => 'paid', 'status' => $order->status === 'pending' ? 'confirmed' : $order->status]);
            OrderStatusHistory::create(['order_id' => $order->id, 'status' => $order->status, 'note' => 'Payment verified by staff'.($note ? ": {$note}" : ''), 'user_id' => $staff->id]);
            PaymentTransaction::record($order, $order->payment_method, 'verified', 'paid', $payment->reference, (int) $order->total, $payment, $staff, $note ? ['note' => $note] : null);
            ActivityLogger::log('payment.verified', $order, ['method' => $order->payment_method, 'reference' => $reference]);
        });
    }

    /**
     * Record that a refund was paid back to the customer. This ONLY records it: it never moves money.
     * Refund through your bank, courier or payment provider first, then record it here.
     *
     * @throws CartException
     */
    public function recordRefund(Order $order, User $staff, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $staff, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->payment_status !== 'paid') {
                throw new CartException('Only a paid order can be marked as refunded.');
            }
            if (! in_array($order->status, ['cancelled', 'returned'], true)) {
                throw new CartException('Cancel the order or mark it as returned before recording a refund.');
            }
            $order->payments()->where('status', 'paid')->update(['status' => 'refunded']);
            $order->update(['payment_status' => 'refunded']);
            OrderStatusHistory::create(['order_id' => $order->id, 'status' => $order->status, 'note' => 'Refund recorded by staff'.($note ? ": {$note}" : ''), 'user_id' => $staff->id]);
            PaymentTransaction::record($order, $order->payment_method, 'refund_recorded', 'refunded', null, (int) $order->total, $order->payments()->latest('id')->first(), $staff, $note ? ['note' => $note] : null);
            ActivityLogger::log('payment.refund_recorded', $order, ['amount' => $order->total, 'note' => $note]);
        });
    }

    /** Staff reject a reported transfer (e.g. nothing arrived). */
    public function rejectManually(Order $order, User $staff, ?string $note = null): void
    {
        if ($order->payment_status === 'paid') {
            throw new CartException('A paid order cannot be rejected. Cancel or refund it instead.');
        }
        $order->payments()->where('status', 'pending')->update(['status' => 'failed']);
        $order->update(['payment_status' => 'failed']);
        OrderStatusHistory::create(['order_id' => $order->id, 'status' => $order->status, 'note' => 'Payment not received'.($note ? ": {$note}" : ''), 'user_id' => $staff->id]);
        PaymentTransaction::record($order, $order->payment_method, 'rejected', 'failed', null, (int) $order->total, $order->payments()->latest('id')->first(), $staff, $note ? ['note' => $note] : null);
        ActivityLogger::log('payment.rejected', $order, ['note' => $note]);
    }
}
