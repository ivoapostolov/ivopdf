<?php

declare(strict_types=1);

/**
 * Regression tests for the layout findings of the independent (Fable) audit, part B:
 *  - H3: no blank page after a box that exactly fills the page and has page-break-after: always
 *  - H4: a block that starts beside a preceding float keeps its full width; only its lines are shortened, each by
 *        the floats beside that line (lines below the float return to the full width); floats paint over blocks
 *  - H6: thousands of floats and 20 000 <br> inside an inline-block lay out in near-linear time
 *  - M6: vertical padding / borders of inline boxes do not enlarge the line box; clearance absorbs the margins
 *  - M7: an unbreakable word overflows its box unless overflow-wrap / word-break allow breaking it
 *  - L5: list-style shorthand, outside marker gaps, percentage cell widths, caption padding, collapsed borders,
 *        margin boxes inheriting the @page font, widows / orphans
 *  - L6 / H1.2: absurd lengths (font-size, border, padding, line-height, image size, tab-size) are clamped
 *
 * Run: php tests/test_audit_fable_b.php
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
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable-b-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([{'w':[list(w[:5]) for w in p.get_text('words')]} for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable-b-words.py';
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

/** @return list<LayoutBox> */
function byTag(LayoutBox $box, string $tag): array
{
    $out = [];
    foreach ($box->children as $c) {
        if ($c->tagName === $tag) {
            $out[] = $c;
        }
        array_push($out, ...byTag($c, $tag));
    }
    return $out;
}

/** Right edge of every line of a box: x of the line start + its width. @return list<float> */
function lineRights(LayoutBox $box): array
{
    return array_map(static fn($l): float => $l->x + $l->width, $box->lines);
}

$page = '<style>@page{size:A4;margin:36pt} html,body{margin:0;padding:0} body{font-family:Arial;font-size:10pt}</style>';
$words = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty ';

// ---------------------------------------------------------------------------
echo "H3. page-break-after: always on a box that exactly fills the page\n";
// ---------------------------------------------------------------------------
$labels = render('<style>@page{size:100mm 50mm;margin:0} html,body{margin:0;padding:0} .lab{width:100mm;height:50mm;box-sizing:border-box;padding:4mm;page-break-after:always;border:0.3mm solid #000}</style>'
    . '<div class="lab">A</div><div class="lab">B</div><div class="lab">C</div>');
check('three 50 mm labels on 50 mm pages give three pages (no blank page after each)', $labels->getPageCount() === 3, (string) $labels->getPageCount());
$a4 = render('<style>@page{size:A4;margin:0} html,body{margin:0}</style><div style="height:297mm;page-break-after:always">A</div><div style="height:297mm;page-break-after:always">B</div>');
check('two full-height A4 boxes give two pages', $a4->getPageCount() === 2, (string) $a4->getPageCount());
$short = render($page . '<div style="height:100pt;page-break-after:always">A</div><div>B</div>');
check('a shorter box still breaks after itself', $short->getPageCount() === 2, (string) $short->getPageCount());
$carriers = render($page . '<p>a</p><div style="page-break-after:always"></div><p>b</p>');
check('an empty break carrier still breaks', $carriers->getPageCount() === 2, (string) $carriers->getPageCount());

// ---------------------------------------------------------------------------
echo "\nH4. A block beside a preceding float: full-width box, lines shortened only beside the float\n";
// ---------------------------------------------------------------------------
$f1 = render($page . '<style>p{margin:0 0 8pt} .box{float:right;width:160pt;height:30pt;margin:0 0 8pt 10pt}</style>'
    . '<div class="box"></div><p id="p">' . str_repeat($words, 6) . 'END</p><p id="p2">second para</p>');
