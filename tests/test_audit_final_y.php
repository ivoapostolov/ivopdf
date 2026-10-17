<?php

declare(strict_types=1);

/**
 * Regression tests for the last audit items:
 *  - CMYK / PDF/X / PDF/A-1 image conversion under the memory budget: decompression bombs are converted row by row
 *    (no PHP fatal error at memory_limit 64M / 128M), a JPEG too large for the memory left is kept / skipped with a
 *    warning, corrupt pixel data never leaks PHP warnings; conversion results are pixel-exact
 *  - PDF/A-1b also neutralises ExtGState soft masks that carry no opacity entry (SVG <mask>)
 *  - text extraction of right-to-left text: Hebrew / Arabic lines come out in the order Chrome / Edge PDFs give
 *    (one text object per direction, spaces drawn beside right-to-left text, mirrored brackets, lam-alef)
 *  - dompdf Canvas drop-in: set_page_number() only renumbers, new_page() returns a page handle, page_text() /
 *    page_script() after render() work like dompdf's, text() prints {PAGE_NUM} literally, register_font() refuses
 *    files that are not fonts
 *  - outline-offset: the background stays inside the border box, the outline ring sits outline-offset away
 *
 * Run: php tests/test_audit_final_y.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Pdf\ColorOps;
use IvoPdf\Pdf\ImageCmykConverter;

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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-final-y-' . getmypid();
@mkdir($tmp, 0777, true);
$tmp = str_replace('\\', '/', (string) realpath($tmp));

/** Runs $python (with `d` = fitz document of $pdf) and returns its JSON output, or null without PyMuPDF. */
function py(string $pdf, string $python): mixed
{
    global $tmp;
    $file = $tmp . '/py-' . md5($python . $pdf) . '.pdf';
    file_put_contents($file, $pdf);
    $script = $tmp . '/py-' . md5($python) . '.py';
    file_put_contents($script, "import sys, json\nsys.stdout.reconfigure(encoding='utf-8')\nimport fitz\nd = fitz.open(sys.argv[1])\n" . $python . "\n");
    $out = @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    if (!is_string($out) || trim($out) === '') {
        return null;
    }
    return json_decode(trim($out), true);
}

/** The PHP command line for a child process, with the extensions of this one when it runs without php.ini. */
function phpCommand(string $memoryLimit): string
{
    $cmd = escapeshellarg(PHP_BINARY);
    if (php_ini_loaded_file() === false) {
        $cmd .= ' -n -d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'));
        foreach (['mbstring', 'openssl', 'gd', 'intl'] as $ext) {
            if (extension_loaded($ext)) {
                $cmd .= ' -d extension=' . $ext;
            }
        }
    }
    return $cmd . ' -d memory_limit=' . $memoryLimit . ' -d display_errors=1';
}

