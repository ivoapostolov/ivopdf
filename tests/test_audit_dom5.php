<?php

declare(strict_types=1);

/**
 * Regression tests for audit 5 (DOM / CSS):
 *  - blockification (CSS Display 3 section 2.7): a floated / absolutely positioned element and a flex / grid item gets a
 *    block-level display; floated <span>, <b>, <a>, display:inline[-block] floats no longer lose their content
 *  - list-style-type: armenian, georgian, hebrew, cjk-ideographic, cjk-decimal, upper-greek
 *  - <details> without `open`
 *  - <footnote> (IvoPdf extension) behaves like `float: footnote`
 *
 * Run: php tests/test_audit_dom5.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Text\CounterStyles;

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
    $pdf = new IvoPdf(array_merge(['compress' => false, 'defaultFont' => 'DejaVu Sans', 'margins' => ['top' => 0, 'left' => 0, 'right' => 0, 'bottom' => 0]], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<list<array{0: float, 1: float, 2: float, 3: float, 4: string}>>|null words per page (x0, y0, x1, y1, text) via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dom5-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([[list(w[:5]) for w in p.get_text('words')] for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dom5-words.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** @return array{0: float, 1: float, 2: float, 3: float, 4: string}|null */
function word(?array $pages, string $text, int $page = 0): ?array
{
    foreach ($pages[$page] ?? [] as $w) {
        if ($w[4] === $text) {
            return $w;
        }
    }
    return null;
}

function allText(?array $pages): string
{
    $out = [];
    foreach ($pages ?? [] as $p) {
        foreach ($p as $w) {
            $out[] = $w[4];
        }
    }
    return implode(' ', $out);
}

function findByTag(LayoutBox $box, string $tag): ?LayoutBox
{
    if ($box->tagName === $tag) {
        return $box;
    }
    foreach ($box->children as $c) {
        $f = findByTag($c, $tag);
        if ($f !== null) {
            return $f;
        }
    }
    return null;
}

$pyOk = pdfWords(render('<p>x</p>')) !== null;
if (!$pyOk) {
    echo "(PyMuPDF not available: text position checks are skipped)\n";
}

