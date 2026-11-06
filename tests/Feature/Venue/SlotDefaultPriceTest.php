<?php

namespace Tests\Feature\Venue;

use App\Models\Booking;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class SlotDefaultPriceTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private User $owner;

    private Venue $venue;

    private Sport $sport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->owner = $this->actingAsRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner, [
            'morning_hourly_price' => 75,
            'evening_hourly_price' => 140,
        ]);
        $this->sport = $this->makeSport();
        DB::table('venue_sports')->insert(['venue_id' => $this->venue->id, 'sport_id' => $this->sport->id]);
    }

    private function createSlot(string $start, string $end, array $extra = [])
    {
        return $this->postJson("/api/v1/venue_owner/owner/venues/{$this->venue->id}/time-slots", array_merge([
            'sport_id' => $this->sport->id,
            'slot_date' => now()->addDays(3)->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
        ], $extra));
    }

    private function storedPrice($response): float
    {
        return (float) TimeSlot::findOrFail($response->assertCreated()->json('data.id'))->hourly_price;
    }

    private function bookAsPlayer(int $slotId)
    {
        Sanctum::actingAs($this->userWithRole('player'));

        return $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $slotId,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
        ]);
    }

    public function test_morning_slot_defaults_to_morning_price(): void
    {
        $this->assertSame(75.0, $this->storedPrice($this->createSlot('10:00', '11:00')));
    }

    public function test_slot_starting_before_evening_start_stays_morning(): void
    {
        $this->assertSame(75.0, $this->storedPrice($this->createSlot('15:00', '16:00')));
    }

    public function test_slot_starting_at_evening_start_uses_evening_price(): void
    {
        $this->assertSame(140.0, $this->storedPrice($this->createSlot(Venue::EVENING_STARTS_AT, '17:00')));
    }

    public function test_late_slot_with_explicit_null_uses_evening_price(): void
    {
        $this->assertSame(140.0, $this->storedPrice($this->createSlot('20:00', '21:00', ['hourly_price' => null])));
    }

    public function test_slot_ending_at_midnight_uses_evening_price(): void
    {
        $this->assertSame(140.0, $this->storedPrice($this->createSlot('23:00', '00:00')));
    }

    public function test_explicit_price_wins_over_default(): void
    {
        $this->assertSame(99.5, $this->storedPrice($this->createSlot('20:00', '21:00', ['hourly_price' => 99.5])));
        $this->assertSame(0.0, $this->storedPrice($this->createSlot('09:00', '10:00', ['hourly_price' => 0])));
    }

    public function test_missing_default_price_returns_422_on_hourly_price(): void
    {
        $this->venue->update(['evening_hourly_price' => null]);

        $this->createSlot('18:00', '19:00')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'The hourly price is required.')
            ->assertJsonPath(
                'errors.hourly_price.0',
                'The hourly price is required because the venue has no morning/evening price set for this time.'
            );

        $this->assertSame(0, TimeSlot::count());

        $this->assertSame(75.0, $this->storedPrice($this->createSlot('08:00', '09:00')));
    }

    public function test_price_change_allowed_on_available_slot(): void
    {
        $slot = $this->makeSlot($this->venue, ['sport_id' => $this->sport->id, 'hourly_price' => 50]);

        $this->putJson("/api/v1/venue_owner/owner/time-slots/{$slot->id}", ['hourly_price' => 60])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(60.0, (float) $slot->fresh()->hourly_price);
    }

    public function test_price_change_rejected_on_blocked_and_booked_slots(): void
    {
        foreach (['blocked' => '10:00:00', 'booked' => '12:00:00'] as $status => $start) {
            $slot = $this->makeSlot($this->venue, [
                'sport_id' => $this->sport->id,
                'start_time' => $start,
                'end_time' => sprintf('%02d:00:00', (int) substr($start, 0, 2) + 1),
                'hourly_price' => 50,
                'status' => $status,
            ]);

            $this->putJson("/api/v1/venue_owner/owner/time-slots/{$slot->id}", ['hourly_price' => 70])
                ->assertStatus(409)
                ->assertJsonPath('message', 'The price can only be changed while the slot is available.');

            $this->assertSame(50.0, (float) $slot->fresh()->hourly_price, $status);

            // Resending the same price is not a change.
            $this->putJson("/api/v1/venue_owner/owner/time-slots/{$slot->id}", ['hourly_price' => 50])
                ->assertOk();
        }
    }

    public function test_price_change_rejected_when_slot_held_by_a_booking(): void
    {
        $slot = $this->makeSlot($this->venue, ['sport_id' => $this->sport->id, 'hourly_price' => 50]);

        $this->bookAsPlayer($slot->id)->assertCreated();

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/venue_owner/owner/time-slots/{$slot->id}", ['hourly_price' => 70])
            ->assertStatus(409);

        $this->assertSame(50.0, (float) $slot->fresh()->hourly_price);
    }

    public function test_booking_total_equals_defaulted_slot_price(): void
    {
        $slotId = $this->createSlot('19:00', '20:00')->assertCreated()->json('data.id');

        $this->bookAsPlayer($slotId)->assertCreated();

        $this->assertSame(140.0, (float) Booking::where('time_slot_id', $slotId)->firstOrFail()->total_price);
    }

    public function test_changing_venue_prices_does_not_touch_existing_slots(): void
    {
        $morning = $this->createSlot('10:00', '11:00')->json('data.id');
        $evening = $this->createSlot('18:00', '19:00')->json('data.id');

        $this->putJson("/api/v1/venue_owner/owner/venues/{$this->venue->id}", [
            'morning_hourly_price' => 80,
            'evening_hourly_price' => 150,
        ])->assertOk();

        $this->assertSame(75.0, (float) TimeSlot::findOrFail($morning)->hourly_price);
        $this->assertSame(140.0, (float) TimeSlot::findOrFail($evening)->hourly_price);

        $newSlot = $this->createSlot('11:00', '12:00')->json('data.id');
        $this->assertSame(80.0, (float) TimeSlot::findOrFail($newSlot)->hourly_price);
    }
}