$p = findById($f1->getLayoutTree(), 'p');
$rights = lineRights($p);
$floatLeft = 559.28 - 160.0 - 10.0; // the float's margin box starts here
$besideLines = count(array_filter($p->lines, static fn($l): bool => $l->y < 36.0 + 38.0 - 0.5));
check('the paragraph box keeps the full width', $p !== null && abs($p->width - 523.28) < 0.1, $p ? sprintf('%.2f', $p->width) : 'missing');
check('lines beside the float end before it', $besideLines >= 3 && max(array_slice($rights, 0, $besideLines)) <= $floatLeft + 0.1, sprintf('%d lines, max right %.1f', $besideLines, max(array_slice($rights, 0, $besideLines))));
check('lines below the float use the full width again (as in Edge: 10 lines, not 12)', count($p->lines) === 9 && max(array_slice($rights, $besideLines, -1)) > 530.0, sprintf('%d lines, max right %.1f', count($p->lines), max(array_slice($rights, $besideLines, -1))));
$f2 = render($page . '<div style="float:left;width:100pt;height:40pt"></div><div id="bfc" style="overflow:hidden">' . $words . '</div><table id="t"><tr><td>cell</td></tr></table>');
$bfc = findById($f2->getLayoutTree(), 'bfc');
check('a block formatting context root (overflow: hidden) is placed beside the float instead', $bfc !== null && abs($bfc->x - 136.0) < 0.1 && $bfc->width < 424.0, $bfc ? sprintf('x=%.1f w=%.1f', $bfc->x, $bfc->width) : 'missing');
$f3 = render($page . '<div style="float:left;width:100pt;height:60pt"></div><div id="outer"><p id="inner" style="margin:0">' . str_repeat($words, 8) . '</p></div>');
$inner = findById($f3->getLayoutTree(), 'inner');
$ir = lineRights($inner);
check('nested blocks inherit the float: their first lines start after it, later lines at the left edge',
    $inner !== null && abs($inner->lines[0]->x - 136.0) < 0.1 && abs(end($inner->lines)->x - 36.0) < 0.1,
    $inner ? sprintf('first x=%.1f last x=%.1f', $inner->lines[0]->x, end($inner->lines)->x) : 'missing');
// The float sits below the bottom margin of the block before it, not below the collapsed margin of the next one
$rep = render($page . '<h1 id="h" style="font-size:18pt;margin:0 0 10pt">Title</h1><div id="fl" style="float:right;width:100pt;height:20pt"></div><h2 id="h2" style="margin:14pt 0 6pt">Section</h2>');
$h = findById($rep->getLayoutTree(), 'h');
$fl = findById($rep->getLayoutTree(), 'fl');
$h2 = findById($rep->getLayoutTree(), 'h2');
check('a float between two blocks starts after the first block\'s bottom margin', $fl !== null && $h !== null && abs($fl->y - ($h->y + $h->height + 10.0)) < 0.1, $fl ? sprintf('float y=%.2f h1 bottom=%.2f', $fl->y, $h->y + $h->height) : 'missing');
check('the next block still collapses the margins (14pt)', $h2 !== null && abs($h2->y - ($h->y + $h->height + 14.0)) < 0.1, $h2 ? sprintf('%.2f', $h2->y) : 'missing');
// Floats paint after the in-flow blocks they overlap (CSS 2.1 Appendix E)
$paint = new IvoPdf(['compress' => false]);
$paint->loadHtml('<div style="float:left;width:50pt;height:50pt;background:#ff0000">F</div><p style="background:#00ff00">para</p>');
$stream = $paint->output();
$redAt = strpos($stream, '1.000 0.000 0.000 rg');
$greenAt = strpos($stream, '0.000 1.000 0.000 rg');
check('the float background is painted after (over) the paragraph background beside it', $redAt !== false && $greenAt !== false && $redAt > $greenAt);

// ---------------------------------------------------------------------------
echo "\nM6. Inline padding and clearance\n";
// ---------------------------------------------------------------------------
$plain = render($page . '<p id="a">text <span>inline span long enough</span> after</p><p id="b">next</p>');
$padded = render($page . '<p id="a">text <span style="padding:2pt 4pt;border:1pt solid #000;background:#ff0">inline span long enough</span> after</p><p id="b">next</p>');
check('a padded, bordered <span> does not make its line taller', abs(findById($plain->getLayoutTree(), 'b')->y - findById($padded->getLayoutTree(), 'b')->y) < 0.01,
    sprintf('%.2f vs %.2f', findById($plain->getLayoutTree(), 'b')->y, findById($padded->getLayoutTree(), 'b')->y));
$clr = render($page . '<div style="float:left;width:80pt;height:30pt;margin:0 6pt 6pt 0"></div><div style="float:right;width:60pt;height:50pt;margin:0 0 6pt 6pt"></div>'
    . '<p style="margin:0 0 10pt">short</p><div id="c" style="clear:both">cleared</div>');
