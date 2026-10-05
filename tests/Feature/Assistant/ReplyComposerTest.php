<?php

namespace Tests\Feature\Assistant;

use App\Services\Assistant\ReplyComposer;
use App\Services\Assistant\TextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReplyComposerTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private function replies(): ReplyComposer
    {
        return new ReplyComposer;
    }

    // ---------------------------------------------------------------- needs details

    public static function missingFields(): array
    {
        return [
            'hour' => [['hour'], 'What time would you like to play?', 'أي ساعة؟'],
            'day' => [['date'], 'Which day would you like to play?', 'أي يوم؟'],
            'sport' => [['sport'], 'Which sport would you like to play?', 'أي رياضة؟'],
            'sport and hour' => [['sport', 'hour'], 'Tell me the sport and hour you want, for example: "Football tomorrow at 6pm".', 'أخبرني بالرياضة والساعة، مثلاً: "كرة قدم بكرا الساعة 6 مساءً".'],
            'day and hour' => [['hour', 'date'], 'Tell me the day and hour you want, for example: "Football tomorrow at 6pm".', 'أخبرني باليوم والساعة، مثلاً: "كرة قدم بكرا الساعة 6 مساءً".'],
            'everything' => [['sport', 'date', 'hour'], 'Tell me the sport, day and hour you want, for example: "Football tomorrow at 6pm".', 'أخبرني بالرياضة واليوم والساعة، مثلاً: "كرة قدم بكرا الساعة 6 مساءً".'],
        ];
    }

    #[DataProvider('missingFields')]
    public function test_asks_only_for_what_is_missing(array $missing, string $english, string $arabic): void
    {
        $this->assertSame($english, $this->replies()->needsDetails($missing, false));
        $this->assertSame($arabic, $this->replies()->needsDetails($missing, true));
    }

    public function test_offers_up_to_three_free_hours_when_only_the_hour_is_missing(): void
    {
        $this->assertSame('What time would you like to play? Free that evening: 18:00, 19:00 and 21:00.', $this->replies()->needsDetails(['hour'], false, ['18:00', '19:00', '21:00']));
        $this->assertSame('أي ساعة؟ المتاح مساءً: 18:00، 19:00 و21:00.', $this->replies()->needsDetails(['hour'], true, ['18:00', '19:00', '21:00']));
        $this->assertSame('What time would you like to play? Free that evening: 20:00.', $this->replies()->needsDetails(['hour'], false, ['20:00']));
        // Free hours are only offered for a missing hour.
        $this->assertSame('Which day would you like to play?', $this->replies()->needsDetails(['date'], false, ['18:00']));
    }

    public function test_free_hours_are_introduced_by_the_part_of_the_day_asked_for(): void
    {
        $this->assertSame('أي ساعة؟ المتاح صباحاً: 09:00 و10:00.', $this->replies()->needsDetails(['hour'], true, ['09:00', '10:00'], 'morning'));
        $this->assertSame('What time would you like to play? Free that morning: 09:00 and 10:00.', $this->replies()->needsDetails(['hour'], false, ['09:00', '10:00'], 'morning'));
        $this->assertSame('أي ساعة؟ المتاح بعد الظهر: 15:00.', $this->replies()->needsDetails(['hour'], true, ['15:00'], 'afternoon'));
        $this->assertSame('What time would you like to play? Free that afternoon: 15:00.', $this->replies()->needsDetails(['hour'], false, ['15:00'], 'afternoon'));
    }

    public function test_the_arabic_suggestion_reads_right_for_any_venue_name(): void
    {
        $this->seedRoles();
        $today = CarbonImmutable::parse('2026-10-03');

        foreach (['صالة خانيونس الرياضية', 'ساحة الشجاعية', 'ملعب الحقل الأخضر'] as $name) {
            $slot = $this->makeSlot($this->makeVenue(null, ['name_ar' => $name]), ['slot_date' => '2026-10-04'])->load('venue');

            $this->assertSame("يتوفر موعد في {$name} غداً الساعة 18:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟", $this->replies()->suggestion($slot, $today, true));
        }
    }

    public function test_the_day_is_compared_in_the_players_timezone(): void
    {
        // At 00:30 in Gaza (21:30 UTC the day before), a 01:00 slot that night is today.
        $this->seedRoles();
        $slot = $this->makeSlot($this->makeVenue(), ['slot_date' => '2026-10-04', 'start_time' => '01:00:00', 'end_time' => '02:00:00'])->load('venue');
        $today = CarbonImmutable::parse('2026-10-04 00:30', 'Asia/Gaza')->startOfDay();

        $this->assertSame('Green Field Court is available today at 01:00 for 40 ILS/hour. Want me to book it?', $this->replies()->suggestion($slot, $today, false));
        $this->assertSame('يتوفر موعد في ملعب الحقل الأخضر اليوم الساعة 01:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟', $this->replies()->suggestion($slot, $today, true));
    }

    // ---------------------------------------------------------------- not covered

    public function test_says_a_city_or_area_is_not_covered(): void
    {
        $this->assertSame(
            "We don't cover Cairo yet — we currently cover: Gaza, Khan Younis and Rafah.",
            $this->replies()->notCovered('Cairo', ['Gaza', 'Khan Younis', 'Rafah'], false),
        );
        $this->assertSame(
            'لا نغطي القدس بعد — نغطي حالياً: غزة، خانيونس ورفح.',
            $this->replies()->notCovered('القدس', ['غزة', 'خانيونس', 'رفح'], true),
        );
        $this->assertSame("I couldn't find a venue in Al-Nasr — try another area, or leave the area out.", $this->replies()->unknownArea('Al-Nasr', false));
        $this->assertSame('لم أجد ملعباً في النصر — جرّب منطقة أخرى أو اطلب بدون تحديد المنطقة.', $this->replies()->unknownArea('النصر', true));
    }

    // ---------------------------------------------------------------- after midnight

    public static function midnightSlots(): array
    {
        // Saturday 2026-10-03.
        return [
            'tonight' => ['2026-10-04', '00:00:00', 'Green Field Court is available tonight after midnight (Sun 4 Oct) at 00:00 for 40 ILS/hour. Want me to book it?', 'يتوفر موعد في ملعب الحقل الأخضر الليلة بعد منتصف الليل (الأحد 4 أكتوبر) الساعة 00:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟'],
            'tomorrow night' => ['2026-10-05', '01:00:00', 'Green Field Court is available tomorrow night after midnight (Mon 5 Oct) at 01:00 for 40 ILS/hour. Want me to book it?', 'يتوفر موعد في ملعب الحقل الأخضر بكرا بالليل بعد منتصف الليل (الإثنين 5 أكتوبر) الساعة 01:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟'],
            'later' => ['2026-10-09', '00:00:00', 'Green Field Court is available after midnight (Fri 9 Oct) at 00:00 for 40 ILS/hour. Want me to book it?', 'يتوفر موعد في ملعب الحقل الأخضر بعد منتصف الليل (الجمعة 9 أكتوبر) الساعة 00:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟'],
            'today after midnight is just today' => ['2026-10-03', '01:00:00', 'Green Field Court is available today at 01:00 for 40 ILS/hour. Want me to book it?', 'يتوفر موعد في ملعب الحقل الأخضر اليوم الساعة 01:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟'],
            'evening is unchanged' => ['2026-10-04', '18:00:00', 'Green Field Court is available tomorrow at 18:00 for 40 ILS/hour. Want me to book it?', 'يتوفر موعد في ملعب الحقل الأخضر غداً الساعة 18:00 بسعر 40 شيكل للساعة. هل تريد أن أحجزه لك؟'],
        ];
    }

    #[DataProvider('midnightSlots')]
    public function test_a_slot_after_midnight_is_said_as_the_night_before(string $date, string $start, string $english, string $arabic): void
    {
        $this->seedRoles();
        $end = sprintf('%02d:00:00', ((int) substr($start, 0, 2) + 1) % 24);
        $slot = $this->makeSlot($this->makeVenue(), ['slot_date' => $date, 'start_time' => $start, 'end_time' => $end])->load('venue');
        $today = CarbonImmutable::parse('2026-10-03');

        $this->assertSame($english, $this->replies()->suggestion($slot, $today, false));
        $this->assertSame($arabic, $this->replies()->suggestion($slot, $today, true));
    }

    // ---------------------------------------------------------------- the normaliser's tolerance

    public function test_edit_distance_counts_characters_and_swaps(): void
    {
        $this->assertSame(1, TextNormalizer::distance('padel', 'padle'));
        $this->assertSame(1, TextNormalizer::distance('كورا', 'كوره'));
        $this->assertSame(2, TextNormalizer::distance('tommorow', 'tomorrow'));
        $this->assertSame(0, TextNormalizer::distance('غزه', 'غزه'));
    }

    public function test_a_typo_keeps_the_word_recognisable(): void
    {
        $this->assertSame(1, TextNormalizer::wordDistance('fotball', 'football'));
        $this->assertSame(2, TextNormalizer::wordDistance('tommorow', 'tomorrow'));
        $this->assertSame(0, TextNormalizer::wordDistance('بالجلا', 'جلا'));
        $this->assertSame(1, TextNormalizer::wordDistance('tenns', 'tennis'));
        $this->assertSame(1, TextNormalizer::wordDistance('padle', 'padel'), 'two neighbours swapped');
        $this->assertSame(1, TextNormalizer::wordDistance('شجعيه', 'شجاعيه'));
        $this->assertNull(TextNormalizer::wordDistance('سلم', 'سله'), 'too short');
        $this->assertNull(TextNormalizer::wordDistance('rootball', 'football'), 'another first letter');
        $this->assertNull(TextNormalizer::wordDistance('fooball5x', 'football'), 'too far');
        // Short names and changed endings make other words: a name, a suffix.
        $this->assertNull(TextNormalizer::wordDistance('jalal', 'jalaa'));
        $this->assertNull(TextNormalizer::wordDistance('panel', 'padel'));
        $this->assertNull(TextNormalizer::wordDistance('رجال', 'رمال'));
        $this->assertNull(TextNormalizer::wordDistance('جبالي', 'جباليا'));
        $this->assertNull(TextNormalizer::wordDistance('jabali', 'jabalia'));
    }

    public function test_loose_forms_fold_arabic_word_endings(): void
    {
        $this->assertSame(TextNormalizer::loose('الجلاء'), TextNormalizer::loose('الجلا'));
        $this->assertSame(TextNormalizer::loose('تل الهوى'), TextNormalizer::loose('تل الهوا'));
        $this->assertSame(TextNormalizer::loose('كرة الطائرة'), TextNormalizer::loose('كره الطايره'));
        $this->assertSame(TextNormalizer::loose('كورة'), TextNormalizer::loose('كورا'));
        $this->assertTrue(TextNormalizer::mentions('كرة قدم بالجلا بكرا', 'الجلاء'));
        $this->assertSame(1, TextNormalizer::closeness('football in shejaiya', 'Shujaiya'));
        $this->assertNull(TextNormalizer::closeness('football in rafah', 'Shujaiya'));
    }

    public function test_arabic_names_are_matched_as_whole_words(): void
    {
        $this->assertTrue(TextNormalizer::mentions('كرة قدم بخان يونس', 'خانيونس'));
        $this->assertTrue(TextNormalizer::mentions('كرة قدم بدير البلح', 'دير البلح'));
        $this->assertTrue(TextNormalizer::mentions('بدي ملعب للسلة', 'سلة'));
        $this->assertFalse(TextNormalizer::mentions('أنا جلال', 'الجلاء'));
        $this->assertFalse(TextNormalizer::mentions('لا تنسى', 'تنس'));
        $this->assertFalse(TextNormalizer::mentions('بدنا نتبادل', 'بادل'));
        $this->assertFalse(TextNormalizer::mentions('بدي ملعب للرجال', 'الرمال'));
    }

    public function test_a_name_is_recognised_in_its_common_spellings(): void
    {
        foreach (['khanyounis', 'khan yunis', 'khanyonis', 'khan younes'] as $spelling) {
            $this->assertTrue(TextNormalizer::spells("football in {$spelling}", 'Khan Younis'), $spelling);
        }

        $this->assertTrue(TextNormalizer::spells("football in shuja'iyya", 'Shujaiya'));
        $this->assertTrue(TextNormalizer::spells('football in tel al-hawa', 'Tal al-Hawa'));
        $this->assertTrue(TextNormalizer::spells('football in ghazza', 'Gaza'));
        $this->assertTrue(TextNormalizer::spells('fe rafa7', 'Rafah'));
        $this->assertFalse(TextNormalizer::spells('hi I am jalal', 'Al-Jalaa'));
        $this->assertFalse(TextNormalizer::spells('hi I am bassam', 'Al-Bassa'));
        $this->assertFalse(TextNormalizer::spells('for rafat', 'Rafah'));
        $this->assertFalse(TextNormalizer::spells('football in nuseirat', 'Gaza'));
    }

    public function test_one_short_word_of_a_longer_name_may_be_one_letter_off(): void
    {
        $this->assertSame(1, TextNormalizer::closeness('ملعب كرت قدم بكرا', 'كرة قدم'));
        $this->assertNull(TextNormalizer::closeness('ملعب كرت شحن بكرا', 'كرة قدم'), 'both words off');
        $this->assertNull(TextNormalizer::closeness('كرت', 'كرة'), 'a one-word name');
        // A known word in its own sense is never read as a typo.
        $this->assertNull(TextNormalizer::closeness('football tusday', 'tuesday', ['tusday']));
    }
}
