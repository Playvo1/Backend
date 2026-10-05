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
 * short Arabic, English and Arabizi requests: sport names from the SPORT table plus common
 * synonyms and typos, today/tomorrow/weekdays (اليوم، بكرا، بعد بكرا، الجمعة, bukra,
 * tmrw...), explicit dates, hours such as "8", "6pm", "6.30pm", "18:30", "6 المسا",
 * "الساعة ستة", "عالتمانية", "السابعة مساءً", "6 ونص", "6 الا ربع", "at seven" or
 * "12 بالليل", and city/area names from the CITY and VENUE tables.
 *
 * A date or hour is only returned when the text gives one: the assistant asks for the
 * missing detail instead of guessing.
 */
class RuleBasedQueryParser implements QueryParser
{
    /**
     * Extra ways players name a sport, keyed by SPORT.name_en (lower case), written in
     * normalised form (ة as ه, no hamza on alef). Small typos of these are accepted too.
     */
    private const SPORT_ALIASES = [
        'football' => ['soccer', 'futsal', 'footy', 'kora', 'koora', 'kura', 'كوره', 'كوره قدم', 'كره قدم', 'فوتبول', 'فطبول', 'خماسي', 'خماسيات'],
        'basketball' => ['basket', 'basket ball', 'باسكت', 'باسكيت', 'باسكت بول', 'كره سله', 'سله'],
        'volleyball' => ['volley', 'volley ball', 'فولي', 'فولي بول', 'كره طايره', 'طايره'],
        'tennis' => ['تنس', 'تنس ارضي'],
        'padel' => ['paddle', 'بادل', 'بادل تنس'],
    ];

    private const DAY_AFTER_TOMORROW = ['day after tomorrow', 'بعد بكرا', 'بعد بكره', 'بعد غد', 'بعد غدا'];

