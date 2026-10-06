<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * The single function Gemini may call for the booking assistant (US-4.5).
 *
 * Gemini only turns the player's sentence into these arguments; the venue is
 * always picked from real, available time slots here, never invented by the
 * model. Sport and city are offered as enums of the seeded names so the model
 * can only answer with values that exist in the SPORT and CITY tables.
 *
 * The argument names are kept short for the model; they map to the ERD as:
 * - sport: SPORT.name_ar / name_en, logged as ASSISTANT_QUERY.parsed_sport_id
 * - date:  TIME_SLOT.slot_date, logged as ASSISTANT_QUERY.parsed_date
 * - hour:  inside TIME_SLOT.start_time..end_time, logged as ASSISTANT_QUERY.parsed_hour
 * - city:  CITY.name_ar / name_en, matched against VENUE.city_id
 * - area:  VENUE.area_ar / area_en / address_ar / address_en
 * The venue found is logged as ASSISTANT_QUERY.suggested_venue_id.
 */
class VenueSearchTool
{
    public const NAME = 'search_available_venues';

    private const ARGUMENTS = ['sport', 'date', 'hour', 'city', 'area'];

    /**
     * A slot that ends at midnight is stored with this end_time.
     */
    private const MIDNIGHT = '00:00:00';

    /**
     * Gemini function declaration (OpenAPI subset used by function calling).
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Find an available sports venue time slot for a sport, date and hour. '
                .'Resolve relative dates such as "today" or "بكرا" to an absolute date before calling.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'sport' => $this->withEnum([
                        'type' => 'STRING',
                        'description' => 'Sport the player wants, in Arabic or English.',
                    ], $this->namesOf(Sport::query())),
                    'date' => [
                        'type' => 'STRING',
                        'description' => 'Requested date as YYYY-MM-DD.',
                    ],
                    'hour' => [
                        'type' => 'STRING',
                        'description' => 'Requested start hour in 24-hour HH:mm, e.g. "18:00" for 6 pm.',
                    ],
                    'city' => $this->withEnum([
                        'type' => 'STRING',
                        'description' => 'City, if the player mentioned one.',
                    ], $this->namesOf(City::query())),
                    'area' => [
                        'type' => 'STRING',
                        'description' => 'Neighbourhood or area, if the player mentioned one (e.g. "الجلاء").',
                    ],
                ],
                'required' => ['sport', 'date', 'hour'],
            ],
        ];
    }

    /**
     * Runs the search for the arguments Gemini returned.
     *
     * Returns the best available slot (highest-rated venue, then cheapest),
     * or null when nothing matches — the caller then replies with the
     * "No venues match that request — try a different time." fallback.
     * A slot on a past day, or one that has already started today, is never
     * suggested; "now" is $now when given, otherwise the current time.
     */
    public function execute(array $arguments, ?CarbonInterface $now = null): ?TimeSlot
    {
        if (! $this->hasOnlyStringArguments($arguments)) {
            return null;
        }

        $now ??= now();
        $today = $now->toDateString();
        $sport = $this->findSport($arguments['sport'] ?? null);
        $date = $this->parseDate($arguments['date'] ?? null);
        $hour = $this->parseHour($arguments['hour'] ?? null);

        if (! $sport || ! $date || ! $hour || $date < $today) {
            return null;
        }

        $query = TimeSlot::query()
            ->select('time_slots.*')
            ->join('venues', 'venues.id', '=', 'time_slots.venue_id')
            // Like GET /venues: the venue must offer the sport, not only have a slot for it.
            ->join('venue_sports', fn (JoinClause $join) => $join
                ->on('venue_sports.venue_id', '=', 'venues.id')
                ->on('venue_sports.sport_id', '=', 'time_slots.sport_id'))
            ->where('time_slots.sport_id', $sport->id)
            ->whereDate('time_slots.slot_date', $date)
            ->where('time_slots.start_time', '<=', $hour)
            ->where(fn (Builder $q) => $q
                ->where('time_slots.end_time', '>', $hour)
                ->orWhere('time_slots.end_time', self::MIDNIGHT))
            ->where('time_slots.status', 'available')
            ->where('venues.status', 'active')
            ->when($date === $today, fn (Builder $q) => $q->where('time_slots.start_time', '>', $now->format('H:i:s')));

        $city = $this->filledArgument($arguments, 'city');

        if ($city !== null) {
            // A city name can exist in several countries (e.g. Rafah), so every match is searched.
            $cityIds = $this->matchingByName(City::query(), $city)->modelKeys();

            if ($cityIds === []) {
                return null;
            }

            $query->whereIn('venues.city_id', $cityIds);
        }

        $area = $this->filledArgument($arguments, 'area');

        if ($area !== null) {
            $pattern = $this->areaPattern($area);

            if ($pattern === null) {
                return null;
            }

            $query->where(function (Builder $q) use ($pattern) {
                foreach (['area_ar', 'area_en', 'address_ar', 'address_en'] as $column) {
                    $q->orWhereRaw("venues.{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query
            ->orderByDesc('venues.avg_rating')
            ->orderBy('time_slots.hourly_price')
            ->orderBy('time_slots.id')
            ->with('venue')
            ->first();
    }

    public function findSport(?string $name): ?Sport
    {
        return $this->matchingByName(Sport::query(), $name)->first();
    }

    /**
     * The arguments come from the model, so anything that isn't a string is rejected.
     */
    private function hasOnlyStringArguments(array $arguments): bool
    {
        foreach (self::ARGUMENTS as $key) {
            if (isset($arguments[$key]) && ! is_string($arguments[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * An optional argument, trimmed; null when it is missing or blank.
     */
    private function filledArgument(array $arguments, string $key): ?string
    {
        $value = trim($arguments[$key] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Gemini rejects an empty enum, so the list is only offered when there are values.
     */
    private function withEnum(array $property, array $values): array
    {
        return $values === [] ? $property : [...$property, 'enum' => $values];
    }

    /**
     * Arabic and English names, so the model can answer in the player's language.
     */
    private function namesOf(Builder $query): array
    {
        return $query->get(['name_ar', 'name_en'])
            ->flatMap(fn ($row) => [$row->name_ar, $row->name_en])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Rows whose Arabic or English name equals $name once both are normalised. The SPORT
     * and CITY tables are small, so they are compared in PHP and behave the same on every database.
     */
    private function matchingByName(Builder $query, ?string $name): Collection
    {
        $wanted = TextNormalizer::normalize($name ?? '');

        if ($wanted === '') {
            return new Collection;
        }

        return $query->orderBy('id')->get()
            ->filter(fn ($row) => TextNormalizer::normalize($row->name_ar) === $wanted
                || TextNormalizer::normalize($row->name_en) === $wanted)
            ->values();
    }

    /**
     * LIKE pattern for the free-text area. Wildcards typed by the player are escaped
     * ("!" is the escape character because it means the same on MySQL and SQLite),
     * harakat are dropped, and each letter that players swap (ا/أ/إ/آ, ة/ه, ى/ي)
     * becomes the single-character wildcard, so "الشجاعيه" finds "الشجاعية".
     * Returns null when nothing but those letters is left, as it would match any area.
     */
    private function areaPattern(string $area): ?string
    {
        $area = trim(TextNormalizer::stripDiacritics($area));

        if (trim(str_replace(TextNormalizer::AMBIGUOUS_LETTERS, '', $area)) === '') {
            return null;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $area);

        return '%'.str_replace(TextNormalizer::AMBIGUOUS_LETTERS, '_', $escaped).'%';
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $date;
    }

    private function parseHour(?string $hour): ?string
    {
        if (! $hour || ! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $hour, $m)) {
            return null;
        }

        return sprintf('%02d:%s:00', $m[1], $m[2]);
    }
}