/**
 * Renders <img src=$file> in a separate PHP process at $memoryLimit with $options; returns
 * ['status' => 'ok'|'limit'|'crash', 'warnings' => [...], 'issues' => [...], 'pdf' => string, 'raw' => string].
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function renderChild(string $file, array $options, string $memoryLimit): array
{
    global $tmp;
    $script = $tmp . '/child.php';
    if (!is_file($script)) {
        file_put_contents($script, '<?php
require ' . var_export(str_replace('\\', '/', dirname(__DIR__)) . '/autoload.php', true) . ';
$issues = [];
set_error_handler(static function (int $n, string $s, string $f, int $l) use (&$issues): bool { $issues[] = "$s @ $f:$l"; return true; });
[$_, $img, $opts, $out] = $argv;
$res = ["status" => "ok", "warnings" => [], "issues" => []];
try {
    $pdf = new IvoPdf\IvoPdf(json_decode(base64_decode($opts), true) + ["basePath" => dirname($img), "compress" => false]);
    file_put_contents($out, $pdf->loadHtml("<p>x</p><img src=\"" . basename($img) . "\" style=\"width:100pt\">")->output());
    $res["warnings"] = $pdf->getWarnings();
} catch (IvoPdf\Exception\LimitExceededException $e) {
    $res["status"] = "limit";
    $res["warnings"] = [$e->getMessage()];
}
$res["issues"] = $issues;
echo "\nRESULT:" . json_encode($res);
');
    }
    $out = $tmp . '/child-' . md5($file . json_encode($options) . $memoryLimit) . '.pdf';
    if (is_file($out)) {
        unlink($out);
    }
    $raw = (string) shell_exec(phpCommand($memoryLimit) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode((string) json_encode($options))) . ' ' . escapeshellarg($out) . ' 2>&1');
    $pos = strrpos($raw, "\nRESULT:");
    if ($pos === false) {
        return ['status' => 'crash', 'warnings' => [], 'issues' => [], 'pdf' => '', 'raw' => $raw];
    }
    $res = (array) json_decode(substr($raw, $pos + 8), true);
    $res['pdf'] = is_file($out) ? (string) file_get_contents($out) : '';
    $res['raw'] = $raw;
    return $res;
}

/** A PNG of $w x $h one-colour pixels (colour type 2 or 6) written row by row: a few KB declaring megapixels. */
function pngBomb(string $file, int $w, int $h, string $pixel, int $colorType): void
{
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = "\0" . str_repeat($pixel, $w);
    $idat = '';
    for ($y = 0; $y < $h; $y++) {
        $idat .= deflate_add($z, $row, ZLIB_NO_FLUSH);
    }
    $idat .= deflate_add($z, '', ZLIB_FINISH);
    file_put_contents($file, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $colorType, 0, 0, 0)) . $chunk('IDAT', $idat) . $chunk('IEND', ''));
}

