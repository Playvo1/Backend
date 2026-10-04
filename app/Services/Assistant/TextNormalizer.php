<?php

namespace App\Services\Assistant;

/**
 * Normalises Arabic and English text so names match regardless of case, harakat,
 * tatweel, the letters players swap when typing Arabic (أ/إ/آ/ٱ as ا, ة as ه, ى as ي),
 * Arabic-Indic digits or punctuation. It runs in PHP, so matching behaves the same
 * on MySQL and SQLite.
 */
class TextNormalizer
{
    /**
     * Letters that are commonly written in place of one another.
     */
    public const AMBIGUOUS_LETTERS = ['ا', 'أ', 'إ', 'آ', 'ٱ', 'ة', 'ه', 'ى', 'ي'];

    /**
     * Harakat (U+064B-U+065F), superscript alef (U+0670) and tatweel (U+0640).
     */
    private const DIACRITICS = '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u';

    private const LETTER_VARIANTS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private const SYMBOLS = ['a.m.' => 'am', 'p.m.' => 'pm', 'a.m' => 'am', 'p.m' => 'pm', '٫' => '.', '：' => ':'];

    /**
     * Lower-case, unified letters and digits, punctuation turned into spaces (":", "/"
     * and a hyphen between digits are kept for times and dates), single spaces.
     */
    public static function normalize(string $text): string
    {
        $text = strtr(self::stripDiacritics(mb_strtolower($text)), self::LETTER_VARIANTS + self::DIGITS);
        $text = strtr($text, self::SYMBOLS);
        $text = preg_replace('/(?<!\d)-|-(?!\d)/u', ' ', $text) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}:\/\-\s]/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function stripDiacritics(string $text): string
    {
        return preg_replace(self::DIACRITICS, '', $text) ?? '';
    }

    /**
     * normalize() without articles ("ال", "al", "el"), for comparing place and sport names.
     */
    public static function loose(string $text): string
    {
        $words = array_filter(
            explode(' ', self::normalize($text)),
            fn (string $word) => $word !== 'al' && $word !== 'el',
        );

        $words = array_map(
            fn (string $word) => mb_strlen($word) > 3 && str_starts_with($word, 'ال') ? mb_substr($word, 2) : $word,
            $words,
        );

        return implode(' ', $words);
    }

    /**
     * Whether $text mentions $name. Latin names must match whole words; Arabic names may
     * be glued to a prefix (ب، ل، في...) or written with or without spaces ("خان يونس").
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

        return str_contains(str_replace(' ', '', $text), str_replace(' ', '', $name));
    }

    /**
     * Whether most of the letters are Arabic, so "football at 6pm in الجلاء" is still English.
     */
    public static function isArabic(string $text): bool
    {
        return preg_match_all('/\p{Arabic}/u', $text) > preg_match_all('/\p{Latin}/u', $text);
    }
}
