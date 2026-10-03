<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an admin's receipt decision (US-3.6): verified, or rejected with a reason
 * that is shown to the player as-is.
 */
class ReviewPaymentReceiptRequest extends FormRequest
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
            'status' => 'required|in:verified,rejected',
            'rejection_reason' => 'nullable|required_if:status,rejected|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required_if' => 'Please enter the reason for rejecting this receipt.',
        ];
    }
}
