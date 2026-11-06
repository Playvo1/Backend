<?php

namespace Tests\Feature\Venue;

use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class OwnerVenueUpdateTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private const PAGE_COLUMNS = [
        'description_ar', 'description_en',
        'surface_ar', 'surface_en',
        'team_format_ar', 'team_format_en',
        'capacity_ar', 'capacity_en',
        'morning_hourly_price', 'evening_hourly_price',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function url(int $id): string
    {
        return "/api/v1/venue_owner/owner/venues/{$id}";
    }

    private function fullVenue($owner, array $overrides = []): Venue
    {
        return $this->makeVenue($owner, array_merge([
            'address_ar' => 'شارع عمر المختار',
            'address_en' => 'Omar Al-Mukhtar St',
            'area_ar' => 'الرمال',
            'area_en' => 'Rimal',
            'latitude' => 31.5123456,
            'longitude' => 34.4612345,
            'length_m' => 40,
            'width_m' => 20,
            'avg_rating' => 4.5,
            'min_hourly_price' => 50,
        ], $overrides));
    }

    // ---- Update: every field persists ----

    public function test_every_editable_field_updates_and_persists(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $payload = [
            'name_ar' => 'ملعب جديد',
            'name_en' => 'New Court',
            'address_ar' => 'عنوان جديد',
            'address_en' => 'New address',
            'area_ar' => 'تل الهوى',
            'area_en' => 'Tal al-Hawa',
            'latitude' => 31.25,
            'longitude' => 34.25,
            'length_m' => 42.5,
            'width_m' => 21.25,
            'description_ar' => 'وصف',
            'description_en' => 'Description',
            'surface_ar' => 'عشب صناعي',
            'surface_en' => 'Artificial turf',
            'team_format_ar' => '5 ضد 5',
            'team_format_en' => '5 vs 5',
            'capacity_ar' => '10 لاعبين',
            'capacity_en' => '10 players',
            'morning_hourly_price' => 75.5,
            'evening_hourly_price' => 140,
        ];

        $response = $this->putJson($this->url($venue->id), $payload)->assertOk();

        $venue->refresh();
        foreach ($payload as $key => $value) {
            $this->assertEquals($value, $venue->{$key}, "{$key} was not persisted");
            $this->assertEquals($value, $response->json("data.{$key}"), "{$key} not returned");
        }
    }

    public function test_single_field_update_leaves_everything_else_unchanged(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $sport = $this->makeSport();
        $venue = $this->fullVenue($owner, ['surface_ar' => 'عشب', 'morning_hourly_price' => 60]);
        $venue->sports()->sync([$sport->id]);
        $before = $venue->fresh()->getAttributes();

        $this->putJson($this->url($venue->id), ['name_ar' => 'اسم فقط'])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'اسم فقط')
            ->assertJsonPath('data.sports.0.id', $sport->id);

        $after = $venue->fresh()->getAttributes();
        unset($before['name_ar'], $after['name_ar'], $before['updated_at'], $after['updated_at']);
        $this->assertEquals($before, $after);
        $this->assertSame([$sport->id], $venue->sports()->pluck('sports.id')->all());
    }

    public function test_empty_body_is_a_no_op(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $this->putJson($this->url($venue->id), [])->assertOk();

        $this->assertSame(31.5123456, $venue->fresh()->latitude);
        $this->assertSame('Omar Al-Mukhtar St', $venue->fresh()->address_en);
    }

    public function test_nullable_fields_can_be_cleared(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner, ['surface_en' => 'Grass', 'evening_hourly_price' => 90]);

        $this->putJson($this->url($venue->id), [
            'area_en' => null,
            'surface_en' => null,
            'evening_hourly_price' => null,
            'length_m' => null,
        ])->assertOk();

        $venue->refresh();
        $this->assertNull($venue->area_en);
        $this->assertNull($venue->surface_en);
        $this->assertNull($venue->evening_hourly_price);
        $this->assertNull($venue->length_m);
        $this->assertSame(20.0, $venue->width_m);
    }

    public function test_sport_ids_are_synced(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $football = $this->makeSport();
        $padel = $this->makeSport('Padel', 'بادل');
        $venue = $this->fullVenue($owner);
        $venue->sports()->sync([$football->id]);

        $this->putJson($this->url($venue->id), ['sport_ids' => [$padel->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.sports')
            ->assertJsonPath('data.sports.0.id', $padel->id);

        $this->assertSame([$padel->id], $venue->sports()->pluck('sports.id')->all());
    }

    // ---- Validation ----

    public static function invalidPayloads(): array
    {
        return [
            'empty name_ar' => [['name_ar' => ''], 'name_ar'],
            'null name_en' => [['name_en' => null], 'name_en'],
            'empty address_ar' => [['address_ar' => ''], 'address_ar'],
            'null address_en' => [['address_en' => null], 'address_en'],
            'name too long' => [['name_en' => str_repeat('a', 256)], 'name_en'],
            'area too long' => [['area_ar' => str_repeat('ب', 256)], 'area_ar'],
            'description too long' => [['description_en' => str_repeat('a', 2001)], 'description_en'],
            'surface too long' => [['surface_ar' => str_repeat('a', 256)], 'surface_ar'],
            'team format too long' => [['team_format_en' => str_repeat('a', 256)], 'team_format_en'],
            'capacity too long' => [['capacity_en' => str_repeat('a', 256)], 'capacity_en'],
            'latitude without longitude' => [['latitude' => 31.5], 'longitude'],
            'longitude without latitude' => [['longitude' => 34.4], 'latitude'],
            'null latitude alone' => [['latitude' => null], 'latitude'],
            'both coordinates null' => [['latitude' => null, 'longitude' => null], 'latitude'],
            'latitude out of range' => [['latitude' => 91, 'longitude' => 34], 'latitude'],
            'longitude out of range' => [['latitude' => 31, 'longitude' => -181], 'longitude'],
            'non numeric latitude' => [['latitude' => 'abc', 'longitude' => 34], 'latitude'],
            'negative length' => [['length_m' => -1], 'length_m'],
            'non numeric width' => [['width_m' => 'wide'], 'width_m'],
            'negative morning price' => [['morning_hourly_price' => -0.01], 'morning_hourly_price'],
            'too large evening price' => [['evening_hourly_price' => 10000.01], 'evening_hourly_price'],
            'non numeric morning price' => [['morning_hourly_price' => 'cheap'], 'morning_hourly_price'],
            'sport_ids not array' => [['sport_ids' => 5], 'sport_ids'],
            'no sports left' => [['sport_ids' => []], 'sport_ids'],
            'price with three decimals' => [['morning_hourly_price' => 75.555], 'morning_hourly_price'],
            'length over the limit' => [['length_m' => 1001], 'length_m'],
            'sport_ids null' => [['sport_ids' => null], 'sport_ids'],
            'unknown sport id' => [['sport_ids' => [999999]], 'sport_ids.0'],
            'duplicate sport ids' => [['sport_ids' => ['__sport__', '__sport__']], 'sport_ids.0'],
            'non integer sport id' => [['sport_ids' => ['x']], 'sport_ids.0'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected(array $payload, string $errorKey): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $sport = $this->makeSport();
        $venue = $this->fullVenue($owner);

        if (isset($payload['sport_ids']) && is_array($payload['sport_ids'])) {
            $payload['sport_ids'] = array_map(fn ($id) => $id === '__sport__' ? $sport->id : $id, $payload['sport_ids']);
        }

        $before = $venue->fresh()->getAttributes();

        $this->putJson($this->url($venue->id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertEquals($before, $venue->fresh()->getAttributes());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $this->putJson($this->url($venue->id), [
            'latitude' => -90,
            'longitude' => 180,
            'morning_hourly_price' => 0,
            'evening_hourly_price' => 10000,
            'name_ar' => str_repeat('م', 255),
            'description_ar' => str_repeat('و', 2000),
        ])->assertOk();

        $venue->refresh();
        $this->assertSame(-90.0, $venue->latitude);
        $this->assertSame(0.0, $venue->morning_hourly_price);
        $this->assertSame(10000.0, $venue->evening_hourly_price);
    }

    /**
     * length_m / width_m are decimal(8,2): anything above 999999.99 cannot be stored
     * (MySQL strict mode answers with a server error), so it must be a 422.
     */
    public function test_dimensions_larger_than_the_column_are_rejected(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $this->putJson($this->url($venue->id), ['length_m' => 1000000, 'width_m' => 99999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['length_m', 'width_m']);

        $this->assertSame(40.0, $venue->fresh()->length_m);
    }

    // ---- Protected fields ----

    public function test_status_owner_city_rating_and_min_price_cannot_be_changed(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $otherCity = $this->makeCity('Khan Younis', 'خان يونس');
        $venue = $this->fullVenue($owner);
        $other = $this->userWithRole('venue_owner');
        $cityId = $venue->city_id;

        $this->putJson($this->url($venue->id), [
            'status' => 'inactive',
            'owner_id' => $other->id,
            'city_id' => $otherCity->id,
            'avg_rating' => 1,
            'min_hourly_price' => 1,
            'id' => 9999,
            'deleted_at' => now()->toDateTimeString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.id', $venue->id);

        $venue = Venue::query()->findOrFail($venue->id);
        $this->assertSame('active', $venue->status);
        $this->assertSame($owner->id, (int) $venue->owner_id);
        $this->assertSame($cityId, (int) $venue->city_id);
        $this->assertEquals(4.5, $venue->avg_rating);
        $this->assertSame(50.0, $venue->min_hourly_price);
        $this->assertNull($venue->deleted_at);
    }

    // ---- Access control ----

    public function test_unauthenticated_request_gets_401(): void
    {
        $venue = $this->fullVenue($this->userWithRole('venue_owner'));

        $this->putJson($this->url($venue->id), ['name_en' => 'X'])->assertStatus(401);
        $this->getJson('/api/v1/venue_owner/owner/myvenues')->assertStatus(401);
    }

    public function test_another_owner_gets_403_and_venue_is_unchanged(): void
    {
        $venue = $this->fullVenue($this->userWithRole('venue_owner'));
        $this->actingAsRole('venue_owner');

        $this->putJson($this->url($venue->id), ['name_en' => 'Hijacked', 'status' => 'inactive'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame('Green Field Court', $venue->fresh()->name_en);
    }

    public function test_player_and_admin_roles_are_forbidden(): void
    {
        $venue = $this->fullVenue($this->userWithRole('venue_owner'));

        foreach (['player', 'admin'] as $role) {
            $this->actingAsRole($role);

            $this->putJson($this->url($venue->id), ['name_en' => 'X'])->assertStatus(403);
            $this->getJson('/api/v1/venue_owner/owner/myvenues')->assertStatus(403);
        }

        $this->assertSame('Green Field Court', $venue->fresh()->name_en);
    }

    public function test_missing_and_soft_deleted_venues_get_404(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);
        $venue->delete();

        $this->putJson($this->url($venue->id), ['name_en' => 'X'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
        $this->putJson($this->url(999999), ['name_en' => 'X'])->assertStatus(404);

        $this->assertSame('Green Field Court', Venue::withTrashed()->find($venue->id)->name_en);
    }

    // ---- Response shape ----

    public function test_update_response_has_the_same_shape_as_a_my_venues_item(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);
        $venue->sports()->sync([$this->makeSport()->id]);
        $venue->images()->create(['image_url' => 'https://res.cloudinary.com/demo/a.jpg', 'sort_order' => 0]);

        $put = $this->putJson($this->url($venue->id), ['name_en' => 'Same'])->assertOk()->json('data');
        $list = $this->getJson('/api/v1/venue_owner/owner/myvenues')->assertOk()->json('data.0');

        $putKeys = array_keys($put);
        $listKeys = array_keys($list);
        sort($putKeys);
        sort($listKeys);
        $this->assertSame($listKeys, $putKeys);
        $this->assertEquals($list, $put);
    }

    // ---- myvenues ----

    public function test_my_venues_exposes_dashboard_and_page_keys(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner, ['surface_en' => 'Turf', 'evening_hourly_price' => 120]);
        $venue->images()->create(['image_url' => 'venues/a.jpg', 'sort_order' => 0]);

        $response = $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [[
                'id', 'owner_id', 'city_id', 'name_ar', 'name_en', 'address_ar', 'address_en',
                'area_ar', 'area_en', 'latitude', 'longitude', 'length_m', 'width_m', 'status',
                ...self::PAGE_COLUMNS,
                'owner_name', 'cover_image_url', 'images_count',
                'city' => ['id', 'name_ar', 'name_en', 'country' => ['id', 'name_ar', 'name_en']],
                'sports',
                'images' => [['id', 'image_url', 'sort_order', 'is_cover']],
            ]]])
            ->assertJsonPath('data.0.owner_name', $owner->name)
            ->assertJsonPath('data.0.length_m', 40)
            ->assertJsonPath('data.0.evening_hourly_price', 120)
            ->assertJsonPath('data.0.images.0.image_url', asset('storage/venues/a.jpg'));

        $this->assertStringStartsWith('http', $response->json('data.0.cover_image_url'));
    }

    public function test_cover_image_prefers_is_cover_then_lowest_sort_order_then_null(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertJsonPath('data.0.cover_image_url', null)
            ->assertJsonPath('data.0.images_count', 0)
            ->assertJsonPath('data.0.images', []);

        $c = $venue->images()->create(['image_url' => 'https://res.cloudinary.com/demo/c.jpg', 'sort_order' => 3]);
        $a = $venue->images()->create(['image_url' => '/storage/venues/a.jpg', 'sort_order' => 1]);
        $b2 = $venue->images()->create(['image_url' => 'https://res.cloudinary.com/demo/b2.jpg', 'sort_order' => 2]);
        $b1 = $venue->images()->create(['image_url' => 'https://res.cloudinary.com/demo/b1.jpg', 'sort_order' => 2]);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertJsonPath('data.0.images_count', 4)
            ->assertJsonPath('data.0.images.*.id', [$a->id, $b2->id, $b1->id, $c->id])
            ->assertJsonPath('data.0.images.0.image_url', url('/storage/venues/a.jpg'))
            ->assertJsonPath('data.0.cover_image_url', url('/storage/venues/a.jpg'));

        $c->update(['is_cover' => true]);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertJsonPath('data.0.cover_image_url', 'https://res.cloudinary.com/demo/c.jpg')
            ->assertJsonPath('data.0.images.3.is_cover', true);

        // Stored values are never rewritten by the presentation layer.
        $this->assertSame('/storage/venues/a.jpg', $a->fresh()->image_url);
    }

    public function test_my_venues_never_shows_another_owners_or_deleted_venue(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $mine = $this->fullVenue($owner);
        $this->fullVenue($this->userWithRole('venue_owner'), ['name_en' => 'Not mine']);

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $mine->delete();

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_without_venue_gets_empty_list(): void
    {
        $this->actingAsRole('venue_owner');

        $this->getJson('/api/v1/venue_owner/owner/myvenues')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ---- profile ----

    public function test_profile_prefers_arabic_address_and_area(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $this->fullVenue($owner, [
            'description_en' => 'Lit pitch',
            'surface_ar' => 'عشب',
            'team_format_en' => '5v5',
            'capacity_en' => '10',
            'evening_hourly_price' => 140,
        ]);

        $this->getJson('/api/v1/venue_owner/owner/profile')
            ->assertOk()
            ->assertJsonPath('data.venue.address', 'شارع عمر المختار')
            ->assertJsonPath('data.venue.area', 'الرمال')
            ->assertJsonPath('data.venue.address_ar', 'شارع عمر المختار')
            ->assertJsonPath('data.venue.address_en', 'Omar Al-Mukhtar St')
            ->assertJsonPath('data.venue.area_ar', 'الرمال')
            ->assertJsonPath('data.venue.area_en', 'Rimal')
            ->assertJsonPath('data.venue.description_en', 'Lit pitch')
            ->assertJsonPath('data.venue.description_ar', null)
            ->assertJsonPath('data.venue.surface_ar', 'عشب')
            ->assertJsonPath('data.venue.team_format_en', '5v5')
            ->assertJsonPath('data.venue.capacity_en', '10')
            ->assertJsonPath('data.venue.length_m', 40)
            ->assertJsonPath('data.venue.width_m', 20)
            ->assertJsonPath('data.venue.morning_hourly_price', null)
            ->assertJsonPath('data.venue.evening_hourly_price', 140)
            ->assertJsonPath('data.venue.status', 'active');
    }

    public function test_profile_falls_back_to_english_and_handles_empty_values(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $this->fullVenue($owner, ['address_ar' => '', 'area_ar' => null]);

        $this->getJson('/api/v1/venue_owner/owner/profile')
            ->assertOk()
            ->assertJsonPath('data.venue.address', 'Omar Al-Mukhtar St')
            ->assertJsonPath('data.venue.area', 'Rimal');
    }

    public function test_profile_address_is_null_when_both_languages_missing(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $this->fullVenue($owner, ['address_ar' => null, 'address_en' => null, 'area_ar' => null, 'area_en' => null]);

        $this->getJson('/api/v1/venue_owner/owner/profile')
            ->assertOk()
            ->assertJsonPath('data.venue.address', null)
            ->assertJsonPath('data.venue.area', null);
    }

    public function test_profile_reflects_an_update(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->fullVenue($owner);

        $this->putJson($this->url($venue->id), ['area_ar' => 'الشيخ رضوان', 'morning_hourly_price' => 80])
            ->assertOk();

        $this->getJson('/api/v1/venue_owner/owner/profile')
            ->assertJsonPath('data.venue.area', 'الشيخ رضوان')
            ->assertJsonPath('data.venue.morning_hourly_price', 80);
    }

    // ---- Migration ----

    public function test_migration_can_be_rolled_back_and_re_run(): void
    {
        $migration = require database_path('migrations/2026_11_04_100000_add_page_details_to_venues_table.php');

        foreach (self::PAGE_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('venues', $column), "{$column} missing after migrate");
        }

        // Running up again on an already migrated schema is a no-op.
        $migration->up();

        $migration->down();
        foreach (self::PAGE_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('venues', $column), "{$column} still present after down");
        }
        $this->assertTrue(Schema::hasColumn('venues', 'name_ar'));

        // Running down twice is a no-op too.
        $migration->down();

        $migration->up();
        foreach (self::PAGE_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('venues', $column), "{$column} missing after re-run");
        }

        // A partially applied schema is completed, not duplicated.
        Schema::table('venues', fn ($table) => $table->dropColumn('capacity_en'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('venues', 'capacity_en'));
    }
}
