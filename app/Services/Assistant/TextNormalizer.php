<?php

namespace App\Services\Assistant;

/**
 * Normalises Arabic and English text so names match regardless of case, harakat,
 * tatweel or the letters players swap when typing Arabic (أ/إ/آ/ٱ as ا, ة as ه, ى as ي).
 * It runs in PHP, so matching behaves the same on MySQL and SQLite.
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

    public static function normalize(string $text): string
    {
        $text = strtr(self::stripDiacritics(mb_strtolower($text)), self::LETTER_VARIANTS);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function stripDiacritics(string $text): string
    {
        return preg_replace(self::DIACRITICS, '', $text) ?? '';
    }
}