// ---------------------------------------------------------------------------
echo "1. blockification: floated inline elements keep their content\n";
// ---------------------------------------------------------------------------
foreach (['span', 'b', 'em', 'a', 'label', 'i', 'strong', 'small', 'code'] as $tag) {
    $pdf = render("<p>Left text <{$tag} style=\"float:right\">FLOATED</{$tag}> after</p>");
    $w = $pyOk ? word(pdfWords($pdf), 'FLOATED') : null;
    if (!$pyOk) {
        skip("float:right <{$tag}> drawn");
        continue;
    }
    check("float:right <{$tag}> is drawn at the right edge", $w !== null && $w[0] > 400.0, $w ? sprintf('x=%.1f', $w[0]) : 'missing');
}
if ($pyOk) {
    $words = pdfWords(render('<h3>Date <span style="float:right">12.05.2026</span></h3>'));
    $date = word($words, '12.05.2026');
    $label = word($words, 'Date');
    check('heading: the date is drawn on the right, on the same line as the label', $date !== null && $label !== null && $date[0] > 400.0 && abs($date[1] - $label[1]) < 2.0,
        $date ? sprintf('date y=%.1f label y=%s', $date[1], $label[1] ?? 'none') : 'missing');

    $words = pdfWords(render('<div>L <a href="http://example.com/x" style="display:inline-block;float:right">Click</a></div>'));
    check('float with display:inline-block is drawn', word($words, 'Click') !== null && word($words, 'Click')[0] > 400.0);
    $words = pdfWords(render('<div>L <span style="display:inline;float:left">LEFT</span> rest of text</div>'));
    $l = word($words, 'LEFT');
    $r = word($words, 'rest');
    check('float:left with display:inline is drawn and text flows beside it', $l !== null && $r !== null && $r[0] > $l[2] - 0.5 && abs($r[1] - $l[1]) < 3.0,
        $l && $r ? sprintf('LEFT x1=%.1f rest x0=%.1f', $l[2], $r[0]) : 'missing');
    $words = pdfWords(render('<table style="width:100%"><tr><td>Name <b style="float:right">99.00</b></td></tr></table>'));
    check('table cell: floated <b> is drawn at the right of the cell', word($words, '99.00') !== null && word($words, '99.00')[0] > 400.0);
    $words = pdfWords(render('<table><tr><td>Name <span style="float:right">99.00</span></td><td>x</td></tr></table>'));
    $n = word($words, 'Name');
    $p99 = word($words, '99.00');
    check('auto-width cell: the float counts in the shrink-to-fit width (same line)', $n !== null && $p99 !== null && abs($n[1] - $p99[1]) < 3.0,
        $n && $p99 ? sprintf('y %.1f vs %.1f', $n[1], $p99[1]) : 'missing');
    $words = pdfWords(render('<ul><li>Item <span style="float:right">5</span></li></ul>'));
    check('list item: floated span is drawn right', word($words, '5') !== null && word($words, '5')[0] > 400.0);
    $words = pdfWords(render('<h3><b>Date <span style="float:right">12.05</span></b></h3>'));
    check('float inside an inline element floats to the block container', word($words, '12.05') !== null && word($words, '12.05')[0] > 400.0);
    $words = pdfWords(render('<div>Name <span style="float:right">R1</span><span style="float:right">R2</span> x</div>'));
    check('two right floats on one line sit side by side', word($words, 'R1') !== null && word($words, 'R2') !== null && word($words, 'R2')[0] < word($words, 'R1')[0]);
    $words = pdfWords(render('<div>L <span style="position:absolute;top:50px;left:100px">ABS</span> R</div>'));
    check('position:absolute <span> is drawn', word($words, 'ABS') !== null && word($words, 'ABS')[0] > 70.0);
    $words = pdfWords(render('<div style="display:flex"><span>A <b style="float:right">F</b></span><span>B</span></div>'));
    // the float ends the shrink-to-fit item exactly, so PyMuPDF may join "F" and the next item's "B" into one word
    $all = $words === null ? '' : implode(' ', array_map(static fn(array $w): string => (string) $w[4], array_merge(...$words)));
    check('flex item with a float: all text present', $words === null || (str_contains($all, 'A') && str_contains($all, 'F') && str_contains($all, 'B')), $all);
    $words = pdfWords(render('<style>p::after{content:"PS";float:right}</style><p>hello</p>'));
    check('floated ::after pseudo-element is drawn right', word($words, 'PS') !== null && word($words, 'PS')[0] > 400.0);
    $words = pdfWords(render('<p>' . str_repeat('word ', 12) . '<span style="float:right">FLT</span> tail</p>', ['pageSize' => 'A6']));
    check('long text before a float: nothing lost', word($words, 'FLT') !== null && word($words, 'tail') !== null);
}
$pdf = render('<div>L <a href="http://example.com/float" style="float:right">Go</a></div>');
check('a floated <a href> keeps its link annotation', str_contains($pdf->output(), 'http://example.com/float'));
$tree = render('<div><span id="s" style="float:right">X</span><i style="display:inline-flex">y</i><span style="display:inline-flex;position:absolute">z</span></div>')->getLayoutTree();
$span = findByTag($tree, 'span');
check('computed display of a floated span is block', $span !== null && $span->getDisplay() === 'block', $span?->getDisplay() ?? 'missing');
$tree = render('<span style="float:left;display:inline-flex">a</span><span style="float:left;display:inline-table"><span style="display:table-cell">b</span></span>')->getLayoutTree();
$displays = [];
foreach ($tree->children as $c) {
    if (!$c->isTextNode()) {
        $displays[] = $c->getDisplay();
    }
}
check('inline-flex -> flex, inline-table -> table', $displays === ['flex', 'table'], implode(',', $displays));
$tree = render('<ul><li style="float:left">item</li></ul>')->getLayoutTree();
$li = findByTag($tree, 'li');
check('list-item stays list-item when floated', $li !== null && $li->getDisplay() === 'list-item', $li?->getDisplay() ?? 'missing');

