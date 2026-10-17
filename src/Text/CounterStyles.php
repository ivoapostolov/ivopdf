<?php

declare(strict_types=1);

namespace IvoPdf\Text;

/**
 * Non-Latin predefined counter styles (CSS Counter Styles 3): the additive systems of armenian / georgian / hebrew,
 * the numeric cjk-decimal, the traditional-Chinese cjk-ideographic and upper-greek. A value outside the range of the
 * style is shown in decimal (the style's fallback) but keeps the style's suffix.
 */
final class CounterStyles
{
    /** Largest magnitude ever formatted (matches the counter clamp of the DOM builder, keeps loops bounded). */
    private const MAX = 2147483647;

    private const ARMENIAN = [
        9000 => 'Ք', 8000 => 'Փ', 7000 => 'Ւ', 6000 => 'Ց', 5000 => 'Ր', 4000 => 'Տ', 3000 => 'Վ', 2000 => 'Ս', 1000 => 'Ռ',
        900 => 'Ջ', 800 => 'Պ', 700 => 'Չ', 600 => 'Ո', 500 => 'Շ', 400 => 'Ն', 300 => 'Յ', 200 => 'Մ', 100 => 'Ճ',
        90 => 'Ղ', 80 => 'Ձ', 70 => 'Հ', 60 => 'Կ', 50 => 'Ծ', 40 => 'Խ', 30 => 'Լ', 20 => 'Ի', 10 => 'Ժ',
        9 => 'Թ', 8 => 'Ը', 7 => 'Է', 6 => 'Զ', 5 => 'Ե', 4 => 'Դ', 3 => 'Գ', 2 => 'Բ', 1 => 'Ա',
    ];

    private const GEORGIAN = [
        10000 => 'ჵ', 9000 => 'ჰ', 8000 => 'ჯ', 7000 => 'ჴ', 6000 => 'ხ', 5000 => 'ჭ', 4000 => 'წ', 3000 => 'ძ', 2000 => 'ც', 1000 => 'ჩ',
        900 => 'შ', 800 => 'ყ', 700 => 'ღ', 600 => 'ქ', 500 => 'ფ', 400 => 'ჳ', 300 => 'ტ', 200 => 'ს', 100 => 'რ',
        90 => 'ჟ', 80 => 'პ', 70 => 'ო', 60 => 'ჲ', 50 => 'ნ', 40 => 'მ', 30 => 'ლ', 20 => 'კ', 10 => 'ი',
        9 => 'თ', 8 => 'ჱ', 7 => 'ზ', 6 => 'ვ', 5 => 'ე', 4 => 'დ', 3 => 'გ', 2 => 'ბ', 1 => 'ა',
    ];

    private const HEBREW = [
        10000 => "י\u{05F3}", 9000 => "ט\u{05F3}", 8000 => "ח\u{05F3}", 7000 => "ז\u{05F3}", 6000 => "ו\u{05F3}",
        5000 => "ה\u{05F3}", 4000 => "ד\u{05F3}", 3000 => "ג\u{05F3}", 2000 => "ב\u{05F3}", 1000 => "א\u{05F3}",
        400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק', 90 => 'צ', 80 => 'פ', 70 => 'ע', 60 => 'ס', 50 => 'נ', 40 => 'מ',
        30 => 'ל', 20 => 'כ', 19 => 'יט', 18 => 'יח', 17 => 'יז', 16 => 'טז', 15 => 'טו', 10 => 'י',
        9 => 'ט', 8 => 'ח', 7 => 'ז', 6 => 'ו', 5 => 'ה', 4 => 'ד', 3 => 'ג', 2 => 'ב', 1 => 'א',
    ];

    private const GREEK_UPPER = 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ';

    public static function supports(string $type): bool
    {
        return in_array($type, ['armenian', 'upper-armenian', 'lower-armenian', 'georgian', 'hebrew', 'cjk-decimal',
            'cjk-ideographic', 'trad-chinese-informal', 'upper-greek'], true);
    }

    /** Marker text including the style's suffix ('.' as for the other list styles; CJK styles use U+3001). */
    public static function marker(int $num, string $type): string
    {
        return self::body($num, $type) . (in_array($type, ['cjk-decimal', 'cjk-ideographic', 'trad-chinese-informal'], true) ? "\u{3001}" : '.');
    }

    /** The counter value without a suffix, decimal when $num is outside the range of the style. */
    public static function body(int $num, string $type): string
    {
        $num = max(-self::MAX, min(self::MAX, $num));
        $out = match ($type) {
            'armenian', 'upper-armenian' => $num >= 1 && $num <= 9999 ? self::additive($num, self::ARMENIAN) : null,
            'lower-armenian' => $num >= 1 && $num <= 9999 ? mb_strtolower(self::additive($num, self::ARMENIAN), 'UTF-8') : null,
            'georgian' => $num >= 1 && $num <= 19999 ? self::additive($num, self::GEORGIAN) : null,
            'hebrew' => $num >= 1 && $num <= 10999 ? self::additive($num, self::HEBREW) : null,
            'cjk-decimal' => $num >= 0 ? strtr((string) $num, ['0' => '〇', '1' => '一', '2' => '二', '3' => '三', '4' => '四',
                '5' => '五', '6' => '六', '7' => '七', '8' => '八', '9' => '九']) : null,
            'cjk-ideographic', 'trad-chinese-informal' => abs($num) <= 9999 ? self::chinese($num) : null,
            'upper-greek' => $num >= 1 ? self::alphabetic($num, preg_split('//u', self::GREEK_UPPER, -1, PREG_SPLIT_NO_EMPTY) ?: ['Α']) : null,
            default => null,
        };
        return $out ?? (string) $num;
    }

    /** @param array<int, string> $symbols weight => symbol, descending */
    private static function additive(int $num, array $symbols): string
    {
        $out = '';
        foreach ($symbols as $weight => $symbol) {
            if ($num >= $weight) {
                $times = intdiv($num, $weight);
                $out .= str_repeat($symbol, $times);
                $num -= $times * $weight;
            }
            if ($num === 0) {
                break;
            }
        }
        return $out;
    }

    /** @param list<string> $alphabet */
    private static function alphabetic(int $num, array $alphabet): string
    {
        $base = count($alphabet);
        $out = '';
        while ($num > 0) {
            $num--;
            $out = $alphabet[$num % $base] . $out;
            $num = intdiv($num, $base);
        }
        return $out;
    }

    /** Traditional Chinese informal numerals, -9999..9999: 10 -> 十, 101 -> 一百零一, 1010 -> 一千零一十. */
    private static function chinese(int $num): string
    {
        if ($num === 0) {
            return '零';
        }
        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        $places = [1000 => '千', 100 => '百', 10 => '十', 1 => ''];
        $neg = $num < 0;
        $n = abs($num);
        $out = '';
        $pendingZero = false;
        foreach ($places as $weight => $unit) {
            $d = intdiv($n, $weight) % 10;
            if ($d === 0) {
                $pendingZero = $out !== '';
                continue;
            }
            if ($pendingZero) {
                $out .= '零';
                $pendingZero = false;
            }
            $out .= ($weight === 10 && $d === 1 && $out === '' ? '' : $digits[$d]) . $unit;
        }
        return ($neg ? '負' : '') . $out;
    }
}
