<?php

namespace Tests\Feature\Assistant;

use App\Models\City;
use App\Services\Assistant\RuleBasedQueryParser;
use App\Services\Assistant\TextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class RuleBasedQueryParserTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $gaza = $this->makeCity();
        $this->makeCity('Khan Younis', 'خانيونس');
        $this->makeSport();
        $this->makeSport('Basketball', 'كرة السلة');
        $this->makeSport('Padel', 'البادل');
        $this->makeSport('Tennis', 'التنس');
        $this->makeVenue(null, ['city_id' => $gaza->id, 'area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa']);
        $this->makeVenue(null, ['city_id' => $gaza->id, 'area_ar' => 'غزة', 'area_en' => 'Gaza']);
        $this->makeVenue(null, ['city_id' => $gaza->id, 'area_ar' => 'الشجاعية', 'area_en' => 'Shujaiya']);
    }

    private function parse(string $text): array
    {
        // A Saturday.
        return (new RuleBasedQueryParser)->parse($text, CarbonImmutable::parse('2026-10-03'));
    }

    public static function hours(): array
    {
        return [
            '6pm' => ['football at 6pm', '18:00'],
            '6 PM' => ['football at 6 PM', '18:00'],
            '6 p.m.' => ['football at 6 p.m.', '18:00'],
            '6:30pm' => ['football at 6:30pm', '18:30'],
            '9am' => ['football at 9am', '09:00'],
            '12am' => ['football at 12am', '00:00'],
            '12pm' => ['football at 12pm', '12:00'],
            '24h' => ['football at 18:00', '18:00'],
            '24h with minutes' => ['football at 18:30', '18:30'],
            'leading zero is 24h' => ['football at 08', '08:00'],
            'bare hour means evening' => ['football tomorrow at 8', '20:00'],
            'morning word' => ['football tomorrow 9 in the morning', '09:00'],
            'arabic evening' => ['كرة قدم الساعة 6 مساءً', '18:00'],
            'arabic المسا' => ['كرة قدم 6 المسا', '18:00'],
            'arabic dialect night' => ['كرة قدم الساعه 8 بالليل', '20:00'],
            'arabic الصبح' => ['كرة قدم 8 الصبح', '08:00'],
            'arabic morning' => ['كرة قدم الساعة 9 الصبح', '09:00'],
            'arabic afternoon' => ['كرة سلة الساعة 5 العصر', '17:00'],
            'arabic 24h' => ['كرة قدم الساعة 18:00', '18:00'],
            'arabic-indic digits' => ['كرة قدم الساعة ٨ مساءً', '20:00'],
            'arabic-indic 24h' => ['كرة قدم الساعة ١٨:٣٠', '18:30'],
        ];
    }

    #[DataProvider('hours')]
    public function test_reads_the_hour(string $text, string $hour): void
    {
        $this->assertSame($hour, $this->parse($text)['hour'] ?? null);
    }

    public static function dates(): array
    {
        return [
            'today' => ['football today at 6pm', '2026-10-03'],
            'now word means today' => ['هلق بدي كورة الساعة 6', '2026-10-03'],
            'english now' => ['football now', '2026-10-03'],
            'هلا greeting does not beat a weekday' => ['هلا، بدي ملعب كرة قدم الجمعة الساعة 6', '2026-10-09'],
            'now word does not beat tomorrow' => ['هلق بدي كورة بكرا الساعة 6', '2026-10-04'],
            'tonight' => ['football tonight at 9', '2026-10-03'],
            'tomorrow' => ['football tomorrow at 6pm', '2026-10-04'],
            'day after tomorrow' => ['football the day after tomorrow at 6pm', '2026-10-05'],
            'اليوم' => ['كرة قدم اليوم الساعة 6', '2026-10-03'],
            'بكرا' => ['كرة قدم بكرا الساعة 6', '2026-10-04'],
            'بكره' => ['كرة قدم بكره الساعة 6', '2026-10-04'],
            'بكرة' => ['كرة قدم بكرة الساعة 6', '2026-10-04'],
            'بكرى' => ['كرة قدم بكرى الساعة 6', '2026-10-04'],
            'غداً' => ['كرة قدم غداً الساعة 6', '2026-10-04'],
            'بعد بكرا' => ['كرة قدم بعد بكرا الساعة 6', '2026-10-05'],
            'بعد بكره' => ['كرة قدم بعد بكره الساعة 6', '2026-10-05'],
            'weekday' => ['football on monday at 7pm', '2026-10-05'],
            'same weekday means today' => ['football on saturday at 7pm', '2026-10-03'],
            'arabic weekday' => ['كرة قدم يوم الجمعة الساعة 7', '2026-10-09'],
            'arabic weekday with hamza' => ['كرة قدم يوم الأحد الساعة 7', '2026-10-04'],
            'iso date' => ['football on 2026-10-12 at 7pm', '2026-10-12'],
            'day/month' => ['football on 12/10 at 7pm', '2026-10-12'],
            'past day/month means next year' => ['football on 1/10 at 7pm', '2027-10-01'],
        ];
    }

    #[DataProvider('dates')]
    public function test_reads_the_date(string $text, string $date): void
    {
        $this->assertSame($date, $this->parse($text)['date']);
    }

    public static function spokenAndNightTimes(): array
    {
        return [
            // Night: 12, midnight and 1-4 are after midnight of the named day; 5-11 are evening.
            '12 at night arabic' => ['كرة قدم اليوم الساعة 12 بالليل', '2026-10-04', '00:00'],
            '12 at night english' => ['football today at 12 at night', '2026-10-04', '00:00'],
            'midnight' => ['football tomorrow at midnight', '2026-10-05', '00:00'],
            'arabic midnight' => ['كورة بكرا نص الليل', '2026-10-05', '00:00'],
            '1 at night' => ['كرة قدم بكرا الساعة 1 بالليل', '2026-10-05', '01:00'],
            '3 at night' => ['كرة قدم الساعة 3 ليلا اليوم', '2026-10-04', '03:00'],
            'tonight at 1' => ['football tonight at 1', '2026-10-04', '01:00'],
            '11 at night stays evening' => ['كرة قدم بكرا الساعة 11 بالليل', '2026-10-04', '23:00'],
            '9 tonight stays evening' => ['football tonight at 9', '2026-10-03', '21:00'],
            '12am stays on the named day' => ['football tomorrow at 12am', '2026-10-04', '00:00'],
            // Spoken minutes.
            'ونص' => ['كرة قدم بكرا الساعة 6 ونص', '2026-10-04', '18:30'],
            'و نص' => ['كرة قدم بكرا 7 و نص المسا', '2026-10-04', '19:30'],
            'وربع' => ['كرة قدم بكرا الساعة 8 وربع', '2026-10-04', '20:15'],
            'الا ربع' => ['كرة قدم بكرا الساعة 6 الا ربع', '2026-10-04', '17:45'],
            'إلا ربع with hamza' => ['كرة قدم بكرا الساعة 6 إلا ربع', '2026-10-04', '17:45'],
            'وثلث' => ['كرة قدم بكرا الساعة 5 وثلث', '2026-10-04', '17:20'],
            'ونص الصبح' => ['كرة قدم بكرا الساعة 9 ونص الصبح', '2026-10-04', '09:30'],
            '12 ونص بالليل' => ['كرة قدم اليوم الساعة 12 ونص بالليل', '2026-10-04', '00:30'],
            '12 الا ربع بالليل' => ['كرة قدم اليوم الساعة 12 الا ربع بالليل', '2026-10-03', '23:45'],
            'half past' => ['football tomorrow at half past 6', '2026-10-04', '18:30'],
            'quarter past' => ['football tomorrow quarter past 7pm', '2026-10-04', '19:15'],
            'quarter to' => ['football tomorrow quarter to 7', '2026-10-04', '18:45'],
            // Dotted minutes.
            '6.30pm' => ['football tomorrow at 6.30pm', '2026-10-04', '18:30'],
            '6.30 مساء' => ['كرة قدم بكرا 6.30 مساء', '2026-10-04', '18:30'],
            '18.30' => ['football tomorrow 18.30', '2026-10-04', '18:30'],
            'arabic decimal separator' => ['كرة قدم بكرا ٦٫٣٠ مساء', '2026-10-04', '18:30'],
            'sentence dot' => ['football tomorrow at 6.30.', '2026-10-04', '18:30'],
            'noon' => ['football tomorrow at noon', '2026-10-04', '12:00'],
        ];
    }

    #[DataProvider('spokenAndNightTimes')]
    public function test_reads_spoken_night_and_dotted_times(string $text, string $date, string $hour): void
    {
        $parsed = $this->parse($text);

        $this->assertSame($hour, $parsed['hour'] ?? null, 'hour');
        $this->assertSame($date, $parsed['date'] ?? null, 'date');
    }

    public static function hoursWrittenAsWords(): array
    {
        return [
            'الساعة ستة المسا' => ['كورة بكرا الساعة ستة المسا', '2026-10-04', '18:00'],
            'الساعة ثمانية (evening by default)' => ['كرة قدم بكرا الساعة ثمانية', '2026-10-04', '20:00'],
            'عالتمانية' => ['شو في ملاعب كورة اليوم عالتمانية؟', '2026-10-03', '20:00'],
            'ع السبعة' => ['كورة بكرا ع السبعة', '2026-10-04', '19:00'],
            'على ستة' => ['كورة بكرا على ستة', '2026-10-04', '18:00'],
            'بالسبعة' => ['كورة بكرا بالسبعة', '2026-10-04', '19:00'],
            'ستة المسا without a marker' => ['كورة بكرا ستة المسا', '2026-10-04', '18:00'],
            'formal السابعة مساءً' => ['أريد ملعب كرة قدم غداً الساعة السابعة مساءً', '2026-10-04', '19:00'],
            'formal without a marker' => ['كرة قدم بكرا السابعة مساء', '2026-10-04', '19:00'],
            'formal عالسابعة' => ['كورة بكرا عالسابعة', '2026-10-04', '19:00'],
            'formal على السابعة' => ['كورة بكرا على السابعة', '2026-10-04', '19:00'],
            'formal السادسة والنصف' => ['كرة قدم بكرا الساعة السادسة والنصف مساء', '2026-10-04', '18:30'],
            'formal الواحدة needs the marker' => ['كرة قدم بكرا الساعة الواحدة بالليل', '2026-10-05', '01:00'],
            'حداش بالليل' => ['كرة قدم بكرا الساعة حداش بالليل', '2026-10-04', '23:00'],
            'اطناش بالليل' => ['كرة قدم بكرا الساعة اطناش بالليل', '2026-10-05', '00:00'],
            'سبعة ونص' => ['كورة بكرا سبعة ونص', '2026-10-04', '19:30'],
            'تسعة الا ربع' => ['كورة بكرا الساعة تسعة الا ربع', '2026-10-04', '20:45'],
            'at seven' => ['football tomorrow at seven', '2026-10-04', '19:00'],
            'at seven pm' => ['football tomorrow at seven pm', '2026-10-04', '19:00'],
            'nine am' => ['football tomorrow nine am', '2026-10-04', '09:00'],
            'half past seven' => ['football tomorrow half past seven', '2026-10-04', '19:30'],
            'twelve at night' => ['football tomorrow at twelve at night', '2026-10-05', '00:00'],
        ];
    }

    #[DataProvider('hoursWrittenAsWords')]
    public function test_reads_hours_written_as_words(string $text, string $date, string $hour): void
    {
        $parsed = $this->parse($text);

        $this->assertSame($hour, $parsed['hour'] ?? null, 'hour');
        $this->assertSame($date, $parsed['date'] ?? null, 'date');
    }

    public function test_a_number_word_outside_a_time_context_is_not_an_hour(): void
    {
        $this->assertArrayNotHasKey('hour', $this->parse('بدنا ملعب لعشرة شباب بكرا'));
        $this->assertArrayNotHasKey('hour', $this->parse('football for five players tomorrow'));
        $this->assertArrayNotHasKey('hour', $this->parse('one court tomorrow please'));
    }

    public static function numbersThatAreNotAnHour(): array
    {
        return [
            'a price' => ['بدي ملعب كورة بكرا ب 20 شيكل'],
            'a price in ils' => ['football tomorrow for 20 ils'],
            'a price with the shekel sign' => ['football tomorrow 20₪'],
            'a price before the number' => ['ملعب بسعر 20 بكرا'],
            'a team size' => ['ملعب خماسي 5 ضد 5 بكرا'],
            'a team size in English' => ['football 5 vs 5 tomorrow'],
            'a team size with على' => ['basketball 3 على 3 tomorrow'],
            'a player count' => ['بدنا ملعب ل 10 لاعبين بكرا'],
            'a count of courts' => ['بدي 2 ملاعب بكرا'],
            'a duration' => ['football tomorrow for 2 hours'],
            'a duration in Arabic' => ['ملعب كورة بكرا 2 ساعة'],
            'a day of the month' => ['بدي ملعب كورة 5 أكتوبر'],
            'يوم and a day of the month' => ['بدي ملعب كورة يوم 5'],
            'a court number' => ['بدي ملعب رقم 3 بكرا'],
            'phone number groups' => ['رقمي 0599 12 34 56 بدي ملعب بكرا'],
            'two bare numbers' => ['football tomorrow 7 or 8'],
        ];
    }

    #[DataProvider('numbersThatAreNotAnHour')]
    public function test_a_number_that_is_not_an_hour_is_not_read_as_one(string $text): void
    {
        $this->assertArrayNotHasKey('hour', $this->parse($text));
        $this->assertFalse((new RuleBasedQueryParser)->mentionsTime($text, CarbonImmutable::parse('2026-10-03')), 'mentionsTime');
    }

    public static function hoursNextToOtherNumbers(): array
    {
        return [
            'after a team size' => ['ملعب كورة 5 ضد 5 بكرا 8 بالليل', '20:00'],
            'after a price' => ['بدي ملعب كورة ب 50 شيكل بكرا 8 بالليل', '20:00'],
            'before a price' => ['بدي ملعب كورة بكرا 8 بالليل ب 50 شيكل', '20:00'],
            'after a player count' => ['بدنا ملعب كورة ل 10 لاعبين بكرا 8 بالليل', '20:00'],
            'after phone number groups' => ['رقمي 0599 12 34 56 بدي ملعب كورة بكرا 8 بالليل', '20:00'],
            'after a day and month' => ['بدي ملعب كورة 10 اكتوبر 8 بالليل', '20:00'],
            'after a dotted day and month' => ['بدي ملعب كورة يوم 5.10 الساعة 8 بالليل', '20:00'],
            'الساعة beats an earlier bare number' => ['ملعب 3 بكرا الساعة 8', '20:00'],
            'a clock word before a count word' => ['بكرا الساعة 8 ملعب كورة', '20:00'],
            'the only number left' => ['football 5 vs 5 tomorrow 8', '20:00'],
            'with a duration after' => ['football tomorrow at 8 for 2 hours', '20:00'],
            '@' => ['football tomorrow @ 8', '20:00'],
            'ع before digits' => ['كورة بكرا ع 7', '19:00'],
        ];
    }

    #[DataProvider('hoursNextToOtherNumbers')]
    public function test_the_hour_is_found_next_to_numbers_that_are_not_hours(string $text, string $hour): void
    {
        $this->assertSame($hour, $this->parse($text)['hour'] ?? null);
    }

    public static function datesWithAMonth(): array
    {
        return [
            'day and Arabic month' => ['كورة 10 اكتوبر 8 بالليل', '2026-10-10'],
            'day and month with hamza' => ['كورة 5 أكتوبر', '2026-10-05'],
            'Levantine month' => ['كورة 10 تشرين الاول', '2026-10-10'],
            'English month' => ['football 12 october at 7pm', '2026-10-12'],
            'month first' => ['football oct 12th at 7pm', '2026-10-12'],
            'a past day means next year' => ['كورة 1 اكتوبر', '2027-10-01'],
            'day and month number' => ['كورة 12 شهر 10', '2026-10-12'],
            'a dotted date after يوم' => ['كورة يوم 5.10 الساعة 8', '2026-10-05'],
            'a full dotted date' => ['كرة قدم 12.10.2026 الساعة 7', '2026-10-12'],
        ];
    }

    #[DataProvider('datesWithAMonth')]
    public function test_reads_a_day_with_its_month(string $text, string $date): void
    {
        $this->assertSame($date, $this->parse($text)['date'] ?? null);
    }

    public static function nextWeekdays(): array
    {
        // A Saturday.
        return [
            'السبت الجاي on a Saturday' => ['كورة السبت الجاي الساعة 9', '2026-10-10'],
            'يوم السبت القادم on a Saturday' => ['كورة يوم السبت القادم', '2026-10-10'],
            'next saturday on a Saturday' => ['football next saturday 9pm', '2026-10-10'],
            'this coming saturday on a Saturday' => ['football this coming saturday', '2026-10-10'],
            'السبت alone is today' => ['كورة السبت الساعة 9', '2026-10-03'],
            'الخميس الجاي is the coming Thursday' => ['كورة الخميس الجاي', '2026-10-08'],
            'next monday is the coming Monday' => ['football next monday', '2026-10-05'],
        ];
    }

    #[DataProvider('nextWeekdays')]
    public function test_next_weekday_is_a_week_later_only_on_that_weekday(string $text, string $date): void
    {
        $this->assertSame($date, $this->parse($text)['date'] ?? null);
    }

    public function test_tonight_with_an_am_hour_is_after_midnight(): void
    {
        $parser = new RuleBasedQueryParser;
        $today = CarbonImmutable::parse('2026-10-03');

        foreach (['football tonight at 1am' => '01:00', 'football tonight at 2 am' => '02:00', 'football tonight 12am' => '00:00'] as $text => $hour) {
            $this->assertSame(['2026-10-04', $hour], [$parser->parse($text, $today)['date'], $parser->parse($text, $today)['hour']], $text);
        }

        // Already after midnight, "tonight at 1am" is later the same day; without a night word
        // an am hour stays on the named day.
        $early = $parser->parse('football tonight at 1am', CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-04 00:30'));
        $this->assertSame('2026-10-04', $early['date']);
        $this->assertSame('2026-10-04', $parser->parse('football tomorrow at 1am', $today)['date']);
        $this->assertSame('2026-10-03', $parser->parse('football today at 5am', $today)['date']);
    }

    public static function typosAndSlang(): array
    {
        return [
            'كورا' => ['بدي ملعب كورا بكرا', 'sport', 'Football'],
            'fotball' => ['fotball tomorrow', 'sport', 'Football'],
            'footbal' => ['footbal tomorrow', 'sport', 'Football'],
            'socer' => ['socer tomorrow', 'sport', 'Football'],
            'basketbal' => ['basketbal tomorrow', 'sport', 'Basketball'],
            'tenis' => ['tenis tomorrow', 'sport', 'Tennis'],
            'padle (swapped letters)' => ['padle tomorrow', 'sport', 'Padel'],
            'footy' => ['any footy pitch tomorrow', 'sport', 'Football'],
            '5-a-side' => ['need a 5-a-side pitch tomorrow', 'sport', 'Football'],
            'five a side' => ['five a side tomorrow', 'sport', 'Football'],
            'خماسي' => ['بدي ملعب خماسي بكرا', 'sport', 'Football'],
            'kora (Arabizi)' => ['bde mal3ab kora bukra', 'sport', 'Football'],
            'tomorow' => ['football tomorow', 'date', '2026-10-04'],
            'tommorow' => ['football tommorow', 'date', '2026-10-04'],
            'tmrw' => ['football tmrw', 'date', '2026-10-04'],
            '2moro' => ['football 2moro', 'date', '2026-10-04'],
            'thursdy' => ['football thursdy', 'date', '2026-10-08'],
            'tusday is tuesday, not thursday' => ['football tusday', 'date', '2026-10-06'],
            'tonite' => ['football tonite', 'date', '2026-10-03'],
            'bukra' => ['kora bukra', 'date', '2026-10-04'],
            'el yom' => ['kora el yom', 'date', '2026-10-03'],
            'ba3d bukra' => ['kora ba3d bukra', 'date', '2026-10-05'],
            '3al 7' => ['bde mal3ab kora bukra 3al 7', 'hour', '19:00'],
            '3al 9 bel lel' => ['kora bukra 3al 9 bel lel', 'hour', '21:00'],
        ];
    }

    #[DataProvider('typosAndSlang')]
    public function test_reads_typos_slang_and_arabizi(string $text, string $field, string $value): void
    {
        $this->assertSame($value, $this->parse($text)[$field] ?? null);
    }

    public function test_short_words_and_other_first_letters_are_not_typos(): void
    {
        $this->assertArrayNotHasKey('sport', $this->parse('بدي سلم بكرا'));
        $this->assertArrayNotHasKey('sport', $this->parse('rootball tomorrow'));
        $this->assertArrayNotHasKey('date', $this->parse('football tmorrow2'));
    }

    public function test_digits_inside_arabizi_words_are_not_hours(): void
    {
        $parsed = $this->parse('bde mal3ab kora bukra');

        $this->assertArrayNotHasKey('hour', $parsed);
        $this->assertFalse((new RuleBasedQueryParser)->mentionsTime('bde mal3ab kora bukra', CarbonImmutable::parse('2026-10-03')));
        $this->assertTrue((new RuleBasedQueryParser)->mentionsDate('bde mal3ab kora bukra', CarbonImmutable::parse('2026-10-03')));
    }

    public function test_areas_match_with_the_spelling_variants_the_search_accepts(): void
    {
        $this->makeVenue(null, ['area_ar' => 'تل الهوى', 'area_en' => 'Tal al-Hawa']);

        $this->assertSame('الجلاء', $this->parse('كرة قدم بالجلا بكرا')['area'] ?? null);
        $this->assertSame('تل الهوى', $this->parse('كورة بتل الهوا بكرا')['area'] ?? null);
        $this->assertSame('Shujaiya', $this->parse('football in shejaiya tomorrow')['area'] ?? null);
        $this->assertSame('Al-Jalaa', $this->parse('football in al jalaa tomorrow')['area'] ?? null);
    }

    public function test_a_typo_never_picks_one_of_two_equally_close_places(): void
    {
        $this->makeVenue(null, ['area_ar' => null, 'area_en' => 'Shamali']);
        $this->makeVenue(null, ['area_ar' => null, 'area_en' => 'Shimali']);

        $this->assertArrayNotHasKey('area', $this->parse('football in shomali tomorrow'));
        $this->assertSame('Shamali', $this->parse('football in shamali tomorrow')['area'] ?? null);
        // "shemali" is not a typo but another spelling of Shimali (e for i).
        $this->assertSame('Shimali', $this->parse('football in shemali tomorrow')['area'] ?? null);
    }

    /**
     * The seeded places of the evaluation fixture that the tests below need.
     */
    private function seedMorePlaces(): void
    {
        $rafah = $this->makeCity('Rafah', 'رفح');
        $deir = $this->makeCity('Deir al-Balah', 'دير البلح');
        $jabalia = $this->makeCity('Jabalia', 'جباليا');
        $gaza = City::where('name_en', 'Gaza')->first();
        $this->makeVenue(null, ['city_id' => $gaza->id, 'area_ar' => 'تل الهوى', 'area_en' => 'Tal al-Hawa']);
        $this->makeVenue(null, ['city_id' => $gaza->id, 'area_ar' => 'الرمال', 'area_en' => 'Al-Rimal']);
        $this->makeVenue(null, ['city_id' => $rafah->id, 'area_ar' => 'تل السلطان', 'area_en' => 'Tal al-Sultan']);
        $this->makeVenue(null, ['city_id' => $deir->id, 'area_ar' => 'البصة', 'area_en' => 'Al-Bassa']);
        $this->makeVenue(null, ['city_id' => $jabalia->id, 'area_ar' => 'النزلة', 'area_en' => 'Al-Nazla']);
    }

    public static function wordsThatAreNotAPlace(): array
    {
        return [
            'an Arabic name inside which الجلا is written' => ['أنا جلال بدي ملعب كورة بكرا 8 بالليل'],
            'a Latin name one letter from Al-Jalaa' => ["hi I'm jalal, football tomorrow 8pm"],
            'a Latin name one letter from Al-Bassa' => ['hi this is bassam, football tomorrow 8pm'],
            'a Latin name one letter from Rafah' => ['football tomorrow 8pm for rafat'],
            'للرجال is not الرمال' => ['بدي ملعب كورة بكرا الساعة 8 بالليل للرجال'],
            'نزلت is not النزلة' => ['نزلت ع البلد، بدي ملعب كورة بكرا 8 بالليل'],
            'منزله is not النزلة' => ['بدي ملعب كورة قريب من منزله بكرا 8 بالليل'],
            'the family name الجبالي is not Jabalia' => ['احجز لابو الجبالي ملعب كورة بكرا 8 بالليل'],
        ];
    }

    #[DataProvider('wordsThatAreNotAPlace')]
    public function test_a_name_or_an_everyday_word_is_not_a_place(string $text): void
    {
        $this->seedMorePlaces();
        $parsed = $this->parse($text);

        $this->assertArrayNotHasKey('city', $parsed);
        $this->assertArrayNotHasKey('area', $parsed);
    }

    public static function coveredPlacesInOtherSpellings(): array
    {
        return [
            'khanyounis' => ['football tomorrow 6pm in khanyounis', 'city', 'Khan Younis'],
            'khanyunis' => ['football tomorrow 6pm in khanyunis', 'city', 'Khan Younis'],
            'khanyonis' => ['football tomorrow 6pm in khanyonis', 'city', 'Khan Younis'],
            'khan younes' => ['football tomorrow 6pm in khan younes', 'city', 'Khan Younis'],
            'ghazza' => ['football tomorrow 8pm in ghazza', 'city', 'Gaza'],
            'rafa7 (Arabizi digit)' => ['tennis el yom 5 el masa fe rafa7', 'city', 'Rafah'],
            'dair al balah' => ['football tomorrow 8pm in dair al balah', 'city', 'Deir al-Balah'],
            'الدير (the everyday name)' => ['ملعب طايرة بالدير الاثنين الساعة 6 المسا', 'city', 'Deir al-Balah'],
            "shuja'iyya" => ["football tomorrow 7pm in shuja'iyya", 'area', 'Shujaiya'],
            'tel al hawa' => ['football tomorrow 8pm in tel al hawa', 'area', 'Tal al-Hawa'],
            'tel al sultan' => ['tennis tomorrow 7pm in tel al sultan', 'area', 'Tal al-Sultan'],
            'remal' => ['mal3ab kora fi el remal yom el a7ad 9 bel lail', 'area', 'Al-Rimal'],
        ];
    }

    #[DataProvider('coveredPlacesInOtherSpellings')]
    public function test_a_covered_place_is_read_in_its_common_spellings(string $text, string $field, string $place): void
    {
        $this->seedMorePlaces();

        $this->assertSame($place, $this->parse($text)[$field] ?? null);
        $this->assertNull((new RuleBasedQueryParser)->unknownPlace($text));
    }

    public static function unknownPlaces(): array
    {
        return [
            'بالقدس' => ['كرة قدم بالقدس بكرا الساعة 6', 'القدس'],
            'في النصر' => ['في ملعب في النصر بكرا؟', 'النصر'],
            'بحي (quoted as the player spelt it)' => ['كورة بحي الصبرة بكرا', 'الصبرة'],
            'in cairo' => ['football in cairo tomorrow at 6pm', 'cairo'],
            'near nablus' => ['football near nablus tomorrow', 'nablus'],
            'in al nasr' => ['football in al nasr tomorrow', 'nasr'],
            'an area of two words after بال' => ['بدي ملعب كورة بالشيخ رضوان بكرا 8 بالليل', 'الشيخ رضوان'],
            'after بالقرب من' => ['بدي ملعب كورة بالقرب من النصيرات بكرا', 'النصيرات'],
            'after Arabizi fi' => ['football tomorrow 8pm fi nuseirat', 'nuseirat'],
            'after Arabizi fe' => ['bdi mal3ab koora fe bait lahia bokra 7', 'bait lahia'],
        ];
    }

    #[DataProvider('unknownPlaces')]
    public function test_notices_a_place_that_is_no_seeded_city_or_area(string $text, string $place): void
    {
        $this->assertSame($place, (new RuleBasedQueryParser)->unknownPlace($text));
    }

    public static function notPlaces(): array
    {
        return [
            'بالليل' => ['كورة بكرا الساعة 9 بالليل'],
            'في ملعب (is there)' => ['في ملعب فاضي بكرا؟'],
            'في بادل' => ['في بادل بكرا؟'],
            'in the evening' => ['football tomorrow in the evening'],
            'near me' => ['football near me tomorrow'],
            'a seeded city' => ['football in gaza tomorrow'],
            'a seeded area with a typo' => ['كرة قدم بالجلا بكرا'],
            'an area in English' => ['football at 7 in al jalaa'],
            'بالعربي' => ['احكيلي بالعربي، كورة بكرا'],
            'بالساعة' => ['كورة بكرا بالساعة 7'],
            'بالقرب من and a landmark' => ['بدي ملعب كورة بالقرب من الجامعة بكرا'],
            'بالقرب مني' => ['بدي ملعب كورة بالقرب مني بكرا'],
            'عند البيت' => ['بدي ملعب كورة عند البيت بكرا'],
            'قرب البحر' => ['بدي ملعب كورة قرب البحر بكرا'],
            'بالظبط' => ['بدي ملعب كورة بكرا الساعة 8 بالظبط'],
            'بالنهار' => ['بدي ملعب كورة بكرا بالنهار'],
            'بالكاش' => ['بدي ملعب كورة بكرا والدفع بالكاش'],
            'بالسعر المناسب' => ['بدي ملعب كورة بكرا بالسعر المناسب'],
            'بالحي' => ['بدي ملعب كورة بالحي بكرا'],
            'بالمخيم' => ['بدي ملعب كورة بالمخيم بكرا'],
            'near the university' => ['football near the university tomorrow'],
            'in the gym' => ['football tomorrow in the gym'],
            'around the corner' => ['football tomorrow around the corner'],
            'in two weeks' => ['football in two weeks'],
            'Arabizi fi ay' => ['fi ay mal3ab koora bokra'],
            'a covered city in another spelling' => ['football bokra 6 masa2 fi khanyounis'],
        ];
    }

    #[DataProvider('notPlaces')]
    public function test_ordinary_words_after_in_or_bal_are_not_places(string $text): void
    {
        $this->assertNull((new RuleBasedQueryParser)->unknownPlace($text));
    }

    public function test_after_midnight_tonight_at_1_is_the_current_day(): void
    {
        $parser = new RuleBasedQueryParser;

        // 00:30 on the 4th: "tonight at 1" is in half an hour.
        $early = $parser->parse('كورة الليلة الساعة 1', CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-04 00:30'));
        $this->assertSame(['2026-10-04', '01:00'], [$early['date'], $early['hour']]);

        // 22:00 on the 3rd: "tonight at 1" is after midnight, on the 4th.
        $evening = $parser->parse('كورة الليلة الساعة 1', CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2026-10-03 22:00'));
        $this->assertSame(['2026-10-04', '01:00'], [$evening['date'], $evening['hour']]);

        // "tomorrow at 1 at night" stays the night after tomorrow, whatever the time now.
        $tomorrow = $parser->parse('football tomorrow at 1 at night', CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-04 00:30'));
        $this->assertSame('2026-10-06', $tomorrow['date']);
    }

    public function test_hours_written_as_words_count_as_an_hour_for_gemini(): void
    {
        $parser = new RuleBasedQueryParser;
        $today = CarbonImmutable::parse('2026-10-03');

        foreach (['كورة بكرا الساعة ستة', 'بدي ملعب كورة بكرا عالسبعة', 'كرة قدم غداً الساعة السابعة مساءً', 'football tomorrow at seven pm', 'كرة قدم بكرا السادسة والنصف'] as $text) {
            $this->assertTrue($parser->mentionsTime($text, $today), $text);
        }

        foreach (['كرة قدم بكرا بعد المغرب', 'football tomorrow evening', 'كورة الليلة'] as $text) {
            $this->assertFalse($parser->mentionsTime($text, $today), $text);
        }

        $this->assertTrue($parser->mentionsDate('footy tmrw 9pm', $today));
        $this->assertTrue($parser->mentionsDate('fotball tomorow at 7pm', $today));
    }

    public function test_a_dotted_date_is_not_read_as_a_time(): void
    {
        $this->assertSame('كره قدم 12 10 2026', TextNormalizer::normalize('كرة قدم 12.10.2026'));
        $this->assertSame('football 6:30pm', TextNormalizer::normalize('Football 6.30pm'));
    }

    public function test_tells_whether_a_day_or_an_hour_was_given(): void
    {
        $parser = new RuleBasedQueryParser;
        $today = CarbonImmutable::parse('2026-10-03');

        $this->assertTrue($parser->mentionsDate('football tomorrow', $today));
        $this->assertTrue($parser->mentionsDate('كورة الجمعة', $today));
        $this->assertTrue($parser->mentionsDate('football on 12/10', $today));
        $this->assertTrue($parser->mentionsDate('football next week', $today));
        $this->assertTrue($parser->mentionsDate('football on the 12th', $today));
        $this->assertTrue($parser->mentionsDate('كورة 15 تشرين', $today));
        $this->assertTrue($parser->mentionsDate('هلق كورة', $today));
        $this->assertFalse($parser->mentionsDate('football at 6pm', $today));
        $this->assertFalse($parser->mentionsDate('بدي ملعب كرة قدم الساعة 6', $today));

        $this->assertTrue($parser->mentionsTime('football at 6pm', $today));
        $this->assertTrue($parser->mentionsTime('كورة الساعة ستة', $today));
        $this->assertTrue($parser->mentionsTime('football at seven', $today));
        $this->assertTrue($parser->mentionsTime('football at midnight', $today));
        $this->assertFalse($parser->mentionsTime('football tomorrow', $today));
        $this->assertFalse($parser->mentionsTime('football tomorrow evening', $today));
        $this->assertFalse($parser->mentionsTime('football on 2026-10-12', $today));
        $this->assertFalse($parser->mentionsTime('كورة بكرا بالجلاء', $today));
    }

    public function test_explicit_dates_are_not_read_as_hours(): void
    {
        $this->assertSame('19:00', $this->parse('football on 2026-10-12 at 7pm')['hour']);
        $this->assertSame('19:00', $this->parse('football 12/10 at 7')['hour']);
    }

    public static function sports(): array
    {
        return [
            'english name' => ['football tomorrow at 6', 'Football'],
            'english name any case' => ['FOOTBALL tomorrow at 6', 'Football'],
            'english alias' => ['soccer tomorrow at 6', 'Football'],
            'arabic name with article' => ['كرة القدم بكرا', 'Football'],
            'arabic name without article' => ['ملعب كرة قدم بكرا', 'Football'],
            'arabic with heh' => ['ملعب كره قدم بكرا', 'Football'],
            'arabic dialect كورة' => ['بدي كورة بكرا', 'Football'],
            'arabic فوتبول' => ['بدي فوتبول بكرا', 'Football'],
            'basketball arabic' => ['كرة سلة اليوم', 'Basketball'],
            'padel beats tennis in بادل تنس' => ['بادل تنس اليوم', 'Padel'],
            'tennis' => ['تنس اليوم', 'Tennis'],
        ];
    }

    #[DataProvider('sports')]
    public function test_recognizes_the_sport(string $text, string $sport): void
    {
        $this->assertSame($sport, $this->parse($text)['sport'] ?? null);
    }

    public static function sportSpellings(): array
    {
        return [
            'كرت قدم (construct-state spelling)' => ['بدي ملعب كرت قدم بكرا', 'Football'],
            'كرة قدن (a slip in the second word)' => ['بدي ملعب كرة قدن بكرا', 'Football'],
            'bball' => ['bball court day after tomorrow 7pm', 'Basketball'],
            'b-ball' => ['b-ball tomorrow 7pm', 'Basketball'],
            'باديل' => ['فيه ملعب باديل بكرا', 'Padel'],
            'تينس' => ['بدي ملعب تينس بكرا', 'Tennis'],
            'socer (one letter of a double dropped)' => ['socer tomorrow', 'Football'],
        ];
    }

    #[DataProvider('sportSpellings')]
    public function test_recognizes_common_sport_spellings(string $text, string $sport): void
    {
        $this->assertSame($sport, $this->parse($text)['sport'] ?? null);
    }

    public static function wordsThatAreNotASport(): array
    {
        return [
            'كورس (a course)' => ['بدي كورس سباحة بكرا الساعة 7'],
            'تنسى (forget)' => ['لا تنسى بكرا الساعة 7 المسا'],
            'نتبادل (swap)' => ['بدنا نتبادل الحجز بكرا الساعة 6 المسا'],
            'panel' => ['panel tomorrow 6pm'],
            'كرة يد (handball, not seeded)' => ['بدي ملعب كرة يد بكرا'],
            'كرة طاولة (table tennis, not seeded)' => ['بدي ملعب كرة طاولة بكرا'],
        ];
    }

    #[DataProvider('wordsThatAreNotASport')]
    public function test_a_word_containing_a_sport_name_is_not_that_sport(string $text): void
    {
        $this->makeSport('Volleyball', 'كرة الطائرة');

        $this->assertArrayNotHasKey('sport', $this->parse($text));
    }

    public function test_recognizes_cities_and_areas(): void
    {
        $this->assertSame('Gaza', $this->parse('football tomorrow at 6pm near Gaza City')['city']);
        $this->assertSame('Gaza', $this->parse('كرة قدم في غزة')['city']);
        $this->assertSame('Khan Younis', $this->parse('كرة قدم في خانيونس')['city']);
        $this->assertSame('Khan Younis', $this->parse('كرة قدم في خان يونس')['city']);
        $this->assertSame('الجلاء', $this->parse('كرة قدم بكرا بالجلاء')['area']);
        $this->assertSame('Al-Jalaa', $this->parse('football near al jalaa')['area']);
        $this->assertSame('الشجاعية', $this->parse('كرة قدم بالشجاعيه')['area']);
    }

    public function test_an_area_spelled_like_the_city_is_treated_as_the_city(): void
    {
        $parsed = $this->parse('football in gaza tomorrow');

        $this->assertSame('Gaza', $parsed['city']);
        $this->assertArrayNotHasKey('area', $parsed);
    }

    public function test_reads_a_full_arabic_request(): void
    {
        $this->assertSame([
            'sport' => 'Football',
            'date' => '2026-10-04',
            'hour' => '18:00',
            'area' => 'الجلاء',
        ], $this->parse('بدي ملعب كرة قدم بكرا الساعة 6 مساءً بالجلاء'));
    }

    public function test_leaves_out_what_it_cannot_find(): void
    {
        $this->assertSame([], $this->parse('hello'));
        $this->assertSame([], $this->parse(''));
    }

    public function test_no_day_or_hour_is_guessed(): void
    {
        $this->assertSame(['sport' => 'Football', 'hour' => '18:00'], $this->parse('football at 6pm'));
        $this->assertSame(['sport' => 'Football', 'date' => '2026-10-04'], $this->parse('football tomorrow'));
    }

    public function test_normalizer_unifies_arabic_letter_variants(): void
    {
        $this->assertSame('كره قدم بكره الساعه 8 مساء', TextNormalizer::normalize('كرةُ قدمٍ بكرة الساعة ٨ مساءً!'));
        $this->assertSame('العاب القوي', TextNormalizer::normalize(' ألعاب   القـوى '));
        $this->assertTrue(TextNormalizer::mentions('بدي ملعب بالجلاء', 'الجلاء'));
        $this->assertFalse(TextNormalizer::mentions('gazans', 'Gaza'));
    }

    public function test_a_dropped_hamza_seat_still_matches(): void
    {
        $this->makeSport('Volleyball', 'كرة الطائرة');

        $this->assertSame('كره طايره', TextNormalizer::normalize('كرة طائرة'));
        $this->assertSame('موجل', TextNormalizer::normalize('مؤجل'));
        $this->assertSame('Volleyball', $this->parse('كرة طايرة الخميس 5 العصر')['sport'] ?? null);
        $this->assertSame('Volleyball', $this->parse('كرة طائرة الخميس 5 العصر')['sport'] ?? null);
        $this->assertSame('Volleyball', $this->parse('بدي طايرة بكرا')['sport'] ?? null);
    }

    public function test_language_is_the_majority_script(): void
    {
        $this->assertTrue(TextNormalizer::isArabic('بدي ملعب كرة قدم بكرا'));
        $this->assertFalse(TextNormalizer::isArabic('football tomorrow at 6pm in الجلاء'));
        $this->assertTrue(TextNormalizer::isArabic('كرة قدم بكرا الساعة 6pm'));
        $this->assertFalse(TextNormalizer::isArabic('18:00'));
    }
}
