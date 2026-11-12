<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Cart\CartException;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\InventoryService;
use App\Support\Settings;

/**
 * Orders (port of the old App\Services\OrderService + the Order model's constants and helpers).
 * An order is an orders row; load() adds the shipping snapshot (name, phone, province, city, address, postal_code,
 * like the old accessors), 'items', 'history' (newest first) and 'shipment' (latest, with tracking fields) or null.
 * $user / $by are users rows, user ids or null.
 */
final class OrderService
{
    public const STATUSES = [
        'pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing',
        'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid', 'pending' => 'Payment pending', 'awaiting_verification' => 'Awaiting verification',
        'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded',
    ];

    /** Allowed status moves. Cancelled/returned are final; delivered can only be returned. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    /* ---------------- reads ---------------- */

    public static function find(int $id): ?array
    {
        $o = Database::get()->one('SELECT * FROM orders WHERE id = ?', [$id]);
        return $o ? self::load($o) : null;
    }

    public static function findByNumber(string $number): ?array
    {
        $o = Database::get()->one('SELECT * FROM orders WHERE number = ?', [$number]);
        return $o ? self::load($o) : null;
    }

    /** Attaches the address snapshot, items, history and shipment to an orders row. */
    public static function load(array $order): array
    {
        $db = Database::get();
        $id = (int) $order['id'];
        $addr = $db->one("SELECT * FROM order_addresses WHERE order_id = ? AND type = 'shipping' LIMIT 1", [$id]);
        foreach (['name', 'phone', 'province', 'city', 'address', 'postal_code'] as $k) {
            $order[$k] = $addr[$k] ?? null;
        }
        $order['items'] = $db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
        $order['history'] = $db->all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC', [$id]);
        $order['shipment'] = $db->one('SELECT * FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        $order['tracking_number'] = $order['shipment']['tracking_number'] ?? null;
        $order['tracking_url'] = $order['shipment']['tracking_url'] ?? null;
        $order['courier'] = $order['shipment']['courier'] ?? null;
        return $order;
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

    public static function canTransitionTo(array $order, string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$order['status']] ?? [], true);
    }

    public static function isCancellable(array $order): bool
    {
        return self::canTransitionTo($order, 'cancelled') && $order['payment_status'] !== 'paid';
    }

    /* ---------------- placing ---------------- */

