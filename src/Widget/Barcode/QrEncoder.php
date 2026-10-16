<?php

declare(strict_types=1);

namespace IvoPdf\Widget\Barcode;

use InvalidArgumentException;

/**
 * Pure-PHP QR Code (ISO/IEC 18004) encoder: versions 1-40, ECC levels L/M/Q/H, numeric / alphanumeric / byte
 * segments (optimal DP segmentation, ECI 26 for UTF-8 when non-ASCII), Reed-Solomon, interleaving and mask scoring.
 */
final class QrEncoder
{
    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /** Format-info bits per ECC level. */
    private const FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** @var array<string, list<int>> ECC codewords per block, index = version. */
    private const ECC_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** @var array<string, list<int>> Number of error-correction blocks, index = version. */
    private const NUM_BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** @var list<int>|null */
    private static ?array $exp = null;
    /** @var list<int>|null */
    private static ?array $log = null;

    /**
     * Encodes $data into a module matrix.
     *
     * @param string   $data       Raw (UTF-8) string.
     * @param string   $ecc        'L', 'M', 'Q' or 'H'.
     * @param int|null $mask       Force a mask 0-7 (null = best by penalty score).
     * @param int      $minVersion Minimum version 1-40.
     * @return array{matrix: list<list<int>>, version: int, mask: int, size: int}
     */
    public static function encode(string $data, string $ecc = 'M', ?int $mask = null, int $minVersion = 1): array
    {
        $ecc = strtoupper($ecc);
        if (!isset(self::FORMAT_BITS[$ecc])) {
            $ecc = 'M';
        }
        $bytes = $data === '' ? [] : array_values(unpack('C*', $data) ?: []);
        $needEci = false;
        foreach ($bytes as $b) {
            if ($b >= 0x80) {
                $needEci = true;
                break;
            }
        }

        $version = 0;
        $segments = [];
        $cache = [];
        for ($v = max(1, $minVersion); $v <= 40; $v++) {
            $cls = $v <= 9 ? 0 : ($v <= 26 ? 1 : 2);
            $cache[$cls] ??= self::segment($bytes, $cls);
            $bits = $needEci ? 12 : 0;
            foreach ($cache[$cls] as $seg) {
                $bits += 4 + self::ccBits($seg['mode'], $cls) + self::segDataBits($seg['mode'], $seg['len']);
            }
            if ($bits <= self::dataCodewords($v, $ecc) * 8) {
                $version = $v;
                $segments = $cache[$cls];
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Data too long for a QR code');
        }

        // Bit stream
        $cls = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        $bits = '';
        if ($needEci) {
            $bits .= '0111' . str_pad(decbin(26), 8, '0', STR_PAD_LEFT);
        }
        $pos = 0;
        foreach ($segments as $seg) {
            $mode = $seg['mode'];
            $len = $seg['len'];
            $bits .= ['N' => '0001', 'A' => '0010', 'B' => '0100'][$mode];
            $bits .= str_pad(decbin($len), self::ccBits($mode, $cls), '0', STR_PAD_LEFT);
            $chunk = array_slice($bytes, $pos, $len);
            $pos += $len;
            if ($mode === 'N') {
                for ($i = 0; $i < $len; $i += 3) {
                    $g = array_slice($chunk, $i, 3);
                    $n = (int) implode('', array_map(static fn(int $c): string => chr($c), $g));
                    $bits .= str_pad(decbin($n), count($g) * 3 + 1, '0', STR_PAD_LEFT);
                }
            } elseif ($mode === 'A') {
                for ($i = 0; $i < $len; $i += 2) {
                    if ($i + 1 < $len) {
                        $n = (int) strpos(self::ALNUM, chr($chunk[$i])) * 45 + (int) strpos(self::ALNUM, chr($chunk[$i + 1]));
                        $bits .= str_pad(decbin($n), 11, '0', STR_PAD_LEFT);
                    } else {
                        $bits .= str_pad(decbin((int) strpos(self::ALNUM, chr($chunk[$i]))), 6, '0', STR_PAD_LEFT);
                    }
                }
            } else {
                foreach ($chunk as $c) {
                    $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
                }
            }
        }
        $capBits = self::dataCodewords($version, $ecc) * 8;
        $bits .= str_repeat('0', min(4, $capBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $codewords = [];
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
            $codewords[] = (int) bindec(substr($bits, $i, 8));
        }
        for ($pad = 0xEC; count($codewords) < intdiv($capBits, 8); $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        $all = self::addEccAndInterleave($codewords, $version, $ecc);
        return self::buildMatrix($all, $version, $ecc, $mask);
    }

    /** Total data codewords for version/level. */
    public static function dataCodewords(int $ver, string $ecc): int
    {
        return intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK[$ecc][$ver] * self::NUM_BLOCKS[$ecc][$ver];
    }

    private static function rawModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $na = intdiv($ver, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($ver >= 7) {
                $r -= 36;
            }
        }
        return $r;
    }

    private static function ccBits(string $mode, int $cls): int
    {
        return match ($mode) {
            'N' => [10, 12, 14][$cls],
            'A' => [9, 11, 13][$cls],
            default => [8, 16, 16][$cls],
        };
    }

    private static function segDataBits(string $mode, int $len): int
    {
        return match ($mode) {
            'N' => intdiv($len, 3) * 10 + [0, 4, 7][$len % 3],
            'A' => intdiv($len, 2) * 11 + ($len % 2) * 6,
            default => $len * 8,
        };
    }

    /**
     * Optimal-ish segmentation by dynamic programming (costs in 1/6 bit).
     *
     * @param list<int> $bytes
     * @return list<array{mode: string, len: int}>
     */
    private static function segment(array $bytes, int $cls): array
    {
        $n = count($bytes);
        if ($n === 0) {
            return [];
        }
        $modes = ['N', 'A', 'B'];
        $charCost = ['N' => 20, 'A' => 33, 'B' => 48];
        $head = [];
        foreach ($modes as $m) {
            $head[$m] = (4 + self::ccBits($m, $cls)) * 6;
        }
        $inf = PHP_INT_MAX >> 2;
        $cost = [];
        $from = [];
        for ($i = 0; $i < $n; $i++) {
            $b = $bytes[$i];
            $ok = [
                'N' => $b >= 48 && $b <= 57,
                'A' => $b < 128 && strpos(self::ALNUM, chr($b)) !== false,
                'B' => true,
            ];
            foreach ($modes as $m) {
                $cost[$i][$m] = $inf;
                if (!$ok[$m]) {
                    continue;
                }
                if ($i === 0) {
                    $cost[$i][$m] = $head[$m] + $charCost[$m];
                    $from[$i][$m] = '';
                    continue;
                }
                $best = $cost[$i - 1][$m];
                $bf = $m;
                foreach ($modes as $p) {
                    if ($p !== $m && $cost[$i - 1][$p] + $head[$m] < $best) {
                        $best = $cost[$i - 1][$p] + $head[$m];
                        $bf = $p;
                    }
                }
                if ($best < $inf) {
                    $cost[$i][$m] = $best + $charCost[$m];
                    $from[$i][$m] = $bf;
                }
            }
        }
        $cur = 'B';
        $bestC = $inf;
        foreach (['B', 'A', 'N'] as $m) {
            if ($cost[$n - 1][$m] < $bestC) {
                $bestC = $cost[$n - 1][$m];
                $cur = $m;
            }
        }
        $seq = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $seq[$i] = $cur;
            $cur = $from[$i][$cur];
        }
        ksort($seq);
        $segs = [];
        foreach ($seq as $m) {
            $last = count($segs) - 1;
            if ($last >= 0 && $segs[$last]['mode'] === $m) {
                $segs[$last]['len']++;
            } else {
                $segs[] = ['mode' => $m, 'len' => 1];
            }
        }
        return $segs;
    }

    private static function initGf(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $e = array_fill(0, 512, 0);
        $l = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $e[$i] = $x;
            $l[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $e[$i] = $e[$i - 255];
        }
        self::$exp = $e;
        self::$log = $l;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function rsRemainder(array $data, int $degree): array
    {
        self::initGf();
        $exp = self::$exp;
        $log = self::$log;
        // generator polynomial coefficients, highest degree first (leading 1 included)
        $gen = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= $c === 0 ? 0 : $exp[$log[$c] + $i];
            }
            $gen = $next;
        }
        $rem = array_fill(0, $degree, 0);
        foreach ($data as $d) {
            $factor = $d ^ array_shift($rem);
            $rem[] = 0;
            if ($factor !== 0) {
                $lf = $log[$factor];
                for ($i = 0; $i < $degree; $i++) {
                    $g = $gen[$i + 1];
                    if ($g !== 0) {
                        $rem[$i] ^= $exp[$log[$g] + $lf];
                    }
                }
            }
        }
        return $rem;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function addEccAndInterleave(array $data, int $ver, string $ecc): array
    {
        $nb = self::NUM_BLOCKS[$ecc][$ver];
        $eccLen = self::ECC_PER_BLOCK[$ecc][$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $numShort = $nb - $raw % $nb;
        $shortLen = intdiv($raw, $nb);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $nb; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $rs = self::rsRemainder($dat, $eccLen);
            if ($i < $numShort) {
                $dat[] = -1; // placeholder, skipped on interleave
            }
            $blocks[] = array_merge($dat, $rs);
        }
        $out = [];
        $blockLen = $shortLen + 1;
        for ($i = 0; $i < $blockLen; $i++) {
            for ($j = 0; $j < $nb; $j++) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $out[] = $blocks[$j][$i];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<int> $codewords
     * @return array{matrix: list<list<int>>, version: int, mask: int, size: int}
     */
    private static function buildMatrix(array $codewords, int $ver, string $ecc, ?int $forceMask): array
    {
        $size = $ver * 4 + 17;
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, int $v) use (&$m, &$fn, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $m[$y][$x] = $v;
                $fn[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0 ? 1 : 0);
            $set($i, 6, $i % 2 === 0 ? 1 : 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, ($d !== 2 && $d !== 4) ? 1 : 0);
                }
            }
        }
        $align = self::alignmentPositions($ver);
        $na = count($align);
        for ($i = 0; $i < $na; $i++) {
            for ($j = 0; $j < $na; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($align[$i] + $dx, $align[$j] + $dy, max(abs($dx), abs($dy)) !== 1 ? 1 : 0);
                    }
                }
            }
        }
        $drawFormat = static function (int $mask) use ($ecc, $size, $set): void {
            $data = (self::FORMAT_BITS[$ecc] << 3) | $mask;
            $rem = $data;
            for ($i = 0; $i < 10; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
            }
            $bits = (($data << 10) | $rem) ^ 0x5412;
            $b = static fn(int $i): int => ($bits >> $i) & 1;
            for ($i = 0; $i <= 5; $i++) {
                $set(8, $i, $b($i));
            }
            $set(8, 7, $b(6));
            $set(8, 8, $b(7));
            $set(7, 8, $b(8));
            for ($i = 9; $i < 15; $i++) {
                $set(14 - $i, 8, $b($i));
            }
            for ($i = 0; $i < 8; $i++) {
                $set($size - 1 - $i, 8, $b($i));
            }
            for ($i = 8; $i < 15; $i++) {
                $set(8, $size - 15 + $i, $b($i));
            }
            $set(8, $size - 8, 1);
        };
        $drawFormat(0);
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }

        // Codewords in zigzag order
        $total = count($codewords) * 8;
        $idx = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $idx < $total) {
                        $m[$y][$x] = ($codewords[$idx >> 3] >> (7 - ($idx & 7))) & 1;
                        $idx++;
                    }
                }
            }
        }

        // Masks
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $bestMatrix = $m;
        $masks = ($forceMask !== null && $forceMask >= 0 && $forceMask <= 7) ? [$forceMask] : range(0, 7);
        foreach ($masks as $mask) {
            $t = $m;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if ($fn[$y][$x]) {
                        continue;
                    }
                    $inv = match ($mask) {
                        0 => ($x + $y) % 2 === 0,
                        1 => $y % 2 === 0,
                        2 => $x % 3 === 0,
                        3 => ($x + $y) % 3 === 0,
                        4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                        5 => $x * $y % 2 + $x * $y % 3 === 0,
                        6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                        default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                    };
                    if ($inv) {
                        $t[$y][$x] ^= 1;
                    }
                }
            }
            // Format bits for this mask are written on the masked copy
            $saveM = $m;
            $m = $t;
            $drawFormat($mask);
            $t = $m;
            $m = $saveM;
            $score = count($masks) === 1 ? 0 : self::penalty($t, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
                $bestMatrix = $t;
            }
        }
        return ['matrix' => $bestMatrix, 'version' => $ver, 'mask' => $bestMask, 'size' => $size];
    }

    /** @return list<int> */
    private static function alignmentPositions(int $ver): array
    {
        if ($ver === 1) {
            return [];
        }
        $n = intdiv($ver, 7) + 2;
        $size = $ver * 4 + 17;
        $step = $ver === 32 ? 26 : (int) (ceil(($ver * 4 + 4) / ($n * 2 - 2)) * 2);
        $res = [];
        for ($i = 0, $pos = $size - 7; $i < $n - 1; $i++, $pos -= $step) {
            array_unshift($res, $pos);
        }
        array_unshift($res, 6);
        return $res;
    }

    /** @param list<list<int>> $m */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        $dark = 0;
        $rows = [];
        $cols = array_fill(0, $size, '');
        for ($y = 0; $y < $size; $y++) {
            $rows[$y] = implode('', $m[$y]);
            $dark += substr_count($rows[$y], '1');
            for ($x = 0; $x < $size; $x++) {
                $cols[$x] .= $m[$y][$x];
            }
        }
        foreach ([$rows, $cols] as $lines) {
            foreach ($lines as $line) {
                if (preg_match_all('/0{5,}|1{5,}/', $line, $mm)) {
                    foreach ($mm[0] as $run) {
                        $score += 3 + strlen($run) - 5;
                    }
                }
                $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $size * $size;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
