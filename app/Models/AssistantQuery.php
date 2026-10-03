<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booking-assistant question and what it was understood as (ERD ASSISTANT_QUERY),
 * logged so suggestion accuracy can be measured against the SRS target.
 */
class AssistantQuery extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'query_text',
        'parsed_sport_id',
        'parsed_date',
        'parsed_hour',
        'suggested_venue_id',
    ];

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
