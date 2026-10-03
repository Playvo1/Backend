<?php

namespace Tests\Feature\Assistant;

use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class AssistantQueryTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $player;

    private Sport $football;

    private Venue $venue;

    private TimeSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // 09:00 UTC is 12:00 in Gaza, so "today" is 2026-10-03 and "tomorrow" 2026-10-04.
        $this->travelTo('2026-10-03 09:00:00');
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);

        $this->seedRoles();
        $this->player = $this->actingAsRole('player');
        $this->makeCity();
        $this->football = $this->makeSport();
        $this->venue = $this->makeVenue(null, ['area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa', 'avg_rating' => 4.5]);
        $this->slot = $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'hourly_price' => 40]);
    }

    private function fakeGeminiCall(array $args): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]],
            ]]]],
        ])]);
    }

    private function ask(string $text)
    {
        return $this->postJson('/api/v1/assistant/query', ['query_text' => $text]);
    }

    public function test_gemini_parses_the_request_and_the_best_venue_is_suggested(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'city' => 'Gaza']);

        $response = $this->ask('Football court tomorrow at 6pm near Gaza City');

        $response->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OK',
                'errors' => null,
                'data' => [
                    'assistant_query_id' => $response->json('data.assistant_query_id'),
                    'parsed_sport_id' => $this->football->id,
                    'parsed_date' => '2026-10-04',
                    'parsed_hour' => '18:00',
                    'suggested_venue_id' => $this->venue->id,
                    'reply_text' => 'Green Field Court is available tomorrow at 18:00 for 40 ILS/hour. Want me to book it?',
                ],
            ]);

        $this->assertDatabaseHas('assistant_queries', [
            'id' => $response->json('data.assistant_query_id'),
            'user_id' => $this->player->id,
            'query_text' => 'Football court tomorrow at 6pm near Gaza City',
            'parsed_sport_id' => $this->football->id,
            'parsed_date' => '2026-10-04',
            'parsed_hour' => '18:00:00',
            'suggested_venue_id' => $this->venue->id,
        ]);
    }

    public function test_gemini_request_uses_function_calling_and_knows_todays_date(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->ask('Football tomorrow at 6pm')->assertOk();

        Http::assertSent(function (Request $request) {
            $declaration = $request['tools'][0]['functionDeclarations'][0];

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $declaration['name'] === VenueSearchTool::NAME
                && $request['toolConfig']['functionCallingConfig']['mode'] === 'ANY'
                && str_contains($request['systemInstruction']['parts'][0]['text'], 'Today is Saturday 2026-10-03')
                && str_contains($request['systemInstruction']['parts'][0]['text'], 'tomorrow is 2026-10-04')
                && $request['contents'][0]['parts'][0]['text'] === 'Football tomorrow at 6pm';
        });
    }

    public function test_arabic_questions_get_an_arabic_reply(): void
    {
        $this->fakeGeminiCall(['sport' => 'كرة القدم', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => 'الجلاء']);

        $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id)
            ->assertJsonPath('data.reply_text', 'ملعب الحقل الأخضر متاح غداً الساعة 18:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟');
    }

    public function test_no_matching_venue_gets_the_fallback_message(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '22:00']);

        $this->ask('Football tomorrow at 10pm')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.parsed_hour', '22:00')
            ->assertJsonPath('data.reply_text', 'No venues match that request — try a different time.');

        $this->assertDatabaseHas('assistant_queries', ['parsed_hour' => '22:00:00', 'suggested_venue_id' => null]);
    }

    public function test_no_match_reply_is_localized(): void
    {
        $this->fakeGeminiCall(['sport' => 'كرة القدم', 'date' => '2026-10-04', 'hour' => '22:00']);

        $this->ask('كرة قدم بكرا الساعة 10 بالليل')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'لا توجد ملاعب تطابق طلبك — جرّب وقتاً آخر.');
    }

    public function test_falls_back_to_the_rule_based_parser_when_gemini_errors(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 503)]);

        $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_falls_back_when_gemini_times_out(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection('timed out')]);

        $this->ask('football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_falls_back_when_gemini_does_not_call_the_function(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Sure! Which sport?']]]]],
        ])]);

        $this->ask('football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_fallback_fills_an_argument_gemini_left_out(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04']);

        $this->ask('football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_without_an_api_key_gemini_is_never_called(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->ask('Football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);

        Http::assertNothingSent();
    }

    public function test_asks_for_details_when_the_sport_or_hour_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('hello there')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', null)
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'Tell me the sport, day and hour you want, for example: "Football tomorrow at 6pm".');
    }

    public function test_query_text_is_required(): void
    {
        $this->postJson('/api/v1/assistant/query', [])
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['query_text']);

        $this->postJson('/api/v1/assistant/query', ['query_text' => str_repeat('a', 501)])
            ->assertUnprocessable();
    }

    public function test_only_players_can_use_the_assistant(): void
    {
        $this->actingAsRole('venue_owner');

        $this->ask('football tomorrow at 6pm')->assertForbidden();
        $this->assertDatabaseCount('assistant_queries', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->ask('football tomorrow at 6pm')->assertUnauthorized();
    }

    public function test_each_player_is_limited_to_20_questions_per_hour(): void
    {
        config(['services.gemini.key' => null]);

        foreach (range(1, 20) as $i) {
            $this->ask('football tomorrow at 6pm')->assertOk();
        }

        $this->ask('football tomorrow at 6pm')
            ->assertStatus(429)
            ->assertJson(['success' => false, 'data' => null]);

        $this->actingAsRole('player');
        $this->ask('football tomorrow at 6pm')->assertOk();

        $this->travel(61)->minutes();
        $this->actingAs($this->player, 'sanctum');
        $this->ask('football tomorrow at 6pm')->assertOk();
    }
}
