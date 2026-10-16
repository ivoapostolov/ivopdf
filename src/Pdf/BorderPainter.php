<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;
use IvoPdf\Layout\LayoutBox;

/**
 * Paints the "complex" border cases: double / groove / ridge / inset / outset styles, mitred corners between
 * differently coloured sides, non-uniform widths combined with border-radius, and CSS outlines.
 *
 * All coordinates passed in are document coordinates (pt, origin top-left, y down); output is PDF content.
 */
final class BorderPainter
{
    private const K = 0.5522847498;

    public function __construct(private PdfDocument $pdf, private float $pageHeight)
    {
    }

    /**
     * Decides whether this painter must handle the box's border; returns null when the plain renderer is good enough.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    public function paintIfComplex(LayoutBox $box, float $x, float $y, float $w, float $h, array $radii): ?string
    {
        $widths = [$box->borderTop, $box->borderRight, $box->borderBottom, $box->borderLeft];
        $sides = ['top', 'right', 'bottom', 'left'];
        $styles = [];
        $colors = [];
        foreach ($sides as $i => $side) {
            $styles[$i] = strtolower(trim($box->style->get("border-{$side}-style", 'solid')));
            $colors[$i] = CssColor::parse($box->style->get("border-{$side}-color", '#000000'));
        }
        $hasRadius = ($radii[0] + $radii[1] + $radii[2] + $radii[3]) > 0.2;
        $visible = [];
        foreach ($widths as $i => $wv) {
            if ($wv > 0.05) {
                $visible[] = $i;
            }
        }
        if ($visible === []) {
            return null;
        }
        $f = $visible[0];
        $complexStyle = false;
        $uniform = count($visible) === 4;
        $uniformColor = true;
        foreach ($visible as $i) {
            if (in_array($styles[$i], ['double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $complexStyle = true;
            }
            if (abs($widths[$i] - $widths[$f]) > 0.05 || $styles[$i] !== $styles[$f]) {
                $uniform = false;
            }
            if ($colors[$i] !== $colors[$f]) {
                $uniformColor = false;
                $uniform = false;
            }
        }
        $needs = $complexStyle
            || ($hasRadius && !$uniform)
            || (!$hasRadius && !$uniformColor && count($visible) > 1 && $this->allSolid($styles, $widths));
        if (!$needs) {
            return null;
        }
        return $this->paint($x, $y, $w, $h, $radii, $widths, $styles, $colors);
    }

    /**
     * @param list<string> $styles
     * @param list<float> $widths
     */
    private function allSolid(array $styles, array $widths): bool
    {
        foreach ($styles as $i => $s) {
            if ($widths[$i] > 0.05 && $s !== 'solid') {
                return false;
            }
        }
        return true;
    }

    /**
     * Paints the four border sides.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii [tl, tr, br, bl]
     * @param list<float> $widths [top, right, bottom, left]
     * @param list<string> $styles
     * @param list<array{0: float, 1: float, 2: float, 3: float}|null> $colors
     */
    public function paint(float $x, float $y, float $w, float $h, array $radii, array $widths, array $styles, array $colors): string
    {
        $out = '';
        $active = [];
        for ($i = 0; $i < 4; $i++) {
            $st = $styles[$i];
            if ($widths[$i] > 0.05 && !in_array($st, ['none', 'hidden', ''], true) && $colors[$i] !== null) {
                $active[] = $i;
            }
        }
        if ($active === []) {
            return '';
        }

        // Fast path: four identical solid sides -> one ring, no wedge clipping (no seams, no double alpha)
        if (count($active) === 4 && $this->identicalSolid($widths, $styles, $colors)) {
            return $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.0, 1.0, $colors[0]);
        }

