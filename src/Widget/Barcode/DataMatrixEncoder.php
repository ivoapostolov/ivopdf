<?php

declare(strict_types=1);

namespace IvoPdf\Widget\Barcode;

use InvalidArgumentException;

/**
 * Pure-PHP Data Matrix ECC200 encoder (ISO/IEC 16022): square symbols 10x10 .. 144x144 and the six rectangular symbols
 * 8x18 .. 16x48. Encodation schemes ASCII (digit pairs, upper shift), C40, Text and Base 256 are chosen automatically by
 * a minimum-codeword dynamic programme (bytes >= 128, i.e. UTF-8, pass through as bytes). Optional GS1 mode (FNC1).
 */
final class DataMatrixEncoder
{
    /**
     * Symbol table: [rows, columns, data codewords, ECC codewords per block, blocks, region rows, region columns].
     *
     * @var list<array{int, int, int, int, int, int, int}>
     */
    private const SQUARE = [
        [10, 10, 3, 5, 1, 1, 1], [12, 12, 5, 7, 1, 1, 1], [14, 14, 8, 10, 1, 1, 1], [16, 16, 12, 12, 1, 1, 1],
        [18, 18, 18, 14, 1, 1, 1], [20, 20, 22, 18, 1, 1, 1], [22, 22, 30, 20, 1, 1, 1], [24, 24, 36, 24, 1, 1, 1],
        [26, 26, 44, 28, 1, 1, 1], [32, 32, 62, 36, 1, 2, 2], [36, 36, 86, 42, 1, 2, 2], [40, 40, 114, 48, 1, 2, 2],
        [44, 44, 144, 56, 1, 2, 2], [48, 48, 174, 68, 1, 2, 2], [52, 52, 204, 42, 2, 2, 2], [64, 64, 280, 56, 2, 4, 4],
        [72, 72, 368, 36, 4, 4, 4], [80, 80, 456, 48, 4, 4, 4], [88, 88, 576, 56, 4, 4, 4], [96, 96, 696, 68, 4, 4, 4],
        [104, 104, 816, 56, 6, 4, 4], [120, 120, 1050, 68, 6, 6, 6], [132, 132, 1304, 62, 8, 6, 6],
        [144, 144, 1558, 62, 10, 6, 6],
    ];

    private const RECT = [
        [8, 18, 5, 7, 1, 1, 1], [8, 32, 10, 11, 1, 1, 2], [12, 26, 16, 14, 1, 1, 1], [12, 36, 22, 18, 1, 1, 2],
        [16, 36, 32, 24, 1, 1, 2], [16, 48, 49, 28, 1, 1, 2],
    ];

    private const S_ASCII = 0;
    private const S_C40 = 1;   // 1..3 = pending values 0..2
    private const S_TEXT = 4;  // 4..6
    private const S_B256 = 7;
    private const FNC1 = 256;

    /** @var list<int>|null */
    private static ?array $exp = null;
    /** @var list<int>|null */
    private static ?array $log = null;

    /**
     * Encodes data into a module matrix (including finder / timing patterns, no quiet zone).
     *
     * Options: shape ('square' default, 'rect' or 'any' = smallest of both), gs1 (bool: GS1 DataMatrix, FNC1 first, a
     * GS character 0x1D in the data becomes FNC1; a text starting with "(" is parsed as "(AI)value..."), mode ('auto',
     * 'ascii', 'c40', 'text' or 'base256' to restrict the encodation schemes).
     *
     * @param array<string, mixed> $opt
     * @return array{matrix: list<list<int>>, size: int, rows: int, cols: int, codewords: list<int>, data: list<int>}
     */
    public static function encode(string $data, array $opt = []): array
    {
        $gs1 = !empty($opt['gs1']);
        if ($gs1 && str_starts_with($data, '(')) {
            $data = self::gs1FromHri($data);
        }
        $items = [];
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $items[] = $gs1 && $data[$i] === "\x1D" ? self::FNC1 : ord($data[$i]);
        }
        $mode = strtolower((string) ($opt['mode'] ?? 'auto'));
        [$cw, $trailingUnlatch] = self::highLevel($items, $mode);
        if ($gs1) {
            array_unshift($cw, 232);
        }

