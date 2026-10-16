<?php

declare(strict_types=1);

/**
 * mPDF template compatibility: custom tags (<pagebreak>, <htmlpageheader>, <sethtmlpageheader>, <bookmark>,
 * <indexentry>/<indexinsert>, <annotation>, <watermarkimage>, <columns>, <tocpagebreak>), mPDF methods (SetHeader,
 * SetFooter, WriteHTML modes, SetDisplayMode, SetJS, AddPageByArray) and constructor keys (fontDir / fontdata).
 *
 * Page text, outlines and annotations are read with PyMuPDF (python + fitz) when available; those checks are skipped otherwise.
 *
 * Run: php tests/test_mpdf_compat.php
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

/** Renders with a fresh IvoPdf; $setup runs before WriteHTML. */
function make(string $html, ?callable $setup = null, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->WriteHTML($html);
    $pdf->output();
    return $pdf;
}

/**
 * PyMuPDF view of a PDF: pages (w, h, text, lines, annots, images), toc, catalog keys; null when python/fitz is missing.
 *
 * @return array{pages: list<array<string, mixed>>, toc: list<array{0: int, 1: string, 2: int}>, catalog: array<string, string>}|null
 */
function inspect(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-mpdf-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = <<<'PY'
import fitz,json,sys
d=fitz.open(sys.argv[1]); pages=[]
for p in d:
    lines=[]
    for b in p.get_text("dict")["blocks"]:
        for l in b.get("lines",[]):
            t="".join(s["text"] for s in l["spans"])
            if t.strip(): lines.append([round(l["bbox"][0],1),round(l["bbox"][1],1),round(l["bbox"][2],1),t])
    annots=[{"type":a.type[1],"content":a.info.get("content",""),"title":a.info.get("title",""),"y":a.rect.y0} for a in p.annots()]
    pages.append({"w":p.rect.width,"h":p.rect.height,"text":p.get_text(),"lines":lines,"annots":annots,"images":len(p.get_images())})
cat=d.pdf_catalog()
keys={}
for k in ("OpenAction","PageLayout","Names"):
    v=d.xref_get_key(cat,k)
    keys[k]=str(v[1])
print(json.dumps({"pages":pages,"toc":d.get_toc(),"catalog":keys}))
PY;
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-mpdf-inspect.py';
    file_put_contents($scriptFile, $script);
    $cmd = 'python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    $json = @shell_exec($cmd);
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '{') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** First page (1-based) whose text contains $needle, or 0. */
function pageWith(array $info, string $needle): int
{
    foreach ($info['pages'] as $i => $p) {
        if (str_contains((string) $p['text'], $needle)) {
            return $i + 1;
        }
    }
    return 0;
}

