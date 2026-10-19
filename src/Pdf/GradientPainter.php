<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssGradient;
use IvoPdf\Css\CssUnit;
use IvoPdf\Options;

/**
 * Paints CSS gradients (linear, radial, conic and the repeating-* forms) into a clipped box.
 *
 * - Colours are interpolated in premultiplied RGBA like browsers: `red → transparent` stays red while it fades.
 * - Stops with alpha are drawn as a colour shading under a luminosity soft mask that holds the alpha as a matching
 *   DeviceGray shading. Where transparency is not allowed (PDF/A-1, PDF/X-1a / X-3) the stops are composited onto
 *   the box's background colour (or white) instead and a warning says so.
 * - Stop positions may be lengths, percentages (of the gradient line / ray) or angles (conic); colour hints are
 *   honoured; repeating gradients are unrolled over the area the box needs.
 * - Conic gradients have no PDF shading type: they are written as a free-form triangle mesh (ShadingType 4) of thin
 *   wedges around the centre (at most 0.5 degrees where the colour changes, exact edges at hard stops).
 */
final class GradientPainter
{
    /** sub-samples per segment where premultiplied interpolation is not linear in the stored colours */
    private const SAMPLES = 8;
    /** unrolled stops a repeating gradient may need before it is painted as its average colour */
    private const MAX_STOPS = 6000;

    /**
     * @param \Closure(string, float, float): array{0: float, 1: float} $resolvePosition
     */
    public function __construct(
        private PdfDocument $pdf,
        private Options $options,
        private \Closure $resolvePosition
    ) {
    }

    /**
     * Paints one gradient value into the box ($x, $y top-left in document points).
     *
     * @param array<int|string, mixed>|null $currentColor element colour for currentColor stops
     * @param array<int|string, mixed>|null $backdrop opaque colour under the gradient (used only when flattening)
     */
    public function paintCss(string $css, float $x, float $y, float $w, float $h, float $pageHeight, string $clipPath, float $fontSizePt, ?array $currentColor, ?array $backdrop): string
    {
        if ($w <= 0.05 || $h <= 0.05) {
            return '';
        }
        $g = CssGradient::parse($css, $currentColor);
        if ($g === null) {
            $short = strlen($css) > 80 ? substr($css, 0, 77) . '...' : $css;
            $this->options->addWarning("Unsupported or invalid CSS gradient '{$short}' was not drawn.");
            return '';
        }
        return $this->paint($g, $x, $y, $w, $h, $pageHeight, $clipPath, $fontSizePt, $backdrop);
    }

