<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Services\Cart\CartException;

/**
 * The shopping bag (tables carts / cart_items), port of the old App\Services\Cart\CartService (+ CartLine, CartSummary).
 *
 *  - a signed-in customer has ONE cart row with carts.user_id = their id (token NULL); it follows them across devices;
 *  - a guest's cart has carts.token = a random 40-char token kept in the session under "cart_token" (user_id NULL);
 *    it is created lazily on the first "add to bag", never just by browsing;
 *  - on login the guest cart is merged into the user's cart: Cart::mergeGuest($userId) (quantities capped to stock);
 *  - cart_items store ONLY {product, variant, quantity}. Prices, stock and availability are re-read from the catalogue on
 *    every call, so the browser can never influence a price or total.
 *
 * API (all static; every mutation throws App\Services\Cart\CartException with a customer-safe message):
 *   Cart::add(int $productId, ?int $variantId, int $qty)   Cart::update(string $key, int $qty)   Cart::remove($key)
 *   Cart::clear()   Cart::applyCoupon($code)   Cart::removeCoupon()   Cart::mergeGuest($userId)
 *   Cart::count()   Cart::current()   Cart::lines()   Cart::summary(?array $method, ?string $email, ?array $destination)
 *
 * A line (array): key "{product}-{variant|0}", product (products row + 'images' list of product_images rows),
 * variant (product_variants row + 'label') or null, quantity, unit_price, compare_price, stock, notice, total, sku, options.
 * A summary (array): lines, subtotal, discount, shipping, total, coupon (row|null), shipping_method (row|null),
 * coupon_error, shipping_available, count, notices (list of strings), empty (bool).
 */
final class Cart
{
    /** Session key of the guest cart token (Laravel used the nested key "cart.token"). */
    public const TOKEN_KEY = 'cart_token';
    public const MAX_PER_LINE = 20;

    /* ---------------- mutations ---------------- */