    /**
     * Turn the current cart into an order, atomically. Money is computed ONLY from the database.
     *
     * @param array{name:string,phone:string,email:string,province:string,city:string,address:string,postal_code?:?string,notes?:?string} $customer
     * @param array $shipping a ShippingService method row
     * @throws CartException
     */
    public static function place(array $customer, array $shipping, string $paymentMethod, array|int|null $user = null, ?string $ip = null): array
    {
        $userId = is_array($user) ? (int) $user['id'] : $user;
        try {
            $orderId = Database::get()->transaction(static function (Database $db) use ($customer, $shipping, $paymentMethod, $userId, $ip): int {
                $summary = Cart::summary($shipping, $customer['email'], ['province' => $customer['province'], 'city' => $customer['city']]);
                if ($summary['empty']) {
                    throw new CartException('Your bag is empty.');
                }
                if (!$summary['shipping_available']) {
                    throw new CartException($shipping['name'] . ' is not available to ' . $customer['city'] . '. Please choose another delivery method.');
                }
                if ($summary['notices']) {
                    throw new CartException('Some items in your bag changed. Please review your bag and try again.');
                }

                // Coupon: lock the row so limited coupons cannot be over-redeemed by concurrent checkouts.
                $coupon = null;
                if ($summary['coupon']) {
                    $coupon = $db->one('SELECT * FROM coupons WHERE id = ? FOR UPDATE', [(int) $summary['coupon']['id']]);
                    CouponService::check($coupon, $summary['subtotal'], $userId, $customer['email'], $summary['lines']);
                }

                $tax = self::taxFor($summary['subtotal'] - $summary['discount']);
                $now = now();
                $number = self::makeNumber();
                $orderId = $db->insert(
                    'INSERT INTO orders (number, user_id, email, status, payment_status, payment_method, shipping_method_id, shipping_method_name, shipping_eta,
                        subtotal, discount, shipping_cost, total, tax_amount, tax_rate, tax_label, coupon_id, coupon_code, notes, access_token, ip_address, created_at, updated_at)
                     VALUES (?, ?, ?, \'pending\', \'unpaid\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$number, $userId, mb_strtolower($customer['email']), $paymentMethod, (int) $shipping['id'], $shipping['name'],
                        ShippingService::etaFor($shipping, $customer['province'], $customer['city']),
                        $summary['subtotal'], $summary['discount'], $summary['shipping'], $summary['total'],
                        $tax['tax_amount'], $tax['tax_rate'], $tax['tax_label'],
                        $coupon ? (int) $coupon['id'] : null, $coupon['code'] ?? null, ($customer['notes'] ?? null) ?: null,
                        self::randomToken(40), $ip, $now, $now]
                );
                $order = ['id' => $orderId, 'number' => $number, 'total' => $summary['total']];

                $db->insert("INSERT INTO order_addresses (order_id, type, name, phone, province, city, address, postal_code, created_at, updated_at) VALUES (?, 'shipping', ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$orderId, $customer['name'], $customer['phone'], $customer['province'], $customer['city'], $customer['address'], ($customer['postal_code'] ?? null) ?: null, $now, $now]);

                // Stable lock order (by key) prevents deadlocks between concurrent orders.
                $lines = $summary['lines'];
                usort($lines, static fn ($a, $b) => strcmp($a['key'], $b['key']));
                foreach ($lines as $line) {
                    $line['variant']
                        ? InventoryService::adjustVariant((int) $line['variant']['id'], -$line['quantity'], 'sale', $orderId, $userId, 'Order ' . $number)
                        : InventoryService::adjustProduct((int) $line['product']['id'], -$line['quantity'], 'sale', $orderId, $userId, 'Order ' . $number);
                    $db->exec('UPDATE products SET sold_count = sold_count + ? WHERE id = ?', [$line['quantity'], (int) $line['product']['id']]);
                    $db->insert('INSERT INTO order_items (order_id, product_id, variant_id, name, sku, options, image, unit_price, quantity, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$orderId, (int) $line['product']['id'], $line['variant'] ? (int) $line['variant']['id'] : null, $line['product']['name'], $line['sku'], $line['options'],
                            $line['product']['images'][0]['path'] ?? null, $line['unit_price'], $line['quantity'], $line['total'], $now, $now]);
                }

                if ($coupon) {
                    $db->insert('INSERT INTO coupon_usages (coupon_id, order_id, user_id, email, discount_amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [(int) $coupon['id'], $orderId, $userId, mb_strtolower($customer['email']), $summary['discount'], $now, $now]);
                }

                $paymentId = $db->insert("INSERT INTO payments (order_id, provider, status, amount, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?, ?)",
                    [$orderId, $paymentMethod, $summary['total'], $now, $now]);
                Payments\PaymentService::record($order, $paymentMethod, 'initiated', 'pending', null, $summary['total'], $paymentId);
                self::addHistory($orderId, 'pending', 'Order placed', $userId);

                Cart::clear();
                return $orderId;
            });
        } catch (InsufficientStockException) {
            throw new CartException('Sorry, an item in your bag just sold out or has limited stock. Please review your bag.');
        }
        return self::find($orderId);
    }

    /* ---------------- status ---------------- */

