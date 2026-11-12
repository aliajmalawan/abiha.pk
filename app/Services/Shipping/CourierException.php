<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/** A courier could not do what was asked. The message is safe to show to staff. */
class CourierException extends \RuntimeException
{
}
