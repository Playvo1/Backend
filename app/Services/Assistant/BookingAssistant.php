<?php

namespace App\Services\Assistant;

use App\Models\AssistantQuery;
use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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

    /**
     * What the reply did: suggested a venue, found none, asked for the sport, day or hour, or
     * said the place isn't covered.
     */
    public const SUGGESTION = 'suggestion';

    public const NO_MATCH = 'no_match';

    public const NEEDS_DETAILS = 'needs_details';

    /**
     * The city or area the player named has no venues (yet).
     */
    public const NOT_COVERED = 'not_covered';

    /**
     * Who understood the question.
     */
    public const FROM_GEMINI = 'gemini';

    public const FROM_FALLBACK = 'fallback';

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
        $answer = $this->respond($queryText, LocalClock::now());
        $arguments = $answer['arguments'];

        $query = AssistantQuery::create([
            'user_id' => $user->id,
            'query_text' => $queryText,
            'parsed_sport_id' => $answer['sport']?->id,
            'parsed_date' => $arguments['date'] ?? null,
            'parsed_hour' => isset($arguments['hour']) ? $arguments['hour'].':00' : null,
            'suggested_venue_id' => $answer['slot']?->venue_id,
        ]);

        return ['query' => $query, 'reply_text' => $answer['reply_text']];
    }

    /**
     * Understands and answers a question at the players' local time $now, without logging it.
     * The evaluation (assistant:evaluate) uses it with the fixed reference time of the test
     * cases, and with $useGemini false to measure the rule-based fallback alone.
     *
     * @return array{arguments: array<string, string>, source: string, sport: ?Sport, slot: ?TimeSlot, outcome: string, reply_text: string}
     */
    public function respond(string $queryText, CarbonImmutable $now, bool $useGemini = true): array
    {
        $today = $now->startOfDay();
        ['arguments' => $arguments, 'source' => $source, 'unknown' => $unknown] = $this->interpret($queryText, $now, $useGemini);
        $sport = $this->tool->findSport($arguments['sport'] ?? null);
        $missing = array_keys(array_filter([
            'sport' => ! $sport,
            'date' => ! isset($arguments['date']),
            'hour' => ! isset($arguments['hour']),
        ]));
        $slot = $unknown === null && $missing === [] ? $this->tool->execute($arguments, $now) : null;
        $arabic = TextNormalizer::isArabic($queryText);

        [$outcome, $replyText] = match (true) {
            $unknown !== null => [self::NOT_COVERED, $this->notCoveredReply($unknown, $arabic)],
            $missing !== [] => [self::NEEDS_DETAILS, $this->replies->needsDetails(
                $missing,
                $arabic,
                $missing === ['hour'] ? $this->tool->freeHours($arguments, $now) : [],
            )],
            ! $slot => [self::NO_MATCH, $this->replies->noMatch($arabic)],
            default => [self::SUGGESTION, $this->replies->suggestion($slot, $today, $arabic)],
        };

        return [
            'arguments' => $arguments,
            'source' => $source,
            'sport' => $sport,
            'slot' => $slot,
            'outcome' => $outcome,
            'reply_text' => $replyText,
        ];
    }

    /**
     * Gemini's reading wins; the rule-based reading is the fallback when Gemini is
     * unavailable and fills any argument Gemini didn't return.
     *
     * @return array<string, string>
     */
    public function understand(string $queryText, CarbonImmutable $now, bool $useGemini = true): array
    {
        return $this->interpret($queryText, $now, $useGemini)['arguments'];
    }

    /**
     * The source is "gemini" only when a sport, date or hour from Gemini survived the checks
     * below; unknown is a city or area the player named that isn't covered.
     *
     * @return array{arguments: array<string, string>, source: string, unknown: ?array{kind: string, name: string}}
     */
    private function interpret(string $queryText, CarbonImmutable $now, bool $useGemini): array
    {
        $today = $now->startOfDay();
        $fallback = $this->rules->parse($queryText, $today, $now);
        $fromGemini = $useGemini ? $this->gemini->parse($queryText, $today, $now) : null;

        if ($fromGemini === null) {
            return ['arguments' => $fallback, 'source' => self::FROM_FALLBACK, 'unknown' => $this->unknownPlace($queryText, $fallback)];
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

        $source = array_intersect_key($fromGemini, array_flip(self::REQUIRED_ARGUMENTS)) === [] ? self::FROM_FALLBACK : self::FROM_GEMINI;
        ['arguments' => $fromGemini, 'unknown' => $unknown] = $this->resolvePlace($fromGemini, $fallback);
        $arguments = [...$fromGemini, ...array_diff_key($fallback, $fromGemini)];

        return ['arguments' => $arguments, 'source' => $source, 'unknown' => $unknown ?? $this->unknownPlace($queryText, $arguments)];
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
     * Gemini's optional city and area are hard filters, so one that names nothing seeded must
     * not hide a real match or quietly search elsewhere:
     * - an unknown city is replaced by the rule-based reading of the text (which finds "Gaza"
     *   in "Gaza City"); when that finds none either, the city is reported as not covered;
     * - an area that is really a city name is used as the city;
     * - an area no venue is in is replaced by the rule-based reading, or reported.
     *
     * @param  array<string, string>  $arguments
     * @param  array<string, string>  $fallback
     * @return array{arguments: array<string, string>, unknown: ?array{kind: string, name: string}}
     */
    private function resolvePlace(array $arguments, array $fallback): array
    {
        $unknown = null;

        if (isset($arguments['city'])) {
            $city = $this->cityNamed($arguments['city'])?->name_en ?? $fallback['city'] ?? null;
            $unknown = $city === null ? ['kind' => 'city', 'name' => $this->shown($arguments['city'])] : null;
            $arguments['city'] = $city;
        }

        if (isset($arguments['area']) && ($city = $this->cityNamed($arguments['area']))) {
            unset($arguments['area']);
            $arguments['city'] ??= $city->name_en;
        } elseif (isset($arguments['area']) && ! $this->tool->knowsArea($arguments['area'])) {
            // Kept when the text names no known area either, so the search finds nothing
            // rather than looking everywhere.
            $area = $arguments['area'];
            $arguments['area'] = $fallback['area'] ?? $area;

            if (! isset($fallback['area']) && $unknown === null && preg_match('/\p{L}/u', $area) === 1) {
                $unknown = ['kind' => 'area', 'name' => $this->shown($area)];
            }
        }

        return ['arguments' => array_filter($arguments), 'unknown' => $unknown];
    }

    /**
     * A place the player named that no seeded city, area or venue matches.
     *
     * @param  array<string, string>  $arguments
     * @return ?array{kind: string, name: string}
     */
    private function unknownPlace(string $queryText, array $arguments): ?array
    {
        if (isset($arguments['area'])) {
            return null;
        }

        $place = $this->rules->unknownPlace($queryText);

        if ($place === null) {
            return null;
        }

        // Inside a known city it is an area there; otherwise a city we don't cover.
        return ['kind' => isset($arguments['city']) ? 'area' : 'city', 'name' => $this->shown($place)];
    }

    /**
     * @param  array{kind: string, name: string}  $unknown
     */
    private function notCoveredReply(array $unknown, bool $arabic): string
    {
        if ($unknown['kind'] === 'area') {
            return $this->replies->unknownArea($unknown['name'], $arabic);
        }

        $cities = City::query()->orderBy('id')->get(['name_ar', 'name_en'])
            ->map(fn (City $city) => $arabic ? $city->name_ar : $city->name_en)
            ->unique()
            ->values()
            ->all();

        return $this->replies->notCovered($unknown['name'], $cities, $arabic);
    }

    /**
     * A place name as it is quoted back to the player.
     */
    private function shown(string $place): string
    {
        $place = Str::limit(trim(preg_replace('/\s+/u', ' ', $place) ?? $place), 60);

        return preg_match('/^[a-z ]+$/', $place) === 1 ? ucwords($place) : $place;
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
