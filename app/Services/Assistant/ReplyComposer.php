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
    public function suggestion(TimeSlot $slot, string $date, string $hour, CarbonImmutable $today, bool $arabic): string
    {
        $price = rtrim(rtrim(number_format($slot->hourly_price, 2, '.', ''), '0'), '.');

        if ($arabic) {
            return "{$slot->venue->name_ar} متاح {$this->arabicDay($date, $today)} الساعة {$hour} بسعر {$price} شيكل للساعة. هل تريد أن أحجزه لك؟";
        }

        return "{$slot->venue->name_en} is available {$this->englishDay($date, $today)} at {$hour} for {$price} ILS/hour. Want me to book it?";
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
