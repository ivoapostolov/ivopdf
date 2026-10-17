<?php

declare(strict_types=1);

/**
 * Regression tests for audit #4 findings 6-9:
 *  - auto-width <table> is shrink-to-fit; margin auto / align= / float position it (text-align on the parent does not)
 *  - `font:` shorthand with several keyword tokens before the size, small-caps, reset of unspecified sub-properties
 *  - page-break-before/after + break-before/after left|right|recto|verso, @page size grammar, <wbr>, ex / ch units,
 *    <noscript> hidden, save() to an invalid path throws without PHP warnings
 *  - @font-face data: URIs stay in memory (no predictable temp file)
 *
 * Run: php tests/test_audit_css4.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$str} @ {$file}:{$line}";
    return true;
});

$passed = 0;
$failed = 0;
$skipped = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label}\n";
}

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false, 'defaultFont' => 'DejaVu Sans'], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

function findById(LayoutBox $box, string $id): ?LayoutBox
{
    if ($box->domElement?->getAttribute('id') === $id) {
        return $box;
    }
    foreach ($box->children as $c) {
        $f = findById($c, $id);
        if ($f !== null) {
            return $f;
        }
    }
    return null;
}

function box(IvoPdf $pdf, string $id): LayoutBox
{
    $b = findById($pdf->getLayoutTree(), $id);
    if ($b === null) {
        throw new RuntimeException("no element #{$id}");
    }
    return $b;
}

/** @return list<list<array{0: float, 1: float, 2: float, 3: float, 4: string}>>|null words per page via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-css4-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-css4-words.py';
    file_put_contents($scriptFile, "import fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([[list(w[:5]) for w in p.get_text('words')] for p in d]))\n");
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** 1-based number of the page that shows $word, or 0 */
function pageOfWord(?array $pages, string $word): int
{
    foreach ($pages ?? [] as $i => $words) {
        foreach ($words as $w) {
            if ($w[4] === $word) {
                return $i + 1;
            }
        }
    }
    return 0;
}

/** @return array{0: float, 1: float}|null MediaBox size of page 1 */
function mediaBox(IvoPdf $pdf): ?array
{
    if (preg_match('/\/MediaBox\s*\[\s*0 0 ([\d.]+) ([\d.]+)\s*\]/', $pdf->output(), $m) === 1) {
        return [(float) $m[1], (float) $m[2]];
    }
    return null;
}

const LEFT = 42.0; // default page margin 36pt + body margin 8px (6pt), as in browsers
$cell = '<tr><td>x</td></tr>';

// ---------------------------------------------------------------------------
echo "6. <table> without a width: shrink-to-fit and alignment\n";
// ---------------------------------------------------------------------------
$pdf = render("<table id='t'>{$cell}</table>");
$t = box($pdf, 't');
check('plain table is as wide as its content, not the full line', $t->width < 40.0, (string) $t->width);
check('plain table starts at the left margin', abs($t->x - LEFT) < 0.5);
$pdf = render("<table id='t' style='width:auto'>{$cell}</table>");
check('width:auto behaves like no width', box($pdf, 't')->width < 40.0);
$pdf = render("<table id='t' style='width:100%'>{$cell}</table>");
check('width:100% still fills the line', box($pdf, 't')->width > 500.0, (string) box($pdf, 't')->width);
$pdf = render("<table id='t' style='width:300pt'>{$cell}</table>");
check('explicit width is unchanged', abs(box($pdf, 't')->width - 300.0) < 0.5);
$pdf = render("<table id='t' width='400'>{$cell}</table>");
check('width attribute is unchanged (400px = 300pt)', abs(box($pdf, 't')->width - 300.0) < 0.5, (string) box($pdf, 't')->width);
$long = str_repeat('word ', 200);
$pdf = render("<table id='t'><tr><td>{$long}</td></tr></table>");
$t = box($pdf, 't');
check('table with long text still stops at the available width', $t->width > 500.0 && $t->width < 524.0, (string) $t->width);
$pdf = render("<table id='t'><tr><td>Short</td><td>cells here</td></tr></table>");
$w2 = box($pdf, 't')->width;
$pdf = render("<table id='t'><tr><td>Short</td><td>cells here and many more words</td></tr></table>");
check('wider content gives a wider table', box($pdf, 't')->width > $w2 + 20.0);
$pdf = render("<table id='t' border='1'><colgroup><col width='120'><col></colgroup><tr><td>a</td><td>b</td></tr></table>");
check('<col width> is honoured by the shrink-to-fit width', box($pdf, 't')->width > 90.0, (string) box($pdf, 't')->width);

