<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'status_detail' => $this->status_detail,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            // All money in integer nanodollars; formatted on the client.
            'estimated_min_nanos' => $this->estimated_min_nanos,
            'estimated_nanos' => $this->estimated_nanos,
            'estimated_max_nanos' => $this->estimated_max_nanos,
            'hold_nanos' => $this->hold_nanos,
            'charged_nanos' => $this->charged_nanos,
            'usage' => $this->usage,
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
