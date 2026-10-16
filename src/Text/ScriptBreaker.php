<?php

declare(strict_types=1);

namespace IvoPdf\Text;

use IvoPdf\Css\ComputedStyle;

/**
 * Line breaking inside "words" of scripts written without spaces: CJK ideographs, kana and Hangul (UAX #14 essentials
 * with kinsoku rules, CSS `word-break: keep-all | break-all`, `line-break: strict | anywhere`) and Thai / Lao / Khmer /
 * Myanmar (word segmentation with ICU's dictionaries when ext-intl is available, syllable heuristics otherwise).
 */
final class ScriptBreaker
{
    /** Quick test: does the string contain any character that needs script-specific break handling? */
    public const GATE = '/[\x{0E01}-\x{0EFF}\x{1000}-\x{109F}\x{1100}-\x{11FF}\x{1780}-\x{17FF}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7FF}\x{F900}-\x{FAFF}\x{FE30}-\x{FE6F}\x{FF00}-\x{FFEF}\x{20000}-\x{3FFFF}]/u';

    private const AL = 0;   // alphabetic / everything else: never breaks inside a run
    private const ID = 1;   // ideographs, kana, CJK symbols: break allowed on both sides
    private const OP = 2;   // opening punctuation: no break after
    private const CL = 3;   // closing punctuation, stops, exclamation: no break before
    private const NS = 4;   // non-starters (ellipsis, iteration marks, middle dots): no break before
    private const HG = 5;   // Hangul syllables
    private const SA = 6;   // Thai, Lao, Khmer, Myanmar (dictionary / syllable breaking)
    private const B2 = 7;   // em dash: no break between two of them
    private const CJ = 8;   // small kana and the prolonged sound mark: non-starters only in `line-break: strict`

    /** @var array<int, true> opening punctuation and prefix symbols (no break after) */
    private const OPENING = [
        0x2018 => true, 0x201C => true, 0x3008 => true, 0x300A => true, 0x300C => true, 0x300E => true, 0x3010 => true,
        0x3014 => true, 0x3016 => true, 0x3018 => true, 0x301A => true, 0x301D => true, 0xFF08 => true, 0xFF3B => true,
        0xFF5B => true, 0xFF5F => true, 0xFF62 => true, 0xFE59 => true, 0xFE5B => true, 0xFE5D => true, 0xFF04 => true,
        0xFFE5 => true, 0xFFE1 => true,
    ];

    /** @var array<int, true> closing punctuation / terminators (no break before) */
    private const CLOSING = [
        0x2019 => true, 0x201D => true, 0x3001 => true, 0x3002 => true, 0x3009 => true, 0x300B => true, 0x300D => true,
        0x300F => true, 0x3011 => true, 0x3015 => true, 0x3017 => true, 0x3019 => true, 0x301B => true, 0x301E => true,
        0x301F => true, 0xFF01 => true, 0xFF09 => true, 0xFF0C => true, 0xFF0E => true, 0xFF1A => true, 0xFF1B => true,
        0xFF1F => true, 0xFF3D => true, 0xFF5D => true, 0xFF60 => true, 0xFF61 => true, 0xFF63 => true, 0xFF64 => true,
        0xFF05 => true, 0xFE50 => true, 0xFE52 => true, 0xFE54 => true, 0xFE55 => true, 0xFE56 => true, 0xFE57 => true,
        0xFE5A => true, 0xFE5C => true, 0xFE5E => true, 0xFFE0 => true,
    ];

    /** @var array<int, true> non-starters (no break before) */
    private const NONSTART = [
        0x2025 => true, 0x2026 => true, 0x203C => true, 0x2047 => true, 0x2048 => true, 0x2049 => true, 0x3005 => true,
        0x3003 => true, 0x301C => true, 0x303B => true, 0x309B => true, 0x309C => true, 0x309D => true, 0x309E => true,
        0x30A0 => true, 0x30FB => true, 0x30FD => true, 0x30FE => true, 0xFF65 => true, 0xFF9E => true, 0xFF9F => true,
        0x0E46 => true, 0x0EC6 => true, 0x17D4 => true, 0x17D5 => true, 0x17D6 => true, 0x17D7 => true, 0x17D8 => true,
        0x17D9 => true, 0x17DA => true, 0x17DC => true, 0x104A => true, 0x104B => true, 0x104C => true, 0x104D => true,
        0x104E => true, 0x104F => true,
    ];

    /** @var array<int, true> small kana, prolonged sound mark (conditional starters) */
    private const CONDITIONAL = [
        0x3041 => true, 0x3043 => true, 0x3045 => true, 0x3047 => true, 0x3049 => true, 0x3063 => true, 0x3083 => true,
        0x3085 => true, 0x3087 => true, 0x308E => true, 0x3095 => true, 0x3096 => true, 0x30A1 => true, 0x30A3 => true,
        0x30A5 => true, 0x30A7 => true, 0x30A9 => true, 0x30C3 => true, 0x30E3 => true, 0x30E5 => true, 0x30E7 => true,
        0x30EE => true, 0x30F5 => true, 0x30F6 => true, 0x30FC => true, 0xFF70 => true,
    ];

