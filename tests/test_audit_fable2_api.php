<?php

declare(strict_types=1);

/**
 * Regression tests for the API findings of the second independent audit:
 *  - M1: mPDF Output($name) / Output($name, bool) semantics (a file name alone writes the file)
 *  - M2: PDF/A with CMYK colours (CSS cmyk(), spot colours, colorSpace 'cmyk', CMYK JPEGs, mPDF restrictColorSpace=3)
 *  - M3: mPDF PageNo() / $mpdf->page lays out only what was appended since its last call (and stays exact)
 *  - M4: {PAGENO} / {nbpg} in headers and footers count the output pages after DeletePages() / MovePages()
 *  - L1: documents created through `new \Mpdf\Mpdf()` use mPDF's default font, size and line height
 *  - L3: dompdf FontMetrics heights / baselines / families like dompdf (core-font AFM metrics), get_page_count()
 *  - L4: mPDF methods accept numeric strings / booleans under strict_types=1 (this file declares it)
 *  - L5: <input type=range>, <progress>, <meter> drawn like Edge; date / time input widths; Arabic brackets extract
 *
 * Run: php tests/test_audit_fable2_api.php
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

function tmpPdf(string $tag): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable2-' . $tag . '-' . md5(uniqid('', true)) . '.pdf';
}

/** Text per page through PyMuPDF, or null when Python / PyMuPDF is not available. */
function pdfText(string $bytes): ?array
{
    $file = tmpPdf('text');
    file_put_contents($file, $bytes);
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([p.get_text() for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable2-text.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

function findBox(LayoutBox $box, callable $match): ?LayoutBox
{
    if ($match($box)) {
        return $box;
    }
    foreach ($box->children as $c) {
        $f = findBox($c, $match);
        if ($f !== null) {
            return $f;
        }
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null && ($f = findBox($run->inlineBox, $match)) !== null) {
                return $f;
            }
        }
    }
    return null;
}

function byId(IvoPdf $pdf, string $id): ?LayoutBox
{
    $tree = $pdf->getLayoutTree();
    return $tree === null ? null : findBox($tree, static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === $id);
}

$lorem = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore. ', 3);

// ---------------------------------------------------------------------------
echo "1. M1: mPDF Output() destinations\n";
// ---------------------------------------------------------------------------
$file = tmpPdf('out');
$m = new \Mpdf\Mpdf();
$m->WriteHTML('<p>hello</p>');
ob_start();
$r = $m->Output($file);
$echoed = (string) ob_get_clean();
check('Output($file) writes the file, returns null and prints nothing (mPDF: no destination + a name = F)', $r === null && $echoed === '' && is_file($file) && str_starts_with((string) file_get_contents($file), '%PDF-'));
@unlink($file);
ob_start();
$r = $m->Output($file, false);
$echoed = (string) ob_get_clean();
check('Output($file, false) writes the file (mPDF: false = F)', $r === null && $echoed === '' && is_file($file));
@unlink($file);
ob_start();
$r = $m->Output($file, true);
$echoed = (string) ob_get_clean();
check('Output($file, true) sends a download (mPDF: true = D) without a TypeError; the caller\'s output buffer is kept', $r === null && str_starts_with($echoed, '%PDF-') && !is_file($file));
ob_start();
$r = $m->Output();
$echoed = (string) ob_get_clean();
check('Output() without arguments streams inline, as mPDF', $r === null && str_starts_with($echoed, '%PDF-'));
check("Output('', 'S') and OutputBinaryData() return the bytes", str_starts_with((string) $m->Output('', 'S'), '%PDF-') && str_starts_with($m->OutputBinaryData(), '%PDF-'));
$caught = '';
try {
    $m->Output('x.pdf', 'Q');
} catch (\InvalidArgumentException $e) {
    $caught = $e->getMessage();
}
check('an unknown destination is refused like mPDF', str_contains($caught, 'Incorrect output destination'), $caught);
$native = new IvoPdf();
$native->loadHtml('<p>x</p>');
check('IvoPdf::output() (and dompdf-style output([...])) still return the bytes', str_starts_with((string) $native->output(), '%PDF-') && str_starts_with((string) $native->output(['compress' => 0]), '%PDF-'));
$native->output($file);
check('IvoPdf::output($file) writes the file', is_file($file));
@unlink($file);

// ---------------------------------------------------------------------------
echo "\n2. L4: untyped mPDF arguments under strict_types=1\n";
// ---------------------------------------------------------------------------
$errors = [];
$s = new \Mpdf\Mpdf(['margin_top' => '20']);
$calls = [
    'SetFont' => fn() => $s->SetFont('dejavusans', '', '12'),
    'SetFontSize' => fn() => $s->SetFontSize('10'),
    'SetXY' => fn() => $s->SetXY('20', '60'),
    'Cell' => fn() => $s->Cell('40', '8', 'Name', '1', '1', 'C', '1'),
    'MultiCell' => fn() => $s->MultiCell('60', '5', 12345, 0, 'L', 0),
    'Text' => fn() => $s->Text('20', '100', 2024),
    'Rect' => fn() => $s->Rect('10', '10', '20', '20', 'D'),
    'Line' => fn() => $s->Line('10', '10', 50, 50.5),
    'SetLineWidth' => fn() => $s->SetLineWidth('0.5'),
    'SetTitle' => fn() => $s->SetTitle(2024),
    'SetAuthor' => fn() => $s->SetAuthor(null),
    'SetHTMLHeader' => fn() => $s->SetHTMLHeader('<div>H</div>', 'E', true),
    'SetHTMLFooter' => fn() => $s->SetHTMLFooter('<div>F</div>', ''),
    'WriteHTML' => fn() => $s->WriteHTML('<p>x</p>', '0'),
    'AddPage' => fn() => $s->AddPage('L', '', '1'),
    'Bookmark' => fn() => $s->Bookmark('B', '1'),
    'SetColumns' => fn() => $s->SetColumns('1', '', '5'),
    'SetDisplayMode' => fn() => $s->SetDisplayMode(100, 'two'),
    'SetCompression' => fn() => $s->SetCompression(1),
    'SetAutoPageBreak' => fn() => $s->SetAutoPageBreak(1, '15'),
    'SetWatermarkText' => fn() => $s->SetWatermarkText('DRAFT', '0.1'),
    'SetWatermarkImage' => fn() => $s->SetWatermarkImage('none.png', -1, 'D', 'P'),
    'SetProtection' => fn() => $s->SetProtection(['copy'], 'u', 'o', '128'),
    'setProtection reset' => fn() => $s->getOptions()->encryption = null,
    'SetDefaultFontSize' => fn() => $s->SetDefaultFontSize('11'),
    'DeletePages' => fn() => $s->DeletePages('3', '3'),
];
foreach ($calls as $name => $call) {
    try {
        $call();
    } catch (\TypeError $e) {
        $errors[] = "{$name}: " . $e->getMessage();
    }
}
check('numeric strings, ints and booleans are accepted where mPDF accepts them (no TypeError)', $errors === [], implode(' | ', array_slice($errors, 0, 3)));
$wm = (new \ReflectionProperty(IvoPdf::class, 'mpdfWatermarkImage'))->getValue($s);
check('coerced values are used (SetTitle(2024), SetWatermarkImage alpha -1 = 0.2)', $s->getOptions()->title === '2024' && is_array($wm) && abs($wm['alpha'] - 0.2) < 1e-9);
check('a document built with the coerced calls renders', str_starts_with((string) $s->Output('', 'S'), '%PDF-'));

// ---------------------------------------------------------------------------
echo "\n3. M3: PageNo() is incremental and exact\n";
// ---------------------------------------------------------------------------
$chunks = [];
for ($i = 1; $i <= 40; $i++) {
    $chunks[] = match ($i % 8) {
        0 => '<h2>Heading ' . $i . '</h2>',
        1 => '<table border="1" style="width:100%"><tr><td>a' . $i . '</td><td>' . str_repeat('cell text ', $i % 5 + 1) . '</td></tr></table>',
        2 => '<pagebreak />',
        3 => '<div style="border:1px solid #000;padding:4pt;margin:8pt 0">' . str_repeat($lorem, $i % 3 + 1) . '</div>',
        4 => '<p style="margin-top:30pt">' . $lorem . '</p>',
        5 => 'loose text ' . $i,
        6 => '<div style="float:left;width:60pt;height:40pt;background:#eee"></div><p>beside</p>',
        default => '<p>' . str_repeat($lorem, $i % 4 + 1) . '</p>',
    };
}
$inc = new \Mpdf\Mpdf();
$mismatch = [];
foreach ($chunks as $i => $c) {
    $inc->WriteHTML($c);
    $n = $inc->PageNo();
    if ($i % 4 === 3 || $i === count($chunks) - 1) {
        $ref = new \Mpdf\Mpdf();
        foreach (array_slice($chunks, 0, $i + 1) as $h) {
            $ref->WriteHTML($h);
        }
        $ref->render();
        if ($ref->getPageCount() !== $n) {
            $mismatch[] = "after chunk {$i}: PageNo {$n}, full layout {$ref->getPageCount()}";
        }
    }
}
check('PageNo() after every WriteHTML() equals the page count of a full layout', $mismatch === [], implode(' | ', $mismatch));
check('$mpdf->page is PageNo()', $inc->page === $inc->PageNo());
$inc->SetHTMLHeader('<div style="height:200pt">tall header</div>');
$ref = new \Mpdf\Mpdf();
$ref->SetHTMLHeader('<div style="height:200pt">tall header</div>');
foreach ($chunks as $h) {
    $ref->WriteHTML($h);
}
$ref->render();
check('a settings change after PageNo() is taken into account', $inc->PageNo() === $ref->getPageCount(), $inc->PageNo() . ' vs ' . $ref->getPageCount());

$loop = new \Mpdf\Mpdf();
$t = microtime(true);
for ($i = 1; $i <= 150; $i++) {
    $loop->WriteHTML('<p>Row ' . $i . ' ' . $lorem . '</p>');
    $n = $loop->PageNo();
    if ($i % 10 === 0) {
        $loop->WriteHTML('<p><i>(now on page ' . $n . ')</i></p>');
    }
}
$elapsed = microtime(true) - $t;
$final = $loop->PageNo();
$loop->render();
check('150 x WriteHTML() + PageNo() does not lay the whole document out each time (< 12 s; was 29 s)', $elapsed < 12.0, sprintf('%.1f s', $elapsed));
check('the last PageNo() equals the rendered page count', $final === $loop->getPageCount(), $final . ' vs ' . $loop->getPageCount());

// ---------------------------------------------------------------------------
echo "\n4. M4: page numbers after DeletePages() / MovePages()\n";
// ---------------------------------------------------------------------------
$d = new \Mpdf\Mpdf();
$d->SetHTMLHeader('<div>H {PAGENO}/{nbpg}</div>');
$d->WriteHTML('<h1>One</h1><pagebreak /><h1>Two</h1><pagebreak /><h1>Three</h1><pagebreak /><h1>Four</h1>');
$d->DeletePages(2);
$d->MovePages(1, 3);
$text = pdfText((string) $d->Output('', 'S'));
if ($text === null) {
    skip('header numbers after DeletePages + MovePages (needs Python + PyMuPDF)');
} else {
    $heads = array_map(static fn(string $p): string => preg_match('~H \d+/\d+~', $p, $mm) === 1 ? $mm[0] : '?', $text);
    $titles = array_map(static fn(string $p): string => preg_match('~One|Two|Three|Four~', $p, $mm) === 1 ? $mm[0] : '?', $text);
    check('headers read H 1/3, H 2/3, H 3/3 on the reordered pages (as mPDF)', $heads === ['H 1/3', 'H 2/3', 'H 3/3'], json_encode($heads));
    check('the pages are Four, One, Three', $titles === ['Four', 'One', 'Three'], json_encode($titles));
}

// ---------------------------------------------------------------------------
echo "\n5. M2: PDF/A with CMYK colours\n";
// ---------------------------------------------------------------------------
$cmykJpeg = 'data:image/jpeg;base64,/9j/7gAOQWRvYmUAZAAAAAAA/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/8AAFAgACAAIBEMRAE0RAFkRAEsRAP/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/aAA4EQwBNAFkASwAAPwD6pr5Ur5Ur6pr/2Q==';
$cmykHtml = '<p style="color:cmyk(0 1 1 0)">cmyk text</p><p style="background:device-cmyk(0 0 0 .1);color:spot(PANTONE 185 C, 80%)">spot</p>'
    . '<div style="height:20pt;background:linear-gradient(to right, cmyk(1 0 0 0), cmyk(0 0 1 0))"></div>'
    . (function_exists('imagecreatefromstring') ? '<img src="' . $cmykJpeg . '" style="width:40pt">' : '');
foreach ([['1b', 'cmyk'], ['2b', 'rgb'], ['3b', 'cmyk']] as [$level, $space]) {
    $p = new IvoPdf(['pdfa' => $level, 'colorSpace' => $space, 'compress' => false, 'formFields' => false]);
    $p->addSpotColor('PANTONE 185 C', 0, 91, 76, 0);
    $p->loadHtml($cmykHtml);
    $bytes = (string) $p->output();
    $warn = implode(' ', $p->getWarnings());
    check("PDF/A-{$level} + colorSpace {$space} + CMYK / spot colours: no DeviceCMYK left, the pre-check passes, a warning tells", $p->validatePdfA() === [] && preg_match('~(?:[\d.]+ ){4}[kK]\b~', $bytes) !== 1
        && !str_contains($bytes, '/DeviceCMYK') && str_contains($warn, 'converted to RGB'), json_encode([$p->validatePdfA(), $warn]));
}
$conv = new IvoPdf(['pdfa' => '2b', 'compress' => false]);
$conv->loadHtml('<p style="color:cmyk(0 1 1 0)">red</p>');
check('the RGB equivalent keeps the colour (cmyk(0 1 1 0) -> 1 0 0 rg)', str_contains((string) $conv->output(), '1.000 0.000 0.000 rg'));
$plain = new IvoPdf(['pdfa' => '2b']);
$plain->loadHtml('<p style="color:#123456">rgb only</p>');
$plain->output();
check('an RGB-only PDF/A document gets no conversion warning', $plain->getWarnings() === [], json_encode($plain->getWarnings()));
$mp = new \Mpdf\Mpdf(['PDFA' => true, 'PDFAauto' => true, 'restrictColorSpace' => 3]);
$mp->WriteHTML('<p style="color:cmyk(0,100,100,0)">cmyk mode</p><p style="color:red">red</p>');
$mpBytes = (string) $mp->Output('', 'S');
check("mPDF PDFA + restrictColorSpace=3 (no ICCProfile): a conforming RGB file with a warning", $mp->validatePdfA() === [] && str_contains(implode(' ', $mp->getWarnings()), "colorSpace 'cmyk' needs a CMYK output intent"));
$rswop = 'C:/Windows/System32/spool/drivers/color/RSWOP.icm';
if (is_file($rswop)) {
    foreach (['1b', '2b', '3b'] as $level) {
        $p = new IvoPdf(['pdfa' => $level, 'pdfaIccProfile' => $rswop, 'compress' => false, 'formFields' => false]);
        $p->loadHtml('<p style="color:#ff0000">rgb</p>' . $cmykHtml);
        $p->addSpotColor('PANTONE 185 C', 0, 91, 76, 0);
        $bytes = (string) $p->output();
        check("PDF/A-{$level} with a CMYK ICC profile: CMYK output intent (/N 4), no device RGB, the pre-check passes", str_contains($bytes, '/N 4') && str_contains($bytes, '/OutputConditionIdentifier (Custom)')
            && preg_match('~(?:^|\s)(?:[\d.]+ ){3}(?:rg|RG)\s~', $bytes) !== 1 && $p->validatePdfA() === [], json_encode($p->validatePdfA()));
    }
    $mpIcc = new \Mpdf\Mpdf(['PDFA' => true, 'ICCProfile' => $rswop]);
    check("mPDF 'ICCProfile' is the PDF/A output profile", $mpIcc->getOptions()->pdfaIccProfile === $rswop);
} else {
    skip('PDF/A with a CMYK ICC output profile (needs a CMYK .icm, e.g. Windows RSWOP.icm)');
}
$caught = '';
$srgbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable2-srgb.icc';
file_put_contents($srgbFile, \IvoPdf\Pdf\IccProfile::srgb());
try {
    $bad = new IvoPdf(['pdfa' => '2b', 'pdfaIccProfile' => $srgbFile]);
    $bad->loadHtml('<p>x</p>')->output();
} catch (\LogicException $e) {
    $caught = $e->getMessage();
}
@unlink($srgbFile);
check('an RGB profile given as the PDF/A CMYK profile is refused with a clear message', str_contains($caught, 'must be CMYK'), $caught);
$v4 = str_repeat("\0", 132);
$v4 = substr_replace($v4, "\x04", 8, 1);
$v4 = substr_replace($v4, 'prtrCMYK', 12, 8);
$v4 = substr_replace($v4, 'acsp', 36, 4);
check('PDF/A-1 refuses an ICC v4 CMYK profile, PDF/A-2 accepts it', \IvoPdf\Pdf\PdfAIntent::check($v4, 1) !== null && \IvoPdf\Pdf\PdfAIntent::check($v4, 2) === null);
if (function_exists('imagecreatefromstring')) {
    $img = new IvoPdf(['pdfa' => '2b', 'compress' => false]);
    $img->loadHtml('<img src="' . $cmykJpeg . '" style="width:40pt">');
    $ib = (string) $img->output();
    check('a CMYK JPEG is embedded as RGB in PDF/A (sRGB intent)', !str_contains($ib, '/DeviceCMYK') && str_contains($ib, '/DeviceRGB') && $img->validatePdfA() === []);
} else {
    skip('CMYK JPEG in PDF/A (needs GD)');
}

// ---------------------------------------------------------------------------
echo "\n6. L1: mPDF defaults for documents created through the mPDF API\n";
// ---------------------------------------------------------------------------
$md = new \Mpdf\Mpdf();
check('new \\Mpdf\\Mpdf(): DejaVu Serif Condensed 11pt (mPDF default_font serif / default_font_size 11)', $md->getOptions()->defaultFont === 'DejaVu Serif Condensed' && $md->getOptions()->defaultFontSize === 11.0);
$md->WriteHTML('<p id="p">' . $lorem . '</p>');
$mdBytes = (string) $md->Output('', 'S');
$pBox = byId($md, 'p');
check('the text is set in DejaVuSerifCondensed with mPDF\'s 1.33 line height', str_contains($mdBytes, 'DejaVuSerifCondensed') && $pBox !== null && count($pBox->lines) > 1 && abs($pBox->lines[1]->y - $pBox->lines[0]->y - 11 * 1.33) < 0.05, $pBox !== null && count($pBox->lines) > 1 ? (string) ($pBox->lines[1]->y - $pBox->lines[0]->y) : 'no lines');
$cfg = new \Mpdf\Mpdf(['default_font' => 'dejavusans', 'default_font_size' => 9]);
check('configured default_font / default_font_size win', $cfg->getOptions()->defaultFont === 'dejavusans' && $cfg->getOptions()->defaultFontSize === 9.0);
check('the native IvoPdf default stays Inter 12pt', (new IvoPdf())->getOptions()->defaultFont === 'Inter' && (new IvoPdf())->getOptions()->defaultFontSize === 12.0);
check('dejavusanscondensed / dejavuserifcondensed resolve to the bundled condensed faces', $md->getFontManager()->resolveFont('dejavusanscondensed')->postScriptName !== $md->getFontManager()->resolveFont('dejavusans')->postScriptName
    && str_contains($md->getFontManager()->resolveFont('dejavuserifcondensed')->postScriptName, 'Condensed'));

// ---------------------------------------------------------------------------
echo "\n7. L3: dompdf FontMetrics\n";
// ---------------------------------------------------------------------------
$dd = new \Dompdf\Dompdf();
$dd->loadHtml('<p>x</p>');
$fm = $dd->getFontMetrics();
$near = static fn(float $a, float $b): bool => abs($a - $b) < 0.06;
$h = $fm->getFont('helvetica');
$t = $fm->getFont('times', 'italic');
$c = $fm->getFont('courier');
check('getFontHeight / getFontBaseline: Helvetica 12.21 / 11.10, Times 11.88 / 10.80, Courier 10.38 / 9.43 (dompdf values)',
    $near($fm->getFontHeight($h, 12), 12.21) && $near($fm->getFontBaseline($h, 12), 11.10) && $near($fm->getFontHeight($t, 12), 11.88)
    && $near($fm->getFontBaseline($t, 12), 10.80) && $near($fm->getFontHeight($c, 12), 10.38) && $near($fm->getFontBaseline($c, 12), 9.43),
    json_encode([$fm->getFontHeight($h, 12), $fm->getFontBaseline($h, 12), $fm->getFontHeight($t, 12), $fm->getFontBaseline($t, 12)]));
check('DejaVu Sans: 15.36 / 13.97 (its own ascent + descent, ratio 1.1)', $near($fm->getFontHeight($fm->getFont('DejaVu Sans'), 12), 15.36) && $near($fm->getFontBaseline($fm->getFont('DejaVu Sans'), 12), 13.97));
check('serif / sans-serif / monospace / fixed map to Times / Helvetica / Courier, as in dompdf', str_starts_with((string) $fm->getFont('serif'), 'times|') && str_starts_with((string) $fm->getFont('sans-serif'), 'helvetica|')
    && str_starts_with((string) $fm->getFont('monospace'), 'courier|') && str_starts_with((string) $fm->getFont('fixed', 'bold'), 'courier|bold'));
check("getFont() of an unknown family is null (dompdf); measuring null uses Helvetica", $fm->getFont('nonexistent') === null && $near($fm->getTextWidth('Hello World', null, 12), $fm->getTextWidth('Hello World', $h, 12)));
check('getFont(null) is the default font (serif = Times)', str_starts_with((string) $fm->getFont(null), 'times|'));
check('"Hello World" in serif is measured in a Times-metric face (dompdf 60.3pt, was 72.3pt in DejaVu Serif)', abs($fm->getTextWidth('Hello World', $fm->getFont('serif'), 12) - 60.3) < 1.5, (string) $fm->getTextWidth('Hello World', $fm->getFont('serif'), 12));
check('Canvas::get_page_count() is 1 before render(), as dompdf', $dd->getCanvas()->get_page_count() === 1);
$dd->getCanvas()->page_text(100, 100, 'BASE', $h, 20, [0, 0, 0]);
$dd->render();
check('Canvas::get_page_count() after render() is the page count', $dd->getCanvas()->get_page_count() === 1);
$serifDoc = new \Dompdf\Dompdf();
$serifDoc->loadHtml('<p id="s" style="font-family:serif">Serif text</p>');
$serifDoc->render();
check('CSS serif in a document rendered through the dompdf API uses Times metrics (Liberation Serif), as dompdf', str_contains((string) $serifDoc->output(), 'LiberationSerif'));
$baseText = pdfText((string) $dd->output());
if ($baseText !== null) {
    check('page_text() output is extractable', str_contains($baseText[0] ?? '', 'BASE'));
} else {
    skip('page_text() extraction (needs PyMuPDF)');
}

// ---------------------------------------------------------------------------
echo "\n8. L5: range / progress / meter, date widths, Arabic brackets\n";
// ---------------------------------------------------------------------------
$f = new IvoPdf(['compress' => false]);
$f->loadHtml('<html><body style="font-size:11pt"><p><input id="r" type="range" value="25"> <progress id="pg" value="0.5"></progress> <meter id="mt" value="0.6"></meter> <input id="dt" type="date" value="2026-01-02"> <input id="tm" type="time"></p></body></html>');
$fb = (string) $f->output();
$r = byId($f, 'r');
$pg = byId($f, 'pg');
$mt = byId($f, 'mt');
$dt = byId($f, 'dt');
$tm = byId($f, 'tm');
check('<input type=range> is 129px wide (Edge)', $r !== null && abs($r->width - 96.75) < 0.1, $r !== null ? (string) $r->width : 'missing');
check('<progress> is 10em x 1em and <meter> 5em x 1em (Chromium)', $pg !== null && $mt !== null && abs($pg->width - 110.0) < 0.1 && abs($mt->width - 55.0) < 0.1 && abs($pg->height - 11.0) < 0.1, json_encode([$pg?->width, $pg?->height, $mt?->width]));
check('date / time inputs get Edge-like widths (date 87pt, was 132.75pt)', $dt !== null && abs($dt->width - 87.0) < 0.1 && $tm !== null && $tm->width < 80.0, json_encode([$dt?->width, $tm?->width]));
check('the slider thumb / progress bar use the Edge accent colour, the meter its optimum green', substr_count($fb, '0.000 0.459 1.000 rg') >= 2 && str_contains($fb, '0.063 0.486 0.063 rg'));
$slider = new IvoPdf(['compress' => false]);
$slider->loadHtml('<form><input type="range" name="vol" min="0" max="10" value="7"></form>');
check('a slider is drawn, not exported as a text form field', !str_contains((string) $slider->output(), '/FT /Tx') && str_contains((string) $slider->output(), '0.000 0.459 1.000 rg'));
$ar = new IvoPdf(['compress' => false]);
$ar->loadHtml('<p dir="rtl" style="font-family:\'Liberation Sans\'">مرحبا بالعالم 456 XYZ [أقواس] نهاية.</p><p dir="rtl" style="font-family:\'Liberation Sans\'">שלום (סוגריים) [עולם] סוף.</p>');
$arText = pdfText((string) $ar->output());
if ($arText === null) {
    skip('Arabic / Hebrew bracket extraction (needs PyMuPDF)');
} else {
    check('Arabic in a fallback font: brackets extract as [..] (as from Edge), Hebrew keeps (..) and [..]', str_contains($arText[0], '[أقواس]') && str_contains($arText[0], '(סוגריים)') && str_contains($arText[0], '[עולם]'), json_encode($arText[0], JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------------
echo "\n";
echo "\n--- mPDF PDFA / PDFAversion, <body> attributes of a fragment ---\n";
check("mPDF 'PDFA' => true is PDF/A-1b like mPDF", (new \IvoPdf\Options(['PDFA' => true]))->pdfa === '1b');
check("mPDF 'PDFAversion' => '3-B' is honoured", (new \IvoPdf\Options(['PDFA' => true, 'PDFAversion' => '3-B']))->pdfa === '3b');
check("native 'pdfa' => true stays PDF/A-2b", (new \IvoPdf\Options(['pdfa' => true]))->pdfa === '2b');
$frag = new \IvoPdf\IvoPdf();
$frag->loadHtml('<body style="margin:0;color:#ff0000"><p>x</p></body>');
$frag->render();
$bodyBox = $frag->getLayoutTree();
check('a fragment starting with <body style> keeps its attributes (also on the PHP < 8.4 parser)', $bodyBox->marginLeft === 0.0 && strtolower($bodyBox->style->get('color')) === '#ff0000', $bodyBox->marginLeft . ' ' . $bodyBox->style->get('color'));

check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
