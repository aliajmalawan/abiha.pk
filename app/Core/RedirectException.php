<?php

declare(strict_types=1);

namespace App\Core;

final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $to)
    {
        parent::__construct('redirect');
    }
}