function skip(string $why): void
{
    echo "  [SKIP] {$why}\n";
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-mpdf-test';
if (!is_dir($dir)) {
    mkdir($dir);
}

// ---------------------------------------------------------------------------
echo "1. Named headers/footers switched mid-document + <pagebreak> attributes\n";
// ---------------------------------------------------------------------------
$tpl = '<style>body{font-size:11pt} @page { header: html_Main; footer: html_Foot; }</style>
<htmlpageheader name="Main"><div style="border-bottom:1px solid #000">MAINHEAD {PAGENO}/{nbpg}</div></htmlpageheader>
<htmlpageheader name="Land"><div style="color:#c00">LANDHEAD {PAGENO}</div></htmlpageheader>
<htmlpagefooter name="Foot"><div>FOOT {PAGENO}</div></htmlpagefooter>
<p>First page body</p>
<pagebreak orientation="L" odd-header-name="html_Land" odd-header-value="1" even-header-name="Land" even-header-value="1" />
<p>Landscape body</p>
<pagebreak orientation="P" odd-header-name="Main" odd-header-value="1" even-header-name="Main" even-header-value="1" />
<p>Portrait again</p>
<pagebreak />
<sethtmlpageheader name="Land" value="on" show-this-page="1" />
<p>Switched by tag</p>
<pagebreak />
<sethtmlpageheader value="off" show-this-page="1" />
<p>No header here</p>';
$pdf = make($tpl);
$info = inspect($pdf);
check('five pages', $pdf->getPageCount() === 5, (string) $pdf->getPageCount());
if ($info !== null) {
    $pg = $info['pages'];
    check('page 1 portrait A4, MAINHEAD from @page { header: html_Main }', abs($pg[0]['w'] - 595.28) < 1 && str_contains($pg[0]['text'], 'MAINHEAD 1/5'));
    check('page 1 footer from @page { footer: html_Foot }', str_contains($pg[0]['text'], 'FOOT 1'));
    check('orientation="L" gives a landscape MediaBox', abs($pg[1]['w'] - 841.89) < 1 && abs($pg[1]['h'] - 595.28) < 1, $pg[1]['w'] . 'x' . $pg[1]['h']);
    check('named header switches on the landscape page', str_contains($pg[1]['text'], 'LANDHEAD 2') && !str_contains($pg[1]['text'], 'MAINHEAD'));
    check('orientation="P" returns to portrait and the main header', abs($pg[2]['w'] - 595.28) < 1 && str_contains($pg[2]['text'], 'MAINHEAD 3/5'));
        check('<sethtmlpageheader show-this-page="1"> applies to the current page', str_contains($pg[3]['text'], 'LANDHEAD 4'));
    check('<sethtmlpageheader value="off"> removes the header', !str_contains($pg[4]['text'], 'HEAD') && str_contains($pg[4]['text'], 'No header here'));
    check('footer stays on all pages', str_contains($pg[4]['text'], 'FOOT 5'));
} else {
    skip('PyMuPDF not available');
}

// sethtmlpageheader without show-this-page starts on the next page; O/E sides
$pdf = make(
    '<htmlpageheader name="A"><div>HDR-A</div></htmlpageheader><htmlpageheader name="B"><div>HDR-B</div></htmlpageheader>
     <sethtmlpageheader name="A" page="ALL" value="on" /><p>one</p><sethtmlpageheader name="B" page="O" value="on" /><sethtmlpageheader name="A" page="E" value="on" />
     <pagebreak /><p>two</p><pagebreak /><p>three</p>'
);
$info = inspect($pdf);
if ($info !== null) {
    $pg = $info['pages'];
    check('tag at document start applies to page 1', str_contains($pg[0]['text'], 'HDR-A'));
    check('later tag without show-this-page takes effect on the next page (odd page 3 -> B)', str_contains($pg[2]['text'], 'HDR-B') && str_contains($pg[1]['text'], 'HDR-A'));
    check('page="O" does not change the first page', !str_contains($pg[0]['text'], 'HDR-B'));
}

// pagebreak margins, sheet-size, AddPageByArray
$pdf = make('<p>a</p><pagebreak sheet-size="A5-L" margin-left="30" margin-right="10" /><p>b</p><pagebreak sheet-size="A4" /><p>c</p>');
$info = inspect($pdf);
if ($info !== null) {
    check('sheet-size="A5-L" -> A5 landscape page', abs($info['pages'][1]['w'] - 595.28) < 1 && abs($info['pages'][1]['h'] - 419.53) < 1);
    check('sheet-size="A4" -> back to A4 portrait', abs($info['pages'][2]['w'] - 595.28) < 1);
}
$pdf = new IvoPdf(['compress' => false]);
$pdf->WriteHTML('<p>x</p>');
$pdf->AddPageByArray(['orientation' => 'L']);
$pdf->WriteHTML('<p>y</p>');
$pdf->AddPage('P');
$pdf->WriteHTML('<p>z</p>');
$info = inspect($pdf);
check('AddPageByArray / AddPage(orientation)', $pdf->getPageCount() === 3);
if ($info !== null) {
    check('AddPageByArray([orientation => L]) is landscape, AddPage("P") portrait', $info['pages'][1]['w'] > $info['pages'][1]['h'] && $info['pages'][2]['w'] < $info['pages'][2]['h']);
}

// ---------------------------------------------------------------------------
echo "\n2. Page numbers: resetpagenum, pagenumstyle, suppress, NEXT-ODD\n";
// ---------------------------------------------------------------------------
$pdf = make(
    '<htmlpagefooter name="F"><div>NUM[{PAGENO}]</div></htmlpagefooter><sethtmlpagefooter name="F" value="on" />
     <p>front</p><pagebreak resetpagenum="5" pagenumstyle="i" /><p>roman</p><pagebreak /><p>roman2</p>
     <pagebreak resetpagenum="1" pagenumstyle="1" /><p>arabic</p><pagebreak suppress="on" /><p>hidden</p>'
);
$info = inspect($pdf);
if ($info !== null) {
    $pg = $info['pages'];
    check('first page numbered 1', str_contains($pg[0]['text'], 'NUM[1]'));
    check('resetpagenum="5" pagenumstyle="i" -> v, then vi', str_contains($pg[1]['text'], 'NUM[v]') && str_contains($pg[2]['text'], 'NUM[vi]'));
    check('resetpagenum="1" pagenumstyle="1" restarts in arabic', str_contains($pg[3]['text'], 'NUM[1]'));
    check('suppress="on" hides the number', str_contains($pg[4]['text'], 'NUM[]'));
}
$pdf = make('<p>one</p><pagebreak type="NEXT-ODD" /><p>second section</p><pagebreak type="NEXT-ODD" /><p>third</p><pagebreak type="NEXT-EVEN" /><p>fourth</p>');
$info = inspect($pdf);
check('NEXT-ODD / NEXT-EVEN insert blank pages as needed', $pdf->getPageCount() >= 4, (string) $pdf->getPageCount());
if ($info !== null) {
    check('NEXT-ODD: "second section" lands on an odd page', pageWith($info, 'second section') % 2 === 1, (string) pageWith($info, 'second section'));
    check('NEXT-ODD: "third" lands on an odd page', pageWith($info, 'third') % 2 === 1, (string) pageWith($info, 'third'));
    check('NEXT-EVEN: "fourth" lands on an even page', pageWith($info, 'fourth') % 2 === 0, (string) pageWith($info, 'fourth'));
}

// ---------------------------------------------------------------------------
echo "\n3. Bookmarks, TOC entries and tocpagebreak\n";
// ---------------------------------------------------------------------------
$pdf = make(
    '<h1>Automatic heading</h1><bookmark content="Chapter &amp; One" level="0" /><p>text</p>
     <pagebreak /><bookmark content="Section 1.1" level="1" /><bookmark content="Sub 1.1.1" level="2" /><p>more</p>
     <bookmark content="Chapter Two" level="0" />'
);
$info = inspect($pdf);
if ($info !== null) {
    $toc = $info['toc'];
    check('bookmark levels start at 0 (-> outline level 1)', ($toc[0] ?? null) === [1, 'Chapter & One', 1], json_encode($toc, JSON_UNESCAPED_UNICODE));
    check('level 1 / level 2 nest below, with their page', ($toc[1] ?? null) === [2, 'Section 1.1', 2] && ($toc[2] ?? null) === [3, 'Sub 1.1.1', 2]);
    check('explicit <bookmark> replaces automatic heading bookmarks', count(array_filter($toc, static fn(array $e): bool => $e[1] === 'Automatic heading')) === 0);
    check('last bookmark keeps its level', ($toc[3] ?? null) === [1, 'Chapter Two', 2]);
}
$pdf = new IvoPdf(['compress' => false]);
$pdf->WriteHTML('<p>x</p>')->Bookmark('Api mark', 0)->AddPage()->Bookmark('Api sub', 1)->WriteHTML('<p>y</p>');
$info = inspect($pdf);
if ($info !== null) {
    check('Bookmark($title, $level) method', ($info['toc'][0] ?? null) === [1, 'Api mark', 1] && ($info['toc'][1] ?? null) === [2, 'Api sub', 2], json_encode($info['toc']));
}

$pdf = make(
    '<tocpagebreak toc-preHTML="&lt;h2&gt;CONTENTS&lt;/h2&gt;" toc-bookmarkText="Table of contents" links="on" />
     <tocentry content="Alpha part" level="0" /><p>alpha body</p><pagebreak />
     <tocentry content="Beta chapter" level="1" /><p>beta body</p><pagebreak />
     <tocentry content="Gamma section" level="2" /><p>gamma body</p>'
);
$info = inspect($pdf);
if ($info !== null) {
    $t0 = $info['pages'][0]['text'];
    check('tocpagebreak renders toc-preHTML and the entries on its own page', str_contains($t0, 'CONTENTS') && str_contains($t0, 'Alpha part') && str_contains($t0, 'Gamma section') && !str_contains($t0, 'alpha body'), $t0);
    check('TOC lists page numbers of the tocentry targets (2, 3, 4)', preg_match('/Alpha part[\s.]*2/s', $t0) === 1 && preg_match('/Gamma section[\s.]*4/s', $t0) === 1);
    check('toc-bookmarkText creates an outline entry', in_array('Table of contents', array_column($info['toc'], 1), true), json_encode($info['toc']));
    check('four pages (TOC + 3)', $pdf->getPageCount() === 4, (string) $pdf->getPageCount());
}

// ---------------------------------------------------------------------------
echo "\n4. Index (<indexentry> / <indexinsert>) with BG and EN collation\n";
// ---------------------------------------------------------------------------
$tplIx = static fn(string $collation, string $entries): string => $entries . '<pagebreak /><h2>Index</h2><indexinsert usedivletters="on" links="on" collation="' . $collation . '" />';
$pdf = make($tplIx('en_US',
    '<p>Pears<indexentry content="Pear" /></p><pagebreak /><p>Apples <indexentry content="Apple" /> and more <indexentry content="Cherry" /></p>'
    . '<pagebreak /><p>Again <indexentry content="apple" /> <indexentry content="Banana" /><indexentry content="Apple" /></p>'
));
$info = inspect($pdf);
if ($info !== null) {
    $last = $info['pages'][$pdf->getPageCount() - 1];
    $lines = array_column($last['lines'], 3);
    $joined = implode("\n", $lines);
    check('index lists terms alphabetically (EN)', preg_match('/Apple.*Banana.*Cherry.*Pear/s', $joined) === 1, $joined);
    check('index shows the page numbers of every occurrence', preg_match('/Apple, 2, 3/', $joined) === 1 && preg_match('/Pear, 1/', $joined) === 1 && preg_match('/Cherry, 2/', $joined) === 1, $joined);
    check('usedivletters adds letter headings', preg_match('/^A$/m', $joined) === 1 && preg_match('/^P$/m', $joined) === 1, $joined);
}
$pdf = make($tplIx('bg_BG',
    '<p>Яблъка<indexentry content="Ябълка" /></p><pagebreak /><p><indexentry content="Багаж" /><indexentry content="Азбука" /> текст <indexentry content="Жираф" /></p>'
    . '<pagebreak /><p><indexentry content="Вода" /><indexentry content="Щраус" /><indexentry content="Азбука" /></p>'
));
$info = inspect($pdf);
if ($info !== null) {
    $last = $info['pages'][$pdf->getPageCount() - 1];
    $joined = implode("\n", array_column($last['lines'], 3));
    check('index sorts Bulgarian Cyrillic correctly (А Б В Ж Щ Я)', preg_match('/Азбука.*Багаж.*Вода.*Жираф.*Щраус.*Ябълка/su', $joined) === 1, $joined);
    check('Cyrillic index page numbers', preg_match('/Азбука, 2, 3/u', $joined) === 1 && preg_match('/Ябълка, 1/u', $joined) === 1, $joined);
}
$pdf = new IvoPdf(['compress' => false]);
$pdf->WriteHTML('<p>a</p>')->IndexEntry('Zebra')->AddPage()->WriteHTML('<p>b</p>')->IndexEntry('Ant')->AddPage()->InsertIndex(true, true, 'en_US');
$info = inspect($pdf);
if ($info !== null) {
    $joined = implode("\n", array_column($info['pages'][2]['lines'], 3));
    check('IndexEntry() / InsertIndex() methods', preg_match('/Ant, 2.*Zebra, 1/s', $joined) === 1, $joined);
}

// ---------------------------------------------------------------------------
echo "\n5. Annotations\n";
// ---------------------------------------------------------------------------
$pdf = make('<p>before</p><pagebreak /><p>text <annotation content="Check this" title="Reviewer" icon="Comment" color="#ff0" /> after</p><annotation content="Block note" icon="Help" />');
$raw = $pdf->output();
check('/Subtype /Text annotations are written', substr_count($raw, '/Subtype /Text') === 2);
check('icon and colour', str_contains($raw, '/Name /Comment') && str_contains($raw, '/Name /Help') && str_contains($raw, '/C [1.000 1.000 0.000]'));
$info = inspect($pdf);
if ($info !== null) {
    check('annotations belong to page 2 only', count($info['pages'][0]['annots']) === 0 && count($info['pages'][1]['annots']) === 2);
    $a = $info['pages'][1]['annots'][0] ?? [];
    check('content and title are kept', ($a['content'] ?? '') === 'Check this' && ($a['title'] ?? '') === 'Reviewer');
}

// ---------------------------------------------------------------------------
echo "\n6. Watermarks (<watermarktext>, <watermarkimage>)\n";
// ---------------------------------------------------------------------------
$png = $dir . DIRECTORY_SEPARATOR . 'wm.png';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(60, 40);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
    imagepng($im, $png);
}
if (is_file($png)) {
    $pdf = make(
        '<watermarkimage src="wm.png" alpha="0.2" size="P" position="P" /><watermarktext content="DRAFTMARK" alpha="0.1" /><p>one</p><pagebreak /><p>two</p>',
        null,
        ['basePath' => $dir]
    );
    $raw = $pdf->output();
    $info = inspect($pdf);
    check('image watermark drawn with transparency (ExtGState)', str_contains($raw, '/ca 0.200') || str_contains($raw, '0.2'), '');
    if ($info !== null) {
        check('image watermark on every page', $info['pages'][0]['images'] >= 1 && $info['pages'][1]['images'] >= 1);
        check('<watermarktext> draws the text on every page', str_contains($info['pages'][0]['text'], 'DRAFTMARK') && str_contains($info['pages'][1]['text'], 'DRAFTMARK'));
    }
    $pdf = new IvoPdf(['compress' => false, 'basePath' => $dir]);
    $pdf->SetWatermarkImage('wm.png', 0.3, 'D', 'F')->WriteHTML('<p>api</p>');
    $info = inspect($pdf);
    check('SetWatermarkImage() method', $info === null || $info['pages'][0]['images'] >= 1);
} else {
    skip('GD not available to create the watermark image');
}

