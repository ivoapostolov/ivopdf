<?php

declare(strict_types=1);

/**
 * dompdf drop-in compatibility: `Dompdf\Dompdf` / `Dompdf\Options` (lazy aliases, never override a real install),
 * Canvas::page_text() / page_script() / line() / rect(), FontMetrics, option mapping and the `<script type="text/php">` warning.
 *
 * Page text and drawings are read with PyMuPDF (python + fitz) when available; those checks are skipped otherwise.
 *
 * Run: php tests/test_compat_dompdf.php
 */

require_once __DIR__ . '/../autoload.php';

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

function skip(string $why): void
{
    echo "  [SKIP] {$why}\n";
}

/**
 * @return array{pages: list<array{w: float, h: float, text: string, lines: list<array{0: float, 1: float, 2: string}>, drawings: int}>}|null
 */
function inspectPdf(string $bytes): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dompdf-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $bytes);
    $script = <<<'PY'
import fitz,json,sys
d=fitz.open(sys.argv[1]); pages=[]
for p in d:
    lines=[]
    for b in p.get_text("dict")["blocks"]:
        for l in b.get("lines",[]):
            t="".join(s["text"] for s in l["spans"])
            if t.strip(): lines.append([round(l["bbox"][0],1),round(l["bbox"][1],1),t])
    pages.append({"w":p.rect.width,"h":p.rect.height,"text":p.get_text(),"lines":lines,"drawings":len(p.get_drawings())})
print(json.dumps({"pages":pages}))
PY;
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dompdf-inspect.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '{') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

use Dompdf\Dompdf;
use Dompdf\Options;

$three = '<h1>Title</h1><p style="page-break-after:always">One</p><p style="page-break-after:always">Two</p><p>Three</p>';

// ---------------------------------------------------------------------------
echo "1. Aliases\n";
// ---------------------------------------------------------------------------
check('Dompdf\\Dompdf resolves to the IvoPDF wrapper', (new Dompdf()) instanceof \IvoPdf\Compat\Dompdf\Dompdf);
check('Dompdf\\Options resolves to the IvoPDF options object', (new Options()) instanceof \IvoPdf\Compat\Dompdf\Options);
check('Dompdf\\Canvas / Dompdf\\FontMetrics type hints resolve', class_exists('Dompdf\\Canvas') && class_exists('Dompdf\\FontMetrics'));

// ---------------------------------------------------------------------------
echo "\n2. Options\n";
// ---------------------------------------------------------------------------
$o = new Options();
check('defaults follow dompdf (Letter, 96 dpi, remote off)', $o->getDefaultPaperSize() === 'letter' && $o->getDpi() === 96 && $o->getIsRemoteEnabled() === false && $o->isRemoteEnabled() === false);
$o->set('isRemoteEnabled', true);
check("set('isRemoteEnabled', true) / get / getIsRemoteEnabled()", $o->get('isRemoteEnabled') === true && $o->getIsRemoteEnabled() === true);
check('legacy snake_case keys work (enable_remote)', $o->set('enable_remote', false)->get('enable_remote') === false && $o->isRemoteEnabled() === false);
$o->setIsRemoteEnabled(true)->setDefaultFont('DejaVu Sans')->setDefaultPaperSize('a4')->setDpi(150)->setIsHtml5ParserEnabled(true);
$o->setChroot(sys_get_temp_dir())->setFontDir(sys_get_temp_dir())->setFontCache(sys_get_temp_dir())->setTempDir(sys_get_temp_dir());
check('setters / getters round-trip', $o->getDefaultFont() === 'DejaVu Sans' && $o->getDefaultPaperSize() === 'a4' && $o->getDpi() === 150 && $o->getIsHtml5ParserEnabled() === true && $o->isHtml5ParserEnabled());
check('setFontDir / setFontCache / setChroot are accepted', $o->getFontDir() === sys_get_temp_dir() && $o->getFontCache() === sys_get_temp_dir() && $o->getChroot() === sys_get_temp_dir());
$o2 = new Options(['isRemoteEnabled' => true, 'defaultFont' => 'Courier', 'chroot' => ['/a', '/b']]);
check('constructor array + chroot list', $o2->get('defaultFont') === 'Courier' && $o2->getChrootList() === ['/a', '/b']);
check('unknown option names are ignored without error', $o2->set('doesNotExist', 1) === $o2 && $o2->get('doesNotExist') === null);

