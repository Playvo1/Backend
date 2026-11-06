<?php

namespace Tests\Feature\Venue;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class BookingHistoryTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private User $player;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->travelTo(now()->addDays(10)->setTime(20, 0));

        $this->venue = $this->makeVenue();
        $this->player = $this->actingAsRole('player');
    }

    private function bookedSlot(string $date, string $start, string $end): int
    {
        $slot = $this->makeSlot($this->venue, [
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'booked',
        ]);

        return Booking::create([
            'time_slot_id' => $slot->id,
            'captain_user_id' => $this->player->id,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
            'total_price' => 40,
            'status' => 'confirmed',
            'share_token' => Str::random(40),
        ])->id;
    }

    private function historyIds(): array
    {
        Sanctum::actingAs($this->player);

        return collect($this->getJson('/api/v1/bookings')->assertOk()->json('data.data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_history_lists_only_slots_that_have_ended(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $pastYesterday = $this->bookedSlot($yesterday, '18:00:00', '19:00:00');
        $midnightYesterday = $this->bookedSlot($yesterday, '23:00:00', '00:00:00');
        $endedToday = $this->bookedSlot($today, '18:00:00', '19:00:00');
        $laterToday = $this->bookedSlot($today, '21:00:00', '22:00:00');
        $midnightToday = $this->bookedSlot($today, '23:00:00', '00:00:00');
        $future = $this->bookedSlot($tomorrow, '18:00:00', '19:00:00');

        $this->assertSame([$pastYesterday, $midnightYesterday, $endedToday], $this->historyIds());

        // Still before midnight: the 23:00-00:00 slot is being played, not history.
        $this->travelTo(now()->setTime(23, 30));
        $this->assertSame([$pastYesterday, $midnightYesterday, $endedToday, $laterToday], $this->historyIds());

        // After midnight it has ended.
        $this->travelTo(now()->addDay()->setTime(0, 30));
        $this->assertSame(
            [$pastYesterday, $midnightYesterday, $endedToday, $laterToday, $midnightToday],
            $this->historyIds()
        );

        $this->assertNotContains($future, $this->historyIds());
    }

    public function test_slot_ends_at_rule(): void
    {
        $normal = new TimeSlot(['slot_date' => '2026-11-10', 'end_time' => '19:00:00']);
        $midnight = new TimeSlot(['slot_date' => '2026-11-10', 'end_time' => '00:00']);

        $this->assertSame('2026-11-10 19:00:00', $normal->endsAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 00:00:00', $midnight->endsAt()->format('Y-m-d H:i:s'));
    }
}