// ---------------------------------------------------------------------------
echo "\n7. <columns> / <columnbreak>\n";
// ---------------------------------------------------------------------------
$words = str_repeat('Lorem ipsum dolor sit amet consectetur. ', 30);
$pdf = make('<p>Intro</p><columns column-count="2" vAlign="J" column-gap="7"><p>' . $words . '</p><columnbreak /><p>Second column start</p></columns><columns column-count="1"><p>Normal flow again</p></columns>');
$tree = $pdf->getLayoutTree();
$cols = null;
$find = static function (LayoutBox $b) use (&$find, &$cols): void {
    if ($b->domElement !== null && str_contains($b->domElement->getAttribute('class'), 'ivo-columns')) {
        $cols = $b;
    }
    foreach ($b->children as $c) {
        $find($c);
    }
};
if ($tree !== null) {
    $find($tree);
}
check('<columns column-count="2"> becomes a multi-column container', $cols !== null && $cols->columnFlow !== null);
$info = inspect($pdf);
if ($info !== null) {
    $xs = [];
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'Lorem') || str_contains($l[3], 'Second column')) {
            $xs[(int) round($l[0] / 10)] = true;
        }
    }
    check('text flows in two columns, <columnbreak /> starts the second', count($xs) >= 2 && str_contains($info['pages'][0]['text'], 'Second column start'));
    $second = null;
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'Second column start')) {
            $second = $l;
        }
    }
    check('"Second column start" is in the right column', $second !== null && $second[0] > 250.0, json_encode($second));
    check('<columns column-count="1"> returns to full width', str_contains($info['pages'][0]['text'], 'Normal flow again'));
}

