<?php

declare(strict_types=1);

namespace App\Services\Admin;

/** Small formatting helpers for the sales/operations admin views (ports of Carbon/Str calls in the old Blade files). */
final class Display
{
    /** Carbon's diffForHumans() in English: "5 minutes ago", "1 day ago", "just now"-style "1 second ago". */
    public static function ago(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $diff = time() - (int) strtotime($datetime);
        $future = $diff < 0;
        $diff = abs($diff);
        $units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
        foreach ($units as [$name, $secs]) {
            if ($diff >= $secs || $name === 'second') {
                $n = max(1, intdiv($diff, $secs));
                return $n . ' ' . $name . ($n === 1 ? '' : 's') . ($future ? ' from now' : ' ago');
            }
        }
        return '';
    }

    /** "bank_transfer" -> "Bank Transfer" (ucwords(str_replace('_', ' ', ...))). */
    public static function words(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    /** "details_updated" -> "Details updated" (ucfirst(str_replace('_', ' ', ...))). */
    public static function sentence(?string $value): string
    {
        return ucfirst(str_replace('_', ' ', (string) $value));
    }

    /** Laravel's class_basename() for a stored morph class. */
    public static function basename(?string $class): string
    {
        $class = (string) $class;
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** rtrim(rtrim(number_format($rate, 2), '0'), '.') */
    public static function rate(mixed $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
    }
}
