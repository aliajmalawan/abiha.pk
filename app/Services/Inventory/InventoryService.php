<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Auth\Auth;
use App\Core\Database;

/**
 * The ONLY place stock levels change (port of the old App\Services\Inventory\InventoryService). Every change is made
 * under a row lock and written to the append-only stock_movements ledger in the same transaction, so balance and ledger
 * cannot drift and two concurrent orders cannot oversell.
 *
 * An $item is a products row or a product_variants row (a variant row is recognised by its "product_id" key);
 * adjustProduct()/adjustVariant() take an id instead. $order / $user may be a row (array with 'id'), an id, or null
 * ($user null = the signed-in user, like the old auth()->id()).
 *
 *   InventoryService::adjust($variantRow, -2, 'sale', $order, $user, 'Order ABH-...');   // returns the new balance
 *   InventoryService::setLevel($productRow, 12, $user, 'Stock-take');                    // records the difference
 */
final class InventoryService
{
    public const REASONS = ['sale', 'cancellation', 'restock', 'adjustment', 'return', 'initial'];

    /**
     * Add (+) or remove (-) units. Refuses to go below zero.
     *
     * @throws InsufficientStockException
     */
    public static function adjust(array $item, int $delta, string $reason, array|int|null $order = null, array|int|null $user = null, ?string $note = null): int
    {
        $isVariant = array_key_exists('product_id', $item);
        return self::move($isVariant ? 'variant' : 'product', (int) $item['id'], $delta, $reason, self::idOf($order), $user, $note);
    }

    /** @throws InsufficientStockException */
    public static function adjustProduct(int $productId, int $delta, string $reason, array|int|null $order = null, array|int|null $user = null, ?string $note = null): int
    {
        return self::move('product', $productId, $delta, $reason, self::idOf($order), $user, $note);
    }

    /** @throws InsufficientStockException */
    public static function adjustVariant(int $variantId, int $delta, string $reason, array|int|null $order = null, array|int|null $user = null, ?string $note = null): int
    {
        return self::move('variant', $variantId, $delta, $reason, self::idOf($order), $user, $note);
    }

    /**
     * Set an absolute level (stock-take). Records the difference as an "adjustment" (or "initial" for first stock).
     *
     * @throws InsufficientStockException
     */
    public static function setLevel(array $item, int $level, array|int|null $user = null, ?string $note = null, string $reason = 'adjustment'): int
    {
        if ($level < 0) {
            throw new InsufficientStockException('Stock cannot be negative.');
        }
        $kind = array_key_exists('product_id', $item) ? 'variant' : 'product';
        $table = $kind === 'variant' ? 'product_variants' : 'products';
        return Database::get()->transaction(static function (Database $db) use ($kind, $table, $item, $level, $user, $note, $reason): int {
            $current = $db->value("SELECT stock FROM {$table} WHERE id = ? FOR UPDATE", [(int) $item['id']]);
            if ($current === null) {
                throw new \RuntimeException('Stock item not found.');
            }
            $delta = $level - (int) $current;
            return $delta === 0 ? (int) $current : self::move($kind, (int) $item['id'], $delta, $reason, null, $user, $note);
        });
    }

    private static function move(string $kind, int $id, int $delta, string $reason, ?int $orderId, array|int|null $user, ?string $note): int
    {
        $userId = self::idOf($user) ?? Auth::id();
        $balance = Database::get()->transaction(static function (Database $db) use ($kind, $id, $delta, $reason, $orderId, $userId, $note): int {
            $row = $kind === 'variant'
                ? $db->one('SELECT id, product_id, sku, stock FROM product_variants WHERE id = ? FOR UPDATE', [$id])
                : $db->one('SELECT id, name, stock FROM products WHERE id = ? FOR UPDATE', [$id]);
            if (!$row) {
                throw new \RuntimeException('Stock item not found.');
            }
            $balance = (int) $row['stock'] + $delta;
            if ($balance < 0) {
                throw new InsufficientStockException('Insufficient stock: ' . ($kind === 'variant' ? $row['sku'] : $row['name']) . ' has ' . (int) $row['stock'] . ' available.');
            }
            $now = now();
            $db->exec(
                $kind === 'variant' ? 'UPDATE product_variants SET stock = ?, updated_at = ? WHERE id = ?' : 'UPDATE products SET stock = ?, updated_at = ? WHERE id = ?',
                [$balance, $now, $id]
            );
            $db->insert(
                'INSERT INTO stock_movements (product_id, variant_id, `change`, balance_after, reason, order_id, user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$kind === 'variant' ? (int) $row['product_id'] : $id, $kind === 'variant' ? $id : null, $delta, $balance, $reason, $orderId, $userId, $note === null ? null : mb_substr($note, 0, 255), $now]
            );
            return $balance;
        });
        // The old Product/ProductVariant "saved" hooks refreshed the storefront cache (stock shows on cards and filters).
        \App\Support\CatalogCache::bump();
        return $balance;
    }

    private static function idOf(array|int|null $v): ?int
    {
        if (is_array($v)) {
            return isset($v['id']) ? (int) $v['id'] : null;
        }
        return $v;
    }
}
