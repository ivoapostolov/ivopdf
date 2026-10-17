<?php

declare(strict_types=1);

/**
 * Regression tests for the robustness / memory / performance findings of the independent audit (part A):
 *  - H1 the memory budget comes before PHP's fatal error: image decompression bombs (palette PNG, RGBA PNG, GIF),
 *       HTML whose tree cannot fit (lexbor), many lines / links / a huge textarea, `tab-size: 1e9`
 *  - H2 one time budget per render() / output(), across the extra layout passes (CSS footnotes)
 *  - H6 near-linear footnotes, many classes on one element, deeply nested :not(), TOC with many headings
 *  - M3 decoded images are released after output()
 *  - L1 no PHP warnings for absurd numeric attributes; L3 HTML5-like source normalisation for libxml (PHP < 8.4)
 *  - corrupt images never leak PHP warnings; <tocentry> documents list only their entries (as mPDF)
 *
 * Hostile inputs run in separate PHP processes with an explicit memory_limit: they must end with a clean result or a
 * catchable LimitExceededException, never with "Allowed memory size exhausted".
 *
 * Run: php tests/test_audit_fable_a.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Dom\Html5Parser;
use IvoPdf\Dom\InputGuards;
use IvoPdf\IvoPdf;
use IvoPdf\RenderLimits;

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
 * Runs PHP code in a separate process with the given memory_limit. The code leaves its verdict in $result.
 * Returns [last output line, whole output].
 */
function runIsolated(string $code, string $memoryLimit): array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable-a-' . md5(uniqid('', true)) . '.php';
    $autoload = var_export(realpath(__DIR__ . '/../autoload.php'), true);
    file_put_contents($file, "<?php\nrequire {$autoload};\n\$result = '';\ntry {\n{$code}\n} catch (\\IvoPdf\\Exception\\LimitExceededException \$e) {\n\$result = 'LIMIT ' . \$e->getLimit();\n}\nfwrite(STDOUT, \"\\nRESULT \" . \$result . \"\\n\");\n");
    $cmd = escapeshellarg(PHP_BINARY);
    if (php_ini_loaded_file() === false || php_ini_loaded_file() === '') {
        $cmd .= ' -n -d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'));
        foreach (['mbstring', 'openssl', 'gd', 'intl'] as $ext) {
            if (extension_loaded($ext)) {
                $cmd .= ' -d extension=' . $ext;
            }
        }
    }
    $out = (string) @shell_exec($cmd . ' -d memory_limit=' . $memoryLimit . ' -d display_errors=1 ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    $last = '';
    if (preg_match('/RESULT (.*)$/m', $out, $m) === 1) {
        $last = trim($m[1]);
    }
    return [$last, $out];
}

function noFatal(string $out): bool
{
    return stripos($out, 'Allowed memory size') === false && stripos($out, 'Fatal error') === false;
}

/** A PNG with the given IHDR whose pixel rows are all zero (deflated row by row: tiny file, huge bitmap). */
function zeroPng(int $w, int $h, int $colorType, string $extraChunks = ''): string
{
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $bpp = match ($colorType) {
        6 => 4,
        2 => 3,
        4 => 2,
        default => 1,
    };
    $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $idat = '';
    $row = str_repeat("\0", 1 + $w * $bpp);
    for ($y = 0; $y < $h; $y++) {
        $idat .= deflate_add($z, $row, ZLIB_NO_FLUSH);
    }
    $idat .= deflate_add($z, '', ZLIB_FINISH);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $colorType, 0, 0, 0)) . $extraChunks
        . $chunk('IDAT', $idat) . $chunk('IEND', '');
}

