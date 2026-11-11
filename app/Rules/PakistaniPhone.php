<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Pakistani numbers: mobile (03XX XXXXXXX, also +92 3XX… / 0092 3XX… / 92 3XX…) or a landline with area code
 * (0 + 2-3 digit area code + 6-8 digit number, 10-11 digits in total).
 */
class PakistaniPhone implements ValidationRule
{
    /** Strip spaces, dashes and brackets, and turn +92 / 0092 / 92 into the local leading 0. Returns digits only, or null. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $d = preg_replace('/[\s\-().]/', '', $value);
        if (! preg_match('/^\+?\d+$/', $d)) {
            return null;
        }
        $d = ltrim($d, '+');
        if (str_starts_with($d, '0092')) {
            $d = '0'.substr($d, 4);
        } elseif (str_starts_with($d, '92')) {
            $d = '0'.substr($d, 2);
        }

        return $d;
    }

    public static function isValid(mixed $value): bool
    {
        $d = self::normalize($value);

        if ($d === null) {
            return false;
        }
        if (str_starts_with($d, '03')) {
            return preg_match('/^03\d{9}$/', $d) === 1;          // mobile: exactly 11 digits
        }

        return preg_match('/^0[2-9]\d{8,9}$/', $d) === 1;        // landline: 10-11 digits with the area code
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Enter a valid Pakistani phone number, for example 0300 1234567 or +92 300 1234567.');
        }
    }
}