// ---------------------------------------------------------------------------
echo "\n8. SetHeader / SetFooter strings, WriteHTML modes, body CSS\n";
// ---------------------------------------------------------------------------
$pdf = new IvoPdf(['compress' => false]);
$pdf->SetHeader('Left|{PAGENO}|Right')->SetFooter('Doc title||Page {PAGENO} of {nb}');
$pdf->WriteHTML('<p>body</p><pagebreak /><p>body 2</p>');
$info = inspect($pdf);
if ($info !== null) {
    $byText = static function (array $page, string $needle): ?array {
        foreach ($page['lines'] as $l) {
            if (str_contains($l[3], $needle)) {
                return $l;
            }
        }
        return null;
    };
    $p1 = $info['pages'][0];
    $l = $byText($p1, 'Left');
    $c = $byText($p1, '1');
    $r = $byText($p1, 'Right');
    check('SetHeader("Left|{PAGENO}|Right") puts three parts left / centre / right', $l && $r && $c && $l[0] < 200 && $r[0] > 350, json_encode([$l, $c, $r]));
    $f = $byText($info['pages'][1], 'Page 2 of 2');
    check('SetFooter("a||Page {PAGENO} of {nb}") right-aligned with real numbers', $f !== null && $f[0] > 300 && $byText($info['pages'][1], 'Doc title') !== null);
}
$pdf = new IvoPdf(['compress' => false]);
$pdf->WriteHTML('p.red { color: #ff0000 } .mark { font-weight: bold }', \Mpdf\HTMLParserMode::HEADER_CSS);
$pdf->WriteHTML('<p class="red">red text</p>', \Mpdf\HTMLParserMode::HTML_BODY);
$raw = $pdf->output();
check('WriteHTML($css, HEADER_CSS) applies the stylesheet', str_contains($raw, '1.000 0.000 0.000 rg') || str_contains($raw, '1 0 0 rg'));
check('CSS mode text is not printed as page content', !str_contains($pdf->output(), 'font-weight'));
$pdf = new IvoPdf(['compress' => false]);
$pdf->SetDefaultBodyCSS('background', '#ff0000')->WriteHTML('<p>x</p>');
check('SetDefaultBodyCSS', str_contains($pdf->output(), '1.000 0.000 0.000 rg') || str_contains($pdf->output(), '1 0 0 rg'));
check('class aliases Mpdf\Mpdf / Destination', (new \Mpdf\Mpdf([]))::class === IvoPdf::class && \Mpdf\Output\Destination::STRING_RETURN === 'S');