/** First image XObject (not a soft mask) of an uncompressed PDF: [dictionary, decoded data]. */
function firstImage(string $pdf): ?array
{
    // the image itself, not its soft mask (which has no /Interpolate entry)
    if (preg_match('~<< /Type /XObject /Subtype /Image ([^>]*?/Interpolate.*?)>>\nstream\n~s', $pdf, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $dict = $m[1][0];
    $start = $m[0][1] + strlen($m[0][0]);
    $len = preg_match('~/Length (\d+)~', $dict, $lm) === 1 ? (int) $lm[1] : 0;
    $data = substr($pdf, $start, $len);
    if (str_contains($dict, '/FlateDecode')) {
        $data = (string) @gzuncompress($data);
    }
    return [$dict, $data];
}

// ---------------------------------------------------------------------------
echo "1. CMYK / PDF/X / PDF/A-1 image conversion within the memory budget\n";
// ---------------------------------------------------------------------------
$rgbBomb = $tmp . '/bomb_rgb.png';
$rgbaBomb = $tmp . '/bomb_rgba.png';
pngBomb($rgbBomb, 4900, 4900, "\x00\x80\xff", 2);       // 72 MB of RGB once decoded, 96 MB as CMYK
pngBomb($rgbaBomb, 2000, 2000, "\xff\x00\x00\x80", 6);   // half-transparent red: composited onto white
check('test bombs are small files', filesize($rgbBomb) < 200000 && filesize($rgbaBomb) < 100000, filesize($rgbBomb) . ' / ' . filesize($rgbaBomb));
$gifBomb = null;
if (function_exists('imagegif')) {
    $gd = imagecreate(4900, 4900);
    imagecolorallocate($gd, 30, 120, 200);
    $gifBomb = $tmp . '/bomb.gif';
    imagegif($gd, $gifBomb);
    unset($gd);
}

$modes = ['colorSpace cmyk' => ['colorSpace' => 'cmyk'], 'pdfx X-1a' => ['pdfx' => 'X-1a'], 'pdfa 1b' => ['pdfa' => '1b']];
$cases = [['RGB PNG bomb 4900 x 4900', $rgbBomb, '128M'], ['RGBA PNG bomb 2000 x 2000', $rgbaBomb, '64M']];
if ($gifBomb !== null) {
    $cases[] = ['GIF bomb 4900 x 4900', $gifBomb, '128M'];
}
foreach ($cases as [$label, $file, $limit]) {
    foreach ($modes as $modeLabel => $opts) {
        $r = renderChild($file, $opts, $limit);
        $clean = in_array($r['status'], ['ok', 'limit'], true) && $r['issues'] === [] && !str_contains($r['raw'], 'Fatal error');
        check("{$label}, {$modeLabel}, memory_limit {$limit}: clean result, no fatal / PHP warning", $clean, $r['status'] . ' ' . substr(trim($r['raw']), 0, 300) . ' ' . implode(' | ', array_slice($r['issues'], 0, 2)));
        if ($r['status'] !== 'ok') {
            continue;
        }
        $img = firstImage($r['pdf']);
        if ($opts !== ['pdfa' => '1b']) {
            $flatten = isset($opts['pdfx']);
            // colorSpace cmyk keeps the alpha channel (/SMask), PDF/X-1a composites it onto white
            $cmyk = $img !== null && str_contains($img[0], '/DeviceCMYK') && ($flatten || $file !== $rgbaBomb ? !str_contains($img[0], '/SMask') : str_contains($img[0], '/SMask'));
            $px = $img !== null ? substr($img[1], 0, 4) : '';
            check("{$label}, {$modeLabel}: converted to DeviceCMYK", $cmyk && strlen($img[1]) >= 4, $img[0] ?? 'no image');
            if ($cmyk && $file !== $gifBomb) {
                $a = 128 / 255;
                $rgb = $file === $rgbBomb ? [0.0, 128 / 255, 1.0] : ($flatten ? [1.0, 1.0 - $a, 1.0 - $a] : [1.0, 0.0, 0.0]);
                $k = ColorOps::cmyk([$rgb[0], $rgb[1], $rgb[2], 1.0]);
                $want = implode('', array_map(static fn(float $v): string => chr((int) round($v * 255)), array_slice($k, 0, 4)));
                check("{$label}, {$modeLabel}: pixel converted exactly", $px === $want, bin2hex($px) . ' vs ' . bin2hex($want));
            }
        } elseif ($file === $rgbaBomb) {
            check("{$label}, pdfa 1b: alpha composited onto white (no /SMask, red over white)", $img !== null && !str_contains($img[0], '/SMask')
                && substr($img[1], 0, 3) === "\xff\x7f\x7f", $img !== null ? bin2hex(substr($img[1], 0, 3)) : 'no image');
        }
    }
}

// A JPEG is decoded by GD as a whole: when its bitmap does not fit the memory left it is kept (cmyk) or skipped (PDF/X)
if (function_exists('imagejpeg')) {
    $big = imagecreatetruecolor(3500, 3500);
    imagefilledrectangle($big, 0, 0, 3499, 3499, imagecolorallocate($big, 200, 30, 40));
    $jpeg = $tmp . '/big.jpg';
    imagejpeg($big, $jpeg, 40);
    $big = imagecreatetruecolor(600, 400);
    imagefilledrectangle($big, 0, 0, 599, 399, imagecolorallocate($big, 200, 30, 40));
    imagejpeg($big, $tmp . '/small.jpg', 60);
    unset($big);
    $r = renderChild($jpeg, ['colorSpace' => 'cmyk'], '64M');
    check('3500 x 3500 JPEG, colorSpace cmyk, 64M: kept as RGB with a warning', $r['status'] === 'ok' && $r['issues'] === []
        && (bool) array_filter($r['warnings'], static fn(string $w): bool => str_contains($w, 'could not be converted to CMYK') && str_contains($w, 'memory')), json_encode($r['warnings']) . substr($r['raw'], 0, 200));
    $r = renderChild($jpeg, ['pdfx' => 'X-1a'], '64M');
    $img = $r['status'] === 'ok' ? firstImage($r['pdf']) : null;
    check('3500 x 3500 JPEG, PDF/X-1a, 64M: skipped with a warning (no RGB image in the file)', $r['status'] === 'ok' && $r['issues'] === []
        && (bool) array_filter($r['warnings'], static fn(string $w): bool => str_contains($w, 'skipped')) && $img !== null && !str_contains($img[0], '/DeviceRGB'), json_encode($r['warnings']));
    $r = renderChild($tmp . '/small.jpg', ['colorSpace' => 'cmyk'], '64M');
    $img = $r['status'] === 'ok' ? firstImage($r['pdf']) : null;
    check('a JPEG whose bitmap fits is converted to DeviceCMYK', $img !== null && str_contains($img[0], '/DeviceCMYK'), $img[0] ?? $r['raw']);
} else {
    skip('JPEG memory checks (GD without JPEG)');
}

// Corrupt / truncated pixel data: a warning, never a PHP warning
$chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$rows = '';
for ($y = 0; $y < 64; $y++) {
    $rows .= "\0" . str_repeat("\x0a\xc8\x1e", 64);
}
$z = (string) gzcompress($rows);
$bad = $z;
for ($i = 10; $i < strlen($bad) - 4; $i += 3) {
    $bad[$i] = chr(ord($bad[$i]) ^ 0x5a);
}
$hdr = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0));
file_put_contents($tmp . '/corrupt.png', $hdr . $chunk('IDAT', $bad) . $chunk('IEND', ''));
file_put_contents($tmp . '/trunc.png', $hdr . $chunk('IDAT', substr($z, 0, intdiv(strlen($z), 2))) . $chunk('IEND', ''));
foreach (['corrupt.png', 'trunc.png'] as $f) {
    foreach (['cmyk' => ['colorSpace' => 'cmyk'], 'X-1a' => ['pdfx' => 'X-1a']] as $m => $opts) {
        $issues = count($phpIssues);
        $pdf = new IvoPdf($opts + ['basePath' => $tmp, 'compress' => false]);
        $pdf->loadHtml('<img src="' . $f . '">')->output();
        $warned = (bool) array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, 'could not be decoded'));
        check("{$f}, {$m}: reported as a warning, no PHP warning", $warned && count($phpIssues) === $issues, json_encode($pdf->getWarnings()) . implode(' | ', array_slice($phpIssues, $issues, 2)));
    }
}

