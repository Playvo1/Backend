<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Server-side, rule-based fallback for the booking assistant (US-4.5), used when Gemini
 * is not configured, fails or times out, and to fill what Gemini left out. It understands
 * short Arabic and English requests: sport names from the SPORT table plus common
 * synonyms, today/tomorrow/weekdays (اليوم، بكرا، بعد بكرا، الجمعة...), explicit dates,
 * hours such as "8", "6pm", "6.30pm", "18:30", "6 المسا", "الساعة 6 مساءً", "6 ونص",
 * "6 الا ربع" or "12 بالليل", and city/area names from the CITY and VENUE tables.
 *
 * A date or hour is only returned when the text gives one: the assistant asks for the
 * missing detail instead of guessing.
 */
class RuleBasedQueryParser implements QueryParser
{
    /**
     * Extra ways players name a sport, keyed by SPORT.name_en (lower case), written in
     * normalised form (ة as ه, no hamza on alef).
     */
    private const SPORT_ALIASES = [
        'football' => ['soccer', 'futsal', 'كوره', 'كوره قدم', 'كره قدم', 'فوتبول', 'فطبول'],
        'basketball' => ['basket', 'باسكت', 'باسكيت', 'باسكت بول', 'كره سله', 'سله'],
        'volleyball' => ['volley', 'فولي', 'فولي بول', 'كره طايره', 'طايره'],
        'tennis' => ['تنس', 'تنس ارضي'],
        'padel' => ['paddle', 'بادل', 'بادل تنس'],
    ];

    private const DAY_AFTER_TOMORROW = ['day after tomorrow', 'بعد بكرا', 'بعد بكره', 'بعد غد', 'بعد غدا'];

    private const TOMORROW = ['tomorrow', 'tmrw', 'بكرا', 'بكره', 'بكري', 'غدا', 'الغد', 'غد'];

    private const TODAY = ['today', 'tonight', 'اليوم', 'الليله', 'هالليله'];

    /**
     * "Now" words (هلا is also the everyday "hi"): they mean today only when no day is named.
     */
    private const NOW = ['now', 'right now', 'هلا', 'هلق', 'هلقيت', 'هسا', 'هسه', 'هسع', 'الحين', 'حالا'];

    private const WEEKDAYS = [
        'saturday' => ['saturday', 'السبت'],
        'sunday' => ['sunday', 'الاحد'],
        'monday' => ['monday', 'الاثنين', 'الاتنين'],
        'tuesday' => ['tuesday', 'الثلاثاء', 'التلات'],
        'wednesday' => ['wednesday', 'الاربعاء', 'الاربعا'],
        'thursday' => ['thursday', 'الخميس'],
        'friday' => ['friday', 'الجمعه'],
    ];

    /**
     * Words that point at a day this parser can't resolve itself (months, "next week"...),
     * so a date read by Gemini from them is trusted.
     */
    private const OTHER_DATE_WORDS = [
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
        'يناير', 'فبراير', 'مارس', 'ابريل', 'مايو', 'يونيو', 'يوليو', 'اغسطس', 'سبتمبر', 'اكتوبر', 'نوفمبر', 'ديسمبر',
        'كانون', 'شباط', 'اذار', 'نيسان', 'ايار', 'حزيران', 'تموز', 'ايلول', 'تشرين',
        'next week', 'this week', 'weekend', 'الاسبوع', 'الجاي', 'الجايه', 'القادم', 'القادمه', 'يومين',
    ];

    /**
     * Night words: 12 and 1-4 with them are after midnight (the next day); 5-11 are evening.
     */
    private const NIGHT_WORDS = ['night', 'tonight', 'midnight', 'بالليل', 'ليلا', 'ليل', 'الليل', 'بليل', 'الليله', 'هالليله'];

    private const MIDNIGHT_WORDS = ['midnight', 'منتصف الليل', 'نص الليل'];

    private const NOON_WORDS = ['noon', 'midday'];

