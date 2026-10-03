<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantEvaluator;
use App\Services\Assistant\GeminiQueryParser;
use App\Services\Assistant\QueryParser;
use App\Services\Assistant\RuleBasedQueryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs the approved assistant test cases (US-5.4) and prints the suggestion accuracy
 * against the SRS's 90% target. Uses live Gemini when GEMINI_API_KEY is set, otherwise
 * (or with --fallback) the rule-based parser. The fixture's venues and slots are loaded
 * into a throwaway in-memory SQLite database, so real data is never read or written.
 */
class EvaluateAssistant extends Command
{
    private const CONNECTION = 'assistant_evaluation';

    protected $signature = 'assistant:evaluate
        {--fallback : Evaluate the rule-based fallback parser instead of Gemini}
        {--min=90 : Minimum accuracy (%) for the command to succeed}';

    protected $description = 'Measure the booking assistant against the approved test-case set';

    public function handle(GeminiQueryParser $gemini, RuleBasedQueryParser $rules, AssistantEvaluator $evaluator): int
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->error('The pdo_sqlite PHP extension is required to run the evaluation.');

            return self::FAILURE;
        }

        [$parser, $label] = $this->chooseParser($gemini, $rules);
        $this->info("Evaluating the {$label} on the approved test cases...");

        $result = $this->inScratchDatabase(function () use ($evaluator, $parser) {
            $evaluator->seedReferenceData();

            return $evaluator->evaluate($parser);
        });

        $this->report($result);

        return $result['accuracy'] >= (float) $this->option('min') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: QueryParser, 1: string}
     */
    private function chooseParser(GeminiQueryParser $gemini, RuleBasedQueryParser $rules): array
    {
        if ($this->option('fallback')) {
            return [$rules, 'rule-based fallback parser'];
        }

        if (! $gemini->isConfigured()) {
            $this->warn('GEMINI_API_KEY is not set; evaluating the rule-based fallback parser instead.');

            return [$rules, 'rule-based fallback parser'];
        }

        return [$gemini, 'Gemini parser ('.config('services.gemini.model').')'];
    }

    private function inScratchDatabase(callable $callback): array
    {
        $previous = ['database.default' => config('database.default'), 'cache.default' => config('cache.default')];

        // The cache is switched too: migrations flush the permission cache, which would
        // otherwise go to the real database-backed cache store.
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

    private function report(array $result): void
    {
        if ($result['failures'] !== []) {
            $this->table(
                ['Query', 'Wrong', 'Expected', 'Got'],
                collect($result['failures'])->map(fn (array $failure) => [
                    $failure['query'],
                    implode(', ', $failure['mismatches']),
                    $this->describe($failure['expected'], $failure['mismatches']),
                    $this->describe($failure['actual'], $failure['mismatches']),
                ])->all(),
            );
        }

        $line = "Accuracy: {$result['passed']}/{$result['total']} ({$result['accuracy']}%) — target {$this->option('min')}%";

        $result['accuracy'] >= (float) $this->option('min') ? $this->info($line) : $this->error($line);
    }

    private function describe(array $values, array $fields): string
    {
        return collect($fields)->map(fn (string $field) => $field.'='.($values[$field] ?? 'none'))->implode(', ');
    }
}
