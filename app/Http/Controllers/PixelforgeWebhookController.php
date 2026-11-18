<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge webhooks. Delivery is at least once and in any order, so:
 *  - each event id is stored once (unique index); a repeat delivery is acknowledged and ignored;
 *  - the state is taken from the generation object in the event, applied by GenerationSync, which only
 *    moves forward and settles money exactly once.
 * Recording the event and applying it share one transaction: if applying fails we answer 500, nothing
 * is kept, and Pixelforge's retry gets a clean second chance.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSync $sync): Response
    {
        $payload = $request->getContent(); // Raw body: the signature covers the exact bytes.

        if (! WebhookSignature::isValid(
            $payload,
            $request->header('Pixelforge-Signature'),
            (string) config('services.pixelforge.webhook_secret'),
            config('services.pixelforge.webhook_tolerance'),
        )) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Malformed event', 400);
        }

        $object = $event['data']['object'] ?? null;
        $isGenerationEvent = is_array($object) && ($object['object'] ?? null) === 'generation' && isset($object['id']);

        DB::transaction(function () use ($event, $object, $isGenerationEvent, $request, $sync) {
            $generation = $isGenerationEvent ? $this->findGeneration($object) : null;

            $isNew = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => $isGenerationEvent ? $object['id'] : null,
                'generation_id' => $generation?->id,
                'payload' => json_encode($event),
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if ($isNew && $generation !== null) {
                $sync->apply($generation, $object);
            }
        });

        return response()->noContent();
    }

    /**
     * Match by our own uuid from metadata first: the webhook can beat our POST /v1/generations response,
     * before we know Pixelforge's id.
     */
    private function findGeneration(array $object): ?Generation
    {
        $uuid = $object['metadata']['generation_uuid'] ?? null;

        return ($uuid ? Generation::query()->where('uuid', $uuid)->first() : null)
            ?? Generation::query()->where('provider_id', $object['id'])->first();
    }
}