$pdf = render("<table id='t' style='margin-left:auto;margin-right:auto'>{$cell}</table>");
$t = box($pdf, 't');
check('margin-left/right:auto centres an auto-width table', abs(($t->x + $t->width / 2) - (595.28 / 2)) < 1.0, (string) $t->x);
$pdf = render("<table id='t' style='margin-left:auto'>{$cell}</table>");
$t = box($pdf, 't');
check('margin-left:auto alone pushes the table to the right', abs($t->x + $t->width - (595.28 - LEFT)) < 1.0, (string) ($t->x + $t->width));
$pdf = render("<table id='t' style='margin-right:auto'>{$cell}</table>");
check('margin-right:auto alone keeps the table on the left', abs(box($pdf, 't')->x - LEFT) < 0.5);
$pdf = render("<table id='t' align='center'>{$cell}</table>");
$t = box($pdf, 't');
check('align="center" centres the table', abs(($t->x + $t->width / 2) - (595.28 / 2)) < 1.0);
$pdf = render("<table id='t' align='right'>{$cell}</table>");
$t = box($pdf, 't');
check('align="right" floats the table to the right', abs($t->x + $t->width - (595.28 - LEFT)) < 1.0 && $t->width < 40.0, (string) ($t->x + $t->width));
$pdf = render("<table id='t' align='left'>{$cell}</table><p id='after'>text next to it</p>");
$t = box($pdf, 't');
$after = box($pdf, 'after');
check('align="left" floats the table and the following text wraps beside it', abs($t->x - LEFT) < 0.5 && $after->lines !== [] && $after->lines[0]->x > $t->x + $t->width - 0.5);
$pdf = render("<table id='t' style='float:right'>{$cell}</table>");
$t = box($pdf, 't');
check('float:right makes a compact right-hand table', abs($t->x + $t->width - (595.28 - LEFT)) < 1.0 && $t->width < 40.0);
$pdf = render("<div style='text-align:center'><table id='t'>{$cell}</table></div>");
check('text-align:center on the parent does NOT move a block table (as in browsers)', abs(box($pdf, 't')->x - LEFT) < 0.5);
$pdf = render("<center><table id='t'>{$cell}</table></center>");
$t = box($pdf, 't');
check('<center> centres a table (text-align: -webkit-center)', abs(($t->x + $t->width / 2) - (595.28 / 2)) < 1.0);
$pdf = render("<div align='right'><table id='t'>{$cell}</table></div>");
$t = box($pdf, 't');
check('<div align="right"> moves a table to the right', abs($t->x + $t->width - (595.28 - LEFT)) < 1.0);
$pdf = render("<div style='text-align:center'><table id='t' style='display:inline-table'>{$cell}</table></div>");
$t = box($pdf, 't');
check('inline-table follows text-align:center', abs(($t->x + $t->width / 2) - (595.28 / 2)) < 2.0, (string) $t->x);
$pdf = render("<div style='display:flex'><table id='t'>{$cell}</table></div>");
check('table as flex item is content sized', box($pdf, 't')->width < 60.0, (string) box($pdf, 't')->width);

