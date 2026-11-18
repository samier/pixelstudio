<?php

namespace Tests\Support;

use App\Services\Pixelforge\WebhookSignature;

/** Payloads shaped exactly like the examples in the Pixelforge docs. */
trait PixelforgeFixtures
{
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    protected function generationObject(array $overrides = []): array
    {
        return array_replace([
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->pricingPayload()['rates'],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ], $overrides);
    }

    /** The docs' succeeded example: $0.305510000 at the published rates. */
    protected function succeededObject(array $overrides = []): array
    {
        return $this->generationObject([
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1&signature=x',
            'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ],
            'started_at' => '2026-11-04T14:03:13Z',
            'completed_at' => '2026-11-04T14:03:29Z',
            ...$overrides,
        ]);
    }

    protected function failedObject(array $overrides = []): array
    {
        return $this->generationObject([
            'status' => 'failed',
            'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating. You were not charged; please retry.'],
            'completed_at' => '2026-11-04T14:03:29Z',
            ...$overrides,
        ]);
    }

    protected function postWebhook(string $type, array $object, ?string $eventId = null, ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode([
            'id' => $eventId ?? 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $object],
        ]);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::header($body, $secret ?? self::WEBHOOK_SECRET, $timestamp ?? time()),
            'HTTP_PIXELFORGE_EVENT_TYPE' => $type,
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ], $body);
    }
}
