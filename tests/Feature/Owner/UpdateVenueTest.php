<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class UpdateVenueTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    private function completeProfile(): array
    {
        return [
            'address_ar' => 'شارع الجلاء',
            'address_en' => 'Al-Jalaa St',
            'area_ar' => 'الجلاء',
            'area_en' => 'Al-Jalaa',
            'latitude' => 31.5203,
            'longitude' => 34.4531,
            'length_m' => 40,
            'width_m' => 20,
        ];
    }

    public function test_owner_completes_their_venue_profile(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner, ['status' => 'inactive']);
        $football = $this->makeSport();

        $response = $this->putJson("/api/v1/owner/venues/{$venue->id}", $this->completeProfile() + [
            'name_en' => 'City Court (Updated)',
            'min_hourly_price' => 45,
            'sport_ids' => [$football->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Venue updated',
                'errors' => null,
                'data' => [
                    'id' => $venue->id,
                    'name_en' => 'City Court (Updated)',
                    'area_ar' => 'الجلاء',
                    'min_hourly_price' => 45,
                    'profile_complete' => true,
                    'sport_ids' => [$football->id],
                    'status' => 'inactive',
                ],
            ]);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'address_en' => 'Al-Jalaa St', 'width_m' => 20]);
        $this->assertDatabaseHas('venue_sports', ['venue_id' => $venue->id, 'sport_id' => $football->id]);
    }

    public function test_saved_changes_show_on_the_public_venue_page(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->putJson("/api/v1/owner/venues/{$venue->id}", $this->completeProfile())->assertOk();

        $this->getJson("/api/v1/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonPath('data.address_en', 'Al-Jalaa St')
            ->assertJsonPath('data.area_ar', 'الجلاء');
    }

    public function test_partial_update_reports_an_incomplete_profile(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->putJson("/api/v1/owner/venues/{$venue->id}", ['area_en' => 'Al-Rimal'])
            ->assertOk()
            ->assertJsonPath('data.area_en', 'Al-Rimal')
            ->assertJsonPath('data.profile_complete', false);
    }

    public function test_owner_cannot_change_status_or_owner_through_the_profile_form(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner, ['status' => 'inactive']);

        $this->putJson("/api/v1/owner/venues/{$venue->id}", [
            'status' => 'active',
            'owner_id' => 999,
            'name_en' => 'Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'status' => 'inactive',
            'owner_id' => $owner->id,
            'name_en' => 'Renamed',
        ]);
    }

    public function test_owner_cannot_edit_another_owners_venue(): void
    {
        $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue(null, ['name_en' => 'Not Mine']);

        $this->putJson("/api/v1/owner/venues/{$venue->id}", ['name_en' => 'Hijacked'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'data' => null]);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name_en' => 'Not Mine']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->putJson("/api/v1/owner/venues/{$venue->id}", [
            'latitude' => 120,
            'width_m' => -5,
            'name_en' => '',
            'city_id' => 999,
            'sport_ids' => [999],
        ])
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['latitude', 'width_m', 'name_en', 'city_id', 'sport_ids.0']);
    }

    public function test_unknown_venue_returns_404(): void
    {
        $this->actingAsRole('venue_owner');

        $this->putJson('/api/v1/owner/venues/999', ['name_en' => 'X'])
            ->assertNotFound()
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_players_cannot_use_owner_endpoints(): void
    {
        $venue = $this->makeVenue();
        $this->actingAsRole('player');

        $this->putJson("/api/v1/owner/venues/{$venue->id}", ['name_en' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/owner/venues')->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $venue = $this->makeVenue();

        $this->putJson("/api/v1/owner/venues/{$venue->id}", ['name_en' => 'X'])->assertUnauthorized();
    }

    public function test_owner_lists_only_their_own_venues(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $mine = $this->makeVenue($owner, ['name_en' => 'Mine']);
        $this->makeVenue(null, ['name_en' => 'Theirs']);

        $this->getJson('/api/v1/owner/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.name_en', 'Mine');
    }
}
