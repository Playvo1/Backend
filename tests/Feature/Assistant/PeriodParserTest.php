<?php

namespace Tests\Feature\Assistant;

use App\Services\Assistant\PeriodParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spans of days for the assistant (v2). Gaza: the weekend is Friday and Saturday, and a week
 * runs Sunday to Saturday.
 */
class PeriodParserTest extends TestCase
{
    private function parse(string $text, string $today = '2026-10-03'): ?array
    {
        return (new PeriodParser)->parse($text, CarbonImmutable::parse($today, 'Asia/Gaza'));
    }

    /**
     * Seen from Saturday 2026-10-03 (the evaluation's reference day).
     */
    public static function phrasings(): array
    {
        $thisWeek = ['this_week', '2026-10-03', '2026-10-03'];
        $nextWeek = ['next_week', '2026-10-04', '2026-10-10'];
        $thisMonth = ['this_month', '2026-10-03', '2026-10-31'];
        $nextMonth = ['next_month', '2026-11-01', '2026-11-30'];
        $weekend = ['weekend', '2026-10-03', '2026-10-03'];
        $nextWeekend = ['next_weekend', '2026-10-09', '2026-10-10'];
        $upcoming = ['upcoming', '2026-10-03', '2026-10-09'];
        $any = ['any', '2026-10-03', '2026-10-16'];

        return [
            'هالأسبوع' => ['كورة هالأسبوع', $thisWeek],
            'هذا الأسبوع' => ['بدي ملعب هذا الأسبوع', $thisWeek],
            'this week' => ['football this week', $thisWeek],
            'arabizi hal osbo3' => ['kora hal osbo3', $thisWeek],
            'الأسبوع الجاي' => ['كورة الأسبوع الجاي', $nextWeek],
            'الاسبوع القادم' => ['ملعب الاسبوع القادم', $nextWeek],
            'next week' => ['court next week at 8', $nextWeek],
            'arabizi el osbo3 el jay' => ['kora el osbo3 el jay', $nextWeek],
            'هالشهر' => ['كورة هالشهر', $thisMonth],
            'this month' => ['any court this month', $thisMonth],
            'الشهر الجاي' => ['ملعب الشهر الجاي', $nextMonth],
            'next month' => ['football next month', $nextMonth],
            'الويكند on Saturday is today' => ['كورة الويكند', $weekend],
            'weekend' => ['football this weekend', $weekend],
            'نهاية الأسبوع' => ['ملعب نهاية الأسبوع', $weekend],
            'الويكند الجاي' => ['كورة الويكند الجاي', $nextWeekend],
            'next weekend' => ['football next weekend', $nextWeekend],
            'الأيام الجاية' => ['شو في ملاعب الأيام الجاية', $upcoming],
            'coming days' => ['football in the coming days', $upcoming],
            'أي يوم' => ['كورة أي يوم الساعة 8', $any],
            'any day' => ['football any day at 9pm', $any],
            'arabizi ay yom' => ['kora ay yom', $any],
        ];
    }

    #[DataProvider('phrasings')]
    public function test_spans_are_read_in_arabic_english_and_arabizi(string $text, array $expected): void
    {
        [$name, $from, $to] = $expected;

        $this->assertSame(['name' => $name, 'from' => $from, 'to' => $to], $this->parse($text));
    }

    public function test_a_number_of_days(): void
    {
        $this->assertSame(['name' => 'upcoming', 'from' => '2026-10-03', 'to' => '2026-10-06'], $this->parse('كورة خلال 3 ايام'));
        $this->assertSame(['name' => 'upcoming', 'from' => '2026-10-03', 'to' => '2026-10-08'], $this->parse('football in the next 5 days'));
    }

    public function test_the_weekend_is_friday_and_saturday_seen_from_each_day(): void
    {
        // Sunday to Thursday: the coming Friday-Saturday.
        $this->assertSame(['name' => 'weekend', 'from' => '2026-10-09', 'to' => '2026-10-10'], $this->parse('الويكند', '2026-10-04'));
        $this->assertSame(['name' => 'weekend', 'from' => '2026-10-09', 'to' => '2026-10-10'], $this->parse('weekend', '2026-10-08'));
        // On Friday the weekend under way is today and tomorrow; on Saturday it is today.
        $this->assertSame(['name' => 'weekend', 'from' => '2026-10-09', 'to' => '2026-10-10'], $this->parse('الويكند', '2026-10-09'));
        $this->assertSame(['name' => 'weekend', 'from' => '2026-10-10', 'to' => '2026-10-10'], $this->parse('الويكند', '2026-10-10'));
        // "Next weekend" during a weekend is the one after it.
        $this->assertSame(['name' => 'next_weekend', 'from' => '2026-10-16', 'to' => '2026-10-17'], $this->parse('الويكند الجاي', '2026-10-09'));
    }

    public function test_weeks_run_sunday_to_saturday(): void
    {
        // Wednesday: the rest of this week ends on Saturday; next week is Sunday to Saturday.
        $this->assertSame(['name' => 'this_week', 'from' => '2026-10-07', 'to' => '2026-10-10'], $this->parse('this week', '2026-10-07'));
        $this->assertSame(['name' => 'next_week', 'from' => '2026-10-11', 'to' => '2026-10-17'], $this->parse('next week', '2026-10-07'));
        // On Sunday "this week" is the whole week.
        $this->assertSame(['name' => 'this_week', 'from' => '2026-10-04', 'to' => '2026-10-10'], $this->parse('هالأسبوع', '2026-10-04'));
    }

    public function test_the_end_of_a_month_rolls_into_the_next(): void
    {
        $this->assertSame(['name' => 'next_month', 'from' => '2026-11-01', 'to' => '2026-11-30'], $this->parse('الشهر الجاي', '2026-10-31'));
        $this->assertSame(['name' => 'this_month', 'from' => '2026-10-31', 'to' => '2026-10-31'], $this->parse('هالشهر', '2026-10-31'));
    }

    public function test_a_single_day_or_no_day_is_no_span(): void
    {
        foreach (['كورة بكرا الساعة 8', 'football on friday', 'السبت الجاي', 'any football pitch free tomorrow at 7pm?', 'anything free tomorrow', 'hello', ''] as $text) {
            $this->assertNull($this->parse($text), $text);
        }
    }
}
