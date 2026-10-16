<?php

declare(strict_types=1);

namespace IvoPdf\Text;

/**
 * Unicode Bidirectional Algorithm (UAX #9): explicit embeddings/overrides/isolates (X1-X10), weak and neutral
 * type resolution including paired brackets (W1-W7, N0-N2), implicit levels (I1-I2), L1 and per-line reordering (L2).
 *
 * Bidi classes are derived from a compact range table covering the scripts relevant for documents
 * (Latin, Hebrew, Arabic, Syriac, Thaana, N'Ko, punctuation and symbols); other characters default to L.
 */
final class Bidi
{
    public const L = 0;
    public const R = 1;
    public const AL = 2;
    public const EN = 3;
    public const ES = 4;
    public const ET = 5;
    public const AN = 6;
    public const CS = 7;
    public const NSM = 8;
    public const BN = 9;
    public const B = 10;
    public const S = 11;
    public const WS = 12;
    public const ON = 13;
    public const LRE = 14;
    public const LRO = 15;
    public const RLE = 16;
    public const RLO = 17;
    public const PDF = 18;
    public const LRI = 19;
    public const RLI = 20;
    public const FSI = 21;
    public const PDI = 22;

    /** Regex matching text that may need the bidi algorithm (any RTL letter/number/format character). */
    public const RTL_REGEX = '/[\x{0590}-\x{08FF}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u';

    /** @var array<int, int>|null */
    private static ?array $low = null;

    /** @var list<array{0: int, 1: int, 2: int}>|null sorted ranges [from, to, class] */
    private static ?array $ranges = null;

    /** @var array<int, int>|null */
    private static ?array $mirror = null;

    public static function needsBidi(string $text): bool
    {
        return preg_match(self::RTL_REGEX, $text) === 1;
    }

    public static function classOf(int $cp): int
    {
        if ($cp < 0x100) {
            if (self::$low === null) {
                self::initLow();
            }
            return self::$low[$cp];
        }
        if (self::$ranges === null) {
            self::initRanges();
        }
        $r = self::$ranges;
        $lo = 0;
        $hi = count($r) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($cp < $r[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($cp > $r[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return $r[$mid][2];
            }
        }
        return self::L;
    }

    /**
     * Mirrored counterpart of a bracket-like character (or the character itself).
     */
    public static function mirror(int $cp): int
    {
        if (self::$mirror === null) {
            $pairs = [
                [0x28, 0x29], [0x3C, 0x3E], [0x5B, 0x5D], [0x7B, 0x7D], [0xAB, 0xBB], [0x2039, 0x203A], [0x2045, 0x2046],
                [0x207D, 0x207E], [0x208D, 0x208E], [0x2208, 0x220B], [0x2209, 0x220C], [0x220A, 0x220D], [0x2264, 0x2265],
                [0x2266, 0x2267], [0x226A, 0x226B], [0x2308, 0x2309], [0x230A, 0x230B], [0x2329, 0x232A], [0x27E6, 0x27E7],
                [0x27E8, 0x27E9], [0x27EA, 0x27EB], [0x3008, 0x3009], [0x300A, 0x300B], [0x300C, 0x300D], [0x300E, 0x300F],
                [0x3010, 0x3011], [0x3014, 0x3015], [0x3016, 0x3017], [0x3018, 0x3019], [0x301A, 0x301B], [0xFF08, 0xFF09],
                [0xFF1C, 0xFF1E], [0xFF3B, 0xFF3D], [0xFF5B, 0xFF5D],
            ];
            $m = [];
            foreach ($pairs as [$a, $b]) {
                $m[$a] = $b;
                $m[$b] = $a;
            }
            self::$mirror = $m;
        }
        return self::$mirror[$cp] ?? $cp;
    }

    /**
     * First strong direction of a text (0 = LTR, 1 = RTL) per P2/P3, or null when it has no strong character.
     */
    public static function firstStrong(string $text): ?int
    {
        $depth = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            $c = self::classOf(mb_ord($ch, 'UTF-8'));
            if ($c === self::LRI || $c === self::RLI || $c === self::FSI) {
                $depth++;
            } elseif ($c === self::PDI) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0) {
                if ($c === self::L) {
                    return 0;
                }
                if ($c === self::R || $c === self::AL) {
                    return 1;
                }
            }
        }
        return null;
    }