    /** Old CartService::add(Product, ?variantId, qty): $product is a products row or a product id. @throws CartException */
    public static function add(array|int $product, ?int $variantId, int $qty): void
    {
        $productId = is_array($product) ? (int) $product['id'] : $product;
        $qty = max(1, $qty);
        [$product, $variant] = self::resolve($productId, $variantId);
        $available = self::availableStock($product, $variant);
        if ($available <= 0) {
            throw new CartException('Sorry, this item is out of stock.');
        }
        $cart = self::cart(true);
        $item = self::findItem((int) $cart['id'], (int) $product['id'], $variant ? (int) $variant['id'] : null);
        $current = $item ? (int) $item['quantity'] : 0;
        if ($current + $qty > $available) {
            throw new CartException($available > $current
                ? "Only {$available} available. You already have {$current} in your bag."
                : "You already have all {$available} available in your bag.");
        }
        $quantity = min(self::MAX_PER_LINE, $current + $qty);
        $db = Database::get();
        if ($item) {
            $db->exec('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [$quantity, now(), $item['id']]);
        } else {
            $db->insert('INSERT INTO cart_items (cart_id, product_id, variant_id, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $cart['id'], (int) $product['id'], $variant ? (int) $variant['id'] : null, $quantity, now(), now()]);
        }
        $db->exec('UPDATE carts SET updated_at = ? WHERE id = ?', [now(), $cart['id']]);
    }

    /** @throws CartException */
    public static function update(string $key, int $qty): void
    {
        $item = self::itemByKey($key);
        if (!$item) {
            return;
        }
        $db = Database::get();
        if ($qty <= 0) {
            $db->exec('DELETE FROM cart_items WHERE id = ?', [$item['id']]);
            return;
        }
        [$product, $variant] = self::resolve((int) $item['product_id'], $item['variant_id'] !== null ? (int) $item['variant_id'] : null);
        $available = self::availableStock($product, $variant);
        if ($qty > $available) {
            throw new CartException("Only {$available} available.");
        }
        $db->exec('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [min($qty, self::MAX_PER_LINE), now(), $item['id']]);
    }

    public static function remove(string $key): void
    {
        if ($item = self::itemByKey($key)) {
            Database::get()->exec('DELETE FROM cart_items WHERE id = ?', [$item['id']]);
        }
    }

    public static function clear(): void
    {
        if ($cart = self::current()) {
            $db = Database::get();
            $db->exec('DELETE FROM cart_items WHERE cart_id = ?', [$cart['id']]);
            $db->exec('UPDATE carts SET coupon_id = NULL, updated_at = ? WHERE id = ?', [now(), $cart['id']]);
        }
    }

    /** @throws CartException */
    public static function applyCoupon(string $code): void
    {
        $cart = self::cart(true);
        $lines = self::lines();
        $coupon = CouponService::validate($code, self::subtotal($lines), Auth::id(), null, $lines);
        Database::get()->exec('UPDATE carts SET coupon_id = ?, updated_at = ? WHERE id = ?', [(int) $coupon['id'], now(), $cart['id']]);
    }

    public static function removeCoupon(): void
    {
        if ($cart = self::current()) {
            Database::get()->exec('UPDATE carts SET coupon_id = NULL, updated_at = ? WHERE id = ?', [now(), $cart['id']]);
        }
    }

    /**
     * Fold the guest cart (session "cart_token") into the customer's cart on login/registration, quantities capped to
     * stock and 20 per line. Call right after Auth::login(). Old CartService::mergeGuestInto().
     */
    public static function mergeGuest(int $userId): void
    {
        $token = Session::pull(self::TOKEN_KEY);
        $db = Database::get();
        $guest = is_string($token) && $token !== '' ? $db->one('SELECT * FROM carts WHERE token = ? AND user_id IS NULL LIMIT 1', [$token]) : null;
        if (!$guest) {
            return;
        }
        $db->transaction(static function (Database $db) use ($guest, $userId): void {
            $mine = $db->one('SELECT * FROM carts WHERE user_id = ? LIMIT 1', [$userId]);
            if (!$mine) {
                $id = $db->insert('INSERT INTO carts (user_id, created_at, updated_at) VALUES (?, ?, ?)', [$userId, now(), now()]);
                $mine = $db->one('SELECT * FROM carts WHERE id = ?', [$id]);
            }
            foreach ($db->all('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY id', [$guest['id']]) as $row) {
                $vid = $row['variant_id'] !== null ? (int) $row['variant_id'] : null;
                try {
                    [$product, $variant] = self::resolve((int) $row['product_id'], $vid);
                } catch (CartException) {
                    continue;
                }
                $stock = self::availableStock($product, $variant);
                $existing = self::findItem((int) $mine['id'], (int) $row['product_id'], $vid);
                $qty = min(self::MAX_PER_LINE, $stock, ($existing ? (int) $existing['quantity'] : 0) + (int) $row['quantity']);
                if ($qty < 1) {
                    continue;
                }
                if ($existing) {
                    $db->exec('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [$qty, now(), $existing['id']]);
                } else {
                    $db->insert('INSERT INTO cart_items (cart_id, product_id, variant_id, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [(int) $mine['id'], (int) $row['product_id'], $vid, $qty, now(), now()]);
                }
            }
            if ($guest['coupon_id'] && !$mine['coupon_id']) {
                $db->exec('UPDATE carts SET coupon_id = ? WHERE id = ?', [$guest['coupon_id'], $mine['id']]);
            }
            $db->exec('DELETE FROM carts WHERE id = ?', [$guest['id']]);
        });
    }

    /* ---------------- reads ---------------- */

    /** The current visitor's cart row, or null when they have none yet. */
    public static function current(): ?array
    {
        $db = Database::get();
        if ($uid = Auth::id()) {
            return $db->one('SELECT * FROM carts WHERE user_id = ? LIMIT 1', [$uid]);
        }
        $token = Session::get(self::TOKEN_KEY);
        return is_string($token) && $token !== '' ? $db->one('SELECT * FROM carts WHERE token = ? AND user_id IS NULL LIMIT 1', [$token]) : null;
    }

    /** Number of pieces in the bag (sum of quantities), like CartService::count(). Cheap: for the header badge. */
    public static function count(): int
    {
        try {
            $cart = self::current();
        } catch (\Throwable) {
            return 0;
        }
        return $cart ? (int) Database::get()->value('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ?', [$cart['id']]) : 0;
    }

    /**
     * Resolved lines with live prices and stock. Lines whose product was removed/deactivated (or whose variant is gone)
     * are deleted from the bag; quantities above the stock are reduced (with a notice).
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(): array
    {
        $cart = self::current();
        if (!$cart) {
            return [];
        }
        $db = Database::get();
        $items = $db->all('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY id', [$cart['id']]);
        if (!$items) {
            return [];
        }
        $pids = array_values(array_unique(array_map(static fn ($i) => (int) $i['product_id'], $items)));
        $products = [];
        foreach ($db->all('SELECT * FROM products WHERE is_active = 1 AND deleted_at IS NULL AND id IN ' . Database::in($pids), $pids) as $p) {
            $p['images'] = [];
            $p['variants'] = [];
            $products[(int) $p['id']] = $p;
        }
        if ($products) {
            $ids = array_keys($products);
            foreach ($db->all('SELECT * FROM product_images WHERE product_id IN ' . Database::in($ids) . ' ORDER BY sort_order, id', $ids) as $img) {
                $products[(int) $img['product_id']]['images'][] = $img;
            }
            $variants = $db->all('SELECT * FROM product_variants WHERE is_active = 1 AND product_id IN ' . Database::in($ids) . ' ORDER BY sort_order, id', $ids);
            $labels = self::variantLabels(array_map(static fn ($v) => (int) $v['id'], $variants));
            foreach ($variants as $v) {
                $v['label'] = $labels[(int) $v['id']] ?? '';
                $products[(int) $v['product_id']]['variants'][(int) $v['id']] = $v;
            }
        }

        $lines = [];
        foreach ($items as $item) {
            $product = $products[(int) $item['product_id']] ?? null;
            $vid = $item['variant_id'] !== null ? (int) $item['variant_id'] : null;
            $variant = $vid && $product ? ($product['variants'][$vid] ?? null) : null;
            // Product removed/deactivated, or variant gone: drop from the bag
            if (!$product || ($vid && !$variant) || ((int) $product['has_variants'] && !$variant)) {
                $db->exec('DELETE FROM cart_items WHERE id = ?', [$item['id']]);
                continue;
            }
            $stock = self::availableStock($product, $variant);
            $qty = (int) $item['quantity'];
            $notice = null;
            if ($stock <= 0) {
                $notice = 'No longer in stock. Remove it to continue.';
            } elseif ($qty > $stock) {
                $qty = $stock;
                $notice = "Quantity reduced to {$stock} (all that is in stock).";
                $db->exec('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [$qty, now(), $item['id']]);
            }
            [$unit, $compare] = self::prices($product, $variant);
            $lines[] = [
                'key' => $item['product_id'] . '-' . ($vid ?? 0),
                'product' => $product,
                'variant' => $variant,
                'quantity' => $qty,
                'unit_price' => $unit,
                'compare_price' => $compare,
                'stock' => $stock,
                'notice' => $notice,
                'total' => $unit * $qty,
                'sku' => $variant ? (string) $variant['sku'] : (string) $product['sku'],
                'options' => $variant && $variant['label'] !== '' ? $variant['label'] : null,
            ];
        }
        return $lines;
    }

    /**
     * Authoritative totals. $method = a ShippingService method row (null = the default method); $email lets per-customer
     * coupon limits apply to guests at checkout; $destination = ['province' => ..., 'city' => ...] enables city-wise
     * shipping rules (cart pages use the default rate).
     */
    public static function summary(?array $method = null, ?string $email = null, ?array $destination = null): array
    {
        $lines = self::lines();
        $subtotal = self::subtotal($lines);
        $cart = self::current();

        $coupon = null;
        $couponError = null;
        $discount = 0;
        if ($cart && $cart['coupon_id'] && ($candidate = CouponService::find((int) $cart['coupon_id']))) {
            try {
                $coupon = CouponService::check($candidate, $subtotal, Auth::id(), $email, $lines);
                $discount = CouponService::discountFor($coupon, CouponService::eligibleSubtotal($coupon, $lines));
            } catch (CartException $e) {
                $couponError = $e->getMessage();       // e.g. subtotal dropped below the minimum, limit reached
                $coupon = null;
                self::removeCoupon();
            }
        }

        $method ??= ShippingService::default();
        $afterDiscount = $subtotal - $discount;
        $quote = ($lines && $method) ? ShippingService::quote($method, $afterDiscount, $destination['province'] ?? null, $destination['city'] ?? null) : 0;
        $shipping = $quote ?? 0;
        $notices = [];
        foreach ($lines as $l) {
            if ($l['notice']) {
                $notices[] = $l['product']['name'] . ': ' . $l['notice'];
            }
        }
        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => $afterDiscount + $shipping,
            'coupon' => $coupon,
            'shipping_method' => $method,
            'coupon_error' => $couponError,
            'shipping_available' => $quote !== null,
            'count' => array_sum(array_map(static fn ($l) => $l['quantity'], $lines)),
            'notices' => $notices,
            'empty' => $lines === [],
        ];
    }

    /** Sum of the in-stock lines. */
    public static function subtotal(array $lines): int
    {
        $sum = 0;
        foreach ($lines as $l) {
            $sum += $l['stock'] > 0 ? (int) $l['total'] : 0;
        }
        return $sum;
    }

    /**
     * "Size: M / Colour: Black" labels of variants (ordered by attribute sort order), ProductVariant::label.
     *
     * @param list<int> $variantIds
     * @return array<int,string>
     */
    public static function variantLabels(array $variantIds): array
    {
        if (!$variantIds) {
            return [];
        }
        $rows = Database::get()->all(
            'SELECT pv.product_variant_id AS vid, av.value, a.name AS attr FROM attribute_value_product_variant pv
             JOIN attribute_values av ON av.id = pv.attribute_value_id LEFT JOIN attributes a ON a.id = av.attribute_id
             WHERE pv.product_variant_id IN ' . Database::in($variantIds) . ' ORDER BY COALESCE(a.sort_order, 0), av.attribute_id, av.id',
            $variantIds
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['vid']][] = ($r['attr'] ?? 'Option') . ': ' . $r['value'];
        }
        return array_map(static fn ($parts) => implode(' / ', $parts), $out);
    }

    /** [unit price, compare price|null] like ProductVariant::effective_price / effective_compare_price. */
    public static function prices(array $product, ?array $variant): array
    {
        if (!$variant) {
            $compare = $product['compare_price'] !== null && (int) $product['compare_price'] > (int) $product['price'] ? (int) $product['compare_price'] : null;
            return [(int) $product['price'], $compare];
        }
        $price = $variant['price'] !== null ? (int) $variant['price'] : (int) $product['price'];
        $compare = $variant['compare_price'] ?? ($variant['price'] === null ? $product['compare_price'] : null);
        return [$price, $compare !== null && (int) $compare > $price ? (int) $compare : null];
    }

    /* ---------------- helpers ---------------- */

    private static function cart(bool $create = false): ?array
    {
        $cart = self::current();
        if ($cart || !$create) {
            return $cart;
        }
        $db = Database::get();
        if ($uid = Auth::id()) {
            $db->exec('INSERT IGNORE INTO carts (user_id, created_at, updated_at) VALUES (?, ?, ?)', [$uid, now(), now()]);
            return $db->one('SELECT * FROM carts WHERE user_id = ? LIMIT 1', [$uid]);
        }
        $token = self::randomToken(40);
        Session::put(self::TOKEN_KEY, $token);
        $id = $db->insert('INSERT INTO carts (token, created_at, updated_at) VALUES (?, ?, ?)', [$token, now(), now()]);
        return $db->one('SELECT * FROM carts WHERE id = ?', [$id]);
    }

    /** Str::random(): letters and digits. */
    private static function randomToken(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, 61)];
        }
        return $out;
    }

