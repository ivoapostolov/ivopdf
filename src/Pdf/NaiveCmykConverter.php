<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Textbook RGB to CMYK conversion (k = 1 - max(r, g, b)); no gamut mapping or ink limiting, so colors are only
 * approximate. Neutral grays and black become pure K.
 */
final class NaiveCmykConverter implements ColorConverter
{
    public function rgbToCmyk(float $r, float $g, float $b): array
    {
        $k = 1.0 - max($r, $g, $b);
        if ($k >= 0.9999) {
            return [0.0, 0.0, 0.0, 1.0];
        }
        $d = 1.0 - $k;
        $c = ($d - $r) / $d;
        $m = ($d - $g) / $d;
        $y = ($d - $b) / $d;
        if (abs($c - $m) < 0.002 && abs($m - $y) < 0.002) {
            // neutral gray: K only, no CMY
            return [0.0, 0.0, 0.0, 1.0 - ($r + $g + $b) / 3.0];
        }
        return [max(0.0, $c), max(0.0, $m), max(0.0, $y), $k];
    }
}