// ---------------------------------------------------------------------------
echo "\n3. Dompdf: load / paper / render / output\n";
// ---------------------------------------------------------------------------
$d = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans']));
$d->loadHtml('<h1>Hello dompdf</h1><p>Body text</p>', 'UTF-8');
$d->setPaper('A4', 'portrait');
$d->render();
$pdf = (string) $d->output(['compress' => 0]);
check('output() returns a PDF', str_starts_with($pdf, '%PDF-'));
$compressed = (string) $d->output();
check("output(['compress' => 0]) leaves content streams uncompressed (larger than the default output)", str_contains($pdf, ' TJ') && strlen($pdf) > strlen($compressed));
check('default output is compressed', str_contains($compressed, '/FlateDecode'));
$info = inspectPdf($pdf);
if ($info !== null) {
    check('A4 paper, text present', abs($info['pages'][0]['w'] - 595.28) < 1 && str_contains($info['pages'][0]['text'], 'Hello dompdf'));
} else {
    skip('PyMuPDF not available');
}
$d2 = new Dompdf();
$d2->loadHtml('<p>x</p>');
$d2->render();
$info2 = inspectPdf((string) $d2->output());
if ($info2 !== null) {
    check('default paper is Letter like dompdf', abs($info2['pages'][0]['w'] - 612) < 1 && abs($info2['pages'][0]['h'] - 792) < 1);
}
$d2->setPaper([0, 0, 300, 400]);
$d2->render();
$info2 = inspectPdf((string) $d2->output());
if ($info2 !== null) {
    check('setPaper([x0, y0, x1, y1]) custom size', abs($info2['pages'][0]['w'] - 300) < 1 && abs($info2['pages'][0]['h'] - 400) < 1);
}
$d3 = new Dompdf();
$d3->loadHtml('<p>x</p>');
$d3->setPaper('A4', 'landscape');
$d3->render();
$info3 = inspectPdf((string) $d3->output());
if ($info3 !== null) {
    check('landscape orientation', $info3['pages'][0]['w'] > $info3['pages'][0]['h']);
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dompdf-file.html';
file_put_contents($tmp, '<p>From a file</p>');
$d4 = new Dompdf();
$d4->loadHtmlFile($tmp);
$d4->render();
$info4 = inspectPdf((string) $d4->output());
if ($info4 !== null) {
    check('loadHtmlFile()', str_contains($info4['pages'][0]['text'], 'From a file'));
}
$threw = false;
try {
    $d5 = new Dompdf(new Options(['chroot' => [__DIR__ . '/../examples']]));
    $d5->loadHtmlFile($tmp);
} catch (\Throwable) {
    $threw = true;
}
check('chroot is enforced for loadHtmlFile()', $threw);
@unlink($tmp);
$d6 = new Dompdf();
$d6->setOptions((new Options())->setIsRemoteEnabled(false));
check('setOptions() / getOptions()', $d6->getOptions()->isRemoteEnabled() === false);

// ---------------------------------------------------------------------------
echo "\n4. Canvas::page_text() ({PAGE_NUM} / {PAGE_COUNT})\n";
// ---------------------------------------------------------------------------
$d = new Dompdf();
$d->loadHtml($three);
$d->setPaper('A4');
$d->render();
$canvas = $d->getCanvas();
$metrics = $d->getFontMetrics();
$font = $metrics->getFont('helvetica', 'bold');
check('getFont() returns a font descriptor', is_string($font) && $font !== '');
check('get_page_count() / get_width() / get_height() after render', $canvas->get_page_count() === 3 && abs($canvas->get_width() - 595.28) < 1 && abs($canvas->get_height() - 841.89) < 1);
$canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 35, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 10, [0, 0, 0]);
$pdfBytes = (string) $d->output(['compress' => 0]);
$info = inspectPdf($pdfBytes);
if ($info !== null) {
    check('3 pages', count($info['pages']) === 3);
    $ok = true;
    foreach ($info['pages'] as $i => $p) {
        $found = false;
        foreach ($p['lines'] as [$x, $y, $t]) {
            if ($t === 'Page ' . ($i + 1) . ' of 3') {
                $found = $x > 480 && $x < 520 && $y > 795 && $y < 815;
            }
        }
        $ok = $ok && $found;
    }
    check('"Page N of 3" drawn on every page near (x, y) from the top-left', $ok, json_encode(array_map(static fn($p) => $p['lines'], $info['pages'])));
}
check('text width helper', $metrics->getTextWidth('Page 1 of 3', $font, 10) > 30 && $metrics->getTextWidth('Page 1 of 3', $font, 20) > $metrics->getTextWidth('Page 1 of 3', $font, 10));
check('registerFont() with a missing file returns false', $metrics->registerFont(['family' => 'Nope', 'style' => 'normal', 'weight' => 'normal'], '/nonexistent.ttf') === false);
check('registerFont() with a real font returns true', $metrics->registerFont(['family' => 'MyDejaVu', 'style' => 'normal', 'weight' => 'normal'], __DIR__ . '/../resources/fonts/DejaVuSans.ttf') === true);

