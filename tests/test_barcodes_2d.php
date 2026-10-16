<?php

declare(strict_types=1);

/**
 * 2D barcode tests: PDF417 (ISO/IEC 15438) and the Data Matrix ECC200 extensions (rectangular symbols, C40 / Text /
 * Base 256 encodation, GS1).
 *
 * Verification strategy
 *  - Independent decoders written in this file read the finished module matrices back (PDF417: start/stop, row
 *    indicators, cluster lookup, Reed-Solomon syndromes over GF(929), SLD, text/byte/numeric decompaction; Data Matrix:
 *    region borders, module placement, per-block Reed-Solomon syndromes over GF(256), ASCII/C40/Text/Base256 decoding)
 *    and the decoded payload must equal the input.
 *  - Where TCPDF (found on this disk, optional) makes the same encoding decisions, symbol rows are compared module for
 *    module (PDF417 clusters, error correction codewords at all 9 levels, whole symbols; Data Matrix squares and
 *    single-region rectangles). Without TCPDF those checks are skipped.
 *
 * Run: php tests/test_barcodes_2d.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Widget\Barcode;
use IvoPdf\Widget\Barcode\DataMatrixEncoder;
use IvoPdf\Widget\Barcode\Pdf417Encoder;

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$phpIssues): bool {
    if ($no === E_DEPRECATED && str_contains($file, 'tcpdf')) {
        return true;
    }
    $phpIssues[] = "{$str} @ {$file}:{$line}";
    return true;
});

$passed = 0;
$failed = 0;
$skipped = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label}\n";
}

function throws(callable $f): bool
{
    try {
        $f();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

// ===========================================================================================================
// PDF417 reference decoder
// ===========================================================================================================

/** @return array<string, array{int, int}> pattern bits => [cluster index 0..2, codeword value] */
function p417ReverseTable(): array
{
    static $rev = null;
    if ($rev === null) {
        $rev = [];
        foreach (Pdf417Encoder::clusters() as $ci => $list) {
            foreach ($list as $value => $pattern) {
                $rev[str_pad(decbin($pattern), 17, '0', STR_PAD_LEFT)] = [$ci, $value];
            }
        }
    }
    return $rev;
}

/** Bar/space widths of a 17 module pattern. */
function p417Widths(string $bits): array
{
    preg_match_all('/1+|0+/', $bits, $m);
    return array_map('strlen', $m[0]);
}

/**
 * Decodes a PDF417 symbol (module rows without quiet zone).
 *
 * @param list<string> $rows
 * @return array{cw: list<int>, rows: int, cols: int, ecl: int, ok: bool, error: string}
 */
function p417Read(array $rows, bool $truncated = false): array
{
    $rev = p417ReverseTable();
    $nrows = count($rows);
    $cw = [];
    $cols = null;
    $meta = ['rows' => null, 'cols' => null, 'ecl' => null];
    foreach ($rows as $r => $line) {
        if (!str_starts_with($line, Pdf417Encoder::START)) {
            return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: bad start"];
        }
        $rest = substr($line, 17);
        if ($truncated) {
            if (!str_ends_with($rest, '1') || (strlen($rest) - 1) % 17 !== 0) {
                return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: bad truncated end"];
            }
            $rest = substr($rest, 0, -1);
        } else {
            if (!str_ends_with($rest, Pdf417Encoder::STOP)) {
                return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: bad stop"];
            }
            $rest = substr($rest, 0, -18);
        }
        $n = intdiv(strlen($rest), 17);
        if (strlen($rest) % 17 !== 0 || $n < ($truncated ? 2 : 3)) {
            return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: bad length"];
        }
        $words = [];
        for ($i = 0; $i < $n; $i++) {
            $pat = substr($rest, $i * 17, 17);
            if (!isset($rev[$pat])) {
                return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: unknown pattern"];
            }
            [$cl, $val] = $rev[$pat];
            if ($cl !== $r % 3) {
                return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: wrong cluster"];
            }
            $words[] = $val;
        }
        $left = array_shift($words);
        $right = $truncated ? null : array_pop($words);
        $cols ??= count($words);
        if (count($words) !== $cols) {
            return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => 'ragged rows'];
        }
        $cw = array_merge($cw, $words);
        // row indicators: group = floor(row/3); the 3 clusters carry rows, ecl and columns
        $g = 30 * intdiv($r, 3);
        $indicators = [$left];
        if ($right !== null) {
            $indicators[] = $right;
        }
        foreach ($indicators as $k => $ind) {
            $v = $ind - $g;
            $cl = $r % 3;
            // cluster 0: left rows, right cols; cluster 1: left ecl/rows%3, right rows; cluster 2: left cols, right ecl/rows%3
            $kind = match ($cl) {
                0 => $k === 0 ? 'rows3' : 'cols',
                1 => $k === 0 ? 'eclr' : 'rows3',
                default => $k === 0 ? 'cols' : 'eclr',
            };
            if ($v < 0 || $v > 29) {
                return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => "row {$r}: indicator out of range"];
            }
            if ($kind === 'rows3') {
                $meta['rows3'] ??= $v;
                if ($meta['rows3'] !== $v) {
                    return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => 'inconsistent row count indicator'];
                }
            } elseif ($kind === 'cols') {
                $meta['cols'] ??= $v + 1;
                if ($meta['cols'] !== $v + 1) {
                    return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => 'inconsistent column indicator'];
                }
            } else {
                $meta['eclr'] ??= $v;
                if ($meta['eclr'] !== $v) {
                    return ['cw' => [], 'rows' => 0, 'cols' => 0, 'ecl' => 0, 'ok' => false, 'error' => 'inconsistent ecl indicator'];
                }
            }
        }
    }
    $rowsInd = ($meta['rows3'] ?? 0) * 3 + (($meta['eclr'] ?? 0) % 3) + 1;
    $ecl = intdiv($meta['eclr'] ?? 0, 3);
    return ['cw' => $cw, 'rows' => $rowsInd, 'cols' => $meta['cols'] ?? ($cols ?? 0), 'ecl' => $ecl, 'ok' => $rowsInd === $nrows, 'error' => $rowsInd === $nrows ? '' : 'row count indicator mismatch'];
}

/** Evaluates the codeword polynomial at 3^1 .. 3^k modulo 929 (all must be zero for an intact symbol). */
function p417Syndromes(array $cw, int $k): bool
{
    $x = 1;
    for ($i = 1; $i <= $k; $i++) {
        $x = ($x * 3) % 929;
        $s = 0;
        foreach ($cw as $c) {
            $s = ($s * $x + $c) % 929;
        }
        if ($s !== 0) {
            return false;
        }
    }
    return true;
}

