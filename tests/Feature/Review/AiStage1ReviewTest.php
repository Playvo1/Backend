<?php

namespace Tests\Feature\Review;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Review tests for the Sprint 1 booking-assistant function schema and search.
 */
class AiStage1ReviewTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI_TYPES = ['STRING', 'NUMBER', 'INTEGER', 'BOOLEAN', 'ARRAY', 'OBJECT'];

    private const GEMINI_SCHEMA_KEYS = [
        'type', 'format', 'title', 'description', 'nullable', 'enum', 'maxItems', 'minItems',
        'properties', 'required', 'minProperties', 'maxProperties', 'minLength', 'maxLength',
        'pattern', 'example', 'anyOf', 'propertyOrdering', 'default', 'items', 'minimum', 'maximum',
    ];

    private Country $palestine;

    private Sport $football;

    private City $gaza;

    private City $khanYounis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $this->palestine = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $this->gaza = City::forceCreate(['country_id' => $this->palestine->id, 'name_ar' => 'غزة', 'name_en' => 'Gaza']);
        $this->khanYounis = City::forceCreate(['country_id' => $this->palestine->id, 'name_ar' => 'خانيونس', 'name_en' => 'Khan Younis']);
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
        // The search only suggests venues that offer the sport through venue_sports.
        DB::table('venue_sports')->insertOrIgnore([
            'venue_id' => $venue->id,
            'sport_id' => $overrides['sport_id'] ?? $this->football->id,
        ]);

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

    private function search(array $arguments = [], $now = null): ?TimeSlot
    {
        return (new VenueSearchTool)->execute(array_merge([
            'sport' => 'Football',
            'date' => '2026-10-10',
            'hour' => '18:00',
        ], $arguments), $now);
    }

    /**
     * Asserts the arguments are rejected up front: null, and no time-slot query was run.
     */
    private function assertRejected(array $arguments, string $label): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = (new VenueSearchTool)->execute($arguments);

        $slotQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'time_slots'));
        DB::disableQueryLog();

        $this->assertNull($result, "{$label}: expected null");
        $this->assertSame([], array_values($slotQueries), "{$label}: expected no time-slot query, the argument should be rejected as malformed");
    }

    private function walkSchema(array $schema, string $path): void
    {
        foreach (array_keys($schema) as $key) {
            $this->assertContains($key, self::GEMINI_SCHEMA_KEYS, "{$path}: unsupported schema keyword '{$key}'");
        }

        $this->assertArrayHasKey('type', $schema, "{$path}: every schema needs a type");
        $this->assertContains($schema['type'], self::GEMINI_TYPES, "{$path}: type must be an uppercase Gemini type");

        if (array_key_exists('enum', $schema)) {
            $this->assertSame('STRING', $schema['type'], "{$path}: enum is only allowed on STRING");
            $this->assertIsArray($schema['enum']);
            $this->assertNotEmpty($schema['enum'], "{$path}: Gemini rejects an empty enum");
            $this->assertTrue(array_is_list($schema['enum']), "{$path}: enum must be a JSON array, not an object");
            $this->assertSame(array_values(array_unique($schema['enum'])), $schema['enum'], "{$path}: enum values must be unique");

            foreach ($schema['enum'] as $value) {
                $this->assertIsString($value, "{$path}: enum values must be strings");
                $this->assertNotSame('', trim($value), "{$path}: enum values must not be blank");
            }
        }

        if (array_key_exists('description', $schema)) {
            $this->assertIsString($schema['description']);
        }

        if ($schema['type'] === 'OBJECT') {
            $this->assertIsArray($schema['properties'] ?? null, "{$path}: OBJECT needs properties");
            $this->assertFalse(array_is_list($schema['properties']), "{$path}: properties must be a map");

            foreach ($schema['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $schema['properties'], "{$path}: required '{$required}' is not a property");
            }

            foreach ($schema['properties'] as $name => $property) {
                $this->walkSchema($property, "{$path}.{$name}");
            }
        }
    }

    private function assertValidGeminiDeclaration(array $declaration): void
    {
        $this->assertSame(['name', 'description', 'parameters'], array_keys($declaration));
        $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/', $declaration['name']);
        $this->assertIsString($declaration['description']);
        $this->assertSame('OBJECT', $declaration['parameters']['type']);
        $this->walkSchema($declaration['parameters'], 'parameters');

        json_encode($declaration, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- declaration

    public function test_declaration_only_uses_the_gemini_openapi_subset(): void
    {
        $this->assertValidGeminiDeclaration((new VenueSearchTool)->declaration());
    }

    public function test_declaration_is_still_valid_with_empty_sports_and_cities(): void
    {
        City::query()->delete();
        Sport::query()->delete();

        $declaration = (new VenueSearchTool)->declaration();

        $this->assertValidGeminiDeclaration($declaration);
        $this->assertSame(['sport', 'date', 'hour'], $declaration['parameters']['required']);
        $this->assertNull($this->search(['sport' => 'Football']));
    }

    public function test_declaration_enum_is_read_from_the_database_on_every_call(): void
    {
        $tool = new VenueSearchTool;
        $before = $tool->declaration()['parameters']['properties'];
        $this->assertNotContains('Basketball', $before['sport']['enum']);
        $this->assertNotContains('Rafah', $before['city']['enum']);

        Sport::forceCreate(['name_ar' => 'كرة السلة', 'name_en' => 'Basketball']);
        City::forceCreate(['country_id' => $this->palestine->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);

        $after = $tool->declaration()['parameters']['properties'];
        $this->assertContains('Basketball', $after['sport']['enum']);
        $this->assertContains('كرة السلة', $after['sport']['enum']);
        $this->assertContains('Rafah', $after['city']['enum']);
        $this->assertContains('رفح', $after['city']['enum']);
    }

    public function test_declaration_enum_has_no_duplicates_when_names_repeat(): void
    {
        Sport::forceCreate(['name_ar' => 'Padel', 'name_en' => 'Padel']);
        $egypt = Country::forceCreate(['name_ar' => 'مصر', 'name_en' => 'Egypt']);
        City::forceCreate(['country_id' => $this->palestine->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);
        City::forceCreate(['country_id' => $egypt->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);

        $declaration = (new VenueSearchTool)->declaration();

        $this->assertValidGeminiDeclaration($declaration);
        $this->assertSame(1, count(array_keys($declaration['parameters']['properties']['sport']['enum'], 'Padel')));
        $this->assertSame(1, count(array_keys($declaration['parameters']['properties']['city']['enum'], 'Rafah')));
    }

    public function test_declaration_parameters_map_to_erd_columns(): void
    {
        // sport -> SPORT.name_*, city -> CITY.name_*, area -> VENUE.area_*/address_*,
        // date -> TIME_SLOT.slot_date / ASSISTANT_QUERY.parsed_date, hour -> TIME_SLOT.start_time / ASSISTANT_QUERY.parsed_hour.
        $this->assertTrue(Schema::hasColumns('sports', ['name_ar', 'name_en']));
        $this->assertTrue(Schema::hasColumns('cities', ['name_ar', 'name_en']));
        $this->assertTrue(Schema::hasColumns('venues', ['city_id', 'area_ar', 'area_en', 'address_ar', 'address_en', 'avg_rating', 'status']));
        $this->assertTrue(Schema::hasColumns('time_slots', ['venue_id', 'sport_id', 'slot_date', 'start_time', 'end_time', 'hourly_price', 'status']));
        $this->assertTrue(Schema::hasColumns('assistant_queries', ['parsed_sport_id', 'parsed_date', 'parsed_hour', 'suggested_venue_id']));
    }

    // ---------------------------------------------------------------- hours

    public function test_hour_start_is_inclusive_and_end_is_exclusive(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['hour' => '18:00'])));
        $this->assertTrue($slot->is($this->search(['hour' => '18:59'])));
        $this->assertNull($this->search(['hour' => '19:00']));
        $this->assertNull($this->search(['hour' => '17:59']));
    }

    public function test_midnight_hour_matches_a_slot_starting_at_midnight(): void
    {
        $slot = $this->slot($this->venue(), ['start_time' => '00:00:00', 'end_time' => '01:00:00']);

        $this->assertTrue($slot->is($this->search(['hour' => '00:00'])));
        $this->assertTrue($slot->is($this->search(['hour' => '0:00'])));
        $this->assertTrue($slot->is($this->search(['hour' => '00:30'])));
        $this->assertNull($this->search(['hour' => '01:00']));
    }

    public function test_late_slot_ending_at_midnight_is_found(): void
    {
        // A 23:00-00:00 slot (end_time stored as 00:00:00) is a normal late game.
        $slot = $this->slot($this->venue(), ['start_time' => '23:00:00', 'end_time' => '00:00:00']);

        $this->assertTrue($slot->is($this->search(['hour' => '23:00'])), '23:00 should match a 23:00-00:00 slot');
        $this->assertTrue($slot->is($this->search(['hour' => '23:30'])), '23:30 should match a 23:00-00:00 slot');
    }

    public function test_late_slot_ending_at_23_59_is_found(): void
    {
        $slot = $this->slot($this->venue(), ['start_time' => '23:00:00', 'end_time' => '23:59:00']);

        $this->assertTrue($slot->is($this->search(['hour' => '23:30'])));
        $this->assertNull($this->search(['hour' => '23:59']));
    }

    public function test_slot_spanning_several_hours_matches_every_hour_inside_it(): void
    {
        $slot = $this->slot($this->venue(), ['start_time' => '16:00:00', 'end_time' => '20:00:00']);

        foreach (['16:00', '17:00', '18:00', '19:30', '19:59'] as $hour) {
            $this->assertTrue($slot->is($this->search(['hour' => $hour])), "hour {$hour}");
        }

        $this->assertNull($this->search(['hour' => '20:00']));
        $this->assertNull($this->search(['hour' => '15:59']));
    }

    public function test_multi_hour_slot_that_already_started_today_is_skipped(): void
    {
        $this->slot($this->venue(), ['start_time' => '16:00:00', 'end_time' => '20:00:00']);

        $this->assertNull($this->search(['hour' => '18:00'], Carbon::parse('2026-10-10 17:00:00')));
    }

    // ---------------------------------------------------------------- names

    public function test_english_names_are_case_insensitive(): void
    {
        $slot = $this->slot($this->venue(['city_id' => $this->khanYounis->id]));

        foreach (['FOOTBALL', 'football', 'fOoTbAlL'] as $sport) {
            $this->assertTrue($slot->is($this->search(['sport' => $sport, 'city' => 'khan younis'])), $sport);
        }

        $this->assertTrue($slot->is($this->search(['city' => 'KHAN YOUNIS'])));
    }

    public function test_city_may_be_given_in_arabic_or_english_with_either_sport_language(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['sport' => 'Football', 'city' => 'غزة'])));
        $this->assertTrue($slot->is($this->search(['sport' => 'كرة القدم', 'city' => 'Gaza'])));
        $this->assertNull($this->search(['sport' => 'كرة القدم', 'city' => 'خانيونس']));
    }

    public function test_city_with_the_same_name_in_two_countries_searches_both(): void
    {
        $egypt = Country::forceCreate(['name_ar' => 'مصر', 'name_en' => 'Egypt']);
        City::forceCreate(['country_id' => $this->palestine->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);
        $egyptianRafah = City::forceCreate(['country_id' => $egypt->id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);
        $slot = $this->slot($this->venue(['city_id' => $egyptianRafah->id]));

        $this->assertTrue($slot->is($this->search(['city' => 'Rafah'])), 'only the first "Rafah" row is used as the filter');
    }

    public function test_arabic_teh_marbuta_and_heh_spellings_match(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['sport' => 'كره القدم'])), 'sport written with ه instead of ة');
        $this->assertTrue($slot->is($this->search(['city' => 'غزه'])), 'city written with ه instead of ة');
    }

    public function test_arabic_alef_hamza_spellings_match(): void
    {
        Sport::forceCreate(['name_ar' => 'ألعاب القوى', 'name_en' => 'Athletics']);
        $slot = $this->slot($this->venue(), ['sport_id' => Sport::where('name_en', 'Athletics')->value('id')]);

        $this->assertTrue($slot->is($this->search(['sport' => 'العاب القوى'])), 'sport written with ا instead of أ');
    }

    public function test_arabic_diacritics_are_ignored(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['sport' => 'كُرَة القَدَم'])), 'sport with harakat');
    }

    public function test_arabic_area_spelling_variants_match(): void
    {
        $slot = $this->slot($this->venue(['area_ar' => 'الشجاعية', 'area_en' => 'Shujaiya']));

        $this->assertTrue($slot->is($this->search(['area' => 'الشجاعية'])));
        $this->assertTrue($slot->is($this->search(['area' => 'الشجاعيه'])), 'area written with ه instead of ة');
    }

    // ---------------------------------------------------------------- area

    public function test_area_matches_each_area_and_address_column(): void
    {
        $byAreaAr = $this->slot($this->venue(['area_ar' => 'الرمال', 'area_en' => null]));
        $byAreaEn = $this->slot($this->venue(['area_ar' => null, 'area_en' => 'Tal al-Hawa']));
        $byAddressAr = $this->slot($this->venue(['area_ar' => null, 'area_en' => null, 'address_ar' => 'شارع عمر المختار']));
        $byAddressEn = $this->slot($this->venue(['area_ar' => null, 'area_en' => null, 'address_en' => 'Salah al-Din Road']));

        $this->assertTrue($byAreaAr->is($this->search(['area' => 'الرمال'])));
        $this->assertTrue($byAreaEn->is($this->search(['area' => 'Hawa'])));
        $this->assertTrue($byAddressAr->is($this->search(['area' => 'المختار'])));
        $this->assertTrue($byAddressEn->is($this->search(['area' => 'Salah'])));
    }

    public function test_english_area_is_case_insensitive(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['area' => 'jalaa'])));
        $this->assertTrue($slot->is($this->search(['area' => 'AL-JALAA'])));
    }

    public function test_area_wildcards_and_escape_char_are_literal(): void
    {
        $this->slot($this->venue());

        foreach (['%', '_', '!', '!%', '!_', '%%', 'Al_Jalaa', 'Al%', '%Jalaa', 'Al!-Jalaa', '\\', '\\%'] as $area) {
            $this->assertNull($this->search(['area' => $area]), "area '{$area}' should not match Al-Jalaa");
        }
    }

    public function test_area_containing_literal_special_characters_is_found(): void
    {
        $bang = $this->slot($this->venue(['area_en' => 'Block !5']), ['hourly_price' => 10]);
        $percent = $this->slot($this->venue(['area_en' => 'Zone 50%_off']), ['hourly_price' => 20]);
        $backslash = $this->slot($this->venue(['area_en' => 'Gate\\7']), ['hourly_price' => 30]);

        $this->assertTrue($bang->is($this->search(['area' => '!5'])));
        $this->assertTrue($bang->is($this->search(['area' => '!'])));
        $this->assertTrue($percent->is($this->search(['area' => '50%_'])));
        $this->assertTrue($backslash->is($this->search(['area' => 'Gate\\7'])));
        $this->assertTrue($backslash->is($this->search(['area' => '\\'])));
    }

    public function test_empty_city_and_area_are_ignored(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['city' => '', 'area' => ''])));
        $this->assertTrue($slot->is($this->search(['city' => null, 'area' => null])));
    }

    // ---------------------------------------------------------------- ordering

    public function test_higher_decimal_rating_beats_a_cheaper_slot(): void
    {
        $this->slot($this->venue(['avg_rating' => 4.49]), ['hourly_price' => 9]);
        $best = $this->slot($this->venue(['avg_rating' => 4.5]), ['hourly_price' => 40]);
        $this->slot($this->venue(['avg_rating' => 4.5]), ['hourly_price' => 100]);

        $this->assertTrue($best->is($this->search()));
    }

    public function test_equal_rating_and_price_picks_the_lowest_slot_id(): void
    {
        $first = $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 40]);
        $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 40]);
        $this->slot($this->venue(['avg_rating' => 5]), ['hourly_price' => 40]);

        $this->assertTrue($first->is($this->search()));
    }

    public function test_price_is_ordered_numerically_not_as_text(): void
    {
        $this->slot($this->venue(), ['hourly_price' => 100]);
        $cheap = $this->slot($this->venue(), ['hourly_price' => 40]);
        $this->slot($this->venue(), ['hourly_price' => 9.5 + 50]);

        $this->assertTrue($cheap->is($this->search()));
    }

    public function test_cheaper_overlapping_slot_at_the_same_venue_wins(): void
    {
        $venue = $this->venue();
        $this->slot($venue, ['start_time' => '17:00:00', 'end_time' => '19:00:00', 'hourly_price' => 50]);
        $cheap = $this->slot($venue, ['start_time' => '18:00:00', 'end_time' => '19:00:00', 'hourly_price' => 30]);

        $this->assertTrue($cheap->is($this->search()));
    }

    // ---------------------------------------------------------------- statuses

    public function test_inactive_venue_is_skipped_even_when_rated_higher(): void
    {
        $this->slot($this->venue(['status' => 'inactive', 'avg_rating' => 5]));
        $active = $this->slot($this->venue(['avg_rating' => 1]));

        $this->assertTrue($active->is($this->search()));
    }

    public function test_booked_and_blocked_slots_fall_back_to_the_next_available(): void
    {
        $this->slot($this->venue(['avg_rating' => 5]), ['status' => 'booked']);
        $this->slot($this->venue(['avg_rating' => 5]), ['status' => 'blocked']);
        $available = $this->slot($this->venue(['avg_rating' => 1]));

        $this->assertTrue($available->is($this->search()));
    }

    public function test_slot_for_another_sport_is_not_returned(): void
    {
        $basketball = Sport::forceCreate(['name_ar' => 'كرة السلة', 'name_en' => 'Basketball']);
        $this->slot($this->venue(['avg_rating' => 5]), ['sport_id' => $basketball->id]);
        $football = $this->slot($this->venue(['avg_rating' => 1]));

        $this->assertTrue($football->is($this->search()));
        $this->assertNull($this->search(['sport' => 'Tennis']));
    }

    // ---------------------------------------------------------------- now / past

    public function test_past_days_and_started_slots_with_explicit_now(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertNull($this->search([], Carbon::parse('2026-10-11 00:00:00')));
        $this->assertNull($this->search([], Carbon::parse('2026-10-10 18:00:00')));
        $this->assertNull($this->search([], Carbon::parse('2026-10-10 18:00:01')));
        $this->assertTrue($slot->is($this->search([], Carbon::parse('2026-10-10 17:59:59'))));
        $this->assertTrue($slot->is($this->search([], Carbon::parse('2026-10-09 23:59:59'))));
    }

    public function test_now_may_be_an_immutable_carbon(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search([], CarbonImmutable::parse('2026-10-10 17:00:00'))));
        $this->assertNull($this->search([], CarbonImmutable::parse('2026-10-10 18:30:00')));
    }

    public function test_midnight_slot_tomorrow_is_found_late_tonight(): void
    {
        $slot = $this->slot($this->venue(), ['slot_date' => '2026-10-11', 'start_time' => '00:00:00', 'end_time' => '01:00:00']);

        $this->assertTrue($slot->is($this->search(['date' => '2026-10-11', 'hour' => '00:00'], Carbon::parse('2026-10-10 23:30:00'))));
    }

    // ---------------------------------------------------------------- malformed

    public static function invalidDates(): array
    {
        return [
            'feb 30' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'month 00' => ['2026-00-10'],
            'day 00' => ['2026-10-00'],
            'feb 29 non leap' => ['2026-02-29'],
            'short month' => ['2026-1-05'],
            'two digit year' => ['26-10-10'],
            'slashes' => ['2026/10/10'],
            'datetime' => ['2026-10-10T18:00'],
            'leading space' => [' 2026-10-10'],
            'trailing space' => ['2026-10-10 '],
            'trailing newline' => ["2026-10-10\n"],
            'arabic digits' => ['٢٠٢٦-١٠-١٠'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_rejected(string $date): void
    {
        $this->slot($this->venue());

        $this->assertRejected(['sport' => 'Football', 'date' => $date, 'hour' => '18:00'], "date '{$date}'");
    }

    public function test_leap_day_is_accepted(): void
    {
        $slot = $this->slot($this->venue(), ['slot_date' => '2028-02-29']);

        $this->assertTrue($slot->is($this->search(['date' => '2028-02-29'])));
    }

    public function test_trailing_newline_date_does_not_bypass_the_already_started_check(): void
    {
        $this->slot($this->venue());

        $this->assertNull($this->search(['date' => "2026-10-10\n", 'hour' => '18:30'], Carbon::parse('2026-10-10 18:30:00')));
    }

    public static function invalidHours(): array
    {
        return [
            '24:00' => ['24:00'],
            '7:5' => ['7:5'],
            'padded' => [' 18:00 '],
            'trailing space' => ['18:00 '],
            'trailing newline' => ["18:00\n"],
            '18:60' => ['18:60'],
            'no colon' => ['1800'],
            'dot' => ['18.00'],
            'seconds' => ['18:00:00'],
            '6pm' => ['6pm'],
            '6 PM' => ['06:00 PM'],
            'negative' => ['-1:00'],
            'three digit hour' => ['018:00'],
            'arabic digits' => ['١٨:٠٠'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidHours')]
    public function test_invalid_hours_are_rejected(string $hour): void
    {
        $this->slot($this->venue());

        $this->assertRejected(['sport' => 'Football', 'date' => '2026-10-10', 'hour' => $hour], "hour '{$hour}'");
    }

    public static function nonStringArguments(): array
    {
        return [
            'sport array' => [['sport' => ['Football']]],
            'sport int' => [['sport' => 1]],
            'sport float' => [['sport' => 1.0]],
            'sport true' => [['sport' => true]],
            'sport false' => [['sport' => false]],
            'sport object' => [['sport' => new \stdClass]],
            'date int' => [['date' => 20261010]],
            'hour int' => [['hour' => 18]],
            'hour float' => [['hour' => 18.0]],
            'city int' => [['city' => 1]],
            'city array' => [['city' => ['Gaza']]],
            'area array' => [['area' => ['Al-Jalaa']]],
            'area int' => [['area' => 0]],
            'sport null' => [['sport' => null]],
            'date null' => [['date' => null]],
            'hour null' => [['hour' => null]],
        ];
    }

    #[DataProvider('nonStringArguments')]
    public function test_non_string_arguments_are_rejected(array $override): void
    {
        $this->slot($this->venue());

        $this->assertRejected(array_merge(['sport' => 'Football', 'date' => '2026-10-10', 'hour' => '18:00'], $override), json_encode(array_keys($override)));
    }

    public function test_missing_required_arguments_are_rejected(): void
    {
        $this->slot($this->venue());
        $full = ['sport' => 'Football', 'date' => '2026-10-10', 'hour' => '18:00'];

        foreach (array_keys($full) as $key) {
            $arguments = $full;
            unset($arguments[$key]);
            $this->assertRejected($arguments, "missing {$key}");
            $this->assertRejected([...$full, $key => ''], "empty {$key}");
        }

        $this->assertRejected([], 'no arguments');
        $this->assertRejected(['city' => 'Gaza', 'area' => 'Al-Jalaa'], 'only optional arguments');
    }

    public function test_unknown_extra_arguments_are_ignored(): void
    {
        $slot = $this->slot($this->venue());

        $this->assertTrue($slot->is($this->search(['venue_id' => 999, 'price' => ['max' => 1]])));
    }

    public function test_find_sport_handles_empty_input(): void
    {
        $tool = new VenueSearchTool;

        $this->assertNull($tool->findSport(null));
        $this->assertNull($tool->findSport(''));
        $this->assertTrue($this->football->is($tool->findSport('Football')));
        $this->assertTrue($this->football->is($tool->findSport('كرة القدم')));
    }

    // ---------------------------------------------------------------- injection

    public static function injectionArguments(): array
    {
        return [
            'sport or' => [['sport' => "Football' OR '1'='1"]],
            'sport drop' => [['sport' => 'Football"; DROP TABLE time_slots; --']],
            'city or' => [['city' => "Gaza' OR '1'='1"]],
            'city comment' => [['city' => "x' OR 1=1 -- "]],
            'area or' => [['area' => "' OR '1'='1"]],
            'area like or' => [['area' => "%' OR 1=1 -- "]],
            'area escape quote' => [['area' => "!' OR 1=1 -- "]],
            'area backslash quote' => [['area' => "\\' OR 1=1 -- "]],
            'area union' => [['area' => "x' UNION SELECT * FROM time_slots -- "]],
            'hour drop' => [['hour' => "18:00'; DROP TABLE time_slots; --"]],
            'date or' => [['date' => "2026-10-10' OR '1'='1"]],
        ];
    }

    #[DataProvider('injectionArguments')]
    public function test_sql_injection_strings_are_inert(array $override): void
    {
        $this->slot($this->venue());
        $countBefore = TimeSlot::count();

        $this->assertNull($this->search($override));
        $this->assertTrue(Schema::hasTable('time_slots'));
        $this->assertTrue(Schema::hasTable('venues'));
        $this->assertSame($countBefore, TimeSlot::count());
    }
}
