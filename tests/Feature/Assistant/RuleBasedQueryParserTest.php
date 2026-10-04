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
            'no date means today' => ['football at 6pm', '2026-10-03'],
            'today' => ['football today at 6pm', '2026-10-03'],
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
        $this->assertSame(['date' => '2026-10-03'], $this->parse('hello'));
        $this->assertSame(['date' => '2026-10-03'], $this->parse(''));
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