// ---------------------------------------------------------------------------
echo "\n7. font shorthand\n";
// ---------------------------------------------------------------------------
$fontCases = [
    'italic bold 14px Arial' => ['italic', 'bold', 'normal', 10.5, 'Arial'],
    'bold italic 14px Arial' => ['italic', 'bold', 'normal', 10.5, 'Arial'],
    '700 italic 14px serif' => ['italic', '700', 'normal', 10.5, 'serif'],
    'oblique bold 14px Arial' => ['oblique', 'bold', 'normal', 10.5, 'Arial'],
    'small-caps 14px Arial' => ['normal', 'normal', 'small-caps', 10.5, 'Arial'],
    'italic small-caps bold 14px Arial' => ['italic', 'bold', 'small-caps', 10.5, 'Arial'],
    'normal normal bold 12pt Georgia' => ['normal', 'bold', 'normal', 12.0, 'Georgia'],
    'condensed bold 12pt Arial' => ['normal', 'bold', 'normal', 12.0, 'Arial'],
    '12pt Arial' => ['normal', 'normal', 'normal', 12.0, 'Arial'],
];
foreach ($fontCases as $decl => [$style, $weight, $variant, $size, $family]) {
    $pdf = render("<p id='p' style=\"font-style:italic;font-weight:900;font-variant:small-caps;font: {$decl}\">x</p>");
    $s = box($pdf, 'p')->style;
    $ok = $s->get('font-style') === $style && $s->get('font-weight') === $weight && $s->get('font-variant') === $variant
        && abs($s->getFontSizePt() - $size) < 0.01 && $s->get('font-family') === $family;
    check("font: {$decl}", $ok, json_encode([$s->get('font-style'), $s->get('font-weight'), $s->get('font-variant'), $s->getFontSizePt(), $s->get('font-family')]));
}
$pdf = render("<p id='p' style=\"font: bold 10px/20px Arial\">x</p>");
check('line-height after the slash', box($pdf, 'p')->style->get('line-height') === '20px');
$pdf = render("<p id='p' style=\"line-height:3;font: 10px Arial\">x</p>");
check('shorthand resets an earlier line-height', box($pdf, 'p')->style->get('line-height') === 'normal');
$pdf = render("<p id='p' style=\"font: 12pt 'Foo Bar', serif\">x</p>");
check('quoted family list is kept', box($pdf, 'p')->style->get('font-family') === "'Foo Bar', serif");
$pdf = render("<p id='p' style=\"font-size:20pt;font: bold\">x</p>");
check('invalid shorthand (no size / family) is ignored', abs(box($pdf, 'p')->style->getFontSizePt() - 20.0) < 0.01 && box($pdf, 'p')->style->get('font-weight') !== 'bold');
$pdf = render("<p id='p' style=\"font-size:20pt;font: bogus 12pt Arial\">x</p>");
check('unknown keyword before the size ignores the declaration', abs(box($pdf, 'p')->style->getFontSizePt() - 20.0) < 0.01);
$pdf = render("<p id='p' style=\"font: caption\">x</p>");
check('system font keyword degrades to a plain 13.33px face', abs(box($pdf, 'p')->style->getFontSizePt() - 10.0) < 0.05 && box($pdf, 'p')->style->get('font-weight') === 'normal');

// ---------------------------------------------------------------------------
echo "\n8a. forced page breaks to a left / right page\n";
// ---------------------------------------------------------------------------
$cases = [
    ['<p>AAA</p><p style="page-break-before:right">BBB</p>', 'BBB', 3, 'page-break-before:right from page 1 skips to page 3'],
    ['<p>AAA</p><p style="page-break-before:left">BBB</p>', 'BBB', 2, 'page-break-before:left goes to page 2'],
    ['<p>AAA</p><p style="break-before:verso">BBB</p>', 'BBB', 2, 'break-before:verso is a left page'],
    ['<p>AAA</p><p style="break-before:recto">BBB</p>', 'BBB', 3, 'break-before:recto is a right page'],
    ['<p style="page-break-after:always">AAA</p><p style="break-before:right">BBB</p>', 'BBB', 3, 'right after an explicit break to page 2 adds a blank page'],
    ['<p style="page-break-before:left">BBB</p>', 'BBB', 2, 'a left break at the very start leaves page 1 blank'],
    ['<p style="page-break-before:right">BBB</p>', 'BBB', 1, 'a right break at the very start needs no page'],
    ['<p style="page-break-after:right">AAA</p><p>BBB</p>', 'BBB', 3, 'page-break-after:right'],
    ['<p style="break-after:left">AAA</p><p>BBB</p>', 'BBB', 2, 'break-after:left'],
    ['<table style="page-break-after:left"><tr><td>AAA</td></tr></table><p>BBB</p>', 'BBB', 2, 'page-break-after:left on a table'],
];
foreach ($cases as [$html, $word, $expectPage, $label]) {
    $pdf = render($html);
    $pages = pdfWords($pdf);
    if ($pages === null) {
        skip("{$label}: PyMuPDF not available");
        continue;
    }
    check($label, pageOfWord($pages, $word) === $expectPage, 'on page ' . pageOfWord($pages, $word) . ', ' . count($pages) . ' pages');
}
$pdf = render('<p>A</p><h1 style="break-before:right">B</h1><h1 style="break-before:right">C</h1><h1 style="break-before:left">D</h1>');
check('chained side breaks: A=1 B=3 C=5 D=6', $pdf->getPageCount() === 6, (string) $pdf->getPageCount());
$pdf = render('<p>A</p><p style="page-break-before:always">B</p>');
check('page-break-before:always is unchanged', $pdf->getPageCount() === 2);