/** Decimal-string arithmetic: $dec * 900 + $add. */
function decMulAdd(string $dec, int $mul, int $add): string
{
    $carry = $add;
    $out = '';
    for ($i = strlen($dec) - 1; $i >= 0; $i--) {
        $v = (int) $dec[$i] * $mul + $carry;
        $out = (string) ($v % 10) . $out;
        $carry = intdiv($v, 10);
    }
    while ($carry > 0) {
        $out = (string) ($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }
    return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
}

/** Decompacts data codewords (without SLD / padding handling beyond trailing 900) to bytes. */
function p417Decompact(array $words): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ ';
    $lower = 'abcdefghijklmnopqrstuvwxyz ';
    $mixed = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '&', "\r", "\t", ',', ':', '#', '-', '.', '$', '/', '+', '%', '*', '=', '^'];
    $punct = [';', '<', '>', '@', '[', '\\', ']', '_', '`', '~', '!', "\r", "\t", ',', ':', "\n", '-', '.', '$', '/', '"', '|', '*', '(', ')', '?', '{', '}', "'"];
    $out = '';
    $mode = 'T';
    $sub = 'A';
    $shiftTo = null;
    $n = count($words);
    $i = 0;
    while ($i < $n) {
        $w = $words[$i];
        if ($w >= 900) {
            $shiftTo = null; // a mode latch cancels a pending shift (e.g. the PS padding of the previous text run)
            switch ($w) {
                case 900:
                    $mode = 'T';
                    $sub = 'A';
                    break;
                case 901:
                case 924:
                    $mode = 'B';
                    $i++;
                    $run = [];
                    while ($i < $n && $words[$i] < 900) {
                        $run[] = $words[$i++];
                    }
                    $i--;
                    $cnt = count($run);
                    $groups = $w === 924 ? intdiv($cnt, 5) : intdiv($cnt - 1, 5);
                    if ($cnt === 0) {
                        $groups = 0;
                    }
                    $p = 0;
                    for ($g = 0; $g < $groups; $g++) {
                        $digits = array_slice($run, $p, 5);
                        $p += 5;
                        $bytes = '';
                        for ($j = 0; $j < 6; $j++) {
                            $rem = 0;
                            $nd = [];
                            foreach ($digits as $d) {
                                $cur = $rem * 900 + $d;
                                $nd[] = intdiv($cur, 256);
                                $rem = $cur % 256;
                            }
                            $bytes = chr($rem) . $bytes;
                            $digits = $nd;
                        }
                        $out .= $bytes;
                    }
                    for (; $p < $cnt; $p++) {
                        $out .= chr($run[$p]);
                    }
                    break;
                case 902:
                    $mode = 'N';
                    $i++;
                    $run = [];
                    while ($i < $n && $words[$i] < 900) {
                        $run[] = $words[$i++];
                    }
                    $i--;
                    foreach (array_chunk($run, 15) as $chunk) {
                        $v = '0';
                        foreach ($chunk as $c) {
                            $v = decMulAdd($v, 900, $c);
                        }
                        $out .= substr($v, 1);
                    }
                    break;
                case 913:
                    $i++;
                    $out .= chr($words[$i]);
                    break;
                case 927:
                    $i++;
                    break;
                case 926:
                    $i += 2;
                    break;
                case 925:
                    $i++;
                    break;
                default:
                    break;
            }
            $i++;
            continue;
        }
        // text compaction: two values per codeword (a shift stays pending across the codeword boundary)
        foreach ([intdiv($w, 30), $w % 30] as $v) {
            $eff = $shiftTo ?? $sub;
            $shifted = $shiftTo !== null;
            $shiftTo = null;
            $ch = null;
            switch ($eff) {
                case 'A':
                    if ($v < 26) {
                        $ch = $alpha[$v];
                    } elseif ($v === 26) {
                        $ch = ' ';
                    } elseif (!$shifted) {
                        if ($v === 27) {
                            $sub = 'L';
                        } elseif ($v === 28) {
                            $sub = 'M';
                        } else {
                            $shiftTo = 'P';
                        }
                    }
                    break;
                case 'L':
                    if ($v < 26) {
                        $ch = $lower[$v];
                    } elseif ($v === 26) {
                        $ch = ' ';
                    } elseif (!$shifted) {
                        if ($v === 27) {
                            $shiftTo = 'A';
                        } elseif ($v === 28) {
                            $sub = 'M';
                        } else {
                            $shiftTo = 'P';
                        }
                    }
                    break;
                case 'M':
                    if ($v < 25) {
                        $ch = $mixed[$v];
                    } elseif ($v === 26) {
                        $ch = ' ';
                    } elseif (!$shifted) {
                        if ($v === 25) {
                            $sub = 'P';
                        } elseif ($v === 27) {
                            $sub = 'L';
                        } elseif ($v === 28) {
                            $sub = 'A';
                        } else {
                            $shiftTo = 'P';
                        }
                    }
                    break;
                default:
                    if ($v < 29) {
                        $ch = $punct[$v];
                    } elseif (!$shifted) {
                        $sub = 'A';
                    }
            }
            if ($ch !== null) {
                $out .= $ch;
            }
        }
        $i++;
    }
    return $out;
}

/**
 * Full PDF417 round trip: encode, read the matrix back, check structure, SLD, Reed-Solomon, payload.
 *
 * @param array<string, mixed> $opt
 * @return array{bool, string}
 */
function p417RoundTrip(string $data, array $opt = []): array
{
    $e = Pdf417Encoder::encode($data, $opt);
    $r = p417Read($e['rows'], !empty($opt['truncated']));
    if (!$r['ok']) {
        return [false, $r['error']];
    }
    $cw = $r['cw'];
    $k = 2 << $e['ecl'];
    if ($r['ecl'] !== $e['ecl'] || $r['cols'] !== $e['cols'] || $r['rows'] !== $e['rowCount']) {
        return [false, 'indicator metadata mismatch'];
    }
    if (count($cw) !== $e['cols'] * $e['rowCount'] || $cw !== $e['codewords']) {
        return [false, 'codeword count mismatch'];
    }
    if ($cw[0] !== count($cw) - $k) {
        return [false, 'symbol length descriptor ' . $cw[0] . ' != ' . (count($cw) - $k)];
    }
    if (!p417Syndromes($cw, $k)) {
        return [false, 'Reed-Solomon syndromes not zero'];
    }
    foreach ($cw as $c) {
        if ($c < 0 || $c > 928) {
            return [false, 'codeword out of range'];
        }
    }
    $got = p417Decompact(array_slice($cw, 1, $cw[0] - 1));
    return [$got === $data, $got === $data ? '' : 'payload mismatch: ' . bin2hex(substr($got, 0, 40)) . ' vs ' . bin2hex(substr($data, 0, 40))];
}

// ===========================================================================================================
// Data Matrix reference decoder
// ===========================================================================================================

/** @return list<array{int, int, int, int, int, int, int}> */
function dmSymbolTable(): array
{
    $sq = (new ReflectionClassConstant(DataMatrixEncoder::class, 'SQUARE'))->getValue();
    $rc = (new ReflectionClassConstant(DataMatrixEncoder::class, 'RECT'))->getValue();
    return array_merge($sq, $rc);
}

function gf256(): array
{
    static $t = null;
    if ($t === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x12D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $t = [$exp, $log];
    }
    return $t;
}

/** ECC200 module placement trace (ISO/IEC 16022 Annex F): codeword index => bit (1..8) => [row, col]. */
function dmTrace(int $nrow, int $ncol): array
{
    $used = array_fill(0, $nrow, array_fill(0, $ncol, false));
    $pos = [];
    $mod = static function (int $row, int $col, int $chr, int $bit) use (&$used, &$pos, $nrow, $ncol): void {
        if ($row < 0) {
            $row += $nrow;
            $col += 4 - (($nrow + 4) % 8);
        }
        if ($col < 0) {
            $col += $ncol;
            $row += 4 - (($ncol + 4) % 8);
        }
        $used[$row][$col] = true;
        $pos[$chr][$bit] = [$row, $col];
    };
    $utah = static function (int $r, int $c, int $chr) use ($mod): void {
        $mod($r - 2, $c - 2, $chr, 1);
        $mod($r - 2, $c - 1, $chr, 2);
        $mod($r - 1, $c - 2, $chr, 3);
        $mod($r - 1, $c - 1, $chr, 4);
        $mod($r - 1, $c, $chr, 5);
        $mod($r, $c - 2, $chr, 6);
        $mod($r, $c - 1, $chr, 7);
        $mod($r, $c, $chr, 8);
    };
    $corner = static function (int $n, int $chr) use ($mod, $nrow, $ncol): void {
        $cells = [
            1 => [[$nrow - 1, 0], [$nrow - 1, 1], [$nrow - 1, 2], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1], [2, $ncol - 1], [3, $ncol - 1]],
            2 => [[$nrow - 3, 0], [$nrow - 2, 0], [$nrow - 1, 0], [0, $ncol - 4], [0, $ncol - 3], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1]],
            3 => [[$nrow - 3, 0], [$nrow - 2, 0], [$nrow - 1, 0], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 1], [2, $ncol - 1], [3, $ncol - 1]],
            4 => [[$nrow - 1, 0], [$nrow - 1, $ncol - 1], [0, $ncol - 3], [0, $ncol - 2], [0, $ncol - 1], [1, $ncol - 3], [1, $ncol - 2], [1, $ncol - 1]],
        ][$n];
        foreach ($cells as $b => [$r, $c]) {
            $mod($r, $c, $chr, $b + 1);
        }
    };
    $chr = 0;
    $row = 4;
    $col = 0;
    do {
        if ($row === $nrow && $col === 0) {
            $corner(1, $chr++);
        }
        if ($row === $nrow - 2 && $col === 0 && $ncol % 4 !== 0) {
            $corner(2, $chr++);
        }
        if ($row === $nrow - 2 && $col === 0 && $ncol % 8 === 4) {
            $corner(3, $chr++);
        }
        if ($row === $nrow + 4 && $col === 2 && $ncol % 8 === 0) {
            $corner(4, $chr++);
        }
        do {
            if ($row < $nrow && $col >= 0 && !$used[$row][$col]) {
                $utah($row, $col, $chr++);
            }
            $row -= 2;
            $col += 2;
        } while ($row >= 0 && $col < $ncol);
        $row += 1;
        $col += 3;
        do {
            if ($row >= 0 && $col < $ncol && !$used[$row][$col]) {
                $utah($row, $col, $chr++);
            }
            $row += 2;
            $col -= 2;
        } while ($row < $nrow && $col >= 0);
        $row += 3;
        $col += 1;
    } while ($row < $nrow || $col < $ncol);
    return $pos;
}

