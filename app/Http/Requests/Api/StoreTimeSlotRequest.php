<?php

namespace App\Http\Requests\Api;

use App\Models\Venue;
use App\Support\LocalClock;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Validates a new time slot for an owned venue (US-3.3): date, hour range and hourly
 * price are required, the venue must offer the sport, and the slot must not have started
 * yet in local time. Overlaps are checked under a venue lock by TimeSlotScheduler.
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
            'slot_date' => 'required|date_format:Y-m-d',
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
                $errors = $validator->errors();

                if (! $errors->hasAny(['slot_date', 'start_time'])) {
                    LocalClock::addPastSlotError($errors, $this->input('slot_date'), $this->input('start_time'));
                }

                if (! $errors->has('sport_id') && ! $this->venue()->sports()->whereKey($this->integer('sport_id'))->exists()) {
                    $errors->add('sport_id', 'This venue does not offer the selected sport.');
                }
            },
        ];
    }

    private function venue(): Venue
    {
        return $this->route('venue');
    }
}
