<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Shipping methods and destination rules (port of the old App\Services\ShippingService).
 * A method is a shipping_methods row with its rules attached under 'rules' (shipping_rules rows, ordered like the
 * old relation: city rules first, then by province, city).
 *
 *   ShippingService::methods()                       active methods, by sort_order (each with 'rules')
 *   ShippingService::find('dhl') / ::default()
 *   ShippingService::quote($method, $subtotalAfterDiscount, 'Punjab', 'Lahore')   int PKR, or null = not offered there
 *   ShippingService::etaFor($method, $province, $city)                            ?string
 */
final class ShippingService
{
    /** @return list<array<string,mixed>> */
    public static function methods(): array
    {
        $db = Database::get();
        $methods = $db->all('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id');
        $rules = [];
        if ($methods) {
            $ids = array_map(static fn ($m) => (int) $m['id'], $methods);
            foreach ($db->all('SELECT * FROM shipping_rules WHERE shipping_method_id IN ' . Database::in($ids) . ' ORDER BY (city IS NULL), province, city, id', $ids) as $r) {
                $rules[(int) $r['shipping_method_id']][] = $r;
            }
        }
        foreach ($methods as &$m) {
            $m['rules'] = $rules[(int) $m['id']] ?? [];
        }
        unset($m);
        return $methods;
    }

    public static function find(?string $code): ?array
    {
        if (!$code) {
            return null;
        }
        foreach (self::methods() as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }
        return null;
    }

    public static function default(): ?array
    {
        return self::methods()[0] ?? null;
    }

    /** Default (no destination rule) cost of a method (ShippingMethod::costFor). */
    public static function costFor(array $method, int $subtotal): int
    {
        return $method['free_over'] !== null && $subtotal >= (int) $method['free_over'] ? 0 : (int) $method['cost'];
    }

    /**
     * Shipping cost in PKR for a destination, or null when the method is not offered there.
     * Resolution: city rule > province rule > method default. $subtotal is the amount AFTER discounts.
     */
    public static function quote(array $method, int $subtotal, ?string $province = null, ?string $city = null): ?int
    {
        $rule = self::ruleFor($method, $province, $city);
        if (!$rule) {
            return self::costFor($method, $subtotal);
        }
        if (!(int) $rule['is_available']) {
            return null;
        }
        $free = $rule['free_over'] ?? $method['free_over'];
        return $free !== null && $subtotal >= (int) $free ? 0 : (int) $rule['cost'];
    }

    /** Delivery estimate for a destination: the city/province rule's own estimate, else the method's. */
    public static function etaFor(array $method, ?string $province = null, ?string $city = null): ?string
    {
        $rule = self::ruleFor($method, $province, $city);
        $eta = trim((string) (($rule['eta'] ?? null) ?: ($method['eta'] ?? '')));
        return $eta !== '' ? $eta : null;
    }

    public static function ruleFor(array $method, ?string $province, ?string $city): ?array
    {
        $province = self::norm($province);
        $city = self::norm($city);
        $rules = $method['rules'] ?? Database::get()->all(
            'SELECT * FROM shipping_rules WHERE shipping_method_id = ? ORDER BY (city IS NULL), province, city, id',
            [(int) $method['id']]
        );
        if ($city !== '') {
            foreach ($rules as $r) {
                if ($r['city'] !== null && self::norm($r['city']) === $city && ($r['province'] === null || self::norm($r['province']) === $province || $province === '')) {
                    return $r;
                }
            }
        }
        if ($province !== '') {
            foreach ($rules as $r) {
                if ($r['city'] === null && $r['province'] !== null && self::norm($r['province']) === $province) {
                    return $r;
                }
            }
        }
        return null;
    }

    private static function norm(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }
}