    /**
     * @param array<string, mixed> $g {@see CssGradient::parse()}
     * @param array<int|string, mixed>|null $backdrop
     */
    public function paint(array $g, float $x, float $y, float $w, float $h, float $pageHeight, string $clipPath, float $fontSizePt, ?array $backdrop): string
    {
        $type = (string) $g['type'];
        $cyPdfTop = $pageHeight - $y;

        if ($type === 'linear') {
            $angle = (float) $g['angle'];
            if (isset($g['diagonal'])) {
                // legacy -webkit- corner: along the diagonal towards that corner
                $tx = str_contains((string) $g['diagonal'], 'right') ? $w : -$w;
                $ty = str_contains((string) $g['diagonal'], 'bottom') ? $h : -$h;
                $angle = rad2deg(atan2($tx, -$ty));
            } elseif ($g['corner'] !== null) {
                // `to <corner>`: perpendicular to the diagonal through the two neighbouring corners
                $a = rad2deg(atan2($h, $w));
                $angle = match ($g['corner']) {
                    'top right' => $a,
                    'bottom right' => 180.0 - $a,
                    'bottom left' => 180.0 + $a,
                    default => 360.0 - $a,
                };
            }
            // (the same arithmetic as before 1.0.2, so opaque gradients keep byte-identical output)
            $rad = deg2rad(90.0 - $angle);
            $dx = cos($rad);
            $dy = sin($rad);
            $half = (abs($w * $dx) + abs($h * $dy)) / 2.0;
            $len = 2.0 * $half;
            if ($len < 1e-6) {
                return '';
            }
            $cx = $x + $w / 2.0;
            $cy = (($pageHeight - ($y + $h)) + $cyPdfTop) / 2.0;
            $geom = ['len' => $len, 'half' => $half, 'cx' => $cx, 'cy' => $cy, 'dx' => $dx, 'dy' => $dy];
            $toFraction = static fn(string $p): ?float => self::lengthFraction($p, $len, $fontSizePt);
            $range = [0.0, 1.0];
        } elseif ($type === 'radial') {
            [$cxOff, $cyOff] = ($this->resolvePosition)((string) $g['position'], $w, $h);
            [$rx, $ry] = $this->radialRadii($g, $cxOff, $cyOff, $w, $h, $fontSizePt);
            $geom = ['rx' => $rx, 'ry' => $ry, 'cx' => $x + $cxOff, 'cy' => $pageHeight - ($y + $cyOff)];
            $toFraction = static fn(string $p): ?float => self::lengthFraction($p, $rx, $fontSizePt);
            $far = 0.0;
            foreach ([[0.0, 0.0], [$w, 0.0], [0.0, $h], [$w, $h]] as [$px, $py]) {
                $far = max($far, hypot(($px - $cxOff) / $rx, ($py - $cyOff) / $ry));
            }
            $range = [0.0, $far];
        } else {
            [$cxOff, $cyOff] = ($this->resolvePosition)((string) $g['position'], $w, $h);
            $far = 0.0;
            foreach ([[0.0, 0.0], [$w, 0.0], [0.0, $h], [$w, $h]] as [$px, $py]) {
                $far = max($far, hypot($px - $cxOff, $py - $cyOff));
            }
            $geom = ['cx' => $x + $cxOff, 'cy' => $cyPdfTop - $cyOff, 'far' => max(0.5, $far), 'from' => (float) $g['from']];
            $toFraction = static function (string $p): ?float {
                $p = strtolower(trim($p));
                if (str_ends_with($p, '%')) {
                    return is_numeric(substr($p, 0, -1)) ? (float) substr($p, 0, -1) / 100.0 : null;
                }
                $deg = CssGradient::angle($p, true);
                return $deg === null ? null : $deg / 360.0;
            };
            $range = [0.0, 1.0];
        }

        $resolved = CssGradient::resolveStops($g['stops'], $toFraction);
        if ($resolved === null) {
            $this->options->addWarning('A CSS ' . $type . '-gradient with invalid colour stop positions was not drawn.');
            return '';
        }
        $stops = self::applyHints($resolved);

        $hasAlpha = false;
        foreach ($stops as $s) {
            if ((float) $s['c'][3] < 0.999) {
                $hasAlpha = true;
                break;
            }
        }
        if ($hasAlpha && $this->transparencyForbidden()) {
            $bd = $backdrop ?? [1.0, 1.0, 1.0, 1.0];
            foreach ($stops as $k => $s) {
                $stops[$k]['c'] = self::composite($s['c'], $bd);
            }
            $hasAlpha = false;
            $this->options->addWarning('Gradient transparency was flattened onto the background colour (or white): the PDF/A-1 / PDF/X-1a / PDF/X-3 output does not allow transparency.');
        }

        if ($type === 'conic') {
            if ($g['repeating']) {
                $stops = $this->unroll($stops, $range[0], $range[1]);
                if (count($stops) === 1) {
                    return $this->solid($stops[0]['c'], $clipPath);
                }
            }
            return $this->paintConic($stops, $geom, $clipPath, $hasAlpha);
        }
        if ($g['repeating']) {
            return $this->paintRepeating($type, $stops, $geom, $range, $clipPath, $hasAlpha);
        }

        if ($type === 'radial' && $stops[0]['t'] < 0.0) {
            $stops = self::clip($stops, 0.0, max(0.0, $stops[count($stops) - 1]['t']));
        }
        $t0 = $stops[0]['t'];
        $t1 = $stops[count($stops) - 1]['t'];
        if ($t1 - $t0 < 1e-6) {
            // every stop at one position: a hard switch from the first to the last colour there
            $stops = [['t' => $t0, 'c' => $stops[0]['c']], ['t' => $t0 + 1e-4, 'c' => $stops[count($stops) - 1]['c']]];
            $t1 = $t0 + 1e-4;
        }

        $legacy = !$hasAlpha && !$g['repeating'] && $t0 >= -1e-9 && $t1 <= 1.0 + 1e-9;
        if ($type === 'linear') {
            $half = $geom['half'];
            $sx = $geom['cx'] - $geom['dx'] * $half;
            $sy = $geom['cy'] - $geom['dy'] * $half;
            $vx = $geom['dx'] * $geom['len'];
            $vy = $geom['dy'] * $geom['len'];
            $coords = $legacy
                ? [$sx, $sy, $geom['cx'] + $geom['dx'] * $half, $geom['cy'] + $geom['dy'] * $half]
                : [$sx + $vx * $t0, $sy + $vy * $t0, $sx + $vx * $t1, $sy + $vy * $t1];
            $shType = 2;
            $matrix = '';
        } else {
            $coords = $legacy ? [0.0, 0.0, 0.0, 0.0, 0.0, $geom['rx']] : [0.0, 0.0, $t0 * $geom['rx'], 0.0, 0.0, $t1 * $geom['rx']];
            $shType = 3;
            $matrix = sprintf("1 0 0 %.5F %.3F %.3F cm\n", $geom['ry'] / $geom['rx'], $geom['cx'], $geom['cy']);
        }

        if ($legacy) {
            // opaque gradient inside its line: the document's standard shading (also keeps print colours)
            $legacyStops = array_map(static fn(array $s): array => ['color' => $s['c'], 'offset' => $s['t']], $stops);
            $shId = $shType === 2
                ? $this->pdf->registerAxialShading($coords, $legacyStops)
                : $this->pdf->registerRadialShading($coords, $legacyStops);
            if ($shType === 2) {
                return "q\n" . $clipPath . "W n\n/{$shId} sh\nQ\n";
            }
            return "q\n" . $clipPath . "W n\n" . $matrix . "/{$shId} sh\nQ\n";
        }

        $span = $t1 - $t0;
        $norm = array_map(static fn(array $s): array => ['t' => ($s['t'] - $t0) / $span, 'c' => $s['c']], $stops);
        $coordStr = implode(' ', array_map(static fn(float $v): string => sprintf('%.4F', $v), $coords));
        $colorStops = self::colorTrack($norm);
        $space = ColorOps::shadingSpace(array_map(static fn(array $s): array => ['color' => $s['c']], $colorStops));
        $shId = $this->pdf->registerRawShading(sprintf(
            '/ShadingType %d /ColorSpace /%s /Coords [%s] /Function %s /Extend [true true]',
            $shType,
            $space,
            $coordStr,
            self::functionDict($colorStops, static fn(array $c): string => ColorOps::components(self::opaque($c), $space))
        ));
        $mask = '';
        if ($hasAlpha) {
            $alphaStops = array_map(static fn(array $s): array => ['t' => $s['t'], 'c' => [$s['c'][3], $s['c'][3], $s['c'][3], 1.0]], $norm);
            $alphaId = $this->pdf->registerRawShading(sprintf(
                '/ShadingType %d /ColorSpace /DeviceGray /Coords [%s] /Function %s /Extend [true true]',
                $shType,
                $coordStr,
                self::functionDict($alphaStops, static fn(array $c): string => sprintf('%.4F', $c[0]))
            ));
            $mask = '/' . $this->pdf->paints()->addLuminosityMask("/{$alphaId} sh\n") . " gs\n";
        }
        return "q\n" . $clipPath . "W n\n" . $matrix . $mask . "/{$shId} sh\nQ\n";
    }

