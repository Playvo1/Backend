<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\TimeSlot;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OwnerAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => [
                'sometimes',
                Rule::in(['week', 'month', 'year']),
            ],
        ]);

        $user = $request->user();

        $venue = Venue::where('owner_id', $user->id)->first();

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You do not have a venue.',
                'errors' => null,
            ], 404);
        }

        $period = $request->input('period', 'month');

        $now = Carbon::now();

        if ($period === 'week') {
            $start = $now->copy()->startOfWeek();
            $end = $now->copy()->endOfWeek();
            $previousStart = $start->copy()->subWeek();
            $previousEnd = $end->copy()->subWeek();
        } elseif ($period === 'year') {
            $start = $now->copy()->startOfYear();
            $end = $now->copy()->endOfYear();
            $previousStart = $start->copy()->subYear();
            $previousEnd = $end->copy()->subYear();
        } else {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfMonth();
            $previousStart = $start->copy()->subMonth();
            $previousEnd = $end->copy()->subMonth();
        }

        $getStats = function ($from, $to) use ($venue) {
            $slots = TimeSlot::where('venue_id', $venue->id)
                ->whereBetween('slot_date', [
                    $from->toDateString(),
                    $to->toDateString(),
                ]);

            $bookings = Booking::whereIn(
                'time_slot_id',
                (clone $slots)->select('id')
            )->whereBetween('created_at', [
                $from->copy()->startOfDay(),
                $to->copy()->endOfDay(),
            ]);

            $confirmed = (clone $bookings)
                ->where('status', 'confirmed');

            $totalBookings = (clone $bookings)->count();
            $confirmedBookings = (clone $confirmed)->count();

            $revenue = (clone $confirmed)->sum('total_price');

            $bookableSlots = (clone $slots)
                ->whereIn('status', ['available', 'booked'])
                ->count();

            $occupiedSlots = (clone $slots)
                ->where('status', 'booked')
                ->count();

            return [
                'total_bookings' => $totalBookings,
                'confirmed_bookings' => $confirmedBookings,
                'pending_bookings' => (clone $bookings)
                    ->where('status', 'pending_payment')
                    ->count(),
                'cancelled_bookings' => (clone $bookings)
                    ->where('status', 'cancelled')
                    ->count(),
                'revenue' => (float) $revenue,
                'bookable_slots' => $bookableSlots,
                'occupied_slots' => $occupiedSlots,
                'occupancy_rate' => $bookableSlots > 0
                    ? round(($occupiedSlots / $bookableSlots) * 100, 2)
                    : 0,
            ];
        };

        $current = $getStats($start, $end);
        $previous = $getStats($previousStart, $previousEnd);

        $change = function ($currentValue, $previousValue) {
            if ($previousValue == 0) {
                return $currentValue > 0 ? null : 0;
            }

            return round(
                (($currentValue - $previousValue) / abs($previousValue)) * 100,
                2
            );
        };

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'type' => $period,
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                ],
                'bookings' => [
                    'total' => $current['total_bookings'],
                    'confirmed' => $current['confirmed_bookings'],
                    'pending' => $current['pending_bookings'],
                    'cancelled' => $current['cancelled_bookings'],
                    'change_percent' => $change(
                        $current['total_bookings'],
                        $previous['total_bookings']
                    ),
                ],
                'revenue' => [
                    'total' => $current['revenue'],
                    'change_percent' => $change(
                        $current['revenue'],
                        $previous['revenue']
                    ),
                ],
                'occupancy' => [
                    'rate' => $current['occupancy_rate'],
                    'bookable_slots' => $current['bookable_slots'],
                    'occupied_slots' => $current['occupied_slots'],
                    'change_percentage_points' => round(
                        $current['occupancy_rate']
                        - $previous['occupancy_rate'],
                        2
                    ),
                ],
            ],
            'message' => 'Owner analytics retrieved successfully.',
            'errors' => null,
        ]);
    }
}
