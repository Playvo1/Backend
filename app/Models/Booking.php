<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $guarded = [];
    protected $casts = [
    'hold_expires_at' => 'datetime',
];
    /**
     * The time slot this booking reserves.
     */
    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    /**
     * The user who made this booking as captain.
     */
    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }

    /**
     * The payment receipts submitted for this booking.
     */
    public function paymentReceipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class, 'booking_id');
    }

    /**
     * The rating left for this booking.
     */
    public function rating(): HasOne
    {
        return $this->hasOne(VenueRating::class, 'booking_id');
    }

    /**
     * The notifications related to this booking.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'booking_id');
    }
}
