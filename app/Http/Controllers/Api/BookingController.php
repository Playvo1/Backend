<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Models\Booking;
use App\Models\TimeSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Requests\PaymentReceiptUploadRequest;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Storage;
class BookingController extends Controller
{
    public function store(BookingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $timeSlot = TimeSlot::find($data['time_slot_id']);

        if (!$timeSlot) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Time slot not found',
                'errors' => null,
            ], 404);
        }

        if ($timeSlot->status !== 'available') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This time slot is no longer available.',
                'errors' => null,
            ], 409);
        }

        $result = DB::transaction(function () use ($data, $request) {
            $timeSlot = TimeSlot::where('id', $data['time_slot_id'])
                ->lockForUpdate()
                ->first();

            if (!$timeSlot || $timeSlot->status !== 'available') {
                return null;
            }

            $booking = Booking::create([
                'time_slot_id' => $timeSlot->id,
                'captain_user_id' => $request->user()->id,
                'captain_name' => $data['captain_name'],
                'captain_role' => $data['captain_role'],
                'captain_phone' => $data['captain_phone'],
                'total_price' => $timeSlot->hourly_price,
                'status' => 'pending_payment',
                'share_token' => Str::random(40),
                'hold_expires_at' => now()->addMinutes(10),
            ]);

            $timeSlot->update([
                'status' => 'blocked',
            ]);

            return $booking;
        });

        if (!$result) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This time slot is no longer available.',
                'errors' => null,
            ], 409);
        }
        return response()->json([
            'success' => true,
            'data' => [
                'booking_id' => $result->id,
                'total_price' => $result->total_price,
                'status' => $result->status,
            ],
            'message' => 'Booking created successfully',
            'errors' => null,
        ], 201);
    }
    public function uploadPaymentReceipt(PaymentReceiptUploadRequest $request,   $id): JsonResponse
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Booking not found.',
                'errors' => null,
            ], 404);
        }
        if ($booking->captain_user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You are not allowed to upload a receipt for this booking.',
                'errors' => null,
            ], 403);
        }
        if ($booking->status !== 'pending_payment') {
    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'This booking is not awaiting payment.',
        'errors' => null,
    ], 409);
}
if (PaymentReceipt::where('booking_id', $booking->id)->exists()) {
    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'A payment receipt has already been uploaded for this booking.',
        'errors' => null,
    ], 409);
        }
        $file = $request->file('receipt');

        $hash = hash_file('sha256', $file->getRealPath());
        if (PaymentReceipt::where('receipt_hash', $hash)->exists()) {
    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'This receipt has already been used for another booking.',
        'errors' => null,
    ], 422);
}
        $path = $file->store('payment-receipts', 'public');
        $receipt = PaymentReceipt::create([
            'booking_id' => $booking->id,
            'image_url' => asset('storage/' . $path),
            'receipt_hash' => $hash,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'receipt_id' => $receipt->id,
                'booking_id' => $receipt->booking_id,
                'image_url' => $receipt->image_url,
                'status' => $receipt->status,
                'uploaded_at' => $receipt->uploaded_at,
            ],
            'message' => 'Payment receipt uploaded successfully. Awaiting verification.',
            'errors' => null,
        ], 201);
    }
}