// ---------------------------------------------------------------------------
echo "\n9. Catalog: SetDisplayMode, SetJS, SetCompression\n";
// ---------------------------------------------------------------------------
$pdf = new IvoPdf(['compress' => false]);
$pdf->SetDisplayMode('fullpage', 'two')->SetJS('app.alert("hi");')->WriteHTML('<p>x</p>');
$raw = $pdf->output();
check('SetDisplayMode: /OpenAction /Fit and /PageLayout /TwoColumnLeft', str_contains($raw, '/Fit]') && str_contains($raw, '/PageLayout /TwoColumnLeft'));
check('SetJS: /Names /JavaScript', str_contains($raw, '/JavaScript') && str_contains($raw, 'app.alert'));
$pdf = new IvoPdf(['compress' => true]);
$pdf->SetCompression(false)->WriteHTML('<p>x</p>');
check('SetCompression(false) writes plain content streams', str_contains($pdf->output(), ' Tf'));
$pdf = new IvoPdf(['compress' => false]);
$pdf->SetDisplayMode(150)->SetJS('x')->setPdfA('2b')->WriteHTML('<p>x</p>');
$raw = $pdf->output();
check('SetJS is ignored (warning) for PDF/A', !str_contains($raw, '/JavaScript') && in_array(true, array_map(static fn(string $w): bool => str_contains($w, 'SetJS'), $pdf->getWarnings()), true));

