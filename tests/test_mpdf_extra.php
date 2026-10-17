<?php

declare(strict_types=1);

/**
 * mPDF / paged-media extras: mirrorMargins, <dottab>, <textcircle>, CSS `float: footnote` (+ ::footnote-call / ::footnote-marker),
 * and regressions found by the browser/mPDF benchmark: `<sethtmlpageheader show-this-page="1">` limited to its page
 * (the `@page { header: html_x }` default returns afterwards), `<columns vertical-align="justify">`, document CSS applied
 * to `<htmlpageheader>` / `<htmlpagefooter>`.
 *
 * Page text is read with PyMuPDF (python + fitz) when available; those checks are skipped otherwise.
 *
 * Run: php tests/test_mpdf_extra.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;

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

function skip(string $why): void
{
    echo "  [SKIP] {$why}\n";
}

/** @return array{pages: list<array{w: float, h: float, text: string, lines: list<array{0: float, 1: float, 2: float, 3: string}>}>}|null [x, y, fontSize, text] per line */
function inspectIt(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-extra-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = <<<'PY'
import pymupdf as fitz,json,sys
d=fitz.open(sys.argv[1]); pages=[]
for p in d:
    lines=[]
    for b in p.get_text("dict")["blocks"]:
        for l in b.get("lines",[]):
            t="".join(s["text"] for s in l["spans"])
            if t.strip(): lines.append([round(l["bbox"][0],1),round(l["bbox"][1],1),round(l["spans"][0]["size"],1),t])
    pages.append({"w":p.rect.width,"h":p.rect.height,"text":p.get_text(),"lines":lines})
print(json.dumps({"pages":pages}))
PY;
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-extra-inspect.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '{') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** x of the first line on a page whose text contains $needle (null if none). */
function lineX(array $page, string $needle): ?float
{
    foreach ($page['lines'] as $l) {
        if (str_contains($l[3], $needle)) {
            return (float) $l[0];
        }
    }
    return null;
}

function render(string $html, array $options = [], ?callable $setup = null): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->WriteHTML($html);
    $pdf->output();
    return $pdf;
}

// ---------------------------------------------------------------------------
echo "1. mirrorMargins\n";
// ---------------------------------------------------------------------------
$pages = str_repeat('<p style="page-break-after:always">Mirror text</p>', 3);
$pdf = render($pages, ['mirrorMargins' => 1, 'margin_left' => 30, 'margin_right' => 10, 'format' => 'A5']);
$info = inspectIt($pdf);
if ($info !== null) {
    $x = array_map(static fn($p) => lineX($p, 'Mirror text'), array_slice($info['pages'], 0, 3));
    check('odd pages keep margin-left (30 mm)', abs($x[0] - 85.0) < 2 && abs($x[2] - 85.0) < 2, json_encode($x));
    check('even pages swap to margin-right (10 mm) on the left', abs($x[1] - 28.3) < 2, json_encode($x));
} else {
    skip('PyMuPDF not available');
}
$pdf = render($pages, ['margin_left' => 30, 'margin_right' => 10, 'format' => 'A5']);
$info = inspectIt($pdf);
if ($info !== null) {
    check('without mirrorMargins every page uses the same margins', abs(lineX($info['pages'][1], 'Mirror text') - 85.0) < 2);
}
$pdf = render($pages, ['margin_left' => 30, 'margin_right' => 10, 'format' => 'A5'], static function (IvoPdf $p): void {
    $p->mirrorMargins = 1; // property assignment form
});
$info = inspectIt($pdf);
if ($info !== null) {
    check('$mpdf->mirrorMargins = 1 works too', abs(lineX($info['pages'][1], 'Mirror text') - 28.3) < 2);
}

// ---------------------------------------------------------------------------
echo "\n2. <dottab>\n";
// ---------------------------------------------------------------------------
$pdf = render('<p>Chapter one<dottab outdent="2em" />12</p><p>Intro<dottab />7</p>');
$info = inspectIt($pdf);
if ($info !== null) {
    $lines = array_column($info['pages'][0]['lines'], 3);
    check('a dotted leader fills the line up to the right-aligned tail', count($lines) >= 2 && substr_count($lines[0], '.') > 20 && str_ends_with(trim($lines[0]), '12') && str_ends_with(trim($lines[1]), '7'), json_encode($lines));
}

