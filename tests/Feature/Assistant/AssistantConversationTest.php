<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantConversationTurn;
use App\Models\AssistantQuery;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\AsksTheAssistant;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Assistant v2: conversation memory. A follow-up is read against what earlier turns
 * understood; conversations belong to one player and expire when idle.
 */
class AssistantConversationTest extends TestCase
{
    use AsksTheAssistant, BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssistant();
        $this->seedVenues();
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-04', 'start_time' => '20:00:00', 'end_time' => '21:00:00', 'hourly_price' => 40]);
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-05', 'start_time' => '20:00:00', 'end_time' => '21:00:00', 'hourly_price' => 50]);
        $this->makeSlot($this->venues['south'], ['slot_date' => '2026-10-04', 'start_time' => '21:00:00', 'end_time' => '22:00:00', 'hourly_price' => 60]);
    }

    // ---------------------------------------------------------------- follow-ups

    public function test_an_hour_given_as_a_follow_up_completes_the_request(): void
    {
        $first = $this->ask('بدي ملعب كورة بكرا')->assertOk()
            ->assertJsonPath('data.outcome', 'needs_details')
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', null)
            ->assertJsonPath('data.reply_text', 'أي ساعة؟ المتاح مساءً: 20:00 و21:00.');
        $conversationId = $first->json('data.conversation_id');
        $this->assertTrue(Str::isUuid($conversationId));

        // "8" has no letters, so the reply stays in the conversation's Arabic.
        $this->ask('8')->assertOk()
            ->assertJsonPath('data.conversation_id', $conversationId)
            ->assertJsonPath('data.outcome', 'suggestion')
            ->assertJsonPath('data.parsed_date', '2026-10-04')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id)
            ->assertJsonPath('data.reply_text', 'يتوفر موعد في ملعب الجلاء غداً الساعة 20:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟');
    }

    public function test_the_same_follow_up_in_english_and_arabizi(): void
    {
        $this->ask('football tomorrow')->assertJsonPath('data.outcome', 'needs_details');
        $this->ask('at 8')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.reply_text', 'Al-Jalaa Stadium is available tomorrow at 20:00 for 40 ILS/hour. Want me to book it?');

        $this->newConversation();
        $this->ask('kora bukra')->assertJsonPath('data.outcome', 'needs_details');
        $this->ask('3al 8')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
    }

    public function test_an_explicit_change_overrides_the_earlier_value(): void
    {
        $this->ask('كورة بكرا الساعة 8')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);

        $this->ask('لا خليها بعد بكرا')
            ->assertJsonPath('data.parsed_date', '2026-10-05')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);

        $this->ask('الساعة 9')
            ->assertJsonPath('data.parsed_date', '2026-10-05')
            ->assertJsonPath('data.parsed_hour', '21:00')
            ->assertJsonPath('data.outcome', 'no_match');
    }

    public function test_a_place_given_later_narrows_the_search_and_a_new_request_starts_over(): void
    {
        $this->ask('كورة بكرا')->assertJsonPath('data.outcome', 'needs_details');
        $this->ask('بخانيونس')
            ->assertJsonPath('data.outcome', 'needs_details')
            // Only Khan Younis' free hours are offered now, and no "how to search" buttons.
            ->assertJsonPath('data.reply_text', 'أي ساعة؟ المتاح مساءً: 21:00.')
            ->assertJsonPath('data.options', []);
        $this->ask('9')->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);

        // Naming a sport and a day again is a new request: Khan Younis is not kept.
        $this->ask('football on monday at 8pm')
            ->assertJsonPath('data.parsed_date', '2026-10-05')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);
    }

    public function test_new_search_forgets_the_request(): void
    {
        $this->ask('كورة بكرا الساعة 8');

        $this->ask('بحث جديد')
            ->assertJsonPath('data.outcome', 'answer')
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.reply_text', 'تمام، لنبدأ من جديد. ما الرياضة واليوم والساعة التي تريدها؟');

        // Nothing is left to complete: only Football is offered, so the day is asked for.
        $this->ask('الساعة 8')->assertJsonPath('data.reply_text', 'أي يوم؟');
    }

    public function test_yes_after_a_suggestion_says_how_to_book_it_and_no_asks_again(): void
    {
        $this->ask('كورة بكرا الساعة 8');

        $this->ask('تمام')
            ->assertJsonPath('data.intent', 'confirm_booking')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id)
            ->assertJsonPath('data.suggestions.0.venue_id', $this->venues['jalaa']->id)
            ->assertJsonPath('data.reply_text', 'لا أستطيع إتمام الحجز بنفسي: افتح الاقتراح لتنتقل إلى شاشة الحجز، ثم أدخل بيانات كابتن الفريق وأكّد، وارفع إيصال الدفع خلال 10 دقائق.');

        $this->ask('لا')
            ->assertJsonPath('data.outcome', 'needs_details')
            ->assertJsonPath('data.reply_text', 'تمام. أخبرني بيوم أو ساعة أخرى، أو اختر طريقة البحث.');
        $this->ask('9')->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);
    }

    public function test_yes_for_a_slot_taken_meanwhile_searches_again(): void
    {
        $this->ask('كورة بكرا الساعة 8')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
        $this->venues['jalaa']->timeSlots()->update(['status' => 'blocked']);

        // Only South Court's 21:00 is left, an hour away: offered as the closest time.
        $this->ask('yes')
            ->assertJsonPath('data.outcome', 'no_match')
            ->assertJsonPath('data.suggestions.0.exact', false)
            ->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id)
            ->assertJsonPath('data.reply_text', 'Nothing is free at exactly 20:00. Closest times: South Court tomorrow at 21:00 (60 ILS). Which one would you like?');
    }

    public function test_a_question_in_the_middle_keeps_the_request(): void
    {
        $this->ask('كورة بكرا');
        $this->ask('كيف بدفع؟')->assertJsonPath('data.intent', 'payment');
        $this->ask('8')->assertJsonPath('data.parsed_hour', '20:00')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
    }

    public function test_without_a_conversation_id_every_message_stands_alone(): void
    {
        $first = $this->ask('بدي ملعب كورة بكرا')->json('data.conversation_id');
        $this->newConversation();

        $second = $this->ask('8')
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.suggested_venue_id', null)
            ->json('data.conversation_id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, AssistantConversation::count());
    }

    // ---------------------------------------------------------------- persistence and ownership

    public function test_every_turn_is_logged_and_kept_in_its_conversation(): void
    {
        $this->ask('بدي ملعب كورة بكرا');
        $this->ask('8');

        $conversation = AssistantConversation::where('public_id', $this->conversationId)->firstOrFail();
        $this->assertSame($this->player->id, $conversation->user_id);
        $this->assertSame(2, AssistantQuery::count(), 'US-4.5: every query is still logged');
        $this->assertSame(['بدي ملعب كورة بكرا', '8'], $conversation->turns()->orderBy('id')->pluck('query_text')->all());
        $this->assertSame(AssistantQuery::orderBy('id')->pluck('id')->all(), $conversation->turns()->orderBy('id')->pluck('assistant_query_id')->all());
        $this->assertDatabaseHas('assistant_queries', ['query_text' => '8', 'parsed_date' => '2026-10-04', 'parsed_hour' => '20:00:00', 'suggested_venue_id' => $this->venues['jalaa']->id]);
        $this->assertSame(['booking_search', 'booking_search'], AssistantConversationTurn::orderBy('id')->pluck('intent')->all());
        $this->assertSame(['needs_details', 'suggestion'], AssistantConversationTurn::orderBy('id')->pluck('outcome')->all());
        $this->assertSame('2026-10-04', $conversation->state['date']);
    }

    public function test_another_players_conversation_is_forbidden_and_an_unknown_one_not_found(): void
    {
        $this->ask('بدي ملعب كورة بكرا');
        $owned = $this->conversationId;

        $this->actingAsRole('player');
        $this->postJson('/api/v1/assistant/query', ['query_text' => '8', 'conversation_id' => $owned])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'data' => null, 'message' => 'This conversation belongs to another player.', 'errors' => null]);

        $this->postJson('/api/v1/assistant/query', ['query_text' => '8', 'conversation_id' => (string) Str::uuid()])
            ->assertNotFound()
            ->assertJson(['success' => false, 'data' => null]);

        $this->postJson('/api/v1/assistant/query', ['query_text' => '8', 'conversation_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation_id']);

        // Nothing was answered or logged for the other player.
        $this->assertSame(1, AssistantQuery::count());
    }

    public function test_an_idle_conversation_expires_after_30_minutes(): void
    {
        $this->ask('بدي ملعب كورة بكرا');
        $old = $this->conversationId;

        $this->travel(29)->minutes();
        $this->ask('8')->assertJsonPath('data.conversation_id', $old)->assertJsonPath('data.parsed_hour', '20:00');

        $this->ask('بكرا');
        $this->travel(31)->minutes();

        $fresh = $this->ask('8')
            ->assertJsonPath('data.parsed_date', null)
            ->json('data.conversation_id');
        $this->assertNotSame($old, $fresh);
    }

    public function test_the_shared_location_is_rounded_and_forgotten_when_the_conversation_expires(): void
    {
        $this->ask('ملاعب قريبة مني', ['latitude' => 31.509876, 'longitude' => 34.462345]);
        $conversation = AssistantConversation::where('public_id', $this->conversationId)->firstOrFail();
        $this->assertSame([31.51, 34.462], [$conversation->state['latitude'], $conversation->state['longitude']]);

        $this->travel(31)->minutes();
        $this->ask('مرحبا');

        $this->assertArrayNotHasKey('latitude', $conversation->fresh()->state);
    }

    // ---------------------------------------------------------------- Gemini

    public function test_gemini_sees_a_short_history_and_what_was_settled_but_no_personal_data(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->fakeGemini(fn (Request $request) => Http::response(['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['name' => VenueSearchTool::NAME, 'args' => count($request['contents']) === 1
                ? ['sport' => 'Football', 'date' => '2026-10-04']
                : ['hour' => '20:00']],
        ]]]]]]));

        $this->ask('football tomorrow', ['latitude' => 31.51, 'longitude' => 34.46]);
        $this->ask('8')->assertJsonPath('data.parsed_hour', '20:00')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);

        Http::assertSentCount(2);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0])->values();
        $followUp = $requests[1];
        $this->assertSame(['user', 'model', 'user'], array_column($followUp['contents'], 'role'));
        $this->assertSame('football tomorrow', $followUp['contents'][0]['parts'][0]['text']);
        $this->assertSame('8', $followUp['contents'][2]['parts'][0]['text']);
        $this->assertStringContainsString('settled: sport Football, date 2026-10-04', $followUp['systemInstruction']['parts'][0]['text']);

        foreach ($requests as $request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            foreach ([$this->player->name, $this->player->email, (string) $this->player->phone, '31.51', '34.46'] as $personal) {
                if ($personal !== '') {
                    $this->assertStringNotContainsString($personal, $body);
                }
            }
        }
    }

    public function test_gemini_repeating_an_earlier_value_does_not_override_the_state(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Gemini answers the follow-up "بعد بكرا" with the sport and city from the history too.
        $this->fakeGemini(['sport' => 'Football', 'date' => '2026-10-05', 'city' => 'Gaza']);

        $this->ask('كورة بكرا الساعة 8');
        $this->ask('بعد بكرا')
            ->assertJsonPath('data.parsed_date', '2026-10-05')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);
    }

    public function test_a_gemini_failure_on_a_follow_up_falls_back_silently(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['error' => ['message' => 'unavailable']], 503)]);

        $this->ask('بدي ملعب كورة بكرا')->assertOk()->assertJsonPath('data.outcome', 'needs_details');
        $this->ask('8')->assertOk()->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
    }

    // ---------------------------------------------------------------- contract

    public function test_the_response_adds_only_optional_fields_after_the_7_8_ones(): void
    {
        $data = $this->ask('football tomorrow at 8pm')->assertOk()->json('data');

        $this->assertSame([
            'assistant_query_id', 'parsed_sport_id', 'parsed_date', 'parsed_hour', 'suggested_venue_id', 'reply_text',
            'conversation_id', 'intent', 'outcome', 'parsed_date_range', 'suggestions', 'options',
        ], array_keys($data));
        $this->assertSame('booking_search', $data['intent']);
        $this->assertSame('suggestion', $data['outcome']);
        $this->assertNull($data['parsed_date_range']);
        $this->assertSame($data['suggested_venue_id'], $data['suggestions'][0]['venue_id']);
        $this->assertSame([
            'venue_id', 'time_slot_id', 'name_ar', 'name_en', 'area_ar', 'area_en', 'city_ar', 'city_en',
            'date', 'start_time', 'end_time', 'price', 'rating', 'distance_km', 'exact',
        ], array_keys($data['suggestions'][0]));
        $this->assertSame(['ملعب الجلاء', 'Al-Jalaa Stadium', 'الجلاء', 'Al-Jalaa', 'غزة', 'Gaza', '2026-10-04', '20:00', '21:00', 40, 4, null, true], array_values(array_slice($data['suggestions'][0], 2)));
    }
}
