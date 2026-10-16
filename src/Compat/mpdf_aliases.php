<?php

declare(strict_types=1);

/**
 * Lazy class aliases so unchanged mPDF code (`use Mpdf\Mpdf; new Mpdf([...]); \Mpdf\HTMLParserMode::HEADER_CSS`) runs on IvoPDF.
 * The aliases are only created when no real `Mpdf\...` class can be autoloaded (registered last, never overrides mPDF).
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Mpdf\Mpdf' => \IvoPdf\IvoPdf::class,
        'Mpdf\HTMLParserMode' => \IvoPdf\Compat\HTMLParserMode::class,
        'Mpdf\Output\Destination' => \IvoPdf\Compat\Destination::class,
    ];
    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
});