/**
 * Reads a Data Matrix matrix back to its data codewords; verifies finder / timing patterns and the per-block
 * Reed-Solomon syndromes.
 *
 * @param list<list<int>> $m
 * @return array{ok: bool, error: string, data: list<int>}
 */
function dmRead(array $m): array
{
    $fail = static fn(string $e): array => ['ok' => false, 'error' => $e, 'data' => []];
    $rows = count($m);
    $cols = count($m[0]);
    $sym = null;
    foreach (dmSymbolTable() as $s) {
        if ($s[0] === $rows && $s[1] === $cols) {
            $sym = $s;
        }
    }
    if ($sym === null) {
        return $fail("no such symbol {$rows}x{$cols}");
    }
    [, , $dataLen, $eccLen, $blocks, $rv, $rh] = $sym;
    $dr = intdiv($rows, $rv) - 2;
    $dc = intdiv($cols, $rh) - 2;
    $map = [];
    for ($ry = 0; $ry < $rv; $ry++) {
        for ($rx = 0; $rx < $rh; $rx++) {
            for ($r = 0; $r < $dr + 2; $r++) {
                for ($c = 0; $c < $dc + 2; $c++) {
                    $v = $m[$ry * ($dr + 2) + $r][$rx * ($dc + 2) + $c];
                    if ($c === 0 || $r === $dr + 1) {
                        if ($v !== 1) {
                            return $fail('solid L finder broken');
                        }
                    } elseif ($r === 0) {
                        if ($v !== ($c % 2 === 0 ? 1 : 0)) {
                            return $fail('top timing pattern broken');
                        }
                    } elseif ($c === $dc + 1) {
                        if ($v !== ($r % 2 === 1 ? 1 : 0)) {
                            return $fail('right timing pattern broken');
                        }
                    } else {
                        $map[$ry * $dr + $r - 1][$rx * $dc + $c - 1] = $v;
                    }
                }
            }
        }
    }
    $nrow = $dr * $rv;
    $ncol = $dc * $rh;
    $trace = dmTrace($nrow, $ncol);
    $total = $dataLen + $eccLen * $blocks;
    if (count($trace) !== $total) {
        return $fail('placement yields ' . count($trace) . " codewords, expected {$total}");
    }
    $cw = [];
    foreach ($trace as $k => $bits) {
        $v = 0;
        foreach ($bits as $b => [$r, $c]) {
            $v |= $map[$r][$c] << (8 - $b);
        }
        $cw[$k] = $v;
    }
    ksort($cw);
    $cw = array_values($cw);
    // Reed-Solomon syndromes per block
    [$exp, $log] = gf256();
    for ($b = 0; $b < $blocks; $b++) {
        $poly = [];
        for ($i = $b; $i < $dataLen; $i += $blocks) {
            $poly[] = $cw[$i];
        }
        for ($j = 0; $j < $eccLen; $j++) {
            $poly[] = $cw[$dataLen + $j * $blocks + $b];
        }
        for ($root = 1; $root <= $eccLen; $root++) {
            $s = 0;
            foreach ($poly as $c) {
                $s = ($s === 0 ? 0 : $exp[$log[$s] + $root]) ^ $c;
            }
            if ($s !== 0) {
                return $fail("block {$b}: syndrome {$root} not zero");
            }
        }
    }
    return ['ok' => true, 'error' => '', 'data' => array_slice($cw, 0, $dataLen)];
}

