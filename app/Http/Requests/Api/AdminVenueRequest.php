<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use App\Models\Venue;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin "Add venue": the venue, its owner (an existing venue owner via owner_id, or a new
 * account via owner_name/owner_email/owner_password) and up to 6 photos. All checks,
 * including the owner checks, run here so nothing is created when the request is invalid.
 */
class AdminVenueRequest extends FormRequest
{
    public const MAX_IMAGES = 6;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A single sport_id (the form's select) is accepted as sport_ids.
        if ($this->filled('sport_id') && !$this->has('sport_ids')) {
            $this->merge(['sport_ids' => [$this->input('sport_id')]]);
        }
    }

    public function rules(): array
    {
        return [
            // Existing owner, or a new owner account; exactly one of the two. Empty values
            // (as a multipart form sends them) count as absent.
            'owner_id' => [
                'nullable',
                'required_without_all:owner_name,owner_email',
                'prohibits:owner_name,owner_email,owner_password,owner_phone',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $user = User::find($value);

                    if ($user && !$user->hasRole('venue_owner')) {
                        $fail('The selected user is not a venue owner.');

                        return;
                    }

                    if ($user && $user->status !== 'active') {
                        $fail('The selected venue owner account is not active.');

                        return;
                    }

                    // venues.owner_id is unique, soft-deleted venues included.
                    if ($user && Venue::withTrashed()->where('owner_id', $user->id)->exists()) {
                        $fail('The selected owner already has a venue.');
                    }
                },
            ],
            'owner_name' => ['nullable', 'required_without:owner_id', 'string', 'max:255'],
            'owner_email' => ['nullable', 'required_without:owner_id', 'email', 'max:255', 'unique:users,email'],
            'owner_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'owner_phone' => ['nullable', 'required_without:owner_id', 'string', 'max:20'],

            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],

            'city_id' => ['required', 'integer', 'exists:cities,id'],

            'address_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],

            'area_ar' => ['nullable', 'string', 'max:255'],
            'area_en' => ['nullable', 'string', 'max:255'],

            'description_ar' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'surface_ar' => ['nullable', 'string', 'max:255'],
            'surface_en' => ['nullable', 'string', 'max:255'],
            'team_format_ar' => ['nullable', 'string', 'max:255'],
            'team_format_en' => ['nullable', 'string', 'max:255'],
            'capacity_ar' => ['nullable', 'string', 'max:255'],
            'capacity_en' => ['nullable', 'string', 'max:255'],

            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],

            'length_m' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'width_m' => ['nullable', 'numeric', 'min:0', 'max:1000'],

            'min_hourly_price' => ['nullable', 'numeric', 'min:0'],
            'morning_hourly_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'evening_hourly_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],

            'sport_id' => ['sometimes', 'nullable', 'integer', 'exists:sports,id'],
            'sport_ids' => ['sometimes', 'array'],
            'sport_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:sports,id',
            ],

            'images' => ['sometimes', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_id.required_without_all' => 'Choose an existing venue owner or enter the new owner\'s details.',
            'owner_id.prohibits' => 'Send either owner_id or the new owner\'s details, not both.',
            'owner_email.unique' => 'This email address is already in use.',
            'images.max' => 'A venue can have at most '.self::MAX_IMAGES.' photos.',
        ];
    }
}
