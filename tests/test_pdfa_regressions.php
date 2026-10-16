<?php

declare(strict_types=1);

/**
 * Regressions found with veraPDF 1.30.3 in production use:
 *  1. PDF/A-2b/3b images carried /Interpolate true (ISO 19005-2 6.2.8-3).
 *  2. Subsets of fonts with numberOfHMetrics < numGlyphs (DejaVu Sans Mono) had zero advances (19005-2 6.2.11.5-1).
 *  3. min-width / min-height were ignored for shrink-to-fit boxes (empty inline-block, table cells, floats…).
 *
 * Run: php tests/test_pdfa_regressions.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Font\TtfParser;
use IvoPdf\Font\TtfSubsetter;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Pdf\PdfAChecker;

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$str} @ {$file}:{$line}";
    return true;
});

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function alphaPng(int $w = 400, int $h = 120): string
{
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imageline($im, 10, $h - 20, $w - 10, 20, imagecolorallocatealpha($im, 10, 20, 120, 0));
    ob_start();
    imagepng($im);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

/** @return list<string> every `/Interpolate x` value found in object dictionaries */
function interpolateValues(string $pdf): array
{
    preg_match_all('~/Interpolate\s+(true|false)~', $pdf, $m);
    return $m[1];
}

function findById(LayoutBox $box, string $id): ?LayoutBox
{
    if ($box->domElement?->getAttribute('id') === $id) {
        return $box;
    }
    foreach ($box->children as $child) {
        if (($r = findById($child, $id)) !== null) {
            return $r;
        }
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null && ($r = findById($run->inlineBox, $id)) !== null) {
                return $r;
            }
        }
    }
    return null;
}

/**
 * Advance widths (1/1000 em) from a TrueType program's hmtx table (works for subsets without cmap).
 *
 * @return array<int, float>
 */
function hmtxAdvances(string $font): array
{
    $numTables = unpack('n', substr($font, 4, 2))[1];
    $tables = [];
    for ($i = 0; $i < $numTables; $i++) {
        $u = unpack('a4tag/Nsum/Noff/Nlen', substr($font, 12 + $i * 16, 16));
        $tables[$u['tag']] = [$u['off'], $u['len']];
    }
    $upm = unpack('n', substr($font, $tables['head'][0] + 18, 2))[1];
    $numH = unpack('n', substr($font, $tables['hhea'][0] + 34, 2))[1];
    $numGlyphs = unpack('n', substr($font, $tables['maxp'][0] + 4, 2))[1];
    $out = [];
    $last = 0;
    for ($g = 0; $g < $numGlyphs; $g++) {
        if ($g < $numH) {
            $last = unpack('n', substr($font, $tables['hmtx'][0] + $g * 4, 2))[1];
        }
        $out[$g] = $last * 1000.0 / $upm;
    }
    return $out;
}

$png = alphaPng();

// ---------------------------------------------------------------------------
echo "1. /Interpolate in PDF/A\n";
// ---------------------------------------------------------------------------
foreach (['1b', '2b', '3b'] as $level) {
    $pdf = (new IvoPdf(['pdfa' => $level, 'compress' => false]))
        ->loadHtml('<p>Подпис:</p><img src="' . $png . '" style="width:120pt"><img src="' . $png . '" style="width:60pt; image-rendering: pixelated">')
        ->output();
    $values = interpolateValues($pdf);
    check("pdfa {$level}: no image requests interpolation", !in_array('true', $values, true), implode(',', $values));
    $issues = PdfAChecker::check($pdf, (int) $level);
    check("pdfa {$level}: checker reports no issues", $issues === [], implode(' | ', $issues));
}
$plain = (new IvoPdf(['compress' => false]))->loadHtml('<img src="' . $png . '"><img src="' . $png . '" style="image-rendering: pixelated">')->output();
$values = interpolateValues($plain);
check('non-PDF/A output keeps /Interpolate true for normal images', in_array('true', $values, true), implode(',', $values));
check('non-PDF/A output keeps /Interpolate false for image-rendering: pixelated', in_array('false', $values, true), implode(',', $values));

$doc = (new IvoPdf(['pdfa' => '2b', 'compress' => false]))->loadHtml('<img src="' . $png . '">')->output();
$tampered = preg_replace('~/Interpolate false~', '/Interpolate true', $doc, 1);
$issues = PdfAChecker::check((string) $tampered, 2);
check('checker flags /Interpolate true for PDF/A-2 (not only PDF/A-1)', (bool) array_filter($issues, static fn(string $i): bool => str_contains($i, 'Interpolate')), implode(' | ', $issues));

// ---------------------------------------------------------------------------
echo "\n2. Glyph widths of embedded font subsets\n";
// ---------------------------------------------------------------------------
$fontDir = __DIR__ . '/../resources/fonts';
$sample = 'a3f5c9e1b2d4f60718293a4b5c6d7e8f9a0b1c2d Цена 0123456789 ЖЩЮ €';
foreach (glob($fontDir . '/*.ttf') ?: [] as $file) {
    $parser = new TtfParser($file);
    $gids = [0 => 0];
    foreach (\IvoPdf\Font\FontManager::utf8ToCodepoints($sample) as $cp) {
        $gids[$cp] = $parser->getGlyphId($cp);
    }
    $subset = TtfSubsetter::subset($parser->getRawData(), array_values($gids));
    if ($subset === null) {
        check(basename($file) . ': subset produced', false);
        continue;
    }
    $advances = hmtxAdvances($subset);
    $bad = [];
    foreach (array_unique(array_values($gids)) as $gid) {
        $expected = $parser->getGlyphWidthByGid($gid);
        if (abs(($advances[$gid] ?? -1) - $expected) > 1.0) {
            $bad[] = "{$gid}: " . round($advances[$gid] ?? -1) . " != {$expected}";
        }
    }
    check(basename($file) . " (numberOfHMetrics {$parser->numberOfHMetrics}/{$parser->numGlyphs}): subset advances match", $bad === [], implode(', ', array_slice($bad, 0, 4)));
}

