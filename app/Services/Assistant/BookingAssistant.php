<?php

namespace App\Services\Assistant;

use App\Models\AssistantQuery;
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
        $today = self::today();
        $arguments = $this->understand($queryText, $today);
        $slot = $this->tool->execute($arguments);
        $sport = $this->tool->findSport($arguments['sport'] ?? null);
        $arabic = TextNormalizer::isArabic($queryText);

        $replyText = match (true) {
            ! $sport || ! isset($arguments['hour']) => $this->replies->needsDetails($arabic),
            ! $slot => $this->replies->noMatch($arabic),
            default => $this->replies->suggestion($slot, $arguments['date'], $arguments['hour'], $today, $arabic),
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
     * The assistant's "today", in the players' time zone rather than the server's UTC.
     */
    public static function today(): CarbonImmutable
    {
        return LocalClock::today();
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

        $fromGemini = $this->onlyValid($fromGemini);

        // A sport outside the SPORT table can't be searched; the fallback's reading may be.
        if (isset($fromGemini['sport']) && ! $this->tool->findSport($fromGemini['sport'])) {
            unset($fromGemini['sport']);
        }

        $missing = array_diff_key(array_flip(self::REQUIRED_ARGUMENTS), $fromGemini);

        return [...$fromGemini, ...array_intersect_key($fallback, $missing)];
    }

    /**
     * Drops a date that isn't YYYY-MM-DD and pads or drops an hour so it is HH:mm, the formats
     * the search and the ERD columns expect.
     *
     * @param  array<string, string>  $arguments
     * @return array<string, string>
     */
    private function onlyValid(array $arguments): array
    {
        if (isset($arguments['date']) && ! $this->isDate($arguments['date'])) {
            unset($arguments['date']);
        }

        if (isset($arguments['hour'])) {
            $hour = preg_match('/^(\d{1,2}):([0-5]\d)(?::\d{2})?$/', $arguments['hour'], $m) && (int) $m[1] < 24
                ? sprintf('%02d:%s', $m[1], $m[2])
                : null;

            $arguments['hour'] = $hour;
        }

        return array_filter($arguments);
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
