<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Layout\LayoutBox;

/**
 * Print appearance of the controls browsers draw themselves: `<input type="range">` (track, filled part and thumb),
 * `<progress>` and `<meter>` (track and value bar), with Edge's (Chromium Fluent) colours and proportions. They are
 * static page content (no AcroForm field). Colours go through ColorOps, so CMYK / PDF/X output stays consistent.
 */
final class FormGauges
{
    private const TRACK = [0.937, 0.937, 0.937];
    private const OUTLINE = [0.463, 0.463, 0.463];
    private const ACCENT = [0.0, 0.459, 1.0];
    private const OPTIMUM = [0.063, 0.486, 0.063];
    private const SUBOPTIMUM = [1.0, 0.725, 0.0];
    private const EVEN_LESS_GOOD = [0.820, 0.204, 0.220];

    /**
     * <input type="range">: $x / $bottom / $w / $h is the content box in PDF coordinates (y up).
     */
    public static function slider(LayoutBox $box, float $x, float $bottom, float $w, float $h): string
    {
        if ($w < 2.0 || $h < 1.0) {
            return '';
        }
        $el = $box->domElement;
        $min = self::num($el?->getAttribute('min'), 0.0);
        $max = self::num($el?->getAttribute('max'), 100.0);
        if ($max < $min) {
            $max = $min;
        }
        $value = $box->formControl !== null && $box->formControl->value !== '' ? self::num($box->formControl->value, ($min + $max) / 2) : ($max < $min ? $min : $min + ($max - $min) / 2);
        $f = $max > $min ? max(0.0, min(1.0, ($value - $min) / ($max - $min))) : 0.0;

        $thumb = min($h, 11.25);
        $trackH = min($h, 6.0);
        $cy = $bottom + $h / 2;
        $tx = $x + $thumb / 2 - 0.5; // the thumb's centre travels over the track inside its own half width
        $tw = max(1.0, $w - $thumb + 1.0);
        $cx = $tx + $f * $tw;
        $ty = $cy - $trackH / 2;
        $out = "q\n";
        $out .= self::fill(self::TRACK) . self::roundRect($x, $ty, $w, $trackH, $trackH / 2) . "f\n";
        $out .= "q\n" . self::roundRect($x, $ty, $w, $trackH, $trackH / 2) . "W n\n" . self::fill(self::ACCENT)
            . sprintf("%.3F %.3F %.3F %.3F re f\nQ\n", $x, $ty, max(0.0, $cx - $x), $trackH);
        $out .= self::stroke(self::OUTLINE) . "0.75 w\n" . self::roundRect($x + 0.375, $ty + 0.375, $w - 0.75, $trackH - 0.75, ($trackH - 0.75) / 2) . "S\n";
        $out .= self::fill(self::ACCENT) . self::circle($cx, $cy, $thumb / 2) . "f\n";
        return $out . "Q\n";
    }

