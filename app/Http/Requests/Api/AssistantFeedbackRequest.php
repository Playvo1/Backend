<?php

namespace App\Http\Requests\Api;

use App\Models\AssistantFeedback;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the assistant's "rate your experience" form. Whose conversation_id it is is
 * checked by the controller (403/404, like POST /assistant/query).
 */
class AssistantFeedbackRequest extends FormRequest
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
            // numeric too, so a JSON true (which the integer rule reads as 1) is refused.
            'rating' => ['required', 'numeric', 'integer', 'between:1,5'],
            'liked_aspects' => ['nullable', 'array', 'max:'.count(AssistantFeedback::ASPECTS)],
            'liked_aspects.*' => ['string', 'distinct', Rule::in(array_keys(AssistantFeedback::ASPECTS))],
            'comment' => [
                'nullable',
                'string',
                'max:500',
                // Stored as text; MySQL refuses invalid UTF-8.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                        $fail('The :attribute must be valid UTF-8 text.');
                    }
                },
            ],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
