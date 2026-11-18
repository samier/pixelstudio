<?php

namespace App\Services\Generations;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCreditsException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Price the request, reserve its maximum possible cost from the user's balance, and queue it for Pixelforge.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>}  $input
     *
     * @throws InsufficientCreditsException
     */
    public function create(User $user, array $input): Generation
    {
        $references = array_values($input['reference_images'] ?? []);
        $estimate = $this->pixelforge->pricing()->estimate($input['prompt'], $input['size'], $input['quality'], count($references));
        $markup = config('billing.markup_percent');

        $generation = new Generation([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'prompt' => $input['prompt'],
            'size' => $input['size'],
            'quality' => $input['quality'],
            'reference_images' => $references,
            'status_detail' => 'Sending to Pixelforge',
            'estimated_min_nanos' => Money::withMarkup($estimate['min']['cost'], $markup),
            'estimated_nanos' => Money::withMarkup($estimate['expected']['cost'], $markup),
            'estimated_max_nanos' => Money::withMarkup($estimate['max']['cost'], $markup),
        ]);

        // Reserve the worst case, so a user can never end up owing more than they had.
        $generation->hold_nanos = $generation->estimated_max_nanos;

        DB::transaction(function () use ($generation, $user) {
            $generation->save();

            if (! $this->ledger->hold($generation)) {
                throw new InsufficientCreditsException($generation->hold_nanos, $user->fresh()->balance_nanos);
            }

            SubmitGeneration::dispatch($generation)->afterCommit();
        });

        return $generation;
    }
}