// ---------------------------------------------------------------------------
echo "\n2. list-style-type: armenian / georgian / hebrew / cjk / greek\n";
// ---------------------------------------------------------------------------
check('armenian 1, 12, 2024', CounterStyles::marker(1, 'armenian') === "\u{0531}." && CounterStyles::body(12, 'armenian') === "\u{053A}\u{0532}" && CounterStyles::body(2024, 'armenian') === 'ՍԻԴ');
check('armenian 9999 and out of range falls back to decimal', CounterStyles::body(9999, 'armenian') === 'ՔՋՂԹ' && CounterStyles::body(10000, 'armenian') === '10000' && CounterStyles::body(0, 'armenian') === '0');
check('lower-armenian is lowercase', CounterStyles::body(14, 'lower-armenian') === 'ժդ');
check('georgian 15, 2024, range ends at 19999', CounterStyles::body(15, 'georgian') === 'იე' && CounterStyles::body(2024, 'georgian') === 'ცკდ' && CounterStyles::body(19999, 'georgian') === 'ჵჰშჟთ' && CounterStyles::body(20000, 'georgian') === '20000');
check('hebrew 15 and 16 avoid the divine name', CounterStyles::body(15, 'hebrew') === 'טו' && CounterStyles::body(16, 'hebrew') === 'טז' && CounterStyles::body(19, 'hebrew') === 'יט');
check('hebrew 1000 uses geresh, 500 = tav + qof', CounterStyles::body(1000, 'hebrew') === "א\u{05F3}" && CounterStyles::body(500, 'hebrew') === 'תק' && CounterStyles::body(11000, 'hebrew') === '11000');
check('cjk-ideographic numerals', CounterStyles::body(10, 'cjk-ideographic') === '十' && CounterStyles::body(101, 'cjk-ideographic') === '一百零一'
    && CounterStyles::body(1010, 'cjk-ideographic') === '一千零一十' && CounterStyles::body(0, 'cjk-ideographic') === '零' && CounterStyles::body(10000, 'cjk-ideographic') === '10000');
check('cjk styles end with the ideographic comma', CounterStyles::marker(3, 'cjk-ideographic') === "三\u{3001}" && CounterStyles::marker(2024, 'cjk-decimal') === "二〇二四\u{3001}");
check('upper-greek is alphabetic', CounterStyles::body(1, 'upper-greek') === 'Α' && CounterStyles::body(24, 'upper-greek') === 'Ω' && CounterStyles::body(25, 'upper-greek') === 'ΑΑ');
check('huge and negative values stay bounded', CounterStyles::body(PHP_INT_MAX, 'hebrew') === '2147483647' && CounterStyles::body(-5, 'georgian') === '-5' && strlen(CounterStyles::body(PHP_INT_MIN, 'cjk-decimal')) < 30);
$pdf = render('<ol style="list-style-type:armenian"><li>a</li><li>b</li></ol><ol style="list-style:georgian" start="3"><li>c</li></ol><ol style="list-style-type:upper-greek"><li>g</li></ol><ol style="list-style-type:hebrew" start="15"><li>h</li></ol>');
$markers = [];
foreach ($pdf->getLayoutTree()->children as $ol) {
    foreach ($ol->children as $li) {
        if ($li->listMarker !== null) {
            $markers[] = $li->listMarker;
        }
    }
}
check('<ol> markers per list-style-type', $markers === ["\u{0531}.", "\u{0532}.", 'გ.', 'Α.', 'טו.'], implode(' ', $markers));
$pdf = render('<ol style="list-style-type:cjk-ideographic"><li>a</li></ol><ol style="list-style-type:cjk-decimal"><li>b</li></ol><ul><li style="list-style-type:disclosure-open">c</li></ul>');
$markers = [];
foreach ($pdf->getLayoutTree()->children as $ol) {
    foreach ($ol->children as $li) {
        $markers[] = (string) $li->listMarker;
    }
}
check('cjk markers are set (not a bullet)', $markers[0] === "一\u{3001}" && $markers[1] === "一\u{3001}" && $markers[2] === "\u{25BE}", implode(' ', $markers));
if ($pyOk) {
    $words = pdfWords(render('<ol style="list-style-type:armenian"><li>a</li></ol><ol style="list-style-type:hebrew" start="15"><li>d</li></ol>'));
    check('armenian and hebrew markers appear in the PDF text', str_contains(allText($words), 'Ա.') && str_contains(allText($words), 'ט'), allText($words));
}
$pdf = render('<div style="counter-reset:n 12"><p style="counter-increment:n">t</p></div><style>p::before{content:counter(n, armenian)}</style>');
check('counter(n, armenian) in generated content', $pyOk ? str_contains(allText(pdfWords($pdf)), 'ԺԳ') : true, $pyOk ? allText(pdfWords($pdf)) : '');

