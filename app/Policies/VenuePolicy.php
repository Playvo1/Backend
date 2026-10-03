<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Auth\Access\Response;

/**
 * Venue owners may only manage (edit, add photos/slots to, read bookings of) their own venues.
 * The venue_owner role itself is enforced by route middleware.
 */
class VenuePolicy
{
    public function manage(User $user, Venue $venue): Response
    {
        return (int) $venue->owner_id === (int) $user->id
            ? Response::allow()
            : Response::deny('You do not own this venue.');
    }
}
