<?php

declare(strict_types=1);

/**
 * Regression tests for the findings of audit 6:
 *  1. long unbreakable words / `pre` lines are broken at the edge of the page (Options::$pageOverflowWrap) instead of
 *     running off it, with one warning per document; tables fit their columns to the page
 *  2. `<table border="1">` (inset / outset cell borders) writes a compact content stream
 *  3. `rowspan="0"` spans the rest of the row group
 *  4. a `maxMemory` that cannot be used always reaches getWarnings() of the render
 *  5. `<textcircle>` draws its text or says why it cannot
 *  6. Composer package: export-ignore rules, no stray files, no duplicated or unused fonts
 *
 * Run: php tests/test_audit6.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\TextRun;

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

/** Text runs of the layout tree (inline boxes excluded). @return list<TextRun> */
function textRuns(LayoutBox $box, array &$out = []): array
{
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox === null && $run->text !== '') {
                $out[] = $run;
            }
        }
    }
    foreach ($box->children as $child) {
        textRuns($child, $out);
    }
    return $out;
}

/** All drawn text without white space (layout tree). */
function allText(IvoPdf $pdf): string
{
    $s = '';
    foreach (textRuns($pdf->getLayoutTree()) as $r) {
        $s .= $r->text;
    }
    return (string) preg_replace('/\s+/u', '', $s);
}

/** Largest distance (pt) by which a glyph run passes the right edge of the A4 content area (36 pt margins); <= 0: none. */
function pastRight(IvoPdf $pdf): float
{
    $max = -INF;
    foreach (textRuns($pdf->getLayoutTree()) as $r) {
        if (trim($r->text) !== '') {
            $max = max($max, $r->x + $r->width - (595.28 - 36.0));
        }
    }
    return $max;
}

/** Largest distance (pt) by which a glyph run starts before the left edge of the content area; <= 0: none. */
function pastLeft(IvoPdf $pdf): float
{
    $max = -INF;
    foreach (textRuns($pdf->getLayoutTree()) as $r) {
        if (trim($r->text) !== '') {
            $max = max($max, 36.0 - $r->x);
        }
    }
    return $max;
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
function findByTag(LayoutBox $box, string $tag, array &$out = []): array
{
    if ($box->tagName === $tag) {
        $out[] = $box;
    }
    foreach ($box->children as $c) {
        findByTag($c, $tag, $out);
    }
    return $out;
}

function warningsWith(IvoPdf $pdf, string $needle): array
{
    return array_values(array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, $needle)));
}

