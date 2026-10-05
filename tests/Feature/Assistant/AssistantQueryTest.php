<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantQuery;
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
        // 09:00 UTC is 12:00 in Gaza, so "today" is Saturday 2026-10-03 and "tomorrow" 2026-10-04.
        $this->travelTo('2026-10-03 09:00:00');
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

    // ---------------------------------------------------------------- happy path and contract

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

    public function test_gemini_request_uses_function_calling_and_knows_todays_local_date(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->ask('Football tomorrow at 6pm')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $declaration = $request['tools'][0]['functionDeclarations'][0];
            $instruction = $request['systemInstruction']['parts'][0]['text'];

            return $request->method() === 'POST'
                && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'test-key')
                && $declaration === (new VenueSearchTool)->declaration()
                && $request['toolConfig']['functionCallingConfig'] === ['mode' => 'ANY', 'allowedFunctionNames' => [VenueSearchTool::NAME]]
                && str_contains($instruction, 'Today is Saturday 2026-10-03')
                && str_contains($instruction, 'tomorrow is 2026-10-04')
                && $request['contents'][0]['parts'][0]['text'] === 'Football tomorrow at 6pm';
        });
    }

    public function test_gemini_only_extracts_arguments_the_venue_and_price_come_from_the_database(): void
    {
        // Even if the model adds a venue or price of its own, only the search result is used.
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'venue' => 'Fake Arena', 'price' => '1']);

        $this->ask('Football tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id)
            ->assertJsonPath('data.reply_text', 'Green Field Court is available tomorrow at 18:00 for 40 ILS/hour. Want me to book it?');
    }

    public function test_arabic_questions_get_an_arabic_reply(): void
    {
        $this->fakeGeminiCall(['sport' => 'كرة القدم', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => 'الجلاء']);

        $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id)
            ->assertJsonPath('data.reply_text', 'يتوفر موعد في ملعب الحقل الأخضر غداً الساعة 18:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟');
    }

    public function test_reply_for_today_and_a_later_date(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-03', 'start_time' => '20:00:00', 'end_time' => '21:00:00', 'hourly_price' => 35.5]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-12', 'start_time' => '19:00:00', 'end_time' => '20:00:00']);

        $this->ask('football today at 8pm')
            ->assertJsonPath('data.reply_text', 'Green Field Court is available today at 20:00 for 35.5 ILS/hour. Want me to book it?');
        $this->ask('football on 2026-10-12 at 7pm')
            ->assertJsonPath('data.reply_text', 'Green Field Court is available on 2026-10-12 at 19:00 for 40 ILS/hour. Want me to book it?');
    }

    // ---------------------------------------------------------------- no match

    public function test_no_matching_venue_gets_the_fallback_message_and_is_logged(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '22:00']);

        $this->ask('Football tomorrow at 10pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.parsed_hour', '22:00')
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'No venues match that request — try a different time.');

        $this->assertDatabaseHas('assistant_queries', [
            'user_id' => $this->player->id,
            'parsed_sport_id' => $this->football->id,
            'parsed_date' => '2026-10-04',
            'parsed_hour' => '22:00:00',
            'suggested_venue_id' => null,
        ]);
    }

    public function test_no_match_reply_is_localized(): void
    {
        $this->fakeGeminiCall(['sport' => 'كرة القدم', 'date' => '2026-10-04', 'hour' => '22:00']);

        $this->ask('كرة قدم بكرا الساعة 10 بالليل')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'لا توجد ملاعب تطابق طلبك — جرّب وقتاً آخر.');
    }

    public function test_asks_for_details_when_the_sport_or_hour_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('hello there')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', null)
            ->assertJsonPath('data.parsed_hour', null)
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'Tell me the sport, day and hour you want, for example: "Football tomorrow at 6pm".');

        $this->assertDatabaseHas('assistant_queries', ['query_text' => 'hello there', 'parsed_sport_id' => null, 'parsed_hour' => null]);
    }

    // ---------------------------------------------------------------- fallback

    public function test_falls_back_to_the_rule_based_parser_when_gemini_errors(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);

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

    // ---------------------------------------------------------------- what the player did not say

    public function test_an_hour_gemini_invents_is_ignored_and_the_player_is_asked(): void
    {
        // Mode ANY makes Gemini return an hour even for "football tomorrow".
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->ask('football tomorrow')
            ->assertOk()
            ->assertJsonPath('data.parsed_sport_id', $this->football->id)
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', null)
            ->assertJsonPath('data.suggested_venue_id', null)
            // Only the hour is missing, so only the hour is asked for, with the free hours that evening.
            ->assertJsonPath('data.reply_text', 'What time would you like to play? Free that evening: 18:00.');

        $this->ask('بدي كورة بكرا')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', null)
            ->assertJsonPath('data.reply_text', 'أي ساعة؟ المتاح مساءً: 18:00.');

        $this->assertDatabaseHas('assistant_queries', ['query_text' => 'football tomorrow', 'parsed_hour' => null, 'suggested_venue_id' => null]);
    }

    public function test_a_morning_request_is_offered_the_free_morning_hours(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $this->ask('بدي كورة بكرا الصبح')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', null)
            ->assertJsonPath('data.reply_text', 'أي ساعة؟ المتاح صباحاً: 09:00 و10:00.');

        $this->ask('football tomorrow morning')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'What time would you like to play? Free that morning: 09:00 and 10:00.');

        // Without a part of the day, the evening hours are offered as before.
        $this->ask('football tomorrow')
            ->assertOk()
            ->assertJsonPath('data.reply_text', 'What time would you like to play? Free that evening: 18:00.');
    }

    public function test_an_hour_given_in_words_keeps_geminis_hour(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        $this->ask('football tomorrow at six in the evening')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_a_number_that_is_not_an_hour_does_not_keep_geminis_hour(): void
    {
        // A count, a price, a day of the month or "one court" is not an hour, so the hour
        // Gemini filled in is dropped and the player is asked for it.
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        foreach (['بدنا ملعب كورة بكرا لعشرة شباب', 'بدي ملعب كورة بكرا ب 20 شيكل', 'I need one football court tomorrow', 'football 5 vs 5 tomorrow'] as $text) {
            $this->ask($text)
                ->assertOk()
                ->assertJsonPath('data.parsed_hour', null)
                ->assertJsonPath('data.suggested_venue_id', null);
        }

        $this->ask('football 5 vs 5 tomorrow at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_a_day_gemini_invents_is_ignored_unless_the_player_said_now(): void
    {
        $today = $this->makeSlot($this->venue, ['slot_date' => '2026-10-03', 'hourly_price' => 30]);
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-03', 'hour' => '18:00']);

        $this->ask('football at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'Which day would you like to play?');

        $this->ask('هلق بدي كورة الساعة 6')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', '2026-10-03')
            ->assertJsonPath('data.suggested_venue_id', $today->venue_id);
    }

    public function test_without_gemini_a_missing_day_is_asked_for_too(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('كورة الساعة 6 المسا')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.parsed_hour', '18:00')
            ->assertJsonPath('data.reply_text', 'أي يوم؟');
    }

    // ---------------------------------------------------------------- city and area from Gemini

    public function test_a_city_gemini_writes_with_city_is_still_found(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'city' => 'مدينة غزة']);

        $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 بمدينة غزة')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_an_unknown_city_is_not_covered_instead_of_suggesting_another_city(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'city' => 'Jerusalem']);

        $this->ask('football tomorrow at 6pm in Jerusalem')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', "We don't cover Jerusalem yet — we currently cover: Gaza.");

        // The fallback notices the place too.
        config(['services.gemini.key' => null]);

        $this->ask('بدي ملعب كرة قدم بكرا الساعة 6 بالقدس')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'لا نغطي القدس بعد — نغطي حالياً: غزة.');
    }

    public function test_a_city_given_as_the_area_does_not_override_a_different_city(): void
    {
        $khanYounis = $this->makeCity('Khan Younis', 'خانيونس');
        $south = $this->makeVenue(null, ['city_id' => $khanYounis->id, 'name_en' => 'South Court', 'avg_rating' => 1]);
        $this->makeSlot($south, ['slot_date' => '2026-10-04']);
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'city' => 'Khan Younis', 'area' => 'Gaza']);

        $this->ask('football tomorrow at 6pm in Khan Younis')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $south->id);
    }

    public function test_an_area_gemini_names_that_no_venue_is_in_uses_the_fallback_reading(): void
    {
        $this->fakeGeminiCall(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'area' => 'Jalaa Stadium Street']);

        $this->ask('football tomorrow at 6pm near al jalaa')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id);
    }

    public function test_an_unknown_area_in_a_known_city_is_said_plainly(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('كورة بكرا الساعة 6 بحي الصبرة بغزة')
            ->assertOk()
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'لم أجد ملعباً في الصبرة — جرّب منطقة أخرى أو اطلب بدون تحديد المنطقة.');
    }

    // ---------------------------------------------------------------- the slot's own time in the reply

    public function test_the_reply_quotes_the_slot_time_not_the_requested_minute(): void
    {
        config(['services.gemini.key' => null]);

        $this->ask('football tomorrow at 6:30pm')
            ->assertJsonPath('data.parsed_hour', '18:30')
            ->assertJsonPath('data.reply_text', 'Green Field Court is available tomorrow at 18:00 for 40 ILS/hour. Want me to book it?');
    }

    public function test_a_longer_slot_is_quoted_as_a_range(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-05', 'start_time' => '17:00:00', 'end_time' => '20:00:00']);

        $this->ask('football on monday at 6pm')
            ->assertJsonPath('data.reply_text', 'Green Field Court is available on 2026-10-05 from 17:00 to 20:00 for 40 ILS/hour. Want me to book it?');
        $this->ask('كرة قدم يوم الاثنين الساعة 6')
            ->assertJsonPath('data.reply_text', 'يتوفر موعد في ملعب الحقل الأخضر بتاريخ 2026-10-05 من الساعة 17:00 حتى 20:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟');
    }

    public function test_a_slot_ending_at_midnight_is_one_hour(): void
    {
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-04', 'start_time' => '23:00:00', 'end_time' => '00:00:00']);

        $this->ask('football tomorrow at 11pm')
            ->assertJsonPath('data.reply_text', 'Green Field Court is available tomorrow at 23:00 for 40 ILS/hour. Want me to book it?');
    }

    // ---------------------------------------------------------------- local time

    public function test_today_is_the_players_local_date_not_utc(): void
    {
        // 22:30 UTC on the 3rd is 01:30 on the 4th in Gaza.
        $this->travelTo('2026-10-03 22:30:00');
        config(['services.gemini.key' => null]);

        $this->ask('football today at 6pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.suggested_venue_id', $this->venue->id)
            ->assertJsonPath('data.reply_text', 'Green Field Court is available today at 18:00 for 40 ILS/hour. Want me to book it?');

        $this->ask('football tomorrow at 6pm')->assertJsonPath('data.parsed_date', '2026-10-05');
    }

    public function test_a_slot_that_already_started_in_local_time_is_not_suggested(): void
    {
        // 15:30 UTC is 18:30 in Gaza: the 18:00 slot has started even though it is only 15:30 UTC.
        $this->travelTo('2026-10-03 15:30:00');
        config(['services.gemini.key' => null]);
        $this->makeSlot($this->venue, ['slot_date' => '2026-10-03', 'start_time' => '18:00:00', 'end_time' => '20:00:00']);

        $this->ask('football today at 6:30pm')
            ->assertOk()
            ->assertJsonPath('data.parsed_hour', '18:30')
            ->assertJsonPath('data.suggested_venue_id', null);
    }

    // ---------------------------------------------------------------- access and validation

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->ask('football tomorrow at 6pm')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'data' => null, 'message' => 'Unauthenticated.', 'errors' => null]);
    }

    public function test_only_players_can_use_the_assistant(): void
    {
        foreach (['venue_owner', 'admin'] as $role) {
            $this->actingAsRole($role);

            $this->ask('football tomorrow at 6pm')
                ->assertForbidden()
                ->assertJson(['success' => false, 'data' => null, 'errors' => null]);
        }

        $this->assertDatabaseCount('assistant_queries', 0);
    }

    public function test_query_text_is_validated(): void
    {
        config(['services.gemini.key' => null]);

        foreach ([[], ['query_text' => ''], ['query_text' => '   '], ['query_text' => ['football']], ['query_text' => str_repeat('a', 501)]] as $body) {
            $this->postJson('/api/v1/assistant/query', $body)
                ->assertUnprocessable()
                ->assertJson(['success' => false, 'data' => null])
                ->assertJsonValidationErrors(['query_text']);
        }

        $this->post('/api/v1/assistant/query', ['query_text' => "football \xB1\x31"], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query_text']);

        $this->ask(str_repeat('a', 500))->assertOk();
        $this->assertSame(1, AssistantQuery::count());
    }

    // ---------------------------------------------------------------- rate limit

    public function test_each_player_is_limited_to_20_questions_per_hour(): void
    {
        config(['services.gemini.key' => null]);

        foreach (range(1, 20) as $i) {
            $this->ask('football tomorrow at 6pm')->assertOk();
        }

        $limited = $this->ask('football tomorrow at 6pm')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => "You've reached the limit of 20 assistant questions per hour. Please try again later.",
                'errors' => null,
            ]);

        $this->assertTrue($limited->headers->has('Retry-After'));
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(20, AssistantQuery::count());

        // Per player: another player is unaffected.
        $this->actingAsRole('player');
        $this->ask('football tomorrow at 6pm')->assertOk();

        // And the first player can ask again after the hour.
        $this->travel(61)->minutes();
        $this->actingAs($this->player, 'sanctum');
        $this->ask('football tomorrow at 6pm')->assertOk();
    }
}
