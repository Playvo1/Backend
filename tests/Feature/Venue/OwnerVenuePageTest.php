<?php

namespace Tests\Feature\Venue;

use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class OwnerVenuePageTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_owner_can_partially_update_venue_details(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner, [
            'address_ar' => 'شارع الوحدة',
            'address_en' => 'Al-Wehda St',
            'latitude' => 31.5,
            'longitude' => 34.46,
        ]);

        $this->putJson("/api/v1/venue_owner/owner/venues/{$venue->id}", [
            'name_ar' => 'ملعب غزة الرياضي',
            'surface_ar' => 'عشب صناعي (FIFA Pro)',
            'team_format_ar' => '5 في 5',
            'capacity_ar' => '10 لاعبين + مشجعين',
            'description_ar' => 'ملعب مضاء',
            'morning_hourly_price' => 75,
            'evening_hourly_price' => 140,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name_ar', 'ملعب غزة الرياضي')
            ->assertJsonPath('data.address_ar', 'شارع الوحدة')
            ->assertJsonPath('data.morning_hourly_price', 75)
            ->assertJsonPath('data.evening_hourly_price', 140)
            ->assertJsonPath('data.owner_name', $owner->name)
            ->assertJsonPath('data.images_count', 0)
            ->assertJsonPath('data.cover_image_url', null);

        $venue->refresh();
        $this->assertSame('Green Field Court', $venue->name_en);
        $this->assertSame(31.5, $venue->latitude);
        $this->assertSame('عشب صناعي (FIFA Pro)', $venue->surface_ar);
    }

    public function test_status_and_protected_fields_are_ignored(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);
        $other = $this->userWithRole('venue_owner');

        $this->putJson("/api/v1/venue_owner/owner/venues/{$venue->id}", [
            'status' => 'inactive',
            'owner_id' => $other->id,
            'avg_rating' => 5,
            'min_hourly_price' => 1,
            'name_en' => 'Renamed',
        ])->assertOk();

        $venue->refresh();
        $this->assertSame('active', $venue->status);
        $this->assertSame($owner->id, (int) $venue->owner_id);
        $this->assertSame('Renamed', $venue->name_en);
        $this->assertNull($venue->min_hourly_price);
    }

    public function test_validation_rejects_empty_name_and_lone_coordinate(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->putJson("/api/v1/venue_owner/owner/venues/{$venue->id}", [
            'name_ar' => '',
            'latitude' => 31.5,
            'evening_hourly_price' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar', 'longitude', 'evening_hourly_price']);
    }

    public function test_other_owner_gets_403_and_trashed_venue_gets_404(): void
    {
        $venue = $this->makeVenue();
        $this->actingAsRole('venue_owner');

        $this->putJson("/api/v1/venue_owner/owner/venues/{$venue->id}", ['name_en' => 'X'])
            ->assertStatus(403);

        $owner = $this->actingAsRole('venue_owner');
        $trashed = $this->makeVenue($owner);
        $trashed->delete();

        $this->putJson("/api/v1/venue_owner/owner/venues/{$trashed->id}", ['name_en' => 'X'])
            ->assertStatus(404);
    }

    public function test_my_venues_returns_page_fields_and_ordered_absolute_images(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner, ['address_ar' => 'شارع الوحدة', 'area_ar' => 'الرمال']);

        $venue->images()->create(['image_url' => '/storage/venues/b.jpg', 'sort_order' => 2]);
        $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/a.jpg', 'sort_order' => 1]);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner_name', $owner->name)
            ->assertJsonPath('data.0.images_count', 2)
            ->assertJsonPath('data.0.images.0.image_url', 'https://res.cloudinary.com/x/a.jpg')
            ->assertJsonPath('data.0.images.1.image_url', url('/storage/venues/b.jpg'))
            ->assertJsonPath('data.0.cover_image_url', 'https://res.cloudinary.com/x/a.jpg')
            ->assertJsonPath('data.0.city.country.name_en', 'Palestine')
            ->assertJsonStructure(['data' => [['name_ar', 'name_en', 'status', 'length_m', 'width_m', 'sports', 'surface_ar', 'morning_hourly_price']]]);

        $venue->images()->where('sort_order', 2)->update(['is_cover' => true]);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertJsonPath('data.0.cover_image_url', url('/storage/venues/b.jpg'));

        $this->assertSame('/storage/venues/b.jpg', $venue->images()->where('sort_order', 2)->value('image_url'));
    }

    public function test_profile_returns_venue_address_area_and_page_fields(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $this->makeVenue($owner, [
            'address_ar' => 'شارع الوحدة',
            'address_en' => 'Al-Wehda St',
            'area_en' => 'Rimal',
            'capacity_ar' => '10 لاعبين',
            'morning_hourly_price' => 75,
        ]);

        $this->getJson('/api/v1/venue_owner/owner/profile')
            ->assertOk()
            ->assertJsonPath('data.venue.address', 'شارع الوحدة')
            ->assertJsonPath('data.venue.address_en', 'Al-Wehda St')
            ->assertJsonPath('data.venue.area', 'Rimal')
            ->assertJsonPath('data.venue.area_ar', null)
            ->assertJsonPath('data.venue.capacity_ar', '10 لاعبين')
            ->assertJsonPath('data.venue.morning_hourly_price', 75);
    }
}
