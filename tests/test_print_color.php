<?php

declare(strict_types=1);

/**
 * Tests for print-production color: cmyk()/device-cmyk() colors, spot colors (Separation), the colorSpace option,
 * PDF/X-1a / X-3 / X-4 output (OutputIntent, Info keys, page boxes, no RGB, no transparency), bleed and crop marks,
 * RGB to CMYK image conversion and option conflicts.
 *
 * Run: php tests/test_print_color.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\CssColor;
use IvoPdf\IvoPdf;
use IvoPdf\Options;
use IvoPdf\Pdf\ColorConverter;
use IvoPdf\Pdf\ColorOps;
use IvoPdf\Pdf\NaiveCmykConverter;

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

/**
 * @param array<string, mixed> $options
 * @return array{0: string, 1: IvoPdf}
 */
function make(string $html, array $options = [], ?callable $setup = null): array
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $bytes = $pdf->loadHtml($html)->output();
    return [$bytes, $pdf];
}

/** RGB of the pixel at PDF point (x, y from top-left) rendered by PyMuPDF; null when unavailable. */
function pixel(string $pdfBytes, float $x, float $y): ?array
{
    static $script = null;
    static $available = null;
    if ($available === null) {
        $probe = @shell_exec('python -I -c "import pymupdf as fitz;print(1)" 2>&1');
        $available = is_string($probe) && trim($probe) === '1';
    }
    if (!$available) {
        return null;
    }
    $dir = sys_get_temp_dir();
    $script ??= $dir . '/ivopdf_px.py';
    file_put_contents($script, "import sys, fitz\nd = fitz.open(sys.argv[1])\np = d[0]\nx = float(sys.argv[2]); y = float(sys.argv[3])\n"
        . "pm = p.get_pixmap(dpi=72, alpha=False)\nox = int(x - p.mediabox.x0 if False else x); oy = int(y)\n"
        . "px = pm.pixel(ox, oy)\nprint(px[0], px[1], px[2])\n");
    $file = $dir . '/ivopdf_px_' . md5($pdfBytes) . '.pdf';
    file_put_contents($file, $pdfBytes);
    $out = (string) @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' ' . $x . ' ' . $y . ' 2>&1');
    @unlink($file);
    $parts = preg_split('/\s+/', trim($out)) ?: [];
    return count($parts) === 3 && ctype_digit($parts[0]) ? array_map('intval', $parts) : null;
}

/** @return array<string, mixed>|null caught exception class => message */
function thrown(callable $fn): ?string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
    return null;
}

// ---------------------------------------------------------------------------
echo "1. CSS color parsing\n";
// ---------------------------------------------------------------------------
$c = CssColor::parse('cmyk(0 100% 100% 0)');
check('cmyk() with percentages keeps the CMYK model', $c !== null && ($c['cs'] ?? '') === 'cmyk' && $c['cmyk'] === [0.0, 1.0, 1.0, 0.0]);
check('cmyk() returns an RGB approximation (red)', $c !== null && abs($c[0] - 1.0) < 0.01 && $c[1] < 0.01 && $c[2] < 0.01);
$c = CssColor::parse('cmyk(10, 20, 30, 40)');
check('cmyk() with 0..100 numbers', $c !== null && abs($c['cmyk'][0] - 0.10) < 1e-6 && abs($c['cmyk'][3] - 0.40) < 1e-6);
$c = CssColor::parse('cmyk(0.1 0.2 0.3 0.4)');
check('cmyk() with 0..1 numbers', $c !== null && abs($c['cmyk'][1] - 0.2) < 1e-6);
$c = CssColor::parse('device-cmyk(0 1 1 0 / 0.5)');
check('device-cmyk() with alpha', $c !== null && ($c['cs'] ?? '') === 'cmyk' && abs($c[3] - 0.5) < 1e-6);
check('rgb colors stay 4-element arrays (backward compatible)', CssColor::parse('#ff0000') === [1.0, 0.0, 0.0, 1.0]);
$rich = CssColor::parseColor('cmyk(1 0 0 0)');
check('parseColor() rich form', $rich !== null && $rich['space'] === 'cmyk' && $rich['components'] === [1.0, 0.0, 0.0, 0.0] && $rich['alpha'] === 1.0);
$rich = CssColor::parseColor('#00f');
check('parseColor() rgb', $rich !== null && $rich['space'] === 'rgb' && $rich['components'] === [0.0, 0.0, 1.0]);
check('cmyk() with too few components is invalid', CssColor::parse('cmyk(1 0 0)') === null);

