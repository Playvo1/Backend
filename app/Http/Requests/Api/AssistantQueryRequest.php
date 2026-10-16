<?php

namespace App\Http\Requests\Api;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a player's natural-language question to the booking assistant (US-4.5), and the
 * optional assistant v2 fields: the conversation to continue, a quick reply the player
 * tapped (option_id, then query_text may be left out) and the player's location for "near me".
 */
class AssistantQueryRequest extends FormRequest
{
    /**
     * Quick replies: the fixed ones, or a city ("city_<CITY.id>").
     */
    public const OPTION_PATTERN = '/^(use_location|pick_area|all_venues|cheapest|best_rated|new_search|city_\d{1,9})$/D';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'query_text' => [
                'required_without:option_id',
                'nullable',
                'string',
                'max:500',
                $this->validUtf8(),
            ],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'option_id' => ['nullable', 'string', 'max:40', 'regex:'.self::OPTION_PATTERN],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * The text is logged to ASSISTANT_QUERY; MySQL refuses invalid UTF-8.
     */
    private function validUtf8(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('The :attribute must be valid UTF-8 text.');
            }
        };
    }
}
