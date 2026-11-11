<?php

namespace App\Support;

use App\Models\ShippingMethod;
use App\Services\Payments\PaymentManager;

/**
 * The reassurance row under the hero. Every item is derived from what the owner has really configured; nothing is
 * invented. If nothing applies the row is not shown at all.
 */
class TrustStrip
{
    /** @return list<array{icon:string,title:string,text:string,href:?string}> */
    public static function items(): array
    {
        return (function () {
            $cur = config('abiha.currency.symbol');
            $items = [];

            $freeOver = ShippingMethod::query()->where('is_active', true)->whereNotNull('free_over')->min('free_over');
            if ($freeOver !== null) {
                $items[] = ['icon' => 'truck', 'title' => 'Free delivery', 'text' => 'On orders over '.$cur.' '.number_format((int) $freeOver), 'href' => route('page.shipping')];
            }

            if (app(PaymentManager::class)->enabled()->has('cod')) {
                $items[] = ['icon' => 'credit-card', 'title' => 'Cash on delivery', 'text' => 'Pay when your order arrives', 'href' => null];
            }

            $items[] = ['icon' => 'map-pin', 'title' => 'Track your order', 'text' => 'Follow it with your order number', 'href' => route('track')];

            $items[] = ['icon' => 'rotate-ccw', 'title' => 'Shipping & returns', 'text' => 'Read our policy before you order', 'href' => route('page.shipping')];

            return $items;
        })();
    }
}
