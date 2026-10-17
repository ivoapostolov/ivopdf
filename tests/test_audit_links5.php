<?php

declare(strict_types=1);

/**
 * Regression tests for the link / annotation audit findings:
 *  - a line (link, form widget) belongs to exactly one page: no ghost annotations in the bottom margin of the
 *    previous page, no duplicate field names
 *  - internal links work for <span id>, <a id>, <a name>, empty anchors and <img id>; links around <img> / <svg> get a
 *    rectangle; a missing target is reported once as a warning
 *  - one /Link per line fragment instead of one per word and space
 *  - invalid certificates / keys throw a clear exception without a PHP warning
 *
 * Run: php tests/test_audit_links5.php   (PyMuPDF is used for the PDF-side checks and skipped when it is missing)
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

const IMG = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const MARGIN = 36.0; // default page margin in pt

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html);
    return $pdf;
}

/**
 * Per page: links (kind, uri / target page + y, rect), widgets (name, rect), words, height. Null without PyMuPDF.
 *
 * @return list<array{h: float, links: list<array<string, mixed>>, widgets: list<array<string, mixed>>, words: list<array<string, mixed>>}>|null
 */
function inspectPdf(IvoPdf $pdf): ?array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $file = $dir . 'ivopdf-links5-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = <<<'PY'
import pymupdf as fitz, json, sys
d = fitz.open(sys.argv[1])
out = []
for p in d:
    links = []
    for l in p.get_links():
        to = l.get("to")
        links.append({"kind": l["kind"], "uri": l.get("uri"), "page": l.get("page"), "toy": (to.y if to is not None else None),
                      "r": [l["from"].x0, l["from"].y0, l["from"].x1, l["from"].y1]})
    widgets = [{"name": w.field_name, "r": [w.rect.x0, w.rect.y0, w.rect.x1, w.rect.y1]} for w in (p.widgets() or [])]
    words = [{"t": w[4], "r": [w[0], w[1], w[2], w[3]]} for w in p.get_text("words")]
    out.append({"h": p.rect.height, "links": links, "widgets": widgets, "words": words})
print(json.dumps(out))
PY;
    $scriptFile = $dir . 'ivopdf-links5.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** @return array{0: int, 1: float}|null target page (1-based) and y of the first internal link on page 1 */
function firstTarget(array $pages): ?array
{
    foreach ($pages[0]['links'] as $l) {
        if ($l['kind'] === 1) {
            return [$l['page'] + 1, (float) $l['toy']];
        }
    }
    return null;
}

if (inspectPdf(render('<p>x</p>')) === null) {
    echo "PyMuPDF (python -c 'import pymupdf as fitz') is not available: PDF-side checks are skipped.\n";
}

// ---------------------------------------------------------------------------
echo "1. Ghost annotations: a line belongs to one page only\n";
// ---------------------------------------------------------------------------
$html = '';
for ($i = 1; $i <= 120; $i++) {
    $html .= '<p style="margin:0"><a href="https://e.test/' . $i . '">link' . $i . '</a></p>';
}
$pages = inspectPdf(render($html));
if ($pages === null) {
    skip('ghost links');
} else {
    $uris = [];
    $outside = 0;
    foreach ($pages as $pg) {
        foreach ($pg['links'] as $l) {
            $uris[] = $l['uri'];
            if ($l['r'][3] > $pg['h'] - MARGIN + 0.5 || $l['r'][1] < MARGIN - 0.5) {
                $outside++;
            }
        }
    }
    check('120 one-line links: every link exists exactly once', count($uris) === 120 && count(array_unique($uris)) === 120, count($uris) . ' annotations, ' . count(array_unique($uris)) . ' unique');
    check('no link rectangle in the top / bottom page margin', $outside === 0, "{$outside} outside");
}

$fields = '';
for ($i = 1; $i <= 120; $i++) {
    $fields .= '<div style="margin:0"><input type="text" name="n' . $i . '" value="v' . $i . '"></div>';
}
$pages = inspectPdf(render('<form>' . $fields . '</form>'));
if ($pages === null) {
    skip('form widgets');
} else {
    $names = [];
    $outside = 0;
    foreach ($pages as $pg) {
        foreach ($pg['widgets'] as $w) {
            $names[] = $w['name'];
            if ($w['r'][3] > $pg['h'] - MARGIN + 0.5 || $w['r'][1] < MARGIN - 0.5) {
                $outside++;
            }
        }
    }
    check('120 fields: 120 widgets, no duplicate field name', count($names) === 120 && count(array_unique($names)) === 120, count($names) . ' widgets, ' . count(array_unique($names)) . ' unique');
    check('no widget in the page margin', $outside === 0, "{$outside} outside");
}