    private const PM_WORDS = ['pm', 'evening', 'afternoon', 'مساء', 'مسا', 'المسا', 'المساء', 'العصر', 'عصرا', 'عصر', 'بعد الظهر'];

    private const AM_WORDS = ['am', 'morning', 'صباحا', 'صباح', 'الصباح', 'الصبح', 'صبح', 'الفجر', 'فجرا'];

    private const HOUR_MARKERS = ['at', 'around', 'الساعه', 'ساعه', 'عالساعه', 'حوالي'];

    /**
     * Minutes said after the hour ("6 ونص" is 6:30, "6 الا ربع" is 5:45).
     */
    private const SPOKEN_MINUTES = [
        'و نصف' => 30, 'ونصف' => 30, 'والنص' => 30, 'و نص' => 30, 'ونص' => 30,
        'والربع' => 15, 'و ربع' => 15, 'وربع' => 15,
        'والثلث' => 20, 'و ثلث' => 20, 'وثلث' => 20,
        'الا الربع' => -15, 'الا ربع' => -15, 'الا الثلث' => -20, 'الا ثلث' => -20,
    ];

    /**
     * English "half past 6", "quarter past 6" and "quarter to 7".
     */
    private const ENGLISH_MINUTES = ['half past' => 30, 'quarter past' => 15, 'quarter to' => -15];

    /**
     * Hours written as words, which only Gemini reads; they still count as "an hour was given".
     */
    private const HOUR_WORDS = [
        'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'oclock', 'o clock',
        'واحده', 'وحده', 'ثنتين', 'اثنين', 'تنتين', 'ثلاث', 'ثلاثه', 'تلات', 'تلاته', 'اربع', 'اربعه', 'خمس', 'خمسه',
        'ست', 'سته', 'سبع', 'سبعه', 'ثمان', 'ثمانيه', 'تمانيه', 'تمنه', 'تسع', 'تسعه', 'عشر', 'عشره',
        'احدعش', 'حداش', 'اطنعش', 'اتناعش', 'ثنعش',
    ];

    public function parse(string $queryText, CarbonImmutable $today): array
    {
        $text = TextNormalizer::normalize($queryText);
        [$date, $textWithoutDates] = $this->extractDate($text, $today);
        $time = $this->extractTime($textWithoutDates);

        // "اليوم الساعة 12 بالليل" is the next calendar day at 00:00.
        if ($date !== null && $time !== null && $time['days'] !== 0) {
            $date = CarbonImmutable::parse($date)->addDays($time['days'])->toDateString();
        }

        return array_filter([
            'sport' => $this->matchSport($text),
            'date' => $date,
            'hour' => $time['hour'] ?? null,
            'city' => $this->matchCity($text),
            'area' => $this->matchArea($text),
        ]);
    }

    /**
     * Whether the text names a day at all, even one only Gemini can resolve.
     */
    public function mentionsDate(string $queryText, CarbonImmutable $today): bool
    {
        $text = TextNormalizer::normalize($queryText);

        return $this->extractDate($text, $today)[0] !== null
            || $this->containsAnyWord($text, self::OTHER_DATE_WORDS)
            || preg_match('/(?<!\d)\d{1,2}(?:st|nd|rd|th)(?![a-z])/', $text) === 1;
    }

    /**
     * Whether the text gives an hour at all, even one only Gemini can read ("الساعة ستة").
     */
    public function mentionsTime(string $queryText, CarbonImmutable $today): bool
    {
        [, $text] = $this->extractDate(TextNormalizer::normalize($queryText), $today);

        return $this->extractTime($text) !== null
            || preg_match('/\d/', $text) === 1
            || $this->containsAnyWord($text, self::HOUR_WORDS);
    }

    private function matchCity(string $text): ?string
    {
        $candidates = City::query()->get(['name_ar', 'name_en'])->flatMap(fn (City $city) => [
            ['name' => $city->name_en, 'alias' => $city->name_ar],
            ['name' => $city->name_en, 'alias' => $city->name_en],
        ]);

        return $this->longestMention($text, $candidates);
    }

