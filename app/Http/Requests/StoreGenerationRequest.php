<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    // Mirrors Pixelforge's accepted values (see docs, "Create a generation").
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Ignore empty reference rows left in the form.
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url) => is_string($url) && trim($url) !== '',
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most '.self::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.distinct' => 'That reference image is listed twice.',
        ];
    }
}
