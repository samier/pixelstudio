<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationValidationTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private const BASE = 'https://pixelforge.test/v1';

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.base_url' => self::BASE]);
        $this->app->forgetInstance(PixelforgeClient::class);
        Queue::fake();
        Http::fake([self::BASE.'/pricing' => Http::response($this->pricingPayload())]);
    }

    public function test_reference_images_must_be_distinct_http_urls_and_at_most_four(): void
    {
        $this->submit(['not a url'])->assertSessionHasErrors('reference_images.0');
        $this->submit(['ftp://example.com/a.png'])->assertSessionHasErrors('reference_images.0');
        $this->submit(['https://example.com/a.png', 'https://example.com/a.png'])->assertSessionHasErrors('reference_images.1');
        $this->submit(array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5)))->assertSessionHasErrors('reference_images');

        $this->assertSame(0, Generation::count());
    }

    public function test_each_reference_image_adds_765_input_image_tokens_to_the_estimate(): void
    {
        $this->submit([])->assertSessionHasNoErrors();
        $this->submit(['https://example.com/a.png', 'https://example.com/b.png'])->assertSessionHasNoErrors();

        [$without, $with] = Generation::orderBy('id')->get()->all();

        // 2 × 765 tokens × $10 per 1M = $0.0153 provider cost, + 25% = $0.019125 on every scenario.
        $this->assertSame(19_125_000, $with->estimated_nanos - $without->estimated_nanos);
        $this->assertSame(19_125_000, $with->estimated_max_nanos - $without->estimated_max_nanos);
        $this->assertSame(['https://example.com/a.png', 'https://example.com/b.png'], $with->reference_images);
    }

    private function submit(array $references)
    {
        $this->user ??= User::factory()->create(['balance_nanos' => Money::fromDollars(5)]);

        return $this->actingAs($this->user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => $references,
        ]);
    }
}
