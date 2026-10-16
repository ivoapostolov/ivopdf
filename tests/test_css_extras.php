<?php

declare(strict_types=1);

/**
 * Tests for the extra CSS / image features: text-shadow, outline, clip-path, filter, mix-blend-mode, border-image,
 * border styles + radius, display: contents / inline-table, table-layout: fixed, percentage heights, aspect-ratio,
 * visibility, group opacity, list markers, BMP / TIFF decoders, EXIF orientation, image-rendering.
 *
 * Run: php tests/test_css_extras.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Image\BmpDecoder;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Image\ImageVariants;
use IvoPdf\Image\TiffDecoder;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Options;
use IvoPdf\Pdf\EffectsPainter;
use IvoPdf\Pdf\PdfDocument;

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

/** @return array{0: IvoPdf, 1: string} */
function renderPdf(string $html, array $options = []): array
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html);
    $bytes = $pdf->output();
    return [$pdf, $bytes];
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

function dataUri(string $bytes, string $mime): string
{
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/** Builds a w*h RGB test pattern: left half red, right half blue, bottom-left quadrant green. */
function patternPixel(int $x, int $y, int $w, int $h): array
{
    if ($x < intdiv($w, 2)) {
        return $y >= intdiv($h, 2) ? [0, 200, 0] : [220, 20, 20];
    }
    return [20, 20, 220];
}

// ---------------------------------------------------------------------------
echo "1. Border styles and radius\n";
// ---------------------------------------------------------------------------
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border:9pt double #c00">x</div>');
check('double border draws two rings (even-odd fills)', substr_count($b, "f*\n") >= 2);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border:8pt groove #36c">x</div>');
check('groove border: dark and light half rings per side', substr_count($b, "f*\n") >= 8);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border:8pt inset #36c">x</div>');
check('inset border uses two tones', substr_count($b, "f*\n") >= 4 && substr_count($b, ' rg') >= 4);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border-width:2pt 10pt 6pt 14pt;border-style:solid;border-color:red green blue orange;border-radius:12pt">x</div>');
check('non-uniform widths/colors with radius: one clipped ring per side', substr_count($b, "W n\n") >= 4 && substr_count($b, "f*\n") >= 4);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border:4pt solid;border-color:red blue;">x</div>');
check('mitred corners between differently coloured sides (wedge clip)', substr_count($b, "W n\n") >= 4);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;border:4pt solid red">x</div>');
check('plain solid border keeps the fast path (no wedge clip)', substr_count($b, "W n\n") <= 1);

// ---------------------------------------------------------------------------
echo "\n2. Outline\n";
// ---------------------------------------------------------------------------
[$pdf, $b] = renderPdf('<div id="o" style="width:100pt;height:40pt;outline:3pt solid #0a0;outline-offset:4pt">x</div><div id="n" style="width:100pt;height:10pt">y</div>');
check('outline drawn as ring', str_contains($b, "f*\n"));
check('outline does not change layout', abs((byId($pdf, 'o')?->height ?? 0) - 40.0) < 0.1 && abs((byId($pdf, 'o')?->width ?? 0) - 100.0) < 0.1);
[$pdf2] = renderPdf('<div id="o" style="width:100pt;height:40pt">x</div><div id="n" style="width:100pt;height:10pt">y</div>');
check('outline does not shift following boxes', abs((byId($pdf, 'n')?->y ?? 0) - (byId($pdf2, 'n')?->y ?? 1)) < 0.01);
[, $b] = renderPdf('<div style="width:100pt;height:40pt;outline:2px dashed red">x</div>');
check('dashed outline uses a dash pattern', str_contains($b, ' d'));

// ---------------------------------------------------------------------------
echo "\n3. text-shadow\n";
// ---------------------------------------------------------------------------
[, $plain] = renderPdf('<p>Shadow</p>');
[, $one] = renderPdf('<p style="text-shadow:2px 2px 0 #f00">Shadow</p>');
[, $multi] = renderPdf('<p style="text-shadow:2px 2px 0 #f00, 4px 4px 0 #00f">Shadow</p>');
[, $blur] = renderPdf('<p style="text-shadow:0 0 6px rgba(0,0,0,.5)">Shadow</p>');
$bt = static fn(string $s): int => substr_count($s, "BT\n") + substr_count($s, "BT /");
check('text-shadow adds a shadow copy before the text', $bt($one) === $bt($plain) * 2, $bt($one) . ' vs ' . $bt($plain));
check('two shadows -> three text copies', $bt($multi) === $bt($plain) * 3);
check('blurred shadow uses several alpha layers', $bt($blur) > $bt($plain) * 4);
check('shadow colour is applied', str_contains($one, '1.000 0.000 0.000 rg'));
[$pdf] = renderPdf('<div style="text-shadow:1px 1px #888"><p id="c">child</p></div>');
check('text-shadow is inherited', byId($pdf, 'c')?->style->get('text-shadow') === '1px 1px #888');

// ---------------------------------------------------------------------------
echo "\n4. clip-path\n";
// ---------------------------------------------------------------------------
$ep = new EffectsPainter(new PdfDocument(new Options(), new \IvoPdf\Font\FontManager((new Options())->fontDir), new ImageLoader()), 800.0);
$circle = $ep->clipPath('circle(40px at 50% 50%)', 10.0, 10.0, 100.0, 100.0, 12.0);
check('circle() -> four Bezier arcs + clip', substr_count($circle, " c\n") === 4 && str_contains($circle, 'W n'));
check('ellipse() clip', substr_count($ep->clipPath('ellipse(50% 30% at 50% 50%)', 0.0, 0.0, 100.0, 60.0, 12.0), " c\n") === 4);
check('inset() with round -> curved rect', substr_count($ep->clipPath('inset(10px 5px round 8px)', 0.0, 0.0, 100.0, 60.0, 12.0), " c\n") === 4);
check('inset() plain -> re', str_contains($ep->clipPath('inset(10%)', 0.0, 0.0, 100.0, 100.0, 12.0), ' re'));
$poly = $ep->clipPath('polygon(50% 0%, 100% 100%, 0% 100%)', 0.0, 0.0, 100.0, 100.0, 12.0);
check('polygon() with % -> 3 vertices', substr_count($poly, " l\n") === 2 && str_starts_with($poly, '50.00 800.00 m'), $poly);
check('unsupported clip-path ignored', $ep->clipPath('url(#x)', 0.0, 0.0, 10.0, 10.0, 12.0) === '');
[, $b] = renderPdf('<div style="width:100pt;height:100pt;background:#f60;clip-path:circle(50%)">x</div>');
check('clip-path is applied around the element painting', substr_count($b, " c\n") >= 4 && str_contains($b, 'W n'));

// ---------------------------------------------------------------------------
echo "\n5. filter / mix-blend-mode / opacity\n";
// ---------------------------------------------------------------------------
$im = imagecreatetruecolor(8, 8);
imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocate($im, 255, 0, 0));
ob_start();
imagepng($im);
$redPng = (string) ob_get_clean();
$red = dataUri($redPng, 'image/png');
$opts = new Options(['compress' => false]);
$loader = new ImageLoader(null, false, $opts);
$gray = $loader->loadVariant($red, 'grayscale(1)');
$rgb = $gray !== null ? (string) gzuncompress($gray['data']) : '';
check('grayscale(1) turns red into equal channels', strlen($rgb) === 8 * 8 * 3 && $rgb[0] === $rgb[1] && $rgb[1] === $rgb[2] && ord($rgb[0]) > 40 && ord($rgb[0]) < 80, bin2hex(substr($rgb, 0, 3)));
$sepia = $loader->loadVariant($red, 'sepia(1)');
$srgb = $sepia !== null ? (string) gzuncompress($sepia['data']) : '';
check('sepia(1) shifts hue (R > G > B)', $srgb !== '' && ord($srgb[0]) > ord($srgb[1]) && ord($srgb[1]) > ord($srgb[2]));
$bright = $loader->loadVariant($red, 'brightness(0.5)');
check('brightness(0.5) halves red', $bright !== null && abs(ord((string) gzuncompress($bright['data'])[0]) - 128) <= 1);
$inv = $loader->loadVariant($red, 'invert(1)');
check('invert(1) -> cyan', $inv !== null && bin2hex(substr((string) gzuncompress($inv['data']), 0, 3)) === '00ffff');
check('variants are cached by filter key', $loader->loadVariant($red, 'grayscale(1)')['id'] === $gray['id'] && $gray['id'] !== $loader->load($red)['id']);
$px = $loader->loadVariant($red, 'none', true);
check('pixelated variant has interpolate=false', $px !== null && $px['interpolate'] === false);
check('filter list parser', EffectsPainter::parseFilterList('grayscale(50%) drop-shadow(2px 2px 4px rgba(0,0,0,.5)) blur(2px)') === [['grayscale', '50%'], ['drop-shadow', '2px 2px 4px rgba(0,0,0,.5)'], ['blur', '2px']]);
check('opacity() filter folds into opacity', abs(EffectsPainter::filterOpacity('opacity(40%) grayscale(1)') - 0.4) < 0.001);
[$pdf, $b] = renderPdf('<img src="' . $red . '" style="width:40pt;filter:grayscale(1) blur(3px)"><img src="' . $red . '" style="width:40pt;image-rendering:pixelated">');
check('blur() is ignored with a warning', (bool) array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, 'blur')));
check('/Interpolate false written for pixelated, true otherwise', str_contains($b, '/Interpolate false') && str_contains($b, '/Interpolate true'));
[, $b] = renderPdf('<div style="mix-blend-mode:multiply;background:#fc0;width:50pt;height:20pt">x</div>');
check('mix-blend-mode -> /BM /Multiply ExtGState', str_contains($b, '/BM /Multiply'));
[, $b] = renderPdf('<div style="mix-blend-mode:screen;background:#fc0;width:50pt;height:20pt">x</div>');
check('mix-blend-mode screen', str_contains($b, '/BM /Screen'));
[, $b] = renderPdf('<div style="filter:drop-shadow(3px 3px 4px rgba(0,0,0,.5));background:#eee;width:50pt;height:20pt">x</div>');
check('drop-shadow() approximated like box-shadow (alpha layers)', substr_count($b, ' gs') >= 2);
[, $b] = renderPdf('<div style="opacity:.5;width:80pt"><div style="background:#000;height:10pt"></div><div style="background:#f00;height:10pt;margin-top:-5pt"></div></div>');
check('opacity on a group renders a transparency-group Form XObject', str_contains($b, '/Subtype /Form') && str_contains($b, '/S /Transparency'));
[, $b] = renderPdf('<div style="opacity:.5;width:80pt;height:10pt;background:#000"></div>');
check('opacity on a leaf box does not need a group', !str_contains($b, '/Subtype /Form'));

