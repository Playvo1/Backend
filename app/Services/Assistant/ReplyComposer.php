<?php

namespace App\Services\Assistant;

use App\Models\TimeSlot;
use Carbon\CarbonImmutable;

/**
 * Writes the assistant's reply in the player's language (Arabic or English). Replies are
 * built from the database result rather than generated, so the venue and price quoted
 * are always the real ones.
 */
class ReplyComposer
{
    /**
     * Quotes the slot's own time (what the player would book), not the minute they asked for:
     * "at 18:00" for a one-hour slot, "from 17:00 to 20:00" for a longer one.
     */
    public function suggestion(TimeSlot $slot, CarbonImmutable $today, bool $arabic): string
    {
        $price = rtrim(rtrim(number_format((float) $slot->hourly_price, 2, '.', ''), '0'), '.');
        $date = substr((string) $slot->slot_date, 0, 10);
        $start = substr((string) $slot->start_time, 0, 5);
        $end = substr((string) $slot->end_time, 0, 5);
        $oneHour = $this->minutesBetween($start, $end) === 60;

        if ($arabic) {
            $time = $oneHour ? "الساعة {$start}" : "من الساعة {$start} حتى {$end}";

            return "{$slot->venue->name_ar} متاح {$this->arabicDay($date, $today)} {$time} بسعر {$price} شيكل للساعة. هل تريد أن أحجزه لك؟";
        }

        $time = $oneHour ? "at {$start}" : "from {$start} to {$end}";

        return "{$slot->venue->name_en} is available {$this->englishDay($date, $today)} {$time} for {$price} ILS/hour. Want me to book it?";
    }

    public function noMatch(bool $arabic): string
    {
        return $arabic
            ? 'لا توجد ملاعب تطابق طلبك — جرّب وقتاً آخر.'
            : 'No venues match that request — try a different time.';
    }

    public function needsDetails(bool $arabic): string
    {
        return $arabic
            ? 'أخبرني بالرياضة واليوم والساعة التي تريدها، مثلاً: "كرة قدم بكرا الساعة 6 مساءً".'
            : 'Tell me the sport, day and hour you want, for example: "Football tomorrow at 6pm".';
    }

    /**
     * A slot ending at 00:00 ends at midnight, the end of its day.
     */
    private function minutesBetween(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $endMinutes = $endHour * 60 + $endMinute;

        return ($endMinutes === 0 ? 1440 : $endMinutes) - ($startHour * 60 + $startMinute);
    }

    private function englishDay(string $date, CarbonImmutable $today): string
    {
        return match ($date) {
            $today->toDateString() => 'today',
            $today->addDay()->toDateString() => 'tomorrow',
            default => "on {$date}",
        };
    }

    private function arabicDay(string $date, CarbonImmutable $today): string
    {
        return match ($date) {
            $today->toDateString() => 'اليوم',
            $today->addDay()->toDateString() => 'غداً',
            default => "بتاريخ {$date}",
        };
    }
}