// Pixel-exact conversion of ordinary images (PNG with mixed row filters, RGBA, JPEG, grey + alpha)
if (function_exists('imagepng')) {
    $opt = new \IvoPdf\Options();
    $opt->basePath = $tmp;
    $loader = new \IvoPdf\Image\ImageLoader($tmp, false, $opt);
    $g = imagecreatetruecolor(97, 61);
    imagealphablending($g, false);
    imagesavealpha($g, true);
    $ga = imagecreatetruecolor(97, 61);
    for ($y = 0; $y < 61; $y++) {
        for ($x = 0; $x < 97; $x++) {
            imagesetpixel($g, $x, $y, imagecolorallocate($g, ($x * 7 + $y) % 256, ($y * 11) % 256, ($x * $y) % 256));
            imagesetpixel($ga, $x, $y, imagecolorallocate($ga, ($x * 7 + $y) % 256, ($y * 11) % 256, ($x * $y) % 256));
        }
    }
    imagepng($g, $tmp . '/grad.png', 9, PNG_ALL_FILTERS);
    $img = $loader->load($tmp . '/grad.png');
    ColorOps::configure(new \IvoPdf\Options(['colorSpace' => 'cmyk']));
    $conv = $img !== null ? ImageCmykConverter::convert($img, $opt, true, false) : null;
    $data = $conv !== null ? (string) gzuncompress($conv['data']) : '';
    $exact = $conv !== null && $conv['colorSpace'] === 'DeviceCMYK' && strlen($data) === 97 * 61 * 4;
    for ($y = 0; $exact && $y < 61; $y += 7) {
        for ($x = 0; $x < 97; $x += 5) {
            $c = imagecolorat($g, $x, $y);
            $k = ColorOps::cmyk([(($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255, 1.0]);
            $want = implode('', array_map(static fn(float $v): string => chr((int) round($v * 255)), array_slice($k, 0, 4)));
            $exact = substr($data, ($y * 97 + $x) * 4, 4) === $want;
        }
    }
    check('PNG with predictor rows (all filter types) converts pixel-exactly to CMYK', $exact, $conv['colorSpace'] ?? 'null');
    ColorOps::configure(new \IvoPdf\Options());
} else {
    skip('pixel-exact conversion (no GD)');
}

// PDF/A-1b: an ExtGState that only carries a soft mask (SVG <mask>) is neutralised as well
$pdf = new IvoPdf(['compress' => false, 'pdfa' => '1b']);
$out = $pdf->loadHtml('<svg width="100" height="100"><defs><mask id="m"><rect width="100" height="100" fill="white"/><circle cx="50" cy="50" r="20" fill="black"/></mask></defs><rect width="100" height="100" fill="red" mask="url(#m)"/></svg>')->output();
check('PDF/A-1b: SVG <mask> soft mask removed (/SMask /None) and reported', preg_match('~/SMask\s*(<<|\d+ 0 R)~', $out) !== 1 && str_contains($out, '/SMask /None')
    && (bool) array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, 'soft mask(s) removed')));