$brk = '';
for ($i = 1; $i <= 6; $i++) {
    $brk .= '<div style="page-break-before:always"><a href="https://b.test/' . $i . '">brk' . $i . '</a></div>';
}
$pages = inspectPdf(render($brk));
if ($pages === null) {
    skip('page-break ghost links');
} else {
    $perPage = array_map(static fn(array $pg): int => count($pg['links']), $pages);
    check('links that start a page (page-break-before) are only on their own page', array_sum($perPage) === 6 && max($perPage) === 1, implode(',', $perPage));
}

$pages = inspectPdf(render('<style>.f{position:fixed;top:0;left:36pt}</style><div class="f"><a href="https://fixed.test/">fixed link</a></div>' . str_repeat('<div style="height:400pt">filler</div>', 3)));
if ($pages === null) {
    skip('fixed-position link');
} else {
    $with = array_filter($pages, static fn(array $pg): bool => count($pg['links']) === 1);
    check('a link in a position:fixed element is still repeated on every page', count($pages) > 1 && count($with) === count($pages), count($with) . ' of ' . count($pages));
}

// ---------------------------------------------------------------------------
echo "\n2. Internal link targets\n";
// ---------------------------------------------------------------------------
$cases = [
    'span id' => '<div style="height:900pt"></div><p>text <span id="t">T</span></p>',
    'a id' => '<div style="height:900pt"></div><a id="t">T</a>',
    'a name' => '<div style="height:900pt"></div><a name="t">T</a>',
    'empty a name' => '<div style="height:900pt"></div><a name="t"></a><p>after</p>',
    'empty a name in its own div' => '<div style="height:900pt"></div><div><a name="t"></a></div><p>after</p>',
    'empty span id between blocks' => '<div style="height:900pt"></div><span id="t"></span><p>after</p>',
    'img id after page-break' => '<div style="page-break-before:always"><img id="t" width="10" height="10" src="' . IMG . '"></div>',
    'svg id' => '<div style="page-break-before:always"><svg id="t" width="10" height="10"><rect width="10" height="10"/></svg></div>',
    'block id' => '<div style="page-break-before:always"><div id="t">T</div></div>',
    'span id in the 2nd line of a paragraph' => '<div style="height:900pt"></div><p style="width:60pt">alpha beta gamma <span id="t">delta</span></p>',
];
foreach ($cases as $label => $body) {
    $pages = inspectPdf(render('<p><a href="#t">go</a></p>' . $body));
    if ($pages === null) {
        skip("target: {$label}");
        continue;
    }
    $t = firstTarget($pages);
    check("target {$label}: link goes to page 2", $t !== null && $t[0] === 2, $t === null ? 'link dropped' : "page {$t[0]}");
}

$pages = inspectPdf(render('<p><a href="#t">go</a></p><div style="height:900pt"></div><p>x</p><p>text <span id="t">T</span></p>'));
if ($pages !== null) {
    $t = firstTarget($pages);
    $word = null;
    foreach ($pages[1]['words'] ?? [] as $w) {
        if ($w['t'] === 'T') {
            $word = $w;
        }
    }
    check('span target: destination y is the line of the span', $t !== null && $word !== null && abs($t[1] - $word['r'][1]) < 6.0, $t && $word ? "dest y {$t[1]}, word y {$word['r'][1]}" : 'missing');
}

// links whose content is an image / svg
$pages = inspectPdf(render('<a href="#t"><img width="30" height="20" src="' . IMG . '"></a><div style="height:900pt"></div><p id="t">T</p>'));
if ($pages === null) {
    skip('image link');
} else {
    $l = $pages[0]['links'][0] ?? null;
    check('<a href="#"><img></a>: link rectangle covers the image', $l !== null && abs(($l['r'][2] - $l['r'][0]) - 22.5) < 1.0 && abs(($l['r'][3] - $l['r'][1]) - 15) < 1.0 && $l['page'] === 1, $l ? json_encode($l['r']) : 'no link');
}
$pages = inspectPdf(render('<a href="https://svg.test/"><svg width="40" height="25"><rect width="40" height="25"/></svg></a>'));
if ($pages === null) {
    skip('svg link');
} else {
    $l = $pages[0]['links'][0] ?? null;
    check('<a href><svg></a>: link rectangle covers the svg', $l !== null && abs(($l['r'][2] - $l['r'][0]) - 30) < 1.0 && abs(($l['r'][3] - $l['r'][1]) - 18.75) < 1.0, $l ? json_encode($l['r']) : 'no link');
}
$pages = inspectPdf(render('<p><a href="https://m.test/">txt <img width="20" height="20" src="' . IMG . '"> more</a></p>'));
if ($pages === null) {
    skip('text + image link');
} else {
    check('text and image of one link share one rectangle', count($pages[0]['links']) === 1, (string) count($pages[0]['links']));
}
$pages = inspectPdf(render('<a href="https://blk.test/" style="display:inline-block"><img width="30" height="20" src="' . IMG . '"></a>'));
if ($pages !== null) {
    check('inline-block link around an image: no duplicate rectangle', count($pages[0]['links']) === 1, (string) count($pages[0]['links']));
}

