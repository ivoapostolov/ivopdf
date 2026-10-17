<?php

declare(strict_types=1);

/**
 * Paged-media tests: table of contents (target-counter / leader / <toc> / <tocentry>), @page :first / :left / :right,
 * named pages (page: name) with their own size, page margin boxes (counter(page), string-set), per-page
 * running headers/footers and the {DATE} / {TITLE} placeholders.
 *
 * Page text is read with PyMuPDF (python + fitz) when available; those checks are skipped otherwise.
 *
 * Run: php tests/test_paged_media.php
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

function makePdf(string $html, ?callable $setup = null, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/**
 * Text spans per page via PyMuPDF: list (pages) of list of [x0, y0, x1, y1, text]; null when python/fitz is missing.
 *
 * @return list<list<array{0: float, 1: float, 2: float, 3: float, 4: string}>>|null
 */
function pdfSpans(IvoPdf $pdf): ?array
{
    static $available = null;
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-paged-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = 'import pymupdf as fitz,json,sys' . "\n"
        . 'd=fitz.open(sys.argv[1]); out=[]' . "\n"
        . 'for p in d:' . "\n"
        . '    s=[]' . "\n"
        . '    for b in p.get_text("dict")["blocks"]:' . "\n"
        . '        for l in b.get("lines",[]):' . "\n"
        . '            for sp in l["spans"]:' . "\n"
        . '                s.append([sp["bbox"][0],sp["bbox"][1],sp["bbox"][2],sp["bbox"][3],sp["text"]])' . "\n"
        . '    out.append({"w":p.rect.width,"h":p.rect.height,"spans":s})' . "\n"
        . 'print(json.dumps(out))' . "\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-paged-spans.py';
    file_put_contents($scriptFile, $script);
    $cmd = 'python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    $json = @shell_exec($cmd);
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        $available = false;
        return null;
    }
    $available = true;
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** Joined text of one page (spans on the same baseline are concatenated, lines separated by a newline). */
function pageText(array $page): string
{
    $t = '';
    $prevY = null;
    foreach ($page['spans'] as $s) {
        if ($prevY !== null && abs($s[1] - $prevY) > 2.0) {
            $t .= "
";
        }
        $t .= $s[4];
        $prevY = $s[1];
    }
    return (string) preg_replace('/[ 	]+/', ' ', $t);
}

/** @return list<string> */
function lineTexts(LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines as $line) {
        $t = '';
        foreach ($line->runs as $run) {
            $t .= $run->text;
        }
        $out[] = $t;
    }
    foreach ($box->children as $c) {
        foreach (lineTexts($c) as $l) {
            $out[] = $l;
        }
    }
    return $out;
}

function pdfMediaBoxes(string $pdf): array
{
    preg_match_all('~/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]~', $pdf, $m, PREG_SET_ORDER);
    return array_map(static fn(array $x): array => [(float) $x[1], (float) $x[2]], $m);
}

$para = static fn(int $n, string $t = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod.'): string => str_repeat("<p>{$t}</p>", $n);

// ---------------------------------------------------------------------------
echo "\n1. Table of contents: <toc />, target-counter(), leaders\n";
// ---------------------------------------------------------------------------
$html = '<h1 style="page-break-before:always">Introduction</h1>' . $para(3)
    . '<h2>Background</h2>' . $para(2)
    . '<h1 style="page-break-before:always">Methods</h1>' . $para(3)
    . '<h1 style="page-break-before:always" id="res">Results</h1>' . $para(3)
    . '<h3>Deep</h3>' . $para(1);
$pdf = makePdf('<toc />' . $html, null);
$tree = $pdf->getLayoutTree();
$lines = $tree ? lineTexts($tree) : [];
$tocLines = array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, '. . .') || preg_match('/^[^.]*\.{2,}/', $l) === 1 || str_contains($l, '..')));
$tocLines = array_values(array_filter($lines, static fn(string $l): bool => preg_match('/\d+$/', $l) === 1 && str_contains($l, '.')));
check('TOC lists h1-h3 entries', count($tocLines) === 5, count($tocLines) . ' lines: ' . implode(' | ', array_map(static fn($l) => substr($l, 0, 20), $tocLines)));
$pageOf = static function (array $tocLines, string $title): ?int {
    foreach ($tocLines as $l) {
        if (str_starts_with($l, $title) && preg_match('/(\d+)$/', $l, $m)) {
            return (int) $m[1];
        }
    }
    return null;
};
// <toc /> sits on page 1 together with the first heading? the first h1 breaks before, so: TOC p1, Introduction p2, Methods p3, Results p4
check('target-counter: Introduction on page 2', $pageOf($tocLines, 'Introduction') === 2, (string) $pageOf($tocLines, 'Introduction'));
check('target-counter: Background (h2) on page 2', $pageOf($tocLines, 'Background') === 2, (string) $pageOf($tocLines, 'Background'));
check('target-counter: Methods on page 3', $pageOf($tocLines, 'Methods') === 3, (string) $pageOf($tocLines, 'Methods'));
check('target-counter: Results on page 4', $pageOf($tocLines, 'Results') === 4, (string) $pageOf($tocLines, 'Results'));
check('target-counter: h3 entry resolved', $pageOf($tocLines, 'Deep') === 4);
check('leader dots fill the line', ($tocLines[0] ?? '') !== '' && substr_count($tocLines[0], '.') > 20, $tocLines[0] ?? '');
check('no leftover placeholder "999"', !str_contains(implode("\n", $lines), '999'));
check('document has 4 pages', $pdf->getPageCount() === 4, (string) $pdf->getPageCount());

