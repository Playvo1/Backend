<?php

namespace App\Services\Assistant;

use App\Models\TimeSlot;
use Carbon\CarbonImmutable;

/**
 * What the assistant knows besides a search: the reviewed policy and FAQ texts
 * (resources/assistant/faq.json) and facts computed live from the time slots, so a price or
 * an opening hour is never made up.
 */
class AssistantKnowledge
{
    public const FAQ = 'assistant/faq.json';

    /**
     * How far ahead prices and working hours are read.
     */
    public const PRICE_DAYS = 30;

    public const HOURS_DAYS = 14;

    /**
     * A slot that starts before this hour belongs to the night before (after midnight).
     */
    private const NIGHT_ENDS_AT = '05:00:00';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $faq = null;

    public function __construct(private readonly VenueSearchTool $tool) {}

    /**
     * @return array<string, mixed>
     */
    public function faq(): array
    {
        return $this->faq ??= json_decode(file_get_contents(resource_path(self::FAQ)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A FAQ answer in the reply's language, with its placeholders filled in.
     */
    public function answer(string $key, bool $arabic): string
    {
        $faq = $this->faq();
        $text = $faq['answers'][$key][$arabic ? 'ar' : 'en'] ?? $faq['answers']['help'][$arabic ? 'ar' : 'en'];

        return strtr($text, ['{hold_minutes}' => (string) $faq['meta']['hold_minutes']]);
    }

    /**
     * The lowest and highest hourly price of the available slots in the next PRICE_DAYS
     * days, for the sport, city and area when given, with the cheapest slot.
     *
     * @param  array<string, string>  $arguments  sport, city, area (each optional)
     * @return array{min: float, max: float, cheapest: TimeSlot}|null null when no slot is published
     */
    public function prices(array $arguments, CarbonImmutable $now): ?array
    {
        $query = $this->tool->upcomingSlots($arguments, $now->toDateString(), $now->addDays(self::PRICE_DAYS)->toDateString(), $now);
        $cheapest = $query?->clone()->with('venue')->orderBy('time_slots.hourly_price')->orderBy('time_slots.slot_date')->orderBy('time_slots.id')->first();

        if ($cheapest === null) {
            return null;
        }

        return [
            'min' => (float) $query->clone()->min('time_slots.hourly_price'),
            'max' => (float) $query->clone()->max('time_slots.hourly_price'),
            'cheapest' => $cheapest,
        ];
    }

    /**
     * When the venues run, read from every slot of the next HOURS_DAYS days (booked ones
     * too, as they are part of the opening hours): the earliest start and the latest end. A
     * slot after midnight (00:00-05:00) extends the evening before, so "until 02:00" is
     * reported as after midnight.
     *
     * @param  array<string, string>  $arguments  sport, city, area (each optional)
     * @return array{opens: string, closes: string, after_midnight: bool}|null HH:mm; null when no slot is published
     */
    public function workingHours(array $arguments, CarbonImmutable $now): ?array
    {
        $slots = $this->tool->upcomingSlots($arguments, $now->toDateString(), $now->addDays(self::HOURS_DAYS)->toDateString(), $now, anyStatus: true)
            ?->get(['time_slots.start_time', 'time_slots.end_time']);

        if ($slots === null || $slots->isEmpty()) {
            return null;
        }

        $time = fn (mixed $value) => substr((string) $value, 0, 8);
        $daytime = $slots->filter(fn (TimeSlot $slot) => $time($slot->start_time) >= self::NIGHT_ENDS_AT);
        $night = $slots->filter(fn (TimeSlot $slot) => $time($slot->start_time) < self::NIGHT_ENDS_AT);
        $opens = ($daytime->isNotEmpty() ? $daytime : $slots)->min(fn (TimeSlot $slot) => $time($slot->start_time));

        if ($night->isNotEmpty()) {
            return ['opens' => substr($opens, 0, 5), 'closes' => substr($night->max(fn (TimeSlot $slot) => $time($slot->end_time)), 0, 5), 'after_midnight' => true];
        }

        // 00:00 as an end time is midnight, the latest an evening slot can end.
        $closes = $daytime->max(fn (TimeSlot $slot) => $time($slot->end_time) === '00:00:00' ? '24:00:00' : $time($slot->end_time));

        return ['opens' => substr($opens, 0, 5), 'closes' => $closes === '24:00:00' ? '00:00' : substr($closes, 0, 5), 'after_midnight' => false];
    }
}
