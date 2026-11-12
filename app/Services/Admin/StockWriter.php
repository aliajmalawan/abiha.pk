<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Stock level writes for the admin catalogue (product form, Inventory page), with the same rules as the old
 * App\Services\Inventory\InventoryService::adjust()/setLevel(): every change is made under a row lock and written to
 * the append-only stock_movements ledger in the same transaction, so balance and ledger cannot drift.
 *
 *   StockWriter::adjust('product', $id, +10, 'restock', note: 'PO 12');     // returns the new balance
 *   StockWriter::setLevel('variant', $id, 4, note: 'Edited in product form');   // records the difference (none = no row)
 *
 * Throws InsufficientStockException ("Insufficient stock: X has N available." / "Stock cannot be negative.").
 */
final class StockWriter
{
    public const REASONS = ['sale', 'cancellation', 'restock', 'adjustment', 'return', 'initial'];

    /** Add (+) or remove (-) units. Refuses to go below zero. */
    public static function adjust(string $kind, int $id, int $delta, string $reason, ?int $orderId = null, ?int $userId = null, ?string $note = null): int
    {
        $db = Database::get();
        return $db->transaction(static function (Database $db) use ($kind, $id, $delta, $reason, $orderId, $userId, $note): int {
            $row = self::lock($db, $kind, $id);
            $balance = (int) $row['stock'] + $delta;
            if ($balance < 0) {
                throw new InsufficientStockException('Insufficient stock: ' . ($kind === 'variant' ? $row['sku'] : $row['name']) . ' has ' . (int) $row['stock'] . ' available.');
            }
            $table = $kind === 'variant' ? 'product_variants' : 'products';
            $db->exec("UPDATE `{$table}` SET stock = ?, updated_at = ? WHERE id = ?", [$balance, now(), $id]);
            $db->insert(
                'INSERT INTO stock_movements (product_id, variant_id, `change`, balance_after, reason, order_id, user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $kind === 'variant' ? (int) $row['product_id'] : $id,
                    $kind === 'variant' ? $id : null,
                    $delta,
                    $balance,
                    $reason,
                    $orderId,
                    $userId ?? Auth::id(),
                    $note,
                    now(),
                ]
            );
            return $balance;
        });
    }

    /** Set an absolute level (stock-take). Records the difference as an "adjustment" (or "initial" for first stock). */
    public static function setLevel(string $kind, int $id, int $level, ?int $userId = null, ?string $note = null, string $reason = 'adjustment'): int
    {
        if ($level < 0) {
            throw new InsufficientStockException('Stock cannot be negative.');
        }
        $db = Database::get();
        return $db->transaction(static function (Database $db) use ($kind, $id, $level, $userId, $note, $reason): int {
            $current = (int) self::lock($db, $kind, $id)['stock'];
            $delta = $level - $current;
            return $delta === 0 ? $current : self::adjust($kind, $id, $delta, $reason, null, $userId, $note);
        });
    }

    /** @return array<string,mixed> the locked row (products: soft-deleted rows are not found, like findOrFail) */
    private static function lock(Database $db, string $kind, int $id): array
    {
        $row = $kind === 'variant'
            ? $db->one('SELECT id, product_id, sku, stock FROM product_variants WHERE id = ? FOR UPDATE', [$id])
            : $db->one('SELECT id, name, stock FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw new HttpException(404);
        }
        return $row;
    }
}
