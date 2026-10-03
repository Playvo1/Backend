<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A payment transfer receipt a player uploads for a booking (ERD PAYMENT_RECEIPT),
 * reviewed by an admin before the booking is confirmed.
 */
class PaymentReceipt extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The storage disk and path of the receipt image. New receipts store a path on the
     * private "local" disk; ones uploaded before that change hold a public /storage URL.
     *
     * @return array{0: string, 1: string}
     */
    public function imageLocation(): array
    {
        if (Str::startsWith($this->image_url, ['http://', 'https://'])) {
            return ['public', Str::after($this->image_url, '/storage/')];
        }

        return ['local', $this->image_url];
    }

    /**
     * The booking this payment receipt was submitted for.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * The admin user who verified this payment receipt.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
