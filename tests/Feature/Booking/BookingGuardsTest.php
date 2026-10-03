<?php

namespace Tests\Feature\Booking;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class BookingGuardsTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->player = $this->userWithRole('player');
    }

    private function book(TimeSlot $slot)
    {
        Sanctum::actingAs($this->player);

        return $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $slot->id, 'captain_name' => 'Omar', 'captain_role' => 'Team Captain', 'captain_phone' => '0599123456',
        ]);
    }

    public function test_slot_can_be_booked_again_after_its_booking_was_cancelled(): void
    {
        $slot = $this->makeSlot($this->makeVenue());
        $this->book($slot)->assertCreated();

        $this->travel(11)->minutes();
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('available', $slot->fresh()->status);

        $this->book($slot)->assertCreated()->assertJsonPath('data.status', 'pending_payment');

        $this->assertSame(['cancelled', 'pending_payment'], Booking::orderBy('id')->pluck('status')->all());
    }

    public function test_slot_with_an_active_booking_cannot_be_booked_even_if_marked_available(): void
    {
        $slot = $this->makeSlot($this->makeVenue());
        $this->makeBooking($slot, ['status' => 'confirmed']);
        $slot->update(['status' => 'available']);

        $this->book($slot)->assertStatus(409);
        $this->assertSame(1, Booking::count());
    }

    public function test_slot_of_an_inactive_venue_cannot_be_booked(): void
    {
        $slot = $this->makeSlot($this->makeVenue(null, ['status' => 'inactive']));

        $this->book($slot)->assertNotFound()->assertJson(['success' => false]);
        $this->assertSame(0, Booking::count());
    }

    public function test_removing_a_venue_blocks_its_future_free_slots_and_approving_releases_them(): void
    {
        $venue = $this->makeVenue(null, [
            'address_ar' => 'a', 'address_en' => 'a', 'area_ar' => 'a', 'area_en' => 'a',
            'latitude' => 31.5, 'longitude' => 34.4, 'length_m' => 40, 'width_m' => 20,
        ]);
        $future = $this->makeSlot($venue);
        $past = $this->makeSlot($venue, ['slot_date' => now()->subDays(3)->toDateString()]);
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame('blocked', $future->fresh()->status);
        $this->assertSame('available', $past->fresh()->status);

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('available', $future->fresh()->status);
    }

    public function test_removing_an_already_removed_venue_changes_nothing(): void
    {
        $venue = $this->makeVenue();
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])->assertOk();
        $deletedAt = Venue::withTrashed()->find($venue->id)->deleted_at;

        $this->travel(5)->minutes();
        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(1, AuditLog::count());
        $this->assertEquals($deletedAt, Venue::withTrashed()->find($venue->id)->deleted_at);
    }
}
