<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Server-side, rule-based fallback for the booking assistant (US-4.5), used when Gemini
 * is not configured, fails or times out. It understands short Arabic and English
 * requests: sport names from the SPORT table plus common aliases, today/tomorrow/
 * weekdays (اليوم، بكرا، بعد بكرا، الجمعة...), explicit dates, hours such as "8",
 * "6pm", "18:30" or "الساعة 6 مساءً", and city/area names from the CITY and VENUE tables.
 */
class RuleBasedQueryParser implements QueryParser
{
    /**
     * Extra ways players name a sport, keyed by SPORT.name_en (lower case).
     */
    private const SPORT_ALIASES = [
        'football' => ['soccer', 'futsal', 'كوره', 'كوره قدم', 'كره قدم', 'فوتبول', 'فطبول'],
        'basketball' => ['basket', 'باسكت', 'باسكيت', 'باسكت بول', 'كره سله', 'سله'],
        'volleyball' => ['volley', 'فولي', 'فولي بول', 'كره طائره', 'طائره'],
        'tennis' => ['تنس', 'تنس ارضي'],
        'padel' => ['paddle', 'بادل', 'بادل تنس'],
    ];

    private const DAY_AFTER_TOMORROW = ['day after tomorrow', 'بعد بكرا', 'بعد بكره', 'بعد غد', 'بعد غدا'];

    private const TOMORROW = ['tomorrow', 'tmrw', 'بكرا', 'بكره', 'بكرى', 'غدا', 'الغد', 'غد'];

    private const TODAY = ['today', 'tonight', 'اليوم', 'الليله', 'هالليله', 'هلا', 'هلق', 'هسا'];

    private const WEEKDAYS = [
        'saturday' => ['saturday', 'السبت'],
        'sunday' => ['sunday', 'الاحد'],
        'monday' => ['monday', 'الاثنين', 'الاتنين'],
        'tuesday' => ['tuesday', 'الثلاثاء', 'التلات'],
        'wednesday' => ['wednesday', 'الاربعاء', 'الاربعا'],
        'thursday' => ['thursday', 'الخميس'],
        'friday' => ['friday', 'الجمعه'],
    ];

    private const PM_WORDS = ['pm', 'evening', 'night', 'tonight', 'afternoon', 'مساء', 'مسا', 'المسا', 'المساء', 'بالليل', 'ليلا', 'ليل', 'الليله', 'بليل', 'العصر', 'عصرا', 'عصر', 'بعد الظهر'];

    private const AM_WORDS = ['am', 'morning', 'صباحا', 'صباح', 'الصباح', 'الصبح', 'صبح', 'الفجر', 'فجرا'];

    private const HOUR_MARKERS = ['at', 'around', 'الساعه', 'ساعه', 'عالساعه', 'حوالي'];

    public function parse(string $queryText, CarbonImmutable $today): array
    {
        $text = TextNormalizer::normalize($queryText);
        [$date, $textWithoutDates] = $this->extractDate($text, $today);

        return array_filter([
            'sport' => $this->matchSport($text),
            'date' => $date ?? $today->toDateString(),
            'hour' => $this->extractHour($textWithoutDates),
            'city' => $this->matchCity($text),
            'area' => $this->matchArea($text),
        ]);
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

    private function matchCity(string $text): ?string
    {
        $candidates = City::query()->get(['name_ar', 'name_en'])->flatMap(fn (City $city) => [
            ['name' => $city->name_en, 'alias' => $city->name_ar],
            ['name' => $city->name_en, 'alias' => $city->name_en],
        ]);

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
     * @return array{0: ?string, 1: string} the date, and the text with explicit dates removed
     *                                      so their digits aren't mistaken for an hour
     */
    private function extractDate(string $text, CarbonImmutable $today): array
    {
        if (preg_match('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m) && checkdate($m[2], $m[3], $m[1])) {
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), str_replace($m[0], ' ', $text)];
        }

        if (preg_match('/(?<!\d)(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?(?!\d)/', $text, $m)) {
            return [$this->dayMonthDate((int) $m[1], (int) $m[2], $m[3] ?? null, $today), str_replace($m[0], ' ', $text)];
        }

        return [$this->relativeDate($text, $today)?->toDateString(), $text];
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

    private function extractHour(string $text): ?string
    {
        if (preg_match('/(?<!\d)(\d{1,2})(?::(\d{2}))?\s*(am|pm)(?![a-z])/', $text, $m)) {
            return $this->toClock((int) $m[1], (int) ($m[2] ?? 0), $m[3]);
        }

        $markers = implode('|', array_map(fn ($word) => preg_quote($word, '/'), self::HOUR_MARKERS));

        if (preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)/', $text, $m)
            || preg_match('/(?:'.$markers.')\s*(\d{1,2})(?!\d)/u', $text, $m)
            || preg_match('/(?<![\d\/:])(\d{1,2})(?![\d\/:])/', $text, $m)) {
            $leadingZero = str_starts_with($m[1], '0') && strlen($m[1]) === 2;

            return $this->toClock((int) $m[1], (int) ($m[2] ?? 0), $leadingZero ? 'h24' : $this->meridiemFromWords($text));
        }

        return null;
    }

    private function meridiemFromWords(string $text): ?string
    {
        return match (true) {
            $this->containsAnyWord($text, self::PM_WORDS) => 'pm',
            $this->containsAnyWord($text, self::AM_WORDS) => 'am',
            default => null,
        };
    }

    /**
     * Without am/pm, 1-11 is read as an evening hour: that is when almost all games are booked.
     */
    private function toClock(int $hour, int $minute, ?string $meridiem): ?string
    {
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $hour = match (true) {
            $meridiem === 'h24' => $hour,
            $meridiem === 'am' => $hour === 12 ? 0 : $hour,
            $hour >= 1 && $hour <= 11 => $hour + 12,
            default => $hour,
        };

        return $hour > 23 ? null : sprintf('%02d:%02d', $hour, $minute);
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