// ---------------------------------------------------------------------------
echo "\n6. border-image\n";
// ---------------------------------------------------------------------------
$sprite = imagecreatetruecolor(30, 30);
imagefilledrectangle($sprite, 0, 0, 29, 29, imagecolorallocate($sprite, 0, 0, 255));
ob_start();
imagepng($sprite);
$spriteUri = dataUri((string) ob_get_clean(), 'image/png');
[, $b] = renderPdf('<div style="width:100pt;height:50pt;border:10pt solid;border-image:url(' . $spriteUri . ') 10 stretch">x</div>');
check('border-image url(): nine-slice image draws', substr_count($b, ' Do') >= 8);
[, $b] = renderPdf('<div style="width:100pt;height:50pt;border:6pt solid;border-image:linear-gradient(to right,#f06,#06f) 1">x</div>');
check('border-image gradient: shading per slice', substr_count($b, ' sh') >= 8);
[$pdf] = renderPdf('<div id="bi" style="border:4px solid;border-image:linear-gradient(red,blue) 5 / 2 / 1 round stretch">x</div>');
$st = byId($pdf, 'bi')->style;
check('border-image shorthand expands', str_contains($st->get('border-image-source'), 'linear-gradient') && $st->get('border-image-slice') === '5' && $st->get('border-image-width') === '2' && $st->get('border-image-repeat') === 'round stretch', json_encode([$st->get('border-image-source'), $st->get('border-image-slice'), $st->get('border-image-width'), $st->get('border-image-repeat')]));

