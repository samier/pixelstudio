<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * The response of GET /v1/pricing, plus the maths for estimating a generation's cost before it runs.
 *
 * Unknown until a generation completes: the exact input text tokens (±variance around ceil(chars / 4))
 * and the reasoning tokens (a range per quality). Everything else is exact up front.
 */
final class Pricing
{
    /** @param array<string, mixed> $data the raw pricing response */
    public function __construct(public readonly array $data) {}

    /** @return array<string, string> */
    public function rates(): array
    {
        return $this->data['rates'];
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys($this->data['tokens']['output']['medium']);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->data['tokens']['output']);
    }

    /**
     * Provider-cost estimate (no markup) for a request, as token counts and nanodollars for min / expected / max.
     *
     * @return array{min: array{tokens: array<string,int>, cost: int}, expected: array{tokens: array<string,int>, cost: int}, max: array{tokens: array<string,int>, cost: int}}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $tokens = $this->data['tokens'];

        $textEstimate = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $varianceBp = (int) round($tokens['text_token_variance'] * 10_000); // 0.15 → 1500 basis points

        $common = [
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];

        $reasoning = $tokens['reasoning'][$quality];

        $scenarios = [
            'min' => [
                'input_text_tokens' => intdiv($textEstimate * (10_000 - $varianceBp), 10_000),
                'reasoning_tokens' => $reasoning['min'],
            ],
            'expected' => [
                'input_text_tokens' => $textEstimate,
                'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
            ],
            'max' => [
                'input_text_tokens' => Money::divCeil($textEstimate * (10_000 + $varianceBp), 10_000),
                'reasoning_tokens' => $reasoning['max'],
            ],
        ];

        return array_map(function (array $scenario) use ($common) {
            $all = [...$scenario, ...$common];

            return ['tokens' => $all, 'cost' => CostCalculator::cost($all, $this->rates())];
        }, $scenarios);
    }
}
