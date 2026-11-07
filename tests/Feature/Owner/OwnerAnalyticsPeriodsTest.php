<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Period boundaries, comparisons, edge dates, rounding and query cost of the owner analytics.
 */
class OwnerAnalyticsPeriodsTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private User $owner;

    private Venue $venue;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        config(['app.local_timezone' => 'Asia/Gaza']);

        // Wednesday 18 Nov 2026, 12:00 in Gaza (UTC+2).
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', 'UTC'));

        $this->owner = $this->userWithRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner);
        $this->player = $this->userWithRole('player');
    }

    private function slot(string $date, string $start = '18:00', string $status = 'booked', ?Venue $venue = null): TimeSlot
    {
        $hour = (int) substr($start, 0, 2);

        return $this->makeSlot($venue ?? $this->venue, [
            'slot_date' => $date,
            'start_time' => $start.':00',
            'end_time' => $hour === 23 ? '00:00:00' : sprintf('%02d:00:00', $hour + 1),
            'hourly_price' => 50,
            'status' => $status,
        ]);
    }

    private function booking(TimeSlot $slot, string $status = 'confirmed', float $price = 50): Booking
    {
        return Booking::forceCreate([
            'time_slot_id' => $slot->id,
            'captain_user_id' => $this->player->id,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
            'total_price' => $price,
            'status' => $status,
            'share_token' => Str::random(40),
        ]);
    }

    /** n confirmed bookings, each on its own slot, on the given date. */
    private function bookings(string $date, int $count, float $price = 50): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->booking($this->slot($date, sprintf('%02d:00', 8 + $i)), 'confirmed', $price);
        }
    }

    private function analytics(string $query = '')
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/v1/venue_owner/owner/analytics'.$query)->assertOk();
    }

    // ---- Shape ----

    public function test_existing_keys_are_unchanged_and_new_keys_added(): void
    {
        $this->booking($this->slot('2026-11-18'));

        $this->analytics()->assertJsonStructure([
            'success', 'message', 'errors',
            'data' => [
                'period' => ['type', 'from', 'to'],
                'bookings' => ['total', 'confirmed', 'pending', 'cancelled', 'change_percent'],
                'revenue' => ['total', 'change_percent'],
                'occupancy' => ['rate', 'bookable_slots', 'occupied_slots', 'change_percentage_points'],
                'bookings_overview' => ['from', 'to', 'items' => [['day', 'date', 'bookings']]],
                'revenue_chart' => ['year', 'currency', 'items' => [['month', 'label', 'revenue']]],
                'daily' => [['date', 'day', 'bookings', 'revenue']],
                'peak_hours' => [['hour', 'bookings']],
                'peak_hour',
            ],
        ])->assertJsonPath('data.period.type', 'month');
    }

    public function test_unknown_period_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/venue_owner/owner/analytics?period=quarter')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    // ---- Boundaries and comparisons per period ----

    public static function periods(): array
    {
        // [period, from, to, a date inside, a date in the previous period, a date just outside both]
        return [
            'day' => ['day', '2026-11-18', '2026-11-18', '2026-11-18', '2026-11-17', '2026-11-19'],
            'week (Sat-Fri)' => ['week', '2026-11-14', '2026-11-20', '2026-11-20', '2026-11-07', '2026-11-21'],
            'month' => ['month', '2026-11-01', '2026-11-30', '2026-11-30', '2026-10-01', '2026-12-01'],
            'last_month' => ['last_month', '2026-10-01', '2026-10-31', '2026-10-31', '2026-09-30', '2026-11-01'],
            'year' => ['year', '2026-01-01', '2026-12-31', '2026-12-31', '2025-01-01', '2027-01-01'],
        ];
    }

    #[DataProvider('periods')]
    public function test_period_boundaries_and_change_percent(
        string $period,
        string $from,
        string $to,
        string $inside,
        string $previous,
        string $outside,
    ): void {
        $this->bookings($inside, 3, 40);
        $this->bookings($previous, 2, 50);
        $this->bookings($outside, 5);

        $response = $this->analytics("?period={$period}")
            ->assertJsonPath('data.period.from', $from)
            ->assertJsonPath('data.period.to', $to)
            ->assertJsonPath('data.bookings.total', 3)
            ->assertJsonPath('data.bookings.change_percent', 50)
            ->assertJsonPath('data.revenue.total', 120)
            ->assertJsonPath('data.revenue.change_percent', 20);

        $daily = $response->json('data.daily');
        $this->assertSame($from, $daily[0]['date']);
        $this->assertSame($to, end($daily)['date']);
        $this->assertSame(3, array_sum(array_column($daily, 'bookings')));
        $this->assertEquals(120, array_sum(array_column($daily, 'revenue')));
    }

    public function test_change_percent_is_null_when_previous_is_zero_and_zero_when_both_are(): void
    {
        $this->analytics()
            ->assertJsonPath('data.bookings.change_percent', 0)
            ->assertJsonPath('data.revenue.change_percent', 0);

        $this->bookings('2026-11-05', 1);

        $this->analytics()
            ->assertJsonPath('data.bookings.change_percent', null)
            ->assertJsonPath('data.revenue.change_percent', null);
    }

    public function test_change_percent_can_be_negative(): void
    {
        $this->bookings('2026-11-05', 1);
        $this->bookings('2026-10-05', 4);

        $this->analytics()
            ->assertJsonPath('data.bookings.change_percent', -75)
            ->assertJsonPath('data.revenue.change_percent', -75);
    }

    // ---- Month lengths and leap years ----

    public function test_daily_length_follows_the_month(): void
    {
        $this->assertCount(30, $this->analytics('?period=month')->json('data.daily'));
        $this->assertCount(31, $this->analytics('?period=last_month')->json('data.daily'));
        $this->assertCount(7, $this->analytics('?period=week')->json('data.daily'));
        $this->assertCount(1, $this->analytics('?period=day')->json('data.daily'));
        $this->assertCount(365, $this->analytics('?period=year')->json('data.daily'));
    }

    public function test_leap_year_february_and_year(): void
    {
        $this->travelTo(Carbon::parse('2028-02-29 10:00:00', 'UTC'));
        $this->bookings('2028-02-29', 1);

        $month = $this->analytics('?period=month')
            ->assertJsonPath('data.period.to', '2028-02-29')
            ->json('data.daily');
        $this->assertCount(29, $month);
        $this->assertSame(1, $month[28]['bookings']);

        $this->assertCount(366, $this->analytics('?period=year')->json('data.daily'));

        $this->travelTo(Carbon::parse('2028-03-31 10:00:00', 'UTC'));
        $this->analytics('?period=last_month')
            ->assertJsonPath('data.period.from', '2028-02-01')
            ->assertJsonPath('data.period.to', '2028-02-29')
            ->assertJsonPath('data.bookings.total', 1);
    }

    public function test_last_month_on_the_31st_does_not_overflow(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 10:00:00', 'UTC'));

        $this->analytics('?period=last_month')
            ->assertJsonPath('data.period.from', '2026-11-01')
            ->assertJsonPath('data.period.to', '2026-11-30');

        $this->travelTo(Carbon::parse('2026-03-31 10:00:00', 'UTC'));

        $this->analytics('?period=month')
            ->assertJsonPath('data.period.from', '2026-03-01');
    }

    // ---- Local midnight ----

    public function test_just_after_local_midnight_the_new_local_month_and_day_are_used(): void
    {
        // 30 Nov 22:30 UTC is 1 Dec 00:30 in Gaza.
        $this->travelTo(Carbon::parse('2026-11-30 22:30:00', 'UTC'));
        $this->bookings('2026-12-01', 1);
        $this->bookings('2026-11-30', 2);

        $this->analytics('?period=day')
            ->assertJsonPath('data.period.from', '2026-12-01')
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.bookings.change_percent', -50);

        $this->analytics('?period=month')
            ->assertJsonPath('data.period.from', '2026-12-01')
            ->assertJsonPath('data.period.to', '2026-12-31')
            ->assertJsonPath('data.bookings.total', 1);
    }

    public function test_slot_from_23_to_midnight_counts_on_its_own_date_and_hour(): void
    {
        $this->booking($this->slot('2026-11-30', '23:00'));

        $response = $this->analytics()
            ->assertJsonPath('data.peak_hour', '23:00')
            ->assertJsonPath('data.peak_hours.0', ['hour' => '23:00', 'bookings' => 1]);

        $this->assertSame(1, $response->json('data.daily.29.bookings'));
        $this->assertSame('2026-11-30', $response->json('data.daily.29.date'));
    }

    // ---- Peak hours ----

    public function test_peak_hours_only_cover_hours_with_slots_in_the_period(): void
    {
        $this->slot('2026-11-10', '08:00', 'available');
        $this->booking($this->slot('2026-11-10', '20:00'));
        $this->booking($this->slot('2026-11-11', '20:00'));
        // Previous month and another owner's venue are ignored.
        $this->booking($this->slot('2026-10-10', '12:00'));
        $other = $this->makeVenue($this->userWithRole('venue_owner'));
        $this->booking($this->slot('2026-11-10', '15:00', 'booked', $other));

        $this->analytics()
            ->assertJsonPath('data.peak_hours', [
                ['hour' => '08:00', 'bookings' => 0],
                ['hour' => '20:00', 'bookings' => 2],
            ])
            ->assertJsonPath('data.peak_hour', '20:00');
    }

    public function test_peak_hour_is_null_without_bookings(): void
    {
        $this->slot('2026-11-10', '08:00', 'available');

        $this->analytics()
            ->assertJsonPath('data.peak_hours', [['hour' => '08:00', 'bookings' => 0]])
            ->assertJsonPath('data.peak_hour', null);
    }

    // ---- Occupancy ----

    public function test_occupancy_and_its_change_in_percentage_points(): void
    {
        $this->slot('2026-11-10', '08:00', 'available');
        $this->booking($this->slot('2026-11-10', '09:00'));
        $this->booking($this->slot('2026-11-10', '10:00'));
        $this->slot('2026-11-10', '11:00', 'available');
        $this->slot('2026-10-10', '08:00', 'available');
        $this->booking($this->slot('2026-10-10', '09:00'));

        $this->analytics()
            ->assertJsonPath('data.occupancy.rate', 50)
            ->assertJsonPath('data.occupancy.bookable_slots', 4)
            ->assertJsonPath('data.occupancy.occupied_slots', 2)
            ->assertJsonPath('data.occupancy.change_percentage_points', 0);
    }

    // ---- Money ----

    public function test_revenue_is_rounded_to_cents(): void
    {
        $this->booking($this->slot('2026-11-10', '08:00'), 'confirmed', 0.1);
        $this->booking($this->slot('2026-11-10', '09:00'), 'confirmed', 0.2);
        $this->booking($this->slot('2026-11-11', '09:00'), 'confirmed', 33.33);
        $this->booking($this->slot('2026-11-11', '10:00'), 'confirmed', 33.34);

        $response = $this->analytics()->assertJsonPath('data.revenue.total', 66.97);

        $this->assertSame(0.3, $response->json('data.daily.9.revenue'));
        $this->assertSame(66.97, $response->json('data.revenue_chart.items.10.revenue'));
    }

    // ---- Access ----

    public function test_soft_deleted_venue_gets_404(): void
    {
        $this->booking($this->slot('2026-11-10'));
        $this->venue->delete();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/venue_owner/owner/analytics')->assertStatus(404);
    }

    public function test_player_cannot_read_owner_analytics(): void
    {
        Sanctum::actingAs($this->player);
        $this->getJson('/api/v1/venue_owner/owner/analytics')->assertStatus(403);
    }

    // ---- Query cost ----

    public function test_query_count_does_not_grow_with_bookings(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->analytics('?period=year');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->bookings('2026-03-01', 2);
        $count(); // warm-up: the role lookup is cached after the first request
        $few = $count();

        for ($day = 1; $day <= 28; $day++) {
            $this->bookings(sprintf('2026-%02d-%02d', ($day % 12) + 1, $day), 3);
        }
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(15, $many);
    }
}
