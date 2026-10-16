<?php

declare(strict_types=1);

namespace IvoPdf\Widget;

use InvalidArgumentException;
use IvoPdf\Widget\Barcode\DataMatrixEncoder;
use IvoPdf\Widget\Barcode\LinearEncoders;
use IvoPdf\Widget\Barcode\Pdf417Encoder;
use IvoPdf\Widget\Barcode\QrEncoder;

/**
 * Vector barcode generator (inline <svg>): Code 128 (auto A/B/C), EAN-13/8, UPC-A/E, ISBN/ISSN, Code 39 / 93,
 * Interleaved 2 of 5, Codabar, QR Code, Data Matrix (square + rectangular, ASCII/C40/Text/Base 256, GS1) and PDF417. Used by the <barcode> HTML tag and available as a PHP API.
 *
 * Types (mPDF-compatible names): C128, C128A, C128B, C128C, EAN13, EAN8, UPCA, UPCE, ISBN, ISSN, C39, C39+, C39E,
 * C39E+, C93, I25, I25+, CODABAR, QR (QRCODE), DATAMATRIX, PDF417.
 *
 * Options: width, height (px), color, text (bool), size (scale factor), error (QR: L|M|Q|H; PDF417: level 0..8), quiet (bool or
 * modules), background (colour), checksum (bool: C39 / I25), warn (callable(string): void).
 * PDF417: columns (1..30), rows (3..90), ecl (0..8), aspect (width:height, default 2), truncated (bool), rowheight (module
 * rows per symbol row, default 3), eci (false|int; default ECI 26 for UTF-8). Data Matrix: shape (square|rect|any),
 * gs1 (bool), mode (auto|ascii|c40|text|base256).
 */
final class Barcode
{
    /** Canonical type names and common aliases. */
    private const ALIASES = [
        'CODE128' => 'C128', 'CODE128A' => 'C128A', 'CODE128B' => 'C128B', 'CODE128C' => 'C128C',
        'CODE39' => 'C39', 'CODE39+' => 'C39+', 'CODE39E' => 'C39E', 'CODE39E+' => 'C39E+', 'CODE93' => 'C93',
        'EAN' => 'EAN13', 'EAN-13' => 'EAN13', 'EAN-8' => 'EAN8', 'UPC' => 'UPCA', 'UPC-A' => 'UPCA', 'UPC-E' => 'UPCE',
        'ITF' => 'I25', 'INTERLEAVED25' => 'I25', 'ITF+' => 'I25+', 'NW7' => 'CODABAR',
        'QRCODE' => 'QR', 'PDF-417' => 'PDF417', 'PDF' => 'PDF417', 'DMTX' => 'DATAMATRIX', 'DM' => 'DATAMATRIX', 'ECC200' => 'DATAMATRIX',
    ];

    /**
     * Renders a barcode as an inline <svg> string. Returns '' (and calls the 'warn' callback) on invalid input.
     *
     * @param array<string, mixed> $options
     */
    public static function svg(string $type, string $value, array $options = []): string
    {
        $warn = isset($options['warn']) && is_callable($options['warn']) ? $options['warn'] : null;
        try {
            return self::build($type, $value, $options);
        } catch (InvalidArgumentException $e) {
            if ($warn !== null) {
                $warn('Barcode (' . $type . '): ' . $e->getMessage());
            }
            return '';
        }
    }

    /** Canonical (upper-case) type name; 'QR' for QRCODE etc. */
    public static function normalizeType(string $type): string
    {
        $t = strtoupper(trim($type));
        $t = str_replace([' ', '_'], '', $t);
        if ($t === '') {
            return 'C128';
        }
        return self::ALIASES[$t] ?? $t;
    }

