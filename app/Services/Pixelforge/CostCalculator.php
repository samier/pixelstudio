<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Turns token counts and per-1M-token rates into an exact provider cost.
 */
final class CostCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, int>  $tokens  e.g. ['input_text_tokens' => 12, 'output_tokens' => 6450, ...]
     * @param  array<string, string>  $rates  USD per 1M tokens as decimal strings, e.g. ['output_tokens' => '40.00']
     * @return int nanodollars
     */
    public static function cost(array $tokens, array $rates): int
    {
        // tokens × (nanodollars per 1M tokens) is in units of 1e-15 USD; divide by 1M to get nanodollars.
        // Largest realistic term: 8,000 tokens × 40e9 = 3.2e14, comfortably inside a 64-bit int.
        $femtodollars = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $count = (int) ($tokens[$type] ?? 0);

            if ($count === 0) {
                continue;
            }

            $femtodollars += $count * Money::parse((string) $rates[$type]);
        }

        return Money::divCeil($femtodollars, 1_000_000);
    }
}
