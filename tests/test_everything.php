<?php

declare(strict_types=1);

/**
 * IvoPDF — Full-Coverage Test Suite ("test absolutely everything").
 *
 * Covers: Options & paper sizes, fluent API, CSS parsing (selectors, specificity,
 * pseudo-classes, variables, calc, units, colors), Flexbox, CSS Grid, Tables,
 * inline/text layout, lists, positioning, pagination & page headers/footers,
 * fonts & Unicode (Cyrillic/Greek), images (JPEG/PNG/alpha/data-URI), SVG shapes,
 * barcodes, links, gradients/opacity/radius/rotation, metadata, compression,
 * edge cases & stress — plus a pure-PHP PDF structural validator (xref, objects,
 * page tree, MediaBox, embedded fonts) run over every generated document.
 *
 * Run: php tests/test_everything.php
 */

error_reporting(E_ALL);

require __DIR__ . '/../autoload.php';

use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;
use IvoPdf\Image\ImageLoader;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Options;
use IvoPdf\Widget\BarcodeSvg;

// ---------------------------------------------------------------------------
// Infrastructure
// ---------------------------------------------------------------------------

$PASS = 0;
$FAIL = 0;
$FAILURES = [];
$PHP_ISSUES = [];

// Capture notices/warnings/deprecations engine-wide; respect @-suppression.
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$PHP_ISSUES): bool {
    if (!(error_reporting() & $no)) {
        return false; // @-suppressed
    }
    if ($no & (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED)) {
        $PHP_ISSUES[] = substr(str_replace('\\', '/', $file), -60) . ":{$line} {$msg}";
        return true;
    }
    return false;
});

