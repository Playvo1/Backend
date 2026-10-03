<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an admin adding a venue (US-3.5): the owner account, city and bilingual name
 * are required; the secondary profile fields are optional because the owner completes
 * them afterwards (US-3.1). Admin role is enforced by route middleware.
 */
class CreateAdminVenueRequest extends FormRequest
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
            'owner_id' => ['required', 'integer', 'exists:users,id', $this->mustBeVenueOwner(...)],
            'city_id' => 'required|integer|exists:cities,id',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'address_ar' => 'nullable|string|max:1000',
            'address_en' => 'nullable|string|max:1000',
            'area_ar' => 'nullable|string|max:255',
            'area_en' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'length_m' => 'nullable|numeric|gt:0|max:999999',
            'width_m' => 'nullable|numeric|gt:0|max:999999',
            'min_hourly_price' => 'nullable|numeric|min:0|max:99999999',
            'sport_ids' => 'nullable|array',
            'sport_ids.*' => 'integer|distinct|exists:sports,id',
        ];
    }

    private function mustBeVenueOwner(string $attribute, mixed $value, Closure $fail): void
    {
        if (! User::find($value)?->hasRole('venue_owner')) {
            $fail('The selected user is not a venue owner.');
        }
    }
}
