<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for missed webhooks: polls Pixelforge for generations we haven't heard about for a while.
 * Runs every minute from the scheduler.
 */
class SyncPendingGenerations extends Command
{
    protected $signature = 'pixelforge:sync {--limit=20 : Max generations to poll per run (they count towards the 60/min rate limit)}';

    protected $description = 'Poll Pixelforge for in-flight generations whose webhooks may have been missed';

    public function handle(PixelforgeClient $pixelforge, GenerationSync $sync): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(45))
            ->orderByRaw('last_synced_at is not null, last_synced_at') // never-polled first
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $sync->apply($generation, $pixelforge->getGeneration($generation->provider_id));
            } catch (PixelforgeException $e) {
                if ($e->errorCode === 'rate_limit_exceeded') {
                    $this->warn('Rate limited by Pixelforge; continuing next run.');
                    break;
                }

                Log::warning('Polling Pixelforge failed', ['generation' => $generation->id, 'error' => $e->getMessage()]);
                $generation->forceFill(['last_synced_at' => now()])->save();
            }
        }

        $this->info("Polled {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