        $shape = strtolower((string) ($opt['shape'] ?? 'square'));
        $pools = match ($shape) {
            'rect', 'rectangle', 'rectangular', 'r' => [self::RECT, self::SQUARE],
            'any', 'auto' => [self::sorted(array_merge(self::SQUARE, self::RECT))],
            default => [self::SQUARE],
        };
        $sym = null;
        foreach ($pools as $pool) {
            $cnt = count($cw);
            foreach ($pool as $s) {
                if ($s[2] >= $cnt) {
                    $sym = $s;
                    break;
                }
                // a trailing unlatch is not needed when the symbol is full without it
                if ($trailingUnlatch && $s[2] === $cnt - 1) {
                    array_pop($cw);
                    $sym = $s;
                    break;
                }
            }
            if ($sym !== null) {
                break;
            }
        }
        if ($sym === null) {
            throw new InvalidArgumentException('Data too long for a Data Matrix symbol');
        }
        [$rows, $cols, $dataLen, $eccLen, $blocks, $rv, $rh] = $sym;

        // Padding
        if (count($cw) < $dataLen) {
            $cw[] = 129;
        }
        while (count($cw) < $dataLen) {
            $p = count($cw) + 1;
            $v = 129 + (149 * $p) % 253 + 1;
            if ($v > 254) {
                $v -= 254;
            }
            $cw[] = $v;
        }
        $dataCw = $cw;

        // ECC per block, interleaved after the data
        $ecc = array_fill(0, $eccLen * $blocks, 0);
        for ($b = 0; $b < $blocks; $b++) {
            $blockData = [];
            for ($i = $b; $i < $dataLen; $i += $blocks) {
                $blockData[] = $cw[$i];
            }
            $rs = self::rsEcc($blockData, $eccLen);
            foreach ($rs as $j => $v) {
                $ecc[$j * $blocks + $b] = $v;
            }
        }
        $all = array_merge($cw, $ecc);

        // Placement within the (region-joined) mapping matrix
        $mapRows = $rows - 2 * $rv;
        $mapCols = $cols - 2 * $rh;
        $map = self::place($all, $mapRows, $mapCols);
        $dr = intdiv($mapRows, $rv);
        $dc = intdiv($mapCols, $rh);