    /**
     * Repeating linear / radial gradient: one shading per period ("band"), painted once per repetition. A single
     * shading function over all repetitions would be sampled too coarsely by viewers (MuPDF uses a 256-entry table),
     * so each band is its own `sh`. Every band runs 1 pt past its end with its last colour and the bands are painted
     * from the start outwards: the next band covers that overlap exactly, so there are no hairline seams.
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @param array<string, float> $geom
     * @param array{0: float, 1: float} $range gradient-line fractions the box needs
     */
    private function paintRepeating(string $type, array $stops, array $geom, array $range, string $clipPath, bool $hasAlpha): string
    {
        $n = count($stops);
        $first = $stops[0]['t'];
        $last = $stops[$n - 1]['t'];
        $period = $last - $first;
        if ($period <= 1e-9 || ($range[1] - $range[0]) / $period > 1500) {
            if ($period > 1e-9) {
                $this->options->addWarning('A repeating CSS gradient repeats too often for its box and was painted with its average colour.');
            }
            return $this->solid(self::averageColor($stops), $clipPath);
        }
        $kStart = (int) floor(($range[0] - $first) / $period);
        $kEnd = (int) ceil(($range[1] - $last) / $period);
        $unit = $type === 'linear' ? $geom['len'] : $geom['rx'];
        $over = 1.0 / ($period * $unit); // 1 pt as a fraction of the period
        $space = ColorOps::shadingSpace(array_map(static fn(array $s): array => ['color' => $s['c']], $stops));
        $functions = function (array $norm) use ($over, $space, $hasAlpha): array {
            $norm[] = ['t' => 1.0 + $over, 'c' => $norm[count($norm) - 1]['c']];
            $norm = array_map(static fn(array $s): array => ['t' => $s['t'] / (1.0 + $over), 'c' => $s['c']], $norm);
            return [
                self::functionDict(self::colorTrack($norm), static fn(array $c): string => ColorOps::components(self::opaque($c), $space)),
                $hasAlpha ? self::alphaFunction($norm) : '',
            ];
        };
        [$colorFn, $alphaFn] = $functions(array_map(static fn(array $s): array => ['t' => ($s['t'] - $first) / $period, 'c' => $s['c']], $stops));

        $colorDraws = '';
        $alphaDraws = '';
        if ($type === 'linear') {
            $half = $geom['half'];
            $sx = $geom['cx'] - $geom['dx'] * $half;
            $sy = $geom['cy'] - $geom['dy'] * $half;
            $vx = $geom['dx'] * $geom['len'];
            $vy = $geom['dy'] * $geom['len'];
            $end = $last + $over * $period;
            $coords = self::coordString([$sx + $vx * $first, $sy + $vy * $first, $sx + $vx * $end, $sy + $vy * $end]);
            $colorSh = $this->pdf->registerRawShading(self::shadingDict(2, $space, $coords, $colorFn, 'false false'));
            $alphaSh = $hasAlpha ? $this->pdf->registerRawShading(self::shadingDict(2, 'DeviceGray', $coords, $alphaFn, 'false false')) : '';
            for ($k = $kStart; $k <= $kEnd; $k++) {
                $cm = sprintf('q 1 0 0 1 %.4F %.4F cm ', $vx * $period * $k, $vy * $period * $k);
                $colorDraws .= $cm . "/{$colorSh} sh Q\n";
                if ($hasAlpha) {
                    $alphaDraws .= $cm . "/{$alphaSh} sh Q\n";
                }
            }
            $matrix = '';
        } else {
            $rx = $geom['rx'];
            for ($k = $kStart; $k <= $kEnd; $k++) {
                $a = $first + $k * $period;
                $b = $last + $k * $period;
                if ($b <= 1e-9) {
                    continue;
                }
                $cFn = $colorFn;
                $aFn = $alphaFn;
                if ($a < 0.0) {
                    // the innermost ring starts inside the centre: its stops are cut at radius 0
                    $shifted = array_map(static fn(array $s): array => ['t' => $s['t'] + $k * $period, 'c' => $s['c']], $stops);
                    $cut = array_map(static fn(array $s): array => ['t' => $s['t'] / $b, 'c' => $s['c']], self::clip($shifted, 0.0, $b));
                    [$cFn, $aFn] = $functions($cut);
                    $coords = self::coordString([0.0, 0.0, 0.0, 0.0, 0.0, ($b + $over * $b) * $rx]);
                } else {
                    $coords = self::coordString([0.0, 0.0, $a * $rx, 0.0, 0.0, ($b + $over * $period) * $rx]);
                }
                $colorDraws .= '/' . $this->pdf->registerRawShading(self::shadingDict(3, $space, $coords, $cFn, 'false false')) . " sh\n";
                if ($hasAlpha) {
                    $alphaDraws .= '/' . $this->pdf->registerRawShading(self::shadingDict(3, 'DeviceGray', $coords, $aFn, 'false false')) . " sh\n";
                }
            }
            $matrix = sprintf("1 0 0 %.5F %.3F %.3F cm\n", $geom['ry'] / $rx, $geom['cx'], $geom['cy']);
        }
        if (!$hasAlpha) {
            return "q\n" . $clipPath . "W n\n" . $matrix . $colorDraws . "Q\n";
        }
        // The overlapping bands must be composited as one opaque layer (a group) before the alpha mask applies:
        // each translucent `sh` on its own would darken where the bands overlap
        $mask = '/' . $this->pdf->paints()->addLuminosityMask($alphaDraws) . " gs\n";
        $group = $this->pdf->paints()->addForm($colorDraws);
        return "q\n" . $clipPath . "W n\n" . $matrix . $mask . "/{$group} Do\nQ\n";
    }

