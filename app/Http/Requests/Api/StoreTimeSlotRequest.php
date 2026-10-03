<?php

namespace App\Http\Requests\Api;

use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Validates a new time slot for an owned venue (US-3.3): date, hour range and hourly
 * price are required, the venue must offer the sport, and the range must not overlap
 * another slot of the same venue and sport.
 */
class StoreTimeSlotRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->venue());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sport_id' => 'required|integer|exists:sports,id',
            'slot_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'hourly_price' => 'required|numeric|min:0|max:99999999',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->venue()->sports()->whereKey($this->integer('sport_id'))->exists()) {
                    $validator->errors()->add('sport_id', 'This venue does not offer the selected sport.');

                    return;
                }

                $overlaps = TimeSlot::overlapsExisting(
                    $this->venue()->id,
                    $this->integer('sport_id'),
                    $this->input('slot_date'),
                    $this->input('start_time').':00',
                    $this->input('end_time').':00',
                );

                if ($overlaps) {
                    $validator->errors()->add('start_time', 'This slot overlaps an existing slot for the same sport.');
                }
            },
        ];
    }

    private function venue(): Venue
    {
        return $this->route('venue');
    }
}
