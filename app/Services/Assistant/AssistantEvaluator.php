<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Scores the booking assistant against the approved test cases (US-5.4, SRS objective 4:
 * at least 90% correct suggestions). Every case runs through BookingAssistant at the
 * fixture's fixed reference time, with Gemini or with the rule-based fallback alone.
 *
 * A case is correct only when the outcome matches (the expected venue, no match, a request
 * for the missing details, or a place that is not covered) and the sport, date and hour,
 * plus the city or area when the case expects one, were understood as expected. Each group
 * (GROUPS) is reported separately, as is the accuracy of each field.
 *
 * Assistant v2 cases (group v2) may be a conversation: "turns" are the messages before the
 * final "query", answered in order with the state carried over, and a message may be a
 * quick reply (option_id) or share a location (latitude/longitude, as "context" for the
 * final one). They also expect the intent and the span of days (period) understood.
 */
class AssistantEvaluator
{
    public const FIXTURE = 'assistant/evaluation-cases.json';

    public const GROUPS = ['tuning', 'held_out', 'unseen_r1', 'blind_r2', 'v2'];

    public const FIELDS = ['sport', 'date', 'hour', 'place', 'venue'];

    public function __construct(
        private readonly BookingAssistant $assistant,
        private readonly VenueSearchTool $tool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fixture(): array
    {
        return json_decode(file_get_contents(resource_path(self::FIXTURE)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The players' local time the cases are written against (e.g. "today" and "already started").
     */
    public function referenceNow(): CarbonImmutable
    {
        $meta = $this->fixture()['meta'];

        return CarbonImmutable::parse("{$meta['reference_date']} {$meta['reference_time']}", $meta['timezone']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cases(string $group = 'all'): array
    {
        return array_values(array_filter(
            $this->fixture()['cases'],
            fn (array $case) => $group === 'all' || $case['group'] === $group,
        ));
    }

    /**
     * Creates the fixture's sports, cities, venues, venue_sports and slots in the current
     * default database. The command points that at a throwaway in-memory database.
     */
    public function seedReferenceData(): void
    {
        $fixture = $this->fixture();

        $country = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $sports = collect($fixture['sports'])->mapWithKeys(fn (array $sport) => [$sport['name_en'] => Sport::forceCreate($sport)]);
        $cities = collect($fixture['cities'])->mapWithKeys(fn (array $city) => [
            $city['name_en'] => City::forceCreate([...$city, 'country_id' => $country->id]),
        ]);
        foreach ($fixture['venues'] as $index => $definition) {
            // Each venue gets its own owner: an owner has one venue (VENUE.owner_id is unique).
            $owner = User::forceCreate([
                'name' => 'Evaluation Owner '.($index + 1),
                'email' => 'evaluation-owner-'.($index + 1).'@playvo.test',
                'password' => Hash::make(Str::random(32)),
            ]);

            $venue = Venue::forceCreate([
                'owner_id' => $owner->id,
                'city_id' => $cities[$definition['city']]->id,
                'status' => 'active',
                ...collect($definition)->only(['name_ar', 'name_en', 'area_ar', 'area_en', 'avg_rating', 'latitude', 'longitude'])->all(),
            ]);

            foreach ($definition['sports'] as $sport) {
                DB::table('venue_sports')->insert(['venue_id' => $venue->id, 'sport_id' => $sports[$sport]->id]);
            }

            foreach ($definition['slots'] as $slot) {
                TimeSlot::forceCreate([
                    'venue_id' => $venue->id,
                    'sport_id' => $sports[$slot['sport']]->id,
                    'slot_date' => $slot['date'],
                    'start_time' => $slot['start'].':00',
                    'end_time' => $slot['end'].':00',
                    'hourly_price' => $slot['price'],
                    'status' => 'available',
                ]);
            }
        }
    }

    /**
     * @return array{groups: array<string, array<string, mixed>>, cases: list<array<string, mixed>>, gemini_answers: int}
     */
    public function evaluate(bool $useGemini, string $group = 'all', float $pace = 0): array
    {
        $now = $this->referenceNow();
        $results = [];
        $messages = 0;

        foreach ($this->cases($group) as $case) {
            $state = ConversationState::empty();
            $history = [];

            foreach ([...($case['turns'] ?? []), ['query' => $case['query'], ...($case['context'] ?? [])]] as $message) {
                // The free tier limits Gemini requests per minute, so live runs can be spaced out.
                if ($useGemini && $pace > 0 && $messages++ > 0) {
                    Sleep::for((int) round($pace * 1000))->milliseconds();
                }

                $answer = $this->assistant->respond($message['query'] ?? '', $now, $useGemini, $state, [
                    ...array_intersect_key($message, array_flip(['option_id', 'latitude', 'longitude'])),
                    'history' => $history,
                ]);
                $state = $answer['state'];
                $history[] = ['query' => $answer['query_text'], 'reply' => $answer['reply_text']];
            }

            $actual = $this->actual($answer);

            // v2 cases also expect the intent and the span of days.
            foreach (['intent' => $answer['intent'], 'period' => $answer['period']['name'] ?? null] as $field => $value) {
                if (array_key_exists($field, $case['expected'])) {
                    $actual[$field] = $value;
                }
            }

            $checks = $this->checks($case['expected'], $actual);

            $results[] = [
                'id' => $case['id'],
                'group' => $case['group'],
                'language' => $case['language'],
                'query' => $case['query'],
                'source' => $answer['source'],
                'correct' => ! in_array(false, $checks, true),
                'wrong' => array_keys(array_filter($checks, fn (?bool $check) => $check === false)),
                'checks' => $checks,
                'expected' => $case['expected'],
                'actual' => $actual,
            ];
        }

        $groups = [];

        foreach ($group === 'all' ? [...self::GROUPS, 'all'] : [$group] as $name) {
            $groups[$name] = $this->summary(array_values(array_filter(
                $results,
                fn (array $result) => $name === 'all' || $result['group'] === $name,
            )));
        }

        return [
            'groups' => $groups,
            'cases' => $results,
            'gemini_answers' => count(array_filter($results, fn (array $result) => $result['source'] === BookingAssistant::FROM_GEMINI)),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private function actual(array $answer): array
    {
        $arguments = $answer['arguments'];

        return [
            'sport' => $answer['sport']?->name_en,
            'date' => $arguments['date'] ?? null,
            'hour' => $arguments['hour'] ?? null,
            'city' => $this->tool->findCity($arguments['city'] ?? null)?->name_en ?? ($arguments['city'] ?? null),
            'area' => $arguments['area'] ?? null,
            'outcome' => $answer['outcome'],
            'venue' => $answer['slot']?->venue?->name_en,
        ];
    }

    /**
     * @return array<string, ?bool> null for "place" when the case expects no city or area
     */
    private function checks(array $expected, array $actual): array
    {
        $expectsPlace = $expected['city'] !== null || $expected['area'] !== null;

        return [
            'sport' => $expected['sport'] === $actual['sport'],
            'date' => $expected['date'] === $actual['date'],
            'hour' => $expected['hour'] === $actual['hour'],
            'place' => $expectsPlace
                ? ($expected['city'] === null || $expected['city'] === $actual['city'])
                    && ($expected['area'] === null || $this->sameArea($expected['area'], $actual['area']))
                : null,
            'venue' => $expected['outcome'] === $actual['outcome'] && $expected['venue'] === $actual['venue'],
            // v2: the intent and the span of days, when the case expects them.
            ...collect(['intent', 'period'])
                ->filter(fn (string $field) => array_key_exists($field, $expected))
                ->mapWithKeys(fn (string $field) => [$field => $expected[$field] === ($actual[$field] ?? null)])
                ->all(),
        ];
    }

    private function sameArea(string $expected, ?string $actual): bool
    {
        return $actual !== null && TextNormalizer::normalize($expected) === TextNormalizer::normalize($actual);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summary(array $results): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $applicable = array_filter($results, fn (array $result) => $result['checks'][$field] !== null);
            $correct = count(array_filter($applicable, fn (array $result) => $result['checks'][$field]));
            $fields[$field] = ['correct' => $correct, 'total' => count($applicable), 'accuracy' => $this->percent($correct, count($applicable))];
        }

        $passed = count(array_filter($results, fn (array $result) => $result['correct']));

        return [
            'total' => count($results),
            'passed' => $passed,
            'accuracy' => $this->percent($passed, count($results)),
            'fields' => $fields,
        ];
    }

    private function percent(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 1);
    }
}