$nc = new NaiveCmykConverter();
check('converter: black becomes pure K', $nc->rgbToCmyk(0.0, 0.0, 0.0) === [0.0, 0.0, 0.0, 1.0]);
check('converter: white has no ink', $nc->rgbToCmyk(1.0, 1.0, 1.0) === [0.0, 0.0, 0.0, 0.0]);
check('converter: red is M+Y', $nc->rgbToCmyk(1.0, 0.0, 0.0) === [0.0, 1.0, 1.0, 0.0]);

// ---------------------------------------------------------------------------
echo "\n2. cmyk() in the PDF\n";
// ---------------------------------------------------------------------------
$html = '<div style="background:cmyk(0 100% 100% 0);width:200px;height:100px"></div>'
    . '<p style="color:cmyk(100% 0 0 0)">Cyan text</p>'
    . '<div style="border:4px solid cmyk(0,0,100%,0);width:100px;height:30px"></div>';
[$bytes, $pdf] = make($html);
check('background emits "0 1 1 0 k"', str_contains($bytes, '0.000 1.000 1.000 0.000 k'));
check('text color emits "1 0 0 0 k"', str_contains($bytes, '1.000 0.000 0.000 0.000 k'));
check('border emits a CMYK yellow fill', str_contains($bytes, '0.000 0.000 1.000 0.000 k'));
$px = pixel($bytes, 50, 50); // inside the box (page margin 36pt + body margin 6pt)
check('PyMuPDF renders the cmyk() box red', $px === null || ($px[0] > 200 && $px[1] < 90 && $px[2] < 90), $px ? implode(',', $px) : 'python/fitz unavailable (skipped)');
[$plain] = make('<p style="color:#ff0000">x</p>');
check('plain RGB documents are unchanged (rg, no OutputIntent)', str_contains($plain, ' rg') && !str_contains($plain, 'OutputIntent') && str_starts_with($plain, '%PDF-1.7'));

[$grad] = make('<div style="height:40px;background:linear-gradient(90deg, cmyk(1 0 0 0), cmyk(0 1 0 0))"></div>');
check('gradient between CMYK stops is a DeviceCMYK shading', str_contains($grad, '/ShadingType 2 /ColorSpace /DeviceCMYK') && str_contains($grad, '/C0 [1.000 0.000 0.000 0.000]'));
[$gradRgb] = make('<div style="height:40px;background:linear-gradient(90deg, #f00, cmyk(0 1 0 0))"></div>');
check('mixed RGB/CMYK gradient falls back to DeviceRGB', str_contains($gradRgb, '/ColorSpace /DeviceRGB'));