/** Text of the PDF per page via PyMuPDF (null when Python / PyMuPDF is not available). @return list<string>|null */
function pdfText(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-audit6-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([p.get_text() for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-audit6-text.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

// ---------------------------------------------------------------------------
echo "\n--- 1. Long words are broken at the page edge (pageOverflowWrap) ---\n";
$word = str_repeat('a', 300);
$p = render("<p>{$word}</p>");
check('a 300-character word keeps all 300 characters', allText($p) === $word, (string) strlen(allText($p)));
check('... and no glyph passes the right edge of the page', pastRight($p) <= 0.5, sprintf('%.1f pt', pastRight($p)));
check('... it is broken over several lines', count(findByTag($p->getLayoutTree(), 'p')[0]->lines ?? []) >= 4);
check('... with one warning that counts the broken word', count(warningsWith($p, 'pageOverflowWrap')) === 1 && str_contains($p->getWarnings()[0] ?? '', '1 word'), implode(' | ', $p->getWarnings()));

$off = render("<p>{$word}</p>", ['pageOverflowWrap' => false]);
check('pageOverflowWrap=false: the browser behaviour (one line past the page)', count(findByTag($off->getLayoutTree(), 'p')[0]->lines ?? []) === 1 && pastRight($off) > 100.0);
check('... and a warning says text is cut off', count(warningsWith($off, 'not visible')) === 1, implode(' | ', $off->getWarnings()));
check('the option is on by default', (new \IvoPdf\Options())->pageOverflowWrap === true);

$box = render('<div id="b" style="width:100pt">' . str_repeat('W', 30) . '</div>');
$b = findById($box->getLayoutTree(), 'b');
check('a word wider than its box but not than the page still overflows the box on one line (as in browsers)', $b !== null && count($b->lines) === 1 && $b->lines[0]->width > 100.0 && $box->getWarnings() === []);

$long = render('<p>' . str_repeat('x', 10000) . '</p>');
check('a 10 000-character word: every character is drawn, over several pages', strlen(allText($long)) === 10000 && $long->getPageCount() >= 2 && pastRight($long) <= 0.5, strlen(allText($long)) . ' chars, ' . $long->getPageCount() . ' pages');

$b64 = base64_encode(str_repeat("\x00\x10\x83\xFF\x7F", 1200));
$pre = render('<pre>' . $b64 . '</pre>');
check('<pre> with a 8 000-character base64 line: all of it is drawn inside the page', strlen(allText($pre)) === strlen($b64) && pastRight($pre) <= 0.5, strlen(allText($pre)) . ' of ' . strlen($b64));
$preText = pdfText($pre);
if ($preText === null) {
    skip('PDF text check (Python + PyMuPDF not available)');
} else {
    check('... and the PDF text holds the whole line', strlen((string) preg_replace('/\s+/', '', implode('', $preText))) === strlen($b64));
}
$preWrap = render('<pre style="white-space:pre-wrap">' . $word . '</pre>');
check('white-space: pre-wrap line: broken at the page edge too', allText($preWrap) === $word && pastRight($preWrap) <= 0.5);
$nowrap = render('<p>start <span style="white-space:nowrap">' . str_repeat('word ', 60) . '</span> end</p>');
check('a white-space: nowrap line longer than the page is broken at the page edge', pastRight($nowrap) <= 0.5 && substr_count(allText($nowrap), 'word') === 60 && count(warningsWith($nowrap, '1 word')) === 1, implode(' | ', $nowrap->getWarnings()));
$lines = render('<pre>' . str_repeat('line ' . str_repeat('x', 150) . " end\n", 5) . '</pre>');
check('five long <pre> lines are counted as five', count(warningsWith($lines, '5 word')) === 1, implode(' | ', $lines->getWarnings()));
$two = render("<p>{$word}</p><p>" . str_repeat('b', 300) . '</p>');
check('one warning per document for all broken words', count(warningsWith($two, 'pageOverflowWrap')) === 1 && count(warningsWith($two, '2 word')) === 1, implode(' | ', $two->getWarnings()));

$anywhere = render('<div id="aw" style="width:100pt;overflow-wrap:anywhere">' . $word . '</div>');
$aw = findById($anywhere->getLayoutTree(), 'aw');
$awOk = $aw !== null && count($aw->lines) > 5;
foreach ($aw !== null ? $aw->lines : [] as $line) {
    $awOk = $awOk && $line->width <= 100.5;
}
check('overflow-wrap: anywhere still breaks at the box edge (no page-edge warning)', $awOk && $anywhere->getWarnings() === []);

$rtl = render('<p dir="rtl">' . str_repeat('א', 300) . '</p>');
check('right-to-left: a long word is broken at the left page edge, nothing is lost', mb_strlen(allText($rtl)) === 300 && pastLeft($rtl) <= 0.5 && pastRight($rtl) <= 0.5, sprintf('%d chars, left %.1f', mb_strlen(allText($rtl)), pastLeft($rtl)));
$rtlOver = render('<div id="rd" dir="rtl" style="width:100pt;margin-left:300pt">' . str_repeat('ש', 25) . '</div>', ['pageOverflowWrap' => false]);
$rtlRuns = textRuns($rtlOver->getLayoutTree());
$rd = findById($rtlOver->getLayoutTree(), 'rd');
check('right-to-left: a word wider than its box overflows to the left (start-aligned), as in browsers', $rd !== null && count($rtlRuns) === 1 && abs($rtlRuns[0]->x + $rtlRuns[0]->width - ($rd->x + $rd->width)) < 0.5 && $rtlRuns[0]->x < $rd->x - 50.0, $rd ? sprintf('run %.1f..%.1f box %.1f..%.1f', $rtlRuns[0]->x, $rtlRuns[0]->x + $rtlRuns[0]->width, $rd->x, $rd->x + $rd->width) : 'missing');

$combining = render('<p>' . str_repeat("e\u{0301}", 400) . '</p>');
$splitMark = false;
foreach (textRuns($combining->getLayoutTree()) as $r) {
    $splitMark = $splitMark || str_starts_with($r->text, "\u{0301}");
}
check('breaking is grapheme-safe: an accent never starts a line', !$splitMark && mb_strlen(allText($combining)) === 800 && pastRight($combining) <= 0.5);
$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
$emoji = render('<p>' . str_repeat('x', 60) . str_repeat($family, 80) . '</p>');
check('emoji ZWJ sequences: every character drawn, nothing past the page', mb_strlen(allText($emoji)) === 60 + 80 * 5 && pastRight($emoji) <= 0.5);
/** Lines of the <p> elements, as text. @return list<string> */
$paraLines = static function (IvoPdf $pdf): array {
    $out = [];
    foreach (findByTag($pdf->getLayoutTree(), 'p') as $para) {
        foreach ($para->lines as $line) {
            $out[] = implode('', array_map(static fn(TextRun $r): string => $r->text, $line->runs));
        }
    }
    return $out;
};
$clusterSafe = static function (array $lines): bool {
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        $tail = mb_substr($lines[$i - 1], -3);
        $head = mb_substr($lines[$i], 0, 3);
        if (preg_match_all('/\X/u', $tail . $head) !== preg_match_all('/\X/u', $tail) + preg_match_all('/\X/u', $head)) {
            return false;
        }
    }
    return true;
};
// The vowel signs are runs of their own (another colour): the line breaks between runs, never inside a character
$styled = render('<p>' . str_repeat("\u{0915}<span style=\"color:#c00\">\u{093E}</span>", 300) . '</p>');
$styledLines = $paraLines($styled);
check('... and between runs: a letter keeps a vowel sign that is a run of its own', count($styledLines) > 1 && $clusterSafe($styledLines) && mb_strlen(allText($styled)) === 600 && pastRight($styled) <= 0.5, count($styledLines) . ' lines');

echo "\n--- 1b. Boxes laid out elsewhere first, tables, clipping ---\n";
foreach ([
    'inline-block' => "x <span style=\"display:inline-block\">{$word}</span>",
    'inline-block after text' => 'text text <span style="display:inline-block;margin-left:200pt">' . $word . '</span>',
    'flex item' => "<div style=\"display:flex\"><div>abc</div><div>{$word}</div></div>",
    'grid item' => "<div style=\"display:grid;grid-template-columns:100pt 100pt\"><div>abc</div><div>{$word}</div></div>",
    'absolutely positioned (left)' => "<div style=\"position:absolute;left:300pt;top:100pt\">{$word}</div>",
    'absolutely positioned (right)' => "<div style=\"position:absolute;right:20pt;top:100pt;width:100pt\">{$word}</div>",
    'float: right' => "<div style=\"float:right;width:100pt\">{$word}</div><p>text</p>",
    'multi-column' => "<div style=\"column-count:2\"><p>{$word}</p><p>" . str_repeat('word ', 300) . "</p><p>{$word}</p></div>",
    'list item' => "<ul><li>{$word}</li></ul>",
    'centred text' => "<p style=\"text-align:center\">{$word}</p>",
] as $label => $html) {
    $r = render($html);
    check("{$label}: no text past the page edge, nothing lost", pastRight($r) <= 0.5 && substr_count(allText($r), 'a') >= 300 && warningsWith($r, 'not visible') === [], sprintf('%.1f pt past, %d a, %s', pastRight($r), substr_count(allText($r), 'a'), implode(' | ', $r->getWarnings())));
}

$cell = render('<table id="t" border="1"><tr><td>short</td><td>' . $word . '</td></tr></table>');
$t = findById($cell->getLayoutTree(), 't');
check('table: a column widens to its longest word only up to the page edge', $t !== null && $t->x + $t->width <= 595.28 - 36.0 + 0.5 && pastRight($cell) <= 0.5 && allText($cell) === 'short' . $word, $t ? sprintf('table %.1f..%.1f', $t->x, $t->x + $t->width) : 'missing');
$tds = findByTag($cell->getLayoutTree(), 'td');
$textInCell = true;
foreach (textRuns($tds[1] ?? $cell->getLayoutTree()) as $r) {
    $textInCell = $textInCell && $r->x + $r->width <= $tds[1]->x + $tds[1]->width - $tds[1]->paddingRight - $tds[1]->borderRight + 0.5;
}
check('... and the narrowed column breaks the word at its own edge (inside its border)', $textInCell);
$twoCells = render('<table id="t2" border="1"><tr><td>' . $word . '</td><td>' . str_repeat('b', 300) . '</td></tr></table>');
check('table: two such columns share the page, nothing lost', pastRight($twoCells) <= 0.5 && substr_count(allText($twoCells), 'b') === 300 && substr_count(allText($twoCells), 'a') === 300 && warningsWith($twoCells, 'not visible') === []);
$fits = render('<table id="t3" border="1"><tr><td>' . str_repeat('W', 30) . '</td><td>x</td></tr></table>');
$t3 = findById($fits->getLayoutTree(), 't3');
$t3cells = findByTag($fits->getLayoutTree(), 'td');
check('table: a column still widens to a word that fits the page (no break)', $t3 !== null && count($t3cells[0]->lines) === 1 && $t3cells[0]->width > 300.0 && $fits->getWarnings() === []);
$inCell = render('<table border="1"><tr><td>x</td><td><div style="display:inline-block">' . $word . '</div></td></tr></table>');
check('table: an inline-block inside a cell is kept on the page too', pastRight($inCell) <= 0.5 && substr_count(allText($inCell), 'a') === 300);
$wideTbl = render('<table border="1" style="table-layout:fixed;width:300pt"><tr><td>' . $word . '</td><td>b</td></tr></table>');
check('table-layout: fixed keeps its columns; the word is broken at the page edge', pastRight($wideTbl) <= 0.5 && substr_count(allText($wideTbl), 'a') === 300);

$clip = render('<div id="c" style="width:100pt;overflow:hidden;white-space:nowrap">' . str_repeat('word ', 60) . '</div>');
$c = findById($clip->getLayoutTree(), 'c');
check('overflow: hidden is the author\'s clipping: the line is not broken and nothing is reported', $c !== null && count($c->lines) === 1 && $clip->getWarnings() === []);
$ellipsis = render('<div style="width:100pt;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $word . '</div>');
check('text-overflow: ellipsis still ends the line with an ellipsis', str_contains(allText($ellipsis), "\u{2026}") && $ellipsis->getWarnings() === []);
$rot = render('<div style="transform:rotate(90deg);width:100pt">' . $word . '</div>');
check('a transformed box is not broken at the (untransformed) page edge', count(warningsWith($rot, 'pageOverflowWrap')) === 0);

echo "\n--- 1c. Ordinary documents are unchanged ---\n";
$doc = '<h1>Title</h1><p>' . str_repeat('Some ordinary words in a paragraph. ', 40) . '</p>'
    . '<table border="1" style="width:100%"><tr><th>A</th><th>B</th></tr><tr><td>one two</td><td>three</td></tr></table>'
    . '<pre>code line\n  indented</pre><div style="display:flex"><div style="flex:1">left</div><div>right</div></div>'
    . '<p>A link to <a href="https://example.com/very/long/path/segment/that/wraps">https://example.com/very/long/path/segment/that/wraps</a></p>';
$on = render($doc);
$offDoc = render($doc, ['pageOverflowWrap' => false]);
$pos = static function (IvoPdf $pdf): string {
    $s = '';
    foreach (textRuns($pdf->getLayoutTree()) as $r) {
        $s .= sprintf('%s@%.2f,%.2f;', $r->text, $r->x, $r->y);
    }
    return $s;
};
check('a document without overlong words: same layout with and without the option, no warnings', $pos($on) === $pos($offDoc) && $on->getWarnings() === [] && $offDoc->getWarnings() === []);

// ---------------------------------------------------------------------------
echo "\n--- 2. <table border> writes a compact content stream ---\n";
$cells = '<table border="1">' . str_repeat('<tr><td>c</td><td>d</td></tr>', 500) . '</table>';
$compressed = (new IvoPdf())->loadHtml($cells)->output();
check('1 000 cells with border="1": under 40 KB (was 100 KB)', strlen($compressed) < 40 * 1024, round(strlen($compressed) / 1024) . ' KB');
$small = render('<table border="1"><tr><td>c</td><td>d</td></tr></table>');
$raw = $small->output();
$streams = [];
preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $streams);
$content = '';
foreach ($streams[1] as $st) {
    if (str_contains($st, ' Tj') || str_contains($st, ' re')) {
        $content .= $st;
    }
}
// 2 cells + the table: 12 sides, each its wedge clip and one ring fill, the colour set when it changes
check('every inset / outset side is still painted (12 sides: 12 clips, 12 fills)', substr_count($content, 'W n') === 12 && substr_count($content, "f*\n") === 12, substr_count($content, 'W n') . ' clips, ' . substr_count($content, "f*\n") . ' fills');
check('... with the dark and the light shade', str_contains($content, '0.172 0.172 0.172 rg') && str_contains($content, '0.502 0.502 0.502 rg'));
check('... in coordinates relative to each box (repeating cells compress)', substr_count($content, ' cm') >= 3);
$rounded = render('<div style="border:6px outset #4a8;border-radius:10px">rounded</div><div style="border:6px inset rgba(0,0,255,.5)">alpha</div>');
check('rounded or translucent inset / outset borders keep the general painting', $rounded->getPageCount() === 1 && str_contains($rounded->output(), ' c'));

// ---------------------------------------------------------------------------
echo "\n--- 3. rowspan=\"0\" ---\n";
$rs = render('<table border="1"><tr><td id="r0" rowspan="0">RS0</td><td>a</td></tr><tr><td>b</td></tr><tr><td id="last">c</td></tr></table>');
$r0 = findById($rs->getLayoutTree(), 'r0');
$last = findById($rs->getLayoutTree(), 'last');
check('rowspan="0" spans every remaining row of the row group', $r0 !== null && $last !== null && abs(($r0->y + $r0->height) - ($last->y + $last->height)) < 1.0 && $last->x > $r0->x + $r0->width - 1.0, $r0 && $last ? sprintf('r0 %.1f..%.1f last %.1f..%.1f', $r0->y, $r0->y + $r0->height, $last->y, $last->y + $last->height) : 'missing');
$rsHead = render('<table border="1"><thead><tr><td id="h0" rowspan="0">H</td><td>h1</td></tr><tr><td id="h2">h2</td></tr></thead><tbody><tr><td id="b1">b1</td><td>b2</td></tr></tbody></table>');
$h0 = findById($rsHead->getLayoutTree(), 'h0');
$h2 = findById($rsHead->getLayoutTree(), 'h2');
$b1 = findById($rsHead->getLayoutTree(), 'b1');
check('... but only to the end of its own row group (thead)', $h0 !== null && $h2 !== null && $b1 !== null && abs(($h0->y + $h0->height) - ($h2->y + $h2->height)) < 1.0 && abs($b1->x - $h0->x) < 1.0 && $b1->y >= $h0->y + $h0->height - 1.0);
$neg = render('<table border="1"><tr><td id="n" rowspan="-2">N</td><td>a</td></tr><tr><td id="n2">b</td><td>c</td></tr></table>');
$n = findById($neg->getLayoutTree(), 'n');
$n2 = findById($neg->getLayoutTree(), 'n2');
check('a negative rowspan is 1', $n !== null && $n2 !== null && abs($n2->x - $n->x) < 1.0);
$rsMetrics = render('<table id="tm" border="1"><tr><td rowspan="0">' . str_repeat('w ', 5) . '</td><td>a</td></tr><tr><td>b</td></tr></table>');
check('rowspan="0" in the width measuring: no extra column', count(findByTag($rsMetrics->getLayoutTree(), 'td')) === 3 && $rsMetrics->getWarnings() === []);

// ---------------------------------------------------------------------------
echo "\n--- 4. maxMemory that cannot be used is reported by the render ---\n";
$mm = render('<p>x</p>', ['maxMemory' => 64]);
check("maxMemory 64 (bytes): the render's getWarnings() says it was ignored", count(warningsWith($mm, 'maxMemory of 64 bytes ignored')) === 1, implode(' | ', $mm->getWarnings()));
$mm2 = new IvoPdf();
$mm2->getOptions()->maxMemory = 64;
$mm2->loadHtml('<p>x</p>')->render();
check('... also when set on getOptions() after construction', count(warningsWith($mm2, 'maxMemory')) === 1);
$mm2->output();
check('... and still after output() (a second rendering pass)', count(warningsWith($mm2, 'maxMemory')) === 1);
$mm3 = new IvoPdf();
$mm3->maxMemory = 64;
$mm3->loadHtml('<p>x</p>')->output();
check('... also as a property of the IvoPdf object ($pdf->maxMemory = 64)', count(warningsWith($mm3, 'maxMemory')) === 1 && $mm3->getOptions()->maxMemory === 64);
$mpdf = new \Mpdf\Mpdf(['maxMemory' => 64]);
$mpdf->WriteHTML('<p>x</p>');
$mpdf->Output('', 'S');
check('... and through the mPDF constructor', count(warningsWith($mpdf, 'maxMemory')) === 1);
foreach (['abc', '64 megs', -5] as $bad) {
    $mb = render('<p>x</p>', ['maxMemory' => $bad]);
    check('maxMemory ' . json_encode($bad) . ' is reported, not taken as "no limit"', count(warningsWith($mb, 'is not a memory size')) === 1, implode(' | ', $mb->getWarnings()));
}
foreach (['512M', '2G', 1073741824, 0, 'auto'] as $good) {
    $mg = render('<p>x</p>', ['maxMemory' => $good]);
    check('maxMemory ' . json_encode($good) . ' gives no warning', $mg->getWarnings() === []);
}
$opts = new \IvoPdf\Options(['maxMemory' => '64 megs']);
check('the ignored value falls back to the auto budget', $opts->getMaxMemoryBytes() === (new \IvoPdf\Options())->getMaxMemoryBytes());

// ---------------------------------------------------------------------------
echo "\n--- 5. <textcircle> ---\n";
$tcText = static fn(IvoPdf $p): string => allText($p);
$tc = render('<textcircle r="30mm" top-text="TOPTEXT" bottom-text="BOTTOM" />');
check('<textcircle top-text bottom-text> draws both texts', $tcText($tc) === 'TOPTEXTBOTTOM' && $tc->getWarnings() === [], $tcText($tc));
$tcAlias = render('<textcircle r="30mm" top="TOPTEXT" bottom="BOTTOMTEXT" />');
check('the audit probe (top= / bottom=) now draws its text', $tcText($tcAlias) === 'TOPTEXTBOTTOMTEXT', $tcText($tcAlias));
foreach ([
    'no text' => ['<textcircle r="30mm" />', 'no top-text or bottom-text'],
    'r="0"' => ['<textcircle r="0" top-text="X" />', 'radius'],
    'r="abc"' => ['<textcircle r="abc" top-text="X" />', 'radius'],
    'space-width="0"' => ['<textcircle r="30mm" top-text="X" space-width="0" />', 'space-width'],
] as $label => [$html, $needle]) {
    $w = render($html);
    check("<textcircle> with {$label}: nothing drawn, and a warning says why", allText($w) === '' && count(warningsWith($w, $needle)) === 1, implode(' | ', $w->getWarnings()));
}
$tcM = new \Mpdf\Mpdf();
$tcM->WriteHTML('<textcircle r="20mm" />');
$tcM->Output('', 'S');
check('... also through the mPDF API', count(warningsWith($tcM, 'textcircle')) === 1);

// ---------------------------------------------------------------------------
echo "\n--- 6. Composer package ---\n";
$root = dirname(__DIR__);
$attrs = (string) @file_get_contents($root . '/.gitattributes');
foreach (['/tests', '/examples/output', '/.github', '/skills', '/integrations', '/index.php', '/CLA.md', '/CONTRIBUTING.md'] as $path) {
    check("{$path} is export-ignore", preg_match('~^' . preg_quote($path, '~') . '\s+export-ignore\s*$~m', $attrs) === 1);
}
check('stray PDFs in the repository root are git-ignored', str_contains((string) @file_get_contents($root . '/.gitignore'), "\n/*.pdf\n"));
$fontDir = $root . '/resources/fonts';
$fonts = glob($fontDir . '/*.ttf') ?: [];
$hashes = array_map('md5_file', $fonts);
check('no bundled font file is a duplicate of another', count($fonts) > 0 && count(array_unique($hashes)) === count($fonts), count($fonts) . ' fonts');
$fm = new \IvoPdf\Font\FontManager($fontDir);
$registered = [];
foreach ((new ReflectionClass($fm))->getProperties() as $prop) {
    $val = $prop->getValue($fm);
    if (is_array($val)) {
        array_walk_recursive($val, static function ($v) use (&$registered): void {
            if (is_string($v) && str_ends_with(strtolower($v), '.ttf')) {
                $registered[strtolower(basename($v))] = true;
            }
        });
    }
}
$unused = array_filter($fonts, static fn(string $f): bool => !isset($registered[strtolower(basename($f))]));
check('every bundled font file is registered by the FontManager', $unused === [], implode(', ', array_map('basename', $unused)));

// ---------------------------------------------------------------------------
echo "\n";
echo "\n--- Running header / footer: long words are broken at the area edge too ---\n";
$hfWord = str_repeat('ABCDEFGHIJ', 30);
$hf = new IvoPdf(['defaultFont' => 'DejaVu Sans']);
$hf->setHeaderHtml('<div>X ' . $hfWord . '</div>');
$hf->setFooterHtml('<div>Y ' . $hfWord . '</div>');
$hfBytes = $hf->loadHtml('<p>body</p>')->output();
$hfFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-audit6-hf-' . getmypid() . '.pdf';
file_put_contents($hfFile, $hfBytes);
$hfText = @shell_exec('python -I -c ' . escapeshellarg('import pymupdf as fitz, sys; sys.stdout.write(fitz.open(sys.argv[1])[0].get_text())') . ' ' . escapeshellarg($hfFile) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
@unlink($hfFile);
if (!is_string($hfText) || $hfText === '') {
    echo "  [SKIP] PyMuPDF not available\n";
} else {
    $letters = preg_match_all('/[A-J]/', $hfText);
    check('a 300-character word in the header and in the footer is fully on the page', $letters === 600, "{$letters} of 600 letters");
}

check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