    private static function findItem(int $cartId, int $productId, ?int $variantId): ?array
    {
        return $variantId === null
            ? Database::get()->one('SELECT * FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id IS NULL LIMIT 1', [$cartId, $productId])
            : Database::get()->one('SELECT * FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id = ? LIMIT 1', [$cartId, $productId, $variantId]);
    }

    private static function itemByKey(string $key): ?array
    {
        [$productId, $variantId] = array_pad(explode('-', $key, 2), 2, 0);
        $cart = self::current();
        return $cart ? self::findItem((int) $cart['id'], (int) $productId, ((int) $variantId) ?: null) : null;
    }

    private static function availableStock(array $product, ?array $variant): int
    {
        return max(0, (int) ($variant ? $variant['stock'] : $product['stock']));
    }

    /**
     * @return array{0: array, 1: ?array} [product row, variant row|null]
     * @throws CartException
     */
    private static function resolve(int $productId, ?int $variantId): array
    {
        $db = Database::get();
        $product = $db->one('SELECT * FROM products WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$productId]);
        if (!$product) {
            throw new CartException('This product is no longer available.');
        }
        if ((int) $product['has_variants']) {
            $variant = $variantId ? $db->one('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1', [$variantId, $productId]) : null;
            if (!$variant) {
                throw new CartException('Please choose a size and colour.');
            }
            return [$product, $variant];
        }
        return [$product, null];
    }
}