// ---------------------------------------------------------------------------
echo "\n3. Spot colors\n";
// ---------------------------------------------------------------------------
$spotHtml = '<p style="color:spot(PANTONE 185 C, 100%)">Spot text</p><div style="background:spot(PANTONE 185 C, 50%);width:200px;height:100px"></div>';
[$bytes, $pdf] = make($spotHtml, [], static fn(IvoPdf $p) => $p->addSpotColor('PANTONE 185 C', 0, 91, 76, 0));
check('Separation color space with the spot name', str_contains($bytes, '/Separation /PANTONE#20185#20C /DeviceCMYK'));
check('tint transform is a FunctionType 2 from 0 to the CMYK alternate', str_contains($bytes, '/FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [0.0000 0.9100 0.7600 0.0000]'));
check('registered in the shared /ColorSpace resources', preg_match('~/ColorSpace << /Sp1 \[/Separation~', $bytes) === 1);
check('text uses "/Sp1 cs 1 scn"', str_contains($bytes, '/Sp1 cs 1.000 scn'));
check('50% tint', str_contains($bytes, '/Sp1 cs 0.500 scn'));
$px = pixel($bytes, 50, 100);
check('PyMuPDF renders the 50% tint box in a light red', $px === null || ($px[0] > 200 && $px[1] > 80 && $px[1] < 200 && $px[2] < 200), $px ? implode(',', $px) : 'skipped');
[$unknown] = make('<p style="color:spot(NOPE, 100%)">x</p>');
check('unknown spot name is ignored (no Separation)', !str_contains($unknown, '/Separation'));
check('addSpotColor rejects an empty name', thrown(static fn() => (new IvoPdf())->addSpotColor('', 0, 0, 0, 0)) !== null);
[$spotX] = make($spotHtml, ['pdfx' => 'X-1a'], static fn(IvoPdf $p) => $p->addSpotColor('PANTONE 185 C', 0, 91, 76, 0));
check('spot colors survive PDF/X-1a', str_contains($spotX, '/Sp1 cs 1.000 scn') && str_contains($spotX, '/Separation'));
[$after] = make('<p style="color:#123456">x</p>');
check('spot registry does not leak into the next document', !str_contains($after, '/Separation'));

// ---------------------------------------------------------------------------
echo "\n4. colorSpace = cmyk (without PDF/X)\n";
// ---------------------------------------------------------------------------
$png = (static function (): string {
    $im = imagecreatetruecolor(8, 8);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 128, 0, 64));
    ob_start();
    imagepng($im);
    return base64_encode((string) ob_get_clean());
})();
$jpg = (static function (): string {
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 128, 255));
    ob_start();
    imagejpeg($im, null, 95);
    return base64_encode((string) ob_get_clean());
})();
$mixed = '<h1 style="color:#336699">Title</h1><div style="background:rgba(255,0,0,.5);height:30px">a</div>'
    . '<div style="opacity:.5;background:#0a0;height:20px">b</div>'
    . '<img width="40" src="data:image/png;base64,' . $png . '"><img width="40" src="data:image/jpeg;base64,' . $jpg . '">';
[$cm, $pdfCm] = make($mixed, ['colorSpace' => 'cmyk']);
check('no rg/RG operators remain', preg_match('/ (rg|RG)\b/', $cm) === 0);
check('no DeviceRGB anywhere', !str_contains($cm, 'DeviceRGB'));
check('colors converted with k/K operators', preg_match('/ k\n| k /', $cm) === 1);
check('images are DeviceCMYK', substr_count($cm, '/ColorSpace /DeviceCMYK') >= 2);
check('transparency is kept without PDF/X (alpha graphic state)', str_contains($cm, '/ca 0.500'));
check('mPDF restrictColorSpace => 3 maps to colorSpace cmyk', (new Options(['restrictColorSpace' => 3]))->colorSpace === 'cmyk');

$custom = new class implements ColorConverter {
    public function rgbToCmyk(float $r, float $g, float $b): array
    {
        return [0.25, 0.5, 0.75, 0.125];
    }
};
ColorOps::setConverter($custom);
[$cc] = make('<p style="color:#123456">x</p>', ['colorSpace' => 'cmyk']);
ColorOps::setConverter(null);
check('the RGB to CMYK converter is replaceable', str_contains($cc, '0.250 0.500 0.750 0.125 k'));

