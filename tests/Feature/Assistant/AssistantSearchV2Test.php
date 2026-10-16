<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantQuery;
use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AsksTheAssistant;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Assistant v2 searches: spans of days, lists of up to five options ranked by rating, price
 * or distance, "near me", quick replies, and the data honesty rules for all of them.
 */
class AssistantSearchV2Test extends TestCase
{
    use AsksTheAssistant, BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssistant();
        $this->seedVenues();
    }

    /**
     * Tomorrow (Sunday 4 Oct) every venue has a slot; prices and ratings differ.
     */
    private function slotsTomorrow(): void
    {
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-04', 'start_time' => '20:00:00', 'end_time' => '21:00:00', 'hourly_price' => 50]);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-04', 'start_time' => '20:00:00', 'end_time' => '21:00:00', 'hourly_price' => 35]);
        $this->makeSlot($this->venues['south'], ['slot_date' => '2026-10-04', 'start_time' => '19:00:00', 'end_time' => '21:00:00', 'hourly_price' => 60]);
        $this->makeSlot($this->venues['beach'], ['slot_date' => '2026-10-04', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'hourly_price' => 25]);
    }

    // ---------------------------------------------------------------- spans of days

    public function test_this_week_offers_the_earliest_slot_of_each_venue_from_now_on(): void
    {
        $this->travelTo('2026-10-05 09:00:00'); // Monday 12:00 in Gaza; the week ends on Saturday 10 Oct.
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-05', 'start_time' => '10:00:00', 'end_time' => '11:00:00']); // already over
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-07', 'start_time' => '18:00:00', 'end_time' => '19:00:00']);
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-09', 'start_time' => '18:00:00', 'end_time' => '19:00:00']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-05', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $this->makeSlot($this->venues['south'], ['slot_date' => '2026-10-11', 'start_time' => '20:00:00', 'end_time' => '21:00:00']); // next week

        $response = $this->ask('بدي ملعب كورة هالأسبوع')->assertOk()
            ->assertJsonPath('data.outcome', 'suggestion')
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.parsed_date_range', ['period' => 'this_week', 'from' => '2026-10-05', 'to' => '2026-10-10'])
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);

        $this->assertSame(['Green Field Court', 'Al-Jalaa Stadium'], $this->suggested($response));
        $this->assertSame(['2026-10-07', '2026-10-05'], array_column($response->json('data.suggestions'), 'date'));
        $this->assertSame(
            'أفضل الخيارات المتاحة هذا الأسبوع: ملعب الحقل الأخضر الأربعاء 7 أكتوبر الساعة 18:00 (40 شيكل)، ملعب الجلاء اليوم الساعة 20:00 (40 شيكل). أيها تريد؟',
            $response->json('data.reply_text'),
        );
        $this->assertDatabaseHas('assistant_queries', ['query_text' => 'بدي ملعب كورة هالأسبوع', 'parsed_date' => null, 'suggested_venue_id' => $this->venues['green']->id]);
    }

    public function test_next_week_the_weekend_and_next_month_in_each_language(): void
    {
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-09', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-11-02', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);

        // Saturday: next week is Sunday 4 to Saturday 10 October.
        $this->ask('football next week')->assertJsonPath('data.parsed_date_range.period', 'next_week')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);
        $this->newConversation();
        $this->ask('كورة الويكند الجاي')->assertJsonPath('data.parsed_date_range', ['period' => 'next_weekend', 'from' => '2026-10-09', 'to' => '2026-10-10'])
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);
        $this->newConversation();
        $this->ask('kora el shahr el jay')->assertJsonPath('data.parsed_date_range.period', 'next_month')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
        $this->newConversation();
        // This weekend (Saturday) is only today, which has nothing left.
        $this->ask('football this weekend')->assertJsonPath('data.outcome', 'no_match')
            ->assertJsonPath('data.reply_text', 'No venues match that request — try a different time.');
    }

    public function test_any_day_at_an_hour_finds_the_first_day_with_that_hour(): void
    {
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-06', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-08', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-04', 'start_time' => '18:00:00', 'end_time' => '19:00:00']);

        $response = $this->ask('كورة أي يوم الساعة 8')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.parsed_date_range.period', 'any')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);

        $this->assertSame(['2026-10-06'], array_column($response->json('data.suggestions'), 'date'));
    }

    public function test_gemini_may_read_a_span_the_rules_cannot_but_never_a_day_instead(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-03', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        // Mode ANY makes Gemini fill a date too; the span wins and the date is dropped.
        $this->fakeGemini(['sport' => 'Football', 'date' => '2026-10-03', 'period' => 'this_week']);

        $this->ask('كورة الاسبوع')
            ->assertJsonPath('data.parsed_date', null)
            ->assertJsonPath('data.parsed_date_range', ['period' => 'this_week', 'from' => '2026-10-03', 'to' => '2026-10-03'])
            ->assertJsonPath('data.suggested_venue_id', $this->venues['green']->id);

        // Without a span word in the text, Gemini's span is not used.
        $this->newConversation();
        $this->ask('football at 8pm')->assertJsonPath('data.parsed_date_range', null)->assertJsonPath('data.reply_text', 'Which day would you like to play?');
    }

    public function test_a_span_can_be_narrowed_by_a_follow_up_hour(): void
    {
        $this->slotsTomorrow();

        $this->ask('شو في ملاعب الأيام الجاية')->assertJsonPath('data.intent', 'availability');
        $response = $this->ask('الساعة 8 بالليل')
            ->assertJsonPath('data.parsed_hour', '20:00')
            ->assertJsonPath('data.parsed_date_range.period', 'upcoming');

        $this->assertSame(['South Court', 'Green Field Court', 'Al-Jalaa Stadium'], $this->suggested($response));
    }

    // ---------------------------------------------------------------- lists and ranking

    public function test_the_available_venues_are_listed_by_rating_then_price(): void
    {
        $this->slotsTomorrow();

        $response = $this->ask('ما هي ملاعب كرة القدم المتاحة؟')
            ->assertJsonPath('data.intent', 'availability')
            ->assertJsonPath('data.parsed_date_range.period', 'upcoming');

        $this->assertSame(['South Court', 'Green Field Court', 'Al-Jalaa Stadium', 'Beach Court'], $this->suggested($response));
        $this->assertSame($this->venues['south']->id, $response->json('data.suggested_venue_id'));
        $this->assertSame(['cheapest', 'best_rated', 'use_location'], $this->optionIds($response));
    }

    public function test_at_most_five_options_one_per_venue(): void
    {
        foreach (range(1, 7) as $i) {
            $venue = $this->makeVenue(null, ['name_en' => "Court {$i}", 'avg_rating' => $i / 2]);
            $this->makeSlot($venue, ['slot_date' => '2026-10-04', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
            $this->makeSlot($venue, ['slot_date' => '2026-10-04', 'start_time' => '21:00:00', 'end_time' => '22:00:00']);
        }

        $response = $this->ask('football tomorrow, all venues please');

        $this->assertSame(['Court 7', 'Court 6', 'Court 5', 'Court 4', 'Court 3'], $this->suggested($response));
    }

    public function test_cheapest_and_best_rated_re_rank_the_same_request(): void
    {
        $this->slotsTomorrow();

        $this->ask('كورة بكرا الساعة 8')->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);

        $cheapest = $this->ask('الأرخص')->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);
        $this->assertSame(['Al-Jalaa Stadium', 'Green Field Court', 'South Court'], $this->suggested($cheapest));
        $this->assertSame(['best_rated', 'use_location'], $this->optionIds($cheapest));
        $this->assertStringStartsWith('الأرخص: ملعب الجلاء غداً الساعة 20:00 (35 شيكل)', $cheapest->json('data.reply_text'));

        $best = $this->choose('best_rated')->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);
        $this->assertSame(['South Court', 'Green Field Court', 'Al-Jalaa Stadium'], $this->suggested($best));
    }

    public function test_cheapest_and_best_rated_in_one_message(): void
    {
        $this->slotsTomorrow();

        $this->assertSame('Beach Court', $this->suggested($this->ask('cheapest football court tomorrow'))[0]);
        $this->newConversation();
        $this->assertSame('South Court', $this->suggested($this->ask('مين أحسن ملعب كورة بكرا'))[0]);
    }

    public function test_no_slot_at_the_hour_offers_the_closest_times_as_alternatives(): void
    {
        $this->slotsTomorrow();

        $response = $this->ask('football tomorrow at 10pm')
            ->assertJsonPath('data.outcome', 'no_match')
            ->assertJsonPath('data.suggested_venue_id', null)
            // US-4.5 wording, unchanged.
            ->assertJsonPath('data.reply_text', 'No venues match that request — try a different time.');

        // Up to three hours away: the 18:00 slot is too far.
        $this->assertSame([false, false, false], array_column($response->json('data.suggestions'), 'exact'));
        $this->assertSame(['20:00', '20:00', '19:00'], array_column($response->json('data.suggestions'), 'start_time'));
    }

    // ---------------------------------------------------------------- near me

    public function test_near_me_without_a_location_asks_for_it_with_quick_replies(): void
    {
        $this->slotsTomorrow();

        $response = $this->ask('ابحث عن ملاعب قريبة مني')
            ->assertJsonPath('data.outcome', 'needs_location')
            ->assertJsonPath('data.suggested_venue_id', null)
            ->assertJsonPath('data.reply_text', 'شارك موقعك لأجد لك أقرب الملاعب، أو اختر منطقة، أو اعرض كل الملاعب.')
            ->assertJsonPath('data.options', [
                ['id' => 'use_location', 'label' => 'استخدم موقعي'],
                ['id' => 'pick_area', 'label' => 'اختر منطقة'],
                ['id' => 'all_venues', 'label' => 'كل الملاعب'],
            ]);
        $this->assertSame([], $response->json('data.suggestions'));

        // The app shares the location with the button.
        $near = $this->choose('use_location', ['latitude' => 31.5095, 'longitude' => 34.4625])
            ->assertJsonPath('data.outcome', 'suggestion')
            ->assertJsonPath('data.suggested_venue_id', $this->venues['jalaa']->id);

        $this->assertSame(['Al-Jalaa Stadium', 'Green Field Court', 'South Court', 'Beach Court'], $this->suggested($near));
        $this->assertSame([0.1, 1.7, 24.4, null], array_map(fn ($d) => $d === null ? null : (float) $d, array_column($near->json('data.suggestions'), 'distance_km')));
        $this->assertStringStartsWith('الأقرب إليك في الأيام القادمة: ملعب الجلاء غداً الساعة 20:00 (35 شيكل، 0.1 كم)', $near->json('data.reply_text'));
        $this->assertDatabaseHas('assistant_queries', ['query_text' => 'استخدم موقعي', 'suggested_venue_id' => $this->venues['jalaa']->id]);
    }

    public function test_near_me_with_coordinates_in_english(): void
    {
        $this->slotsTomorrow();

        $response = $this->ask('football near me tomorrow at 8pm', ['latitude' => 31.34, 'longitude' => 34.30])
            ->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);

        $this->assertSame(['South Court', 'Al-Jalaa Stadium', 'Green Field Court'], $this->suggested($response));
        $this->assertStringStartsWith('Closest to you: South Court tomorrow at 19:00 (60 ILS, 0 km)', $response->json('data.reply_text'));
    }

    public function test_a_shared_location_breaks_ties_without_near_me(): void
    {
        $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-04', 'hourly_price' => 40]);
        $twin = $this->makeVenue(null, ['name_en' => 'Twin Court', 'avg_rating' => 4.5, 'latitude' => 31.5101, 'longitude' => 34.4621]);
        $this->makeSlot($twin, ['slot_date' => '2026-10-04', 'hourly_price' => 40]);

        $response = $this->ask('football tomorrow, cheapest', ['latitude' => 31.51, 'longitude' => 34.462]);

        $this->assertSame(['Twin Court', 'Green Field Court'], $this->suggested($response));
    }

    public function test_coordinates_are_validated(): void
    {
        foreach ([['latitude' => 91, 'longitude' => 34], ['latitude' => 31, 'longitude' => 181], ['latitude' => 'north', 'longitude' => 34], ['latitude' => 31.5]] as $body) {
            $this->postJson('/api/v1/assistant/query', ['query_text' => 'near me', ...$body])
                ->assertUnprocessable()
                ->assertJson(['success' => false, 'data' => null]);
        }

        $this->assertSame(0, AssistantQuery::count());
    }

    // ---------------------------------------------------------------- quick replies (the design flow)

    public function test_booking_tomorrow_offers_how_to_search_then_all_venues(): void
    {
        $this->slotsTomorrow();

        $this->ask('أريد حجز ملعب كرة قدم غداً')
            ->assertJsonPath('data.outcome', 'needs_details')
            ->assertJsonPath('data.reply_text', 'أي ساعة؟ المتاح مساءً: 18:00، 19:00 و20:00.')
            ->assertJsonPath('data.options.*.id', ['use_location', 'pick_area', 'all_venues']);

        $all = $this->choose('all_venues')->assertJsonPath('data.parsed_date', '2026-10-04');
        $this->assertSame(['South Court', 'Green Field Court', 'Al-Jalaa Stadium', 'Beach Court'], $this->suggested($all));
    }

    public function test_pick_an_area_then_a_city(): void
    {
        $this->slotsTomorrow();
        City::forceCreate(['country_id' => City::first()->country_id, 'name_ar' => 'رفح', 'name_en' => 'Rafah']);

        $this->ask('أريد حجز ملعب كرة قدم غداً');
        $khanYounis = City::where('name_en', 'Khan Younis')->firstOrFail();

        // Only cities with venues are offered.
        $cities = $this->choose('pick_area')
            ->assertJsonPath('data.reply_text', 'اختر المدينة: غزة وخانيونس.')
            ->assertJsonPath('data.options', [
                ['id' => 'city_'.City::where('name_en', 'Gaza')->value('id'), 'label' => 'غزة'],
                ['id' => 'city_'.$khanYounis->id, 'label' => 'خانيونس'],
            ]);
        $this->assertSame('needs_details', $cities->json('data.outcome'));

        $this->choose('city_'.$khanYounis->id)
            ->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id)
            ->assertJsonPath('data.reply_text', 'يتوفر موعد في الملعب الجنوبي غداً من الساعة 19:00 حتى 21:00 بسعر 60 شيكل للساعة. هل تريد أن أحجزه لك؟');
        $this->assertDatabaseHas('assistant_conversation_turns', ['option_id' => 'city_'.$khanYounis->id, 'query_text' => 'خانيونس']);
    }

    public function test_use_location_without_coordinates_asks_for_them(): void
    {
        $this->ask('football tomorrow');

        $this->choose('use_location')
            ->assertJsonPath('data.outcome', 'needs_location')
            ->assertJsonPath('data.reply_text', 'Share your location so I can find the closest venues, or pick an area, or see all venues.');
    }

    public function test_option_ids_are_validated_and_a_tap_needs_no_text(): void
    {
        foreach (['drop_tables', 'city_', 'city_abc', str_repeat('a', 41), 'USE_LOCATION'] as $optionId) {
            $this->postJson('/api/v1/assistant/query', ['option_id' => $optionId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['option_id']);
        }

        // An unknown city id is answered with the list of cities.
        $this->postJson('/api/v1/assistant/query', ['option_id' => 'city_999'])->assertOk()->assertJsonPath('data.outcome', 'needs_details');
        // A tap without a conversation replies in the app's language.
        $this->slotsTomorrow();
        $english = $this->postJson('/api/v1/assistant/query', ['option_id' => 'all_venues'], ['Accept-Language' => 'en-US'])->assertOk();
        $this->assertStringStartsWith('Best available options in the coming days: South Court tomorrow at 19:00', $english->json('data.reply_text'));
        $this->assertDatabaseHas('assistant_queries', ['query_text' => 'All venues']);
        $this->assertMatchesRegularExpression('/^أفضل الخيارات/u', $this->postJson('/api/v1/assistant/query', ['option_id' => 'all_venues'], ['Accept-Language' => 'ar'])->json('data.reply_text'));
    }

    public function test_quick_reply_taps_have_their_own_hourly_bucket(): void
    {
        config(['services.assistant.hourly_limit' => 2, 'services.assistant.option_hourly_limit' => 3]);

        $this->ask('football tomorrow')->assertOk();
        $this->ask('8')->assertOk();
        $this->ask('9')->assertStatus(429);

        foreach (range(1, 3) as $i) {
            $this->choose('all_venues')->assertOk();
        }

        $this->choose('all_venues')->assertStatus(429)
            ->assertJsonPath('message', "You've reached the limit of 3 assistant questions per hour. Please try again later.");
    }

    // ---------------------------------------------------------------- sports

    public function test_the_only_sport_on_offer_is_assumed(): void
    {
        $this->slotsTomorrow();

        $this->ask('أريد حجز ملعب غداً الساعة 8')
            ->assertJsonPath('data.parsed_sport_id', Sport::query()->value('id'))
            ->assertJsonPath('data.suggested_venue_id', $this->venues['south']->id);
    }

    public function test_a_sport_no_venue_offers_is_coming_soon(): void
    {
        $this->slotsTomorrow();

        // Basketball is not in SPORT at all; Padel is, but no venue offers it.
        $this->ask('بدي ملعب كرة سلة بكرا الساعة 8')
            ->assertJsonPath('data.outcome', 'not_offered')
            ->assertJsonPath('data.parsed_sport_id', null)
            ->assertJsonPath('data.reply_text', 'لا تتوفر ملاعب كرة السلة حالياً — المتاح الآن: كرة القدم، ورياضات أخرى قريباً.');

        $this->makeSport('Padel', 'البادل');
        $this->newConversation();
        $this->ask('padel tomorrow at 8pm')
            ->assertJsonPath('data.outcome', 'not_offered')
            ->assertJsonPath('data.reply_text', "Padel isn't available yet — you can book Football now, with more sports coming soon.");
    }

    // ---------------------------------------------------------------- data honesty

    public function test_only_free_future_slots_of_active_venues_offering_the_sport_are_listed(): void
    {
        $sportId = Sport::query()->value('id');
        $ok = $this->makeSlot($this->venues['green'], ['slot_date' => '2026-10-05', 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-05', 'status' => 'booked']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-06', 'status' => 'blocked']);
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-02']); // yesterday
        $this->makeSlot($this->venues['jalaa'], ['slot_date' => '2026-10-03', 'start_time' => '11:00:00', 'end_time' => '12:00:00']); // already started
        $inactive = $this->makeVenue(null, ['name_en' => 'Inactive Court', 'status' => 'inactive', 'avg_rating' => 5]);
        $this->makeSlot($inactive, ['slot_date' => '2026-10-05']);
        $removed = $this->makeVenue(null, ['name_en' => 'Removed Court', 'avg_rating' => 5]);
        $this->makeSlot($removed, ['slot_date' => '2026-10-05']);
        $removed->delete();
        $notOffering = $this->makeVenue(null, ['name_en' => 'Tennis Only Court', 'avg_rating' => 5]);
        TimeSlot::forceCreate(['venue_id' => $notOffering->id, 'sport_id' => $sportId, 'slot_date' => '2026-10-05', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'hourly_price' => 10, 'status' => 'available']);

        foreach (['كورة هالشهر', 'football any day', 'ما هي ملاعب كرة القدم المتاحة؟', 'cheapest football this month', 'كورة أي يوم الساعة 6 المسا'] as $text) {
            $this->newConversation();
            $suggestions = $this->ask($text)->json('data.suggestions');

            $this->assertSame([$ok->id], array_column($suggestions, 'time_slot_id'), $text);
            $this->assertNotContains('Inactive Court', array_column($suggestions, 'name_en'), $text);
        }

        $this->newConversation();
        $this->ask('الأسعار')->assertJsonPath('data.reply_text', 'أسعار ملاعب كرة القدم في الأيام القادمة 40 شيكل للساعة، والأرخص في ملعب الحقل الأخضر. أسعار كل موعد تظهر عند الحجز.');
    }
}
