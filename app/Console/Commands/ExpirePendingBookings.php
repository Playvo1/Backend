<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Cancel pending bookings that have not uploaded a payment receipt';

    public function handle(): int
    {
        $bookings = Booking::where('status', 'pending_payment')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get();

        foreach ($bookings as $booking) {
            DB::transaction(function () use ($booking) {

                $booking->refresh();

                if ($booking->status !== 'pending_payment') {
                    return;
                }

                if ($booking->hold_expires_at && $booking->hold_expires_at->isFuture()) {
                    return;
                }

                // A rejected receipt doesn't stop the clock; the player has to upload a new one.
                $hasReceipt = $booking->paymentReceipts()->where('status', '!=', 'rejected')->exists();

                if ($hasReceipt) {
                    return;
                }

                $timeSlot = $booking->timeSlot()
                    ->lockForUpdate()
                    ->first();

                if ($timeSlot) {
                    $timeSlot->update([
                        'status' => 'available',
                    ]);
                }

               $booking->update(['status' => 'cancelled']);
            });
        }

        $this->info('Expired bookings processed successfully.');

        return self::SUCCESS;
    }
}