        // Assemble with finder/timing patterns
        $out = array_fill(0, $rows, array_fill(0, $cols, 0));
        for ($ry = 0; $ry < $rv; $ry++) {
            for ($rx = 0; $rx < $rh; $rx++) {
                $oy = $ry * ($dr + 2);
                $ox = $rx * ($dc + 2);
                for ($r = 0; $r < $dr + 2; $r++) {
                    for ($c = 0; $c < $dc + 2; $c++) {
                        if ($c === 0 || $r === $dr + 1) {
                            $v = 1;
                        } elseif ($r === 0) {
                            $v = $c % 2 === 0 ? 1 : 0;
                        } elseif ($c === $dc + 1) {
                            $v = $r % 2 === 1 ? 1 : 0;
                        } else {
                            $v = $map[$ry * $dr + $r - 1][$rx * $dc + $c - 1];
                        }
                        $out[$oy + $r][$ox + $c] = $v;
                    }
                }
            }
        }
        return ['matrix' => $out, 'size' => $cols, 'rows' => $rows, 'cols' => $cols, 'codewords' => $all, 'data' => $dataCw];
    }

    /**
     * @param list<array{int, int, int, int, int, int, int}> $list
     * @return list<array{int, int, int, int, int, int, int}>
     */
    private static function sorted(array $list): array
    {
        usort($list, static fn(array $a, array $b): int => [$a[2], $a[0] * $a[1]] <=> [$b[2], $b[0] * $b[1]]);
        return $list;
    }

    /** @return list<int> */
    public static function encodeAscii(string $data): array
    {
        $cw = [];
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($data[$i]);
            if ($c >= 48 && $c <= 57 && $i + 1 < $n && ctype_digit($data[$i + 1])) {
                $cw[] = 130 + ($c - 48) * 10 + (ord($data[$i + 1]) - 48);
                $i++;
            } elseif ($c < 128) {
                $cw[] = $c + 1;
            } else {
                $cw[] = 235;
                $cw[] = $c - 127;
            }
        }
        return $cw;
    }

    /**
     * Converts "(01)09501101530003(17)140704(10)AB-123" to data with FNC1 (GS) after variable-length elements.
     * Fixed-length application identifiers known to the function are not followed by a separator.
     */
    public static function gs1FromHri(string $text): string
    {
        if (preg_match_all('/\((\d{2,4})\)([^(]*)/', $text, $mm, PREG_SET_ORDER) < 1) {
            return $text;
        }
        $out = '';
        $last = count($mm) - 1;
        foreach ($mm as $i => [, $ai, $val]) {
            $out .= $ai . $val;
            if ($i < $last && !self::gs1Fixed($ai)) {
                $out .= "\x1D";
            }
        }
        return $out;
    }

    private static function gs1Fixed(string $ai): bool
    {
        $p2 = substr($ai, 0, 2);
        if (in_array($p2, ['00', '01', '02', '11', '12', '13', '15', '16', '17', '20'], true)) {
            return true;
        }
        if (strlen($ai) === 4 && ((int) $p2 >= 31 && (int) $p2 <= 36)) {
            return true;
        }
        return strlen($ai) === 3 && $p2 === '41' && $ai[2] <= '7';
    }

    /**
     * C40 / Text values of a character (0..255, or FNC1): basic set plus shift 1/2/3 prefixes.
     *
     * @return list<int>
     */
    private static function shiftValues(int $c, bool $text): array
    {
        if ($c === self::FNC1) {
            return [1, 27];
        }
        $pre = [];
        if ($c >= 128) {
            $pre = [1, 30];
            $c -= 128;
        }
        if ($c < 32) {
            return [...$pre, 0, $c];
        }
        if ($c === 32) {
            return [...$pre, 3];
        }
        if ($c >= 48 && $c <= 57) {
            return [...$pre, $c - 48 + 4];
        }
        if ($text ? ($c >= 97 && $c <= 122) : ($c >= 65 && $c <= 90)) {
            return [...$pre, $c - ($text ? 97 : 65) + 14];
        }
        if ($c <= 47) {
            return [...$pre, 1, $c - 33];
        }
        if ($c >= 58 && $c <= 64) {
            return [...$pre, 1, $c - 58 + 15];
        }
        if ($c >= 91 && $c <= 95) {
            return [...$pre, 1, $c - 91 + 22];
        }
        // Shift 3: ` , letters of the other case, { | } ~ DEL
        if ($c === 96) {
            return [...$pre, 2, 0];
        }
        if ($c >= 123) {
            return [...$pre, 2, $c - 123 + 27];
        }
        return [...$pre, 2, $c - ($text ? 64 : 96)];
    }

    /**
     * Minimum-codeword encodation (ASCII / C40 / Text / Base 256) by dynamic programming over (position, scheme state).
     *
     * @param list<int> $items bytes 0..255 or FNC1 (256)
     * @return array{list<int>, bool} [codewords (no padding), whether the last codeword is a removable unlatch]
     */
    private static function highLevel(array $items, string $mode): array
    {
        $n = count($items);
        $inf = PHP_INT_MAX >> 2;
        $allowC40 = in_array($mode, ['auto', 'c40'], true);
        $allowText = in_array($mode, ['auto', 'text'], true);
        $allowB = in_array($mode, ['auto', 'base256', 'b256'], true);
        $cost = array_fill(0, $n + 1, array_fill(0, 8, $inf));
        $back = array_fill(0, $n + 1, array_fill(0, 8, null));
        $cost[0][self::S_ASCII] = 0;
        $c40v = [];
        $txtv = [];
        for ($i = 0; $i < $n; $i++) {
            $c40v[$i] = self::shiftValues($items[$i], false);
            $txtv[$i] = self::shiftValues($items[$i], true);
        }
        $relax = static function (int $i, int $s, int $c, int $pi, int $ps, bool $tie = false) use (&$cost, &$back): void {
            if ($c < $cost[$i][$s] || ($tie && $c === $cost[$i][$s])) {
                $cost[$i][$s] = $c;
                $back[$i][$s] = [$pi, $ps];
            }
        };

        for ($i = 0; $i <= $n; $i++) {
            // (1) leaving a scheme
            if ($cost[$i][self::S_B256] < $inf) {
                $relax($i, self::S_ASCII, $cost[$i][self::S_B256], $i, self::S_B256);
            }
            foreach ([self::S_C40, self::S_TEXT] as $base) {
                if ($cost[$i][$base] < $inf) {
                    $relax($i, self::S_ASCII, $cost[$i][$base] + 1, $i, $base);
                }
                if ($i === $n && $cost[$i][$base + 2] < $inf) { // two pending values: pad with Shift 1, then unlatch
                    $relax($i, self::S_ASCII, $cost[$i][$base + 2] + 3, $i, $base + 2);
                }
            }
            if ($i === $n) {
                break;
            }
            // (2) entering a scheme
            $a = $cost[$i][self::S_ASCII];
            if ($a < $inf) {
                if ($allowC40) {
                    $relax($i, self::S_C40, $a + 1, $i, self::S_ASCII);
                }
                if ($allowText) {
                    $relax($i, self::S_TEXT, $a + 1, $i, self::S_ASCII);
                }
                if ($allowB && $items[$i] !== self::FNC1) {
                    $relax($i + 1, self::S_B256, $a + 2 + 1, $i, self::S_ASCII);
                }
                // ASCII encodation
                $c = $items[$i];
                if ($c >= 48 && $c <= 57 && $i + 1 < $n && $items[$i + 1] >= 48 && $items[$i + 1] <= 57) {
                    $relax($i + 2, self::S_ASCII, $a + 1, $i, self::S_ASCII, true); // ties: digit pairs as early as possible
                }
                $relax($i + 1, self::S_ASCII, $a + ($c >= 128 && $c !== self::FNC1 ? 2 : 1), $i, self::S_ASCII, true);
            }
            // (3) consuming a character inside a scheme
            foreach ([[self::S_C40, $c40v[$i]], [self::S_TEXT, $txtv[$i]]] as [$base, $vals]) {
                for ($p = 0; $p < 3; $p++) {
                    $cur = $cost[$i][$base + $p];
                    if ($cur >= $inf) {
                        continue;
                    }
                    $t = $p + count($vals);
                    $relax($i + 1, $base + $t % 3, $cur + 2 * intdiv($t, 3), $i, $base + $p);
                }
            }
            $b = $cost[$i][self::S_B256];
            if ($b < $inf && $items[$i] !== self::FNC1) {
                $relax($i + 1, self::S_B256, $b + 1, $i, self::S_B256);
            }
        }
        if ($cost[$n][self::S_ASCII] >= $inf) {
            throw new InvalidArgumentException('Data cannot be encoded as Data Matrix');
        }

        // Reconstruct the path
        $edges = [];
        $i = $n;
        $s = self::S_ASCII;
        while (!($i === 0 && $s === self::S_ASCII)) {
            [$pi, $ps] = $back[$i][$s];
            $edges[] = [$pi, $ps, $i, $s];
            $i = $pi;
            $s = $ps;
        }
        $edges = array_reverse($edges);

        // Emit
        $cw = [];
        $buf = [];
        $b256 = [];
        $text = false;
        $lastUnlatch = false;
        $flushTriplet = static function () use (&$buf, &$cw): void {
            while (count($buf) >= 3) {
                $v = 1600 * $buf[0] + 40 * $buf[1] + $buf[2] + 1;
                $cw[] = $v >> 8;
                $cw[] = $v & 255;
                $buf = array_slice($buf, 3);
            }
        };
        foreach ($edges as [$pi, $ps, $ni, $ns]) {
            $lastUnlatch = false;
            if ($ps === self::S_ASCII && $ns === self::S_ASCII) {
                $c = $items[$pi];
                if ($ni - $pi === 2) {
                    $cw[] = 130 + ($c - 48) * 10 + ($items[$pi + 1] - 48);
                } elseif ($c === self::FNC1) {
                    $cw[] = 232;
                } elseif ($c < 128) {
                    $cw[] = $c + 1;
                } else {
                    $cw[] = 235;
                    $cw[] = $c - 127;
                }
            } elseif ($ps === self::S_ASCII && $ns === self::S_C40) {
                $cw[] = 230;
                $text = false;
            } elseif ($ps === self::S_ASCII && $ns === self::S_TEXT) {
                $cw[] = 239;
                $text = true;
            } elseif ($ps === self::S_ASCII && $ns === self::S_B256) {
                $b256 = [$items[$pi]];
            } elseif ($ps === self::S_B256 && $ns === self::S_B256) {
                $b256[] = $items[$pi];
            } elseif ($ps === self::S_B256 && $ns === self::S_ASCII) {
                $cw[] = 231;
                $len = count($b256);
                $stream = $len <= 249 ? [$len] : [intdiv($len, 250) + 249, $len % 250];
                array_push($stream, ...$b256);
                foreach ($stream as $byte) {
                    $pos = count($cw) + 1;
                    $cw[] = ($byte + (149 * $pos) % 255 + 1) % 256;
                }
                $b256 = [];
            } elseif ($ps >= self::S_C40 && $ps <= self::S_TEXT + 2 && $ns >= self::S_C40 && $ns <= self::S_TEXT + 2) {
                array_push($buf, ...($text ? $txtv[$pi] : $c40v[$pi]));
                $flushTriplet();
            } elseif ($ns === self::S_ASCII) { // unlatch (254), padding an incomplete final triplet with Shift 1
                if ($buf !== []) {
                    $buf[] = 0;
                    $flushTriplet();
                }
                $cw[] = 254;
                $lastUnlatch = $ni === $n;
            }
        }
        return [$cw, $lastUnlatch];
    }

    /**
     * ECC200 module placement (ISO 16022 Annex F).
     *
     * @param list<int> $cw
     * @return list<list<int>>
     */
    private static function place(array $cw, int $nrow, int $ncol): array
    {
        $grid = array_fill(0, $nrow, array_fill(0, $ncol, -1));
        $put = static function (int $row, int $col, int $pos, int $bit) use (&$grid, $nrow, $ncol, $cw): void {
            if ($row < 0) {
                $row += $nrow;
                $col += 4 - (($nrow + 4) % 8);
            }
            if ($col < 0) {
                $col += $ncol;
                $row += 4 - (($ncol + 4) % 8);
            }
            $grid[$row][$col] = (($cw[$pos] ?? 0) >> (8 - $bit)) & 1;
        };
        $utah = static function (int $row, int $col, int $pos) use ($put): void {
            $put($row - 2, $col - 2, $pos, 1);
            $put($row - 2, $col - 1, $pos, 2);
            $put($row - 1, $col - 2, $pos, 3);
            $put($row - 1, $col - 1, $pos, 4);
            $put($row - 1, $col, $pos, 5);
            $put($row, $col - 2, $pos, 6);
            $put($row, $col - 1, $pos, 7);
            $put($row, $col, $pos, 8);
        };
        $corner = static function (int $k, int $pos) use ($put, $nrow, $ncol): void {
            $cells = match ($k) {
                1 => [[$nrow - 1, 0], [$nrow - 1, 1], [$nrow - 1, 2], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1], [2, $ncol - 1], [3, $ncol - 1]],
                2 => [[$nrow - 3, 0], [$nrow - 2, 0], [$nrow - 1, 0], [0, $ncol - 4], [0, $ncol - 3], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1]],
                3 => [[$nrow - 3, 0], [$nrow - 2, 0], [$nrow - 1, 0], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1], [2, $ncol - 1], [3, $ncol - 1]],
                default => [[$nrow - 1, 0], [$nrow - 1, $ncol - 1], [0, $ncol - 3], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 3], [1, $ncol - 2], [1, $ncol - 1]],
            };
            foreach ($cells as $i => [$r, $c]) {
                $put($r, $c, $pos, $i + 1);
            }
        };

        $pos = 0;
        $row = 4;
        $col = 0;
        do {
            if ($row === $nrow && $col === 0) {
                $corner(1, $pos++);
            }
            if ($row === $nrow - 2 && $col === 0 && $ncol % 4 !== 0) {
                $corner(2, $pos++);
            }
            if ($row === $nrow - 2 && $col === 0 && $ncol % 8 === 4) {
                $corner(3, $pos++);
            }
            if ($row === $nrow + 4 && $col === 2 && $ncol % 8 === 0) {
                $corner(4, $pos++);
            }
            do {
                if ($row < $nrow && $col >= 0 && $grid[$row][$col] === -1) {
                    $utah($row, $col, $pos++);
                }
                $row -= 2;
                $col += 2;
            } while ($row >= 0 && $col < $ncol);
            $row += 1;
            $col += 3;
            do {
                if ($row >= 0 && $col < $ncol && $grid[$row][$col] === -1) {
                    $utah($row, $col, $pos++);
                }
                $row += 2;
                $col -= 2;
            } while ($row < $nrow && $col >= 0);
            $row += 3;
            $col += 1;
        } while ($row < $nrow || $col < $ncol);

        if ($grid[$nrow - 1][$ncol - 1] === -1) {
            $grid[$nrow - 1][$ncol - 1] = 1;
            $grid[$nrow - 2][$ncol - 2] = 1;
        }
        foreach ($grid as $r => $line) {
            foreach ($line as $c => $v) {
                if ($v === -1) {
                    $grid[$r][$c] = 0;
                }
            }
        }
        return $grid;
    }

    /**
     * Reed-Solomon ECC over GF(256) with primitive polynomial 0x12D, roots alpha^1..alpha^n.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function rsEcc(array $data, int $n): array
    {
        if (self::$exp === null) {
            $e = array_fill(0, 512, 0);
            $l = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $e[$i] = $x;
                $l[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x12D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $e[$i] = $e[$i - 255];
            }
            self::$exp = $e;
            self::$log = $l;
        }
        $exp = self::$exp;
        $log = self::$log;
        // generator, highest degree first
        $gen = [1];
        for ($i = 1; $i <= $n; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= $c === 0 ? 0 : $exp[$log[$c] + $i];
            }
            $gen = $next;
        }
        $rem = array_fill(0, $n, 0);
        foreach ($data as $d) {
            $factor = $d ^ array_shift($rem);
            $rem[] = 0;
            if ($factor !== 0) {
                $lf = $log[$factor];
                for ($i = 0; $i < $n; $i++) {
                    if ($gen[$i + 1] !== 0) {
                        $rem[$i] ^= $exp[$log[$gen[$i + 1]] + $lf];
                    }
                }
            }
        }
        return $rem;
    }
}
