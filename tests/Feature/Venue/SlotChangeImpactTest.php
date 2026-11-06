<?php

namespace Tests\Feature\Venue;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * How slot times and prices are validated at the edges, and how the
 * endpoints that read slot times treat a slot ending at midnight (end_time 00:00).
 */
class SlotChangeImpactTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private Venue $venue;

    private TimeSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $owner = $this->actingAsRole('venue_owner');
        $this->venue = $this->makeVenue($owner, ['morning_hourly_price' => 60, 'evening_hourly_price' => 90]);
        $this->slot = $this->makeSlot($this->venue, [
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'hourly_price' => 40,
        ]);
    }

    private function create(array $data)
    {
        return $this->postJson("/api/v1/venue_owner/owner/venues/{$this->venue->id}/time-slots", array_merge([
            'sport_id' => $this->slot->sport_id,
            'slot_date' => now()->addDays(5)->toDateString(),
        ], $data));
    }

    public function test_full_day_slot_from_midnight_to_midnight_is_rejected_on_create(): void
    {
        $this->create(['start_time' => '00:00', 'end_time' => '00:00', 'hourly_price' => 50])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['end_time']]);

        $this->assertSame(1, TimeSlot::count());
    }

    public function test_full_day_slot_is_rejected_on_update(): void
    {
        $this->putJson("/api/v1/venue_owner/owner/time-slots/{$this->slot->id}", [
            'start_time' => '00:00',
            'end_time' => '00:00',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['end_time']]);

        $this->assertSame('18:00', substr((string) $this->slot->fresh()->start_time, 0, 5));
    }

    public function test_price_with_more_than_two_decimals_is_rejected(): void
    {
        $this->create(['start_time' => '10:00', 'end_time' => '11:00', 'hourly_price' => 40.555])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hourly_price']);
    }

    public function test_invalid_time_format_is_rejected(): void
    {
        $this->create(['start_time' => '23:00', 'end_time' => '24:00', 'hourly_price' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    }

    /**
     * Pre-existing: the public venue list filters by hour with end_time > hour, so a slot
     * ending at midnight (stored 00:00:00) never matches an hour inside it.
     */
    public function test_venue_list_hour_filter_finds_a_slot_ending_at_midnight(): void
    {
        $date = now()->addDays(4)->toDateString();
        $this->makeSlot($this->venue, [
            'slot_date' => $date,
            'start_time' => '23:00:00',
            'end_time' => '00:00:00',
        ]);

        $this->getJson("/api/v1/venues?date={$date}&hour=11:30%20PM")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $this->venue->id);
    }

    /**
     * Pre-existing: the rating check builds the slot end as slot_date + end_time, so a slot
     * ending at midnight "ends" at the start of its own day and can be rated before it is played.
     */
    public function test_slot_ending_at_midnight_cannot_be_rated_before_it_is_played(): void
    {
        // Noon on the day of a 23:00–00:00 game: it has not started yet.
        $this->travelTo(now()->addDays(2)->setTime(12, 0));

        $slot = $this->makeSlot($this->venue, [
            'slot_date' => now()->toDateString(),
            'start_time' => '23:00:00',
            'end_time' => '00:00:00',
            'status' => 'booked',
        ]);

        $player = $this->userWithRole('player');
        $booking = Booking::forceCreate([
            'time_slot_id' => $slot->id,
            'captain_user_id' => $player->id,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
            'total_price' => 40,
            'status' => 'confirmed',
            'share_token' => Str::random(40),
        ]);

        Sanctum::actingAs($player);

        $this->postJson("/api/v1/bookings/{$booking->id}/rating", ['rating' => 5])
            ->assertJsonPath('message', 'You can rate the venue only after the booking has ended.')
            ->assertStatus(422);

        $this->assertSame(0, $booking->rating()->count());
    }
}