    /**
     * Resolves embedding levels for one paragraph.
     *
     * @param list<int> $cps
     * @param int|null $baseLevel 0/1, or null to detect from the first strong character (P2/P3)
     * @return array{levels: list<int>, base: int}
     */
    public static function resolve(array $cps, ?int $baseLevel = null): array
    {
        $n = count($cps);
        if ($n === 0) {
            return ['levels' => [], 'base' => $baseLevel ?? 0];
        }
        $types = [];
        foreach ($cps as $cp) {
            $types[] = self::classOf($cp);
        }
        $orig = $types;

        // BD9: matching isolate initiators / PDIs
        $matchPdi = array_fill(0, $n, -1);
        $matchInit = array_fill(0, $n, -1);
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $orig[$i];
            if ($t === self::LRI || $t === self::RLI || $t === self::FSI) {
                $stack[] = $i;
            } elseif ($t === self::PDI && $stack !== []) {
                $j = array_pop($stack);
                $matchPdi[$j] = $i;
                $matchInit[$i] = $j;
            }
        }

        $base = $baseLevel ?? (self::firstStrongLevel($orig, $matchPdi, 0, $n) ?? 0);

        // X1-X8
        $levels = array_fill(0, $n, $base);
        $st = [[$base, -1, false]];
        $overflowIsolate = 0;
        $overflowEmbedding = 0;
        $validIsolate = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $orig[$i];
            $top = $st[count($st) - 1];
            switch ($t) {
                case self::RLE:
                case self::LRE:
                case self::RLO:
                case self::LRO:
                    $levels[$i] = $top[0];
                    $rtl = $t === self::RLE || $t === self::RLO;
                    $new = $rtl ? ($top[0] + 1) | 1 : ($top[0] + 2) & ~1;
                    if ($new <= 125 && $overflowIsolate === 0 && $overflowEmbedding === 0) {
                        $ov = $t === self::RLO ? self::R : ($t === self::LRO ? self::L : -1);
                        $st[] = [$new, $ov, false];
                    } elseif ($overflowIsolate === 0) {
                        $overflowEmbedding++;
                    }
                    break;
                case self::RLI:
                case self::LRI:
                case self::FSI:
                    $levels[$i] = $top[0];
                    if ($top[1] !== -1) {
                        $types[$i] = $top[1];
                    }
                    $rtl = $t === self::RLI;
                    if ($t === self::FSI) {
                        $end = $matchPdi[$i] === -1 ? $n : $matchPdi[$i];
                        $rtl = (self::firstStrongLevel($orig, $matchPdi, $i + 1, $end) ?? 0) === 1;
                    }
                    $new = $rtl ? ($top[0] + 1) | 1 : ($top[0] + 2) & ~1;
                    if ($new <= 125 && $overflowIsolate === 0 && $overflowEmbedding === 0) {
                        $validIsolate++;
                        $st[] = [$new, -1, true];
                    } else {
                        $overflowIsolate++;
                    }
                    break;
                case self::PDI:
                    if ($overflowIsolate > 0) {
                        $overflowIsolate--;
                    } elseif ($validIsolate > 0) {
                        $overflowEmbedding = 0;
                        while ($st[count($st) - 1][2] === false) {
                            array_pop($st);
                        }
                        array_pop($st);
                        $validIsolate--;
                    }
                    $top = $st[count($st) - 1];
                    $levels[$i] = $top[0];
                    if ($top[1] !== -1) {
                        $types[$i] = $top[1];
                    }
                    break;
                case self::PDF:
                    $levels[$i] = $top[0];
                    if ($overflowIsolate > 0) {
                        break;
                    }
                    if ($overflowEmbedding > 0) {
                        $overflowEmbedding--;
                    } elseif ($top[2] === false && count($st) >= 2) {
                        array_pop($st);
                    }
                    break;
                case self::B:
                    $levels[$i] = $base;
                    break;
                case self::BN:
                    $levels[$i] = $top[0];
                    break;
                default:
                    $levels[$i] = $top[0];
                    if ($top[1] !== -1) {
                        $types[$i] = $top[1];
                    }
            }
        }

        // X9: removed characters
        $removed = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $orig[$i];
            $removed[$i] = $t === self::RLE || $t === self::LRE || $t === self::RLO || $t === self::LRO || $t === self::PDF || $t === self::BN;
        }

        // X10: level runs
        $runs = [];
        $runOf = [];
        $cur = null;
        $curLevel = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($removed[$i]) {
                continue;
            }
            if ($cur === null || $levels[$i] !== $curLevel) {
                $runs[] = [];
                $cur = count($runs) - 1;
                $curLevel = $levels[$i];
            }
            $runs[$cur][] = $i;
            $runOf[$i] = $cur;
        }

        $final = $levels;
        foreach ($runs as $ri => $run) {
            $first = $run[0];
            if ($orig[$first] === self::PDI && $matchInit[$first] !== -1) {
                continue; // continuation of an isolating run sequence
            }
            $seq = $run;
            $curRun = $run;
            while (true) {
                $last = $curRun[count($curRun) - 1];
                $lt = $orig[$last];
                if (($lt === self::LRI || $lt === self::RLI || $lt === self::FSI) && $matchPdi[$last] !== -1 && isset($runOf[$matchPdi[$last]])) {
                    $curRun = $runs[$runOf[$matchPdi[$last]]];
                    $seq = array_merge($seq, $curRun);
                } else {
                    break;
                }
            }
            self::resolveSequence($seq, $cps, $types, $orig, $levels, $removed, $base, $final);
        }

        // Removed characters take the level of the preceding character
        $prev = $base;
        for ($i = 0; $i < $n; $i++) {
            if ($removed[$i]) {
                $final[$i] = $prev;
            } else {
                $prev = $final[$i];
            }
        }

        // L1: separators and trailing whitespace/isolate formatting characters reset to the paragraph level
        $reset = true;
        for ($i = $n - 1; $i >= 0; $i--) {
            $t = $orig[$i];
            if ($t === self::B || $t === self::S) {
                $final[$i] = $base;
                $reset = true;
            } elseif ($reset && ($t === self::WS || $t === self::FSI || $t === self::LRI || $t === self::RLI || $t === self::PDI || $removed[$i])) {
                $final[$i] = $base;
            } else {
                $reset = false;
            }
        }

        return ['levels' => $final, 'base' => $base];
    }

    /**
     * L2: visual order (indices into $levels) of a single line.
     *
     * @param list<int> $levels
     * @return list<int>
     */
    public static function reorder(array $levels): array
    {
        $n = count($levels);
        $order = range(0, $n - 1);
        if ($n < 2) {
            return $order;
        }
        $max = max($levels);
        $minOdd = 255;
        foreach ($levels as $l) {
            if (($l & 1) === 1 && $l < $minOdd) {
                $minOdd = $l;
            }
        }
        for ($lv = $max; $lv >= $minOdd; $lv--) {
            $i = 0;
            while ($i < $n) {
                if ($levels[$order[$i]] >= $lv) {
                    $j = $i;
                    while ($j + 1 < $n && $levels[$order[$j + 1]] >= $lv) {
                        $j++;
                    }
                    for ($a = $i, $b = $j; $a < $b; $a++, $b--) {
                        [$order[$a], $order[$b]] = [$order[$b], $order[$a]];
                    }
                    $i = $j + 1;
                } else {
                    $i++;
                }
            }
        }
        return $order;
    }

    // ---- internals -------------------------------------------------------------------------------------

    /**
     * @param list<int> $orig
     * @param list<int> $matchPdi
     */
    private static function firstStrongLevel(array $orig, array $matchPdi, int $from, int $to): ?int
    {
        for ($i = $from; $i < $to; $i++) {
            $t = $orig[$i];
            if ($t === self::L) {
                return 0;
            }
            if ($t === self::R || $t === self::AL) {
                return 1;
            }
            if ($t === self::LRI || $t === self::RLI || $t === self::FSI) {
                if ($matchPdi[$i] === -1) {
                    return null;
                }
                $i = $matchPdi[$i];
            }
        }
        return null;
    }

    /**
     * Resolves weak/neutral/implicit types for one isolating run sequence and writes the final levels.
     *
     * @param list<int> $seq character indices of the sequence
     * @param list<int> $cps
     * @param list<int> $types types after X rules
     * @param list<int> $orig
     * @param list<int> $levels
     * @param array<int, bool> $removed
     * @param list<int> $final
     */
    private static function resolveSequence(array $seq, array $cps, array $types, array $orig, array $levels, array $removed, int $base, array &$final): void
    {
        $n = count($seq);
        $level = $levels[$seq[0]];
        $total = count($cps);

        // sos / eos
        $prevLevel = $base;
        for ($k = $seq[0] - 1; $k >= 0; $k--) {
            if (!$removed[$k]) {
                $prevLevel = $levels[$k];
                break;
            }
        }
        $lastIdx = $seq[$n - 1];
        $nextLevel = $base;
        $lt = $orig[$lastIdx];
        if (!($lt === self::LRI || $lt === self::RLI || $lt === self::FSI)) {
            for ($k = $lastIdx + 1; $k < $total; $k++) {
                if (!$removed[$k]) {
                    $nextLevel = $levels[$k];
                    break;
                }
            }
        }
        $sos = (max($level, $prevLevel) & 1) ? self::R : self::L;
        $eos = (max($level, $nextLevel) & 1) ? self::R : self::L;
        $e = ($level & 1) ? self::R : self::L;

        $t = [];
        foreach ($seq as $k => $idx) {
            $t[$k] = $types[$idx];
        }

        // W1
        $prev = $sos;
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === self::NSM) {
                $t[$k] = ($prev === self::LRI || $prev === self::RLI || $prev === self::FSI || $prev === self::PDI) ? self::ON : $prev;
            }
            $prev = $t[$k];
        }
        // W2, W3
        $strong = $sos;
        for ($k = 0; $k < $n; $k++) {
            $c = $t[$k];
            if ($c === self::L || $c === self::R || $c === self::AL) {
                $strong = $c;
            } elseif ($c === self::EN && $strong === self::AL) {
                $t[$k] = self::AN;
            }
        }
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === self::AL) {
                $t[$k] = self::R;
            }
        }
        // W4
        for ($k = 1; $k < $n - 1; $k++) {
            if ($t[$k] === self::ES && $t[$k - 1] === self::EN && $t[$k + 1] === self::EN) {
                $t[$k] = self::EN;
            } elseif ($t[$k] === self::CS) {
                if ($t[$k - 1] === self::EN && $t[$k + 1] === self::EN) {
                    $t[$k] = self::EN;
                } elseif ($t[$k - 1] === self::AN && $t[$k + 1] === self::AN) {
                    $t[$k] = self::AN;
                }
            }
        }
        // W5
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === self::ET) {
                $s = $k;
                while ($k < $n && $t[$k] === self::ET) {
                    $k++;
                }
                $adj = ($s > 0 && $t[$s - 1] === self::EN) || ($k < $n && $t[$k] === self::EN);
                if ($adj) {
                    for ($m = $s; $m < $k; $m++) {
                        $t[$m] = self::EN;
                    }
                }
                $k--;
            }
        }
        // W6
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === self::ES || $t[$k] === self::ET || $t[$k] === self::CS) {
                $t[$k] = self::ON;
            }
        }
        // W7
        $strong = $sos;
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === self::L || $t[$k] === self::R) {
                $strong = $t[$k];
            } elseif ($t[$k] === self::EN && $strong === self::L) {
                $t[$k] = self::L;
            }
        }

        // N0: paired brackets
        self::resolveBrackets($seq, $cps, $orig, $t, $sos, $e);

        // N1, N2
        for ($k = 0; $k < $n; $k++) {
            if (self::isNeutral($t[$k])) {
                $s = $k;
                while ($k < $n && self::isNeutral($t[$k])) {
                    $k++;
                }
                $lead = $s === 0 ? $sos : self::strongDir($t[$s - 1]);
                $trail = $k >= $n ? $eos : self::strongDir($t[$k]);
                $dir = ($lead === $trail) ? $lead : $e;
                for ($m = $s; $m < $k; $m++) {
                    $t[$m] = $dir;
                }
                $k--;
            }
        }

        // I1, I2
        foreach ($seq as $k => $idx) {
            $c = $t[$k];
            $l = $level;
            if (($level & 1) === 0) {
                if ($c === self::R) {
                    $l += 1;
                } elseif ($c === self::AN || $c === self::EN) {
                    $l += 2;
                }
            } elseif ($c === self::L || $c === self::EN || $c === self::AN) {
                $l += 1;
            }
            $final[$idx] = $l;
        }
    }

    private static function isNeutral(int $c): bool
    {
        return $c === self::B || $c === self::S || $c === self::WS || $c === self::ON
            || $c === self::FSI || $c === self::LRI || $c === self::RLI || $c === self::PDI;
    }

    private static function strongDir(int $c): int
    {
        return $c === self::L ? self::L : self::R;
    }

    /**
     * @param list<int> $seq
     * @param list<int> $cps
     * @param list<int> $orig
     * @param array<int, int> $t
     */
    private static function resolveBrackets(array $seq, array $cps, array $orig, array &$t, int $sos, int $e): void
    {
        $n = count($seq);
        $open = [0x28 => 0x29, 0x5B => 0x5D, 0x7B => 0x7D, 0x2329 => 0x232A, 0x3008 => 0x3009, 0x300A => 0x300B, 0x300C => 0x300D,
            0x300E => 0x300F, 0x3010 => 0x3011, 0x3014 => 0x3015, 0xFF08 => 0xFF09, 0xFF3B => 0xFF3D, 0xFF5B => 0xFF5D,
            0x2045 => 0x2046, 0x207D => 0x207E, 0x208D => 0x208E, 0x2308 => 0x2309, 0x230A => 0x230B, 0x27E6 => 0x27E7];
        $closeToOpen = array_flip($open);
        $closeToOpen[0x3009] = 0x3008;
        $stack = [];
        $pairs = [];
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] !== self::ON) {
                continue;
            }
            $cp = $cps[$seq[$k]];
            if ($cp === 0x232A) {
                $cp = 0x3009;
            } elseif ($cp === 0x2329) {
                $cp = 0x3008;
            }
            if (isset($open[$cps[$seq[$k]]])) {
                if (count($stack) >= 63) {
                    break;
                }
                $closer = $open[$cps[$seq[$k]]];
                if ($closer === 0x232A) {
                    $closer = 0x3009;
                }
                $stack[] = [$closer, $k];
            } elseif (isset($closeToOpen[$cps[$seq[$k]]]) || isset($closeToOpen[$cp])) {
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    $want = $stack[$s][0];
                    if ($want === $cp || $want === $cps[$seq[$k]]) {
                        $pairs[] = [$stack[$s][1], $k];
                        $stack = array_slice($stack, 0, $s);
                        break;
                    }
                }
            }
        }
        if ($pairs === []) {
            return;
        }
        usort($pairs, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $opp = $e === self::L ? self::R : self::L;
        foreach ($pairs as [$o, $c]) {
            $foundE = false;
            $foundO = false;
            for ($k = $o + 1; $k < $c; $k++) {
                $s = self::strongOf($t[$k]);
                if ($s === $e) {
                    $foundE = true;
                    break;
                }
                if ($s !== -1) {
                    $foundO = true;
                }
            }
            $set = null;
            if ($foundE) {
                $set = $e;
            } elseif ($foundO) {
                $ctx = $sos;
                for ($k = $o - 1; $k >= 0; $k--) {
                    $s = self::strongOf($t[$k]);
                    if ($s !== -1) {
                        $ctx = $s;
                        break;
                    }
                }
                $set = $ctx === $opp ? $opp : $e;
            }
            if ($set !== null) {
                $t[$o] = $set;
                $t[$c] = $set;
                for ($k = $o + 1; $k < $n && $orig[$seq[$k]] === self::NSM; $k++) {
                    $t[$k] = $set;
                }
                for ($k = $c + 1; $k < $n && $orig[$seq[$k]] === self::NSM; $k++) {
                    $t[$k] = $set;
                }
            }
        }
    }

    private static function strongOf(int $c): int
    {
        if ($c === self::L) {
            return self::L;
        }
        if ($c === self::R || $c === self::EN || $c === self::AN) {
            return self::R;
        }
        return -1;
    }

    private static function initLow(): void
    {
        $t = array_fill(0, 256, self::L);
        $set = static function (int $a, int $b, int $c) use (&$t): void {
            for ($i = $a; $i <= $b; $i++) {
                $t[$i] = $c;
            }
        };
        $set(0x00, 0x08, self::BN);
        $t[0x09] = self::S;
        $t[0x0A] = self::B;
        $t[0x0B] = self::S;
        $t[0x0C] = self::WS;
        $t[0x0D] = self::B;
        $set(0x0E, 0x1B, self::BN);
        $set(0x1C, 0x1E, self::B);
        $t[0x1F] = self::S;
        $t[0x20] = self::WS;
        $set(0x21, 0x22, self::ON);
        $set(0x23, 0x25, self::ET);
        $set(0x26, 0x2A, self::ON);
        $t[0x2B] = self::ES;
        $t[0x2C] = self::CS;
        $t[0x2D] = self::ES;
        $t[0x2E] = self::CS;
        $t[0x2F] = self::CS;
        $set(0x30, 0x39, self::EN);
        $t[0x3A] = self::CS;
        $set(0x3B, 0x40, self::ON);
        $set(0x5B, 0x60, self::ON);
        $set(0x7B, 0x7E, self::ON);
        $set(0x7F, 0x84, self::BN);
        $t[0x85] = self::B;
        $set(0x86, 0x9F, self::BN);
        $t[0xA0] = self::CS;
        $t[0xA1] = self::ON;
        $set(0xA2, 0xA5, self::ET);
        $set(0xA6, 0xA9, self::ON);
        $set(0xAB, 0xAC, self::ON);
        $t[0xAD] = self::BN;
        $set(0xAE, 0xAF, self::ON);
        $set(0xB0, 0xB1, self::ET);
        $set(0xB2, 0xB3, self::EN);
        $t[0xB4] = self::ON;
        $set(0xB6, 0xB8, self::ON);
        $t[0xB9] = self::EN;
        $set(0xBB, 0xBF, self::ON);
        $t[0xD7] = self::ON;
        $t[0xF7] = self::ON;
        self::$low = $t;
    }

    private static function initRanges(): void
    {
        $r = [
            [0x0300, 0x036F, self::NSM], [0x0483, 0x0489, self::NSM],
            [0x0590, 0x0590, self::R], [0x0591, 0x05BD, self::NSM], [0x05BE, 0x05BE, self::R], [0x05BF, 0x05BF, self::NSM],
            [0x05C0, 0x05C0, self::R], [0x05C1, 0x05C2, self::NSM], [0x05C3, 0x05C3, self::R], [0x05C4, 0x05C5, self::NSM],
            [0x05C6, 0x05C6, self::R], [0x05C7, 0x05C7, self::NSM], [0x05C8, 0x05FF, self::R],
            [0x0600, 0x0605, self::AN], [0x0606, 0x0607, self::ON], [0x0608, 0x0608, self::AL], [0x0609, 0x060A, self::ET],
            [0x060B, 0x060B, self::AL], [0x060C, 0x060C, self::CS], [0x060D, 0x060D, self::AL], [0x060E, 0x060F, self::ON],
            [0x0610, 0x061A, self::NSM], [0x061B, 0x064A, self::AL], [0x064B, 0x065F, self::NSM], [0x0660, 0x0669, self::AN],
            [0x066A, 0x066A, self::ET], [0x066B, 0x066C, self::AN], [0x066D, 0x066F, self::AL], [0x0670, 0x0670, self::NSM],
            [0x0671, 0x06D5, self::AL], [0x06D6, 0x06DC, self::NSM], [0x06DD, 0x06DD, self::AN], [0x06DE, 0x06DE, self::ON],
            [0x06DF, 0x06E4, self::NSM], [0x06E5, 0x06E6, self::AL], [0x06E7, 0x06E8, self::NSM], [0x06E9, 0x06E9, self::ON],
            [0x06EA, 0x06ED, self::NSM], [0x06EE, 0x06EF, self::AL], [0x06F0, 0x06F9, self::EN], [0x06FA, 0x0710, self::AL],
            [0x0711, 0x0711, self::NSM], [0x0712, 0x072F, self::AL], [0x0730, 0x074A, self::NSM], [0x074B, 0x07A5, self::AL],
            [0x07A6, 0x07B0, self::NSM], [0x07B1, 0x07BF, self::AL], [0x07C0, 0x07EA, self::R], [0x07EB, 0x07F3, self::NSM],
            [0x07F4, 0x07F5, self::R], [0x07F6, 0x07F9, self::ON], [0x07FA, 0x0815, self::R], [0x0816, 0x0819, self::NSM],
            [0x081A, 0x081A, self::R], [0x081B, 0x0823, self::NSM], [0x0824, 0x0824, self::R], [0x0825, 0x0827, self::NSM],
            [0x0828, 0x0828, self::R], [0x0829, 0x082D, self::NSM], [0x082E, 0x0858, self::R], [0x0859, 0x085B, self::NSM],
            [0x085C, 0x085F, self::R], [0x0860, 0x08D2, self::AL], [0x08D3, 0x08E1, self::NSM], [0x08E2, 0x08E2, self::AN],
            [0x08E3, 0x08FF, self::NSM],
            [0x2000, 0x200A, self::WS], [0x200B, 0x200D, self::BN], [0x200E, 0x200E, self::L], [0x200F, 0x200F, self::R],
            [0x2010, 0x2027, self::ON], [0x2028, 0x2028, self::WS], [0x2029, 0x2029, self::B], [0x202A, 0x202A, self::LRE],
            [0x202B, 0x202B, self::RLE], [0x202C, 0x202C, self::PDF], [0x202D, 0x202D, self::LRO], [0x202E, 0x202E, self::RLO],
            [0x202F, 0x202F, self::CS], [0x2030, 0x2034, self::ET], [0x2035, 0x2043, self::ON], [0x2044, 0x2044, self::CS],
            [0x2045, 0x205E, self::ON], [0x205F, 0x205F, self::WS], [0x2060, 0x2065, self::BN], [0x2066, 0x2066, self::LRI],
            [0x2067, 0x2067, self::RLI], [0x2068, 0x2068, self::FSI], [0x2069, 0x2069, self::PDI], [0x206A, 0x206F, self::BN],
            [0x2070, 0x2070, self::EN], [0x2074, 0x2079, self::EN], [0x207A, 0x207B, self::ES], [0x207C, 0x207E, self::ON],
            [0x2080, 0x2089, self::EN], [0x208A, 0x208B, self::ES], [0x208C, 0x208E, self::ON], [0x20A0, 0x20CF, self::ET],
            [0x20D0, 0x20FF, self::NSM], [0x2190, 0x2211, self::ON], [0x2212, 0x2212, self::ES], [0x2213, 0x2213, self::ET],
            [0x2214, 0x2BFF, self::ON], [0x2E00, 0x2E7F, self::ON],
            [0x3000, 0x3000, self::WS], [0x3001, 0x3004, self::ON], [0x3008, 0x3020, self::ON], [0x302A, 0x302D, self::NSM],
            [0x3030, 0x3030, self::ON],
            [0xFB1D, 0xFB1D, self::R], [0xFB1E, 0xFB1E, self::NSM], [0xFB1F, 0xFB28, self::R], [0xFB29, 0xFB29, self::ES],
            [0xFB2A, 0xFB4F, self::R], [0xFB50, 0xFD3D, self::AL], [0xFD3E, 0xFD4F, self::ON], [0xFD50, 0xFDCF, self::AL],
            [0xFDF0, 0xFDFC, self::AL], [0xFDFD, 0xFDFF, self::ON], [0xFE00, 0xFE0F, self::NSM], [0xFE10, 0xFE19, self::ON],
            [0xFE20, 0xFE2F, self::NSM], [0xFE30, 0xFE4F, self::ON], [0xFE50, 0xFE50, self::CS], [0xFE51, 0xFE51, self::ON],
            [0xFE52, 0xFE52, self::CS], [0xFE54, 0xFE54, self::ON], [0xFE55, 0xFE55, self::CS], [0xFE56, 0xFE5E, self::ON],
            [0xFE5F, 0xFE5F, self::ET], [0xFE60, 0xFE61, self::ON], [0xFE62, 0xFE63, self::ES], [0xFE64, 0xFE66, self::ON],
            [0xFE68, 0xFE68, self::ON], [0xFE69, 0xFE6A, self::ET], [0xFE6B, 0xFE6B, self::ON], [0xFE70, 0xFEFE, self::AL],
            [0xFEFF, 0xFEFF, self::BN], [0xFF01, 0xFF02, self::ON], [0xFF03, 0xFF05, self::ET], [0xFF06, 0xFF0A, self::ON],
            [0xFF0B, 0xFF0B, self::ES], [0xFF0C, 0xFF0C, self::CS], [0xFF0D, 0xFF0D, self::ES], [0xFF0E, 0xFF0F, self::CS],
            [0xFF10, 0xFF19, self::EN], [0xFF1A, 0xFF1A, self::CS], [0xFF1B, 0xFF20, self::ON],
            [0x10800, 0x10CFF, self::R], [0x10D00, 0x10D3F, self::AL], [0x10D40, 0x10EBF, self::R], [0x10EC0, 0x10EFF, self::AL],
            [0x10F00, 0x10F2F, self::R], [0x10F30, 0x10F6F, self::AL], [0x10F70, 0x10FFF, self::R],
            [0x1E800, 0x1EDFF, self::R], [0x1EE00, 0x1EEFF, self::AL], [0x1EF00, 0x1EFFF, self::R],
            [0xFFFC, 0xFFFD, self::ON], [0x1F000, 0x1FAFF, self::ON],
        ];
        usort($r, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        self::$ranges = $r;
    }
}
