<?php

namespace Tests\Feature\Assistant;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VenueSearchToolTest extends TestCase
{
    use RefreshDatabase;

    private Sport $football;

    private City $gaza;

    private City $khanYounis;

    protected function setUp(): void
    {
        parent::setUp();

        // Slots below are on 2026-10-10; keep "now" before that so they are in the future.
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $country = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $this->gaza = City::forceCreate(['country_id' => $country->id, 'name_ar' => 'غزة', 'name_en' => 'Gaza']);
        $this->khanYounis = City::forceCreate(['country_id' => $country->id, 'name_ar' => 'خانيونس', 'name_en' => 'Khan Younis']);
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

    private function slot(Venue $venue, array $overrides = [], bool $venueOffersSport = true): TimeSlot
    {
        if ($venueOffersSport) {
            DB::table('venue_sports')->insertOrIgnore([
                'venue_id' => $venue->id,
                'sport_id' => $overrides['sport_id'] ?? $this->football->id,
            ]);
        }

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

    private function search(array $arguments = [], ?Carbon $now = null): ?TimeSlot
    {
        return (new VenueSearchTool)->execute(array_merge([
            'sport' => 'Football',
            'date' => '2026-10-10',
            'hour' => '18:00',
        ], $arguments), $now);
    }

    public function test_assistant_queries_table_matches_the_erd(): void
    {
        $this->assertTrue(Schema::hasColumns('assistant_queries', [
            'id', 'user_id', 'query_text', 'parsed_sport_id', 'parsed_date', 'parsed_hour', 'suggested_venue_id', 'created_at',
        ]));
    }

    public function test_declaration_offers_only_seeded_sports_and_cities(): void
    {
        $declaration = (new VenueSearchTool)->declaration();
        $parameters = $declaration['parameters'];
        $properties = $parameters['properties'];

        $this->assertSame(VenueSearchTool::NAME, $declaration['name']);
        $this->assertSame('search_available_venues', $declaration['name']);
        $this->assertSame('OBJECT', $parameters['type']);
        $this->assertSame(['sport', 'date', 'hour', 'city', 'area'], array_keys($properties));
        $this->assertSame(['sport', 'date', 'hour'], $parameters['required']);
        $this->assertEqualsCanonicalizing(['كرة القدم', 'Football'], $properties['sport']['enum']);
        $this->assertEqualsCanonicalizing(['غزة', 'Gaza', 'خانيونس', 'Khan Younis'], $properties['city']['enum']);
        $this->assertArrayNotHasKey('enum', $properties['area']);

        foreach ($properties as $property) {
            $this->assertSame('STRING', $property['type']);
        }
    }

    public function test_declaration_leaves_out_an_empty_enum(): void
    {
        City::query()->delete();
        Sport::query()->delete();

        $properties = (new VenueSearchTool)->declaration()['parameters']['properties'];

        $this->assertArrayNotHasKey('enum', $properties['sport']);
        $this->assertArrayNotHasKey('enum', $properties['city']);
    }

    public function test_finds_an_available_slot_covering_the_requested_hour(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search()));
        $this->assertTrue($slot->is($this->search(['hour' => '18:30'])));
        $this->assertTrue($slot->venue->is($this->search()->getRelation('venue')));
    }

