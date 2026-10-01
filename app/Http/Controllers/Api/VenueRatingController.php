<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVenueRatingRequest;
use App\Models\Booking;
use App\Models\VenueRating;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VenueRatingController extends Controller
{
    public function store(
        StoreVenueRatingRequest $request,
        int $id
    ): JsonResponse {
        $user = Auth::user();

        $booking = Booking::with('timeSlot.venue')
            ->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Booking not found.',
                'errors' => null,
            ], 404);
        }

        // Make sure the booking belongs to the authenticated player.
        if ((int) $booking->captain_user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You can only rate your own booking.',
                'errors' => null,
            ], 403);
        }

        // Only confirmed bookings can be rated.
        if ($booking->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Only confirmed bookings can be rated.',
                'errors' => null,
            ], 422);
        }

        if (!$booking->timeSlot || !$booking->timeSlot->venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Booking time slot or venue not found.',
                'errors' => null,
            ], 422);
        }

        // The rating is allowed only after the slot has ended.
        $slotEnd = Carbon::parse(
            $booking->timeSlot->slot_date . ' ' .
            $booking->timeSlot->end_time
        );

        if (now()->lt($slotEnd)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You can rate the venue only after the booking has ended.',
                'errors' => null,
            ], 422);
        }

        // One rating per booking.
        if ($booking->rating()->exists()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This booking has already been rated.',
                'errors' => null,
            ], 422);
        }

        $rating = DB::transaction(function () use ($request, $booking) {
            $rating = VenueRating::create([
                'venue_id' => $booking->timeSlot->venue_id,
                'booking_id' => $booking->id,
                'rating' => $request->integer('rating'),
                'comment' => $request->input('comment'),
            ]);

            $averageRating = VenueRating::where(
                'venue_id',
                $booking->timeSlot->venue_id
            )->avg('rating');

            $booking->timeSlot->venue->update([
                'avg_rating' => round($averageRating, 2),
            ]);

            return $rating;
        });

        return response()->json([
            'success' => true,
            'data' => $rating,
            'message' => 'Rating submitted successfully.',
            'errors' => null,
        ], 201);
    }
}
