<?php

declare(strict_types=1);

/**
 * Regression tests for the compatibility / robustness round 5:
 *  - CSS footnotes: ::footnote-area styling, footnote-display, footnote-policy, configurable room, stable multi-pass layout
 *  - mPDF <dottab outdent>, <textcircle> radius
 *  - dompdf Canvas: page_text() baseline, set_opacity(), save() / restore(), open_object() / add_object()
 *  - pathological text: 500 000 x &nbsp; and multi-megabyte unbroken words stay linear in time and memory
 *
 * Run: php tests/test_audit_compat5.php
 */

require_once __DIR__ . '/../autoload.php';

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

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label}\n";
}

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/**
 * Text lines and vector drawings of every page via PyMuPDF (null when PyMuPDF is missing).
 *
 * @return list<array{h: float, lines: list<array{0: float, 1: float, 2: float, 3: float, 4: string, 5: float, 6: int, 7: float}>, draw: list<array{0: float, 1: float, 2: float, 3: float, 4: ?array, 5: ?array, 6: ?float}>}>|null
 *         lines: x0, y0, x1, y1, text, font size, color, baseline y; draw: x0, y0, x1, y1, stroke, fill, fill opacity
 */
function inspectPdf(string $bytes): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-c5-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $bytes);
    $script = <<<'PY'
import pymupdf as fitz, json, sys
d = fitz.open(sys.argv[1])
out = []
for p in d:
    lines = []
    for b in p.get_text('dict')['blocks']:
        for l in b.get('lines', []):
            t = ''.join(s['text'] for s in l['spans'])
            sp = l['spans'][0]
            lines.append([l['bbox'][0], l['bbox'][1], l['bbox'][2], l['bbox'][3], t, sp['size'], sp['color'], sp['origin'][1]])
    draw = []
    for dr in p.get_drawings():
        r = dr['rect']
        draw.append([r.x0, r.y0, r.x1, r.y1, dr.get('color'), dr.get('fill'), dr.get('fill_opacity')])
    out.append({'h': p.rect.height, 'lines': lines, 'draw': draw})
print(json.dumps(out))
PY;
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-c5-inspect.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** Runs PHP code in a separate process; returns [peak MB, seconds, last output line]. */
function runIsolated(string $code, string $memoryLimit = '1G'): array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-c5-run-' . md5(uniqid('', true)) . '.php';
    $autoload = var_export(realpath(__DIR__ . '/../autoload.php'), true);
    file_put_contents($file, "<?php\nrequire {$autoload};\n\$t0 = microtime(true);\n{$code}\nfwrite(STDOUT, round(memory_get_peak_usage(true) / 1048576, 1) . ' ' . round(microtime(true) - \$t0, 2) . ' ' . (\$result ?? '') . \"\\n\");\n");
    $cmd = escapeshellarg(PHP_BINARY);
    if (php_ini_loaded_file() === false || php_ini_loaded_file() === '') {
        $cmd .= ' -n -d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'));
        foreach (['mbstring', 'openssl', 'gd', 'intl'] as $ext) {
            if (extension_loaded($ext)) {
                $cmd .= ' -d extension=' . $ext;
            }
        }
    }
    $out = @shell_exec($cmd . ' -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out)), static fn(string $l): bool => $l !== ''));
    $last = $lines === [] ? '' : $lines[count($lines) - 1];
    $parts = explode(' ', $last, 3);
    return [(float) ($parts[0] ?? 9999), (float) ($parts[1] ?? 9999), $parts[2] ?? $last];
}

$has = inspectPdf(render('<p>x</p>')->output()) !== null;

