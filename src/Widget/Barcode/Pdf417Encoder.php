<?php

declare(strict_types=1);

namespace IvoPdf\Widget\Barcode;

use InvalidArgumentException;

/**
 * Pure-PHP PDF417 encoder (ISO/IEC 15438): text / byte / numeric compaction with automatic mode selection,
 * Reed-Solomon error correction over GF(929), cluster 0/3/6 codeword patterns, row indicators, start/stop (or
 * truncated) patterns and rows / columns / aspect-ratio selection.
 *
 * Independent implementation from the specification. The 3 x 929 bar/space patterns are regenerated from their
 * structural definition (8 elements of width 1..6 summing to 17 modules, cluster = (b1 - b2 + b3 - b4) mod 9, codeword
 * values ordered by the edge-to-edge distances); only the membership of each cluster (which of the structurally
 * possible patterns are used, 929 per cluster) is stored, as a compact bit mask.
 */
final class Pdf417Encoder
{
    /** Start pattern 81111113 and stop pattern 711311121 (as module strings). */
    public const START = '11111111010101000';
    public const STOP = '111111101000101001';

    public const MAX_CODEWORDS = 929;

    /**
     * Membership masks (base64url alphabet, 6 bits per character, MSB first) over the candidate patterns of each
     * cluster, candidates ordered ascending by the tuple (T1, T2, T3, T4, T5, T0) of edge-to-similar-edge distances.
     *
     * @var list<string>
     */
    private const CLUSTER_MASKS = [
        // cluster
        '_qq_q3_3nVzzV_7yz_zN_Nr__VzN_rHLGV_G_rFrt__FghhI3wro3sp__tjPwjFgylT__-jTizlN__LxKegf' .
        'A00--h_hx__krdR8-Rqzl_xvrshwvDFCZtDhx__Dx_MMshsw_-t-vlggfKb001D_V__ttphjNjt__QlVPosr' .
        'UtV_k_tD_tETp__lzlxkzLLLK0JLLy_rglx-kk-tMx-w_k_kxll__W00000000000000000000000000',
        // cluster
        '_________Vx________l______________xy_______-___Vcz_gz_gz_gzlV__R_R_R_R__x_Vlz_____F_' .
        '_______s_s_s_s___x_w_xVxVxV_______________lx-_l____________-____________y_F_____V_0',
        // cluster
        '_-V_V___x_x__________l__t_______gz_x_____zl____t__________R_____zNlxV___R___x____z__' .
        '_V__zt____gz_R___zl___l___F____V__-t___wlVs___R__z_-F____t__lV__Vs________x______m0',
    ];

    /** @var array<int, list<int>>|null cluster index (0, 1, 2 for clusters 0, 3, 6) => 929 17-bit patterns */
    private static ?array $clusters = null;

    /** Text sub-mode tables: value => character code, per sub-mode (Alpha, Lower, Mixed, Punctuation). */
    private const TEXT_ALPHA = 0;
    private const TEXT_LOWER = 1;
    private const TEXT_MIXED = 2;
    private const TEXT_PUNCT = 3;

    /** @var array<int, array<int, int>>|null sub-mode => [char => value] */
    private static ?array $textMap = null;