    private const TOMORROW = ['tomorrow', 'بكرا', 'بكره', 'بكري', 'غدا', 'الغد', 'غد'];

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
     * English day words that are also read with a small typo ("tomorow", "thursdy").
     */
    private const LATIN_DAY_WORDS = ['tomorrow', 'today', 'tonight', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * Slang and Arabizi (Arabic in Latin letters, with digits as letters: 3 = ع, 7 = ح...)
     * rewritten to the words the rules below understand. Applied to normalised text.
     */
    private const REWRITES = [
        '/(?<![a-z\d])(?:5|five)\s*a\s*side(?![a-z])/' => 'futsal',
        '/(?<![a-z\d])(?:tmrw|tmrow|tmr|2moro|2morrow|2morow|2mrw|2mrrw|tomoz)(?![a-z\d])/' => 'tomorrow',
        '/(?<![a-z\d])(?:2nite|2night|tonite)(?![a-z\d])/' => 'tonight',
        '/(?<![a-z\d])(?:ba3d|ba3ed|b3d)\s*(?:bukra|bokra|bukrah|bokrah|bukura)(?![a-z\d])/' => 'بعد بكرا',
        '/(?<![a-z\d])(?:bukra|bokra|bukrah|bokrah|bukura)(?![a-z\d])/' => 'بكرا',
        '/(?<![a-z\d])(?:el|al|il)\s?(?:yom|youm|yoom)(?![a-z\d])/' => 'اليوم',
        '/(?<![a-z\d])(?:el|al|il)\s?(?:leila|lela|layla|leile)(?![a-z\d])/' => 'الليله',
        '/(?<![a-z\d])(?:halla2|hala2|hal2|hallaq)(?![a-z\d])/' => 'هلق',
        '/(?<![a-z\d])(?:el\s?)?(?:jum3a|jom3a|jum3ah|jome3)(?![a-z\d])/' => 'الجمعه',
        '/(?<![a-z\d])(?:el\s?)?(?:5amis|5ames|khamis|khamees)(?![a-z\d])/' => 'الخميس',
        '/(?<![a-z\d])(?:el\s?)?(?:sabt)(?![a-z\d])/' => 'السبت',
        '/(?<![a-z\d])(?:el\s?)?(?:a7ad|ahad)(?![a-z\d])/' => 'الاحد',
        '/(?<![a-z\d])(?:el\s?)?(?:itnen|etnen|etnein|itnein|tanen)(?![a-z\d])/' => 'الاتنين',
        '/(?<![a-z\d])(?:el\s?)?(?:tlata|talata|tlatha)(?![a-z\d])/' => 'الثلاثاء',
        '/(?<![a-z\d])(?:el\s?)?(?:arb3a|arba3a|arbe3a)(?![a-z\d])/' => 'الاربعاء',
        '/(?<![a-z\d])(?:el\s?|bel\s?|bil\s?)?(?:masa|mesa)(?![a-z\d])/' => 'المسا',
        '/(?<![a-z\d])(?:el\s?|bel\s?|bil\s?)?(?:sob7|sub7|subh|sobh)(?![a-z\d])/' => 'الصبح',
        '/(?<![a-z\d])(?:bel|bil|b|el)\s?(?:lail|leil|lel|layl)(?![a-z\d])/' => 'بالليل',
        '/(?<![a-z\d])(?:el\s?)?3asr(?![a-z\d])/' => 'العصر',
        '/(?<![a-z\d])(?:w|we|wa)\s?(?:nos|nus|nuss|noss)(?![a-z\d])/' => 'ونص',
        '/(?<![a-z\d])(?:w|we|wa)\s?(?:rob3|rub3|roba3)(?![a-z\d])/' => 'وربع',
        '/(?<![a-z\d])(?:illa|ella|ila)\s?(?:rob3|rub3|roba3)(?![a-z\d])/' => 'الا ربع',
        // "3al 7", "3ala 7", "el sa3a 7": Arabizi "at" before an hour.
        '/(?<![a-z\d])(?:3al|3ala|3a|3l|(?:el\s?)?sa3a)\s*(?=\d)/' => 'at ',
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
        'و النصف' => 30, 'والنصف' => 30, 'و نصف' => 30, 'ونصف' => 30, 'والنص' => 30, 'و نص' => 30, 'ونص' => 30,
        'والربع' => 15, 'و ربع' => 15, 'وربع' => 15,
        'والثلث' => 20, 'و ثلث' => 20, 'وثلث' => 20,
        'الا الربع' => -15, 'الا ربع' => -15, 'الا الثلث' => -20, 'الا ثلث' => -20,
    ];

    /**
     * English "half past 6", "quarter past 6" and "quarter to 7".
     */
    private const ENGLISH_MINUTES = ['half past' => 30, 'quarter past' => 15, 'quarter to' => -15];

    /**
     * Hours written as words, in normalised form.
     */
    private const ARABIC_HOURS = [
        1 => ['واحده', 'وحده'],
        2 => ['ثنتين', 'اثنتين', 'اثنين', 'اتنين', 'تنتين'],
        3 => ['ثلاث', 'ثلاثه', 'تلات', 'تلاته'],
        4 => ['اربع', 'اربعه'],
        5 => ['خمس', 'خمسه'],
        6 => ['ست', 'سته'],
        7 => ['سبع', 'سبعه'],
        8 => ['ثمان', 'ثماني', 'ثمانيه', 'تمان', 'تماني', 'تمانيه', 'تمنه', 'ثمنه'],
        9 => ['تسع', 'تسعه'],
        10 => ['عشر', 'عشره'],
        11 => ['احدعش', 'احداش', 'حداش', 'حدعش', 'احدي عشر', 'احدي عشره', 'احد عشر'],
        12 => ['اطناش', 'اطنعش', 'اتناش', 'اتنعش', 'اثنعش', 'ثنعش', 'طنعش', 'اثنا عشر', 'اثني عشر', 'اثنتا عشره', 'اثنتي عشره'],
    ];

    /**
     * Formal ordinal hours ("الساعة السابعة"), without their article.
     */
    private const ARABIC_ORDINAL_HOURS = [
        1 => ['واحده'], 2 => ['ثانيه'], 3 => ['ثالثه'], 4 => ['رابعه'], 5 => ['خامسه'], 6 => ['سادسه'],
        7 => ['سابعه'], 8 => ['ثامنه'], 9 => ['تاسعه'], 10 => ['عاشره'],
        11 => ['حاديه عشر', 'حاديه عشره'], 12 => ['ثانيه عشر', 'ثانيه عشره'],
    ];

    private const ENGLISH_HOURS = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
        7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve',
    ];

    /**
     * Arabic prefixes meaning "at" before an hour written as a word (عالسبعة، ع السبعة، على ستة),
     * in normalised form (على is written علي).
     */
    private const AT_PREFIXES = ['عال', 'ع', 'علي', 'بال'];

