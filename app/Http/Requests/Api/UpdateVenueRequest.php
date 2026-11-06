<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update of a venue by its owner: only the fields sent are changed.
 * Fields outside these rules (status, owner_id, city_id, ratings, ...) are ignored.
 */
class UpdateVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],

            'address_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'address_en' => ['sometimes', 'required', 'string', 'max:255'],

            'area_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area_en' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Coordinates are sent together and cannot be cleared.
            'latitude' => ['required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['required_with:latitude', 'numeric', 'between:-180,180'],

            'length_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'width_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],

            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'surface_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'surface_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'team_format_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'team_format_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_en' => ['sometimes', 'nullable', 'string', 'max:255'],

            'morning_hourly_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'evening_hourly_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],

            // A venue keeps at least one sport, or it drops out of the sport search and the assistant.
            'sport_ids' => ['sometimes', 'array', 'min:1'],
            'sport_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:sports,id',
            ],
        ];
    }
}
