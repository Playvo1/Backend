<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    private const TOP_LIMIT = 5;

    private const ACTIVE_BOOKING_STATUSES = [
        'pending_payment',
        'confirmed',
    ];

    public function index(): JsonResponse
    {
        $totalUsers = User::count();

        $totalVenues = Venue::count();

        $activeVenues = Venue::where('status', 'active')
            ->count();

        $bookingsToday = Booking::whereIn(
            'status',
            self::ACTIVE_BOOKING_STATUSES
        )
            ->whereDate('created_at', today())
            ->count();

        $bookingsThisMonth = Booking::whereIn(
            'status',
            self::ACTIVE_BOOKING_STATUSES
        )
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $pendingReceipts = PaymentReceipt::where('status', 'pending')
            ->count();

        $mostBookedVenues = Booking::query()
            ->join(
                'time_slots',
                'bookings.time_slot_id',
                '=',
                'time_slots.id'
            )
            ->join(
                'venues',
                'time_slots.venue_id',
                '=',
                'venues.id'
            )
            ->whereIn(
                'bookings.status',
                self::ACTIVE_BOOKING_STATUSES
            )
            ->select(
                'venues.id as venue_id',
                'venues.name_ar',
                'venues.name_en'
            )
            ->selectRaw(
                'COUNT(bookings.id) as bookings_count'
            )
            ->groupBy(
                'venues.id',
                'venues.name_ar',
                'venues.name_en'
            )
            ->orderByDesc('bookings_count')
            ->orderBy('venues.id')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'venue_id' => (int) $row->venue_id,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
                'bookings_count' => (int) $row->bookings_count,
            ])
            ->all();

        $mostActiveCities = Booking::query()
            ->join(
                'time_slots',
                'bookings.time_slot_id',
                '=',
                'time_slots.id'
            )
            ->join(
                'venues',
                'time_slots.venue_id',
                '=',
                'venues.id'
            )
            ->join(
                'cities',
                'venues.city_id',
                '=',
                'cities.id'
            )
            ->whereIn(
                'bookings.status',
                self::ACTIVE_BOOKING_STATUSES
            )
            ->select(
                'cities.id as city_id',
                'cities.name_ar',
                'cities.name_en'
            )
            ->selectRaw(
                'COUNT(bookings.id) as bookings_count'
            )
            ->groupBy(
                'cities.id',
                'cities.name_ar',
                'cities.name_en'
            )
            ->orderByDesc('bookings_count')
            ->orderBy('cities.id')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'city_id' => (int) $row->city_id,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
                'bookings_count' => (int) $row->bookings_count,
            ])
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'total_users' => $totalUsers,
                'total_venues' => $totalVenues,
                'active_venues' => $activeVenues,
                'bookings_today' => $bookingsToday,
                'bookings_this_month' => $bookingsThisMonth,
                'pending_receipts' => $pendingReceipts,
                'most_booked_venues' => $mostBookedVenues,
                'most_active_cities' => $mostActiveCities,
            ],
            'message' => 'Dashboard statistics retrieved successfully.',
            'errors' => null,
        ]);
    }
}