// ---------------------------------------------------------------------------
echo "\n3. <textcircle>\n";
// ---------------------------------------------------------------------------
$pdf = render('<p><textcircle r="30mm" top-text="IVO PDF" bottom-text="OFFICIAL" divider="*" style="font-size:14pt;color:#a00;font-weight:bold"></textcircle></p>');
$info = inspectIt($pdf);
if ($info !== null) {
    $glyphs = $info['pages'][0]['lines'];
    $letters = array_column($glyphs, 3);
    check('every glyph of both texts is set separately', count(array_intersect(['I', 'V', 'O', 'P', 'D', 'F', 'C', 'A', 'L'], $letters)) === 9 && count(array_filter($letters, static fn($t) => $t === '*')) === 2, json_encode($letters));
    $ys = array_column($glyphs, 1);
    check('the glyphs spread over the circle (top arc above, bottom text below the divider)', max($ys) - min($ys) > 100);
    $topO = null;
    $botO = null;
    foreach ($glyphs as $g) {
        if ($g[3] === 'V') {
            $topO = $g;
        }
        if ($g[3] === 'C') {
            $botO = $g;
        }
    }
    check('top text is above the bottom text', $topO !== null && $botO !== null && $topO[1] < $botO[1]);
}
check('textcircle produces no warnings', $pdf->getWarnings() === [], json_encode($pdf->getWarnings()));

// ---------------------------------------------------------------------------
echo "\n4. CSS float: footnote\n";
// ---------------------------------------------------------------------------
$fn = '<style>.fn{float:footnote;font-size:9pt}</style>';
for ($i = 1; $i <= 40; $i++) {
    $fn .= "<p>Paragraph {$i} has a note<span class=\"fn\">NOTE-TEXT-{$i} explains paragraph {$i}.</span> and goes on.</p>";
}
$pdf = render($fn);
check('the document paginates', $pdf->getPageCount() >= 2, 'pages ' . $pdf->getPageCount());
$info = inspectIt($pdf);
if ($info !== null) {
    $ok = true;
    $detail = '';
    foreach ($info['pages'] as $pi => $page) {
        $callsOnPage = [];
        $notesOnPage = [];
        foreach ($page['lines'] as $l) {
            if (preg_match('~^Paragraph (\d+) has a note~', $l[3], $m) === 1) {
                $callsOnPage[] = (int) $m[1];
            }
            if (preg_match('~NOTE-TEXT-(\d+)~', $l[3], $m) === 1) {
                $notesOnPage[] = (int) $m[1];
            }
        }
        if ($callsOnPage !== $notesOnPage) {
            $ok = false;
            $detail = 'page ' . ($pi + 1) . ': calls ' . json_encode($callsOnPage) . ' notes ' . json_encode($notesOnPage);
        }
    }
    check('every note is printed on the page of its call', $ok, $detail);
    $p1 = $info['pages'][0];
    $callLine = null;
    $noteLine = null;
    foreach ($p1['lines'] as $l) {
        if (str_starts_with($l[3], 'Paragraph 1 has')) {
            $callLine = $l;
        }
        if (str_contains($l[3], 'NOTE-TEXT-1 ')) {
            $noteLine = $l;
        }
    }
    check('the call is a number inside the text, the note starts with its marker "1."', $callLine !== null && preg_match('~note1 and goes on~', $callLine[3]) === 1 && $noteLine !== null && str_starts_with(trim($noteLine[3]), '1.'), json_encode([$callLine, $noteLine]));
    check('notes sit at the bottom of the page in a smaller font', $noteLine !== null && $noteLine[1] > $p1['h'] * 0.6 && $noteLine[2] < 10.0, json_encode($noteLine));
    $lastBody = 0.0;
    $firstNote = 1e9;
    foreach ($p1['lines'] as $l) {
        if (str_starts_with($l[3], 'Paragraph ')) {
            $lastBody = max($lastBody, $l[1] + $l[2]);
        }
        if (str_contains($l[3], 'NOTE-TEXT-')) {
            $firstNote = min($firstNote, $l[1]);
        }
    }
    check('body text never runs into the footnote area', $lastBody < $firstNote, "{$lastBody} vs {$firstNote}");
}

$pdf = render('<style>.fn{float:footnote} ::footnote-call{color:#c00} ::footnote-marker{font-weight:bold} @page{counter-reset:footnote}</style>'
    . '<p>One<span class="fn">first</span> two<span class="fn">second</span></p><p style="page-break-before:always">Three<span class="fn">third</span></p>');
$info = inspectIt($pdf);
if ($info !== null) {
    $p2 = implode("\n", array_column($info['pages'][1]['lines'], 3));
    check('@page { counter-reset: footnote } restarts the numbering on every page', str_contains($p2, 'Three1') && preg_match('~^1\. third~m', $p2) === 1, $p2);
}
check('footnote CSS rules (::footnote-call / ::footnote-marker) cause no warnings', $pdf->getWarnings() === [], json_encode($pdf->getWarnings()));
$noNotes = render('<p>No notes here</p>');
check('documents without footnotes are unaffected', $noNotes->getPageCount() === 1);

