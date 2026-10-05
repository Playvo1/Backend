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

        $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'status' => ['nullable', 'in:pending_payment,confirmed,cancelled'],
        ]);

        $venue = Venue::find($request->venue_id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Venue not found.',
                'errors' => null,
            ], 404);
        }

        if ((int) $venue->owner_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You are not authorized to view these bookings.',
                'errors' => null,
            ], 403);
        }

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
