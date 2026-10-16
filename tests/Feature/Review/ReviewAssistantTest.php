<?php

namespace Tests\Feature\Review;

use App\Models\AssistantQuery;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Review tests for the booking assistant endpoint: every way Gemini can fail or answer
 * oddly still gets a sensible reply, inputs are inert, and replies follow the query's language.
 */
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
        config([
            'app.local_timezone' => 'Asia/Gaza',
            'services.gemini.key' => 'test-key',
            'services.gemini.model' => 'gemini-test',
        ]);
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

    private function fakeArgs(mixed $args, string $name = VenueSearchTool::NAME): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]]]],
        ])]);
    }

    private function assertFallbackAnswer($response): void
    {
        $response->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    // ---------------------------------------------------------------- fallback triggers

    public static function brokenGeminiReplies(): array
    {
        return [
            'http 500' => [500, ['error' => 'x']],
            'http 429 quota' => [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]],
            'http 400' => [400, ['error' => 'bad request']],
            'http 403 bad key' => [403, ['error' => 'PERMISSION_DENIED']],
            'non-json body' => [200, '<html>oops</html>'],
            'empty body' => [200, ''],
            'no candidates' => [200, ['candidates' => []]],
            'parts as a string' => [200, ['candidates' => [['content' => ['parts' => 'nope']]]]],
            'part as a string' => [200, ['candidates' => [['content' => ['parts' => ['nope']]]]]],
            'function call as a string' => [200, ['candidates' => [['content' => ['parts' => [['functionCall' => 'search']]]]]]],
            'text instead of a call' => [200, ['candidates' => [['content' => ['parts' => [['text' => 'Which sport?']]]]]]],
            'safety block' => [200, ['promptFeedback' => ['blockReason' => 'SAFETY']]],
        ];
    }

    #[DataProvider('brokenGeminiReplies')]
    public function test_a_broken_gemini_reply_falls_back(int $status, mixed $body): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status, is_string($body) ? ['Content-Type' => 'text/html'] : [])]);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_function_call_with_args_as_a_string_falls_back(): void
    {
        $this->fakeArgs('sport=football');

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_function_call_without_args_falls_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => VenueSearchTool::NAME]]]]]],
        ])]);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_a_call_to_another_function_falls_back(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-10', 'hour' => '09:00'], 'book_venue');

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_function_call_with_non_string_args_falls_back(): void
    {
        $this->fakeArgs(['sport' => ['Football'], 'date' => 20261004, 'hour' => 18]);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_function_call_with_invalid_values_falls_back(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-02-31', 'hour' => '6pm']);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_a_past_date_from_gemini_is_replaced_by_the_fallback_reading(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2025-10-04', 'hour' => '18:00']);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_unknown_sport_from_gemini_is_replaced_by_the_fallback_reading(): void
    {
        // Gemini returns a sport that isn't in the SPORT table; the rule-based parser understood "football".
        $this->fakeArgs(['sport' => 'Cricket', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_a_short_hour_from_gemini_is_padded(): void
    {
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '9:00']);

        $this->ask('football tomorrow at 9 in the morning')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', '09:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);

        $this->assertDatabaseHas('assistant_queries', ['parsed_hour' => '09:00:00']);
    }

    public function test_unknown_argument_keys_from_gemini_are_ignored(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'suggested_venue_id' => '999', '0' => 'x']);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
    }

    public function test_http_500_then_429_from_gemini_both_fall_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(['error' => 'x'], 500)->push(['error' => 'quota'], 429)]);

        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
        $this->assertFallbackAnswer($this->ask('football tomorrow at 6pm'));
        $this->assertSame(2, AssistantQuery::count());
    }

    // ---------------------------------------------------------------- inert inputs

    public function test_like_wildcards_from_gemini_area_do_not_match_everything(): void
    {
        $this->fakeArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => '%']);

        // Assistant v2 review: "%" names no place, so Gemini's "%" area is ignored and nothing matches "everything" through LIKE.
        $response = $this->ask('football tomorrow at 6pm in %')->assertOk();
        $this->assertSame((new VenueSearchTool)->execute(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00'])?->venue_id, $response->json('data.suggested_venue_id'));
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
        $this->assertDatabaseHas('assistant_queries', ['query_text' => "'; DROP TABLE venues; -- football tomorrow 6pm"]);
    }

    // ---------------------------------------------------------------- Arabic and language

    public function test_arabic_dialect_query_fallback(): void
    {
        config(['services.gemini.key' => null]);

        $this->assertFallbackAnswer($this->ask('بكرا الساعة 6 مساءً بالجلاء كرة قدم'));
        $this->assertFallbackAnswer($this->ask('بدي كورة بكره 6 المسا'));
        $this->assertFallbackAnswer($this->ask('فوتبول بكرا الساعة ٦ مساءً'));

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء')->json('data.reply_text'));
    }

    public function test_arabic_query_without_sport_asks_for_details_in_arabic(): void
    {
        config(['services.gemini.key' => null]);
        // Assistant v2 assumes the only sport on offer, so a second one keeps "which sport?" asked here.
        $this->makeSlot($this->makeVenue(null, ['name_en' => 'Hoops Hall']), ['sport_id' => $this->makeSport('Basketball', 'كرة السلة')->id]);

        $this->ask('بكرا الساعة 6 مساءً بالجلاء')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'أي رياضة؟');
    }

    public function test_english_query_replies_in_english(): void
    {
        config(['services.gemini.key' => null]);

        $reply = $this->ask('Football court tomorrow at 6pm near Gaza City')->assertOk()->json('data.reply_text');
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $reply);
        $this->assertStringContainsString('Green Field Court', $reply);
        $this->assertStringContainsString('18:00', $reply);
        $this->assertStringContainsString('40 ILS', $reply);
        $this->assertStringContainsString('book', $reply);
    }

    public function test_english_query_with_an_arabic_place_name_still_replies_in_english(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('football tomorrow at 6pm in الجلاء')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id)
            ->assertJsonPath('data.reply_text', 'Green Field Court is available tomorrow at 18:00 for 40 ILS/hour. Want me to book it?');
    }

    public function test_arabic_query_with_an_english_word_still_replies_in_arabic(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('بدي ملعب football بكرا الساعة 6 مساءً بالجلاء')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'يتوفر موعد في ملعب الحقل الأخضر غداً الساعة 18:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟');
    }

    public function test_past_hour_today_is_not_suggested(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-03', 'start_time' => '08:00:00', 'end_time' => '09:00:00']);

        // It's 12:00 in Gaza; an 8am slot today has already passed.
        $this->ask('football today at 8am')->assertOk()->assertJsonPath('data.suggested_venue_id', null);
    }
}
