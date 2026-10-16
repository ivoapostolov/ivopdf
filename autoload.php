<?php

declare(strict_types=1);

/**
 * IvoPDF Standalone PSR-4 Autoloader
 * Allows using IvoPDF on shared hosting without requiring Composer CLI.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'IvoPdf\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . "/src/Compat/mpdf_aliases.php"; // lazy Mpdf\Mpdf / Mpdf\HTMLParserMode aliases
