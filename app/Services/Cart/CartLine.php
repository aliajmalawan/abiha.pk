<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductVariant;

/** One resolved cart line: live prices and stock from the database, never from the browser. */
final class CartLine
{
    public function __construct(
        public readonly string $key,
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $quantity,
        public readonly int $unitPrice,
        public readonly ?int $comparePrice,
        public readonly int $stock,
        public readonly ?string $notice = null,
    ) {
    }

    public function total(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function sku(): string
    {
        return $this->variant?->sku ?? $this->product->sku;
    }

    public function options(): ?string
    {
        return $this->variant?->label ?: null;
    }
}
