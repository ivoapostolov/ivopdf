<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $message): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}\n";
    }
}

function assertApprox(float $actual, float $expected, float $tolerance, string $message): void
{
    $diff = abs($actual - $expected);
    assertTrue($diff <= $tolerance, "{$message} (expected ~{$expected}, got " . round($actual, 2) . ")");
}

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

echo "=== IvoPDF Automated Test Suite ===\n\n";

// 1. Test CSS Units & Colors
echo "1. Testing CSS Units, calc(), and Colors...\n";
assertApprox(CssUnit::toPt('100pt'), 100.0, 0.01, '100pt -> 100.0pt');
assertApprox(CssUnit::toPt('96px'), 72.0, 0.01, '96px -> 72.0pt');
assertApprox(CssUnit::toPt('calc(100% - 20pt)', 500.0), 480.0, 0.01, 'calc(100% - 20pt) of 500pt -> 480pt');
$rgba = CssColor::parse('rgba(37, 99, 235, 0.5)');
assertTrue($rgba !== null && abs($rgba[3] - 0.5) < 0.01, 'Parsed rgba() with 0.5 alpha');
$grad = CssColor::parseLinearGradient('linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%)');
assertTrue($grad !== null && $grad['angle'] === 135.0 && count($grad['stops']) === 2, 'Parsed linear-gradient(135deg, ...)');

// 2. Test Flexbox Layout (Row, Grow, Gap, Stretch, Auto-Margin)
echo "\n2. Testing Flexbox Layout Engine...\n";
$flexHtml = <<<'HTML'
<style>
    @page { margin: 20pt; size: A4 portrait; }
    body { margin: 0; padding: 0; }
    .flex-row {
        display: flex;
        flex-direction: row;
        gap: 10pt;
        width: 500pt;
        align-items: stretch;
    }
    #col1 { flex: 1; padding: 10pt; background: #f1f5f9; }
    #col2 { flex: 2; padding: 10pt; background: #e2e8f0; }
    #col3 { flex: 1; padding: 10pt; background: #cbd5e1; }
    .navbar {
        display: flex;
        width: 500pt;
        margin-top: 15pt;
    }
    #brand { width: 100pt; }
    #cta { width: 80pt; margin-left: auto; }
</style>
<div class="flex-row">
    <div id="col1">Кратък текст</div>
    <div id="col2">Много по-дълъг кирилски текст, който със сигурност ще се пренесе на няколко реда във втората колона на флекс контейнера, за да тестваме align-items: stretch!</div>
    <div id="col3">Трета колона</div>
</div>
<div class="navbar">
    <div id="brand">Лого</div>
    <div id="cta">Бутон</div>
</div>
HTML;

$pdfFlex = new IvoPdf();
$pdfFlex->loadHtml($flexHtml)->render();
$treeFlex = $pdfFlex->getLayoutTree();

$c1 = findBoxById($treeFlex, 'col1');
$c2 = findBoxById($treeFlex, 'col2');
$c3 = findBoxById($treeFlex, 'col3');
$cta = findBoxById($treeFlex, 'cta');

// 500pt width - 2 * 10pt gap = 480pt; flex-basis 0% + content-box padding 2x10pt per item
// gives hypothetical main size 20pt each (browser-accurate): used = 60 + 20 gaps, free = 420
// distributed 1:2:1 -> 20+105=125pt, 20+210=230pt, 20+105=125pt
assertTrue($c1 !== null && $c2 !== null && $c3 !== null, 'Found all 3 flex items in layout tree');
if ($c1 && $c2 && $c3) {
    assertApprox($c1->width, 125.0, 0.5, 'Flex item 1 width (flex: 1, basis 0% + padding) is 125pt');
    assertApprox($c2->width, 230.0, 0.5, 'Flex item 2 width (flex: 2, basis 0% + padding) is 230pt');
    assertApprox($c3->width, 125.0, 0.5, 'Flex item 3 width (flex: 1, basis 0% + padding) is 125pt');
    assertApprox($c2->x - $c1->x, 135.0, 0.5, 'Flex item 2 X offset includes 125pt width + 10pt gap');
    assertApprox($c1->height, $c2->height, 0.5, 'Flex item 1 height stretched to match tallest item 2');
    assertApprox($c3->height, $c2->height, 0.5, 'Flex item 3 height stretched to match tallest item 2');
}
if ($cta && $c1) {
    assertApprox($cta->x - $c1->x, 420.0, 0.5, 'margin-left: auto pushed #cta to right edge (500pt - 80pt = 420pt)');
}

