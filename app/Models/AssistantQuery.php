<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantQuery extends Model
{
    /**
     * The user who submitted this query.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The sport parsed from this query, if any.
     */
    public function parsedSport(): BelongsTo
    {
        return $this->belongsTo(Sport::class, 'parsed_sport_id');
    }

    /**
     * The venue suggested for this query, if any.
     */
    public function suggestedVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'suggested_venue_id');
    }
}
