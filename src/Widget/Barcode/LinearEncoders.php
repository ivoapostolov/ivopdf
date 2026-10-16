<?php

declare(strict_types=1);

namespace IvoPdf\Widget\Barcode;

use InvalidArgumentException;

/**
 * 1D symbologies: Code 128 (A/B/C + automatic switching), EAN-13/8, UPC-A/E, ISBN/ISSN, Code 39 (+ checksum, full ASCII),
 * Code 93, Interleaved 2 of 5 and Codabar. Every encoder returns a module string ('1' bar, '0' space) plus layout hints.
 */
final class LinearEncoders
{
    /**
     * Code 128 patterns, values 0..106 (6 bar/space widths; the stop pattern has 7).
     * @var list<string>
     */
    public const CODE128_PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    private const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
    private const UPCE_PARITY = ['EEEOOO', 'EEOEOO', 'EEOOEO', 'EEOOOE', 'EOEEOO', 'EOOEEO', 'EOOOEE', 'EOEOEO', 'EOEOOE', 'EOOEOE'];

    private const C39_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';
    /** Code 39 element patterns (n = narrow, w = wide), bar/space alternating. */
    private const C39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
        '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn',
    ];

    /** Code 93 symbol widths, index = value (0-46 chars, 47 = start/stop). */
    private const C93 = [
        '131112', '111213', '111312', '111411', '121113', '121212', '121311', '111114', '131211', '141111',
        '211113', '211212', '211311', '221112', '221211', '231111', '112113', '112212', '112311', '122112',
        '132111', '111123', '111222', '111321', '121122', '131121', '212112', '212211', '211122', '211221',
        '221121', '222111', '112122', '112221', '122121', '123111', '121131', '311112', '311211', '321111',
        '112131', '113121', '211131', '121221', '312111', '311121', '122211', '111141',
    ];

    private const I25 = ['nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw', 'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn'];

    private const CODABAR = [
        '0' => 'nnnnnww', '1' => 'nnnnwwn', '2' => 'nnnwnnw', '3' => 'wwnnnnn', '4' => 'nnwnnwn', '5' => 'wnnnnwn',
        '6' => 'nwnnnnw', '7' => 'nwnnwnn', '8' => 'nwwnnnn', '9' => 'wnnwnnn', '-' => 'nnnwwnn', '$' => 'nnwwnnn',
        ':' => 'wnnnwnw', '/' => 'wnwnnnw', '.' => 'wnwnwnn', '+' => 'nnwnwnw', 'A' => 'nnwwnwn', 'B' => 'nwnwnnw',
        'C' => 'nnnwnww', 'D' => 'nnnwwwn',
    ];

    /**
     * Encodes a linear barcode.
     *
     * @param string $type  Normalised upper-case type (C128, C128A, C128B, C128C, EAN13, EAN8, UPCA, UPCE, ISBN, ISSN,
     *                      C39, C39+, C39E, C39E+, C93, I25, I25+, CODABAR).
     * @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>}
     *         kind = 'plain' | 'ean13' | 'ean8' | 'upca' | 'upce'; tall = module ranges [from, to) drawn as guard bars.
     * @throws InvalidArgumentException on invalid data.
     */
    public static function encode(string $type, string $value): array
    {
        switch ($type) {
            case 'C128':
            case 'C128A':
            case 'C128B':
            case 'C128C':
                return self::plain(self::code128(self::code128Codes($value, strlen($type) > 4 ? $type[4] : 'AUTO')), self::printable($value));
            case 'EAN13':
                return self::ean13(self::ean13Digits($value));
            case 'ISBN':
                return self::ean13(self::isbnDigits($value));
            case 'ISSN':
                return self::ean13(self::issnDigits($value));
            case 'EAN8':
                return self::ean8($value);
            case 'UPCA':
                return self::upca($value);
            case 'UPCE':
                return self::upce($value);
            case 'C39':
            case 'C39+':
            case 'C39E':
            case 'C39E+':
                return self::code39($value, str_contains($type, 'E'), str_ends_with($type, '+'));
            case 'C93':
                return self::code93($value);
            case 'I25':
            case 'I25+':
                return self::i25($value, str_ends_with($type, '+'));
            case 'CODABAR':
                return self::codabar($value);
        }
        throw new InvalidArgumentException("Unknown barcode type '{$type}'");
    }

    /** EAN/UPC mod-10 check digit for the given payload (rightmost payload digit has weight 3). */
    public static function eanCheckDigit(string $digits): int
    {
        $sum = 0;
        for ($i = strlen($digits) - 1, $w = 3; $i >= 0; $i--, $w = 4 - $w) {
            $sum += (int) $digits[$i] * $w;
        }
        return (10 - $sum % 10) % 10;
    }

    /** Code 128 checksum for a list of code values (start code first, no checksum/stop yet). */
    public static function code128Checksum(array $codes): int
    {
        $sum = $codes[0];
        for ($i = 1, $n = count($codes); $i < $n; $i++) {
            $sum += $codes[$i] * $i;
        }
        return $sum % 103;
    }

    /**
     * Code 128 symbol values (start code, data/switch codes) for $value in mode AUTO, A, B or C.
     *
     * @return list<int>
     */
    public static function code128Codes(string $value, string $mode = 'AUTO'): array
    {
        $n = strlen($value);
        if ($n === 0) {
            throw new InvalidArgumentException('Empty Code 128 value');
        }
        for ($i = 0; $i < $n; $i++) {
            if (ord($value[$i]) > 127) {
                throw new InvalidArgumentException('Code 128 supports ASCII characters only');
            }
        }
        if ($mode === 'A' || $mode === 'B') {
            $codes = [$mode === 'A' ? 103 : 104];
            for ($i = 0; $i < $n; $i++) {
                $o = ord($value[$i]);
                if ($mode === 'A') {
                    if ($o > 95) {
                        throw new InvalidArgumentException('Character not encodable in Code 128 A');
                    }
                    $codes[] = $o < 32 ? $o + 64 : $o - 32;
                } else {
                    if ($o < 32) {
                        throw new InvalidArgumentException('Character not encodable in Code 128 B');
                    }
                    $codes[] = $o - 32;
                }
            }
            return $codes;
        }
        if ($mode === 'C') {
            if (!ctype_digit($value)) {
                throw new InvalidArgumentException('Code 128 C requires digits only');
            }
            if ($n % 2 === 1) {
                $value = '0' . $value;
                $n++;
            }
            $codes = [105];
            for ($i = 0; $i < $n; $i += 2) {
                $codes[] = (int) substr($value, $i, 2);
            }
            return $codes;
        }

        // AUTO: shortest symbol sequence by dynamic programming over (position, active set); sets: 0=B 1=A 2=C
        $inf = PHP_INT_MAX >> 2;
        $cost = array_fill(0, $n + 1, [$inf, $inf, $inf]);
        $prev = array_fill(0, $n + 1, [null, null, null]);
        $cost[0] = [1, 1, 1];
        for ($i = 0; $i <= $n; $i++) {
            $snap = $cost[$i];
            for ($s = 0; $s < 3; $s++) {
                for ($t = 0; $t < 3; $t++) {
                    if ($s !== $t && $snap[$s] + 1 < $cost[$i][$t]) {
                        $cost[$i][$t] = $snap[$s] + 1;
                        $prev[$i][$t] = [$i, $s];
                    }
                }
            }
            if ($i === $n) {
                break;
            }
            $o = ord($value[$i]);
            $isDigitPair = $i + 1 < $n && ctype_digit($value[$i]) && ctype_digit($value[$i + 1]);
            foreach ([0 => $o >= 32, 1 => $o <= 95] as $s => $okChar) {
                if ($okChar && $cost[$i][$s] + 1 < $cost[$i + 1][$s]) {
                    $cost[$i + 1][$s] = $cost[$i][$s] + 1;
                    $prev[$i + 1][$s] = [$i, $s];
                }
            }
            if ($isDigitPair && $cost[$i][2] + 1 < $cost[$i + 2][2]) {
                $cost[$i + 2][2] = $cost[$i][2] + 1;
                $prev[$i + 2][2] = [$i, 2];
            }
        }
        $s = 0;
        foreach ([0, 1, 2] as $c) {
            if ($cost[$n][$c] < $cost[$n][$s]) {
                $s = $c;
            }
        }
        // Backtrack to a list of [type, i, set]
        $steps = [];
        $i = $n;
        while (true) {
            $p = $prev[$i][$s];
            if ($p === null) {
                $startSet = $s;
                break;
            }
            [$pi, $ps] = $p;
            $steps[] = $pi === $i ? ['sw', $i, $s] : ['enc', $pi, $s, $i - $pi];
            $i = $pi;
            $s = $ps;
        }
        $steps = array_reverse($steps);
        $codes = [[104, 103, 105][$startSet]];
        foreach ($steps as $st) {
            if ($st[0] === 'sw') {
                $codes[] = [100, 101, 99][$st[2]];
                continue;
            }
            [, $pos, $set, $len] = $st;
            if ($set === 2) {
                $codes[] = (int) substr($value, $pos, 2);
            } else {
                $o = ord($value[$pos]);
                $codes[] = ($set === 1 && $o < 32) ? $o + 64 : $o - 32;
            }
        }
        return $codes;
    }

    /** @param list<int> $codes */
    private static function code128(array $codes): string
    {
        $codes[] = self::code128Checksum($codes);
        $codes[] = 106;
        $w = '';
        foreach ($codes as $c) {
            $w .= self::CODE128_PATTERNS[$c];
        }
        return self::widthsToBits($w);
    }

    /** Converts alternating bar/space widths ("2112...") to a module string. */
    private static function widthsToBits(string $widths): string
    {
        $bits = '';
        for ($i = 0, $n = strlen($widths); $i < $n; $i++) {
            $bits .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $widths[$i]);
        }
        return $bits;
    }

    /** Converts n/w element string to widths with the given wide ratio. */
    private static function nwToWidths(string $nw, int $wide = 3): string
    {
        return strtr($nw, ['n' => '1', 'w' => (string) $wide]);
    }

    private static function printable(string $s): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function plain(string $bits, string $text, int $quiet = 10): array
    {
        return ['bits' => $bits, 'text' => $text, 'kind' => 'plain', 'digits' => '', 'quiet' => [$quiet, $quiet], 'tall' => []];
    }

    private static function digitsOnly(string $v, string $what): string
    {
        $v = preg_replace('/[\s-]+/', '', $v) ?? '';
        if ($v === '' || !ctype_digit($v)) {
            throw new InvalidArgumentException("{$what} requires digits only");
        }
        return $v;
    }

    private static function ean13Digits(string $v): string
    {
        $v = self::digitsOnly($v, 'EAN-13');
        if (strlen($v) === 12) {
            return $v . self::eanCheckDigit($v);
        }
        if (strlen($v) !== 13) {
            throw new InvalidArgumentException('EAN-13 needs 12 or 13 digits');
        }
        if ((int) $v[12] !== self::eanCheckDigit(substr($v, 0, 12))) {
            throw new InvalidArgumentException('EAN-13 check digit mismatch');
        }
        return $v;
    }

    private static function isbnDigits(string $v): string
    {
        $v = strtoupper(preg_replace('/[\s-]+/', '', $v) ?? '');
        if (preg_match('/^(?:ISBN)?(\d{9}[\dX]?)$/', $v, $m) === 1 && strlen($m[1]) <= 10) {
            $core = substr($m[1], 0, 9);
            if (strlen($m[1]) === 10) {
                $sum = 0;
                for ($i = 0; $i < 10; $i++) {
                    $sum += (10 - $i) * ($m[1][$i] === 'X' ? 10 : (int) $m[1][$i]);
                }
                if ($sum % 11 !== 0) {
                    throw new InvalidArgumentException('ISBN-10 check digit mismatch');
                }
            }
            $d = '978' . $core;
            return $d . self::eanCheckDigit($d);
        }
        $v = preg_replace('/^ISBN/', '', $v) ?? '';
        if (preg_match('/^97[89]\d{9,10}$/', $v) !== 1) {
            throw new InvalidArgumentException('Invalid ISBN');
        }
        return self::ean13Digits($v);
    }

    private static function issnDigits(string $v): string
    {
        $v = strtoupper(preg_replace('/[\s-]+/', '', $v) ?? '');
        $v = preg_replace('/^ISSN/', '', $v) ?? '';
        if (preg_match('/^\d{7}[\dX]?$/', $v) !== 1) {
            throw new InvalidArgumentException('Invalid ISSN');
        }
        $core = substr($v, 0, 7);
        if (strlen($v) === 8) {
            $sum = 0;
            for ($i = 0; $i < 8; $i++) {
                $sum += (8 - $i) * ($v[$i] === 'X' ? 10 : (int) $v[$i]);
            }
            if ($sum % 11 !== 0) {
                throw new InvalidArgumentException('ISSN check digit mismatch');
            }
        }
        $d = '977' . $core . '00';
        return $d . self::eanCheckDigit($d);
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function ean13(string $d): array
    {
        $parity = self::EAN_PARITY[(int) $d[0]];
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bits .= self::eanCode((int) $d[$i], $parity[$i - 1]);
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::eanCode((int) $d[$i], 'R');
        }
        $bits .= '101';
        return ['bits' => $bits, 'text' => $d, 'kind' => 'ean13', 'digits' => $d, 'quiet' => [11, 7],
            'tall' => [[0, 3], [45, 50], [92, 95]]];
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function ean8(string $v): array
    {
        $v = self::digitsOnly($v, 'EAN-8');
        if (strlen($v) === 7) {
            $v .= self::eanCheckDigit($v);
        }
        if (strlen($v) !== 8) {
            throw new InvalidArgumentException('EAN-8 needs 7 or 8 digits');
        }
        if ((int) $v[7] !== self::eanCheckDigit(substr($v, 0, 7))) {
            throw new InvalidArgumentException('EAN-8 check digit mismatch');
        }
        $bits = '101';
        for ($i = 0; $i < 4; $i++) {
            $bits .= self::eanCode((int) $v[$i], 'L');
        }
        $bits .= '01010';
        for ($i = 4; $i < 8; $i++) {
            $bits .= self::eanCode((int) $v[$i], 'R');
        }
        $bits .= '101';
        return ['bits' => $bits, 'text' => $v, 'kind' => 'ean8', 'digits' => $v, 'quiet' => [7, 7],
            'tall' => [[0, 3], [31, 36], [64, 67]]];
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function upca(string $v): array
    {
        $v = self::digitsOnly($v, 'UPC-A');
        if (strlen($v) === 11) {
            $v .= self::eanCheckDigit($v);
        }
        if (strlen($v) !== 12) {
            throw new InvalidArgumentException('UPC-A needs 11 or 12 digits');
        }
        if ((int) $v[11] !== self::eanCheckDigit(substr($v, 0, 11))) {
            throw new InvalidArgumentException('UPC-A check digit mismatch');
        }
        $bits = '101';
        for ($i = 0; $i < 6; $i++) {
            $bits .= self::eanCode((int) $v[$i], 'L');
        }
        $bits .= '01010';
        for ($i = 6; $i < 12; $i++) {
            $bits .= self::eanCode((int) $v[$i], 'R');
        }
        $bits .= '101';
        return ['bits' => $bits, 'text' => $v, 'kind' => 'upca', 'digits' => $v, 'quiet' => [9, 9],
            'tall' => [[0, 10], [45, 50], [85, 95]]];
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function upce(string $v): array
    {
        $v = self::digitsOnly($v, 'UPC-E');
        $len = strlen($v);
        if ($len === 6) {
            $v = '0' . $v;
            $len = 7;
        }
        if ($len === 7 || $len === 8) {
            $ns = (int) $v[0];
            if ($ns > 1) {
                throw new InvalidArgumentException('UPC-E number system must be 0 or 1');
            }
            $check = self::eanCheckDigit(self::upceExpand(substr($v, 0, 7)));
            if ($len === 8 && (int) $v[7] !== $check) {
                throw new InvalidArgumentException('UPC-E check digit mismatch');
            }
            $v = substr($v, 0, 7) . $check;
        } else {
            throw new InvalidArgumentException('UPC-E needs 6, 7 or 8 digits');
        }
        $parity = self::UPCE_PARITY[(int) $v[7]];
        if ($v[0] === '1') {
            $parity = strtr($parity, ['E' => 'O', 'O' => 'E']);
        }
        $bits = '101';
        for ($i = 0; $i < 6; $i++) {
            $bits .= self::eanCode((int) $v[$i + 1], $parity[$i] === 'O' ? 'L' : 'G');
        }
        $bits .= '010101';
        return ['bits' => $bits, 'text' => $v, 'kind' => 'upce', 'digits' => $v, 'quiet' => [9, 7],
            'tall' => [[0, 3], [45, 51]]];
    }

    /** Expands a UPC-E number (number system + 6 digits) to the 11-digit UPC-A payload. */
    private static function upceExpand(string $v): string
    {
        $ns = $v[0];
        $d = substr($v, 1, 6);
        $last = (int) $d[5];
        if ($last <= 2) {
            return $ns . substr($d, 0, 2) . $last . '0000' . substr($d, 2, 3);
        }
        if ($last === 3) {
            return $ns . substr($d, 0, 3) . '00000' . substr($d, 3, 2);
        }
        if ($last === 4) {
            return $ns . substr($d, 0, 4) . '00000' . $d[4];
        }
        return $ns . substr($d, 0, 5) . '0000' . $last;
    }

    private static function eanCode(int $digit, string $set): string
    {
        $l = self::EAN_L[$digit];
        return match ($set) {
            'L' => $l,
            'R' => strtr($l, ['0' => '1', '1' => '0']),
            default => strrev(strtr($l, ['0' => '1', '1' => '0'])), // G
        };
    }

    /** Full-ASCII (extended) mapping of one character for Code 39 / Code 93: one char or a shift + letter pair. */
    private static function extChars(string $c): string
    {
        $o = ord($c);
        if ($o === 0) {
            return '%U';
        }
        if ($o <= 26) {
            return '$' . chr(64 + $o);
        }
        if ($o <= 31) {
            return '%' . chr(65 + $o - 27);
        }
        if ($o >= 33 && $o <= 44) {
            return '/' . chr(65 + $o - 33);
        }
        if ($o === 47) {
            return '/O';
        }
        if ($o === 58) {
            return '/Z';
        }
        if ($o >= 59 && $o <= 63) {
            return '%' . chr(70 + $o - 59);
        }
        if ($o === 64) {
            return '%V';
        }
        if ($o >= 91 && $o <= 95) {
            return '%' . chr(75 + $o - 91);
        }
        if ($o === 96) {
            return '%W';
        }
        if ($o >= 97 && $o <= 122) {
            return '+' . chr(65 + $o - 97);
        }
        if ($o >= 123 && $o <= 127) {
            return '%' . chr(80 + $o - 123);
        }
        if ($o > 127) {
            throw new InvalidArgumentException('Full ASCII barcodes support ASCII characters only');
        }
        return $c; // space, digits, A-Z, '-', '.'
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function code39(string $v, bool $extended, bool $checksum): array
    {
        if ($v === '') {
            throw new InvalidArgumentException('Empty Code 39 value');
        }
        if ($extended) {
            $enc = '';
            for ($i = 0, $n = strlen($v); $i < $n; $i++) {
                $enc .= self::extChars($v[$i]);
            }
        } else {
            $enc = strtoupper($v);
            if (strlen($enc) !== strspn($enc, self::C39_CHARS)) {
                throw new InvalidArgumentException('Invalid character for Code 39 (use the C39E type for full ASCII)');
            }
        }
        $text = $v;
        if ($checksum) {
            $sum = 0;
            for ($i = 0, $n = strlen($enc); $i < $n; $i++) {
                $sum += (int) strpos(self::C39_CHARS, $enc[$i]);
            }
            $cc = self::C39_CHARS[$sum % 43];
            $enc .= $cc;
            $text .= $cc;
        }
        $bits = '';
        foreach (str_split('*' . $enc . '*') as $k => $ch) {
            if ($k > 0) {
                $bits .= '0';
            }
            $bits .= self::widthsToBits(self::nwToWidths(self::C39[$ch]));
        }
        return self::plain($bits, self::printable($text));
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function code93(string $v): array
    {
        if ($v === '') {
            throw new InvalidArgumentException('Empty Code 93 value');
        }
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';
        $shift = ['$' => 43, '%' => 44, '/' => 45, '+' => 46];
        $vals = [];
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $m = self::extChars($v[$i]);
            if (strlen($m) === 2) {
                $vals[] = $shift[$m[0]];
                $vals[] = (int) strpos($alphabet, $m[1]);
            } else {
                $vals[] = (int) strpos($alphabet, $m);
            }
        }
        foreach ([20, 15] as $maxW) {
            $sum = 0;
            for ($i = count($vals) - 1, $w = 1; $i >= 0; $i--, $w = $w % $maxW + 1) {
                $sum += $vals[$i] * $w;
            }
            $vals[] = $sum % 47;
        }
        $w = self::C93[47];
        foreach ($vals as $x) {
            $w .= self::C93[$x];
        }
        $w .= self::C93[47] . '1';
        return self::plain(self::widthsToBits($w), self::printable($v));
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function i25(string $v, bool $checksum): array
    {
        $v = self::digitsOnly($v, 'Interleaved 2 of 5');
        if ($checksum) {
            $v .= self::eanCheckDigit($v);
        }
        if (strlen($v) % 2 === 1) {
            $v = '0' . $v;
        }
        $w = '1111';
        for ($i = 0, $n = strlen($v); $i < $n; $i += 2) {
            $a = self::nwToWidths(self::I25[(int) $v[$i]]);
            $b = self::nwToWidths(self::I25[(int) $v[$i + 1]]);
            for ($k = 0; $k < 5; $k++) {
                $w .= $a[$k] . $b[$k];
            }
        }
        $w .= '311';
        return self::plain(self::widthsToBits($w), $v);
    }

    /** @return array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} */
    private static function codabar(string $v): array
    {
        $v = strtoupper($v);
        if ($v === '') {
            throw new InvalidArgumentException('Empty Codabar value');
        }
        if (preg_match('/^[A-D].*[A-D]$/', $v) !== 1 || strlen($v) < 2) {
            $v = 'A' . $v . 'A';
        }
        $bits = '';
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            if (!isset(self::CODABAR[$v[$i]])) {
                throw new InvalidArgumentException('Invalid character for Codabar');
            }
            if ($i > 0) {
                $bits .= '0';
            }
            $bits .= self::widthsToBits(self::nwToWidths(self::CODABAR[$v[$i]]));
        }
        return self::plain($bits, $v);
    }
}