    /**
     * Builds the SVG for a <barcode ...> tag attribute string (mPDF style: code|value, type, size, error, height,
     * disableborder; plus width, height in px, color, text="false").
     */
    public static function fromAttributes(string $attrs, ?callable $warn = null): string
    {
        $a = [];
        if (preg_match_all('/([A-Za-z_:][-\w:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/s', $attrs, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                $a[strtolower($m[1])] = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $value = $a['value'] ?? $a['code'] ?? '12345678';
        $type = $a['type'] ?? 'C128';
        $opt = ['warn' => $warn];
        foreach (['width', 'height'] as $k) {
            if (isset($a[$k]) && ($px = self::toPx($a[$k])) !== null) {
                $opt[$k] = $px;
            }
        }
        if (isset($a['color']) && $a['color'] !== '') {
            $opt['color'] = $a['color'];
        }
        if (isset($a['text']) && preg_match('/^(false|0|no|off)$/i', trim($a['text'])) === 1) {
            $opt['text'] = false;
        }
        if (isset($a['size']) && is_numeric($a['size']) && (float) $a['size'] > 0) {
            $opt['size'] = (float) $a['size'];
        }
        if (isset($a['error'])) {
            $opt['error'] = $a['error'];
        }
        foreach (['columns', 'rows', 'ecl', 'aspect', 'rowheight', 'eci', 'shape', 'mode'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '') {
                $opt[$k] = $a[$k];
            }
        }
        foreach (['truncated', 'gs1'] as $k) {
            if (isset($a[$k]) && preg_match('/^(1|true|yes|on)?$/i', trim($a[$k])) === 1) {
                $opt[$k] = true;
            }
        }
        if (isset($a['disableborder']) && preg_match('/^(1|true|yes|on)?$/i', trim($a['disableborder'])) === 1) {
            $opt['quiet'] = false;
        } elseif (isset($a['quiet']) && is_numeric($a['quiet'])) {
            $opt['quiet'] = (float) $a['quiet'] > 0 ? (int) $a['quiet'] : false;
        }
        if (isset($a['background']) || isset($a['bgcolor'])) {
            $opt['background'] = $a['background'] ?? $a['bgcolor'];
        }
        if (isset($a['checksum']) && preg_match('/^(1|true|yes|on)$/i', trim($a['checksum'])) === 1) {
            $opt['checksum'] = true;
        }
        return self::svg($type, $value, $opt);
    }

    private static function toPx(string $v): ?float
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px|pt|mm|cm|in)?\s*$/i', $v, $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];
        return match (strtolower($m[2] ?? 'px')) {
            'pt' => $n * 96 / 72,
            'mm' => $n * 96 / 25.4,
            'cm' => $n * 96 / 2.54,
            'in' => $n * 96,
            default => $n,
        };
    }

    /** @param array<string, mixed> $o */
    private static function build(string $type, string $value, array $o): string
    {
        $error = null;
        if (str_contains($type, ',')) { // TCPDF style "QRCODE,H"
            [$type, $error] = array_map('trim', explode(',', $type, 2));
        }
        $type = self::normalizeType($type);
        if (!empty($o['checksum']) && in_array($type, ['C39', 'C39E', 'I25'], true)) {
            $type .= '+';
        }
        $color = htmlspecialchars((string) ($o['color'] ?? '#0f172a'), ENT_QUOTES, 'UTF-8');
        $size = isset($o['size']) && (float) $o['size'] > 0 ? (float) $o['size'] : 1.0;
        $quietOpt = $o['quiet'] ?? true;
        $bg = isset($o['background']) && $o['background'] !== '' ? htmlspecialchars((string) $o['background'], ENT_QUOTES, 'UTF-8') : null;
        $width = isset($o['width']) ? (float) $o['width'] : null;
        $height = isset($o['height']) ? (float) $o['height'] : null;

        if ($type === 'QR') {
            $ecc = strtoupper((string) ($error ?? $o['error'] ?? 'M'));
            $qr = QrEncoder::encode($value, $ecc);
            $q = $quietOpt === false ? 0 : (is_int($quietOpt) ? $quietOpt : 4);
            $side = self::matrixSide($qr['size'] + 2 * $q, $size, $width, $height);
            return self::matrixSvg($qr['matrix'], $q, $color, $bg, $side, $side);
        }
        if ($type === 'DATAMATRIX') {
            $dm = DataMatrixEncoder::encode($value, [
                'shape' => $o['shape'] ?? 'square',
                'gs1' => self::truthy($o['gs1'] ?? false),
                'mode' => $o['mode'] ?? 'auto',
            ]);
            $q = $quietOpt === false ? 0 : (is_int($quietOpt) ? $quietOpt : 2);
            [$w, $h] = self::fitDims($dm['cols'] + 2 * $q, $dm['rows'] + 2 * $q, 3.0 * $size, $width, $height, $dm['rows'] === $dm['cols']);
            return self::matrixSvg($dm['matrix'], $q, $color, $bg, $w, $h);
        }
        if ($type === 'PDF417') {
            $ecl = $o['ecl'] ?? $error ?? $o['error'] ?? null;
            $p = Pdf417Encoder::encode($value, [
                'columns' => $o['columns'] ?? null,
                'rows' => $o['rows'] ?? null,
                'ecl' => is_numeric($ecl) ? (int) $ecl : null,
                'aspect' => $o['aspect'] ?? null,
                'truncated' => self::truthy($o['truncated'] ?? false),
                'rowheight' => $o['rowheight'] ?? 3,
                'eci' => $o['eci'] ?? null,
            ]);
            $q = $quietOpt === false ? 0 : (is_int($quietOpt) ? $quietOpt : 2);
            $matrix = array_map(static fn(string $r): array => array_map('intval', str_split($r)), $p['rows']);
            [$w, $h] = self::fitDims(
                $p['width'] + 2 * $q,
                $p['rowCount'] * $p['rowHeight'] + 2 * $q,
                2.0 * $size,
                $width,
                $height,
                false
            );
            return self::matrixSvg($matrix, $q, $color, $bg, $w, $h, $p['rowHeight']);
        }

        $enc = LinearEncoders::encode($type, $value);
        $w = max(40.0, $width ?? 180.0 * $size);
        $h = max(20.0, $height ?? 46.0 * $size);
        $showText = ($o['text'] ?? true) !== false;
        $quiet = $enc['quiet'];
        if ($quietOpt === false) {
            $quiet = [0, 0];
        } elseif (is_int($quietOpt)) {
            $quiet = [$quietOpt, $quietOpt];
        }
        return self::linearSvg($enc, $quiet, $color, $bg, $w, $h, $showText);
    }