    private function matchSport(string $text): ?string
    {
        $candidates = Sport::query()->get(['name_ar', 'name_en'])->flatMap(fn (Sport $sport) => collect([
            $sport->name_ar,
            $sport->name_en,
            ...self::SPORT_ALIASES[mb_strtolower($sport->name_en)] ?? [],
        ])->map(fn (string $alias) => ['name' => $sport->name_en, 'alias' => $alias]));

        return $this->longestMention($text, $candidates);
    }

    /**
     * Areas are free text on VENUE, so the known ones are read from active venues. An area
     * spelled like a city is skipped: "Gaza" means the city, not one venue's area field.
     */
    private function matchArea(string $text): ?string
    {
        $cityNames = City::query()->get(['name_ar', 'name_en'])
            ->flatMap(fn (City $city) => [TextNormalizer::loose($city->name_ar), TextNormalizer::loose($city->name_en)])
            ->all();

        $candidates = Venue::query()->where('status', 'active')->get(['area_ar', 'area_en'])
            ->flatMap(fn (Venue $venue) => [$venue->area_ar, $venue->area_en])
            ->filter(fn (?string $area) => filled($area) && ! in_array(TextNormalizer::loose($area), $cityNames, true))
            ->unique()
            ->map(fn (string $area) => ['name' => $area, 'alias' => $area]);

        return $this->longestMention($text, $candidates);
    }

    /**
     * @param  Collection<int, array{name: string, alias: string}>  $candidates
     */
    private function longestMention(string $text, Collection $candidates): ?string
    {
        return $candidates
            ->sortByDesc(fn (array $candidate) => mb_strlen(TextNormalizer::loose($candidate['alias'])))
            ->first(fn (array $candidate) => TextNormalizer::mentions($text, $candidate['alias']))['name'] ?? null;
    }

    /**
     * An explicit date beats a named day, which beats a "now" word.
     *
     * @return array{0: ?string, 1: string} the date, and the text with explicit dates removed
     *                                      so their digits aren't mistaken for an hour
     */
    private function extractDate(string $text, CarbonImmutable $today): array
    {
        if (preg_match('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), str_replace($m[0], ' ', $text)];
        }

        if (preg_match('/(?<!\d)(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?(?!\d)/', $text, $m)) {
            return [$this->dayMonthDate((int) $m[1], (int) $m[2], $m[3] ?? null, $today), str_replace($m[0], ' ', $text)];
        }

        $date = $this->relativeDate($text, $today) ?? ($this->containsAnyWord($text, self::NOW) ? $today : null);

        return [$date?->toDateString(), $text];
    }

    /**
     * Dates are written day/month in Palestine; without a year, a past date means next year.
     */
    private function dayMonthDate(int $day, int $month, ?string $year, CarbonImmutable $today): ?string
    {
        $fullYear = $year === null ? $today->year : (int) (strlen($year) === 2 ? '20'.$year : $year);

        if (! checkdate($month, $day, $fullYear)) {
            return null;
        }

        $date = CarbonImmutable::create($fullYear, $month, $day);

        return ($year === null && $date->lt($today) ? $date->addYear() : $date)->toDateString();
    }

    private function relativeDate(string $text, CarbonImmutable $today): ?CarbonImmutable
    {
        $offsets = [2 => self::DAY_AFTER_TOMORROW, 1 => self::TOMORROW, 0 => self::TODAY];

        foreach ($offsets as $days => $words) {
            if ($this->containsAnyWord($text, $words)) {
                return $today->addDays($days);
            }
        }

        foreach (self::WEEKDAYS as $weekday => $words) {
            if ($this->containsAnyWord($text, $words)) {
                return $today->englishDayOfWeek === ucfirst($weekday) ? $today : $today->modify('next '.$weekday);
            }
        }

        return null;
    }

    /**
     * @return array{hour: string, days: int}|null the HH:mm hour, and 1 when it falls after
     *                                             midnight of the day that was named
     */
    private function extractTime(string $text): ?array
    {
        $english = $this->alternation(array_keys(self::ENGLISH_MINUTES));

        if (preg_match('/(?<![a-z])('.$english.')\s*(\d{1,2})(?!\d)\s*(am|pm)?(?![a-z])/', $text, $m)) {
            return $this->clock((int) $m[2], 0, ($m[3] ?? '') ?: $this->meridiem($m[2], $text), self::ENGLISH_MINUTES[$m[1]]);
        }

        if (preg_match('/(?<!\d)(\d{1,2})(?::(\d{2}))?\s*(am|pm)(?![a-z])/', $text, $m)) {
            return $this->clock((int) $m[1], (int) ($m[2] ?? 0), $m[3]);
        }

        if (preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)/', $text, $m)) {
            return $this->clock((int) $m[1], (int) $m[2], $this->meridiem($m[1], $text));
        }

        $markers = $this->alternation(self::HOUR_MARKERS);
        $spoken = '(?:\s*('.$this->alternation(array_keys(self::SPOKEN_MINUTES)).')(?![\p{L}]))?';

        if (preg_match('/(?:'.$markers.')\s*(\d{1,2})(?!\d)'.$spoken.'/u', $text, $m)
            || preg_match('/(?<![\d\/:])(\d{1,2})(?![\d\/:])'.$spoken.'/u', $text, $m)) {
            return $this->clock((int) $m[1], 0, $this->meridiem($m[1], $text), self::SPOKEN_MINUTES[$m[2] ?? ''] ?? 0);
        }

        return match (true) {
            $this->containsAnyWord($text, self::MIDNIGHT_WORDS) => ['hour' => '00:00', 'days' => 1],
            $this->containsAnyWord($text, self::NOON_WORDS) => ['hour' => '12:00', 'days' => 0],
            default => null,
        };
    }

