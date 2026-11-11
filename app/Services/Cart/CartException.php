<?php

namespace App\Services\Cart;

use RuntimeException;

/** A user-facing cart problem (out of stock, invalid variant…). Message is safe to display. */
class CartException extends RuntimeException
{
}
