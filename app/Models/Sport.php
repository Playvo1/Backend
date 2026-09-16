<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sport extends Model
{
    /**
     * The time slots created for this sport.
     */
    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class, 'sport_id');
    }

    /**
     * The venue-sport pivot records for this sport.
     */
    public function venueSports(): HasMany
    {
        return $this->hasMany(VenueSport::class, 'sport_id');
    }

    /**
     * The venues that offer this sport.
     */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'venue_sports', 'sport_id', 'venue_id');
    }

    /**
     * The assistant queries that were parsed to this sport.
     */
    public function assistantQueries(): HasMany
    {
        return $this->hasMany(AssistantQuery::class, 'parsed_sport_id');
    }
}