    /**
     * @param list<array{t: float, c: array<int|string, mixed>}> $norm
     */
    private static function alphaFunction(array $norm): string
    {
        $alphaStops = array_map(static fn(array $s): array => ['t' => $s['t'], 'c' => [$s['c'][3], $s['c'][3], $s['c'][3], 1.0]], $norm);
        return self::functionDict($alphaStops, static fn(array $c): string => sprintf('%.4F', $c[0]));
    }

    private static function shadingDict(int $type, string $space, string $coords, string $function, string $extend): string
    {
        return sprintf('/ShadingType %d /ColorSpace /%s /Coords [%s] /Function %s /Extend [%s]', $type, $space, $coords, $function, $extend);
    }

    /**
     * @param list<float> $coords
     */
    private static function coordString(array $coords): string
    {
        return implode(' ', array_map(static fn(float $v): string => sprintf('%.4F', $v), $coords));
    }

    /**
     * The average colour of a gradient with its stops spaced evenly (CSS Images 3: a repeating gradient whose stops
     * all sit at one position).
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @return array<int|string, mixed>
     */
    private static function averageColor(array $stops): array
    {
        $n = count($stops);
        $acc = [0.0, 0.0, 0.0, 0.0];
        for ($i = 0; $i < $n - 1; $i++) {
            foreach ([$stops[$i]['c'], $stops[$i + 1]['c']] as $c) {
                $acc[0] += (float) $c[0] * (float) $c[3];
                $acc[1] += (float) $c[1] * (float) $c[3];
                $acc[2] += (float) $c[2] * (float) $c[3];
                $acc[3] += (float) $c[3];
            }
        }
        if ($n === 1) {
            return $stops[0]['c'];
        }
        $a = $acc[3] / (2 * ($n - 1));
        return $acc[3] > 1e-9 ? [$acc[0] / $acc[3], $acc[1] / $acc[3], $acc[2] / $acc[3], $a] : [0.0, 0.0, 0.0, 0.0];
    }

