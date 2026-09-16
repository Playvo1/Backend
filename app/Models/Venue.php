<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
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
}
