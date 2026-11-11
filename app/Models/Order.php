<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUSES = [
        'pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing',
        'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid', 'pending' => 'Payment pending', 'awaiting_verification' => 'Awaiting verification',
        'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded',
    ];

    /** Allowed status moves (anything else is rejected by OrderService). Cancelled/returned are final; delivered can only be returned. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    protected $guarded = [];

    protected $with = ['shippingAddress'];

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();   // history keeps resolving after a customer is archived
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    /** The parcel for this order (courier, tracking number and link live there, with its timeline). */
    public function shipment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function getTrackingNumberAttribute(): ?string
    {
        return $this->shipment?->tracking_number;
    }

    public function getTrackingUrlAttribute(): ?string
    {
        return $this->shipment?->tracking_url;
    }

    public function getCourierAttribute(): ?string
    {
        return $this->shipment?->courier;
    }

    public function getCourierReferenceAttribute(): ?string
    {
        return $this->shipment?->courier_reference;
    }

    /** Create or update this order's shipment. */
    public function saveShipment(array $attributes): Shipment
    {
        $shipment = $this->shipment ?? new Shipment(['order_id' => $this->id]);
        $shipment->fill($attributes)->save();
        $this->setRelation('shipment', $shipment);

        return $shipment;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /* Convenience accessors over the shipping snapshot (views read $order->name, ->city …) */
    public function getNameAttribute(): ?string { return $this->shippingAddress?->name; }
    public function getPhoneAttribute(): ?string { return $this->shippingAddress?->phone; }
    public function getProvinceAttribute(): ?string { return $this->shippingAddress?->province; }
    public function getCityAttribute(): ?string { return $this->shippingAddress?->city; }
    public function getAddressAttribute(): ?string { return $this->shippingAddress?->address; }
    public function getPostalCodeAttribute(): ?string { return $this->shippingAddress?->postal_code; }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? ucfirst($this->payment_status);
    }

    public function getStatusToneAttribute(): string
    {
        return match ($this->status) {
            'delivered' => 'success', 'cancelled' => 'danger', 'returned' => 'warning', 'shipped' => 'info', 'pending' => 'warning', default => 'ivory',
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo('cancelled') && $this->payment_status !== 'paid';
    }
}
