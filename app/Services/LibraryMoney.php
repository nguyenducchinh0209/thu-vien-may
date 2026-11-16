<?php

namespace App\Services;

final class LibraryMoney
{
    public static function minor(string|int $amount): int
    {
        $amount = (string) $amount;
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
        $value = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$value : $value;
    }

    public static function decimal(int $minor): string
    {
        $absolute = abs($minor);

        return ($minor < 0 ? '-' : '').sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }
}