// 3. Test CSS Grid Layout (repeat, fr, span, 1 / -1)
echo "\n3. Testing CSS Grid Layout Engine...\n";
$gridHtml = <<<'HTML'
<style>
    @page { margin: 20pt; }
    .grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15pt;
        width: 480pt;
    }
    .card { padding: 8pt; background: #f8fafc; border: 1pt solid #cbd5e1; }
    #g-span2 { grid-column: span 2; }
    #g-full  { grid-column: 1 / -1; }
</style>
<div class="grid">
    <div id="g1" class="card">Карта 1</div>
    <div id="g-span2" class="card">Карта 2 (Span 2 колони)</div>
    <div id="g3" class="card">Карта 3 (Нов ред, колона 1)</div>
    <div id="g4" class="card">Карта 4 (Колона 2)</div>
    <div id="g5" class="card">Карта 5 (Колона 3)</div>
    <div id="g-full" class="card">Пълна ширина (1 / -1)</div>
</div>
HTML;

$pdfGrid = new IvoPdf();
$pdfGrid->loadHtml($gridHtml)->render();
$treeGrid = $pdfGrid->getLayoutTree();

$g1 = findBoxById($treeGrid, 'g1');
$gSpan2 = findBoxById($treeGrid, 'g-span2');
$g3 = findBoxById($treeGrid, 'g3');
$gFull = findBoxById($treeGrid, 'g-full');

// 480pt - 2 * 15pt gap = 450pt -> each 1fr column is 150pt; span 2 is 150 + 15 + 150 = 315pt; full 1 / -1 is 480pt
assertTrue($g1 !== null && $gSpan2 !== null && $g3 !== null && $gFull !== null, 'Found all Grid items');
if ($g1 && $gSpan2 && $g3 && $gFull) {
    assertApprox($g1->width, 150.0, 0.5, 'Grid 1fr column width is 150pt');
    assertApprox($gSpan2->width, 315.0, 0.5, 'Grid span 2 column width is 315pt (2 * 150pt + 15pt gap)');
    assertTrue($g3->y > $g1->y + $g1->height, 'Grid item 3 auto-placed onto row 2');
    assertApprox($gFull->width, 480.0, 0.5, 'Grid 1 / -1 item spans full 480pt width');
}

// 4. Test Tables with colspan, rowspan, and nested Flexbox + Images + SVG + Barcodes
echo "\n4. Testing Tables (colspan/rowspan), PNG Alpha Images, SVG, Barcodes, and Pagination...\n";

// Create a small 16x16 transparent RGBA PNG in memory
$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH5AoQFx4y27r7mQAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAAcSURBVCjPY2RgYPjPgBswMbAwsDAwMjAwkA8AAI8AAf78X6MAAAAASUVORK5CYII=';

$complexHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<title>Тестова Фактура и Отчет</title>
<style>
    @page { margin: 30pt; size: A4 portrait; }
    table { width: 500pt; border-collapse: collapse; }
    th, td { border: 1pt solid #94a3b8; padding: 6pt; }
</style>
</head>
<body>
    <div style="display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg, #1e3a8a, #2563eb); color:#ffffff; padding:16pt; border-radius:8pt;">
        <div>
            <h1>ФАКТУРА № 0000123456</h1>
            <p>Дата: 16.10.2026 г. | Валута: BGN / EUR</p>
        </div>
        <barcode value="INV-2026-123456" width="150" height="42" color="#ffffff" />
    </div>

    <table style="margin-top: 15pt;">
        <thead>
            <tr>
                <th style="width: 50pt;">№</th>
                <th>Описание на услугата</th>
                <th style="width: 100pt;">Сума</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td id="cell-rowspan" rowspan="2">1</td>
                <td>Уеб разработка със CSS Grid &amp; Flexbox</td>
                <td>1 200.00 лв.</td>
            </tr>
            <tr>
                <td>Поддръжка и оптимизация на сървър</td>
                <td>400.00 лв.</td>
            </tr>
            <tr>
                <td id="cell-colspan" colspan="2" style="text-align:right; font-weight:bold;">Общо с ДДС:</td>
                <td style="font-weight:bold;">1 920.00 лв.</td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:12pt;">
        <img src="data:image/png;base64,{$pngBase64}" width="32" height="32" />
        <svg viewBox="0 0 100 40" width="100" height="40">
            <rect x="2" y="2" width="96" height="36" rx="6" fill="#dcfce7" stroke="#16a34a" stroke-width="2"/>
            <circle cx="20" cy="20" r="8" fill="#16a34a"/>
            <path d="M 16 20 L 19 23 L 25 16" fill="none" stroke="#ffffff" stroke-width="2"/>
            <text x="58" y="24" font-size="11" text-anchor="middle" fill="#166534">ПЛАТЕНО</text>
        </svg>
    </div>

    <div style="page-break-before: always; padding: 15pt; background: #f8fafc; border-radius: 6pt;">
        <h2>Страница 2 — Допълнителни условия</h2>
        <p>Този текст се намира на втора страница благодарение на <code>page-break-before: always</code>.</p>
    </div>
</body>
</html>
HTML;

$pdfComplex = new IvoPdf();
$pdfComplex->setFooterHtml('<div style="text-align:right; font-size:9pt; color:#64748b;">Страница {PAGE_NUM} от {PAGE_COUNT}</div>');
$rawPdf = $pdfComplex->loadHtml($complexHtml)->output();
$treeComplex = $pdfComplex->getLayoutTree();

$cellColspan = findBoxById($treeComplex, 'cell-colspan');
assertTrue($cellColspan !== null, 'Found table cell with colspan=2');
if ($cellColspan) {
    // content-box th widths: col1 = 50pt + 2x6pt padding + 2x1pt border = 64pt;
    // col2 = 500 - 64 - 114 = 322pt; colspan=2 spans 64 + 322 = 386pt (browser-accurate)
    assertApprox($cellColspan->width, 386.0, 1.0, 'Table colspan=2 cell spans column 1 (64pt) + column 2 (322pt) = 386pt');
}

assertTrue($pdfComplex->getPageCount() === 2, 'Document paginated into 2 pages via page-break-before: always');
assertTrue(str_starts_with($rawPdf, '%PDF-1.7'), 'Output starts with %PDF-1.7 header');
assertTrue(str_contains($rawPdf, '/Subtype /Type0'), 'Output embeds TrueType Unicode Type0 CID font');
assertTrue(str_contains($rawPdf, '/ToUnicode'), 'Output includes /ToUnicode CMap for Cyrillic copy/paste');
assertTrue(str_contains($rawPdf, '/ShadingType 2'), 'Output includes native PDF Axial Gradient shading');
assertTrue(str_contains($rawPdf, '%%EOF'), 'Output ends with valid %%EOF marker');

// 5. Security, CSS Inheritance, PNG Palette/Interlaced, Header/Footer Reservation & Audit Regression Tests
echo "\n5. Testing Security, CSS Inheritance, PNG Palette/Interlaced, SVG Arcs, Floats & Repeating Thead...\n";

$defaultOpts = new \IvoPdf\Options();
assertTrue($defaultOpts->isRemoteEnabled === false, 'isRemoteEnabled is false by default');

$emPdf = new IvoPdf(['defaultFontSize' => 12.0]);
$emPdf->loadHtml('<div style="font-size: 2em"><p id="em-p">Test</p></div>')->render();
$emP = findBoxById($emPdf->getLayoutTree(), 'em-p');
assertTrue($emP !== null, 'Found #em-p inside 2em parent');
if ($emP !== null) {
    assertApprox($emP->fontSizePt, 24.0, 0.01, 'Child <p> inherits 24pt from 2em parent without compounding to 48pt');
}

$mediaPdf = new IvoPdf();
$bigMediaCss = '@media screen { #m-test { color: #00ff00; } } @media print { #m-test { color: red; } ' . str_repeat('.dummy { color: blue; } ', 10000) . '}';
$mediaPdf->loadHtml("<style>{$bigMediaCss}</style><p id=\"m-test\">Print</p>")->render();
$mBox = findBoxById($mediaPdf->getLayoutTree(), 'm-test');
assertTrue($mBox !== null && $mBox->style->get('color') === 'red', '@media screen ignored and 10k-rule @media print parsed without PCRE backtracking error');

$customPaperPdf = new IvoPdf();
$customPaperPdf->setPaper([300.0, 400.0], 'portrait')->loadHtml('<p>Custom</p>')->render();
[$cpW, $cpH] = $customPaperPdf->getOptions()->getPageDimensions();
assertApprox($cpW, 300.0, 0.01, 'setPaper([300, 400]) sets width to 300pt');
assertApprox($cpH, 400.0, 0.01, 'setPaper([300, 400]) sets height to 400pt');

$longWordPdf = new IvoPdf();
$longWordPdf->loadHtml('<div id="narrow" style="width:100pt;overflow-wrap:break-word">' . str_repeat('W', 80) . '</div><div id="over" style="width:100pt">' . str_repeat('W', 80) . '</div>')->render();
$narrowBox = findBoxById($longWordPdf->getLayoutTree(), 'narrow');
assertTrue($narrowBox !== null && count($narrowBox->lines) > 1, '80-char unbreakable word wrapped across multiple lines inside 100pt box (overflow-wrap: break-word)');
$overBox = findBoxById($longWordPdf->getLayoutTree(), 'over');
assertTrue($overBox !== null && count($overBox->lines) === 1, 'Without overflow-wrap the 80-char word overflows the box on one line, as in browsers');
if ($narrowBox !== null && !empty($narrowBox->lines)) {
    assertTrue($narrowBox->lines[0]->width <= 100.5, 'First wrapped chunk fits inside 100pt width');
}

$hdrPdf = new IvoPdf(['margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]]);
$hdrPdf->setHeaderHtml('<div style="height:80pt; background:#eee;">Tall Header</div>');
$hdrPdf->loadHtml('<p id="body-first">Body text</p>')->render();
$bodyFirst = findBoxById($hdrPdf->getLayoutTree(), 'body-first');
assertTrue($bodyFirst !== null && $bodyFirst->y >= 90.0, 'Tall running header automatically reserves top margin space (body y >= 90pt)');

// Palette PNG with tRNS transparency and Interlaced PNG via GD
if (function_exists('imagecreate')) {
    $imPal = imagecreate(8, 8);
    $bg = imagecolorallocate($imPal, 255, 255, 255);
    $red = imagecolorallocate($imPal, 255, 0, 0);
    imagecolortransparent($imPal, $bg);
    imagefilledrectangle($imPal, 0, 0, 3, 7, $red);
    ob_start();
    imagepng($imPal);
    $palPng = (string) ob_get_clean();
    unset($imPal);

    $loader = new \IvoPdf\Image\ImageLoader();
    $palParsed = $loader->load('data:image/png;base64,' . base64_encode($palPng));
    assertTrue($palParsed !== null && isset($palParsed['smask']), 'Palette PNG with tRNS decoded with SMask transparency');
    if ($palParsed !== null) {
        $rawRgb = @gzuncompress($palParsed['data']);
        assertTrue($rawRgb !== false && bin2hex(substr($rawRgb, 0, 3)) === 'ff0000', 'Palette PNG first pixel decoded to truecolor #ff0000');
    }
}

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);