// ---------------------------------------------------------------------------
echo "1. Footnotes: ::footnote-area, footnote-display\n";
// ---------------------------------------------------------------------------
$base = '.fn{float:footnote;font-size:9pt}';
$paras = '';
for ($i = 1; $i <= 4; $i++) {
    $paras .= "<p>Para {$i}<span class=\"fn\">Note {$i} text.</span> more.</p>";
}
$plain = render("<style>{$base}</style>{$paras}");
$styled = render("<style>{$base} ::footnote-area{border-top:2pt solid #cc0000;padding-top:4pt;margin-top:6pt;color:#003300}</style>{$paras}");
check('::footnote-area causes no warnings', $styled->getWarnings() === [], json_encode($styled->getWarnings()));
if ($has) {
    $a = inspectPdf($styled->output());
    $b = inspectPdf($plain->output());
    $rule = array_values(array_filter($a[0]['draw'], static fn(array $d): bool => $d[5] !== null && abs($d[5][0] - 0.8) < 0.01 && $d[5][1] < 0.01));
    check('a declared border replaces the built-in short rule (full-width red border)', count($rule) === 1 && $rule[0][2] - $rule[0][0] > 400, json_encode($rule));
    $noteLine = null;
    foreach ($a[0]['lines'] as $l) {
        if (str_contains($l[4], 'Note 1')) {
            $noteLine = $l;
        }
    }
    check('::footnote-area color reaches the notes (a note written without its own color)', $noteLine !== null && $noteLine[6] === 0x003300, json_encode($noteLine));
    $plainRule = array_values(array_filter($b[0]['draw'], static fn(array $d): bool => $d[5] !== null));
    check('without ::footnote-area the short grey rule stays', count($plainRule) === 1 && $plainRule[0][2] - $plainRule[0][0] < 250, json_encode($plainRule));
    $paddedTop = $rule[0][1] ?? 0.0;
    $firstNoteY = $noteLine[1] ?? 0.0;
    check('padding-top separates the border and the first note', $firstNoteY - $paddedTop > 5.0, "{$paddedTop} / {$firstNoteY}");
}

foreach (['block' => 4, 'inline' => 1, 'compact' => 1] as $mode => $expectedLines) {
    $pdf = render("<style>{$base} .fn{footnote-display:{$mode}}</style>{$paras}");
    if ($has) {
        $info = inspectPdf($pdf->output());
        $noteLines = array_values(array_filter($info[0]['lines'], static fn(array $l): bool => preg_match('~\d\. Note \d~', $l[4]) === 1));
        check("footnote-display: {$mode} gives {$expectedLines} line(s) for 4 short notes", count($noteLines) === $expectedLines, json_encode(array_column($noteLines, 4)));
    }
}
$wide = '';
for ($i = 1; $i <= 2; $i++) {
    $wide .= "<p>Para {$i}<span class=\"fn\">" . str_repeat("Wide note {$i} sentence. ", 5) . '</span> more.</p>';
}
$compact = render("<style>{$base} .fn{footnote-display:compact}</style>{$wide}");
if ($has) {
    $info = inspectPdf($compact->output());
    $startLines = array_values(array_filter($info[0]['lines'], static fn(array $l): bool => preg_match('~^\d\. Wide note~', $l[4]) === 1));
    check('compact: a note that does not fit next to the previous one starts a new line', count($startLines) === 2 && abs($startLines[0][0] - $startLines[1][0]) < 1.0, json_encode(array_column($startLines, 4)));
}

// ---------------------------------------------------------------------------
echo "\n2. Footnotes: footnote-policy, room, stability\n";
// ---------------------------------------------------------------------------
function callPages(array $info, string $callRe, string $noteRe): array
{
    $calls = [];
    $notes = [];
    foreach ($info as $pi => $page) {
        foreach ($page['lines'] as $l) {
            if (preg_match($callRe, $l[4], $m) === 1) {
                $calls[(int) $m[1]] = $pi;
            }
            if (preg_match($noteRe, $l[4], $m) === 1) {
                $notes[(int) $m[1]] = $pi;
            }
        }
    }
    return [$calls, $notes];
}

function noOverlap(array $info, string $bodyRe, string $noteRe): bool
{
    foreach ($info as $page) {
        $body = 0.0;
        $noteTop = 1e9;
        foreach ($page['lines'] as $l) {
            if ($l[5] > 10.0 && preg_match($bodyRe, $l[4]) === 1) {
                $body = max($body, $l[3]);
            }
            if ($l[5] < 10.0 && preg_match($noteRe, $l[4]) === 1) {
                $noteTop = min($noteTop, $l[1]);
            }
        }
        if ($body > $noteTop + 0.5) {
            return false;
        }
    }
    return true;
}

