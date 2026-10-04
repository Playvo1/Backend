<?php

namespace Tests\Feature\Assistant;

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

    public function test_language_is_the_majority_script(): void
    {
        $this->assertTrue(TextNormalizer::isArabic('بدي ملعب كرة قدم بكرا'));
        $this->assertFalse(TextNormalizer::isArabic('football tomorrow at 6pm in الجلاء'));
        $this->assertTrue(TextNormalizer::isArabic('كرة قدم بكرا الساعة 6pm'));
        $this->assertFalse(TextNormalizer::isArabic('18:00'));
    }
}
