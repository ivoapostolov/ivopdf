<?php

declare(strict_types=1);

namespace IvoPdf\Text;

/**
 * Unicode script detection (ISO 15924 codes) for the scripts IvoPdf can pick fonts for, plus the language
 * tag handling used to choose between Han typefaces (Japanese / Simplified / Traditional Chinese / Korean).
 */
final class Scripts
{
    /**
     * Sorted [from, to, script] ranges. Everything not listed is 'Zyyy' (common) / 'Latn' below U+0250.
     *
     * @var list<array{0: int, 1: int, 2: string}>
     */
    private const RANGES = [
        [0x0370, 0x03FF, 'Grek'], [0x0400, 0x052F, 'Cyrl'], [0x0530, 0x058F, 'Armn'], [0x0590, 0x05FF, 'Hebr'],
        [0x0600, 0x06FF, 'Arab'], [0x0700, 0x074F, 'Syrc'], [0x0750, 0x077F, 'Arab'], [0x0780, 0x07BF, 'Thaa'],
        [0x07C0, 0x07FF, 'Nkoo'], [0x08A0, 0x08FF, 'Arab'], [0x0900, 0x097F, 'Deva'], [0x0980, 0x09FF, 'Beng'],
        [0x0A00, 0x0A7F, 'Guru'], [0x0A80, 0x0AFF, 'Gujr'], [0x0B00, 0x0B7F, 'Orya'], [0x0B80, 0x0BFF, 'Taml'],
        [0x0C00, 0x0C7F, 'Telu'], [0x0C80, 0x0CFF, 'Knda'], [0x0D00, 0x0D7F, 'Mlym'], [0x0D80, 0x0DFF, 'Sinh'],
        [0x0E00, 0x0E7F, 'Thai'], [0x0E80, 0x0EFF, 'Laoo'], [0x0F00, 0x0FFF, 'Tibt'], [0x1000, 0x109F, 'Mymr'],
        [0x10A0, 0x10FF, 'Geor'], [0x1100, 0x11FF, 'Hang'], [0x1200, 0x139F, 'Ethi'], [0x13A0, 0x13FF, 'Cher'],
        [0x1780, 0x17FF, 'Khmr'], [0x1800, 0x18AF, 'Mong'], [0x19E0, 0x19FF, 'Khmr'], [0x1E00, 0x1EFF, 'Latn'],
        [0x1F00, 0x1FFF, 'Grek'], [0x2E80, 0x303F, 'Hani'], [0x3041, 0x309F, 'Hira'], [0x30A0, 0x30FF, 'Kana'], [0x3100, 0x312F, 'Bopo'],
        [0x3130, 0x318F, 'Hang'], [0x31A0, 0x31BF, 'Bopo'], [0x31F0, 0x31FF, 'Kana'], [0x3200, 0x33FF, 'Hani'], [0x3400, 0x4DBF, 'Hani'],
        [0x4E00, 0x9FFF, 'Hani'], [0xA000, 0xA4CF, 'Yiii'], [0xA960, 0xA97F, 'Hang'], [0xAA60, 0xAA7F, 'Mymr'],
        [0xAC00, 0xD7FF, 'Hang'], [0xF900, 0xFAFF, 'Hani'], [0xFB1D, 0xFB4F, 'Hebr'], [0xFB50, 0xFDFF, 'Arab'],
        [0xFE30, 0xFE6F, 'Hani'], [0xFE70, 0xFEFF, 'Arab'], [0xFF00, 0xFF65, 'Hani'], [0xFF66, 0xFF9F, 'Kana'], [0xFFA0, 0xFFDC, 'Hang'], [0xFFE0, 0xFFEF, 'Hani'], [0x1B000, 0x1B16F, 'Kana'],
        [0x20000, 0x3FFFF, 'Hani'],
    ];

