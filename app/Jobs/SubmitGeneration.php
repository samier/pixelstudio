<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a generation to Pixelforge, waiting out rate/concurrency limits and overloads.
 *
 * Every attempt sends the generation's uuid as the Idempotency-Key, so a retry after a timeout can never
 * create (or bill) a second image: Pixelforge just returns the one it already has.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Give up after this many attempts (≈ 10 minutes of waiting with the delays below). */
    public const MAX_ATTEMPTS = 25;

    public int $tries = self::MAX_ATTEMPTS + 1;

    public function __construct(public readonly Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, GenerationSync $sync): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (a webhook can arrive before our request returns), or already given up on.
        if ($generation === null || $generation->provider_id !== null || $generation->status->isFinal()) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $object = $pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => [
                    'generation_uuid' => $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
            ], idempotencyKey: $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $sync);

            return;
        }

        $sync->apply($generation, $object);
    }

    /** The job crashed in a way we didn't anticipate: we can't be sure Pixelforge didn't accept it. */
    public function failed(?Throwable $e): void
    {
        app(GenerationSync::class)->failLocally($this->generation, Generation::SUBMISSION_UNCONFIRMED,
            'We could not confirm the generation with Pixelforge. You have not been charged.');
    }

    private function handleError(Generation $generation, PixelforgeException $e, GenerationSync $sync): void
    {
        $transient = $e->isRetryable() || $e->isOutcomeUnknown();

        if (! $transient) {
            // Rejected outright: Pixelforge created nothing and billed nothing.
            $sync->failLocally($generation, $e->errorCode, $this->rejectionMessage($e));

            return;
        }

        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $e->isOutcomeUnknown()
                ? $sync->failLocally($generation, Generation::SUBMISSION_UNCONFIRMED,
                    'Pixelforge did not respond after several attempts. You have not been charged.')
                : $sync->failLocally($generation, $e->errorCode,
                    'Pixelforge stayed too busy to accept the generation. You have not been charged; please try again later.');

            return;
        }

        // Honour Retry-After, and back off further each time (capped so a queued user isn't left waiting long).
        $delay = max($e->retryAfter ?? 1, min(30, 2 ** $this->attempts()));

        $generation->update(['status_detail' => match ($e->errorCode) {
            'concurrency_limit_exceeded' => 'Waiting for a free slot at Pixelforge',
            'rate_limit_exceeded' => 'Pixelforge rate limit reached, retrying shortly',
            'overloaded' => 'Pixelforge is overloaded, retrying shortly',
            default => 'Could not reach Pixelforge, retrying',
        }]);

        $this->release($delay);
    }

    private function rejectionMessage(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You have not been charged.',
            'daily_limit_exceeded' => 'The daily generation quota has been used up. It resets at midnight UTC. You have not been charged.',
            'invalid_api_key' => 'Image generation is unavailable right now (our Pixelforge key was rejected). You have not been charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$e->getMessage().' You have not been charged.',
            default => 'Pixelforge could not accept the generation ('.$e->errorCode.'). You have not been charged.',
        };
    }
}
