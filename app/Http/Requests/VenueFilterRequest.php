<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VenueFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'sport_id' => ['nullable', 'integer', 'exists:sports,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'hour' => [
                'nullable',
                'regex:/^(0?[1-9]|1[0-2])(:[0-5][0-9])? ?(AM|PM)$/i',
            ],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
