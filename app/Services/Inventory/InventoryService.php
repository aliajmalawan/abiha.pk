<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place stock levels change. Every change is made under a row lock and written to the
 * append-only stock_movements ledger in the same transaction, so balance and ledger cannot drift
 * and two concurrent orders cannot oversell.
 */
class InventoryService
{
    public const REASONS = ['sale', 'cancellation', 'restock', 'adjustment', 'return', 'initial'];

    /**
     * Add (+) or remove (−) units. Refuses to go below zero.
     *
     * @throws InsufficientStockException
     */
    public function adjust(Product|ProductVariant $item, int $delta, string $reason, ?Order $order = null, ?User $user = null, ?string $note = null): int
    {
        return DB::transaction(function () use ($item, $delta, $reason, $order, $user, $note) {
            $row = $item::query()->lockForUpdate()->findOrFail($item->getKey());
            $balance = (int) $row->stock + $delta;

            if ($balance < 0) {
                throw new InsufficientStockException('Insufficient stock: '.($row instanceof ProductVariant ? $row->sku : $row->name).' has '.(int) $row->stock.' available.');
            }

            $row->update(['stock' => $balance]);
            $item->stock = $balance;                     // keep the caller's instance in sync

            InventoryMovement::create([
                'product_id' => $row instanceof ProductVariant ? $row->product_id : $row->id,
                'variant_id' => $row instanceof ProductVariant ? $row->id : null,
                'change' => $delta,
                'balance_after' => $balance,
                'reason' => $reason,
                'order_id' => $order?->id,
                'user_id' => $user?->id ?? auth()->id(),
                'note' => $note,
            ]);

            return $balance;
        });
    }

    /** Set an absolute level (stock-take). Records the difference as an "adjustment" (or "initial" for first stock). */
    public function setLevel(Product|ProductVariant $item, int $level, ?User $user = null, ?string $note = null, string $reason = 'adjustment'): int
    {
        if ($level < 0) {
            throw new InsufficientStockException('Stock cannot be negative.');
        }

        return DB::transaction(function () use ($item, $level, $user, $note, $reason) {
            $current = (int) $item::query()->lockForUpdate()->findOrFail($item->getKey())->stock;
            $delta = $level - $current;

            return $delta === 0 ? $current : $this->adjust($item, $delta, $reason, null, $user, $note);
        });
    }
}
