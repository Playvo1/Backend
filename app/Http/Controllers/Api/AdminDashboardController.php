<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalUsers = User::count();

        $bookingsToday = Booking::whereDate('created_at', today())
            ->count();

        $bookingsThisMonth = Booking::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $pendingReceipts = PaymentReceipt::where('status', 'pending')
            ->count();

        $mostBookedVenues = Booking::query()
            ->join('time_slots', 'bookings.time_slot_id', '=', 'time_slots.id')
            ->join('venues', 'time_slots.venue_id', '=', 'venues.id')
            ->whereNull('venues.deleted_at')
            ->select(
                'venues.id',
                'venues.name_ar',
                'venues.name_en',
                DB::raw('COUNT(bookings.id) as bookings_count')
            )
            ->groupBy(
                'venues.id',
                'venues.name_ar',
                'venues.name_en'
            )
            ->orderByDesc('bookings_count')
            ->limit(5)
            ->get();

        $mostActiveCities = Booking::query()
            ->join('time_slots', 'bookings.time_slot_id', '=', 'time_slots.id')
            ->join('venues', 'time_slots.venue_id', '=', 'venues.id')
            ->join('cities', 'venues.city_id', '=', 'cities.id')
            ->select(
                'cities.id',
                'cities.name_ar',
                'cities.name_en',
                DB::raw('COUNT(bookings.id) as bookings_count')
            )
            ->groupBy(
                'cities.id',
                'cities.name_ar',
                'cities.name_en'
            )
            ->orderByDesc('bookings_count')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_users' => $totalUsers,
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
