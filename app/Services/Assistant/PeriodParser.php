<?php

namespace App\Services\Assistant;

use Carbon\CarbonImmutable;

/**
 * Reads a span of days rather than one day: "هالأسبوع", "next week", "الشهر الجاي",
 * "الويكند", "أي يوم", "الأيام الجاية", "khilal 3 ayam"... in Arabic, English and Arabizi.
 * The assistant then searches every day of the span from now on and offers the earliest
 * good slots.
 *
 * Assumptions (Gaza): the weekend is Friday and Saturday, and a week runs Sunday to
 * Saturday, so "this week" ends with the coming weekend. "This weekend" said on a Friday or
 * a Saturday is the one under way; "next weekend" then is the one after it.
 */
class PeriodParser
{
    public const NAMES = ['this_week', 'next_week', 'this_month', 'next_month', 'weekend', 'next_weekend', 'upcoming', 'any'];

    /**
     * How far "any day" and "the coming days" look ahead.
     */
    public const ANY_DAYS = 14;

    public const UPCOMING_DAYS = 7;

    /**
     * Patterns on normalised text (TextNormalizer::normalize(): ة as ه, no hamza on alef, ى
     * as ي, lower case), most specific first: "الويكند الجاي" before "الويكند".
     */
    private const PATTERNS = [
        'next_weekend' => [
            '(?:ال)?ويكند\s+(?:الجاي|القادم|المقبل|الياي|اللي\s+جاي|الي\s+جاي)',
            'نهايه\s+الاسبوع\s+(?:الجاي|القادم|المقبل)',
            'next\s+weekend', '(?:el\s+)?weekend\s+(?:el\s+)?(?:jay|jaye|jayy)',
        ],
        'weekend' => [
            '(?:ال|هال|هذا\s+ال|هاد\s+ال)?ويكند', '(?:عطله\s+)?نهايه\s+(?:هذا\s+)?الاسبوع', 'اخر\s+الاسبوع', 'اخر\s+هالاسبوع',
            '(?:this\s+|the\s+|el\s+|hal\s+)?weekend', 'week\s?end',
        ],
        'next_week' => [
            'الاسبوع\s+(?:الجاي|القادم|المقبل|الياي|اللي\s+جاي|الي\s+جاي)',
            'next\s+week', '(?:el\s+|il\s+)?(?:osbo3|esbo3|usbu3|isbo3)\s+(?:el\s+|il\s+)?(?:jay|jaye|jayy|ljay)',
        ],
        'this_week' => [
            'هالاسبوع', '(?:هذا|هاد|هادا|هيدا)\s+الاسبوع', 'الاسبوع\s+(?:هذا|هاد|الحالي)', 'خلال\s+الاسبوع', 'بالاسبوع\s+هاد',
            'this\s+week', '(?:hal|hal\s|hada\s+el\s|el\s)(?:osbo3|esbo3|usbu3|isbo3)',
        ],
        'next_month' => [
            'الشهر\s+(?:الجاي|القادم|المقبل|الياي|اللي\s+جاي)', 'next\s+month', '(?:el\s+)?(?:shahr|sha7r)\s+(?:el\s+)?(?:jay|jaye|ljay)',
        ],
        'this_month' => [
            'هالشهر', '(?:هذا|هاد|هادا)\s+الشهر', 'الشهر\s+(?:هذا|هاد|الحالي)', 'خلال\s+الشهر', 'this\s+month', '(?:hal\s?|el\s)(?:shahr|sha7r)',
        ],
        'upcoming' => [
            '(?:خلال\s+|في\s+|ب)?(?:ال)?ايام\s+(?:الجايه|القادمه|المقبله|الجاي|الياي|اللي\s+جايه)', 'قريبا',
            '(?:the\s+)?(?:next|coming|upcoming)\s+(?:few\s+)?days', 'soon', '(?:el\s+)?ayam\s+(?:el\s+)?(?:jaye|jay)',
        ],
        'any' => [
            '[بف]?(?:اي|ايه)\s+(?:يوم|وقت|موعد)', 'متي\s+ما\s+كان', 'باي\s+وقت', 'في\s+اي\s+وقت',
            'any\s?(?:day|time)', 'whenever', 'ay\s+(?:yom|youm|yoom|wa2t)',
        ],
    ];

    /**
     * "خلال 3 ايام", "next 3 days", "in the next 5 days": today and the N days after.
     */
    private const NEXT_DAYS = [
        '(?:خلال|في|بال)\s*(?:ال)?(\d{1,2})\s*(?:ايام|يوم)\s*(?:الجايه|القادمه|الجاي)?',
        '(?:ال)?(\d{1,2})\s*(?:ايام|يوم)\s+(?:الجايه|القادمه|الجاي)',
        '(?:next|coming|in\s+the\s+next|within)\s+(\d{1,2})\s+days',
    ];

    /**
     * The span named in the text, or null.
     *
     * @return array{name: string, from: string, to: string}|null from and to are YYYY-MM-DD
     */
    public function parse(string $queryText, CarbonImmutable $today): ?array
    {
        $text = TextNormalizer::normalize($queryText);
        $today = $today->startOfDay();

        foreach (self::NEXT_DAYS as $pattern) {
            if (preg_match('/(?<![\p{L}\d])'.$pattern.'(?![\p{L}\d])/u', $text, $m) && (int) $m[1] >= 1) {
                $days = min((int) $m[1], 31);

                return ['name' => 'upcoming', 'from' => $today->toDateString(), 'to' => $today->addDays($days)->toDateString()];
            }
        }

        foreach (self::PATTERNS as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/(?<![\p{L}\d])[وبلف]?'.$pattern.'(?![\p{L}\d])/u', $text)) {
                    return $this->span($name, $today);
                }
            }
        }

        return null;
    }

    /**
     * The days of a named span seen from $today (the players' local date).
     *
     * @return array{name: string, from: string, to: string}
     */
    public function span(string $name, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        // Carbon: Sunday is 0 and Saturday 6, the last day of the week here.
        $daysToSaturday = 6 - $today->dayOfWeek;
        $inWeekend = in_array($today->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true);
        $comingFriday = $today->dayOfWeek === CarbonImmutable::FRIDAY ? $today : $today->next(CarbonImmutable::FRIDAY);

        [$from, $to] = match ($name) {
            'this_week' => [$today, $today->addDays($daysToSaturday)],
            'next_week' => [$today->addDays($daysToSaturday + 1), $today->addDays($daysToSaturday + 7)],
            'this_month' => [$today, $today->endOfMonth()],
            'next_month' => [$today->addMonthNoOverflow()->startOfMonth(), $today->addMonthNoOverflow()->endOfMonth()],
            'weekend' => $inWeekend
                ? [$today, $today->dayOfWeek === CarbonImmutable::FRIDAY ? $today->addDay() : $today]
                : [$comingFriday, $comingFriday->addDay()],
            'next_weekend' => $inWeekend
                ? [$today->next(CarbonImmutable::FRIDAY), $today->next(CarbonImmutable::FRIDAY)->addDay()]
                : [$comingFriday, $comingFriday->addDay()],
            'upcoming' => [$today, $today->addDays(self::UPCOMING_DAYS - 1)],
            default => [$today, $today->addDays(self::ANY_DAYS - 1)],
        };

        return ['name' => in_array($name, self::NAMES, true) ? $name : 'any', 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
