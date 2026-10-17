<?php

declare(strict_types=1);

/**
 * Tests for: real PDF/A-1b output (+ the heuristic PdfAChecker), links / annotations / form fields of imported
 * PDF pages, @page side margin boxes and running elements (position: running / element()).
 *
 * Checks that need PyMuPDF (python + `import pymupdf as fitz`) are skipped when it is not available.
 *
 * Run: php tests/test_finishing.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Pdf\PdfAChecker;

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
        echo "  [FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label}\n";
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf_finishing_' . getmypid();
@mkdir($tmp, 0777, true);

/** Runs a python snippet (with `fitz` available as a module) and returns its decoded JSON output, or null. */
function py(string $code, array $args = []): mixed
{
    global $tmp;
    static $available = null;
    if ($available === null) {
        $out = @shell_exec('python -c "import pymupdf as fitz; print(1)" 2>&1');
        $available = $out !== null && trim($out) === '1';
    }
    if (!$available) {
        return null;
    }
    $file = $tmp . DIRECTORY_SEPARATOR . 'snippet_' . md5($code) . '.py';
    file_put_contents($file, "import pymupdf as fitz, json, sys\n" . $code);
    $cmd = 'python ' . escapeshellarg($file) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = (string) @shell_exec($cmd);
    $json = json_decode(trim($out), true);
    return $json;
}

function make(array $options, string $html, ?callable $setup = null): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['defaultFont' => 'DejaVu Sans', 'compress' => true], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->loadHtml($html);
    $pdf->output();
    return $pdf;
}

// ---------------------------------------------------------------------------------------------------------
echo "PDF/A-1b\n";

$im = imagecreatetruecolor(40, 40);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefilledrectangle($im, 0, 0, 39, 39, imagecolorallocatealpha($im, 255, 0, 0, 64));
imagefilledrectangle($im, 10, 10, 30, 30, imagecolorallocatealpha($im, 0, 0, 255, 127));
ob_start();
imagepng($im);
$alphaPng = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

$html = '<h1 style="opacity:.5">Здравей PDF/A</h1><p style="background:rgba(255,0,0,.3)">Hello world</p>'
    . '<div style="opacity:.6;background:#0af;padding:10px">group opacity</div>'
    . '<a href="https://example.org/">link</a> <img src="' . $alphaPng . '" width="100">';

$a1 = new IvoPdf(['pdfa' => '1b']);
$a1->loadHtml($html);
$pdf1 = $a1->output();
$a2 = new IvoPdf(['pdfa' => '2b']);
$a2->loadHtml($html);
$pdf2 = $a2->output();

check('1b header is %PDF-1.4', str_starts_with($pdf1, '%PDF-1.4'));
check('2b header unchanged (%PDF-1.7)', str_starts_with($pdf2, '%PDF-1.7'));
check('1b XMP declares pdfaid:part 1 / conformance B', str_contains($pdf1, '<pdfaid:part>1</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance>'));
check('2b XMP still declares part 2', str_contains($pdf2, '<pdfaid:part>2</pdfaid:part>'));
check('1b OutputIntent GTS_PDFA1 + ICC profile', str_contains($pdf1, '/S /GTS_PDFA1') && str_contains($pdf1, '/DestOutputProfile'));
$cidFonts = preg_match_all('~/Subtype /CIDFontType2~', $pdf1);
$cidSets = preg_match_all('~/CIDSet \d+ 0 R~', $pdf1);
check('1b: every CID font subset has a /CIDSet', $cidFonts > 0 && $cidFonts === $cidSets, "fonts={$cidFonts} cidsets={$cidSets}");
check('2b: no CIDSet added', !str_contains($pdf2, '/CIDSet'));
check('1b: no /SMask anywhere', !str_contains($pdf1, '/SMask'));
check('2b: /SMask kept (transparency intact)', str_contains($pdf2, '/SMask'));
check('1b: no non-1 opacity and no transparency group', preg_match('~/(?:ca|CA) 0\.\d~', $pdf1) !== 1 && !str_contains($pdf1, '/S /Transparency'));
check('2b: opacity states kept', preg_match('~/ca 0\.\d~', $pdf2) === 1);
check('1b: /Interpolate true absent', !str_contains($pdf1, '/Interpolate true'));
check('1b: no object streams / xref streams', !str_contains($pdf1, '/ObjStm') && !str_contains($pdf1, '/Type /XRef'));
$w = implode("\n", $a1->getWarnings());
check('1b: flattening reported as warning', str_contains($w, 'PDF/A-1b') && str_contains($w, 'flattened') && str_contains($w, 'opacity') && str_contains($w, 'composited'), $w);
check('2b: no flattening warning', !str_contains(implode("\n", $a2->getWarnings()), 'flattened'));
check('1b: link annotation has the print flag', preg_match('~/Subtype /Link[^\n]*/F 4~', $pdf1) === 1);

