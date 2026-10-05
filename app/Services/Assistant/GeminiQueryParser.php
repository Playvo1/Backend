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
 * arguments; the venue, slot and price always come from the database.
 */
class GeminiQueryParser implements QueryParser
{
    public function __construct(private readonly VenueSearchTool $tool) {}

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function parse(string $queryText, CarbonImmutable $today, ?CarbonImmutable $now = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::baseUrl(config('services.gemini.base_url'))
                // The key goes in a header so it never shows up in a logged URL.
                ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->connectTimeout(3)
                ->timeout(config('services.gemini.timeout'))
                ->acceptJson()
                ->post('models/'.config('services.gemini.model').':generateContent', $this->payload($queryText, $now ?? $today))
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
    private function payload(string $queryText, CarbonImmutable $now): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $this->systemInstruction($now)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $queryText]]]],
            'tools' => [['functionDeclarations' => [$this->tool->declaration()]]],
            'toolConfig' => ['functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [VenueSearchTool::NAME],
            ]],
            'generationConfig' => ['temperature' => 0],
        ];
    }

    private function systemInstruction(CarbonImmutable $now): string
    {
        $today = $now->startOfDay();

        return implode("\n", [
            'You are the Playvo booking assistant for sports venues in Palestine. Players write short requests in Arabic (often Palestinian dialect), English or Arabizi.',
            "Today is {$today->englishDayOfWeek} {$today->toDateString()}; tomorrow is {$today->addDay()->toDateString()}. The local time now is {$now->format('H:i')}.",
            'Always call '.VenueSearchTool::NAME.'. Resolve relative dates (today, tonight, tomorrow, اليوم, الليلة, بكرا, بكره, غداً, بعد بكرا, bukra, weekday names) to YYYY-MM-DD.',
            'If the player names no day, leave date empty, and if they give no hour, leave hour empty: never guess them, the assistant asks the player instead.',
            'Give the hour in 24-hour HH:mm. مساءً, المسا, العصر and pm mean afternoon/evening; صباحاً, الصبح and am mean morning. An hour from 1 to 11 with no am/pm means the evening (e.g. 8 means 20:00). Hours may be written as words (ستة, عالتمانية, السابعة, seven).',
            'At night (بالليل, at night), 5 to 11 mean 17:00 to 23:00, while 12, midnight and 1 to 4 are after midnight: 00:00 to 04:00 on the next day\'s date (اليوم الساعة 12 بالليل is tomorrow at 00:00). Between 00:00 and 05:00, tonight or الليلة with 1 to 4 means later today.',
            'ونص adds 30 minutes, وربع adds 15 and الا ربع takes 15 away (6 الا ربع means 17:45).',
            'Use the exact enum values for sport and city. Put a neighbourhood or area such as الجلاء or Al-Rimal in area, not in city.',
        ]);
    }

    /**
     * Reads the search_available_venues call out of the reply. Any unexpected shape (a
     * non-JSON body, parts or args that are not lists/objects, another function name,
     * non-string values) counts as "no answer", so the caller falls back to the
     * rule-based parser instead of failing.
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