    /**
     * <progress> / <meter> box (border box in PDF coordinates, y up); '' for other boxes.
     */
    public static function gauge(LayoutBox $box, float $x, float $bottom, float $w, float $h): string
    {
        if ($w < 2.0 || $h < 1.0) {
            return '';
        }
        $el = $box->domElement;
        if ($box->tagName === 'progress') {
            $max = self::num($el?->getAttribute('max'), 1.0);
            $max = $max > 0 ? $max : 1.0;
            $hasValue = $el !== null && $el->hasAttribute('value');
            $f = $hasValue ? max(0.0, min(1.0, self::num($el->getAttribute('value'), 0.0) / $max)) : 0.0;
            $barH = min($h, max(2.0, $h * 0.48));
            $y = $bottom + ($h - $barH) / 2;
            $r = $barH / 2;
            $out = "q\n" . self::fill(self::TRACK) . self::roundRect($x, $y, $w, $barH, $r) . "f\n";
            if ($f > 0.0) {
                $out .= "q\n" . self::roundRect($x, $y, $w, $barH, $r) . "W n\n" . self::fill(self::ACCENT)
                    . sprintf("%.3F %.3F %.3F %.3F re f\nQ\n", $x, $y, $w * $f, $barH);
            }
            $out .= self::stroke(self::OUTLINE) . "0.75 w\n" . self::roundRect($x + 0.375, $y + 0.375, $w - 0.75, $barH - 0.75, max(0.0, $r - 0.375)) . "S\n";
            return $out . "Q\n";
        }
        if ($box->tagName !== 'meter') {
            return '';
        }
        // HTML: min 0, max 1, value clamped to [min, max]; low / high / optimum decide the colour
        $min = self::num($el?->getAttribute('min'), 0.0);
        $max = self::num($el?->getAttribute('max'), 1.0);
        if ($max < $min) {
            $max = $min;
        }
        $value = max($min, min($max, self::num($el?->getAttribute('value'), 0.0)));
        $low = max($min, min($max, self::num($el?->getAttribute('low'), $min)));
        $high = max($low, min($max, self::num($el?->getAttribute('high'), $max)));
        $optimum = max($min, min($max, self::num($el?->getAttribute('optimum'), ($min + $max) / 2)));
        $region = static fn(float $v): int => $v < $low ? 0 : ($v > $high ? 2 : 1);
        $distance = abs($region($value) - $region($optimum));
        $color = $distance === 0 ? self::OPTIMUM : ($distance === 1 ? self::SUBOPTIMUM : self::EVEN_LESS_GOOD);
        $f = $max > $min ? ($value - $min) / ($max - $min) : 0.0;
        $barH = min($h, max(2.0, $h * 0.55));
        $y = $bottom + ($h - $barH) / 2;
        $r = min(1.5, $barH / 2);
        $out = "q\n" . self::fill(self::TRACK) . self::roundRect($x, $y, $w, $barH, $r) . "f\n";
        if ($f > 0.0) {
            $inset = 0.8;
            $out .= self::fill($color) . sprintf("%.3F %.3F %.3F %.3F re f\n", $x + $inset, $y + $inset, max(0.0, ($w - 2 * $inset) * $f), max(0.0, $barH - 2 * $inset));
        }
        $out .= self::stroke(self::OUTLINE) . "0.75 w\n" . self::roundRect($x + 0.375, $y + 0.375, $w - 0.75, $barH - 0.75, max(0.0, $r - 0.375)) . "S\n";
        return $out . "Q\n";
    }

    private static function num(?string $v, float $default): float
    {
        $v = trim((string) $v);
        return is_numeric($v) && is_finite((float) $v) ? (float) $v : $default;
    }

    /** @param list<float> $rgb */
    private static function fill(array $rgb): string
    {
        return ColorOps::fillOp([$rgb[0], $rgb[1], $rgb[2], 1.0]) . "\n";
    }

    /** @param list<float> $rgb */
    private static function stroke(array $rgb): string
    {
        return ColorOps::strokeOp([$rgb[0], $rgb[1], $rgb[2], 1.0]) . "\n";
    }

    private static function roundRect(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        if ($r < 0.01) {
            return sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $w, $h);
        }
        $k = 0.5523 * $r;
        return sprintf("%.3F %.3F m\n", $x + $r, $y)
            . sprintf("%.3F %.3F l\n", $x + $w - $r, $y)
            . sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n", $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r)
            . sprintf("%.3F %.3F l\n", $x + $w, $y + $h - $r)
            . sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n", $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h)
            . sprintf("%.3F %.3F l\n", $x + $r, $y + $h)
            . sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n", $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r)
            . sprintf("%.3F %.3F l\n", $x, $y + $r)
            . sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\nh\n", $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
    }

    private static function circle(float $cx, float $cy, float $r): string
    {
        return self::roundRect($cx - $r, $cy - $r, 2 * $r, 2 * $r, $r);
    }
}
