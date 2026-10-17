<?php

declare(strict_types=1);

/**
 * Robustness tests: HTML5 parsing, table shrink-to-fit, failure behaviour (limits / degradation),
 * selector index, render culling, lazy pagination and a performance smoke test.
 *
 * Run: php tests/test_robustness.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\CssParser;
use IvoPdf\Dom\DomBuilder;
use IvoPdf\Dom\Html5Parser;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\TableShrink;
use IvoPdf\Options;

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

/** @param array<string, mixed> $options */
function renderDoc(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->render();
    return $pdf;
}

function findBox(LayoutBox $box, callable $pred): ?LayoutBox
{
    if ($pred($box)) {
        return $box;
    }
    foreach ($box->children as $child) {
        $found = findBox($child, $pred);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
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
    foreach ($box->children as $child) {
        array_push($out, ...lineTexts($child));
    }
    return $out;
}

/** Per-page text spans through PyMuPDF; null when python/fitz is unavailable. */
function pdfSpans(string $pdfBytes): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-rob-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdfBytes);
    $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-rob-spans.py';
    file_put_contents($script, "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([p.get_text() for p in d]))\n");
    $json = @shell_exec('python ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

$html5 = Html5Parser::available();

// ---------------------------------------------------------------------------
echo "1. HTML5 parsing\n";
// ---------------------------------------------------------------------------
if (!$html5) {
    echo "  (PHP < 8.4: \\Dom\\HTMLDocument unavailable, checking the fallback only)\n";
    $legacy = renderDoc('<p>fallback</p>');
    check('legacy parser still renders', $legacy->getPageCount() === 1);
} else {
    $tags = static function (string $html): string {
        $dom = Html5Parser::parse($html);
        $out = [];
        foreach ($dom?->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $n) {
            $out[] = $n instanceof DOMElement ? $n->tagName : '#t:' . $n->textContent;
        }
        return implode(',', $out);
    };
    check('<p> is closed by a following <div>', $tags('<p>a<div>b</div>c') === 'p,div,#t:c', $tags('<p>a<div>b</div>c'));
    check('<table> text is foster-parented before the table', $tags('<table>FOSTER<tr><td>c</td></tr></table>') === '#t:FOSTER,table');
    $dom = Html5Parser::parse('<table><tr><td>c</table>');
    check('implied <tbody> is created', $dom?->getElementsByTagName('tbody')->length === 1);
    $dom = Html5Parser::parse('<ul><li>1<li>2<li>3</ul>');
    check('<li> auto-closing', $dom?->getElementsByTagName('li')->length === 3 && $dom->getElementsByTagName('ul')->item(0)?->childNodes->length === 3);

    $dom = Html5Parser::parse('<svg viewBox="0 0 10 10" preserveAspectRatio="xMidYMid"><linearGradient id="g"/><foreignObject/></svg>');
    $svg = $dom?->getElementsByTagName('svg')->item(0);
    check('svg viewBox / preserveAspectRatio keep their case', $svg?->getAttribute('viewBox') === '0 0 10 10' && $svg->getAttribute('preserveAspectRatio') === 'xMidYMid');
    check('svg camelCase element names survive', $dom?->getElementsByTagName('linearGradient')->length === 1 && $dom->getElementsByTagName('foreignObject')->length === 1);
    $svgPdf = renderDoc('<svg viewBox="0 0 200 100" style="display:block"><rect width="200" height="100" fill="red"/></svg>');
    $svgBox = findBox($svgPdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'svg');
    check('inline svg takes its size from viewBox (200px = 150pt)', $svgBox !== null && abs($svgBox->width - 150.0) < 0.5, (string) $svgBox?->width);

    $dom = Html5Parser::parse('<p>a&nbsp b &copy 2024 &amp c &lt;d</p>');
    $text = (string) $dom?->getElementsByTagName('p')->item(0)?->textContent;
    check('entities without trailing semicolon', $text === "a\u{00A0} b \u{00A9} 2024 & c <d", bin2hex($text));

    $dom = Html5Parser::parse('<div class="clear"/><p>after</p><td/>');
    $first = $dom?->getElementsByTagName('div')->item(0);
    check('XHTML-style <div/> does not swallow following content', $first !== null && $first->childNodes->length === 0 && $dom->getElementsByTagName('p')->item(0)?->parentNode?->nodeName === 'body');
    $esc = Html5Parser::expandSelfClosing('<svg><path d="M0"/></svg><br/><img src="a"/><x-y a="1"/>');
    check('self-closing expansion keeps svg / void elements', $esc === '<svg><path d="M0"/></svg><br/><img src="a"/><x-y a="1"></x-y>', $esc);

    $dom = Html5Parser::parse('<p @click="x" [prop]="y" data-ok="1">z</p><a$b>q</a$b>');
    check('attribute / tag names invalid in XML do not break parsing', $dom?->getElementsByTagName('p')->item(0)?->getAttribute('data-ok') === '1');

    $dom = Html5Parser::parse(str_repeat('<div>', 5000) . 'x', 64, $warnCb = static function (string $m) use (&$warned): void {
        $warned = $m;
    });
    $depth = 0;
    for ($n = $dom?->getElementsByTagName('body')->item(0); $n instanceof DOMElement && $n->firstElementChild !== null; $n = $n->firstElementChild) {
        $depth++;
    }
    check('nesting beyond the limit is flattened (depth <= limit) with a warning', $depth <= 70 && isset($warned), "depth {$depth}");

    $po = renderDoc('<p>Alpha</p><my-widget>Inline custom</my-widget> tail<template><p>HIDDEN-TEMPLATE</p></template>');
    $texts = implode('|', lineTexts($po->getLayoutTree()));
    check('<template> content is not rendered', !str_contains($texts, 'HIDDEN-TEMPLATE'), $texts);
    $custom = findBox($po->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'my-widget');
    check('custom elements are inline', $custom !== null && $custom->getDisplay() === 'inline');
    check('custom element text shares a line with its siblings', in_array(true, array_map(static fn(string $l): bool => str_contains($l, 'Inline custom') && str_contains($l, 'tail'), lineTexts($po->getLayoutTree())), true), $texts);

    $a = renderDoc('<p>x</p><div>y</div>', ['html5Parser' => true]);
    $b = renderDoc('<p>x</p><div>y</div>', ['html5Parser' => false]);
    check('html5Parser=false uses the libxml path and still renders', $a->getPageCount() === 1 && $b->getPageCount() === 1);

    $frag = new DomBuilder(new Options(), new CssParser());
    $root = $frag->build('<b>bold</b> fragment');
    check('header/footer style fragments without <html>/<body> build a tree', $root->tagName === 'body' && lineTexts($root) !== [] || count($root->children) > 0);

    $mis = renderDoc('<p>First<div style="border:1px solid red">Block</div>Rest</p>');
    $blk = findBox($mis->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'div');
    $par = findBox($mis->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'p');
    check('mis-nested <p><div>: the div is a sibling placed below the paragraph', $blk !== null && $par !== null && $blk->y >= $par->y + $par->height - 1.0);
}

// ---------------------------------------------------------------------------
echo "\n2. Table shrink-to-fit\n";
// ---------------------------------------------------------------------------
$row = static fn(int $cols, string $word): string => '<tr>' . str_repeat("<td>{$word}</td>", $cols) . '</tr>';
$wide = '<style>td, th { padding: 6pt 8pt; }</style><table border="1">' . str_repeat($row(8, 'Wonderful'), 3) . '</table>';
$tableOf = static fn(IvoPdf $p): ?LayoutBox => findBox($p->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'table');
/** Largest overflow of any text run beyond its cell (pt) and whether any word got split. */
$overflow = static function (LayoutBox $table): float {
    $max = 0.0;
    $stack = [$table];
    while ($stack) {
        $b = array_pop($stack);
        if (in_array($b->tagName, ['td', 'th'], true)) {
            foreach ($b->lines as $line) {
                foreach ($line->runs as $run) {
                    $max = max($max, ($run->x + $run->width) - ($b->x + $b->width - $b->paddingRight));
                }
            }
        }
        foreach ($b->children as $c) {
            $stack[] = $c;
        }
    }
    return $max;
};

$off = renderDoc($wide, ['shrinkTablesToFit' => 0]);
$on = renderDoc($wide);
$tOff = $tableOf($off);
$tOn = $tableOf($on);
$printable = 595.28 - 72.0;
$firstCellLines = static function (?LayoutBox $t): int {
    $td = $t !== null ? findBox($t, static fn(LayoutBox $b): bool => $b->tagName === 'td') : null;
    return $td !== null ? count($td->lines) : -1;
};
// Browsers never break a word inside a cell (overflow-wrap: normal): without shrinking the table grows past the page width
check('without shrinking, words stay whole and the table grows past the content width', $firstCellLines($tOff) === 1 && $tOff !== null && $tOff->width > $printable, (string) $firstCellLines($tOff));
check('with shrinking, every word stays on one line', $firstCellLines($tOn) === 1, (string) $firstCellLines($tOn));
check('shrink-to-fit: no content overflows its cell', $tOn !== null && $overflow($tOn) <= 0.5, (string) ($tOn ? $overflow($tOn) : -1));
check('shrink-to-fit: table stays within the content width', $tOn !== null && $tOn->x + $tOn->width <= 36.0 + $printable + 2.0);
$scale = $tOn !== null ? TableShrink::appliedScale($tOn) : 1.0;
check('shrink scale is below 1 but within the 1/1.4 limit', $scale < 1.0 && $scale >= 1 / 1.4 - 0.001, (string) $scale);
$fs = findBox($on->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'td');
check('cell font size was scaled down', $fs !== null && $fs->fontSizePt < 12.0 * 0.99 && $fs->fontSizePt >= 12.0 / 1.4 - 0.01, (string) $fs?->fontSizePt);

$huge = '<table border="1">' . $row(40, 'Wonderful') . '</table>';
$hp = renderDoc($huge);
$ht = $tableOf($hp);
check('unfittable table is limited to the factor (no exception, scale == 1/1.4)', $ht !== null && abs(TableShrink::appliedScale($ht) - 1 / 1.4) < 0.01, (string) ($ht ? TableShrink::appliedScale($ht) : 0));
$hp2 = renderDoc($huge, ['shrinkTablesToFit' => 3.0]);
$ht2 = $tableOf($hp2);
check('larger factor shrinks further', $ht2 !== null && TableShrink::appliedScale($ht2) < 0.5);

$auto0 = renderDoc(str_replace('<table ', '<table autosize="0" ', $wide));
check('autosize="0" disables shrinking for that table', TableShrink::appliedScale($tableOf($auto0)) === 1.0);
$auto2 = renderDoc(str_replace('<table ', '<table autosize="2" ', '<table border="1">' . $row(18, 'Wonderful') . '</table>'), ['shrinkTablesToFit' => 0]);
check('autosize="2" enables shrinking even when the option is 0', TableShrink::appliedScale($tableOf($auto2)) < 0.7);
$mp = new Options(['shrink_tables_to_fit' => 2]);
check('mPDF alias shrink_tables_to_fit', abs($mp->shrinkTablesToFit - 2.0) < 1e-9);

$fits = renderDoc('<table border="1"><tr><td>Short</td><td>Cells</td></tr></table>');
check('a table that already fits is untouched', TableShrink::appliedScale($tableOf($fits)) === 1.0);
$fixedW = renderDoc('<table border="1" style="width:900px"><tr><td>Alpha beta</td><td>Gamma</td></tr></table>');
$fw = $tableOf($fixedW);
check('explicit table width wider than the page is clamped to the container', $fw !== null && $fw->width <= $printable + 0.5, (string) $fw?->width);

// ---------------------------------------------------------------------------
echo "\n3. Failure behaviour and limits\n";
// ---------------------------------------------------------------------------
$t0 = microtime(true);
$deep = renderDoc(str_repeat('<div>', 10000) . 'DEEP-TEXT' . str_repeat('</div>', 10000));
check('10 000 nested <div>: renders, text kept, warning added', in_array('DEEP-TEXT', array_map('trim', lineTexts($deep->getLayoutTree())), true) && $deep->getWarnings() !== []);
$deepSpan = renderDoc(str_repeat('<span>', 10000) . 'x' . str_repeat('</span>', 10000));
check('10 000 nested <span>: no fatal error', $deepSpan->getPageCount() >= 1);
$deepTable = renderDoc(str_repeat('<table><tr><td>', 1500) . 'x' . str_repeat('</td></tr></table>', 1500));
check('1 500 nested tables: no fatal error', $deepTable->getPageCount() >= 1);
$custom = renderDoc(str_repeat('<div>', 40) . 'ok' . str_repeat('</div>', 40), ['maxNestingDepth' => 10]);
check('maxNestingDepth is configurable', $custom->getWarnings() !== []);
check('deep nesting finishes quickly', microtime(true) - $t0 < 20.0);

$t0 = microtime(true);
$word = renderDoc('<p>' . str_repeat('A', 300000) . '</p>');
check('300 KB single word: renders, split with a warning', $word->getPageCount() >= 1 && $word->getWarnings() !== [] && microtime(true) - $t0 < 30.0);
$word2 = renderDoc('<p>' . str_repeat('B', 5000) . '</p>', ['maxWordLength' => 0]);
check('maxWordLength=0 disables the word guard', $word2->getWarnings() === []);

$threw = null;
try {
    renderDoc('<p>' . str_repeat('x', 2 * 1048576) . '</p>', ['maxHtmlBytes' => 1048576]);
} catch (Throwable $e) {
    $threw = $e;
}
check('oversized HTML throws a clear LengthException', $threw instanceof LengthException && str_contains($threw->getMessage(), 'maxHtmlBytes'), $threw ? get_class($threw) : 'no exception');
$big = renderDoc('<p>' . str_repeat('lorem ', 1000) . '</p>', ['maxHtmlBytes' => 1048576]);
check('HTML below the limit is accepted', $big->getPageCount() >= 1);

$tall = renderDoc('<div style="height:99999999px">x</div>', ['maxPages' => 25]);
check('runaway page count is truncated to maxPages with a warning', $tall->getPageCount() === 25 && $tall->getWarnings() !== []);

$css = renderDoc('<style>p{color:;;} {{{ } @media ( { a{b:c} } p{margin:calc(} .x{background:url(} @font-face{src:url(nofile.ttf)} }}}} p{</style><p>survives</p>');
check('malformed CSS does not abort rendering', in_array('survives', lineTexts($css->getLayoutTree()), true));
$font = renderDoc('<p style="font-family:\'No Such Font\', Nothing">still renders</p>');
check('missing font family falls back to the default font', in_array('still renders', lineTexts($font->getLayoutTree()), true));
$bad = renderDoc('<style>@font-face{font-family:X;src:url(missing.ttf)}p{font-family:X}</style><p>fb</p>');
check('unusable @font-face yields a warning, not an error', $bad->getWarnings() !== [] && $bad->getPageCount() === 1);
$e1 = renderDoc('');
check('empty input renders one blank page', $e1->getPageCount() === 1);
$e2 = renderDoc("<p>bad \xff\xfe bytes \xc3</p>");
check('invalid UTF-8 bytes do not abort', $e2->getPageCount() === 1);
$e3 = renderDoc('<table><tr><td colspan="99999999" rowspan="99999999">x</td></tr></table><div style="width:-50px;margin:-9999px;font-size:100000pt">y</div>');
check('absurd colspan / negative / huge CSS values do not abort', $e3->getPageCount() >= 1);

// ---------------------------------------------------------------------------
echo "\n4. Selector index (rule bucketing)\n";
// ---------------------------------------------------------------------------
$sel = renderDoc(
    '<style>p{color:#111111} .a{color:#222222} p.a{color:#333333} #i{color:#444444} div > p{background:#aaaaaa} '
    . '.b.c{color:#555555} *{margin:0} [data-x]{border-color:#666666} p:first-child{color:#777777} div p.a:not(.z){font-weight:bold}</style>'
    . '<div><p id="p1">one</p><p class="a" id="p2">two</p><p class="a b c z" id="p3">three</p><p class="a" id="i">four</p><span class="b c" data-x="1" id="s1">five</span></div>'
);
$col = static fn(string $id): string => strtolower((string) findBox($sel->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === $id)?->style->get('color'));
check('tag rule + :first-child (specificity beats tag)', $col('p1') === '#777777', $col('p1'));
check('class rule vs p.a (higher specificity wins)', $col('p2') === '#333333', $col('p2'));
check('multiple classes + !important order untouched', $col('s1') === '#555555', $col('s1'));
check('id rule beats everything else', $col('i') === '#444444', $col('i'));
$p3 = findBox($sel->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === 'p3');
check(':not(.z) correctly excludes', $p3 !== null && !str_contains(strtolower($p3->style->get('font-weight')), 'bold') && $p3->style->get('font-weight') !== '700');
$p2 = findBox($sel->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === 'p2');
check('child combinator rule applies via bucketed candidates', $p2 !== null && $p2->style->get('background-color') === '#aaaaaa');

// ---------------------------------------------------------------------------
echo "\n5. Pagination and page culling\n";
// ---------------------------------------------------------------------------
$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. ';
$longHtml = '<style>td{border:1px solid #888;padding:3pt}h2{margin:8pt 0}</style>';
for ($i = 0; $i < 12; $i++) {
    $longHtml .= "<h2>Section {$i}</h2><p>" . str_repeat($lorem, 12) . '</p>';
    if ($i % 3 === 1) {
        $longHtml .= '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody>';
        for ($r = 0; $r < 40; $r++) {
            $longHtml .= "<tr><td>row {$r}</td><td>cell {$i}</td></tr>";
        }
        $longHtml .= '</tbody></table>';
    }
    if ($i % 4 === 2) {
        $longHtml .= '<div style="page-break-before:always">forced ' . $i . '</div>';
    }
}
$long = renderDoc($longHtml);
$pageH = 841.89 - 72.0;
$bad = 0;
$total = 0;
$stack = [$long->getLayoutTree()];
while ($stack) {
    $b = array_pop($stack);
    foreach ($b->lines as $line) {
        $total++;
        $page = (int) floor(($line->y - 36.0 + 0.01) / $pageH);
        if ($line->y + $line->height > 36.0 + ($page + 1) * $pageH + 0.6) {
            $bad++;
        }
    }
    foreach ($b->children as $c) {
        $stack[] = $c;
    }
}
check("no text line straddles a page break ({$total} lines, " . $long->getPageCount() . ' pages)', $total > 100 && $bad === 0, "{$bad} straddling lines");

$spans = pdfSpans((new IvoPdf(['compress' => true]))->loadHtml(
    '<style>.rot{transform:rotate(10deg);margin:30px 0}.sh{box-shadow:2px 2px 6px #888}.abs{position:absolute;left:300px;top:20px}</style>'
    . str_repeat('<p>' . str_repeat($lorem, 10) . '</p>', 14)
    . '<div class="rot">ROTATED-MARK</div><div class="sh">SHADOW-MARK</div><div style="position:relative"><div class="abs" style="width:120px;height:20px"><span>ABS-MARK</span></div></div>'
    . str_repeat('<p>' . $lorem . '</p>', 3)
)->output());
if ($spans === null) {
    echo "  (python/PyMuPDF unavailable: culling text check skipped)\n";
} else {
    $found = static function (string $needle) use ($spans): int {
        foreach ($spans as $i => $t) {
            if (str_contains($t, $needle)) {
                return $i;
            }
        }
        return -1;
    };
    check('transformed / shadowed / absolute content on later pages is still painted', $found('ROTATED-MARK') > 0 && $found('SHADOW-MARK') > 0 && $found('ABS-MARK') > 0, json_encode([$found('ROTATED-MARK'), $found('SHADOW-MARK'), $found('ABS-MARK')]));
}

// ---------------------------------------------------------------------------
echo "\n6. Performance smoke test\n";
// ---------------------------------------------------------------------------
$perf = '';
for ($i = 0; $i < 48; $i++) {
    $perf .= "<h2>Section {$i}</h2>";
    for ($k = 0; $k < 3; $k++) {
        $perf .= '<p>' . str_repeat($lorem, 3) . '</p>';
    }
}
$t0 = microtime(true);
gc_collect_cycles();
$memBase = memory_get_usage();
if (function_exists("memory_reset_peak_usage")) { memory_reset_peak_usage(); } // PHP 8.2+
$perfPdf = renderDoc($perf, ['compress' => true]);
$elapsed = microtime(true) - $t0;
$pages = $perfPdf->getPageCount();
check("{$pages}-page text document renders in under 15 s (" . round($elapsed, 2) . ' s)', $pages >= 20 && $elapsed < 15.0);
$memDelta = memory_get_peak_usage() - $memBase;
check('peak memory of the 20-page render stays under 128 MB (' . round($memDelta / 1048576) . ' MB)', $memDelta < 128 * 1048576);

$t0 = microtime(true);
$rows = '';
for ($r = 0; $r < 1500; $r++) {
    $rows .= "<tr><td>{$r}</td><td>Item {$r}</td><td>" . ($r % 9) . '</td></tr>';
}
$tablePdf = renderDoc('<table><thead><tr><th>#</th><th>Name</th><th>Qty</th></tr></thead><tbody>' . $rows . '</tbody></table>', ['compress' => true]);
$te = microtime(true) - $t0;
check('1 500-row table paginates in under 15 s (' . round($te, 2) . ' s, ' . $tablePdf->getPageCount() . ' pages)', $te < 15.0 && $tablePdf->getPageCount() > 10);

$t0 = microtime(true);
$sheet = '';
for ($i = 0; $i < 150; $i++) {
    $sheet .= ".c{$i}{color:#" . sprintf('%06x', $i * 977) . "} div.x{$i} > p.y{$i}{margin:0}\n";
}
$cssDoc = '<style>' . $sheet . '</style>';
for ($i = 0; $i < 600; $i++) {
    $cssDoc .= "<div class='x" . ($i % 150) . " c" . ($i % 150) . "'><p class='y" . ($i % 150) . "'>Para {$i}</p></div>";
}
$cssPdf = renderDoc($cssDoc);
check('300 rules x 1200 elements cascade in under 10 s (' . round(microtime(true) - $t0, 2) . ' s)', microtime(true) - $t0 < 10.0);

// ---------------------------------------------------------------------------
echo "\n7. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
