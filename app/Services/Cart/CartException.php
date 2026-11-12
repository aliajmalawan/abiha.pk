<?php

declare(strict_types=1);

namespace App\Services\Cart;

/** A customer-safe problem with the bag, a coupon, checkout or an order action (the message is shown as is). */
class CartException extends \RuntimeException
{
}
