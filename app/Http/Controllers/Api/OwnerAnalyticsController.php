<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Owner analytics. Every booking is attributed to its slot's slot_date (a local date), and
 * period boundaries use the players' local date. "Bookings" counts confirmed and
 * pending_payment bookings (cancelled ones are reported separately); revenue sums
 * total_price of confirmed bookings. Aggregation is done in SQL with portable expressions.
 */
class OwnerAnalyticsController extends Controller
{
    // Booking statuses counted as bookings in totals, daily, peak hours and the week overview.
    private const COUNTED_STATUSES = ['confirmed', 'pending_payment'];

    private const DAY_NAMES = [
        'Saturday',
        'Sunday',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => [
                'sometimes',
                Rule::in([
                    'day',
                    'week',
                    'month',
                    'last_month',
                    'year',
                ]),
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
        $now = LocalClock::now();

        // Determine the selected period and its previous comparison period.
        if ($period === 'day') {
            $start = $now->startOfDay();
            $end = $now->endOfDay();

            $previousStart = $start->subDay();
            $previousEnd = $end->subDay();

        } elseif ($period === 'week') {
            // Week runs from Saturday through Friday.
            $start = $now->startOfWeek(Carbon::SATURDAY)->startOfDay();
            $end = $start->addDays(6)->endOfDay();

            $previousStart = $start->subWeek();
            $previousEnd = $end->subWeek();

        } elseif ($period === 'last_month') {
            $start = $now->subMonthNoOverflow()->startOfMonth();
            $end = $start->endOfMonth();

            $previousStart = $start->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $previousStart->endOfMonth();

        } elseif ($period === 'year') {
            $start = $now->startOfYear();
            $end = $now->endOfYear();

            $previousStart = $start->subYear()->startOfYear();
            $previousEnd = $start->subYear()->endOfYear();

        } else {
            $start = $now->startOfMonth();
            $end = $now->endOfMonth();

            $previousStart = $start->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $previousStart->endOfMonth();
        }

        $current = $this->periodData($venue, $start, $end);
        $previous = $this->periodData($venue, $previousStart, $previousEnd);

        $currentStats = $this->stats($current);
        $previousStats = $this->stats($previous);

        $change = function ($currentValue, $previousValue) {
            if ($previousValue == 0) {
                return $currentValue > 0 ? null : 0;
            }

            return round(
                (($currentValue - $previousValue) / abs($previousValue)) * 100,
                2
            );
        };

        // Weekly bookings overview: Saturday through Friday of the current local week.
        $weekStart = $now->startOfWeek(Carbon::SATURDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $weekCounts = $this->bookingsByDate($this->bookingRows($venue, $weekStart, $weekEnd));

        $bookingsOverview = collect(self::DAY_NAMES)
            ->map(function ($day, $index) use ($weekStart, $weekCounts) {
                $date = $weekStart->addDays($index)->toDateString();

                return [
                    'day' => $day,
                    'date' => $date,
                    'bookings' => (int) ($weekCounts[$date]['bookings'] ?? 0),
                ];
            })
            ->values()
            ->all();

        // Revenue chart: confirmed booking revenue for each month of this local year.
        $yearRows = $period === 'year'
            ? $current['bookings']
            : $this->bookingRows($venue, $now->startOfYear(), $now->endOfYear());

        $revenueByMonth = $yearRows
            ->groupBy(fn (array $row) => (int) substr($row['slot_date'], 5, 2))
            ->map(fn (Collection $rows) => round($rows->sum('revenue'), 2));

        $revenueChart = collect(range(1, 12))
            ->map(fn (int $month) => [
                'month' => $month,
                'label' => CarbonImmutable::create(2000, $month, 1)->format('F'),
                'revenue' => (float) $revenueByMonth->get($month, 0),
            ])
            ->values()
            ->all();

        $peakHours = $this->peakHours($current);
        $peak = collect($peakHours)
            ->filter(fn (array $item) => $item['bookings'] > 0)
            ->sortBy([['bookings', 'desc'], ['hour', 'asc']])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'type' => $period,
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                ],

                'bookings' => [
                    'total' => $currentStats['total_bookings'],
                    'confirmed' => $currentStats['confirmed_bookings'],
                    'pending' => $currentStats['pending_bookings'],
                    'cancelled' => $currentStats['cancelled_bookings'],
                    'change_percent' => $change(
                        $currentStats['total_bookings'],
                        $previousStats['total_bookings']
                    ),
                ],

                'revenue' => [
                    'total' => $currentStats['revenue'],
                    'change_percent' => $change(
                        $currentStats['revenue'],
                        $previousStats['revenue']
                    ),
                ],

                'occupancy' => [
                    'rate' => $currentStats['occupancy_rate'],
                    'bookable_slots' => $currentStats['bookable_slots'],
                    'occupied_slots' => $currentStats['occupied_slots'],
                    'change_percentage_points' => round(
                        $currentStats['occupancy_rate']
                        - $previousStats['occupancy_rate'],
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

                'daily' => $this->daily($current, $start, $end),

                'peak_hours' => $peakHours,
                'peak_hour' => $peak['hour'] ?? null,
            ],
            'message' => 'Owner analytics retrieved successfully.',
            'errors' => null,
        ]);
    }

