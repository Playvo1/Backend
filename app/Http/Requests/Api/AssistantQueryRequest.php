<?php

namespace App\Http\Requests\Api;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a player's natural-language question to the booking assistant (US-4.5).
 */
class AssistantQueryRequest extends FormRequest
{
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
                'required',
                'string',
                'max:500',
                // The text is logged to ASSISTANT_QUERY; MySQL refuses invalid UTF-8.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                        $fail('The :attribute must be valid UTF-8 text.');
                    }
                },
            ],
        ];
    }
}
