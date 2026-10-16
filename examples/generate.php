<?php

declare(strict_types=1);

/**
 * Renders every showcase template in every supported language to PDF.
 *
 * Usage: php examples/generate.php [outDir]   (default: examples/output)
 * Output files: {template}-{lang}.pdf, e.g. invoice-en.pdf
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

require __DIR__ . '/../autoload.php';
require_once __DIR__ . '/Templates.php';

use IvoPdf\Examples\Templates;
use IvoPdf\IvoPdf;

$outDir = $argv[1] ?? __DIR__ . '/output';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: {$outDir}\n");
    exit(1);
}
$outDir = rtrim((string) realpath($outDir), '/\\');

$root = dirname(__DIR__);
$failures = 0;
$count = 0;
$tStart = microtime(true);

foreach (array_keys(Templates::languages()) as $lang) {
    foreach (Templates::all($lang) as $key => $tpl) {
        $file = $outDir . DIRECTORY_SEPARATOR . "{$key}-{$lang}.pdf";
        $count++;
        try {
            $t0 = microtime(true);
            $pdf = new IvoPdf([
                'paperSize' => $tpl['paper'],
                'orientation' => $tpl['orientation'],
                'isRemoteEnabled' => false,
                'allowedPaths' => [
                    $root . '/examples',
                    $root . '/resources',
                ],
            ]);
            if (trim($tpl['footer']) !== '') {
                $pdf->setFooterHtml($tpl['footer']);
            }
            $pdf->loadHtml($tpl['html'])->save($file);
            $ms = (microtime(true) - $t0) * 1000;
            printf(
                "  OK    %-22s %2d page(s)  %8.1f KB  %8.1f ms\n",
                basename($file),
                $pdf->getPageCount(),
                filesize($file) / 1024,
                $ms
            );
        } catch (\Throwable $e) {
            $failures++;
            printf("  FAIL  %-22s %s: %s (%s:%d)\n", basename($file), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }
}

printf(
    "\n%d/%d PDF(s) written to %s in %.1f ms\n",
    $count - $failures,
    $count,
    $outDir,
    (microtime(true) - $tStart) * 1000
);

exit($failures > 0 ? 1 : 0);
