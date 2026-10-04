<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantEvaluator;
use App\Services\Assistant\GeminiQueryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs the approved assistant test cases (US-5.4) through the booking assistant and
 * prints the suggestion accuracy for the tuning and held-out groups against the SRS's
 * 90% target. By default the assistant uses live Gemini when GEMINI_API_KEY is set;
 * --fallback measures the rule-based parser alone. The fixture is loaded into a
 * throwaway in-memory SQLite database, so the configured database is never touched.
 */
class EvaluateAssistant extends Command
{
    private const CONNECTION = 'assistant_evaluation';

    protected $signature = 'assistant:evaluate
        {--fallback : Use only the rule-based fallback parser, never Gemini}
        {--group=all : Which cases to run: tuning, held_out or all}
        {--min=90 : Minimum accuracy (%) every reported group must reach for the command to succeed}
        {--json= : Also write the full results as JSON to this path}';

    protected $description = 'Measure the booking assistant against the approved test-case set';

    public function handle(AssistantEvaluator $evaluator, GeminiQueryParser $gemini): int
    {
        $group = (string) $this->option('group');

        if (! in_array($group, [...AssistantEvaluator::GROUPS, 'all'], true)) {
            $this->error('--group must be tuning, held_out or all.');

            return self::INVALID;
        }

        if (! extension_loaded('pdo_sqlite')) {
            $this->error('The pdo_sqlite PHP extension is required to run the evaluation.');

            return self::FAILURE;
        }

        $useGemini = ! $this->option('fallback');
        $this->describeMode($useGemini, $gemini, $evaluator);

        $result = $this->inScratchDatabase(function () use ($evaluator, $useGemini, $group) {
            $evaluator->seedReferenceData();

            return $evaluator->evaluate($useGemini, $group);
        });

        $passed = $this->report($result, $useGemini);

        if ($path = $this->option('json')) {
            $this->writeJson($path, $result, $useGemini, $evaluator);
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function describeMode(bool $useGemini, GeminiQueryParser $gemini, AssistantEvaluator $evaluator): void
    {
        $meta = $evaluator->fixture()['meta'];

        if (! $useGemini) {
            $this->info('Evaluating the rule-based fallback parser only.');
        } elseif ($gemini->isConfigured()) {
            $this->info('Evaluating the booking assistant with live Gemini ('.config('services.gemini.model').'), falling back per case if a call fails.');
        } else {
            $this->warn('GEMINI_API_KEY is not set, so the assistant falls back to the rule-based parser for every case.');
        }

        $this->line("Test cases: {$meta['status']}; reference {$meta['reference_weekday']} {$meta['reference_date']} {$meta['reference_time']} ({$meta['timezone']}).");
    }

    /**
     * @return array<string, mixed>
     */
    private function inScratchDatabase(callable $callback): array
    {
        $previous = ['database.default' => config('database.default'), 'cache.default' => config('cache.default')];

        // The cache is switched too: migrations flush the permission cache, which would
        // otherwise go to the real (possibly database-backed) cache store.
        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => self::CONNECTION,
            'cache.default' => 'array',
        ]);

        try {
            $this->callSilently('migrate', ['--database' => self::CONNECTION, '--force' => true]);

            return $callback();
        } finally {
            config($previous);
            DB::purge(self::CONNECTION);
        }
    }

    /**
     * @return bool whether every reported group reached --min
     */
    private function report(array $result, bool $useGemini): bool
    {
        $min = (float) $this->option('min');
        $misses = array_values(array_filter($result['cases'], fn (array $case) => ! $case['correct']));

        if ($misses !== []) {
            $this->newLine();
            $this->line('Misses (expected → got):');
            $this->table(
                ['Case', 'Query', 'Wrong', 'Expected', 'Got'],
                array_map(fn (array $miss) => [
                    $miss['id'],
                    $miss['query'],
                    implode(', ', $miss['wrong']),
                    $this->describe($miss['expected'], $miss['wrong']),
                    $this->describe($miss['actual'], $miss['wrong']),
                ], $misses),
            );
        }

        $this->newLine();
        $this->table(
            ['Group', 'Correct', 'Accuracy', 'Sport', 'Date', 'Hour', 'City/area', 'Venue'],
            array_map(fn (string $name, array $group) => [
                $name,
                "{$group['passed']}/{$group['total']}",
                "{$group['accuracy']}%",
                ...array_map(
                    fn (string $field) => "{$group['fields'][$field]['correct']}/{$group['fields'][$field]['total']} ({$group['fields'][$field]['accuracy']}%)",
                    ['sport', 'date', 'hour', 'place', 'venue'],
                ),
            ], array_keys($result['groups']), $result['groups']),
        );

        if ($useGemini) {
            $this->line("Gemini answered {$result['gemini_answers']} of ".count($result['cases']).' cases; the rest used the fallback.');
        }

        $passed = true;

        foreach ($result['groups'] as $name => $group) {
            $line = "Accuracy ({$name}): {$group['passed']}/{$group['total']} ({$group['accuracy']}%) — target {$min}%";

            if ($group['accuracy'] >= $min) {
                $this->info($line);
            } else {
                $this->error($line);
                $passed = false;
            }
        }

        return $passed;
    }

    /**
     * @param  list<string>  $fields
     */
    private function describe(array $values, array $fields): string
    {
        $shown = [];

        foreach ($fields as $field) {
            $keys = match ($field) {
                'place' => ['city', 'area'],
                'venue' => ['outcome', 'venue'],
                default => [$field],
            };

            foreach ($keys as $key) {
                $shown[] = $key.'='.($values[$key] ?? 'none');
            }
        }

        return implode(', ', $shown);
    }

    private function writeJson(string $path, array $result, bool $useGemini, AssistantEvaluator $evaluator): void
    {
        $path = preg_match('/^([a-zA-Z]:)?[\\\\\/]/', $path) ? $path : base_path($path);

        $document = [
            'meta' => [
                'mode' => $useGemini ? 'assistant' : 'fallback',
                'model' => $useGemini && filled(config('services.gemini.key')) ? config('services.gemini.model') : null,
                'cases' => $evaluator->fixture()['meta'],
                'generated_at' => now()->toIso8601String(),
            ],
            'groups' => $result['groups'],
            'gemini_answers' => $result['gemini_answers'],
            'cases' => $result['cases'],
        ];

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->line("Results written to {$path}");
    }
}
