<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    use HasFactory;

    /**
     * Failure we recorded ourselves because we never got an answer from Pixelforge. Pixelforge may still have
     * accepted the request, so this is the only failure a later provider event is allowed to override.
     */
    public const SUBMISSION_UNCONFIRMED = 'submission_unconfirmed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min_nanos' => 'integer',
            'estimated_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'hold_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Settled for good: nothing Pixelforge says later can change it. */
    public function isSettled(): bool
    {
        return $this->status->isFinal() && $this->error_code !== self::SUBMISSION_UNCONFIRMED;
    }
}
