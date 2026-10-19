<?php

declare(strict_types=1);

/**
 * Regression tests for audit 7:
 *  - gradients with transparency (soft mask, premultiplied interpolation, PDF/A-1 / PDF/X flattening)
 *  - repeating-linear / repeating-radial / conic / repeating-conic gradients, two-position stops, colour hints,
 *    currentColor stops, length stop positions, gradient tiles (background-size), several background layers
 *  - <input type="password">: bullets, the value never written into the PDF
 *  - nested CSS counters (counter-reset scoping, counters())
 *  - SVG <textPath>
 *  - li { display: block } has no marker, box-shadow: inset, bookmark-level / bookmark-label, numeric maxMemory
 *
 * Pixel and text checks use PyMuPDF (skipped when Python / PyMuPDF is not available).
 *
 * Run: php tests/test_audit7.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$phpIssues): bool {
    if ((error_reporting() & $no) === 0) {
        return true; // @-suppressed in the test itself
    }
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

/** @param array<string, mixed> $options */
function make(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false, 'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]], $options));
    $pdf->loadHtml('<style>body{margin:0}</style>' . $html);
    return $pdf;
}

function pyAvailable(): bool
{
    static $ok = null;
    if ($ok === null) {
        $probe = @shell_exec('python -I -c "import pymupdf;print(1)" 2>&1');
        $ok = is_string($probe) && trim($probe) === '1';
    }
    return $ok;
}

/**
 * Runs Python code with `d` = the opened PDF; returns the decoded JSON it prints (null without PyMuPDF).
 */
function py(string $pdfBytes, string $code): mixed
{
    if (!pyAvailable()) {
        return null;
    }
    $dir = sys_get_temp_dir();
    $id = md5($pdfBytes . $code);
    $file = $dir . DIRECTORY_SEPARATOR . "ivopdf-a7-{$id}.pdf";
    $script = $dir . DIRECTORY_SEPARATOR . "ivopdf-a7-{$id}.py";
    file_put_contents($file, $pdfBytes);
    file_put_contents($script, "import sys, json\nimport pymupdf as fitz\nd = fitz.open(sys.argv[1])\n" . $code . "\n");
    $out = @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    @unlink($script);
    $decoded = is_string($out) ? json_decode(trim($out), true) : null;
    return $decoded;
}

/**
 * RGB pixels (72 dpi, page 1) at the given points (pt).
 *
 * @param list<array{0: float|int, 1: float|int}> $points
 * @return list<array{0: int, 1: int, 2: int}>|null
 */
function pixels(string $pdfBytes, array $points): ?array
{
    $pts = json_encode($points);
    $res = py($pdfBytes, "pm = d[0].get_pixmap(dpi=72, alpha=False)\nprint(json.dumps([list(pm.pixel(int(x), int(y))[:3]) for x, y in {$pts}]))");
    return is_array($res) ? $res : null;
}

/** Text of page 1 (words joined by spaces, in reading order of their lines). */
function pageText(string $pdfBytes): ?string
{
    $res = py($pdfBytes, "ws = d[0].get_text('words')\nws.sort(key=lambda w: (round(w[1] / 4), w[0]))\nprint(json.dumps(' '.join(w[4] for w in ws)))");
    return is_string($res) ? $res : null;
}

/** @param array{0: int, 1: int, 2: int} $px */
function near(?array $px, array $want, int $tol = 12): bool
{
    return $px !== null && abs($px[0] - $want[0]) <= $tol && abs($px[1] - $want[1]) <= $tol && abs($px[2] - $want[2]) <= $tol;
}

function fmt(?array $px): string
{
    return $px === null ? 'null' : '(' . implode(',', $px) . ')';
}

/** A 100 x 50 pt box at (10, 10) with the background $bg over an outer colour. */
function gradBox(string $bg, string $under = '#ffffff', string $extra = ''): string
{
    return '<div style="padding:10pt;width:100pt;height:50pt;background:' . $under . '"><div style="width:100pt;height:50pt;background:' . $bg . ';' . $extra . '"></div></div>';
}

