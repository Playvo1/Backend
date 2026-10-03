<?php

namespace Tests\Feature\Assistant;

use App\Services\Assistant\AssistantEvaluator;
use App\Services\Assistant\RuleBasedQueryParser;
use App\Services\Assistant\TextNormalizer;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function evaluator(): AssistantEvaluator
    {
        return $this->app->make(AssistantEvaluator::class);
    }

    public function test_the_approved_case_set_covers_both_languages_and_every_field(): void
    {
        $cases = collect($this->evaluator()->fixture()['cases']);

        $this->assertGreaterThanOrEqual(20, $cases->count());
        $this->assertGreaterThanOrEqual(10, $cases->filter(fn ($case) => TextNormalizer::isArabic($case['query']))->count());
        $this->assertGreaterThanOrEqual(10, $cases->reject(fn ($case) => TextNormalizer::isArabic($case['query']))->count());
        $this->assertGreaterThanOrEqual(5, $cases->whereNotNull('expected.city')->count());
        $this->assertGreaterThanOrEqual(2, $cases->whereNull('expected.venue')->count());
        $this->assertGreaterThanOrEqual(4, $cases->pluck('expected.sport')->unique()->count());
    }

    public function test_command_evaluates_the_fallback_parser_without_an_api_key(): void
    {
        config(['services.gemini.key' => null]);
        Http::preventStrayRequests();

        $this->artisan('assistant:evaluate')
            ->expectsOutputToContain('GEMINI_API_KEY is not set')
            ->expectsOutputToContain('Accuracy:')
            ->assertSuccessful();
    }

    public function test_command_runs_the_case_set_against_gemini_when_a_key_is_set(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $expected = collect($this->evaluator()->fixture()['cases'])->pluck('expected', 'query');

        Http::fake(function (Request $request) use ($expected) {
            $answer = $expected[$request['contents'][0]['parts'][0]['text']];

            return Http::response(['candidates' => [['content' => ['parts' => [[
                'functionCall' => ['name' => VenueSearchTool::NAME, 'args' => array_filter([
                    'sport' => $answer['sport'],
                    'date' => $answer['date'],
                    'hour' => $answer['hour'],
                    'city' => $answer['city'],
                ])],
            ]]]]]]);
        });

        $this->artisan('assistant:evaluate')
            ->expectsOutputToContain('Gemini parser')
            ->expectsOutputToContain("Accuracy: {$expected->count()}/{$expected->count()} (100%)")
            ->assertSuccessful();

        Http::assertSentCount($expected->count());
    }

    public function test_command_fails_below_the_target_and_lists_the_misses(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);

        $this->artisan('assistant:evaluate')
            ->expectsOutputToContain('Accuracy: 0/')
            ->assertFailed();
    }

    public function test_command_leaves_the_application_database_untouched(): void
    {
        $this->artisan('assistant:evaluate --fallback')->assertSuccessful();

        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('sports', 0);
    }

    public function test_the_fallback_parser_meets_the_90_percent_target(): void
    {
        $evaluator = $this->evaluator();
        $evaluator->seedReferenceData();

        $result = $evaluator->evaluate(new RuleBasedQueryParser);

        $this->assertGreaterThanOrEqual(
            90,
            $result['accuracy'],
            'Fallback parser failures: '.json_encode($result['failures'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }
}
