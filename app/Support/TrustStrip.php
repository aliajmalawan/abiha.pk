<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;

/**
 * The reassurance row (port of the old App\Support\TrustStrip; partial store/partials/trust-strip, which the old home
 * page did not include either). Every item is derived from what the owner has really configured; nothing is invented.
 */
final class TrustStrip
{
    /** @return list<array{icon:string,title:string,text:string,href:?string}> */
    public static function items(): array
    {
        $cur = Config::get('abiha.currency.symbol');
        $items = [];
        $freeOver = Database::get()->value('SELECT MIN(free_over) FROM shipping_methods WHERE is_active = 1 AND free_over IS NOT NULL');
        if ($freeOver !== null) {
            $items[] = ['icon' => 'truck', 'title' => 'Free delivery', 'text' => 'On orders over ' . $cur . ' ' . number_format((int) $freeOver), 'href' => route('page.shipping')];
        }
        $enabled = Settings::get('payments.enabled');
        $codes = is_array($enabled) ? $enabled : (array) Config::get('payments.enabled', []);
        if (in_array('cod', $codes, true)) {
            $items[] = ['icon' => 'credit-card', 'title' => 'Cash on delivery', 'text' => 'Pay when your order arrives', 'href' => null];
        }
        $items[] = ['icon' => 'map-pin', 'title' => 'Track your order', 'text' => 'Follow it with your order number', 'href' => route('track')];
        $items[] = ['icon' => 'rotate-ccw', 'title' => 'Shipping & returns', 'text' => 'Read our policy before you order', 'href' => route('page.shipping')];
        return $items;
    }
}