    /**
     * Move an order to a new status, enforcing the allowed transitions. Cancelling returns stock to inventory and
     * releases the coupon redemption; 'returned' with $restock puts the goods back on the shelf.
     *
     * @throws CartException
     */
    public static function changeStatus(array|int $order, string $status, array|int|null $by = null, ?string $note = null, bool $restock = false): array
    {
        $orderId = is_array($order) ? (int) $order['id'] : $order;
        $byId = is_array($by) ? (int) $by['id'] : $by;
        Database::get()->transaction(static function (Database $db) use ($orderId, $status, $byId, $note, $restock): void {
            $order = $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                throw new CartException('Order not found.');
            }
            if (!self::canTransitionTo($order, $status)) {
                throw new CartException("An order that is {$order['status']} cannot be changed to {$status}.");
            }
            if ($status === 'cancelled') {
                self::restock($order, $byId);
            } elseif ($status === 'returned' && $restock) {
                self::restock($order, $byId, 'return', 'returned', false);
            }
            $db->exec('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?', [$status, now(), $orderId]);
            self::addHistory($orderId, $status, $note, $byId);
            self::recordShipmentStatus($orderId, $status, $byId);
            ActivityLogger::log('order.status_changed', 'Order', $orderId, ['from' => $order['status'], 'to' => $status, 'note' => $note]);
        });
        return self::find($orderId);
    }

    /** Customer self-service cancellation (unpaid, unshipped orders only). @throws CartException */
    public static function cancel(array|int $order, array|int|null $by = null, ?string $note = null): array
    {
        $row = is_array($order) ? $order : Database::get()->one('SELECT * FROM orders WHERE id = ?', [$order]);
        $fresh = $row ? Database::get()->one('SELECT * FROM orders WHERE id = ?', [(int) $row['id']]) : null;
        if (!$fresh || !self::isCancellable($fresh)) {
            throw new CartException('This order can no longer be cancelled online. Please contact us.');
        }
        return self::changeStatus($fresh, 'cancelled', $by, $note ?? 'Cancelled by customer');
    }

    /** Courier details typed by staff (manual shipping). */
    public static function setTracking(array|int $order, ?string $number, ?string $url, array|int|null $by = null, ?string $courier = null): void
    {
        $orderId = is_array($order) ? (int) $order['id'] : $order;
        $byId = is_array($by) ? (int) $by['id'] : $by;
        $number = $number ?: null;
        $url = $url ?: null;
        $courier = $courier ?: null;
        $exists = Database::get()->value('SELECT id FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        if (!$exists && !$number && !$url && !$courier) {
            return;
        }
        $shipmentId = self::saveShipment($orderId, ['tracking_number' => $number, 'tracking_url' => $url, 'courier' => $courier]);
        self::logShipmentEvent($shipmentId, 'details_updated', 'Courier details saved by staff' . ($courier ? ": {$courier}" : '') . ($number ? ", tracking {$number}" : ''), 'staff', $byId);
        ActivityLogger::log('order.tracking_updated', 'Order', $orderId, ['tracking_number' => $number, 'courier' => $courier]);
    }

    /** Create or update the order's (latest) shipment. Returns the shipment id. */
    public static function saveShipment(int $orderId, array $attributes): int
    {
        $db = Database::get();
        $allowed = ['courier', 'tracking_number', 'tracking_url', 'courier_reference', 'status', 'shipped_at', 'delivered_at'];
        $attributes = array_intersect_key($attributes, array_flip($allowed));
        $id = $db->value('SELECT id FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        if ($id) {
            if ($attributes) {
                $sets = implode(', ', array_map(static fn ($k) => "`{$k}` = ?", array_keys($attributes)));
                $db->exec("UPDATE shipments SET {$sets}, updated_at = ? WHERE id = ?", [...array_values($attributes), now(), $id]);
            }
            return (int) $id;
        }
        $attributes += ['order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()];
        $cols = implode(', ', array_map(static fn ($k) => "`{$k}`", array_keys($attributes)));
        return $db->insert("INSERT INTO shipments ({$cols}) VALUES " . Database::in(array_values($attributes)), array_values($attributes));
    }

    /** A factual entry in the shipment timeline (Shipment::logEvent). */
    public static function logShipmentEvent(int $shipmentId, string $status, ?string $description = null, string $source = 'system', ?int $userId = null, ?string $location = null): int
    {
        return Database::get()->insert(
            'INSERT INTO shipment_events (shipment_id, status, description, location, occurred_at, source, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$shipmentId, $status, $description, $location, now(), $source, $userId, now(), now()]
        );
    }

    /** Row in order_status_history (OrderStatusHistory::create). */
    public static function addHistory(int $orderId, string $status, ?string $note = null, ?int $userId = null): void
    {
        Database::get()->insert('INSERT INTO order_status_history (order_id, status, note, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $status, $note === null ? null : mb_substr($note, 0, 255), $userId, now(), now()]);
    }

    /* ---------------- helpers ---------------- */

    /** Keep the shipment and its timeline in step with the order. Only real status changes are recorded. */
    private static function recordShipmentStatus(int $orderId, string $status, ?int $byId): void
    {
        if (!in_array($status, ['shipped', 'delivered', 'returned'], true)) {
            return;
        }
        $attrs = ['status' => $status] + ($status === 'shipped' ? ['shipped_at' => now()] : []) + ($status === 'delivered' ? ['delivered_at' => now()] : []);
        $shipmentId = self::saveShipment($orderId, $attrs);
        self::logShipmentEvent($shipmentId, $status, 'Order marked as ' . $status . ' by ' . ($byId ? 'staff' : 'the system'), $byId ? 'staff' : 'system', $byId);
    }

    /**
     * Tax snapshot. Prices are tax-INCLUSIVE: tax = (subtotal - discount) x rate / (100 + rate). Shipping is excluded.
     *
     * @return array{tax_amount:int, tax_rate:?float, tax_label:?string}
     */
    private static function taxFor(int $taxable): array
    {
        $rate = (float) Settings::get('tax.rate', 0);
        if (!Settings::get('tax.enabled') || $rate <= 0) {
            return ['tax_amount' => 0, 'tax_rate' => null, 'tax_label' => null];
        }
        return ['tax_amount' => (int) round($taxable * $rate / (100 + $rate)), 'tax_rate' => $rate, 'tax_label' => (string) Settings::get('tax.label', 'Tax')];
    }

    private static function restock(array $order, ?int $byId, string $reason = 'cancellation', string $word = 'cancelled', bool $freeCoupon = true): void
    {
        $db = Database::get();
        foreach ($db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int) $order['id']]) as $item) {
            $note = 'Order ' . $order['number'] . " {$word}";
            $done = false;
            if ($item['variant_id'] && $db->value('SELECT id FROM product_variants WHERE id = ?', [$item['variant_id']])) {
                InventoryService::adjustVariant((int) $item['variant_id'], (int) $item['quantity'], $reason, (int) $order['id'], $byId, $note);
                $done = true;
            } elseif (!$item['variant_id'] && $item['product_id'] && $db->value('SELECT id FROM products WHERE id = ? AND deleted_at IS NULL', [$item['product_id']])) {
                InventoryService::adjustProduct((int) $item['product_id'], (int) $item['quantity'], $reason, (int) $order['id'], $byId, $note);
                $done = true;
            }
            if ($done) {
                $db->exec('UPDATE products SET sold_count = sold_count - ? WHERE id = ? AND sold_count >= ?', [(int) $item['quantity'], (int) $item['product_id'], (int) $item['quantity']]);
            }
        }
        if ($freeCoupon) {
            $db->exec('DELETE FROM coupon_usages WHERE order_id = ?', [(int) $order['id']]);   // frees the redemption
        }
    }

    /** ABH-ymd-XXXXX (5 random letters/digits, upper case). */
    private static function makeNumber(): string
    {
        $db = Database::get();
        do {
            $number = 'ABH-' . date('ymd') . '-' . strtoupper(self::randomToken(5));
        } while ($db->value('SELECT 1 FROM orders WHERE number = ?', [$number]));
        return $number;
    }

    private static function randomToken(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, 61)];
        }
        return $out;
    }
}
