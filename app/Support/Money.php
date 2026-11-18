<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is handled as integer nanodollars (1 USD = 1_000_000_000 nanos).
 *
 * Pixelforge reports amounts as exact decimal strings with nine decimal places,
 * so nanodollars represent every provider cost exactly, without float drift.
 */
final class Money
{
    public const NANOS_PER_USD = 1_000_000_000;

    /** Parse an exact decimal USD string such as "0.046125000" or "12.50" into nanodollars. */
    public static function parse(string $usd): int
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', trim($usd), $m)) {
            throw new InvalidArgumentException("Not an exact USD amount with at most 9 decimals: [{$usd}]");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_USD + (int) str_pad($m[3] ?? '', 9, '0');

        return ($m[1] ?? '') === '-' ? -$nanos : $nanos;
    }

    /** Format nanodollars as an exact nine-decimal string, the same format Pixelforge uses. */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return sprintf('%s%d.%09d', $sign, intdiv($abs, self::NANOS_PER_USD), $abs % self::NANOS_PER_USD);
    }

    /** Human display, e.g. "0.0577". Rounded for reading only; stored amounts stay exact. */
    public static function display(int $nanos, int $decimals = 4): string
    {
        $unit = 10 ** (9 - $decimals);
        $rounded = intdiv(abs($nanos) + intdiv($unit, 2), $unit);
        $whole = intdiv($rounded, 10 ** $decimals);
        $fraction = str_pad((string) ($rounded % 10 ** $decimals), $decimals, '0', STR_PAD_LEFT);

        return ($nanos < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    public static function fromDollars(int $dollars): int
    {
        return $dollars * self::NANOS_PER_USD;
    }

    /**
     * Apply a percentage markup, rounding up to the next nanodollar if it doesn't divide evenly,
     * so we never charge less than cost + markup. (With Pixelforge's published rates it always divides evenly.)
     */
    public static function withMarkup(int $nanos, int $markupPercent): int
    {
        return self::divCeil($nanos * (100 + $markupPercent), 100);
    }

    /** Integer division rounding towards positive infinity, for non-negative numerators. */
    public static function divCeil(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