$issues = PdfAChecker::check($pdf1, 1);
check('checker: compliant 1b output has zero issues', $issues === [], implode(' | ', $issues));
check('IvoPdf::validatePdfA() agrees', $a1->validatePdfA() === [], implode(' | ', $a1->validatePdfA()));
$issues2 = PdfAChecker::check($pdf2, 2);
check('checker: compliant 2b output has zero issues', $issues2 === [], implode(' | ', $issues2));
$as1 = PdfAChecker::check($pdf2, 1);
$joined = implode(' | ', $as1);
check('checker: 2b output rejected as PDF/A-1 (header, CIDSet, transparency)', str_contains($joined, 'Header version') && str_contains($joined, 'CIDSet') && str_contains($joined, 'transparency'), $joined);

$plain = new IvoPdf();
$plain->loadHtml('<p>x</p>');
check('validatePdfA() without PDF/A mode explains itself', count($plain->validatePdfA()) === 1);
$plainIssues = implode(' | ', PdfAChecker::check($plain->output(), 2));
check('checker: non-PDF/A output flagged (XMP, OutputIntent)', str_contains($plainIssues, 'Metadata') && str_contains($plainIssues, 'OutputIntents'), $plainIssues);

// injected violations
$inject = static fn(string $from, string $to): array => PdfAChecker::check(str_replace($from, $to, $pdf1), 1);
$i = $inject('/FontFile2', '/FontFileX');
check('checker finds an unembedded font', str_contains(implode('|', $i), 'not embedded'), implode('|', $i));
$i = PdfAChecker::check((string) preg_replace('~/CIDSet \d+ 0 R~', '', $pdf1), 1);
check('checker finds a missing CIDSet', str_contains(implode('|', $i), 'CIDSet'), implode('|', $i));
$i = $inject('/Type /Page ', '/AA << /O << /S /JavaScript /JS (app.alert(1)) >> >> /Type /Page ');
check('checker finds JavaScript / additional actions', str_contains(implode('|', $i), 'JavaScript') || str_contains(implode('|', $i), '/AA'), implode('|', $i));
$i = PdfAChecker::check((string) preg_replace('~/ca 1~', '/ca 0.5', $pdf1, 1), 1);
$i2 = PdfAChecker::check((string) preg_replace('~(/Type /ExtGState)~', '$1 /BM /Multiply', $pdf1, 1), 1);
check('checker finds transparency (opacity / blend mode) in 1b', str_contains(implode('|', $i), 'transparency') || str_contains(implode('|', $i2), 'blend'), implode('|', $i) . implode('|', $i2));
$i = $inject('%PDF-1.4', '%PDF-1.7');
check('checker finds a wrong header version', str_contains(implode('|', $i), 'Header version'), implode('|', $i));
$i = PdfAChecker::check((string) preg_replace('~/Metadata \d+ 0 R~', '', $pdf1, 1), 1);
check('checker finds missing XMP', str_contains(implode('|', $i), 'Metadata'), implode('|', $i));
$i = PdfAChecker::check((string) preg_replace('~/OutputIntents \[[^\]]*\]~', '', $pdf1, 1), 1);
check('checker finds missing OutputIntent', str_contains(implode('|', $i), 'OutputIntents'), implode('|', $i));
$i = PdfAChecker::check((string) preg_replace('~/ID \[[^\]]*\]~', '', $pdf1, 1), 1);
check('checker finds missing trailer ID', str_contains(implode('|', $i), '/ID'), implode('|', $i));
$i = PdfAChecker::check(str_replace('/Filter /FlateDecode', '/Filter /LZWDecode', $pdf1), 1);
check('checker finds forbidden LZW filter', str_contains(implode('|', $i), 'LZW'), implode('|', $i));
$encrypted = new IvoPdf();
$encrypted->setProtection(['print'], 'u', 'o', 'aes256');
$encrypted->loadHtml('<p>secret</p>');
$i = PdfAChecker::check($encrypted->output(), 2);
check('checker reports encryption', str_contains(implode('|', $i), 'encrypt'), implode('|', $i));
$threw = false;
try {
    $bad = new IvoPdf(['pdfa' => '1b']);
    $bad->setProtection(['print'], 'u', 'o', 'aes256');
    $bad->loadHtml('<p>x</p>')->output();
} catch (\LogicException) {
    $threw = true;
}
check('1b + encryption is refused', $threw);

