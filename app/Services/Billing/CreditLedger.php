<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger. users.balance_nanos is the running sum of a user's entries and is only
 * changed here, in the same transaction as the entry that explains it.
 *
 * Lifecycle of a generation's money:
 *   hold (−max estimate) when submitted → hold_release (+same) and, if it succeeded, charge (−actual cost).
 * Generation entries are unique per (generation, type) in the database, so replaying any step is a no-op.
 */
class CreditLedger
{
    /** Atomically reserve the hold for a new generation. Returns false if the user can't afford it. */
    public function hold(Generation $generation): bool
    {
        return DB::transaction(function () use ($generation) {
            // A single conditional UPDATE: two concurrent requests can't both spend the same balance.
            $reserved = User::query()
                ->whereKey($generation->user_id)
                ->where('balance_nanos', '>=', $generation->hold_nanos)
                ->decrement('balance_nanos', $generation->hold_nanos);

            if ($reserved === 0) {
                return false;
            }

            $this->insertEntry($generation->user_id, CreditTransaction::HOLD, -$generation->hold_nanos,
                'Reserved for generation', $generation->id);

            return true;
        });
    }

    /** Release the hold; free of charge. Used for every failed generation. */
    public function releaseHold(Generation $generation): void
    {
        $this->record($generation->user_id, CreditTransaction::HOLD_RELEASE, $generation->hold_nanos,
            'Reservation released', $generation);
    }

    /** Release the hold and charge the actual cost. */
    public function settle(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $this->releaseHold($generation);
            $this->record($generation->user_id, CreditTransaction::CHARGE, -$generation->charged_nanos,
                'Image generation', $generation);
        });
    }

    public function credit(User $user, string $type, int $amountNanos, string $description): void
    {
        $this->record($user->id, $type, $amountNanos, $description);
    }

    /** Record an entry and move the balance with it. Returns false if this generation entry already exists. */
    private function record(int $userId, string $type, int $amountNanos, string $description, ?Generation $generation = null): bool
    {
        return DB::transaction(function () use ($userId, $type, $amountNanos, $description, $generation) {
            $inserted = $this->insertEntry($userId, $type, $amountNanos, $description, $generation?->id);

            if ($inserted) {
                User::query()->whereKey($userId)->increment('balance_nanos', $amountNanos);
            }

            return $inserted;
        });
    }

    private function insertEntry(int $userId, string $type, int $amountNanos, string $description, ?int $generationId): bool
    {
        // ON CONFLICT DO NOTHING (rather than catching an exception) keeps the surrounding transaction usable.
        return CreditTransaction::query()->insertOrIgnore([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nanos' => $amountNanos,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
