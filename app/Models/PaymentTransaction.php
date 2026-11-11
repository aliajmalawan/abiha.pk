<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in an order's payment history. Append-only: rows are written, never edited. */
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write a history line. The payload must never hold card data. */
    public static function record(Order $order, string $provider, string $type, string $status, ?string $reference = null, ?int $amount = null, ?Payment $payment = null, ?User $by = null, ?array $payload = null): self
    {
        return static::create([
            'order_id' => $order->id, 'payment_id' => $payment?->id, 'provider' => $provider, 'type' => $type, 'status' => $status,
            'reference' => $reference, 'amount' => $amount ?? (int) $order->total, 'user_id' => $by?->id, 'payload' => $payload,
        ]);
    }
}
