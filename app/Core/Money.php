<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ABIHA money: whole Pakistani rupees stored as integers (no minor units, no floats).
 * Printed exactly like the old site: "PKR 1,200" (symbol from cfg('abiha.currency.symbol'), Admin -> Settings may change it).
 */
final class Money
{
    public static function symbol(): string
    {
        return (string) Config::get('abiha.currency.symbol', 'PKR');
    }

    /** "PKR 1,200". */
    public static function format(int|float|string|null $amount): string
    {
        return self::symbol() . ' ' . number_format((int) $amount);
    }

    /**
     * Parses admin/user input ("2,499", "2499", "2499.00") into whole rupees.
     * Returns null for malformed, fractional or negative input. Never goes through floats.
     */
    public static function parse(string $input): ?int
    {
        $s = str_replace([',', ' '], '', trim($input));
        if (!preg_match('/^(\d{1,10})(?:\.0{1,2})?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    /** Percentage of an amount, rounded half-up to the nearest rupee. $percent is a whole number 0-100. */
    public static function percentOf(int $amount, int $percent): int
    {
        return intdiv($amount * $percent + 50, 100);
    }

    /**
     * Splits $amount across $weights proportionally (largest remainder), so the parts always sum exactly to $amount.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0 || $amount === 0) {
            return array_fill(0, count($weights), 0);
        }
        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach (array_values($weights) as $i => $w) {
            $exact = $amount * $w;
            $parts[$i] = intdiv($exact, $total);
            $remainders[$i] = $exact % $total;
            $allocated += $parts[$i];
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($allocated >= $amount) {
                break;
            }
            $parts[$i]++;
            $allocated++;
        }
        return array_values($parts);
    }
}
