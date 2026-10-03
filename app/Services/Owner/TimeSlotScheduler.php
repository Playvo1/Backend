<?php

namespace App\Services\Owner;

use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and moves time slots (US-3.3) without double-booking the court. A venue is one
 * physical court, so a slot may not overlap any other slot of the venue, whatever the
 * sport. The check runs under a lock on the venue row, so two concurrent requests can't
 * both pass it.
 */
class TimeSlotScheduler
{
    /**
     * @param  array{sport_id: int, slot_date: string, start_time: string, end_time: string, hourly_price: float|int|string}  $slot  times as HH:mm:ss
     */
    public function create(Venue $venue, array $slot): TimeSlot
    {
        return DB::transaction(function () use ($venue, $slot) {
            $this->lockVenue($venue->id);
            $this->ensureFree($venue->id, $slot['slot_date'], $slot['start_time'], $slot['end_time']);

            return $venue->timeSlots()->create([...$slot, 'status' => 'available']);
        });
    }

    /**
     * @param  array{slot_date: string, start_time: string, end_time: string, hourly_price: float|int|string}  $changes  times as HH:mm:ss
     */
    public function update(TimeSlot $timeSlot, array $changes): TimeSlot
    {
        return DB::transaction(function () use ($timeSlot, $changes) {
            $this->lockVenue($timeSlot->venue_id);
            $this->ensureFree($timeSlot->venue_id, $changes['slot_date'], $changes['start_time'], $changes['end_time'], $timeSlot->id);

            $timeSlot->update($changes);

            return $timeSlot;
        });
    }

    private function lockVenue(int $venueId): void
    {
        Venue::query()->whereKey($venueId)->lockForUpdate()->first();
    }

    private function ensureFree(int $venueId, string $date, string $start, string $end, ?int $ignoreId = null): void
    {
        if (TimeSlot::overlapsExisting($venueId, $date, $start, $end, $ignoreId)) {
            throw ValidationException::withMessages([
                'start_time' => 'This slot overlaps an existing slot at this venue.',
            ]);
        }
    }
}
