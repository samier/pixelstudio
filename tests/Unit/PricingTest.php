<?php

namespace Tests\Unit;

use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Pricing;
use App\Support\Money;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures;

class PricingTest extends TestCase
{
    use PixelforgeFixtures;

    public function test_money_round_trips_exact_decimal_strings(): void
    {
        $this->assertSame(46_125_000, Money::parse('0.046125000'));
        $this->assertSame(12_500_000_000, Money::parse('12.50'));
        $this->assertSame(1, Money::parse('0.000000001'));
        $this->assertSame('0.046125000', Money::format(46_125_000));
        $this->assertSame('-1.000000001', Money::format(-1_000_000_001));
    }

    public function test_markup_is_exact_and_never_rounds_down(): void
    {
        $this->assertSame(57_656_250, Money::withMarkup(46_125_000, 25));
        $this->assertSame(2, Money::withMarkup(1, 25)); // 1.25 nanos → 2: never less than cost + markup
    }

    public function test_display_rounds_for_humans_only(): void
    {
        $this->assertSame('0.0577', Money::display(57_656_250));
        $this->assertSame('1.0000', Money::display(1_000_000_000));
        $this->assertSame('0.0000', Money::display(0));
    }

    public function test_cost_of_the_docs_succeeded_example(): void
    {
        $cost = CostCalculator::cost(
            ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
            $this->pricingPayload()['rates'],
        );

        $this->assertSame('0.305510000', Money::format($cost));
    }

    public function test_cost_matches_the_docs_usage_example(): void
    {
        $cost = CostCalculator::cost(
            ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950],
            $this->pricingPayload()['rates'],
        );

        $this->assertSame('0.169080000', Money::format($cost));
    }

    public function test_maximum_estimate_matches_the_docs_worked_example(): void
    {
        $estimate = (new Pricing($this->pricingPayload()))->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate['max']['tokens']['input_text_tokens']);
        $this->assertSame('0.373875000', Money::format($estimate['max']['cost']));
        $this->assertSame(85, $estimate['min']['tokens']['input_text_tokens']);
        $this->assertSame(1000, $estimate['min']['tokens']['reasoning_tokens']);
        $this->assertLessThan($estimate['max']['cost'], $estimate['expected']['cost']);
        $this->assertLessThan($estimate['expected']['cost'], $estimate['min']['cost']);
    }

    public function test_text_tokens_count_unicode_characters_not_bytes(): void
    {
        $estimate = (new Pricing($this->pricingPayload()))->estimate('ééééé', '1024x1024', 'low', 0);

        $this->assertSame(2, $estimate['expected']['tokens']['input_text_tokens']); // ceil(5 / 4), not ceil(10 / 4)
    }
}