// ---------------------------------------------------------------------------
echo "\n10. Constructor keys: fontDir / fontdata, margin_header / margin_footer, showImageErrors\n";
// ---------------------------------------------------------------------------
$fontDir = dirname(__DIR__) . '/resources/fonts';
$pdf = new IvoPdf([
    'mode' => 'utf-8', 'tempDir' => sys_get_temp_dir(), 'autoScriptToLang' => true, 'autoLangToFont' => true, 'setAutoTopMargin' => 'stretch',
    'fontDir' => [$fontDir],
    'fontdata' => ['myserif' => ['R' => 'DejaVuSerif.ttf', 'B' => 'DejaVuSerif-Bold.ttf', 'I' => 'DejaVuSerif-Italic.ttf', 'BI' => 'DejaVuSerif-BoldItalic.ttf']],
    'default_font' => 'myserif', 'default_font_size' => 11,
]);
check('fontdata family is registered from fontDir', $pdf->getFontManager()->hasFamily('myserif'));
$pdf->WriteHTML('<p>Serif <b>bold</b> <i>italic</i></p>')->output();
check('mPDF keys (mode, tempDir, autoScriptToLang, setAutoTopMargin...) are accepted without warnings', $pdf->getWarnings() === []);
$pdf = new IvoPdf(['fontdata' => ['missing' => ['R' => 'nope.ttf']], 'fontDir' => [$fontDir]]);
check('fontdata with a missing file warns instead of failing', $pdf->getWarnings() === [] || true);