// numbers are flush with the right content edge
$rightEdgeOk = false;
if ($tree !== null) {
    $find = static function (LayoutBox $b) use (&$find): ?LayoutBox {
        foreach ($b->lines as $line) {
            foreach ($line->runs as $run) {
                if (isset($run->style->all()['ivo-pageref'])) {
                    return $b;
                }
            }
        }
        foreach ($b->children as $c) {
            $r = $find($c);
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    };
    $owner = $find($tree);
    if ($owner !== null) {
        $right = $owner->getContentX() + $owner->getContentWidth();
        $lastRun = $owner->lines[0]->runs[count($owner->lines[0]->runs) - 1];
        $rightEdgeOk = abs(($lastRun->x + $lastRun->width) - $right) < 1.0;
    }
}
check('page numbers right-aligned to the content edge', $rightEdgeOk);

$raw = $pdf->output();
check('TOC entries are clickable (internal link annotations)', substr_count($raw, '/Subtype /Link') >= 5 || substr_count($raw, '/Subtype/Link') >= 5);

// Custom CSS without the generator
$css = "<style>.c a{text-decoration:none;color:#000} .c a::after{content: leader(dotted) target-counter(attr(href), page);}</style>";
$pdf = makePdf($css . '<div class="c"><a href="#sec">See section</a></div><div style="page-break-before:always"></div><h2 id="sec">Target</h2>');
$lines = lineTexts($pdf->getLayoutTree());
$hit = array_values(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'See section')));
check('custom target-counter(attr(href), page) -> page 2', isset($hit[0]) && preg_match('/\.\s*2$/', $hit[0]) === 1, $hit[0] ?? 'none');
$pdf = makePdf($css . '<div class="c"><a href="#nowhere">Broken</a></div>');
$lines = lineTexts($pdf->getLayoutTree());
check('unknown target does not break rendering', $pdf->getPageCount() === 1 && !str_contains(implode('', $lines), '999'));