// ---------------------------------------------------------------------------
echo "\n--- gradients with transparency ---\n";
$pdf = make(gradBox('linear-gradient(90deg,rgba(255,0,0,1),rgba(255,0,0,0))', '#ffcc00'));
$bytes = $pdf->output();
check('rgba(..,0) stop keeps the gradient (a soft mask is written)', str_contains($bytes, '/S /Luminosity'));
if (($px = pixels($bytes, [[12, 35], [60, 35], [108, 35]])) !== null) {
    check('red to rgba(255,0,0,0) over yellow: red at the start', near($px[0], [255, 10, 0], 20), fmt($px[0]));
    check('... half red half yellow in the middle', near($px[1], [255, 102, 0], 20), fmt($px[1]));
    check('... the yellow backdrop at the end', near($px[2], [255, 200, 0], 20), fmt($px[2]));
} else {
    skip('gradient pixels (PyMuPDF not available)');
}

$bytes = make(gradBox('linear-gradient(rgba(0,0,0,0),rgba(0,0,0,.7))', '#2a7fff'))->output();
if (($px = pixels($bytes, [[60, 11], [60, 35], [60, 59]])) !== null) {
    check('dark overlay: the top shows the backdrop', near($px[0], [42, 127, 255], 15), fmt($px[0]));
    check('dark overlay: the middle is darkened (not drawn opaque, not lost)', $px[1][2] < 200 && $px[1][2] > 120, fmt($px[1]));
    check('dark overlay: the bottom is 70 % black', near($px[2], [13, 38, 77], 15), fmt($px[2]));
}

$bytes = make(gradBox('linear-gradient(90deg,#ff000080,#0000ff80)'))->output();
if (($px = pixels($bytes, [[12, 35], [108, 35]])) !== null) {
    check('#rrggbbaa stops are 50 % transparent (left)', near($px[0], [255, 128, 128], 15), fmt($px[0]));
    check('#rrggbbaa stops are 50 % transparent (right)', near($px[1], [128, 128, 255], 15), fmt($px[1]));
}

$bytes = make(gradBox('linear-gradient(90deg,#f00,transparent)', '#00cc66'))->output();
if (($px = pixels($bytes, [[60, 35]])) !== null) {
    check('`transparent` is interpolated premultiplied (no grey fringe)', near($px[0], [128, 102, 51], 15), fmt($px[0]));
}

$bytes = make(gradBox('radial-gradient(circle,rgba(255,255,255,1),rgba(255,255,255,0) 50%)', '#334455'))->output();
if (($px = pixels($bytes, [[60, 35], [15, 15]])) !== null) {
    check('radial gradient with alpha: white centre', near($px[0], [255, 255, 255], 20), fmt($px[0]));
    check('radial gradient with alpha: backdrop at the corner', near($px[1], [51, 68, 85], 12), fmt($px[1]));
}

// PDF/A and PDF/X
$alphaHtml = gradBox('linear-gradient(90deg,rgba(0,0,0,0),rgba(0,0,0,.6))', '#ffffff', 'background-color:#2a7fff');
$a2 = make($alphaHtml, ['pdfa' => '2b']);
$a2Bytes = $a2->output();
check('PDF/A-2b keeps the gradient soft mask', str_contains($a2Bytes, '/S /Luminosity') && $a2->getWarnings() === [], json_encode($a2->getWarnings()));
$a1 = make($alphaHtml, ['pdfa' => '1b']);
$a1Bytes = $a1->output();
$a1Warn = implode(' ', $a1->getWarnings());
check('PDF/A-1b: no soft mask, transparency flattened with a warning', !str_contains($a1Bytes, '/SMask') && str_contains($a1Warn, 'Gradient transparency was flattened'), $a1Warn);
if (($px = pixels($a1Bytes, [[12, 35], [108, 35]])) !== null) {
    check('PDF/A-1b: flattened onto the element background colour', near($px[0], [42, 127, 255], 12) && near($px[1], [17, 51, 102], 15), fmt($px[0]) . ' ' . fmt($px[1]));
}
$x1 = make($alphaHtml, ['pdfx' => 'X-1a']);
$x1Bytes = $x1->output();
check('PDF/X-1a: no soft mask, no DeviceRGB shading, a flattening warning', !str_contains($x1Bytes, '/SMask <<') && !preg_match('~/ShadingType[^>]*/DeviceRGB~', $x1Bytes) && str_contains(implode(' ', $x1->getWarnings()), 'Gradient transparency was flattened'));

