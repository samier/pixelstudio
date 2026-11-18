<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const GRANT = 'grant';

    public const PURCHASE = 'purchase';

    public const HOLD = 'hold';

    public const HOLD_RELEASE = 'hold_release';

    public const CHARGE = 'charge';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