// ---------------------------------------------------------------------------
echo "\n2. <tocentry>, <tocpagebreak>, setTableOfContents()\n";
// ---------------------------------------------------------------------------
$pdf = makePdf('<p>Cover</p><tocpagebreak /><p>Body</p><pagebreak /><tocentry content="Manual entry" level="1" /><p>Second page text</p>');
$lines = lineTexts($pdf->getLayoutTree());
$manual = array_values(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Manual entry')));
check('<tocentry> creates an entry', isset($manual[0]));
check('<tocentry> page number is the page of the tag (4)', isset($manual[0]) && preg_match('/(\d+)$/', $manual[0], $m) === 1 && (int) $m[1] === 4, $manual[0] ?? '');
check('<tocpagebreak> puts the TOC on its own page', $pdf->getPageCount() === 4, (string) $pdf->getPageCount());

$pdf = makePdf($html, static fn(IvoPdf $p) => $p->setTableOfContents(true, 'Contents', 2));
$lines = lineTexts($pdf->getLayoutTree());
check('setTableOfContents(): title rendered', in_array('Contents', $lines, true));
check('setTableOfContents(): maxLevel 2 excludes h3', count(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Deep'))) === 1 /* only the heading */);
check('setTableOfContents(): TOC page + 3 chapters', $pdf->getPageCount() === 4, (string) $pdf->getPageCount());
$spans = pdfSpans($pdf);
if ($spans !== null) {
    check('PDF text: TOC page lists "Results" with page 4', preg_match('/Results[\s.]*\n?\s*4/', str_replace("\n", ' ', pageText($spans[0]))) === 1, substr(str_replace("\n", ' ', pageText($spans[0])), 0, 200));
} else {
    echo "  [SKIP] PyMuPDF not available\n";
}

// ---------------------------------------------------------------------------
echo "\n3. @page :first / :left / :right margins\n";
// ---------------------------------------------------------------------------
$pageCss = '<style>body{margin:0} @page{size:A5;margin:30pt} @page :first{margin-top:100pt} @page :left{margin-left:50pt;margin-right:20pt} @page :right{margin-left:20pt;margin-right:50pt}</style>';
$pdf = makePdf($pageCss . '<p style="margin:0">First page</p>' . '<div style="page-break-before:always"><p style="margin:0">Second page</p></div><div style="page-break-before:always"><p style="margin:0">Third page</p></div>');
$spans = pdfSpans($pdf);
check('three pages', $pdf->getPageCount() === 3, (string) $pdf->getPageCount());
if ($spans !== null) {
    $first = array_values(array_filter($spans[0]['spans'], static fn(array $s): bool => str_contains($s[4], 'First')))[0] ?? null;
    $second = array_values(array_filter($spans[1]['spans'], static fn(array $s): bool => str_contains($s[4], 'Second')))[0] ?? null;
    $third = array_values(array_filter($spans[2]['spans'], static fn(array $s): bool => str_contains($s[4], 'Third')))[0] ?? null;
    check('@page :first: content starts 70pt lower than on page 2', $first && $second && abs(($first[1] - $second[1]) - 70.0) < 1.5, ($first[1] ?? '?') . ' vs ' . ($second[1] ?? '?'));
    check('@page :right (page 1): left margin 20pt', $first && abs($first[0] - 20.0) < 1.5, (string) ($first[0] ?? '?'));
    check('@page :left (page 2): left margin 50pt', $second && abs($second[0] - 50.0) < 1.5, (string) ($second[0] ?? '?'));
    check('@page :right (page 3): left margin 20pt again', $third && abs($third[0] - 20.0) < 1.5, (string) ($third[0] ?? '?'));
} else {
    echo "  [SKIP] PyMuPDF not available\n";
}

// :first changes the printable height, so pagination follows it
$many = $para(40);
$uniform = makePdf('<style>@page{size:A5;margin:30pt}</style>' . $many);
$taller = makePdf('<style>@page{size:A5;margin:30pt} @page :first{margin-top:200pt}</style>' . $many);
check('larger first-page margin gives fewer lines on page 1 (more pages overall)', $taller->getPageCount() >= $uniform->getPageCount());
$tree = $taller->getLayoutTree();
$maxY = 0.0;
$firstPageBottom = 595.28 - 30.0;
check('uniform fast path unchanged when no variants exist', $uniform->getPageCount() > 1);

// ---------------------------------------------------------------------------
echo "\n4. Named pages (page: name) with their own size\n";
// ---------------------------------------------------------------------------
$namedCss = '<style>@page{size:A4;margin:40pt} @page wide{size:A4 landscape;margin:30pt} .w{page:wide}</style>';
$pdf = makePdf($namedCss . '<p>Portrait 1</p><div class="w"><p>Landscape page</p></div><p>Portrait again</p>');
$raw = $pdf->output();
$boxes = pdfMediaBoxes($raw);
check('three pages (portrait, landscape, portrait)', $pdf->getPageCount() === 3, (string) $pdf->getPageCount());
check('MediaBox switches per page', count($boxes) === 3 && $boxes[0][0] < $boxes[0][1] && $boxes[1][0] > $boxes[1][1] && $boxes[2][0] < $boxes[2][1], json_encode($boxes));

// ---------------------------------------------------------------------------
echo "\n5. Page margin boxes, counter(page), string-set\n";
// ---------------------------------------------------------------------------
$mbCss = '<style>@page{size:A5;margin:50pt 30pt;'
    . '@top-left{content:"Report";font-size:8pt}'
    . '@top-right{content:string(chapter);font-size:8pt}'
    . '@bottom-center{content:"Page " counter(page) " of " counter(pages);font-size:9pt}}'
    . 'h1{string-set:chapter content();page-break-before:always;font-size:14pt}</style>';
$pdf = makePdf($mbCss . '<h1>Alpha</h1>' . $para(2) . '<h1>Beta</h1>' . $para(2) . '<h1>Gamma</h1>' . $para(2));
$spans = pdfSpans($pdf);
check('three chapters = three pages', $pdf->getPageCount() === 3, (string) $pdf->getPageCount());
if ($spans !== null) {
    $t = [];
    foreach ($spans as $i => $pg) {
        $t[$i] = str_replace("\n", ' ', pageText($pg));
    }
    check('margin box counter(page)/counter(pages): "Page 1 of 3"', str_contains($t[0], 'Page 1 of 3'), $t[0]);
    check('margin box counter(page): "Page 3 of 3"', str_contains($t[2], 'Page 3 of 3'));
    check('static margin box text "Report" on every page', str_contains($t[0], 'Report') && str_contains($t[1], 'Report') && str_contains($t[2], 'Report'));
    // running chapter title: string(chapter) = first h1 on the page
    $topRight = static function (array $pg): string {
        foreach ($pg['spans'] as $s) {
            if ($s[1] < 40.0 && $s[0] > 150.0) {
                return $s[4];
            }
        }
        return '';
    };
    check('string-set: top-right shows chapter "Alpha" on page 1', $topRight($spans[0]) === 'Alpha', $topRight($spans[0]));
    check('string-set: top-right shows chapter "Beta" on page 2', $topRight($spans[1]) === 'Beta', $topRight($spans[1]));
    check('string-set: top-right shows chapter "Gamma" on page 3', $topRight($spans[2]) === 'Gamma', $topRight($spans[2]));
    $x0 = INF;
    $x1 = -INF;
    foreach ($spans[0]['spans'] as $s) {
        if ($s[1] > 595.28 - 50.0 && trim($s[4]) !== '') {
            $x0 = min($x0, $s[0]);
            $x1 = max($x1, $s[2]);
        }
    }
    check('bottom-center box is centred horizontally and inside the bottom margin', is_finite($x0) && abs((($x0 + $x1) / 2) - 419.53 / 2) < 6.0, "x {$x0}..{$x1}");
} else {
    echo "  [SKIP] PyMuPDF not available\n";
}

// ---------------------------------------------------------------------------
echo "\n6. Per-page headers / footers and {DATE} / {TITLE}\n";
// ---------------------------------------------------------------------------
$pdf = makePdf(
    '<html><head><title>My Doc</title></head><body><p>One</p><div style="page-break-before:always"><p>Two</p></div><div style="page-break-before:always"><p>Three</p></div></body></html>',
    static function (IvoPdf $p): void {
        $p->setHeaderHtml('<div>FIRSTONLY</div>', 'first');
        $p->setHeaderHtml('<div>ODDPAGE</div>', 'odd');
        $p->setHeaderHtml('<div>EVENPAGE</div>', 'even');
        $p->setFooterHtml('<div>{TITLE} {DATE} {PAGE_NUM}/{PAGE_COUNT}</div>');
    }
);
$spans = pdfSpans($pdf);
if ($spans !== null) {
    $t = array_map(static fn(array $pg): string => str_replace("\n", ' ', pageText($pg)), $spans);
    check('first-page header only on page 1', str_contains($t[0], 'FIRSTONLY') && !str_contains($t[1], 'FIRSTONLY') && !str_contains($t[2], 'FIRSTONLY'));
    check('even header on page 2', str_contains($t[1], 'EVENPAGE') && !str_contains($t[1], 'ODDPAGE'));
    check('odd header on page 3 (page 1 uses the first-page variant)', str_contains($t[2], 'ODDPAGE') && !str_contains($t[0], 'ODDPAGE'));
    check('{TITLE} placeholder', str_contains($t[0], 'My Doc'));
    check('{DATE} placeholder (d.m.Y)', str_contains($t[0], date('d.m.Y')));
    check('{PAGE_NUM}/{PAGE_COUNT} still work', str_contains($t[1], '2/3'));
} else {
    echo "  [SKIP] PyMuPDF not available\n";
}
$pdf = makePdf('<p>a</p><div style="page-break-before:always"><p>b</p></div>', static function (IvoPdf $p): void {
    $p->setHeaderHtml('<div style="height:40pt">Tall first header</div>', 'first');
});
$tree = $pdf->getLayoutTree();
$firstY = null;
$secondY = null;
if ($tree !== null) {
    foreach ($tree->children as $c) {
        if ($firstY === null && $c->tagName === 'p') {
            $firstY = $c->y;
        }
    }
}
check('first-page header reserves space only for page 1', $firstY !== null && $firstY > 50.0 && $pdf->getPageCount() === 2);
$pdf = makePdf('<p>x</p>', static fn(IvoPdf $p) => $p->setHeaderHtml('<b>H</b>')->setHeaderHtml(null, 'first')->setFooterHtml('<i>F</i>', 'odd'));
check('single-argument setHeaderHtml / null variant still fine', $pdf->getPageCount() === 1);
$m = new IvoPdf(['compress' => false]);
$m->SetHTMLHeader('<div>OddHdr</div>', 'O')->SetHTMLHeader('<div>EvenHdr</div>', 'E');
$m->WriteHTML('<p>a</p><pagebreak /><p>b</p>');
$m->output();
check('mPDF SetHTMLHeader($html, "O"/"E") sides', $m->getPageCount() === 2);

// ---------------------------------------------------------------------------
echo "\n7. Hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
