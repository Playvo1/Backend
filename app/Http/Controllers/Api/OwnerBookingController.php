<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerBookingController extends Controller
{
    /**
     * List bookings for the authenticated venue owner.
     * Supports searching by player name or booking ID,
     * filtering by date and booking status.
     */
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

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => [
                'nullable',
                'in:pending_payment,confirmed,cancelled',
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Booking::query()
            ->whereHas('timeSlot', function ($q) use ($venue) {
                $q->where('venue_id', $venue->id);
            })
            ->with([
                'timeSlot.venue',
                'timeSlot.sport',
                'captain',
                'paymentReceipts' => function ($q) {
                    $q->latest('id');
                },
            ]);

        // Search by booking ID or player name.
        if (!empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($q) use ($search) {
                if (ctype_digit($search)) {
                    $q->where('id', (int) $search)
                        ->orWhereHas('captain', function ($playerQuery) use ($search) {
                            $playerQuery->where(
                                'name',
                                'like',
                                "%{$search}%"
                            );
                        });
                } else {
                    $q->whereHas('captain', function ($playerQuery) use ($search) {
                        $playerQuery->where(
                            'name',
                            'like',
                            "%{$search}%"
                        );
                    });
                }
            });
        }

        // Filter by the booking date.
        if (!empty($validated['date'])) {
            $query->whereHas('timeSlot', function ($q) use ($validated) {
                $q->whereDate('slot_date', $validated['date']);
            });
        }

        // Filter by booking status.
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $bookings = $query
            ->latest('id')
            ->paginate($validated['per_page'] ?? 10);

        // Weekly booking statistics.
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
                    'income' => (float) $weeklyIncome,
                ],
            ],
            'message' => 'Owner bookings retrieved successfully.',
            'errors' => null,
        ]);
    }

    /**
     * Show one booking, only if it belongs to this owner's venue.
     */
    public function show(Request $request, int $id): JsonResponse
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

        $venue = Venue::where('owner_id', $user->id)->first();

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No venue found for this owner.',
                'errors' => null,
            ], 404);
        }

        $booking = Booking::query()
            ->where('id', $id)
            ->whereHas('timeSlot', function ($q) use ($venue) {
                $q->where('venue_id', $venue->id);
            })
            ->with([
                'captain',
                'timeSlot.venue',
                'timeSlot.sport',
                'paymentReceipts' => function ($q) {
                    $q->latest('id');
                },
            ])
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Booking not found for this venue.',
                'errors' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => 'Booking details retrieved successfully.',
            'errors' => null,
        ]);
    }
}