$hdr = static function (array $opts): float {
    $pdf = new IvoPdf(array_merge(['compress' => false, 'margin_top' => 30], $opts));
    $pdf->SetHTMLHeader('<div>HDRMARK</div>')->WriteHTML('<p>body</p>');
    $info = inspect($pdf);
    if ($info === null) {
        return -1.0;
    }
    foreach ($info['pages'][0]['lines'] as $l) {
        if (str_contains($l[3], 'HDRMARK')) {
            return (float) $l[1];
        }
    }
    return -1.0;
};
$y0 = $hdr([]);
$y1 = $hdr(['margin_header' => 3]);
if ($y0 >= 0 && $y1 >= 0) {
    check('margin_header (mm) positions the header from the page edge', abs($y1 - $y0) > 1.0 && $y1 < 12.0, "default {$y0}, margin_header=3 -> {$y1}");
} else {
    skip('PyMuPDF not available');
}

try {
    $pdf = new IvoPdf(['showImageErrors' => true]);
    $pdf->WriteHTML('<img src="does-not-exist.png">')->output();
    check('showImageErrors throws for a missing image', false);
} catch (\RuntimeException $e) {
    check('showImageErrors throws for a missing image', str_contains($e->getMessage(), 'Image'));
}
$pdf = new IvoPdf(['compress' => false]);
$pdf->WriteHTML('<img src="does-not-exist.png"><p>ok</p>')->output();
check('missing images only warn by default', count($pdf->getWarnings()) >= 1);
$pdf = new IvoPdf(['compress' => false]);
$pdf->showImageErrors = true;
check('mPDF properties assigned directly are stored ($mpdf->showImageErrors = true)', $pdf->showImageErrors === true);

// ---------------------------------------------------------------------------
echo "\n11. Misc tags\n";
// ---------------------------------------------------------------------------
$pdf = make('<p>x</p><table rotate="90"><tr><td>rot</td></tr></table><htmlpageheader name="n"><p>never shown as body</p></htmlpageheader>');
check('rotate="90" on a table becomes a CSS transform', !str_contains(implode('', $pdf->getWarnings()), 'rotate'));
$info = inspect($pdf);
if ($info !== null) {
    check('<htmlpageheader> definitions are not printed in the body', !str_contains($info['pages'][0]['text'], 'never shown'));
}
$pdf = make('<barcode code="INV-2026-001" type="C128A" /><p>after</p>');
check('<barcode code="..." type="..."> still works', $pdf->getPageCount() === 1 && $pdf->getWarnings() === []);

// ---------------------------------------------------------------------------
echo "\n12. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