    /**
     * How a written hour is meant: a two-digit hour with a leading zero is 24-hour; otherwise
     * night, evening or morning words decide, and a bare 1-11 is an evening hour.
     */
    private function meridiem(string $hour, string $text): ?string
    {
        return match (true) {
            strlen($hour) === 2 && $hour[0] === '0' => 'h24',
            $this->containsAnyWord($text, self::NIGHT_WORDS) => 'night',
            $this->containsAnyWord($text, self::PM_WORDS) => 'pm',
            $this->containsAnyWord($text, self::AM_WORDS) => 'am',
            default => null,
        };
    }

    /**
     * Without am/pm, 1-11 is read as an evening hour: that is when almost all games are booked.
     * At night, 12 and 1-4 are after midnight, so they belong to the next day.
     *
     * @return array{hour: string, days: int}|null
     */
    private function clock(int $hour, int $minute, ?string $meridiem, int $deltaMinutes = 0): ?array
    {
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $days = 0;

        $hour = match (true) {
            $meridiem === 'h24' || $hour > 12 => $hour,
            $meridiem === 'am' => $hour === 12 ? 0 : $hour,
            $meridiem === 'night' && ($hour === 12 || $hour <= 4) => $hour % 12,
            $hour >= 1 && $hour <= 11 => $hour + 12,
            default => $hour,
        };

        if ($meridiem === 'night' && $hour <= 4) {
            $days = 1;
        }

        $minutes = $hour * 60 + $minute + $deltaMinutes;

        if ($minutes < 0) {
            [$minutes, $days] = [$minutes + 1440, $days - 1];
        } elseif ($minutes >= 1440) {
            [$minutes, $days] = [$minutes - 1440, $days + 1];
        }

        return ['hour' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60), 'days' => $days];
    }

    /**
     * @param  list<string>  $words
     */
    private function alternation(array $words): string
    {
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words));
    }

    /**
     * Whole-word match that also accepts a one-letter Arabic prefix (و، ب، ل، ف).
     *
     * @param  list<string>  $words
     */
    private function containsAnyWord(string $text, array $words): bool
    {
        foreach ($words as $word) {
            $pattern = '/(?<![\p{L}\p{N}])[وبلف]?'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