    /** Human-readable script names for warnings. */
    private const NAMES = [
        'Hani' => 'Han (CJK ideographs)', 'Hira' => 'Hiragana', 'Kana' => 'Katakana', 'Hang' => 'Hangul', 'Bopo' => 'Bopomofo',
        'Deva' => 'Devanagari', 'Beng' => 'Bengali', 'Guru' => 'Gurmukhi', 'Gujr' => 'Gujarati', 'Orya' => 'Oriya',
        'Taml' => 'Tamil', 'Telu' => 'Telugu', 'Knda' => 'Kannada', 'Mlym' => 'Malayalam', 'Sinh' => 'Sinhala',
        'Thai' => 'Thai', 'Laoo' => 'Lao', 'Khmr' => 'Khmer', 'Mymr' => 'Myanmar', 'Tibt' => 'Tibetan',
        'Arab' => 'Arabic', 'Hebr' => 'Hebrew', 'Armn' => 'Armenian', 'Geor' => 'Georgian', 'Ethi' => 'Ethiopic',
        'Grek' => 'Greek', 'Cyrl' => 'Cyrillic', 'Thaa' => 'Thaana', 'Syrc' => 'Syriac', 'Mong' => 'Mongolian',
        'Yiii' => 'Yi', 'Cher' => 'Cherokee', 'Nkoo' => "N'Ko",
    ];

    /**
     * ISO 15924 script of a codepoint ('Latn' for Latin, 'Zyyy' for common/unknown characters).
     */
    public static function of(int $cp): string
    {
        if ($cp < 0x0250) {
            return ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A) || $cp >= 0xC0 ? 'Latn' : 'Zyyy';
        }
        foreach (self::RANGES as [$from, $to, $script]) {
            if ($cp < $from) {
                break;
            }
            if ($cp <= $to) {
                return $script;
            }
        }
        return 'Zyyy';
    }

    public static function name(string $script): string
    {
        return self::NAMES[$script] ?? $script;
    }

    /**
     * Whether text in this script normally needs a font beyond the bundled Latin/Cyrillic/Greek one.
     */
    public static function needsFont(string $script): bool
    {
        return isset(self::NAMES[$script]) && !in_array($script, ['Cyrl', 'Grek'], true);
    }

    /**
     * Normalises a BCP 47 tag to the variant keys used by `scriptFonts` ("ja", "ko", "zh-CN", "zh-TW", ...).
     */
    public static function langKey(string $lang): string
    {
        $l = strtolower(str_replace('_', '-', trim($lang)));
        if ($l === '') {
            return '';
        }
        if (str_starts_with($l, 'zh')) {
            return preg_match('/^zh-(tw|hk|mo|hant)/', $l) === 1 ? 'zh-TW' : 'zh-CN';
        }
        return explode('-', $l)[0];
    }

    /**
     * Script keys tried (in order) in a `scriptFonts` map for a character: language specific first.
     * Kana and Han in Japanese text also match 'Jpan'; Hangul also matches 'Kore'; Han in zh also matches 'Hans'/'Hant'.
     *
     * @return list<string>
     */
    public static function fontKeys(string $script, string $lang): array
    {
        $lk = self::langKey($lang);
        $keys = [];
        if ($lk !== '') {
            $keys[] = $script . ':' . $lk;
            if (in_array($script, ['Hani', 'Hira', 'Kana', 'Hang', 'Bopo'], true)) {
                $keys[] = $lk;
            }
        }
        $keys[] = $script;
        switch ($script) {
            case 'Hira':
            case 'Kana':
                $keys[] = 'Jpan';
                $keys[] = 'ja';
                break;
            case 'Hang':
                $keys[] = 'Kore';
                $keys[] = 'ko';
                break;
            case 'Hani':
                $keys[] = $lk === 'ja' ? 'Jpan' : ($lk === 'ko' ? 'Kore' : ($lk === 'zh-TW' ? 'Hant' : 'Hans'));
                if ($lk === '') {
                    $keys[] = 'Hans';
                }
                break;
            case 'Bopo':
                $keys[] = 'Hant';
                break;
        }
        return array_values(array_unique($keys));
    }
}