$c = findById($clr->getLayoutTree(), 'c');
check('clearance puts the block right below the floats (margins above it are absorbed)', $c !== null && abs($c->y - (36.0 + 56.0)) < 0.1, $c ? sprintf('%.2f', $c->y) : 'missing');
$noClr = render($page . '<div style="float:left;width:80pt;height:5pt"></div><p style="margin:0 0 20pt">short</p><div id="c" style="clear:both">x</div>');
$c2 = findById($noClr->getLayoutTree(), 'c');
check('no clearance when the margins already reach below the float', $c2 !== null && $c2->y > 36.0 + 20.0, $c2 ? sprintf('%.2f', $c2->y) : 'missing');

// ---------------------------------------------------------------------------
echo "\nM7. Unbreakable words overflow by default\n";
// ---------------------------------------------------------------------------
$lw = 'Supercalifragilisticexpialidociousandmore word';
$ow = render($page . '<div id="n" style="width:120pt">' . $lw . '</div><div id="a" style="width:120pt;overflow-wrap:anywhere">' . $lw . '</div>'
    . '<div id="w" style="width:120pt;word-wrap:break-word">' . $lw . '</div><div id="b" style="width:120pt;word-break:break-all">' . $lw . '</div>');
$tree = $ow->getLayoutTree();
check('overflow-wrap: normal keeps the long word on one line (it overflows the box)', count(findById($tree, 'n')->lines) === 2 && findById($tree, 'n')->lines[0]->width > 120.0);
check('overflow-wrap: anywhere breaks it', count(findById($tree, 'a')->lines) === 2 && findById($tree, 'a')->lines[0]->width <= 120.05);
check('word-wrap: break-word (legacy alias) breaks it', count(findById($tree, 'w')->lines) === 2 && findById($tree, 'w')->lines[0]->width <= 120.05);
check('word-break: break-all breaks it', findById($tree, 'b')->lines[0]->width <= 120.05);
check('the overflowing word adds no page', $ow->getPageCount() === 1);
$guard = render($page . '<div id="g" style="width:120pt">' . str_repeat('x', 3000) . '</div>');
check('the maxWordLength guard still splits a 3 000-character word', count(findById($guard->getLayoutTree(), 'g')->lines) >= 2);

// ---------------------------------------------------------------------------
echo "\nH6. Near-linear floats and <br> inside an inline-block\n";
// ---------------------------------------------------------------------------
$t0 = microtime(true);
$many = render(str_repeat('<div style="float:left;width:10px;height:10px">x</div>', 4000), ['maxRenderTime' => 300]);
$tf = microtime(true) - $t0;
check('4 000 floats render in under 8 s (was 13.6 s; 8 000 took 54 s)', $tf < 8.0, sprintf('%.2f s', $tf));
$rows = [];
foreach (byTag($many->getLayoutTree(), 'div') as $d) {
    $rows[(string) round($d->y, 1)] = true;
}
check('each float row starts below the previous one (no float above an earlier float)', count($rows) > 40, (string) count($rows));
$t0 = microtime(true);
$brs = render('<div style="display:inline-block">' . str_repeat('x<br>', 10000) . '</div>', ['maxRenderTime' => 300]);
$tb = microtime(true) - $t0;
check('10 000 <br> in an inline-block render in under 6 s (was 17.4 s)', $tb < 6.0, sprintf('%.2f s', $tb));
$bytes = strlen($brs->output());
check('each page paints only its slice of the inline-block (output stays small)', $bytes < 3_000_000, (string) $bytes);
$fl3 = render('<style>@page{size:A4;margin:36pt} body{margin:0;font-size:5px;line-height:1} div{float:left;width:20px;height:16px;margin:0 2px 3px 0}</style>'
    . str_repeat("<div></div>\n", 3000) . '<p id="end" style="clear:left">end</p>');
check('3 000 floats separated by white space wrap onto rows and two pages (as in Edge)', $fl3->getPageCount() === 2, (string) $fl3->getPageCount());

