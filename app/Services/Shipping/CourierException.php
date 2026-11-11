<?php

namespace App\Services\Shipping;

use RuntimeException;

/** A courier could not do what was asked. The message is safe to show to staff. */
class CourierException extends RuntimeException
{
}
