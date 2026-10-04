<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single function Gemini may call for the booking assistant (US-4.5).
 *
 * Gemini only turns the player's sentence into these arguments; the venue is
 * always picked from real, available time slots here, never invented by the
 * model. Sport and city are offered as enums of the seeded names so the model
 * can only answer with values that exist in the SPORT and CITY tables.
 */
class VenueSearchTool
{
    public const NAME = 'search_available_venues';

    private const ARGUMENTS = ['sport', 'date', 'hour', 'city', 'area'];

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
            ->where('time_slots.sport_id', $sport->id)
            ->whereDate('time_slots.slot_date', $date)
            ->where('time_slots.start_time', '<=', $hour)
            ->where('time_slots.end_time', '>', $hour)
            ->where('time_slots.status', 'available')
            ->where('venues.status', 'active')
            ->when($date === $today, fn (Builder $q) => $q->where('time_slots.start_time', '>', $now->format('H:i:s')));

        if (! empty($arguments['city'])) {
            $city = $this->findByName(City::query(), $arguments['city']);

            if (! $city) {
                return null;
            }

            $query->where('venues.city_id', $city->id);
        }

        if (! empty($arguments['area'])) {
            // "!" is used as the LIKE escape character because it means the same on MySQL and SQLite.
            $area = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $arguments['area']).'%';

            $query->where(function (Builder $q) use ($area) {
                foreach (['area_ar', 'area_en', 'address_ar', 'address_en'] as $column) {
                    $q->orWhereRaw("venues.{$column} LIKE ? ESCAPE '!'", [$area]);
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
        return $this->findByName(Sport::query(), $name);
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

    private function findByName(Builder $query, ?string $name)
    {
        if (! $name) {
            return null;
        }

        return $query->where('name_ar', $name)
            ->orWhereRaw('LOWER(name_en) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $date;
    }

    private function parseHour(?string $hour): ?string
    {
        if (! $hour || ! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hour, $m)) {
            return null;
        }

        return sprintf('%02d:%s:00', $m[1], $m[2]);
    }
}
