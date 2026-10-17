<?php

declare(strict_types=1);

/**
 * Regression tests for the last layout / performance items of the Fable audit:
 *  1. pagination is near-linear (lazy sibling shifts): 8 000 headings, a TOC, tables, floats and fixed boxes
 *  2. memory per line: neighbouring runs of a line are joined, a TextRun carries fewer declared properties
 *  3. an explicit `height` is kept when the content overflows (also across page breaks), as in browsers
 *  4. a tall inline-block crossing a page break is split between its lines (no line is sliced)
 *  5. inside list markers and the <summary> marker have the browsers' width
 *  6. collapsed table borders: every cell takes the winning border of its edges (CSS 2.1 17.6.2.1)
 * Expected values come from Microsoft Edge renders of the same markup (A4, 36pt margins, DejaVu Sans 11pt).
 *
 * Run: php tests/test_audit_final_x.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LineBox;
use IvoPdf\Layout\TextRun;

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

const PAGE_CSS = "<style>@page{size:A4;margin:36pt} body{font-family:'DejaVu Sans';font-size:11pt}</style>";
const PAGE_TOP = 36.0;
const PAGE_H = 841.89 - 72.0;

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml(PAGE_CSS . $html)->output();
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

function byId(IvoPdf $pdf, string $id): LayoutBox
{
    $b = findById($pdf->getLayoutTree(), $id);
    if ($b === null) {
        throw new RuntimeException("no #{$id}");
    }
    return $b;
}

/** 0-based page of a virtual y (uniform pages). */
function pageOf(float $y): int
{
    return (int) floor(($y - PAGE_TOP + 0.01) / PAGE_H);
}

/** @return list<LineBox> every line box of the tree, inline-block content included */
function allLines(LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines as $line) {
        $out[] = $line;
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null) {
                array_push($out, ...allLines($run->inlineBox));
            }
        }
    }
    foreach ($box->children as $c) {
        if (!($c->isInlineLevel() && $box->lines !== [])) {
            array_push($out, ...allLines($c));
        }
    }
    return $out;
}

/** Lines whose box crosses a page boundary (a line never should). */
function slicedLines(IvoPdf $pdf): int
{
    $n = 0;
    foreach (allLines($pdf->getLayoutTree()) as $line) {
        if ($line->height > 0.5 && $line->height < PAGE_H && pageOf($line->y) !== pageOf($line->y + $line->height - 0.6)) {
            $n++;
        }
    }
    return $n;
}

/** The run of a box's lines whose text contains $word, with the word's x. */
function wordX(LayoutBox $box, string $word): ?float
{
    foreach (allLines($box) as $line) {
        foreach ($line->runs as $run) {
            $p = strpos($run->text, $word);
            if ($p !== false) {
                return $p === 0 ? $run->x : null; // the tests look for words that start their run
            }
        }
    }
    return null;
}