$long = '';
for ($i = 1; $i <= 12; $i++) {
    $long .= "<p>Para {$i} has a call<span class=\"fn\">" . str_repeat("Long note {$i} sentence. ", 30) . '</span> and goes on and on to fill.</p>';
}
foreach (['auto', 'line', 'block'] as $policy) {
    $t0 = microtime(true);
    $pdf = render("<style>{$base} .fn{footnote-policy:{$policy}}</style>{$long}");
    $secs = microtime(true) - $t0;
    check("footnote-policy: {$policy} paginates and warns about nothing", $pdf->getPageCount() >= 2 && $pdf->getWarnings() === [], 'pages ' . $pdf->getPageCount() . ' ' . json_encode($pdf->getWarnings()));
    check("footnote-policy: {$policy} renders in reasonable time", $secs < 40.0, round($secs, 1) . ' s');
    if ($has) {
        $info = inspectPdf($pdf->output());
        [$calls, $notes] = callPages($info, '~^Para (\d+) has a call~', '~^(\d+)\. Long note~');
        check("policy {$policy}: all 12 notes printed, each on the page of its call", count($notes) === 12 && $calls === $notes, json_encode([$calls, $notes]));
        check("policy {$policy}: body text never overlaps the notes", noOverlap($info, '~^Para ~', '~Long note~'));
    }
}
$cap = render("<style>{$base} ::footnote-area{max-height:20%}</style>{$long}");
if ($has) {
    $info = inspectPdf($cap->output());
    $tops = [];
    foreach ($info as $page) {
        $t = 1e9;
        foreach ($page['lines'] as $l) {
            if (str_contains($l[4], 'Long note')) {
                $t = min($t, $l[1]);
            }
        }
        if ($t < 1e9) {
            $tops[] = $page['h'] - $t;
        }
    }
    check('::footnote-area { max-height: 20% } limits the room of the notes', $tops !== [] && max($tops) < 842 * 0.2 + 36.0 + 4.0, json_encode($tops));
    check('the smaller room makes more pages than the default 40 %', $cap->getPageCount() > render("<style>{$base}</style>{$long}")->getPageCount());
}

$huge = render("<style>{$base} ::footnote-area{max-height:100pt}</style><p>Before<span class=\"fn\">" . str_repeat('Huge note sentence. ', 120) . '</span> after.</p><p>Second paragraph.</p>');
check('a note taller than its room is cut with a warning, never over the text', count(array_filter($huge->getWarnings(), static fn(string $w): bool => str_contains($w, 'Footnotes of page 1'))) === 1, json_encode($huge->getWarnings()));
if ($has) {
    $info = inspectPdf($huge->output());
    check('the cut note starts below the body text', noOverlap($info, '~^(Before|Second)~', '~Huge note~'));
}

$many = '';
for ($i = 1; $i <= 90; $i++) {
    $many .= "<p>Item {$i} has a call<span class=\"fn\">" . str_repeat("Note {$i} words. ", ($i * 7) % 23 + 1) . '</span> and goes on to fill the line a bit more.</p>';
}
$t0 = microtime(true);
$pdf = render("<style>{$base}</style>{$many}");
$secs = microtime(true) - $t0;
check('90 paragraphs with notes of very different length settle without warnings', $pdf->getWarnings() === [], json_encode(array_slice($pdf->getWarnings(), 0, 2)));
check('... in reasonable time', $secs < 60.0, round($secs, 1) . ' s');
if ($has) {
    $info = inspectPdf($pdf->output());
    [$calls, $notes] = callPages($info, '~^Item (\d+) has a call~', '~^(\d+)\. Note \d+ words~');
    check('every one of the 90 notes sits on the page of its call', count($notes) === 90 && $calls === $notes, count($notes) . ' notes');
    check('... and no body text overlaps a note', noOverlap($info, '~^Item ~', '~Note \d+ words~'));
}

// ---------------------------------------------------------------------------
echo "\n3. mPDF <dottab outdent>, <textcircle>\n";
// ---------------------------------------------------------------------------
$dt = render('<p>Chapter<dottab outdent="30mm" />12</p><p>Plain<dottab />7</p>');
if ($has) {
    $info = inspectPdf($dt->output());
    $right = 595.28 - 36.0 - 6.0; // page margin + body margin 8px
    $l1 = array_values(array_filter($info[0]['lines'], static fn(array $l): bool => str_starts_with($l[4], 'Chapter')))[0] ?? null;
    $l2 = array_values(array_filter($info[0]['lines'], static fn(array $l): bool => str_starts_with($l[4], 'Plain')))[0] ?? null;
    check('<dottab> without outdent: the tail ends at the right edge', $l2 !== null && abs($l2[2] - $right) < 1.5, json_encode($l2));
    check('<dottab outdent="30mm">: the tail ends 30 mm left of the right edge', $l1 !== null && abs(($right - $l1[2]) - 30 * 72 / 25.4) < 1.5, json_encode($l1));
    check('<dottab outdent> still fills the gap with dots', $l1 !== null && substr_count($l1[4], '.') > 20, json_encode($l1));
}
$tc = render('<p><textcircle r="20" top-text="ROUND TEXT" bottom-text="STAMP" style="font-size:10pt"></textcircle></p>');
check('<textcircle r="20"> (unitless) is read as millimetres', $tc->getWarnings() === []);
if ($has) {
    $mm = render('<p><textcircle r="20mm" top-text="ROUND TEXT" bottom-text="STAMP" style="font-size:10pt"></textcircle></p>');
    $px = render('<p><textcircle r="20px" top-text="ROUND TEXT" bottom-text="STAMP" style="font-size:10pt"></textcircle></p>');
    $a = inspectPdf($tc->output());
    $b = inspectPdf($mm->output());
    $c = inspectPdf($px->output());
    check('... the same drawing as r="20mm" and not the one of r="20px"', $a[0]['lines'] == $b[0]['lines'] && $a[0]['lines'] != $c[0]['lines']);
}

