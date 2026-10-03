<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class AdminVenueManagementTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->actingAsRole('admin');
    }

    private function completeVenue(array $overrides = []): Venue
    {
        return $this->makeVenue(null, array_merge([
            'address_ar' => 'شارع الجلاء',
            'address_en' => 'Al-Jalaa St',
            'area_ar' => 'الجلاء',
            'area_en' => 'Al-Jalaa',
            'latitude' => 31.52,
            'longitude' => 34.45,
            'length_m' => 40,
            'width_m' => 20,
        ], $overrides));
    }

    public function test_admin_adds_a_venue_for_its_owner_to_complete(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $city = $this->makeCity();
        $football = $this->makeSport();

        $response = $this->postJson('/api/v1/admin/venues', [
            'owner_id' => $owner->id,
            'city_id' => $city->id,
            'name_ar' => 'ملعب المدينة',
            'name_en' => 'City Court',
            'sport_ids' => [$football->id],
        ]);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'errors' => null,
                'data' => [
                    'owner_id' => $owner->id,
                    'name_ar' => 'ملعب المدينة',
                    'name_en' => 'City Court',
                    'status' => 'inactive',
                    'profile_complete' => false,
                    'sport_ids' => [$football->id],
                ],
            ]);

        $venueId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'venue_created',
            'target_type' => 'VENUE',
            'target_id' => $venueId,
        ]);

        // The owner can now complete it from their dashboard.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/owner/venues/{$venueId}", ['area_en' => 'Al-Jalaa'])
            ->assertOk();
    }

    public function test_venue_creation_requires_name_city_and_a_venue_owner(): void
    {
        $player = $this->userWithRole('player');

        $this->postJson('/api/v1/admin/venues', ['owner_id' => $player->id])
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['owner_id', 'city_id', 'name_ar', 'name_en'])
            ->assertJsonPath('errors.owner_id.0', 'The selected user is not a venue owner.');

        $this->assertDatabaseCount('venues', 0);
    }

    public function test_admin_approves_a_completed_venue(): void
    {
        $venue = $this->completeVenue(['status' => 'inactive']);

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Venue approved',
                'data' => ['id' => $venue->id, 'status' => 'active'],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'venue_status_change',
            'target_type' => 'VENUE',
            'target_id' => $venue->id,
        ]);
    }

    public function test_incomplete_venue_cannot_go_live(): void
    {
        $venue = $this->makeVenue(null, ['status' => 'inactive']);

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'status' => 'inactive']);
    }

    public function test_removing_a_venue_soft_deletes_it_and_hides_it_from_players(): void
    {
        $venue = $this->completeVenue();
        $slot = $this->makeSlot($venue, ['slot_date' => now()->addDays(2)->toDateString()]);
        $this->makeBooking($this->makeSlot($venue, ['slot_date' => now()->subDays(5)->toDateString()]));

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Venue removed', 'data' => ['status' => 'inactive']]);

        $this->assertSoftDeleted('venues', ['id' => $venue->id]);
        $this->assertDatabaseCount('bookings', 1);

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/venues/{$venue->id}")->assertNotFound();
        $this->assertNull((new VenueSearchTool)->execute([
            'sport' => 'Football',
            'date' => $slot->slot_date,
            'hour' => '18:00',
        ]));
    }

    public function test_cannot_remove_a_venue_with_upcoming_confirmed_bookings(): void
    {
        $venue = $this->completeVenue();
        $this->makeBooking($this->makeSlot($venue, ['slot_date' => now()->addDays(2)->toDateString()]));

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => "Resolve this venue's upcoming bookings before removing it.",
            ]);

        $this->assertNotSoftDeleted('venues', ['id' => $venue->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'venue_status_change']);
    }

    public function test_cancelled_upcoming_bookings_do_not_block_removal(): void
    {
        $venue = $this->completeVenue();
        $this->makeBooking($this->makeSlot($venue), ['status' => 'cancelled']);

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])->assertOk();
    }

    public function test_removed_venue_can_be_restored_by_approving_it(): void
    {
        $venue = $this->completeVenue();
        $venue->update(['status' => 'inactive']);
        $venue->delete();

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'active'])->assertOk();

        $this->assertNotSoftDeleted('venues', ['id' => $venue->id]);
        $this->getJson("/api/v1/venues/{$venue->id}")->assertOk();
    }

    public function test_admin_lists_venues_by_status_including_removed_ones(): void
    {
        $pending = $this->makeVenue(null, ['status' => 'inactive']);
        $removed = $this->completeVenue(['status' => 'inactive']);
        $removed->delete();
        $this->completeVenue(['status' => 'active']);

        $response = $this->getJson('/api/v1/admin/venues?status=inactive')->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([$pending->id, $removed->id], array_column($response->json('data'), 'id'));
        $this->assertNotNull(collect($response->json('data'))->firstWhere('id', $removed->id)['deleted_at']);
    }

    public function test_status_must_be_active_or_inactive(): void
    {
        $venue = $this->completeVenue();

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'removed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_unknown_venue_returns_404(): void
    {
        $this->putJson('/api/v1/admin/venues/999/status', ['status' => 'inactive'])->assertNotFound();
    }

    public function test_non_admins_cannot_manage_venues(): void
    {
        $venue = $this->completeVenue();
        $this->actingAsRole('venue_owner');

        $this->postJson('/api/v1/admin/venues', [])->assertForbidden();
        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->getJson('/api/v1/admin/venues')->assertForbidden();
    }
}