function dataUri(string $bytes, string $mime): string
{
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-fable-a-' . getmypid();
@mkdir($tmpDir);

// ---------------------------------------------------------------------------
echo "\n1. H1 image decompression bombs (separate processes)\n";
// ---------------------------------------------------------------------------
$plte = pack('N', 6) . 'PLTE' . "\x00\x00\x00\xff\xff\xff" . pack('N', crc32("PLTE\x00\x00\x00\xff\xff\xff"));
file_put_contents($tmpDir . '/pal.png', zeroPng(4900, 4900, 3, $plte));
file_put_contents($tmpDir . '/rgba.png', zeroPng(2000, 2000, 6));
$gif = '';
if (function_exists('imagecreate')) {
    $im = imagecreate(4900, 4900);
    imagecolorallocate($im, 255, 255, 255);
    ob_start();
    imagegif($im);
    $gif = (string) ob_get_clean();
    unset($im);
    file_put_contents($tmpDir . '/bomb.gif', $gif);
}
check('the palette PNG bomb is small (' . filesize($tmpDir . '/pal.png') . ' bytes for 24 megapixels)', filesize($tmpDir . '/pal.png') < 100000);

$imgCode = static fn(string $file): string => '$pdf = new IvoPdf\IvoPdf(["basePath" => ' . var_export($tmpDir, true) . ']);'
    . '$pdf->loadHtml(\'<p>x</p><img src="' . $file . '" style="width:100pt">\')->output();'
    . '$w = implode(" | ", $pdf->getWarnings());'
    . '$result = "OK skipped=" . (str_contains($w, "Image skipped") ? 1 : 0);';
[$r, $out] = runIsolated($imgCode('pal.png'), '64M');
check('palette PNG bomb at memory_limit=64M: skipped with a warning, no fatal error', noFatal($out) && $r === 'OK skipped=1', $r . ' ' . substr($out, 0, 200));
[$r, $out] = runIsolated($imgCode('pal.png'), '128M');
check('palette PNG bomb at memory_limit=128M: decoded (no truecolor copy) or skipped, no fatal error', noFatal($out) && str_starts_with($r, 'OK'), $r . ' ' . substr($out, 0, 200));
if ($gif !== '') {
    [$r, $out] = runIsolated($imgCode('bomb.gif'), '64M');
    check('GIF bomb at memory_limit=64M: skipped with a warning, no fatal error', noFatal($out) && $r === 'OK skipped=1', $r . ' ' . substr($out, 0, 200));
}
[$r, $out] = runIsolated($imgCode('rgba.png') . '$result .= " peak=" . round(memory_get_peak_usage(true) / 1048576);', '64M');
check('RGBA PNG (pure-PHP decoder, 4 MP) streams within memory_limit=64M', noFatal($out) && str_starts_with($r, 'OK skipped=0'), $r . ' ' . substr($out, 0, 200));

// in-process: RenderLimits helpers and the per-pixel budget
check('RenderLimits::available() is positive and fits() agrees', RenderLimits::available() > 0 && RenderLimits::fits(1024) && !RenderLimits::fits(PHP_INT_MAX));
$caught = null;
try {
    RenderLimits::ensure(PHP_INT_MAX, 'a test allocation');
} catch (\IvoPdf\Exception\LimitExceededException $e) {
    $caught = $e;
}
check('RenderLimits::ensure() throws LimitExceededException (memory) for what cannot fit', $caught !== null && $caught->getLimit() === 'memory' && str_contains($caught->getMessage(), 'a test allocation'));

// ---------------------------------------------------------------------------
echo "\n2. H1 large trees, many lines, huge textarea, tab-size (separate processes)\n";
// ---------------------------------------------------------------------------
$renderCode = static fn(string $htmlExpr, string $opts = '[]'): string => '$pdf = new IvoPdf\IvoPdf(' . $opts . ');'
    . '$pdf->loadHtml(' . $htmlExpr . ')->output(); $result = "OK pages=" . $pdf->getPageCount();';
foreach ([
    ['x<br> x 100 000 (500 KB of HTML)', "str_repeat('x<br>', 100000)", '128M'],
    ['x<br> x 100 000', "str_repeat('x<br>', 100000)", '256M'],
    ['200 000 <span>', "'<p>' . str_repeat('<span>a</span>', 200000)", '128M'],
    ['200 000 SVG <rect>', "'<svg width=\"100\" height=\"100\">' . str_repeat('<rect width=\"1\" height=\"1\"/>', 200000) . '</svg>'", '256M'],
    ['50 000 links', "str_repeat('<a href=\"http://x/\">a</a> ', 50000)", '256M'],
    ['30 000 lines', "str_repeat('x<br>', 30000)", '128M'],
] as [$label, $expr, $limit]) {
    [$r, $out] = runIsolated($renderCode($expr), $limit);
    check("{$label} at memory_limit={$limit}: result or LimitExceededException, no fatal error", noFatal($out) && ($r === 'LIMIT memory' || str_starts_with($r, 'OK')), $r . ' ' . substr($out, 0, 240));
}
[$r, $out] = runIsolated($renderCode("'<textarea>' . str_repeat('line\\\\n', 100000) . '</textarea>'"), '128M');
check('a 600 KB textarea value at memory_limit=128M renders (only the visible part is measured)', noFatal($out) && str_starts_with($r, 'OK'), $r . ' ' . substr($out, 0, 200));
[$r, $out] = runIsolated($renderCode("'<pre style=\"tab-size:1e9\">' . str_repeat(\"\\t\", 100000) . 'x</pre>'"), '128M');
check('tab-size: 1e9 with 100 000 tabs at memory_limit=128M renders', noFatal($out) && str_starts_with($r, 'OK'), $r . ' ' . substr($out, 0, 200));

if (Html5Parser::available()) {
    $est = Html5Parser::estimateParseBytes(str_repeat('x<br>', 1000));
    check('parse estimate of 1 000 "x<br>" is about 1.6 MB (measured lexbor peak: ~1.4 MB)', $est > 1400000 && $est < 2500000, (string) $est);
}

// ---------------------------------------------------------------------------
echo "\n3. H2 one time budget per render(), across footnote passes\n";
// ---------------------------------------------------------------------------
$fnHtml = '<p>' . str_repeat('t<span style="float:footnote">note</span> ', 1600) . '</p>';
$t = microtime(true);
$caught = null;
try {
    (new IvoPdf(['maxRenderTime' => 3]))->loadHtml($fnHtml)->output();
} catch (\IvoPdf\Exception\LimitExceededException $e) {
    $caught = $e;
}
$el = microtime(true) - $t;
check('1 600 footnotes (many passes, each under the limit) with maxRenderTime=3 stop after ~3 s (' . round($el, 1) . ' s)', $caught !== null && $caught->getLimit() === 'time' && $el < 6.0, $caught?->getMessage() ?? 'no exception');
$ok = (new IvoPdf(['maxRenderTime' => 30]))->loadHtml('<p>a<span style="float:footnote">n</span></p>')->output();
check('the budget starts again for the next render', str_starts_with($ok, '%PDF-'));

// ---------------------------------------------------------------------------
echo "\n4. H6 near-linear hot paths\n";
// ---------------------------------------------------------------------------
$time = static function (string $html): float {
    $t = microtime(true);
    (new IvoPdf(['maxRenderTime' => 300]))->loadHtml($html)->output();
    return microtime(true) - $t;
};
$classes = static fn(int $n): string => '<style>' . implode('', array_map(static fn(int $i): string => ".c{$i}{color:red}", range(0, $n))) . '</style><p class="'
    . implode(' ', array_map(static fn(int $i): string => "c{$i}", range(0, $n))) . '">x</p>';
$a = $time($classes(10000));
$b = $time($classes(20000));
check(sprintf('20 000 classes on one element with a rule each: %.2f s (10 000: %.2f s), near-linear', $b, $a), $b < 6.0 && $b < max(0.3, $a) * 3.5);
$el = $time('<style>' . str_repeat(':not(', 2000) . 'p' . str_repeat(')', 2000) . '{color:blue}' . str_repeat('div ', 5000) . 'p{color:red}</style><div><p>x</p></div>');
check(sprintf('a 2 000-deep :not() and a 5 000-compound selector: %.2f s', $el), $el < 3.0);
$fnA = $time('<p>' . str_repeat('t<span style="float:footnote">note</span> ', 200) . '</p>');
$fnB = $time('<p>' . str_repeat('t<span style="float:footnote">note</span> ', 400) . '</p>');
check(sprintf('400 footnote calls in one paragraph: %.2f s (200: %.2f s)', $fnB, $fnA), $fnB < 15.0);
$fnDoc = str_repeat('<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor.<span style="float:footnote">A note.</span> More text.</p>', 400);
$fnP = new IvoPdf(['maxRenderTime' => 300]);
$t = microtime(true);
$fnP->loadHtml($fnDoc)->output();
$el = microtime(true) - $t;
check(sprintf('400 paragraphs with a footnote each: %.2f s, %d pages, no notes left out', $el, $fnP->getPageCount()), $el < 20.0 && !str_contains(implode(' ', $fnP->getWarnings()), 'left out'));
$el = $time('<toc/>' . str_repeat('<h1>H</h1>', 2000));
check(sprintf('<toc> with 2 000 headings: %.2f s', $el), $el < 20.0);

// ---------------------------------------------------------------------------
echo "\n5. M3 memory released after output()\n";
// ---------------------------------------------------------------------------
$png = zeroPng(300, 300, 6);
$pdf = new IvoPdf();
$pdf->loadHtml('<img src="' . dataUri($png, 'image/png') . '"><table>' . str_repeat('<tr><td>a</td><td>b</td></tr>', 50) . '</table>')->output();
$loader = (new ReflectionProperty($pdf, 'imageLoader'))->getValue($pdf);
check('decoded images are released after output() (they are in the PDF)', $loader->getLoadedImages() === []);
check('getLayoutTree() still works after output()', $pdf->getLayoutTree() !== null && $pdf->getLayoutTree()->children !== []);

// ---------------------------------------------------------------------------
echo "\n6. L1 absurd numeric attributes, corrupt images: no PHP warnings\n";
// ---------------------------------------------------------------------------
check('InputGuards::intAttr: HTML integer rules, saturation, default', InputGuards::intAttr('99999999999999999999', 1, 100, 1) === 100
    && InputGuards::intAttr('-99999999999999999999', -5, 5, 0) === -5 && InputGuards::intAttr(' 12abc', 0, 99, 0) === 12
    && InputGuards::intAttr('1e400', 1, 100, 1) === 1 && InputGuards::intAttr('x', 1, 100, 7) === 7 && InputGuards::intAttr(null, 1, 9, 3) === 3);
$phpIssues = [];
$tiny = dataUri(zeroPng(2, 2, 2), 'image/png');
foreach (['99999999999999999999', '-99999999999999999999', '1e400', 'NaN', '9223372036854775808'] as $v) {
    $html = "<table border=\"$v\" cellpadding=\"$v\" width=\"$v\"><colgroup span=\"$v\"><col span=\"$v\" width=\"$v\"></colgroup><tr><td colspan=\"$v\" rowspan=\"$v\">x</td></tr></table>"
        . "<ol start=\"$v\"><li value=\"$v\">a</li></ol><font size=\"$v\">f</font><img src=\"$tiny\" width=\"$v\" height=\"$v\"><hr size=\"$v\">"
        . "<input size=\"$v\" maxlength=\"$v\"><textarea rows=\"$v\" cols=\"$v\">t</textarea><select size=\"$v\"><option>o</option></select>"
        . "<img src=\"doc.pdf\" data-page=\"$v\"><bookmark content=\"b\" level=\"$v\" /><columns column-count=\"$v\" /><p>c</p><columns column-count=\"1\" />"
        . "<dottab outdent=\"$v\" /><p>z</p>";
    try {
        (new IvoPdf(['maxPages' => 20, 'maxRenderTime' => 30]))->loadHtml($html)->output();
    } catch (\Throwable $e) {
        $phpIssues[] = get_class($e) . ': ' . $e->getMessage();
    }
}
check('absurd colspan/rowspan/span/start/value/size/rows/cols/level/outdent values: no PHP warnings or errors', $phpIssues === [], implode(' | ', array_slice(array_unique($phpIssues), 0, 3)));

$phpIssues = [];
$valid = zeroPng(40, 30, 3, $plte);
$warnCount = [];
foreach (['truncated' => substr($valid, 0, (int) (strlen($valid) * 0.6)), 'header only' => substr($valid, 0, 40), 'garbled' => substr($valid, 0, 33) . str_repeat("\x5A", 60)] as $name => $bytes) {
    $p = new IvoPdf();
    $p->loadHtml('<p>x</p><img src="' . dataUri($bytes, 'image/png') . '">')->output();
    $warnCount[$name] = count(array_filter($p->getWarnings(), static fn(string $w): bool => stripos($w, 'image') !== false));
}
check('corrupt PNGs leak no PHP warnings (gd / libpng / zlib)', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 2)));
check('each corrupt PNG gives one IvoPDF warning', array_values(array_unique($warnCount)) === [1], json_encode($warnCount));

