<?php

declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Please sign in to continue.',
            403 => 'You do not have permission to do that.',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Please refresh the page and try again.',
            429 => 'Too many attempts. Please wait and try again.',
            503 => 'Service temporarily unavailable',
            default => 'Something went wrong',
        };
    }
}
