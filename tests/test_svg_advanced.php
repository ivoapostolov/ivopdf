<?php

declare(strict_types=1);

/**
 * Advanced SVG tests: gradients (fill / stroke / text, units, transforms, spread, href inheritance, stop opacity),
 * clipPath, mask, markers, <style> / class, <pattern>, <image>, and WMF / EMF metafile images.
 * Pixel checks rasterize the PDF with PyMuPDF (python + fitz) and are skipped when it is unavailable.
 *
 * Run: php tests/test_svg_advanced.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Image\EmfConverter;
use IvoPdf\Image\MetafileConverter;
use IvoPdf\Image\WmfConverter;
use IvoPdf\IvoPdf;

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

/** Renders an SVG (or any body HTML) at the page's top-left corner and returns the PDF bytes. */
function svgPdf(string $bodyHtml, array $options = []): string
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $html = '<html><head><style>@page{margin:0}body{margin:0}</style></head><body>' . $bodyHtml . '</body></html>';
    return $pdf->loadHtml($html)->output();
}

function hasRaster(): bool
{
    static $ok = null;
    if ($ok === null) {
        $out = @shell_exec('python -c "import fitz; print(\'OK\')" 2>&1');
        $ok = is_string($out) && str_contains($out, 'OK');
    }
    return $ok;
}

/**
 * Samples pixel colours of page 1 at 96 dpi (1 SVG px = 1 pixel). Returns a list of [r, g, b] or null without python.
 *
 * @param list<array{0: int, 1: int}> $points
 * @return list<array{0: int, 1: int, 2: int}>|null
 */
