<?php

namespace App\Services\Assistant;

/**
 * Normalizes Arabic and English text so names can be matched regardless of
 * diacritics, letter variants (أ/إ/آ, ة/ه, ى/ي), Arabic-Indic digits, the "ال" / "al-"
 * article, or letters glued to a preceding preposition (e.g. "بالجلاء").
 */
class TextNormalizer
{
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(self::ARABIC_DIGITS, self::LATIN_DIGITS, $text);
        $text = str_replace(['a.m.', 'p.m.', 'a.m', 'p.m', '٫', '：'], ['am', 'pm', 'am', 'pm', '.', ':'], $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ا', 'ه', 'ي'], $text);
        $text = preg_replace('/(?<!\d)-|-(?!\d)/u', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}:\/\-\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * normalize() without articles, for comparing place and sport names.
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
