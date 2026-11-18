<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Pixelforge\CostCalculator;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place where a generation changes state. Every source of truth funnels through here:
 * the create response, webhooks (duplicated, out of order, early) and polling.
 *
 * It's idempotent and order-independent: the state only ever moves forward
 * (queued → processing → succeeded|failed), a settled generation never changes, and the money for it
 * is recorded by the ledger exactly once.
 */
class GenerationSync
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /** @param array<string, mixed> $object a Pixelforge generation object */
    public function apply(Generation $generation, array $object): Generation
    {
        return DB::transaction(function () use ($generation, $object) {
            // Row lock: a webhook and a poll for the same generation are applied one after the other.
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->provider_id !== null && $generation->provider_id !== $object['id']) {
                Log::warning('Pixelforge object does not match generation', [
                    'generation' => $generation->id, 'expected' => $generation->provider_id, 'got' => $object['id'],
                ]);

                return $generation;
            }

            if ($generation->provider_id === null && $generation->status === GenerationStatus::Queued) {
                $generation->status_detail = 'Waiting in the Pixelforge queue'; // Accepted; no longer "sending".
            }

            $generation->provider_id ??= $object['id'];
            $generation->submitted_at ??= now();
            $generation->rates ??= $object['rates'] ?? null;
            $generation->last_synced_at = now();

            if (! empty($object['image_url'])) {
                $generation->image_url = $object['image_url'];
                $generation->image_url_expires_at = self::time($object['image_url_expires_at'] ?? null);
            }

            $incoming = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

            if ($generation->isSettled() || $incoming === null || ! $this->movesForward($generation, $incoming)) {
                $generation->save();

                return $generation;
            }

            $generation->status = $incoming;
            $generation->started_at ??= self::time($object['started_at'] ?? null);
            $generation->error_code = null;
            $generation->error_message = null;

            match ($incoming) {
                GenerationStatus::Queued => $generation->status_detail = 'Waiting in the Pixelforge queue',
                GenerationStatus::Processing => $generation->status_detail = 'Generating your image',
                GenerationStatus::Succeeded => $this->markSucceeded($generation, $object),
                GenerationStatus::Failed => $this->markFailed($generation, $object),
            };

            $generation->save();

            return $generation;
        });
    }

    /**
     * Fail a generation that Pixelforge never accepted (or never confirmed). The hold is released: it's free.
     */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status->isFinal()) {
                return;
            }

            $generation->forceFill([
                'status' => GenerationStatus::Failed,
                'status_detail' => null,
                'error_code' => $code,
                'error_message' => $message,
            ])->save();

            $this->ledger->releaseHold($generation);
        });
    }

    private function movesForward(Generation $generation, GenerationStatus $incoming): bool
    {
        // An unconfirmed submission failure is our guess, not Pixelforge's answer: any real state wins.
        $currentRank = $generation->status->isFinal() ? -1 : $generation->status->rank();

        return $incoming->rank() > $currentRank;
    }

    private function markSucceeded(Generation $generation, array $object): void
    {
        $rates = $object['rates'] ?? $generation->rates;
        $providerCost = CostCalculator::cost($object['usage'], $rates);

        $generation->forceFill([
            'status_detail' => null,
            'rates' => $rates,
            'usage' => $object['usage'],
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => Money::withMarkup($providerCost, config('billing.markup_percent')),
            'completed_at' => self::time($object['completed_at'] ?? null) ?? now(),
        ])->save();

        $this->ledger->settle($generation);
    }

    private function markFailed(Generation $generation, array $object): void
    {
        $generation->forceFill([
            'status_detail' => null,
            'error_code' => $object['error']['code'] ?? 'generation_failed',
            'error_message' => $object['error']['message'] ?? 'The generation failed. You were not charged.',
            'completed_at' => self::time($object['completed_at'] ?? null) ?? now(),
        ])->save();

        $this->ledger->releaseHold($generation);
    }

    private static function time(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso)->utc() : null;
    }
}