    /**
     * Aggregated bookings and slots of the venue for slots dated within [from, to].
     *
     * @return array{bookings: Collection, slots: Collection}
     */
    private function periodData(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'bookings' => $this->bookingRows($venue, $from, $to),
            'slots' => $this->slotRows($venue, $from, $to),
        ];
    }

    /**
     * Bookings grouped by slot date and slot start hour (one SQL query, portable GROUP BY).
     * Each row: slot_date (Y-m-d), hour (HH:00), counted, confirmed, pending, cancelled, revenue.
     */
    private function bookingRows(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $hour = 'SUBSTR(time_slots.start_time, 1, 2)';
        [$confirmed, $pending, $cancelled] = ['confirmed', 'pending_payment', 'cancelled'];

        return DB::table('bookings')
            ->join('time_slots', 'time_slots.id', '=', 'bookings.time_slot_id')
            ->where('time_slots.venue_id', $venue->id)
            ->whereBetween('time_slots.slot_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('time_slots.slot_date as slot_date')
            ->selectRaw("{$hour} as slot_hour")
            ->selectRaw('SUM(CASE WHEN bookings.status IN (?, ?) THEN 1 ELSE 0 END) as counted', self::COUNTED_STATUSES)
            ->selectRaw('SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END) as confirmed', [$confirmed])
            ->selectRaw('SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END) as pending', [$pending])
            ->selectRaw('SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END) as cancelled', [$cancelled])
            ->selectRaw('SUM(CASE WHEN bookings.status = ? THEN bookings.total_price ELSE 0 END) as revenue', [$confirmed])
            ->groupBy('time_slots.slot_date')
            ->groupByRaw($hour)
            ->get()
            ->map(fn ($row) => [
                'slot_date' => substr((string) $row->slot_date, 0, 10),
                'hour' => $row->slot_hour . ':00',
                'counted' => (int) $row->counted,
                'confirmed' => (int) $row->confirmed,
                'pending' => (int) $row->pending,
                'cancelled' => (int) $row->cancelled,
                'revenue' => (float) $row->revenue,
            ]);
    }

    /**
     * Slot counts grouped by start hour and status. Each row: hour (HH:00), status, total.
     */
    private function slotRows(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $hour = 'SUBSTR(start_time, 1, 2)';

        return DB::table('time_slots')
            ->where('venue_id', $venue->id)
            ->whereBetween('slot_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$hour} as slot_hour")
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as total')
            ->groupByRaw($hour)
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'hour' => $row->slot_hour . ':00',
                'status' => $row->status,
                'total' => (int) $row->total,
            ]);
    }

    private function stats(array $data): array
    {
        $bookings = $data['bookings'];
        $slots = $data['slots'];

        // A slot held by a pending booking (blocked) is occupied, like a booked one.
        $bookableSlots = (int) $slots->whereIn('status', ['available', 'booked', 'blocked'])->sum('total');
        $occupiedSlots = (int) $slots->whereIn('status', ['booked', 'blocked'])->sum('total');

        return [
            'total_bookings' => (int) $bookings->sum('counted'),
            'confirmed_bookings' => (int) $bookings->sum('confirmed'),
            'pending_bookings' => (int) $bookings->sum('pending'),
            'cancelled_bookings' => (int) $bookings->sum('cancelled'),
            'revenue' => round((float) $bookings->sum('revenue'), 2),
            'bookable_slots' => $bookableSlots,
            'occupied_slots' => $occupiedSlots,
            'occupancy_rate' => $bookableSlots > 0
                ? round(($occupiedSlots / $bookableSlots) * 100, 2)
                : 0,
        ];
    }

    /**
     * @return array<string, array{bookings: int, revenue: float}> keyed by Y-m-d
     */
    private function bookingsByDate(Collection $rows): array
    {
        return $rows
            ->groupBy('slot_date')
            ->map(fn (Collection $items) => [
                'bookings' => (int) $items->sum('counted'),
                'revenue' => round((float) $items->sum('revenue'), 2),
            ])
            ->all();
    }

    /**
     * One entry per day of the period, days without bookings included.
     */
    private function daily(array $data, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDate = $this->bookingsByDate($data['bookings']);

        return collect(CarbonPeriod::create($from->startOfDay(), '1 day', $to->startOfDay()))
            ->map(function ($day) use ($byDate) {
                $date = $day->toDateString();

                return [
                    'date' => $date,
                    'day' => $day->format('l'),
                    'bookings' => (int) ($byDate[$date]['bookings'] ?? 0),
                    'revenue' => (float) ($byDate[$date]['revenue'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Bookings per slot start hour, for every hour that has a slot in the period.
     * A 23:00–00:00 slot is 23:00.
     */
    private function peakHours(array $data): array
    {
        $counts = $data['bookings']
            ->groupBy('hour')
            ->map(fn (Collection $rows) => (int) $rows->sum('counted'));

        return $data['slots']
            ->pluck('hour')
            ->unique()
            ->sort()
            ->map(fn (string $hour) => [
                'hour' => $hour,
                'bookings' => (int) $counts->get($hour, 0),
            ])
            ->values()
            ->all();
    }
}
