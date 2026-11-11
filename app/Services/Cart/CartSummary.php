<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\ShippingMethod;
use Illuminate\Support\Collection;

final class CartSummary
{
    /** @param Collection<int, CartLine> $lines */
    public function __construct(
        public readonly Collection $lines,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $shipping,
        public readonly int $total,
        public readonly ?Coupon $coupon,
        public readonly ?ShippingMethod $shippingMethod,
        public readonly ?string $couponError = null,
        public readonly bool $shippingAvailable = true,   // false: the method is not offered to the destination
    ) {
    }

    public function count(): int
    {
        return (int) $this->lines->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    /** Lines whose quantity was reduced or that need attention. */
    public function notices(): array
    {
        return $this->lines->filter(fn (CartLine $l) => $l->notice)->map(fn (CartLine $l) => $l->product->name.': '.$l->notice)->values()->all();
    }
}