// ---------------------------------------------------------------------------
echo "\n--- repeating, conic and other gradient forms ---\n";
$bytes = make(gradBox('repeating-linear-gradient(90deg,#f00 0,#f00 10pt,#00f 10pt,#00f 20pt)'))->output();
if (($px = pixels($bytes, [[15, 35], [25, 35], [35, 35], [45, 35], [105, 35]])) !== null) {
    check('repeating-linear-gradient stripes', near($px[0], [255, 0, 0]) && near($px[1], [0, 0, 255]) && near($px[2], [255, 0, 0]) && near($px[3], [0, 0, 255]) && near($px[4], [0, 0, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make(gradBox('repeating-linear-gradient(45deg,#f00,#f00 5px,#fff 5px,#fff 10px)'))->output();
check('repeating stripes in px: one band shading drawn once per repetition', substr_count($bytes, ' cm /ShR') > 10);
$bytes = make(gradBox('repeating-radial-gradient(circle,#f00,#f00 5pt,#fff 5pt,#fff 10pt)'))->output();
if (($px = pixels($bytes, [[60, 35], [67, 35], [72, 35], [77, 35]])) !== null) {
    check('repeating-radial-gradient rings', near($px[0], [255, 0, 0]) && near($px[1], [255, 255, 255]) && near($px[2], [255, 0, 0]) && near($px[3], [255, 255, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make(gradBox('conic-gradient(#e33 0 25%,#3a3 0 60%,#36f 0)'))->output();
check('conic-gradient is a triangle mesh shading', str_contains($bytes, '/ShadingType 4'));
if (($px = pixels($bytes, [[80, 25], [80, 45], [40, 25]])) !== null) {
    check('conic pie: quarter at the top right', near($px[0], [238, 51, 51], 15), fmt($px[0]));
    check('conic pie: second sector bottom right', near($px[1], [51, 170, 51], 15), fmt($px[1]));
    check('conic pie: third sector on the left', near($px[2], [51, 102, 255], 15), fmt($px[2]));
}
$bytes = make(gradBox('conic-gradient(from 90deg,#f00,#00f)'))->output();
if (($px = pixels($bytes, [[100, 33], [100, 37], [60, 45]])) !== null) {
    check('conic from 90deg starts red at the right (just below the axis)', $px[1][0] > 200 && $px[1][2] < 60, fmt($px[1]));
    check('conic from 90deg ends blue just above the axis', $px[0][2] > 200 && $px[0][0] < 60, fmt($px[0]));
}
$bytes = make(gradBox('repeating-conic-gradient(#000 0 25%,#fff 0 50%)', '#fff', 'background-size:20pt 20pt'))->output();
if (($px = pixels($bytes, [[25, 15], [15, 15], [15, 25], [25, 25]])) !== null) {
    check('repeating-conic checkerboard tile (background-size)', near($px[0], [0, 0, 0], 30) && near($px[1], [255, 255, 255], 30) && near($px[2], [0, 0, 0], 30) && near($px[3], [255, 255, 255], 30), implode(' ', array_map('fmt', $px)));
}
$bytes = make(gradBox('linear-gradient(90deg,#f00 0 50%,#00f 50% 100%)'))->output();
if (($px = pixels($bytes, [[20, 35], [55, 35], [65, 35], [100, 35]])) !== null) {
    check('stop with two positions: hard red / blue halves', near($px[0], [255, 0, 0]) && near($px[1], [255, 0, 0]) && near($px[2], [0, 0, 255]) && near($px[3], [0, 0, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make(gradBox('linear-gradient(90deg,currentColor,#fff)', '#fff', 'color:#00aa00'))->output();
if (($px = pixels($bytes, [[11, 35]])) !== null) {
    check('currentColor stop takes the element colour', near($px[0], [0, 170, 0], 15), fmt($px[0]));
}
$bytes = make(gradBox('linear-gradient(90deg,#f00,20%,#00f)'))->output();
if (($px = pixels($bytes, [[30, 35]])) !== null) {
    check('colour hint at 20 %: the midpoint colour sits there', near($px[0], [128, 0, 128], 25), fmt($px[0]));
}
$bytes = make(gradBox('linear-gradient(90deg,#f00 20pt,#00f 40pt)'))->output();
if (($px = pixels($bytes, [[25, 35], [40, 35], [80, 35]])) !== null) {
    check('stop positions as lengths', near($px[0], [255, 0, 0]) && near($px[1], [128, 0, 128], 30) && near($px[2], [0, 0, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make(gradBox('linear-gradient(to top right,#f00 50%,#00f 50%)', '#fff', 'width:200pt'))->output();
if (($px = pixels($bytes, [[15, 15], [205, 55], [80, 22], [140, 48]])) !== null) {
    // to top right: the 50 % line runs through the top-left and bottom-right corners (Chromium "magic corners")
    check('`to top right` follows the box diagonal', near($px[0], [255, 0, 0]) && near($px[1], [0, 0, 255]) && near($px[2], [0, 0, 255]) && near($px[3], [255, 0, 0]), implode(' ', array_map('fmt', $px)));
}
$pdf = make('<div style="width:200pt;height:100pt;background:linear-gradient(rgba(0,0,0,0),rgba(0,0,0,.7)), url(data:image/gif;base64,R0lGODlhAQABAIAAAP8AAP///ywAAAAAAQABAAACAkQBADs=) center/cover"></div>');
$bytes = $pdf->output();
if (($px = pixels($bytes, [[100, 2], [100, 98]])) !== null) {
    check('gradient overlay above a photo layer: the photo stays (top)', near($px[0], [255, 0, 0], 15), fmt($px[0]));
    check('... and is darkened at the bottom', $px[1][0] < 110 && $px[1][0] > 60, fmt($px[1]));
}
$bytes = make(gradBox('-webkit-linear-gradient(left,#f00,#00f)'))->output();
if (($px = pixels($bytes, [[12, 35], [108, 35]])) !== null) {
    check('legacy -webkit-linear-gradient(left, ...) starts at the left (as Chromium)', near($px[0], [255, 0, 0], 20) && near($px[1], [0, 0, 255], 20), fmt($px[0]) . ' ' . fmt($px[1]));
}
$bytes = make(gradBox('-moz-linear-gradient(left,#f00,#00f)'))->output();
if (($px = pixels($bytes, [[60, 35]])) !== null) {
    check('-moz-linear-gradient is not drawn (Chromium ignores it)', near($px[0], [255, 255, 255], 5), fmt($px[0]));
}
$bytes = make('<div style="width:200pt;height:100pt;border:20pt solid;border-image:conic-gradient(red,blue,red) 1"></div>')->output();
if (($px = pixels($bytes, [[120, 10], [120, 130], [10, 70]])) !== null) {
    check('conic border-image: red at the top, blue at the bottom', near($px[0], [255, 0, 0], 40) && near($px[1], [0, 0, 255], 40) && $px[2][0] > 80 && $px[2][2] > 80, implode(' ', array_map('fmt', $px)));
}
$pdf = make(gradBox('wobbly-gradient(red, blue)'));
$pdf->output();
$pdf = make(gradBox('linear-gradient(in oklch, red, blue)'));
$pdf->output();
check('an unsupported gradient is reported', str_contains(implode(' ', $pdf->getWarnings()), 'Unsupported or invalid CSS gradient'), json_encode($pdf->getWarnings()));
check('legacy CssColor::parseLinearGradient still parses', \IvoPdf\Css\CssColor::parseLinearGradient('linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%)')['angle'] === 135.0);
check('CssColor::parse() ignores a malformed hex colour (no PHP deprecation)', \IvoPdf\Css\CssColor::parse('#f00 10pt') === null);

// ---------------------------------------------------------------------------
echo "\n--- password fields ---\n";
foreach ([true, false] as $active) {
    foreach ([false, true] as $compress) {
        $pdf = make('<form><input type="password" name="pw" value="s3cr3tValue"></form>', ['formFields' => $active, 'compress' => $compress]);
        $bytes = $pdf->output();
        $label = ($active ? 'active' : 'static') . ($compress ? ', compressed' : '');
        $raw = $bytes;
        if ($compress) {
            // inflate every stream to search the decoded content too
            $raw .= (string) preg_replace_callback('~stream\r?\n(.*?)\r?\nendstream~s', static fn(array $m): string => (string) @gzuncompress($m[1]), $bytes);
        }
        check("password value never written into the PDF ({$label})", !str_contains($raw, 's3cr3tValue') && !str_contains($raw, 's3cr3t'));
        if (!$compress && ($txt = pageText($bytes)) !== null) {
            check("password drawn as one bullet per character ({$label})", str_contains($txt, str_repeat("\u{2022}", 11)), $txt);
        }
    }
}
$bytes = make('<form><input type="password" name="pw" value="abc"></form>')->output();
check('password field: Ff password flag, no /V', preg_match('~/T \(pw\)[^\n]*/Ff 8192~', $bytes) === 1 && preg_match('~/T \(pw\)[^\n]*/V ~', $bytes) !== 1);

// ---------------------------------------------------------------------------
echo "\n--- CSS counters ---\n";
$counterCases = [
    'nested counters()' => ['<style>ol{counter-reset:item;list-style:none;padding:0}li{counter-increment:item}li::before{content:counters(item,".") " "}</style><ol><li>a<ol><li>b<ol><li>c</li></ol></li><li>d</li></ol></li><li>e</li></ol>', '1 a 1.1 b 1.1.1 c 1.2 d 2 e'],
    'counter() of the outer list resumes' => ['<style>ol{counter-reset:item;list-style:none;padding:0}li{counter-increment:item}li::before{content:counter(item) ". "}</style><ol><li>a<ol><li>b</li><li>c</li></ol></li><li>d</li></ol>', '1. a 1. b 2. c 2. d'],
    'h1 / h2 numbering' => ['<style>body{counter-reset:h1}h1{counter-increment:h1;counter-reset:h2}h2{counter-increment:h2}h1::before{content:counter(h1)". "}h2::before{content:counter(h1)"."counter(h2)" "}</style><h1>A</h1><h2>a</h2><h2>b</h2><h1>B</h1><h2>c</h2>', '1. A 1.1 a 1.2 b 2. B 2.1 c'],
    'increment without a reset is scoped to the siblings' => ['<style>p{counter-increment:n}p::before{content:counter(n)" "}</style><div><p>x</p><p>y</p></div><div><p>z</p></div>', '1 x 2 y 1 z'],
    'counter-set' => ['<style>body{counter-reset:k}p{counter-increment:k}p.s{counter-set:k 10}p::before{content:counter(k)" "}</style><p>a</p><p class=s>b</p><p>c</p>', '1 a 10 b 11 c'],
    'counter-reset on body with a value' => ['<style>body{counter-reset:k 5}p{counter-increment:k 2}p::before{content:counter(k)" "}</style><p>a</p><p>b</p>', '7 a 9 b'],
    'a reset in ::before stays in the pseudo-element (Chromium)' => ['<style>body{counter-reset:q}.d::before{content:"";counter-reset:q 7}p{counter-increment:q}p::before{content:counter(q)" "}</style><div class=d><p>a</p><p>b</p></div><p>c</p>', '1 a 2 b 3 c'],
    'counters() separator with a comma, list style' => ['<style>ol{counter-reset:item;list-style:none;padding:0}li{counter-increment:item}li::before{content:"[" counters(item, ", ", upper-roman) "] "}</style><ol><li>a<ol><li>b</li></ol></li></ol>', '[I] a [I, I] b'],
    'counter(x, none) is empty' => ['<style>body{counter-reset:k}p{counter-increment:k}p::before{content:counter(k, none) "|" counter(k, lower-alpha) " "}</style><p>a</p><p>b</p>', '|a a |b b'],
    '::marker content with counters(list-item)' => ['<style>li::marker{content:counters(list-item,".") " "}</style><ol><li>a<ol><li>b<ol><li>c</li></ol></li><li>d</li></ol></li><li>e</li></ol>', '1 a 1.1 b 1.1.1 c 1.2 d 2 e'],
    'list-item still nests when the author resets another counter on ol' => ['<style>ol{counter-reset:item}li::marker{content:counters(list-item,".") " "}</style><ol><li>a<ol><li>b</li></ol></li><li>c</li></ol>', '1 a 1.1 b 2 c'],
    '::marker with ol start and a counter style' => ['<style>li::marker{content:counter(list-item, upper-roman) ". "}</style><ol start="3"><li>x</li><li>y</li></ol>', 'III. x IV. y'],
    '::marker string and none' => ['<style>ul li::marker{content:"-> "}ol li::marker{content:none}</style><ul><li>x</li></ul><ol><li>y</li></ol>', '-> x y'],
    'counter(list-item) in ::before' => ['<style>ol{list-style:none}li::before{content:counters(list-item,".") " "}</style><ol><li>a<ol><li>b</li></ol></li><li>c</li></ol>', '1 a 1.1 b 2 c'],
    'li { display: block } with ::before counters' => ['<style>ol{counter-reset:item}li{display:block}li::before{content:counters(item,".") " ";counter-increment:item}</style><ol><li>x<ol><li>y</li></ol></li><li>v</li></ol>', '1 x 1.1 y 2 v'],
];
foreach ($counterCases as $label => [$html, $want]) {
    $txt = pageText(make($html)->output());
    if ($txt === null) {
        skip($label);
        continue;
    }
    check($label, $txt === $want, $txt);
}

echo "\n--- list markers ---\n";
$txt = pageText(make('<ul><li style="display:block">item</li></ul><ol><li>one</li><li style="display:block">two</li><li>three</li></ol>')->output());
if ($txt !== null) {
    check('li { display: block } has no marker and does not count', $txt === 'item 1. one two 2. three', $txt);
}

// ---------------------------------------------------------------------------
echo "\n--- SVG textPath ---\n";
$svg = '<svg width="400" height="266" viewBox="0 0 400 266"><defs><path id="p" d="M20 200 Q200 20 380 200"/></defs>'
    . '<text font-size="28"><textPath href="#p">CURVED TEXT ON A PATH</textPath></text></svg>';
$pdf = make($svg);
$bytes = $pdf->output();
$chars = py($bytes, "out = []\nfor b in d[0].get_text('rawdict')['blocks']:\n  for l in b.get('lines', []):\n    for s in l['spans']:\n      for c in s['chars']:\n        out.append([c['c'], c['origin'][0], c['origin'][1], l['dir'][0], l['dir'][1]])\nprint(json.dumps(out))");
if (is_array($chars)) {
    $letters = implode('', array_map(static fn(array $c): string => $c[0], $chars));
    check('textPath text is drawn (not lost)', str_replace(' ', '', $letters) === 'CURVEDTEXTONAPATH', $letters);
    $n = count($chars);
    if ($n >= 17) {
        $mid = intdiv($n, 2);
        check('glyphs follow the arc: the middle is higher than the ends', $chars[$mid][2] < $chars[0][2] - 20 && $chars[$mid][2] < $chars[$n - 1][2] - 20, json_encode(array_map(static fn(array $c): float => round($c[2], 1), $chars)));
        check('glyphs are rotated along the path (rising, then falling)', $chars[0][4] < -0.3 && $chars[$n - 1][4] > 0.3, sprintf('%.2f %.2f', $chars[0][4], $chars[$n - 1][4]));
    }
} else {
    skip('textPath glyph positions (PyMuPDF not available)');
}
$pdf = make('<svg width="400" height="266" viewBox="0 0 400 266"><defs><path id="p" d="M20 200 Q200 20 380 200"/></defs>'
    . '<text font-size="28" text-anchor="middle"><textPath xlink:href="#p" startOffset="50%">OOOOOOOO</textPath></text></svg>');
$chars2 = py($pdf->output(), "print(json.dumps([[c['c'], (c['bbox'][0] + c['bbox'][2]) / 2] for b in d[0].get_text('rawdict')['blocks'] for l in b.get('lines', []) for s in l['spans'] for c in s['chars']]))");
if (is_array($chars2) && count($chars2) === 8) {
    $last = $chars2[7][1];
    // the page is 0.75 pt per SVG px: the path's middle is at x = 150 pt
    check('xlink:href, startOffset="50%" and text-anchor: middle centre the text on the path', abs(($chars2[0][1] + $last) / 2 - 150) < 2, json_encode([$chars2[0][1], $last]));
}
$pdf = make('<svg width="200" height="100"><text><textPath href="#missing">LOST</textPath></text></svg>');
$pdf->output();
check('textPath with a missing path is reported', str_contains(implode(' ', $pdf->getWarnings()), 'textPath'), json_encode($pdf->getWarnings()));

// ---------------------------------------------------------------------------
echo "\n--- box-shadow: inset ---\n";
$bytes = make('<div style="margin:20pt;width:100pt;height:60pt;background:#fff;box-shadow:inset 0 0 0 8pt #00f"></div>')->output();
if (($px = pixels($bytes, [[24, 50], [70, 24], [70, 50], [10, 50]])) !== null) {
    check('inset spread shadow inside the padding box', near($px[0], [0, 0, 255]) && near($px[1], [0, 0, 255]), fmt($px[0]) . ' ' . fmt($px[1]));
    check('inset shadow leaves the centre and the outside alone', near($px[2], [255, 255, 255]) && near($px[3], [255, 255, 255]), fmt($px[2]) . ' ' . fmt($px[3]));
}
$bytes = make('<div style="margin:20pt;width:100pt;height:60pt;background:#fff;box-shadow:inset 8pt 8pt 0 #000"></div>')->output();
if (($px = pixels($bytes, [[24, 50], [116, 50], [70, 24], [70, 76]])) !== null) {
    check('inset offset shadow: left / top edges dark, right / bottom clean', near($px[0], [0, 0, 0]) && near($px[2], [0, 0, 0]) && near($px[1], [255, 255, 255]) && near($px[3], [255, 255, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make('<div style="margin:20pt;width:100pt;height:60pt;background:#fff;box-shadow:inset 0 0 10pt #000"></div>')->output();
if (($px = pixels($bytes, [[21, 50], [26, 50], [70, 50]])) !== null) {
    check('inset blur fades inwards', $px[0][0] < $px[1][0] && $px[1][0] < $px[2][0] && $px[0][0] > 120 && $px[0][0] < 220, implode(' ', array_map('fmt', $px)));
}

$shadowHtml = '<div style="margin:20pt;width:100pt;height:60pt;background:#fff;box-shadow:0 4pt 12pt rgba(0,0,0,.4), inset 0 0 12pt rgba(0,0,255,.8)"></div>';
$a1 = make($shadowHtml, ['pdfa' => '1b']);
$a1Bytes = $a1->output();
check('PDF/A-1b: box shadows need no opacity state (nothing to reset)', !str_contains(implode(' ', $a1->getWarnings()), 'opacity state'), json_encode($a1->getWarnings()));
if (($px = pixels($a1Bytes, [[70, 84], [21, 50]])) !== null) {
    check('PDF/A-1b: the outer shadow is a light grey, not a black ring', $px[0][0] > 150 && $px[0][0] < 250, fmt($px[0]));
    check('PDF/A-1b: the inset shadow is pre-blended (light blue at the edge)', $px[1][2] > 200 && $px[1][0] > 120 && $px[1][0] < 245, fmt($px[1]));
}

// ---------------------------------------------------------------------------
echo "\n--- box-shadow: outer blur profile (Edge) ---\n";
// reference pixels from Edge's print-to-PDF of the same markup (72 dpi): 136 / 221 / 255 at 1, 10 and 25 pt outside
$box = '<div style="margin:42pt;width:180pt;height:102pt;background:#fff;box-shadow:%s"></div>';
foreach (['' => [], ' (PDF/A-1b, pre-blended)' => ['pdfa' => '1b']] as $label => $opts) {
    $bytes = make(sprintf($box, '0 0 24px #000'), $opts)->output();
    if (($px = pixels($bytes, [[223, 92], [232, 92], [247, 92]])) !== null) {
        check('outer blur 24px follows the Gaussian Edge paints' . $label, near($px[0], [136, 136, 136], 14) && near($px[1], [221, 221, 221], 14) && near($px[2], [255, 255, 255], 4), implode(' ', array_map('fmt', $px)));
    }
}
$bytes = make('<div style="margin:60pt;width:21pt;height:21pt;background:#fff;box-shadow:0 0 24px #000"></div>')->output();
if (($px = pixels($bytes, [[82, 70]])) !== null) {
    // a box smaller than the blur never reaches full opacity (Edge: 168 next to the box, not the 136 of a big box)
    check('outer blur of a small box is lighter', $px[0][0] > 150 && $px[0][0] < 190, fmt($px[0]));
}
$bytes = make('<div style="margin:20pt;width:100pt;height:60pt;background:#fff;box-shadow:0 0 0 6pt red"></div><div style="margin:20pt;width:100pt;height:60pt;color:#00f;background:#fff;box-shadow:0 0 0 6pt"></div>')->output();
if (($px = pixels($bytes, [[123, 50], [123, 130]])) !== null) {
    check('outer shadow: named colour and currentColor', near($px[0], [255, 0, 0]) && near($px[1], [0, 0, 255]), implode(' ', array_map('fmt', $px)));
}
$bytes = make(sprintf($box, 'inset -8px 0 16px #00f'))->output();
if (($px = pixels($bytes, [[43, 92], [220, 92], [132, 43]])) !== null) {
    // Edge: weak (left) side clean, strong (right) side 95, top / bottom 189 - the top uses its own depth, not the right's
    check('inset offset shadow: each side at its own depth (Edge)', near($px[0], [255, 255, 255], 4) && near($px[1], [95, 95, 255], 16) && near($px[2], [189, 189, 255], 14), implode(' ', array_map('fmt', $px)));
}

// ---------------------------------------------------------------------------
echo "\n--- bookmark-level / bookmark-label ---\n";
$toc = static function (string $html): ?array {
    return py(make($html)->output(), 'print(json.dumps(d.get_toc()))');
};
$t = $toc('<style>h1{bookmark-label:"Custom " content()}h2{bookmark-level:none}</style><h1>H1</h1><h2>H2</h2><h3>H3</h3>');
if ($t !== null) {
    check('bookmark-level: none removes a heading; bookmark-label "string" content()', $t === [[1, 'Custom H1', 1], [2, 'H3', 1]], json_encode($t));
    $t = $toc('<style>.chap{bookmark-level:1;bookmark-label:attr(data-t)}.sec{bookmark-level:2}</style><div class="chap" data-t="Chapter A">x</div><p class="sec">Section text</p>');
    check('bookmark-level on non-heading elements, bookmark-label: attr()', $t === [[1, 'Chapter A', 1], [2, 'Section text', 1]], json_encode($t));
    $t = $toc('<style>body{counter-reset:c}h2{counter-increment:c;bookmark-label:counter(c) ". " content(text)}</style><h2>Intro</h2><h2>Next</h2>');
    check('bookmark-label with counter()', $t === [[1, '1. Intro', 1], [1, '2. Next', 1]], json_encode($t));
    $t = $toc('<style>h2::before{content:"Part "}h2{bookmark-label:content(before) content()}</style><h2>One</h2>');
    check('bookmark-label: content(before)', $t === [[1, 'Part One', 1]], json_encode($t));
} else {
    skip('bookmarks (PyMuPDF not available)');
}

// ---------------------------------------------------------------------------
echo "\n--- maxMemory without a unit ---\n";
$hasMemWarning = static fn(array $w): bool => count(array_filter($w, static fn(string $m): bool => str_contains($m, 'maxMemory'))) > 0;
$p = new IvoPdf(['maxMemory' => 64]);
$p->loadHtml('<p>x</p>')->output();
check('maxMemory => 64 (options array) is reported', $hasMemWarning($p->getWarnings()));
$p = new IvoPdf();
$p->maxMemory = 64;
$p->loadHtml('<p>x</p>')->output();
check('$pdf->maxMemory = 64 is reported', $hasMemWarning($p->getWarnings()));
$p = new IvoPdf();
$p->getOptions()->maxMemory = 64;
$p->loadHtml('<p>x</p>')->output();
check('Options::$maxMemory = 64 is reported', $hasMemWarning($p->getWarnings()));
$p = new \IvoPdf\Compat\Mpdf(['maxMemory' => 64]);
$p->WriteHTML('<p>x</p>');
$p->Output('', 'S');
check('mPDF config maxMemory => 64 is reported', $hasMemWarning($p->getWarnings()));
$p = new IvoPdf(['maxMemory' => '512M']);
$p->loadHtml('<p>x</p>')->output();
check('maxMemory with a unit is not reported', !$hasMemWarning($p->getWarnings()));
check('maxMemory keeps bytes semantics (64 MB as a number of bytes)', (new \IvoPdf\Options(['maxMemory' => 67108864]))->getMaxMemoryBytes() <= 67108864);

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices / deprecations', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
