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
                Rule::in(['day', 'week', 'month', 'last_month']),
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

        // Determine the selected period and its previous comparison period.
        if ($period === 'day') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();

            $previousStart = $start->copy()->subDay();
            $previousEnd = $end->copy()->subDay();

        } elseif ($period === 'week') {
            // Week runs from Saturday through Friday.
            $start = $now->copy()
                ->startOfWeek(Carbon::SATURDAY)
                ->startOfDay();

            $end = $start->copy()->addDays(6)->endOfDay();

            $previousStart = $start->copy()->subWeek();
            $previousEnd = $end->copy()->subWeek();

        } elseif ($period === 'last_month') {
            $start = $now->copy()
                ->subMonthNoOverflow()
                ->startOfMonth();

            $end = $start->copy()->endOfMonth();

            $previousStart = $start->copy()
                ->subMonthNoOverflow()
                ->startOfMonth();

            $previousEnd = $previousStart->copy()->endOfMonth();

        } else {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfMonth();

            $previousStart = $start->copy()
                ->subMonthNoOverflow()
                ->startOfMonth();

            $previousEnd = $previousStart->copy()->endOfMonth();
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
                    ? round(
                        ($occupiedSlots / $bookableSlots) * 100,
                        2
                    )
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

        // Bookings overview: bookings created each day, Saturday to Friday.
        $weekStart = $now->copy()
            ->startOfWeek(Carbon::SATURDAY)
            ->startOfDay();

        $weekEnd = $weekStart->copy()
            ->addDays(6)
            ->endOfDay();

        $weekBookings = Booking::whereIn(
            'time_slot_id',
            TimeSlot::where('venue_id', $venue->id)->select('id')
        )
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->get(['created_at']);

        $bookingCountsByDate = $weekBookings
            ->groupBy(
                fn ($booking) => Carbon::parse(
                    $booking->created_at
                )->toDateString()
            )
            ->map(fn ($items) => $items->count());

        $dayNames = [
            'Saturday',
            'Sunday',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
        ];

        $bookingsOverview = collect($dayNames)
            ->map(function ($day, $index) use (
                $weekStart,
                $bookingCountsByDate
            ) {
                $date = $weekStart->copy()
                    ->addDays($index)
                    ->toDateString();

                return [
                    'day' => $day,
                    'date' => $date,
                    'bookings' => (int) $bookingCountsByDate->get(
                        $date,
                        0
                    ),
                ];
            })
            ->values()
            ->all();

        // Revenue chart: confirmed booking revenue for each month of this year.
        $yearStart = $now->copy()->startOfYear();
        $yearEnd = $now->copy()->endOfYear();

        $yearBookings = Booking::whereIn(
            'time_slot_id',
            TimeSlot::where('venue_id', $venue->id)->select('id')
        )
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [
                $yearStart->copy()->startOfDay(),
                $yearEnd->copy()->endOfDay(),
            ])
            ->get(['created_at', 'total_price']);

        $revenueByMonth = $yearBookings
            ->groupBy(
                fn ($booking) => Carbon::parse(
                    $booking->created_at
                )->month
            )
            ->map(
                fn ($items) => (float) $items->sum('total_price')
            );

        $monthNames = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        $revenueChart = collect($monthNames)
            ->map(function ($monthName, $monthNumber) use (
                $revenueByMonth
            ) {
                return [
                    'month' => $monthNumber,
                    'label' => $monthName,
                    'revenue' => (float) $revenueByMonth->get(
                        $monthNumber,
                        0
                    ),
                ];
            })
            ->values()
            ->all();

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

                'bookings_overview' => [
                    'from' => $weekStart->toDateString(),
                    'to' => $weekEnd->toDateString(),
                    'items' => $bookingsOverview,
                ],

                'revenue_chart' => [
                    'year' => $now->year,
                    'currency' => 'ILS',
                    'items' => $revenueChart,
                ],
            ],
            'message' => 'Owner analytics retrieved successfully.',
            'errors' => null,
        ]);
    }
}