    public function test_accepts_arabic_and_english_sport_and_city_names(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['sport' => 'كرة القدم', 'city' => 'غزة'])));
        $this->assertTrue($slot->is($this->search(['sport' => 'Football', 'city' => 'Gaza'])));
        $this->assertTrue($slot->is($this->search(['sport' => 'football', 'city' => 'GAZA'])));
    }

    public function test_filters_by_city(): void
    {
        $this->slot($this->venue(['avg_rating' => 5]));
        $inKhanYounis = $this->slot($this->venue(['city_id' => $this->khanYounis->id, 'avg_rating' => 3]));

        $this->assertTrue($inKhanYounis->is($this->search(['city' => 'Khan Younis'])));
        $this->assertTrue($inKhanYounis->is($this->search(['city' => 'خانيونس'])));
        $this->assertNull($this->search(['city' => 'Rafah']));
    }

    public function test_filters_by_area_in_either_language_or_the_address(): void
    {
        $this->slot($this->venue(['area_ar' => 'الرمال', 'area_en' => 'Al-Rimal', 'avg_rating' => 5]));
        $jalaa = $this->slot($this->venue());
        $byAddress = $this->slot($this->venue(['area_ar' => null, 'area_en' => null, 'address_en' => 'Omar Al-Mukhtar Street', 'avg_rating' => 1]));

        $this->assertTrue($jalaa->is($this->search(['area' => 'الجلاء'])));
        $this->assertTrue($jalaa->is($this->search(['area' => 'Jalaa'])));
        $this->assertTrue($byAddress->is($this->search(['area' => 'Mukhtar'])));
    }

    public function test_like_wildcards_in_the_area_are_matched_literally(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['area' => '%']));
        $this->assertNull($this->search(['area' => '_']));
        $this->assertNull($this->search(['area' => 'Al%Jalaa']));
        $this->assertNull($this->search(['area' => 'Al_Jalaa']));

        $literal = $this->slot($this->venue(['area_en' => 'Block 5%_!A', 'avg_rating' => 1]));

        $this->assertTrue($literal->is($this->search(['area' => '5%_!A'])));
    }

    public function test_prefers_the_higher_rated_then_cheaper_venue(): void
    {
        $this->slot($this->venue(['avg_rating' => 3]), ['hourly_price' => 20]);
        $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 60]);
        $best = $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 50]);

        $this->assertTrue($best->is($this->search()));
    }

    public function test_ignores_booked_and_blocked_slots_and_inactive_venues(): void
    {
        $this->slot($this->venue(), ['status' => 'booked']);
        $this->slot($this->venue(), ['status' => 'blocked']);
        $this->slot($this->venue(['status' => 'inactive']));

        $this->assertNull($this->search());
    }

    public function test_skips_slots_that_already_started_today(): void
    {
        $evening = $this->slot($this->venue());
        $later = $this->slot($this->venue(), ['start_time' => '19:00:00', 'end_time' => '20:00:00']);

        $this->assertTrue($evening->is($this->search([], Carbon::parse('2026-10-10 17:59:00'))));
        $this->assertNull($this->search([], Carbon::parse('2026-10-10 18:00:00')));
        $this->assertNull($this->search(['hour' => '18:30'], Carbon::parse('2026-10-10 18:15:00')));
        $this->assertTrue($later->is($this->search(['hour' => '19:00'], Carbon::parse('2026-10-10 18:15:00'))));
    }

    public function test_uses_the_current_time_when_now_is_not_given(): void
    {
        $this->slot($this->venue());

        $this->travelTo(Carbon::parse('2026-10-10 18:30:00'));

        $this->assertNull($this->search());
    }

    public function test_never_suggests_a_slot_on_a_past_day(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search([], Carbon::parse('2026-10-11 09:00:00')));
    }

    public function test_returns_null_when_nothing_matches_the_hour_date_or_city(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['hour' => '17:59']));
        $this->assertNull($this->search(['hour' => '19:00']));
        $this->assertNull($this->search(['hour' => '20:00']));
        $this->assertNull($this->search(['date' => '2026-10-11']));
        $this->assertNull($this->search(['city' => 'Khan Younis']));
    }

    public function test_returns_null_for_unknown_or_malformed_arguments(): void
    {
        $this->slot($this->venue());
        $tool = new VenueSearchTool;

        $this->assertNull($this->search(['sport' => 'Tennis']));
        $this->assertNull($this->search(['date' => 'tomorrow']));
        $this->assertNull($this->search(['date' => '2026-02-31']));
        $this->assertNull($this->search(['date' => '10/10/2026']));
        $this->assertNull($this->search(['hour' => '6 pm']));
        $this->assertNull($this->search(['hour' => '24:00']));
        $this->assertNull($this->search(['hour' => '18:00:00']));
        $this->assertNull($this->search(['sport' => ['Football']]));
        $this->assertNull($this->search(['hour' => 18]));
        $this->assertNull($this->search(['city' => ['Gaza']]));
        $this->assertNull($tool->execute(['date' => '2026-10-10', 'hour' => '18:00']));
        $this->assertNull($tool->execute([]));
    }

    public function test_only_suggests_venues_that_offer_the_sport(): void
    {
        $this->slot($this->venue(['avg_rating' => 5]), [], venueOffersSport: false);
        $offered = $this->slot($this->venue(['avg_rating' => 1]));

        $this->assertTrue($offered->is($this->search()));
    }

    public function test_a_slot_ending_at_midnight_covers_the_last_hour(): void
    {
        $late = $this->slot($this->venue(), ['start_time' => '23:00:00', 'end_time' => '00:00:00']);

        $this->assertTrue($late->is($this->search(['hour' => '23:00'])));
        $this->assertTrue($late->is($this->search(['hour' => '23:59'])));
        $this->assertNull($this->search(['hour' => '22:59']));
        $this->assertNull($this->search(['hour' => '00:00']));
    }

    public function test_a_city_name_shared_by_two_countries_searches_both(): void
    {
        $egypt = Country::forceCreate(['name_ar' => 'مصر', 'name_en' => 'Egypt']);
        City::forceCreate(['country_id' => $this->gaza->country_id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);
        $egyptianRafah = City::forceCreate(['country_id' => $egypt->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);
        $slot = $this->slot($this->venue(['city_id' => $egyptianRafah->id]));

        $this->assertTrue($slot->is($this->search(['city' => 'Rafah'])));
        $this->assertTrue($slot->is($this->search(['city' => 'رفح'])));
    }

    public function test_arabic_spelling_variants_match_names_and_areas(): void
    {
        $slot = $this->slot($this->venue(['area_ar' => 'الشجاعية']));

        $this->assertTrue($slot->is($this->search(['sport' => 'كره القدم', 'city' => 'غزه'])));
        $this->assertTrue($slot->is($this->search(['sport' => 'كُرَة القَدَم'])));
        $this->assertTrue($slot->is($this->search(['sport' => ' كرة   القدم '])));
        $this->assertTrue($slot->is($this->search(['area' => 'الشجاعيه'])));
        $this->assertTrue($slot->is($this->search(['area' => 'الشُّجاعية'])));
        $this->assertTrue($slot->is($this->search(['area' => 'إلشجاعية'])));
    }

    public function test_blank_city_and_area_are_ignored_but_an_area_of_only_variant_letters_is_not(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['city' => '  ', 'area' => ' '])));
        $this->assertNull($this->search(['area' => 'ه']));
        $this->assertNull($this->search(['area' => 'ا ي']));
    }

    public function test_trailing_newlines_are_rejected(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['date' => "2026-10-10\n"]));
        $this->assertNull($this->search(['hour' => "18:00\n"]));
    }

    public function test_accepts_a_single_digit_hour(): void
    {
        $slot = $this->slot($this->venue(), ['start_time' => '09:00:00', 'end_time' => '10:00:00']);

        $this->assertTrue($slot->is($this->search(['hour' => '9:00'])));
    }
}