    private static function matrixSide(int $modules, float $size, ?float $width, ?float $height): float
    {
        if ($width !== null || $height !== null) {
            $side = min($width ?? PHP_FLOAT_MAX, $height ?? PHP_FLOAT_MAX);
            return max(8.0, $side);
        }
        return $modules * 3.0 * $size;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || (is_string($v) && preg_match('/^(1|true|yes|on)$/i', trim($v)) === 1) || (is_int($v) && $v === 1);
    }

    /**
     * Output size of a (possibly non-square) module matrix: default modules x unit, or fitted into width / height
     * (aspect ratio kept; for square symbols the smaller of both is used as the side, at least 8).
     *
     * @return array{float, float}
     */
    private static function fitDims(int $mw, int $mh, float $unit, ?float $width, ?float $height, bool $square): array
    {
        if ($width === null && $height === null) {
            return [$mw * $unit, $mh * $unit];
        }
        if ($square) {
            $side = self::matrixSide($mw, 1.0, $width, $height);
            return [$side, $side];
        }
        $scale = min($width !== null ? $width / $mw : PHP_FLOAT_MAX, $height !== null ? $height / $mh : PHP_FLOAT_MAX);
        $scale = max($scale, 4.0 / max($mw, $mh));
        return [$mw * $scale, $mh * $scale];
    }

    /**
     * Module matrix as merged rects (horizontal runs joined vertically when identical); each matrix row is
     * $rowH modules tall.
     *
     * @param list<list<int>> $matrix
     */
    private static function matrixSvg(array $matrix, int $quiet, string $color, ?string $bg, float $w, float $h, int $rowH = 1): string
    {
        $n = count($matrix);
        $m = count($matrix[0]);
        $totalW = $m + 2 * $quiet;
        $totalH = $n * $rowH + 2 * $quiet;
        $rects = [];
        $open = []; // "x:w" => start row
        for ($y = 0; $y <= $n; $y++) {
            $runs = [];
            if ($y < $n) {
                $row = $matrix[$y];
                for ($x = 0; $x < $m; $x++) {
                    if ($row[$x]) {
                        $s = $x;
                        while ($x + 1 < $m && $row[$x + 1]) {
                            $x++;
                        }
                        $runs[$s . ':' . ($x - $s + 1)] = true;
                    }
                }
            }
            foreach ($open as $key => $start) {
                if (!isset($runs[$key])) {
                    [$rx, $rw] = array_map('intval', explode(':', (string) $key));
                    $rects[] = [$rx, $start, $rw, $y - $start];
                    unset($open[$key]);
                }
            }
            foreach ($runs as $key => $_) {
                $open[$key] ??= $y;
            }
        }
        $body = '';
        if ($bg !== null) {
            $body .= sprintf('<rect x="0" y="0" width="%d" height="%d" fill="%s"/>', $totalW, $totalH, $bg);
        }
        foreach ($rects as [$rx, $ry, $rw, $rh]) {
            $body .= sprintf('<rect x="%d" y="%d" width="%d" height="%d"/>', $rx + $quiet, $ry * $rowH + $quiet, $rw, $rh * $rowH);
        }
        $body = sprintf('<g fill="%s">%s</g>', $color, $body);
        return sprintf(
            '<svg viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" width="%s" height="%s">%s</svg>',
            $totalW,
            $totalH,
            self::fmt($w),
            self::fmt($h),
            $body
        );
    }