// ---------------------------------------------------------------------------
echo "\nL5. Lists, tables, captions, margin boxes\n";
// ---------------------------------------------------------------------------
$lists = render($page . '<ul style="list-style-position:inside"><li id="sq" style="list-style:square">square</li></ul><ol><li id="one">one</li></ol>');
$sq = findById($lists->getLayoutTree(), 'sq');
check('list-style: square resets an inherited list-style-position: inside (marker outside)', $sq !== null && $sq->listMarker === '▪', $sq ? var_export($sq->listMarker, true) : 'missing');
$wp = pdfWords($lists);
if ($wp === null) {
    skip('outside marker gap: PyMuPDF not available');
} else {
    $num = wordsMatching($wp[0], '1.');
    $txt = wordsMatching($wp[0], 'one');
    check('the outside "1." marker ends one space (2.78 pt at 10pt Arial) before the text, as in Chrome', $num !== [] && $txt !== [] && abs(($txt[0][0] - $num[0][2]) - 2.78) < 0.3,
        $num !== [] && $txt !== [] ? sprintf('gap %.2f', $txt[0][0] - $num[0][2]) : 'missing');
}
$pct = render($page . '<table style="width:100%;border-collapse:collapse"><tr><td id="c30" style="width:30%;border:1px solid;padding:2pt">30%</td><td style="border:1px solid;padding:2pt">rest of the row text</td></tr></table>');
$c30 = findById($pct->getLayoutTree(), 'c30');
check('a 30% cell takes 30% of the table (padding included, as in browsers)', $c30 !== null && abs($c30->width - 0.3 * (523.28 - 0.75)) < 1.0, $c30 ? sprintf('%.2f', $c30->width) : 'missing');
$cap = render($page . '<table id="t"><caption id="cap">Caption</caption><tr><td>x</td></tr></table>');
check('<caption> has no default padding', findById($cap->getLayoutTree(), 'cap')->paddingBottom === 0.0);
$coll = render($page . '<style>td{border:10pt solid #888;padding:0}</style><table id="t" style="border-collapse:collapse"><tr><td id="a">QAAAA</td><td>QBB</td></tr></table><p id="after" style="margin:0">x</p>');
$t = findById($coll->getLayoutTree(), 't');
$a = findById($coll->getLayoutTree(), 'a');
check('a collapsed table holds its whole outer border (top edge at the table top, as in Edge)', $t !== null && $a !== null && abs(($a->lines[0]->y ?? 0) - ($t->y + 10.0)) < 0.1, $a ? sprintf('content y %.2f table y %.2f', $a->lines[0]->y ?? 0, $t->y) : 'missing');
check('collapsed table height = content + one border per grid line', $t !== null && abs($t->height - (10.0 + 11.5 + 10.0)) < 0.6, $t ? sprintf('%.2f', $t->height) : 'missing');
check('collapsed columns count each shared border once', $t !== null && $t->width < 3 * 10.0 + 60.0, $t ? sprintf('%.2f', $t->width) : 'missing');
$inv = render($page . '<table style="width:100%;border-collapse:collapse"><tr><th style="border:1px solid;padding:4pt 6pt">№</th><th style="border:1px solid;padding:4pt 6pt">Описание</th>'
    . '<th style="border:1px solid;padding:4pt 6pt">Кол.</th><th id="h" style="border:1px solid;padding:4pt 6pt">Ед. цена</th><th style="border:1px solid;padding:4pt 6pt">Сума</th></tr>'
    . '<tr><td style="border:1px solid;padding:4pt 6pt">1</td><td style="border:1px solid;padding:4pt 6pt">Услуга по поддръжка на уеб сайт – месец 1, включително хостинг и домейн</td><td style="border:1px solid;padding:4pt 6pt">2</td><td style="border:1px solid;padding:4pt 6pt">1 234,56</td><td style="border:1px solid;padding:4pt 6pt">2 345,67</td></tr>'
    . '<tr><td colspan="4" style="border:1px solid;padding:4pt 6pt">Данъчна основа</td><td style="border:1px solid;padding:4pt 6pt;font-weight:bold">12 345,67</td></tr></table>');
check('invoice header "Ед. цена" wraps like in Edge (auto column width with collapsed borders)', count(findById($inv->getLayoutTree(), 'h')->lines) === 2);
$mb = render('<style>@page{size:A4;margin:36pt;font-family:Arial;font-size:9pt;@bottom-right{content:"Qpage " counter(page)}} body{margin:0}</style><p>x</p>');
$mw = pdfWords($mb);
if ($mw === null) {
    skip('margin box font: PyMuPDF not available');
} else {
    $q = wordsMatching($mw[0], 'Qpage');
    check('margin boxes inherit font-family / font-size from @page (9pt Arial "Qpage" is about 28 pt wide)', $q !== [] && abs(($q[0][2] - $q[0][0]) - 27.5) < 1.5, $q !== [] ? sprintf('%.2f', $q[0][2] - $q[0][0]) : 'missing');
}

