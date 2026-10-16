<?php

namespace App\Services\Assistant;

/**
 * What the assistant has understood so far in one conversation, so a follow-up such as
 * "8" or "لا خليها بعد بكرا" is read against the earlier turns. It is stored as JSON on
 * ASSISTANT_CONVERSATION.state and rebuilt for every turn; the evaluation keeps it in
 * memory between the turns of a multi-turn case.
 *
 * - sport: SPORT.name_en; date: YYYY-MM-DD, or period: a date range (see PeriodParser);
 * - hour: HH:mm, or part_of_day when only "the morning" was said;
 * - city (CITY.name_en) and area; location: how to search when no place was named
 *   (near, all, area); latitude/longitude: the shared location, rounded to about 100 m;
 * - sort: cheapest, best_rated or nearest; mode: list when the player wants options
 *   (a span of days, "the cheapest", "near me", all venues) rather than one hour;
 * - asked: the detail the last reply asked for;
 * - last_slot_id: the slot suggested last, for "yes, book it"; language: ar or en.
 */
final class ConversationState
{
    /**
     * The details of a booking search, in the order they are merged.
     */
    public const SEARCH_FIELDS = ['sport', 'date', 'period', 'hour', 'part_of_day', 'city', 'area', 'location', 'latitude', 'longitude', 'sort', 'mode'];

    private const FIELDS = [...self::SEARCH_FIELDS, 'asked', 'last_slot_id', 'language'];

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Anything that is not one of the known fields, or is empty, is dropped, so a stored state
     * written by an older version (or edited by hand) can never break a turn.
     *
     * @param  array<string, mixed>|null  $values
     */
    public static function fromArray(?array $values): self
    {
        $values = array_intersect_key($values ?? [], array_flip(self::FIELDS));

        return new self(array_filter($values, fn (mixed $value) => $value !== null && $value !== '' && $value !== []));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function has(string $field): bool
    {
        return isset($this->values[$field]);
    }

    /**
     * A copy with $changes applied; a null value removes the field.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray([...$this->values, ...$changes]);
    }

    /**
     * Whether a booking search is under way (anything but the language and the last reply).
     */
    public function inProgress(): bool
    {
        return array_intersect_key($this->values, array_flip(self::SEARCH_FIELDS)) !== [];
    }

    /**
     * Forgets the search but keeps the language, for "new search" or a new topic.
     */
    public function reset(): self
    {
        return self::fromArray(['language' => $this->get('language')]);
    }

    public function hasLocation(): bool
    {
        return is_numeric($this->get('latitude')) && is_numeric($this->get('longitude'));
    }

    /**
     * Merges what one new message said into what was understood before. A detail the
     * message gives replaces the old one; one it doesn't give is kept, so "8" after "football
     * tomorrow" is tomorrow at 20:00. A day replaces a date range and the other way round, a
     * city drops the old area, and a message that names both a sport and a day starts a new
     * search, so nothing from the old request (hour, place, sort) leaks into it.
     *
     * @param  array<string, mixed>  $said  sport, date, period, hour, part_of_day, city, area, location, sort
     */
    public function merge(array $said): self
    {
        $said = array_filter(array_intersect_key($said, array_flip(self::SEARCH_FIELDS)), fn (mixed $value) => $value !== null && $value !== '');
        $newTopic = isset($said['sport']) && (isset($said['date']) || isset($said['period']))
            && ($said['sport'] !== $this->get('sport') || ($said['date'] ?? null) !== $this->get('date') || ($said['period'] ?? null) !== $this->get('period'));
        $base = $newTopic ? $this->reset()->with(['latitude' => $this->get('latitude'), 'longitude' => $this->get('longitude')]) : $this;
        $changes = $said;

        if (isset($said['date'])) {
            $changes['period'] = null;
        }

        if (isset($said['period'])) {
            $changes['date'] = null;
        }

        if (isset($said['hour'])) {
            $changes['part_of_day'] = null;
        }

        if (isset($said['part_of_day']) && ! isset($said['hour'])) {
            $changes['hour'] = null;
        }

        if (isset($said['city']) && ! isset($said['area']) && $said['city'] !== $this->get('city')) {
            $changes['area'] = null;
        }

        // A named place replaces "near me" or "all venues".
        if ((isset($said['city']) || isset($said['area'])) && ! isset($said['location'])) {
            $changes['location'] = null;
        }

        return $base->with([...$changes, 'last_slot_id' => null]);
    }
}