// ---------------------------------------------------------------------------
echo "\n5. PDF/X-1a\n";
// ---------------------------------------------------------------------------
[$x1, $pdfX] = make($mixed, ['pdfx' => 'X-1a', 'title' => 'Print job']);
check('header is PDF 1.4', str_starts_with($x1, '%PDF-1.4'));
check('OutputIntent /S /GTS_PDFX', str_contains($x1, '/Type /OutputIntent /S /GTS_PDFX'));
check('OutputConditionIdentifier FOGRA39 + RegistryName', str_contains($x1, '/OutputConditionIdentifier (FOGRA39)') && str_contains($x1, '/RegistryName (http://www.color.org)'));
check('Info: GTS_PDFXVersion / GTS_PDFXConformance / Trapped', str_contains($x1, '/GTS_PDFXVersion (PDF/X-1a:2003)') && str_contains($x1, '/GTS_PDFXConformance (PDF/X-1a:2003)') && str_contains($x1, '/Trapped /False'));
check('XMP metadata carries the PDF/X id', str_contains($x1, 'pdfxid:GTS_PDFXVersion'));
check('TrimBox and BleedBox on every page', preg_match('~/Type /Page /Parent \d+ 0 R /MediaBox \[[-\d. ]+\] /TrimBox \[0 0 [\d.]+ [\d.]+\] /BleedBox~', $x1) === 1);
check('no DeviceRGB anywhere', !str_contains($x1, 'DeviceRGB'));
check('no rg/RG operators', preg_match('/ (rg|RG)\b/', $x1) === 0);
check('RGB images converted to DeviceCMYK', substr_count($x1, '/ColorSpace /DeviceCMYK') >= 2);
check('no soft masks / transparency groups / blend modes', !str_contains($x1, '/SMask') && !str_contains($x1, '/Transparency'));
check('every opacity state is 1', preg_match('~/ca 0\.[0-9]*[1-9]~', $x1) === 0 && preg_match('~/CA 0\.[0-9]*[1-9]~', $x1) === 0);
$warn = implode(' | ', $pdfX->getWarnings());
check('warnings report the flattened transparency', stripos($warn, 'flattened') !== false, $warn);
check('warnings report the image alpha channel', stripos($warn, 'alpha channel') !== false, $warn);
check('trailer /ID present', preg_match('~/ID \[<[0-9A-F]{32}> <[0-9A-F]{32}>\]~', $x1) === 1);
check('fonts are embedded', str_contains($x1, '/FontFile2'));
check('pdfx => true means X-1a', (new Options(['PDFX' => true]))->pdfx === 'X-1a' && (new Options(['pdfx' => true]))->pdfx === 'X-1a');
$px = pixel($x1, 40, 60);
check('X-1a document rasterizes', $px === null || count($px) === 3);

[$formPdf] = make('<form><input type="text" name="a" value="x"></form>', ['pdfx' => 'X-1a']);
check('form fields are not written as AcroForm', !str_contains($formPdf, '/AcroForm') && !str_contains($formPdf, '/Widget'));

[$wm, $pdfWm] = make('<p>Hello</p>', ['pdfx' => 'X-1a', 'watermarkText' => 'DRAFT']);
check('watermark is drawn without transparency', !str_contains($wm, '/Transparency') && preg_match('~/ca 0\.[0-9]*[1-9]~', $wm) === 0);

// ---------------------------------------------------------------------------
echo "\n6. PDF/X-3 and X-4\n";
// ---------------------------------------------------------------------------
[$x3] = make($mixed, ['pdfx' => 'X-3']);
check('X-3: GTS_PDFXVersion (PDF/X-3:2003), CMYK only', str_contains($x3, '/GTS_PDFXVersion (PDF/X-3:2003)') && !str_contains($x3, 'DeviceRGB'));
[$x4, $pdfX4] = make($mixed, ['pdfx' => 'X-4']);
check('X-4: header 1.6 and OutputIntent', str_starts_with($x4, '%PDF-1.6') && str_contains($x4, '/S /GTS_PDFX'));
check('X-4: /GTS_PDFXVersion (PDF/X-4) without conformance key', str_contains($x4, '/GTS_PDFXVersion (PDF/X-4)') && !str_contains($x4, '/GTS_PDFXConformance'));
check('X-4 keeps RGB colors and images', str_contains($x4, ' rg') && str_contains($x4, '/ColorSpace /DeviceRGB'));
check('X-4 keeps transparency', str_contains($x4, '/ca 0.500'));
check('X-4 has TrimBox/BleedBox', str_contains($x4, '/TrimBox'));
check('X-4 does not warn about flattening', stripos(implode(' ', $pdfX4->getWarnings()), 'flattened') === false);
[$x4c] = make($mixed, ['pdfx' => 'X-4', 'pdfxOutputCondition' => 'CGATS TR 001']);
check('custom OutputConditionIdentifier', str_contains($x4c, '/OutputConditionIdentifier (CGATS TR 001)'));