foreach (['1b', '2b', '3b'] as $level) {
    $html = '<p>Hash: <span style="font-family: monospace;">' . hash('sha256', 'ivopdf') . '</span></p>'
        . '<p style="font-family: serif">Serif <b>bold</b> <i>italic</i> <b><i>both</i></b></p>'
        . '<p>Sans <b>bold</b> <i>oblique</i> <span style="font-family: monospace"><b>mono bold</b> <i>mono oblique</i></span></p>';
    $pdf = (new IvoPdf(['pdfa' => $level]))->loadHtml($html)->output();
    $issues = PdfAChecker::check($pdf, (int) $level);
    check("pdfa {$level}: all embedded fonts (Sans/Serif/Mono, bold/italic) are consistent with /W", $issues === [], implode(' | ', $issues));
}

$mono = (new IvoPdf(['pdfa' => '2b', 'compress' => false]))->loadHtml('<span style="font-family: monospace">abc</span>')->output();
$tampered = preg_replace('~(/W \[\s*\d+\s*\[)\s*602~', '${1}500', $mono, 1);
$issues = PdfAChecker::check((string) $tampered, 2);
check('checker flags a /W entry that differs from the embedded program', (bool) array_filter($issues, static fn(string $i): bool => str_contains($i, 'differs from /W')), implode(' | ', $issues));

$otf = null;
foreach (glob('C:/Windows/Fonts/*.otf') ?: [] as $candidate) {
    $otf = $candidate;
    break;
}
if ($otf !== null) {
    $pdf = new IvoPdf(['pdfa' => '2b', 'allowedPaths' => [dirname($otf)]]);
    $pdf->registerFontFamily('SysOtf', ['regular' => $otf]);
    $bytes = $pdf->loadHtml('<p style="font-family: SysOtf">OpenType CFF text 123</p>')->output();
    check('OpenType/CFF font output passes the checker (' . basename($otf) . ')', PdfAChecker::check($bytes, 2) === [], implode(' | ', PdfAChecker::check($bytes, 2)));
} else {
    echo "  [SKIP] no system .otf font found\n";
}

// ---------------------------------------------------------------------------
echo "\n3. min-width / min-height on shrink-to-fit boxes\n";
// ---------------------------------------------------------------------------
$cases = [
    ['empty inline-block uses min-width', '<p>Подпис: <span id="t" style="display:inline-block; min-width:190px; border-bottom:1px dotted #000; height:30px"></span></p>', 142.5, null],
    ['inline-block with short text uses min-width', '<p><span id="t" style="display:inline-block; min-width:190px">ab</span></p>', 142.5, null],
    ['min-width wins over a smaller max-width', '<p><span id="t" style="display:inline-block; min-width:190px; max-width:100px"></span></p>', 142.5, null],
    ['max-width caps a wide inline-block', '<p><span id="t" style="display:inline-block; max-width:60px">' . str_repeat('word ', 20) . '</span></p>', 45.0, null],
    ['inline-block min-height', '<p><span id="t" style="display:inline-block; width:50px; min-height:40px"></span></p>', 37.5, 30.0],
    ['float uses min-width', '<div><div id="t" style="float:left; min-width:200px">x</div></div>', 150.0, null],
    ['absolute uses min-width', '<div style="position:relative; height:50pt"><div id="t" style="position:absolute; top:0; left:0; min-width:200px">x</div></div>', 150.0, null],
    ['block min-width', '<div style="width:100px"><div id="t" style="min-width:190px; height:10px"></div></div>', 142.5, null],
];
foreach ($cases as [$label, $html, $width, $height]) {
    $pdf = (new IvoPdf())->loadHtml($html);
    $pdf->output();
    $box = findById($pdf->getLayoutTree(), 't');
    $ok = $box !== null && abs($box->width - $width) < 0.6 && ($height === null || abs($box->height - $height) < 0.6);
    check($label, $ok, $box === null ? 'box not found' : sprintf('w=%.1f h=%.1f', $box->width, $box->height));
}

$pdf = (new IvoPdf())->loadHtml('<table style="width:200px"><tr><td id="t" style="min-width:150px">a</td><td>b</td></tr></table>');
$pdf->output();
$td = findById($pdf->getLayoutTree(), 't');
check('table cell min-width raises the column width', $td !== null && $td->width >= 112.5 - 0.5, sprintf('w=%.1f', $td?->width ?? -1));

$pdf = (new IvoPdf())->loadHtml('<table><tr><td id="t" style="min-height:60px; padding:0">a</td></tr></table>');
$pdf->output();
$td = findById($pdf->getLayoutTree(), 't');
check('table cell min-height', $td !== null && $td->height >= 45.0 - 0.5, sprintf('h=%.1f', $td?->height ?? -1));

$raw = (new IvoPdf(['compress' => false]))->loadHtml('<span style="display:inline-block; min-width:190px; border-bottom:1px solid #000; height:30px"></span>')->output();
check('empty inline-block bottom border is drawn with its min-width', preg_match('~[\d.]+ [\d.]+ 142\.50 0\.75 re~', $raw) === 1);

// ---------------------------------------------------------------------------
echo "\n4. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', $phpIssues === [], count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
