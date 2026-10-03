<?php

namespace Tests\Feature\Assistant;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VenueSearchToolTest extends TestCase
{
    use RefreshDatabase;

    private Sport $football;

    private City $gaza;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $this->gaza = City::forceCreate(['country_id' => $country->id, 'name_ar' => 'غزة', 'name_en' => 'Gaza']);
        City::forceCreate(['country_id' => $country->id, 'name_ar' => 'خانيونس', 'name_en' => 'Khan Younis']);
        $this->football = Sport::forceCreate(['name_ar' => 'كرة القدم', 'name_en' => 'Football']);
    }

    private function venue(array $overrides = []): Venue
    {
        return Venue::forceCreate(array_merge([
            'owner_id' => User::factory()->create()->id,
            'city_id' => $this->gaza->id,
            'name_ar' => 'ملعب',
            'name_en' => 'Venue',
            'area_ar' => 'الجلاء',
            'area_en' => 'Al-Jalaa',
            'status' => 'active',
            'avg_rating' => 4,
        ], $overrides));
    }

    private function slot(Venue $venue, array $overrides = []): TimeSlot
    {
        return TimeSlot::forceCreate(array_merge([
            'venue_id' => $venue->id,
            'sport_id' => $this->football->id,
            'slot_date' => '2026-10-10',
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'hourly_price' => 40,
            'status' => 'available',
        ], $overrides));
    }

    private function search(array $arguments = []): ?TimeSlot
    {
        return (new VenueSearchTool)->execute(array_merge([
            'sport' => 'Football',
            'date' => '2026-10-10',
            'hour' => '18:00',
        ], $arguments));
    }

    public function test_assistant_queries_table_matches_the_erd(): void
    {
        $this->assertTrue(Schema::hasColumns('assistant_queries', [
            'user_id', 'query_text', 'parsed_sport_id', 'parsed_date', 'parsed_hour', 'suggested_venue_id',
        ]));
    }

    public function test_declaration_offers_only_seeded_sports_and_cities(): void
    {
        $declaration = (new VenueSearchTool)->declaration();
        $properties = $declaration['parameters']['properties'];

        $this->assertSame(VenueSearchTool::NAME, $declaration['name']);
        $this->assertSame(['sport', 'date', 'hour'], $declaration['parameters']['required']);
        $this->assertEqualsCanonicalizing(['كرة القدم', 'Football'], $properties['sport']['enum']);
        $this->assertEqualsCanonicalizing(['غزة', 'Gaza', 'خانيونس', 'Khan Younis'], $properties['city']['enum']);
    }

    public function test_finds_an_available_slot_covering_the_requested_hour(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['hour' => '18:30'])));
    }

    public function test_accepts_arabic_sport_and_city_names(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['sport' => 'كرة القدم', 'city' => 'غزة'])));
    }

    public function test_filters_by_area(): void
    {
        $this->slot($this->venue(['area_ar' => 'الرمال', 'area_en' => 'Al-Rimal']));
        $jalaa = $this->slot($this->venue());

        $this->assertTrue($jalaa->is($this->search(['area' => 'الجلاء'])));
    }

    public function test_prefers_the_higher_rated_then_cheaper_venue(): void
    {
        $this->slot($this->venue(['avg_rating' => 3]), ['hourly_price' => 20]);
        $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 60]);
        $best = $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 50]);

        $this->assertTrue($best->is($this->search()));
    }

    public function test_ignores_booked_slots_and_inactive_venues(): void
    {
        $this->slot($this->venue(), ['status' => 'booked']);
        $this->slot($this->venue(['status' => 'inactive']));

        $this->assertNull($this->search());
    }

    public function test_returns_null_when_nothing_matches_the_hour_or_city(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['hour' => '20:00']));
        $this->assertNull($this->search(['city' => 'Khan Younis']));
    }

    public function test_returns_null_for_unknown_or_malformed_arguments(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['sport' => 'Tennis']));
        $this->assertNull($this->search(['date' => 'tomorrow']));
        $this->assertNull($this->search(['hour' => '6 pm']));
    }
}
