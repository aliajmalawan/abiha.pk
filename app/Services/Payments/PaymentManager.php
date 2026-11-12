<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Config;
use App\Support\Settings;

/**
 * The payment gateways (cfg('payments.gateways'): code => class). Port of the old PaymentManager.
 *
 *   PaymentManager::enabledCodes()   codes the admin switched on (setting payments.enabled, else cfg('payments.enabled'))
 *   PaymentManager::enabled()        [code => PaymentGateway] enabled AND available (what checkout offers)
 *   PaymentManager::all()            [code => PaymentGateway] every registered gateway (admin screen)
 *   PaymentManager::find('cod')      enabled+available gateway or null
 *   PaymentManager::online('card')   OnlinePaymentGateway regardless of "enabled" (webhooks keep working after disabling)
 *   PaymentManager::label('cod')     "Cash on Delivery" (fallback "Bank Transfer"-style title case)
 */
final class PaymentManager
{
    /** @return list<string> */
    public static function enabledCodes(): array
    {
        $stored = Settings::get('payments.enabled');
        return array_values(is_array($stored) ? $stored : (array) Config::get('payments.enabled', []));
    }

    /** @return array<string,PaymentGateway> */
    public static function enabled(): array
    {
        $out = [];
        foreach (self::enabledCodes() as $code) {
            $g = self::make((string) $code);
            if ($g && $g->isAvailable()) {
                $out[$g->code()] = $g;
            }
        }
        return $out;
    }

    /** @return array<string,PaymentGateway> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys((array) Config::get('payments.gateways', [])) as $code) {
            if ($g = self::make((string) $code)) {
                $out[$g->code()] = $g;
            }
        }
        return $out;
    }

    public static function find(string $code): ?PaymentGateway
    {
        return self::enabled()[$code] ?? null;
    }

    public static function online(string $code): ?OnlinePaymentGateway
    {
        $g = self::make($code);
        return $g instanceof OnlinePaymentGateway ? $g : null;
    }

    public static function label(string $code): string
    {
        $g = self::make($code);
        return $g ? $g->label() : ucwords(str_replace('_', ' ', $code));
    }

    private static function make(string $code): ?PaymentGateway
    {
        $class = ((array) Config::get('payments.gateways', []))[$code] ?? null;
        if (!is_string($class) || !class_exists($class)) {
            return null;
        }
        $g = new $class();
        return $g instanceof PaymentGateway ? $g : null;
    }
}
