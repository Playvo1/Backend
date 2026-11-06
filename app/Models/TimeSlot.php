<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TimeSlot extends Model
{
    protected $guarded = [];

    // Every slot created or retimed through the API lasts exactly one hour; longer play means booking more slots.
    public const DURATION_MINUTES = 60;

    /**
     * Stored end_time values meaning midnight at the end of the slot's day (24:00).
     */
    public const MIDNIGHT_END_TIMES = ['00:00', '00:00:00'];

    /**
     * When the slot ends; a 00:00 end is midnight at the end of slot_date, i.e. the next day.
     */
    public function endsAt(): Carbon
    {
        $end = Carbon::parse(substr((string) $this->slot_date, 0, 10) . ' ' . $this->end_time);

        return $this->endsAtMidnight() ? $end->addDay() : $end;
    }

    public function endsAtMidnight(): bool
    {
        return substr((string) $this->end_time, 0, 5) === '00:00';
    }

    /**
     * Slots that ended before the given moment, using the same rule as endsAt().
     * Portable across MySQL and SQLite (no TIMESTAMP()).
     */
    public function scopeEndedBefore(Builder $query, Carbon $moment): Builder
    {
        $date = $moment->toDateString();
        $time = $moment->format('H:i:s');

        return $query->where(function (Builder $q) use ($date, $time) {
            // Any slot dated before today has ended by now, including one ending at midnight.
            $q->whereDate('slot_date', '<', $date)
                ->orWhere(function (Builder $today) use ($date, $time) {
                    $today->whereDate('slot_date', $date)
                        ->whereNotIn('end_time', self::MIDNIGHT_END_TIMES)
                        ->where('end_time', '<', $time);
                });
        });
    }

    /**
     * Length of the slot in minutes. An end time of 00:00 is midnight at the end of the slot's day.
     */
    public function durationInMinutes(): int
    {
        $minutes = function (string $time): int {
            [$hour, $minute] = array_map('intval', array_slice(explode(':', $time), 0, 2) + [0, 0]);

            return $hour * 60 + $minute;
        };

        $start = $minutes((string) $this->start_time);
        $end = $minutes((string) $this->end_time);

        return ($end === 0 ? 24 * 60 : $end) - $start;
    }

    /**
     * The venue this time slot belongs to.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    /**
     * The sport this time slot is for.
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class, 'sport_id');
    }

    /**
     * The booking made for this time slot, if any.
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class, 'time_slot_id');
    }
}