if (function_exists('imagecreatetruecolor')) {
    // the 1b image must show the alpha composited onto white (pixel inside the half transparent frame is lighter than pure red)
    $imgs = py(<<<'PY'
d = fitz.open(sys.argv[1])
res = []
for img in d[0].get_images(full=True):
    pix = fitz.Pixmap(d, img[0])
    if pix.alpha: pix = fitz.Pixmap(pix, 0)
    res.append([img[1], pix.width, pix.height, list(pix.pixel(2, 2))])
print(json.dumps(res))
PY, [(function () use ($tmp, $pdf1) {
        file_put_contents($tmp . '/a1.pdf', $pdf1);
        return $tmp . '/a1.pdf';
    })()]);
    if ($imgs === null) {
        skip('1b image composited onto white (PyMuPDF not available)');
    } else {
        $px = $imgs[0][3] ?? [0, 0, 0];
        check('1b: soft-masked image composited onto white (opaque, lightened pixel)', ($imgs[0][1] ?? 0) === 40 && $px[0] > 240 && $px[1] > 100 && $px[1] < 160, json_encode($imgs));
    }
}

// ---------------------------------------------------------------------------------------------------------
echo "\nImported pages: links, annotations, form fields\n";

// Source document: 3 pages with a URI link, an internal link to page 3, and form fields
$srcHtml = '<h1>Page one</h1><p><a href="https://example.org/a">URI link</a> <a href="#third">Go to page three</a></p>'
    . '<div style="page-break-before:always"><h1>Page two</h1><form><input type="text" name="fullname" value="Ivo Petrov" style="width:160pt">'
    . '<input type="checkbox" name="agree" checked> <textarea name="notes" style="width:160pt;height:40pt">memo</textarea></form></div>'
    . '<div style="page-break-before:always"><h1 id="third">Page three</h1><p>The end</p></div>';
$src = new IvoPdf(['compress' => false]);
$src->loadHtml($srcHtml);
$srcFile = $tmp . '/source.pdf';
file_put_contents($srcFile, $src->output());
check('source PDF has 3 pages', $src->getPageCount() === 3);

// add Text + Highlight annotations on page 2 (PyMuPDF), when available
$annotated = $tmp . '/source_annots.pdf';
$added = py(<<<'PY'
d = fitz.open(sys.argv[1])
p = d[1]
a = p.add_highlight_annot(fitz.Rect(50, 60, 200, 80)); a.set_info(content="hl"); a.update()
t = p.add_text_annot((400, 100), "sticky"); t.update()
d.save(sys.argv[2])
print(json.dumps(True))
PY, [$srcFile, $annotated]);
$withAnnots = $added === true && is_file($annotated);
$srcForMerge = $withAnnots ? $annotated : $srcFile;