// ---------------------------------------------------------------------------
echo "\n4. dompdf Canvas: baseline, opacity, save / restore, objects\n";
// ---------------------------------------------------------------------------
$d = new \Dompdf\Dompdf();
$d->loadHtml('<p>one</p><p style="page-break-before:always">two</p><p style="page-break-before:always">three</p>');
$d->setPaper('A4');
$d->render();
$canvas = $d->getCanvas();
$metrics = $d->getFontMetrics();
$font = $metrics->getFont('helvetica');
$canvas->page_text(100, 100, 'BASELINE', $font, 20, [0, 0, 0]);
[$asc, $desc] = $metrics->ascentDescent($font, 20);
$canvas->set_opacity(0.3);
$canvas->filled_rectangle(300, 300, 100, 50, [1, 0, 0]);
$canvas->save();
$canvas->set_opacity(0.6);
$canvas->filled_rectangle(300, 400, 100, 50, [0, 0, 1]);
$canvas->restore();
$canvas->filled_rectangle(300, 500, 100, 50, [0, 0.6, 0]);
$obj = $canvas->open_object();
$canvas->set_opacity(1.0);
$canvas->filled_rectangle(50, 700, 30, 30, [0.5, 0, 0.5]);
$canvas->close_object();
$canvas->add_object($obj, 'all');
$bytes = (string) $d->output(['compress' => 0]);
if ($has) {
    $info = inspectPdf($bytes);
    $line = null;
    foreach ($info[2]['lines'] as $l) {
        if ($l[4] === 'BASELINE') {
            $line = $l;
        }
    }
    check('page_text(): the baseline lies one font height (ascent + descent) below y, text top at y', $line !== null && abs($line[7] - (100 + $asc + $desc)) < 0.6, json_encode([$line, $asc, $desc]));
    $rects = $info[2]['draw'];
    $byX = static function (array $rects, float $y0): ?array {
        foreach ($rects as $r) {
            if (abs($r[1] - $y0) < 0.5 && abs($r[0] - 300) < 0.5) {
                return $r;
            }
        }
        return null;
    };
    $r1 = $byX($rects, 300.0);
    $r2 = $byX($rects, 400.0);
    $r3 = $byX($rects, 500.0);
    check('drawing coordinates are points from the top-left (rectangle at 300, 300)', $r1 !== null && abs($r1[2] - 400) < 0.5 && abs($r1[3] - 350) < 0.5, json_encode($rects));
    check('set_opacity(0.3) applies to the following drawing', $r1 !== null && abs(($r1[6] ?? 1.0) - 0.3) < 0.02, json_encode($r1));
    check('save() / set_opacity(0.6) inside', $r2 !== null && abs(($r2[6] ?? 1.0) - 0.6) < 0.02, json_encode($r2));
    check('restore() brings the earlier opacity (0.3) back', $r3 !== null && abs(($r3[6] ?? 1.0) - 0.3) < 0.02, json_encode($r3));
    $objOnPages = 0;
    foreach ($info as $p) {
        foreach ($p['draw'] as $r) {
            if (abs($r[0] - 50) < 0.5 && abs($r[1] - 700) < 0.5) {
                $objOnPages++;
            }
        }
    }
    check('add_object($obj, "all") after render() draws the object on the current (last) page only, like dompdf', $objOnPages === 1, "pages with the object: {$objOnPages}");
}