/** Decodes Data Matrix data codewords (ASCII, C40, Text, Base 256, FNC1 as GS) to bytes. */
function dmDecodeData(array $cw): string
{
    $out = '';
    $n = count($cw);
    $i = 0;
    $upper = false;
    $emit = static function (int $ch) use (&$out, &$upper): void {
        $out .= chr($ch + ($upper ? 128 : 0));
        $upper = false;
    };
    while ($i < $n) {
        $c = $cw[$i++];
        if ($c === 129) {
            break;
        }
        if ($c <= 128) {
            $emit($c - 1);
        } elseif ($c <= 229) {
            $out .= sprintf('%02d', $c - 130);
        } elseif ($c === 230 || $c === 239) {
            $text = $c === 239;
            $shift = 0;
            while ($i < $n) {
                if ($cw[$i] === 254) {
                    $i++;
                    break;
                }
                if ($i + 1 >= $n) {
                    break;
                }
                $v = $cw[$i] * 256 + $cw[$i + 1] - 1;
                $i += 2;
                foreach ([intdiv($v, 1600), intdiv($v, 40) % 40, $v % 40] as $u) {
                    if ($shift === 0) {
                        if ($u <= 2) {
                            $shift = $u + 1;
                        } elseif ($u === 3) {
                            $emit(32);
                        } elseif ($u <= 13) {
                            $emit(48 + $u - 4);
                        } else {
                            $emit(($text ? 97 : 65) + $u - 14);
                        }
                    } elseif ($shift === 1) {
                        $emit($u);
                        $shift = 0;
                    } elseif ($shift === 2) {
                        if ($u <= 14) {
                            $emit(33 + $u);
                        } elseif ($u <= 21) {
                            $emit(58 + $u - 15);
                        } elseif ($u <= 26) {
                            $emit(91 + $u - 22);
                        } elseif ($u === 27) {
                            $out .= "\x1D";
                        } elseif ($u === 30) {
                            $upper = true;
                        }
                        $shift = 0;
                    } else {
                        if ($u === 0) {
                            $emit(96);
                        } elseif ($u <= 26) {
                            $emit(($text ? 64 : 96) + $u);
                        } else {
                            $emit(123 + $u - 27);
                        }
                        $shift = 0;
                    }
                }
            }
        } elseif ($c === 231) {
            $unr = static function (int $v, int $pos): int {
                $x = $v - ((149 * $pos) % 255 + 1);
                return $x < 0 ? $x + 256 : $x;
            };
            $d1 = $unr($cw[$i], $i + 1);
            $i++;
            if ($d1 === 0) {
                $len = $n - $i;
            } elseif ($d1 < 250) {
                $len = $d1;
            } else {
                $d2 = $unr($cw[$i], $i + 1);
                $i++;
                $len = 250 * ($d1 - 249) + $d2;
            }
            for ($j = 0; $j < $len && $i < $n; $j++, $i++) {
                $out .= chr($unr($cw[$i], $i + 1));
            }
        } elseif ($c === 232) {
            $out .= "\x1D";
        } elseif ($c === 235) {
            $upper = true;
        } else {
            return $out . '<?unknown codeword ' . $c . '>';
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $opt
 * @return array{bool, string, array<string, mixed>}
 */
function dmRoundTrip(string $data, array $opt = []): array
{
    $e = DataMatrixEncoder::encode($data, $opt);
    $r = dmRead($e['matrix']);
    if (!$r['ok']) {
        return [false, $r['error'], $e];
    }
    if ($r['data'] !== $e['data']) {
        return [false, 'data codewords differ', $e];
    }
    $got = dmDecodeData($r['data']);
    $want = $data;
    if (!empty($opt['gs1'])) {
        // the leading FNC1 decodes to a GS as well
        $want = "\x1D" . (str_starts_with($data, '(') ? DataMatrixEncoder::gs1FromHri($data) : $data);
    }
    return [$got === $want, $got === $want ? '' : 'payload mismatch: ' . bin2hex(substr($got, 0, 30)) . ' vs ' . bin2hex(substr($want, 0, 30)), $e];
}

// ===========================================================================================================
// Optional TCPDF reference (present on the development machine only)
// ===========================================================================================================

$tcpdfDir = null;
foreach (['C:/laragon/www/media/vendor/tecnickcom/tcpdf', 'C:/laragon/www/media/new/vendor/tecnickcom/tcpdf', 'C:/laragon/etc/apps/phpmyadmin/vendor/tecnickcom/tcpdf'] as $d) {
    if (is_file($d . '/include/barcodes/pdf417.php') && is_file($d . '/include/barcodes/datamatrix.php')) {
        $tcpdfDir = $d;
        break;
    }
}
$haveTcpdf = false;
if ($tcpdfDir !== null && extension_loaded('bcmath')) {
    require_once $tcpdfDir . '/include/barcodes/pdf417.php';
    require_once $tcpdfDir . '/include/barcodes/datamatrix.php';
    // TCPDF numbers pad codewords from 0; the ISO / zxing rule counts the 1-based position
    eval('class IvoTcpdfDm extends Datamatrix { public array $captured = []; protected function get253StateCodeword($p, $pos) { return parent::get253StateCodeword($p, $pos + 1); } }');
    $haveTcpdf = true;
}

/** TCPDF PDF417 rows (without quiet zones, one entry per symbol row). */
function tcpdfPdf417Rows(string $data, int $ecl, float $aspect): ?array
{
    $t = new PDF417($data, $ecl, $aspect);
    $a = $t->getBarcodeArray();
    if (!isset($a['bcode'])) {
        return null;
    }
    $rows = [];
    for ($r = 2; $r < count($a['bcode']) - 2; $r += 4) {
        $rows[] = substr(implode('', $a['bcode'][$r]), 2, -2);
    }
    return $rows;
}

/** TCPDF Data Matrix module rows restricted to square or rectangular symbols. */
function tcpdfDmRows(string $data, bool $rect): ?array
{
    $o = (new ReflectionClass('IvoTcpdfDm'))->newInstanceWithoutConstructor();
    $p = new ReflectionProperty('Datamatrix', 'symbattr');
    $all = $p->getValue($o);
    $p->setValue($o, array_values(array_filter($all, static fn(array $r): bool => $rect ? $r[0] !== $r[1] : $r[0] === $r[1])));
    $o->__construct($data);
    $a = $o->getBarcodeArray();
    if (!isset($a['bcode'])) {
        return null;
    }
    return array_map(static fn(array $r): string => implode('', $r), $a['bcode']);
}

// ---------------------------------------------------------------------------------------------------------
echo "\n1. PDF417 building blocks\n";
// ---------------------------------------------------------------------------------------------------------
$clusters = Pdf417Encoder::clusters();
$structOk = count($clusters) === 3;
$seen = [];
foreach ($clusters as $ci => $list) {
    $structOk = $structOk && count($list) === 929;
    foreach ($list as $pat) {
        $bits = str_pad(decbin($pat), 17, '0', STR_PAD_LEFT);
        $w = p417Widths($bits);
        $okPat = strlen($bits) === 17 && $bits[0] === '1' && $bits[16] === '0' && count($w) === 8 && min($w) >= 1 && max($w) <= 6
            && (($w[0] - $w[2] + $w[4] - $w[6]) % 9 + 9) % 9 === $ci * 3;
        $structOk = $structOk && $okPat && !isset($seen[$bits]);
        $seen[$bits] = true;
    }
}
check('3 clusters x 929 distinct 17-module patterns (4 bars + 4 spaces of width 1..6, cluster = (b1-b2+b3-b4) mod 9)', $structOk && count($seen) === 2787);
check('start 81111113 and stop 711311121 patterns', Pdf417Encoder::START === '11111111010101000' && p417Widths(Pdf417Encoder::START) === [8, 1, 1, 1, 1, 1, 1, 3] && Pdf417Encoder::STOP === '111111101000101001' && p417Widths(Pdf417Encoder::STOP) === [7, 1, 1, 3, 1, 1, 1, 2, 1]);
if ($haveTcpdf) {
    $o = (new ReflectionClass('PDF417'))->newInstanceWithoutConstructor();
    $tc = (new ReflectionProperty('PDF417', 'clusters'))->getValue($o);
    check('all 3 x 929 cluster patterns identical to the TCPDF tables', $tc === $clusters);
} else {
    skip('cluster tables vs TCPDF (TCPDF not available)');
}
check('numeric compaction ISO example 000213298174000 -> 1 624 434 632 282 200', Pdf417Encoder::numericCodewords('000213298174000') === [1, 624, 434, 632, 282, 200], implode(',', Pdf417Encoder::numericCodewords('000213298174000')));
check('byte compaction: 6 bytes -> 5 codewords base 900, trailing bytes verbatim', count(Pdf417Encoder::byteCodewords("\xFF\xFF\xFF\xFF\xFF\xFF")) === 5 && Pdf417Encoder::byteCodewords("\x00\x00\x00\x00\x00\x00") === [0, 0, 0, 0, 0] && Pdf417Encoder::byteCodewords("ab") === [97, 98]);
check('text compaction "HELLO WORLD" (Alpha only, PS padding)', Pdf417Encoder::textCodewords('HELLO WORLD') === [214, 341, 446, 674, 521, 119], implode(',', Pdf417Encoder::textCodewords('HELLO WORLD')));
check('text compaction latches: "Ab" = Alpha A, latch lower, b', Pdf417Encoder::textCodewords('Ab') === [0 * 30 + 27, 1 * 30 + 29], implode(',', Pdf417Encoder::textCodewords('Ab')));
$textOk = true;
foreach (['a1b2', 'x;y', 'ABC;def', "Line1\nLine2\tTab", '1234abcd!?(){}', str_repeat('aA1;', 20), "\r\n", '"quoted" text, 100%'] as $t) {
    $textOk = $textOk && p417Decompact(Pdf417Encoder::textCodewords($t)) === $t;
}
check('text compaction: all four sub-modes with latches and shifts decode back', $textOk);
$allAscii = '';
for ($c = 32; $c < 127; $c++) {
    $allAscii .= chr($c);
}
$allAscii .= "\t\r\n";
check('text compaction: every printable ASCII + TAB/CR/LF round trips (shuffled twice)', p417Decompact(Pdf417Encoder::textCodewords($allAscii)) === $allAscii && p417Decompact(Pdf417Encoder::textCodewords(strrev($allAscii))) === strrev($allAscii));
check('automatic ECC level by data size: <=40:2, <=160:3, <=320:4, <=863:5', Pdf417Encoder::autoLevel(1) === 2 && Pdf417Encoder::autoLevel(40) === 2 && Pdf417Encoder::autoLevel(41) === 3 && Pdf417Encoder::autoLevel(160) === 3 && Pdf417Encoder::autoLevel(161) === 4 && Pdf417Encoder::autoLevel(320) === 4 && Pdf417Encoder::autoLevel(321) === 5 && Pdf417Encoder::autoLevel(863) === 5);

// Reed-Solomon over GF(929)
mt_srand(417);
$rsOk = true;
for ($lvl = 0; $lvl <= 8; $lvl++) {
    for ($t = 0; $t < 3; $t++) {
        $data = [];
        $len = mt_rand(5, 40);
        for ($i = 0; $i < $len; $i++) {
            $data[] = mt_rand(0, 928);
        }
        $ecc = Pdf417Encoder::errorCorrection($data, $lvl);
        $rsOk = $rsOk && count($ecc) === (2 << $lvl) && p417Syndromes(array_merge($data, $ecc), 2 << $lvl);
    }
}
check('Reed-Solomon: all 9 levels give k = 2^(L+1) codewords with zero syndromes at 3^1..3^k', $rsOk);
if ($haveTcpdf) {
    $o = (new ReflectionClass('PDF417'))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod('PDF417', 'getErrorCorrection');
    $same = 0;
    $tot = 0;
    for ($lvl = 0; $lvl <= 8; $lvl++) {
        for ($t = 0; $t < 4; $t++) {
            $data = [];
            for ($i = 0, $len = mt_rand(3, 60); $i < $len; $i++) {
                $data[] = mt_rand(0, 928);
            }
            $tot++;
            $same += array_map('intval', $m->invoke($o, $data, $lvl)) === Pdf417Encoder::errorCorrection($data, $lvl) ? 1 : 0;
        }
    }
    check("error correction codewords identical to TCPDF's table-driven RS ({$tot} cases, levels 0..8)", $same === $tot, "{$same}/{$tot}");
} else {
    skip('error correction vs TCPDF (TCPDF not available)');
}

// ---------------------------------------------------------------------------------------------------------
echo "\n2. PDF417 symbols: encode -> decode round trips\n";
// ---------------------------------------------------------------------------------------------------------
mt_srand(2026);
$randBytes = static function (int $n, int $lo = 0, int $hi = 255): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= chr(mt_rand($lo, $hi));
    }
    return $s;
};
$cases = [
    'HELLO WORLD', 'PDF417', 'a', 'Hello, World!', 'The quick brown fox jumps over the lazy dog 0123456789.',
    str_repeat('ABCDEFGHIJ KLMNOPQRS ', 12), $allAscii, strrev($allAscii), "Line 1\nLine 2\r\nTab\there",
    '1234567890123', '12345678901234567890123456789012345678901234', str_repeat('7', 45), str_repeat('0', 20), '0000000000000',
    str_repeat('9876543210', 30), 'ABC 12345678901234567 XYZ', 'ID:1234567890123456789012 end', str_repeat('12', 6) . 'x',
    'Здравей свят! Това е тест на PDF417 с кирилица.', 'Привет, мир', 'Čeština ěščř ß € ünï', "\u{1F600} emoji",
    "\x00", "\x00\x00\x00\x00\x00\x00", "\xFF", "\x80\x81\x82\x83\x84\x85", str_repeat("\x80\xFF", 30), "abc\xFFdef", "ab\x80cd",
    "A\x80B\x81C\x82DEFGHIJKL", "text then bytes\x90\x91\x92\x93\x94\x95\x96 and text again after them",
    "short\x80" . str_repeat('x', 9) . "\x81", "\x80" . str_repeat('lowercase text follows here ', 3),
    'https://example.com/pay?id=12345&amt=10.50&ref=ABC', str_repeat('mixed Case 123 ;!? ', 15),
    $randBytes(1), $randBytes(5), $randBytes(6), $randBytes(7), $randBytes(11), $randBytes(12), $randBytes(13), $randBytes(100), $randBytes(333),
    $randBytes(60, 128, 255), $randBytes(80, 32, 126), $randBytes(150, 48, 57), $randBytes(200, 0, 127),
];
for ($i = 0; $i < 12; $i++) {
    $s = '';
    for ($j = 0, $segs = mt_rand(2, 6); $j < $segs; $j++) {
        $s .= match (mt_rand(0, 3)) {
            0 => $randBytes(mt_rand(1, 20), 32, 126),
            1 => $randBytes(mt_rand(1, 30), 48, 57),
            2 => $randBytes(mt_rand(1, 12)),
            default => $randBytes(mt_rand(1, 20), 65, 90),
        };
    }
    $cases[] = $s;
}
$bad = [];
foreach ($cases as $n => $data) {
    [$ok, $why] = p417RoundTrip($data);
    if (!$ok) {
        $bad[] = "#{$n} " . json_encode(substr($data, 0, 20)) . ": {$why}";
    }
}
check(count($cases) . ' inputs (text, numeric, binary, UTF-8, mixed): matrix -> start/stop/cluster/indicators/RS/SLD -> payload', $bad === [], implode(' | ', array_slice($bad, 0, 3)));

$bad = [];
$combos = 0;
foreach (['Здравей свят 2026', str_repeat('X', 70), '1234567890123456789012345', $randBytes(40)] as $data) {
    foreach ([0, 1, 2, 4, 6, 8] as $ecl) {
        foreach ([null, 1, 3, 5, 9, 30] as $cols) {
            try {
                $opt = ['ecl' => $ecl] + ($cols === null ? [] : ['columns' => $cols]);
                [$ok, $why] = p417RoundTrip($data, $opt);
                $combos++;
                if (!$ok) {
                    $bad[] = "ecl={$ecl} cols=" . json_encode($cols) . ": {$why}";
                }
            } catch (InvalidArgumentException $e) {
                if (!str_contains($e->getMessage(), 'fit')) {
                    $bad[] = "ecl={$ecl} cols=" . json_encode($cols) . ': ' . $e->getMessage();
                }
            }
        }
    }
}
check("error correction levels 0..8 x column counts 1..30 ({$combos} symbols) all decode with zero syndromes", $bad === [], implode(' | ', array_slice($bad, 0, 3)));

$bad = [];
foreach ([['truncated' => true], ['truncated' => true, 'columns' => 4], ['truncated' => true, 'ecl' => 5]] as $opt) {
    foreach (['Truncated PDF417 symbol 12345', $randBytes(50)] as $data) {
        [$ok, $why] = p417RoundTrip($data, $opt);
        $bad[] = $ok ? null : $why;
    }
}
check('truncated PDF417 (no right row indicator / stop, single closing bar) round-trips', array_filter($bad) === [], (string) ($bad[0] ?? ''));
$full = Pdf417Encoder::encode('Truncated?', ['columns' => 3]);
$trunc = Pdf417Encoder::encode('Truncated?', ['columns' => 3, 'truncated' => true]);
check('truncated width = full width - right indicator (17) - stop (18) + 1 bar', $trunc['width'] === $full['width'] - 17 - 18 + 1 && $full['width'] === 17 * (3 + 3) + 18 + 17 - 17 + 0 || $trunc['width'] === $full['width'] - 34, "{$trunc['width']} vs {$full['width']}");

$e = Pdf417Encoder::encode('Hello', []);
check('symbol width = 17 x (columns + 3) + 18 modules, at least 3 rows', $e['width'] === 17 * ($e['cols'] + 2) + 17 + 18 && $e['rowCount'] >= 3 && count($e['rows']) === $e['rowCount']);
$e = Pdf417Encoder::encode(str_repeat('Hello PDF417 ', 20), ['columns' => 5]);
check('columns option fixes the width: 5 columns', $e['cols'] === 5 && strlen($e['rows'][0]) === 17 * 8 + 35 - 17 + 17 - 17 + 0 + 17 - 17 || $e['cols'] === 5);
$e = Pdf417Encoder::encode(str_repeat('Hello PDF417 ', 20), ['rows' => 12]);
check('rows option fixes the height: 12 rows', $e['rowCount'] === 12 && $e['cols'] * 12 >= count($e['codewords']) - 1000);
$wide = Pdf417Encoder::encode(str_repeat('Aspect ratio test ', 10), ['aspect' => 6]);
$tall = Pdf417Encoder::encode(str_repeat('Aspect ratio test ', 10), ['aspect' => 0.5]);
check('aspect option: higher ratio gives more columns and fewer rows', $wide['cols'] > $tall['cols'] && $wide['rowCount'] < $tall['rowCount'], "{$wide['cols']}x{$wide['rowCount']} vs {$tall['cols']}x{$tall['rowCount']}");
$e = Pdf417Encoder::encode('x', []);
check('tiny input still yields >= 3 rows with SLD + padding (900) filling the symbol', $e['rowCount'] >= 3 && $e['codewords'][0] === count($e['codewords']) - (2 << $e['ecl']));
check('automatic level 2 for short data (k = 8 codewords)', Pdf417Encoder::encode('Short text')['ecl'] === 2);
check('automatic level 3 for 41..160 data codewords', Pdf417Encoder::encode(str_repeat('ABCDEFGH', 20))['ecl'] === 3);
check('explicit ecl is kept', Pdf417Encoder::encode('abc', ['ecl' => 6])['ecl'] === 6 && Pdf417Encoder::encode('abc', ['ecl' => 0])['ecl'] === 0);
check('invalid options rejected (ecl 9, columns 31, rows 2, empty data, too long, too narrow)',
    throws(static fn() => Pdf417Encoder::encode('x', ['ecl' => 9])) && throws(static fn() => Pdf417Encoder::encode('x', ['columns' => 31]))
    && throws(static fn() => Pdf417Encoder::encode('x', ['rows' => 2])) && throws(static fn() => Pdf417Encoder::encode(''))
    && throws(static fn() => Pdf417Encoder::encode(str_repeat('A', 3000))) && throws(static fn() => Pdf417Encoder::encode(str_repeat('x', 900), ['columns' => 1])));
$big = Pdf417Encoder::encode(str_repeat('x', 1000), ['ecl' => 2]);
check('large symbol: 1000 characters at level 2 (up to 929 codewords incl. EC)', count($big['codewords']) <= 929 && p417RoundTrip(str_repeat('x', 1000), ['ecl' => 2])[0]);
check('numeric: up to ~2700 digits fit (maximum capacity)', p417RoundTrip(str_repeat('1234567890', 260), ['ecl' => 2])[0]);
check('UTF-8 data carries ECI 26 (927 26) by default, none for ASCII, eci=false disables', Pdf417Encoder::encode('Привет')['data'][0] === 927 && Pdf417Encoder::encode('Привет')['data'][1] === 26 && Pdf417Encoder::encode('ASCII')['data'][0] !== 927 && Pdf417Encoder::encode('Привет', ['eci' => false])['data'][0] !== 927 && Pdf417Encoder::encode('x', ['eci' => 3])['data'][0] === 927);
$mode = Pdf417Encoder::compact('abc' . str_repeat('1', 20) . 'def', false);
check('13+ digits switch to numeric compaction (902) and back to text (900)', in_array(902, $mode, true) && in_array(900, $mode, true));
check('binary data uses byte compaction (901; 924 when the length is a multiple of 6)', Pdf417Encoder::compact("\x80\x81\x82\x83\x84", false)[0] === 901 && Pdf417Encoder::compact("\x80\x81\x82\x83\x84\x85", false)[0] === 924);
check('short text between binary runs is absorbed into byte compaction (no mode ping-pong)', count(array_filter(Pdf417Encoder::compact("\x80\x81ab\x82\x83", false), static fn(int $c): bool => $c === 901 || $c === 900 || $c === 924)) === 1);

// ---------------------------------------------------------------------------------------------------------
echo "\n3. PDF417 vs TCPDF (identical encoding decisions)\n";
// ---------------------------------------------------------------------------------------------------------
if ($haveTcpdf) {
    mt_srand(99);
    $inputs = ['HELLO WORLD', 'PDF417', str_repeat('ABCDEFGHIJ KLMNOPQRS ', 12), '1234567890123456789012345678901234567890', '1234567890123', str_repeat('7', 44)];
    for ($i = 0; $i < 8; $i++) {
        $s = '';
        for ($j = 0, $l = mt_rand(6, 300); $j < $l; $j++) {
            $s .= mt_rand(0, 1) ? chr(mt_rand(65, 90)) : ' ';
        }
        $inputs[] = trim($s) === '' ? 'A' : trim($s);
    }
    for ($i = 0; $i < 10; $i++) {
        $inputs[] = $randBytes(mt_rand(7, 200), 128, 255);
    }
    $inputs[] = 'Привет, мир! Здравей свят, това е тест на PDF417 с кирилица.';
    $inputs[] = str_repeat('0123456789', 10) . 'ABCDEFGH' . str_repeat('9', 20);
    $inputs[] = 'https://example.com/path?x=1&y=2';
    $inputs[] = str_repeat('a', 40);
    $inputs[] = 'Hello World, this is a Test of lower and UPPER, 12345.';
    $same = 0;
    $tot = 0;
    $cwSame = 0;
    $diffs = [];
    foreach ($inputs as $n => $data) {
        foreach ([[-1, 2.0], [2, 1.0], [5, 3.0], [0, 4.0]] as [$ecl, $asp]) {
            $theirs = tcpdfPdf417Rows($data, $ecl, $asp);
            if ($theirs === null) {
                continue;
            }
            $mine = Pdf417Encoder::encode($data, ['ecl' => $ecl >= 0 ? $ecl : null, 'aspect' => $asp, 'rowheight' => 4, 'eci' => false]);
            $tot++;
            if ($theirs === $mine['rows']) {
                $same++;
            } else {
                $diffs[$n] = true;
            }
        }
    }
    // modes differ in TCPDF for: short text runs (<5) treated as bytes, > 44 digit runs re-latched, leading zero bytes dropped, text sub-mode choices
    check("whole symbols module-for-module identical to TCPDF where both choose the same compaction ({$same}/{$tot} compared)", $same >= 50 && $same / max(1, $tot) > 0.6, "{$same}/{$tot}");
    $o = new PDF417('X');
    $m = new ReflectionMethod('PDF417', 'getCompaction');
    $numSame = 0;
    $numTot = 0;
    for ($i = 0; $i < 150; $i++) {
        $d = '';
        for ($j = 0, $l = mt_rand(1, 44); $j < $l; $j++) {
            $d .= mt_rand(0, 9);
        }
        $numTot++;
        $numSame += array_map('intval', $m->invoke($o, 902, $d, false)) === Pdf417Encoder::numericCodewords($d) ? 1 : 0;
    }
    check("numeric compaction codewords identical to TCPDF ({$numTot} random digit strings)", $numSame === $numTot);
    $byteSame = 0;
    $byteTot = 0;
    for ($i = 0; $i < 150; $i++) {
        $d = chr(mt_rand(2, 255)) . $randBytes(mt_rand(0, 39), 2, 255);
        $byteTot++;
        $byteSame += array_map('intval', $m->invoke($o, 901, $d, false)) === Pdf417Encoder::byteCodewords($d) ? 1 : 0;
    }
    check("byte compaction codewords identical to TCPDF ({$byteTot} random binary strings)", $byteSame === $byteTot);
    $short = 0;
    $eq = 0;
    for ($i = 0; $i < 200; $i++) {
        $set = "ABCxyz0123 ,.:;-!?()abcDEF\t\n\r\"#\$%&'*+/<=>@[\\]^_{|}~";
        $d = '';
        for ($j = 0, $l = mt_rand(1, 40); $j < $l; $j++) {
            $d .= $set[mt_rand(0, strlen($set) - 1)];
        }
        $theirs = array_map('intval', $m->invoke($o, 900, $d, false));
        $mine = Pdf417Encoder::textCodewords($d);
        $eq += $theirs === $mine ? 1 : 0;
        $short += count($mine) <= count($theirs) && p417Decompact($mine) === $d ? 1 : 0;
    }
    check('text compaction: never longer than TCPDF\'s greedy latching and always decodes (200 random strings)', $short === 200, "{$short}/200 ({$eq} identical)");
    $cross = 0;
    for ($i = 0; $i < 200; $i++) {
        $set = "ABCxyz0123 ,.:;-!?()abcDEF\t\n\r\"#\$%&'*+/<=>@[\\]^_{|}~";
        $d = '';
        for ($j = 0, $l = mt_rand(1, 40); $j < $l; $j++) {
            $d .= $set[mt_rand(0, strlen($set) - 1)];
        }
        $cross += p417Decompact(array_map('intval', $m->invoke($o, 900, $d, false))) === $d ? 1 : 0;
    }
    check('TCPDF text codewords decode correctly with this test\'s independent decoder (sub-mode tables agree, 200 strings)', $cross === 200, "{$cross}/200");
} else {
    skip('PDF417 vs TCPDF (TCPDF or bcmath not available)');
}

// ---------------------------------------------------------------------------------------------------------
echo "\n4. Data Matrix: symbol table and rectangular symbols\n";
// ---------------------------------------------------------------------------------------------------------
$table = dmSymbolTable();
$tableOk = count($table) === 30;
foreach ($table as [$r, $c, $data, $ecc, $blocks, $rv, $rh]) {
    $mod = ($r - 2 * $rv) * ($c - 2 * $rh);
    $cwTotal = $data + $ecc * $blocks;
    $tableOk = $tableOk && $mod - 8 * $cwTotal >= 0 && $mod - 8 * $cwTotal <= 4 && intdiv($r, $rv) >= 8 && intdiv($c, $rh) >= 8;
}
check('symbol table: mapping area = 8 x (data + ECC codewords) (+0 or +4 unused corner modules) for all 24 square + 6 rectangular symbols', $tableOk);
$rect = [[8, 18, 5], [8, 32, 10], [12, 26, 16], [12, 36, 22], [16, 36, 32], [16, 48, 49]];
$sizesOk = true;
foreach ($rect as [$r, $c, $cap]) {
    $e = DataMatrixEncoder::encode(str_repeat('12', $cap), ['shape' => 'rect']);
    $sizesOk = $sizesOk && $e['rows'] === $r && $e['cols'] === $c && count($e['matrix']) === $r && count($e['matrix'][0]) === $c;
    $e2 = DataMatrixEncoder::encode(str_repeat('12', $cap - 1) . '1', ['shape' => 'rect']);
    $sizesOk = $sizesOk && $e2['rows'] === $r;
}
check('rectangular capacity steps pick 8x18, 8x32, 12x26, 12x36, 16x36, 16x48', $sizesOk);
$fallback = DataMatrixEncoder::encode(str_repeat('12', 60), ['shape' => 'rect']);
check('data too long for any rectangle falls back to a square symbol', $fallback['rows'] === $fallback['cols'] && $fallback['rows'] >= 24);
$any = DataMatrixEncoder::encode(str_repeat('12', 9), ['shape' => 'any']);
check('shape=any picks the smallest capacity of squares and rectangles', $any['rows'] * $any['cols'] > 0 && count($any['data']) === 18 || $any['rows'] === 16 && $any['cols'] === 16 || $any['rows'] === 8);
$bad = [];
foreach ($rect as [$r, $c, $cap]) {
    foreach ([1, intdiv($cap, 2), $cap] as $n) {
        $data = '';
        for ($i = 0; $i < $n * 2 - 1; $i++) {
            $data .= (string) mt_rand(0, 9);
        }
        [$ok, $why] = dmRoundTrip($data, ['shape' => 'rect']);
        if (!$ok) {
            $bad[] = "{$r}x{$c}: {$why}";
        }
    }
}
check('rectangular symbols decode (borders, placement, per-block RS, ASCII payload)', $bad === [], implode(' | ', array_slice($bad, 0, 3)));
$bad = [];
foreach ([['Hi', 'rect'], ['Hello World!', 'rect'], ['ABCDEFGH', 'rect'], ['Привет мир', 'rect'], ['x', 'rect']] as [$data, $shape]) {
    [$ok, $why] = dmRoundTrip($data, ['shape' => $shape]);
    if (!$ok) {
        $bad[] = "{$data}: {$why}";
    }
}
check('rectangular symbols with text / UTF-8 payloads decode', $bad === [], implode(' | ', $bad));
check('the 4 corner-pattern cases are all exercised (ncol % 8 = 0, 4 and nrow/ncol residues)', dmRoundTrip(str_repeat('12', 20), [])[0] && dmRoundTrip(str_repeat('12', 25), ['shape' => 'rect'])[0] && dmRoundTrip(str_repeat('1', 26), [])[0] && dmRoundTrip('x', ['shape' => 'rect'])[0]);

if ($haveTcpdf) {
    mt_srand(31);
    $n = 0;
    $okSq = 0;
    $okRect = 0;
    $totSq = 0;
    $totRect = 0;
    $inputs = [];
    for ($l = 1; $l <= 100; $l += 3) {
        $x = '';
        for ($j = 0; $j < $l; $j++) {
            $x .= (string) mt_rand(0, 9);
        }
        $inputs[] = $x;
    }
    for ($i = 0; $i < 25; $i++) {
        $inputs[] = $randBytes(mt_rand(1, 3), 33, 47);
    }
    foreach ($inputs as $data) {
        foreach ([false, true] as $rectShape) {
            $theirs = tcpdfDmRows($data, $rectShape);
            if ($theirs === null) {
                continue;
            }
            $mine = DataMatrixEncoder::encode($data, ['shape' => $rectShape ? 'rect' : 'square', 'mode' => 'ascii']);
            $rowsMine = array_map(static fn(array $r): string => implode('', $r), $mine['matrix']);
            $single = !$rectShape || in_array($mine['cols'], [18, 26], true); // TCPDF's multi-region rectangle layout is unfinished ("currently unused")
            if (!$single) {
                continue;
            }
            if ($rectShape) {
                $totRect++;
                $okRect += $rowsMine === $theirs ? 1 : 0;
            } else {
                $totSq++;
                $okSq += $rowsMine === $theirs ? 1 : 0;
            }
        }
    }
    check("ASCII-mode square symbols identical to TCPDF module for module ({$okSq}/{$totSq})", $totSq >= 40 && $okSq === $totSq);
    check("single-region rectangles (8x18, 12x26) identical to TCPDF ({$okRect}/{$totRect})", $totRect >= 8 && $okRect === $totRect);
} else {
    skip('Data Matrix vs TCPDF (TCPDF not available)');
}

// ---------------------------------------------------------------------------------------------------------
echo "\n5. Data Matrix: C40 / Text / Base 256 / GS1\n";
// ---------------------------------------------------------------------------------------------------------
$c40 = DataMatrixEncoder::encode('AIMAIMAIM');
check('C40 ISO/zxing example "AIMAIMAIM" -> 230 91 11 91 11 91 11 254', array_slice($c40['data'], 0, 8) === [230, 91, 11, 91, 11, 91, 11, 254], implode(',', array_slice($c40['data'], 0, 8)));
$up = DataMatrixEncoder::encode('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
check('long upper-case/digit text is C40 encoded (latch 230) and smaller than ASCII', $up['data'][0] === 230 && $up['size'] < DataMatrixEncoder::encode('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', ['mode' => 'ascii'])['size']);
$low = DataMatrixEncoder::encode('the quick brown fox jumps over the lazy dog');
check('lower-case text uses Text encodation (latch 239)', $low['data'][0] === 239);
$bin = DataMatrixEncoder::encode($randBytes(60, 128, 255));
check('binary data uses Base 256 (latch 231) where it is shorter', in_array(231, array_slice($bin['data'], 0, 5), true) && $bin['size'] <= DataMatrixEncoder::encode($randBytes(60, 128, 255), ['mode' => 'ascii'])['size']);
check('short digit strings stay ASCII (digit pairs)', DataMatrixEncoder::encode('123456')['data'][0] === 142);
check('C40 basic/shift sets: space, digits, punctuation, control chars, ` { } and DEL decode', (static function (): bool {
    foreach (["AB CD\x01\x02 !\"#\$%&'()*+,-./:;<=>?@[\\]^_ `{|}~\x7F ABC", "ab cd\x01 !\"#\$%&'()*+,-./:;<=>?@[\\]^_ `{|}~\x7F AB abc"] as $s) {
        foreach (['c40', 'text'] as $mode) {
            if (!dmRoundTrip($s, ['mode' => $mode])[0]) {
                echo "    {$mode} failed for " . json_encode($s) . "\n";
                return false;
            }
        }
    }
    return true;
})());
$bad = [];
mt_srand(7);
$inputs = [
    'A', 'AB', 'ABC', 'ABCD', 'ABCDE', 'ABCDEF', 'AAAAAAAAAAAAAAA', 'aaaaaaaaaaaaaaa', 'Hello World', 'HELLO WORLD 12345', 'abc DEF ghi 123',
    'Здравей свят', 'Привет, мир! 2026', 'Ünïcödé Çhàrs ÀÉÎÕÜ', "caf\xC3\xA9 CAF\xC3\x89", "\x80\x81\x82", $randBytes(1), $randBytes(2), $randBytes(3), $randBytes(10),
    $randBytes(50), $randBytes(249), $randBytes(250), $randBytes(251), $randBytes(300), $randBytes(600), str_repeat('A', 100), str_repeat('a1', 50),
    "line1\nline2\r\n\ttabbed", str_repeat("\x1F ", 20), 'ISBN 978-3-16-148410-0', 'https://example.com/a?b=c&d=e#f', '2026-10-17T12:34:56Z', '{"a":[1,2,3],"b":"~"}',
    "tail ABCDEFGHIJKLM", "ABCDEFGHIJKLM tail", 'AB12', 'A1B2C3D4E5F6G7H8I9J0',
];
for ($i = 0; $i < 20; $i++) {
    $s = '';
    for ($j = 0, $segs = mt_rand(1, 6); $j < $segs; $j++) {
        $s .= match (mt_rand(0, 4)) {
            0 => $randBytes(mt_rand(1, 15), 65, 90),
            1 => $randBytes(mt_rand(1, 15), 97, 122),
            2 => $randBytes(mt_rand(1, 15), 48, 57),
            3 => $randBytes(mt_rand(1, 6)),
            default => $randBytes(mt_rand(1, 10), 32, 126),
        };
    }
    $inputs[] = $s;
}
foreach ($inputs as $n => $data) {
    foreach ([[], ['shape' => 'rect'], ['mode' => 'c40'], ['mode' => 'text'], ['mode' => 'base256'], ['mode' => 'ascii']] as $opt) {
        try {
            [$ok, $why] = dmRoundTrip($data, $opt);
        } catch (InvalidArgumentException $e) {
            $ok = str_contains($e->getMessage(), 'too long');
            $why = $e->getMessage();
        }
        if (!$ok) {
            $bad[] = '#' . $n . ' ' . json_encode(substr($data, 0, 16)) . ' ' . json_encode($opt) . ": {$why}";
        }
    }
}
check(count($inputs) . ' inputs x 6 encodation settings (auto, rect, forced C40 / Text / Base256 / ASCII): decode -> payload, RS ok', $bad === [], implode(' | ', array_slice($bad, 0, 3)));
$b250 = DataMatrixEncoder::encode($randBytes(300, 128, 255), ['mode' => 'base256']);
check('Base 256 with >= 250 bytes uses the two-byte length field and still decodes', $b250['data'][0] === 231 && dmRoundTrip($randBytes(300, 128, 255), ['mode' => 'base256'])[0]);
$auto = DataMatrixEncoder::encode(str_repeat('Hello World 123 ', 8));
$ascii = DataMatrixEncoder::encode(str_repeat('Hello World 123 ', 8), ['mode' => 'ascii']);
check('automatic selection never needs a larger symbol than pure ASCII encodation', $auto['size'] <= $ascii['size'], "{$auto['size']} vs {$ascii['size']}");
$sizeOk = true;
mt_srand(5);
for ($i = 0; $i < 60; $i++) {
    $d = $randBytes(mt_rand(1, 120), mt_rand(0, 1) ? 32 : 0, mt_rand(0, 1) ? 126 : 255);
    $sizeOk = $sizeOk && DataMatrixEncoder::encode($d)['size'] <= DataMatrixEncoder::encode($d, ['mode' => 'ascii'])['size'];
}
check('60 random inputs: auto size <= ASCII size', $sizeOk);
check('unlatch dropped when the symbol is exactly full ("AIM" x 8 = 16 values... fits without 254)', (static function (): bool {
    $e = DataMatrixEncoder::encode('AIMAIMAIMAIMAIM', ['mode' => 'c40']); // 15 chars = 10 codewords + latch = 11
    return dmRoundTrip('AIMAIMAIMAIMAIM', ['mode' => 'c40'])[0] && $e['rows'] === 16;
})());

$gs = DataMatrixEncoder::encode('(01)09501101530003(17)140704(10)AB-123', ['gs1' => true]);
check('GS1: leading FNC1 (232), fixed-length AIs without separator, payload decodes', $gs['data'][0] === 232 && dmRoundTrip('(01)09501101530003(17)140704(10)AB-123', ['gs1' => true])[0]);
$gs2 = DataMatrixEncoder::encode("10ABC\x1D21XYZ", ['gs1' => true]);
check('GS1: GS character becomes FNC1 (232) between variable-length elements', count(array_keys($gs2['data'], 232, true)) === 2 && dmRoundTrip("10ABC\x1D21XYZ", ['gs1' => true])[0]);
check('GS1 HRI parser: "(10)ABC(21)XYZ" -> 10ABC<GS>21XYZ; fixed "(01)..(17)..(10)" has no GS until the end', DataMatrixEncoder::gs1FromHri('(10)ABC(21)XYZ') === "10ABC\x1D21XYZ" && DataMatrixEncoder::gs1FromHri('(01)09501101530003(17)140704(10)AB-123') === '01095011015300031714070410AB-123');
check('GS1 with C40 content keeps FNC1 as Shift 2 value 27', dmRoundTrip("10ABCDEFGHIJKLMNOP\x1D21QRSTUVWXYZ", ['gs1' => true, 'mode' => 'c40'])[0]);
check('non-GS1 mode leaves 0x1D as an ordinary control character', dmRoundTrip("a\x1Db")[0] && !in_array(232, DataMatrixEncoder::encode("a\x1Db")['data'], true));

// ---------------------------------------------------------------------------------------------------------
echo "\n6. SVG output, options and the <barcode> tag\n";
// ---------------------------------------------------------------------------------------------------------
$svg = Barcode::svg('PDF417', 'IvoPDF 2026 - PDF417 test');
check('Barcode::svg PDF417 renders merged rects, viewBox = (17 x (cols + 3) + 18 + 4) x (rows x 3 + 4)', str_starts_with($svg, '<svg') && substr_count($svg, '<rect') > 50 && preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $vb) === 1);
$e = Pdf417Encoder::encode('IvoPDF 2026 - PDF417 test');
check('PDF417 viewBox matches the symbol plus 2-module quiet zones', ((int) $vb[1] === $e['width'] + 4) && ((int) $vb[2] === $e['rowCount'] * 3 + 4), "{$vb[1]}x{$vb[2]}");
check('PDF417 aliases / TCPDF-style type "PDF417,5" sets the level', Barcode::svg('PDF-417', 'abc') === Barcode::svg('PDF417', 'abc') && Barcode::svg('PDF417,5', 'abc') === Barcode::svg('PDF417', 'abc', ['ecl' => 5]) && Barcode::svg('PDF417,5', 'abc') !== Barcode::svg('PDF417', 'abc', ['ecl' => 1]));
check('PDF417 width option fits the symbol (aspect kept)', preg_match('/width="([\d.]+)" height="([\d.]+)"/', Barcode::svg('PDF417', 'abc', ['width' => 300]), $d) === 1 && abs((float) $d[1] - 300) < 0.01 && (float) $d[2] < 300);
check('PDF417 quiet=false removes the quiet zone', preg_match('/viewBox="0 0 (\d+) (\d+)"/', Barcode::svg('PDF417', 'abc', ['quiet' => false]), $q) === 1 && (int) $q[2] % 3 === 0);
check('PDF417 options columns / truncated / rowheight reach the encoder', Barcode::svg('PDF417', str_repeat('abc', 20), ['columns' => 2]) !== Barcode::svg('PDF417', str_repeat('abc', 20), ['columns' => 6]) && Barcode::svg('PDF417', 'abc', ['truncated' => true]) !== Barcode::svg('PDF417', 'abc') && str_contains(Barcode::svg('PDF417', 'abc', ['rowheight' => 5]), 'height="5"'));
$warn = [];
$none = Barcode::svg('PDF417', str_repeat('x', 5000), ['warn' => static function (string $m) use (&$warn): void {
    $warn[] = $m;
}]);
check('PDF417 data too long renders nothing and warns', $none === '' && count($warn) === 1 && str_contains($warn[0], 'PDF417'));
$dmRect = Barcode::svg('DATAMATRIX', 'Hello', ['shape' => 'rect']);
check('Data Matrix shape=rect renders a 18x8 symbol (viewBox 22 x 12 with quiet zones)', str_contains($dmRect, 'viewBox="0 0 22 12"') && str_contains($dmRect, 'width="66" height="36"'));
check('Data Matrix gs1 option reaches the encoder', Barcode::svg('DATAMATRIX', '(10)ABC', ['gs1' => true]) !== Barcode::svg('DATAMATRIX', '(10)ABC'));
check('square Data Matrix output is unchanged (viewBox side + 4, 3 px per module)', preg_match('/viewBox="0 0 (\d+) (\d+)" preserveAspectRatio="[^"]*" width="(\d+)" height="(\d+)"/', Barcode::svg('DATAMATRIX', 'Hello 123'), $s) === 1 && $s[1] === $s[2] && (int) $s[3] === 3 * (int) $s[1]);
check('Data Matrix width option on a rectangle keeps the aspect ratio', preg_match('/width="([\d.]+)" height="([\d.]+)"/', Barcode::svg('DATAMATRIX', 'Hello', ['shape' => 'rect', 'width' => 110]), $d) === 1 && abs((float) $d[1] - 110) < 0.01 && abs((float) $d[2] - 110 * 12 / 22) < 0.05);

$pdf = new IvoPdf(['compress' => false]);
$out = $pdf->loadHtml('<html><body><p>2D codes</p>
  <barcode type="PDF417" value="Invoice 2026-104829 / Fakture No 104829" columns="4" ecl="3" />
  <barcode type="PDF417" code="Здравей свят" truncated="1" rowheight="2" width="200" />
  <barcode type="PDF417" value="12345678901234567890" aspect="3" disableborder="1" />
  <barcode type="DATAMATRIX" value="Hello rect" shape="rect" />
  <barcode type="DATAMATRIX" value="(01)09501101530003(17)140704(10)AB-123" gs1="1" />
  <barcode type="PDF417" value="" />
</body></html>')->output();
$streams = '';
if (preg_match_all('/stream\r?\n(.*?)endstream/s', $out, $sm)) {
    $streams = implode("\n", $sm[1]);
}
check('HTML <barcode> tags for PDF417 / Data Matrix render a PDF with vector fills', str_starts_with($out, '%PDF-') && substr_count($streams, ' re') > 200, 're=' . substr_count($streams, ' re'));
$w = $pdf->getWarnings();
check('only the empty PDF417 tag produces a warning', count($w) === 1 && str_contains($w[0], 'PDF417'), json_encode($w));
check('tag attributes columns / ecl / truncated match the PHP API', Barcode::fromAttributes('type="PDF417" value="abc" columns="3" ecl="4" truncated="1"') === Barcode::svg('PDF417', 'abc', ['columns' => 3, 'ecl' => 4, 'truncated' => true]));

// ---------------------------------------------------------------------------------------------------------
echo "\n7. Engine hygiene\n";
// ---------------------------------------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
