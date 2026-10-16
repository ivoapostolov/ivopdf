<?php

declare(strict_types=1);

/**
 * Renders an HTML file to PDF with IvoPDF and prints pages, size, time and warnings.
 *
 * Usage: php render.php input.html output.pdf [--paper=A4] [--landscape] [--footer="Page {PAGE_NUM} of {PAGE_COUNT}"]
 *                                             [--header="..."] [--autoload=path/to/autoload.php] [--remote]
 */

$args = array_slice($argv, 1);
$opts = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}
if (count($positional) < 2) {
    fwrite(STDERR, "Usage: php render.php input.html output.pdf [--paper=A4] [--landscape] [--footer=HTML] [--header=HTML] [--autoload=PATH] [--remote]\n");
    exit(2);
}
[$input, $output] = $positional;

// Locate the IvoPDF autoloader: explicit option, Composer vendor dir, or the repository this skill ships in
$candidates = array_filter([
    is_string($opts['autoload'] ?? null) ? $opts['autoload'] : null,
    getcwd() . '/vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
]);
$loaded = false;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $loaded = class_exists(\IvoPdf\IvoPdf::class);
        if ($loaded) {
            break;
        }
    }
}
if (!$loaded) {
    fwrite(STDERR, "IvoPDF not found. Run `composer require ivopdf/ivopdf` or pass --autoload=/path/to/ivopdf/autoload.php\n");
    exit(2);
}

$inputReal = realpath($input);
if ($inputReal === false || !is_file($inputReal)) {
    fwrite(STDERR, "Input file not found: {$input}\n");
    exit(2);
}

$pdf = new \IvoPdf\IvoPdf([
    'paperSize' => is_string($opts['paper'] ?? null) ? $opts['paper'] : 'A4',
    'orientation' => isset($opts['landscape']) ? 'landscape' : 'portrait',
    'basePath' => dirname($inputReal),
    'isRemoteEnabled' => isset($opts['remote']),
]);
if (is_string($opts['header'] ?? null)) {
    $pdf->setHeaderHtml($opts['header']);
}
if (is_string($opts['footer'] ?? null)) {
    $pdf->setFooterHtml($opts['footer']);
}

$t0 = microtime(true);
try {
    $pdf->loadHtmlFile($inputReal)->save($output);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Render failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$ms = (microtime(true) - $t0) * 1000;

printf("OK  %s  pages=%d  size=%.1f KB  time=%.0f ms\n", $output, $pdf->getPageCount(), filesize($output) / 1024, $ms);
foreach ($pdf->getWarnings() as $w) {
    echo "WARNING: {$w}\n";
}