    /** PDF/A-1 and PDF/X-1a / X-3 do not allow soft masks. */
    private function transparencyForbidden(): bool
    {
        return ColorOps::transparencyForbidden();
    }

    /**
     * @param array<string, mixed> $g
     * @return array{0: float, 1: float}
     */
    private function radialRadii(array $g, float $cxOff, float $cyOff, float $w, float $h, float $fontSizePt): array
    {
        $dxMin = min(abs($cxOff), abs($w - $cxOff));
        $dxMax = max(abs($cxOff), abs($w - $cxOff));
        $dyMin = min(abs($cyOff), abs($h - $cyOff));
        $dyMax = max(abs($cyOff), abs($h - $cyOff));
        $isCircle = $g['shape'] === 'circle';
        if ($g['radii'][0] !== null) {
            $rx = CssUnit::toPt((string) $g['radii'][0], $w, $fontSizePt);
            $ry = $g['radii'][1] !== null ? CssUnit::toPt((string) $g['radii'][1], $h, $fontSizePt) : $rx;
        } else {
            [$rx, $ry] = match ($g['size']) {
                'closest-side' => $isCircle ? [min($dxMin, $dyMin), min($dxMin, $dyMin)] : [$dxMin, $dyMin],
                'farthest-side' => $isCircle ? [max($dxMax, $dyMax), max($dxMax, $dyMax)] : [$dxMax, $dyMax],
                'closest-corner' => $isCircle
                    ? [hypot($dxMin, $dyMin), hypot($dxMin, $dyMin)]
                    : [$dxMin * M_SQRT2, $dyMin * M_SQRT2],
                default => $isCircle
                    ? [hypot($dxMax, $dyMax), hypot($dxMax, $dyMax)]
                    : [$dxMax * M_SQRT2, $dyMax * M_SQRT2],
            };
        }
        return [max(0.1, $rx), max(0.1, $ry)];
    }

    private static function lengthFraction(string $p, float $len, float $fontSizePt): ?float
    {
        $p = trim($p);
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i', $p) === 1 && (float) $p !== 0.0) {
            return null; // unitless non-zero length
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?(%|[a-z]+)?$/i', $p) !== 1 && !preg_match('/^(?:calc|min|max|clamp)\(/i', $p)) {
            return null;
        }
        return CssUnit::toPt($p, $len, $fontSizePt) / $len;
    }

    /**
     * Turns resolved stops (with colour hints) into plain stops; a hint is approximated by sub-stops along the
     * CSS hint curve t^(log 0.5 / log H).
     *
     * @param list<array{color: array<int|string, mixed>|null, t: float}> $resolved
     * @return list<array{t: float, c: array<int|string, mixed>}>
     */
    private static function applyHints(array $resolved): array
    {
        $out = [];
        $n = count($resolved);
        for ($i = 0; $i < $n; $i++) {
            $s = $resolved[$i];
            if ($s['color'] !== null) {
                $out[] = ['t' => $s['t'], 'c' => self::normalize($s['color'])];
                continue;
            }
            $a = $resolved[$i - 1];
            $b = $resolved[$i + 1];
            $ca = self::normalize((array) $a['color']);
            $cb = self::normalize((array) $b['color']);
            $len = $b['t'] - $a['t'];
            if ($len < 1e-9) {
                continue;
            }
            $hf = ($s['t'] - $a['t']) / $len;
            if ($hf <= 1e-6) {
                $out[] = ['t' => $a['t'], 'c' => $cb]; // the hint at the first stop: hard switch there
                continue;
            }
            if ($hf >= 1.0 - 1e-6) {
                $out[] = ['t' => $b['t'], 'c' => $ca];
                continue;
            }
            if (abs($hf - 0.5) < 1e-6) {
                continue; // a centred hint is plain linear interpolation
            }
            $exp = log(0.5) / log($hf);
            $steps = 16;
            for ($k = 1; $k < $steps; $k++) {
                $f = $k / $steps;
                $out[] = ['t' => $a['t'] + $len * $f, 'c' => self::mixPremultiplied($ca, $cb, $f ** $exp)];
            }
        }
        return $out;
    }

    /**
     * Unrolls a repeating gradient over [$lo, $hi]; a single stop means a solid (average) colour.
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @return list<array{t: float, c: array<int|string, mixed>}>
     */
    private function unroll(array $stops, float $lo, float $hi): array
    {
        $n = count($stops);
        $first = $stops[0]['t'];
        $period = $stops[$n - 1]['t'] - $first;
        $copies = $period > 1e-9 ? ($hi - $lo) / $period + 2 : INF;
        if ($period <= 1e-9 || $copies * $n > self::MAX_STOPS) {
            if ($period > 1e-9) {
                $this->options->addWarning('A repeating CSS gradient repeats too often for its box and was painted with its average colour.');
            }
            return [['t' => 0.0, 'c' => self::averageColor($stops)]];
        }
        $kStart = (int) floor(($lo - $first) / $period) - 1;
        $kEnd = (int) ceil(($hi - $first) / $period);
        $out = [];
        for ($k = $kStart; $k <= $kEnd; $k++) {
            foreach ($stops as $s) {
                $out[] = ['t' => $s['t'] + $k * $period, 'c' => $s['c']];
            }
        }
        return self::clip($out, $lo, $hi);
    }

