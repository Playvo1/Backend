<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\Venue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Every argument the declaration offers.
     */
    public const ARGUMENTS = ['sport', 'date', 'hour', 'city', 'area'];

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
                    // Assistant v2: optional readings the assistant checks before using them.
                    'period' => [
                        'type' => 'STRING',
                        'description' => 'A span of days instead of one date, if the player asked for one.',
                        'enum' => PeriodParser::NAMES,
                    ],
                    'intent' => [
                        'type' => 'STRING',
                        'description' => 'What the latest message is about.',
                        'enum' => IntentClassifier::INTENTS,
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

        $hour = $this->parseHour($arguments['hour'] ?? null);
        $query = $hour ? $this->availableSlots($arguments, $now ?? now()) : null;

        return $query
            ?->where('time_slots.start_time', '<=', $hour)
            ->where(fn (Builder $q) => $q
                ->where('time_slots.end_time', '>', $hour)
                ->orWhere('time_slots.end_time', self::MIDNIGHT))
            ->orderByDesc('venues.avg_rating')
            ->orderBy('time_slots.hourly_price')
            ->orderBy('time_slots.id')
            ->with('venue')
            ->first();
    }

    /**
     * Up to $limit start times (HH:mm) of free slots for the sport and day from $from on (the
     * evening by default) and before $until when given, so a reply that asks for the hour can
     * offer some.
     *
     * @return list<string>
     */
    public function freeHours(array $arguments, CarbonInterface $now, string $from = '17:00', int $limit = 3, ?string $until = null): array
    {
        if (! $this->hasOnlyStringArguments($arguments)) {
            return [];
        }

        return ($this->availableSlots($arguments, $now)?->where('time_slots.start_time', '>=', $from.':00')
            ->when($until !== null, fn (Builder $q) => $q->where('time_slots.start_time', '<', $until.':00'))
            ->orderBy('time_slots.start_time')
            ->pluck('time_slots.start_time') ?? collect())
            ->map(fn ($start) => substr((string) $start, 0, 5))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Whether any active venue lies in this area, so an area that matches none can be
     * reported instead of quietly searching somewhere else.
     */
    public function knowsArea(string $area): bool
    {
        $pattern = $this->areaPattern($area);

        return $pattern !== null && $this->whereArea(Venue::query()->where('status', 'active'), $pattern, 'venues')->exists();
    }

    /**
     * Up to $limit slots for a list of options (assistant v2): one per venue, so the player
     * sees different venues, ranked by:
     * 1. a slot that contains the hour asked for (when one was asked);
     * 2. then, by default, rating (high first), price (low first) and distance when the
     *    player shared their location; "cheapest" puts the price first, and "near me" or
     *    "nearest" the distance (venues without coordinates last);
     * 3. then the earliest day.
     * For each venue its earliest day is used, so a span of days ("this week") offers the
     * earliest good slots. When nothing contains the hour, the closest free start times (up to
     * three hours away) come back as alternatives instead.
     *
     * The same honesty rules as execute(): only available slots, from now on, in active,
     * not removed venues that offer the sport, inside the city and area when given.
     *
     * @param  array<string, mixed>  $criteria  sport, date or from/to (YYYY-MM-DD), hour, from_hour and until_hour
     *                                          (HH:mm, a part of the day), city, area, sort, near, latitude, longitude
     * @return array{exact: list<array{slot: TimeSlot, distance_km: ?float}>, alternatives: list<array{slot: TimeSlot, distance_km: ?float}>}
     */
    public function search(array $criteria, CarbonInterface $now, int $limit = 5): array
    {
        $none = ['exact' => [], 'alternatives' => []];
        $from = $this->parseDate($criteria['from'] ?? $criteria['date'] ?? null);
        $to = $this->parseDate($criteria['to'] ?? $criteria['date'] ?? null);
        $arguments = array_filter(array_intersect_key($criteria, array_flip(self::ARGUMENTS)), 'is_string');

        if ($from === null || $to === null) {
            return $none;
        }

        $query = $this->slotsBetween($arguments, max($from, $now->toDateString()), $to, $now);

        if ($query === null) {
            return $none;
        }

        $hour = $this->parseHour($criteria['hour'] ?? null);
        $fromHour = $this->parseHour($criteria['from_hour'] ?? null);
        $untilHour = $this->parseHour($criteria['until_hour'] ?? null);

        $slots = $query
            ->when($hour === null && $fromHour !== null, fn (Builder $q) => $q->where('time_slots.start_time', '>=', $fromHour))
            ->when($hour === null && $untilHour !== null, fn (Builder $q) => $q->where('time_slots.start_time', '<', $untilHour))
            ->with('venue.city')
            ->orderBy('time_slots.slot_date')
            ->orderBy('time_slots.start_time')
            ->orderBy('time_slots.id')
            // Far more than a list needs; keeps one request cheap whatever the data.
            ->limit(2000)
            ->get();

        $origin = is_numeric($criteria['latitude'] ?? null) && is_numeric($criteria['longitude'] ?? null)
            ? [(float) $criteria['latitude'], (float) $criteria['longitude']]
            : null;
        $byDistance = $origin !== null && (! empty($criteria['near']) || ($criteria['sort'] ?? null) === 'nearest');
        $options = $slots->map(fn (TimeSlot $slot) => ['slot' => $slot, 'distance_km' => $this->distanceKm($origin, $slot->venue)]);

        $exact = $hour === null ? $options : $options->filter(fn (array $option) => $this->contains($option['slot'], $hour));
        $ranked = $this->onePerVenue($exact, fn (array $a, array $b) => [$a['slot']->slot_date, (float) $a['slot']->hourly_price, $a['slot']->id]
                <=> [$b['slot']->slot_date, (float) $b['slot']->hourly_price, $b['slot']->id])
            ->sort(fn (array $a, array $b) => $this->rankKey($a, $criteria['sort'] ?? null, $byDistance) <=> $this->rankKey($b, $criteria['sort'] ?? null, $byDistance))
            ->take($limit)
            ->values()
            ->all();

        if ($ranked !== [] || $hour === null) {
            return ['exact' => $ranked, 'alternatives' => []];
        }

        $wanted = $this->minutes($hour);
        $gap = fn (array $option) => abs($this->minutes((string) $option['slot']->start_time) - $wanted);
        $alternatives = $this->onePerVenue(
            $options->filter(fn (array $option) => $gap($option) <= 180),
            fn (array $a, array $b) => [$gap($a), $a['slot']->slot_date, $a['slot']->id] <=> [$gap($b), $b['slot']->slot_date, $b['slot']->id],
        )
            ->sort(fn (array $a, array $b) => [$gap($a), ...$this->rankKey($a, $criteria['sort'] ?? null, $byDistance)]
                <=> [$gap($b), ...$this->rankKey($b, $criteria['sort'] ?? null, $byDistance)])
            ->take($limit)
            ->values()
            ->all();

        return ['exact' => [], 'alternatives' => $alternatives];
    }

    /**
     * The sports at least one active venue offers, in SPORT order: what can be booked now.
     *
     * @return SupportCollection<int, Sport>
     */
    public function offeredSports(): SupportCollection
    {
        return Sport::query()
            ->whereIn('id', DB::table('venue_sports')
                ->join('venues', 'venues.id', '=', 'venue_sports.venue_id')
                ->where('venues.status', 'active')
                ->whereNull('venues.deleted_at')
                ->select('venue_sports.sport_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Slots from $from to $to (YYYY-MM-DD) in active, not removed venues that offer the
     * slot's sport, for the sport, city and area in $arguments (each optional here), from
     * now on; only available ones unless $anyStatus (working hours count booked slots too).
     * Null when a named sport, city or area matches nothing.
     */
    public function upcomingSlots(array $arguments, string $from, string $to, CarbonInterface $now, bool $anyStatus = false): ?Builder
    {
        return $this->slotsBetween($arguments, max($from, $now->toDateString()), $to, $now, sportRequired: false, anyStatus: $anyStatus);
    }

    /**
     * Rank of one option; smaller comes first.
     *
     * @param  array{slot: TimeSlot, distance_km: ?float}  $option
     * @return list<mixed>
     */
    private function rankKey(array $option, ?string $sort, bool $byDistance): array
    {
        $slot = $option['slot'];
        $rating = -(float) ($slot->venue->avg_rating ?? 0);
        $price = (float) $slot->hourly_price;
        // Without coordinates a venue can't be placed, so it comes after every placed one.
        $distance = $option['distance_km'] ?? PHP_INT_MAX;

        $keys = match (true) {
            $byDistance => [$distance, $rating, $price],
            $sort === 'cheapest' => [$price, $rating, $distance],
            default => [$rating, $price, $distance],
        };

        return [...$keys, (string) $slot->slot_date, $slot->id];
    }

    /**
     * @param  SupportCollection<int, array{slot: TimeSlot, distance_km: ?float}>  $options
     * @return SupportCollection<int, array{slot: TimeSlot, distance_km: ?float}>
     */
    private function onePerVenue(SupportCollection $options, callable $better): SupportCollection
    {
        return $options->groupBy(fn (array $option) => $option['slot']->venue_id)
            ->map(fn (SupportCollection $venueOptions) => $venueOptions->sort($better)->first())
            ->values();
    }

    /**
     * Whether the slot covers the hour (HH:mm:ss); a slot ending at 00:00 runs to midnight.
     */
    private function contains(TimeSlot $slot, string $hour): bool
    {
        $start = substr((string) $slot->start_time, 0, 8);
        $end = substr((string) $slot->end_time, 0, 8);

        return $start <= $hour && ($end > $hour || $end === self::MIDNIGHT);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', array_slice(explode(':', $time), 0, 2) + [0, 0]);

        return $hour * 60 + $minute;
    }

    /**
     * Great-circle (haversine) distance in kilometres, computed in PHP so it is the same on
     * every database; null when either side has no coordinates.
     *
     * @param  array{0: float, 1: float}|null  $origin
     */
    private function distanceKm(?array $origin, ?Venue $venue): ?float
    {
        if ($origin === null || $venue === null || ! is_numeric($venue->latitude) || ! is_numeric($venue->longitude)) {
            return null;
        }

        [$lat1, $lon1] = array_map('deg2rad', $origin);
        [$lat2, $lon2] = [deg2rad((float) $venue->latitude), deg2rad((float) $venue->longitude)];
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lon2 - $lon1) / 2) ** 2;

        return round(2 * 6371.0 * asin(min(1.0, sqrt($a))), 1);
    }

    /**
     * Available slots for the sport and date in active venues that offer the sport, within
     * the city and area when given; null when the arguments can't match anything.
     */
    private function availableSlots(array $arguments, CarbonInterface $now): ?Builder
    {
        $date = $this->parseDate($arguments['date'] ?? null);

        if (! $date || $date < $now->toDateString()) {
            return null;
        }

        return $this->slotsBetween($arguments, $date, $date, $now);
    }

    /**
     * See upcomingSlots(); here the sport is required unless $sportRequired is false.
     */
    private function slotsBetween(array $arguments, string $from, string $to, CarbonInterface $now, bool $sportRequired = true, bool $anyStatus = false): ?Builder
    {
        $today = $now->toDateString();
        $sportName = $this->filledArgument($arguments, 'sport');
        $sport = $this->findSport($sportName);

        if ((! $sport && ($sportRequired || $sportName !== null)) || $to < $from || $to < $today) {
            return null;
        }

        $query = TimeSlot::query()
            ->select('time_slots.*')
            ->join('venues', 'venues.id', '=', 'time_slots.venue_id')
            // Like GET /venues: the venue must offer the sport, not only have a slot for it.
            ->join('venue_sports', fn (JoinClause $join) => $join
                ->on('venue_sports.venue_id', '=', 'venues.id')
                ->on('venue_sports.sport_id', '=', 'time_slots.sport_id'))
            ->when($sport !== null, fn (Builder $q) => $q->where('time_slots.sport_id', $sport->id))
            ->when($from === $to, fn (Builder $q) => $q->whereDate('time_slots.slot_date', $from))
            ->when($from !== $to, fn (Builder $q) => $q->whereDate('time_slots.slot_date', '>=', $from)->whereDate('time_slots.slot_date', '<=', $to))
            ->when(! $anyStatus, fn (Builder $q) => $q->where('time_slots.status', 'available'))
            ->where('venues.status', 'active')
            // The join bypasses Venue's SoftDeletes scope, so removed venues are excluded here.
            ->whereNull('venues.deleted_at')
            // Today, only what hasn't started yet (in the players' local time).
            ->when($from <= $today, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereDate('time_slots.slot_date', '>', $today)
                ->orWhere('time_slots.start_time', '>', $now->format('H:i:s'))));

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

            $this->whereArea($query, $pattern, 'venues');
        }

        return $query;
    }

    private function whereArea(Builder $query, string $pattern, string $table): Builder
    {
        return $query->where(function (Builder $q) use ($pattern, $table) {
            foreach (['area_ar', 'area_en', 'address_ar', 'address_en'] as $column) {
                $q->orWhereRaw("{$table}.{$column} LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    public function findSport(?string $name): ?Sport
    {
        return $this->matchingByName(Sport::query(), $name)->first();
    }

    /**
     * The first seeded city with this name (the search itself uses every city sharing it).
     */
    public function findCity(?string $name): ?City
    {
        return $this->matchingByName(City::query(), $name)->first();
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
     * harakat are dropped, and each letter that players swap (ا/أ/إ/آ, ة/ه, ى/ي), each
     * hyphen and each space becomes the single-character wildcard, so "الشجاعيه" finds
     * "الشجاعية" and "Al Jalaa" finds "Al-Jalaa".
     * Returns null when only those characters are left, as it would match any area.
     */
    private function areaPattern(string $area): ?string
    {
        $area = preg_replace('/\s+/u', ' ', trim(TextNormalizer::stripDiacritics($area))) ?? '';
        $interchangeable = [...TextNormalizer::AMBIGUOUS_LETTERS, '-', ' '];

        if (str_replace($interchangeable, '', $area) === '') {
            return null;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $area);

        return '%'.str_replace($interchangeable, '_', $escaped).'%';
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
