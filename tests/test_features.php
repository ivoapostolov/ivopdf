<?php

declare(strict_types=1);

/**
 * Regression tests for the dompdf / mPDF replacement work:
 * selectors, media queries, generated content, counters, @font-face, fallback fonts, font subsetting,
 * inline layout (nbsp, glued words, decorations), tables (collapse, caption, colgroup, repeated thead),
 * bookmarks, internal links, gradients, backgrounds, transforms, watermark, warnings, SSRF and API compatibility.
 *
 * Run: php tests/test_features.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssParser;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
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

function render(string $html, array $options = [], ?callable $setup = null): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->loadHtml($html)->output();
    return $pdf;
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

function byId(IvoPdf $pdf, string $id): ?LayoutBox
{
    return findBox($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === $id);
}

// ---------------------------------------------------------------------------
echo "1. CSS selectors\n";
// ---------------------------------------------------------------------------
$dom = new DOMDocument();
@$dom->loadHTML('<div class="a"><div class="b"><div class="x"><p class="c" data-x="a+b" title="mailto:x" lang="bg-BG">t</p></div></div></div>');
$p = $dom->getElementsByTagName('p')->item(0);
$xDiv = $dom->getElementsByTagName('div')->item(2);
$css = new CssParser();
check('descendant chain with backtracking (.a .b .c)', $css->matchesSelector($p, '.a .b .c'));
check('child combinator rejects non-parent (.b > .c)', !$css->matchesSelector($p, '.b > .c'));
check('[attr~=] with no spaces is not mangled', $css->matchesSelector($p, '[class~="c"]'));
check('attribute value containing + and :', $css->matchesSelector($p, '[data-x="a+b"]') && $css->matchesSelector($p, '[title^="mailto:"]'));
check(':is() / :where()', $css->matchesSelector($p, 'p:is(.c, .z)') && !$css->matchesSelector($p, 'p:where(.z)'));
check(':has(> p)', $css->matchesSelector($xDiv, 'div:has(> p)'));
check(':not(:nth-child(2n))', $css->matchesSelector($xDiv, '.x:not(:nth-child(2n))'));
check(':lang(bg)', $css->matchesSelector($p, 'p:lang(bg)'));
check('selector list in matchesSelector', $css->matchesSelector($p, '.nope, .c'));
check(':hover never matches in print', !$css->matchesSelector($p, '.c:hover'));

// ---------------------------------------------------------------------------
echo "\n2. Media queries\n";
// ---------------------------------------------------------------------------
$css->setMediaViewport(523.0, 770.0);
check('print matches', $css->matchesMediaQueryList('print'));
check('screen does not match', !$css->matchesMediaQueryList('screen'));
check('(max-width: 600px) false on A4 (≈697px)', !$css->matchesMediaQueryList('(max-width: 600px)'));
check('(min-width: 500px) true', $css->matchesMediaQueryList('(min-width: 500px)'));
check('orientation: portrait', $css->matchesMediaQueryList('print and (orientation: portrait)'));
check('not screen', $css->matchesMediaQueryList('not screen'));
check('range syntax (width >= 400px)', $css->matchesMediaQueryList('(width >= 400px)'));

// ---------------------------------------------------------------------------
echo "\n3. Cascade & shorthands\n";
// ---------------------------------------------------------------------------
$pdf = render('<style>.g{background:linear-gradient(#000,#fff)} .g.w{background:#fff}
ul.none{list-style:none} .bw{border:.5pt solid red} .inh{margin:inherit} .p{margin:7pt}</style>
<div class="g w" id="g">x</div><ul class="none"><li id="li">x</li></ul><div class="bw" id="bw">x</div>
<div class="p"><div class="inh" id="inh">x</div></div><div id="bs" style="box-sizing:border-box"><div id="bs2">x</div></div>');
$g = byId($pdf, 'g');
check('background shorthand resets an earlier gradient', $g !== null && $g->style->get('background-image') === 'none');
check('list-style: none removes markers', byId($pdf, 'li')?->listMarker === null);
check('border width ".5pt" parsed as width (not color)', abs((byId($pdf, 'bw')?->borderTop ?? 0) - 0.5) < 0.01);
check('margin: inherit copies longhands', abs((byId($pdf, 'inh')?->marginTop ?? 0) - 7.0) < 0.01);
check('box-sizing is not inherited', byId($pdf, 'bs2')?->style->get('box-sizing') === 'content-box');
$st = new ComputedStyle();
$st->set('color', 'red');
$st->set('border', '1px solid');
check('border color defaults to currentColor', $st->get('border-top-color') === 'red');
$st->set('color', 'hsl(120.5, 50%, 50%)');
check('fractional hsl hue parses without deprecation', \IvoPdf\Css\CssColor::parse($st->get('color')) !== null);

$importDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-import-' . getmypid();
@mkdir($importDir);
file_put_contents($importDir . '/a.css', "@import \"b.css\";\n#ia{margin-top:11pt}");
file_put_contents($importDir . '/b.css', "@import url(a.css);\n#ib{margin-top:13pt}");
file_put_contents($importDir . '/s.css', "#ia{margin-top:99pt}");
$pdf = render('<style>@import "a.css" print; @import url("s.css") screen; @import "missing.css";</style><div id="ia">a</div><div id="ib">b</div>', ['basePath' => $importDir]);
check('@import loads nested stylesheets (cycle-safe)', abs((byId($pdf, 'ia')?->marginTop ?? 0) - 11.0) < 0.01 && abs((byId($pdf, 'ib')?->marginTop ?? 0) - 13.0) < 0.01);
check('@import honours media and warns about missing files', count(array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, 'missing.css'))) === 1);
array_map('unlink', glob($importDir . '/*.css') ?: []);
@rmdir($importDir);

$pdf = render('<div style="position:relative;height:50pt"><div id="abs" style="position:absolute;top:5pt;left:5pt">ABSTEXT</div></div><div id="fl" style="float:left;width:80pt">FLOATTEXT</div><p>after</p>');
check('text directly inside position:absolute is laid out', count(byId($pdf, 'abs')?->lines ?? []) === 1 && (byId($pdf, 'abs')?->width ?? 0) > 20.0);
check('text directly inside a float is laid out in the float', in_array('FLOATTEXT', lineTexts(byId($pdf, 'fl')), true));

// ---------------------------------------------------------------------------
echo "\n4. Generated content & counters\n";
// ---------------------------------------------------------------------------
$pdf = render('<style>.req::after{content:" *"} body{counter-reset:s} h2{counter-increment:s} h2::before{content:counter(s, upper-roman) ". "} a[href]::after{content:" (" attr(href) ")"} .pg::after{content:"P" counter(page) "/" counter(pages)}</style>
<p class="req">Name</p><h2>First</h2><h2>Second</h2><p><q>quote</q> <a href="http://x.example">x</a></p><div class="pg"></div>');
$texts = implode('|', lineTexts($pdf->getLayoutTree()));
check('::after string content', str_contains($texts, 'Name *'));
check('counter() with list style', str_contains($texts, 'I. First') && str_contains($texts, 'II. Second'));
check('<q> default quotes', str_contains($texts, '“quote”'));
check('attr() in content', str_contains($texts, '(http://x.example)'));
check('counter(page)/counter(pages) placeholders', str_contains($texts, 'P{PAGE_NUM}/{PAGE_COUNT}'));

// ---------------------------------------------------------------------------
echo "\n5. Inline layout\n";
// ---------------------------------------------------------------------------
$pdf = render('<p id="n" style="width:120pt">aaaa bbbb cccc 1&nbsp;200,00&nbsp;лв. dddd</p>');
$lines = lineTexts(byId($pdf, 'n'));
check('&nbsp; never breaks (amount stays on one line)', (bool) array_filter($lines, static fn(string $l): bool => str_contains($l, "1\u{00A0}200,00\u{00A0}лв.")), implode(' / ', $lines));
$pdf = render('<p id="g" style="width:60pt">xxxxxx foo<b>bar</b>baz</p>');
$lines = lineTexts(byId($pdf, 'g'));
check('words split across inline tags are not broken apart', in_array('foobarbaz', $lines, true), implode(' / ', $lines));
$pdf = render('<p id="ws">a <span> b</span></p>');
check('whitespace collapses across element boundaries', lineTexts(byId($pdf, 'ws'))[0] === 'a b');
$pdf = render('<p id="h" style="width:70pt">extraordinary-long-hyphenated-word</p>');
check('long hyphenated words break after hyphens', count(lineTexts(byId($pdf, 'h'))) >= 2);
$pdf = render('<p id="sp">x <span style="background:#ff0;border-radius:3pt">two words</span> y</p>');
$runs = byId($pdf, 'sp')->lines[0]->runs;
$decorated = array_filter($runs, static fn($r): bool => !empty($r->decorations));
check('decorated span tokens share one decoration box', count($decorated) === 3 && count(array_unique(array_map(static fn($r) => spl_object_id($r->decorations[0]), $decorated))) === 1);
$pdf = render('<div id="e" style="width:80pt;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">A very long sentence that overflows</div>');
$eLines = lineTexts(byId($pdf, 'e'));
check('text-overflow: ellipsis appends …', count($eLines) === 1 && str_ends_with($eLines[0], '…'), $eLines[0] ?? '');

// ---------------------------------------------------------------------------
echo "\n6. Box sizing\n";
// ---------------------------------------------------------------------------
$pdf = render('<div id="mh" style="max-height:30pt;overflow:hidden">1<br>2<br>3<br>4<br>5<br>6</div><div id="fc" style="width:fit-content;background:#eee">short</div>');
check('max-height clamps height', abs((byId($pdf, 'mh')?->height ?? 0) - 30.0) < 0.1);
check('width: fit-content shrinks to content', (byId($pdf, 'fc')?->width ?? 999) < 60.0);
$im = imagecreatetruecolor(48, 48);
ob_start();
imagepng($im);
$png48 = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
$pdf = render('<div style="display:flex; width:200pt"><img id="fi" src="' . $png48 . '" width="40"><div>Text text text text text text text text text text text text</div></div>');
check('flex: explicit width caps the automatic minimum of replaced elements', abs((byId($pdf, 'fi')?->width ?? 0) - 30.0) < 0.1, (string) (byId($pdf, 'fi')?->width ?? 'n/a'));

// ---------------------------------------------------------------------------
echo "\n7. Tables\n";
// ---------------------------------------------------------------------------
$rows = '';
for ($i = 1; $i <= 80; $i++) {
    $rows .= "<tr><td>{$i}</td><td>Row {$i}</td></tr>";
}
$pdf = render('<table style="border-collapse:collapse"><caption id="cap">Caption</caption><colgroup><col width="60"><col></colgroup><thead><tr><th>#</th><th>Name</th></tr></thead><tbody>' . $rows . '</tbody></table>');
check('<caption> is laid out', byId($pdf, 'cap') !== null && byId($pdf, 'cap')->height > 5.0);
check('long table spans several pages with sane page count', $pdf->getPageCount() >= 2 && $pdf->getPageCount() <= 4, 'pages=' . $pdf->getPageCount());
$firstCell = findBox($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->tagName === 'td');
check('<col width> applied (≈45pt incl. collapse)', $firstCell !== null && abs($firstCell->width - 45.0) < 2.0, (string) ($firstCell?->width ?? 'n/a'));
$pdf = render('<table><tr><td id="va" style="height:60pt">tall</td><td id="vb">mid</td></tr></table>');
check('td defaults to vertical-align: middle', (byId($pdf, 'vb')?->lines[0]->y ?? 0) > (byId($pdf, 'va')?->y ?? 0) + 10.0);

// ---------------------------------------------------------------------------
echo "\n8. PDF structure: subsetting, bookmarks, links, gradients\n";
// ---------------------------------------------------------------------------
$pdf = render('<h1 id="top">Title</h1><p><a href="#s2">to section 2</a> <a href="https://example.com/път">ext</a></p>
<div style="height:40pt;background:radial-gradient(circle, #f00, #00f)"></div>
<div style="height:40pt;background:linear-gradient(90deg, red 20%, blue 80%)"></div>
<div style="width:50pt;height:20pt;background:#0f0;transform:rotate(10deg) translateX(10pt) skewX(5deg)"></div>
<h2 id="s2" style="page-break-before:always">Section 2</h2><h3>Sub</h3>');
$bytes = $pdf->output();
check('font is subset (tag prefix ABCDEF+)', preg_match('/\/BaseFont \/[A-Z]{6}\+DejaVuSans/', $bytes) === 1);
check('subset document is small (< 60 KB uncompressed streams aside)', strlen((new IvoPdf())->loadHtml('<p>Hello свят</p>')->output()) < 60000);
check('bookmarks / outlines present', str_contains($bytes, '/Type /Outlines') && str_contains($bytes, '/PageMode /UseOutlines'));
check('internal link uses /Dest', preg_match('/\/Subtype \/Link [^>]*\/Dest \[\d+ 0 R \/XYZ/', $bytes) === 1);
check('non-ASCII URI is percent-encoded', str_contains($bytes, '/URI (https://example.com/%D0%BF%D1%8A%D1%82)'));
check('radial gradient → ShadingType 3', str_contains($bytes, '/ShadingType 3'));
check('gradient stops normalized (stitching function)', str_contains($bytes, '/FunctionType 3'));
check('transform list emits one cm matrix', preg_match('/-?\d+\.\d{5} -?\d+\.\d{5} -?\d+\.\d{5} -?\d+\.\d{5} -?\d+\.\d{3} -?\d+\.\d{3} cm/', $bytes) === 1);
check('/Lang taken from <html lang>', str_contains(render('<html lang="bg"><body>x</body></html>')->output(), '/Lang (bg)'));

// ---------------------------------------------------------------------------
echo "\n9. Fonts: @font-face, fallback, validation\n";
// ---------------------------------------------------------------------------
$serif = base64_encode((string) file_get_contents(__DIR__ . '/../resources/fonts/DejaVuSerif.ttf'));
$pdf = render("<style>@font-face{font-family:'MyFace';src:url(data:font/ttf;base64,{$serif}) format('truetype')} p{font-family:'MyFace'}</style><p>Hi</p>");
check('@font-face with large base64 data URI registers the family', str_contains($pdf->output(), '+DejaVuSerif'));
$pdf = render('<p style="font-family:\'DejaVu Sans Mono\'">∰ ⨌ ⟶</p>');
check('missing glyphs fall back to another bundled family', substr_count($pdf->output(), '/Subtype /Type0') >= 2);
$bad = tempnam(sys_get_temp_dir(), 'ivo') ?: '';
file_put_contents($bad, 'not a font at all');
$threw = false;
try {
    new \IvoPdf\Font\TtfParser($bad);
} catch (\RuntimeException) {
    $threw = true;
}
@unlink($bad);
check('invalid TTF is rejected with an exception', $threw);

// ---------------------------------------------------------------------------
echo "\n10. Header/footer, watermark, warnings, API compatibility\n";
// ---------------------------------------------------------------------------
$pdf = render(str_repeat('<p style="page-break-after:always">x</p>', 3), [], static function (IvoPdf $p): void {
    $p->setFooterHtml('<div style="text-align:right">{PAGE_NUM} / {PAGE_COUNT}</div>');
    $p->setWatermarkText('DRAFT');
});
$raw = $pdf->output();
check('footer laid out per page with real numbers', $pdf->getPageCount() >= 3);
check('watermark adds transparency state', str_contains($raw, '/ExtGState'));
$pdf = render('<img src="missing-file.png"><img src="/etc/passwd">');
check('warnings report images that could not be loaded', count($pdf->getWarnings()) === 2, implode(' | ', $pdf->getWarnings()));
$opts = new Options(['format' => 'A5-L', 'margin_left' => 10, 'chroot' => __DIR__, 'default_font' => 'DejaVu Serif']);
[$w, $h] = $opts->getPageDimensions();
check('mPDF option "format" => "A5-L"', $w > $h && abs($w - 595.28) < 0.5);
check('mPDF margin_left (mm) converted to pt', abs($opts->margins['left'] - 28.35) < 0.1);
check('dompdf "chroot" maps to allowedPaths', $opts->allowedPaths === [__DIR__]);
$m = new IvoPdf();
$m->SetHTMLFooter('<div>{PAGENO}/{nbpg}</div>');
$m->WriteHTML('<p>a</p>');
$m->AddPage();
$m->WriteHTML('<p>b</p>');
$str = $m->output('', 'S');
check('mPDF Output(\'\', \'S\') returns bytes; AddPage() breaks', str_starts_with($str, '%PDF-') && $m->getPageCount() === 2);

// ---------------------------------------------------------------------------
echo "\n11. Security\n";
// ---------------------------------------------------------------------------
$sec = new Options(['isRemoteEnabled' => true]);
check('SSRF: loopback literal blocked', $sec->fetchSafeRemoteUrl('http://127.0.0.1/x.png') === null);
check('SSRF: IPv6 loopback blocked', $sec->fetchSafeRemoteUrl('http://[::1]/x.png') === null);
check('SSRF: IPv4-mapped IPv6 blocked', $sec->fetchSafeRemoteUrl('http://[::ffff:10.0.0.1]/x.png') === null);
check('SSRF: metadata address blocked', $sec->fetchSafeRemoteUrl('http://169.254.169.254/latest') === null);
check('SSRF: CGNAT range blocked', $sec->fetchSafeRemoteUrl('http://100.64.1.1/') === null);
check('non-http schemes refused', $sec->fetchSafeRemoteUrl('file:///etc/passwd') === null);

// ---------------------------------------------------------------------------
echo "\n12. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
