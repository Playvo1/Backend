<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingRequest;
use App\Services\CloudinaryImageService;
use App\Models\Booking;
use App\Models\TimeSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Requests\Api\PaymentReceiptUploadRequest;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Storage;
use App\Models\Notification;
use App\Models\User;
class BookingController extends Controller
{

public function store(BookingRequest $request): JsonResponse
{
    $user = $request->user();

    // Only players can create bookings.
    if (!$user || !$user->hasRole('player')) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Only players can create bookings.',
            'errors' => null,
        ], 403);
    }

    $data = $request->validated();

    $timeSlot = TimeSlot::with('venue')->find($data['time_slot_id']);

    if (!$timeSlot) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Time slot not found.',
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

    $result = DB::transaction(function () use ($data, $user) {
        $timeSlot = TimeSlot::with('venue')
            ->where('id', $data['time_slot_id'])
            ->lockForUpdate()
            ->first();

        if (!$timeSlot || $timeSlot->status !== 'available') {
            return ['error' => 'unavailable'];
        }

        // Prevent booking a venue owned by the current user.
        if (
            $timeSlot->venue &&
            (int) $timeSlot->venue->owner_id === (int) $user->id
        ) {
            return ['error' => 'own_venue'];
        }

        $booking = Booking::create([
            'time_slot_id' => $timeSlot->id,
            'captain_user_id' => $user->id,
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

        return ['booking' => $booking];
    });

    if (isset($result['error'])) {
        $isOwnVenue = $result['error'] === 'own_venue';

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $isOwnVenue
                ? 'You cannot book your own venue.'
                : 'This time slot is no longer available.',
            'errors' => null,
        ], $isOwnVenue ? 403 : 409);
    }

    $booking = $result['booking'];

    return response()->json([
        'success' => true,
        'data' => [
            'booking_id' => $booking->id,
            'total_price' => $booking->total_price,
            'status' => $booking->status,
        ],
        'message' => 'Booking created successfully.',
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

        $uploaded = CloudinaryImageService::upload(
            $file,
            'playvo/payment-receipts'
        );

        try {
            $receipt = PaymentReceipt::create([
                'booking_id' => $booking->id,
                'image_url' => $uploaded['url'],
                'receipt_hash' => $hash,
                'status' => 'pending',
                'uploaded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            CloudinaryImageService::delete($uploaded['url']);
            throw $e;
        }


        $playerName = $request->user()->name;

        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'booking_id' => $booking->id,
                'type' => 'payment_receipt_uploaded',
                'title' => 'إيصال دفع جديد',
                'body' => "قام اللاعب {$playerName} برفع إيصال دفع للحجز رقم {$booking->id}.",
                'is_read' => false,
                'sent_at' => now(),
            ]);
        }


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
    public function history(\Illuminate\Http\Request $request): JsonResponse
{
    $query = Booking::query()
        ->with([
            'timeSlot.venue',
            'timeSlot.sport',
            'rating',
        ])
        ->where('captain_user_id', $request->user()->id)
        ->whereHas('timeSlot', function ($query) {
            $query->whereRaw(
                "TIMESTAMP(slot_date, end_time) < ?",
                [now()]
            );
        });

    if ($request->filled('sport_id')) {
        $query->whereHas('timeSlot', function ($query) use ($request) {
            $query->where('sport_id', $request->sport_id);
        });
    }

    $bookings = $query
        ->latest('id')
        ->paginate(10);

    return response()->json([
        'success' => true,
        'data' => $bookings,
        'message' => 'Booking history retrieved successfully.',
        'errors' => null,
    ]);
}
public function share(string $shareToken): JsonResponse
{
    $booking = Booking::with([
        'timeSlot.venue',
        'timeSlot.sport',
    ])
        ->where('share_token', $shareToken)
        ->first();

    if (!$booking) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Booking share link not found.',
            'errors' => null,
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'booking_id' => $booking->id,
            'status' => $booking->status,
            'venue' => [
                'id' => $booking->timeSlot->venue->id,
                'name' => $booking->timeSlot->venue->name,
                'name_ar' => $booking->timeSlot->venue->name_ar,
            ],
            'sport' => [
                'id' => $booking->timeSlot->sport->id,
                'name' => $booking->timeSlot->sport->name,
            ],
            'date' => $booking->timeSlot->slot_date,
            'start_time' => $booking->timeSlot->start_time,
            'end_time' => $booking->timeSlot->end_time,
        ],
        'message' => 'Booking details retrieved successfully.',
        'errors' => null,
    ]);
}
}
