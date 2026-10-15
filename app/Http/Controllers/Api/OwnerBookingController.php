<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerBookingController extends Controller
{

public function index(Request $request): JsonResponse
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Unauthenticated.',
            'errors' => null,
        ], 401);
    }

    $validated = $request->validate([
        'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
        'status' => [
            'nullable',
            'in:pending_payment,confirmed,cancelled',
        ],
    ]);

    // Get all venues belonging to the authenticated owner.
    $venuesQuery = Venue::where('owner_id', $user->id);

    // Optionally filter by a specific venue.
    if (!empty($validated['venue_id'])) {
        $venuesQuery->where('id', $validated['venue_id']);
    }

    $venues = $venuesQuery->get();

    if ($venues->isEmpty()) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'No venues found for this owner.',
            'errors' => null,
        ], 404);
    }

    $venueIds = $venues->pluck('id');

    // Get bookings for all the owner's venues.
    $query = Booking::query()
        ->whereHas('timeSlot', function ($q) use ($venueIds) {
            $q->whereIn('venue_id', $venueIds);
        })
        ->with([
            'timeSlot.venue',
            'captain',
        ])
        ->latest();

    if (!empty($validated['status'])) {
        $query->where('status', $validated['status']);
    }

    $bookings = $query->paginate(10);

    // Weekly statistics across all the owner's venues.
    $weekStart = now()->startOfWeek();
    $weekEnd = now()->endOfWeek();

    $weeklyBookingsQuery = Booking::query()
        ->whereHas('timeSlot', function ($q) use ($venueIds) {
            $q->whereIn('venue_id', $venueIds);
        })
        ->whereBetween('created_at', [$weekStart, $weekEnd]);

    $weeklyBookings = (clone $weeklyBookingsQuery)->count();

    $weeklyIncome = (clone $weeklyBookingsQuery)
        ->where('status', 'confirmed')
        ->sum('total_price');

    return response()->json([
        'success' => true,
        'data' => [
            'venues' => $venues,
            'bookings' => $bookings,
            'weekly_stats' => [
                'total_bookings' => $weeklyBookings,
                'income' => $weeklyIncome,
            ],
        ],
        'message' => 'Owner bookings retrieved successfully.',
        'errors' => null,
    ]);
}

}