// ---------------------------------------------------------------------------
echo "\n7. Layout: display: contents, inline-table, table-layout, % heights, aspect-ratio\n";
// ---------------------------------------------------------------------------
[$pdf] = renderPdf('<div style="display:grid;grid-template-columns:100pt 100pt 100pt;width:300pt"><div id="a">A</div><div style="display:contents"><div id="b">B</div><div id="c">C</div></div></div>');
$ax = byId($pdf, 'a')->x;
check('display: contents promotes children into the grid', abs(byId($pdf, 'b')->x - ($ax + 100.0)) < 1.0 && abs(byId($pdf, 'c')->x - ($ax + 200.0)) < 1.0, round(byId($pdf, 'b')->x - $ax, 1) . ' / ' . round(byId($pdf, 'c')->x - $ax, 1));
[$pdf] = renderPdf('<div id="w" style="width:400pt">x <table id="t" style="display:inline-table"><tr><td>1</td><td>2</td></tr></table> y</div>');
$t = byId($pdf, 't');
check('inline-table shrinks to content and flows inline', $t !== null && $t->width < 100.0 && count(lineTexts(byId($pdf, 'w'))) >= 1 && $t->isInlineLevel(), (string) ($t?->width ?? 'n/a'));
[$pdf] = renderPdf('<div style="width:300pt">a <span id="f" style="display:inline-flex;gap:4pt"><b>1</b><b>2</b></span> <span id="g" style="display:inline-grid;grid-template-columns:20pt 20pt"><i>3</i><i>4</i></span></div>');
check('inline-flex / inline-grid are shrink-to-fit inline boxes', byId($pdf, 'f')->width < 60.0 && byId($pdf, 'g')->width < 60.0 && abs(byId($pdf, 'f')->y - byId($pdf, 'g')->y) < 8.0);
[$pdf] = renderPdf('<table style="table-layout:fixed;width:300pt;border-collapse:collapse"><colgroup><col style="width:100pt"><col style="width:50pt"></colgroup><tr><td id="c1">a very long cell content that would normally widen the column</td><td id="c2">b</td><td id="c3">c</td></tr></table>');
check('table-layout: fixed honours <col> widths exactly', abs(byId($pdf, 'c1')->width - 100.0) < 1.5 && abs(byId($pdf, 'c2')->width - 50.0) < 1.5 && abs(byId($pdf, 'c3')->width - 150.0) < 1.5, round(byId($pdf, 'c1')->width, 1) . '/' . round(byId($pdf, 'c2')->width, 1) . '/' . round(byId($pdf, 'c3')->width, 1));
[$pdf] = renderPdf('<table style="table-layout:fixed;width:300pt;border-collapse:collapse"><tr><td id="c1" style="width:60pt;box-sizing:border-box">x</td><td id="c2">longer text here</td></tr></table>');
check('table-layout: fixed uses first-row cell widths, rest shared', abs(byId($pdf, 'c1')->width - 60.0) < 1.5 && abs(byId($pdf, 'c2')->width - 240.0) < 1.5);
[$pdf] = renderPdf('<div style="height:100pt"><div id="p" style="height:50%">x</div></div><div style="height:200pt"><div id="m" style="min-height:25%">x</div><div id="x" style="height:150pt;max-height:50%">y</div></div>');
check('height: 50% of a fixed-height parent', abs(byId($pdf, 'p')->height - 50.0) < 0.5, (string) byId($pdf, 'p')->height);
check('min-height in %', abs(byId($pdf, 'm')->height - 50.0) < 0.5, (string) byId($pdf, 'm')->height);
check('max-height in %', abs(byId($pdf, 'x')->height - 100.0) < 0.5, (string) byId($pdf, 'x')->height);
[$pdf] = renderPdf('<div style="height:auto"><div id="u" style="height:50%">x</div></div>');
check('percentage height against an auto-height parent stays auto', byId($pdf, 'u')->height < 30.0);
[$pdf] = renderPdf('<div style="display:flex;height:100pt;width:200pt"><div id="fl" style="height:50%;width:40pt">x</div></div>');
check('flex item height: 50%', abs(byId($pdf, 'fl')->height - 50.0) < 0.5, (string) byId($pdf, 'fl')->height);
[$pdf] = renderPdf('<div style="display:grid;grid-template-rows:100pt;grid-template-columns:100pt;width:200pt"><div id="gi" style="height:50%">x</div></div>');
check('grid item height: 50% of its grid area', abs(byId($pdf, 'gi')->height - 50.0) < 0.5, (string) byId($pdf, 'gi')->height);
[$pdf] = renderPdf('<div id="ar" style="width:200pt;aspect-ratio:2/1">x</div><div id="ar2" style="width:90pt;aspect-ratio:1.5;box-sizing:content-box;padding:5pt">y</div>');
check('aspect-ratio: 2/1 derives the height', abs(byId($pdf, 'ar')->height - 100.0) < 0.5, (string) byId($pdf, 'ar')->height);
check('aspect-ratio with content-box sizing', abs(byId($pdf, 'ar2')->height - (90.0 / 1.5 + 10.0)) < 0.5, (string) byId($pdf, 'ar2')->height);
[$pdf] = renderPdf('<div id="b1" style="width:100pt">Averyveryverylongwordwithoutbreaks here</div><div id="b2" style="width:100pt;word-break:break-all">abcdefghij klmnopqrstuvwxyz</div><div id="b3" style="width:100pt;overflow-wrap:anywhere">Averyveryverylongwordwithoutbreaks</div>');
check('overflow-wrap / long words break', count(lineTexts(byId($pdf, 'b1'))) >= 3 && count(lineTexts(byId($pdf, 'b3'))) >= 2);
$bl = lineTexts(byId($pdf, 'b2'));
check('word-break: break-all fills lines mid-word', count($bl) >= 2 && mb_strlen($bl[0]) >= 12, implode('|', $bl));
$imgSmall = dataUri($redPng, 'image/png');
[$pdf] = renderPdf('<div style="font-size:20pt;line-height:30pt"><img id="i1" src="' . $imgSmall . '" style="height:40pt;vertical-align:top"><img id="i2" src="' . $imgSmall . '" style="height:40pt;vertical-align:bottom"><img id="i3" src="' . $imgSmall . '" style="height:40pt;vertical-align:middle"><img id="i4" src="' . $imgSmall . '" style="height:40pt;vertical-align:10pt"><img id="i5" src="' . $imgSmall . '" style="height:40pt"></div>');
$lineTop = byId($pdf, 'i1')->y;
check('vertical-align: top aligns the image with the line top', abs(byId($pdf, 'i1')->y - $lineTop) < 0.5);
check('vertical-align: bottom / length / baseline differ', byId($pdf, 'i2')->y >= $lineTop - 0.5 && byId($pdf, 'i4')->y < byId($pdf, 'i5')->y + 0.01 && abs(byId($pdf, 'i5')->y - byId($pdf, 'i4')->y) > 5.0);