    /**
     * Words after which a place is named, and words that follow them without being a place.
     */
    private const PLACE_MARKERS = ['in', 'near', 'around', 'في', 'عند', 'قرب', 'جنب', 'جمب', 'حي', 'بحي', 'منطقه', 'بمنطقه', 'مدينه', 'بمدينه'];

    private const NOT_PLACES = [
        'the', 'a', 'an', 'my', 'me', 'this', 'that', 'some', 'any', 'one', 'court', 'courts', 'pitch', 'pitches', 'field', 'fields',
        'stadium', 'club', 'hall', 'venue', 'place', 'time', 'hour', 'hours', 'morning', 'evening', 'afternoon', 'night', 'week',
        'weekend', 'city', 'area', 'town', 'center', 'centre', 'english', 'arabic',
        'ملعب', 'ملاعب', 'مكان', 'اماكن', 'وقت', 'ساعه', 'حدا', 'اشي', 'شي', 'حجز', 'مجال', 'امكانيه', 'نفس', 'هاد', 'هذا', 'هاي',
        'ليل', 'مسا', 'مساء', 'صبح', 'صباح', 'عصر', 'ظهر', 'مغرب', 'عشا', 'فجر', 'ليله', 'نادي', 'صاله', 'منطقه', 'مدينه',
        'عربي', 'انجليزي', 'انكليزي', 'له', 'لله', 'ضبط', 'زبط', 'نسبه', 'مره', 'دور', 'توفيق', 'كثير',
    ];

