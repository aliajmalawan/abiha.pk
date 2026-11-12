<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/** A stock change would take a product or variant below zero (or a negative level was asked for). */
class InsufficientStockException extends \RuntimeException
{
}