    public static function needsSegmentation(string $word): bool
    {
        return preg_match(self::GATE, $word) === 1;
    }

    /**
     * Whether the text starts with a CJK character (ideograph, kana, Hangul or CJK punctuation) as opposed to
     * Thai-like scripts, where inter-character justification would split syllables.
     */
    public static function isCjkText(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $c = self::classOf((int) mb_ord($text, 'UTF-8'));
        return $c !== self::AL && $c !== self::SA;
    }

    /**
     * CSS Text 3 segment-break transformation: a source line break between two East Asian wide characters
     * (Han, kana, full-width forms) is removed instead of becoming a space.
     */
    public static function joinSegmentBreaks(string $text): string
    {
        if (!str_contains($text, "\n") && !str_contains($text, "\r")) {
            return $text;
        }
        $wide = '[\x{2E80}-\x{A4CF}\x{F900}-\x{FAFF}\x{FE30}-\x{FE6F}\x{FF00}-\x{FFEF}\x{20000}-\x{3FFFF}]';
        return preg_replace('/(?<=' . $wide . ')[ \t\f]*[\r\n]+[ \t\r\n\f]*(?=' . $wide . ')/u', '', $text) ?? $text;
    }

    /**
     * 0 normal, 1 `word-break: break-all`, 2 `line-break: anywhere`, 3 `word-break: keep-all`.
     */
    private static function mode(ComputedStyle $style): int
    {
        if (strtolower(trim($style->get('line-break', 'auto'))) === 'anywhere') {
            return 2;
        }
        $wb = strtolower(trim($style->get('word-break', 'normal')));
        return $wb === 'break-all' ? 1 : ($wb === 'keep-all' ? 3 : 0);
    }

    /**
     * Break class of a codepoint.
     */
    public static function classOf(int $cp, bool $strict = false): int
    {
        if ($cp < 0x0E00) {
            return match (true) {
                $cp === 0x2018 || $cp === 0x201C => self::OP,
                $cp === 0x2019 || $cp === 0x201D => self::CL,
                $cp === 0x2014 => self::B2,
                $cp === 0x2025 || $cp === 0x2026 || $cp === 0x203C || $cp === 0x2047 || $cp === 0x2048 || $cp === 0x2049 => self::NS,
                $cp >= 0x1100 && $cp <= 0x11FF => self::HG,
                $cp >= 0x1000 && $cp <= 0x109F => isset(self::NONSTART[$cp]) ? self::NS : self::SA,
                default => self::AL,
            };
        }
        if ($cp <= 0x0EFF) {
            return isset(self::NONSTART[$cp]) ? self::NS : ($cp === 0x0E3F ? self::AL : self::SA);
        }
        if ($cp >= 0x1780 && $cp <= 0x17FF) {
            return isset(self::NONSTART[$cp]) ? self::NS : self::SA;
        }
        if (($cp >= 0xAC00 && $cp <= 0xD7FF) || ($cp >= 0x3130 && $cp <= 0x318F) || ($cp >= 0xA960 && $cp <= 0xA97F) || ($cp >= 0xFFA0 && $cp <= 0xFFDC)) {
            return self::HG;
        }
        if (isset(self::OPENING[$cp])) {
            return self::OP;
        }
        if (isset(self::CLOSING[$cp])) {
            return self::CL;
        }
        if (isset(self::NONSTART[$cp])) {
            return self::NS;
        }
        if (isset(self::CONDITIONAL[$cp])) {
            return $strict ? self::NS : self::CJ;
        }
        if (($cp >= 0x2E80 && $cp <= 0xA4CF) || ($cp >= 0xF900 && $cp <= 0xFAFF) || ($cp >= 0xFE30 && $cp <= 0xFE6F)
            || ($cp >= 0xFF00 && $cp <= 0xFFEF) || ($cp >= 0x1B000 && $cp <= 0x1B16F) || ($cp >= 0x20000 && $cp <= 0x3FFFF)) {
            return self::ID;
        }
        return self::AL;
    }

    /**
     * Whether a line may break between two adjacent clusters of the given classes.
     */
    private static function allowed(int $a, int $b, int $mode): bool
    {
        if ($mode === 2) {
            return true;
        }
        if ($b === self::CL || $b === self::NS || $a === self::OP) {
            return false;
        }
        if ($b === self::CJ) {
            $b = self::ID;
        }
        if ($a === self::CJ) {
            $a = self::ID;
        }
        if ($a === self::B2 && $b === self::B2) {
            return false;
        }
        if ($mode === 1) {
            return true;
        }
        if ($a === self::SA && $b === self::SA) {
            return false; // decided by the word segmenter
        }
        if ($a === self::AL && $b === self::AL) {
            return false;
        }
        if ($mode === 3 && ($a === self::ID || $a === self::HG || $a === self::AL || $a === self::B2)
            && ($b === self::ID || $b === self::HG || $b === self::AL || $b === self::B2)) {
            return false; // keep-all: CJK letters stay together
        }
        return true;
    }

