<?php

namespace Tests\Feature\Review;

use App\Models\AssistantQuery;
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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Stage 4 review of the booking assistant: the Sprint 1 search, the Sprint 4 assistant and
 * the Sprint 5 evaluation together, at ea90d84.
 *
 * - A blind set of 68 phrasings (group "blind_r2"), written from the fixture and meta of
 *   resources/assistant/evaluation-cases.json only, before the fallback parser was read,
 *   and scored exactly like AssistantEvaluator (its own actual() and checks()).
 * - Regression probes for the Stage 3 fixes: word-distance matches, not_covered, hours,
 *   Arabizi digits, midnight, the assistant:evaluate options and the 7.8 contract.
 *
 * A test that documents a defect fails with "DEFECT [major|minor]" in its message. A blind
 * case whose expectation is debatable is marked incomplete instead of failing.
 */
class AiStage4BlindReviewTest extends TestCase
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
     * The evaluator's own reading of an answer (AssistantEvaluator::actual()).
     *
     * @return array<string, ?string>
     */
    private function actual(array $answer): array
    {
        return (new ReflectionMethod(AssistantEvaluator::class, 'actual'))->invoke($this->evaluator(), $answer);
    }

    /**
     * The evaluator's own scoring (AssistantEvaluator::checks()).
     *
     * @return array<string, ?bool>
     */
    private function checks(array $expected, array $actual): array
    {
        return (new ReflectionMethod(AssistantEvaluator::class, 'checks'))->invoke($this->evaluator(), $expected, $actual);
    }

    private static function expected(?string $sport, ?string $date, ?string $hour, string $outcome, ?string $venue = null, ?string $city = null, ?string $area = null): array
    {
        return compact('sport', 'date', 'hour', 'city', 'area', 'outcome', 'venue');
    }

    private static function describe(array $values): string
    {
        return implode(', ', array_map(
            fn (string $key) => $key.'='.($values[$key] ?? 'none'),
            array_keys($values),
        ));
    }

    /**
     * Asserts only the fields given in $expected (a null means "must not be set").
     */
    private function assertReads(string $query, array $expected, string $defect, ?CarbonImmutable $now = null, bool $useGemini = false): array
    {
        $answer = $this->respond($query, $useGemini, $now);
        $actual = $this->actual($answer);
        $got = array_intersect_key($actual, $expected);

        $this->assertSame(
            $expected,
            array_merge($expected, $got),
            "{$defect}\n  query:    {$query}\n  expected: ".self::describe($expected)."\n  got:      ".self::describe($got)."\n  reply:    {$answer['reply_text']}",
        );

        return $answer;
    }

    private function geminiArgs(array $args): void
    {
        config(['services.gemini.key' => 'test-key-not-real']);
        $this->geminiReply = fn () => Http::response(['candidates' => [['content' => ['parts' => [
            ['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]],
        ]]]]]);
    }

    // ------------------------------------------------------------------ Task 1: blind set (fallback)

    /**
     * 68 blind phrasings: 39 Arabic, 17 English, 12 Arabizi or mixed. Each row is
     * [query, language, expected, debatable reason or null]. Also saved as blind_r2.json.
     */
    public static function blindCases(): array
    {
        return [
            'b2-ar-01' => ['بدي احجز ملعب كورة بكرا الساعة 8 بالليل ⚽', 'ar', self::expected('Football', '2026-10-04', '20:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-ar-02' => ['في ملعب كرة قدم فاضي الليلة الساعة 9 بغزة؟', 'ar', self::expected('Football', '2026-10-03', '21:00', 'suggestion', 'Green Field Court', 'Gaza', null), null],
            'b2-ar-03' => ['بدنا نلعب كورة بكرة الساعه ٦ المسا في خانيونس', 'ar', self::expected('Football', '2026-10-04', '18:00', 'suggestion', 'South Court', 'Khan Younis', null), null],
            'b2-ar-04' => ['ملعب سلة بعد بكرا الساعة 7 المسا', 'ar', self::expected('Basketball', '2026-10-05', '19:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-ar-05' => ['بدي ملعب طائره يوم الخميس الساعه 5 العصر', 'ar', self::expected('Volleyball', '2026-10-08', '17:00', 'suggestion', 'Khan Younis Sports Hall', null, null), null],
            'b2-ar-06' => ['حجز تنس اليوم الساعة 5 مساءا برفح', 'ar', self::expected('Tennis', '2026-10-03', '17:00', 'suggestion', 'Rafah Padel Club', 'Rafah', null), null],
            'b2-ar-07' => ['بادل بكرا ع الساعة 6 بالليل', 'ar', self::expected('Padel', '2026-10-04', '18:00', 'suggestion', 'Rafah Padel Club', null, null), null],
            'b2-ar-08' => ['بدي العب فوتبول بكرا الساعة 7 بالشجاعية', 'ar', self::expected('Football', '2026-10-04', '19:00', 'suggestion', 'Shujaiya Arena', null, 'الشجاعية'), null],
            'b2-ar-09' => ['ملعب كوره بالرمال يوم الاحد الساعه 9 بالليل', 'ar', self::expected('Football', '2026-10-04', '21:00', 'suggestion', 'Green Field Court', null, 'الرمال'), null],
            'b2-ar-10' => ['في ملعب بالجلاء بكرا الساعه 11 بالليل لكرة القدم', 'ar', self::expected('Football', '2026-10-04', '23:00', 'suggestion', 'Al-Jalaa Stadium', null, 'الجلاء'), null],
            'b2-ar-11' => ['كرة قدم الجمعه الساعة ١٠ صباحا بغزة', 'ar', self::expected('Football', '2026-10-09', '10:00', 'suggestion', 'Shujaiya Arena', 'Gaza', null), null],
            'b2-ar-12' => ['بدي ملعب كورة بجباليا اليوم الساعة 10 بالليل', 'ar', self::expected('Football', '2026-10-03', '22:00', 'suggestion', 'Jabalia Youth Court', 'Jabalia', null), null],
            'b2-ar-13' => ['ملعب كرة قدم في دير البلح بكرا الساعة 5 العصر', 'ar', self::expected('Football', '2026-10-04', '17:00', 'suggestion', 'Deir Beach Court', 'Deir al-Balah', null), null],
            'b2-ar-14' => ['ملعب طايرة بالدير الاثنين الساعة 6 المسا', 'ar', self::expected('Volleyball', '2026-10-05', '18:00', 'suggestion', 'Deir Beach Court', 'Deir al-Balah', null), '\'الدير\' is the everyday name for Deir al-Balah, but the fixture only lists \'دير البلح\'.'],
            'b2-ar-15' => ['بدي ملعب كورة بالنصيرات بكرا الساعة 7', 'ar', self::expected('Football', '2026-10-04', '19:00', 'not_covered', null, null, null), null],
            'b2-ar-16' => ['في ملاعب بالشيخ رضوان؟ بدي كورة بكرا 8 بالليل', 'ar', self::expected('Football', '2026-10-04', '20:00', 'not_covered', null, null, null), null],
            'b2-ar-17' => ['بدي ألعب كورة بكرا', 'ar', self::expected('Football', '2026-10-04', null, 'needs_details', null, null, null), null],
            'b2-ar-18' => ['ملعب سلة الساعة 7 المسا', 'ar', self::expected('Basketball', null, '19:00', 'needs_details', null, null, null), null],
            'b2-ar-19' => ['بدي احجز ملعب لبكرا الساعة 8', 'ar', self::expected(null, '2026-10-04', '20:00', 'needs_details', null, null, null), 'In Gaza \'ملعب\' alone usually means a football pitch; a parser that assumes Football is defensible.'],
            'b2-ar-20' => ['كرة سلة بكرا الساعة 6 المسا', 'ar', self::expected('Basketball', '2026-10-04', '18:00', 'no_match', null, null, null), null],
            'b2-ar-21' => ['تنس يوم الاثنين الساعة 5 المسا', 'ar', self::expected('Tennis', '2026-10-05', '17:00', 'no_match', null, null, null), null],
            'b2-ar-22' => ['ملعب كورة الليلة الساعة 1', 'ar', self::expected('Football', '2026-10-04', '01:00', 'no_match', null, null, null), null],
            'b2-ar-23' => ['بدي ملعب كورة الليلة 12 بالليل', 'ar', self::expected('Football', '2026-10-04', '00:00', 'no_match', null, null, null), 'Midnight \'tonight\' could also be read as the end of today; 2026-10-04 00:00 is the calendar-correct reading.'],
            'b2-ar-24' => ['ملعب كرة قدم اليوم الساعة 11 الصبح', 'ar', self::expected('Football', '2026-10-03', '11:00', 'no_match', null, null, null), null],
            'b2-ar-25' => ['ابغى ملعب كرة القدم يوم السبت الجاي الساعة 6 مساء', 'ar', self::expected('Football', '2026-10-10', '18:00', 'no_match', null, null, null), null],
            'b2-ar-26' => ['كورة اليوم الساعة 8 بالليل في الشجاعيه', 'ar', self::expected('Football', '2026-10-03', '20:00', 'suggestion', 'Shujaiya Arena', null, 'الشجاعية'), null],
            'b2-ar-27' => ['سلة بخان يونس الخميس الساعة 6 مساءً', 'ar', self::expected('Basketball', '2026-10-08', '18:00', 'suggestion', 'South Court', 'Khan Younis', null), null],
            'b2-ar-28' => ['بدي ملعب كرة طائرة بوسط البلد يوم الخميس الساعة 5 العصر', 'ar', self::expected('Volleyball', '2026-10-08', '17:00', 'suggestion', 'Khan Younis Sports Hall', null, 'وسط البلد'), null],
            'b2-ar-29' => ['فيه ملعب باديل بكرة الساعة ٨ مساءً؟', 'ar', self::expected('Padel', '2026-10-04', '20:00', 'no_match', null, null, null), null],
            'b2-ar-30' => ['بدي كورة يوم الأحد الساعة ثمانية بالليل ببني سهيلا', 'ar', self::expected('Football', '2026-10-04', '20:00', 'no_match', null, null, 'بني سهيلا'), null],
            'b2-ar-31' => ['كورة بكرا الساعة سبعة ونص المسا', 'ar', self::expected('Football', '2026-10-04', '19:30', 'suggestion', 'South Court', null, null), 'Half past: an hour of 19:00 finds the same slot; whether the minutes must be kept is a scoring choice.'],
            'b2-ar-32' => ['عاوز ملعب كرة قدم بتل الهوا بكرا الساعة 8 بالليل', 'ar', self::expected('Football', '2026-10-04', '20:00', 'suggestion', 'Tal al-Hawa Club', null, 'تل الهوى'), null],
            'b2-ar-33' => ['بدي العب تنس بكرا الساعه 7 بالليل 🎾', 'ar', self::expected('Tennis', '2026-10-04', '19:00', 'suggestion', 'Rafah Padel Club', null, null), null],
            'b2-ar-34' => ['ملعب كرة قدم بالنزلة يوم الجمعة 6 المسا!!', 'ar', self::expected('Football', '2026-10-09', '18:00', 'suggestion', 'Jabalia Youth Court', null, 'النزلة'), null],
            'b2-ar-35' => ['شو في ملاعب كورة فاضية بكرا من الساعة 9 بالليل؟', 'ar', self::expected('Football', '2026-10-04', '21:00', 'suggestion', 'South Court', null, null), null],
            'b2-ar-36' => ['أرغب في حجز ملعب لكرة السلة يوم الاثنين القادم في تمام الساعة السابعة مساءً', 'ar', self::expected('Basketball', '2026-10-05', '19:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-ar-37' => ['هل يوجد ملعب تنس متاح غدا الساعة السادسة مساء في رفح', 'ar', self::expected('Tennis', '2026-10-04', '18:00', 'suggestion', 'Rafah Padel Club', 'Rafah', null), null],
            'b2-ar-38' => ['بدي ملعب كرت قدم بكرا الساعه 6 مسا', 'ar', self::expected('Football', '2026-10-04', '18:00', 'suggestion', 'South Court', null, null), null],
            'b2-ar-39' => ['بدي ملعب للسله يوم التلات الساعة 7', 'ar', self::expected('Basketball', '2026-10-06', '19:00', 'no_match', null, null, null), null],
            'b2-en-01' => ['need a football pitch tmrw at 7pm', 'en', self::expected('Football', '2026-10-04', '19:00', 'suggestion', 'South Court', null, null), null],
            'b2-en-02' => ['any basketball court free on monday 7 pm?', 'en', self::expected('Basketball', '2026-10-05', '19:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-en-03' => ['footbal tonight 9pm in gaza', 'en', self::expected('Football', '2026-10-03', '21:00', 'suggestion', 'Green Field Court', 'Gaza', null), null],
            'b2-en-04' => ['volleyball thursday 5pm khan yunis', 'en', self::expected('Volleyball', '2026-10-08', '17:00', 'suggestion', 'Khan Younis Sports Hall', 'Khan Younis', null), null],
            'b2-en-05' => ['tennis today at 5 pm rafah', 'en', self::expected('Tennis', '2026-10-03', '17:00', 'suggestion', 'Rafah Padel Club', 'Rafah', null), null],
            'b2-en-06' => ['can i book paddle tomorrow 6pm', 'en', self::expected('Padel', '2026-10-04', '18:00', 'suggestion', 'Rafah Padel Club', null, null), null],
            'b2-en-07' => ['bball court day after tomorrow 7pm', 'en', self::expected('Basketball', '2026-10-05', '19:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-en-08' => ['football in nuseirat tomorrow 8pm', 'en', self::expected('Football', '2026-10-04', '20:00', 'not_covered', null, null, null), null],
            'b2-en-09' => ['I want to play football tomorrow', 'en', self::expected('Football', '2026-10-04', null, 'needs_details', null, null, null), null],
            'b2-en-10' => ['football at 8pm', 'en', self::expected('Football', null, '20:00', 'needs_details', null, null, null), 'Some players mean today when no day is given; the meta says a missing day is asked for.'],
            'b2-en-11' => ['basketball tomorrow 6pm', 'en', self::expected('Basketball', '2026-10-04', '18:00', 'no_match', null, null, null), null],
            'b2-en-12' => ['soccer field sunday 11pm al jalaa', 'en', self::expected('Football', '2026-10-04', '23:00', 'suggestion', 'Al-Jalaa Stadium', null, 'Al-Jalaa'), null],
            'b2-en-13' => ['tennis 2moro 7 pm', 'en', self::expected('Tennis', '2026-10-04', '19:00', 'suggestion', 'Rafah Padel Club', null, null), null],
            'b2-en-14' => ['football tonight at 1am', 'en', self::expected('Football', '2026-10-04', '01:00', 'no_match', null, null, null), null],
            'b2-en-15' => ['pls find me a voleyball court on friday 4pm in deir al balah', 'en', self::expected('Volleyball', '2026-10-09', '16:00', 'suggestion', 'Deir Beach Court', 'Deir al-Balah', null), null],
            'b2-en-16' => ['football pitch shujaiya tmr 7pm', 'en', self::expected('Football', '2026-10-04', '19:00', 'suggestion', 'Shujaiya Arena', null, 'Shujaiya'), null],
            'b2-en-17' => ['football this friday 9am', 'en', self::expected('Football', '2026-10-09', '09:00', 'suggestion', 'South Court', null, null), null],
            'b2-mx-01' => ['bdi mal3ab koora bokra sa3a 7 bel lail', 'mixed', self::expected('Football', '2026-10-04', '19:00', 'suggestion', 'South Court', null, null), null],
            'b2-mx-02' => ['fi mal3ab football elyom 3al 9 bel lel?', 'mixed', self::expected('Football', '2026-10-03', '21:00', 'suggestion', 'Green Field Court', null, null), null],
            'b2-mx-03' => ['bdna nel3ab basket ba3d bokra 3al 7', 'mixed', self::expected('Basketball', '2026-10-05', '19:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-mx-04' => ['football bokra 6 masa2 fi khanyounis', 'mixed', self::expected('Football', '2026-10-04', '18:00', 'suggestion', 'South Court', 'Khan Younis', null), null],
            'b2-mx-05' => ['tennis el yom 5 el masa fe rafa7', 'mixed', self::expected('Tennis', '2026-10-03', '17:00', 'suggestion', 'Rafah Padel Club', 'Rafah', null), null],
            'b2-mx-06' => ['بدي football بكرا 8 pm', 'mixed', self::expected('Football', '2026-10-04', '20:00', 'suggestion', 'Tal al-Hawa Club', null, null), null],
            'b2-mx-07' => ['حجز padel بكرا الساعة 6pm', 'mixed', self::expected('Padel', '2026-10-04', '18:00', 'suggestion', 'Rafah Padel Club', null, null), null],
            'b2-mx-08' => ['volleyball يوم الخميس 5pm', 'mixed', self::expected('Volleyball', '2026-10-08', '17:00', 'suggestion', 'Khan Younis Sports Hall', null, null), null],
            'b2-mx-09' => ['bdi koora bokra', 'mixed', self::expected('Football', '2026-10-04', null, 'needs_details', null, null, null), null],
            'b2-mx-10' => ['koora el leila sa3a 1', 'mixed', self::expected('Football', '2026-10-04', '01:00', 'no_match', null, null, null), null],
            'b2-mx-11' => ['mal3ab kora fi el remal yom el a7ad 9 bel lail', 'mixed', self::expected('Football', '2026-10-04', '21:00', 'suggestion', 'Green Field Court', null, 'Al-Rimal'), null],
            'b2-mx-12' => ['bdi mal3ab koora fe bait lahia bokra 7', 'mixed', self::expected('Football', '2026-10-04', '19:00', 'not_covered', null, null, null), null],
        ];
    }

    #[DataProvider('blindCases')]
    public function test_blind_case_is_understood_by_the_fallback(string $query, string $language, array $expected, ?string $debatable): void
    {
        $answer = $this->respond($query);
        $actual = $this->actual($answer);
        $wrong = array_keys(array_filter($this->checks($expected, $actual), fn (?bool $check) => $check === false));

        if ($wrong !== [] && $debatable !== null) {
            $this->markTestIncomplete("Debatable expectation ({$debatable}); wrong: ".implode(', ', $wrong).'; got '.self::describe($actual));
        }

        $this->assertSame([], $wrong, "Blind miss ({$language}): {$query}\n  expected: ".self::describe($expected)."\n  got:      ".self::describe($actual)."\n  reply:    {$answer['reply_text']}");
    }

    public function test_blind_set_reaches_the_srs_90_percent_target_on_the_fallback(): void
    {
        $results = [];

        foreach (self::blindCases() as $id => [$query, $language, $expected, $debatable]) {
            $checks = $this->checks($expected, $this->actual($this->respond($query)));
            $results[$id] = ['language' => $language, 'debatable' => $debatable !== null, 'correct' => ! in_array(false, $checks, true)];
        }

        $rate = function (array $subset): string {
            $passed = count(array_filter($subset, fn (array $result) => $result['correct']));

            return sprintf('%d/%d (%.1f%%)', $passed, count($subset), count($subset) ? $passed / count($subset) * 100 : 0);
        };
        $by = fn (callable $filter) => array_filter($results, $filter);
        $passed = count(array_filter($results, fn (array $result) => $result['correct']));
        $strict = $passed / count($results) * 100;
        $misses = implode(', ', array_keys(array_filter($results, fn (array $result) => ! $result['correct'])));

        $this->assertGreaterThanOrEqual(
            90.0,
            $strict,
            'DEFECT [major] blind_r2 on the fallback is below the SRS 90% target when scored strictly: '.$rate($results)
                .'; ar '.$rate($by(fn ($r) => $r['language'] === 'ar'))
                .', en '.$rate($by(fn ($r) => $r['language'] === 'en'))
                .', mixed '.$rate($by(fn ($r) => $r['language'] === 'mixed'))
                .'; without the debatable cases '.$rate($by(fn ($r) => ! $r['debatable']))
                .". Misses: {$misses}.",
        );
    }

    // ------------------------------------------------------------------ Task 2: word distance and substrings

    /**
     * Names and everyday words that the typo or substring matching reads as a place, so
     * the search is narrowed to the wrong area or city. Football tomorrow at 20:00 should
     * suggest Tal al-Hawa Club (4.4) with no place filter.
     */
    public static function wordsReadAsAPlace(): array
    {
        $plain = ['city' => null, 'area' => null, 'outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club'];

        return [
            'Arabic name Jalal read as Al-Jalaa (substring)' => ['أنا جلال بدي ملعب كورة بكرا 8 بالليل', $plain],
            'Latin name Jalal read as Al-Jalaa (one edit)' => ["hi I'm jalal, football tomorrow 8pm", $plain],
            'Latin name Bassam read as Al-Bassa (one edit)' => ['hi this is bassam, football tomorrow 8pm', $plain],
            'Latin name Rafat read as Rafah (one edit)' => ['football tomorrow 8pm for rafat', $plain],
            'للرجال (for men) read as الرمال (one edit)' => ['بدي ملعب كورة بكرا الساعة 8 بالليل للرجال', $plain],
            'نزلت (I went down) read as النزلة (one edit)' => ['نزلت ع البلد، بدي ملعب كورة بكرا 8 بالليل', $plain],
            'family name الجبالي read as Jabalia (one edit)' => ['احجز لابو الجبالي ملعب كورة بكرا 8 بالليل', $plain],
        ];
    }

    #[DataProvider('wordsReadAsAPlace')]
    public function test_a_name_or_common_word_is_not_read_as_a_place(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'DEFECT [major] a person name or common word is matched as a place and filters the search');
    }

    /**
     * Everyday words matched as a sport by substring (Arabic) or by one edit.
     */
    public static function wordsReadAsASport(): array
    {
        $ask = ['sport' => null, 'outcome' => 'needs_details'];

        return [
            'كورس (a course) read as كورة' => ['بدي كورس سباحة بكرا الساعة 7', $ask],
            'تنسى (forget) contains تنس' => ['لا تنسى بكرا الساعة 7 المسا', $ask],
            'نتبادل (swap) contains بادل' => ['بدنا نتبادل الحجز بكرا الساعة 6 المسا', $ask],
            'panel read as padel' => ['panel tomorrow 6pm', $ask],
        ];
    }

    #[DataProvider('wordsReadAsASport')]
    public function test_a_common_word_is_not_read_as_a_sport(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'DEFECT [minor] a common word is matched as a sport');
    }

    /**
     * Guards that hold today: near-miss words that must not become a sport or a day.
     */
    public static function nearMissesThatMustStayUnread(): array
    {
        return [
            'pedal is not padel' => ['pedal court tomorrow 6pm', ['sport' => null]],
            'tunis is not tennis' => ['tunis tomorrow 7pm', ['sport' => null]],
            'money is not monday' => ['football money 8pm', ['date' => null]],
            'fridge is not friday' => ['football fridge 8pm', ['date' => null]],
            'toady is today' => ['football toady 9pm', ['date' => '2026-10-03', 'venue' => 'Green Field Court']],
            'tenis is tennis' => ['tenis tomorrow 7pm', ['sport' => 'Tennis', 'venue' => 'Rafah Padel Club']],
            'shejaiya is Shujaiya' => ['football tomorrow 7pm in shejaiya', ['area' => 'Shujaiya', 'venue' => 'Shujaiya Arena']],
            'بالشجعية is الشجاعية' => ['بدي ملعب كورة بالشجعية بكرا 7 المسا', ['area' => 'الشجاعية', 'venue' => 'Shujaiya Arena']],
            'بالجلا is الجلاء' => ['بدي ملعب كورة بالجلا بكرا 8 بالليل', ['area' => 'الجلاء', 'venue' => 'Al-Jalaa Stadium']],
            'gazza is Gaza' => ['football tomorrow 8pm in gazza', ['city' => 'Gaza', 'venue' => 'Tal al-Hawa Club']],
        ];
    }

    #[DataProvider('nearMissesThatMustStayUnread')]
    public function test_near_misses_keep_their_meaning(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'Regression: a near-miss word changed meaning');
    }

    // ------------------------------------------------------------------ Task 2: spurious not_covered

    /**
     * Ordinary words after "بال", "عند", "قرب", "in", "near" or "around" that are reported
     * as a place "we don't cover", although sport, day and hour are all given.
     */
    public static function wordsReportedAsNotCovered(): array
    {
        $tomorrow8 = ['outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club'];

        return [
            'بالقرب من (near)' => ['بدي ملعب كورة بالقرب من الجامعة بكرا الساعة 8 بالليل', $tomorrow8],
            'بالقرب مني (near me)' => ['بدي ملعب كورة بالقرب مني بكرا الساعة 8 بالليل', $tomorrow8],
            'عند البيت (by the house)' => ['بدي ملعب كورة عند البيت بكرا الساعة 8 بالليل', $tomorrow8],
            'عند المسجد (by the mosque)' => ['بدي ملعب كورة عند المسجد بكرا 8 بالليل', $tomorrow8],
            'قرب البحر (near the sea)' => ['بدي ملعب كورة قرب البحر بكرا الساعة 8 بالليل', $tomorrow8],
            'بالظبط (exactly, common spelling)' => ['بدي ملعب كورة بكرا الساعة 8 بالظبط', $tomorrow8],
            'بالنهار (in the daytime)' => ['بدي ملعب كورة بكرا بالنهار الساعة 10 الصبح', ['outcome' => 'no_match', 'hour' => '10:00']],
            'بالكاش (in cash)' => ['بدي ملعب كورة بكرا 8 بالليل والدفع بالكاش', $tomorrow8],
            'بالسعر المناسب (at a fair price)' => ['بدي ملعب كورة بكرا 8 بالليل بالسعر المناسب', $tomorrow8],
            'بالحي (in the neighbourhood)' => ['بدي ملعب كورة بالحي بكرا 8 بالليل', $tomorrow8],
            'near the university' => ['football near the university tomorrow 8pm', $tomorrow8],
            'in the gym' => ['football tomorrow 8pm in the gym', $tomorrow8],
            'around the corner' => ['football tomorrow 8pm around the corner', $tomorrow8],
        ];
    }

    #[DataProvider('wordsReportedAsNotCovered')]
    public function test_an_ordinary_word_is_not_reported_as_an_uncovered_place(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'DEFECT [major] an ordinary word is reported as a place we don\'t cover, so no venue is suggested');
    }

    /**
     * Covered places in common spellings that are reported as not covered (the English
     * reply even lists "Khan Younis" as covered right after saying it isn't).
     */
    public static function coveredPlacesCalledNotCovered(): array
    {
        return [
            'khanyounis (one word)' => ['football tomorrow 6pm in khanyounis', ['city' => 'Khan Younis', 'outcome' => 'suggestion', 'venue' => 'South Court']],
            'khanyunis (one word)' => ['football tomorrow 6pm in khanyunis', ['city' => 'Khan Younis', 'outcome' => 'suggestion', 'venue' => 'South Court']],
            "shuja'iyya" => ["football tomorrow 7pm in shuja'iyya", ['outcome' => 'suggestion', 'venue' => 'Shujaiya Arena']],
            'tel al hawa' => ['football tomorrow 8pm in tel al hawa', ['outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club']],
            'tel al sultan' => ['tennis tomorrow 7pm in tel al sultan', ['outcome' => 'suggestion', 'venue' => 'Rafah Padel Club']],
            'ghazza' => ['football tomorrow 8pm in ghazza', ['city' => 'Gaza', 'outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club']],
        ];
    }

    #[DataProvider('coveredPlacesCalledNotCovered')]
    public function test_a_covered_place_in_a_common_spelling_is_not_called_not_covered(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'DEFECT [major] a covered city or area in a common spelling is reported as not covered');
    }

    /**
     * Arabizi "fi"/"fe" (in) is not a place marker, so an uncovered town is ignored and a
     * venue in another city is suggested.
     */
    public static function arabiziUncoveredTowns(): array
    {
        return [
            'fi nuseirat' => ['football tomorrow 8pm fi nuseirat'],
            'fe bait lahia' => ['bdi mal3ab koora fe bait lahia bokra 7'],
        ];
    }

    #[DataProvider('arabiziUncoveredTowns')]
    public function test_an_uncovered_town_after_arabizi_fi_is_not_covered(string $query): void
    {
        $this->assertReads($query, ['outcome' => 'not_covered', 'venue' => null], 'DEFECT [major] an uncovered town named after Arabizi "fi/fe" is ignored and a venue elsewhere is suggested');
    }

    public static function placePhrasesThatMustStayPlain(): array
    {
        $tomorrow8 = ['outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club'];

        return [
            'قريب من البيت' => ['بدي ملعب كورة قريب من البيت بكرا الساعة 8 بالليل', $tomorrow8],
            'بأي مكان' => ['بدي ملعب كورة بأي مكان بكرا الساعة 8 بالليل', $tomorrow8],
            'في أي مكان' => ['بدي ملعب كورة في أي مكان بكرا الساعة 8 بالليل', $tomorrow8],
            'عند الجامعة' => ['بدي ملعب كورة عند الجامعة بكرا الساعة 8 بالليل', $tomorrow8],
            'بالضبط' => ['بدي ملعب كورة بكرا الساعة 8 بالضبط', $tomorrow8],
            'near my house' => ['football near my house tomorrow 8pm', $tomorrow8],
            'near me' => ['football tomorrow 8pm near me', $tomorrow8],
            'in my area' => ['football tomorrow 8pm in my area', $tomorrow8],
            'anywhere' => ['football anywhere tomorrow 8pm', $tomorrow8],
            'please' => ['football tomorrow 8pm please', $tomorrow8],
            'tel al-hawa (hyphen)' => ['football tomorrow 8pm tel al-hawa', $tomorrow8],
            'النصيرات is not covered' => ['بدي ملعب كورة في النصيرات بكرا 8 بالليل', ['outcome' => 'not_covered', 'venue' => null]],
            'nuseirat after in is not covered' => ['football in nuseirat tomorrow 8pm', ['outcome' => 'not_covered', 'venue' => null]],
        ];
    }

    #[DataProvider('placePhrasesThatMustStayPlain')]
    public function test_plain_place_phrases_keep_working(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'Regression: a place phrase that worked at ea90d84 changed');
    }

    public function test_the_not_covered_reply_names_the_whole_area(): void
    {
        $answer = $this->respond('بدي ملعب كورة بالشيخ رضوان بكرا 8 بالليل');

        $this->assertSame('not_covered', $answer['outcome']);
        $this->assertStringContainsString('الشيخ رضوان', $answer['reply_text'], 'DEFECT [minor] the not-covered reply quotes only the first word of the area: '.$answer['reply_text']);
    }

    // ------------------------------------------------------------------ Task 2: hours

    /**
     * Numbers that are not an hour (prices, team sizes, durations, phone groups, dates)
     * read as one, or that hide the real hour because they come first.
     */
    public static function numbersMisreadAsAnHour(): array
    {
        $tomorrow8 = ['hour' => '20:00', 'outcome' => 'suggestion', 'venue' => 'Tal al-Hawa Club'];
        $askHour = ['hour' => null, 'outcome' => 'needs_details'];

        return [
            'price ب 20 شيكل becomes 20:00' => ['بدي ملعب كورة بكرا ب 20 شيكل', $askHour],
            'price for 20 ils becomes 20:00' => ['football tomorrow for 20 ils', $askHour],
            'price ب 50 شيكل first hides 8 بالليل' => ['بدي ملعب كورة ب 50 شيكل بكرا 8 بالليل', $tomorrow8],
            'team size 5 ضد 5 becomes 17:00 over 8 بالليل' => ['ملعب كورة 5 ضد 5 بكرا 8 بالليل', $tomorrow8],
            'team size 5 ضد 5 becomes 17:00' => ['ملعب خماسي 5 ضد 5 بكرا', $askHour],
            'team size 5 vs 5 becomes 17:00' => ['football 5 vs 5 tomorrow', $askHour],
            'player count ل 10 لاعبين becomes 22:00' => ['بدنا ملعب كورة ل 10 لاعبين بكرا 8 بالليل', $tomorrow8],
            'phone groups 12 34 56 become 00:00' => ['رقمي 0599 12 34 56 بدي ملعب كورة بكرا 8 بالليل', $tomorrow8],
            'duration for 2 hours becomes 14:00' => ['football tomorrow for 2 hours', $askHour],
            'duration at night becomes 02:00 the day after' => ['football tomorrow night for 2 hours', ['date' => '2026-10-04', 'hour' => null, 'outcome' => 'needs_details']],
            'dotted date 5.10 becomes 17:10 over الساعة 8' => ['بدي ملعب كورة يوم 5.10 الساعة 8 بالليل', ['hour' => '20:00']],
            'day of month 10 اكتوبر becomes 22:00 over 8 بالليل' => ['بدي ملعب كورة 10 اكتوبر 8 بالليل', ['hour' => '20:00']],
            'day of month 5 أكتوبر becomes 17:00' => ['بدي ملعب كورة 5 أكتوبر', ['hour' => null]],
        ];
    }

    #[DataProvider('numbersMisreadAsAnHour')]
    public function test_a_number_that_is_not_an_hour_is_not_read_as_one(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'DEFECT [major] a number that is not an hour is read as the hour (the first bare 1-2 digit number wins)');
    }

    public static function hoursThatMustStayRight(): array
    {
        return [
            'price after the hour' => ['بدي ملعب كورة بكرا 8 بالليل ب 50 شيكل', ['hour' => '20:00']],
            '5v5 is not an hour' => ['football 5v5 tomorrow', ['hour' => null]],
            '5v5 keeps 8pm' => ['football 5v5 tomorrow 8pm', ['hour' => '20:00']],
            'ساعتين is a duration' => ['بدي ملعب كورة بكرا ساعتين', ['hour' => null]],
            'لمدة ساعتين after the hour' => ['بدي ملعب كورة بكرا الساعة 8 لمدة ساعتين', ['hour' => '20:00']],
            'phone number in one block' => ['بدي ملعب كورة بكرا 8 بالليل رقمي 0599123456', ['hour' => '20:00']],
            'slash date 5/10' => ['football 5/10 at 8pm', ['date' => '2026-10-05', 'hour' => '20:00', 'venue' => 'Green Field Court']],
            'عشرة شباب is not ten o\'clock' => ['ملعب كورة بكرا لعشرة شباب', ['hour' => null]],
            'الساعة ستة' => ['بدي ملعب كورة بكرا الساعة ستة', ['hour' => '18:00']],
            'الثلاثاء المسا has no hour' => ['ملعب كورة يوم الثلاثاء المسا', ['date' => '2026-10-06', 'hour' => null]],
            'الاربعاء العصر has no hour' => ['ملعب كورة الاربعاء العصر', ['date' => '2026-10-07', 'hour' => null]],
            'seventeen is not seven' => ['tennis tomorrow at seventeen', ['hour' => null]],
            'ten in tennis is not an hour' => ['tennis tomorrow', ['hour' => null]],
            'ten pm' => ['tennis tomorrow ten pm', ['hour' => '22:00']],
            'at seven' => ['football tomorrow at seven', ['hour' => '19:00']],
        ];
    }

    #[DataProvider('hoursThatMustStayRight')]
    public function test_hours_that_worked_keep_working(string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'Regression: an hour reading that worked at ea90d84 changed');
    }

    /**
     * With Gemini, an hour is kept only when the text mentions one (mode ANY makes Gemini
     * fill it anyway). Number words and numbers that are not an hour still count as a
     * mention, so Gemini's made-up hour is used and a venue is suggested instead of asking.
     */
    public static function geminiInventedHours(): array
    {
        return [
            'عشرة شباب (ten guys)' => ['بدنا ملعب كورة بكرا لعشرة شباب', ['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']],
            'ب 20 شيكل (a price)' => ['بدي ملعب كورة بكرا ب 20 شيكل', ['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']],
            '5 أكتوبر (a day of the month)' => ['بدي ملعب كورة 5 أكتوبر', ['sport' => 'Football', 'date' => '2026-10-05', 'hour' => '20:00']],
            'one court (a count)' => ['I need one football court tomorrow', ['sport' => 'Football', 'date' => '2026-10-04', 'hour' => '18:00']],
        ];
    }

    #[DataProvider('geminiInventedHours')]
    public function test_geminis_made_up_hour_is_dropped_when_the_text_gives_no_hour(string $query, array $geminiArgs): void
    {
        $this->geminiArgs($geminiArgs);

        $this->assertReads($query, ['hour' => null, 'outcome' => 'needs_details'], 'DEFECT [major] Gemini path: an hour Gemini made up is kept because a number in the text counts as mentioning a time', useGemini: true);
    }

    // ------------------------------------------------------------------ Task 2: Arabizi digits

    public static function arabiziHours(): array
    {
        return [
            '3al 7' => ['koora bokra 3al 7', '2026-10-04', '19:00'],
            '3ala 7' => ['koora bokra 3ala 7', '2026-10-04', '19:00'],
            '3a 7' => ['koora bokra 3a 7', '2026-10-04', '19:00'],
            '3al 7:30' => ['koora bokra 3al 7:30', '2026-10-04', '19:30'],
            'standalone 7' => ['koora bokra 7', '2026-10-04', '19:00'],
            'standalone 8 after mal3ab' => ['bdi mal3ab koora bokra 8', '2026-10-04', '20:00'],
            'sa3a 7 w nos' => ['koora bokra sa3a 7 w nos', '2026-10-04', '19:30'],
            'el sa3a 3' => ['mal3ab koora bokra el sa3a 3', '2026-10-04', '15:00'],
            '3al 9 bel lail today' => ['koora el yom 3al 9 bel lail', '2026-10-03', '21:00'],
            '3al 12 bel lail' => ['koora bokra 3al 12 bel lail', '2026-10-05', '00:00'],
            '3al 10' => ['koora bokra 3al 10', '2026-10-04', '22:00'],
        ];
    }

    #[DataProvider('arabiziHours')]
    public function test_arabizi_digits_keep_their_hours(string $query, string $date, string $hour): void
    {
        $this->assertReads($query, ['sport' => 'Football', 'date' => $date, 'hour' => $hour], 'Regression: an Arabizi hour changed');
    }

    // ------------------------------------------------------------------ Task 2: midnight and after midnight

    public static function midnightEdges(): array
    {
        return [
            '23:30 tonight at 12 is 00:00 next day' => ['2026-10-03 23:30', 'كورة الليلة الساعة 12', ['date' => '2026-10-04', 'hour' => '00:00', 'outcome' => 'no_match']],
            '23:30 tomorrow 11 at night' => ['2026-10-03 23:30', 'كورة بكرا الساعة 11 بالليل', ['date' => '2026-10-04', 'hour' => '23:00', 'venue' => 'Al-Jalaa Stadium']],
            '23:30 today at 23:00 has started' => ['2026-10-03 23:30', 'كورة اليوم الساعة 11 بالليل', ['date' => '2026-10-03', 'hour' => '23:00', 'outcome' => 'no_match']],
            '00:00 tonight at 1 is the same day' => ['2026-10-04 00:00', 'كورة الليلة الساعة 1', ['date' => '2026-10-04', 'hour' => '01:00']],
            '00:00 tonight 11 at night' => ['2026-10-04 00:00', 'كورة الليلة 11 بالليل', ['date' => '2026-10-04', 'hour' => '23:00', 'venue' => 'Al-Jalaa Stadium']],
            '00:00 tonight at 11pm (English)' => ['2026-10-04 00:00', 'football tonight at 11pm', ['date' => '2026-10-04', 'hour' => '23:00', 'venue' => 'Al-Jalaa Stadium']],
            '04:59 tonight at 4 has passed' => ['2026-10-04 04:59', 'كورة الليلة الساعة 4', ['date' => '2026-10-04', 'hour' => '04:00', 'outcome' => 'no_match']],
            '04:59 tomorrow at 1 at night' => ['2026-10-04 04:59', 'كورة بكرا الساعة 1 بالليل', ['date' => '2026-10-06', 'hour' => '01:00']],
            '04:59 today 11 at night' => ['2026-10-04 04:59', 'كورة اليوم 11 بالليل', ['date' => '2026-10-04', 'hour' => '23:00', 'venue' => 'Al-Jalaa Stadium']],
            '12:00 tonight at midnight' => ['2026-10-03 12:00', 'football tonight at midnight', ['date' => '2026-10-04', 'hour' => '00:00']],
            '12:00 الليلة 1 الفجر' => ['2026-10-03 12:00', 'كورة الليلة 1 الفجر', ['date' => '2026-10-04', 'hour' => '01:00']],
        ];
    }

    #[DataProvider('midnightEdges')]
    public function test_midnight_edges(string $now, string $query, array $expected): void
    {
        $this->assertReads($query, $expected, 'Regression: a midnight edge changed', CarbonImmutable::parse($now, 'Asia/Gaza'));
    }

    /**
     * "Tonight" with an English am hour (1am, 2 am, 12am) stays on today's date, which has
     * already passed, while "tonight at 1" and "الليلة 1 الفجر" move to the next day.
     */
    public static function tonightWithAm(): array
    {
        return [
            'tonight at 1am' => ['football tonight at 1am', '01:00'],
            'tonight at 2 am' => ['football tonight at 2 am', '02:00'],
            'tonight 12am' => ['football tonight 12am', '00:00'],
        ];
    }

    #[DataProvider('tonightWithAm')]
    public function test_tonight_with_an_am_hour_is_after_midnight(string $query, string $hour): void
    {
        $this->assertReads($query, ['date' => '2026-10-04', 'hour' => $hour], 'DEFECT [minor] "tonight" with an am hour keeps today\'s (already passed) date');
    }

    public static function nextSaturdayOnASaturday(): array
    {
        return [
            'السبت الجاي' => ['كورة السبت الجاي الساعة 9 بالليل'],
            'next saturday' => ['football next saturday 9pm'],
        ];
    }

    #[DataProvider('nextSaturdayOnASaturday')]
    public function test_next_saturday_said_on_a_saturday_is_next_week(string $query): void
    {
        // Today (Saturday) has a 21:00 slot at Green Field Court, so reading "next Saturday"
        // as today suggests a venue for the wrong week.
        $this->assertReads($query, ['date' => '2026-10-10', 'outcome' => 'no_match', 'venue' => null], 'DEFECT [minor] "next Saturday" said on a Saturday is read as today');
    }

    private function addJalaaSlotAt1am(): void
    {
        TimeSlot::forceCreate([
            'venue_id' => Venue::where('name_en', 'Al-Jalaa Stadium')->value('id'),
            'sport_id' => Sport::where('name_en', 'Football')->value('id'),
            'slot_date' => '2026-10-04',
            'start_time' => '01:00:00',
            'end_time' => '02:00:00',
            'hourly_price' => 40,
            'status' => 'available',
        ]);
    }

    public function test_after_midnight_suggestions_say_so(): void
    {
        $this->addJalaaSlotAt1am();

        // Developer: the Arabic suggestion is now worded "يتوفر موعد في <venue>" so it agrees with any venue name (the gender nit).
        $this->assertSame('يتوفر موعد في ملعب الجلاء الليلة بعد منتصف الليل (الأحد 4 أكتوبر) الساعة 01:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟', $this->respond('كورة الليلة الساعة 1')['reply_text']);
        $this->assertSame('Al-Jalaa Stadium is available tonight after midnight (Sun 4 Oct) at 01:00 for 40 ILS/hour. Want me to book it?', $this->respond('football tonight at 1')['reply_text']);
    }

    public function test_after_midnight_a_slot_later_that_night_is_today(): void
    {
        // ReplyComposer parses the slot date in the app timezone (UTC) and compares it with
        // midnight in Gaza, so a slot on today's date is always "later" than today.
        $this->addJalaaSlotAt1am();
        $reply = $this->respond('football tonight at 1', now: CarbonImmutable::parse('2026-10-04 00:30', 'Asia/Gaza'))['reply_text'];

        $this->assertSame('Al-Jalaa Stadium is available today at 01:00 for 40 ILS/hour. Want me to book it?', $reply, 'DEFECT [nit] at 00:30 a 01:00 slot the same night is worded "after midnight (Sun 4 Oct)" because the date comparison mixes UTC and Asia/Gaza');
    }

    // ------------------------------------------------------------------ Task 2: replies

    public function test_needs_details_asks_only_for_what_is_missing(): void
    {
        $this->assertSame('أي ساعة؟ المتاح مساءً: 17:00، 18:00 و19:00.', $this->respond('كورة بكرا')['reply_text']);
        $this->assertSame('أي يوم؟', $this->respond('ملعب سلة الساعة 7 المسا')['reply_text']);
        $this->assertSame('أي رياضة؟', $this->respond('بدي احجز ملعب لبكرا الساعة 8')['reply_text']);
        $this->assertSame('Tell me the day and hour you want, for example: "Football tomorrow at 6pm".', $this->respond('football')['reply_text']);
    }

    public function test_a_morning_request_is_offered_morning_hours(): void
    {
        // Friday 09:00 (Al-Jalaa, South Court) and 10:00 (Shujaiya) are free; only 18:00 is offered.
        $reply = $this->respond('كورة يوم الجمعة الصبح')['reply_text'];

        $this->assertMatchesRegularExpression('/09:00|10:00/', $reply, 'DEFECT [minor] a morning request ("الصبح") is offered evening hours only: '.$reply);
    }

    // ------------------------------------------------------------------ Task 2: assistant:evaluate

    public static function minValues(): array
    {
        return [
            '0' => ['0', 0],
            '100' => ['100', 0],
            '90.0' => ['90.0', 0],
            '-1' => ['-1', 2],
            'abc' => ['abc', 2],
            'empty' => ['', 2],
            '100.0001' => ['100.0001', 2],
        ];
    }

    #[DataProvider('minValues')]
    public function test_min_edges(string $min, int $exitCode): void
    {
        $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'held_out', '--min' => $min])->assertExitCode($exitCode);
    }

    public function test_an_unknown_group_is_rejected(): void
    {
        // Developer: blind_r2 is now a fixture group (as requested), so an unknown group needs another name.
        $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'blind_r3'])
            ->expectsOutputToContain('--group must be tuning, held_out, unseen_r1, blind_r2 or all.')
            ->assertExitCode(2);
    }

    public function test_json_with_a_relative_and_an_absolute_path(): void
    {
        $relative = 'stage4-review-'.uniqid().'.json';
        $relativeTarget = storage_path('app/assistant-eval/'.$relative);
        $absolute = storage_path('framework/testing/stage4-review-'.uniqid().'.json');

        try {
            $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'held_out', '--json' => $relative])->assertSuccessful();
            $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'held_out', '--json' => $absolute])->assertSuccessful();

            foreach ([$relativeTarget, $absolute] as $path) {
                $this->assertFileExists($path);
                $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(['meta', 'groups', 'gemini_answers', 'cases'], array_keys($document));
                $this->assertSame(['held_out'], array_keys($document['groups']));
                $this->assertSame('fallback', $document['meta']['mode']);
            }
        } finally {
            @unlink($relativeTarget);
            @unlink($absolute);
        }
    }

    // ------------------------------------------------------------------ Task 2: endpoint contract 7.8

    public static function contractOutcomes(): array
    {
        return [
            'not_covered' => [
                'بدي ملعب كورة بالنصيرات بكرا الساعة 7',
                ['parsed_date' => '2026-10-04', 'parsed_hour' => '19:00', 'suggested_venue_id' => null],
                'لا نغطي النصيرات بعد — نغطي حالياً: غزة، خانيونس، دير البلح، رفح وجباليا.',
                '19:00:00',
            ],
            'needs_details' => [
                'بدي ألعب كورة بكرا',
                ['parsed_date' => '2026-10-04', 'parsed_hour' => null, 'suggested_venue_id' => null],
                'أي ساعة؟ المتاح مساءً: 17:00، 18:00 و19:00.',
                null,
            ],
        ];
    }

    #[DataProvider('contractOutcomes')]
    public function test_endpoint_contract_for_not_covered_and_needs_details(string $query, array $data, string $reply, ?string $storedHour): void
    {
        $this->seed(RoleSeeder::class);
        $player = User::factory()->create(['status' => 'active']);
        $player->assignRole('player');
        Sanctum::actingAs($player);
        // 09:00 UTC is 12:00 in Gaza, the fixture's reference time.
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $football = Sport::where('name_en', 'Football')->value('id');

        $response = $this->postJson('/api/v1/assistant/query', ['query_text' => $query])->assertOk();

        $this->assertSame(['success', 'message', 'data', 'errors'], array_keys($response->json()));
        $this->assertTrue($response->json('success'));
        $this->assertSame('OK', $response->json('message'));
        $this->assertNull($response->json('errors'));
        $this->assertSame(['assistant_query_id', 'parsed_sport_id', 'parsed_date', 'parsed_hour', 'suggested_venue_id', 'reply_text'], array_keys($response->json('data')));
        $this->assertSame(['parsed_sport_id' => $football, ...$data, 'reply_text' => $reply], array_diff_key($response->json('data'), ['assistant_query_id' => true]));

        $row = AssistantQuery::findOrFail($response->json('data.assistant_query_id'));
        $this->assertSame($player->id, $row->user_id);
        $this->assertSame($query, $row->query_text);
        $this->assertSame($football, $row->parsed_sport_id);
        $this->assertSame('2026-10-04', substr((string) $row->parsed_date, 0, 10));
        $this->assertSame($storedHour, $row->parsed_hour);
        $this->assertNull($row->suggested_venue_id);
        $this->assertSame(1, AssistantQuery::count());
        Http::assertNothingSent();
    }
}
