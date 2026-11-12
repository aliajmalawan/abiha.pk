<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Config;

/** The couriers registered in cfg('shipping.couriers'). Empty by default: shipping is managed by hand. */
final class CourierManager
{
    /** @return array<string,CourierProvider> every registered courier, configured or not */
    public static function all(): array
    {
        $out = [];
        foreach ((array) Config::get('shipping.couriers', []) as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, CourierProvider::class)) {
                $c = new $class();
                $out[$c->code()] = $c;
            }
        }
        return $out;
    }

    /** @return array<string,CourierProvider> only couriers with working credentials */
    public static function available(): array
    {
        return array_filter(self::all(), static fn (CourierProvider $c) => $c->isConfigured());
    }

    public static function find(?string $code): ?CourierProvider
    {
        return $code ? (self::available()[$code] ?? null) : null;
    }
}
