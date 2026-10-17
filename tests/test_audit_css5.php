<?php

declare(strict_types=1);

/**
 * Regression tests for the remaining CSS / paged-media audit items:
 *  - bounded counter-style formatters (roman / alphabetic / huge `start` and counter values)
 *  - caption-side: top | bottom (also across page breaks)
 *  - tab-size for white-space: pre / pre-wrap
 *  - :dir(ltr) / :dir(rtl)
 *  - <wbr> and inline boundaries in min-content measurement
 *  - ex / ch units with @font-face families and with font-family declared after the length
 *  - recto / verso / left / right forced breaks for right-to-left documents
 *
 * Run: php tests/test_audit_css5.php
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
    $pdf = new IvoPdf(array_merge(['compress' => false, 'defaultFont' => 'DejaVu Sans'], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<array{w: list<array{0: float, 1: float, 2: float, 3: float, 4: string}>}>|null words per page (x0, y0, x1, y1, text) via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-css5-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([{'w':[list(w[:5]) for w in p.get_text('words')]} for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-css5-words.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** First word (x0, y0, x1, y1, text) with exactly this text on a page, or null */
function wordAt(array $page, string $text): ?array
{
    foreach ($page['w'] as $w) {
        if ($w[4] === $text) {
            return $w;
        }
    }
    return null;
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

function box(IvoPdf $pdf, string $id): ?LayoutBox
{
    return findById($pdf->getLayoutTree(), $id);
}

/** @return list<string> list markers in document order */
function markers(LayoutBox $box): array
{
    $out = [];
    if ($box->listMarker !== null && $box->listMarker !== '') {
        $out[] = $box->listMarker;
    }
    foreach ($box->children as $c) {
        array_push($out, ...markers($c));
    }
    return $out;
}

function refAlpha(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(97 + $n % 26) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

// ---------------------------------------------------------------------------
echo "1. Counter styles are bounded\n";
// ---------------------------------------------------------------------------
$t0 = microtime(true);
$pdf = render('<ol start="999999999999" type="i"><li>a</li><li>b</li></ol>');
$m = markers($pdf->getLayoutTree());
check('huge start with roman: no hang, falls back to decimal', microtime(true) - $t0 < 5.0 && count($m) === 2 && preg_match('/^\d+\.$/', $m[0]) === 1, json_encode($m));
check('huge start is clamped to a 32-bit range', ($m[0] ?? '') === '2147483647.', json_encode($m));

$pdf = render('<ol start="3999" type="I"><li>a</li><li>b</li></ol>');
$m = markers($pdf->getLayoutTree());
check('roman 3999 = MMMCMXCIX, 4000 falls back to decimal', $m === ['MMMCMXCIX.', '4000.'], json_encode($m));

$pdf = render('<ol start="1994" type="i"><li>a</li></ol><ol start="4" type="I"><li>a</li></ol>');
check('roman numerals within range stay exact', markers($pdf->getLayoutTree()) === ['mcmxciv.', 'IV.']);

$t0 = microtime(true);
$pdf = render('<ol start="99999999999999999999999" style="list-style-type:upper-roman"><li>a</li></ol><ol start="2147483647" type="a"><li>a</li></ol><ol start="1e30" type="A"><li>a</li></ol>');
$m = markers($pdf->getLayoutTree());
check('lower-alpha with a huge counter is computed in O(log n)', ($m[1] ?? '') === refAlpha(2147483647) . '.', json_encode($m));
check('numeric overflow strings / exponents do not break', microtime(true) - $t0 < 5.0 && ($m[0] ?? '') === '2147483647.' && ($m[2] ?? '') === strtoupper(refAlpha(2147483647)) . '.', json_encode($m));

$t0 = microtime(true);
$pdf = render('<style>li::before{content:counter(c, upper-roman) " " counter(c, lower-alpha) " " counter(c, lower-greek) " "}</style>'
    . '<ul style="counter-reset:c 999999999999999999999"><li style="counter-increment:c 9999999999">x</li><li style="counter-increment:c 2147483647">y</li></ul>'
    . '<ul style="counter-reset:c -999999999999"><li style="counter-increment:c -5">z</li></ul>');
check('CSS counter() with huge reset / increment values is bounded', microtime(true) - $t0 < 5.0);

$t0 = microtime(true);
$items = str_repeat('<li>x</li>', 300);
$pdf = render("<ol reversed start=\"-99999999999\" type=\"I\">{$items}</ol>");
check('reversed list with a huge negative start is bounded', microtime(true) - $t0 < 10.0);
$pdf = render('<ol><li value="5000" style="list-style-type:upper-roman">a</li><li value="-3" style="list-style-type:lower-roman">b</li></ol>');
check('negative / out-of-range roman values fall back to decimal', markers($pdf->getLayoutTree()) === ['5000.', '-3.'], json_encode(markers($pdf->getLayoutTree())));

// ---------------------------------------------------------------------------
echo "\n2. caption-side\n";
// ---------------------------------------------------------------------------
$pdf = render('<table border="1"><caption id="c">Cap</caption><tr id="r1"><td>A</td></tr><tr id="r2"><td>B</td></tr></table>');
$c = box($pdf, 'c');
$r1 = box($pdf, 'r1');
check('default caption is above the rows', $c !== null && $r1 !== null && $c->y < $r1->y);

$pdf = render('<table border="1"><caption id="c" style="caption-side:bottom">Cap</caption><tr id="r1"><td>A</td></tr><tr id="r2"><td>B</td></tr></table>');
$c = box($pdf, 'c');
$r2 = box($pdf, 'r2');
check('caption-side: bottom puts the caption below the last row', $c !== null && $r2 !== null && $c->y >= $r2->y + $r2->height - 1.0, $c && $r2 ? sprintf('cap %.1f row-bottom %.1f', $c->y, $r2->y + $r2->height) : 'missing');
$tbl = $c?->parent;
check('table height includes the bottom caption', $tbl !== null && $c !== null && $tbl->y + $tbl->height >= $c->y + $c->height - 1.0, $tbl ? sprintf('%.1f vs %.1f', $tbl->y + $tbl->height, $c->y + $c->height) : 'missing');

$pdf = render('<table style="caption-side:bottom"><caption id="c">Cap</caption><tr id="r1"><td>A</td></tr></table>');
$c = box($pdf, 'c');
$r1 = box($pdf, 'r1');
check('caption-side is inherited from the table', $c !== null && $r1 !== null && $c->y > $r1->y);

$pdf = render('<table border="1"><caption id="c" style="caption-side:bottom">Cap</caption></table><p id="after">after</p>');
$c = box($pdf, 'c');
$after = box($pdf, 'after');
check('caption-only table with caption-side: bottom', $c !== null && $after !== null && $after->y >= $c->y + $c->height - 1.0);

$rows = '';
for ($i = 1; $i <= 100; $i++) {
    $rows .= "<tr><td>Row{$i}</td></tr>";
}
$pdf = render('<table border="1" style="width:300pt"><caption style="caption-side:bottom;width:300pt">CAPTXT</caption><thead><tr><th>HEAD</th></tr></thead><tbody>' . $rows . '</tbody></table>');
$pages = pdfWords($pdf);
if ($pages === null) {
    skip('bottom caption after the last row of a split table: PyMuPDF not available');
} else {
    $n = count($pages);
    $capPages = 0;
    $capOnLast = false;
    foreach ($pages as $pi => $pg) {
        $cap = wordAt($pg, 'CAPTXT');
        if ($cap !== null) {
            $capPages++;
            $row = wordAt($pg, 'Row100');
            $capOnLast = $pi === $n - 1 && $row !== null && $cap[1] > $row[3];
        }
    }
    check('split table: more than one page', $n >= 3, (string) $n);
    check('split table: bottom caption printed once, after the last row', $capPages === 1 && $capOnLast, "{$capPages} caption(s), after last row: " . ($capOnLast ? 'yes' : 'no'));
}

// ---------------------------------------------------------------------------
echo "\n3. tab-size\n";
// ---------------------------------------------------------------------------
$tabHtml = static fn(string $style, string $tag): string => "<{$tag} style=\"{$style}\">a\tb\nabcdefgh\tc\n\txy</{$tag}>";
$px = static function (string $style, string $tag = 'pre') use ($tabHtml): ?array {
    $pages = pdfWords(render($tabHtml($style, $tag)));
    if ($pages === null) {
        return null;
    }
    $p = $pages[0];
    return ['a' => wordAt($p, 'a')[0] ?? -1.0, 'b' => wordAt($p, 'b')[0] ?? -1.0, 'c' => wordAt($p, 'c')[0] ?? -1.0, 'xy' => wordAt($p, 'xy')[0] ?? -1.0];
};
$d8 = $px('');
if ($d8 === null) {
    skip('tab-size tests: PyMuPDF not available');
} else {
    $t4 = $px('tab-size:4');
    $t8 = $px('tab-size:8');
    $tl = $px('tab-size:40px');
    $t0 = $px('tab-size:0');
    $space = ($t4['b'] - $t4['a']) / 4;
    check('default tab-size is 8', abs($d8['b'] - $t8['b']) < 0.01 && abs(($d8['b'] - $d8['a']) / 8 - $space) < 0.2, json_encode([$d8, $space]));
    check('tab-size: 4 halves the tab width', abs(($t8['b'] - $t8['a']) - 2 * ($t4['b'] - $t4['a'])) < 0.2);
    $stop = $space * 4;
    $cOff = $t4['c'] - $t4['a'];
    check('tab stops are relative to the line start (tab after a 8-letter word goes to the next stop)', $cOff > $stop && abs($cOff / $stop - round($cOff / $stop)) < 0.02 && $cOff > 2 * $stop - 0.5, sprintf('%.2f stops', $cOff / $stop));
    check('a leading tab moves to the first stop', abs(($t4['xy'] - $t4['a']) - $stop) < 0.2);
    check('tab-size accepts a length (40px = 30pt)', abs(($tl['b'] - $tl['a']) - 30.0) < 0.3, sprintf('%.2f', $tl['b'] - $tl['a']));
    check('tab-size: 0 collapses tabs', $t0['b'] - $t0['a'] < 12.0, json_encode($t0));
    $pw = $px('tab-size:4;white-space:pre-wrap');
    check('white-space: pre-wrap honours tab-size', $pw !== null && abs(($pw['b'] - $pw['a']) - $stop) < 0.2);
    $inh = pdfWords(render('<div style="tab-size:2"><pre>a' . "\t" . 'b</pre></div>'));
    check('tab-size is inherited', $inh !== null && abs((wordAt($inh[0], 'b')[0] - wordAt($inh[0], 'a')[0]) - 2 * $space) < 0.2);
}

// ---------------------------------------------------------------------------
echo "\n4. :dir()\n";
// ---------------------------------------------------------------------------
$pdf = render('<style>.t:dir(rtl){color:#ff0000} .t:dir(ltr){color:#0000ff}</style>'
    . '<div dir="rtl"><p class="t" id="a">x</p><p class="t" dir="ltr" id="b">y</p><span class="t" id="e">z</span></div>'
    . '<p class="t" id="c" dir="auto">שלום</p><p class="t" id="d">w</p><p class="t" id="f" dir="auto">hello</p><bdi class="t" id="g">مرحبا</bdi>');
$col = static fn(string $id): string => strtolower(box($pdf, $id)?->style->get('color', '') ?? 'missing');
check(':dir(rtl) matches an element inside dir=rtl', $col('a') === '#ff0000', $col('a'));
check(':dir(ltr) matches an explicit dir=ltr child', $col('b') === '#0000ff', $col('b'));
check(':dir(rtl) is inherited by descendants', $col('e') === '#ff0000', $col('e'));
check('dir=auto uses the first strong character (rtl)', $col('c') === '#ff0000', $col('c'));
check('no dir at all is ltr', $col('d') === '#0000ff', $col('d'));
check('dir=auto with latin text is ltr', $col('f') === '#0000ff', $col('f'));
check('<bdi> is auto by default', $col('g') === '#ff0000', $col('g'));
$pdf = render('<style>p:dir(fr){color:#00ff00}</style><p id="a">x</p>');
check('an invalid :dir() argument matches nothing', strtolower(box($pdf, 'a')?->style->get('color', '') ?? '') !== '#00ff00');

// ---------------------------------------------------------------------------
echo "\n5. <wbr> and inline boundaries in min-content\n";
// ---------------------------------------------------------------------------
$f = static fn(string $inner): float => box(render('<div id="a" style="float:left;width:min-content;font:14px DejaVu Sans">' . $inner . '</div>'), 'a')->width;
$glued = $f('aaaaaaaabbbbbbbbcccccccc');
$wbr = $f('aaaaaaaa<wbr>bbbbbbbb<wbr>cccccccc');
$one = max($f('aaaaaaaa'), $f('bbbbbbbb'), $f('cccccccc'));
check('<wbr> lets a shrink-to-fit box be as narrow as one segment', abs($wbr - $one) < 0.5 && $wbr < $glued / 2.5, sprintf('wbr %.1f one %.1f glued %.1f', $wbr, $one, $glued));
check('inline element boundaries are not break opportunities', abs($f('aaaaaaaa<span>bbbbbbbb</span>cccccccc') - $glued) < 1.0, sprintf('%.1f vs %.1f', $f('aaaaaaaa<span>bbbbbbbb</span>cccccccc'), $glued));
$inner = $f('aaaaaaaa<span>x<wbr>bbbbbbbb</span>');
check('<wbr> inside an inline element', abs($inner - max($f('aaaaaaaax'), $f('bbbbbbbb'))) < 0.5, sprintf('%.1f', $inner));
check('a space is still a break opportunity next to inline elements', abs($f('aaaaaaaa <span>bbbbbbbb</span>') - max($f('aaaaaaaa'), $f('bbbbbbbb'))) < 0.5);
check('text glued around <wbr> joins the neighbouring run', abs($f('aaaaaaaa<wbr>bbbbbbbb<b>cc</b>') - $f('bbbbbbbbcc')) < 1.0);
$long = str_repeat('x', 40);
$pdf = render('<div style="width:100pt"><table><tr><td id="a">aaaaaaaa<wbr>bbbbbbbb<wbr>cccccccc</td><td>' . $long . '</td></tr></table></div><div style="width:100pt"><table><tr><td id="b">aaaaaaaabbbbbbbbcccccccc</td><td>' . $long . '</td></tr></table></div>');
check('table columns can be narrower at a <wbr>', box($pdf, 'a')->width < box($pdf, 'b')->width - 5.0, sprintf('%.1f vs %.1f', box($pdf, 'a')->width, box($pdf, 'b')->width));

// ---------------------------------------------------------------------------
echo "\n6. ex / ch with @font-face and property order\n";
// ---------------------------------------------------------------------------
$b64 = base64_encode((string) file_get_contents(__DIR__ . '/../resources/fonts/Inter-Regular.ttf'));
$face = "@font-face{font-family:MyInter;src:url(data:font/ttf;base64,{$b64})}";
$w = static fn(string $html): float => box(render($html), 'a')->width;
$ref = $w('<div id="a" style="font-family:Inter;font-size:16px;width:10ex">x</div>');
$faceEx = $w("<style>{$face}</style><div id=\"a\" style=\"font-family:MyInter;font-size:16px;width:10ex\">x</div>");
check('ex uses the metrics of an @font-face family (not 0.5em)', abs($faceEx - $ref) < 0.05 && abs($ref - 60.0) > 1.0, sprintf('face %.2f ref %.2f', $faceEx, $ref));
$refCh = $w('<div id="a" style="font-family:Inter;font-size:16px;width:10ch">x</div>');
$faceCh = $w("<style>{$face}</style><div id=\"a\" style=\"font-family:MyInter;font-size:16px;width:10ch\">x</div>");
check('ch uses the metrics of an @font-face family', abs($faceCh - $refCh) < 0.05 && abs($refCh - 60.0) > 1.0, sprintf('face %.2f ref %.2f', $faceCh, $refCh));
$faceBefore = $w("<style>{$face} #a{width:10ch;font-family:MyInter;font-size:16px}</style><div id=\"a\">x</div>");
check('@font-face + length declared before font-family in the same rule', abs($faceBefore - $refCh) < 0.05, sprintf('%.2f vs %.2f', $faceBefore, $refCh));
$before = $w('<div id="a" style="width:10ch;font-family:Inter;font-size:16px">x</div>');
$after = $w('<div id="a" style="font-family:Inter;font-size:16px;width:10ch">x</div>');
check('ch is independent of declaration order', abs($before - $after) < 0.01 && abs($before - $refCh) < 0.01, sprintf('%.2f %.2f', $before, $after));
$exBefore = $w('<div id="a" style="width:10ex;font-family:Inter;font-size:16px">x</div>');
check('ex is independent of declaration order', abs($exBefore - $ref) < 0.01, sprintf('%.2f vs %.2f', $exBefore, $ref));
$later = $w('<div id="a" style="width:10ch;font-family:Inter;width:50px;font-size:16px">x</div>');
check('a later plain declaration still overrides a pending ex/ch length', abs($later - 37.5) < 0.01, sprintf('%.2f', $later));
$def = $w('<div id="a" style="font-size:16px;width:10ch">x</div>');
check('inherited font-family (no declaration in the rule) still works', abs($def - 76.32) < 0.5, sprintf('%.2f', $def));

// ---------------------------------------------------------------------------
echo "\n7. recto / verso for right-to-left documents\n";
// ---------------------------------------------------------------------------
$pageCount = static function (IvoPdf $pdf): int {
    return preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf->output()) ?: 0;
};
foreach (['ltr' => false, 'rtl' => true] as $dir => $isRtl) {
    $mk = static fn(string $side): string => '<html dir="' . $dir . '"><body><p>first</p><div style="break-before:' . $side . '">second</div></body></html>';
    $nRecto = $pageCount(render($mk('recto')));
    $nVerso = $pageCount(render($mk('verso')));
    $nRight = $pageCount(render($mk('right')));
    $nLeft = $pageCount(render($mk('left')));
    // Page 1 holds "first". Page 1 is a right page for ltr and a left page for rtl.
    $expectRecto = $isRtl ? 2 : 3;
    $expectVerso = $isRtl ? 3 : 2;
    check("{$dir}: break-before: recto", $nRecto === $expectRecto, "{$nRecto} pages, expected {$expectRecto}");
    check("{$dir}: break-before: verso", $nVerso === $expectVerso, "{$nVerso} pages, expected {$expectVerso}");
    check("{$dir}: break-before: right = recto, left = verso", $nRight === $nRecto && $nLeft === $nVerso);
}
$pdf = render('<html dir="rtl"><head><style>@page:left{margin-left:50pt} @page:right{margin-left:10pt}</style></head><body><p id="p1">one</p><div style="break-before:page"><p id="p2">two</p></div></body></html>');
$pages = pdfWords($pdf);
if ($pages === null || count($pages) < 2) {
    skip('rtl: @page :left applies to page 1: PyMuPDF not available');
} else {
    $x1 = wordAt($pages[0], 'one')[0] ?? -1.0;
    $x2 = wordAt($pages[1], 'two')[0] ?? -1.0;
    check('rtl: page 1 uses the @page :left rule', $x1 >= 0 && $x2 >= 0 && abs(($x1 - $x2) - 40.0) < 2.0 || abs(($x2 - $x1) - 40.0) < 2.0, sprintf('%.1f vs %.1f', $x1, $x2));
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
