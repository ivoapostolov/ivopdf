<?php

declare(strict_types=1);

/**
 * Regression tests for the "fable" audit findings of the PDF / security / API-compatibility area:
 *  - H5  RC4 / AES-128 passwords are converted to PDFDocEncoding (ISO 32000-1 Algorithm 2); unrepresentable ones are refused
 *  - M1  PDF/A: <annotation> gets an appearance stream; validatePdfA() agrees with the output (forms, NeedAppearances)
 *  - M2  UNC / protocol-relative / stream-wrapper paths never reach the filesystem
 *  - M4  dompdf API: Canvas::rectangle() and the other missing methods
 *  - M5  mPDF API: imperative methods (Image, WriteFixedPosHTML, Cell, DeletePages, ...) and the __call fallback
 *  - L2  registerFontFamily() with a missing file throws; missing bold / italic faces are synthesized
 *  - L4  characters without a font keep their code points; text-shadow copies are not extracted twice
 *
 * Run: php tests/test_audit_fable_c.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Options;
use IvoPdf\Pdf\PdfEncryption;

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

/** @return mixed|null result of a small PyMuPDF script (JSON printed by it), null when Python / PyMuPDF is unavailable */
function py(string $pdfBytes, string $body, string ...$args): mixed
{
    static $ok = null;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    if ($ok === null) {
        $probe = @shell_exec('python -c "import pymupdf as fitz; print(1)" 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        $ok = is_string($probe) && trim($probe) === '1';
    }
    if (!$ok) {
        return null;
    }
    $file = $dir . 'ivopdf-fc-' . md5(uniqid('', true)) . '.pdf';
    $script = $dir . 'ivopdf-fc-' . md5($body) . '.py';
    file_put_contents($file, $pdfBytes);
    file_put_contents($script, "import pymupdf as fitz, json, sys\nd = fitz.open(sys.argv[1])\nargs = [bytes.fromhex(a).decode() for a in sys.argv[2:]]\n" . $body . "\n");
    $cmd = 'python -X utf8 ' . escapeshellarg($script) . ' ' . escapeshellarg($file);
    foreach ($args as $a) {
        $cmd .= ' ' . bin2hex($a);
    }
    $out = @shell_exec($cmd . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    return is_string($out) && $out !== '' ? json_decode(trim($out), true) : null;
}

function pdfOf(string $html, array $options = []): string
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    return $pdf->loadHtml($html)->output();
}

$extract = "print(json.dumps([p.get_text() for p in d]))";

// ---------------------------------------------------------------------------
echo "1. H5: legacy (RC4 / AES-128) passwords in PDFDocEncoding\n";
// ---------------------------------------------------------------------------
check('Latin-1 letters map to their PDFDocEncoding byte', PdfEncryption::legacyPasswordBytes('ümlaut') === "\xFCmlaut");
check('euro / per-mille / ligature map to the PDFDocEncoding specials', PdfEncryption::legacyPasswordBytes('€‰ﬁ') === "\xA0\x8B\x93");
check('ASCII and already-encoded byte strings are unchanged', PdfEncryption::legacyPasswordBytes("tab\tnl") === "tab\tnl" && PdfEncryption::legacyPasswordBytes("\xFC") === "\xFC");
foreach (['aes128', 'rc4', 128] as $m) {
    $refused = false;
    try {
        (new IvoPdf())->setProtection(['print'], 'парола', 'owner', $m);
    } catch (\InvalidArgumentException $e) {
        $refused = str_contains($e->getMessage(), 'aes256');
    }
    check("Cyrillic password with method {$m} is refused with a clear exception", $refused);
}
$ok256 = true;
try {
    (new IvoPdf())->setProtection(['print'], 'парола', 'собственик', 'aes256');
} catch (\Throwable) {
    $ok256 = false;
}
check('AES-256 keeps accepting Unicode passwords', $ok256);
foreach (['aes128', 'rc4'] as $m) {
    $p = new IvoPdf();
    $p->loadHtml('<p>secret text</p>')->setProtection(['print'], 'ümlaut', 'owner-ümlaut', $m);
    $r = py($p->output(), "a = d.authenticate(args[0])\nb = fitz.open(sys.argv[1]); o = b.authenticate(args[1])\nprint(json.dumps([a, o, d[0].get_text().strip() if a else '']))", 'ümlaut', 'owner-ümlaut');
    if ($r === null) {
        skip("PyMuPDF authenticate('ümlaut') with {$m}");
    } else {
        check("PyMuPDF opens the {$m} file with user 'ümlaut' and owner 'owner-ümlaut'", $r[0] > 0 && $r[1] > 0 && $r[2] === 'secret text', json_encode($r));
    }
}

// ---------------------------------------------------------------------------
echo "\n2. M1: PDF/A annotations and validatePdfA()\n";
// ---------------------------------------------------------------------------
foreach (['1b', '2b', '3b'] as $lvl) {
    $p = new IvoPdf(['pdfa' => $lvl, 'compress' => false]);
    $p->loadHtml('<p>Text <annotation content="Бележка" color="#ff0000" opacity="0.5" /></p>');
    $out = $p->output();
    $hasAp = preg_match('~/Subtype /Text [^\n]*/AP << /N \d+ 0 R >>~', $out) === 1;
    check("PDF/A-{$lvl}: the /Text annotation has a normal appearance stream", $hasAp);
    check("PDF/A-{$lvl}: validatePdfA() finds no issue", $p->validatePdfA() === [], json_encode($p->validatePdfA()));
    if ($lvl === '1b') {
        check('PDF/A-1b: the annotation is opaque (/CA 1)', preg_match('~/Subtype /Text [^\n]*/CA 1(?:\.0+)? ~', $out) === 1);
    }
}
$plain = pdfOf('<p>x <annotation content="note" /></p>');
check('without PDF/A the note keeps the viewer\'s own icon (no /AP)', preg_match('~/Subtype /Text [^\n]*/AP~', $plain) !== 1);
$fp = new IvoPdf(['pdfa' => '2b']);
$fp->loadHtml('<input type="text" name="a" value="x">')->output();
check('PDF/A with form fields: a warning says the file will not validate', (bool) array_filter($fp->getWarnings(), static fn(string $w): bool => str_contains($w, 'PDF/A') && str_contains($w, 'formFields')));
check('PDF/A-2 with form fields: validatePdfA() reports /NeedAppearances like veraPDF', in_array('AcroForm /NeedAppearances true is not allowed in PDF/A-2', $fp->validatePdfA(), true));
$ff = new IvoPdf(['pdfa' => '2b', 'formFields' => false]);
$ff->loadHtml('<input type="text" name="a" value="x">')->output();
check('formFields => false: validatePdfA() is clean', $ff->validatePdfA() === [], json_encode($ff->validatePdfA()));

// ---------------------------------------------------------------------------
echo "\n3. M2: UNC / protocol-relative / stream-wrapper paths\n";
// ---------------------------------------------------------------------------
$bs = '\\';
foreach (['//host/share/x.png', $bs . $bs . 'host' . $bs . 'share', '/' . $bs . 'host/x', $bs . '/host/x', $bs . $bs . '?' . $bs . 'UNC' . $bs . 'h' . $bs . 's', $bs . $bs . '.' . $bs . 'pipe' . $bs . 'x', 'file://host/x', 'ftp://h/x', 'phar://x.phar/y', 'compress.zlib://x'] as $p) {
    check('isNetworkPath(' . $p . ')', Options::isNetworkPath($p));
}
foreach (['C:' . $bs . 'fonts' . $bs . 'a.ttf', 'C:/fonts/a.ttf', '/var/www/a.png', 'img/a.png', 'a.png', $bs . 'root' . $bs . 'a.png'] as $p) {
    check('local path is not a network path: ' . $p, !Options::isNetworkPath($p));
}
check('protocol-relative URL becomes https', Options::normalizeProtocolRelativeUrl('//cdn.example.com/a.png') === 'https://cdn.example.com/a.png');
check('UNC with backslashes is not turned into a URL', Options::normalizeProtocolRelativeUrl($bs . $bs . 'h' . $bs . 'x') === $bs . $bs . 'h' . $bs . 'x');
$o = new Options(['basePath' => __DIR__]);
$t = microtime(true);
$r1 = $o->resolveAllowedLocalFile('//10.255.255.1/share/y.png');
$r2 = $o->resolveAllowedLocalFile($bs . $bs . '10.255.255.1' . $bs . 'share' . $bs . 'y.png');
check('resolveAllowedLocalFile() refuses UNC paths without touching the network', $r1 === null && $r2 === null && microtime(true) - $t < 1.0, sprintf('%.2fs', microtime(true) - $t));
check('...and says why', (bool) array_filter($o->getWarnings(), static fn(string $w): bool => str_contains($w, 'UNC')));
$t = microtime(true);
$img = new IvoPdf(['basePath' => __DIR__]);
$img->loadHtml('<img src="//10.255.255.2/share/y.png"><img src="' . $bs . $bs . '10.255.255.3' . $bs . 's' . $bs . 'y.png"><link rel="stylesheet" href="//10.255.255.4/s.css"><style>@font-face{font-family:Q;src:url(//10.255.255.5/f.ttf)}</style><p style="font-family:Q">x</p>')->output();
check('images / stylesheets / fonts with UNC or // sources render without stalling', microtime(true) - $t < 3.0, sprintf('%.2fs', microtime(true) - $t));
$remote = new IvoPdf(['isRemoteEnabled' => true]);
$remote->loadHtml('<img src="//127.0.0.1/x.png">')->output();
check('a // URL with remote access enabled goes through the SSRF guard', (bool) array_filter($remote->getWarnings(), static fn(string $w): bool => str_contains($w, 'private/reserved') && str_contains($w, 'https://127.0.0.1')), json_encode($remote->getWarnings()));
foreach ([
    'loadHtmlFile(//host)' => static fn() => (new IvoPdf())->loadHtmlFile('//10.255.255.6/s/x.html'),
    'loadHtmlFile(\\\\host)' => static fn() => (new IvoPdf())->loadHtmlFile($bs . $bs . '10.255.255.7' . $bs . 'x.html'),
    'appendPdf(\\\\host)' => static fn() => (new IvoPdf())->appendPdf($bs . $bs . '10.255.255.8' . $bs . 'x.pdf'),
    'setPageTemplate(//host)' => static fn() => (new IvoPdf())->setPageTemplate('//10.255.255.9/t.pdf'),
    'dompdf loadHtmlFile(\\\\host)' => static fn() => (new \IvoPdf\Compat\Dompdf\Dompdf())->loadHtmlFile($bs . $bs . '10.255.255.10' . $bs . 'x.html'),
] as $label => $fn) {
    $t = microtime(true);
    $threw = false;
    try {
        $fn();
    } catch (\Throwable $e) {
        $threw = str_contains($e->getMessage(), 'UNC');
    }
    check("{$label} is refused at once", $threw && microtime(true) - $t < 1.0, sprintf('%.2fs', microtime(true) - $t));
}

// ---------------------------------------------------------------------------
echo "\n4. M4: dompdf API\n";
// ---------------------------------------------------------------------------
$d = new \IvoPdf\Compat\Dompdf\Dompdf();
$d->loadHtml('<p id="q">Body</p><p style="page-break-before:always">Two</p>');
check('getDom() returns the parsed DOMDocument (getElementById works)', $d->getDom()->getElementById('q')?->textContent === 'Body');
$d->render();
$c = $d->getCanvas();
$c->rectangle(50, 100, 100, 50, [1, 0, 0], 2);
$c->arc(300, 125, 30, 20, 0, 180, [0, 0.5, 0], 2);
$c->save();
$c->clipping_rectangle(50, 200, 60, 30);
$c->filled_rectangle(40, 190, 200, 100, [0, 0.6, 0]);
$c->clipping_end();
$c->rotate(30, 100, 400);
$c->text(100, 430, 'rotated', $d->getFontMetrics()->getFont('Helvetica'), 12);
$c->restore();
$c->add_link('https://example.com/a', 50, 100, 100, 50);
$c->add_named_dest('dest1');
$c->add_info('Title', 'Canvas title');
$c->javascript('app.alert(1);');
$c->new_page();
$c->text(50, 50, 'on the new page', 'Helvetica', 12);
$c->serialize_object(1); // not supported: warning, no fatal
$out = (string) $d->output(['compress' => 0]);
check('Canvas::rectangle() draws an outline rectangle', str_contains($out, '0.00 0.00 100.00 50.00 re') || preg_match('~100(\.00)? 50(\.00)? re~', $out) === 1);
check('Canvas::new_page() appends a page', $d->getEngine()?->getPageCount() === 3);
check('javascript() adds document JavaScript', str_contains($out, '/JavaScript'));
check('unsupported CPDF methods only warn', (bool) array_filter($d->getWarnings(), static fn(string $w): bool => str_contains($w, 'serialize_object')));
$r = py($out, "print(json.dumps({'title': d.metadata.get('title'), 'links': [l.get('uri') for l in d[1].get_links()], 'p3': d[2].get_text().strip(), 'rot': 'rotated' in d[1].get_text()}))");
if ($r === null) {
    skip('PyMuPDF checks of the canvas output');
} else {
    // after render() the canvas draws on the last page (page 2), as in dompdf; new_page() then moves to page 3
    check('add_link() / rotate() / new_page() / add_info() take effect', $r['links'] === ['https://example.com/a'] && $r['p3'] === 'on the new page' && $r['rot'] === true && $r['title'] === 'Canvas title', json_encode($r));
}
$d2 = new \IvoPdf\Compat\Dompdf\Dompdf();
$d2->loadHtml('<p>a</p><p style="page-break-before:always">b</p>');
$d2->render();
$d2->getCanvas()->add_named_dest('target');
$d2->getCanvas()->page_script(static function (int $n, int $t, $canvas): void {
    if ($n === 1) {
        $canvas->add_link('#target', 10, 10, 100, 30);
    }
});
$r = py((string) $d2->output(), "print(json.dumps([[l['kind'], l.get('page')] for l in d[0].get_links()]))");
$r === null ? skip('named destination link') : check('add_named_dest() is a target for add_link(\'#name\')', $r === [[1, 1]], json_encode($r));
$d3 = new \IvoPdf\Compat\Dompdf\Dompdf();
$d3->setCallbacks([['event' => 'end_document', 'f' => static function (int $n, int $t, $canvas, $fm): void {
    $canvas->text(300, 20, "cb {$n}/{$t}", $fm->getFont('Helvetica'), 9);
}], ['event' => 'begin_frame', 'f' => 'strlen']]);
check('setCallbacks() / getCallbacks()', count($d3->getCallbacks()) === 2);
$d3->loadHtml('<p>x</p>');
$o3 = (string) $d3->output(['compress' => 0]);
check('end_document callbacks draw on every page', str_contains($o3, 'cb 1/1') || (py($o3, $extract)[0] ?? '') !== '' && str_contains(implode('', py($o3, $extract) ?? []), 'cb 1/1'));
check('unsupported callback events are reported', (bool) array_filter($d3->getWarnings(), static fn(string $w): bool => str_contains($w, 'begin_frame')));
$d3->setHttpContext(stream_context_create());
check('setHttpContext() / getHttpContext() / getQuirksmode() / getTree()', is_resource($d3->getHttpContext()) && $d3->getQuirksmode() === false && $d3->getTree() !== null);
$d3->set_options(['defaultFont' => 'Courier']);
check('set_options() applies values', $d3->getOptions()->get('defaultFont') === 'Courier');
$fm = $d3->getFontMetrics();
check('FontMetrics::getFamily() / register_font() / setFontFamily()', array_keys((array) $fm->getFamily('DejaVu Sans')) === ['normal', 'bold', 'italic', 'bold_italic']
    && $fm->register_font(['family' => 'fc-reg'], __DIR__ . '/../resources/fonts/DejaVuSerif.ttf') === true);
$fm->setFontFamily('fc-fam', ['normal' => __DIR__ . '/../resources/fonts/DejaVuSerif']);
check('setFontFamily() accepts dompdf\'s extension-less paths', ($fm->getFamily('fc-fam')['normal'] ?? '') !== '');
check('Canvas::get_font_baseline() is the font height', $c->get_font_baseline('Helvetica', 10) > 9.0 && $c->get_font_baseline('Helvetica', 10) < 16.0);
check('Options::getFontDir() is the bundled font folder', is_dir((string) (new \IvoPdf\Compat\Dompdf\Options())->getFontDir()));

// ---------------------------------------------------------------------------
echo "\n5. M5: mPDF API\n";
// ---------------------------------------------------------------------------
$logo = 'data:image/png;base64,' . base64_encode((static function (): string {
    $im = imagecreatetruecolor(20, 10);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 220));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
})());
$m = new IvoPdf(['compress' => false]);
$m->WriteHTML('<h1>One</h1>');
$m->AddPage();
$m->WriteHTML('<h1>Two</h1>');
$m->Image($logo, 20, 40, 30, 15);
$m->WriteFixedPosHTML('<div>FIXEDPOS</div>', 100, 100, 50, 20);
$m->SetFont('dejavusans', 'B', 12);
$m->Text(20, 80, 'TEXTAT');
$m->SetXY(20, 120);
$m->Cell(40, 10, 'CELLONE', 1, 0, 'C');
$m->Cell(40, 10, 'CELLTWO', 1, 1, 'C');
check('PageNo() is the page the written HTML ends on', $m->PageNo() === 2);
$out = $m->Output('', 'S');
$r = py($out, $extract);
if ($r === null) {
    skip('page of the imperative drawing');
} else {
    check('Image / WriteFixedPosHTML / Text / Cell land on the current (second) page', !str_contains($r[0], 'FIXEDPOS') && str_contains($r[1], 'FIXEDPOS') && str_contains($r[1], 'TEXTAT') && str_contains($r[1], 'CELLONE') && str_contains($r[1], 'CELLTWO'), json_encode($r));
}
check('Image() paints an image XObject', substr_count($out, '/Subtype /Image') >= 1);
$m = new IvoPdf();
$m->SetFont('dejavusans', '', 11);
$m->Cell(0, 10, 'FLOWCELL', 1, 1);
$m->WriteHTML('<p>after</p>');
$r = py($m->Output('', 'S'), $extract);
$r === null ? skip('Cell() in the flow') : check('Cell() without SetXY() flows with the HTML', str_contains($r[0], "FLOWCELL\nafter"), json_encode($r));
$m = new IvoPdf();
$m->WriteHTML('<p>P1</p><pagebreak /><p>P2</p><pagebreak /><p>P3</p><pagebreak /><p>P4</p>');
$m->DeletePages(2);
$m->MovePages(1, 3);
$r = py($m->Output('', 'S'), $extract);
$r === null ? skip('DeletePages / MovePages') : check('DeletePages(2) + MovePages(1, 3) give P4, P1, P3', array_map('trim', $r) === ['P4', 'P1', 'P3'], json_encode($r));
$m = new IvoPdf();
$m->SetAutoPageBreak(true, 30);
check('SetAutoPageBreak(true, 30) sets a 30 mm bottom margin', abs($m->getOptions()->margins['bottom'] - 30 * 72 / 25.4) < 0.01);
$m->WriteHTML('<p>Cover</p>');
$m->TOCpagebreak();
$m->WriteHTML('<h1>Chapter A</h1><p>a</p>');
$r = py($m->Output('', 'S'), $extract);
$r === null ? skip('TOCpagebreak') : check('TOCpagebreak() puts the table of contents on its own page', count($r) === 3 && str_contains($r[1], 'Chapter A') && str_contains($r[1], '3'), json_encode($r));
$m = new IvoPdf();
$m->SetImportUse();
$m->SetAnchor2Bookmark(1);
$m->SetAnchor2Bookmark(1);
$m->WriteHTML('<p>x</p>');
$m->output();
$w = array_values(array_filter($m->getWarnings(), static fn(string $w): bool => str_contains($w, 'SetAnchor2Bookmark')));
check('mPDF methods IvoPDF does not implement warn once instead of failing', count($w) === 1, json_encode($m->getWarnings()));
$err = '';
try {
    $m->noSuchMethodAnywhere();
} catch (\Error $e) {
    $err = $e->getMessage();
}
check('names that are not mPDF methods still raise the usual Error', str_contains($err, 'undefined method'));
$m = new IvoPdf();
$m->WriteHTML('<p>abc</p>');
check('GetStringWidth() measures in mm with the SetFont() font', $m->SetFont('dejavusans', '', 10)->GetStringWidth('Hello') > 5.0 && $m->GetStringWidth('Hello') < 15.0);

