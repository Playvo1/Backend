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
 * synonyms and typos, today/tomorrow/weekdays (اليوم، بكرا، بعد بكرا، الجمعة، السبت الجاي,
 * bukra, tmrw...), explicit dates (12/10, 10 اكتوبر, oct 12), hours such as "8", "6pm",
 * "6.30pm", "18:30", "6 المسا", "الساعة ستة", "عالتمانية", "السابعة مساءً", "6 ونص",
 * "6 الا ربع", "at seven", "3al sab3a" or "12 بالليل", and city/area names from the CITY and VENUE
 * tables.
 *
 * A date or hour is only returned when the text gives one: the assistant asks for the
 * missing detail instead of guessing. A number is only read as the hour in a time context,
 * never when it is a price, a count, a duration or part of a date or phone number.
 */
class RuleBasedQueryParser implements QueryParser
{
    /**
     * Extra ways players name a sport, keyed by SPORT.name_en (lower case), written in
     * normalised form (ة as ه, no hamza on alef). Small typos of these are accepted too.
     */
    private const SPORT_ALIASES = [
        'football' => ['soccer', 'futsal', 'futbol', 'footy', 'kora', 'koora', 'kura', 'كوره', 'كوره قدم', 'كره قدم', 'فوتبول', 'فطبول', 'خماسي', 'خماسيات'],
        'basketball' => ['basket', 'basket ball', 'bball', 'b ball', 'hoops', 'باسكت', 'باسكيت', 'باسكت بول', 'كره سله', 'سله'],
        'volleyball' => ['volley', 'volley ball', 'vball', 'v ball', 'فولي', 'فولي بول', 'كره طايره', 'طايره'],
        'tennis' => ['تنس', 'تينس', 'تنس ارضي'],
        'padel' => ['paddle', 'بادل', 'باديل', 'بادل تنس'],
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

    /**
     * The afternoon among PM_WORDS, and noon: what "which hour?" offers hours for.
     */
    private const AFTERNOON_WORDS = ['afternoon', 'noon', 'midday', 'العصر', 'عصرا', 'عصر', 'بعد الظهر', 'الظهر', 'ظهرا'];

    private const HOUR_MARKERS = ['at', 'around', 'about', 'الساعه', 'ساعه', 'عالساعه', 'حوالي'];

    /**
     * "At" before an hour in digits ("ع 8", "عال8", "على 7"). Unlike HOUR_MARKERS they also
     * mean "for" ("على 10 لاعبين"), so a count or price after the number still wins.
     */
    private const AT_WORDS = ['ع', 'عال', 'علي'];

    /**
     * Words next to a number that make it something other than an hour. A price is never an
     * hour; a count (players, a team size, courts, a duration) is not one either unless a
     * clock word such as "الساعة" or "at" comes first ("الساعة 8 ملعب كورة").
     */
    private const PRICE_WORDS = [
        'شيكل', 'شيقل', 'شواكل', 'شيكلات', 'ش', 'ils', 'nis', 'shekel', 'shekels', 'sheqel', 'sheqels',
        'دولار', 'dollar', 'dollars', 'usd', 'دينار', 'jod',
    ];

    private const COUNT_WORDS = [
        // Players and people.
        'لاعب', 'لاعبين', 'لعيبه', 'شباب', 'شب', 'اشخاص', 'شخص', 'انفار', 'نفر', 'افراد', 'ناس', 'زلم', 'رجال', 'اولاد', 'ولاد', 'بنات', 'اصحاب', 'صحاب',
        'players', 'player', 'people', 'persons', 'person', 'guys', 'men', 'kids', 'boys', 'girls', 'friends',
        // Team sizes ("5 ضد 5", "5 vs 5", "7 a side").
        'ضد', 'مقابل', 'vs', 'v', 'x', 'against', 'a side', 'aside', 'side',
        // Courts, teams and bookings.
        'ملاعب', 'ملعب', 'فرق', 'فريق', 'حجوزات', 'مرات', 'courts', 'court', 'pitches', 'pitch', 'fields', 'field', 'teams', 'team', 'bookings', 'times',
        // Durations ("ساعة" before the number is the clock word, after it a duration).
        'ساعه', 'ساعات', 'دقيقه', 'دقايق', 'دقائق', 'ايام', 'اسابيع', 'شهور', 'اشهر',
        'hours', 'hour', 'hrs', 'hr', 'minutes', 'minute', 'mins', 'min', 'days', 'weeks', 'months',
    ];

    /**
     * Words before a number that make it something other than an hour: the second side of a
     * team size, a court or phone number, a price, or a day of the month ("يوم 5").
     */
    private const NOT_HOUR_BEFORE = ['ضد', 'مقابل', 'vs', 'v', 'x', 'against', 'رقم', 'number', 'num', 'سعر', 'بسعر', 'price', 'cost', 'يوم', 'شهر'];

    /**
     * Month names, in normalised form, for "10 اكتوبر" or "oct 10". تشرين and كانون alone
     * name two months each, so they only stop the day being read as an hour.
     */
    private const MONTHS = [
        1 => ['يناير', 'كانون الثاني', 'كانون ثاني', 'كانون التاني', 'january', 'jan'],
        2 => ['فبراير', 'شباط', 'february', 'feb'],
        3 => ['مارس', 'اذار', 'march', 'mar'],
        4 => ['ابريل', 'نيسان', 'april', 'apr'],
        5 => ['مايو', 'ايار', 'may'],
        6 => ['يونيو', 'يونيه', 'حزيران', 'june', 'jun'],
        7 => ['يوليو', 'يوليه', 'تموز', 'july', 'jul'],
        8 => ['اغسطس', 'اب', 'august', 'aug'],
        9 => ['سبتمبر', 'ايلول', 'september', 'sept', 'sep'],
        10 => ['اكتوبر', 'تشرين الاول', 'تشرين اول', 'october', 'oct'],
        11 => ['نوفمبر', 'تشرين الثاني', 'تشرين ثاني', 'تشرين التاني', 'november', 'nov'],
        12 => ['ديسمبر', 'كانون الاول', 'كانون اول', 'december', 'dec'],
    ];

    private const AMBIGUOUS_MONTHS = ['تشرين', 'كانون', 'شهر', 'الشهر'];

    /**
     * Said after a weekday ("السبت الجاي") or "next" before it: on that very weekday it means
     * a week later, on any other day the coming one.
     */
    private const NEXT_AFTER = ['الجاي', 'الجايه', 'القادم', 'القادمه', 'المقبل', 'المقبله', 'الياي', 'اللي جاي', 'الي جاي'];

    private const NEXT_BEFORE = ['next', 'coming', 'this coming'];

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

    /**
     * Hours written as Arabizi words ("3al sab3a", "sa3a tmanye"). Several are also weekday
     * names (tlata, arba3a), so they are only read after "3al"/"sa3a" or before "bel lail".
     */
    private const ARABIZI_HOURS = [
        1 => ['wa7de', 'wa7deh', 'wa7da', 'wahde'],
        2 => ['tintain', 'tinten', 'tnen', 'tneen'],
        3 => ['tlate', 'tlateh', 'tlata', 'talateh'],
        4 => ['arba3a', 'arb3a', 'arba3ah'],
        5 => ['5amse', '5amseh', '5amsa', 'khamse', 'khamseh'],
        6 => ['sitte', 'sitteh', 'sette', 'setteh', 'sitta'],
        7 => ['sab3a', 'sab3ah', 'saba3', 'sabe3'],
        8 => ['tmanye', 'tmanyeh', 'tamanye', 'tamanyeh', 'tmanya', 'tamanya', 'tmane'],
        9 => ['tis3a', 'tis3ah', 'tes3a', 'tes3ah'],
        10 => ['3ashra', '3ashrah', '3ashara', '3asharah'],
        11 => ['7da3sh', 'hda3sh', '7da3esh', 'ida3sh'],
        12 => ['tna3sh', 'itna3sh', 'etna3sh', 'tnash'],
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
     * Words after which a place is named ("في"/"fi"/"fe" in Arabizi, "near", "بالقرب من"...).
     * They are never a place themselves, and a connector after them ("من", "to") is skipped.
     */
    private const PLACE_MARKERS = [
        'in', 'near', 'around', 'nearby', 'fi', 'fe',
        'في', 'عند', 'قرب', 'بالقرب', 'قريب', 'جنب', 'جمب', 'حد', 'جوا', 'داخل', 'حي', 'بحي', 'منطقه', 'بمنطقه', 'مدينه', 'بمدينه',
    ];

    private const PLACE_CONNECTORS = ['من', 'to', 'of'];

    /**
     * Words that are never a place name, so "بالجامعة" or "near the gym" is not reported as a
     * place we don't cover (a false "not covered" blocks a booking; ignoring a word does not).
     * Written in normalised form without the article; grow them by category.
     */
    private const NOT_PLACES = [
        // Function words, pronouns and request verbs (Arabic, English, Arabizi).
        'the', 'a', 'an', 'my', 'me', 'us', 'you', 'this', 'that', 'some', 'any', 'one', 'two', 'three', 'four', 'five', 'six', 'seven',
        'eight', 'nine', 'ten', 'please', 'pls', 'plz', 'thanks', 'thank', 'here', 'there', 'anywhere', 'somewhere', 'everywhere',
        'ay', 'ayy', 'ai', 'eh', 'shi', 'shu', 'sho', 'esh', 'ish', 'ishi', 'hon', 'hoon', 'hun', 'hena', 'hnak', 'kaman', 'kman',
        'mish', 'msh', 'mesh', 'ma', 'la', 'aw', 'wala', 'yalla', 'ya', 'ana', 'enta', 'inta', 'bdi', 'bde', 'bdna', 'badi', 'baddi',
        'mumkin', 'momken', 'shukran',
        'حدا', 'اشي', 'شي', 'نفس', 'هاد', 'هذا', 'هاي', 'هون', 'هنا', 'هناك', 'له', 'لله', 'كثير', 'اي', 'كل', 'بعض', 'مني', 'منك', 'منا',
        'بدي', 'بدنا', 'بدك', 'ابغي', 'نبغي', 'اريد', 'نريد', 'عاوز', 'عايز', 'حابب', 'حابين', 'ممكن', 'لو', 'سمحت', 'احجز', 'نحجز',
        'العب', 'نلعب', 'شو', 'وين', 'هل', 'كمان', 'بس', 'ايضا', 'شكرا', 'مرحبا', 'سلام', 'يعطيك', 'عافيه',
        // Venues and facilities.
        'court', 'courts', 'pitch', 'pitches', 'field', 'fields', 'stadium', 'club', 'hall', 'venue', 'gym', 'arena',
        'ملعب', 'ملاعب', 'نادي', 'صاله', 'جيم', 'ساحه', 'حجز', 'مجال', 'امكانيه',
        // Landmarks and buildings.
        'university', 'uni', 'college', 'school', 'mosque', 'masjid', 'church', 'hospital', 'clinic', 'pharmacy', 'house', 'home',
        'apartment', 'flat', 'building', 'tower', 'mall', 'market', 'souq', 'souk', 'shop', 'store', 'restaurant', 'cafe', 'coffee',
        'hotel', 'office', 'work', 'station', 'port', 'harbour', 'harbor', 'bridge', 'street', 'road', 'corner', 'roundabout',
        'square', 'park', 'garden', 'beach', 'sea', 'coast',
        'جامعه', 'كليه', 'مدرسه', 'مسجد', 'جامع', 'كنيسه', 'مستشفي', 'عياده', 'صيدليه', 'بيت', 'دار', 'منزل', 'شقه', 'عماره', 'برج',
        'مول', 'سوق', 'محل', 'دكان', 'مطعم', 'كافيه', 'قهوه', 'فندق', 'مكتب', 'شغل', 'عمل', 'محطه', 'ميناء', 'جسر', 'شارع', 'طريق',
        'دوار', 'ميدان', 'مركز', 'حديقه', 'منتزه', 'بحر', 'شاطئ', 'كورنيش',
        // Places in general, not a name.
        'place', 'city', 'area', 'town', 'village', 'camp', 'district', 'region', 'neighbourhood', 'neighborhood', 'hood', 'center',
        'centre', 'downtown', 'general',
        'مكان', 'اماكن', 'منطقه', 'مدينه', 'حي', 'حاره', 'مخيم', 'بلد', 'قريه', 'ضاحيه', 'جيران', 'اطراف', 'قرب',
        // Times and periods.
        'time', 'hour', 'hours', 'day', 'daytime', 'morning', 'evening', 'afternoon', 'night', 'week', 'weekend', 'month', 'holiday',
        'وقت', 'ساعه', 'يوم', 'نهار', 'ليل', 'ليله', 'مسا', 'مساء', 'صبح', 'صباح', 'عصر', 'ظهر', 'مغرب', 'عشا', 'فجر', 'اسبوع',
        'شهر', 'ويكند', 'عطله', 'اجازه', 'عيد',
        // Manner: "بالضبط", "بالدور", "بالسلامة", "بالعربي".
        'english', 'arabic', 'exactly', 'asap', 'total', 'advance',
        'ضبط', 'ظبط', 'زبط', 'تحديد', 'نسبه', 'مره', 'دور', 'توفيق', 'سلامه', 'عربي', 'انجليزي', 'انكليزي', 'كامل', 'مجان',
        'تقسيط', 'راحه', 'فعل', 'اول', 'اخر', 'مناسبه', 'مناسب', 'طبع', 'تاكيد', 'اضافه', 'عكس', 'سرعه', 'تمام',
        // Paying.
        'cash', 'price', 'card', 'visa', 'money', 'discount', 'offer', 'app', 'free',
        'كاش', 'سعر', 'فلوس', 'مصاري', 'دفع', 'حساب', 'فيزا', 'بطاقه', 'تطبيق', 'خصم', 'عرض',
        // People.
        'friends', 'family', 'team', 'guys', 'kids', 'men', 'women', 'boys', 'girls', 'people', 'group',
        'شباب', 'جماعه', 'ربع', 'اصحاب', 'صحاب', 'عيله', 'اولاد', 'ولاد', 'رجال', 'نساء', 'بنات', 'فريق', 'فرقه', 'اطفال', 'كبار',
        'صغار', 'ناس', 'اهل', 'اخوان', 'زملاء',
    ];

    /**
     * Everyday names of seeded cities, keyed by CITY.name_en in lower case: "الدير" is how
     * players in Gaza name Deir al-Balah.
     */
    private const CITY_ALIASES = [
        'deir al-balah' => ['الدير', 'deir'],
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

        $known = $this->knownWords();

        return array_filter([
            'sport' => $this->bestMention($text, $this->sportCandidates(), $known),
            'date' => $date,
            'hour' => $time['hour'] ?? null,
            'city' => $this->bestMention($text, $this->cityCandidates(), $known),
            'area' => $this->bestMention($text, $this->areaCandidates(), $known),
        ]);
    }

    /**
     * Whether the text names a day at all, even one only Gemini can resolve.
     */
    public function mentionsDate(string $queryText, CarbonImmutable $today): bool
    {
        $text = $this->prepare($queryText);

        return $this->extractDate($text, $today->startOfDay())[0] !== null
            || $this->containsAnyWord($text, [...self::OTHER_DATE_WORDS, ...self::AMBIGUOUS_MONTHS])
            || preg_match('/(?<![a-z\d])\d{1,2}(?:st|nd|rd|th)(?![a-z])/', $text) === 1
            || preg_match('/(?<![\p{L}])[وبلف]?يوم\s*\d{1,2}(?![\d:\/])/u', $text) === 1;
    }

    /**
     * Whether the text gives an hour, by the same rules as parse(): a number only counts in
     * a time context, so a price, a team size, a duration, a day of the month or a number
     * word such as "عشرة شباب" or "one court" is not an hour, and Gemini's hour (which mode
     * ANY makes it fill in) is dropped for them. Digits inside an Arabizi word ("mal3ab") are
     * letters, and vague words such as "evening" or "بعد المغرب" are not an hour either.
     */
    public function mentionsTime(string $queryText, CarbonImmutable $today): bool
    {
        [, $text] = $this->extractDate($this->prepare($queryText), $today->startOfDay());

        return $this->extractTime($text) !== null || $this->containsAnyWord($text, ['oclock', 'o clock']);
    }

    /**
     * The part of the day the player named without an hour ("الجمعة الصبح", "tomorrow
     * afternoon", "بكرا بالليل"): morning, afternoon or evening; null when none is named.
     */
    public function partOfDay(string $queryText): ?string
    {
        $text = $this->prepare($queryText);
        // "am" and "pm" come with an hour, and "am" is also the English verb.
        $words = fn (array $words) => array_values(array_diff($words, ['am', 'pm']));

        return match (true) {
            $this->containsAnyWord($text, $words(self::AM_WORDS)) => 'morning',
            $this->containsAnyWord($text, self::AFTERNOON_WORDS) => 'afternoon',
            $this->containsAnyWord($text, [...$words(self::PM_WORDS), ...self::NIGHT_WORDS]) => 'evening',
            default => null,
        };
    }

    /**
     * A place the player named that is no seeded city, area or venue ("بالقدس", "in Cairo"),
     * so the assistant can say it isn't covered instead of suggesting somewhere else. Only
     * clear cases are reported: "بال..." or "في ال..." in Arabic, "in"/"near"/"fi" in English
     * and Arabizi, with up to three words ("بالشيخ رضوان"). When in doubt no place is
     * reported: a common word, a word shorter than three Latin letters, or anything that
     * loosely spells a covered city or area is never "not covered".
     */
    public function unknownPlace(string $queryText): ?string
    {
        $words = explode(' ', $this->prepare($queryText));
        $known = $this->knownWords();
        $covered = $this->cityCandidates()->concat($this->areaCandidates());

        foreach ($words as $index => $word) {
            $place = match (true) {
                in_array($word, self::PLACE_MARKERS, true) => array_slice($words, $index + 1, 4),
                mb_strlen($word) >= 5 && str_starts_with($word, 'بال') => [mb_substr($word, 1), ...array_slice($words, $index + 1, 2)],
                default => [],
            };

            // Skip a connector ("بالقرب من") and English articles. In Arabic "في" also means
            // "is there" ("في ملعب؟"), so a place needs its article unless a place noun (حي،
            // منطقة، مدينة) comes first.
            if ($place !== [] && in_array($place[0], self::PLACE_CONNECTORS, true)) {
                array_shift($place);
            }

            if ($place !== [] && in_array($place[0], ['the', 'al', 'el'], true)) {
                array_shift($place);
            }

            $placeNoun = in_array($word, ['حي', 'بحي', 'منطقه', 'بمنطقه', 'مدينه', 'بمدينه'], true);

            if ($place === [] || (preg_match('/^\p{Arabic}/u', $place[0]) === 1 && ! str_starts_with($place[0], 'ال') && ! $placeNoun)) {
                continue;
            }

            $phrase = [];

            foreach (array_slice($place, 0, 3) as $candidate) {
                if (preg_match('/\d/', $candidate) === 1 || in_array($candidate, self::PLACE_MARKERS, true)
                    || preg_match('/^[a-z]{1,2}$/', $candidate) === 1 || $this->isKnownWord($candidate, $known)) {
                    break;
                }

                $phrase[] = $candidate;
            }

            if ($phrase !== [] && ! $this->namesCoveredPlace(implode(' ', $phrase), $covered, $known)) {
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
     * as words turned into digits. Before normalising, symbols that carry meaning are kept
     * as words ("@ 8" is "at 8", "20₪" a price), and a dotted date is written with slashes:
     * a full one (12.10.2026), or a day and month after a day word ("يوم 5.10"), which
     * TextNormalizer would otherwise read as the time 5:10.
     */
    private function prepare(string $queryText): string
    {
        $queryText = strtr($queryText, ['@' => ' at ', '₪' => ' ils ', '$' => ' usd ']);
        $queryText = preg_replace('/(?<![\p{Nd}.٫])(\p{Nd}{1,2})[.٫](\p{Nd}{1,2})[.٫](\p{Nd}{2,4})(?!\p{Nd})/u', '$1/$2/$3', $queryText) ?? $queryText;
        $queryText = preg_replace('/((?<!\p{L})(?:[وب]?يوم|on|date|تاريخ|بتاريخ)\s*)(\p{Nd}{1,2})[.٫](\p{Nd}{1,2})(?![.٫]?\p{Nd})/iu', '$1$2/$3', $queryText) ?? $queryText;
        $text = $this->arabiziHours(TextNormalizer::normalize($queryText));

        foreach (self::REWRITES as $pattern => $replacement) {
            $text = preg_replace($pattern.'u', $replacement, $text) ?? $text;
        }

        $text = preg_replace_callback('/(?<![a-z\d])[a-z]{4,}(?![a-z\d])/u', fn (array $m) => $this->closestDayWord($m[0]) ?? $m[0], $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $this->hoursFromWords($text)) ?? $text);
    }

    /**
     * "3al sab3a", "sa3a tmanye" and "tes3a bel lail" become "at 7", "at 8" and "9 bel lail".
     * Run before the rewrites, which read "tlata" and "arba3a" alone as weekdays.
     */
    private function arabiziHours(string $text): string
    {
        $hours = $this->wordsToNumbers(self::ARABIZI_HOURS);
        $words = $this->alternation(array_keys($hours));
        $timeOfDay = '(?:bel|bil|b|el)\s?(?:lail|leil|lel|layl)|(?:el\s?|bel\s?|bil\s?)?(?:masa|mesa|sob7|sub7|subh|sobh)|(?:el\s?)?3asr';

        $text = preg_replace_callback(
            '/(?<![a-z\d])(?:3al|3ala|3a|3l|(?:el\s?|il\s?)?sa3a|at|around)\s*('.$words.')(?![a-z\d])/u',
            fn (array $m) => ' at '.$hours[$m[1]].' ',
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/(?<![a-z\d])('.$words.')(?=\s+(?:'.$timeOfDay.')(?![a-z\d]))/u',
            fn (array $m) => ' '.$hours[$m[1]].' ',
            $text,
        ) ?? $text;
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
        $cardinals = $this->wordsToNumbers(self::ARABIC_HOURS);
        $ordinals = $this->wordsToNumbers(self::ARABIC_ORDINAL_HOURS);
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
     * @param  array<int, list<string>>  $numbers  the spellings of each number (an hour, a month)
     * @return array<string, int>
     */
    private function wordsToNumbers(array $numbers): array
    {
        $words = [];

        foreach ($numbers as $number => $spellings) {
            foreach ($spellings as $spelling) {
                $words[$spelling] = $number;
            }
        }

        return $words;
    }

    /**
     * @return Collection<int, array{name: string, alias: string}>
     */
    private function cityCandidates(): Collection
    {
        return City::query()->get(['name_ar', 'name_en'])->flatMap(fn (City $city) => collect([
            $city->name_ar,
            $city->name_en,
            ...self::CITY_ALIASES[mb_strtolower($city->name_en)] ?? [],
        ])->map(fn (string $alias) => ['name' => $city->name_en, 'alias' => $alias]));
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
     *
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
     * In order, so a looser reading never overrides a closer one:
     * 1. the longest exact mention, as whole words;
     * 2. a name in another common spelling (TextNormalizer::spells(): "khanyounis",
     *    "tel al hawa", "ghazza");
     * 3. the closest near-miss (a typo), but only when one name is clearly closest, so a
     *    typo never silently picks a different place, and never for a word in $known that
     *    means something else.
     *
     * @param  Collection<int, array{name: string, alias: string}>  $candidates
     * @param  list<string>  $known  see knownWords()
     */
    private function bestMention(string $text, Collection $candidates, array $known): ?string
    {
        $byLength = $candidates->sortByDesc(fn (array $candidate) => mb_strlen(TextNormalizer::loose($candidate['alias'])));
        $match = $byLength->first(fn (array $candidate) => TextNormalizer::mentions($text, $candidate['alias']))
            ?? $byLength->first(fn (array $candidate) => TextNormalizer::spells($text, $candidate['alias']));

        if ($match !== null) {
            return $match['name'];
        }

        $close = $this->nearMisses($text, $candidates, $known);

        if ($close->isEmpty()) {
            return null;
        }

        $best = $close->min('distance');
        $names = $close->where('distance', $best)->pluck('name')->unique();

        return $names->count() === 1 ? $names->first() : null;
    }

    /**
     * @param  Collection<int, array{name: string, alias: string}>  $candidates
     * @param  list<string>  $known
     * @return Collection<int, array{name: string, alias: string, distance: int}>
     */
    private function nearMisses(string $text, Collection $candidates, array $known): Collection
    {
        // Known words in their own sense: a word that names this very candidate stays readable.
        $skip = fn (array $candidate) => array_values(array_diff($known, explode(' ', TextNormalizer::loose($candidate['alias']))));

        return $candidates
            ->map(fn (array $candidate) => [...$candidate, 'distance' => TextNormalizer::closeness($text, $candidate['alias'], $skip($candidate))])
            ->whereNotNull('distance')
            ->values();
    }

    /**
     * Whether $phrase may name a covered city or area: in any spelling the matching accepts,
     * even when two are equally close, or with a word one letter off a word of a covered
     * name ("dair" for the "deir" of Deir al-Balah). Such a place is never reported as not
     * covered, since wrongly saying so blocks a booking.
     *
     * @param  Collection<int, array{name: string, alias: string}>  $covered
     * @param  list<string>  $known
     */
    private function namesCoveredPlace(string $phrase, Collection $covered, array $known): bool
    {
        $coveredWords = $covered->flatMap(fn (array $candidate) => explode(' ', TextNormalizer::loose($candidate['alias'])))->unique();

        return $covered->contains(fn (array $candidate) => TextNormalizer::mentions($phrase, $candidate['alias'])
            || TextNormalizer::spells($phrase, $candidate['alias']))
            || $this->nearMisses($phrase, $covered, $known)->isNotEmpty()
            || collect(explode(' ', TextNormalizer::loose($phrase)))->contains(fn (string $word) => $coveredWords->contains(
                fn (string $coveredWord) => TextNormalizer::oneLetterOff($word, $coveredWord),
            ));
    }

    /**
     * Every word the parser knows, so it isn't taken for an unknown place or read as a typo
     * of another name.
     *
     * @return list<string>
     */
    private function knownWords(): array
    {
        $names = [
            ...$this->sportCandidates()->pluck('alias')->all(),
            ...$this->areaCandidates()->pluck('alias')->all(),
            ...$this->cityCandidates()->pluck('alias')->all(),
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

        // "10 اكتوبر", "10 من شهر اكتوبر", "5 oct", "october 5th".
        $months = $this->wordsToNumbers(self::MONTHS);
        $month = $this->alternation(array_keys($months));

        if (preg_match('/(?<![\p{L}\d:\/])(\d{1,2})(?:st|nd|rd|th)?\s*(?:of\s*|من\s*)?(?:شهر\s*)?('.$month.')(?![\p{L}])/u', $text, $m)
            || preg_match('/(?<![\p{L}])('.$month.')\s*(\d{1,2})(?:st|nd|rd|th)?(?![\p{L}\d:\/])/u', $text, $m)) {
            [$day, $name] = ctype_digit($m[1]) ? [$m[1], $m[2]] : [$m[2], $m[1]];

            return [$this->dayMonthDate((int) $day, $months[$name], null, $today), str_replace($m[0], ' ', $text)];
        }

        // "5 شهر 10", "5 من الشهر 10": the day, then the month's number.
        if (preg_match('/(?<![\p{L}\d:\/])(\d{1,2})\s*(?:من\s*)?(?:ال)?شهر\s*(\d{1,2})(?![\p{L}\d:\/])/u', $text, $m)) {
            return [$this->dayMonthDate((int) $m[1], (int) $m[2], null, $today), str_replace($m[0], ' ', $text)];
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
            if (! $this->containsAnyWord($text, $words)) {
                continue;
            }

            if ($today->englishDayOfWeek !== ucfirst($weekday)) {
                return $today->modify('next '.$weekday);
            }

            // Said on that weekday, the weekday alone is today and "next" makes it a week later.
            return $this->saysNext($text, $words) ? $today->addWeek() : $today;
        }

        return null;
    }

    /**
     * "السبت الجاي", "يوم السبت القادم", "next saturday", "this coming saturday".
     *
     * @param  list<string>  $weekday  the ways to write the weekday
     */
    private function saysNext(string $text, array $weekday): bool
    {
        $day = '[وبلف]?(?:'.$this->alternation($weekday).')';

        return preg_match('/(?<![\p{L}])'.$day.'\s+(?:'.$this->alternation(self::NEXT_AFTER).')(?![\p{L}])/u', $text) === 1
            || preg_match('/(?<![\p{L}])(?:'.$this->alternation(self::NEXT_BEFORE).')\s+(?:on\s+)?'.$day.'(?![\p{L}])/u', $text) === 1;
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
            // "tonight at 1am" is after midnight, like "الليلة الساعة 1": the next calendar day.
            $nightAm = $m[3] === 'am' && (int) $m[1] % 12 <= 4 && $this->containsAnyWord($text, self::NIGHT_WORDS);
            $meridiem = $nightAm ? 'night' : $m[3];

            return $this->clock((int) $m[1], (int) ($m[2] ?? 0), $meridiem);
        }

        $hour = $this->numberedHour($text);

        if ($hour !== null) {
            return $this->clock($hour['hour'], $hour['minute'], $this->meridiem($hour['written'], $text), $hour['spoken']);
        }

        return match (true) {
            $this->containsAnyWord($text, self::MIDNIGHT_WORDS) => ['hour' => '00:00', 'days' => 1],
            $this->containsAnyWord($text, self::NOON_WORDS) => ['hour' => '12:00', 'days' => 0],
            default => null,
        };
    }

    /**
     * The hour among the numbers of the text (without am/pm), read only in a time context:
     * 1. after a clock word or "at" ("الساعة 8", "ع 8", "at 8", "sa3a 8"), which beats
     *    any earlier number;
     * 2. written as a clock time ("18:30") or before a time-of-day word ("8 بالليل",
     *    "6 المسا", "7 ونص");
     * 3. otherwise the only number left, as in "كورة بكرا 8".
     * A number next to a price, a count, a team size, a duration, a month or "يوم" is never
     * the hour, nor are the groups of a phone number ("0599 12 34 56").
     *
     * @return array{hour: int, minute: int, written: string, spoken: int}|null
     */
    private function numberedHour(string $text): ?array
    {
        $text = $this->withoutNonHourNumbers($text);
        $spokenMinutes = $this->alternation(array_keys(self::SPOKEN_MINUTES));
        $clockWord = '(?<![\p{L}])[وبلف]?(?:'.$this->alternation(self::HOUR_MARKERS).')\s*$';
        $atWord = '(?<![\p{L}])[وبلف]?(?:'.$this->alternation(self::AT_WORDS).')\s*$';
        $timeOfDay = '^\s*(?:[وبلف]?(?:'.$this->alternation([...self::PM_WORDS, ...self::AM_WORDS, ...self::NIGHT_WORDS, 'الظهر', 'ظهرا']).')|'
            .$spokenMinutes.'|o\s?clock|oclock|in the (?:morning|evening|afternoon)|at night)(?![\p{L}])';
        $price = $this->alternation(self::PRICE_WORDS);
        $count = $this->alternation(self::COUNT_WORDS);
        $months = $this->alternation([...array_merge(...array_values(self::MONTHS)), ...self::AMBIGUOUS_MONTHS]);
        $notHourBefore = '(?<![\p{L}])[وبلف]?(?:'.$this->alternation(self::NOT_HOUR_BEFORE).'|'.$months.'|'.$price.')\s*$';
        $candidates = [];

        preg_match_all('/(?<![a-z\d\/:])(\d{1,2})(?::([0-5]\d))?(?![a-z\d\/:])/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$written, $offset] = $match[1];
            $minute = isset($match[2]) ? (int) $match[2][0] : 0;
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($match[0][0]));
            $clock = preg_match('/'.$clockWord.'/u', $before) === 1;

            if ((int) $written > 23
                || preg_match('/^\s*(?:'.$price.'|'.$months.')(?![\p{L}])/u', $after) === 1
                || preg_match('/'.$notHourBefore.'/u', $before) === 1
                || (! $clock && preg_match('/^\s*(?:'.$count.')(?![\p{L}])/u', $after) === 1)) {
                continue;
            }

            preg_match('/^\s*('.$spokenMinutes.')(?![\p{L}])/u', $after, $spoken);

            $candidates[] = [
                'hour' => (int) $written,
                'minute' => $minute,
                'written' => $written,
                'spoken' => self::SPOKEN_MINUTES[$spoken[1] ?? ''] ?? 0,
                'rank' => match (true) {
                    $clock || preg_match('/'.$atWord.'/u', $before) === 1 => 3,
                    isset($match[2]) || preg_match('/'.$timeOfDay.'/u', $after) === 1 => 2,
                    default => 1,
                },
            ];
        }

        $best = collect($candidates)->sortByDesc('rank')->first();

        return $best !== null && ($best['rank'] > 1 || count($candidates) === 1) ? $best : null;
    }

    /**
     * Removes the numbers that are never an hour as a whole: a team size ("5 ضد 5", "3 على 3",
     * "5 on 5"), a phone number with its groups ("0599 12 34 56"), and any run of three or
     * more numbers in a row.
     */
    private function withoutNonHourNumbers(string $text): string
    {
        $patterns = [
            '/(?<![\p{L}\d:\/])\d{1,2}\s*(?:ضد|مقابل|vs|v|x|against)\s*\d{1,2}(?![\p{L}\d:\/])/u',
            '/(?<![\p{L}\d:\/])(\d{1,2})\s*(?:علي|on)\s*\1(?![\p{L}\d:\/])/u',
            '/(?<![\p{L}\d:\/])0\d{2,}(?:[\s-]+\d{2,4}(?![\p{L}\d:\/]))*/u',
            '/(?<![\p{L}\d:\/])\d{1,4}(?:[\s-]+\d{1,4}){2,}(?![\p{L}\d:\/])/u',
        ];

        return preg_replace($patterns, ' ', $text) ?? $text;
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
