<?php

namespace Tests\Feature\Review;

use App\Models\AssistantQuery;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewAssistantTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $player;

    private Sport $football;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo('2026-10-03 09:00:00'); // 12:00 in Gaza
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        $this->seedRoles();
        $this->player = $this->actingAsRole('player');
        $this->makeCity();
        $this->football = $this->makeSport();
        $this->venue = $this->makeVenue(null, ['area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa', 'avg_rating' => 4.5]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'hourly_price' => 40]);
    }

    private function ask(string $text)
    {
        return $this->postJson('/api/v1/assistant/query', ['query_text' => $text]);
    }

    private function fakeArgs(mixed $args): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]]]]]],
        ])]);
    }

    public function test_non_json_gemini_body_falls_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_parts_as_a_string_falls_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => 'nope']]]])]);

        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_function_call_with_args_as_a_string_falls_back(): void
    {
        $this->fakeArgs('sport=football');

        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_function_call_with_non_string_args_falls_back(): void
    {
        $this->fakeArgs(['sport' => ['Football'], 'date' => 20261004, 'hour' => 18]);

        $this->ask('football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_function_call_with_invalid_values_falls_back(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-02-31', 'hour' => '6pm']);

        $this->ask('football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_unknown_sport_from_gemini_is_replaced_by_the_fallback_reading(): void
    {
        // Gemini returns a sport that isn't in the SPORT table; the rule-based parser understood "football".
        $this->fakeArgs(['sport' => 'Cricket', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_http_500_and_429_from_gemini_fall_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(['error' => 'x'], 500)->push(['error' => 'quota'], 429)]);

        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
        $this->ask('football tomorrow at 6pm')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_like_wildcards_from_gemini_area_do_not_match_everything(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => '%']);

        $this->ask('football tomorrow at 6pm in %')->assertOk()->assertJsonPath('data.suggested_venue_id', null);
    }

    public function test_sql_injection_in_area_and_city_is_harmless(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => "x' OR 1=1 --", 'city' => "Gaza' OR '1'='1"]);

        $this->ask("football tomorrow 6pm x' OR 1=1 --")->assertOk()->assertJsonPath('data.suggested_venue_id', null);
        $this->assertSame(1, Venue::count());
        $this->assertSame(1, AssistantQuery::count());
    }

    public function test_sql_injection_in_fallback_text_is_harmless(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask("'; DROP TABLE venues; -- football tomorrow 6pm")->assertOk();
        $this->assertSame(1, Venue::count());
    }

    public function test_arabic_dialect_query_fallback(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('بكرا الساعة 6 مساءً بالجلاء كرة قدم')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء')->json('data.reply_text'));
    }

    public function test_arabic_query_without_sport_asks_for_details_in_arabic(): void
    {
        config(['services.gemini.key' => null]);

        $reply = $this->ask('بكرا الساعة 6 مساءً بالجلاء')->assertOk()->json('data.reply_text');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $reply);
    }

    public function test_english_query_replies_in_english(): void
    {
        config(['services.gemini.key' => null]);

        $reply = $this->ask('Football court tomorrow at 6pm near Gaza City')->assertOk()->json('data.reply_text');
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $reply);
        $this->assertStringContainsString('40 ILS', $reply);
    }

    public function test_english_query_with_an_arabic_place_name_still_replies_in_english(): void
    {
        config(['services.gemini.key' => null]);

        $reply = $this->ask('football tomorrow at 6pm in الجلاء')->assertOk()->json('data.reply_text');
        $this->assertDoesNotMatchRegularExpression('/^\P{Latin}*$/u', $reply);
        $this->assertStringContainsString('available', $reply);
    }

    public function test_past_hour_today_is_not_suggested(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-03', 'start_time' => '08:00:00', 'end_time' => '09:00:00']);

        // It's 12:00 in Gaza; an 8am slot today has already passed.
        $this->ask('football today at 8am')->assertOk()->assertJsonPath('data.suggested_venue_id', null);
    }

    public function test_rate_limit_returns_429_in_unified_shape_after_20_calls(): void
    {
        config(['services.gemini.key' => null]);

        for ($i = 0; $i < 20; $i++) {
            $this->ask('football tomorrow at 6pm')->assertOk();
        }

        $this->ask('football tomorrow at 6pm')
            ->assertStatus(429)
            ->assertJson(['success' => false, 'data' => null, 'errors' => null])
            ->assertJsonStructure(['success', 'data', 'message', 'errors']);
        $this->assertSame(20, AssistantQuery::count());

        // Per user: another player is unaffected.
        $this->actingAsRole('player');
        $this->ask('football tomorrow at 6pm')->assertOk();
    }

    public function test_rate_limited_response_carries_retry_after(): void
    {
        config(['services.gemini.key' => null]);

        for ($i = 0; $i < 20; $i++) {
            $this->ask('x');
        }

        $this->assertTrue($this->ask('x')->assertStatus(429)->headers->has('Retry-After'));
    }

    public function test_query_validation(): void
    {
        $this->postJson('/api/v1/assistant/query', [])->assertStatus(422)->assertJsonValidationErrors('query_text');
        $this->postJson('/api/v1/assistant/query', ['query_text' => str_repeat('a', 501)])->assertStatus(422);
    }
}
