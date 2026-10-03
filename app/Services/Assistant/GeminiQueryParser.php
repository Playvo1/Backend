<?php

namespace App\Services\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks Google Gemini (generateContent with forced function calling) to fill in the
 * VenueSearchTool arguments for a player's request. Gemini only extracts the
 * arguments; the venue itself is always picked from the database.
 */
class GeminiQueryParser implements QueryParser
{
    public function __construct(private readonly VenueSearchTool $tool) {}

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function parse(string $queryText, CarbonImmutable $today): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::baseUrl(config('services.gemini.base_url'))
                ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->timeout(config('services.gemini.timeout'))
                ->acceptJson()
                ->post('models/'.config('services.gemini.model').':generateContent', $this->payload($queryText, $today))
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Gemini request failed; the assistant falls back to the rule-based parser.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->functionArguments($response->json('candidates.0.content.parts'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $queryText, CarbonImmutable $today): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $this->systemInstruction($today)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $queryText]]]],
            'tools' => [['functionDeclarations' => [$this->tool->declaration()]]],
            'toolConfig' => ['functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [VenueSearchTool::NAME],
            ]],
            'generationConfig' => ['temperature' => 0],
        ];
    }

    private function systemInstruction(CarbonImmutable $today): string
    {
        return implode("\n", [
            'You are the Playvo booking assistant for sports venues in Palestine. Players write short requests in Arabic (often Palestinian dialect) or English.',
            "Today is {$today->englishDayOfWeek} {$today->toDateString()}; tomorrow is {$today->addDay()->toDateString()}.",
            'Always call '.VenueSearchTool::NAME.'. Resolve relative dates (today, tonight, tomorrow, اليوم, الليلة, بكرا, بكره, غداً, بعد بكرا, weekday names) to YYYY-MM-DD; if no date is given use today.',
            'Give the hour in 24-hour HH:mm. مساءً, المسا, بالليل, العصر and pm mean afternoon/evening; صباحاً, الصبح and am mean morning. An hour from 1 to 11 with no am/pm means the evening (e.g. 8 means 20:00).',
            'Use the exact enum values for sport and city. Put a neighbourhood or area such as الجلاء or Al-Rimal in area, not in city.',
        ]);
    }

    /**
     * Reads the search_available_venues call out of the reply. Any unexpected shape (a
     * non-JSON body, parts or args that are not lists/objects, non-string values) counts
     * as "no answer", so the caller falls back to the rule-based parser instead of failing.
     *
     * @return array<string, string>|null
     */
    private function functionArguments(mixed $parts): ?array
    {
        $call = collect(is_array($parts) ? $parts : [])
            ->map(fn (mixed $part) => is_array($part) ? ($part['functionCall'] ?? null) : null)
            ->first(fn (mixed $call) => is_array($call) && ($call['name'] ?? null) === VenueSearchTool::NAME);

        if (! is_array($call['args'] ?? null)) {
            Log::warning('Gemini answered without a usable '.VenueSearchTool::NAME.' call; using the rule-based parser.');

            return null;
        }

        return array_filter($call['args'], fn (mixed $value) => is_string($value) && trim($value) !== '');
    }
}
