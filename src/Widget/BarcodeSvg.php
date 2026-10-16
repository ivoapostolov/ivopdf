<?php

declare(strict_types=1);

namespace IvoPdf\Widget;

/**
 * Backward-compatible Code 128 wrapper; see {@see Barcode} for all symbologies (EAN, QR, Data Matrix, ...).
 */
class BarcodeSvg
{
    /**
     * Generates a vector <svg> Code 128 barcode (automatic A/B/C subset switching) for ASCII strings.
     * Returns '' for values that cannot be encoded.
     */
    public static function code128(
        string $text,
        int $width = 180,
        int $height = 48,
        string $color = '#0f172a',
        bool $showText = true
    ): string {
        return Barcode::svg('C128', $text, [
            'width' => $width,
            'height' => $height,
            'color' => $color,
            'text' => $showText,
        ]);
    }
}