// ---------------------------------------------------------------------------
echo "\n8b. @page size\n";
// ---------------------------------------------------------------------------
$mm = 72.0 / 25.4;
$sizes = [
    '100mm' => [100 * $mm, 100 * $mm],
    '100mm 50mm' => [100 * $mm, 50 * $mm],
    '50mm 100mm' => [50 * $mm, 100 * $mm],
    'A5 landscape' => [595.28, 419.53],
    'landscape A5' => [595.28, 419.53],
    'letter' => [612.0, 792.0],
    'letter landscape' => [792.0, 612.0],
    'landscape' => [841.89, 595.28],
    'portrait' => [595.28, 841.89],
    'auto' => [595.28, 841.89],
    '8.5in 11in' => [612.0, 792.0],
];
foreach ($sizes as $decl => [$w, $h]) {
    $pdf = render("<style>@page { size: {$decl} }</style><p>x</p>");
    $mb = mediaBox($pdf);
    check("@page size: {$decl}", $mb !== null && abs($mb[0] - $w) < 0.1 && abs($mb[1] - $h) < 0.1, json_encode($mb));
}

// ---------------------------------------------------------------------------
echo "\n8c. <wbr>\n";
// ---------------------------------------------------------------------------
$pdf = render('<div id="d" style="width:40pt;font-size:10pt">aaaa<wbr>bbbb<wbr>cccc</div>');
check('<wbr> offers break opportunities (3 lines in a 40pt box)', count(box($pdf, 'd')->lines) === 3, (string) count(box($pdf, 'd')->lines));
$pdf = render('<div id="d" style="width:300pt;font-size:10pt">aaaa<wbr>bbbb<wbr>cccc</div>');
$words = pdfWords($pdf);
if ($words === null) {
    skip('<wbr> that is not needed: PyMuPDF not available');
} else {
    check('<wbr> that is not needed does not split or add space', count($words[0]) === 1 && $words[0][0][4] === 'aaaabbbbcccc', json_encode($words[0]));
}
$pdf = render('<div id="d" style="width:40pt;font-size:10pt">aaaa<wbr>bbbb</div>');
$words = pdfWords($pdf);
if ($words === null) {
    skip('<wbr> hyphen check: PyMuPDF not available');
} else {
    $texts = array_map(static fn(array $w): string => $w[4], $words[0]);
    check('<wbr> break draws no hyphen', $texts === ['aaaa', 'bbbb'], json_encode($texts));
}

// ---------------------------------------------------------------------------
echo "\n8d. ex and ch units\n";
// ---------------------------------------------------------------------------
$pdf = render('<div style="font-family:Inter;font-size:20pt"><div id="a" style="width:10ex">x</div><div id="b" style="width:10ch">x</div><div id="c" style="font-size:2ex">x</div></div>');
$interEx = box($pdf, 'a')->width / 10.0 / 20.0;
check('ex uses the font x-height (Inter: about 0.55 em, not 0.5)', $interEx > 0.52 && $interEx < 0.6, (string) $interEx);
$interCh = box($pdf, 'b')->width / 10.0 / 20.0;
check('ch uses the advance width of 0 (Inter: about 0.63 em)', $interCh > 0.58 && $interCh < 0.7, (string) $interCh);
check('font-size in ex is relative to the parent font', abs(box($pdf, 'c')->fontSizePt - 2 * $interEx * 20.0) < 0.1, (string) box($pdf, 'c')->fontSizePt);
$pdf = render('<div style="font-family:DejaVu Sans;font-size:20pt"><div id="a" style="width:10ex">x</div><div id="b" style="width:10ch">x</div></div>');
check('ex falls back to 0.5 em for fonts without sxHeight', abs(box($pdf, 'a')->width - 100.0) < 0.5, (string) box($pdf, 'a')->width);
check('ch in DejaVu Sans is the width of the digit zero (0.636 em)', abs(box($pdf, 'b')->width - 127.2) < 1.0, (string) box($pdf, 'b')->width);
$pdf = render('<div style="width:5em" id="a">x</div>');
check('em still works', abs(box($pdf, 'a')->width - 60.0) < 0.01);