// ---------------------------------------------------------------------------
echo "\n8. visibility, lists\n";
// ---------------------------------------------------------------------------
[, $b] = renderPdf('<div style="visibility:hidden;background:#f00;width:80pt;height:20pt">hidden <span style="visibility:visible">shown</span></div>');
check('visibility: hidden parent paints no background', !str_contains($b, '1.000 0.000 0.000 rg'));
[$pdf, $b] = renderPdf('<div style="visibility:hidden"><p id="v" style="visibility:visible;background:#00f">visible child</p></div>');
check('visibility: visible child of a hidden parent is painted', str_contains($b, '0.000 0.000 1.000 rg') && count(lineTexts(byId($pdf, 'v'))) === 1);
[$pdf] = renderPdf('<style>li::marker{color:#c00;font-size:20pt}</style><ul><li id="m">x</li></ul><ul style="list-style-position:inside"><li id="in">inside item</li></ul>');
check('::marker style is captured', byId($pdf, 'm')->markerStyle?->get('color') === '#c00');
check('list-style-position: inside moves the marker into the text', byId($pdf, 'in')->listMarker === null && str_starts_with(lineTexts(byId($pdf, 'in'))[0], '• '), lineTexts(byId($pdf, 'in'))[0] ?? '');
[$pdf] = renderPdf('<ul style="list-style-image:url(' . $red . ')"><li id="li">img</li></ul>');
check('list-style-image is recorded for the item', byId($pdf, 'li')->listMarkerImage === $red);