    /**
     * Cuts a stop list to [$lo, $hi], interpolating the colour at both ends.
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @return list<array{t: float, c: array<int|string, mixed>}>
     */
    private static function clip(array $stops, float $lo, float $hi): array
    {
        $out = [['t' => $lo, 'c' => self::sampleAt($stops, $lo, true)]];
        foreach ($stops as $s) {
            if ($s['t'] > $lo && $s['t'] < $hi) {
                $out[] = $s;
            }
        }
        $out[] = ['t' => $hi, 'c' => self::sampleAt($stops, $hi, false)];
        return $out;
    }

    /**
     * Colour at $t (premultiplied interpolation); at a hard stop $right picks the colour after it.
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @return array<int|string, mixed>
     */
    private static function sampleAt(array $stops, float $t, bool $right): array
    {
        $n = count($stops);
        if ($t < $stops[0]['t'] || ($t === $stops[0]['t'] && !$right)) {
            return $stops[0]['c'];
        }
        if ($t > $stops[$n - 1]['t'] || ($t === $stops[$n - 1]['t'] && $right)) {
            return $stops[$n - 1]['c'];
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $stops[$i];
            $b = $stops[$i + 1];
            if ($right ? ($t >= $a['t'] && $t < $b['t']) : ($t > $a['t'] && $t <= $b['t'])) {
                $len = $b['t'] - $a['t'];
                return $len < 1e-12 ? $b['c'] : self::mixPremultiplied($a['c'], $b['c'], ($t - $a['t']) / $len);
            }
        }
        return $stops[$n - 1]['c'];
    }