// ---------------------------------------------------------------------------
echo "\n8e. <noscript>\n";
// ---------------------------------------------------------------------------
$variants = [
    '<p>A</p><noscript>HIDDENNS</noscript><p>B</p>',
    '<p>A</p><noscript><p>HIDDENNS</p></noscript>',
    '<html><head><noscript><style>p{color:red}</style>HIDDENNS</noscript></head><body><p>x</p></body></html>',
];
foreach ($variants as $i => $html) {
    $words = pdfWords(render($html));
    if ($words === null) {
        skip("noscript variant {$i}: PyMuPDF not available");
        continue;
    }
    check("<noscript> content is not rendered (variant {$i})", pageOfWord($words, 'HIDDENNS') === 0);
}

// ---------------------------------------------------------------------------
echo "\n8f. save() to an invalid path\n";
// ---------------------------------------------------------------------------
$before = count($phpIssues);
$pdf = new IvoPdf(['compress' => false]);
$pdf->loadHtml('<p>x</p>');
$threw = false;
$msg = '';
try {
    $pdf->save(PHP_OS_FAMILY === 'Windows' ? 'Z:\\ivopdf-nonexistent-drive\\x.pdf' : '/proc/ivopdf/nonexistent/x.pdf');
} catch (RuntimeException $e) {
    $threw = true;
    $msg = $e->getMessage();
}
check('save() to a missing drive / read-only location throws RuntimeException with a clear message', $threw && str_contains($msg, 'Failed to write PDF'), $msg);
check('save() failure emits no PHP warnings', count($phpIssues) === $before, implode(' | ', array_slice($phpIssues, $before, 2)));
$ok = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-css4-' . md5(uniqid('', true)) . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'ok.pdf';
$pdf->save($ok);
check('save() still creates missing directories', is_file($ok) && str_starts_with((string) file_get_contents($ok), '%PDF'));
@unlink($ok);
@rmdir(dirname($ok));
@rmdir(dirname($ok, 2));

// ---------------------------------------------------------------------------
echo "\n9. @font-face data: URIs\n";
// ---------------------------------------------------------------------------
$ttf = (string) file_get_contents(__DIR__ . '/../resources/fonts/DejaVuSansMono.ttf');
$tempPattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-font-*';
$tempBefore = glob($tempPattern) ?: [];
$uri = 'data:font/ttf;base64,' . base64_encode($ttf);
$faceHtml = '<style>@font-face{font-family:"MemFont";src:url(' . $uri . ')}</style><p style="font-family:MemFont">Hello</p>';
$pdf = new IvoPdf(['compress' => false, 'defaultFont' => 'Inter']);
$pdf->loadHtml($faceHtml);
check('data: font is embedded in the PDF', str_contains($pdf->output(), 'DejaVuSansMono'));
check('no temp file with a predictable name is written', (glob($tempPattern) ?: []) === $tempBefore);
$copies = array_filter(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*') ?: [], static fn(string $f): bool => is_file($f) && filesize($f) === strlen($ttf) && filemtime($f) >= time() - 60 && md5_file($f) === md5($ttf));
check('no copy of the font is left in the temp directory', $copies === [], implode(',', $copies));
// a planted file under the old predictable name must not matter
$poison = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-font-' . md5($ttf) . '.ttf';
$planted = !file_exists($poison) && file_put_contents($poison, 'not a font at all, planted by another user') !== false;
$pdf2 = new IvoPdf(['compress' => false, 'defaultFont' => 'Inter']);
$pdf2->loadHtml($faceHtml);
check('a planted file with the old predictable name is ignored', str_contains($pdf2->output(), 'DejaVuSansMono') && $pdf2->getOptions()->getWarnings() === []);
if ($planted) {
    @unlink($poison);
}
$pdf3 = new IvoPdf(['compress' => false]);
$pdf3->loadHtml('<style>@font-face{font-family:"Bad";src:url(data:font/ttf;base64,AAAAAAAAAAAAAAAAAAAA)}</style><p style="font-family:Bad">Hello</p>');
$pdf3->output();
check('an invalid data: font only produces a warning', count($pdf3->getOptions()->getWarnings()) === 1);
$pdf4 = new IvoPdf(['compress' => false, 'defaultFont' => 'Inter']);
$pdf4->loadHtml($faceHtml);
$pdf4->output();
$pdf4->loadHtml(str_replace('Hello', 'Again', $faceHtml));
check('re-rendering the same instance keeps working', str_contains($pdf4->output(), 'DejaVuSansMono'));

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