// ---------------------------------------------------------------------------
echo "\n9. Image decoders: BMP / TIFF / EXIF / SVG background\n";
// ---------------------------------------------------------------------------
$W = 16;
$H = 10;
$gd = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        [$r, $g, $bl2] = patternPixel($x, $y, $W, $H);
        imagesetpixel($gd, $x, $y, imagecolorallocate($gd, $r, $g, $bl2));
    }
}
ob_start();
imagebmp($gd, null, false);
$bmp24 = (string) ob_get_clean();
$raster = BmpDecoder::decode($bmp24);
check('BMP 24-bit decoded', $raster !== null && $raster->width === $W && $raster->height === $H && bin2hex(substr($raster->rgb, 0, 3)) === 'dc1414');
check('BMP bottom-up rows in right order (green bottom-left)', $raster !== null && bin2hex(substr($raster->rgb, (($H - 1) * $W) * 3, 3)) === '00c800');
$gdP = imagecreate($W, $H);
$cA = imagecolorallocate($gdP, 255, 0, 0);
$cB = imagecolorallocate($gdP, 0, 0, 255);
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        imagesetpixel($gdP, $x, $y, $x < 8 ? $cA : $cB);
    }
}
ob_start();
imagebmp($gdP, null, false);
$bmp8 = (string) ob_get_clean();
$r8 = BmpDecoder::decode($bmp8);
check('BMP 8-bit palette decoded', $r8 !== null && bin2hex(substr($r8->rgb, 0, 3)) === 'ff0000' && bin2hex(substr($r8->rgb, ($W - 1) * 3, 3)) === '0000ff');
// Hand-made 4-bit and 1-bit BMPs
$mk = static function (int $bpp, array $pal, string $rows, int $w, int $h): string {
    $palBytes = '';
    foreach ($pal as [$r, $g, $b]) {
        $palBytes .= chr($b) . chr($g) . chr($r) . "\0";
    }
    $off = 14 + 40 + strlen($palBytes);
    return 'BM' . pack('VvvV', $off + strlen($rows), 0, 0, $off) . pack('VVVvvVVVVVV', 40, $w, $h, 1, $bpp, 0, strlen($rows), 2835, 2835, count($pal), 0) . $palBytes . $rows;
};
$bmp1 = $mk(1, [[0, 0, 0], [255, 255, 255]], "\xA0\0\0\0", 8, 1); // 1010 0000
$r1 = BmpDecoder::decode($bmp1);
check('BMP 1-bit decoded', $r1 !== null && bin2hex(substr($r1->rgb, 0, 9)) === 'ffffff000000ffffff', $r1 ? bin2hex(substr($r1->rgb, 0, 9)) : 'null');
$bmp4 = $mk(4, [[255, 0, 0], [0, 255, 0], [0, 0, 255]], "\x01\x20\0\0", 4, 1); // idx 0,1,2,0
$r4 = BmpDecoder::decode($bmp4);
check('BMP 4-bit decoded', $r4 !== null && bin2hex($r4->rgb) === 'ff0000' . '00ff00' . '0000ff' . 'ff0000', $r4 ? bin2hex($r4->rgb) : 'null');
$rle = $mk(8, [[255, 0, 0], [0, 255, 0]], "\x03\x00\x02\x01\x00\x01", 5, 1);
$rle = substr($rle, 0, 30) . pack('V', 1) . substr($rle, 34); // BI_RGB -> BI_RLE8
$rr = BmpDecoder::decode($rle);
check('BMP RLE8 decoded', $rr !== null && bin2hex($rr->rgb) === str_repeat('ff0000', 3) . str_repeat('00ff00', 2), $rr ? bin2hex($rr->rgb) : 'null');