// ---------------------------------------------------------------------------
echo "\n7. L3 HTML5-like normalisation for libxml; <tocentry> mode\n";
// ---------------------------------------------------------------------------
$n = Html5Parser::html5Normalize('<p>&nbsp&amp&copy;&hellip;&notanentity;&zzz; a&b</p><a href="?a=1&copy=2&amp;b">t</a>');
check('entities without semicolon, HTML5 names, longest legacy prefix, literal unknowns', str_contains($n, "\u{00A0}&amp;©…¬anentity;&amp;zzz; a&amp;b"), $n);
check('in attribute values "&copy=" stays literal', str_contains($n, 'href="?a=1&amp;copy=2&amp;b"'), $n);
$text = static function (string $html): string {
    $dom = Html5Parser::parseLegacy($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    return $body !== null ? (string) $dom->saveHTML($body) : '';
};
check('libxml path: CDATA in HTML is dropped, kept in SVG', !str_contains($text('<p>a<![CDATA[x]]>c</p>'), 'CDATA') && str_contains(Html5Parser::html5Normalize('<svg><text><![CDATA[s]]></text></svg>'), 'CDATA[s]'));
check('libxml path: <section> closes the open <p>', str_contains($text('<p>para<section>sec</section>after</p>'), '<p>para</p><section>sec</section>'));
check('libxml path: <form> around table rows keeps the row and its input', str_contains($text('<table><form><tr><td><input name="q" value="v"></td></tr></form></table>'), 'value="v"'));
check('libxml path: </br> is a line break, <td> in <table> gets its <tr>', str_contains($text('<p>a</br>b</p>'), '<br>') && str_contains($text('<table><td>no tr</td></table>'), 'no tr'));

$toc = \IvoPdf\Paged\TocGenerator::process('<tocpagebreak /><h1>Chapter</h1><tocentry content="Manual" level="1" /><p>x</p><h2>Sub</h2>', false);
preg_match_all('~ivo-toc-entry[^>]*><a[^>]*>([^<]*)~', $toc, $m);
check('a document with <tocentry> lists only those entries (mPDF)', $m[1] === ['Manual'], json_encode($m[1]));
$toc = \IvoPdf\Paged\TocGenerator::process('<toc /><h1>Chapter</h1><h2>Sub</h2>', false);
preg_match_all('~ivo-toc-entry[^>]*><a[^>]*>([^<]*)~', $toc, $m);
check('without <tocentry> the headings are listed', $m[1] === ['Chapter', 'Sub'], json_encode($m[1]));

foreach (glob($tmpDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir);

restore_error_handler();
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
