<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bookable hour range at a venue for one sport (ERD TIME_SLOT).
 */
class TimeSlot extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_price' => 'float',
        ];
    }

    /**
     * A slot that is reserved (pending payment) or booked can no longer be edited or removed (US-3.3).
     */
    public function hasActiveBooking(): bool
    {
        return $this->status !== 'available'
            || $this->bookings()->whereIn('status', ['pending_payment', 'confirmed'])->exists();
    }

    /**
     * Slots players may book: the venue is live (active and not removed).
     */
    public function scopeBookable(Builder $query): void
    {
        $query->whereHas('venue', fn (Builder $venue) => $venue->where('status', 'active'));
    }

    /**
     * Whether another slot of the venue (one physical court, any sport) already covers part
     * of this hour range. Times are compared as zero-padded HH:mm:ss strings, which orders
     * correctly on SQLite and MySQL.
     */
    public static function overlapsExisting(int $venueId, string $date, string $start, string $end, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('venue_id', $venueId)
            ->whereDate('slot_date', $date)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
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
     * The latest booking made for this time slot, if any.
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class, 'time_slot_id')->latestOfMany();
    }

    /**
     * Every booking made for this slot; earlier ones are cancelled.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'time_slot_id');
    }
}