/** TIFF writer (baseline, one strip, little endian). */
$tiff = static function (string $pixels, int $w, int $h, int $comp, int $photo, int $spp, array $extra = []): string {
    $entries = [
        [256, 4, 1, $w], [257, 4, 1, $h], [259, 3, 1, $comp], [262, 3, 1, $photo], [277, 3, 1, $spp], [278, 4, 1, $h],
        [279, 4, 1, strlen($pixels)],
    ];
    $bpsOffset = 0;
    $entries[] = [258, 3, $spp, 0];
    $entries[] = [273, 4, 1, 0];
    foreach ($extra as $e) {
        $entries[] = $e;
    }
    usort($entries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $n = count($entries);
    $ifdSize = 2 + $n * 12 + 4;
    $bpsPos = 8 + $ifdSize;
    $dataPos = $bpsPos + 6;
    $ifd = pack('v', $n);
    foreach ($entries as [$tag, $type, $count, $val]) {
        if ($tag === 258) {
            $ifd .= pack('vvVV', 258, 3, $spp, $spp === 1 ? 8 : $bpsPos);
        } elseif ($tag === 273) {
            $ifd .= pack('vvVV', 273, 4, 1, $dataPos);
        } elseif ($type === 3) {
            $ifd .= pack('vvVv', $tag, 3, $count, $val) . "\0\0";
        } else {
            $ifd .= pack('vvVV', $tag, $type, $count, $val);
        }
    }
    return "II*\0" . pack('V', 8) . $ifd . pack('V', 0) . pack('vvv', 8, 8, 8) . $pixels;
};
$rgbPix = '';
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $rgbPix .= implode('', array_map('chr', patternPixel($x, $y, $W, $H)));
    }
}
$t1 = TiffDecoder::decode($tiff($rgbPix, $W, $H, 1, 2, 3));
check('TIFF uncompressed RGB decoded', $t1 !== null && $t1->rgb === $rgbPix);
$packed = '';
$pixLen = strlen($rgbPix);
for ($i = 0; $i < $pixLen; $i += 100) {
    $chunk = substr($rgbPix, $i, 100);
    $packed .= chr(strlen($chunk) - 1) . $chunk; // PackBits literal run
}
$packed .= chr(257 - 4) . 'Z'; // trailing repeat run (4 bytes, ignored: beyond expected size)
$t2 = TiffDecoder::decode($tiff($packed, $W, $H, 32773, 2, 3));
check('TIFF PackBits decoded', $t2 !== null && $t2->rgb === $rgbPix);
$t3 = TiffDecoder::decode($tiff((string) gzcompress($rgbPix), $W, $H, 8, 2, 3));
check('TIFF Deflate decoded', $t3 !== null && $t3->rgb === $rgbPix);
/** TIFF LZW encoder (MSB first, early change). */
$lzw = static function (string $data): string {
    $bits = '';
    $emit = static function (int $code, int $size) use (&$bits): void {
        $bits .= str_pad(decbin($code), $size, '0', STR_PAD_LEFT);
    };
    $table = [];
    for ($i = 0; $i < 256; $i++) {
        $table[chr($i)] = $i;
    }
    $next = 258;
    $size = 9;
    $emit(256, $size);
    $w = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $c = $data[$i];
        if (isset($table[$w . $c])) {
            $w .= $c;
            continue;
        }
        $emit($table[$w], $size);
        $table[$w . $c] = $next++;
        if ($next + 1 > 4094) {
            $emit(256, $size);
            $table = [];
            for ($k = 0; $k < 256; $k++) {
                $table[chr($k)] = $k;
            }
            $next = 258;
            $size = 9;
        } elseif ($next + 1 > 2047) {
            $size = 12;
        } elseif ($next + 1 > 1023) {
            $size = 11;
        } elseif ($next + 1 > 511) {
            $size = 10;
        }
        $w = $c;
    }
    if ($w !== '') {
        $emit($table[$w], $size);
    }
    $emit(257, $size);
    $bits = str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0');
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        $out .= chr(bindec($byte));
    }
    return $out;
};
$t4 = TiffDecoder::decode($tiff($lzw($rgbPix), $W, $H, 5, 2, 3));
check('TIFF LZW decoded', $t4 !== null && $t4->rgb === $rgbPix, $t4 ? 'mismatch' : 'null');
$gray = '';
for ($i = 0; $i < $W * $H; $i++) {
    $gray .= chr($i % 256);
}
$t5 = TiffDecoder::decode($tiff($gray, $W, $H, 1, 1, 1));
check('TIFF 8-bit gray decoded', $t5 !== null && ord($t5->rgb[3 * 5]) === 5 && ord($t5->rgb[3 * 5 + 2]) === 5);
$t6 = TiffDecoder::decode($tiff($gray, $W, $H, 1, 0, 1));
check('TIFF WhiteIsZero inverted', $t6 !== null && ord($t6->rgb[3 * 5]) === 250);
$palEntries = [];
$cmap = '';
$colors = [[255, 0, 0], [0, 255, 0]];
foreach ([0, 1, 2] as $ch) {
    for ($i = 0; $i < 256; $i++) {
        $cmap .= pack('v', ($colors[$i][$ch] ?? 0) * 257);
    }
}
$palTiff = $tiff(str_repeat("\x00\x01", $W * $H / 2), $W, $H, 1, 3, 1, [[320, 3, 768, 0]]);
// the ColorMap entry needs an out-of-line offset: append the map and patch the pointer
$mapOffset = strlen($palTiff);
$palTiff .= $cmap;
$pos = strpos($palTiff, pack('vvV', 320, 3, 768));
$palTiff = substr_replace($palTiff, pack('V', $mapOffset), $pos + 8, 4);
$t7 = TiffDecoder::decode($palTiff);
check('TIFF palette decoded', $t7 !== null && bin2hex(substr($t7->rgb, 0, 6)) === 'ff000000ff00', $t7 ? bin2hex(substr($t7->rgb, 0, 6)) : 'null');
check('BMP / TIFF magic detection', BmpDecoder::isBmp($bmp24) && TiffDecoder::isTiff($tiff($gray, $W, $H, 1, 1, 1)) && !BmpDecoder::isBmp('GIF89a....') && !TiffDecoder::isTiff($bmp24));
[$pdf, $b] = renderPdf('<img id="ib" src="' . dataUri($bmp24, 'image/bmp') . '" width="64"><img id="it" src="' . dataUri($tiff($rgbPix, $W, $H, 1, 2, 3), 'image/tiff') . '" width="64">');
check('BMP and TIFF images embed into the PDF', substr_count($b, '/Subtype /Image') >= 2 && count($pdf->getWarnings()) === 0, implode(';', $pdf->getWarnings()));

