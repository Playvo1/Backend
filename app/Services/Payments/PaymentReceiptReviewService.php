<?php

namespace App\Services\Payments;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An admin's verify/reject decision on an uploaded payment receipt (US-3.6).
 *
 * Verifying confirms the booking and books its slot; rejecting keeps the booking
 * pending and gives the player a fresh payment window to upload a corrected receipt.
 * Both notify the player and write an AUDIT_LOG row. Rows are locked and re-checked
 * inside the transaction so two admins can't review the same receipt twice.
 */
class PaymentReceiptReviewService
{
    public const REUPLOAD_WINDOW_MINUTES = 10;

    public function __construct(private readonly ReceiptReuseCheck $reuseCheck) {}

    public function verify(PaymentReceipt $receipt, User $admin): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $admin) {
            [$receipt, $booking] = $this->lockForReview($receipt);

            if ($this->reuseCheck->usedForAnotherBooking($receipt)) {
                throw new ConflictHttpException('This receipt has already been used for another booking.');
            }

            $receipt->update(['status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
            $booking->update(['status' => 'confirmed']);
            $booking->timeSlot->update(['status' => 'booked']);

            $this->notifyPlayer($booking, 'booking_confirmed', 'Booking confirmed', $this->confirmationText($booking));
            AuditLog::record($admin->id, 'receipt_verified', 'PAYMENT_RECEIPT', $receipt->id);

            return $receipt;
        });
    }

    public function reject(PaymentReceipt $receipt, User $admin, string $reason): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $admin, $reason) {
            [$receipt, $booking] = $this->lockForReview($receipt);

            $receipt->update(['status' => 'rejected', 'rejection_reason' => $reason]);
            $booking->update(['hold_expires_at' => now()->addMinutes(self::REUPLOAD_WINDOW_MINUTES)]);

            $this->notifyPlayer(
                $booking,
                'receipt_rejected',
                'Payment receipt rejected',
                "Your payment receipt for booking #{$booking->id} was rejected: {$reason}. Please upload a corrected receipt.",
            );
            AuditLog::record($admin->id, 'receipt_rejected', 'PAYMENT_RECEIPT', $receipt->id);

            return $receipt;
        });
    }

    /**
     * Re-reads the receipt and its booking under a row lock and makes sure both are still reviewable.
     *
     * @return array{0: PaymentReceipt, 1: Booking}
     */
    private function lockForReview(PaymentReceipt $receipt): array
    {
        $receipt = PaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
        $booking = Booking::query()->with('timeSlot.venue')->lockForUpdate()->findOrFail($receipt->booking_id);

        if ($receipt->status !== 'pending') {
            throw new ConflictHttpException('This receipt has already been reviewed.');
        }

        if ($booking->status !== 'pending_payment') {
            throw new ConflictHttpException('This booking is no longer awaiting payment.');
        }

        return [$receipt, $booking];
    }

    private function confirmationText(Booking $booking): string
    {
        $slot = $booking->timeSlot;

        return sprintf(
            'Booking #%d at %s on %s, %s-%s is confirmed.',
            $booking->id,
            $slot->venue?->name_en,
            Carbon::parse($slot->slot_date)->toDateString(),
            Carbon::parse($slot->start_time)->format('H:i'),
            Carbon::parse($slot->end_time)->format('H:i'),
        );
    }

    private function notifyPlayer(Booking $booking, string $type, string $title, string $body): void
    {
        Notification::create([
            'user_id' => $booking->captain_user_id,
            'booking_id' => $booking->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }
}
