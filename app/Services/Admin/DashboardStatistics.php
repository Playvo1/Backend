<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide numbers for the admin dashboard (US-5.1). Everything is aggregated per
 * request so the cards move as soon as bookings come in. Cancelled bookings never
 * count; "today" and "this month" are local-time ranges on bookings.created_at.
 */
class DashboardStatistics
{
    public const TOP_LIMIT = 5;

    private const ACTIVE_BOOKING_STATUSES = ['pending_payment', 'confirmed'];

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'total_users' => User::count(),
            'total_venues' => Venue::count(),
            'active_venues' => Venue::where('status', 'active')->count(),
            'bookings_today' => $this->bookingsBetween(LocalClock::now()->startOfDay(), LocalClock::now()->endOfDay()),
            'bookings_this_month' => $this->bookingsBetween(LocalClock::now()->startOfMonth(), LocalClock::now()->endOfMonth()),
            'pending_receipts' => PaymentReceipt::where('status', 'pending')->count(),
            'most_booked_venues' => $this->mostBookedVenues(),
            'most_active_cities' => $this->mostActiveCities(),
        ];
    }

    private function bookingsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return Booking::query()
            ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->count();
    }

    /**
     * Removed (soft-deleted) venues still count: their bookings happened.
     *
     * @return list<array{venue_id: int, name_ar: string, name_en: string, bookings_count: int}>
     */
    private function mostBookedVenues(): array
    {
        return $this->activeBookingsByVenue()
            ->groupBy('venues.id', 'venues.name_ar', 'venues.name_en')
            ->select('venues.id as venue_id', 'venues.name_ar', 'venues.name_en')
            ->selectRaw('COUNT(bookings.id) as bookings_count')
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
    }

    /**
     * @return list<array{city_id: int, name_ar: string, name_en: string, bookings_count: int}>
     */
    private function mostActiveCities(): array
    {
        return $this->activeBookingsByVenue()
            ->join('cities', 'cities.id', '=', 'venues.city_id')
            ->groupBy('cities.id', 'cities.name_ar', 'cities.name_en')
            ->select('cities.id as city_id', 'cities.name_ar', 'cities.name_en')
            ->selectRaw('COUNT(bookings.id) as bookings_count')
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
    }

    private function activeBookingsByVenue(): Builder
    {
        return DB::table('bookings')
            ->join('time_slots', 'time_slots.id', '=', 'bookings.time_slot_id')
            ->join('venues', 'venues.id', '=', 'time_slots.venue_id')
            ->whereIn('bookings.status', self::ACTIVE_BOOKING_STATUSES);
    }
}