// ---------------------------------------------------------------------------
echo "\n5. Canvas::page_script() with a callable, direct drawing\n";
// ---------------------------------------------------------------------------
$d = new Dompdf();
$d->loadHtml($three);
$d->setPaper('A4');
$calls = [];
$d->getCanvas()->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use (&$calls) {
    $calls[] = [$pageNumber, $pageCount, $canvas instanceof \Dompdf\Canvas, $fontMetrics instanceof \Dompdf\FontMetrics];
    $font = $fontMetrics->getFont('helvetica');
    $canvas->text(40, 30, "Script {$pageNumber}/{$pageCount}", $font, 9, [0, 0, 1]);
    if ($pageNumber > 1) {
        $canvas->line(40, 50, 300, 50, [1, 0, 0], 2);
    }
    $canvas->rect(40, 60, 100, 20, [0, 0.5, 0], 1);
    $canvas->filled_rectangle(150, 60, 20, 20, [0.9, 0.9, 0]);
});
$d->render();
$pdfBytes = (string) $d->output(['compress' => 0]);
$seen = array_unique(array_map(static fn($c) => $c[0] . '/' . $c[1], $calls));
check('callable is called for every page with (pageNumber, pageCount, canvas, fontMetrics)', in_array('1/3', $seen, true) && in_array('2/3', $seen, true) && in_array('3/3', $seen, true));
check('arguments have the dompdf types', $calls !== [] && $calls[0][2] === true && $calls[0][3] === true);
$info = inspectPdf($pdfBytes);
if ($info !== null) {
    check('script text drawn on each page', str_contains($info['pages'][0]['text'], 'Script 1/3') && str_contains($info['pages'][2]['text'], 'Script 3/3'));
    check('lines / rectangles are drawn as vector graphics', $info['pages'][1]['drawings'] >= 3 && $info['pages'][0]['drawings'] >= 2);
}
$d = new Dompdf();
$d->loadHtml('<p>x</p>');
$d->render();
$d->getCanvas()->line(10, 10, 200, 200, [0, 0, 0], 1);
$d->getCanvas()->text(50, 300, 'Direct text', $d->getFontMetrics()->getFont('helvetica'), 12);
$info = inspectPdf((string) $d->output(['compress' => 0]));
if ($info !== null) {
    check('drawing after render() re-renders on output()', str_contains($info['pages'][0]['text'], 'Direct text') && $info['pages'][0]['drawings'] >= 1);
}
$d = new Dompdf();
$d->loadHtml('<p>x</p>');
$d->getCanvas()->page_script('$pdf->text(1, 1, "evil", $font, 9);');
$d->render();
check('page_script() with a string of PHP code is refused with a warning', array_filter($d->getWarnings(), static fn($w) => str_contains($w, 'page_script')) !== []);

// ---------------------------------------------------------------------------
echo "\n6. <script type=\"text/php\"> is never executed\n";
// ---------------------------------------------------------------------------
$d = new Dompdf(new Options(['isPhpEnabled' => true]));
$d->loadHtml('<p>Body</p><script type="text/php">if (isset($pdf)) { $pdf->page_text(10, 10, "PHPRAN", null, 9); }</script><script type="text/php">/* second */</script>');
$d->render();
$warnings = array_values(array_filter($d->getWarnings(), static fn($w) => str_contains($w, 'text/php')));
check('a warning explains the alternatives (page_text / setFooterHtml)', count($warnings) === 1 && str_contains($warnings[0], 'page_text') && str_contains($warnings[0], 'setFooterHtml'), json_encode($d->getWarnings()));
$info = inspectPdf((string) $d->output());
if ($info !== null) {
    check('script code is neither executed nor printed', !str_contains($info['pages'][0]['text'], 'PHPRAN') && !str_contains($info['pages'][0]['text'], 'page_text'));
}
$plain = new \IvoPdf\IvoPdf();
$plain->loadHtml('<p>x</p><script type="text/php">echo 1;</script>')->output();
check('the warning also appears with the native IvoPdf API', count(array_filter($plain->getWarnings(), static fn($w) => str_contains($w, 'text/php'))) === 1);

// ---------------------------------------------------------------------------
echo "\n7. Options reach the engine\n";
// ---------------------------------------------------------------------------
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$allowed = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-chroot-ok';
$denied = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-chroot-denied';
@mkdir($allowed);
@mkdir($denied);
file_put_contents($denied . DIRECTORY_SEPARATOR . 'pixel.png', $png);
$d = new Dompdf(new Options(['chroot' => $allowed]));
$d->loadHtml('<img src="' . str_replace('\\', '/', $denied) . '/pixel.png"><p>x</p>');
$d->render();
check('chroot blocks images outside the allowed directory', $d->getWarnings() !== [], 'no warning');
$d = new Dompdf(new Options(['chroot' => $denied]));
$d->loadHtml('<img src="' . str_replace('\\', '/', $denied) . '/pixel.png"><p>x</p>');
$d->render();
check('images inside the chroot load', $d->getWarnings() === [], json_encode($d->getWarnings()));
@unlink($denied . DIRECTORY_SEPARATOR . 'pixel.png');
@rmdir($allowed);
@rmdir($denied);
$d = new Dompdf();
$d->setBasePath(__DIR__);
check('setBasePath() / getBasePath()', $d->getBasePath() === __DIR__);

// ---------------------------------------------------------------------------
echo "\n8. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
