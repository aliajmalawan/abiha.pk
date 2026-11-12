<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Services\Cart\CartException;

/**
 * Coupon rules (port of the old App\Services\CouponService + Coupon model helpers). A coupon is a coupons row
 * (soft-deleted coupons do not exist for the shop). $lines are cart lines as returned by Cart::lines().
 * $user is a users row, a user id or null; $email lets per-customer limits apply to guests at checkout.
 *
 *   $coupon = CouponService::validate('save10', $subtotal, $user, $email, $lines);   // throws CartException
 *   $discount = CouponService::discountFor($coupon, CouponService::eligibleSubtotal($coupon, $lines));
 */
final class CouponService
{
    /** @throws CartException with a customer-safe message */
    public static function validate(string $code, int $subtotal, array|int|null $user = null, ?string $email = null, ?array $lines = null): array
    {
        $coupon = self::findByCode($code);
        if (!$coupon) {
            throw new CartException('This coupon code is not valid.');
        }
        return self::check($coupon, $subtotal, $user, $email, $lines);
    }

    public static function findByCode(string $code): ?array
    {
        return Database::get()->one('SELECT * FROM coupons WHERE code = ? AND deleted_at IS NULL LIMIT 1', [strtoupper(trim($code))]);
    }

    public static function find(int $id): ?array
    {
        return Database::get()->one('SELECT * FROM coupons WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @throws CartException */
    public static function check(array $coupon, int $subtotal, array|int|null $user = null, ?string $email = null, ?array $lines = null): array
    {
        $now = now();
        if (!(int) $coupon['is_active']) {
            throw new CartException('This coupon code is not valid.');
        }
        if (!empty($coupon['starts_at']) && $coupon['starts_at'] > $now) {
            throw new CartException('This coupon is not active yet.');
        }
        if (!empty($coupon['expires_at']) && $coupon['expires_at'] < $now) {
            throw new CartException('This coupon has expired.');
        }
        if ($subtotal < (int) $coupon['min_subtotal']) {
            throw new CartException('Spend at least ' . Money::symbol() . ' ' . number_format((int) $coupon['min_subtotal']) . ' to use this coupon.');
        }
        if (self::isRestricted($coupon) && ($lines === null || self::eligibleSubtotal($coupon, $lines) <= 0)) {
            throw new CartException('This coupon does not apply to the items in your bag.');     // fails closed: no lines, no discount
        }
        $db = Database::get();
        if ($coupon['usage_limit'] !== null && (int) $db->value('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?', [(int) $coupon['id']]) >= (int) $coupon['usage_limit']) {
            throw new CartException('This coupon has reached its usage limit.');
        }
        if ($coupon['per_customer_limit'] !== null && self::customerUses($coupon, $user, $email) >= (int) $coupon['per_customer_limit']) {
            throw new CartException('You have already used this coupon the maximum number of times.');
        }
        return $coupon;
    }

    /** True when the coupon only applies to chosen products or categories. */
    public static function isRestricted(array $coupon): bool
    {
        $db = Database::get();
        return (bool) $db->value('SELECT 1 FROM coupon_category WHERE coupon_id = ? LIMIT 1', [(int) $coupon['id']])
            || (bool) $db->value('SELECT 1 FROM coupon_product WHERE coupon_id = ? LIMIT 1', [(int) $coupon['id']]);
    }

    /**
     * The part of the bag this coupon may discount: the whole subtotal for an unrestricted coupon, otherwise only the
     * lines whose product is listed or whose category is listed (a listed category includes its sub-categories).
     */
    public static function eligibleSubtotal(array $coupon, array $lines): int
    {
        $db = Database::get();
        $productIds = array_map('intval', $db->column('SELECT cp.product_id FROM coupon_product cp JOIN products p ON p.id = cp.product_id WHERE cp.coupon_id = ? AND p.deleted_at IS NULL', [(int) $coupon['id']]));
        $categoryIds = array_map('intval', $db->column('SELECT cc.category_id FROM coupon_category cc JOIN categories c ON c.id = cc.category_id WHERE cc.coupon_id = ? AND c.deleted_at IS NULL', [(int) $coupon['id']]));
        if ($productIds === [] && $categoryIds === []) {
            return array_sum(array_map(static fn ($l) => (int) $l['total'], $lines));
        }
        $all = [];
        foreach ($categoryIds as $id) {
            foreach (self::descendantIds($id) as $d) {
                $all[$d] = true;
            }
        }
        $sum = 0;
        foreach ($lines as $l) {
            $pid = (int) $l['product']['id'];
            $cid = $l['product']['category_id'] !== null ? (int) $l['product']['category_id'] : null;
            if (in_array($pid, $productIds, true) || ($cid && isset($all[$cid]))) {
                $sum += (int) $l['total'];
            }
        }
        return $sum;
    }

    /** Category::descendantIds(): the category and every sub-category below it. @return list<int> */
    public static function descendantIds(int $id): array
    {
        $ids = [$id];
        $frontier = [$id];
        $db = Database::get();
        while ($frontier) {
            $frontier = array_map('intval', $db->column('SELECT id FROM categories WHERE deleted_at IS NULL AND parent_id IN ' . Database::in($frontier), $frontier));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }
        return $ids;
    }

    /** How many times this customer (account or checkout email) redeemed the coupon. */
    public static function customerUses(array $coupon, array|int|null $user, ?string $email): int
    {
        $uid = is_array($user) ? (int) $user['id'] : $user;
        if (!$uid && !$email) {
            return 0;
        }
        $where = [];
        $params = [(int) $coupon['id']];
        if ($uid) {
            $where[] = 'user_id = ?';
            $params[] = $uid;
        }
        if ($email) {
            $where[] = 'LOWER(email) = ?';
            $params[] = mb_strtolower($email);
        }
        return (int) Database::get()->value('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND (' . implode(' OR ', $where) . ')', $params);
    }

    /** Discount in whole PKR for a given subtotal (never more than the subtotal). Coupon::discountFor(). */
    public static function discountFor(array $coupon, int $subtotal): int
    {
        $amount = $coupon['type'] === 'percent' ? (int) floor($subtotal * (int) $coupon['value'] / 100) : (int) $coupon['value'];
        if ($coupon['max_discount'] !== null) {
            $amount = min($amount, (int) $coupon['max_discount']);
        }
        return max(0, min($amount, $subtotal));
    }
}