    /**
     * Whether a break between two adjacent text fragments (e.g. across an inline element boundary) is allowed.
     */
    public static function breakBetween(string $prev, string $next, ComputedStyle $style): bool
    {
        if ($prev === '' || $next === '') {
            return false;
        }
        $strict = strtolower(trim($style->get('line-break', 'auto'))) === 'strict';
        $a = self::classOf((int) mb_ord(mb_substr($prev, -1, 1, 'UTF-8'), 'UTF-8'), $strict);
        $b = self::classOf((int) mb_ord(mb_substr($next, 0, 1, 'UTF-8'), 'UTF-8'), $strict);
        return self::allowed($a, $b, self::mode($style));
    }

    /**
     * Splits a word into fragments; a line may break between consecutive fragments.
     *
     * @return list<string>
     */
    public static function segment(string $word, ComputedStyle $style): array
    {
        if (preg_match_all('/\X/u', $word, $m) < 2) {
            return [$word];
        }
        /** @var list<string> $clusters */
        $clusters = $m[0];
        $mode = self::mode($style);
        $strict = strtolower(trim($style->get('line-break', 'auto'))) === 'strict';

        $classes = [];
        $hasSa = false;
        foreach ($clusters as $i => $cl) {
            $c = self::classOf((int) mb_ord($cl, 'UTF-8'), $strict);
            $classes[$i] = $c;
            $hasSa = $hasSa || $c === self::SA;
        }
        $saBreak = $hasSa ? self::segmentSoutheastAsian($clusters, $classes) : [];

        $pieces = [];
        $cur = $clusters[0];
        for ($i = 1, $n = count($clusters); $i < $n; $i++) {
            if ($classes[$i - 1] === self::SA && $classes[$i] === self::SA) {
                $ok = $mode === 1 || $mode === 2 || (isset($saBreak[$i]) && $mode !== 3);
            } else {
                $ok = self::allowed($classes[$i - 1], $classes[$i], $mode);
            }
            if ($ok) {
                $pieces[] = $cur;
                $cur = '';
            }
            $cur .= $clusters[$i];
        }
        $pieces[] = $cur;
        return $pieces;
    }

    /**
     * Word boundaries inside runs of Thai / Lao / Khmer / Myanmar clusters: [cluster index => true] where a break
     * before that cluster is allowed.
     *
     * @param list<string> $clusters
     * @param array<int, int> $classes
     * @return array<int, true>
     */
    private static function segmentSoutheastAsian(array $clusters, array $classes): array
    {
        $breaks = [];
        $n = count($clusters);
        $i = 0;
        while ($i < $n) {
            if ($classes[$i] !== self::SA) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $classes[$j] === self::SA) {
                $j++;
            }
            foreach (self::segmentRun(array_slice($clusters, $i, $j - $i)) as $rel) {
                $breaks[$i + $rel] = true;
            }
            $i = $j;
        }
        return $breaks;
    }

    /**
     * @param list<string> $run clusters of one SEA-script run
     * @return list<int> indices (>0) of clusters that start a new word/syllable
     */
    private static function segmentRun(array $run): array
    {
        $count = count($run);
        if ($count < 2) {
            return [];
        }
        $out = [];
        if (class_exists(\IntlBreakIterator::class)) {
            $it = \IntlBreakIterator::createWordInstance('th');
            if ($it !== null) {
                $it->setText(implode('', $run));
                $starts = [];
                $pos = 0;
                foreach ($run as $k => $cl) {
                    $starts[$pos] = $k;
                    $pos += strlen($cl);
                }
                foreach ($it as $b) {
                    if ($b > 0 && isset($starts[$b])) {
                        $out[] = $starts[$b];
                    }
                }
                if ($out !== [] || $count < 12) {
                    return $out;
                }
            }
        }
        // Heuristic syllable breaks without ICU. Thai / Lao: before a leading vowel, and before a consonant that
        // follows a syllable-final vowel sign. Khmer: before an independent letter that is not a subscript consonant.
        for ($k = 1; $k < $count; $k++) {
            $cp = (int) mb_ord($run[$k], 'UTF-8');
            $prev = (int) mb_ord(mb_substr($run[$k - 1], -1, 1, 'UTF-8'), 'UTF-8');
            if (($prev >= 0x0E40 && $prev <= 0x0E44) || ($prev >= 0x0EC0 && $prev <= 0x0EC4)) {
                continue;
            }
            $leading = ($cp >= 0x0E40 && $cp <= 0x0E44) || ($cp >= 0x0EC0 && $cp <= 0x0EC4);
            $consonant = ($cp >= 0x0E01 && $cp <= 0x0E2E) || ($cp >= 0x0E81 && $cp <= 0x0EAE);
            if ($leading || ($consonant && ($prev === 0x0E30 || $prev === 0x0E33 || $prev === 0x0EB0 || $prev === 0x0EB3))) {
                $out[] = $k;
            } elseif ($cp >= 0x1780 && $cp <= 0x17B3 && $prev !== 0x17D2) {
                $out[] = $k;
            }
        }
        return $out;
    }
}
