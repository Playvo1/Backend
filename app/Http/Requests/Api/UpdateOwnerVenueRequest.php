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
        return [
            'city_id' => 'sometimes|required|integer|exists:cities,id',
            'name_ar' => 'sometimes|required|string|max:255',
            'name_en' => 'sometimes|required|string|max:255',
            'address_ar' => 'sometimes|nullable|string|max:1000',
            'address_en' => 'sometimes|nullable|string|max:1000',
            'area_ar' => 'sometimes|nullable|string|max:255',
            'area_en' => 'sometimes|nullable|string|max:255',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'length_m' => 'sometimes|nullable|numeric|gt:0|max:999999',
            'width_m' => 'sometimes|nullable|numeric|gt:0|max:999999',
            'min_hourly_price' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'sport_ids' => 'sometimes|array|min:1',
            'sport_ids.*' => 'integer|distinct|exists:sports,id',
        ];
    }
}