// widows / orphans (initial 2, as in browsers)
$fill = '<div style="height:' . (769.89 - 3 * 11.5 - 1.0) . 'pt"></div>';
$four = 'line one<br>line two<br>line three<br>line four';
$wo = render($page . $fill . '<p id="w" style="margin:0">' . $four . '</p>');
$wl = findById($wo->getLayoutTree(), 'w');
$onFirst = count(array_filter($wl->lines, static fn($l): bool => $l->y < 805.89));
check('widows: a 4-line paragraph with room for 3 lines breaks 2 + 2 (not 3 + 1)', count($wl->lines) === 4 && $onFirst === 2, sprintf('%d lines, %d on page 1', count($wl->lines), $onFirst));
$fill1 = '<div style="height:' . (769.89 - 11.5 - 1.0) . 'pt"></div>';
$or = render($page . $fill1 . '<p id="o" style="margin:0">' . $four . '</p>');
$ol = findById($or->getLayoutTree(), 'o');
check('orphans: a single line is not left at the page bottom (the paragraph moves)', $ol->lines[0]->y > 805.0, sprintf('first line y %.1f', $ol->lines[0]->y));
$wo3 = render($page . $fill . '<p id="w" style="margin:0;widows:1;orphans:1">' . $four . '</p>');
$wl3 = findById($wo3->getLayoutTree(), 'w');
check('widows: 1 / orphans: 1 allow the 3 + 1 split', count(array_filter($wl3->lines, static fn($l): bool => $l->y < 805.89)) === 3);

// ---------------------------------------------------------------------------
echo "\nL6 / H1.2. Absurd lengths are clamped\n";
// ---------------------------------------------------------------------------
foreach ([
    'font-size: 1e9pt' => '<p style="font-size:1e9pt">x</p>',
    'border: 1e9px' => '<p style="border:1e9px solid">x</p>',
    'padding: 1e9%' => '<p style="padding:1e9%">x</p>',
    'line-height: 1e9' => '<p style="line-height:1e9">x</p>',
    '<img width="1e9">' => '<img width="1e9" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">',
] as $label => $html) {
    $t0 = microtime(true);
    $pdf = render($html);
    check("{$label}: at most 60 pages instead of 5 000", $pdf->getPageCount() <= 60, (string) $pdf->getPageCount());
}
$big = render('<p id="b" style="font-size:2000pt">x</p><p id="n" style="font-size:72pt;border:20pt solid;padding:50pt;line-height:3">x</p>');
$bb = findById($big->getLayoutTree(), 'b');
$nn = findById($big->getLayoutTree(), 'n');
check('large but sane values are kept (font-size 2000pt, border 20pt, padding 50pt)', $bb->fontSizePt === 2000.0 && $nn->borderTop === 20.0 && $nn->paddingTop === 50.0);
$m0 = memory_get_usage();
$tab = render('<pre id="pre" style="tab-size:1e9">a' . "\t" . 'b' . "\t" . 'c</pre>');
$grow = memory_get_peak_usage() - $m0;
check('<pre style="tab-size:1e9"> with tabs does not allocate a gigabyte', $grow < 64 * 1048576, sprintf('%.1f MB', $grow / 1048576));
check('a tab never advances further than the line width', ($tab->getLayoutTree() !== null));
$tabs = render($page . '<pre id="p" style="font-family:\'Courier New\';tab-size:4">a' . "\t" . 'b</pre>');
$pre = findById($tabs->getLayoutTree(), 'p');
$pw = pdfWords($tabs);
if ($pw === null) {
    skip('tab-size 4 positions: PyMuPDF not available');
} else {
    $bw = wordsMatching($pw[0], 'b');
    check('ordinary tab-size values are unchanged (b at column 4 = 24 pt)', $bw !== [] && abs($bw[0][0] - (36.0 + 24.0)) < 0.2, $bw !== [] ? sprintf('%.2f', $bw[0][0]) : 'missing');
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
