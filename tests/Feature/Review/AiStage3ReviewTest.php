<?php

namespace Tests\Feature\Review;

use App\Models\AssistantQuery;
use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\AssistantEvaluator;
use App\Services\Assistant\BookingAssistant;
use App\Services\Assistant\VenueSearchTool;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Review tests for Sprint 5 (US-5.4 evaluation) on top of the Sprint 1 search and the
 * Sprint 4 assistant: an unseen set of phrasings, the honesty of the scoring, the
 * assistant:evaluate command, and the behaviour changes made after the Stage 2 review.
 */
class AiStage3ReviewTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private ?\Closure $geminiReply = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.gemini.key' => null,
            'services.gemini.model' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);
        Http::fake(fn (Request $request, array $options) => ($this->geminiReply ?? fn () => Http::response(['candidates' => []]))($request, $options));

        // The evaluation fixture: 5 sports, 5 cities, 9 venues; Saturday 2026-10-03 12:00 in Gaza.
        $this->evaluator()->seedReferenceData();
        $this->now = $this->evaluator()->referenceNow();
    }

    private function evaluator(): AssistantEvaluator
    {
        return $this->app->make(AssistantEvaluator::class);
    }

    private function respond(string $query, bool $useGemini = false, ?CarbonImmutable $now = null): array
    {
        return $this->app->make(BookingAssistant::class)->respond($query, $now ?? $this->now, $useGemini);
    }

    /**
     * The same reading of an answer as AssistantEvaluator::actual().
     */
    private function actual(array $answer): array
    {
        $arguments = $answer['arguments'];

        return [
            'sport' => $answer['sport']?->name_en,
            'date' => $arguments['date'] ?? null,
            'hour' => $arguments['hour'] ?? null,
            'city' => (new VenueSearchTool)->findCity($arguments['city'] ?? null)?->name_en ?? ($arguments['city'] ?? null),
            'area' => $arguments['area'] ?? null,
            'outcome' => $answer['outcome'],
            'venue' => $answer['slot']?->venue?->name_en,
        ];
    }

    /**
     * Strict like the evaluator: sport, date, hour, the place when one is expected, the outcome and the venue.
     *
     * @return list<string> the fields that are wrong
     */
    private function wrongFields(array $expected, array $actual): array
    {
        $wrong = [];

        foreach (['sport', 'date', 'hour', 'outcome', 'venue'] as $field) {
            if (($expected[$field] ?? null) !== $actual[$field]) {
                $wrong[] = $field;
            }
        }

        if (($expected['city'] ?? null) !== null && $expected['city'] !== $actual['city']) {
            $wrong[] = 'city';
        }

        if (($expected['area'] ?? null) !== null && $actual['area'] === null) {
            $wrong[] = 'area';
        }

        return $wrong;
    }

    private function geminiArgs(array $args): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->geminiReply = fn () => Http::response(['candidates' => [['content' => ['parts' => [
            ['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]],
        ]]]]]);
    }

    private static function expect(?string $sport, ?string $date, ?string $hour, string $outcome, ?string $venue = null, ?string $city = null, ?string $area = null): array
    {
        return compact('sport', 'date', 'hour', 'city', 'area', 'outcome', 'venue');
    }

    // ---------------------------------------------------------------- unseen phrasings (fallback only)

    /**
     * 45 requests the developer never saw, 27 Arabic and 18 Latin-script (60% Arabic),
     * written against the evaluation fixture at its reference time.
     */
    public static function unseenQueries(): array
    {
        $s = 'suggestion';
        $n = 'no_match';
        $d = 'needs_details';

        return [
            // Arabic
            'A01 typo كورا' => ['بدي ملعب كورا بكرا الساعة 7 المسا', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
            'A02 hour word ستة' => ['كورة بكرا الساعة ستة المسا', self::expect('Football', '2026-10-04', '18:00', $s, 'South Court')],
            'A03 hour word ثمانية' => ['بدي ملعب كرة قدم بكرا الساعة ثمانية', self::expect('Football', '2026-10-04', '20:00', $s, 'Tal al-Hawa Club')],
            'A04 بعد المغرب (vague)' => ['كرة قدم بكرا بعد المغرب', self::expect('Football', '2026-10-04', null, $d)],
            'A05 بعد العصر 5' => ['كورة اليوم بعد العصر الساعة 5', self::expect('Football', '2026-10-03', '17:00', $n)],
            'A06 بعد الظهر برفح' => ['تنس اليوم الساعة 5 بعد الظهر برفح', self::expect('Tennis', '2026-10-03', '17:00', $s, 'Rafah Padel Club', 'Rafah')],
            'A07 dialect بدنا عالساعة' => ['بدنا ملعب كورة بكرة عالساعة 9 بالليل', self::expect('Football', '2026-10-04', '21:00', $s, 'South Court')],
            'A08 dialect عالتمانية' => ['شو في ملاعب كورة فاضية اليوم عالتمانية؟', self::expect('Football', '2026-10-03', '20:00', $s, 'Al-Jalaa Stadium')],
            'A09 فطبول الخميس' => ['ممكن احجز ملعب فطبول يوم الخميس الساعة 6 المسا؟', self::expect('Football', '2026-10-08', '18:00', $n)],
            'A10 باسكت' => ['بدي العب باسكت بكرا الساعة 7', self::expect('Basketball', '2026-10-04', '19:00', $n)],
            'A11 area بالجلا' => ['كرة قدم بالجلا بكرا الساعة 8', self::expect('Football', '2026-10-04', '20:00', $s, 'Al-Jalaa Stadium', null, 'الجلاء')],
            'A12 area تل الهوا' => ['كورة بتل الهوا بكرا الساعة 8 المسا', self::expect('Football', '2026-10-04', '20:00', $s, 'Tal al-Hawa Club', null, 'تل الهوى')],
            'A13 طائرة بدير البلح' => ['كرة طائرة بدير البلح بعد بكرا الساعة 6 المسا', self::expect('Volleyball', '2026-10-05', '18:00', $s, 'Deir Beach Court', 'Deir al-Balah')],
            'A14 بادل الاثنين بالليل' => ['بادل يوم الاثنين الساعة 8 بالليل', self::expect('Padel', '2026-10-05', '20:00', $s, 'Rafah Padel Club')],
            'A15 mixed football' => ['بدي ملعب football بكرا الساعة 6 المسا', self::expect('Football', '2026-10-04', '18:00', $s, 'South Court')],
            'A16 mixed basketball' => ['basketball بخانيونس الخميس الساعة 6', self::expect('Basketball', '2026-10-08', '18:00', $s, 'South Court', 'Khan Younis')],
            'A17 no sport' => ['في ملعب فاضي بكرا الساعة 9 بالليل؟', self::expect(null, '2026-10-04', '21:00', $d)],
            'A18 لو سمحت' => ['لو سمحت بدي ملعب كرة قدم بكرا الساعة 7 المسا', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
            'A19 مرحبا الجمعة ٦' => ['مرحبا، الله يعطيك العافية، بدي ملعب كورة يوم الجمعة الساعة ٦ المسا', self::expect('Football', '2026-10-09', '18:00', $s, 'Green Field Court')],
            'A20 سلة ٧' => ['كرة سلة بعد بكرا الساعة ٧ المسا', self::expect('Basketball', '2026-10-05', '19:00', $s, 'Tal al-Hawa Club')],
            'A21 date ٩/١٠' => ['كرة قدم يوم ٩/١٠ الساعة ٦', self::expect('Football', '2026-10-09', '18:00', $s, 'Green Field Court')],
            'A22 ٧:٠٠ مساءً' => ['تنس بكرا الساعة ٧:٠٠ مساءً', self::expect('Tennis', '2026-10-04', '19:00', $s, 'Rafah Padel Club')],
            'A23 11 بالليل today' => ['كورة اليوم الساعة 11 بالليل', self::expect('Football', '2026-10-03', '23:00', $n)],
            'A24 الليلة 10' => ['بدي ملعب كرة قدم الليلة الساعة 10', self::expect('Football', '2026-10-03', '22:00', $s, 'Jabalia Youth Court')],
            'A25 الخميس الجاي' => ['كرة سلة الخميس الجاي الساعة 6 المسا بخانيونس', self::expect('Basketball', '2026-10-08', '18:00', $s, 'South Court', 'Khan Younis')],
            'A26 10 ونص بالليل' => ['كورة بكرا 10 ونص بالليل', self::expect('Football', '2026-10-04', '22:30', $n)],
            'A27 6 الصبح بجباليا' => ['بدي ملعب كورة بكرا 6 الصبح بجباليا', self::expect('Football', '2026-10-04', '06:00', $n, null, 'Jabalia')],
            // Latin script
            'E01 typos fotball tomorow' => ['fotball tomorow at 7pm', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
            'E02 typo footbal' => ['footbal tomorrow 7pm', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
            'E03 seven pm' => ['football tomorrow at seven pm', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
            'E04 at seven' => ['basketball day after tomorrow at seven', self::expect('Basketball', '2026-10-05', '19:00', $s, 'Tal al-Hawa Club')],
            'E05 area shejaiya' => ['football in shejaiya tomorrow at 7pm', self::expect('Football', '2026-10-04', '19:00', $s, 'Shujaiya Arena', null, 'Shujaiya')],
            'E06 al rimal' => ['football at al rimal tomorrow 9pm', self::expect('Football', '2026-10-04', '21:00', $s, 'Green Field Court', null, 'Al-Rimal')],
            'E07 tennis rafah today 5' => ['any tennis court in rafah today at 5?', self::expect('Tennis', '2026-10-03', '17:00', $s, 'Rafah Padel Club', 'Rafah')],
            'E08 volleyball thursday' => ['volleyball on thursday 5pm', self::expect('Volleyball', '2026-10-08', '17:00', $s, 'Khan Younis Sports Hall')],
            'E09 slang footy tmrw' => ['yo any footy pitch free tmrw 9pm', self::expect('Football', '2026-10-04', '21:00', $s, 'South Court')],
            'E10 5-a-side' => ['need a 5-a-side pitch tomorrow at 9pm', self::expect('Football', '2026-10-04', '21:00', $s, 'South Court')],
            'E11 slang 2moro' => ['gonna need a basketball court 2moro at 7', self::expect('Basketball', '2026-10-04', '19:00', $n)],
            'E12 no sport' => ['anything free tomorrow at 8pm?', self::expect(null, '2026-10-04', '20:00', $d)],
            'E13 polite' => ['hi, could you please find me a football court tomorrow at 6pm? thanks', self::expect('Football', '2026-10-04', '18:00', $s, 'South Court')],
            'E14 evening (vague)' => ['football tomorrow evening', self::expect('Football', '2026-10-04', null, $d)],
            'E15 padel please' => ['Can I get a padel court tomorrow at 6 pm in Rafah please', self::expect('Padel', '2026-10-04', '18:00', $s, 'Rafah Padel Club', 'Rafah')],
            'E16 day/month date' => ['football 9/10 at 6pm', self::expect('Football', '2026-10-09', '18:00', $s, 'Green Field Court')],
            'E17 mixed area' => ['football tomorrow at 6pm بالرمال', self::expect('Football', '2026-10-04', '18:00', $s, 'Green Field Court', null, 'الرمال')],
            'E18 arabizi' => ['bde mal3ab kora bukra 3al 7', self::expect('Football', '2026-10-04', '19:00', $s, 'South Court')],
        ];
    }

    #[DataProvider('unseenQueries')]
    public function test_unseen_query_is_understood(string $query, array $expected): void
    {
        $actual = $this->actual($this->respond($query));

        $this->assertSame([], $this->wrongFields($expected, $actual), 'got '.json_encode($actual, JSON_UNESCAPED_UNICODE));
    }

    public function test_unseen_set_reaches_the_srs_90_percent_target(): void
    {
        $cases = self::unseenQueries();
        $misses = [];

        foreach ($cases as $id => [$query, $expected]) {
            $wrong = $this->wrongFields($expected, $this->actual($this->respond($query)));

            if ($wrong !== []) {
                $misses[] = "{$id}: ".implode(',', $wrong);
            }
        }

        $accuracy = round((count($cases) - count($misses)) / count($cases) * 100, 1);
        $arabic = count(array_filter(array_keys($cases), fn (string $id) => str_starts_with($id, 'A')));

        $this->assertGreaterThanOrEqual(25, count($cases));
        $this->assertGreaterThanOrEqual(0.6, $arabic / count($cases));
        $this->assertGreaterThanOrEqual(90, $accuracy, "unseen accuracy {$accuracy}% (".(count($cases) - count($misses)).'/'.count($cases)."); misses:\n".implode("\n", $misses));
    }

    // ---------------------------------------------------------------- scoring honesty

    public function test_the_right_venue_with_a_wrong_field_is_not_counted_correct(): void
    {
        // Gemini answers every tuning case as expected, except T03's hour: 18:30 still finds
        // South Court (its slot is 18:00-19:00), so only the hour is wrong.
        config(['services.gemini.key' => 'test-key']);
        $expected = collect($this->evaluator()->cases())->pluck('expected', 'query');
        $this->geminiReply = function (Request $request) use ($expected) {
            $query = $request['contents'][0]['parts'][0]['text'];
            $args = array_filter(collect($expected[$query])->only(['sport', 'date', 'hour', 'city', 'area'])->all());

            if ($query === 'فوتبول بكره 6 المسا') {
                $args['hour'] = '18:30';
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]]]]]]]);
        };

        $result = $this->evaluator()->evaluate(useGemini: true, group: 'tuning');
        $t03 = collect($result['cases'])->firstWhere('id', 'T03');

        $this->assertSame('South Court', $t03['actual']['venue']);
        $this->assertTrue($t03['checks']['venue']);
        $this->assertSame(['hour'], $t03['wrong']);
        $this->assertFalse($t03['correct']);
        $this->assertSame(34, $result['groups']['tuning']['passed']);
    }

    public function test_the_evaluation_does_not_depend_on_the_real_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 13:00:00', 'UTC'));
        $first = $this->evaluator()->evaluate(useGemini: false);

        $this->travelTo(Carbon::parse('2027-06-15 23:30:00', 'UTC'));
        $second = $this->evaluator()->evaluate(useGemini: false);

        $this->assertSame(
            collect($first['cases'])->map(fn ($c) => [$c['id'], $c['correct'], $c['actual']])->all(),
            collect($second['cases'])->map(fn ($c) => [$c['id'], $c['correct'], $c['actual']])->all(),
        );
    }

    public function test_every_expected_date_matches_the_reference_calendar(): void
    {
        // Saturday 2026-10-03: tomorrow Sunday 04, Monday 05, Thursday 08, Friday 09.
        $calendar = [
            'اليوم' => '2026-10-03', 'today' => '2026-10-03', 'tonight' => '2026-10-03',
            'بكرا' => '2026-10-04', 'بكره' => '2026-10-04', 'tomorrow' => '2026-10-04', 'الأحد' => '2026-10-04',
            'بعد بكرا' => '2026-10-05', 'day after tomorrow' => '2026-10-05', 'الاثنين' => '2026-10-05',
            'الخميس' => '2026-10-08', 'thursday' => '2026-10-08', 'Thursday' => '2026-10-08',
            'الجمعة' => '2026-10-09', 'Friday' => '2026-10-09',
        ];
        $nextDay = ['12 بالليل' => true, 'midnight' => true];

        foreach ($this->evaluator()->cases() as $case) {
            $expectedDate = $case['expected']['date'];
            $words = array_filter(array_keys($calendar), fn (string $word) => preg_match('/(?<![\p{L}])[وبلف]?'.preg_quote($word, '/').'(?![\p{L}])/u', $case['query']));
            usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

            if ($words === []) {
                continue;
            }

            $date = CarbonImmutable::parse($calendar[$words[0]]);
            foreach (array_keys($nextDay) as $marker) {
                if (str_contains($case['query'], $marker)) {
                    $date = $date->addDay();
                }
            }

            // Stage 4: "tonight at 1am" (an am hour from 12 to 4) is after midnight too.
            if (str_contains($case['query'], 'tonight') && preg_match('/(?<!\d)(?:12|0?[1-4])\s*am(?![a-z])/', $case['query'])) {
                $date = $date->addDay();
            }

            $this->assertSame($date->toDateString(), $expectedDate, "{$case['id']} ({$case['query']})");
        }
    }

    // ---------------------------------------------------------------- assistant:evaluate

    public function test_the_command_leaves_the_application_database_and_config_as_they_were(): void
    {
        $counts = fn () => collect(['sports', 'cities', 'venues', 'venue_sports', 'time_slots', 'users', 'assistant_queries', 'countries'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $before = $counts();
        $connection = DB::connection()->getName();
        $config = [config('database.default'), config('cache.default')];

        $this->artisan('assistant:evaluate --fallback')->assertSuccessful();

        $this->assertSame($before, $counts());
        $this->assertSame($connection, DB::connection()->getName());
        $this->assertSame($config, [config('database.default'), config('cache.default')]);
        $this->assertArrayNotHasKey('assistant_evaluation', DB::getConnections());
    }

    public function test_min_is_a_strict_threshold_on_the_unrounded_accuracy(): void
    {
        // Updated for the Stage 3 decision 10: --min is compared with the unrounded accuracy.
        // Gemini answers every held-out case as expected except one hour: 22/23 = 95.65...%,
        // shown as 95.7%.
        config(['services.gemini.key' => 'test-key']);
        $cases = collect($this->evaluator()->cases('held_out'));
        $expected = $cases->pluck('expected', 'query');
        $wrong = $cases->first(fn (array $case) => $case['expected']['hour'] !== null)['query'];
        $this->geminiReply = function (Request $request) use ($expected, $wrong) {
            $query = $request['contents'][0]['parts'][0]['text'];
            $args = array_filter(collect($expected[$query])->only(['sport', 'date', 'hour', 'city', 'area'])->all());

            if ($query === $wrong) {
                $args['hour'] = '07:00';
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]]]]]]]);
        };

        $exact = ($cases->count() - 1) / $cases->count() * 100;

        $this->artisan('assistant:evaluate', ['--group' => 'held_out', '--min' => (string) floor($exact * 100) / 100])->assertExitCode(0);
        $this->artisan('assistant:evaluate', ['--group' => 'held_out', '--min' => (string) round($exact, 1)])->assertExitCode(1);
        $this->artisan('assistant:evaluate', ['--group' => 'held_out', '--min' => '100.1'])->assertExitCode(2);
    }

    public function test_a_non_numeric_min_is_rejected(): void
    {
        // (float) "abc" is 0, which would make any accuracy pass.
        $this->artisan('assistant:evaluate', ['--fallback' => true, '--min' => 'abc'])->assertExitCode(2);
    }

    public function test_json_output_shape_per_group(): void
    {
        $path = storage_path('framework/testing/stage3-evaluation.json');
        @unlink($path);

        $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'held_out', '--json' => $path])->assertSuccessful();
        $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        @unlink($path);

        $this->assertSame(['meta', 'groups', 'gemini_answers', 'cases'], array_keys($document));
        $this->assertSame(['mode', 'model', 'cases', 'generated_at'], array_keys($document['meta']));
        $this->assertSame('fallback', $document['meta']['mode']);
        $this->assertNull($document['meta']['model']);
        $this->assertSame(['held_out'], array_keys($document['groups']));
        $this->assertSame(['total', 'passed', 'accuracy', 'fields'], array_keys($document['groups']['held_out']));
        $this->assertSame(0, $document['gemini_answers']);
        $this->assertCount(count($this->evaluator()->cases('held_out')), $document['cases']);

        foreach ($document['cases'] as $case) {
            $this->assertSame(['id', 'group', 'language', 'query', 'source', 'correct', 'wrong', 'checks', 'expected', 'actual'], array_keys($case));
            $this->assertSame('held_out', $case['group']);
            $this->assertSame('fallback', $case['source']);
            $this->assertSame(['sport', 'date', 'hour', 'city', 'area', 'outcome', 'venue'], array_keys($case['actual']));
            $this->assertSame($case['correct'], $case['wrong'] === []);
        }
    }

    public function test_gemini_503_falls_back_for_every_case_and_reports_it(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->geminiReply = fn () => Http::response(['error' => ['message' => 'unavailable']], 503);
        $total = count($this->evaluator()->cases());

        // Updated for the Stage 3 decision 11: with a key, an evaluation Gemini never answered fails.
        $this->artisan('assistant:evaluate')
            ->expectsOutputToContain("Gemini answered 0 of {$total} cases")
            ->expectsOutputToContain('Gemini answered none of the cases')
            ->assertFailed();

        Http::assertSentCount($total);
    }

    // ---------------------------------------------------------------- 6(a) unseeded cities

    public function test_an_unseeded_city_is_not_covered_rather_than_suggested_elsewhere(): void
    {
        // Updated for the Stage 3 decision 6: a city we have no venues in is said plainly,
        // with the cities we do cover, and no venue in another city is offered.
        $fallback = $this->respond('كرة قدم بالقدس بكرا الساعة 6 المسا');
        $this->assertSame('not_covered', $fallback['outcome']);
        $this->assertNull($fallback['slot']);
        $this->assertArrayNotHasKey('city', $fallback['arguments']);
        $this->assertSame('لا نغطي القدس بعد — نغطي حالياً: غزة، خانيونس، دير البلح، رفح وجباليا.', $fallback['reply_text']);

        $this->geminiArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00', 'city' => 'Cairo']);
        $gemini = $this->respond('football in Cairo tomorrow at 6pm', useGemini: true);
        $this->assertSame('gemini', $gemini['source']);
        $this->assertSame('not_covered', $gemini['outcome']);
        $this->assertNull($gemini['slot']);
        $this->assertSame("We don't cover Cairo yet — we currently cover: Gaza, Khan Younis, Deir al-Balah, Rafah and Jabalia.", $gemini['reply_text']);
    }

    // ---------------------------------------------------------------- 6(b) midnight

    public function test_tomorrow_at_midnight_is_the_night_after_tomorrow_evening(): void
    {
        $green = Venue::where('name_en', 'Green Field Court')->first();
        TimeSlot::forceCreate([
            'venue_id' => $green->id, 'sport_id' => Sport::where('name_en', 'Football')->value('id'),
            'slot_date' => '2026-10-05', 'start_time' => '00:00:00', 'end_time' => '01:00:00', 'hourly_price' => 40, 'status' => 'available',
        ]);

        $answer = $this->respond('بدي ملعب كرة قدم بكرا الساعة 12 بالليل');

        $this->assertSame(['2026-10-05', '00:00'], [$answer['arguments']['date'], $answer['arguments']['hour']]);
        $this->assertSame('Green Field Court', $answer['slot']->venue->name_en);
        // Updated for the Stage 3 decision 8: the player said "tomorrow", so the reply says
        // tomorrow night after midnight, with the calendar date of the day after.
        $this->assertSame('يتوفر موعد في ملعب الحقل الأخضر بكرا بالليل بعد منتصف الليل (الإثنين 5 أكتوبر) الساعة 00:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟', $answer['reply_text']);
    }

    public function test_after_midnight_tonight_at_1_is_the_same_calendar_day(): void
    {
        // 00:30 in Gaza on Sunday the 4th: "الليلة الساعة 1" is in half an hour, not on the 5th.
        $answer = $this->respond('كورة الليلة الساعة 1', now: CarbonImmutable::parse('2026-10-04 00:30', 'Asia/Gaza'));

        $this->assertSame('01:00', $answer['arguments']['hour'] ?? null);
        $this->assertSame('2026-10-04', $answer['arguments']['date'] ?? null);
    }

    // ---------------------------------------------------------------- 6(c) vague times

    public static function vagueTimes(): array
    {
        return [
            'tonight' => ['football tonight'],
            'evening' => ['football tomorrow evening'],
            'الليلة' => ['كورة الليلة'],
            'المسا' => ['كرة قدم بكرا المسا'],
            'بعد المغرب' => ['كرة قدم بكرا بعد المغرب'],
        ];
    }

    #[DataProvider('vagueTimes')]
    public function test_vague_times_ask_for_details(string $query): void
    {
        $answer = $this->respond($query);

        $this->assertSame('needs_details', $answer['outcome']);
        $this->assertArrayNotHasKey('hour', $answer['arguments']);
        $this->assertSame('Football', $answer['sport']?->name_en);
    }

    // ---------------------------------------------------------------- 6(d) Gemini hour and date guards

    public static function hourWordsGeminiReads(): array
    {
        return [
            'سبعة' => ['بدي ملعب كورة بكرا الساعة سبعة المسا'],
            'ستة' => ['كورة بكرا الساعة ستة'],
            'ثمانية' => ['كورة بكرا الساعة ثمانية المسا'],
            'seven pm' => ['football tomorrow at seven pm'],
            'السابعة (formal)' => ['أريد ملعب كرة قدم غداً الساعة السابعة مساءً'],
            'عالسبعة (prefixed)' => ['بدي ملعب كورة بكرا عالسبعة'],
            'السادسة والنصف' => ['كرة قدم بكرا الساعة السادسة والنصف مساء'],
        ];
    }

    #[DataProvider('hourWordsGeminiReads')]
    public function test_geminis_hour_is_kept_when_the_hour_is_written_as_a_word(string $query): void
    {
        $this->geminiArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '19:00']);

        $answer = $this->respond($query, useGemini: true);

        $this->assertSame('19:00', $answer['arguments']['hour'] ?? null, 'Gemini read an hour the text does give');
        $this->assertSame('suggestion', $answer['outcome']);
    }

    public function test_geminis_hour_is_dropped_when_the_text_has_none(): void
    {
        $this->geminiArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']);

        $answer = $this->respond('football tomorrow', useGemini: true);

        $this->assertArrayNotHasKey('hour', $answer['arguments']);
        $this->assertSame('needs_details', $answer['outcome']);
    }

    public function test_geminis_date_is_kept_for_an_arabizi_day(): void
    {
        // "bukra" is how many players type بكرا; Gemini reads it, the date guard does not.
        $this->geminiArgs(['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '19:00']);

        $answer = $this->respond('bde mal3ab kora bukra 3al 7', useGemini: true);

        $this->assertSame('2026-10-04', $answer['arguments']['date'] ?? null);
        $this->assertSame('South Court', $answer['slot']?->venue?->name_en);
    }

    // ---------------------------------------------------------------- Sprint 4 regression through the endpoint

    public function test_endpoint_still_honours_contract_logging_and_reply_language(): void
    {
        $this->seed(RoleSeeder::class);
        $player = User::factory()->create(['status' => 'active']);
        $player->assignRole('player');
        Sanctum::actingAs($player);
        // 09:00 UTC is 12:00 in Gaza, the fixture's reference time.
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));

        $response = $this->postJson('/api/v1/assistant/query', ['query_text' => 'فوتبول بكره 6 المسا'])->assertOk();

        $this->assertSame(['success', 'message', 'data', 'errors'], array_keys($response->json()));
        $this->assertSame('OK', $response->json('message'));
        $this->assertSame('2026-10-04', $response->json('data.parsed_date'));
        $this->assertSame('18:00', $response->json('data.parsed_hour'));
        $this->assertSame(Venue::where('name_en', 'South Court')->value('id'), $response->json('data.suggested_venue_id'));
        $this->assertSame('يتوفر موعد في الملعب الجنوبي غداً الساعة 18:00 بسعر 60 شيكل للساعة. هل تريد أن أحجزه لك؟', $response->json('data.reply_text'));
        $this->assertSame(1, AssistantQuery::where('user_id', $player->id)->count());
    }
}
