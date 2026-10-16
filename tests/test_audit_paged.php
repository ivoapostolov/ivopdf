<?php

declare(strict_types=1);

/**
 * Regression tests for the pagination / box-model audit findings:
 *  - <tfoot> written before <tbody> (visual order) and <tfoot> repeated on every page of a table
 *  - inline content (anonymous text, inline-block rows) next to block siblings across page breaks
 *  - vertical margin collapsing (parent / first child, parent / last child, empty blocks)
 *  - flex / grid items with overflow != visible have an automatic minimum size of 0
 *  - floats containing tables are shrink-to-fit
 *
 * Run: php tests/test_audit_paged.php
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
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
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
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<array{w: list<array{0: float, 1: float, 2: float, 3: float, 4: string}>}>|null words per page (x0, y0, x1, y1, text) via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-audit-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([{'w':[list(w[:5]) for w in p.get_text('words')]} for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-audit-words.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** @return list<array{0: float, 1: float, 2: float, 3: float, 4: string}> */
function wordsMatching(array $page, string $prefix): array
{
    return array_values(array_filter($page['w'], static fn(array $w): bool => str_starts_with($w[4], $prefix)));
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

function yOf(IvoPdf $pdf, string $id): float
{
    $b = findById($pdf->getLayoutTree(), $id);
    return $b === null ? -1.0 : $b->y;
}

// ---------------------------------------------------------------------------
echo "1. <tfoot> before <tbody>, repeated on every page\n";
// ---------------------------------------------------------------------------
$rows = '';
for ($i = 1; $i <= 100; $i++) {
    $rows .= "<tr><td>Row{$i}</td><td>v{$i}</td></tr>";
}
$tfootFirst = "<table border=1><thead><tr><th>HEADCOL</th><th>B</th></tr></thead><tfoot><tr><td colspan=2>TOTALFOOT</td></tr></tfoot><tbody>{$rows}</tbody></table>";
$tfootLast = "<table border=1><thead><tr><th>HEADCOL</th><th>B</th></tr></thead><tbody>{$rows}</tbody><tfoot><tr><td colspan=2>TOTALFOOT</td></tr></tfoot></table>";

foreach (['tfoot before tbody' => $tfootFirst, 'tfoot after tbody' => $tfootLast] as $label => $html) {
    $pdf = render($html);
    $pages = pdfWords($pdf);
    if ($pages === null) {
        skip("{$label}: PyMuPDF not available");
        continue;
    }
    $n = count($pages);
    check("{$label}: table spans several pages", $n >= 3, (string) $n);
    $footPages = 0;
    $overlap = false;
    $order = true;
    foreach ($pages as $pi => $page) {
        $head = wordsMatching($page, 'HEADCOL');
        $foot = wordsMatching($page, 'TOTALFOOT');
        $body = wordsMatching($page, 'Row');
        $footPages += count($foot) === 1 ? 1 : 0;
        $headBottom = $head !== [] ? $head[0][3] : 0.0;
        foreach ($body as $w) {
            if ($w[1] < $headBottom - 0.5) {
                $overlap = true; // body text drawn on top of the repeated header
            }
            if ($foot !== [] && $w[3] > $foot[0][1] + 0.5) {
                $order = false; // body row below the footer on the same page
            }
        }
    }
    check("{$label}: <tfoot> is printed on every page", $footPages === $n, "{$footPages} of {$n}");
    check("{$label}: no row text overlaps the repeated <thead>", !$overlap);
    check("{$label}: footer is always below the rows of its page", $order);
    $last = $pages[$n - 1];
    $lastRow = wordsMatching($last, 'Row100');
    $lastFoot = wordsMatching($last, 'TOTALFOOT');
    check("{$label}: last page shows the footer right after the final row", $lastRow !== [] && $lastFoot !== [] && $lastFoot[0][1] > $lastRow[0][1] && $lastFoot[0][1] - $lastRow[0][3] < 20.0);
    $all = [];
    foreach ($pages as $page) {
        foreach (wordsMatching($page, 'Row') as $w) {
            $all[] = (int) substr($w[4], 3);
        }
    }
    check("{$label}: all 100 rows printed once, in order", count($all) === 100 && $all === range(1, 100));
}

$short = render('<table><thead><tr><th>H</th></tr></thead><tfoot><tr><td>FOOT</td></tr></tfoot><tbody><tr><td>R1</td></tr><tr><td>R2</td></tr></tbody></table>');
$shortPages = pdfWords($short);
if ($shortPages !== null) {
    $ys = ['H' => 0.0, 'R1' => 0.0, 'R2' => 0.0, 'FOOT' => 0.0];
    foreach ($shortPages[0]['w'] as $w) {
        if (isset($ys[$w[4]])) {
            $ys[$w[4]] = $w[1];
        }
    }
    check('single-page table: thead, rows, tfoot in visual order, footer once', count($shortPages) === 1 && $ys['H'] < $ys['R1'] && $ys['R1'] < $ys['R2'] && $ys['R2'] < $ys['FOOT'] && count(wordsMatching($shortPages[0], 'FOOT')) === 1);
}

// ---------------------------------------------------------------------------
echo "\n2. Inline content next to blocks across page breaks\n";
// ---------------------------------------------------------------------------
$pdf = render('<div style="page-break-after:always">One</div>GOINLINE<p>after</p>');
$pages = pdfWords($pdf);
if ($pages === null) {
    skip('PyMuPDF not available');
} else {
    check('anonymous text after a page-break-after block lands on page 2', count($pages) === 2 && wordsMatching($pages[1], 'GOINLINE') !== [] && wordsMatching($pages[0], 'GOINLINE') === []);

    $pdf = render(str_repeat('<p>line</p>', 60) . 'GOINLINE2');
    $pages = pdfWords($pdf);
    $lastLine = 0.0;
    $inline = null;
    foreach ($pages as $page) {
        foreach ($page['w'] as $w) {
            if ($w[4] === 'line') {
                $lastLine = $w[3];
            }
            if ($w[4] === 'GOINLINE2') {
                $inline = $w;
            }
        }
    }
    $lastPage = $pages[count($pages) - 1];
    $lastLinePageY = 0.0;
    foreach ($lastPage['w'] as $w) {
        if ($w[4] === 'line') {
            $lastLinePageY = max($lastLinePageY, $w[3]);
        }
    }
    check('trailing anonymous text sits below the last paragraph, not on top of it', $inline !== null && $inline[1] >= $lastLinePageY - 0.5, json_encode([$inline, $lastLinePageY]));

    $pdf = render('<h1>Title</h1><p>intro</p><h1 style="page-break-before:always">Sec</h1><a href="#sec">GOLINK</a><p>after</p>');
    $pages = pdfWords($pdf);
    check('link after a page-break-before heading stays with it on page 2', count($pages) === 2 && wordsMatching($pages[1], 'GOLINK') !== [] && wordsMatching($pages[0], 'GOLINK') === []);

    $pdf = new IvoPdf(['compress' => false]);
    $pdf->setTableOfContents(true, 'Contents', 2);
    $pdf->loadHtml('<h1>Alpha</h1><p>x</p><h1 style="page-break-before:always">Beta</h1><a href="#top">GOLINK2</a>')->output();
    $pages = pdfWords($pdf);
    $linkPage = null;
    foreach ($pages as $pi => $page) {
        if (wordsMatching($page, 'GOLINK2') !== []) {
            $linkPage = $pi;
        }
    }
    $betaPage = null;
    foreach ($pages as $pi => $page) {
        foreach (wordsMatching($page, 'Beta') as $w) {
            if ($w[1] < 200) {
                $betaPage = $pi;
            }
        }
    }
    check('with a TOC, a body link is not drawn on the TOC page', $linkPage !== null && $linkPage > 0 && $linkPage === $betaPage, json_encode([$linkPage, $betaPage]));

    // inline-block row falling after a page break must start at the top of the new page
    $cards = '';
    for ($k = 0; $k < 3; $k++) {
        $cards .= "<div style=\"display:inline-block;width:100pt;height:46pt;margin:4pt 6pt 8pt 0;vertical-align:top;background:#ccc\">CARD{$k}</div>";
    }
    $ys = [];
    foreach ([50, 52] as $n) {
        $pdf = render(str_repeat("<p style='margin:0;line-height:14pt'>x</p>", $n) . $cards . '<p>END</p>');
        $pages = pdfWords($pdf);
        $c = wordsMatching($pages[count($pages) - 1], 'CARD0');
        $ys[$n] = $c !== [] ? $c[0][1] : -1.0;
    }
    check('inline-block row after a page break starts at the top of the new page', $ys[50] > 0 && abs($ys[50] - $ys[52]) < 1.0 && $ys[50] < 60.0, json_encode($ys));
}

// ---------------------------------------------------------------------------
echo "\n3. Vertical margin collapsing\n";
// ---------------------------------------------------------------------------
$pdf = render('<div id="o" style="background:#eee"><div id="c" style="margin-top:50pt">child</div></div>');
check('parent / first child: margin escapes (parent starts 50pt down, child flush with it)', abs(yOf($pdf, 'o') - 86.0) < 0.5 && abs(yOf($pdf, 'c') - 86.0) < 0.5, yOf($pdf, 'o') . ' / ' . yOf($pdf, 'c'));

$pdf = render('<div id="o" style="padding-top:1pt"><div id="c" style="margin-top:50pt">child</div></div>');
check('padding on the parent stops first-child collapsing', abs(yOf($pdf, 'c') - yOf($pdf, 'o') - 51.0) < 0.5);

$pdf = render('<div id="o" style="overflow:hidden"><div id="c" style="margin-top:50pt">child</div></div>');
check('overflow:hidden parent (new BFC) keeps the child margin inside', abs(yOf($pdf, 'c') - yOf($pdf, 'o') - 50.0) < 0.5);

$pdf = render('<div id="a" style="margin-top:10pt"><div id="b" style="margin-top:30pt"><div id="c" style="margin-top:20pt">x</div></div></div>');
check('nested first children collapse to the largest margin', abs(yOf($pdf, 'a') - 66.0) < 0.5 && abs(yOf($pdf, 'c') - 66.0) < 0.5, (string) yOf($pdf, 'a'));

$pdf = render('<p id="p1" style="margin:0">a</p><div id="e" style="margin:30pt 0"></div><p id="p2" style="margin:0">b</p>');
$gap = yOf($pdf, 'p2') - (yOf($pdf, 'p1') + findById($pdf->getLayoutTree(), 'p1')->height);
check('empty block between paragraphs: margins collapse through (30pt, not 60pt)', abs($gap - 30.0) < 0.5, (string) $gap);

$pdf = render('<div id="o" style="margin-bottom:40pt"><div style="margin-bottom:60pt">child</div></div><p id="n" style="margin:20pt 0 0 0">n</p>');
$o = findById($pdf->getLayoutTree(), 'o');
check('parent / last child: bottom margins collapse to 60pt (and do not enlarge the parent)', $o !== null && abs(yOf($pdf, 'n') - ($o->y + $o->height) - 60.0) < 0.5, (string) (yOf($pdf, 'n') - ($o?->y + $o?->height)));

$pdf = render('<div id="o" style="margin-bottom:40pt;border-bottom:1pt solid #000"><div style="margin-bottom:60pt">child</div></div><p id="n" style="margin:0">n</p>');
$o = findById($pdf->getLayoutTree(), 'o');
check('border-bottom on the parent keeps the child margin inside', $o !== null && abs(yOf($pdf, 'n') - ($o->y + $o->height) - 40.0) < 0.5);

$pdf = render('<p id="p1" style="margin:0">a</p><div style="margin-top:20pt"><div style="margin-bottom:50pt"></div></div><p id="p2" style="margin:0">b</p>');
$gap = yOf($pdf, 'p2') - (yOf($pdf, 'p1') + findById($pdf->getLayoutTree(), 'p1')->height);
check('empty block nested in an empty block: all margins collapse to the largest (50pt)', abs($gap - 50.0) < 0.5, (string) $gap);

$pdf = render('<p id="a" style="margin:0 0 20pt 0">a</p><p id="b" style="margin:30pt 0 0 0">b</p>');
check('sibling collapsing still gives the larger margin', abs(yOf($pdf, 'b') - yOf($pdf, 'a') - findById($pdf->getLayoutTree(), 'a')->height - 30.0) < 0.5);

// ---------------------------------------------------------------------------
echo "\n4. Flex / grid items that are scroll containers shrink below their content\n";
// ---------------------------------------------------------------------------
$pdf = render('<div style="display:flex;width:100pt"><div id="i" style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis">A very long text that cannot possibly fit into one hundred points of width</div></div>');
$i = findById($pdf->getLayoutTree(), 'i');
check('flex item with overflow:hidden + nowrap shrinks to the container (<= 100pt)', $i !== null && $i->width <= 100.5, (string) ($i?->width));

$pdf = render('<div style="display:flex;width:100pt"><div id="i" style="white-space:nowrap">A very long text that cannot possibly fit into one hundred points of width</div></div>');
$i = findById($pdf->getLayoutTree(), 'i');
check('flex item with overflow:visible keeps its content minimum (control)', $i !== null && $i->width > 150.0, (string) ($i?->width));

$pdf = render('<div style="display:flex;width:200pt"><div id="i" style="overflow:hidden;white-space:nowrap;flex:1">A very long text that cannot possibly fit into one hundred points of width</div><div id="j" style="width:50pt">B</div></div>');
$i = findById($pdf->getLayoutTree(), 'i');
check('flex:1 item with overflow:hidden fills only the remaining space', $i !== null && $i->width <= 150.5, (string) ($i?->width));

$pdf = render('<div style="display:grid;grid-template-columns:auto auto;width:200pt"><div id="i" style="overflow:hidden;white-space:nowrap">A very long text that cannot possibly fit into one hundred points of width</div><div id="j">B</div></div>');
$i = findById($pdf->getLayoutTree(), 'i');
check('grid item with overflow:hidden stays inside the grid (<= 200pt)', $i !== null && $i->width <= 200.5, (string) ($i?->width));

// ---------------------------------------------------------------------------
echo "\n5. Floats containing tables are shrink-to-fit\n";
// ---------------------------------------------------------------------------
$pdf = render('<div><div id="l" style="float:left">LEFT<br>left line 2</div><div id="r" style="float:right;text-align:right"><h1 id="h" style="margin:0">INVOICE</h1><table style="margin-left:auto"><tr><td>No:</td><td>1</td></tr></table></div><div style="clear:both"></div></div>');
$l = findById($pdf->getLayoutTree(), 'l');
$r = findById($pdf->getLayoutTree(), 'r');
check('right float with a table stays beside the left float', $l !== null && $r !== null && abs($r->y - $l->y) < 1.0 && $r->width < 200.0, json_encode([$l?->y, $r?->y, $r?->width]));

$pdf = render('<div><div id="l" style="float:left;width:100pt">LEFT</div><div id="r" style="float:right"><table><tr><td>No:</td><td>1</td></tr></table></div><div style="clear:both"></div></div>');
$r = findById($pdf->getLayoutTree(), 'r');
check('float containing only a table is as narrow as the table content', $r !== null && $r->width < 150.0, (string) ($r?->width));

// ---------------------------------------------------------------------------
echo "\n--- <br> inside an inline span: shrink-to-fit float width ---\n";
// Classic invoice header: logo + two address lines in a left float, "INVOICE" block in a right float.
// The <br> inside the span used to be ignored, so both lines were summed and the right float dropped below.
$hdr = render('<div><div id="lf" style="float:left"><div style="width:135pt;height:40pt"></div>'
    . '<span style="font-size:8.5pt">12 Industrial Way, Springfield 12345<br>billing@acme.example · +1 555 0100</span></div>'
    . '<div id="rf" style="float:right"><h1 style="margin:0;font-size:22pt">INVOICE</h1></div><div style="clear:both"></div></div>');
$lf = findById($hdr->getLayoutTree(), 'lf');
$rf = findById($hdr->getLayoutTree(), 'rf');
check('left float is as wide as its longest line, not the sum of lines', $lf !== null && $lf->width < 200.0, $lf ? sprintf('%.1f pt', $lf->width) : 'missing');
check('right float stays beside the left float', $lf !== null && $rf !== null && abs($rf->y - $lf->y) < 0.5, $rf ? sprintf('y %.1f vs %.1f', $rf->y, $lf?->y ?? 0) : 'missing');
$br = render('<div id="f" style="float:left">aaaa aaaa aaaa<br><b>bb</b></div>');
$brNoBr = render('<div id="f" style="float:left">aaaa aaaa aaaa <b>bb</b></div>');
check('<br> ends the line run for max-content', findById($br->getLayoutTree(), 'f')->width < findById($brNoBr->getLayoutTree(), 'f')->width - 5.0);

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