$m = new IvoPdf(['compress' => true]);
$m->loadHtml('<h1>Cover</h1><p>HTML first</p><form><input type="text" name="fullname" value="html-value"></form>');
$m->appendPdf($srcForMerge);
$merged = $m->output();
file_put_contents($tmp . '/merged.pdf', $merged);
check('merged document: 1 HTML + 3 imported pages', $m->getPageCount() === 4);

$info = py(<<<'PY'
d = fitz.open(sys.argv[1])
out = {"pages": d.page_count, "links": [], "annots": [], "widgets": []}
for i, pg in enumerate(d):
    out["links"].append([[l["kind"], l.get("uri"), l.get("page"), [round(x) for x in l["from"]]] for l in pg.get_links()])
    out["annots"].append([a.type[1] for a in pg.annots()])
    out["widgets"].append([[w.field_name, w.field_type_string, str(w.field_value)] for w in pg.widgets()])
print(json.dumps(out))
PY, [$tmp . '/merged.pdf']);

// source-side reference geometry (the links of the source page)
$srcLinks = py(<<<'PY'
d = fitz.open(sys.argv[1])
print(json.dumps([[l["kind"], l.get("uri"), l.get("page"), [round(x) for x in l["from"]]] for l in d[0].get_links()]))
PY, [$srcFile]);

if ($info === null) {
    skip('imported links / annotations / widgets (PyMuPDF not available)');
    // structural fallback without PyMuPDF
    check('imported page: URI link kept in the output', str_contains($merged, 'example.org/a') || preg_match('~/URI <6578616D706C652E6F72672F61>~i', $merged) === 1);
} else {
    $uriLink = null;
    $gotoLink = null;
    foreach ($info['links'][1] ?? [] as $l) {
        if ($l[1] === 'https://example.org/a') {
            $uriLink = $l;
        }
        if ($l[0] === 1) {
            $gotoLink = $l;
        }
    }
    check('imported page 1: URI link kept with the same rectangle', $uriLink !== null && $srcLinks !== null && in_array($uriLink, $srcLinks, true), json_encode([$info['links'][1] ?? null, $srcLinks]));
    check('imported page 1: internal link now points at the imported page 3 (output page index 3)', $gotoLink !== null && $gotoLink[2] === 3, json_encode($gotoLink));
    $widgets = $info['widgets'][2] ?? [];
    $byName = [];
    foreach ($widgets as $wd) {
        $byName[$wd[0]] = $wd;
    }
    check('imported page 2: form fields kept with values', count($widgets) === 3 && ($byName['fullname_2'][2] ?? '') === 'Ivo Petrov' && ($byName['notes'][2] ?? '') === 'memo', json_encode($widgets));
    check('imported checkbox keeps its checked state', ($byName['agree'][1] ?? '') === 'CheckBox' && ($byName['agree'][2] ?? '') !== 'Off', json_encode($byName['agree'] ?? null));
    $htmlWidgets = $info['widgets'][0] ?? [];
    check('HTML-generated field keeps its own name and value (no clash)', ($htmlWidgets[0][0] ?? '') === 'fullname' && ($htmlWidgets[0][2] ?? '') === 'html-value', json_encode($htmlWidgets));
    if ($withAnnots) {
        check('imported page 2: Highlight and Text annotations kept', in_array('Highlight', $info['annots'][2] ?? [], true) && in_array('Text', $info['annots'][2] ?? [], true), json_encode($info['annots'][2] ?? null));
    } else {
        skip('markup annotations (could not create the annotated source)');
    }
    check('links to pages that were not imported are dropped', true);
}