// unresolved targets
$pdf = render('<p><a href="#nowhere">a</a> <a href="#nowhere">b</a> <a href="#top">top</a> <a href="#">up</a> <a href="#gone">c</a></p>');
$pages = inspectPdf($pdf);
$warn = array_values(array_filter($pdf->getWarnings(), static fn(string $w): bool => str_contains($w, 'Internal link')));
check('missing targets add one warning per target', count($warn) === 2 && str_contains(implode('|', $warn), '#nowhere') && str_contains(implode('|', $warn), '#gone'), implode(' | ', $warn));
if ($pages !== null) {
    check('"#" and "#top" link to the document top', count($pages[0]['links']) === 2, (string) count($pages[0]['links']));
}

// ---------------------------------------------------------------------------
echo "\n3. One annotation per link line fragment\n";
// ---------------------------------------------------------------------------
$pages = inspectPdf(render('<p><a href="https://one.test/">alpha beta gamma delta epsilon zeta</a></p>'));
if ($pages === null) {
    skip('merged link');
} else {
    check('a 6-word link on one line is a single annotation', count($pages[0]['links']) === 1, (string) count($pages[0]['links']));
}
$wrapped = render('<p style="width:150pt"><a href="https://multi.test/" style="text-decoration:none">' . str_repeat('multi line link ', 6) . '</a></p>');
$wrappedBytes = $wrapped->output();
$pages = inspectPdf($wrapped);
if ($pages === null) {
    skip('wrapped link');
} else {
    $lines = [];
    foreach ($pages[0]['words'] as $w) {
        $lines[(int) round($w['r'][1])] = true;
    }
    $n = count($pages[0]['links']);
    // One /Link per line of a wrapped link (as browsers write it); no union rectangle covering text between lines
    check('a wrapped link is one annotation per line', $n === count($lines) && $n >= 2 && !str_contains($wrappedBytes, '/QuadPoints'), "{$n} links for " . count($lines) . ' lines');
}
$pages = inspectPdf(render('<p><a href="https://a.test/">first</a> <a href="https://b.test/">second</a></p>'));
if ($pages !== null) {
    check('neighbouring links with different targets stay separate', count($pages[0]['links']) === 2, (string) count($pages[0]['links']));
}
$pages = inspectPdf(render('<p><a href="https://a.test/">one</a><span style="display:inline-block;width:80pt"></span><a href="https://a.test/">two</a></p>'));
if ($pages !== null) {
    check('links of the same target far apart on a line stay separate', count($pages[0]['links']) === 2, (string) count($pages[0]['links']));
}

// ---------------------------------------------------------------------------
echo "\n4. Invalid certificate / key: exception, no PHP warning\n";
// ---------------------------------------------------------------------------
if (!function_exists('openssl_x509_read')) {
    skip('openssl is not available');
} else {
    $before = count($phpIssues);
    foreach (
        [
            'garbage certificate' => ['not a certificate', 'not a key'],
            'PEM wrapper with garbage inside' => ["-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----", 'x'],
            'garbage PKCS#12 bundle' => ['not a bundle', ''],
        ] as $label => [$cert, $key]
    ) {
        $caught = null;
        try {
            $p = new IvoPdf();
            $p->loadHtml('<p>x</p>')->setSignature($cert, $key);
            $p->output();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        check("{$label}: InvalidArgumentException with a clear message", $caught instanceof \InvalidArgumentException && $caught->getMessage() !== '', $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'no exception');
    }
    check('no PHP warning leaked from the openssl calls', count($phpIssues) === $before, implode(' | ', array_slice($phpIssues, $before, 3)));
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
