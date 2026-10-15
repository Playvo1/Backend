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

        // Get the venue belonging to the logged-in owner.
        $venue = Venue::where('owner_id', $user->id)->first();

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No venue found for this owner.',
                'errors' => null,
            ], 404);
        }

        $request->validate([
            'status' => ['nullable', 'in:pending_payment,confirmed,cancelled'],
        ]);

        $query = Booking::query()
            ->whereHas('timeSlot', function ($q) use ($venue) {
                $q->where('venue_id', $venue->id);
            })
            ->with([
                'timeSlot',
                'captain',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->paginate(10);

        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $weeklyBookingsQuery = Booking::query()
            ->whereHas('timeSlot', function ($q) use ($venue) {
                $q->where('venue_id', $venue->id);
            })
            ->whereBetween('created_at', [$weekStart, $weekEnd]);

        $weeklyBookings = (clone $weeklyBookingsQuery)->count();

        $weeklyIncome = (clone $weeklyBookingsQuery)
            ->where('status', 'confirmed')
            ->sum('total_price');

        return response()->json([
            'success' => true,
            'data' => [
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
