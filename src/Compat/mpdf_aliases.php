<?php

declare(strict_types=1);

/**
 * Lazy class aliases so unchanged mPDF / dompdf code (`use Mpdf\Mpdf; new Mpdf([...]); \Mpdf\HTMLParserMode::HEADER_CSS`,
 * `use Dompdf\Dompdf; use Dompdf\Options;`) runs on IvoPDF.
 * The aliases are only created when no real `Mpdf\...` / `Dompdf\...` class can be autoloaded (registered last, never
 * overrides the real libraries).
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Mpdf\Mpdf' => \IvoPdf\Compat\Mpdf::class, // IvoPdf with mPDF's defaults (font, Output())
        'Mpdf\HTMLParserMode' => \IvoPdf\Compat\HTMLParserMode::class,
        'Mpdf\Output\Destination' => \IvoPdf\Compat\Destination::class,
        'Dompdf\Dompdf' => \IvoPdf\Compat\Dompdf\Dompdf::class,
        'Dompdf\Options' => \IvoPdf\Compat\Dompdf\Options::class,
        'Dompdf\Canvas' => \IvoPdf\Compat\Dompdf\Canvas::class,
        'Dompdf\Adapter\CPDF' => \IvoPdf\Compat\Dompdf\Canvas::class,
        'Dompdf\FontMetrics' => \IvoPdf\Compat\Dompdf\FontMetrics::class,
    ];
    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
});
