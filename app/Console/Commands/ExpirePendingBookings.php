<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\TimeSlot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description =
        'Cancel expired bookings that have not uploaded a payment receipt';

    public function handle(): int
    {
        $bookingIds = Booking::where('status', 'pending_payment')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->pluck('id');

        foreach ($bookingIds as $bookingId) {
            DB::transaction(function () use ($bookingId) {
                $booking = Booking::whereKey($bookingId)
                    ->lockForUpdate()
                    ->first();

                if (
                    !$booking ||
                    $booking->status !== 'pending_payment' ||
                    !$booking->hold_expires_at ||
                    $booking->hold_expires_at->isFuture()
                ) {
                    return;
                }

                // Keep the booking if a receipt was uploaded.
                if ($booking->paymentReceipts()->exists()) {
                    return;
                }

                $timeSlot = TimeSlot::whereKey($booking->time_slot_id)
                    ->lockForUpdate()
                    ->first();

                // Preserve the booking history.
                $booking->update([
                    'status' => 'cancelled',
                ]);

                // Release the slot only if it is still blocked.
                if ($timeSlot && $timeSlot->status === 'blocked') {
                    $timeSlot->update([
                        'status' => 'available',
                    ]);
                }
            });
        }

        $this->info('Expired bookings processed successfully.');

        return self::SUCCESS;
    }
}
