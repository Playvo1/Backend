<?php

namespace App\Models;

use App\Support\LocalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bookable sports facility (ERD VENUE), owned by a venue_owner user. Admins remove
 * venues with a soft delete so booking and rating history is preserved (US-3.5).
 */
class Venue extends Model
{
    use SoftDeletes;

    /**
     * Secondary information the owner must complete before the venue can go live (US-3.1).
     */
    public const PROFILE_FIELDS = [
        'address_ar',
        'address_en',
        'area_ar',
        'area_en',
        'latitude',
        'longitude',
        'length_m',
        'width_m',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'owner_id',
        'city_id',
        'name_ar',
        'name_en',
        'address_ar',
        'address_en',
        'area_ar',
        'area_en',
        'latitude',
        'longitude',
        'length_m',
        'width_m',
        'min_hourly_price',
        'status',
    ];

    /**
     * Decimal columns come back as strings from MySQL; the API contract uses numbers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'length_m' => 'float',
            'width_m' => 'float',
            'avg_rating' => 'float',
            'min_hourly_price' => 'float',
        ];
    }

    public function isProfileComplete(): bool
    {
        foreach (self::PROFILE_FIELDS as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a player still holds or has a booking for a slot that hasn't finished yet.
     */
    public function hasUpcomingBookings(): bool
    {
        $now = LocalClock::now();

        return Booking::query()
            ->whereIn('status', ['pending_payment', 'confirmed'])
            ->whereHas('timeSlot', fn (Builder $slot) => $slot
                ->where('venue_id', $this->id)
                ->where(fn (Builder $when) => $when
                    ->whereDate('slot_date', '>', $now->toDateString())
                    ->orWhere(fn (Builder $today) => $today
                        ->whereDate('slot_date', $now->toDateString())
                        ->where('end_time', '>', $now->format('H:i:s')))))
            ->exists();
    }

    /**
     * Not-yet-started slots in $status that no player holds or has booked. Removing a
     * venue blocks its available ones; restoring it releases the blocked ones again.
     */
    public function upcomingFreeSlots(string $status): HasMany
    {
        $now = LocalClock::now();

        return $this->timeSlots()
            ->where('status', $status)
            ->whereDoesntHave('bookings', fn (Builder $booking) => $booking->whereIn('status', ['pending_payment', 'confirmed']))
            ->where(fn (Builder $when) => $when
                ->whereDate('slot_date', '>', $now->toDateString())
                ->orWhere(fn (Builder $today) => $today
                    ->whereDate('slot_date', $now->toDateString())
                    ->where('start_time', '>', $now->format('H:i:s'))));
    }

    /**
     * The user who owns this venue.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The city this venue is located in.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * The time slots available at this venue.
     */
    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class, 'venue_id');
    }

    /**
     * The venue-sport pivot records for this venue.
     */
    public function venueSports(): HasMany
    {
        return $this->hasMany(VenueSport::class, 'venue_id');
    }

    /**
     * The sports offered at this venue.
     */
    public function sports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'venue_sports', 'venue_id', 'sport_id');
    }

    /**
     * The ratings left for this venue.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(VenueRating::class, 'venue_id');
    }

    /**
     * The favorite records referencing this venue.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class, 'venue_id');
    }

    /**
     * The users who have favorited this venue.
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites', 'venue_id', 'user_id');
    }

    /**
     * The assistant queries that suggested this venue.
     */
    public function assistantQueries(): HasMany
    {
        return $this->hasMany(AssistantQuery::class, 'suggested_venue_id');
    }

    /**
     * The venue's photos, in the display order the owner chose (US-3.2).
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
