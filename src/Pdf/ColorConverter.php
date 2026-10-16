<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Converts device RGB to device CMYK. Implement it (e.g. with an ICC based transform) and register it with
 * ColorOps::setConverter() to replace the naive default conversion.
 */
interface ColorConverter
{
    /**
     * @param float $r red 0..1
     * @param float $g green 0..1
     * @param float $b blue 0..1
     * @return array{0: float, 1: float, 2: float, 3: float} c, m, y, k in 0..1
     */
    public function rgbToCmyk(float $r, float $g, float $b): array;
}
