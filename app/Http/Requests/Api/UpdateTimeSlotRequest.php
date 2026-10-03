<?php

namespace App\Http\Requests\Api;

use App\Models\TimeSlot;
use App\Support\LocalClock;
use Carbon\Carbon;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Validates an edit to an existing time slot (US-3.3). Any of date, hours and price may
 * be sent; the resulting schedule is checked against the slot's current values so a
 * partial edit can't produce an inverted range or move the slot into the past.
 *
 * A slot that is held or booked is refused with 409 before any validation, so the
 * owner always gets the story's message. Overlaps are checked by TimeSlotScheduler.
 */
class UpdateTimeSlotRequest extends FormRequest
{
    public const BOOKED_MESSAGE = 'This slot already has a booking and cannot be changed.';

    public function authorize(): Response
    {
        $venue = $this->timeSlot()->venue ?? throw new NotFoundHttpException;
        $ownership = Gate::inspect('manage', $venue);

        if ($ownership->allowed() && $this->timeSlot()->hasActiveBooking()) {
            throw new ConflictHttpException(self::BOOKED_MESSAGE);
        }

        return $ownership;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slot_date' => 'sometimes|required|date_format:Y-m-d',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'hourly_price' => 'sometimes|required|numeric|min:0|max:99999999',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['slot_date', 'start_time', 'end_time'])) {
                    return;
                }

                [$date, $start, $end] = $this->resultingSchedule();

                if ($end <= $start) {
                    $validator->errors()->add('end_time', 'The end time must be after the start time.');

                    return;
                }

                LocalClock::addPastSlotError($validator->errors(), $date, $start);
            },
        ];
    }

    /**
     * The slot's date and HH:mm:ss hour range after applying this request.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function resultingSchedule(): array
    {
        $slot = $this->timeSlot();

        return [
            $this->input('slot_date', Carbon::parse($slot->slot_date)->toDateString()),
            $this->has('start_time') ? $this->input('start_time').':00' : Carbon::parse($slot->start_time)->format('H:i:s'),
            $this->has('end_time') ? $this->input('end_time').':00' : Carbon::parse($slot->end_time)->format('H:i:s'),
        ];
    }

    private function timeSlot(): TimeSlot
    {
        return $this->route('timeSlot');
    }
}
