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
     * How a list of options is introduced, by what the player asked for.
     */
    private const SORT_LABELS = [
        'default' => ['أفضل الخيارات المتاحة', 'Best available options'],
        'best_rated' => ['الأعلى تقييماً', 'Best rated'],
        'cheapest' => ['الأرخص', 'Cheapest'],
        'nearest' => ['الأقرب إليك', 'Closest to you'],
    ];

    /**
     * PeriodParser spans, as they are said in a reply.
     */
    private const PERIOD_LABELS = [
        'this_week' => ['هذا الأسبوع', 'this week'],
        'next_week' => ['الأسبوع القادم', 'next week'],
        'this_month' => ['هذا الشهر', 'this month'],
        'next_month' => ['الشهر القادم', 'next month'],
        'weekend' => ['في الويكند', 'this weekend'],
        'next_weekend' => ['في الويكند القادم', 'next weekend'],
        'upcoming' => ['في الأيام القادمة', 'in the coming days'],
        'any' => ['في الأسبوعين القادمين', 'in the next two weeks'],
        'days' => ['في الأيام التي ذكرتها', 'on the days you named'],
    ];

    /**
     * Quick-reply buttons ([Arabic, English]); a city button is labelled with the city's name.
     */
    private const OPTION_LABELS = [
        'use_location' => ['استخدم موقعي', 'Use my location'],
        'pick_area' => ['اختر منطقة', 'Pick an area'],
        'all_venues' => ['كل الملاعب', 'All venues'],
        'cheapest' => ['الأرخص', 'Cheapest'],
        'best_rated' => ['الأعلى تقييماً', 'Best rated'],
        'new_search' => ['بحث جديد', 'New search'],
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
     * Several options (assistant v2), e.g. for "this week" or "the cheapest". One option reads
     * like suggestion(); several are listed with their day, time and price, introduced by
     * what the player asked for (the cheapest, the nearest, a span of days).
     *
     * @param  list<array{slot: TimeSlot, distance_km: ?float}>  $options
     * @param  ?string  $sort  cheapest, best_rated or nearest
     * @param  ?string  $period  a PeriodParser name, when a span of days was searched
     */
    public function options(array $options, CarbonImmutable $today, bool $arabic, ?string $sort = null, ?string $period = null): string
    {
        if (count($options) === 1) {
            $reply = $this->suggestion($options[0]['slot'], $today, $arabic);
            $distance = $options[0]['distance_km'];

            return $distance === null ? $reply : $reply.($arabic ? " (يبعد عنك {$this->number($distance)} كم)" : " ({$this->number($distance)} km away)");
        }

        $items = array_map(function (array $option) use ($today, $arabic) {
            $slot = $option['slot'];
            $start = substr((string) $slot->start_time, 0, 5);
            $price = $this->number((float) $slot->hourly_price);
            $day = $this->shortDay(substr((string) $slot->slot_date, 0, 10), $today, $arabic);
            $distance = $option['distance_km'] === null ? '' : ($arabic ? "، {$this->number($option['distance_km'])} كم" : ", {$this->number($option['distance_km'])} km");

            return $arabic
                ? "{$slot->venue->name_ar} {$day} الساعة {$start} ({$price} شيكل{$distance})"
                : "{$slot->venue->name_en} {$day} at {$start} ({$price} ILS{$distance})";
        }, $options);

        $span = $period === null ? '' : ' '.self::PERIOD_LABELS[$period][$arabic ? 0 : 1];
        [$arabicIntro, $englishIntro] = self::SORT_LABELS[$sort ?? 'default'] ?? self::SORT_LABELS['default'];

        return $arabic
            ? "{$arabicIntro}{$span}: ".implode('، ', $items).'. أيها تريد؟'
            : "{$englishIntro}{$span}: ".implode('; ', $items).'. Which one would you like?';
    }

    /**
     * Nothing is free at the hour asked for, but some slots start close to it: said plainly,
     * with each one's day, time, venue and price.
     *
     * @param  list<array{slot: TimeSlot, distance_km: ?float}>  $alternatives
     */
    public function closestOptions(string $hour, array $alternatives, CarbonImmutable $today, bool $arabic): string
    {
        $items = array_map(function (array $option) use ($today, $arabic) {
            $slot = $option['slot'];
            $start = substr((string) $slot->start_time, 0, 5);
            $price = $this->number((float) $slot->hourly_price);
            $day = $this->shortDay(substr((string) $slot->slot_date, 0, 10), $today, $arabic);

            return $arabic
                ? "{$slot->venue->name_ar} {$day} الساعة {$start} ({$price} شيكل)"
                : "{$slot->venue->name_en} {$day} at {$start} ({$price} ILS)";
        }, $alternatives);

        return $arabic
            ? "لا يوجد موعد متاح الساعة {$hour} بالضبط. أقرب المواعيد: ".implode('، ', $items).'. أيها تريد؟'
            : "Nothing is free at exactly {$hour}. Closest times: ".implode('; ', $items).'. Which one would you like?';
    }

    /**
     * "بعد العصر" with nothing free that afternoon: said, with the hours free that day; or that
     * the day has nothing at all. $named is whether the player named the part of the day.
     *
     * @param  list<string>  $dayHours
     */
    public function noFreeHours(string $partOfDay, array $dayHours, bool $named, bool $arabic): string
    {
        if ($dayHours === []) {
            return $arabic ? 'لا توجد مواعيد متاحة في ذلك اليوم — جرّب يوماً آخر.' : 'Nothing is free that day — try another day.';
        }

        $part = ['morning' => ['صباحاً', 'that morning'], 'afternoon' => ['بعد الظهر', 'that afternoon'], 'evening' => ['مساءً', 'that evening']][$partOfDay] ?? ['مساءً', 'that evening'];

        if ($arabic) {
            $lead = $named ? "لا توجد مواعيد متاحة {$part[0]}. " : '';

            return "{$lead}أي ساعة؟ المتاح في ذلك اليوم: ".$this->arabicList(array_slice($dayHours, 0, 3)).'.';
        }

        $lead = $named ? "Nothing is free {$part[1]}. " : '';

        return "{$lead}What time would you like to play? Free that day: ".$this->englishList(array_slice($dayHours, 0, 3)).'.';
    }

    /**
     * "Near me" without a shared location.
     */
    public function askLocation(bool $arabic): string
    {
        return $arabic
            ? 'شارك موقعك لأجد لك أقرب الملاعب، أو اختر منطقة، أو اعرض كل الملاعب.'
            : 'Share your location so I can find the closest venues, or pick an area, or see all venues.';
    }

    /**
     * @param  list<string>  $cities  in the reply's language
     */
    public function pickCity(array $cities, bool $arabic): string
    {
        return $arabic
            ? 'اختر المدينة: '.$this->arabicList($cities).'.'
            : 'Pick a city: '.$this->englishList($cities).'.';
    }

    /**
     * A sport that is in the app's words but no venue offers yet.
     *
     * @param  list<string>  $offered  sport names in the reply's language
     */
    public function sportNotOffered(string $sport, array $offered, bool $arabic): string
    {
        if ($offered === []) {
            return $arabic ? "لا تتوفر ملاعب {$sport} بعد — قريباً." : "{$sport} isn't available yet — coming soon.";
        }

        return $arabic
            ? "لا تتوفر ملاعب {$sport} حالياً — المتاح الآن: {$this->arabicList($offered)}، ورياضات أخرى قريباً."
            : "{$sport} isn't available yet — you can book {$this->englishList($offered)} now, with more sports coming soon.";
    }

    /**
     * The price range of the coming slots, computed from the database.
     *
     * @param  array{min: float, max: float, cheapest: TimeSlot}|null  $prices
     */
    public function prices(?array $prices, ?string $sport, ?string $place, bool $arabic): string
    {
        $where = $place === null ? '' : ($arabic ? " في {$place}" : " in {$place}");

        if ($prices === null) {
            return $arabic
                ? "لا توجد مواعيد متاحة{$where} في الأيام القادمة لأعرض أسعارها."
                : "There are no free slots{$where} in the coming days to quote a price for.";
        }

        [$min, $max] = [$this->number($prices['min']), $this->number($prices['max'])];
        $venue = $prices['cheapest']->venue;
        $what = $sport === null ? ($arabic ? 'الملاعب' : 'Venue') : ($arabic ? "ملاعب {$sport}" : $sport);

        if ($arabic) {
            $range = $min === $max ? "{$min} شيكل للساعة" : "من {$min} إلى {$max} شيكل للساعة";

            return "أسعار {$what}{$where} في الأيام القادمة {$range}، والأرخص في {$venue->name_ar}. أسعار كل موعد تظهر عند الحجز.";
        }

        $range = $min === $max ? "{$min} ILS/hour" : "from {$min} to {$max} ILS/hour";

        return "{$what} prices{$where} over the coming days run {$range}; the cheapest is at {$venue->name_en}. Each slot shows its own price when you book.";
    }

    /**
     * When the venues run, from the published slots.
     *
     * @param  array{opens: string, closes: string, after_midnight: bool}|null  $hours
     */
    public function workingHours(?array $hours, ?string $place, bool $arabic): string
    {
        $where = $place === null ? '' : ($arabic ? " في {$place}" : " in {$place}");

        if ($hours === null) {
            return $arabic
                ? "لم تنشر الملاعب{$where} مواعيد للأيام القادمة بعد. أنا متاح للرد في أي وقت."
                : "The venues{$where} haven't published times for the coming days yet. I'm here to help any time.";
        }

        if ($arabic) {
            $closes = match (true) {
                $hours['after_midnight'] => "{$hours['closes']} بعد منتصف الليل",
                $hours['closes'] === '00:00' => 'منتصف الليل',
                default => $hours['closes'],
            };

            return "حسب المواعيد المنشورة للأيام القادمة، تعمل الملاعب{$where} من الساعة {$hours['opens']} حتى {$closes}، وتختلف المواعيد من ملعب لآخر. يمكنك سؤالي والحجز من التطبيق في أي وقت.";
        }

        $closes = match (true) {
            $hours['after_midnight'] => "{$hours['closes']} after midnight",
            $hours['closes'] === '00:00' => 'midnight',
            default => $hours['closes'],
        };

        return "Based on the published slots for the coming days, venues{$where} run from {$hours['opens']} to {$closes}; times vary by venue. You can ask me and book in the app any time.";
    }

    /**
     * The label of a quick-reply button.
     */
    public function optionLabel(string $id, bool $arabic): string
    {
        return self::OPTION_LABELS[$id][$arabic ? 0 : 1] ?? $id;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * "اليوم", "غداً" or "الأحد 4 أكتوبر"; "today", "tomorrow" or "Sun 4 Oct".
     */
    private function shortDay(string $date, CarbonImmutable $today, bool $arabic): string
    {
        $today = CarbonImmutable::parse($today->toDateString(), config('app.local_timezone'));
        $day = CarbonImmutable::parse($date, $today->getTimezone());

        return match ($date) {
            $today->toDateString() => $arabic ? 'اليوم' : 'today',
            $today->addDay()->toDateString() => $arabic ? 'غداً' : 'tomorrow',
            default => $arabic
                ? self::ARABIC_WEEKDAYS[$day->dayOfWeek].' '.$day->day.' '.self::ARABIC_MONTHS[$day->month]
                : $day->format('D j M'),
        };
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
     * calendar date: "tomorrow night after midnight (Mon 5 Oct)". Any other day is written as
     * in the lists, "on Mon 5 Oct" / "يوم الإثنين 5 أكتوبر", never as YYYY-MM-DD.
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
            default => "on {$calendar}",
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
            default => "يوم {$calendar}",
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