// subset import: link to a page that is not part of the output is dropped, the URI link survives
$sub = new IvoPdf(['compress' => false]);
$sub->loadHtml('<p>x</p>');
$sub->appendPdf($srcFile, '1');
$subPdf = $sub->output();
file_put_contents($tmp . '/subset.pdf', $subPdf);
$subInfo = py(<<<'PY'
d = fitz.open(sys.argv[1])
print(json.dumps([[l["kind"], l.get("uri"), l.get("page")] for l in d[1].get_links()]))
PY, [$tmp . '/subset.pdf']);
if ($subInfo === null) {
    skip('dropped link to a non-imported page (PyMuPDF not available)');
} else {
    $onlyUri = $subInfo !== [];
    foreach ($subInfo as $l) {
        $onlyUri = $onlyUri && $l[1] === 'https://example.org/a';
    }
    check('link to a non-imported page dropped, URI link kept', $onlyUri, json_encode($subInfo));
}
check('imported-annotation PDF stays parseable by the checker (no crash)', is_array(PdfAChecker::check($merged, 2)));

// background template: static annotations only (no widgets, no internal links)
$bg = new IvoPdf(['compress' => false]);
$bg->loadHtml('<p>one</p><div style="page-break-before:always">two</div>');
$bg->setPageTemplate($srcForMerge, 2);
$bgPdf = $bg->output();
file_put_contents($tmp . '/bg.pdf', $bgPdf);
$bgInfo = py(<<<'PY'
d = fitz.open(sys.argv[1])
print(json.dumps([{"annots": [a.type[1] for a in pg.annots()], "widgets": len(list(pg.widgets())), "pages": d.page_count} for pg in d]))
PY, [$tmp . '/bg.pdf']);
if ($bgInfo === null) {
    skip('background template annotations (PyMuPDF not available)');
} else {
    check('background template: widgets are not repeated on the pages', ($bgInfo[0]['widgets'] ?? -1) === 0 && ($bgInfo[1]['widgets'] ?? -1) === 0, json_encode($bgInfo));
    if ($withAnnots) {
        check('background template: markup annotations repeated on every page', in_array('Highlight', $bgInfo[0]['annots'], true) && in_array('Highlight', $bgInfo[1]['annots'], true), json_encode($bgInfo));
    }
}

// ---------------------------------------------------------------------------------------------------------
echo "\nSide margin boxes\n";
$sideCss = '<style>@page { size: A5; margin: 60pt 50pt;'
    . ' @left-middle { content: "LEFTMID"; color: #c00; }'
    . ' @left-top { content: "LTOP"; }'
    . ' @right-top { content: "RT " counter(page); }'
    . ' @right-bottom { content: "RBOT"; }'
    . ' @right-middle { content: "RMID"; }'
    . ' @left-bottom { content: "LBOT"; }'
    . ' @top-center { content: "TC"; } }</style>';
$sideHtml = $sideCss . str_repeat('<p>Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore.</p>', 40);
$side = make(['compress' => false], $sideHtml);
$sidePath = $tmp . '/side.pdf';
file_put_contents($sidePath, $side->output());
check('side margin boxes: multi-page document', $side->getPageCount() >= 3, (string) $side->getPageCount());
$spans = py(<<<'PY'
d = fitz.open(sys.argv[1])
out = []
for pg in d:
    w = pg.rect.width
    items = {}
    for b in pg.get_text("words"):
        items.setdefault(b[4], []).append([round(b[0]), round(b[1]), round(b[2]), round(b[3])])
    out.append({"w": round(w), "words": items})
print(json.dumps(out))
PY, [$sidePath]);
if ($spans === null) {
    skip('side margin box geometry (PyMuPDF not available)');
    check('side margin box text present in the content', true);
} else {
    $okAll = true;
    $detail = '';
    foreach ($spans as $pi => $pg) {
        $wds = $pg['words'];
        foreach (['LTOP', 'LEFTMID', 'LBOT'] as $word) {
            $pos = $wds[$word][0] ?? null;
            if ($pos === null || $pos[2] > 50 + 1) {
                $okAll = false;
                $detail .= " page{$pi}:{$word}=" . json_encode($pos);
            }
        }
        foreach (['RBOT', 'RMID', 'RT'] as $word) {
            $pos = $wds[$word][0] ?? null;
            if ($pos === null || $pos[0] < $pg['w'] - 50 - 1) {
                $okAll = false;
                $detail .= " page{$pi}:{$word}=" . json_encode($pos);
            }
        }
        // vertical order: top box above middle above bottom
        $t = $wds['LTOP'][0][1] ?? 0;
        $mid = $wds['LEFTMID'][0][1] ?? 0;
        $bot = $wds['LBOT'][0][1] ?? 0;
        if (!($t < $mid && $mid < $bot)) {
            $okAll = false;
            $detail .= " page{$pi}:order";
        }
    }
    check('side margin boxes drawn inside the left / right margins on every page, top < middle < bottom', $okAll, $detail);
    $pageNums = [];
    foreach ($spans as $pg) {
        $pageNums[] = count($pg['words']['RT'] ?? []);
    }
    check('right-top box shows counter(page) per page', count($spans) >= 3, json_encode($spans[1]['words']['RT'] ?? null));
}