    /**
     * Stops of the colour shading (the soft mask carries the alpha): a fully transparent end takes the colour of the
     * other end, segments between different alphas are sub-sampled (premultiplied interpolation is not linear there).
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @return list<array{t: float, c: array<int|string, mixed>}>
     */
    private static function colorTrack(array $stops): array
    {
        $out = [];
        $push = static function (float $t, array $c) use (&$out): void {
            $last = $out[count($out) - 1] ?? null;
            if ($last !== null && abs($last['t'] - $t) < 1e-9 && self::sameColor($last['c'], $c)) {
                return;
            }
            $out[] = ['t' => $t, 'c' => $c];
        };
        $n = count($stops);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $stops[$i];
            $b = $stops[$i + 1];
            $aa = (float) $a['c'][3];
            $ba = (float) $b['c'][3];
            $ca = $a['c'];
            $cb = $b['c'];
            if ($aa < 1e-6 && $ba >= 1e-6) {
                $ca = $cb;
            } elseif ($ba < 1e-6 && $aa >= 1e-6) {
                $cb = $ca;
            }
            $push($a['t'], $ca);
            if ($aa >= 1e-6 && $ba >= 1e-6 && abs($aa - $ba) > 1e-3 && !self::sameColor($ca, $cb) && $b['t'] - $a['t'] > 1e-9) {
                for ($k = 1; $k < self::SAMPLES; $k++) {
                    $f = $k / self::SAMPLES;
                    $push($a['t'] + ($b['t'] - $a['t']) * $f, self::mixPremultiplied($ca, $cb, $f));
                }
            }
            $push($b['t'], $cb);
        }
        if ($n === 1) {
            $push($stops[0]['t'], $stops[0]['c']);
        }
        return $out;
    }

    /**
     * PDF function over [0, 1] for stops with t in 0..1: a type 2 function per non-empty segment, stitched (type 3).
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @param callable(array<int|string, mixed>): string $components
     */
    private static function functionDict(array $stops, callable $components): string
    {
        if ($stops[0]['t'] > 1e-9) {
            array_unshift($stops, ['t' => 0.0, 'c' => $stops[0]['c']]);
        }
        $last = $stops[count($stops) - 1];
        if ($last['t'] < 1.0 - 1e-9) {
            $stops[] = ['t' => 1.0, 'c' => $last['c']];
        }
        $segs = [];
        $n = count($stops);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($stops[$i + 1]['t'] - $stops[$i]['t'] > 1e-7) {
                $segs[] = [$stops[$i], $stops[$i + 1]];
            }
        }
        $fn = static fn(array $a, array $b): string => sprintf('<< /FunctionType 2 /Domain [0 1] /C0 [%s] /C1 [%s] /N 1 >>', $components($a['c']), $components($b['c']));
        if ($segs === []) {
            return $fn($stops[$n - 1], $stops[$n - 1]);
        }
        if (count($segs) === 1) {
            return $fn($segs[0][0], $segs[0][1]);
        }
        $fns = [];
        $bounds = [];
        $prev = 0.0;
        foreach ($segs as $k => [$a, $b]) {
            $fns[] = $fn($a, $b);
            if ($k > 0) {
                $bound = min(1.0 - 1e-6, max($prev + 1e-6, $a['t']));
                $bounds[] = sprintf('%.6F', $bound);
                $prev = $bound;
            }
        }
        return sprintf(
            '<< /FunctionType 3 /Domain [0 1] /Functions [%s] /Bounds [%s] /Encode [%s] >>',
            implode(' ', $fns),
            implode(' ', $bounds),
            implode(' ', array_fill(0, count($fns), '0 1'))
        );
    }

    /**
     * Conic gradient as a triangle mesh around the centre.
     *
     * @param list<array{t: float, c: array<int|string, mixed>}> $stops
     * @param array<string, float> $geom
     */
    private function paintConic(array $stops, array $geom, string $clipPath, bool $hasAlpha): string
    {
        $stops = self::clip($stops, 0.0, 1.0);
        $maxSpan = 1.0 / 48.0; // 7.5 degrees for a constant colour
        $fine = 1.0 / 720.0;   // 0.5 degrees where the colour changes
        $radius = $geom['far'] / cos(M_PI * $maxSpan) + 2.0;
        $cx = $geom['cx'];
        $cy = $geom['cy'];
        $from = $geom['from'];

        /** @var list<array{0: float, 1: float, 2: array<int|string, mixed>, 3: array<int|string, mixed>, 4: array<int|string, mixed>}> $tris t0, t1, colour at t0, at t1, at the centre */
        $tris = [];
        $n = count($stops);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $stops[$i];
            $b = $stops[$i + 1];
            $len = $b['t'] - $a['t'];
            if ($len < 1e-9) {
                continue;
            }
            $varies = !self::sameColor($a['c'], $b['c']) || abs((float) $a['c'][3] - (float) $b['c'][3]) > 1e-4;
            $parts = max(1, (int) ceil($len / ($varies ? $fine : $maxSpan) - 1e-9));
            for ($k = 0; $k < $parts; $k++) {
                $f0 = $k / $parts;
                $f1 = ($k + 1) / $parts;
                $tris[] = [
                    $a['t'] + $len * $f0,
                    $a['t'] + $len * $f1,
                    self::mixPremultiplied($a['c'], $b['c'], $f0),
                    self::mixPremultiplied($a['c'], $b['c'], $f1),
                    self::mixPremultiplied($a['c'], $b['c'], ($f0 + $f1) / 2.0),
                ];
            }
        }
        if ($tris === []) {
            return $this->solid($stops[count($stops) - 1]['c'], $clipPath);
        }

        $space = ColorOps::shadingSpace(array_map(static fn(array $s): array => ['color' => $s['c']], $stops));
        $nComp = $space === 'DeviceCMYK' ? 4 : 3;
        $x0 = $cx - $radius;
        $y0 = $cy - $radius;
        $ext = 2.0 * $radius;
        $point = static function (float $t) use ($from, $cx, $cy, $radius): array {
            $th = deg2rad($from + 360.0 * $t);
            return [$cx + $radius * sin($th), $cy + $radius * cos($th)];
        };
        $q = static fn(float $v, float $min): string => substr(pack('N', (int) round(max(0.0, min(1.0, ($v - $min) / $ext)) * 16777215)), 1);
        $comp = static fn(float $v): string => pack('n', (int) round(max(0.0, min(1.0, $v)) * 65535));
        $colorData = '';
        $alphaData = '';
        foreach ($tris as [$ta, $tb, $ca, $cb, $cc]) {
            $pa = $point($ta);
            $pb = $point($tb);
            foreach ([[[$cx, $cy], $cc], [$pa, $ca], [$pb, $cb]] as [$p, $c]) {
                $xy = "\x00" . $q($p[0], $x0) . $q($p[1], $y0);
                if ($nComp === 4) {
                    $k4 = ColorOps::cmyk($c);
                    $colorData .= $xy . $comp($k4[0]) . $comp($k4[1]) . $comp($k4[2]) . $comp($k4[3]);
                } else {
                    $colorData .= $xy . $comp((float) $c[0]) . $comp((float) $c[1]) . $comp((float) $c[2]);
                }
                if ($hasAlpha) {
                    $alphaData .= $xy . $comp((float) $c[3]);
                }
            }
        }
        if (ColorOps::isRgbOnly()) {
            foreach ($stops as $s) {
                if (($s['c']['cs'] ?? 'rgb') !== 'rgb') {
                    ColorOps::noteDowngrade();
                    break;
                }
            }
        }
        $decodeXY = sprintf('%.4F %.4F %.4F %.4F', $x0, $x0 + $ext, $y0, $y0 + $ext);
        $mesh = '/ShadingType 4 /ColorSpace /%s /BitsPerCoordinate 24 /BitsPerComponent 16 /BitsPerFlag 8 /Decode [%s%s]';
        $shId = $this->pdf->registerRawShading(sprintf($mesh, $space, $decodeXY, str_repeat(' 0 1', $nComp)), $colorData);
        $mask = '';
        if ($hasAlpha) {
            $alphaId = $this->pdf->registerRawShading(sprintf($mesh, 'DeviceGray', $decodeXY, ' 0 1'), $alphaData);
            $mask = '/' . $this->pdf->paints()->addLuminosityMask("/{$alphaId} sh\n") . " gs\n";
        }
        return "q\n" . $clipPath . "W n\n" . $mask . "/{$shId} sh\nQ\n";
    }

    /** @param array<int|string, mixed> $c */
    private function solid(array $c, string $clipPath): string
    {
        $a = (float) $c[3];
        if ($a < 0.002) {
            return '';
        }
        $out = "q\n";
        if ($a < 0.999) {
            $out .= '/' . $this->pdf->registerAlphaState($a) . " gs\n";
        }
        return $out . ColorOps::fillOp(self::opaque($c)) . "\n" . $clipPath . "f\nQ\n";
    }

    /**
     * Interpolates two colours in premultiplied RGBA (print colour models are interpolated alongside when both ends
     * have one).
     *
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     * @return array<int|string, mixed>
     */
    private static function mixPremultiplied(array $a, array $b, float $f): array
    {
        $f = max(0.0, min(1.0, $f));
        $aa = (float) $a[3];
        $ba = (float) $b[3];
        $alpha = $aa + ($ba - $aa) * $f;
        // weight of $b in the un-premultiplied colour
        $wb = $alpha > 1e-9 ? ($ba * $f) / $alpha : $f;
        $out = [
            (float) $a[0] + ((float) $b[0] - (float) $a[0]) * $wb,
            (float) $a[1] + ((float) $b[1] - (float) $a[1]) * $wb,
            (float) $a[2] + ((float) $b[2] - (float) $a[2]) * $wb,
            $alpha,
        ];
        if (isset($a['cs'], $b['cs']) && $a['cs'] !== 'rgb' && $b['cs'] !== 'rgb') {
            $ka = ColorOps::cmyk($a);
            $kb = ColorOps::cmyk($b);
            $out['cs'] = 'cmyk';
            $out['cmyk'] = [
                $ka[0] + ($kb[0] - $ka[0]) * $wb,
                $ka[1] + ($kb[1] - $ka[1]) * $wb,
                $ka[2] + ($kb[2] - $ka[2]) * $wb,
                $ka[3] + ($kb[3] - $ka[3]) * $wb,
            ];
        }
        return $out;
    }

    /**
     * Source-over of a translucent colour on an opaque backdrop.
     *
     * @param array<int|string, mixed> $c
     * @param array<int|string, mixed> $bd
     * @return array<int|string, mixed>
     */
    private static function composite(array $c, array $bd): array
    {
        $a = (float) $c[3];
        $out = [
            (float) $c[0] * $a + (float) $bd[0] * (1.0 - $a),
            (float) $c[1] * $a + (float) $bd[1] * (1.0 - $a),
            (float) $c[2] * $a + (float) $bd[2] * (1.0 - $a),
            1.0,
        ];
        if (($c['cs'] ?? 'rgb') !== 'rgb') {
            // a print colour stays a print colour: composited in CMYK (an RGB backdrop is converted)
            $kc = ColorOps::cmyk($c);
            $kb = ColorOps::cmyk($bd);
            $out['cs'] = 'cmyk';
            $out['cmyk'] = [
                $kc[0] * $a + $kb[0] * (1.0 - $a),
                $kc[1] * $a + $kb[1] * (1.0 - $a),
                $kc[2] * $a + $kb[2] * (1.0 - $a),
                $kc[3] * $a + $kb[3] * (1.0 - $a),
            ];
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $c
     * @return array<int|string, mixed>
     */
    private static function normalize(array $c): array
    {
        $c[3] = max(0.0, min(1.0, (float) ($c[3] ?? 1.0)));
        return $c;
    }

    /**
     * @param array<int|string, mixed> $c
     * @return array<int|string, mixed>
     */
    private static function opaque(array $c): array
    {
        $c[3] = 1.0;
        return $c;
    }

    /**
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     */
    private static function sameColor(array $a, array $b): bool
    {
        return abs((float) $a[0] - (float) $b[0]) < 1e-4 && abs((float) $a[1] - (float) $b[1]) < 1e-4 && abs((float) $a[2] - (float) $b[2]) < 1e-4
            && ($a['cs'] ?? 'rgb') === ($b['cs'] ?? 'rgb');
    }
}
