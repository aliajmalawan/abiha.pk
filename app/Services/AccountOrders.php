<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Cart\CartException;
use App\Support\CatalogCache;

/**
 * Orders as the customer account sees them (port of the Order model accessors + OrderPolicy used by the old
 * Account\OrderController and partials/order-tracking).
 *
 *   AccountOrders::find('ABH-...')            order row + name/phone/province/city/address/postal_code (shipping snapshot),
 *                                             courier/tracking_number/tracking_url (latest shipment), or null
 *   AccountOrders::withDetails($order)        + 'items' and 'history' (newest first)
 *   AccountOrders::isCancellable($order)      pending/confirmed/processing and not paid (Order::isCancellable)
 *   AccountOrders::cancel($order, $user)      uses App\Services\OrderService::cancel() when the cart & orders slice has it,
 *                                             otherwise the same rules here (restock through the ledger, free the coupon,
 *                                             status history, activity log). Throws CartException with a customer message.
 */
final class AccountOrders
{
    public const STATUSES = [
        'pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing',
        'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid', 'pending' => 'Payment pending', 'awaiting_verification' => 'Awaiting verification',
        'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded',
    ];

    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public static function find(string $number): ?array
    {
        $o = Database::get()->one('SELECT * FROM orders WHERE number = ? LIMIT 1', [$number]);
        return $o ? self::decorate($o) : null;
    }

    /** Adds the shipping snapshot and shipment fields the views read. */
    public static function decorate(array $o): array
    {
        $db = Database::get();
        $a = $db->one("SELECT * FROM order_addresses WHERE order_id = ? AND type = 'shipping' ORDER BY id LIMIT 1", [$o['id']]) ?? [];
        foreach (['name', 'phone', 'province', 'city', 'address', 'postal_code'] as $k) {
            $o[$k] = $a[$k] ?? null;
        }
        $s = $db->one('SELECT * FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o['id']]) ?? [];
        foreach (['courier', 'tracking_number', 'tracking_url'] as $k) {
            $o[$k] = $s[$k] ?? null;
        }
        return $o;
    }

    public static function withDetails(array $o): array
    {
        $db = Database::get();
        $o['items'] = $db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
        $o['history'] = $db->all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC', [$o['id']]);
        return $o;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst($status);
    }

    public static function paymentStatusLabel(string $status): string
    {
        return self::PAYMENT_STATUSES[$status] ?? ucfirst($status);
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'delivered' => 'success', 'cancelled' => 'danger', 'returned' => 'warning', 'shipped' => 'info', 'pending' => 'warning', default => 'ivory',
        };
    }

    public static function isCancellable(array $o): bool
    {
        return in_array('cancelled', self::TRANSITIONS[$o['status']] ?? [], true) && $o['payment_status'] !== 'paid';
    }

    /** Customer self-service cancellation. @throws CartException */
    public static function cancel(array $order, ?array $by = null, ?string $note = null): void
    {
        if (!self::isCancellable($order)) {
            throw new CartException('This order can no longer be cancelled online. Please contact us.');
        }
        if (class_exists(OrderService::class) && method_exists(OrderService::class, 'cancel')) {
            OrderService::cancel($order, $by, $note);
            return;
        }
        $note ??= 'Cancelled by customer';
        $byId = $by['id'] ?? null;
        Database::get()->transaction(static function (Database $db) use ($order, $byId, $note): void {
            $o = $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if (!$o || !in_array('cancelled', self::TRANSITIONS[$o['status']] ?? [], true)) {
                throw new CartException('An order that is ' . ($o['status'] ?? 'missing') . ' cannot be changed to cancelled.');
            }
            // Restock (InventoryService::adjust with reason "cancellation") and give back sold_count.
            foreach ($db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]) as $item) {
                $qty = (int) $item['quantity'];
                $exists = $item['variant_id']
                    ? $db->value('SELECT id FROM product_variants WHERE id = ?', [$item['variant_id']])
                    : ($item['product_id'] ? $db->value('SELECT id FROM products WHERE id = ?', [$item['product_id']]) : null);
                if (!$exists) {
                    continue;
                }
                $text = 'Order ' . $o['number'] . ' cancelled';
                if (class_exists(\App\Services\Inventory\InventoryService::class)) {
                    $item['variant_id']
                        ? \App\Services\Inventory\InventoryService::adjustVariant((int) $item['variant_id'], $qty, 'cancellation', (int) $o['id'], $byId, $text)
                        : \App\Services\Inventory\InventoryService::adjustProduct((int) $item['product_id'], $qty, 'cancellation', (int) $o['id'], $byId, $text);
                } else {
                    self::restockRow($db, $item, $qty, (int) $o['id'], $byId, $text);
                }
                if ($item['product_id']) {
                    $db->exec('UPDATE products SET sold_count = sold_count - ? WHERE id = ? AND sold_count >= ?', [$qty, $item['product_id'], $qty]);
                }
            }
            $db->exec('DELETE FROM coupon_usages WHERE order_id = ?', [$o['id']]);     // frees the redemption
            $now = now();
            $db->exec("UPDATE orders SET status = 'cancelled', updated_at = ? WHERE id = ?", [$now, $o['id']]);
            $db->insert('INSERT INTO order_status_history (order_id, status, note, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$o['id'], 'cancelled', $note, $byId, $now, $now]);
            ActivityLogger::log('order.status_changed', 'Order', (int) $o['id'], ['from' => $o['status'], 'to' => 'cancelled', 'note' => $note]);
        });
        CatalogCache::bump();
    }

    /** Ledger-backed restock used only when the inventory service is not available. */
    private static function restockRow(Database $db, array $item, int $qty, int $orderId, ?int $byId, string $note): void
    {
        $variant = (bool) $item['variant_id'];
        $id = (int) ($variant ? $item['variant_id'] : $item['product_id']);
        $table = $variant ? 'product_variants' : 'products';
        $row = $db->one("SELECT * FROM {$table} WHERE id = ? FOR UPDATE", [$id]);
        $balance = (int) $row['stock'] + $qty;
        $db->exec("UPDATE {$table} SET stock = ?, updated_at = ? WHERE id = ?", [$balance, now(), $id]);
        $db->insert(
            'INSERT INTO stock_movements (product_id, variant_id, `change`, balance_after, reason, order_id, user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$variant ? (int) $row['product_id'] : $id, $variant ? $id : null, $qty, $balance, 'cancellation', $orderId, $byId, $note, now()]
        );
    }
}