// ---------------------------------------------------------------------------
echo "\n6. L2: registerFontFamily() contract and synthesized faces\n";
// ---------------------------------------------------------------------------
$threw = '';
try {
    (new IvoPdf())->registerFontFamily('Missing', ['regular' => __DIR__ . '/no-such-font.ttf']);
} catch (\InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
check('a missing font file throws InvalidArgumentException naming the file', str_contains($threw, 'no-such-font.ttf'));
$fonts = __DIR__ . '/../resources/fonts/';
$s = new IvoPdf(['compress' => false]);
$s->registerFontFamily('OnlyRegular', ['regular' => $fonts . 'Inter-Regular.ttf']);
$out = $s->loadHtml('<p style="font-family:OnlyRegular"><b>BoldSynth</b> <i>ItalicSynth</i></p>')->output();
check('missing bold face: synthesized by stroking (2 Tr)', str_contains($out, ' 2 Tr '));
check('missing italic face: synthesized by slanting (Tm skew)', str_contains($out, '1 0 0.25 1 '));
check('no warning for synthesized faces', $s->getWarnings() === [], json_encode($s->getWarnings()));
$real = pdfOf('<p style="font-family:Inter"><b>Bold</b> <i>Italic</i></p>');
check('families with real bold / italic faces are not synthesized', !str_contains($real, ' 2 Tr ') && !str_contains($real, '1 0 0.25 1 '));
$r = py($out, $extract);
$r === null ? skip('synthesized text extraction') : check('synthesized text stays extractable', str_contains($r[0], 'BoldSynth') && str_contains($r[0], 'ItalicSynth'));

// ---------------------------------------------------------------------------
echo "\n7. L4: text extraction of glyphs without a font and of text shadows\n";
// ---------------------------------------------------------------------------
$cjk = pdfOf('<p>日本語 中文 한국어</p>');
check('characters without a font carry /ActualText', str_contains($cjk, '/ActualText <FEFF65E5672C8A9E'));
$r = py($cjk, $extract);
$r === null ? skip('PyMuPDF extraction of .notdef text') : check('PyMuPDF extracts the original code points (with spaces)', trim($r[0]) === '日本語 中文 한국어', json_encode($r));
$sym = pdfOf('<p style="font-family:Arial">Arial with ☺ ✓ → symbols</p>');
$r = py($sym, $extract);
$r === null ? skip('spaces next to fallback symbols') : check('spaces between fallback-font symbols are extractable', trim($r[0]) === 'Arial with ☺ ✓ → symbols', json_encode($r));
$sh = pdfOf('<p style="text-shadow:1pt 1pt 0 red">shadowed</p>');
check('text-shadow copies are marked as an artifact with empty /ActualText', str_contains($sh, '/Artifact << /ActualText <FEFF> >> BDC'));
$r = py($sh, $extract);
$r === null ? skip('PyMuPDF extraction of shadowed text') : check('shadowed text is extracted once', trim($r[0]) === 'shadowed', json_encode($r));

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
