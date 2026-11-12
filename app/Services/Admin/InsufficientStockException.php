<?php

declare(strict_types=1);

namespace App\Services\Admin;

/** A stock change would take a balance below zero (port of App\Services\Inventory\InsufficientStockException). */
final class InsufficientStockException extends \RuntimeException
{
}
