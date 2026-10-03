<?php

namespace Tests\Feature\Owner;

use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class TimeSlotManagementTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $owner;

    private Venue $venue;

    private Sport $football;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->actingAsRole('venue_owner');
        $this->football = $this->makeSport();
        $this->venue = $this->makeVenue($this->owner);
        $this->venue->sports()->attach($this->football->id);
    }

    private function slotPayload(array $overrides = []): array
    {
        return array_merge([
            'sport_id' => $this->football->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '19:00',
            'hourly_price' => 40,
        ], $overrides);
    }

    public function test_owner_creates_an_available_slot_shown_on_the_public_calendar(): void
    {
        $date = now()->addDay()->toDateString();

        $response = $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload());

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Time slot created',
                'data' => [
                    'status' => 'available',
                    'slot_date' => $date,
                    'start_time' => '18:00',
                    'end_time' => '19:00',
                    'hourly_price' => 40,
                ],
            ]);

        $this->getJson("/api/v1/venues/{$this->venue->id}/time-slots?date={$date}&sport_id={$this->football->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $response->json('data.id'))
            ->assertJsonPath('data.0.status', 'available');
    }

    public function test_slot_requires_date_hours_and_price(): void
    {
        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [])
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['sport_id', 'slot_date', 'start_time', 'end_time', 'hourly_price']);
    }

    public function test_slot_rejects_past_dates_and_inverted_hours(): void
    {
        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload([
            'slot_date' => now()->subDay()->toDateString(),
            'start_time' => '19:00',
            'end_time' => '18:00',
            'hourly_price' => -1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slot_date', 'end_time', 'hourly_price']);
    }

    public function test_slot_must_be_for_a_sport_the_venue_offers(): void
    {
        $tennis = $this->makeSport('Tennis', 'التنس');

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['sport_id' => $tennis->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sport_id.0', 'This venue does not offer the selected sport.');
    }

    public function test_overlapping_slots_are_rejected(): void
    {
        $this->makeSlot($this->venue, ['slot_date' => now()->addDay()->toDateString(), 'start_time' => '18:00:00', 'end_time' => '19:00:00']);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['start_time' => '18:30', 'end_time' => '19:30']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['start_time' => '19:00', 'end_time' => '20:00']))
            ->assertCreated();
    }

    public function test_slots_of_different_sports_cannot_overlap_on_the_same_court(): void
    {
        $basketball = $this->makeSport('Basketball', 'كرة السلة');
        $this->venue->sports()->attach($basketball->id);
        $this->makeSlot($this->venue, ['slot_date' => now()->addDay()->toDateString()]);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['sport_id' => $basketball->id, 'start_time' => '18:30', 'end_time' => '19:30']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_slot_that_already_started_today_is_rejected(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Gaza')->setTime(15, 0)->utc());
        $today = now()->setTimezone('Asia/Gaza')->toDateString();

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['slot_date' => $today, 'start_time' => '14:00', 'end_time' => '16:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload(['slot_date' => $today, 'start_time' => '16:00', 'end_time' => '17:00']))
            ->assertCreated();
    }

    public function test_editing_a_slot_into_the_past_is_rejected(): void
    {
        $slot = $this->makeSlot($this->venue);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['slot_date' => now()->subDays(2)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slot_date']);
    }

    public function test_owner_cannot_create_slots_for_another_owners_venue(): void
    {
        $other = $this->makeVenue();
        $other->sports()->attach($this->football->id);

        $this->postJson("/api/v1/owner/venues/{$other->id}/time-slots", $this->slotPayload())
            ->assertForbidden()
            ->assertJson(['success' => false, 'data' => null]);

        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_owner_changes_the_price_of_an_available_slot(): void
    {
        $slot = $this->makeSlot($this->venue);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 45])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Time slot updated',
                'data' => ['id' => $slot->id, 'hourly_price' => 45, 'start_time' => '18:00'],
            ]);

        $this->assertDatabaseHas('time_slots', ['id' => $slot->id, 'hourly_price' => 45]);
    }

    public function test_owner_moves_an_available_slot_to_new_hours(): void
    {
        $slot = $this->makeSlot($this->venue);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['start_time' => '20:00', 'end_time' => '21:30'])
            ->assertOk()
            ->assertJsonPath('data.start_time', '20:00')
            ->assertJsonPath('data.end_time', '21:30');
    }

    public function test_partial_edit_cannot_invert_the_hour_range(): void
    {
        $slot = $this->makeSlot($this->venue);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['start_time' => '19:30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    }

    public function test_confirmed_slot_cannot_be_edited(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'confirmed']);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 99])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'This slot already has a booking and cannot be changed.',
            ]);

        $this->assertDatabaseHas('time_slots', ['id' => $slot->id, 'hourly_price' => 40]);
    }

    public function test_slot_held_for_a_pending_payment_cannot_be_edited(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'pending_payment']);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 99])->assertStatus(409);
    }

    public function test_slot_freed_by_a_cancelled_booking_can_be_edited(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'cancelled']);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 50])->assertOk();
    }

    public function test_owner_cannot_edit_another_owners_slot(): void
    {
        $slot = $this->makeSlot($this->makeVenue());

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/owner/time-slots/{$slot->id}")->assertForbidden();
    }

    public function test_unknown_slot_returns_404(): void
    {
        $this->putJson('/api/v1/owner/time-slots/999', ['hourly_price' => 1])->assertNotFound();
        $this->deleteJson('/api/v1/owner/time-slots/999')->assertNotFound();
    }

    public function test_owner_removes_an_available_slot(): void
    {
        $slot = $this->makeSlot($this->venue);

        $this->deleteJson("/api/v1/owner/time-slots/{$slot->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('time_slots', ['id' => $slot->id]);
    }

    public function test_booked_slot_cannot_be_removed(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot);

        $this->deleteJson("/api/v1/owner/time-slots/{$slot->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This slot already has a booking and cannot be changed.');

        $this->assertDatabaseHas('time_slots', ['id' => $slot->id]);
    }

    public function test_players_cannot_manage_slots(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->actingAsRole('player');

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", $this->slotPayload())->assertForbidden();
        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 1])->assertForbidden();
    }
}
