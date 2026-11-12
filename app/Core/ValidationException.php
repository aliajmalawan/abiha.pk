<?php

declare(strict_types=1);

namespace App\Core;

/**
 * User-correctable input problems (the port of Laravel's ValidationException).
 *
 * ErrorHandler turns it into: HTML request -> redirect back (or to $redirectTo) with the "errors" (field => message) and
 * "old" input flashed; JSON request -> 422 {"message": first error, "errors": {"field": ["message"]}} like Laravel.
 *
 *   throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
 */
final class ValidationException extends \RuntimeException
{
    public ?string $redirectTo = null;

    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors, ?string $message = null)
    {
        parent::__construct($message ?? self::summary($errors));
    }

    /** @param array<string,string|list<string>> $errors */
    public static function withMessages(array $errors): self
    {
        $flat = [];
        foreach ($errors as $k => $v) {
            $flat[(string) $k] = is_array($v) ? (string) reset($v) : (string) $v;
        }
        return new self($flat);
    }

    public static function one(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** Redirect to this path instead of "back". */
    public function redirectTo(string $path): self
    {
        $this->redirectTo = $path;
        return $this;
    }

    /** Laravel's summary: "First message (and 2 more errors)". */
    public static function summary(array $errors): string
    {
        if (!$errors) {
            return 'The given data was invalid.';
        }
        $first = (string) reset($errors);
        $more = count($errors) - 1;
        return $more > 0 ? $first . ' (and ' . $more . ' more error' . ($more > 1 ? 's' : '') . ')' : $first;
    }
}