        $wedges = $this->wedges($x, $y, $w, $h, $widths);
        foreach ($active as $i) {
            $style = $styles[$i];
            $color = $colors[$i];
            $out .= "q\n" . $this->polygonPath($wedges[$i]) . "W n\n";
            switch ($style) {
                case 'double':
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.0, 1.0 / 3.0, $color);
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 2.0 / 3.0, 1.0, $color);
                    break;
                case 'groove':
                case 'ridge':
                    $topLeft = $i === 0 || $i === 3;
                    $dark = $this->shade($color, true);
                    $light = $this->shade($color, false);
                    // groove: outer half dark on top/left, light on bottom/right; ridge is the reverse
                    $outerDark = ($style === 'groove') === $topLeft;
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.0, 0.5, $outerDark ? $dark : $light);
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.5, 1.0, $outerDark ? $light : $dark);
                    break;
                case 'inset':
                case 'outset':
                    $topLeft = $i === 0 || $i === 3;
                    $useDark = ($style === 'inset') === $topLeft;
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.0, 1.0, $this->shade($color, $useDark));
                    break;
                case 'dashed':
                case 'dotted':
                    $out .= $this->strokeSide($x, $y, $w, $h, $radii, $widths, $i, $style, $color);
                    break;
                default:
                    $out .= $this->fillRing($x, $y, $w, $h, $radii, $widths, 0.0, 1.0, $color);
                    break;
            }
            $out .= "Q\n";
        }
        return $out;
    }

    /**
     * Paints a CSS outline around the border box (never affects layout).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    public function paintOutline(LayoutBox $box, float $x, float $y, float $w, float $h, array $radii): string
    {
        $style = strtolower(trim($box->style->get('outline-style', 'none')));
        if ($style === 'none' || $style === 'hidden' || $style === '') {
            return '';
        }
        if ($style === 'auto') {
            $style = 'solid';
        }
        $ow = CssUnit::toPt($box->style->get('outline-width', 'medium'), 0.0, $box->fontSizePt);
        $off = CssUnit::toPt($box->style->get('outline-offset', '0'), 0.0, $box->fontSizePt);
        $color = CssColor::parse($box->style->get('outline-color', '#000000'));
        if ($ow <= 0.05 || $color === null) {
            return '';
        }
        $grow = $off + $ow;
        $ox = $x - $grow;
        $oy = $y - $grow;
        $oW = $w + 2.0 * $grow;
        $oH = $h + 2.0 * $grow;
        if ($oW <= 0.1 || $oH <= 0.1) {
            return '';
        }
        $r = [];
        foreach ($radii as $rv) {
            $r[] = $rv > 0.1 ? max(0.0, $rv + $grow) : 0.0;
        }
        /** @var array{0: float, 1: float, 2: float, 3: float} $r */
        return $this->paint($ox, $oy, $oW, $oH, $r, [$ow, $ow, $ow, $ow], [$style, $style, $style, $style], [$color, $color, $color, $color]);
    }

    /**
     * @param list<float> $widths
     * @param list<string> $styles
     * @param list<array{0: float, 1: float, 2: float, 3: float}|null> $colors
     */
    private function identicalSolid(array $widths, array $styles, array $colors): bool
    {
        for ($i = 1; $i < 4; $i++) {
            if (abs($widths[$i] - $widths[0]) > 0.05 || $styles[$i] !== $styles[0] || $colors[$i] !== $colors[0]) {
                return false;
            }
        }
        return $styles[0] === 'solid';
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $c
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function shade(array $c, bool $dark): array
    {
        $v = max($c[0], $c[1], $c[2]);
        if ($dark) {
            $m = $v > 0.001 ? max(0.0, ($v - 0.33) / $v) : 0.0;
            return [$c[0] * $m, $c[1] * $m, $c[2] * $m, $c[3]];
        }
        if ($v < 0.05) {
            return [0.33, 0.33, 0.33, $c[3]];
        }
        return $c;
    }

    /**
     * Four wedge polygons (diagonal joins at the corners) in document coordinates.
     *
     * @param list<float> $widths
     * @return list<list<array{0: float, 1: float}>>
     */
    private function wedges(float $x, float $y, float $w, float $h, array $widths): array
    {
        $ix0 = min($x + $widths[3], $x + $w / 2.0);
        $iy0 = min($y + $widths[0], $y + $h / 2.0);
        $ix1 = max($x + $w - $widths[1], $x + $w / 2.0);
        $iy1 = max($y + $h - $widths[2], $y + $h / 2.0);
        return [
            [[$x, $y], [$x + $w, $y], [$ix1, $iy0], [$ix0, $iy0]],
            [[$x + $w, $y], [$x + $w, $y + $h], [$ix1, $iy1], [$ix1, $iy0]],
            [[$x + $w, $y + $h], [$x, $y + $h], [$ix0, $iy1], [$ix1, $iy1]],
            [[$x, $y + $h], [$x, $y], [$ix0, $iy0], [$ix0, $iy1]],
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $pts
     */
    private function polygonPath(array $pts): string
    {
        $s = '';
        foreach ($pts as $i => [$px, $py]) {
            $s .= sprintf("%.2F %.2F %s\n", $px, $this->pageHeight - $py, $i === 0 ? 'm' : 'l');
        }
        return $s . "h\n";
    }

    /**
     * Fills the band between the box inset by $fromFrac*width and by $toFrac*width (per-side widths).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param list<float> $widths
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function fillRing(float $x, float $y, float $w, float $h, array $radii, array $widths, float $fromFrac, float $toFrac, array $color): string
    {
        $outer = $this->insetPath($x, $y, $w, $h, $radii, $widths, $fromFrac);
        $inner = $this->insetPath($x, $y, $w, $h, $radii, $widths, $toFrac);
        if ($outer === '') {
            return '';
        }
        $out = "q\n";
        if ($color[3] < 0.999) {
            $out .= '/' . $this->pdf->registerAlphaState($color[3]) . " gs\n";
        }
        $out .= ColorOps::fillOp($color) . "\n";
        $out .= $outer . $inner . "f*\nQ\n";
        return $out;
    }

    /**
     * Rounded-rect path of the box inset by frac * (per-side width); radii shrink elliptically.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param list<float> $widths
     */
    private function insetPath(float $x, float $y, float $w, float $h, array $radii, array $widths, float $frac): string
    {
        $et = $widths[0] * $frac;
        $er = $widths[1] * $frac;
        $eb = $widths[2] * $frac;
        $el = $widths[3] * $frac;
        $x0 = $x + $el;
        $y0 = $y + $et;
        $x1 = $x + $w - $er;
        $y1 = $y + $h - $eb;
        if ($x1 - $x0 < 0.01 || $y1 - $y0 < 0.01) {
            return '';
        }
        $rx = [max(0.0, $radii[0] - $el), max(0.0, $radii[1] - $er), max(0.0, $radii[2] - $er), max(0.0, $radii[3] - $el)];
        $ry = [max(0.0, $radii[0] - $et), max(0.0, $radii[1] - $et), max(0.0, $radii[2] - $eb), max(0.0, $radii[3] - $eb)];
        return $this->ellipticalRect($x0, $y0, $x1, $y1, $rx, $ry);
    }

    /**
     * @param list<float> $rx tl,tr,br,bl
     * @param list<float> $ry tl,tr,br,bl
     */
    private function ellipticalRect(float $x0, float $y0, float $x1, float $y1, array $rx, array $ry): string
    {
        $H = $this->pageHeight;
        $k = self::K;
        $p = static fn(float $a, float $b): string => sprintf('%.2F %.2F', $a, $b);
        $s = $p($x0 + $rx[0], $H - $y0) . " m\n";
        $s .= $p($x1 - $rx[1], $H - $y0) . " l\n";
        if ($rx[1] > 0.01 && $ry[1] > 0.01) {
            $s .= $p($x1 - $rx[1] + $k * $rx[1], $H - $y0) . ' ' . $p($x1, $H - ($y0 + $ry[1] - $k * $ry[1])) . ' ' . $p($x1, $H - ($y0 + $ry[1])) . " c\n";
        } else {
            $s .= $p($x1, $H - $y0) . " l\n";
        }
        $s .= $p($x1, $H - ($y1 - $ry[2])) . " l\n";
        if ($rx[2] > 0.01 && $ry[2] > 0.01) {
            $s .= $p($x1, $H - ($y1 - $ry[2] + $k * $ry[2])) . ' ' . $p($x1 - $rx[2] + $k * $rx[2], $H - $y1) . ' ' . $p($x1 - $rx[2], $H - $y1) . " c\n";
        } else {
            $s .= $p($x1, $H - $y1) . " l\n";
        }
        $s .= $p($x0 + $rx[3], $H - $y1) . " l\n";
        if ($rx[3] > 0.01 && $ry[3] > 0.01) {
            $s .= $p($x0 + $rx[3] - $k * $rx[3], $H - $y1) . ' ' . $p($x0, $H - ($y1 - $ry[3] + $k * $ry[3])) . ' ' . $p($x0, $H - ($y1 - $ry[3])) . " c\n";
        } else {
            $s .= $p($x0, $H - $y1) . " l\n";
        }
        $s .= $p($x0, $H - ($y0 + $ry[0])) . " l\n";
        if ($rx[0] > 0.01 && $ry[0] > 0.01) {
            $s .= $p($x0, $H - ($y0 + $ry[0] - $k * $ry[0])) . ' ' . $p($x0 + $rx[0] - $k * $rx[0], $H - $y0) . ' ' . $p($x0 + $rx[0], $H - $y0) . " c\n";
        } else {
            $s .= $p($x0, $H - $y0) . " l\n";
        }
        return $s . "h\n";
    }

    /**
     * Strokes the centre line of one side (dashed / dotted) with its own width, to be clipped by the caller.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param list<float> $widths
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function strokeSide(float $x, float $y, float $w, float $h, array $radii, array $widths, int $side, string $style, array $color): string
    {
        $lw = $widths[$side];
        $mid = [$lw / 2.0, $lw / 2.0, $lw / 2.0, $lw / 2.0];
        // Centre line: inset each edge by half of its own width (so the line sits inside this side's band)
        $x0 = $x + $widths[3] / 2.0;
        $y0 = $y + $widths[0] / 2.0;
        $x1 = $x + $w - $widths[1] / 2.0;
        $y1 = $y + $h - $widths[2] / 2.0;
        if ($x1 - $x0 < 0.01 || $y1 - $y0 < 0.01) {
            return '';
        }
        $rx = [max(0.0, $radii[0] - $widths[3] / 2.0), max(0.0, $radii[1] - $widths[1] / 2.0), max(0.0, $radii[2] - $widths[1] / 2.0), max(0.0, $radii[3] - $widths[3] / 2.0)];
        $ry = [max(0.0, $radii[0] - $widths[0] / 2.0), max(0.0, $radii[1] - $widths[0] / 2.0), max(0.0, $radii[2] - $widths[2] / 2.0), max(0.0, $radii[3] - $widths[2] / 2.0)];
        unset($mid);
        $out = "q\n";
        if ($color[3] < 0.999) {
            $out .= '/' . $this->pdf->registerAlphaState($color[3]) . " gs\n";
        }
        if ($style === 'dotted') {
            $out .= sprintf("1 J [0 %.2F] 0 d\n", max(1.0, $lw * 2.0));
        } else {
            $out .= sprintf("[%.2F %.2F] 0 d\n", max(2.0, $lw * 3.0), max(1.5, $lw * 2.0));
        }
        $out .= sprintf("%.2F w %s\n", $lw, ColorOps::strokeOp($color));
        $out .= $this->ellipticalRect($x0, $y0, $x1, $y1, $rx, $ry) . "S\nQ\n";
        return $out;
    }
}
