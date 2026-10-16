<?php

namespace App\Services\Assistant;

/**
 * Normalises Arabic and English text so names match regardless of case, harakat,
 * tatweel, the letters players swap when typing Arabic (أ/إ/آ/ٱ as ا, ة as ه, ى and ئ
 * as ي, ؤ as و), Arabic-Indic digits or punctuation. It runs in PHP, so matching
 * behaves the same on MySQL and SQLite.
 */
class TextNormalizer
{
    /**
     * Letters that are commonly written in place of one another.
     */
    public const AMBIGUOUS_LETTERS = ['ا', 'أ', 'إ', 'آ', 'ٱ', 'ة', 'ه', 'ى', 'ي', 'ئ'];

    /**
     * Harakat (U+064B-U+065F), superscript alef (U+0670) and tatweel (U+0640).
     */
    private const DIACRITICS = '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u';

    /**
     * Hamza seats are often left out: طائرة is typed طايرة, مؤجل is typed موجل.
     */
    private const LETTER_VARIANTS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private const SYMBOLS = ['a.m.' => 'am', 'p.m.' => 'pm', 'a.m' => 'am', 'p.m' => 'pm', '٫' => '.', '：' => ':'];

    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Lower-case, unified letters and digits, punctuation turned into spaces (":", "/"
     * and a hyphen between digits are kept for times and dates), single spaces. A dotted
     * time such as "6.30" or "18.30" becomes "6:30" before the dot would be dropped.
     */
    public static function normalize(string $text): string
    {
        $text = strtr(self::stripDiacritics(mb_strtolower($text)), self::LETTER_VARIANTS + self::DIGITS);
        $text = strtr($text, self::SYMBOLS);
        // Not inside a dotted date such as 12.10.2026.
        $text = preg_replace('/(?<!\d)(?<!\d\.)(\d{1,2})\.([0-5]\d)(?!\d|\.\d)/u', '$1:$2', $text) ?? '';
        $text = preg_replace('/(?<!\d)-|-(?!\d)/u', ' ', $text) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}:\/\-\s]/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function stripDiacritics(string $text): string
    {
        return preg_replace(self::DIACRITICS, '', $text) ?? '';
    }

    /**
     * normalize() without articles ("ال", "al", "el") and with Arabic word endings folded,
     * for comparing place and sport names: a dropped final hamza (الجلا = الجلاء), a final
     * ى, ي or ا written for one another (الهوا = الهوى), and a final ة written ا (كورا = كورة).
     */
    public static function loose(string $text): string
    {
        return self::remember('loose', $text, fn () => implode(' ', array_map(fn (string $word) => self::foldEnding($word), self::words($text))));
    }

    /**
     * loose() and skeleton() are pure and called many times per request while names are
     * matched, so their results are kept (a bounded cache, cleared when it fills up).
     */
    private static function remember(string $kind, string $text, callable $compute): string
    {
        if (count(self::$cache) >= 20000) {
            self::$cache = [];
        }

        return self::$cache[$kind."\0".$text] ??= $compute();
    }

    /**
     * The words of normalize() without articles, endings left as written.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = array_filter(
            explode(' ', self::normalize($text)),
            fn (string $word) => $word !== '' && $word !== 'al' && $word !== 'el',
        );

        return array_values(array_map(
            fn (string $word) => mb_strlen($word) > 3 && str_starts_with($word, 'ال') ? mb_substr($word, 2) : $word,
            $words,
        ));
    }

    private static function foldEnding(string $word): string
    {
        if (preg_match('/\p{Arabic}/u', $word) !== 1) {
            return $word;
        }

        $word = preg_replace('/ء$/u', '', $word) ?? $word;

        return preg_replace('/[يه]$/u', 'ا', $word) ?? $word;
    }

    /**
     * A spelling-insensitive form of a place or sport name, so the spellings players use for
     * the same name compare equal: no spaces, hyphens or apostrophes ("khanyounis",
     * "shuja'iyya"), tel/til as tal, gh as g and kh as k (ghazza), Arabizi digits as letters
     * (rafa7), o/ou/oo as u, e/ee/y as i, double letters as one and no final h after a vowel
     * (gazza, rafa). Short or different names stay apart: "jalal" is not "jalaa".
     */
    public static function skeleton(string $text): string
    {
        return self::remember('skeleton', $text, function () use ($text) {
            $text = preg_replace('/(?<![\p{L}\d])(?:tel|tell|til)(?![\p{L}\d])/u', 'tal', self::loose($text)) ?? '';
            $text = preg_replace(['/(?<=[a-z])7|7(?=[a-z])/', '/(?<=[a-z])5|5(?=[a-z])/', '/(?<=[a-z])[23]|[23](?=[a-z])/'], ['h', 'kh', ''], $text) ?? '';
            $text = strtr(str_replace(' ', '', $text), ['gh' => 'g', 'kh' => 'k', 'q' => 'k', 'ou' => 'u', 'oo' => 'u', 'ee' => 'i']);
            $text = strtr($text, ['o' => 'u', 'e' => 'i', 'y' => 'i']);
            $text = preg_replace('/(.)\1+/u', '$1', $text) ?? '';

            return preg_replace('/(?<=[aiu])h$/', '', $text) ?? '';
        });
    }

    /**
     * Whether $text names $name in one of its spellings (see skeleton()): some words in a row
     * (up to one more than $name has), the first possibly with a glued Arabic prefix, spell
     * the same as $name. Names shorter than four letters are left to exact matching.
     */
    public static function spells(string $text, string $name): bool
    {
        $target = self::skeleton($name);
        $words = explode(' ', self::loose($text));
        $longest = substr_count(self::loose($name), ' ') + 2;

        if (mb_strlen($target) < 4) {
            return false;
        }

        foreach (array_keys($words) as $start) {
            for ($length = 1; $length <= $longest && $start + $length <= count($words); $length++) {
                $rest = implode(' ', array_slice($words, $start + 1, $length - 1));

                foreach (self::withoutPrefixes($words[$start]) as $first) {
                    if (self::skeleton(trim($first.' '.$rest)) === $target) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * How close $text comes to mentioning $name, allowing a small typo: 0 for an exact
     * mention, the total edit distance for a close one, null when none is close. Each word
     * may be a typo as wordDistance() allows; in a name of several words, one short word may
     * also be one letter off when the others match exactly ("كرت قدم" for "كرة قدم"). Words
     * in $skip (known words that mean something else, such as a day or a common noun) are
     * never read as a typo.
     *
     * @param  list<string>  $skip  loose() forms
     */
    public static function closeness(string $text, string $name, array $skip = []): ?int
    {
        if (self::mentions($text, $name)) {
            return 0;
        }

        $nameWords = self::words($name);
        $textWords = self::words($text);
        $best = null;

        for ($start = 0; $nameWords !== [] && $start + count($nameWords) <= count($textWords); $start++) {
            $distances = [];

            foreach ($nameWords as $offset => $word) {
                $textWord = $textWords[$start + $offset];
                $distances[] = in_array(self::foldEnding($textWord), $skip, true) ? null : self::wordDistance($textWord, $word);
            }

            $unread = array_keys($distances, null, true);

            // The one word left may be a short typo when every other word matches exactly.
            if (count($nameWords) > 1 && count($unread) === 1 && array_sum($distances) === 0
                && self::oneLetterOff($textWords[$start + $unread[0]], $nameWords[$unread[0]])) {
                $distances[$unread[0]] = 1;
                $unread = [];
            }

            if ($unread === []) {
                $best = min($best ?? PHP_INT_MAX, array_sum($distances));
            }
        }

        return $best;
    }

    /**
     * 0 when $word is $target (an Arabic word may carry a glued prefix such as ب، بال، و، ع),
     * the edit distance when it is a likely typo of it, otherwise null. Only typos that keep
     * a word recognisable count, so names and everyday words are not read as another word
     * ("jalal" is not "jalaa", "panel" not "padel", "الجبالي" not "جباليا"):
     * - the same first letter on both sides;
     * - two neighbouring letters swapped, from five letters on ("padle", "toady");
     * - or one edit (two from eight letters on) when $target has six letters or more and
     *   $word five or more, and both end with the same letter unless $target has eight or
     *   more (a changed ending usually makes another word: a name, a suffix).
     */
    public static function wordDistance(string $word, string $target): ?int
    {
        $best = null;
        $length = mb_strlen($target);

        foreach (self::withoutPrefixes($word) as $candidate) {
            if ($candidate === $target) {
                return 0;
            }

            if (mb_substr($candidate, 0, 1) !== mb_substr($target, 0, 1)) {
                continue;
            }

            $distance = self::distance($candidate, $target);
            $close = match (true) {
                $length >= 5 && self::isSwap($candidate, $target) => true,
                $length < 6 || mb_strlen($candidate) < 5 => false,
                $length < 8 && mb_substr($candidate, -1) !== mb_substr($target, -1) => false,
                default => $distance <= ($length >= 8 ? 2 : 1),
            };

            if ($close && ($best === null || $distance < $best)) {
                $best = $distance;
            }
        }

        return $best;
    }

    /**
     * Whether $a is $b with two neighbouring letters swapped.
     */
    private static function isSwap(string $a, string $b): bool
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $diff = array_keys(array_diff_assoc($a, $b));

        return count($a) === count($b) && count($diff) === 2 && $diff[1] === $diff[0] + 1
            && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
    }

    /**
     * Whether $word is $target or one letter off it, for words of three letters or more with
     * the same first letter (a glued Arabic prefix is allowed). Too loose to pick a name on its
     * own; used inside a name of several words, and to stay on the safe side of "not covered".
     */
    public static function oneLetterOff(string $word, string $target): bool
    {
        foreach (self::withoutPrefixes($word) as $candidate) {
            if (mb_strlen($candidate) >= 3 && mb_strlen($target) >= 3
                && mb_substr($candidate, 0, 1) === mb_substr($target, 0, 1) && self::distance($candidate, $target) <= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Edit distance over characters (not bytes) where swapping two neighbours is one edit.
     */
    public static function distance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $rows = [];

        for ($i = 0; $i <= count($a); $i++) {
            for ($j = 0; $j <= count($b); $j++) {
                $rows[$i][$j] = match (true) {
                    $i === 0 => $j,
                    $j === 0 => $i,
                    default => min(
                        $rows[$i - 1][$j] + 1,
                        $rows[$i][$j - 1] + 1,
                        $rows[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),
                    ),
                };

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $rows[$i][$j] = min($rows[$i][$j], $rows[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $rows[count($a)][count($b)];
    }

    /**
     * The word, and for Arabic the word without a glued prefix.
     *
     * @return list<string>
     */
    private static function withoutPrefixes(string $word): array
    {
        $variants = [$word];

        if (preg_match('/^\p{Arabic}/u', $word) === 1) {
            foreach (['وبال', 'بال', 'وال', 'فال', 'عال', 'لل', 'ال', 'و', 'ب', 'ل', 'ف', 'ع'] as $prefix) {
                if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= 2) {
                    $variants[] = mb_substr($word, mb_strlen($prefix));
                }
            }
        }

        return $variants;
    }

    /**
     * Whether $text mentions $name as whole words. Latin names must match whole words;
     * Arabic names may be glued to a prefix (ب، بال، و، ل، لل، ع...) and written with or
     * without spaces ("خان يونس"), but never matched inside another word, so "جلال" is not
     * "الجلاء" and "تنسى" is not "تنس".
     */
    public static function mentions(string $text, string $name): bool
    {
        $name = self::loose($name);
        $text = self::loose($text);

        if ($name === '') {
            return false;
        }

        if (preg_match('/^[a-z0-9 ]+$/', $name)) {
            return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text);
        }

        $target = str_replace(' ', '', $name);
        $words = explode(' ', $text);
        $longest = substr_count($name, ' ') + 2;

        foreach (array_keys($words) as $start) {
            for ($length = 1; $length <= $longest && $start + $length <= count($words); $length++) {
                $rest = implode('', array_slice($words, $start + 1, $length - 1));

                foreach (self::withoutPrefixes($words[$start]) as $first) {
                    if ($first.$rest === $target) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether most of the letters are Arabic, so "football at 6pm in الجلاء" is still English.
     */
    public static function isArabic(string $text): bool
    {
        return preg_match_all('/\p{Arabic}/u', $text) > preg_match_all('/\p{Latin}/u', $text);
    }
}