function pixels(string $pdfBytes, array $points): ?array
{
    if (!hasRaster()) {
        return null;
    }
    $dir = sys_get_temp_dir();
    $pdfFile = tempnam($dir, 'ivosvg') . '.pdf';
    $pyFile = tempnam($dir, 'ivosvg') . '.py';
    file_put_contents($pdfFile, $pdfBytes);
    file_put_contents(
        $pyFile,
        "import sys, json, fitz\n"
        . "d = fitz.open(sys.argv[1]); pm = d[0].get_pixmap(dpi=96)\n"
        . "pts = json.loads(sys.argv[2])\n"
        . "print(json.dumps([list(pm.pixel(x, y))[:3] for x, y in pts]))\n"
    );
    $cmd = 'python ' . escapeshellarg($pyFile) . ' ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg((string) json_encode($points));
    $res = @shell_exec($cmd);
    @unlink($pdfFile);
    @unlink($pyFile);
    $decoded = is_string($res) ? json_decode(trim($res), true) : null;
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array{0: int, 1: int, 2: int}|null $got
 * @param array{0: int, 1: int, 2: int} $want
 */
function near(?array $got, array $want, int $tol = 28): bool
{
    if ($got === null) {
        return false;
    }
    foreach ([0, 1, 2] as $i) {
        if (abs($got[$i] - $want[$i]) > $tol) {
            return false;
        }
    }
    return true;
}

function pixelCheck(string $label, ?array $px, int $index, array $want, int $tol = 28): void
{
    global $skipped;
    if ($px === null) {
        $skipped++;
        echo "  [SKIP] {$label} (python/fitz unavailable)\n";
        return;
    }
    $got = $px[$index] ?? null;
    check($label, near($got, $want, $tol), 'got ' . ($got !== null ? implode(',', $got) : 'none') . ' want ' . implode(',', $want));
}

$NS = 'xmlns="http://www.w3.org/2000/svg"';

// ----------------------------------------------------------------------------------------------------------------
echo "--- gradients ---\n";

$svg = <<<SVG
<svg width="300" height="200" {$NS}>
<defs>
 <linearGradient id="lg"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient>
 <radialGradient id="rg"><stop offset="0" stop-color="#00ff00"/><stop offset="1" stop-color="#000000"/></radialGradient>
 <linearGradient id="user" gradientUnits="userSpaceOnUse" x1="100" y1="0" x2="200" y2="0"><stop offset="0" stop-color="#ffff00"/><stop offset="1" stop-color="#00ffff"/></linearGradient>
 <linearGradient id="inh" href="#lg" x1="1" x2="0"/>
 <linearGradient id="refl" x2="0.25" spreadMethod="reflect"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient>
</defs>
<rect x="20" y="10" width="200" height="30" fill="url(#lg)"/>
<circle cx="60" cy="100" r="40" fill="url(#rg)"/>
<rect x="100" y="60" width="200" height="30" fill="url(#user)"/>
<rect x="20" y="150" width="200" height="30" fill="url(#inh)"/>
<rect x="100" y="100" width="200" height="30" fill="url(#refl)"/>
<rect x="240" y="10" width="50" height="40" fill="none" stroke="url(#lg)" stroke-width="10"/>
</svg>
SVG;
$pdf = svgPdf($svg);
check('gradient output has a /ShadingType 2 and a /ShadingType 3 object', str_contains($pdf, '/ShadingType 2') && str_contains($pdf, '/ShadingType 3'));
check('gradient stroke is a /PatternType 2 pattern with a /Pattern resource', str_contains($pdf, '/PatternType 2') && str_contains($pdf, '/Pattern <<'));
check('stitching or axial function emitted', str_contains($pdf, '/FunctionType'));
$px = pixels($pdf, [[22, 25], [218, 25], [120, 25], [60, 100], [96, 100], [102, 75], [298, 75], [30, 165], [215, 165], [102, 115], [149, 115], [248, 12], [248, 48]]);
pixelCheck('objectBoundingBox linear gradient: left end is red', $px, 0, [255, 0, 0], 40);
pixelCheck('objectBoundingBox linear gradient: right end is blue', $px, 1, [0, 0, 255], 40);
pixelCheck('objectBoundingBox linear gradient: middle is purple', $px, 2, [128, 0, 128], 40);
pixelCheck('radial gradient: centre is green', $px, 3, [0, 255, 0], 40);
pixelCheck('radial gradient: toward the rim is darker', $px, 4, [0, 60, 0], 70);
pixelCheck('userSpaceOnUse gradient starts yellow at x1', $px, 5, [255, 255, 0], 45);
pixelCheck('userSpaceOnUse gradient pads cyan after x2', $px, 6, [0, 255, 255], 30);
pixelCheck('href inheritance with swapped x1/x2: left is blue', $px, 7, [0, 0, 255], 45);
pixelCheck('href inheritance with swapped x1/x2: right is red', $px, 8, [255, 0, 0], 45);
pixelCheck('spreadMethod reflect: starts red', $px, 9, [255, 0, 0], 50);
pixelCheck('spreadMethod reflect: blue at the first period end', $px, 10, [0, 0, 255], 70);
pixelCheck('gradient stroke is painted (top-left corner area)', $px, 11, [255, 0, 0], 90);

// gradientTransform + gradient text
$svg = <<<SVG
<svg width="300" height="100" {$NS}>
<defs><linearGradient id="g" gradientTransform="rotate(90)"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient>
<linearGradient id="t"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#ff0000"/></linearGradient></defs>
<rect x="10" y="10" width="80" height="80" fill="url(#g)"/>
<text x="110" y="70" font-size="70" font-weight="bold" fill="url(#t)">MM</text>
</svg>
SVG;
$pdf = svgPdf($svg);
$px = pixels($pdf, [[50, 12], [50, 88], [118, 50]]);
pixelCheck('gradientTransform rotate(90): top is red', $px, 0, [255, 0, 0], 50);
pixelCheck('gradientTransform rotate(90): bottom is blue', $px, 1, [0, 0, 255], 50);
check('gradient text uses text clipping mode (7 Tr)', str_contains($pdf, '7 Tr'));

// stop-opacity
$svg = <<<SVG
<svg width="300" height="100" {$NS}>
<defs><linearGradient id="o"><stop offset="0" stop-color="#000000" stop-opacity="1"/><stop offset="1" stop-color="#000000" stop-opacity="0"/></linearGradient></defs>
<rect x="10" y="10" width="200" height="50" fill="url(#o)"/>
</svg>
SVG;
$pdf = svgPdf($svg);
check('stop-opacity produces a luminosity /SMask', str_contains($pdf, '/SMask') && str_contains($pdf, '/Luminosity'));
$px = pixels($pdf, [[14, 30], [206, 30]]);
pixelCheck('stop-opacity: opaque black at the start', $px, 0, [0, 0, 0], 40);
pixelCheck('stop-opacity: transparent (white page) at the end', $px, 1, [255, 255, 255], 30);

// ----------------------------------------------------------------------------------------------------------------
echo "--- clipPath / mask ---\n";

$svg = <<<SVG
<svg width="300" height="200" {$NS}>
<defs>
 <clipPath id="c"><circle cx="60" cy="60" r="40"/></clipPath>
 <clipPath id="bb" clipPathUnits="objectBoundingBox"><rect x="0" y="0" width="0.5" height="1"/></clipPath>
 <clipPath id="eo"><path clip-rule="evenodd" d="M150 10h60v60h-60z M165 25h30v30h-30z"/></clipPath>
 <clipPath id="outer"><rect x="10" y="120" width="60" height="60"/></clipPath>
 <clipPath id="inner" clip-path="url(#outer)"><rect x="40" y="100" width="100" height="100"/></clipPath>
</defs>
<rect x="0" y="0" width="150" height="150" fill="#ff0000" clip-path="url(#c)"/>
<rect x="220" y="10" width="60" height="60" fill="#00ff00" clip-path="url(#bb)"/>
<rect x="140" y="0" width="80" height="80" fill="#0000ff" clip-path="url(#eo)"/>
<rect x="0" y="100" width="200" height="100" fill="#ff00ff" clip-path="url(#inner)"/>
</svg>
SVG;
$pdf = svgPdf($svg);
$px = pixels($pdf, [[60, 60], [10, 10], [140, 140], [225, 40], [275, 40], [155, 15], [180, 40], [55, 150], [100, 150], [20, 150]]);
pixelCheck('clipPath circle: centre is painted', $px, 0, [255, 0, 0]);
pixelCheck('clipPath circle: corner outside the clip stays white', $px, 1, [255, 255, 255]);
pixelCheck('clipPath circle: far outside stays white', $px, 2, [255, 255, 255]);
pixelCheck('clipPathUnits=objectBoundingBox: left half painted', $px, 3, [0, 255, 0]);
pixelCheck('clipPathUnits=objectBoundingBox: right half clipped', $px, 4, [255, 255, 255]);
pixelCheck('clip-rule evenodd: ring is painted', $px, 5, [0, 0, 255]);
pixelCheck('clip-rule evenodd: hole is not painted', $px, 6, [255, 255, 255]);
pixelCheck('nested clipPath intersects: overlap painted', $px, 7, [255, 0, 255]);
pixelCheck('nested clipPath intersects: outside the outer clip is white', $px, 8, [255, 255, 255]);
pixelCheck('nested clipPath intersects: outside the inner clip is white', $px, 9, [255, 255, 255]);

$svg = <<<SVG
<svg width="300" height="100" {$NS}>
<defs>
 <mask id="m"><rect x="0" y="0" width="100" height="100" fill="#ffffff"/><rect x="40" y="0" width="20" height="100" fill="#000000"/></mask>
 <mask id="g"><linearGradient id="lg"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#000"/></linearGradient><rect x="110" y="0" width="100" height="100" fill="url(#lg)"/></mask>
</defs>
<rect x="0" y="0" width="100" height="100" fill="#ff0000" mask="url(#m)"/>
<rect x="110" y="0" width="100" height="100" fill="#0000ff" mask="url(#g)"/>
</svg>
SVG;
$pdf = svgPdf($svg);
check('mask produces a /SMask with a /Luminosity soft mask group', str_contains($pdf, '/SMask') && str_contains($pdf, '/S /Luminosity'));
$px = pixels($pdf, [[20, 50], [50, 50], [80, 50], [115, 50], [205, 50]]);
pixelCheck('mask: white mask area shows the red fill', $px, 0, [255, 0, 0]);
pixelCheck('mask: black mask stripe hides the fill', $px, 1, [255, 255, 255]);
pixelCheck('mask: white again after the stripe', $px, 2, [255, 0, 0]);
pixelCheck('mask with gradient: left is opaque blue', $px, 3, [0, 0, 255], 60);
pixelCheck('mask with gradient: right end fades out', $px, 4, [255, 255, 255], 50);

// ----------------------------------------------------------------------------------------------------------------
echo "--- markers / style / pattern / image ---\n";

$svg = <<<SVG
<svg width="300" height="120" {$NS}>
<defs>
 <marker id="a" markerWidth="10" markerHeight="10" refX="5" refY="5" orient="auto" markerUnits="userSpaceOnUse"><rect x="0" y="0" width="10" height="10" fill="#ff0000"/></marker>
 <marker id="b" markerWidth="10" markerHeight="10" refX="5" refY="5" markerUnits="userSpaceOnUse"><rect x="0" y="0" width="10" height="10" fill="#0000ff"/></marker>
</defs>
<path d="M30 60 L130 60 L230 60" fill="none" stroke="#999999" stroke-width="2" marker-start="url(#a)" marker-mid="url(#b)" marker-end="url(#a)"/>
</svg>
SVG;
$pdf = svgPdf($svg);
$px = pixels($pdf, [[30, 60], [130, 60], [230, 60], [80, 60], [80, 40]]);
pixelCheck('marker-start is drawn at the path start', $px, 0, [255, 0, 0]);
pixelCheck('marker-mid is drawn at the inner vertex', $px, 1, [0, 0, 255]);
pixelCheck('marker-end is drawn at the path end', $px, 2, [255, 0, 0]);
pixelCheck('the path itself is drawn between markers', $px, 3, [153, 153, 153], 40);
pixelCheck('nothing is drawn away from the path', $px, 4, [255, 255, 255]);

$svg = <<<SVG
<svg width="300" height="100" {$NS}>
<style type="text/css">
 .box { fill: #00ff00; stroke: none }
 rect.big { fill: #0000ff }
 #special { fill: #ff0000 }
 g > circle { fill: #ffff00 }
</style>
<rect class="box" x="10" y="10" width="60" height="60" fill="#000000"/>
<rect class="box big" x="80" y="10" width="60" height="60"/>
<rect id="special" class="box" x="150" y="10" width="60" height="60"/>
<g><circle cx="250" cy="40" r="30"/></g>
<rect class="box" x="10" y="75" width="60" height="20" style="fill:#ff00ff"/>
</svg>
SVG;
$pdf = svgPdf($svg);
$px = pixels($pdf, [[40, 40], [110, 40], [180, 40], [250, 40], [40, 85]]);
pixelCheck('<style> class rule beats the presentation attribute', $px, 0, [0, 255, 0]);
pixelCheck('<style> tag.class selector (higher specificity)', $px, 1, [0, 0, 255]);
pixelCheck('<style> #id selector wins over .class', $px, 2, [255, 0, 0]);
pixelCheck('<style> child combinator selector', $px, 3, [255, 255, 0]);
pixelCheck('inline style beats the <style> rule', $px, 4, [255, 0, 255]);

$svg = <<<SVG
<svg width="300" height="100" {$NS}>
<defs><pattern id="p" width="20" height="20" patternUnits="userSpaceOnUse"><rect x="0" y="0" width="10" height="10" fill="#ff0000"/><rect x="10" y="10" width="10" height="10" fill="#0000ff"/></pattern></defs>
<rect x="0" y="0" width="200" height="100" fill="url(#p)"/>
</svg>
SVG;
$pdf = svgPdf($svg);
check('<pattern> fill produces a /PatternType 1 tiling pattern', str_contains($pdf, '/PatternType 1'));
$px = pixels($pdf, [[5, 5], [15, 15], [15, 5], [55, 55], [25, 5]]);
pixelCheck('pattern tile: red cell', $px, 0, [255, 0, 0]);
pixelCheck('pattern tile: blue cell', $px, 1, [0, 0, 255]);
pixelCheck('pattern tile: empty cell is transparent', $px, 2, [255, 255, 255]);
pixelCheck('pattern repeats (second tile blue cell)', $px, 3, [0, 0, 255]);
pixelCheck('pattern repeats (second tile red cell)', $px, 4, [255, 0, 0]);

if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(4, 4);
    imagefilledrectangle($im, 0, 0, 3, 3, (int) imagecolorallocate($im, 0, 200, 0));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    $uri = 'data:image/png;base64,' . base64_encode($png);
    $svg = "<svg width=\"200\" height=\"100\" {$NS}><image x=\"20\" y=\"10\" width=\"80\" height=\"40\" preserveAspectRatio=\"none\" href=\"{$uri}\"/></svg>";
    $pdf = svgPdf($svg);
    check('<image> inside SVG embeds an image XObject', str_contains($pdf, '/Subtype /Image'));
    $px = pixels($pdf, [[60, 30], [110, 30]]);
    pixelCheck('<image> is painted at its box', $px, 0, [0, 200, 0]);
    pixelCheck('<image> does not paint outside its box', $px, 1, [255, 255, 255]);
}

// ----------------------------------------------------------------------------------------------------------------
echo "--- WMF / EMF ---\n";

/** @param list<int> $words */
function wmfRec(int $fn, array $words = []): string
{
    $params = '';
    foreach ($words as $w) {
        $params .= pack('v', $w & 0xFFFF);
    }
    return pack('Vv', (6 + strlen($params)) / 2, $fn) . $params;
}

function wmfColor(int $r, int $g, int $b): array
{
    return [$r | ($g << 8), $b];
}

function makeWmf(): string
{
    $rec = wmfRec(0x0103, [8]) . wmfRec(0x020B, [0, 0]) . wmfRec(0x020C, [100, 200]);
    $rec .= wmfRec(0x02FC, array_merge([0], wmfColor(255, 0, 0), [0]));       // brush 0: red
    $rec .= wmfRec(0x02FC, array_merge([0], wmfColor(0, 0, 255), [0]));       // brush 1: blue
    $rec .= wmfRec(0x02FA, array_merge([0, 8, 8], wmfColor(0, 160, 0)));      // pen 2: green, 8 wide
    $rec .= wmfRec(0x02FA, array_merge([5, 0, 0], wmfColor(0, 0, 0)));        // pen 3: null
    $rec .= wmfRec(0x012D, [3]) . wmfRec(0x012D, [0]);
    $rec .= wmfRec(0x041B, [90, 90, 10, 10]);                                  // rectangle 10,10 - 90,90
    $rec .= wmfRec(0x012D, [1]);
    $rec .= wmfRec(0x0418, [90, 190, 10, 110]);                                // ellipse 110,10 - 190,90
    $rec .= wmfRec(0x012D, [2]);
    $rec .= wmfRec(0x0325, [3, 10, 96, 100, 96, 190, 96]);                     // polyline along y=96
    $rec .= wmfRec(0x0000);
    $std = pack('vvvVvVv', 1, 9, 0x0300, (18 + strlen($rec)) / 2, 4, 10, 0);
    $plc = pack('V', 0x9AC6CDD7) . pack('v', 0) . pack('s4', 0, 0, 200, 100) . pack('v', 96) . pack('V', 0);
    $sum = 0;
    foreach (str_split($plc, 2) as $w) {
        $sum ^= (int) unpack('v', $w)[1];
    }
    return $plc . pack('v', $sum) . $std . $rec;
}

function emfRec(int $type, string $data = ''): string
{
    $size = 8 + strlen($data);
    if ($size % 4 !== 0) {
        $data .= str_repeat("\0", 4 - $size % 4);
        $size = 8 + strlen($data);
    }
    return pack('VV', $type, $size) . $data;
}

function makeEmf(): string
{
    $rec = emfRec(39, pack('VVVV', 1, 0, 0x000000FF, 0));         // brush 1: red
    $rec .= emfRec(39, pack('VVVV', 2, 0, 0x00FF0000, 0));        // brush 2: blue
    $rec .= emfRec(38, pack('VVVVVV', 3, 0, 8, 0, 0x0000A000, 0)); // pen 3: green, 8 wide
    $rec .= emfRec(37, pack('V', 0x80000008)) . emfRec(37, pack('V', 1));
    $rec .= emfRec(43, pack('l4', 10, 10, 90, 90));
    $rec .= emfRec(37, pack('V', 2));
    $rec .= emfRec(42, pack('l4', 110, 10, 190, 90));
    $rec .= emfRec(37, pack('V', 3));
    $rec .= emfRec(87, pack('l4', 10, 96, 190, 96) . pack('V', 3) . pack('s*', 10, 96, 100, 96, 190, 96));
    $rec .= emfRec(14, pack('VVV', 0, 16, 20));
    $hdr = pack('VV', 1, 108) . pack('l4', 0, 0, 199, 99) . pack('l4', 0, 0, 5292, 2646) . pack('VV', 0x464D4520, 0x10000);
    $hdr .= pack('VVvvVVV', 108 + strlen($rec), 12, 3, 0, 0, 0, 0) . pack('l4', 800, 600, 211, 158) . pack('VVVVV', 0, 0, 0, 0, 0);
    return $hdr . $rec;
}

$wmf = makeWmf();
$emf = makeEmf();
check('WMF magic bytes detected', WmfConverter::isWmf($wmf) && MetafileConverter::isMetafile($wmf));
check('EMF signature detected', EmfConverter::isEmf($emf) && MetafileConverter::isMetafile($emf));
check('plain text is not a metafile', !MetafileConverter::isMetafile('hello world, not a metafile at all.....'));
$wmfSvg = MetafileConverter::toSvg($wmf) ?? '';
$emfSvg = MetafileConverter::toSvg($emf) ?? '';
check('WMF converts to SVG with its shapes', str_contains($wmfSvg, '<svg') && str_contains($wmfSvg, '#ff0000') && str_contains($wmfSvg, '#0000ff'));
check('EMF converts to SVG with its shapes', str_contains($emfSvg, '<svg') && str_contains($emfSvg, '#ff0000') && str_contains($emfSvg, '#0000ff'));
check('truncated metafile does not throw', MetafileConverter::toSvg(substr($wmf, 0, 50)) === null || true);

foreach (['wmf' => [$wmf, 'x-wmf'], 'emf' => [$emf, 'x-emf']] as $kind => [$bytes, $mime]) {
    $pdf = svgPdf('<img src="data:image/' . $mime . ';base64,' . base64_encode($bytes) . '" width="200" height="100">');
    $px = pixels($pdf, [[50, 50], [150, 50], [190, 3], [50, 96], [150, 96]]);
    pixelCheck(strtoupper($kind) . ' image: red rectangle in place', $px, 0, [255, 0, 0]);
    pixelCheck(strtoupper($kind) . ' image: blue ellipse in place', $px, 1, [0, 0, 255]);
    pixelCheck(strtoupper($kind) . ' image: empty corner is white', $px, 2, [255, 255, 255]);
    pixelCheck(strtoupper($kind) . ' image: green pen polyline drawn', $px, 3, [0, 160, 0], 40);
    pixelCheck(strtoupper($kind) . ' image: green pen polyline drawn (right)', $px, 4, [0, 160, 0], 40);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivosvg_' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents($tmp . '/logo.wmf', $wmf);
file_put_contents($tmp . '/logo.emf', $emf);
$pdf = svgPdf('<img src="logo.wmf" width="200" height="100"><br><img src="logo.emf" width="200" height="100">', ['basePath' => $tmp]);
$px = pixels($pdf, [[50, 50], [50, 160]]);
pixelCheck('<img src="logo.wmf"> from an allowed local path renders', $px, 0, [255, 0, 0]);
pixelCheck('<img src="logo.emf"> from an allowed local path renders', $px, 1, [255, 0, 0]);
@unlink($tmp . '/logo.wmf');
@unlink($tmp . '/logo.emf');
@rmdir($tmp);

// ----------------------------------------------------------------------------------------------------------------
echo "--- regressions ---\n";

$pdf = svgPdf("<svg width=\"100\" height=\"50\" {$NS}><defs><linearGradient id=\"x\"><stop offset=\"0\" stop-color=\"#f00\"/></linearGradient></defs><rect width=\"100\" height=\"50\" fill=\"url(#x)\"/><rect width=\"10\" height=\"10\" fill=\"url(#missing) #00f\"/></svg>");
$px = pixels($pdf, [[50, 25], [5, 5]]);
pixelCheck('single-stop gradient is a solid colour', $px, 0, [255, 0, 0]);
pixelCheck('missing paint server uses its fallback colour', $px, 1, [0, 0, 255]);
check('document with SVG paint servers is a valid PDF', str_starts_with($pdf, '%PDF-') && str_contains($pdf, '%%EOF'));
check('no PHP warnings or deprecations were raised', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));

echo "\n=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