    public function parse(string $queryText, CarbonImmutable $today, ?CarbonImmutable $now = null): array
    {
        $today = $today->startOfDay();
        $text = $this->prepare($queryText);
        [$date, $textWithoutDates] = $this->extractDate($text, $today);
        $time = $this->extractTime($textWithoutDates);

        // "اليوم الساعة 12 بالليل" is the next calendar day at 00:00, except that between
        // 00:00 and 05:00 "tonight at 1" is the night already under way: today.
        $stillTonight = $now !== null && $now->hour < 5 && $date === $today->toDateString();

        if ($date !== null && $time !== null && $time['days'] !== 0 && ! ($stillTonight && $time['days'] === 1)) {
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
        $text = $this->prepare($queryText);

        return $this->extractDate($text, $today->startOfDay())[0] !== null
            || $this->containsAnyWord($text, self::OTHER_DATE_WORDS)
            || preg_match('/(?<![a-z\d])\d{1,2}(?:st|nd|rd|th)(?![a-z])/', $text) === 1;
    }

    /**
     * Whether the text gives an hour at all, even one only Gemini can read. Digits inside
     * an Arabizi word ("mal3ab") are letters, not an hour; vague words such as "evening"
     * or "بعد المغرب" are not an hour either.
     */
    public function mentionsTime(string $queryText, CarbonImmutable $today): bool
    {
        [, $text] = $this->extractDate($this->prepare($queryText), $today->startOfDay());

        return $this->extractTime($text) !== null
            || $this->containsAnyWord($text, [
                ...self::ENGLISH_HOURS, 'oclock', 'o clock', ...self::MIDNIGHT_WORDS, ...self::NOON_WORDS,
                ...array_merge(...array_values(self::ARABIC_HOURS)),
                ...array_map(fn (string $word) => 'ال'.$word, array_merge(...array_values(self::ARABIC_ORDINAL_HOURS))),
            ]);
    }

    /**
     * A place the player named that is no seeded city, area or venue ("بالقدس", "in Cairo"),
     * so the assistant can say it isn't covered instead of suggesting somewhere else. Only
     * clear cases are reported: "بال..." or "في ال..." in Arabic, "in"/"near" in English.
     */
    public function unknownPlace(string $queryText): ?string
    {
        $words = explode(' ', $this->prepare($queryText));
        $known = $this->knownWords();

        foreach ($words as $index => $word) {
            $place = match (true) {
                mb_strlen($word) >= 5 && str_starts_with($word, 'بال') => [mb_substr($word, 1)],
                in_array($word, self::PLACE_MARKERS, true) => array_slice($words, $index + 1, 3),
                default => [],
            };

            // Skip English articles. In Arabic "في" also means "is there" ("في ملعب؟"), so
            // a place needs its article unless a place noun (حي، منطقة، مدينة) comes first.
            if ($place !== [] && in_array($place[0], ['the', 'al', 'el'], true)) {
                array_shift($place);
            }

            $placeNoun = in_array($word, ['حي', 'بحي', 'منطقه', 'بمنطقه', 'مدينه', 'بمدينه'], true);

            if ($place === [] || (preg_match('/^\p{Arabic}/u', $place[0]) === 1 && ! str_starts_with($place[0], 'ال') && ! $placeNoun)) {
                continue;
            }

            $phrase = [];

            foreach ($place as $candidate) {
                if (preg_match('/\d/', $candidate) === 1 || in_array($candidate, self::PLACE_MARKERS, true) || $this->isKnownWord($candidate, $known)) {
                    break;
                }

                $phrase[] = $candidate;
            }

            if ($phrase !== []) {
                return $this->originalSpelling($queryText, implode(' ', $phrase));
            }
        }

        return null;
    }

    /**
     * The words of $queryText that normalise to $phrase, so the reply quotes the player's own
     * spelling ("الصبرة", "Cairo"), without a glued "ب".
     */
    private function originalSpelling(string $queryText, string $phrase): string
    {
        $words = preg_split('/[\s\p{P}]+/u', $queryText, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $length = count(explode(' ', $phrase));

        for ($start = 0; $start + $length <= count($words); $start++) {
            $window = implode(' ', array_slice($words, $start, $length));
            $normalized = TextNormalizer::normalize($window);

            if ($normalized === $phrase) {
                return $window;
            }

            if ($normalized === 'ب'.$phrase) {
                return mb_substr($window, 1);
            }
        }

        return $phrase;
    }

    /**
     * normalize(), then slang, Arabizi and English day typos rewritten, and hours written
     * as words turned into digits.
     */
    private function prepare(string $queryText): string
    {
        $text = TextNormalizer::normalize($queryText);

        foreach (self::REWRITES as $pattern => $replacement) {
            $text = preg_replace($pattern.'u', $replacement, $text) ?? $text;
        }

        $text = preg_replace_callback('/(?<![a-z\d])[a-z]{4,}(?![a-z\d])/u', fn (array $m) => $this->closestDayWord($m[0]) ?? $m[0], $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $this->hoursFromWords($text)) ?? $text);
    }

    private function closestDayWord(string $word): ?string
    {
        $known = [...self::LATIN_DAY_WORDS, ...array_keys(self::ENGLISH_MINUTES)];

        if (in_array($word, $known, true)) {
            return null;
        }

        $distances = [];

        foreach (self::LATIN_DAY_WORDS as $day) {
            $distance = TextNormalizer::wordDistance($word, $day);

            if ($distance !== null) {
                $distances[$day] = $distance;
            }
        }

        asort($distances);

        // Only a clear best guess: "tusday" is closer to tuesday than to thursday.
        return $distances !== [] && count(array_keys($distances, reset($distances), true)) === 1 ? array_key_first($distances) : null;
    }

    /**
     * "الساعة ستة", "عالتمانية", "السابعة", "ستة المسا", "at seven" and "seven pm" become
     * digits the hour rules read. A number word only counts in a time context (after a
     * time marker or an "at" prefix, before a time-of-day word, or as a formal ordinal),
     * so "عشرة شباب" is not ten o'clock.
     */
    private function hoursFromWords(string $text): string
    {
        $cardinals = $this->wordsToHours(self::ARABIC_HOURS);
        $ordinals = $this->wordsToHours(self::ARABIC_ORDINAL_HOURS);
        $english = array_flip(self::ENGLISH_HOURS);
        $cardinal = $this->alternation(array_keys($cardinals));
        $ordinal = $this->alternation(array_keys($ordinals));
        $englishWords = $this->alternation(array_keys($english));
        $markers = $this->alternation(self::HOUR_MARKERS);
        $atPrefix = $this->alternation(self::AT_PREFIXES);
        $timeOfDay = $this->alternation([...self::PM_WORDS, ...self::AM_WORDS, ...self::NIGHT_WORDS, ...array_keys(self::SPOKEN_MINUTES)]);
        $atHour = fn (int $hour) => ' الساعه '.$hour.' ';

        $rules = [
            // Formal ordinals: "السابعة", "عالسابعة", "على السابعة". الواحدة/الثانية need a time context.
            '/(?<![\p{L}])(?:[وبلف]?ال|عال|بال|ع\s?ال|علي\s?ال)('.$ordinal.')(?![\p{L}])/u' => function (array $m) use ($ordinals, $atHour) {
                return in_array($m[1], ['واحده', 'ثانيه'], true) ? $m[0] : $atHour($ordinals[$m[1]]);
            },
            '/(?<![\p{L}])((?:'.$markers.')\s*)(?:ال)?('.$ordinal.')(?![\p{L}])/u' => fn (array $m) => $atHour($ordinals[$m[2]]),
            // "عالتمانية", "ع السبعة", "على ستة", "بالسبعة".
            '/(?<![\p{L}])(?:'.$atPrefix.')\s?(?:ال)?('.$cardinal.')(?![\p{L}])/u' => fn (array $m) => $atHour($cardinals[$m[1]]),
            // "الساعة ستة".
            '/(?<![\p{L}])((?:'.$markers.')\s*)(?:ال)?('.$cardinal.')(?![\p{L}])/u' => fn (array $m) => $atHour($cardinals[$m[2]]),
            // "ستة المسا", "سبعة ونص".
            '/(?<![\p{L}])(?:ال)?('.$cardinal.')(?=\s*[وبلف]?(?:'.$timeOfDay.')(?![\p{L}]))/u' => fn (array $m) => ' '.$cardinals[$m[1]].' ',
            // "at seven", "half past seven".
            '/(?<![a-z])(at|around|by|about|half past|quarter past|quarter to)\s+('.$englishWords.')(?![a-z])/u' => fn (array $m) => " {$m[1]} {$english[$m[2]]} ",
            // "seven pm", "seven in the evening", "twelve at night".
            '/(?<![a-z])('.$englishWords.')(?=\s*(?:am|pm|o\s?clock|in the (?:morning|evening|afternoon)|at night|tonight)(?![a-z]))/u' => fn (array $m) => ' '.$english[$m[1]].' ',
        ];

        foreach ($rules as $pattern => $replace) {
            $text = preg_replace_callback($pattern, $replace, $text) ?? $text;
        }

        return $text;
    }

    /**
     * @param  array<int, list<string>>  $hours
     * @return array<string, int>
     */
    private function wordsToHours(array $hours): array
    {
        $words = [];

        foreach ($hours as $hour => $spellings) {
            foreach ($spellings as $spelling) {
                $words[$spelling] = $hour;
            }
        }

        return $words;
    }

    private function matchCity(string $text): ?string
    {
        $candidates = City::query()->get(['name_ar', 'name_en'])->flatMap(fn (City $city) => [
            ['name' => $city->name_en, 'alias' => $city->name_ar],
            ['name' => $city->name_en, 'alias' => $city->name_en],
        ]);

        return $this->bestMention($text, $candidates);
    }

    private function matchSport(string $text): ?string
    {
        return $this->bestMention($text, $this->sportCandidates());
    }

    /**
     * @return Collection<int, array{name: string, alias: string}>
     */
    private function sportCandidates(): Collection
    {
        return Sport::query()->get(['name_ar', 'name_en'])->flatMap(fn (Sport $sport) => collect([
            $sport->name_ar,
            $sport->name_en,
            ...self::SPORT_ALIASES[mb_strtolower($sport->name_en)] ?? [],
        ])->map(fn (string $alias) => ['name' => $sport->name_en, 'alias' => $alias]));
    }

    /**
     * Areas are free text on VENUE, so the known ones are read from active venues. An area
     * spelled like a city is skipped: "Gaza" means the city, not one venue's area field.
     */
    private function matchArea(string $text): ?string
    {
        return $this->bestMention($text, $this->areaCandidates());
    }

    /**
     * @return Collection<int, array{name: string, alias: string}>
     */
    private function areaCandidates(): Collection
    {
        $cityNames = City::query()->get(['name_ar', 'name_en'])
            ->flatMap(fn (City $city) => [TextNormalizer::loose($city->name_ar), TextNormalizer::loose($city->name_en)])
            ->all();

        return Venue::query()->where('status', 'active')->get(['area_ar', 'area_en'])
            ->flatMap(fn (Venue $venue) => [$venue->area_ar, $venue->area_en])
            ->filter(fn (?string $area) => filled($area) && ! in_array(TextNormalizer::loose($area), $cityNames, true))
            ->unique()
            ->values()
            ->map(fn (string $area) => ['name' => $area, 'alias' => $area]);
    }

    /**
     * The longest exact mention wins; otherwise the closest near-miss (a typo), but only
     * when one name is clearly closest, so a typo never silently picks a different place.
     *
     * @param  Collection<int, array{name: string, alias: string}>  $candidates
     */
    private function bestMention(string $text, Collection $candidates): ?string
    {
        $exact = $candidates
            ->sortByDesc(fn (array $candidate) => mb_strlen(TextNormalizer::loose($candidate['alias'])))
            ->first(fn (array $candidate) => TextNormalizer::mentions($text, $candidate['alias']));

        if ($exact !== null) {
            return $exact['name'];
        }

        $close = $candidates
            ->map(fn (array $candidate) => [...$candidate, 'distance' => TextNormalizer::closeness($text, $candidate['alias'])])
            ->whereNotNull('distance');

        if ($close->isEmpty()) {
            return null;
        }

        $best = $close->min('distance');
        $names = $close->where('distance', $best)->pluck('name')->unique();

        return $names->count() === 1 ? $names->first() : null;
    }

    /**
     * Every word the parser knows, so it isn't taken for an unknown place.
     *
     * @return list<string>
     */
    private function knownWords(): array
    {
        $names = [
            ...$this->sportCandidates()->pluck('alias')->all(),
            ...$this->areaCandidates()->pluck('alias')->all(),
            ...City::query()->get(['name_ar', 'name_en'])->flatMap(fn (City $city) => [$city->name_ar, $city->name_en])->all(),
            ...Venue::query()->get(['name_ar', 'name_en'])->flatMap(fn (Venue $venue) => [$venue->name_ar, $venue->name_en])->all(),
            ...self::DAY_AFTER_TOMORROW, ...self::TOMORROW, ...self::TODAY, ...self::NOW, ...self::OTHER_DATE_WORDS,
            ...array_merge(...array_values(self::WEEKDAYS)),
            ...self::NIGHT_WORDS, ...self::MIDNIGHT_WORDS, ...self::NOON_WORDS, ...self::PM_WORDS, ...self::AM_WORDS,
            ...self::NOT_PLACES,
        ];

        return array_values(array_unique(array_filter(explode(' ', TextNormalizer::loose(implode(' ', $names))))));
    }

    /**
     * @param  list<string>  $known
     */
    private function isKnownWord(string $word, array $known): bool
    {
        $word = TextNormalizer::loose($word);

        if ($word === '' || in_array($word, $known, true)) {
            return true;
        }

        foreach ($known as $knownWord) {
            if (TextNormalizer::wordDistance($word, $knownWord) !== null) {
                return true;
            }
        }

        return false;
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
     * Numbers next to Latin letters ("mal3ab", "3al") are Arabizi letters, never an hour.
     *
     * @return array{hour: string, days: int}|null the HH:mm hour, and 1 when it falls after
     *                                             midnight of the day that was named
     */
    private function extractTime(string $text): ?array
    {
        $english = $this->alternation(array_keys(self::ENGLISH_MINUTES));

        if (preg_match('/(?<![a-z])('.$english.')\s*(\d{1,2})(?!\d)\s*(am|pm)?(?![a-z])/', $text, $m)) {
            return $this->clock((int) $m[2], 0, ($m[3] ?? '') ?: $this->meridiem($m[2], $text), self::ENGLISH_MINUTES[$m[1]]);
        }

        if (preg_match('/(?<![a-z\d])(\d{1,2})(?::(\d{2}))?\s*(am|pm)(?![a-z])/', $text, $m)) {
            return $this->clock((int) $m[1], (int) ($m[2] ?? 0), $m[3]);
        }

        if (preg_match('/(?<![a-z\d])(\d{1,2}):(\d{2})(?!\d)/', $text, $m)) {
            return $this->clock((int) $m[1], (int) $m[2], $this->meridiem($m[1], $text));
        }

        $markers = $this->alternation(self::HOUR_MARKERS);
        $spoken = '(?:\s*('.$this->alternation(array_keys(self::SPOKEN_MINUTES)).')(?![\p{L}]))?';

        if (preg_match('/(?<![\p{L}])[وبلف]?(?:'.$markers.')\s*(\d{1,2})(?![a-z\d])'.$spoken.'/u', $text, $m)
            || preg_match('/(?<![a-z\d\/:])(\d{1,2})(?![a-z\d\/:])'.$spoken.'/u', $text, $m)) {
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
