<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a venue owner's profile update (US-3.1). Every field is optional so the
 * dashboard can save one section at a time; owner_id and status are never accepted.
 * Ownership is checked by VenuePolicy in the controller.
 */
class UpdateOwnerVenueRequest extends FormRequest
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
        // A live venue must keep its secondary information (US-3.1): it can be changed, not cleared.
        $profile = $this->route('venue')?->status === 'active' ? 'sometimes|required' : 'sometimes|nullable';

        return [
            'city_id' => 'sometimes|required|integer|exists:cities,id',
            'name_ar' => 'sometimes|required|string|max:255',
            'name_en' => 'sometimes|required|string|max:255',
            'address_ar' => "{$profile}|string|max:1000",
            'address_en' => "{$profile}|string|max:1000",
            'area_ar' => "{$profile}|string|max:255",
            'area_en' => "{$profile}|string|max:255",
            'latitude' => "{$profile}|numeric|between:-90,90",
            'longitude' => "{$profile}|numeric|between:-180,180",
            'length_m' => "{$profile}|numeric|gt:0|max:999999",
            'width_m' => "{$profile}|numeric|gt:0|max:999999",
            'min_hourly_price' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'sport_ids' => 'sometimes|array|min:1',
            'sport_ids.*' => 'integer|distinct|exists:sports,id',
        ];
    }
}