// ---------------------------------------------------------------------------
echo "\n3. <details> without open\n";
// ---------------------------------------------------------------------------
if ($pyOk) {
    $t = allText(pdfWords(render('<details><summary>Sum</summary><p>HIDDEN para</p></details><p>after</p>')));
    check('closed details: summary shown, content hidden', str_contains($t, 'Sum') && !str_contains($t, 'HIDDEN') && str_contains($t, 'after'), $t);
    $t = allText(pdfWords(render('<details><p>HIDDEN</p>loose text</details><p>after</p>')));
    check('closed details without summary: default "Details" label', str_contains($t, 'Details') && !str_contains($t, 'HIDDEN') && !str_contains($t, 'loose'), $t);
    $t = allText(pdfWords(render('<details open><summary>Sum</summary><p>SHOWN</p></details>')));
    check('open details shows everything', str_contains($t, 'Sum') && str_contains($t, 'SHOWN'), $t);
    $t = allText(pdfWords(render('<details><p>H1</p><summary>Late</summary><summary>Second</summary><p>H2</p></details>')));
    check('the first summary is the one shown, wherever it is', str_contains($t, 'Late') && !str_contains($t, 'Second') && !str_contains($t, 'H1') && !str_contains($t, 'H2'), $t);
}
$tree = render('<details><summary>S</summary>HID</details>')->getLayoutTree();
$det = findByTag($tree, 'details');
check('closed details has exactly one child box', $det !== null && count($det->children) === 1, $det ? (string) count($det->children) : 'missing');

// ---------------------------------------------------------------------------
echo "\n4. <footnote> (IvoPdf extension) = float: footnote\n";
// ---------------------------------------------------------------------------
if ($pyOk) {
    $words = pdfWords(render('<p>Body one<footnote>NOTE-A</footnote> two<footnote>NOTE-B</footnote> end.</p>' . str_repeat('<p>filler</p>', 3)));
    $a = word($words, 'NOTE-A');
    $b = word($words, 'NOTE-B');
    $body = word($words, 'two2') ?? word($words, 'two');
    check('<footnote> notes are drawn at the bottom of the page, not in the paragraph', $a !== null && $b !== null && $a[1] > 700.0 && $b[1] > $a[1], $a ? sprintf('A y=%.1f', $a[1]) : 'missing');
    check('<footnote> leaves numbered calls in the text', $body !== null && str_contains(allText($words), 'one1') && str_contains(allText($words), 'two2'), allText($words));
    $same = allText(pdfWords(render('<style>.fn{float:footnote}</style><p>Body one<span class="fn">NOTE-A</span> two<span class="fn">NOTE-B</span> end.</p>' . str_repeat('<p>filler</p>', 3))));
    check('<footnote> renders like the CSS float: footnote form', $same === allText($words), $same);
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
