<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewOwnerVenueTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $owner;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->owner = $this->userWithRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner, [
            'status' => 'inactive',
            'avg_rating' => 3.2,
            'address_ar' => 'شارع الجلاء', 'address_en' => 'Al-Jalaa St',
            'area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa',
            'latitude' => 31.5, 'longitude' => 34.46, 'length_m' => 40, 'width_m' => 20,
        ]);
        Sanctum::actingAs($this->owner);
    }

    public function test_owner_cannot_mass_assign_status_owner_or_rating(): void
    {
        $other = $this->userWithRole('venue_owner');

        $this->putJson("/api/v1/owner/venues/{$this->venue->id}", [
            'name_en' => 'City Court (Updated)',
            'status' => 'active',
            'owner_id' => $other->id,
            'avg_rating' => 5,
            'deleted_at' => now()->toDateTimeString(),
            'id' => 999,
        ])->assertOk()->assertJsonPath('data.name_en', 'City Court (Updated)');

        $fresh = Venue::withTrashed()->find($this->venue->id);
        $this->assertSame('inactive', $fresh->status);
        $this->assertSame($this->owner->id, (int) $fresh->owner_id);
        $this->assertEquals(3.2, $fresh->avg_rating);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_contract_example_request_is_accepted(): void
    {
        $this->putJson("/api/v1/owner/venues/{$this->venue->id}", ['name_en' => 'City Court (Updated)', 'min_hourly_price' => 45])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Venue updated', 'errors' => null, 'data' => ['id' => $this->venue->id, 'name_en' => 'City Court (Updated)']]);
    }

    public function test_owner_cannot_clear_required_profile_info_of_a_live_venue(): void
    {
        // US-3.1: "The venue cannot go live for players until this information is completed."
        // Once live, nulling the address/area/coordinates leaves a public venue with an incomplete profile.
        $this->venue->update(['status' => 'active']);

        $this->putJson("/api/v1/owner/venues/{$this->venue->id}", ['address_en' => null, 'latitude' => null])
            ->assertStatus(422);
    }

    public function test_soft_deleted_venue_cannot_be_edited_by_its_owner(): void
    {
        $this->venue->delete();

        $this->putJson("/api/v1/owner/venues/{$this->venue->id}", ['name_en' => 'Back'])->assertNotFound();
        $this->getJson('/api/v1/owner/venues')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_numeric_strings_and_types_round_trip_as_numbers(): void
    {
        $this->putJson("/api/v1/owner/venues/{$this->venue->id}", ['latitude' => '31.51', 'length_m' => '42'])
            ->assertOk()
            ->assertJsonPath('data.latitude', 31.51)
            ->assertJsonPath('data.length_m', 42);
    }
}