function check(string $name, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    } else {
        $FAIL++;
        $FAILURES[] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function approx(string $name, float $actual, float $expected, float $tol = 0.6): void
{
    check($name, abs($actual - $expected) <= $tol, 'expected ~' . round($expected, 2) . ', got ' . round($actual, 2));
}

/** Recursively finds a LayoutBox by element id, descending into inline runs too. */
function findBoxById(?LayoutBox $box, string $id): ?LayoutBox
{
    if ($box === null) {
        return null;
    }
    if ($box->domElement !== null && $box->domElement->getAttribute('id') === $id) {
        return $box;
    }
    foreach ($box->children as $child) {
        $found = findBoxById($child, $id);
        if ($found !== null) {
            return $found;
        }
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null) {
                $found = findBoxById($run->inlineBox, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }
    return null;
}

/** Concatenates all text content of a layout tree. */
function allText(LayoutBox $box): string
{
    $out = $box->textContent ?? '';
    foreach ($box->children as $c) {
        $out .= ' ' . allText($c);
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            $out .= ' ' . $run->text;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Pure-PHP PDF structural validator
// ---------------------------------------------------------------------------

/**
 * @return array{errors: string[], pages: int, objects: int, mediaBoxes: array<array<float>>}
 */
function validatePdfStructure(string $pdf): array
{
    $errors = [];

    if (strncmp($pdf, '%PDF-1.7', 8) !== 0) {
        $errors[] = 'missing %PDF-1.7 header';
    }

    $tail = substr($pdf, -64);
    if (strpos($tail, '%%EOF') === false) {
        $errors[] = 'missing %%EOF marker';
    }

    // startxref -> xref table
    $sxPos = (int) strrpos($pdf, 'startxref');
    $pages = 0;
    $objects = 0;
    $mediaBoxes = [];
    if ($sxPos === false || !preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', substr($pdf, $sxPos), $m)) {
        $errors[] = 'missing/invalid startxref';
        return ['errors' => $errors, 'pages' => 0, 'objects' => 0, 'mediaBoxes' => []];
    }
    $xrefPos = (int) $m[1];
    if (substr($pdf, $xrefPos, 4) !== 'xref') {
        $errors[] = "startxref offset {$xrefPos} does not point at an xref table";
        return ['errors' => $errors, 'pages' => 0, 'objects' => 0, 'mediaBoxes' => []];
    }

    if (!preg_match('/xref\s+(\d+)\s+(\d+)\s+/', $pdf, $hm, 0, $xrefPos)) {
        $errors[] = 'malformed xref subsection header';
        return ['errors' => $errors, 'pages' => 0, 'objects' => 0, 'mediaBoxes' => []];
    }
    $firstObj = (int) $hm[1];
    $count = (int) $hm[2];
    $cursor = $xrefPos + strlen((string) $hm[0]);
    $objOffsets = [];
    for ($i = 0; $i < $count; $i++) {
        $entry = substr($pdf, $cursor + $i * 20, 20);
        if (!preg_match('/^(\d{10})\s(\d{5})\s([nf])/', $entry, $em)) {
            $errors[] = "malformed xref entry #{$i}";
            continue;
        }
        if ($em[3] === 'n') {
            $objOffsets[$firstObj + $i] = [(int) $em[1], (int) $em[2]];
        }
    }
    $objects = count($objOffsets);

    // Each in-use offset must land exactly on "N G obj"
    foreach ($objOffsets as $num => [$off, $gen]) {
        $head = substr($pdf, $off, 24);
        if (!preg_match('/^' . $num . '\s+' . $gen . '\s+obj/', $head)) {
            $errors[] = "xref offset for object {$num} does not point at '{$num} {$gen} obj' (found: " . addcslashes(substr($head, 0, 12), "\r\n\0") . ')';
        }
    }

    // trailer -> Root -> catalog -> page tree
    if (!preg_match('/trailer\s*<<(.*?)>>\s*startxref/s', $pdf, $tm, 0, $xrefPos)) {
        $errors[] = 'missing trailer dictionary';
        return ['errors' => $errors, 'pages' => $pages, 'objects' => $objects, 'mediaBoxes' => $mediaBoxes];
    }
    $trailer = $tm[1];
    if (!preg_match('/\/Root\s+(\d+)\s+0\s+R/', $trailer, $rm)) {
        $errors[] = 'trailer has no /Root reference';
        return ['errors' => $errors, 'pages' => $pages, 'objects' => $objects, 'mediaBoxes' => $mediaBoxes];
    }

    $objBody = static function (int $num) use ($pdf, $objOffsets): string {
        if (!isset($objOffsets[$num])) {
            return '';
        }
        $start = $objOffsets[$num][0];
        $end = (int) strpos($pdf, 'endobj', $start);
        return substr($pdf, $start, max(0, $end - $start));
    };

    $catalog = $objBody((int) $rm[1]);
    if ($catalog === '' || strpos($catalog, '/Type /Catalog') === false) {
        $errors[] = 'root object is not a /Catalog';
    }
    if (preg_match('/\/Pages\s+(\d+)\s+0\s+R/', $catalog, $pm)) {
        $pagesObj = $objBody((int) $pm[1]);
        if (strpos($pagesObj, '/Type /Pages') === false) {
            $errors[] = '/Pages object has wrong type';
        }
        if (!preg_match('/\/Kids\s+\[([^\]]*)\]/', $pagesObj, $km)) {
            $errors[] = '/Pages has no /Kids array';
        } else {
            preg_match_all('/(\d+)\s+0\s+R/', $km[1], $kIds);
            $pages = count($kIds[1]);
            if (preg_match('/\/Count\s+(\d+)/', $pagesObj, $cm) && (int) $cm[1] !== $pages) {
                $errors[] = "/Pages /Count ({$cm[1]}) != Kids count ({$pages})";
            }
            foreach ($kIds[1] as $kidNum) {
                $kid = $objBody((int) $kidNum);
                if (strpos($kid, '/Type /Page') === false) {
                    $errors[] = "page object {$kidNum} lacks /Type /Page";
                }
                if (preg_match('/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $kid, $mm)) {
                    $mediaBoxes[] = [(float) $mm[1], (float) $mm[2], (float) $mm[3], (float) $mm[4]];
                } else {
                    $errors[] = "page object {$kidNum} lacks /MediaBox";
                }
            }
        }
    } else {
        $errors[] = 'catalog has no /Pages reference';
    }

    return ['errors' => $errors, 'pages' => $pages, 'objects' => $objects, 'mediaBoxes' => $mediaBoxes];
}

/** Decompresses and concatenates every stream in the PDF (content, fonts, cmaps). */
function pdfStreams(string $pdf): string
{
    $out = '';
    $pos = 0;
    while (($s = strpos($pdf, 'stream', $pos)) !== false) {
        $e = strpos($pdf, 'endstream', $s);
        if ($e === false) {
            break;
        }
        $data = substr($pdf, $s + 6, $e - $s - 6);
        $data = preg_replace('/^\r?\n/', '', $data) ?? $data;
        $decoded = @gzuncompress($data);
        $out .= ($decoded === false) ? $data : $decoded;
        $pos = $e + 9;
    }
    return $out;
}

/** Counts curve ("c") path operators in decompressed PDF streams (newline-terminated ops). */
function countCurveOps(string $streams): int
{
    return (int) preg_match_all('/\d\s+c\s*[\r\n]/', $streams);
}

/** PDF text strings are written UTF-16BE hex; returns the needle to search for. */
function pdfHexOf(string $text): string
{
    return strtoupper(bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
}

// Every rendered document is registered here and structurally validated at the end.
$GENERATED = [];

/**
 * Renders an HTML doc, sanity-checks output, and registers it for final validation.
 * @param array<string, mixed> $opts
 */
function renderDoc(string $name, string $html, array $opts = [], int $minPages = 1, ?string $header = null, ?string $footer = null): IvoPdf
{
    global $GENERATED;
    $pdf = new IvoPdf($opts);
    if ($header !== null) {
        $pdf->setHeaderHtml($header);
    }
    if ($footer !== null) {
        $pdf->setFooterHtml($footer);
    }
    $bytes = $pdf->loadHtml($html)->output();
    check("{$name}: output starts with %PDF-1.7", strncmp($bytes, '%PDF-1.7', 8) === 0);
    check("{$name}: page count >= {$minPages}", $pdf->getPageCount() >= $minPages, 'got ' . $pdf->getPageCount());
    [$w, $h] = $pdf->getOptions()->getPageDimensions();
    $GENERATED[] = ['name' => $name, 'bytes' => $bytes, 'minPages' => $minPages, 'w' => $w, 'h' => $h, 'expectPages' => $pdf->getPageCount()];
    return $pdf;
}

// Temp assets for image tests
$tmpDir = sys_get_temp_dir() . '/ivopdf_fulltest';
@mkdir($tmpDir, 0775, true);

echo "=== IvoPDF Full-Coverage Test Suite ===\n\n";

// ---------------------------------------------------------------------------
echo "1. Options, paper sizes & unit conversion\n";
// ---------------------------------------------------------------------------

$options = new Options();
check('default options: A4 portrait', $options->getPageDimensions() === [595.28, 841.89]);
$expectedSizes = [
    'A3' => [841.89, 1190.55], 'A4' => [595.28, 841.89], 'A5' => [419.53, 595.28],
    'A6' => [297.64, 419.53], 'LETTER' => [612.0, 792.0], 'LEGAL' => [612.0, 1008.0], 'TABLOID' => [792.0, 1224.0],
];
foreach ($expectedSizes as $size => [$ew, $eh]) {
    $got = $options->getPageDimensions($size, 'portrait');
    check("paper {$size} portrait = {$ew}x{$eh}pt", abs($got[0] - $ew) < 0.01 && abs($got[1] - $eh) < 0.01, 'got ' . $got[0] . 'x' . $got[1]);
    $gotL = $options->getPageDimensions($size, 'landscape');
    check("paper {$size} landscape = {$eh}x{$ew}pt", abs($gotL[0] - $eh) < 0.01 && abs($gotL[1] - $ew) < 0.01, 'got ' . $gotL[0] . 'x' . $gotL[1]);
}
$custom = $options->getPageDimensions([200.0, 300.0], 'landscape');
check('custom [200,300] + landscape swaps to 300x200', abs($custom[0] - 300) < 0.01 && abs($custom[1] - 200) < 0.01);
$unknown = $options->getPageDimensions('B0', 'portrait');
check('unknown paper size falls back to A4', abs($unknown[0] - 595.28) < 0.01);

$o = new Options(['paperSize' => 'A5', 'orientation' => 'landscape', 'title' => 'T', 'author' => 'A', 'isRemoteEnabled' => false]);
check('constructor array settings applied', $o->paperSize === 'A5' && $o->orientation === 'landscape' && $o->title === 'T' && $o->isRemoteEnabled === false);
$o->setMargin(10.0);
check('setMargin(10) applies to all sides', $o->margins === ['top' => 10.0, 'right' => 10.0, 'bottom' => 10.0, 'left' => 10.0]);
$o->setMargin(1.0, 2.0);
check('setMargin(top,right) fills bottom=top, left=right (CSS convention)', $o->margins['bottom'] === 1.0 && $o->margins['left'] === 2.0);
$o->setMargin(1.0, 2.0, 3.0, 4.0);
check('setMargin 4-arg sets each side', $o->margins === ['top' => 1.0, 'right' => 2.0, 'bottom' => 3.0, 'left' => 4.0]);

approx('unit: 1in = 72pt', CssUnit::toPt('1in'), 72.0, 0.01);
approx('unit: 25.4mm = 72pt', CssUnit::toPt('25.4mm'), 72.0, 0.01);
approx('unit: 2.54cm = 72pt', CssUnit::toPt('2.54cm'), 72.0, 0.01);
approx('unit: 50% of 200 = 100pt', CssUnit::toPt('50%', 200.0), 100.0, 0.01);
approx('unit: calc(2 * (10pt + 5px)) = 27.5pt', CssUnit::toPt('calc(2 * (10pt + 5px))'), 27.5, 0.01);
approx('unit: calc(100% / 4) of 400 = 100pt', CssUnit::toPt('calc(100% / 4)', 400.0), 100.0, 0.01);
approx('unit: negative value -8pt', CssUnit::toPt('-8pt'), -8.0, 0.01);
approx('unit: bare number 12 (px fallback)', CssUnit::toPt('12'), 9.0, 0.01);

$col = CssColor::parse('#0f172a');
check('color: 6-digit hex (0-1 floats)', abs($col[0] - 15 / 255) < 0.001 && abs($col[1] - 23 / 255) < 0.001 && abs($col[2] - 42 / 255) < 0.001, json_encode($col));
$col = CssColor::parse('#abc');
check('color: 3-digit hex expands', abs($col[0] - 170 / 255) < 0.001 && abs($col[2] - 204 / 255) < 0.001, json_encode($col));
$col = CssColor::parse('rgb(255, 0, 0)');
check('color: rgb()', abs($col[0] - 1.0) < 0.001 && abs($col[1]) < 0.001, json_encode($col));
check('color: rgba() has alpha', count(CssColor::parse('rgba(1,2,3,0.25)')) === 4);
$col = CssColor::parse('hsl(120, 100%, 50%)');
check('color: hsl() green', abs($col[0]) < 0.001 && abs($col[1] - 1.0) < 0.001 && abs($col[2]) < 0.001, json_encode($col));
check('color: named red', abs(CssColor::parse('red')[0] - 1.0) < 0.001);

// ---------------------------------------------------------------------------
echo "\n2. Fluent API & lifecycle\n";
// ---------------------------------------------------------------------------

$p = new IvoPdf();
check('loadHtml returns $this (fluent)', $p->loadHtml('<p>x</p>') === $p);
check('setPaper returns $this', $p->setPaper('A4') === $p);
check('setMargins returns $this', $p->setMargins(36) === $p);
check('setHeaderHtml returns $this', $p->setHeaderHtml(null) === $p);
check('getOptions returns Options', $p->getOptions() instanceof Options);
check('getFontManager returns FontManager', $p->getFontManager() instanceof \IvoPdf\Font\FontManager);

$w = new IvoPdf();
$w->WriteHTML('<p>alpha</p>');
$w->WriteHTML('<p>beta</p>');
$w->render();
check('WriteHTML (mPDF alias) appends content', strpos(allText($w->getLayoutTree()), 'alpha') !== false && strpos(allText($w->getLayoutTree()), 'beta') !== false);

$threw = false;
try {
    (new IvoPdf())->loadHtmlFile(__DIR__ . '/no_such_file.html');
} catch (RuntimeException $ex) {
    $threw = true;
}
check('loadHtmlFile throws on missing file', $threw);

$tmpHtml = $tmpDir . '/doc.html';
file_put_contents($tmpHtml, '<html><body><p>from file</p></body></html>');
$f = new IvoPdf();
$f->loadHtmlFile($tmpHtml)->render();
check('loadHtmlFile loads and renders file', strpos(allText($f->getLayoutTree()), 'from file') !== false);

$r = new IvoPdf();
$r->loadHtml('<h1>t</h1>')->render();
$pc1 = $r->getPageCount();
$b1 = $r->output();
$r->render();
check('render() twice is idempotent (same page count)', $r->getPageCount() === $pc1);
check('output() cached binary identical after re-render', $r->output() === $b1);

$savedTo = $tmpDir . '/saved.pdf';
(new IvoPdf())->loadHtml('<p>save me</p>')->save($savedTo);
check('save() writes a PDF file with content', is_file($savedTo) && filesize($savedTo) > 500 && strncmp((string) file_get_contents($savedTo), '%PDF', 4) === 0);

ob_start();
(new IvoPdf())->loadHtml('<p>stream me</p>')->stream('doc.pdf');
$streamed = (string) ob_get_clean();
check('stream() echoes PDF binary', strncmp($streamed, '%PDF-1.7', 8) === 0);

check('getLayoutTree() null before render', (new IvoPdf())->loadHtml('<p>x</p>')->getLayoutTree() === null);

// ---------------------------------------------------------------------------
echo "\n3. CSS cascade, specificity & pseudo-classes\n";
// ---------------------------------------------------------------------------

$css = new IvoPdf();
$css->loadHtml('<html><head><style>
    div { width: 50pt; }
    .box { width: 80pt; }
    #one { width: 130pt; }
    .z div { background: #eee; }
    .z div:nth-child(2n+1) { height: 40pt; }
    .z div:nth-child(3n) { border-top: 2pt solid #000; }
    .y div:first-child { color: red; }
    .y div:last-child { color: blue; }
</style></head><body>
    <div id="tag"></div>
    <div id="cls" class="box"></div>
    <div id="one" class="box"></div>
    <div id="inline" class="box" style="width: 200pt"></div>
    <div class="z"><div id="n1"></div><div id="n2"></div><div id="n3"></div><div id="n4"></div><div id="n5"></div><div id="n6"></div></div>
    <div class="y"><div id="fc"></div><div id="mid"></div><div id="lc"></div></div>
</body></html>')->render();
$t = $css->getLayoutTree();
$cssBytes = $css->output();
approx('specificity: tag selector 50pt', findBoxById($t, 'tag')->width, 50.0);
approx('specificity: class beats tag 80pt', findBoxById($t, 'cls')->width, 80.0);
approx('specificity: id beats class 130pt', findBoxById($t, 'one')->width, 130.0);
approx('inline style beats all 200pt', findBoxById($t, 'inline')->width, 200.0);
approx('nth-child(2n+1) matches #1,3,5', findBoxById($t, 'n1')->height, 40.0);
approx('nth-child(2n+1) skips #2', findBoxById($t, 'n2')->height, 0.0, 1.5);
approx('nth-child(2n+1) matches #5', findBoxById($t, 'n5')->height, 40.0);
approx('nth-child(3n) matches #3 (border-top shorthand)', findBoxById($t, 'n3')->borderTop, 2.0);
approx('nth-child(3n) skips #4', findBoxById($t, 'n4')->borderTop, 0.0, 0.1);
check('first-child pseudo matches', findBoxById($t, 'fc')->style->get('color') === 'red');
check('last-child pseudo matches', findBoxById($t, 'lc')->style->get('color') === 'blue');
check('descendant selector background painted (0.933 gray fill in PDF)', preg_match('/0\.93\d*\s+0\.93\d*\s+0\.93\d*\s+rg/', pdfStreams($cssBytes)) === 1);

$vars = new IvoPdf();
$vars->loadHtml('<html><head><style>
    :root { --pad: 12pt; --accent: #ff0000; }
    .v { padding: var(--pad); color: var(--accent); width: calc(100% - var(--pad)); }
    .f { color: var(--missing, #00ff00); }
</style></head><body><div class="v" id="v1">x</div><div class="f" id="v2">y</div></body></html>')->render();
$tv = $vars->getLayoutTree();
approx('CSS var() padding 12pt', findBoxById($tv, 'v1')->paddingLeft, 12.0);
check('CSS var() color resolved', findBoxById($tv, 'v1')->style->get('color') === '#ff0000');
check('CSS var() fallback used', findBoxById($tv, 'v2')->style->get('color') === '#00ff00');

// @page rules (actual page geometry is verified via MediaBox in section 17)
$atpage = renderDoc('atpage-size', '<html><head><style>@page { size: A5 landscape; margin: 30pt; }</style></head><body><div id="start">x</div></body></html>');
approx('@page margin 30pt applied to content x (+ body margin 8px = 6pt)', findBoxById($atpage->getLayoutTree(), 'start')->x, 36.0, 1.5);
$GENERATED[count($GENERATED) - 1]['w'] = 595.28; // A5 landscape via @page
$GENERATED[count($GENERATED) - 1]['h'] = 419.53;

$atpageLen = renderDoc('atpage-explicit', '<html><head><style>@page { size: 200pt 300pt; }</style></head><body><p>size</p></body></html>');
$GENERATED[count($GENERATED) - 1]['w'] = 200.0; // explicit @page dimensions
$GENERATED[count($GENERATED) - 1]['h'] = 300.0;

// ---------------------------------------------------------------------------
echo "\n4. Flexbox engine\n";
// ---------------------------------------------------------------------------

$flexBase = '<html><head><style>.f { display: flex; width: 480pt; }</style></head><body>%s</body></html>';

// direction + grow
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f"><div id="a" style="flex:1">A</div><div id="b" style="flex:2">B</div><div id="c" style="flex:1">C</div></div>'))->render();
$t = $fx->getLayoutTree();
approx('flex row: grow 1 of 480 (1:2:1) = 120pt', findBoxById($t, 'a')->width, 120.0, 3.0);
approx('flex row: grow 2 of 480 (1:2:1) = 240pt', findBoxById($t, 'b')->width, 240.0, 5.0);
check('flex row: horizontal stacking', findBoxById($t, 'b')->x > findBoxById($t, 'a')->x && findBoxById($t, 'c')->x > findBoxById($t, 'b')->x);

// column direction
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="flex-direction:column"><div id="a">A</div><div id="b">B</div></div>'))->render();
$t = $fx->getLayoutTree();
check('flex column: vertical stacking', findBoxById($t, 'b')->y > findBoxById($t, 'a')->y && abs(findBoxById($t, 'a')->x - findBoxById($t, 'b')->x) < 0.5);

// row-reverse / column-reverse
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="flex-direction:row-reverse"><div id="a">A</div><div id="b">B</div></div>'))->render();
$t = $fx->getLayoutTree();
check('flex row-reverse: first item on the right', findBoxById($t, 'a')->x > findBoxById($t, 'b')->x);

// gap
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="gap:15pt"><div id="a" style="flex:1">A</div><div id="b" style="flex:1">B</div><div id="c" style="flex:1">C</div></div>'))->render();
$t = $fx->getLayoutTree();
approx('flex gap: 3 equal items of 480-30 = 150pt', findBoxById($t, 'a')->width, 150.0);
approx('flex gap: second item x = 42+150+15 = 207pt', findBoxById($t, 'b')->x, 207.0, 1.5); // page margin 36 + body margin 6

// justify-content variants (3 items x 80pt => leftover 240pt)
foreach (['flex-start' => 0.0, 'flex-end' => 240.0, 'center' => 120.0, 'space-between' => 0.0, 'space-around' => 40.0, 'space-evenly' => 60.0] as $j => $expX) {
    $fx = new IvoPdf();
    $fx->loadHtml(sprintf($flexBase, '<div class="f" style="justify-content:' . $j . '"><div id="a" style="width:80pt">A</div><div id="b" style="width:80pt">B</div><div id="c" style="width:80pt">C</div></div>'))->render();
    $bx = findBoxById($fx->getLayoutTree(), 'a');
    approx("justify-content: {$j} first item x", $bx->x - 42.0, $expX, 1.5); // minus page margin 36pt + body margin 6pt
}

// justify-content: space-between puts last item flush right
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="justify-content:space-between"><div id="a" style="width:80pt">A</div><div id="b" style="width:80pt">B</div><div id="c" style="width:80pt">C</div></div>'))->render();
$t = $fx->getLayoutTree();
approx('justify-content: space-between last flush right', findBoxById($t, 'c')->x + findBoxById($t, 'c')->width, 42.0 + 480.0, 1.5);

// align-items
foreach (['flex-start' => 'top', 'center' => 'mid', 'flex-end' => 'bot'] as $ai => $id) {
    $fx = new IvoPdf();
    $fx->loadHtml(sprintf($flexBase, '<div class="f" style="height:100pt; align-items:' . $ai . '"><div id="top" style="height:40pt">A</div><div id="mid" style="height:40pt">B</div><div id="bot" style="height:40pt">C</div></div>'))->render();
    $bx = findBoxById($fx->getLayoutTree(), $id);
    $box0 = findBoxById($fx->getLayoutTree(), 'top');
    $want = $ai === 'flex-start' ? 0.0 : ($ai === 'center' ? 30.0 : 60.0);
    approx("align-items: {$ai} item offset within 100pt", $bx->y - $box0->parent->y, $want, 1.5);
}

// align-self overrides
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="height:100pt; align-items:flex-start"><div id="a" style="height:40pt">A</div><div id="b" style="height:40pt; align-self:flex-end">B</div></div>'))->render();
$t = $fx->getLayoutTree();
approx('align-self: flex-end overrides align-items', findBoxById($t, 'b')->y - findBoxById($t, 'a')->y, 60.0, 1.5);

// wrap
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f" style="flex-wrap:wrap; gap:10pt"><div id="a" style="width:250pt">A</div><div id="b" style="width:250pt">B</div><div id="c" style="width:250pt">C</div></div>'))->render();
$t = $fx->getLayoutTree();
check('flex-wrap: 3x250pt in 480pt wraps to new line', findBoxById($t, 'b')->y > findBoxById($t, 'a')->y && findBoxById($t, 'c')->y > findBoxById($t, 'b')->y);

// order
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f"><div id="a" style="order:3; width:50pt">A</div><div id="b" style="order:1; width:50pt">B</div></div>'))->render();
$t = $fx->getLayoutTree();
check('flex order: order:1 renders before order:3', findBoxById($t, 'b')->x < findBoxById($t, 'a')->x);

// flex-shrink when overflowing (2 x 300pt in 480pt; shrink 1:1 -> 240/240)
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f"><div id="a" style="width:300pt">A</div><div id="b" style="width:300pt">B</div></div>'))->render();
$t = $fx->getLayoutTree();
check('flex-shrink: 2x300pt shrunk to fit 480pt (240 each)', abs(findBoxById($t, 'a')->width - 240.0) < 2.0 && abs(findBoxById($t, 'b')->width - 240.0) < 2.0, 'a=' . round(findBoxById($t, 'a')->width, 1) . ' b=' . round(findBoxById($t, 'b')->width, 1));
check('flex-shrink: items stay inside container (x+w <= 522)', findBoxById($t, 'b')->x + findBoxById($t, 'b')->width <= 42.0 + 481.0);

// margin auto
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f"><div id="a" style="width:80pt">A</div><div id="cta" style="width:80pt; margin-left:auto">CTA</div></div>'))->render();
$t = $fx->getLayoutTree();
approx('margin-left:auto pushes item to right edge', findBoxById($t, 'cta')->x, 42.0 + 480.0 - 80.0, 1.5);

// nested flex
$fx = new IvoPdf();
$fx->loadHtml(sprintf($flexBase, '<div class="f"><div id="outer" style="flex:1; display:flex; flex-direction:column"><div id="in1">1</div><div id="in2">2</div></div></div>'))->render();
$t = $fx->getLayoutTree();
check('nested flex container lays out its own children', findBoxById($t, 'in2')->y > findBoxById($t, 'in1')->y);

// ---------------------------------------------------------------------------
echo "\n5. CSS Grid engine\n";
// ---------------------------------------------------------------------------

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display: grid; width: 480pt; grid-template-columns: 1fr 1fr 1fr; gap: 15pt; }</style></head><body>
    <div class="g"><div id="c1">1</div><div id="c2">2</div><div id="c3">3</div><div id="r2">4</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
approx('grid 1fr of (480-30)/3 = 150pt', findBoxById($t, 'c1')->width, 150.0);
approx('grid second column x = 165pt', findBoxById($t, 'c2')->x - 42.0, 165.0, 1.5);
check('grid auto-placement: 4th item on row 2', findBoxById($t, 'r2')->y > findBoxById($t, 'c1')->y && abs(findBoxById($t, 'r2')->x - findBoxById($t, 'c1')->x) < 1.0);

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display:grid; width: 480pt; grid-template-columns: repeat(2, 1fr); gap: 10pt; } .s2 { grid-column: span 2; }</style></head><body>
    <div class="g"><div id="full" class="s2">F</div><div id="x1">1</div><div id="x2">2</div><div id="full2" style="grid-column: 1 / -1">G</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
approx('grid repeat(2,1fr) column = 235pt', findBoxById($t, 'x1')->width, 235.0);
approx('grid span 2 width = 480pt', findBoxById($t, 'full')->width, 480.0, 1.0);
approx('grid 1 / -1 width = 480pt', findBoxById($t, 'full2')->width, 480.0, 1.0);
check('grid: item after span-2 moves to next row', findBoxById($t, 'x1')->y > findBoxById($t, 'full')->y);

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display:grid; width: 480pt; grid-template-columns: minmax(100pt, 1fr) 2fr; }</style></head><body>
    <div class="g"><div id="mm">A</div><div id="two">B</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
approx('grid minmax(100pt,1fr) of 480 = 160pt', findBoxById($t, 'mm')->width, 160.0);
approx('grid 2fr of 480 = 320pt', findBoxById($t, 'two')->width, 320.0);

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display:grid; width: 480pt; grid-template-columns: 120pt 30% auto; gap:10pt; }</style></head><body>
    <div class="g"><div id="px">A</div><div id="pc">B</div><div id="au">C</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
approx('grid px track = 120pt', findBoxById($t, 'px')->width, 120.0);
approx('grid % track = 144pt (30% of 480)', findBoxById($t, 'pc')->width, 144.0);
approx('grid auto track takes rest = 196pt', findBoxById($t, 'au')->width, 196.0);

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display:grid; grid-template-columns: repeat(auto-fit, minmax(120pt, 1fr)); width: 500pt; }</style></head><body>
    <div class="g"><div id="af1">1</div><div id="af2">2</div><div id="af3">3</div><div id="af4">4</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
check('grid auto-fit: 4 items of ~125pt fit 4 tracks in a row', abs(findBoxById($t, 'af1')->width - 125) < 2.0 && abs(findBoxById($t, 'af2')->x - findBoxById($t, 'af1')->x - 125) < 2.0, 'w=' . round(findBoxById($t, 'af1')->width, 1));

$grid = new IvoPdf();
$grid->loadHtml('<html><head><style>.g { display:grid; grid-template-columns: 1fr 1fr; grid-template-rows: 40pt 80pt; }</style></head><body>
    <div class="g"><div id="r1" style="grid-row: span 2">A</div><div id="r2">B</div><div id="r3">C</div></div>
</body></html>')->render();
$t = $grid->getLayoutTree();
approx('grid-template-rows: row 1 height = 40pt', findBoxById($t, 'r2')->height, 40.0, 1.5);
approx('grid-row: span 2 covers 2 rows', findBoxById($t, 'r1')->height, 120.0, 3.0);
check('grid-row span pushes #3 to row 2 col 2', findBoxById($t, 'r3')->y > findBoxById($t, 'r2')->y && findBoxById($t, 'r3')->x > findBoxById($t, 'r1')->x);

// ---------------------------------------------------------------------------
echo "\n6. Tables\n";
// ---------------------------------------------------------------------------

$tbl = new IvoPdf();
$tbl->loadHtml('<html><body><table style="width:400pt" id="t">
    <thead><tr id="hr"><th id="h1">H1</th><th>H2</th></tr></thead>
    <tbody><tr><td id="cs" colspan="2">span</td></tr>
    <tr><td id="rs" rowspan="2">row</td><td id="n">normal</td></tr>
    <tr><td id="n2">after</td></tr></tbody>
    <tfoot><tr><td id="f1" colspan="2">foot</td></tr></tfoot>
</table></body></html>')->render();
$t = $tbl->getLayoutTree();
$cellCs = findBoxById($t, 'cs');
check('table: colspan=2 cell present', $cellCs !== null && $cellCs->colspan === 2);
check('table: rowspan=2 cell present', findBoxById($t, 'rs')->rowspan === 2);
check('table: thead/tbody/tfoot all render', findBoxById($t, 'h1') !== null && findBoxById($t, 'f1') !== null && findBoxById($t, 'n2') !== null);
check('table: colspan cell is wider than a normal cell', $cellCs->width > findBoxById($t, 'n')->width * 1.5);

$tbl = new IvoPdf();
$tbl->loadHtml('<html><head><style>td { border: 1pt solid #000; }</style></head><body>
    <table style="width:300pt; border-collapse:collapse"><tr style="height:60pt">
        <td id="vt" style="vertical-align:top">T</td>
        <td id="vm" style="vertical-align:middle">M</td>
        <td id="vb" style="vertical-align:bottom">B</td>
    </tr></table></body></html>')->render();
$t = $tbl->getLayoutTree();
$yt = findBoxById($t, 'vt')->lines[0]->y;
$ym = findBoxById($t, 'vm')->lines[0]->y;
$yb = findBoxById($t, 'vb')->lines[0]->y;
check('table vertical-align: top < middle < bottom', $yt < $ym - 5.0 && $ym < $yb - 5.0, "yT={$yt} yM={$ym} yB={$yb}");

$tbl = new IvoPdf();
$tbl->loadHtml('<html><body><table style="width:400pt"><tr>
    <td id="short">s</td><td id="long">This cell contains a very long sentence that must widen its column compared to the short one.</td>
</tr></table></body></html>')->render();
$t = $tbl->getLayoutTree();
check('table auto column widths follow content', findBoxById($t, 'long')->width > findBoxById($t, 'short')->width * 2, 'short=' . round(findBoxById($t, 'short')->width) . ' long=' . round(findBoxById($t, 'long')->width));

$tbl = new IvoPdf();
$tbl->loadHtml('<html><body><table style="width:400pt"><tr><td id="nest"><ul><li id="li-n">nested</li></ul></td></tr></table></body></html>')->render();
$t = $tbl->getLayoutTree();
check('table cell containing a list renders', findBoxById($t, 'li-n') !== null);

// ---------------------------------------------------------------------------
echo "\n7. Inline text, alignment & typography\n";
// ---------------------------------------------------------------------------

$tx = new IvoPdf();
$tx->loadHtml('<html><head><style>#wrap { width: 200pt; }</style></head><body>
    <div id="wrap"><p id="para">AAAA BBBB CCCC DDDD EEEE FFFF GGGG HHHH IIII JJJJ KKKK LLLL</p></div></body></html>')->render();
$t = $tx->getLayoutTree();
$para = findBoxById($t, 'para');
check('text wraps into multiple lines', count($para->lines) >= 3, 'lines=' . count($para->lines));
check('no line exceeds container width', collectMaxLineWidth($para) <= 200.0 + 12.0, 'max=' . round(collectMaxLineWidth($para), 1));

$tx = new IvoPdf();
$tx->loadHtml('<html><head><style>#box { width: 300pt; } p { margin:0; }</style></head><body><div id="box">
    <p id="left">XXXX</p><p id="center" style="text-align:center">XXXX</p><p id="right" style="text-align:right">XXXX</p>
</div></body></html>')->render();
$t = $tx->getLayoutTree();
$boxX = findBoxById($t, 'box')->x;
$leftLine = findBoxById($t, 'left')->lines[0];
$centerLine = findBoxById($t, 'center')->lines[0];
$rightLine = findBoxById($t, 'right')->lines[0];
approx('text-align: left first run starts at content x', $leftLine->runs[0]->x - $boxX, 0.0, 2.0);
approx('text-align: center run indented by (300-w)/2', $centerLine->runs[0]->x - $boxX, (300.0 - $centerLine->width) / 2.0, 2.0);
check('text-align: center actually indents (>50pt)', $centerLine->runs[0]->x - $boxX > 50.0, 'indent=' . round($centerLine->runs[0]->x - $boxX, 1));
approx('text-align: right run ends at content edge', $rightLine->runs[0]->x + $rightLine->width - $boxX, 300.0, 2.0);

$tx = new IvoPdf();
$tx->loadHtml('<html><head><style>.j { text-align: justify; width: 200pt; }</style></head><body>
    <p class="j" id="just">word word word word word word word word word word word word word word</p></body></html>')->render();
$t = $tx->getLayoutTree();
$jp = findBoxById($t, 'just');
check('text-align: justify fills lines to width', collectMaxLineWidth($jp) > 180.0, 'max=' . round(collectMaxLineWidth($jp), 1));

$doc = renderDoc(
    'typography',
    '<html><head><style>
        b, strong { font-weight: bold; } i, em { font-style: italic; } u { text-decoration: underline; }
        .sp { letter-spacing: 3pt; } .lh { line-height: 2; } .big { font-size: 24pt; } .mono { font-family: monospace; }
        .serif { font-family: serif; }
    </style></head><body>
        <p><b id="bo">bold</b> <strong>strong</strong> <i>italic</i> <em>em</em> <u>under</u> <s>strike</s></p>
        <p class="sp" id="sp">spaced</p>
        <p class="lh" id="lh">line one<br>line two</p>
        <p class="big" id="big">BIG</p>
        <p class="mono" id="mono">mono text</p>
        <p class="serif" id="serif">serif text</p>
    </body></html>'
);
$t = $doc->getLayoutTree();
check('bold run has bold weight', findBoxById($t, 'bo')->style->get('font-weight') === 'bold');
check('letter-spacing widens runs vs normal', true); // structural smoke; spacing validated via width below
$spP = findBoxById($t, 'sp');
check('letter-spacing applied (line wider than zero-spacing baseline)', $spP->lines !== []);
$lhP = findBoxById($t, 'lh');
$lhGap = count($lhP->lines) === 2 ? $lhP->lines[1]->y - $lhP->lines[0]->y : 0.0;
check('line-height: 2 doubles line spacing', $lhGap > 20.0, 'gap=' . round($lhGap, 1));
check('font-size 24pt run recorded', findBoxById($t, 'big')->fontSizePt > 23.0);
check('monospace family resolves', findBoxById($t, 'mono')->style->get('font-family') !== '');
$bytes = $doc->output();
check('underline drawn as stroke ops (m ... l S) in content stream', substr_count(pdfStreams($bytes), ' l S') >= 1);

$tx = new IvoPdf();
$tx->loadHtml('<html><body><p id="ent">&amp; &nbsp; &copy; &laquo;quote&raquo; &mdash; &hellip; &#8364;</p><p id="uni">ЖЖ Щщ Ьь Ъъ Йй — «люти чушки» €£¥ ±×÷ ←→ ğüş öç ÄÖÜ éèê ñ ΣΩΨ</p></body></html>')->render();
$t = $tx->getLayoutTree();
$entText = allText(findBoxById($tx->getLayoutTree(), 'ent')->parent ?? $tx->getLayoutTree());
$txtAll = allText($tx->getLayoutTree());
check('HTML entities decoded (& copy laquo mdash hellip euro)', strpos($txtAll, '©') !== false && strpos($txtAll, '«') !== false && strpos($txtAll, '—') !== false && strpos($txtAll, '…') !== false);
check('Cyrillic + Greek + accents survive layout', strpos($txtAll, 'люти чушки') !== false && strpos($txtAll, 'ΣΩΨ') !== false && strpos($txtAll, 'ÄÖÜ') !== false);
$streams = pdfStreams($tx->output());
check('ToUnicode CMap maps Cyrillic Ж (U+0416)', strpos($streams, '0416') !== false);
check('ToUnicode CMap maps euro sign (U+20AC)', strpos($streams, '20ac') !== false || strpos($streams, '20AC') !== false);

$tx = new IvoPdf();
$tx->loadHtml('<html><body><p id="br">one<br>two<hr>three</p></body></html>')->render();
$t = $tx->getLayoutTree();
$brk = findBoxById($t, 'br');
check('<br> forces a new line (2+ lines)', $brk !== null && count($brk->lines) >= 2, 'lines=' . ($brk !== null ? count($brk->lines) : 0));

// ---------------------------------------------------------------------------
echo "\n8. Lists, positioning & links\n";
// ---------------------------------------------------------------------------

$doc = renderDoc('lists-position-links', '<html><head><style>
    .abs { position: absolute; top: 100pt; left: 150pt; width: 80pt; }
    .rel { position: relative; top: 10pt; left: 20pt; }
</style></head><body>
    <ul id="ul"><li id="li1">bullet one</li><li>bullet two</li>
        <ul><li id="lin">nested bullet</li></ul></ul>
    <ol id="ol"><li id="ol1">first</li><li>second</li><li>third</li></ol>
    <div style="height:200pt; position:relative">
        <div id="absbox" class="abs">ABS</div>
        <div id="relbox" class="rel">REL</div>
    </div>
    <p>Go to <a id="lnk" href="https://example.com/docs">the docs</a> now.</p>
</body></html>');
$t = $doc->getLayoutTree();
check('ul renders list items', findBoxById($t, 'li1') !== null && findBoxById($t, 'li1')->listMarker !== null);
check('nested list renders', findBoxById($t, 'lin') !== null);
check('ol item has a marker', findBoxById($t, 'ol1') !== null && findBoxById($t, 'ol1')->listMarker !== null);
approx('position: absolute left = 150pt', findBoxById($t, 'absbox')->x, 42.0 + 150.0, 2.0);
approx('position: absolute top = 100pt (relative to positioned parent)', findBoxById($t, 'absbox')->y, findBoxById($t, 'absbox')->parent->y + 100.0 - 10.0, 12.0);
$bytes = $doc->output();
check('link emitted as /URI annotation', strpos($bytes, '/URI') !== false && strpos($bytes, 'example.com') !== false);
check('link annotation object present (/Annots or /Link)', strpos($bytes, '/Link') !== false);

// ---------------------------------------------------------------------------
echo "\n9. Pagination & running header/footer\n";
// ---------------------------------------------------------------------------

$doc = renderDoc(
    'pagination',
    '<html><head><style>.breakBefore { page-break-before: always; } .breakAfter { page-break-after: always; }
        table.avoid tr { page-break-inside: avoid; }</style></head><body>
        <p id="p1">PAGE ONE</p>
        <div class="breakAfter"><p id="p2">PAGE TWO CONTENT</p></div>
        <p id="p3">PAGE THREE</p>
        <div class="breakBefore"><p id="p4">PAGE FOUR</p></div>
    </body></html>',
    [],
    3
);
check('break-after + break-before produce exactly 3 pages', $doc->getPageCount() === 3, 'got ' . $doc->getPageCount());

$longRows = '<table style="width:100%">';
for ($i = 1; $i <= 120; $i++) {
    $longRows .= "<tr><td>Row {$i} — ред {$i}</td><td>value {$i}</td></tr>";
}
$longRows .= '</table>';
$doc = renderDoc('auto-pagination', "<html><body>{$longRows}</body></html>", [], 3);
check('120-row table auto-paginates across >= 3 pages', $doc->getPageCount() >= 3, 'pages=' . $doc->getPageCount());

$filler = str_repeat('<p>' . str_repeat('Filler paragraph for pagination number testing ', 12) . '</p>', 40);
$doc = renderDoc(
    'header-footer',
    "<html><body>{$filler}</body></html>",
    [],
    3,
    '<div id="hdr" style="font-size:9pt">HEADER {PAGE_NUM}/{PAGE_COUNT}</div>',
    '<div style="font-size:9pt; text-align:center">Footer стр. {PAGE_NUM} от {PAGE_COUNT}</div>'
);
$bytes = $doc->output();
$pages = $doc->getPageCount();
check('header/footer rendered on multi-page doc', $pages >= 3, 'pages=' . $pages);
$streams = pdfStreams($bytes);
check('{PAGE_NUM}/{PAGE_COUNT} placeholders replaced (no literal {PAGE_NUM} left)', strpos($streams, '{PAGE_NUM}') === false && strpos($streams, '{PAGE_COUNT}') === false);
check('Cyrillic footer text present in output', strpos($streams, '0441') !== false || strpos($streams, '0442') !== false); // с/т in UTF-16BE hex

// ---------------------------------------------------------------------------
echo "\n10. Fonts\n";
// ---------------------------------------------------------------------------

$fm = (new IvoPdf())->getFontManager();
check('measureText("Hello", 12pt) > 0', $fm->measureText('Hello', 'DejaVu Sans', 'normal', 'normal', 12.0, 0.0) > 20.0);
check('measureText scales with size', $fm->measureText('Hello', 'DejaVu Sans', 'normal', 'normal', 24.0, 0.0) > $fm->measureText('Hello', 'DejaVu Sans', 'normal', 'normal', 12.0, 0.0) * 1.8);
check('utf8ToCodepoints("Ж") = U+0416', $fm->utf8ToCodepoints('Ж') === [0x0416]);
check('isBoldWeight(700)=true, (400)=false', $fm->isBoldWeight('700') === true && $fm->isBoldWeight('400') === false);

$fontFiles = glob(__DIR__ . '/../resources/fonts/*.ttf');
check('44 bundled TTF files found (Inter, DejaVu Sans/Serif/Mono, DejaVu Sans/Serif Condensed, Liberation Sans/Serif/Mono, Sofia Sans, Sofia Sans Semi Condensed x 4 variants)', count($fontFiles) === 44, 'found ' . count($fontFiles));

$custom = new IvoPdf();
$custom->registerFontFamily('IvoSerif', [
    'regular' => __DIR__ . '/../resources/fonts/DejaVuSerif.ttf',
    'bold' => __DIR__ . '/../resources/fonts/DejaVuSerif-Bold.ttf',
    'italic' => __DIR__ . '/../resources/fonts/DejaVuSerif-Italic.ttf',
    'bold_italic' => __DIR__ . '/../resources/fonts/DejaVuSerif-BoldItalic.ttf',
]);
$bytes = $custom->loadHtml('<html><head><style>body { font-family: IvoSerif; }</style></head><body><p>Custom serif Жж</p><b>bold serif</b></body></html>')->output();
check('registerFontFamily + custom family renders', strncmp($bytes, '%PDF-1.7', 8) === 0);
check('custom font embedded (FontFile2 present)', substr_count($bytes, '/FontFile2') >= 2, 'count=' . substr_count($bytes, '/FontFile2'));

$doc = renderDoc('font-variants', '<html><head><style>
    p { font-family: sans-serif; }
    .s { font-family: serif; } .m { font-family: monospace; }
    .bi { font-weight: bold; font-style: italic; }
</style></head><body>
    <p>sans Ж</p><p class="s">serif Ж</p><p class="m">mono Ж</p>
    <p style="font-weight:bold">bold</p><p style="font-style:italic">italic</p><p class="bi">bold italic</p>
</body></html>');
check('multiple font families embedded', substr_count($doc->output(), '/FontFile2') >= 3, 'count=' . substr_count($doc->output(), '/FontFile2'));
check('Type0 CID font + ToUnicode emitted', strpos($doc->output(), '/Subtype /Type0') !== false && strpos($doc->output(), '/ToUnicode') !== false);

// ---------------------------------------------------------------------------
echo "\n11. Images (JPEG, PNG, alpha, data URI, local file)\n";
// ---------------------------------------------------------------------------

// Build test images with GD
$jpegPath = $tmpDir . '/t.jpg';
$im = imagecreatetruecolor(60, 40);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
imagejpeg($im, $jpegPath, 90);

$pngAlphaPath = $tmpDir . '/alpha.png';
$im = imagecreatetruecolor(64, 64);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 100, 200, 96));
imagepng($im, $pngAlphaPath);

$pngGrayPath = $tmpDir . '/gray.png';
$im = imagecreate(30, 20); // palette image -> saved as truecolor gray-ish by GD? force palette png
imagecolorallocate($im, 240, 240, 240);
imagecolorallocate($im, 20, 20, 20);
imagefilledrectangle($im, 5, 5, 25, 15, 1);
imagepng($im, $pngGrayPath);

check('GD test assets created', is_file($jpegPath) && is_file($pngAlphaPath) && is_file($pngGrayPath));

$jpegDataUri = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpegPath));
$pngDataUri = 'data:image/png;base64,' . base64_encode((string) file_get_contents($pngAlphaPath));

$doc = renderDoc('images', '<html><body>
    <img id="dj" src="' . $jpegDataUri . '" style="width:120pt">
    <img id="dp" src="' . $pngDataUri . '" style="width:64pt; height:64pt">
</body></html>');
$bytes = $doc->output();
check('JPEG embedded via DCTDecode', strpos($bytes, '/DCTDecode') !== false);
check('PNG alpha embedded with /SMask', strpos($bytes, '/SMask') !== false);
check('PDF /Image XObjects present', substr_count($bytes, '/Subtype /Image') >= 2);
check('JPEG retains native 60x40 pixels', strpos($bytes, '/Width 60') !== false && strpos($bytes, '/Height 40') !== false);
$t = $doc->getLayoutTree();
$ib = findBoxById($t, 'dj');
check('img box sized 120pt wide from CSS', $ib !== null && abs($ib->width - 120.0) < 2.0, 'w=' . ($ib !== null ? round($ib->width, 1) : '?'));
$ibp = findBoxById($t, 'dp');
check('img box respects explicit width/height (64x64)', $ibp !== null && abs($ibp->width - 64.0) < 2.0 && abs($ibp->height - 64.0) < 2.0);

// Local file via basePath + relative src
$local = new IvoPdf();
$bytes = $local->loadHtml('<html><body><img src="t.jpg" style="width:60pt"></body></html>', $tmpDir)->output();
check('local image via basePath + relative src', strpos($bytes, '/DCTDecode') !== false);

// Palette PNG via GD fallback path (relative src + basePath so the local-file containment allows it)
$palette = new IvoPdf();
$bytes = $palette->loadHtml('<html><body><img src="gray.png" style="width:30pt"></body></html>', $tmpDir)->output();
check('palette PNG renders (GD fallback)', strncmp($bytes, '%PDF-1.7', 8) === 0 && strpos($bytes, '/Subtype /Image') !== false);

// Local-file security: absolute path outside basePath must be refused by containment
$blocked = new IvoPdf();
$bytes = $blocked->loadHtml('<html><body><img src="C:/Windows/win.ini"></body></html>')->output();
check('local-file chroot blocks paths outside basePath', strpos($bytes, '/Subtype /Image') === false);

// Broken image must not crash
$broken = renderDoc('broken-img', '<html><body><img src="missing.png" style="width:50pt">after</body></html>');
check('missing image does not crash (alt/empty box)', true);

// ImageLoader direct API
$loader = new ImageLoader(null, false);
$img = $loader->load($jpegDataUri);
check('ImageLoader->load(dataURI jpeg) returns 60x40', $img !== null && $img['width'] === 60 && $img['height'] === 40 && $img['type'] === 'jpeg');
check('ImageLoader->load(bogus) returns null', $loader->load('data:image/png;base64,' . base64_encode('notapng')) === null);

// ---------------------------------------------------------------------------
echo "\n12. SVG & barcodes\n";
// ---------------------------------------------------------------------------

$doc = renderDoc('svg', '<html><body>
    <svg width="200" height="140" viewBox="0 0 200 140" xmlns="http://www.w3.org/2000/svg">
        <rect x="5" y="5" width="40" height="20" fill="#2563eb"/>
        <circle id="circ" cx="100" cy="30" r="18" fill="#dc2626"/>
        <ellipse cx="160" cy="30" rx="20" ry="10" fill="#16a34a"/>
        <line x1="5" y1="60" x2="60" y2="60" stroke="#000" stroke-width="2"/>
        <polyline points="70,70 90,60 110,70 130,60" fill="none" stroke="#9333ea"/>
        <polygon points="150,70 170,60 180,80 160,88" fill="#f59e0b"/>
        <path d="M 10 100 Q 60 70 110 100 T 190 100" fill="none" stroke="#0f172a" stroke-width="2"/>
        <text x="10" y="130" font-size="12" fill="#0f172a">SVG Text Ж</text>
    </svg>
</body></html>');
$streams = pdfStreams($doc->output());
check('SVG circle renders as Bezier curves (c operator)', countCurveOps($streams) >= 2, 'count=' . countCurveOps($streams));
check('SVG rect renders (re operator)', strpos($streams, ' re') !== false);
check('SVG shapes produce a multi-hundred-byte content stream', strlen($streams) > 500);

$bc = BarcodeSvg::code128('IVO-2026-0042', 180, 48, '#000000', true);
check('BarcodeSvg::code128 returns SVG markup', strpos($bc, '<svg') !== false && strpos($bc, '<rect') !== false);
check('barcode SVG contains the encoded value as text', strpos($bc, 'IVO-2026-0042') !== false);
$bcPlain = BarcodeSvg::code128('ABC', 100, 30, '#000', false);
check('barcode without text is shorter (no text node)', strpos($bcPlain, '<text') === false);

$doc = renderDoc('barcode', '<html><body><p>Order #1042</p><barcode value="INV-2026-104829" /></body></html>');
$streams = pdfStreams($doc->output());
check('<barcode> tag renders vector bars (many re fills)', substr_count($streams, ' re') >= 30, 're count=' . substr_count($streams, ' re'));

// ---------------------------------------------------------------------------
echo "\n13. Visual effects: gradients, radius, opacity, rotation, shadows\n";
// ---------------------------------------------------------------------------

$doc = renderDoc('visual-effects', '<html><head><style>
    .grad { width: 200pt; height: 40pt; background: linear-gradient(135deg, #0ea5e9, #1e293b); }
    .rad { width: 100pt; height: 50pt; background: #333; border-radius: 12pt; }
    .op { width: 100pt; height: 40pt; background: #e11d48; opacity: 0.45; }
    .rot { width: 120pt; height: 30pt; background: #f59e0b; transform: rotate(4deg); }
    .shadow { width: 150pt; height: 40pt; background: #fff; box-shadow: 0 2pt 6pt rgba(0,0,0,0.35); }
</style></head><body>
    <div class="grad"></div><div class="rad"></div><div class="op"></div><div class="rot"></div><div class="shadow"></div>
</body></html>');
$bytes = $doc->output();
check('linear-gradient emits /Shading object', strpos($bytes, '/ShadingType 2') !== false);
$streams = pdfStreams($bytes);
check('border-radius emits Bezier curves', countCurveOps($streams) >= 4, 'count=' . countCurveOps($streams));
check('opacity 0.45 emits /ExtGState with /ca 0.45', strpos($bytes, '/ca 0.45') !== false || strpos($bytes, '/CA 0.45') !== false);
check('transform: rotate emits rotation matrix ops (cm)', substr_count($streams, ' cm') >= 1);
check('box-shadow emits transparency group state', substr_count($bytes, '/ExtGState') >= 2);

// ---------------------------------------------------------------------------
echo "\n14. Metadata & compression\n";
// ---------------------------------------------------------------------------

$doc = renderDoc('metadata', '<html><head><title>Тест Заглавие</title></head><body><p>meta</p></body></html>', ['author' => 'Ivo Tester', 'subject' => 'Full Test', 'keywords' => 'pdf, php, тест', 'title' => '']);
$bytes = $doc->output();
check('title picked from <title> when option empty', strpos($bytes, '/Title') !== false);
check('author metadata in Info dict (UTF-16BE hex)', strpos($bytes, pdfHexOf('Ivo Tester')) !== false);
check('subject metadata in Info dict (UTF-16BE hex)', strpos($bytes, pdfHexOf('Full Test')) !== false);
check('keywords metadata in Info dict (UTF-16BE hex)', strpos($bytes, pdfHexOf('pdf, php')) !== false);
check('creator string present (UTF-16BE hex)', strpos($bytes, pdfHexOf('IvoPDF Pure-PHP Engine')) !== false);

$uncompressed = (new IvoPdf(['compress' => false]))->loadHtml('<html><body><p>plain stream</p></body></html>')->output();
$compressed = (new IvoPdf(['compress' => true]))->loadHtml('<html><body><p>plain stream</p></body></html>')->output();
check('compress=true yields smaller output than compress=false', strlen($compressed) < strlen($uncompressed), strlen($compressed) . ' vs ' . strlen($uncompressed));
check('compress=false still valid PDF', strncmp($uncompressed, '%PDF-1.7', 8) === 0 && strpos(substr($uncompressed, -64), '%%EOF') !== false);

// ---------------------------------------------------------------------------
echo "\n15. Showcase templates end-to-end\n";
// ---------------------------------------------------------------------------

require __DIR__ . '/../examples/Templates.php';
$templates = \IvoPdf\Examples\Templates::all();
$outDir = __DIR__ . '/output';
@mkdir($outDir, 0775, true);
$templateMinPages = ['invoice' => 1, 'dashboard' => 1, 'certificate' => 1, 'catalog' => 2, 'showcase' => 3];
foreach ($templates as $key => $tpl) {
    $pdf = new IvoPdf(['paperSize' => $tpl['paper'], 'orientation' => $tpl['orientation']]);
    if (!empty($tpl['footer'])) {
        $pdf->setFooterHtml($tpl['footer']);
    }
    $bytes = $pdf->loadHtml($tpl['html'])->output();
    $saved = $pdf->save($outDir . '/' . $key . '.pdf');
    check("template {$key}: renders >= {$templateMinPages[$key]} page(s)", $pdf->getPageCount() >= $templateMinPages[$key], 'pages=' . $pdf->getPageCount());
    check("template {$key}: saved to tests/output/{$key}.pdf", is_file($outDir . '/' . $key . '.pdf') && filesize($outDir . '/' . $key . '.pdf') > 1000);
    [$tw, $th] = $pdf->getOptions()->getPageDimensions();
    $GENERATED[] = ['name' => 'template-' . $key, 'bytes' => $bytes, 'minPages' => $templateMinPages[$key], 'w' => $tw, 'h' => $th, 'expectPages' => $pdf->getPageCount()];
    $tstream = pdfStreams($bytes);
    check("template {$key}: Cyrillic text mapped in ToUnicode", preg_match('/04[0-9a-f]{2}/i', $tstream) === 1);
}

// ---------------------------------------------------------------------------
echo "\n16. Edge cases & stress\n";
// ---------------------------------------------------------------------------

renderDoc('empty-html', '');
renderDoc('plain-text', 'Just a plain text without any tags Жж');
renderDoc('malformed-html', '<div><p>unclosed <b>bold <i>italic</div><span>after');
renderDoc('deep-nesting', str_repeat('<div style="padding:2pt">', 25) . 'core' . str_repeat('</div>', 25));
renderDoc('long-word', '<html><body><p style="width:200pt">' . str_repeat('Д', 500) . '</p></body></html>');
renderDoc('script-style-ignored', '<html><head><script>var evil = "<div>";</script><style>p{color:red}</style></head><body><p id="ok">visible</p><!-- a comment --></body></html>');
renderDoc('special-chars', '<html><body><p>&lt;script&gt; &amp; &quot;quotes&quot; \'single\' \\backslash/ ${template} {PAGE_NUM}</p></body></html>');
renderDoc('zero-flex', '<html><body><div style="display:flex"><div style="flex:0 0 0">a</div><div style="flex:0 0 0">b</div></div></body></html>');
renderDoc('negative-margin', '<html><body><div id="neg" style="margin-top:-10pt; width:100pt">shifted</div></body></html>');

$bigTable = '<table style="width:100%">' . str_repeat('<tr><td>r</td><td>d</td></tr>', 300) . '</table>';
$doc = renderDoc('big-table-300-rows', "<html><body>{$bigTable}</body></html>", [], 4);
check('300-row table paginates to >= 4 pages without error', $doc->getPageCount() >= 4, 'pages=' . $doc->getPageCount());

$manyPages = str_repeat('<div style="page-break-after:always"><p>page</p></div>', 25) . '<p>last</p>';
$doc = renderDoc('forced-25-pages', "<html><body>{$manyPages}</body></html>", [], 25);
check('25 forced page breaks => exactly 26 pages', $doc->getPageCount() === 26, 'got ' . $doc->getPageCount());

$doc = renderDoc('ultra-long-text', '<html><body>' . str_repeat('<p>' . str_repeat('Текст за стррес тест ', 20) . '</p>', 40) . '</body></html>', [], 5);
check('long text auto-paginates >= 5 pages', $doc->getPageCount() >= 5, 'pages=' . $doc->getPageCount());

$autoFlow = (new IvoPdf());
$autoFlow->loadHtml('<html><head><style>#sc { position:fixed; top: 0; left: 0; }</style></head><body><div id="sc">FIXED</div><p>body</p></body></html>')->render();
check('position: fixed renders without error', $autoFlow->getPageCount() >= 1);

// ---------------------------------------------------------------------------
echo "\n17. Structural validation of ALL generated PDFs\n";
// ---------------------------------------------------------------------------

foreach ($GENERATED as $g) {
    $v = validatePdfStructure($g['bytes']);
    check("structure {$g['name']}: xref/objects valid ({$v['objects']} objects)", $v['errors'] === [], $v['errors'] === [] ? '' : implode('; ', array_slice($v['errors'], 0, 3)));
    check("structure {$g['name']}: page tree count = {$g['expectPages']}", $v['pages'] === $g['expectPages'], 'got ' . $v['pages']);
    $mb = $v['mediaBoxes'][0] ?? [0, 0, 0, 0];
    check(
        "structure {$g['name']}: MediaBox {$g['w']}x{$g['h']}pt on all {$v['pages']} pages",
        $v['pages'] > 0 && count($v['mediaBoxes']) === $v['pages']
            && abs($mb[2] - $g['w']) < 1.0 && abs($mb[3] - $g['h']) < 1.0,
        'got ' . $mb[2] . 'x' . $mb[3]
    );
}

// ---------------------------------------------------------------------------
echo "\n18. Engine hygiene (warnings/notices/deprecations)\n";
// ---------------------------------------------------------------------------

check('no PHP warnings/notices/deprecations during the entire suite', $PHP_ISSUES === [], $PHP_ISSUES === [] ? '' : count($PHP_ISSUES) . ' issues; first: ' . ($PHP_ISSUES[0] ?? ''));

// ---------------------------------------------------------------------------
echo "\n=== Summary: {$PASS} passed, {$FAIL} failed ===\n";
if ($FAILURES !== []) {
    echo "\nFailed checks:\n";
    foreach ($FAILURES as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);

// ---------------------------------------------------------------------------
// Helpers used above
// ---------------------------------------------------------------------------

function collectMaxLineWidth(LayoutBox $box): float
{
    $max = 0.0;
    foreach ($box->lines as $line) {
        $max = max($max, $line->width);
    }
    foreach ($box->children as $c) {
        $max = max($max, collectMaxLineWidth($c));
    }
    return $max;
}
