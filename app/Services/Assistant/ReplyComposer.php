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
    private const ARABIC_WEEKDAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    private const ARABIC_MONTHS = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /**
     * A slot starting before this hour is "after midnight" of the evening before.
     */
    private const NIGHT_ENDS_AT = 5;

    /**
     * How the free hours offered with "which hour?" are introduced, by the part of the day
     * the player asked for.
     */
    private const FREE_HOURS_LABELS = [
        'morning' => ['المتاح صباحاً', 'Free that morning'],
        'afternoon' => ['المتاح بعد الظهر', 'Free that afternoon'],
        'evening' => ['المتاح مساءً', 'Free that evening'],
    ];

    /**
     * Quotes the slot's own time (what the player would book), not the minute they asked for:
     * "at 18:00" for a one-hour slot, "from 17:00 to 20:00" for a longer one. The Arabic reply
     * is about the slot ("يتوفر موعد في ..."), so it reads right whatever the venue is called
     * (ملعب، صالة، ساحة...). $today is the players' local date.
     */
    public function suggestion(TimeSlot $slot, CarbonImmutable $today, bool $arabic): string
    {
        $price = rtrim(rtrim(number_format((float) $slot->hourly_price, 2, '.', ''), '0'), '.');
        $date = substr((string) $slot->slot_date, 0, 10);
        $start = substr((string) $slot->start_time, 0, 5);
        $end = substr((string) $slot->end_time, 0, 5);
        $oneHour = $this->minutesBetween($start, $end) === 60;
        $afterMidnight = (int) substr($start, 0, 2) < self::NIGHT_ENDS_AT;
        // Slot dates are local calendar dates, so both sides are compared in the local timezone.
        $today = CarbonImmutable::parse($today->toDateString(), config('app.local_timezone'));

        if ($arabic) {
            $time = $oneHour ? "الساعة {$start}" : "من الساعة {$start} حتى {$end}";

            return "يتوفر موعد في {$slot->venue->name_ar} {$this->arabicDay($date, $today, $afterMidnight)} {$time} بسعر {$price} شيكل للساعة. هل تريد أن أحجزه لك؟";
        }

        $time = $oneHour ? "at {$start}" : "from {$start} to {$end}";

        return "{$slot->venue->name_en} is available {$this->englishDay($date, $today, $afterMidnight)} {$time} for {$price} ILS/hour. Want me to book it?";
    }

    public function noMatch(bool $arabic): string
    {
        return $arabic
            ? 'لا توجد ملاعب تطابق طلبك — جرّب وقتاً آخر.'
            : 'No venues match that request — try a different time.';
    }

    /**
     * Asks only for what is missing (sport, date, hour), e.g. "أي ساعة؟". When only the hour
     * is missing, up to three free hours are offered, from the part of the day the player
     * asked for (the evening unless they said the morning or the afternoon).
     *
     * @param  list<string>  $missing
     * @param  list<string>  $freeHours
     * @param  string  $partOfDay  morning, afternoon or evening
     */
    public function needsDetails(array $missing, bool $arabic, array $freeHours = [], string $partOfDay = 'evening'): string
    {
        $missing = array_values(array_intersect(['sport', 'date', 'hour'], $missing));

        if (count($missing) === 1) {
            $question = $arabic
                ? ['sport' => 'أي رياضة؟', 'date' => 'أي يوم؟', 'hour' => 'أي ساعة؟'][$missing[0]]
                : ['sport' => 'Which sport would you like to play?', 'date' => 'Which day would you like to play?', 'hour' => 'What time would you like to play?'][$missing[0]];

            if ($missing === ['hour'] && $freeHours !== []) {
                [$arabicLabel, $englishLabel] = self::FREE_HOURS_LABELS[$partOfDay] ?? self::FREE_HOURS_LABELS['evening'];

                $question .= $arabic
                    ? " {$arabicLabel}: ".$this->arabicList($freeHours).'.'
                    : " {$englishLabel}: ".$this->englishList($freeHours).'.';
            }

            return $question;
        }

        if ($arabic) {
            $names = ['sport' => 'الرياضة', 'date' => 'اليوم', 'hour' => 'الساعة'];

            // No relative clause after the list, as it would have to agree with each noun.
            return 'أخبرني ب'.implode(' و', array_map(fn (string $field) => $names[$field], $missing ?: ['sport', 'date', 'hour']))
                .'، مثلاً: "كرة قدم بكرا الساعة 6 مساءً".';
        }

        $names = ['sport' => 'sport', 'date' => 'day', 'hour' => 'hour'];

        return 'Tell me the '.$this->englishList(array_map(fn (string $field) => $names[$field], $missing ?: ['sport', 'date', 'hour']))
            .' you want, for example: "Football tomorrow at 6pm".';
    }

    /**
     * A city (or place) the platform has no venues in yet, with the cities it does cover.
     *
     * @param  list<string>  $cities  in the reply's language
     */
    public function notCovered(string $place, array $cities, bool $arabic): string
    {
        return $arabic
            ? "لا نغطي {$place} بعد — نغطي حالياً: {$this->arabicList($cities)}."
            : "We don't cover {$place} yet — we currently cover: {$this->englishList($cities)}.";
    }

    /**
     * An area no venue is in: said plainly rather than suggesting another area.
     */
    public function unknownArea(string $area, bool $arabic): string
    {
        return $arabic
            ? "لم أجد ملعباً في {$area} — جرّب منطقة أخرى أو اطلب بدون تحديد المنطقة."
            : "I couldn't find a venue in {$area} — try another area, or leave the area out.";
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

    /**
     * A slot after midnight belongs to the evening before, so it is said that way, with the
     * calendar date: "tomorrow night after midnight (Mon 5 Oct)".
     */
    private function englishDay(string $date, CarbonImmutable $today, bool $afterMidnight): string
    {
        $day = CarbonImmutable::parse($date, $today->getTimezone());
        $calendar = $day->format('D j M');

        if ($afterMidnight && $day->gt($today)) {
            return match ($date) {
                $today->addDay()->toDateString() => "tonight after midnight ({$calendar})",
                $today->addDays(2)->toDateString() => "tomorrow night after midnight ({$calendar})",
                default => "after midnight ({$calendar})",
            };
        }

        return match ($date) {
            $today->toDateString() => 'today',
            $today->addDay()->toDateString() => 'tomorrow',
            default => "on {$date}",
        };
    }

    private function arabicDay(string $date, CarbonImmutable $today, bool $afterMidnight): string
    {
        $day = CarbonImmutable::parse($date, $today->getTimezone());
        $calendar = self::ARABIC_WEEKDAYS[$day->dayOfWeek].' '.$day->day.' '.self::ARABIC_MONTHS[$day->month];

        if ($afterMidnight && $day->gt($today)) {
            return match ($date) {
                $today->addDay()->toDateString() => "الليلة بعد منتصف الليل ({$calendar})",
                $today->addDays(2)->toDateString() => "بكرا بالليل بعد منتصف الليل ({$calendar})",
                default => "بعد منتصف الليل ({$calendar})",
            };
        }

        return match ($date) {
            $today->toDateString() => 'اليوم',
            $today->addDay()->toDateString() => 'غداً',
            default => "بتاريخ {$date}",
        };
    }

    /**
     * @param  list<string>  $items
     */
    private function englishList(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /**
     * @param  list<string>  $items
     */
    private function arabicList(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode('، ', array_slice($items, 0, -1)).' و'.end($items);
    }
}