// EXIF orientation
ob_start();
imagejpeg($gd, null, 95);
$jpeg = (string) ob_get_clean();
$exifJpeg = static function (string $jpeg, int $orientation): string {
    $tiffHdr = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
    $app1 = "Exif\0\0" . $tiffHdr;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
};
check('EXIF orientation read from JPEG', ImageVariants::jpegOrientation($exifJpeg($jpeg, 6)) === 6 && ImageVariants::jpegOrientation($jpeg) === 1);
$l2 = new ImageLoader(null, false, new Options());
$up = $l2->load(dataUri($exifJpeg($jpeg, 6), 'image/jpeg'));
check('EXIF orientation 6 swaps the image dimensions', $up !== null && $up['width'] === $H && $up['height'] === $W, $up ? "{$up['width']}x{$up['height']}" : 'null');
$rot = $up !== null ? (string) gzuncompress($up['data']) : '';
// rotate 90 CW: source top-left (red) ends up at the top-right corner
$tr = substr($rot, ($H - 1) * 3, 3);
check('EXIF orientation 6 rotates pixels clockwise', strlen($rot) > 0 && ord($tr[0]) > 150 && ord($tr[2]) < 90, bin2hex($tr));
$plainJ = $l2->load(dataUri($jpeg, 'image/jpeg'));
check('JPEG without EXIF stays a pass-through DCT stream', $plainJ !== null && $plainJ['filter'] === 'DCTDecode' && $plainJ['width'] === $W);

// SVG background
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><circle cx="10" cy="10" r="8" fill="#f06"/></svg>';
[, $b] = renderPdf('<div style="width:80pt;height:40pt;background:url(\'' . dataUri($svg, 'image/svg+xml') . '\') repeat">x</div>');
check('SVG background-image is vector (path ops, no bitmap)', str_contains($b, ' c') && !str_contains($b, '/Subtype /Image'));

// ---------------------------------------------------------------------------
echo "\n10. Hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