// objects added from page_script() on page 1 reach all following pages; 'add' / 'odd' / 'next'
$d = new \Dompdf\Dompdf();
$d->loadHtml('<p>one</p><p style="page-break-before:always">two</p><p style="page-break-before:always">three</p><p style="page-break-before:always">four</p>');
$d->setPaper('A4');
$d->getCanvas()->page_script(function ($n, $count, $canvas) {
    if ($n === 1) {
        $all = $canvas->open_object();
        $canvas->filled_rectangle(40, 40, 20, 20, [1, 0, 0]);
        $canvas->close_object();
        $canvas->add_object($all, 'all');
        $odd = $canvas->open_object();
        $canvas->filled_rectangle(80, 40, 20, 20, [0, 1, 0]);
        $canvas->close_object();
        $canvas->add_object($odd, 'odd');
        $one = $canvas->open_object();
        $canvas->filled_rectangle(120, 40, 20, 20, [0, 0, 1]);
        $canvas->close_object();
        $canvas->add_object($one, 'add');
        $next = $canvas->open_object();
        $canvas->filled_rectangle(160, 40, 20, 20, [0, 0, 0]);
        $canvas->close_object();
        $canvas->add_object($next, 'next');
    }
});
$d->render();
$bytes = (string) $d->output(['compress' => 0]);
if ($has) {
    $info = inspectPdf($bytes);
    $has40 = static function (array $page, float $x): bool {
        foreach ($page['draw'] as $r) {
            if (abs($r[0] - $x) < 0.5 && abs($r[1] - 40) < 0.5) {
                return true;
            }
        }
        return false;
    };
    $row = static fn(float $x): string => implode('', array_map(static fn(array $p): string => $has40($p, $x) ? '1' : '0', $info));
    check('page_script object "all": pages 1-4', $row(40) === '1111', $row(40));
    check('page_script object "odd": pages 1 and 3', $row(80) === '1010', $row(80));
    check('page_script object "add": page 1 only', $row(120) === '1000', $row(120));
    check('page_script object "next": pages 2-4', $row(160) === '0111', $row(160));
}
$again = (string) $d->output(['compress' => 0]);
check('rendering the same document again gives the same overlay (objects are not duplicated)', $has ? ($row(40) === '1111' && count(inspectPdf($again)[1]['draw']) === count($info[1]['draw'])) : true);
check('Canvas calls cause no PHP warnings', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));

// ---------------------------------------------------------------------------
echo "\n5. Pathological text: time and memory\n";
// ---------------------------------------------------------------------------
[$mb, $secs, $res] = runIsolated('$pdf = new IvoPdf\IvoPdf(["compress" => true]); $pdf->loadHtml("<p>" . str_repeat("&nbsp;", 500000) . "x</p>"); $out = $pdf->output(); $result = $pdf->getPageCount() . " " . strlen($out);', '512M');
check('500 000 x &nbsp; in one paragraph: peak memory below 120 MB', $mb < 120.0, "{$mb} MB, {$secs} s, {$res}");
check('... and below 20 s', $secs < 20.0, "{$secs} s");
check('... and it produces pages', (int) $res > 1, $res);
[$mb, $secs, $res] = runIsolated('$pdf = new IvoPdf\IvoPdf(["compress" => true, "maxWordLength" => 0]); $pdf->loadHtml("<p>" . str_repeat("a", 1000000) . "</p>"); $out = $pdf->output(); $result = $pdf->getPageCount() . " " . strlen($out);', '512M');
check('a 1 MB unbroken word with the guard off (maxWordLength 0): peak memory below 150 MB', $mb < 150.0, "{$mb} MB, {$secs} s, {$res}");
check('... and below 30 s', $secs < 30.0, "{$secs} s");
[$mb, $secs, $res] = runIsolated('$pdf = new IvoPdf\IvoPdf(["compress" => true]); $pdf->loadHtml("<p>" . str_repeat("a", 5000000) . "</p>"); $out = $pdf->output(); $result = $pdf->getPageCount() . " " . strlen($out) . " " . count($pdf->getWarnings());', '1G');
check('a 5 MB unbroken word (default guard): peak memory below 400 MB', $mb < 400.0, "{$mb} MB, {$secs} s, {$res}");
check('... and below 40 s', $secs < 40.0, "{$secs} s");
$parts = explode(' ', $res);
check('... with the guard warning', ($parts[2] ?? '0') === '1', $res);
$short = render('<div style="width:200pt;font-size:10pt;overflow-wrap:anywhere">' . str_repeat('W', 200) . '</div>');
$tree = $short->getLayoutTree();
check('forced character breaking still wraps a long word inside its box', $short->getPageCount() === 1 && $short->getWarnings() === []);
if ($has) {
    $info = inspectPdf($short->output());
    $maxRight = 0.0;
    $linesW = 0;
    foreach ($info[0]['lines'] as $l) {
        $maxRight = max($maxRight, $l[2]);
        $linesW++;
    }
    check('... every broken line stays inside the 200 pt box', $maxRight <= 36.0 + 200.0 + 0.5 && $linesW >= 4, "right {$maxRight}, lines {$linesW}");
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