    /**
     * Encodes data into a PDF417 symbol.
     *
     * Options: columns (1..30), rows (3..90), ecl (0..8, default automatic), aspect (width:height ratio used to pick
     * the column count when neither columns nor rows is given, default 2), truncated (bool), rowheight (module rows
     * per symbol row, default 3), eci (null = ECI 26 for UTF-8 data with non-ASCII bytes, false = never, int = explicit ECI).
     *
     * @param array<string, mixed> $opt
     * @return array{rows: list<string>, codewords: list<int>, cols: int, rowCount: int, ecl: int, width: int, rowHeight: int, data: list<int>}
     */
    public static function encode(string $data, array $opt = []): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('PDF417 needs non-empty data');
        }
        $eci = $opt['eci'] ?? null;
        if (is_string($eci)) {
            $eci = trim($eci) === '' ? null : (is_numeric($eci) ? (int) $eci : (preg_match('/^(false|no|off)$/i', trim($eci)) === 1 ? false : null));
        }
        $cw = self::compact($data, $eci);
        $nData = count($cw);
        if ($nData > self::MAX_CODEWORDS - 3) {
            throw new InvalidArgumentException('Data too long for a PDF417 symbol');
        }

        $eclOpt = isset($opt['ecl']) && $opt['ecl'] !== '' && $opt['ecl'] !== null ? (int) $opt['ecl'] : -1;
        if ($eclOpt > 8) {
            throw new InvalidArgumentException('PDF417 error correction level must be 0..8');
        }
        $ecl = $eclOpt >= 0 ? $eclOpt : self::autoLevel($nData);
        $cols = isset($opt['columns']) && $opt['columns'] !== '' ? (int) $opt['columns'] : 0;
        $rowsOpt = isset($opt['rows']) && $opt['rows'] !== '' ? (int) $opt['rows'] : 0;
        if (($cols !== 0 && ($cols < 1 || $cols > 30)) || ($rowsOpt !== 0 && ($rowsOpt < 3 || $rowsOpt > 90))) {
            throw new InvalidArgumentException('PDF417 columns must be 1..30 and rows 3..90');
        }
        $rowHeight = isset($opt['rowheight']) && (int) $opt['rowheight'] > 0 ? (int) $opt['rowheight'] : 3;
        $aspect = isset($opt['aspect']) && (float) $opt['aspect'] > 0 ? (float) $opt['aspect'] : 2.0;

        // An automatically chosen level is reduced when the symbol would otherwise not fit.
        if ($eclOpt < 0) {
            while ($ecl > 0 && $nData + 1 + (2 << $ecl) > self::MAX_CODEWORDS) {
                $ecl--;
            }
        }
        $k = 2 << $ecl;
        $nce = $nData + 1 + $k; // data + symbol length descriptor + error correction
        if ($nce > self::MAX_CODEWORDS) {
            throw new InvalidArgumentException('Data too long for a PDF417 symbol at this error correction level');
        }

        [$cols, $rows] = self::dimensions($nce, $cols, $rowsOpt, $aspect, $rowHeight);

        // Pad to fill the symbol, then the symbol length descriptor
        $total = $cols * $rows;
        $sld = $total - $k;
        $words = $cw;
        while (count($words) + 1 < $sld) {
            $words[] = 900;
        }
        array_unshift($words, $sld);
        $all = array_merge($words, self::errorCorrection($words, $ecl));

        $truncated = !empty($opt['truncated']);
        $table = self::clusters();
        $out = [];
        $idx = 0;
        for ($r = 0; $r < $rows; $r++) {
            $cl = $r % 3;
            $g = 30 * intdiv($r, 3);
            $a = $g + intdiv($rows - 1, 3);
            $b = $g + $ecl * 3 + ($rows - 1) % 3;
            $c = $g + $cols - 1;
            [$left, $right] = match ($cl) {
                0 => [$a, $c],
                1 => [$b, $a],
                default => [$c, $b],
            };
            $line = self::START . self::bits($table[$cl][$left]);
            for ($i = 0; $i < $cols; $i++) {
                $line .= self::bits($table[$cl][$all[$idx++]]);
            }
            $line .= $truncated ? '1' : self::bits($table[$cl][$right]) . self::STOP;
            $out[] = $line;
        }
        return [
            'rows' => $out,
            'codewords' => $all,
            'cols' => $cols,
            'rowCount' => $rows,
            'ecl' => $ecl,
            'width' => strlen($out[0]),
            'rowHeight' => $rowHeight,
            'data' => $cw,
        ];
    }

    private static function bits(int $pattern): string
    {
        return str_pad(decbin($pattern), 17, '0', STR_PAD_LEFT);
    }

    /** Recommended error correction level for the number of data codewords (ISO/IEC 15438 table). */
    public static function autoLevel(int $dataCodewords): int
    {
        return match (true) {
            $dataCodewords <= 40 => 2,
            $dataCodewords <= 160 => 3,
            $dataCodewords <= 320 => 4,
            $dataCodewords <= 863 => 5,
            default => 6,
        };
    }

    /** @return array{int, int} [columns, rows] */
    private static function dimensions(int $nce, int $cols, int $rows, float $aspect, int $rowHeight): array
    {
        if ($cols > 0 && $rows > 0) {
            if ($cols * $rows < $nce) {
                throw new InvalidArgumentException("PDF417 {$cols} columns x {$rows} rows cannot hold {$nce} codewords");
            }
            return [$cols, $rows];
        }
        if ($cols > 0) {
            $rows = max(3, intdiv($nce + $cols - 1, $cols));
            if ($rows > 90) {
                throw new InvalidArgumentException("Data does not fit in {$cols} PDF417 columns (max 90 rows)");
            }
            return [$cols, $rows];
        }
        if ($rows > 0) {
            $cols = intdiv($nce + $rows - 1, $rows);
            if ($cols > 30) {
                throw new InvalidArgumentException("Data does not fit in {$rows} PDF417 rows (max 30 columns)");
            }
            return [max(1, $cols), $rows];
        }
        // Width = 17 c + 69 modules, height = rowHeight * nce / c: solve for the requested aspect ratio.
        $c = (int) round((sqrt(4761 + 68 * $aspect * $rowHeight * $nce) - 69) / 34);
        $c = max(1, min(30, $c));
        $r = intdiv($nce + $c - 1, $c);
        if ($r < 3) {
            $r = 3;
            $c = intdiv($nce + 2, 3);
        }
        while ($r > 90 && $c < 30) {
            $c++;
            $r = intdiv($nce + $c - 1, $c);
        }
        if ($r > 90) {
            throw new InvalidArgumentException('Data too long for a PDF417 symbol');
        }
        return [$c, $r];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Cluster patterns
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array<int, list<int>> */
    public static function clusters(): array
    {
        if (self::$clusters !== null) {
            return self::$clusters;
        }
        $res = [];
        foreach ([0, 3, 6] as $ci => $cluster) {
            $cand = self::candidates($cluster);
            $maskStr = self::CLUSTER_MASKS[$ci];
            $mask = '';
            for ($i = 0, $n = strlen($maskStr); $i < $n; $i++) {
                $mask .= str_pad(decbin((int) strpos(self::MASK_ALPHABET, $maskStr[$i])), 6, '0', STR_PAD_LEFT);
            }
            $list = [];
            foreach ($cand as $i => $pattern) {
                if (($mask[$i] ?? '0') === '1') {
                    $list[] = $pattern;
                }
            }
            if (count($list) !== 929) {
                throw new \LogicException('PDF417 cluster table is corrupt');
            }
            $res[$ci] = $list;
        }
        return self::$clusters = $res;
    }

    private const MASK_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_';

    /**
     * All structurally possible 17-module patterns of a cluster: 4 bars and 4 spaces of width 1..6,
     * (b1 - b2 + b3 - b4) mod 9 = cluster, ordered by (T1, T2, T3, T4, T5, T0) with Ti = element i + element i+1.
     *
     * @return list<int>
     */
    private static function candidates(int $cluster): array
    {
        $list = [];
        for ($w0 = 1; $w0 <= 6; $w0++) {
            for ($w1 = 1; $w1 <= 6; $w1++) {
                for ($w2 = 1; $w2 <= 6; $w2++) {
                    for ($w3 = 1; $w3 <= 6; $w3++) {
                        for ($w4 = 1; $w4 <= 6; $w4++) {
                            $w6 = (($w0 - $w2 + $w4 - $cluster) % 9 + 9) % 9;
                            if ($w6 < 1 || $w6 > 6) {
                                continue;
                            }
                            for ($w5 = 1; $w5 <= 6; $w5++) {
                                $w7 = 17 - ($w0 + $w1 + $w2 + $w3 + $w4 + $w5 + $w6);
                                if ($w7 < 1 || $w7 > 6) {
                                    continue;
                                }
                                $w = [$w0, $w1, $w2, $w3, $w4, $w5, $w6, $w7];
                                $bits = 0;
                                foreach ($w as $i => $width) {
                                    $bits = ($bits << $width) | ($i % 2 === 0 ? (1 << $width) - 1 : 0);
                                }
                                $key = (((((($w1 + $w2) * 16 + $w2 + $w3) * 16 + $w3 + $w4) * 16 + $w4 + $w5) * 16 + $w5 + $w6) * 16) + $w0 + $w1;
                                $list[] = [$key, $bits];
                            }
                        }
                    }
                }
            }
        }
        usort($list, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return array_column($list, 1);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Error correction
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Reed-Solomon error correction codewords over GF(929) (generator roots 3^1 .. 3^k, k = 2^(level+1)).
     *
     * @param list<int> $words data codewords including the symbol length descriptor
     * @return list<int>
     */
    public static function errorCorrection(array $words, int $level): array
    {
        $k = 2 << $level;
        // g(x) = prod (x - 3^i), coefficients from x^k down to x^0 (index 0 is the leading 1)
        $g = [1];
        $root = 1;
        for ($i = 1; $i <= $k; $i++) {
            $root = ($root * 3) % 929;
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $next[$j] = ($next[$j] + $c) % 929;
                $next[$j + 1] = ($next[$j + 1] + 929 - ($c * $root) % 929) % 929;
            }
            $g = $next;
        }
        // remainder of d(x) * x^k divided by g(x), long division
        $rem = array_fill(0, $k, 0);
        foreach ($words as $d) {
            $f = ($d + $rem[0]) % 929;
            array_shift($rem);
            $rem[] = 0;
            if ($f !== 0) {
                for ($j = 0; $j < $k; $j++) {
                    $rem[$j] = ($rem[$j] + 929 - ($f * $g[$j + 1]) % 929) % 929;
                }
            }
        }
        // error correction codewords are the negated remainder
        foreach ($rem as $j => $v) {
            $rem[$j] = $v === 0 ? 0 : 929 - $v;
        }
        return $rem;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Compaction
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * High-level encoding: data bytes to data codewords (without symbol length descriptor / padding / error correction).
     *
     * @param int|bool|null $eci
     * @return list<int>
     */
    public static function compact(string $data, int|bool|null $eci = null): array
    {
        $cw = [];
        if ($eci === true) {
            $eci = 26;
        } elseif ($eci === null) {
            $eci = preg_match('/[\x80-\xFF]/', $data) === 1 && preg_match('//u', $data) === 1 ? 26 : false;
        }
        if (is_int($eci) && $eci >= 0) {
            if ($eci < 900) {
                array_push($cw, 927, $eci);
            } elseif ($eci < 810900) {
                array_push($cw, 926, intdiv($eci, 900) - 1, $eci % 900);
            } else {
                array_push($cw, 925, $eci - 810900);
            }
        }

        $n = strlen($data);
        $isText = static function (int $c): bool {
            return $c === 9 || $c === 10 || $c === 13 || ($c >= 32 && $c <= 126);
        };
        // 1. raw segments: N (>= 13 digits), T (text-compactable) and B (anything else)
        $segs = []; // [type, start, end(excl)]
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j < $n && $data[$j] >= '0' && $data[$j] <= '9') {
                $j++;
            }
            if ($j - $i >= 13) {
                $segs[] = ['N', $i, $j];
                $i = $j;
                continue;
            }
            $c = ord($data[$i]);
            $type = $isText($c) ? 'T' : 'B';
            $j = $i + 1;
            while ($j < $n) {
                $cj = ord($data[$j]);
                if (($isText($cj) ? 'T' : 'B') !== $type) {
                    break;
                }
                if ($data[$j] >= '0' && $data[$j] <= '9') {
                    $k = $j;
                    while ($k < $n && $data[$k] >= '0' && $data[$k] <= '9') {
                        $k++;
                    }
                    if ($k - $j >= 13) {
                        break;
                    }
                }
                $j++;
            }
            if ($segs !== [] && $segs[count($segs) - 1][0] === $type && $segs[count($segs) - 1][2] === $i) {
                $segs[count($segs) - 1][2] = $j;
            } else {
                $segs[] = [$type, $i, $j];
            }
            $i = $j;
        }
        // 2. absorb short text runs into neighbouring byte runs where switching modes would cost more
        $changed = true;
        while ($changed) {
            $changed = false;
            $count = count($segs);
            for ($s = 0; $s < $count; $s++) {
                if ($segs[$s][0] !== 'T') {
                    continue;
                }
                $len = $segs[$s][2] - $segs[$s][1];
                $prevB = $s > 0 && $segs[$s - 1][0] === 'B';
                $nextB = $s + 1 < $count && $segs[$s + 1][0] === 'B';
                $merge = false;
                if ($prevB && $nextB) {
                    $merge = $len < 5;
                } elseif ($prevB) {
                    $merge = $len < 3;
                } elseif ($nextB) {
                    $merge = $s === 0 ? $len < 2 : $len < 3;
                }
                if ($merge) {
                    $segs[$s][0] = 'B';
                    $changed = true;
                    break;
                }
            }
            if ($changed) { // join neighbours of the same type
                $joined = [];
                foreach ($segs as $seg) {
                    $last = count($joined) - 1;
                    if ($last >= 0 && $joined[$last][0] === $seg[0] && $joined[$last][0] !== 'N') {
                        $joined[$last][2] = $seg[2];
                    } else {
                        $joined[] = $seg;
                    }
                }
                $segs = $joined;
            }
        }
        // 3. emit
        $first = true;
        foreach ($segs as [$type, $a, $b]) {
            $chunk = substr($data, $a, $b - $a);
            switch ($type) {
                case 'T':
                    if (!$first) {
                        $cw[] = 900;
                    }
                    array_push($cw, ...self::textCodewords($chunk));
                    break;
                case 'B':
                    $cw[] = strlen($chunk) % 6 === 0 ? 924 : 901;
                    array_push($cw, ...self::byteCodewords($chunk));
                    break;
                default:
                    $cw[] = 902;
                    array_push($cw, ...self::numericCodewords($chunk));
            }
            $first = false;
        }
        return $cw;
    }

    /**
     * Byte compaction: every 6 bytes become 5 base-900 codewords, remaining bytes one codeword each.
     *
     * @return list<int>
     */
    public static function byteCodewords(string $bytes): array
    {
        $out = [];
        $n = strlen($bytes);
        $i = 0;
        for (; $i + 6 <= $n; $i += 6) {
            $v = 0;
            for ($j = 0; $j < 6; $j++) {
                $v = $v * 256 + ord($bytes[$i + $j]);
            }
            $group = [];
            for ($j = 0; $j < 5; $j++) {
                $group[] = $v % 900;
                $v = intdiv($v, 900);
            }
            array_push($out, ...array_reverse($group));
        }
        for (; $i < $n; $i++) {
            $out[] = ord($bytes[$i]);
        }
        return $out;
    }

    /**
     * Numeric compaction: groups of up to 44 digits, prefixed with a "1", converted to base 900.
     *
     * @return list<int>
     */
    public static function numericCodewords(string $digits): array
    {
        $out = [];
        foreach (str_split($digits, 44) as $group) {
            $num = array_map('intval', str_split('1' . $group));
            $words = [];
            while ($num !== []) {
                $rem = 0;
                $quot = [];
                foreach ($num as $d) {
                    $cur = $rem * 10 + $d;
                    $q = intdiv($cur, 900);
                    $rem = $cur % 900;
                    if ($quot !== [] || $q !== 0) {
                        $quot[] = $q;
                    }
                }
                $words[] = $rem;
                $num = $quot;
            }
            array_push($out, ...array_reverse($words));
        }
        return $out;
    }

    /** @return array<int, array<int, int>> */
    private static function textMap(): array
    {
        if (self::$textMap !== null) {
            return self::$textMap;
        }
        $alpha = [];
        $lower = [];
        for ($i = 0; $i < 26; $i++) {
            $alpha[65 + $i] = $i;
            $lower[97 + $i] = $i;
        }
        $alpha[32] = 26;
        $lower[32] = 26;
        $mixedChars = "0123456789&\r\t,:#-.\$/+%*=^";
        $mixed = [32 => 26];
        foreach (str_split($mixedChars) as $v => $ch) {
            $mixed[ord($ch)] = $v;
        }
        $punctChars = ";<>@[\\]_`~!\r\t,:\n-.\$/\"|*()?{}'";
        $punct = [];
        foreach (str_split($punctChars) as $v => $ch) {
            $punct[ord($ch)] = $v;
        }
        return self::$textMap = [$alpha, $lower, $mixed, $punct];
    }

    /**
     * Text compaction with optimal sub-mode latches / shifts (dynamic programming over the sub-mode).
     * The sequence starts in the Alpha sub-mode; an odd number of values is padded with 29 (PS).
     *
     * @return list<int>
     */
    public static function textCodewords(string $text): array
    {
        $map = self::textMap();
        // latch sequences [from][to]
        $latch = [
            [[], [27], [28], [28, 25]],
            [[28, 28], [], [28], [28, 25]],
            [[28], [27], [], [25]],
            [[29], [29, 27], [29, 28], []],
        ];
        $n = strlen($text);
        $inf = PHP_INT_MAX >> 2;
        $cost = [0 => [0, $inf, $inf, $inf]];
        $from = [];
        $seq = [];
        for ($i = 0; $i < $n; $i++) {
            $c = ord($text[$i]);
            $next = [$inf, $inf, $inf, $inf];
            $from[$i] = [];
            $seq[$i] = [];
            for ($s = 0; $s < 4; $s++) {
                $base = $cost[$i][$s];
                if ($base >= $inf) {
                    continue;
                }
                for ($t = 0; $t < 4; $t++) {
                    if (!isset($map[$t][$c])) {
                        continue;
                    }
                    $lat = $latch[$s][$t];
                    $cc = $base + count($lat) + 1;
                    if ($cc < $next[$t]) {
                        $next[$t] = $cc;
                        $from[$i][$t] = $s;
                        $seq[$i][$t] = [...$lat, $map[$t][$c]];
                    }
                }
                // single-character shifts: Punctuation from Alpha / Lower / Mixed, Alpha from Lower
                if ($s !== self::TEXT_PUNCT && isset($map[self::TEXT_PUNCT][$c])) {
                    $cc = $base + 2;
                    if ($cc < $next[$s]) {
                        $next[$s] = $cc;
                        $from[$i][$s] = $s;
                        $seq[$i][$s] = [29, $map[self::TEXT_PUNCT][$c]];
                    }
                }
                if ($s === self::TEXT_LOWER && isset($map[self::TEXT_ALPHA][$c]) && $c !== 32) {
                    $cc = $base + 2;
                    if ($cc < $next[$s]) {
                        $next[$s] = $cc;
                        $from[$i][$s] = $s;
                        $seq[$i][$s] = [27, $map[self::TEXT_ALPHA][$c]];
                    }
                }
            }
            $cost[$i + 1] = $next;
        }
        $best = 0;
        $bestCost = $inf;
        for ($s = 0; $s < 4; $s++) {
            $c = $cost[$n][$s];
            $adj = $c + ($c % 2);
            if ($adj < $bestCost) {
                $bestCost = $adj;
                $best = $s;
            }
        }
        $vals = [];
        $s = $best;
        for ($i = $n - 1; $i >= 0; $i--) {
            $vals = [...$seq[$i][$s], ...$vals];
            $s = $from[$i][$s];
        }
        if (count($vals) % 2 === 1) {
            $vals[] = 29;
        }
        $out = [];
        for ($i = 0, $m = count($vals); $i < $m; $i += 2) {
            $out[] = 30 * $vals[$i] + $vals[$i + 1];
        }
        return $out;
    }
}