// ---------------------------------------------------------------------------
echo "\n5. Benchmark regressions: headers, columns, footer CSS\n";
// ---------------------------------------------------------------------------
$doc19 = '<style>
@page { margin-top: 70pt; margin-bottom: 60pt; margin-left: 50pt; margin-right: 50pt; header: html_myHeader; footer: html_myFooter; }
body { font-family: sans-serif; font-size: 10pt; }
.hdr { border-bottom: 1px solid #7b1e3a; font-size: 9pt; }
.ftr { border-top: 1px solid #999; font-size: 8pt; color: #666; }
</style>
<htmlpageheader name="myHeader"><table width="100%" class="hdr"><tr><td><b>DEFAULTHEAD</b></td></tr></table></htmlpageheader>
<htmlpageheader name="firstHeader"><div style="font-size:14pt"><b>COVERHEAD</b></div></htmlpageheader>
<htmlpagefooter name="myFooter"><table width="100%" class="ftr"><tr><td>FOOTLEFT</td><td align="right">Page {PAGENO} of {nbpg}</td></tr></table></htmlpagefooter>
<sethtmlpageheader name="firstHeader" value="on" show-this-page="1" />
<h1>Cover</h1>
<columns column-count="2" vertical-align="justify" column-gap="14" />
<p>' . str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 18) . '</p>
<columns column-count="1" />
<pagebreak /><h1>Second</h1><pagebreak /><h1>Third</h1>';
$pdf = render($doc19);
$info = inspectIt($pdf);
if ($info !== null) {
    $texts = array_map(static fn($p) => $p['text'], $info['pages']);
    check('show-this-page: the cover header appears on page 1 only', str_contains($texts[0], 'COVERHEAD') && !str_contains($texts[1] ?? '', 'COVERHEAD') && !str_contains($texts[2] ?? '', 'COVERHEAD'));
    check('the @page default header returns on pages 2 and 3', str_contains($texts[1] ?? '', 'DEFAULTHEAD') && str_contains($texts[2] ?? '', 'DEFAULTHEAD'));
    check('the default header is not on the cover page', !str_contains($texts[0], 'DEFAULTHEAD'));
    $xs = [];
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'Lorem') || str_contains($l[3], 'ipsum') || str_contains($l[3], 'elit')) {
            $xs[] = (int) round($l[0]);
        }
    }
    check('<columns column-count="2" vertical-align="justify"> fills both columns', $xs !== [] && max($xs) > 250 && min($xs) < 100, json_encode(array_slice($xs, 0, 12)));
    $ftr = null;
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'FOOTLEFT')) {
            $ftr = $l;
        }
    }
    check('document CSS applies to <htmlpagefooter> (.ftr font-size 8pt)', $ftr !== null && abs($ftr[2] - 8.0) < 0.6, json_encode($ftr));
    $hdr = null;
    foreach ($info['pages'][1]['lines'] ?? [] as $l) {
        if (str_contains($l[3], 'DEFAULTHEAD')) {
            $hdr = $l;
        }
    }
    check('document CSS applies to <htmlpageheader> (.hdr font-size 9pt)', $hdr !== null && abs($hdr[2] - 9.0) < 0.6, json_encode($hdr));
}
$pdf = render('<style>.f{font-size:7pt}</style><htmlpagefooter name="x"><div class="f">SEVENPT</div></htmlpagefooter><sethtmlpagefooter name="x" value="on" /><p>Body</p>');
$info = inspectIt($pdf);
if ($info !== null) {
    $l7 = null;
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'SEVENPT')) {
            $l7 = $l;
        }
    }
    check('footers selected with <sethtmlpagefooter> also use the document CSS', $l7 !== null && abs($l7[2] - 7.0) < 0.6, json_encode($l7));
}

// ---------------------------------------------------------------------------
echo "\n6. Engine hygiene\n";
// ---------------------------------------------------------------------------
echo "\n--- <pagebreak orientation> followed by a heading ---\n";
$orient = new \Mpdf\Mpdf();
$orient->WriteHTML('<p>a</p><pagebreak orientation="L" /><h1>b</h1><p>t</p><pagebreak orientation="P" /><h1>c</h1>');
$orientPdf = $orient->Output('', 'S');
preg_match_all('~/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)~', $orientPdf, $mb);
$sides = implode('', array_map(static fn($w, $h) => (float) $w > (float) $h ? 'L' : 'P', $mb[1], $mb[2]));
check('orientation switch + heading: P, L, P without an empty extra page', $sides === 'PLP', $sides);

check('no PHP warnings/notices/deprecations',empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