// ---------------------------------------------------------------------------
echo "\n2. Text extraction order of right-to-left text (as in Chrome / Edge PDFs)\n";
// ---------------------------------------------------------------------------
$lines = [
    // [paragraph dir, HTML, what PyMuPDF extracts (same as from Edge's PDF of the same HTML)]
    ['rtl', 'שלום עולם זהו משפט בעברית', 'שלום עולם זהו משפט בעברית'],
    ['rtl', 'مرحبا بالعالم هذه جملة عربية', 'مرحبا بالعالم هذه جملة عربية'],
    ['ltr', 'This English sentence contains שלום עולם in Hebrew.', 'This English sentence contains שלום עולם in Hebrew.'],
    ['ltr', 'The Arabic words مرحبا بالعالم appear here.', 'The Arabic words مرحبا بالعالم appear here.'],
    ['ltr', 'Version 2.0 שוחררה היום, see notes.', 'Version 2.0 שוחררה היום, see notes.'],
    ['ltr', 'Mixed: abc אבג def دهو ghi.', 'Mixed: abc אבג def دهو ghi.'],
    ['rtl', 'המחיר הוא 250 שקלים בשנת 2024', '2024 שקלים בשנת250 המחיר הוא'],
    ['rtl', 'שאלה? תשובה! סוף.', '.שאלה? תשובה! סוף'],
    ['rtl', '(טקסט בסוגריים) ו-[עוד] סימנים!', '![עוד] סימנים-(טקסט בסוגריים) ו'],
    ['rtl', 'טלפון: 03-1234567, דוא"ל test@example.com', 'test@example.com , דוא"ל03-1234567 :טלפון'],
    ['rtl', 'מילה <b>מודגשת</b> ועוד <i>נטויה</i> בתוך משפט.', '.מילה מודגשת ועוד נטויה בתוך משפט'],
    ['rtl', 'لا إله إلا الله والسلام عليكم', 'لا إله إلا الله والسلام عليكم'],
    ['rtl', 'كتاب "الأيام" لطه حسين', 'كتاب "الأيام" لطه حسين'],
];
$html = '<style>@page{size:900pt 1000pt;margin:20pt} body{font-family:"DejaVu Sans";font-size:12pt} p{margin:0 0 14pt 0}</style>';
foreach ($lines as [$dir, $content]) {
    $html .= '<p dir="' . $dir . '">' . $content . '</p>';
}
$bidiPdf = (new IvoPdf(['compress' => false]))->loadHtml($html)->output();
$got = py($bidiPdf, "print(json.dumps([' '.join(l.split()) for l in d[0].get_text().splitlines() if l.strip()]))");
if ($got === null) {
    skip('PyMuPDF extraction of right-to-left lines');
} else {
    foreach ($lines as $i => [$dir, $content, $want]) {
        check('PyMuPDF: ' . $want, ($got[$i] ?? '') === $want, $got[$i] ?? 'missing');
    }
}
$miner = py($bidiPdf, "from pdfminer.high_level import extract_text\nprint(json.dumps([' '.join(l.split()) for l in extract_text(sys.argv[1]).splitlines() if l.strip()]))");
if (!is_array($miner)) {
    skip('pdfminer extraction (not installed)');
} else {
    // pdfminer reads glyphs in drawing order: the visual order, as for Chrome / Edge PDFs
    check('pdfminer: Hebrew line in visual order with its spaces', in_array('תירבעב טפשמ והז םלוע םולש', $miner, true), implode(' | ', array_slice($miner, 0, 3)));
    check('pdfminer: Hebrew inside an English line', in_array('This English sentence contains םלוע םולש in Hebrew.', $miner, true));
}
check('a right-to-left line is one text object per direction (BT count)', substr_count((new IvoPdf(['compress' => false]))->loadHtml('<p dir="rtl" style="font-family:DejaVu Sans">שאלה? תשובה! סוף.</p>')->output(), 'BT /') === 1);
$ltrOnly = (new IvoPdf(['compress' => false]))->loadHtml('<p style="font-family:DejaVu Sans">Plain left to right words</p>')->output();
check('left-to-right lines are drawn as before (no text object shared by several runs)', preg_match('~T[Jj] -?[\d.]+ 0 Td~', $ltrOnly) !== 1 && str_contains($ltrOnly, 'BT /'));
check('mirrored bracket of a right-to-left run carries its logical character as /ActualText', str_contains($bidiPdf, '/ActualText <FEFF005D>') && str_contains($bidiPdf, '/ActualText <FEFF0028>'));
check('lam-alef ligature mapped in visual order ("ال") for bidi-aware extractors', preg_match('~<[0-9A-F]{4}> <06270644>~', $bidiPdf) === 1);
$pos = py((new IvoPdf(['compress' => false]))->loadHtml('<p dir="rtl" style="font-family:DejaVu Sans;font-size:12pt">שלום עולם</p>')->output(), "print(json.dumps([[w[4], round(w[0], 1)] for w in d[0].get_text('words')]))");
if ($pos === null) {
    skip('word positions');
} else {
    $x = array_column($pos, 1, 0);
    check('words stay where the layout put them (right-to-left, right-aligned)', isset($x['שלום'], $x['עולם']) && $x['שלום'] > $x['עולם'] && $x['שלום'] > 400.0, json_encode($pos, JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------------
echo "\n3. dompdf Canvas: page numbers, new_page(), register_font() (as dompdf 3.1)\n";
// ---------------------------------------------------------------------------
$pageTexts = "print(json.dumps([' | '.join(l.strip() for l in p.get_text().splitlines() if l.strip()) for p in d]))";
$d = new \IvoPdf\Compat\Dompdf\Dompdf();
$d->loadHtml('<p>P1</p><p style="page-break-before:always">P2</p><p style="page-break-before:always">P3</p>');
$c = $d->getCanvas();
$font = $d->getFontMetrics()->getFont('Helvetica');
check('before render(): page number 1', $c->get_page_number() === 1);
$d->render();
check('after render(): page number and count = number of pages', $c->get_page_number() === 3 && $c->get_page_count() === 3);
$c->set_page_number(1);
check('set_page_number(1) renumbers', $c->get_page_number() === 1);
$c->text(50, 50, 'AFTER-SET', $font, 12);
$c->set_page_number(7);
$h1 = $c->new_page();
check('new_page() returns an int handle and increments both counters', is_int($h1) && $c->get_page_number() === 8 && $c->get_page_count() === 4);
$c->text(50, 80, 'ON-NEW', $font, 12);
$h2 = $c->new_page();
$c->text(50, 80, 'ON-NEW2 {PAGE_NUM}/{PAGE_COUNT}', $font, 12);
$c->reopen_object($h1);
$c->text(50, 120, 'REOPENED', $font, 12);
$c->close_object();
$c->text(50, 160, 'AFTER-CLOSE', $font, 12);
check('the handles differ', $h1 !== $h2);
$r = py((string) $d->output(), $pageTexts);
if ($r === null) {
    skip('PyMuPDF checks of the canvas pages');
} else {
    check('drawing after set_page_number(1) stays on the last page (as dompdf)', count($r) === 5 && str_contains($r[2], 'AFTER-SET') && !str_contains($r[0], 'AFTER-SET'), json_encode($r, JSON_UNESCAPED_UNICODE));
    check('new_page() pages: drawing, reopen_object(handle) / close_object()', count($r) === 5 && str_contains($r[3], 'ON-NEW') && str_contains($r[3], 'REOPENED')
        && str_contains($r[4], 'ON-NEW2') && str_contains($r[4], 'AFTER-CLOSE'), json_encode($r, JSON_UNESCAPED_UNICODE));
    check('text() prints {PAGE_NUM} / {PAGE_COUNT} literally (only page_text() substitutes)', str_contains($r[4] ?? '', '{PAGE_NUM}/{PAGE_COUNT}'), $r[4] ?? '');
}
$d = new \IvoPdf\Compat\Dompdf\Dompdf();
$d->loadHtml('<p>P1</p><p style="page-break-before:always">P2</p><p style="page-break-before:always">P3</p>');
$d->render();
$c = $d->getCanvas();
$c->set_page_count(10);
$c->set_page_number(5);
$c->page_text(300, 20, 'PT {PAGE_NUM}/{PAGE_COUNT}', $font, 9);
$seen = [];
$c->page_script(static function (int $n, int $t, $canvas) use (&$seen): void {
    $seen[] = [$n, $t, $canvas->get_page_number()];
});
check('page_script() after render(): called at once per page with the canvas counters', $seen === [[1, 10, 5], [2, 10, 5], [3, 10, 5]], json_encode($seen));
$r = py((string) $d->output(), $pageTexts);
$r === null ? skip('page_text after set_page_count') : check('page_text() after render() uses get_page_count()', str_contains($r[0], 'PT 1/10') && str_contains($r[2], 'PT 3/10'), json_encode($r));
$d = new \IvoPdf\Compat\Dompdf\Dompdf();
$events = [];
$d->setCallbacks([['event' => 'end_document', 'f' => static function (int $n, int $t, $canvas) use (&$events): void {
    $events[] = [$n, $t, $canvas->get_page_number()];
}]]);
$d->loadHtml('<p>P1</p><p style="page-break-before:always">P2</p>');
$d->render();
check('end_document callbacks see get_page_number() = last page (as dompdf)', $events === [[1, 2, 2], [2, 2, 2]], json_encode($events));
$fm = $d->getFontMetrics();
$ttf = __DIR__ . '/../resources/fonts/DejaVuSerif.ttf';
check('register_font(): true for a font, again true, false for a missing file and for a non-font file',
    $fm->register_font(['family' => 'fy-reg', 'weight' => 'normal', 'style' => 'normal'], $ttf) === true
    && $fm->register_font(['family' => 'fy-reg', 'weight' => 'normal', 'style' => 'normal'], $ttf) === true
    && $fm->register_font(['family' => 'fy-miss', 'weight' => 'normal', 'style' => 'normal'], __DIR__ . '/no-such.ttf') === false
    && $fm->register_font(['family' => 'fy-bad', 'weight' => 'normal', 'style' => 'normal'], __FILE__) === false);

// ---------------------------------------------------------------------------
echo "\n4. outline-offset (showcase demo)\n";
// ---------------------------------------------------------------------------
$ol = (new IvoPdf(['compress' => false]))->loadHtml('<div style="margin:40pt;width:120pt;padding:8pt;outline:2pt solid #0284c7;outline-offset:3pt;border-radius:4pt;background:#f0f9ff">outline-offset</div>')->output();
$dr = py($ol, "print(json.dumps([[list(map(lambda v: round(v, 2), x['rect'])), x.get('fill')] for x in d[0].get_drawings()]))");
if ($dr === null) {
    skip('outline-offset drawings');
} else {
    $bg = null;
    $ring = null;
    foreach ($dr as [$rect, $fill]) {
        if ($fill !== null && abs($fill[0] - 0.941) < 0.01) {
            $bg = $rect;
        } elseif ($fill !== null && abs($fill[2] - 0.78) < 0.01) {
            $ring = $rect;
        }
    }
    check('background fills the border box only, the outline ring lies 3pt + 2pt outside it', $bg !== null && $ring !== null
        && abs(($bg[0] - $ring[0]) - 5.0) < 0.05 && abs(($ring[2] - $bg[2]) - 5.0) < 0.05 && abs(($bg[1] - $ring[1]) - 5.0) < 0.05, json_encode($dr));
}

// ---------------------------------------------------------------------------
foreach (glob($tmp . '/*') ?: [] as $f) {
    unlink($f);
}
rmdir($tmp);
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
