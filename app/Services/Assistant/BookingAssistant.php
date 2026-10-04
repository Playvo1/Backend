<?php

namespace App\Services\Assistant;

use App\Models\AssistantQuery;
use App\Models\City;
use App\Models\User;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * Answers a player's booking question (US-4.5): understands it with Gemini when
 * available, falls back to the rule-based parser otherwise, searches real available
 * slots, replies in the player's language and logs the query to ASSISTANT_QUERY.
 */
class BookingAssistant
{
    /**
     * The arguments a search can't run without; the fallback fills them if Gemini left one out.
     */
    private const REQUIRED_ARGUMENTS = ['sport', 'date', 'hour'];

    public function __construct(
        private readonly GeminiQueryParser $gemini,
        private readonly RuleBasedQueryParser $rules,
        private readonly VenueSearchTool $tool,
        private readonly ReplyComposer $replies,
    ) {}

    /**
     * @return array{query: AssistantQuery, reply_text: string}
     */
    public function answer(User $user, string $queryText): array
    {
        // "Today" and "already started" are the players' local time, not the server's UTC.
        $now = LocalClock::now();
        $today = $now->startOfDay();
        $arguments = $this->understand($queryText, $today);
        $slot = $this->tool->execute($arguments, $now);
        $sport = $this->tool->findSport($arguments['sport'] ?? null);
        $arabic = TextNormalizer::isArabic($queryText);

        $replyText = match (true) {
            ! $sport || ! isset($arguments['date'], $arguments['hour']) => $this->replies->needsDetails($arabic),
            ! $slot => $this->replies->noMatch($arabic),
            default => $this->replies->suggestion($slot, $today, $arabic),
        };

        $query = AssistantQuery::create([
            'user_id' => $user->id,
            'query_text' => $queryText,
            'parsed_sport_id' => $sport?->id,
            'parsed_date' => $arguments['date'] ?? null,
            'parsed_hour' => isset($arguments['hour']) ? $arguments['hour'].':00' : null,
            'suggested_venue_id' => $slot?->venue_id,
        ]);

        return ['query' => $query, 'reply_text' => $replyText];
    }

    /**
     * Gemini's reading wins; the rule-based reading is the fallback when Gemini is
     * unavailable and fills any required argument Gemini didn't return.
     *
     * @return array<string, string>
     */
    public function understand(string $queryText, CarbonImmutable $today): array
    {
        $fallback = $this->rules->parse($queryText, $today);
        $fromGemini = $this->gemini->parse($queryText, $today);

        if ($fromGemini === null) {
            return $fallback;
        }

        $fromGemini = $this->onlyValid($fromGemini, $today);

        // Mode ANY makes Gemini fill every required argument, even a day or an hour the
        // player never gave; then the assistant asks for it instead of guessing.
        if (! $this->rules->mentionsDate($queryText, $today)) {
            unset($fromGemini['date']);
        }

        if (! $this->rules->mentionsTime($queryText, $today)) {
            unset($fromGemini['hour']);
        }

        // A sport outside the SPORT table can't be searched; the fallback's reading may be.
        if (isset($fromGemini['sport']) && ! $this->tool->findSport($fromGemini['sport'])) {
            unset($fromGemini['sport']);
        }

        $fromGemini = $this->resolvePlace($fromGemini, $fallback);
        $missing = array_diff_key(array_flip(self::REQUIRED_ARGUMENTS), $fromGemini);

        return [...$fromGemini, ...array_intersect_key($fallback, $missing)];
    }

    /**
     * Keeps only the search arguments, drops a date that isn't a real YYYY-MM-DD date from
     * today on (a past day can't be booked, so the fallback's reading is used instead), and
     * pads or drops an hour so it is HH:mm, the formats the search and the ERD columns expect.
     *
     * @param  array<string, string>  $arguments
     * @return array<string, string>
     */
    private function onlyValid(array $arguments, CarbonImmutable $today): array
    {
        $arguments = array_intersect_key($arguments, array_flip(VenueSearchTool::ARGUMENTS));

        if (isset($arguments['date'])
            && (! $this->isDate($arguments['date']) || $arguments['date'] < $today->toDateString())) {
            unset($arguments['date']);
        }

        if (isset($arguments['hour'])) {
            $hour = preg_match('/^(\d{1,2}):([0-5]\d)(?::\d{2})?$/D', $arguments['hour'], $m) && (int) $m[1] < 24
                ? sprintf('%02d:%s', $m[1], $m[2])
                : null;

            $arguments['hour'] = $hour;
        }

        return array_filter($arguments);
    }

    /**
     * Gemini's optional city and area are hard filters, so one that names no seeded city must
     * not hide a real match. An unknown city is replaced by the rule-based reading of the text
     * (which finds "Gaza" in "Gaza City") or dropped; an area that is really a city name is
     * used as the city instead.
     *
     * @param  array<string, string>  $arguments
     * @param  array<string, string>  $fallback
     * @return array<string, string>
     */
    private function resolvePlace(array $arguments, array $fallback): array
    {
        if (isset($arguments['city'])) {
            $arguments['city'] = $this->cityNamed($arguments['city'])?->name_en ?? $fallback['city'] ?? null;
        }

        if (isset($arguments['area']) && ($city = $this->cityNamed($arguments['area']))) {
            unset($arguments['area']);
            $arguments['city'] ??= $city->name_en;
        }

        return array_filter($arguments);
    }

    /**
     * The seeded city a value names, also when it adds the word city ("Gaza City", "مدينة غزة").
     */
    private function cityNamed(string $value): ?City
    {
        return $this->tool->findCity($value)
            ?? $this->tool->findCity(preg_replace('/(?<!\p{L})(?:city|مدينه)(?!\p{L})/u', '', TextNormalizer::normalize($value)));
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