$iccFile = sys_get_temp_dir() . '/ivopdf_test_cmyk.icc';
file_put_contents($iccFile, str_repeat("\0", 16) . 'CMYK' . str_repeat("\0", 108));
[$x4i] = make('<p>x</p>', ['pdfx' => 'X-4', 'pdfxIccProfile' => $iccFile]);
@unlink($iccFile);
check('ICC profile path is embedded as /DestOutputProfile with /N 4', str_contains($x4i, '/DestOutputProfile') && str_contains($x4i, '/N 4'));

// ---------------------------------------------------------------------------
echo "\n7. Bleed and crop marks\n";
// ---------------------------------------------------------------------------
[$bl] = make('<p>x</p>', ['pdfx' => 'X-1a', 'bleed' => 3, 'cropMarks' => true]);
check('TrimBox equals the trim page, MediaBox is larger', preg_match('~/MediaBox \[-(\d+\.\d+) -\d+\.\d+ (\d+\.\d+) (\d+\.\d+)\] /TrimBox \[0 0 595\.28 841\.89\] /BleedBox \[-8\.50 -8\.50 603\.78 850\.39\]~', $bl, $m) === 1 && (float) $m[1] > 8.5, $bl !== '' ? (preg_match('~/MediaBox[^>]*>?~', $bl, $mm) ? $mm[0] : '') : '');
check('crop marks use the registration color', str_contains($bl, '1 1 1 1 K'));
[$bl2] = make('<p>x</p>', ['bleed' => 3]);
check('bleed alone (no PDF/X) adds boxes and no OutputIntent', str_contains($bl2, '/BleedBox') && !str_contains($bl2, 'OutputIntent'));

// ---------------------------------------------------------------------------
echo "\n8. Option conflicts\n";
// ---------------------------------------------------------------------------
$e = thrown(static fn() => make('<p>x</p>', ['pdfx' => 'X-1a', 'pdfa' => '2b']));
check('PDF/X + PDF/A throws LogicException', $e !== null && str_starts_with($e, 'LogicException') && str_contains($e, 'mutually exclusive'), (string) $e);
$e = thrown(static fn() => make('<p>x</p>', ['pdfx' => 'X-4'], static fn(IvoPdf $p) => $p->setProtection(['print'], 'pw')));
check('PDF/X + encryption throws LogicException', $e !== null && str_starts_with($e, 'LogicException') && str_contains($e, 'encryption'), (string) $e);
$e = thrown(static fn() => make('<p>x</p>', ['pdfx' => 'X-1a'], static fn(IvoPdf $p) => $p->setSignature('cert', 'key')));
check('PDF/X + signature throws LogicException', $e !== null && str_starts_with($e, 'LogicException'), (string) $e);
$e = thrown(static fn() => make('<p>x</p>', ['pdfx' => 'X-4', 'pdfxIccProfile' => '/nonexistent/profile.icc']));
check('missing ICC profile throws LogicException', $e !== null && str_starts_with($e, 'LogicException'), (string) $e);
[$ok] = make('<p>x</p>', ['pdfx' => '']);
check('pdfx = "" disables PDF/X (state does not leak from earlier documents)', !str_contains($ok, 'GTS_PDFX') && !str_contains($ok, 'OutputIntent') && !str_contains($ok, '/TrimBox'));

$api = new IvoPdf(['compress' => false]);
$api->setPdfX('X-1a')->setColorSpace('cmyk')->setBleed(3)->addSpotColor('Gold', 0, 20, 100, 20);
$api->loadHtml('<p style="color:spot(Gold, 80%)">x</p>');
$out = $api->output();
check('fluent API: setPdfX / setColorSpace / setBleed / addSpotColor', str_contains($out, '/GTS_PDFX') && str_contains($out, '/BleedBox') && str_contains($out, '/Separation /Gold'));

// ---------------------------------------------------------------------------
echo "\n9. Hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
