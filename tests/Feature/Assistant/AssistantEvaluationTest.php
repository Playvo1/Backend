<?php

namespace Tests\Feature\Assistant;

use App\Models\Venue;
use App\Services\Assistant\AssistantEvaluator;
use App\Services\Assistant\BookingAssistant;
use App\Services\Assistant\TextNormalizer;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AssistantEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.gemini.key' => null, 'services.gemini.model' => 'gemini-test']);
    }

    private function evaluator(): AssistantEvaluator
    {
        return $this->app->make(AssistantEvaluator::class);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cases(string $group = 'all')
    {
        return collect($this->evaluator()->cases($group));
    }

    private function scratch(string $name): string
    {
        $path = storage_path('framework/testing/'.$name);
        @unlink($path);

        return $path;
    }

    /**
     * A Gemini stand-in that answers every case with its expected arguments.
     */
    private function fakeGeminiAnsweringAsExpected(): void
    {
        $expected = $this->cases()->pluck('expected', 'query');

        // The latest message is the last content (v2 sends earlier turns first); an earlier
        // turn of a v2 conversation gets an empty call, so the fallback reads it.
        Http::fake(fn (Request $request) => Http::response(['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['name' => VenueSearchTool::NAME, 'args' => array_filter(
                collect($expected[collect($request['contents'])->last()['parts'][0]['text']] ?? [])->only(['sport', 'date', 'hour', 'city', 'area'])->all(),
            )],
        ]]]]]]));
    }

    // ---------------------------------------------------------------- the case set

    public function test_the_case_set_is_a_balanced_draft_with_a_held_out_group(): void
    {
        $fixture = $this->evaluator()->fixture();
        $cases = $this->cases();
        $arabic = $cases->filter(fn (array $case) => TextNormalizer::isArabic($case['query']))->count();

        $this->assertSame('draft — pending team approval', $fixture['meta']['status']);
        $this->assertSame('2026-10-03', $fixture['meta']['reference_date']);
        $this->assertSame('Saturday', $this->evaluator()->referenceNow()->englishDayOfWeek);
        $this->assertSame('2026-10-03 12:00', $this->evaluator()->referenceNow()->format('Y-m-d H:i'));

        $this->assertGreaterThanOrEqual(50, $cases->count());
        $this->assertSame($cases->count(), $cases->pluck('id')->unique()->count(), 'case ids are unique');
        $this->assertEqualsCanonicalizing(AssistantEvaluator::GROUPS, $cases->pluck('group')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(20, $this->cases('held_out')->count());
        $this->assertGreaterThanOrEqual(30, $this->cases('tuning')->count());

        // About 60% Arabic, 40% English, by majority script.
        $this->assertGreaterThanOrEqual(0.55, $arabic / $cases->count());
        $this->assertLessThanOrEqual(0.65, $arabic / $cases->count());

        foreach ($cases as $case) {
            // A mixed-language case may lean either way; the others follow their majority script.
            // A v2 case may be a conversation; its language is that of all its messages.
            if ($case['language'] !== 'mixed') {
                $conversation = implode(' ', [...array_column($case['turns'] ?? [], 'query'), $case['query']]);
                $this->assertSame($case['language'] === 'ar', TextNormalizer::isArabic($conversation), "{$case['id']} language");
            }

            // v2 cases also expect the intent and the span of days.
            $fields = ['sport', 'date', 'hour', 'city', 'area', 'outcome', 'venue', ...($case['group'] === 'v2' ? ['intent', 'period'] : [])];
            $this->assertSame($fields, array_keys($case['expected']), "{$case['id']} fields");
        }
    }

    public function test_the_case_set_covers_every_kind_of_request(): void
    {
        $cases = $this->cases();
        $queries = $cases->pluck('query')->implode("\n");

        $this->assertGreaterThanOrEqual(5, $cases->pluck('expected.sport')->filter()->unique()->count());
        $this->assertGreaterThanOrEqual(4, $cases->pluck('expected.city')->filter()->unique()->count());
        $this->assertGreaterThanOrEqual(4, $cases->pluck('expected.area')->filter()->unique()->count());
        $this->assertGreaterThanOrEqual(4, $cases->where('expected.outcome', BookingAssistant::NO_MATCH)->count());
        $this->assertGreaterThanOrEqual(2, $cases->where('expected.outcome', BookingAssistant::NEEDS_DETAILS)->whereNull('expected.hour')->count());
        $this->assertGreaterThanOrEqual(2, $cases->where('expected.outcome', BookingAssistant::NEEDS_DETAILS)->whereNull('expected.sport')->count());

        foreach (['كورة', 'فوتبول', 'soccer', 'اليوم', 'بكرا', 'بعد بكرا', 'today', 'tomorrow', 'الجمعة', 'thursday', '2026-10-09', '12/10',
            'مساءً', 'المسا', 'الصبح', 'ونص', 'وربع', 'الا ربع', 'بالليل', '6pm', '6.30pm', '18:00', '٧', 'Gaza City', 'بالشجاعيه'] as $form) {
            $this->assertStringContainsString($form, $queries, "the cases include \"{$form}\"");
        }
    }

    public function test_each_reference_venue_has_its_own_owner(): void
    {
        // An owner has one venue, so the fixture must not depend on several venues per owner.
        $this->evaluator()->seedReferenceData();

        $this->assertSame(count($this->evaluator()->fixture()['venues']), Venue::query()->count());
        $this->assertSame(Venue::query()->count(), Venue::query()->distinct()->count('owner_id'));
    }

    public function test_every_expected_venue_follows_from_the_expected_arguments(): void
    {
        // Checks the fixture itself, independent of any parser: searching with the expected
        // arguments at the reference time must give the expected venue (or none).
        $evaluator = $this->evaluator();
        $evaluator->seedReferenceData();
        $tool = new VenueSearchTool;

        // v2 cases span days, list options and hold conversations; the evaluation itself checks them.
        foreach ($this->cases()->where('group', '!=', 'v2') as $case) {
            $expected = $case['expected'];

            // A place that is not covered is not a search argument, so there is nothing to search.
            if ($expected['outcome'] === BookingAssistant::NOT_COVERED) {
                $this->assertNull($expected['venue'], $case['id']);

                continue;
            }

            if ($expected['outcome'] === BookingAssistant::NEEDS_DETAILS) {
                $this->assertTrue($expected['sport'] === null || $expected['date'] === null || $expected['hour'] === null, "{$case['id']} misses a detail");
                $this->assertNull($expected['venue'], $case['id']);

                continue;
            }

            $arguments = array_filter(collect($expected)->only(['sport', 'date', 'hour', 'city', 'area'])->all());
            $venue = $tool->execute($arguments, $evaluator->referenceNow())?->venue?->name_en;

            $this->assertSame($expected['outcome'] === BookingAssistant::SUGGESTION ? $expected['venue'] : null, $venue, $case['id']);
        }
    }

    // ---------------------------------------------------------------- accuracy (SRS objective 4)

    public function test_the_fallback_reaches_90_percent_on_the_tuning_set(): void
    {
        $this->evaluator()->seedReferenceData();

        $result = $this->evaluator()->evaluate(useGemini: false, group: 'tuning');

        $this->assertGreaterThanOrEqual(90, $result['groups']['tuning']['accuracy'], $this->misses($result));
    }

    public function test_the_fallback_reaches_90_percent_on_the_held_out_set(): void
    {
        $this->evaluator()->seedReferenceData();

        $result = $this->evaluator()->evaluate(useGemini: false, group: 'held_out');

        $this->assertGreaterThanOrEqual(90, $result['groups']['held_out']['accuracy'], $this->misses($result));
    }

    public function test_the_fallback_reaches_90_percent_on_the_reviewers_sets(): void
    {
        $this->evaluator()->seedReferenceData();

        // Written blind by the reviewer; known now, so they guard against regressions.
        foreach (['unseen_r1', 'blind_r2'] as $group) {
            $result = $this->evaluator()->evaluate(useGemini: false, group: $group);

            $this->assertGreaterThanOrEqual(90, $result['groups'][$group]['accuracy'], $this->misses($result));
        }
    }

    public function test_the_v2_conversations_carry_their_state_and_reach_90_percent(): void
    {
        $this->evaluator()->seedReferenceData();

        $result = $this->evaluator()->evaluate(useGemini: false, group: 'v2');
        $this->assertGreaterThanOrEqual(90, $result['groups']['v2']['accuracy'], $this->misses($result));

        // "8" is read against the earlier "بدي ملعب كورة بكرا".
        $followUp = collect($result['cases'])->firstWhere('id', 'v2-ar-01');
        $this->assertSame(['Football', '2026-10-04', '20:00', 'booking_search', null], [
            $followUp['actual']['sport'], $followUp['actual']['date'], $followUp['actual']['hour'], $followUp['actual']['intent'], $followUp['actual']['period'],
        ]);
        $this->assertTrue($followUp['correct']);

        // A quick reply after a quick reply: pick an area, then Deir al-Balah.
        $this->assertTrue(collect($result['cases'])->firstWhere('id', 'v2-ar-23')['correct']);

        $this->artisan('assistant:evaluate --fallback --group=v2')
            ->expectsOutputToContain('Accuracy (v2):')
            ->doesntExpectOutputToContain('Accuracy (tuning):')
            ->assertSuccessful();
    }

    public function test_a_v2_case_is_wrong_when_the_intent_or_the_span_is(): void
    {
        $checks = new \ReflectionMethod(AssistantEvaluator::class, 'checks');
        $expected = ['sport' => null, 'date' => null, 'hour' => null, 'city' => null, 'area' => null, 'outcome' => 'answer', 'venue' => null, 'intent' => 'prices', 'period' => null];
        $actual = ['sport' => null, 'date' => null, 'hour' => null, 'city' => null, 'area' => null, 'outcome' => 'answer', 'venue' => null];

        $this->assertFalse($checks->invoke($this->evaluator(), $expected, [...$actual, 'intent' => 'greeting', 'period' => null])['intent']);
        $this->assertFalse($checks->invoke($this->evaluator(), $expected, [...$actual, 'intent' => 'prices', 'period' => 'this_week'])['period']);
        $this->assertNotContains(false, $checks->invoke($this->evaluator(), $expected, [...$actual, 'intent' => 'prices', 'period' => null]));
        // A single-message case without them is checked as before.
        $this->assertArrayNotHasKey('intent', $checks->invoke($this->evaluator(), array_slice($expected, 0, 7), $actual));
    }

    private function misses(array $result): string
    {
        $misses = array_filter($result['cases'], fn (array $case) => ! $case['correct']);

        return 'Misses: '.json_encode(array_values($misses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function test_a_case_counts_only_when_every_expected_field_and_the_outcome_match(): void
    {
        $this->evaluator()->seedReferenceData();

        $result = $this->evaluator()->evaluate(useGemini: false);

        foreach ($result['cases'] as $case) {
            $this->assertSame(! in_array(false, $case['checks'], true), $case['correct'], $case['id']);
        }

        // Assistant v2 added the v2 group.
        $this->assertSame(['tuning', 'held_out', 'unseen_r1', 'blind_r2', 'v2', 'all'], array_keys($result['groups']));
        $this->assertSame(['sport', 'date', 'hour', 'place', 'venue'], array_keys($result['groups']['all']['fields']));
        $this->assertSame(
            array_sum(array_map(fn (string $group) => $result['groups'][$group]['total'], AssistantEvaluator::GROUPS)),
            $result['groups']['all']['total'],
        );
    }

    // ---------------------------------------------------------------- assistant:evaluate

    public function test_without_an_api_key_the_command_says_it_falls_back(): void
    {
        $this->artisan('assistant:evaluate --min=0')
            ->expectsOutputToContain('GEMINI_API_KEY is not set')
            ->expectsOutputToContain('Accuracy (tuning):')
            ->expectsOutputToContain('Accuracy (held_out):')
            ->expectsOutputToContain('Accuracy (unseen_r1):')
            ->expectsOutputToContain('Accuracy (blind_r2):')
            ->expectsOutputToContain('Accuracy (all):')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fallback_mode_never_calls_gemini_even_with_a_key(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->artisan('assistant:evaluate --fallback --group=tuning')
            ->expectsOutputToContain('rule-based fallback parser only')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_command_runs_every_case_through_gemini_when_a_key_is_set(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->fakeGeminiAnsweringAsExpected();
        $total = $this->cases()->count();

        // v2 questions, small talk and quick replies are answered without Gemini, so Gemini
        // answers every single-message case and only some of the v2 ones.
        $singleMessage = $this->cases()->where('group', '!=', 'v2')->count();

        $this->artisan('assistant:evaluate')
            ->expectsOutputToContain('live Gemini (gemini-test)')
            ->expectsOutputToContain("of {$total} cases; the rest used the fallback.")
            ->expectsOutputToContain("Accuracy (all): {$total}/{$total} (100%)")
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual($singleMessage, count(Http::recorded()));
        $this->evaluator()->seedReferenceData();
        $this->assertGreaterThanOrEqual($singleMessage, $this->evaluator()->evaluate(useGemini: true)['gemini_answers']);
        Http::assertSent(fn (Request $request) => str_contains($request['systemInstruction']['parts'][0]['text'], 'Today is Saturday 2026-10-03'));
    }

    public function test_failed_gemini_calls_fall_back_per_case_and_the_run_fails(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);
        $total = $this->cases()->count();

        // Every case is still answered (by the fallback), but a Gemini run that Gemini never
        // answered must not pass as a Gemini measurement.
        $this->artisan('assistant:evaluate --min=0')
            ->expectsOutputToContain("Gemini answered 0 of {$total} cases")
            ->expectsOutputToContain('Accuracy (all):')
            ->expectsOutputToContain('Gemini answered none of the cases')
            ->assertFailed();
    }

    public function test_guards_that_discard_every_gemini_value_count_as_the_fallback(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->evaluator()->seedReferenceData();
        // An unseeded sport, a past day, and an hour the text never gives: all three are dropped.
        Http::fake(fn () => Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => ['sport' => 'Cricket', 'date' => '2025-01-01', 'hour' => '18:00']]]]]]]]));

        $answer = $this->app->make(BookingAssistant::class)->respond('football tomorrow', $this->evaluator()->referenceNow());

        $this->assertSame(BookingAssistant::FROM_FALLBACK, $answer['source']);
        $this->assertSame(BookingAssistant::NEEDS_DETAILS, $answer['outcome']);
    }

    public function test_the_group_option_limits_the_cases(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->fakeGeminiAnsweringAsExpected();
        $heldOut = $this->cases('held_out')->count();

        $this->artisan('assistant:evaluate --group=held_out')
            ->expectsOutputToContain("Accuracy (held_out): {$heldOut}/{$heldOut} (100%)")
            ->doesntExpectOutputToContain('Accuracy (tuning)')
            ->assertSuccessful();

        Http::assertSentCount($heldOut);

        $this->artisan('assistant:evaluate --group=everything')->assertExitCode(2);
    }

    public function test_pace_waits_between_gemini_calls(): void
    {
        Sleep::fake();
        config(['services.gemini.key' => 'test-key']);
        $this->fakeGeminiAnsweringAsExpected();
        $heldOut = $this->cases('held_out')->count();

        $this->artisan('assistant:evaluate --group=held_out --pace=4')->assertSuccessful();

        // No wait before the first case, then one 4-second wait before each later one.
        Sleep::assertSleptTimes($heldOut - 1);
        Sleep::assertSequence(array_fill(0, $heldOut - 1, Sleep::for(4000)->milliseconds()));
    }

    public function test_pace_never_waits_without_gemini(): void
    {
        Sleep::fake();

        $this->artisan('assistant:evaluate --fallback --group=held_out --pace=4')->assertSuccessful();

        Sleep::assertNeverSlept();
    }

    public function test_pace_must_be_a_number_of_seconds(): void
    {
        foreach (['abc', '-1', '61'] as $pace) {
            $this->artisan('assistant:evaluate', ['--fallback' => true, '--pace' => $pace])
                ->expectsOutputToContain('--pace must be a number of seconds from 0 to 60')
                ->assertExitCode(2);
        }
    }

    public function test_below_the_minimum_the_command_fails_and_lists_the_misses(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $expected = $this->cases()->pluck('expected', 'query');

        // Gemini misreads every hour as 07:00; the text gives an hour, so the misreading is used.
        Http::fake(fn (Request $request) => Http::response(['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['name' => VenueSearchTool::NAME, 'args' => array_filter([
                ...collect($expected[$request['contents'][0]['parts'][0]['text']])->only(['sport', 'date', 'city', 'area'])->all(),
                'hour' => '07:00',
            ])],
        ]]]]]]));

        $this->artisan('assistant:evaluate --group=tuning')
            ->expectsOutputToContain('Misses (expected → got):')
            ->expectsOutputToContain('T01')
            ->expectsOutputToContain('hour=18:00')
            ->expectsOutputToContain('hour=07:00')
            ->assertFailed();
    }

    public function test_the_minimum_must_be_a_percentage(): void
    {
        foreach (['abc', '-1', '100.1', '', '90%'] as $min) {
            $this->artisan('assistant:evaluate', ['--fallback' => true, '--min' => $min])
                ->expectsOutputToContain('--min must be a number from 0 to 100')
                ->assertExitCode(2);
        }
    }

    public function test_the_minimum_is_configurable(): void
    {
        $this->artisan('assistant:evaluate --fallback --min=101')->assertFailed();
        $this->artisan('assistant:evaluate --fallback --group=tuning --min=50')->assertSuccessful();
    }

    public function test_results_can_be_written_as_json(): void
    {
        $path = $this->scratch('assistant-evaluation.json');

        $this->artisan('assistant:evaluate', ['--fallback' => true, '--min' => '0', '--json' => $path])
            ->expectsOutputToContain('Results written to')
            ->assertSuccessful();

        $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        @unlink($path);

        $this->assertSame('fallback', $document['meta']['mode']);
        $this->assertSame('draft — pending team approval', $document['meta']['cases']['status']);
        // Assistant v2 added the v2 group.
        $this->assertSame(['tuning', 'held_out', 'unseen_r1', 'blind_r2', 'v2', 'all'], array_keys($document['groups']));
        $this->assertCount($this->cases()->count(), $document['cases']);
        $this->assertArrayHasKey('expected', $document['cases'][0]);
        $this->assertArrayHasKey('actual', $document['cases'][0]);
    }

    public function test_a_relative_json_path_goes_under_storage(): void
    {
        $path = storage_path('app/assistant-eval/review/run.json');
        @unlink($path);

        // The path is printed with the operating system's separator only.
        $this->artisan('assistant:evaluate', ['--fallback' => true, '--group' => 'tuning', '--json' => 'review/run.json'])
            ->expectsOutputToContain('Results written to '.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path))
            ->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(base_path('review/run.json'));
        @unlink($path);
        @rmdir(dirname($path));
    }

    public function test_the_command_never_touches_the_application_database(): void
    {
        $default = config('database.default');

        $this->artisan('assistant:evaluate --fallback --min=0')->assertSuccessful();

        $this->assertSame($default, config('database.default'));
        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('sports', 0);
        $this->assertDatabaseCount('time_slots', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('assistant_queries', 0);
    }
}