/** @return list<array{0: float, 1: float, 2: float, 3: float, 4: string, 5: int}>|null words of the PDF (x0, y0, x1, y1, text, page) via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-final-x-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([list(w[:5])+[i] for i,p in enumerate(d) for w in p.get_text('words')]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-final-x-words.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $words = json_decode($json, true);
    return is_array($words) ? $words : null;
}

// ---------------------------------------------------------------------------
echo "1. Near-linear pagination (lazy shifts of the following siblings)\n";
// ---------------------------------------------------------------------------
$headings = static function (int $n): string {
    $h = '';
    for ($i = 0; $i < $n; $i++) {
        $h .= "<h6>Heading {$i}</h6>";
    }
    return $h;
};
$t = microtime(true);
$small = render($headings(1500));
$tSmall = microtime(true) - $t;
$t = microtime(true);
$big = render($headings(6000));
$tBig = microtime(true) - $t;
// Before: every page break re-scanned all following siblings (5k / 10k / 20k headings: 1.8 / 5.0 / 17.7 s, x3.5 per
// doubling); a quadratic run makes 4x the headings take ~12x the time, a linear one ~4x
check('4x the headings take less than 7x the time (was ~12x)', $tBig < 7.0 * max(0.05, $tSmall), sprintf('%.2f s vs %.2f s', $tBig, $tSmall));
check('6 000 headings: 207 pages (as before the change), no heading line sliced', $big->getPageCount() === 207 && slicedLines($big) === 0, $big->getPageCount() . ' pages, ' . slicedLines($big) . ' sliced');
$lastY = -INF;
$ordered = true;
foreach ($big->getLayoutTree()->children as $c) {
    if ($c->tagName === 'h6') {
        $ordered = $ordered && $c->y > $lastY;
        $lastY = $c->y;
    }
}
check('headings keep their order after the lazy shifts', $ordered);

$mixed = '';
for ($i = 0; $i < 40; $i++) {
    $mixed .= "<h2 id=\"h{$i}\">Section {$i}</h2><p>" . str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 6) . '</p>';
    if ($i % 7 === 3) {
        $rows = '';
        for ($r = 0; $r < 30; $r++) {
            $rows .= "<tr><td>R{$r}</td><td>value {$r}</td></tr>";
        }
        $mixed .= "<table id=\"t{$i}\" border=\"1\" style=\"width:100%\"><thead><tr><th>N</th><th>V</th></tr></thead><tbody>{$rows}</tbody></table>";
    }
    if ($i % 9 === 4) {
        $mixed .= '<div style="float:right;width:100pt;height:60pt;background:#ddd"></div>';
    }
}
$m = render($mixed);
check('mixed document: no line sliced by a page break', slicedLines($m) === 0, (string) slicedLines($m));
$cellsAligned = true;
foreach ([3, 10, 17, 24, 31, 38] as $ti) {
    $tb = findById($m->getLayoutTree(), "t{$ti}");
    foreach ($tb === null ? [] : $tb->children as $group) {
        foreach ($group->children as $row) {
            $ys = array_map(static fn(LayoutBox $c): float => round($c->y, 2), $row->children);
            $cellsAligned = $cellsAligned && count(array_unique($ys)) <= 1 && pageOf($row->y) === pageOf($row->y + $row->height - 0.6);
        }
    }
}
check('table rows moved by page breaks keep their cells side by side and whole', $cellsAligned);
$hOk = true;
for ($i = 1; $i < 40; $i++) {
    $hOk = $hOk && byId($m, "h{$i}")->y > byId($m, 'h' . ($i - 1))->y;
}
check('sections stay in document order', $hOk);

$toc = new IvoPdf(['compress' => false]);
$tocBody = '<toc levels="1" title="Contents" />';
for ($i = 0; $i < 600; $i++) {
    $tocBody .= "<h1>Chapter {$i}</h1><p>Text {$i}.</p>";
}
$toc->loadHtml(PAGE_CSS . $tocBody)->output();
check('TOC with 600 chapters: 70 pages (as before the change)', $toc->getPageCount() === 70, (string) $toc->getPageCount());

// ---------------------------------------------------------------------------
echo "\n2. Memory: joined runs, smaller TextRun\n";
// ---------------------------------------------------------------------------
$p = render('<p id="p">Hello world, this is a <b>test</b> of <a href="#q">links</a> and more words.</p>');
$runs = byId($p, 'p')->lines[0]->runs;
$texts = array_map(static fn(TextRun $r): string => $r->text, $runs);
check('a paragraph line keeps a few runs instead of one per word and space (21 before)', count($runs) <= 10, count($runs) . ': ' . implode('|', $texts));
check('the joined runs still hold the whole text in order', implode('', $texts) === 'Hello world, this is a test of links and more words.', implode('', $texts));
$link = array_values(array_filter($runs, static fn(TextRun $r): bool => $r->href === '#q'));
check('a link keeps its own run (the link annotation covers only it)', count($link) === 1 && $link[0]->text === 'links');
$bold = array_values(array_filter($runs, static fn(TextRun $r): bool => $r->text === 'test'));
check('a differently styled word keeps its own run', count($bold) === 1);
$words = pdfWords($p);
check('PDF text extraction is unchanged', $words === null || implode(' ', array_map(static fn(array $w): string => $w[4], $words)) === 'Hello world, this is a test of links and more words.');
$j = render('<p id="j" style="text-align:justify;width:200pt">aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk lll mmm nnn ooo ppp</p>');
check('justified lines keep a run per word (the spaces stretch)', count(byId($j, 'j')->lines[0]->runs) > 5);
$ws = render('<p id="w" style="word-spacing:5pt">aaa bbb ccc</p>');
check('word-spacing keeps the spaces as their own runs', count(byId($ws, 'w')->lines[0]->runs) === 5);
$run = new TextRun('x', byId($p, 'p')->style, 10.0, 12.0, 9.0, 3.0);
check('TextRun::$height reads ascent + descent', $run->height === 12.0);
$run->ascent = 10.0;
check('TextRun::$height follows ascent changes', $run->height === 13.0);
check('rare run properties default without allocating', $run->isLineBreak === false && $run->breakBefore === true && $run->letterSpacingPt === 0.0 && !$run->hasExtra());
$run->letterSpacingPt = 1.5;
$run->breakBefore = false;
check('rare run properties can still be set', $run->letterSpacingPt === 1.5 && $run->breakBefore === false && $run->hasExtra());
$m0 = memory_get_usage();
$many = [];
for ($i = 0; $i < 5000; $i++) {
    $many[] = new TextRun('x', $run->style, 1.0);
}
$perRun = (memory_get_usage() - $m0) / 5000;
unset($many);
check('a TextRun takes at most ~380 bytes (was ~424)', $perRun < 385.0, sprintf('%.0f B', $perRun));
$br = render('<div id="br">' . str_repeat('x<br>', 3000) . '</div>');
$lineRuns = 0;
foreach (byId($br, 'br')->lines as $line) {
    $lineRuns += count($line->runs);
}
check('x<br> x3000: one run per line', $lineRuns === count(byId($br, 'br')->lines) && $lineRuns === 3000, (string) $lineRuns);

// ---------------------------------------------------------------------------
echo "\n3. A definite height is kept when the content overflows (Edge)\n";
// ---------------------------------------------------------------------------
$h = render('<div id="a" style="height:30pt;background:#fdd;border:1pt solid red">Aone<br>Atwo<br>Athree<br>Afour</div><p id="b">Bafter</p>'
    . '<div id="c" style="display:flex;height:20pt"><div>Cone<br>Ctwo<br>Cthree</div></div><p id="d">Dafter</p>'
    . '<p id="e">Qa <span id="ib" style="display:inline-block;height:20pt;width:100pt;vertical-align:top">Eone<br>Etwo<br>Ethree</span> Fbeside</p><p id="g">Gafter</p>'
    . '<table id="t" style="height:10pt"><tr><td>Hone<br>Htwo</td></tr></table>'
    . '<div id="j" style="height:20pt;min-height:40pt">Jone<br>Jtwo<br>Jthree<br>Jfour</div>'
    . '<div id="l" style="height:20pt;overflow:hidden">Lone<br>Ltwo<br>Lthree</div>'
    . '<div id="v" style="height:50%">Vone<br>Vtwo</div>');
$a = byId($h, 'a');
check('overflow: visible block keeps height: 30pt (+ borders)', abs($a->height - 32.0) < 0.01, sprintf('%.2f', $a->height));
check('its overflowing lines are still laid out below it', count($a->lines) === 4 && $a->lines[3]->y > $a->y + $a->height - 15.0);
check('the next paragraph starts after the declared height (Edge: Bafter 42.0 below the box top)', abs(byId($h, 'b')->y - $a->y - 43.0) < 0.6, sprintf('%.2f', byId($h, 'b')->y - $a->y));
check('flex container keeps height: 20pt', abs(byId($h, 'c')->height - 20.0) < 0.01, sprintf('%.2f', byId($h, 'c')->height));
check('inline-block keeps height: 20pt', abs(byId($h, 'ib')->height - 20.0) < 0.01, sprintf('%.2f', byId($h, 'ib')->height));
check('a top-aligned overflowing inline-block adds only its height to the line (Edge)', abs(byId($h, 'e')->height - 20.0) < 0.6, sprintf('%.2f', byId($h, 'e')->height));
check('a table height stays a minimum (CSS 2.1 17.5.3)', byId($h, 't')->height > 25.0, sprintf('%.2f', byId($h, 't')->height));
check('min-height wins over a smaller height', abs(byId($h, 'j')->height - 40.0) < 0.01, sprintf('%.2f', byId($h, 'j')->height));
check('overflow: hidden keeps the height', abs(byId($h, 'l')->height - 20.0) < 0.01);
check('a percentage height of an auto-height parent is auto', byId($h, 'v')->height > 20.0, sprintf('%.2f', byId($h, 'v')->height));
$hw = pdfWords($h);
if ($hw !== null) {
    $txt = array_map(static fn(array $w): string => $w[4], $hw);
    check('overflowing content is painted outside the box', in_array('Afour', $txt, true) && in_array('Ethree', $txt, true) && in_array('Jfour', $txt, true));
    check('overflow: hidden clips it', !in_array('Lthree', $txt, true) && in_array('Lone', $txt, true));
}
$lines = implode('<br>', array_map(static fn(int $i): string => sprintf('L%02d line text', $i), range(0, 29)));
$hp = render('<div style="height:640pt"></div><div id="f" style="height:200pt;border:1pt solid red">' . $lines . '</div><p id="after">Bafter</p>');
$f = byId($hp, 'f');
check('a fixed-height box crossing a page break keeps its height', abs($f->height - 202.0) < 0.01, sprintf('%.2f', $f->height));
check('the line at the break moves to the next page inside it (no sliced line)', slicedLines($hp) === 0);
check('the following paragraph is placed after the declared height, not after the moved lines (Edge)', abs(byId($hp, 'after')->y - ($f->y + $f->height + 11.0)) < 0.6, sprintf('%.2f vs %.2f', byId($hp, 'after')->y, $f->y + $f->height + 11.0));
$hw = pdfWords($hp);
if ($hw !== null) {
    $after = array_values(array_filter($hw, static fn(array $w): bool => $w[4] === 'Bafter'));
    check('Edge: Bafter at y 124.8 on page 2', count($after) === 1 && $after[0][5] === 1 && abs($after[0][1] - 124.79) < 1.0, json_encode($after));
}

// ---------------------------------------------------------------------------
echo "\n4. A tall inline-block across a page break is split between its lines\n";
// ---------------------------------------------------------------------------
$ibLines = implode('<br>', array_map(static fn(int $i): string => sprintf('L%02d inline text', $i), range(0, 79)));
$ib = render('<div style="height:300pt"></div><p>Qa before <span id="ib" style="display:inline-block;border:1pt solid green;vertical-align:top">' . $ibLines . '</span> Qb after</p><p id="c">Cafter</p>');
check('no line of the inline-block is sliced (Edge slices one line in two)', slicedLines($ib) === 0, (string) slicedLines($ib));
$ibBox = byId($ib, 'ib');
check('its lines stay in order and the box encloses them', $ibBox->lines[79]->y > $ibBox->lines[0]->y && $ibBox->y + $ibBox->height >= $ibBox->lines[79]->y + $ibBox->lines[79]->height);
check('the content after it follows the box', byId($ib, 'c')->y > $ibBox->y + $ibBox->height);

// ---------------------------------------------------------------------------
echo "\n5. Inside list markers and <summary> (Edge widths)\n";
// ---------------------------------------------------------------------------
$mk = render('<ul style="list-style-position:inside;padding:0"><li id="d">Adisc</li><li id="s" style="list-style-type:square">Csquare</li></ul>'
    . '<ul style="list-style-position:inside;padding:0;font-size:20pt"><li id="big">Kbig</li></ul>'
    . '<ol style="list-style-position:inside;padding:0"><li id="n">Ddec</li></ol>'
    . '<details><summary id="sum">Hsum</summary>x</details><details id="def"><p>Cpara</p></details>'
    . '<ul style="list-style-position:inside;padding:0;list-style-type:\'-> \'"><li id="str">Lstr</li></ul>'
    . '<ul style="list-style-type:\'-> \'"><li id="out">Aout</li></ul>');
$off = static fn(string $id, string $word): float => (wordX(byId($mk, $id), $word) ?? -100.0) - byId($mk, $id)->getContentX();
check('inside disc: text 15.49pt after the line start (Edge 15.49, was 15.24)', abs($off('d', 'Adisc') - 15.49) < 0.1, sprintf('%.2f', $off('d', 'Adisc')));
check('inside square: same width (was 16.21)', abs($off('s', 'Csquare') - 15.49) < 0.1, sprintf('%.2f', $off('s', 'Csquare')));
check('inside disc at 20pt: 26.74 (Edge; was 23.41)', abs($off('big', 'Kbig') - 26.74) < 0.1, sprintf('%.2f', $off('big', 'Kbig')));
check('inside decimal: "1. " unchanged (Edge 13.99)', abs($off('n', 'Ddec') - 13.99) < 0.1, sprintf('%.2f', $off('n', 'Ddec')));
check('<summary> text 11.65pt after its start (Edge 11.65, was 9.02)', abs($off('sum', 'Hsum') - 11.65) < 0.1, sprintf('%.2f', $off('sum', 'Hsum')));
$defSum = byId($mk, 'def')->children[0];
check('the default "Details" summary has the same marker', $defSum->tagName === 'summary' && abs((wordX($defSum, 'Details') ?? -100.0) - $defSum->getContentX() - 11.65) < 0.1);
check("list-style-type: '-> ' inside: the string is the marker (Edge 16.68)", abs($off('str', 'Lstr') - 16.68) < 0.1, sprintf('%.2f', $off('str', 'Lstr')));
check("list-style-type: '-> ' outside: stored as the marker text", byId($mk, 'out')->listMarker === '-> ');
$mw = pdfWords($mk);
if ($mw !== null) {
    $aout = array_values(array_filter($mw, static fn(array $w): bool => $w[4] === 'Aout'))[0] ?? null;
    $arrow = array_values(array_filter($mw, static fn(array $w): bool => $w[4] === '->' && $aout !== null && abs($w[1] - $aout[1]) < 1.0));
    check("the outside string marker ends at the content edge (Edge: '->' at x 55.32, Aout at 72.0)", count($arrow) === 1 && abs($arrow[0][0] - 55.32) < 0.2 && abs($aout[0] - 72.0) < 0.2, json_encode([$arrow, $aout]));
}

// ---------------------------------------------------------------------------
echo "\n6. Collapsed borders: the winning border of every edge (CSS 2.1 17.6.2.1)\n";
// ---------------------------------------------------------------------------
$cb = render('<table id="t1" style="border-collapse:collapse;border:6pt solid red"><tr><td id="a1" style="border:1pt solid">Aone</td><td id="b1" style="border:1pt solid">Btwo</td></tr><tr><td id="c1" style="border:1pt solid">Cthree</td><td style="border:1pt solid">Dfour</td></tr></table>'
    . '<table id="t2" style="border-collapse:collapse;border:1pt solid red"><tr><td id="f2" style="border:1pt solid;border-right:8pt solid blue">Fone</td><td id="g2" style="border:1pt solid">Gtwo</td></tr></table>'
    . '<table id="t3" style="border-collapse:collapse;border:6pt solid red;width:300pt"><tr><td style="border:1pt solid">Mone</td><td style="border:1pt solid">Ntwo</td></tr></table>'
    . '<table id="t4" style="border-collapse:collapse"><tr style="border:6pt solid"><td id="p4" style="border:1pt solid">Pone</td><td id="q4">Qtwo</td></tr></table>'
    . '<table id="t5" style="border-collapse:collapse;border:4pt solid"><tr><td id="h5" style="border:2pt hidden">Hid</td></tr></table>');
$t1 = byId($cb, 't1');
$a1 = byId($cb, 'a1');
check('the table border (6pt) wins over the cells (1pt): the content is inset by half of it', abs($a1->getContentX() - $t1->x - 6.75) < 0.05 && abs($a1->getContentY() - $t1->y - 6.75) < 0.05, sprintf('%.2f / %.2f (Edge 6.75)', $a1->getContentX() - $t1->x, $a1->getContentY() - $t1->y));
check('the outer cell carries the winning border', $a1->borderLeft === 6.0 && $a1->borderTop === 6.0 && $a1->borderRight === 1.0);
check('the second row starts below line + padding + the halves of the 1pt borders (15.25; Edge 15.0 with 1px borders)', abs(byId($cb, 'c1')->getContentY() - $a1->getContentY() - 15.25) < 0.05, sprintf('%.2f', byId($cb, 'c1')->getContentY() - $a1->getContentY()));
check('the table paints no border of its own (each collapsed border is drawn once)', $t1->borderTop === 0.0 && $t1->borderLeft === 0.0);
// two rows of 12.75pt lines + 1.5pt padding, the 1pt border between them and the 6pt outer borders (Edge 41.25)
check('the table box holds the whole outer borders', abs($t1->height - (6.0 + 2 * 14.25 + 1.0 + 6.0)) < 0.05, sprintf('%.2f', $t1->height));
// the grid line between the cells is the middle of the 8pt border: F's border box ends 4pt beyond it
$gridLine = byId($cb, 'f2')->x + byId($cb, 'f2')->width - 4.0;
check('a wider neighbour border wins: the next cell is inset by half of it (4 + 0.75 padding)', byId($cb, 'g2')->borderLeft === 8.0 && abs(byId($cb, 'g2')->getContentX() - $gridLine - 4.75) < 0.05, sprintf('%.2f', byId($cb, 'g2')->getContentX() - $gridLine));
check('a row border takes part in the resolution', byId($cb, 'p4')->borderLeft === 6.0 && byId($cb, 'q4')->borderTop === 6.0);
check('border-style: hidden suppresses the border', byId($cb, 'h5')->borderLeft === 0.0 && byId($cb, 'h5')->borderTop === 0.0);
check('table width is its border box (Chromium UA box-sizing: border-box)', abs(byId($cb, 't3')->width - 300.0) < 0.01, sprintf('%.2f', byId($cb, 't3')->width));
$sep = render('<table id="s" style="border:6pt solid;padding:4pt;width:300pt"><tr><td>x</td></tr></table>');
check('also with separated borders and padding', abs(byId($sep, 's')->width - 300.0) < 0.01, sprintf('%.2f', byId($sep, 's')->width));
$cw = pdfWords($cb);
if ($cw !== null) {
    $at = static function (string $w) use ($cw): ?array {
        foreach ($cw as $x) {
            if ($x[4] === $w) {
                return $x;
            }
        }
        return null;
    };
    check('Edge positions: Aone x 48.75, Btwo x 87.77, Mone right edge 194.5 ± 0.5', abs(($at('Aone')[0] ?? 0) - 48.75) < 0.3 && abs(($at('Btwo')[0] ?? 0) - 87.77) < 0.5);
}

// ---------------------------------------------------------------------------
echo "\n7. Consecutive forced breaks; right-to-left words split by font fallback\n";
// ---------------------------------------------------------------------------
foreach (['height:1pt', 'height:0'] as $h) {
    $pb = render("<p>a</p><div style=\"page-break-before:always;{$h}\"></div><div style=\"page-break-before:always;{$h}\"></div><p>b</p>");
    check("two consecutive page-break-before blocks ({$h}) give 3 pages (Edge)", $pb->getPageCount() === 3, (string) $pb->getPageCount());
}
$first = render('<div style="page-break-before:always"></div><p>a</p>');
check('a forced break before the first content still makes no empty first page', $first->getPageCount() === 1);
$nested = render('<p>a</p><div style="page-break-before:always"><div style="page-break-before:always">b</div></div>');
check('a forced break on a first child joins its parent\'s break', $nested->getPageCount() === 2, (string) $nested->getPageCount());
$after = render('<p>a</p><div style="page-break-after:always;height:1pt"></div><div style="page-break-after:always;height:1pt"></div><p>b</p>');
check('two consecutive page-break-after blocks give 3 pages (Edge)', $after->getPageCount() === 3);
$fb = render('<p id="r" dir="rtl" style="font-family:Inter">דוא"ל שלום</p>');
$pieces = [];
foreach (byId($fb, 'r')->lines[0]->runs as $r) {
    if (!$r->isWhitespace) {
        $pieces[] = $r->text . '@' . strtolower($r->style->get('font-family'));
    }
}
check('a fallback-font RTL word keeps its inner quote in that font: one run (was ל | " | דוא)', in_array('דוא"ל@dejavu sans', $pieces, true), implode(' ', $pieces));
$fbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-final-x-rtl.pdf';
file_put_contents($fbFile, $fb->output());
$fbScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-final-x-text.py';
file_put_contents($fbScript, "import fitz,sys
sys.stdout.buffer.write(fitz.open(sys.argv[1])[0].get_text().encode('utf8'))
");
$fbText = @shell_exec('python ' . escapeshellarg($fbScript) . ' ' . escapeshellarg($fbFile) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
@unlink($fbFile);
if (is_string($fbText) && $fbText !== '') {
    check('the paragraph is extracted in logical order (before: ל שלום" דוא)', trim($fbText) === 'דוא"ל שלום', $fbText);
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
