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
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class OwnerAnalyticsTest extends TestCase
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

        // 15 Nov 2026, 10:00 UTC (12:00 in Gaza).
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'UTC'));

        $this->owner = $this->userWithRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner);
        $this->player = $this->userWithRole('player');
    }

    private function slot(string $date, string $start, string $status = 'booked', ?Venue $venue = null): TimeSlot
    {
        $hour = (int) substr($start, 0, 2);

        return $this->makeSlot($venue ?? $this->venue, [
            'slot_date' => $date,
            'start_time' => $start . ':00',
            'end_time' => $hour === 23 ? '00:00:00' : sprintf('%02d:00:00', $hour + 1),
            'hourly_price' => 50,
            'status' => $status,
        ]);
    }

    private function booking(TimeSlot $slot, string $status = 'confirmed', float $price = 50, ?string $createdAt = null): Booking
    {
        $booking = Booking::forceCreate([
            'time_slot_id' => $slot->id,
            'captain_user_id' => $this->player->id,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
            'total_price' => $price,
            'status' => $status,
            'share_token' => Str::random(40),
        ]);

        if ($createdAt) {
            $booking->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $booking;
    }

    private function analytics(string $query = '')
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/v1/venue_owner/owner/analytics' . $query)->assertOk();
    }

    public function test_booking_made_last_month_for_a_slot_this_month_is_counted(): void
    {
        $this->booking($this->slot('2026-11-20', '18:00'), 'confirmed', 80, '2026-10-20 12:00:00');

        $this->analytics()
            ->assertJsonPath('data.period.from', '2026-11-01')
            ->assertJsonPath('data.period.to', '2026-11-30')
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.bookings.confirmed', 1)
            ->assertJsonPath('data.revenue.total', 80);
    }

    public function test_totals_count_confirmed_and_pending_and_revenue_only_confirmed(): void
    {
        $this->booking($this->slot('2026-11-03', '18:00'), 'confirmed', 100);
        $this->booking($this->slot('2026-11-04', '18:00', 'blocked'), 'pending_payment', 70);
        $this->booking($this->slot('2026-11-05', '18:00', 'available'), 'cancelled', 60);
        $this->slot('2026-11-06', '18:00', 'available');

        $this->analytics()
            ->assertJsonPath('data.bookings.total', 2)
            ->assertJsonPath('data.bookings.confirmed', 1)
            ->assertJsonPath('data.bookings.pending', 1)
            ->assertJsonPath('data.bookings.cancelled', 1)
            ->assertJsonPath('data.revenue.total', 100)
            // (booked + blocked) / (available + booked + blocked): 2 of 4.
            ->assertJsonPath('data.occupancy.bookable_slots', 4)
            ->assertJsonPath('data.occupancy.occupied_slots', 2)
            ->assertJsonPath('data.occupancy.rate', 50);
    }

    public function test_cancelled_bookings_are_excluded_from_total_daily_peak_and_overview(): void
    {
        // Sunday 15 Nov is in the current local week (Sat 14 - Fri 20).
        // Two cancelled bookings of the same slot (it was released and booked again).
        $slot = $this->slot('2026-11-15', '18:00', 'available');
        $this->booking($slot, 'cancelled', 60);
        $this->booking($slot, 'cancelled', 60);

        $response = $this->analytics()
            ->assertJsonPath('data.bookings.total', 0)
            ->assertJsonPath('data.bookings.cancelled', 2)
            ->assertJsonPath('data.bookings.change_percent', 0)
            ->assertJsonPath('data.revenue.total', 0)
            ->assertJsonPath('data.peak_hours', [['hour' => '18:00', 'bookings' => 0]])
            ->assertJsonPath('data.peak_hour', null)
            ->assertJsonPath('data.bookings_overview.items.1.date', '2026-11-15')
            ->assertJsonPath('data.bookings_overview.items.1.bookings', 0);

        $this->assertSame(0, collect($response->json('data.daily'))->sum('bookings'));
    }

    public function test_pending_bookings_are_included_in_total_daily_peak_and_overview(): void
    {
        $this->booking($this->slot('2026-11-15', '19:00', 'blocked'), 'pending_payment', 70);
        $this->booking($this->slot('2026-11-15', '20:00', 'available'), 'cancelled', 70);

        $response = $this->analytics()
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.bookings.pending', 1)
            ->assertJsonPath('data.revenue.total', 0)
            ->assertJsonPath('data.peak_hours', [
                ['hour' => '19:00', 'bookings' => 1],
                ['hour' => '20:00', 'bookings' => 0],
            ])
            ->assertJsonPath('data.peak_hour', '19:00')
            ->assertJsonPath('data.bookings_overview.items.1.bookings', 1);

        $day = collect($response->json('data.daily'))->firstWhere('date', '2026-11-15');
        $this->assertSame(1, $day['bookings']);
        $this->assertSame(0.0, (float) $day['revenue']);
    }

    public function test_blocked_slot_counts_as_occupied_and_bookable(): void
    {
        $this->booking($this->slot('2026-11-10', '18:00', 'blocked'), 'pending_payment');
        $this->slot('2026-11-10', '19:00', 'available');
        // Last month: one available slot only, so occupancy went from 0 to 50.
        $this->slot('2026-10-10', '19:00', 'available');

        $this->analytics()
            ->assertJsonPath('data.occupancy.bookable_slots', 2)
            ->assertJsonPath('data.occupancy.occupied_slots', 1)
            ->assertJsonPath('data.occupancy.rate', 50)
            ->assertJsonPath('data.occupancy.change_percentage_points', 50);
    }

    public function test_query_count_is_constant_for_month_and_year(): void
    {
        $count = function (string $query): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->analytics($query);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->booking($this->slot('2026-11-02', '08:00'));
        $count('');
        $fewMonth = $count('');
        $fewYear = $count('?period=year');

        for ($day = 1; $day <= 20; $day++) {
            foreach (['10:00', '11:00', '12:00'] as $hour) {
                $this->booking($this->slot(sprintf('2026-11-%02d', $day), $hour), $day % 3 ? 'confirmed' : 'pending_payment');
            }
        }

        $this->assertSame($fewMonth, $count(''));
        $this->assertSame($fewYear, $count('?period=year'));
        // The year view reuses the current period for the revenue chart.
        $this->assertLessThan($fewMonth, $fewYear);
    }

    public function test_daily_has_every_day_of_the_month_with_sums(): void
    {
        $this->booking($this->slot('2026-11-01', '10:00'), 'confirmed', 40);
        $this->booking($this->slot('2026-11-10', '10:00'), 'confirmed', 50);
        $this->booking($this->slot('2026-11-10', '11:00'), 'pending_payment', 70);
        $this->booking($this->slot('2026-11-30', '23:00'), 'confirmed', 90);

        $daily = $this->analytics()->json('data.daily');

        $this->assertCount(30, $daily);
        $this->assertSame('2026-11-01', $daily[0]['date']);
        $this->assertSame('Sunday', $daily[0]['day']);
        $this->assertSame('2026-11-30', $daily[29]['date']);

        $byDate = collect($daily)->keyBy('date');
        $this->assertEquals(['date' => '2026-11-01', 'day' => 'Sunday', 'bookings' => 1, 'revenue' => 40], $byDate['2026-11-01']);
        $this->assertSame(2, $byDate['2026-11-10']['bookings']);
        $this->assertSame(50.0, (float) $byDate['2026-11-10']['revenue']);
        $this->assertSame(0, $byDate['2026-11-11']['bookings']);
        $this->assertSame(0.0, (float) $byDate['2026-11-11']['revenue']);
        $this->assertSame(1, $byDate['2026-11-30']['bookings']);

        $this->assertSame(4, collect($daily)->sum('bookings'));
        $this->assertSame(180.0, (float) collect($daily)->sum('revenue'));
    }

    public function test_peak_hours_group_by_start_hour_and_pick_the_busiest(): void
    {
        $this->booking($this->slot('2026-11-02', '08:00'));
        $this->booking($this->slot('2026-11-02', '20:00'));
        $this->booking($this->slot('2026-11-03', '20:00'), 'pending_payment');
        $this->booking($this->slot('2026-11-04', '23:00'));
        $this->slot('2026-11-05', '12:00', 'available');

        $this->analytics()
            ->assertJsonPath('data.peak_hours', [
                ['hour' => '08:00', 'bookings' => 1],
                ['hour' => '12:00', 'bookings' => 0],
                ['hour' => '20:00', 'bookings' => 2],
                ['hour' => '23:00', 'bookings' => 1],
            ])
            ->assertJsonPath('data.peak_hour', '20:00');
    }

    public function test_peak_hour_tie_goes_to_the_earlier_hour(): void
    {
        $this->booking($this->slot('2026-11-02', '19:00'));
        $this->booking($this->slot('2026-11-02', '09:00'));

        $this->analytics()->assertJsonPath('data.peak_hour', '09:00');
    }

    public function test_period_boundaries_use_the_local_date(): void
    {
        // 30 Nov 22:30 UTC is already 1 Dec 00:30 in Gaza.
        $this->travelTo(Carbon::parse('2026-11-30 22:30:00', 'UTC'));

        $this->booking($this->slot('2026-11-30', '20:00'), 'confirmed', 40);
        $this->booking($this->slot('2026-12-01', '10:00'), 'confirmed', 60);

        $this->analytics()
            ->assertJsonPath('data.period.from', '2026-12-01')
            ->assertJsonPath('data.period.to', '2026-12-31')
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.revenue.total', 60)
            ->assertJsonCount(31, 'data.daily');

        $this->analytics('?period=day')
            ->assertJsonPath('data.period.from', '2026-12-01')
            ->assertJsonPath('data.daily.0.bookings', 1);

        // The previous month (November) holds the other booking.
        $this->analytics('?period=last_month')
            ->assertJsonPath('data.period.from', '2026-11-01')
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.revenue.total', 40);
    }

    public function test_other_owners_bookings_are_never_counted(): void
    {
        $otherVenue = $this->makeVenue();
        $this->booking($this->slot('2026-11-10', '18:00', 'booked', $otherVenue), 'confirmed', 500);
        $this->booking($this->slot('2026-11-10', '18:00'), 'confirmed', 50);

        $this->analytics()
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.revenue.total', 50)
            ->assertJsonPath('data.occupancy.bookable_slots', 1)
            ->assertJsonCount(1, 'data.peak_hours');
    }

    public function test_venue_without_bookings_returns_zeros(): void
    {
        $response = $this->analytics()
            ->assertJsonPath('data.bookings.total', 0)
            ->assertJsonPath('data.bookings.change_percent', 0)
            ->assertJsonPath('data.revenue.total', 0)
            ->assertJsonPath('data.occupancy.rate', 0)
            ->assertJsonPath('data.peak_hours', [])
            ->assertJsonPath('data.peak_hour', null)
            ->assertJsonCount(30, 'data.daily')
            ->assertJsonCount(7, 'data.bookings_overview.items')
            ->assertJsonCount(12, 'data.revenue_chart.items');

        $this->assertSame(0, collect($response->json('data.daily'))->sum('bookings'));
    }

    public function test_revenue_chart_and_week_overview_use_slot_dates(): void
    {
        // Booked in October for a November slot: counts in November.
        $this->booking($this->slot('2026-11-15', '18:00'), 'confirmed', 75, '2026-10-01 12:00:00');

        $response = $this->analytics();

        $this->assertSame(75.0, (float) $response->json('data.revenue_chart.items.10.revenue'));
        $this->assertSame(0.0, (float) $response->json('data.revenue_chart.items.9.revenue'));

        // Local week of Sat 14 Nov to Fri 20 Nov.
        $response->assertJsonPath('data.bookings_overview.from', '2026-11-14')
            ->assertJsonPath('data.bookings_overview.items.1.date', '2026-11-15')
            ->assertJsonPath('data.bookings_overview.items.1.bookings', 1);
    }

    public function test_owner_without_venue_gets_404(): void
    {
        Sanctum::actingAs($this->userWithRole('venue_owner'));

        $this->getJson('/api/v1/venue_owner/owner/analytics')->assertStatus(404);
    }
}
