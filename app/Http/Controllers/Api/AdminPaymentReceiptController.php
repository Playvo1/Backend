<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PaymentReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AdminPaymentReceiptController extends Controller
{
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

    public function verify(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {

            $receipt = PaymentReceipt::with([
                'booking.timeSlot.venue',
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

            $alreadyUsed = PaymentReceipt::where('receipt_hash', $receipt->receipt_hash)
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

            $receipt->update([
                'status' => 'verified',
                'verified_by' => Auth::id(),
                'verified_at' => now(),
            ]);

            $booking->update([
                'status' => 'confirmed',
            ]);
            $booking->timeSlot->update([
                'status' => 'booked',
            ]);

            return ApiResponse::send(
                true,
                200,
                'Payment receipt verified and booking confirmed.',
                [
                    'booking_id' => $booking->id,
                    'venue' => $booking->timeSlot?->venue?->name,
                    'date' => $booking->timeSlot?->date,
                    'time_slot' => [
                        'start_time' => $booking->timeSlot?->start_time,
                        'end_time' => $booking->timeSlot?->end_time,
                    ],
                ]
            );
        });
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $receipt = PaymentReceipt::findOrFail($id);

        if ($receipt->status !== 'pending') {
            return ApiResponse::send(
                false,
                422,
                'This receipt is no longer pending.',
                null
            );
        }

        $receipt->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
        ]);
        $booking = $receipt->booking;

        $booking->update([
            'status' => 'cancelled',
        ]);

        $booking->timeSlot->update([
            'status' => 'available',
        ]);

        return ApiResponse::send(
            true,
            200,
            'Payment receipt rejected.',
            [
                'receipt_id' => $receipt->id,
                'reason' => $receipt->rejection_reason,
            ]
        );
    }
}
