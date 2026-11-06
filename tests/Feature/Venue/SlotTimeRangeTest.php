<?php

namespace Tests\Feature\Venue;

use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class SlotTimeRangeTest extends TestCase
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
        $this->venue = $this->makeVenue($owner);
        $this->slot = $this->makeSlot($this->venue, [
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'hourly_price' => 40,
        ]);
    }

    private function update(array $data)
    {
        return $this->putJson("/api/v1/venue_owner/owner/time-slots/{$this->slot->id}", $data);
    }

    private function create(string $start, string $end)
    {
        return $this->postJson("/api/v1/venue_owner/owner/venues/{$this->venue->id}/time-slots", [
            'sport_id' => $this->slot->sport_id,
            'slot_date' => now()->addDays(5)->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'hourly_price' => 50,
        ]);
    }

    private function assertSlotUnchanged(): void
    {
        $fresh = $this->slot->fresh();
        $this->assertSame('18:00', substr((string) $fresh->start_time, 0, 5));
        $this->assertSame('19:00', substr((string) $fresh->end_time, 0, 5));
        $this->assertSame(40.0, (float) $fresh->hourly_price);
    }

    public function test_updating_only_start_to_end_or_later_is_rejected(): void
    {
        foreach (['19:00', '20:00'] as $start) {
            $this->update(['start_time' => $start, 'hourly_price' => 99])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonStructure(['errors' => ['end_time']]);
        }

        $this->assertSlotUnchanged();
    }

    public function test_updating_only_end_to_start_or_earlier_is_rejected(): void
    {
        foreach (['18:00', '17:00'] as $end) {
            $this->update(['end_time' => $end])
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['end_time']]);
        }

        $this->assertSlotUnchanged();
    }

    public function test_valid_updates_are_saved(): void
    {
        $this->update(['start_time' => '20:00', 'end_time' => '21:00'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('21:00', substr((string) $this->slot->fresh()->end_time, 0, 5));

        $this->update(['start_time' => '23:00', 'end_time' => '00:00'])->assertOk();
        $this->assertSame(60, $this->slot->fresh()->durationInMinutes());
    }

    public function test_updating_only_end_to_make_two_hours_is_rejected(): void
    {
        $this->update(['end_time' => '20:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A time slot must last exactly one hour.')
            ->assertJsonStructure(['errors' => ['end_time']]);

        $this->assertSlotUnchanged();
    }

    public function test_price_only_update_of_an_existing_longer_slot_is_allowed(): void
    {
        $long = $this->makeSlot($this->venue, [
            'start_time' => '20:00:00',
            'end_time' => '22:00:00',
            'hourly_price' => 40,
        ]);

        $this->putJson("/api/v1/venue_owner/owner/time-slots/{$long->id}", ['hourly_price' => 55])
            ->assertOk();

        $fresh = $long->fresh();
        $this->assertSame(55.0, (float) $fresh->hourly_price);
        $this->assertSame('22:00', substr((string) $fresh->end_time, 0, 5));
    }

    public function test_create_accepts_only_one_hour_slots(): void
    {
        $this->create('18:00', '20:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A time slot must last exactly one hour.')
            ->assertJsonStructure(['errors' => ['end_time']]);
        $this->create('18:00', '18:30')->assertStatus(422)->assertJsonStructure(['errors' => ['end_time']]);
        $this->assertSame(1, TimeSlot::count());

        $this->create('18:00', '19:00')->assertCreated();
        $this->create('23:00', '00:00')->assertCreated();
        $this->assertSame(3, TimeSlot::count());
    }

    public function test_slot_ending_at_midnight_can_be_created(): void
    {
        $id = $this->create('23:00', '00:00')->assertCreated()->json('data.id');

        $this->assertSame(60, TimeSlot::findOrFail($id)->durationInMinutes());
    }

    public function test_create_rejects_end_not_after_start(): void
    {
        $this->create('20:00', '20:00')->assertStatus(422)->assertJsonStructure(['errors' => ['end_time']]);
        $this->create('20:00', '19:00')->assertStatus(422)->assertJsonStructure(['errors' => ['end_time']]);
        $this->create('20:00', '01:00')->assertStatus(422)->assertJsonStructure(['errors' => ['end_time']]);

        $this->assertSame(1, TimeSlot::count());
    }
}
