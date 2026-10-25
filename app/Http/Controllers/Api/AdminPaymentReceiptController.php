<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PaymentReceipt;
use App\Models\Notification;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminPaymentReceiptController extends Controller
{
    /**
     * Get payment receipts filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $receipts = PaymentReceipt::with([
            'booking.timeSlot.venue',
            'booking.captain',
        ])
            ->where('status', $status)
            ->orderBy('created_at', 'asc')
            ->get();

        return ApiResponse::send(
            true,
            200,
            'Payment receipts retrieved successfully',
            $receipts
        );
    }

    /**
     * Verify a payment receipt and confirm its booking.
     */
    public function verify(
        int $id,
        FirebaseNotificationService $firebase
    ): JsonResponse {
        return DB::transaction(function () use ($id, $firebase) {
            $receipt = PaymentReceipt::with([
                'booking.timeSlot.venue.owner',
                'booking.captain',
            ])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($receipt->status !== 'pending') {
                return ApiResponse::send(
                    false,
                    422,
                    'This receipt is no longer pending.',
                    null
                );
            }

            $alreadyUsed = PaymentReceipt::where(
                'receipt_hash',
                $receipt->receipt_hash
            )
                ->where('id', '!=', $receipt->id)
                ->where('status', 'verified')
                ->exists();

            if ($alreadyUsed) {
                return ApiResponse::send(
                    false,
                    422,
                    'This receipt has already been used for another booking.',
                    null
                );
            }

            $booking = $receipt->booking;

            if (!$booking || !$booking->timeSlot) {
                return ApiResponse::send(
                    false,
                    422,
                    'The booking or time slot was not found.',
                    null
                );
            }

            $timeSlot = $booking->timeSlot;

            if ($booking->status !== 'pending_payment') {
                return ApiResponse::send(
                    false,
                    422,
                    'This booking is no longer awaiting payment.',
                    null
                );
            }

            if ($timeSlot->status !== 'available') {
                return ApiResponse::send(
                    false,
                    422,
                    'This time slot is no longer available.',
                    null
                );
            }

            $player = $booking->captain;

            if (!$player) {
                return ApiResponse::send(
                    false,
                    422,
                    'The booking player was not found.',
                    null
                );
            }

            $receipt->update([
                'status' => 'verified',
                'verified_by' => Auth::id(),
                'verified_at' => now(),
            ]);

            $booking->update([
                'status' => 'confirmed',
            ]);

            $timeSlot->update([
                'status' => 'booked',
            ]);

            $venue = $timeSlot->venue;
            $owner = $venue?->owner;

            // Save notification for the venue owner (dashboard).
            if ($owner) {
                Notification::create([
                    'user_id' => $owner->id,
                    'booking_id' => $booking->id,
                    'type' => 'booking_confirmed_for_owner',
                    'title' => 'تم تأكيد حجز على ملعبك',
                    'body' => 'وافق الأدمن على إيصال الدفع وتم تأكيد الحجز رقم '
                        . $booking->id
                        . ' على ملعبك'
                        . ($venue?->name ? ': ' . $venue->name : '.'),
                    'is_read' => false,
                    'sent_at' => now(),
                ]);
            }

            // Save notification for the player (dashboard/app).
            $title = 'تم تأكيد الحجز';
            $body = 'تمت الموافقة على إيصال الدفع الخاص بك وتأكيد حجزك بنجاح.';

            $notification = Notification::create([
                'user_id' => $player->id,
                'booking_id' => $booking->id,
                'type' => 'booking_confirmed',
                'title' => $title,
                'body' => $body,
                'is_read' => false,
                'sent_at' => now(),
            ]);

            // Send Firebase notification to the player's mobile app.
            $deviceTokens = $player->deviceTokens()->pluck('fcm_token');

            foreach ($deviceTokens as $token) {
                try {
                    $firebase->sendToToken(
                        $token,
                        $title,
                        $body,
                        [
                            'type' => 'booking_confirmed',
                            'booking_id' => (string) $booking->id,
                            'notification_id' => (string) $notification->id,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('FCM notification failed', [
                        'user_id' => $player->id,
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return ApiResponse::send(
                true,
                200,
                'Payment receipt verified and booking confirmed.',
                [
                    'booking_id' => $booking->id,
                    'venue' => $venue?->name,
                    'date' => $timeSlot->date,
                    'time_slot' => [
                        'start_time' => $timeSlot->start_time,
                        'end_time' => $timeSlot->end_time,
                    ],
                ]
            );
        });
    }

    /**
     * Reject a payment receipt and notify the player only.
     */
    public function reject(
        Request $request,
        int $id,
        FirebaseNotificationService $firebase
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($id, $validated) {
            $receipt = PaymentReceipt::with([
                'booking.timeSlot',
                'booking.captain',
            ])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($receipt->status !== 'pending') {
                return [
                    'error' => ApiResponse::send(
                        false,
                        422,
                        'This receipt is no longer pending.',
                        null
                    ),
                ];
            }

            $booking = $receipt->booking;

            if (!$booking || !$booking->timeSlot) {
                return [
                    'error' => ApiResponse::send(
                        false,
                        422,
                        'The booking or time slot was not found.',
                        null
                    ),
                ];
            }

            $receipt->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
            ]);

            $booking->update([
                'status' => 'cancelled',
            ]);

            $booking->timeSlot->update([
                'status' => 'available',
            ]);

            $player = $booking->captain;

            $notification = null;

            if ($player) {
                $title = 'تم رفض إيصال الدفع';
                $body = 'تم رفض إيصال الدفع الخاص بحجزك. السبب: '
                    . $validated['reason'];

                $notification = Notification::create([
                    'user_id' => $player->id,
                    'booking_id' => $booking->id,
                    'type' => 'payment_receipt_rejected',
                    'title' => $title,
                    'body' => $body,
                    'is_read' => false,
                    'sent_at' => now(),
                ]);
            }

            return [
                'receipt' => $receipt,
                'booking' => $booking,
                'player' => $player,
                'notification' => $notification,
            ];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        // Send Firebase only to the player's mobile app.
        $player = $result['player'];
        $notification = $result['notification'];

        if ($player && $notification) {
            $deviceTokens = $player->deviceTokens()->pluck('fcm_token');

            foreach ($deviceTokens as $token) {
                try {
                    $firebase->sendToToken(
                        $token,
                        $notification->title,
                        $notification->body,
                        [
                            'type' => 'payment_receipt_rejected',
                            'booking_id' => (string) $result['booking']->id,
                            'notification_id' => (string) $notification->id,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('FCM rejection notification failed', [
                        'user_id' => $player->id,
                        'booking_id' => $result['booking']->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ApiResponse::send(
            true,
            200,
            'Payment receipt rejected.',
            [
                'receipt_id' => $result['receipt']->id,
                'booking_id' => $result['booking']->id,
                'reason' => $result['receipt']->rejection_reason,
            ]
        );
    }
}