    /**
     * @param array{bits: string, text: string, kind: string, digits: string, quiet: array{int, int}, tall: list<array{int, int}>} $enc
     * @param array{int, int} $quiet
     */
    private static function linearSvg(array $enc, array $quiet, string $color, ?string $bg, float $w, float $h, bool $showText): string
    {
        $bits = $enc['bits'];
        $n = strlen($bits);
        $total = $quiet[0] + $n + $quiet[1];
        $mw = $w / $total;
        $isEan = $enc['kind'] !== 'plain';
        $fs = max(5.0, min(9.0, $h * 0.25));
        $hasText = $showText && ($isEan || $enc['text'] !== '');

        if (!$hasText) {
            $regBottom = $h;
            $tallBottom = $h;
        } elseif ($isEan) {
            $regBottom = $h - $fs * 0.85 - 1.0;
            $tallBottom = $h - $fs * 0.3 - 1.0;
        } else {
            $regBottom = $h - ($fs + 4.0);
            $tallBottom = $regBottom;
        }
        $isTall = static function (int $m) use ($enc): bool {
            foreach ($enc['tall'] as [$a, $b]) {
                if ($m >= $a && $m < $b) {
                    return true;
                }
            }
            return false;
        };

        $out = '';
        if ($bg !== null) {
            $out .= sprintf('<rect x="0" y="0" width="%s" height="%s" fill="%s"/>', self::fmt($w), self::fmt($h), $bg);
        }
        $i = 0;
        while ($i < $n) {
            if ($bits[$i] !== '1') {
                $i++;
                continue;
            }
            $tall = $isTall($i);
            $j = $i;
            while ($j + 1 < $n && $bits[$j + 1] === '1' && $isTall($j + 1) === $tall) {
                $j++;
            }
            $out .= sprintf(
                '<rect x="%s" y="0" width="%s" height="%s" fill="%s"/>',
                self::fmt(($quiet[0] + $i) * $mw),
                self::fmt(($j - $i + 1) * $mw),
                self::fmt($tall ? $tallBottom : $regBottom),
                $color
            );
            $i = $j + 1;
        }

        if ($hasText) {
            $y = self::fmt($h - 1.5);
            $fsS = self::fmt($fs);
            $txt = static fn(float $modulePos, string $s): string => sprintf(
                '<text x="%s" y="%s" font-size="%s" text-anchor="middle" fill="%s">%s</text>',
                self::fmt($modulePos * $mw),
                $y,
                $fsS,
                $color,
                htmlspecialchars($s, ENT_QUOTES, 'UTF-8')
            );
            $d = $enc['digits'];
            $q0 = $quiet[0];
            switch ($enc['kind']) {
                case 'ean13':
                    $out .= $txt($q0 / 2, $d[0]);
                    for ($k = 1; $k <= 6; $k++) {
                        $out .= $txt($q0 + 3 + ($k - 1) * 7 + 3.5, $d[$k]);
                        $out .= $txt($q0 + 50 + ($k - 1) * 7 + 3.5, $d[$k + 6]);
                    }
                    break;
                case 'ean8':
                    for ($k = 0; $k < 4; $k++) {
                        $out .= $txt($q0 + 3 + $k * 7 + 3.5, $d[$k]);
                        $out .= $txt($q0 + 36 + $k * 7 + 3.5, $d[$k + 4]);
                    }
                    break;
                case 'upca':
                    $out .= $txt($q0 / 2, $d[0]);
                    for ($k = 1; $k <= 5; $k++) {
                        $out .= $txt($q0 + 3 + $k * 7 + 3.5, $d[$k]);
                        $out .= $txt($q0 + 50 + ($k - 1) * 7 + 3.5, $d[$k + 5]);
                    }
                    $out .= $txt($q0 + 95 + $quiet[1] / 2, $d[11]);
                    break;
                case 'upce':
                    $out .= $txt($q0 / 2, $d[0]);
                    for ($k = 0; $k < 6; $k++) {
                        $out .= $txt($q0 + 3 + $k * 7 + 3.5, $d[$k + 1]);
                    }
                    $out .= $txt($q0 + 51 + $quiet[1] / 2, $d[7]);
                    break;
                default:
                    $out .= $txt($total / 2, $enc['text']);
            }
        }

        return sprintf(
            '<svg viewBox="0 0 %s %s" preserveAspectRatio="none" width="%s" height="%s">%s</svg>',
            self::fmt($w),
            self::fmt($h),
            self::fmt($w),
            self::fmt($h),
            $out
        );
    }

    private static function fmt(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