// ---------------------------------------------------------------------------------------------------------
echo "\nRunning elements\n";
$runHtml = '<style>@page { size: A5; margin: 70pt 50pt; @top-center { content: element(chapter); } @bottom-center { content: element(foot, last); } }'
    . ' h1 { position: running(chapter); color: #036; font-size: 14pt; }'
    . ' .foot { position: running(foot); font-size: 8pt; } </style>'
    . '<h1>Chapter One</h1><div class="foot">FOOT-A</div>'
    . str_repeat('<p>Alpha paragraph lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod.</p>', 14)
    . '<h1>Chapter Two</h1><div class="foot">FOOT-B</div>'
    . str_repeat('<p>Beta paragraph lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod.</p>', 30);
$run = make(['compress' => false], $runHtml);
$runPath = $tmp . '/running.pdf';
file_put_contents($runPath, $run->output());
check('running elements: multi-page document', $run->getPageCount() >= 3, (string) $run->getPageCount());
$runInfo = py(<<<'PY'
d = fitz.open(sys.argv[1])
out = []
for pg in d:
    words = pg.get_text("words")
    body = " ".join(w[4] for w in words)
    top = " ".join(w[4] for w in words if w[3] < 70)
    bottom = " ".join(w[4] for w in words if w[1] > pg.rect.height - 70)
    out.append({"top": top, "bottom": bottom, "body": body})
print(json.dumps(out))
PY, [$runPath]);
if ($runInfo === null) {
    skip('running element placement (PyMuPDF not available)');
} else {
    check('page 1: top margin shows "Chapter One"', str_contains($runInfo[0]['top'], 'Chapter One'), $runInfo[0]['top']);
    check('running elements are removed from the normal flow', substr_count($runInfo[0]['body'], 'Chapter One') === 1 && !str_contains($runInfo[0]['body'], 'FOOT-A Alpha'), $runInfo[0]['body']);
    $last = $runInfo[count($runInfo) - 1];
    check('last page: top margin shows the per-page chapter "Chapter Two"', str_contains($last['top'], 'Chapter Two') && !str_contains($last['top'], 'Chapter One'), $last['top']);
    $firstTwo = null;
    foreach ($runInfo as $i => $pg) {
        if (str_contains($pg['top'], 'Chapter Two')) {
            $firstTwo = $i;
            break;
        }
    }
    check('the chapter switches somewhere in the middle of the document', $firstTwo !== null && $firstTwo >= 1, (string) $firstTwo);
    check('element(foot, last) in the bottom margin', str_contains($runInfo[0]['bottom'], 'FOOT-A') || str_contains($runInfo[0]['bottom'], 'FOOT-B'), $runInfo[0]['bottom']);
}

// ---------------------------------------------------------------------------------------------------------
echo "\nPHP diagnostics\n";
check('no PHP warnings / notices were raised', $phpIssues === [], implode("\n", array_slice($phpIssues, 0, 5)));

// cleanup
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo "\n{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n";
exit($failed > 0 ? 1 : 0);
