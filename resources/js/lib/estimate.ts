import type { Pricing } from '@/types';
import { parseUsd } from './money';

/**
 * Client-side mirror of App\Services\Pixelforge\Pricing::estimate(), so the price updates as the user types.
 * The server recomputes it on submit; that figure is the one we reserve and record.
 */
export type TokenCounts = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

export interface Scenario {
    tokens: TokenCounts;
    /** Price to the user in nanodollars, markup included. */
    price: number;
}

export interface Estimate {
    min: Scenario;
    expected: Scenario;
    max: Scenario;
}

const divCeil = (a: number, b: number) => Math.floor((a + b - 1) / b);

export function providerCost(tokens: TokenCounts, rates: Pricing['rates']): number {
    // tokens × nanodollars-per-1M is at most ~3e14, well inside Number's exact integer range.
    const femto = (Object.keys(tokens) as (keyof TokenCounts)[]).reduce((sum, type) => sum + tokens[type] * parseUsd(rates[type]), 0);

    return divCeil(femto, 1_000_000);
}

export function estimate(
    pricing: Pricing,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
    markupPercent: number,
): Estimate {
    const t = pricing.tokens;
    const chars = [...prompt].length; // Unicode code points, like PHP's mb_strlen
    const text = Math.ceil(chars / t.characters_per_text_token);
    const varianceBp = Math.round(t.text_token_variance * 10_000);
    const reasoning = t.reasoning[quality];

    const common = {
        input_image_tokens: referenceImages * t.per_reference_image,
        output_tokens: t.output[quality][size],
    };

    const scenario = (inputText: number, reasoningTokens: number): Scenario => {
        const tokens = { input_text_tokens: inputText, reasoning_tokens: reasoningTokens, ...common };

        return { tokens, price: divCeil(providerCost(tokens, pricing.rates) * (100 + markupPercent), 100) };
    };

    return {
        min: scenario(Math.floor((text * (10_000 - varianceBp)) / 10_000), reasoning.min),
        expected: scenario(text, Math.floor((reasoning.min + reasoning.max) / 2)),
        max: scenario(divCeil(text * (10_000 + varianceBp), 10_000), reasoning.max),
    };
}
