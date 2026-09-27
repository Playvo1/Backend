<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload an admin submits to provision a venue owner account (US-1.4).
 * Role/authorization is enforced by route middleware, not here.
 */
class CreateVenueOwnerRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already in use.',
        ];
    }
}
