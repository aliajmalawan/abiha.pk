<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentGateway
{
    /** Stable code stored on the order, e.g. "cod". */
    public function code(): string;

    public function label(): string;

    /** Short customer-facing explanation shown under the option at checkout. */
    public function description(): string;

    /** False hides the option (e.g. credentials missing). */
    public function isAvailable(): bool;
}
