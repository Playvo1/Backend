<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Scores a QueryParser against the approved assistant test cases (US-5.4). A case passes
 * when the sport, date, hour (and city, when the case names one) are understood
 * correctly and the search then suggests the expected venue, or none when no venue
 * should match. The SRS target is at least 90% of cases.
 */
class AssistantEvaluator
{
    public const FIXTURE = 'assistant/evaluation-cases.json';

    public function __construct(private readonly VenueSearchTool $tool) {}

    /**
     * @return array<string, mixed>
     */
    public function fixture(): array
    {
        return json_decode(file_get_contents(resource_path(self::FIXTURE)), true, flags: JSON_THROW_ON_ERROR);
    }

    public function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->fixture()['reference_date']);
    }

    /**
     * Creates the fixture's sports, cities, venues and slots in the current database.
     */
    public function seedReferenceData(): void
    {
        $fixture = $this->fixture();
        $today = $this->referenceDate();

        $country = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $sports = collect($fixture['sports'])->mapWithKeys(fn (array $sport) => [$sport['name_en'] => Sport::forceCreate($sport)]);
        $cities = collect($fixture['cities'])->mapWithKeys(fn (array $city) => [
            $city['name_en'] => City::forceCreate([...$city, 'country_id' => $country->id]),
        ]);
        $owner = User::forceCreate([
            'name' => 'Evaluation Owner',
            'email' => 'evaluation-owner@playvo.test',
            'password' => Hash::make(Str::random(32)),
        ]);

        foreach ($fixture['venues'] as $definition) {
            $venue = Venue::forceCreate([
                'owner_id' => $owner->id,
                'city_id' => $cities[$definition['city']]->id,
                'status' => 'active',
                ...collect($definition)->only(['name_ar', 'name_en', 'area_ar', 'area_en', 'avg_rating'])->all(),
            ]);

            foreach ($definition['slots'] as $slot) {
                $venue->sports()->syncWithoutDetaching([$sports[$slot['sport']]->id]);
                $venue->timeSlots()->create([
                    'sport_id' => $sports[$slot['sport']]->id,
                    'slot_date' => $today->addDays($slot['day'])->toDateString(),
                    'start_time' => $slot['start'].':00',
                    'end_time' => $slot['end'].':00',
                    'hourly_price' => $slot['price'],
                    'status' => 'available',
                ]);
            }
        }
    }

    /**
     * @return array{total: int, passed: int, accuracy: float, failures: list<array<string, mixed>>}
     */
    public function evaluate(QueryParser $parser): array
    {
        $today = $this->referenceDate();
        $failures = [];
        $cases = $this->fixture()['cases'];

        foreach ($cases as $case) {
            $arguments = $parser->parse($case['query'], $today) ?? [];
            $actual = $this->outcome($arguments, $today);
            $mismatches = $this->mismatches($case['expected'], $actual);

            if ($mismatches !== []) {
                $failures[] = ['query' => $case['query'], 'mismatches' => $mismatches, 'expected' => $case['expected'], 'actual' => $actual];
            }
        }

        $total = count($cases);
        $passed = $total - count($failures);

        return [
            'total' => $total,
            'passed' => $passed,
            'accuracy' => $total === 0 ? 0.0 : round($passed / $total * 100, 1),
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<string, string>  $arguments
     * @return array<string, ?string>
     */
    private function outcome(array $arguments, CarbonImmutable $today): array
    {
        $city = isset($arguments['city'])
            ? City::where('name_ar', $arguments['city'])->orWhere('name_en', $arguments['city'])->first()
            : null;

        return [
            'sport' => $this->tool->findSport($arguments['sport'] ?? null)?->name_en,
            'date' => $arguments['date'] ?? null,
            'hour' => $arguments['hour'] ?? null,
            'city' => $city?->name_en,
            'venue' => $this->tool->execute($arguments, $today)?->venue?->name_en,
        ];
    }

    /**
     * @return list<string> the fields that didn't come out as expected
     */
    private function mismatches(array $expected, array $actual): array
    {
        return collect(['sport', 'date', 'hour', 'city', 'venue'])
            ->reject(fn (string $field) => $field === 'city' && $expected['city'] === null)
            ->reject(fn (string $field) => $expected[$field] === $actual[$field])
            ->values()
            ->all();
    }
}
