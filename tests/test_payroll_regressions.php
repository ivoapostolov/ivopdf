<?php

declare(strict_types=1);

/**
 * Regressions found while generating protocols in a payroll system (absent in 00e912a, present in 223a1ec):
 *  1. a scaled <img> (height: 15mm; max-width: 50mm) counted with its natural width in the table column widths:
 *     the table grew wider than the page and the right cell was pushed off it
 *  2. `td { height }` smaller than the content was a fixed height: the next row overlapped the content
 *  3. `word-break: break-all` did not lower the min-content width: a long URL kept the table wider than the page
 * Positions are checked against Edge (headless print to PDF of the same HTML).
 *
 * Run: php tests/test_payroll_regressions.php
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

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<array{w: list<array{0: float, 1: float, 2: float, 3: float, 4: string}>}>|null words per page (x0, y0, x1, y1, text) via PyMuPDF */
function pdfWords(IvoPdf $pdf): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-payroll-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    $script = "import pymupdf as fitz,json,sys\nd=fitz.open(sys.argv[1])\nprint(json.dumps([{'w':[list(w[:5]) for w in p.get_text('words')]} for p in d]))\n";
    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-payroll-words.py';
    file_put_contents($scriptFile, $script);
    $json = @shell_exec('python ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** @return list<LayoutBox> element boxes matching $pred, in document order */
function boxesWhere(LayoutBox $box, callable $pred): array
{
    $out = $pred($box) ? [$box] : [];
    foreach ($box->children as $c) {
        array_push($out, ...boxesWhere($c, $pred));
    }
    return $out;
}

/** @return list<LayoutBox> */
function byTag(IvoPdf $pdf, string $tag): array
{
    return boxesWhere($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => !$b->isTextNode() && $b->tagName === $tag);
}

/** @return list<LayoutBox> */
function byClass(IvoPdf $pdf, string $class): array
{
    return boxesWhere($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => !$b->isTextNode()
        && in_array($class, preg_split('/\s+/', (string) $b->domElement?->getAttribute('class')) ?: [], true));
}

function byId(IvoPdf $pdf, string $id): ?LayoutBox
{
    return boxesWhere($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => !$b->isTextNode() && $b->domElement?->getAttribute('id') === $id)[0] ?? null;
}

/** Bottom edge of the line boxes inside $box (recursively), null when it has none. */
function linesBottom(LayoutBox $box): ?float
{
    $b = null;
    foreach ($box->lines as $line) {
        $b = max($b ?? -INF, $line->y + $line->height);
    }
    foreach ($box->children as $c) {
        $cb = linesBottom($c);
        if ($cb !== null) {
            $b = max($b ?? -INF, $cb);
        }
    }
    return $b;
}

/** @return list<array{0: float, 1: float}> [left, right] of each line box inside $box */
function lineExtents(LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines as $line) {
        $out[] = [$line->x, $line->x + $line->width];
    }
    return $out;
}

function fmt(float ...$v): string
{
    return implode(' / ', array_map(static fn(float $x): string => sprintf('%.2f', $x), $v));
}

const MM = 72.0 / 25.4;
const PX = 0.75;
$pageLeft = 20 * MM;
$pageRight = 595.28 - 20 * MM;
$avail = $pageRight - $pageLeft;

$css = '<style>@page { size: A4; margin: 22mm 20mm } body { margin: 0; font-family: "DejaVu Sans"; font-size: 12.5px; line-height: 1.45 }
.sign { width:100%; border-collapse:collapse; margin-top:46px }
.sign td { width:50%; vertical-align:bottom; padding:0; height:70px }
.sig { height:15mm; max-width:50mm; vertical-align:bottom }
.items { width:100%; border-collapse:collapse }
.items td, .items th { border:1px solid #000; padding:7px 8px; text-align:left; vertical-align:top }
.r { text-align:right !important; white-space:nowrap; width:105px }
.note { font-size:11px; word-break:break-all }
</style>';
$doc = static fn(string $body): string => "<!doctype html><html><head><meta charset=\"utf-8\">{$css}</head><body>{$body}</body></html>";

$png = null;
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(812, 208);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imageline($im, 10, 150, 800, 40, imagecolorallocate($im, 0, 0, 160));
    ob_start();
    imagepng($im);
    $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

$sign = static fn(string $src): string => '<table class="sign"><tr>
  <td>Автор:<br><img class="sig" src="' . $src . '"><br><strong>Иван Иванов</strong></td>
  <td style="text-align:right">Главен редактор:<br><img class="sig" src="' . $src . '"><br><strong>Петър Петров</strong></td>
</tr>';

// ---------------------------------------------------------------------------
echo "1. A scaled image counts with its used size in the table column widths\n";
// ---------------------------------------------------------------------------
if ($png === null) {
    skip('GD not available: no test image');
} else {
    $pdf = render($doc($sign($png) . '</table>'));
    $table = byClass($pdf, 'sign')[0] ?? null;
    $cells = byTag($pdf, 'td');
    $imgs = byTag($pdf, 'img');
    check('table is exactly the available width', $table !== null && abs($table->width - $avail) < 0.1, $table ? fmt($table->width, $avail) : 'missing');
    check('both columns are 50%', count($cells) === 2 && abs($cells[0]->width - $avail / 2) < 0.1 && abs($cells[1]->width - $avail / 2) < 0.1, count($cells) === 2 ? fmt($cells[0]->width, $cells[1]->width) : 'cells');
    check('images painted 50mm x 15mm (Edge: 141.8 x 42.8)', count($imgs) === 2 && abs($imgs[0]->width - 50 * MM) < 0.1 && abs($imgs[0]->height - 15 * MM) < 0.1, count($imgs) === 2 ? fmt($imgs[0]->width, $imgs[0]->height) : 'imgs');
    check('second image is right-aligned inside the page (Edge x = 397.5)', count($imgs) === 2 && abs($imgs[1]->x + $imgs[1]->width - $pageRight) < 0.1 && abs($imgs[1]->x - 397.5) < 1.0, count($imgs) === 2 ? fmt($imgs[1]->x) : 'imgs');
    $pages = pdfWords($pdf);
    if ($pages === null) {
        skip('PyMuPDF not available');
    } else {
        $right = array_values(array_filter($pages[0]['w'], static fn(array $w): bool => in_array($w[4], ['Главен', 'редактор:', 'Петър', 'Петров'], true)));
        $inside = $right !== [] && array_reduce($right, static fn(bool $ok, array $w): bool => $ok && $w[0] >= $pageLeft && $w[2] <= $pageRight + 0.5, true);
        check('right cell text is printed inside the page (Edge: "Петров" ends at 539.2)', count($right) === 4 && $inside, json_encode($right));
    }

    // The image's min-content / max-content contribution in other contexts (numbers from Edge)
    $probe = static fn(string $attrs, string $body = ''): string => $doc('<table id="t" style="border-collapse:collapse"><tr><td id="c" style="padding:0">'
        . '<img id="i" ' . $attrs . ' src="' . $png . '"></td><td style="padding:0">X</td></tr></table>' . $body);
    $cases = [
        'height + max-width' => ['style="height:15mm;max-width:50mm"', 50 * MM],
        'only height' => ['style="height:20px"', 20 * 812 / 208 * PX],
        'only width' => ['style="width:120px"', 120 * PX],
        'width attribute' => ['width="200"', 200 * PX],
        'height + min-width' => ['style="height:10px;min-width:100px"', 100 * PX],
        'max-width + min-height' => ['style="max-width:100px;min-height:40px"', 100 * PX],
    ];
    foreach ($cases as $label => [$attrs, $expect]) {
        $p = render($probe($attrs));
        $c = byId($p, 'c');
        $i = byId($p, 'i');
        check("img {$label}: column is the used width " . sprintf('%.1f', $expect), $c !== null && $i !== null && abs($c->width - $expect) < 0.2 && abs($i->width - $expect) < 0.2, $c && $i ? fmt($c->width, $i->width) : 'missing');
    }
    $p = render($probe('style="max-width:30%"'));
    [$t, $c, $i] = [byId($p, 't'), byId($p, 'c'), byId($p, 'i')];
    check('img max-width: 30%: max-content is the natural width, the table fills the page (Edge)', $t !== null && abs($t->width - $avail) < 0.5 && $i !== null && $c !== null && abs($i->width - 0.3 * $c->width) < 0.2, $t && $c && $i ? fmt($t->width, $c->width, $i->width) : 'missing');
    $p = render($doc('<table id="t" style="border-collapse:collapse;width:300px"><tr><td id="c" style="padding:0"><img style="width:50%" src="' . $png . '"></td><td style="padding:0;white-space:nowrap">'
        . str_repeat('N', 30) . '</td></tr></table>'));
    $c = byId($p, 'c');
    check('img width: 50% is compressible: min-content 0 (the nowrap column keeps its width)', $c !== null && $c->width < 60.0, $c ? fmt($c->width) : 'missing');
    $p = render($doc('<div><span id="ib" style="display:inline-block"><img class="sig" src="' . $png . '"></span></div>'
        . '<div style="display:flex"><div id="fi"><img style="height:20px" src="' . $png . '"></div><div>X</div></div>'
        . '<div id="fl" style="float:left"><img style="height:12px" src="' . $png . '"></div>'));
    $ib = byId($p, 'ib');
    $fi = byId($p, 'fi');
    $fl = byId($p, 'fl');
    check('inline-block around a scaled image is as wide as the image', $ib !== null && abs($ib->width - 50 * MM) < 0.2, $ib ? fmt($ib->width) : 'missing');
    check('flex item around a scaled image is as wide as the image', $fi !== null && abs($fi->width - 20 * 812 / 208 * PX) < 0.2, $fi ? fmt($fi->width) : 'missing');
    check('float around a scaled image is as wide as the image', $fl !== null && abs($fl->width - 12 * 812 / 208 * PX) < 0.2, $fl ? fmt($fl->width) : 'missing');
}

// ---------------------------------------------------------------------------
echo "\n2. A cell / row height smaller than the content is a minimum\n";
// ---------------------------------------------------------------------------
if ($png === null) {
    skip('GD not available: no test image');
} else {
    $pdf = render($doc($sign($png) . '<tr><td>Съгласувано с:<br><img class="sig" src="' . $png . '"><br><strong>Мария Маринова</strong></td><td></td></tr></table>'));
    $rows = byTag($pdf, 'tr');
    $cells = byTag($pdf, 'td');
    $strong = byTag($pdf, 'strong');
    if (count($rows) !== 2 || count($cells) !== 4) {
        check('table has two rows and four cells', false, count($rows) . ' / ' . count($cells));
    } else {
        $b0 = linesBottom($cells[0]) ?? 0.0;
        check('first row grows to its content (taller than 70px)', $rows[0]->height > 70 * PX + 1.0 && $b0 <= $rows[0]->y + $rows[0]->height + 0.1, fmt($rows[0]->height, $b0, $rows[0]->y + $rows[0]->height));
        check('second row starts below the first row content (no overlap)', $rows[1]->y >= $b0 - 0.1, fmt($rows[1]->y, $b0));
        check('cell boxes do not overlap', $cells[2]->y >= $cells[0]->y + $cells[0]->height - 0.5, fmt($cells[0]->y + $cells[0]->height, $cells[2]->y));
        check('vertical-align: bottom aligns to the real row height', abs($b0 - ($rows[0]->y + $rows[0]->height)) < 0.5, fmt($b0, $rows[0]->y + $rows[0]->height));
        $r1 = linesBottom($cells[1]) ?? 0.0;
        check('both cells of the first row end at the same baseline row bottom', abs($r1 - $b0) < 0.5, fmt($r1, $b0));
        $pages = pdfWords($pdf);
        if ($pages === null) {
            skip('PyMuPDF not available');
        } else {
            $y = [];
            foreach ($pages[0]['w'] as $w) {
                $y[$w[4]] = $w;
            }
            // Edge: "Иванов" 154.0-165.0, "Съгласувано" 167.5, "Маринова" 223.8
            check('row 2 text starts below the row 1 name (Edge: 165.0 -> 167.5)', isset($y['Иванов'], $y['Съгласувано']) && $y['Съгласувано'][1] >= $y['Иванов'][3] - 0.1 && abs($y['Съгласувано'][1] - 167.5) < 1.5, json_encode([$y['Иванов'] ?? null, $y['Съгласувано'] ?? null]));
            check('row 2 name at the Edge position (y 223.8)', isset($y['Маринова']) && abs($y['Маринова'][1] - 223.8) < 1.5, json_encode($y['Маринова'] ?? null));
        }
    }
}
$p = render('<table style="border-collapse:collapse"><tr id="tr" style="height:20px"><td id="td" style="height:10px;padding:0">a<br>b<br>c<br>d</td></tr><tr id="tr2"><td>n</td></tr></table>'
    . '<div id="blk" style="height:20px">a<br>b<br>c<br>d</div><div id="after">x</div>');
$tr = byId($p, 'tr');
$tr2 = byId($p, 'tr2');
$td = byId($p, 'td');
check('row and cell heights are minimums (four lines fit)', $tr !== null && $tr2 !== null && $td !== null && $tr->height > 40.0 && $tr2->y >= $tr->y + $tr->height - 0.1 && abs($td->height - $tr->height) < 0.5, $tr && $td ? fmt($tr->height, $td->height) : 'missing');
$blk = byId($p, 'blk');
$after = byId($p, 'after');
check('a block with an explicit height keeps it (content overflows)', $blk !== null && $after !== null && abs($blk->height - 15.0) < 0.1 && abs($after->y - ($blk->y + 15.0)) < 0.1, $blk && $after ? fmt($blk->height, $after->y - $blk->y) : 'missing');

// ---------------------------------------------------------------------------
echo "\n3. word-break: break-all lowers min-content to one character\n";
// ---------------------------------------------------------------------------
$url = 'https://example.com/a/b-c.html?fbclid=IwY2xjawUoTFtleHRuA2FlbQIxMQBzcnRjIwY2xjawUoTFtleHRuA2FlbQIxMQBzcnRjIwY2xjawUoTFtleHRuA2FlbQIxMQBzcnRjIwY2xjawUoTFtleHRuA2FlbQIxMQBzcnRj';
$pdf = render($doc('<table class="items"><tr><th>Наименование</th><th class="r">Брутно (€)</th><th class="r">Нетно (€)</th></tr>
<tr><td><strong>BYD</strong><div class="note">' . $url . '</div></td>
<td class="r">58,51</td><td class="r">55,00</td></tr></table>'));
$table = byClass($pdf, 'items')[0] ?? null;
$r = byClass($pdf, 'r');
$note = byClass($pdf, 'note')[0] ?? null;
$first = byTag($pdf, 'td')[0] ?? null;
check('table is exactly the available width', $table !== null && abs($table->width - $avail) < 0.1, $table ? fmt($table->width, $avail) : 'missing');
$rMin = (105 + 16) * PX; // width + padding (content-box)
check('.r columns keep at least 105px (+ padding), Edge: 91.6pt', count($r) === 4 && array_reduce($r, static fn(bool $ok, LayoutBox $b): bool => $ok && $b->width >= $rMin - 0.1 && $b->width < $rMin + 2.0, true), implode(' ', array_map(static fn(LayoutBox $b): string => fmt($b->width), $r)));
check('.r columns are inside the page', count($r) === 4 && max(array_map(static fn(LayoutBox $b): float => $b->x + $b->width, $r)) <= $pageRight + 0.6, count($r) === 4 ? fmt(max(array_map(static fn(LayoutBox $b): float => $b->x + $b->width, $r))) : '');
$lines = $note !== null ? lineExtents($note) : [];
check('the URL wraps (Edge: 3 lines)', count($lines) >= 3, (string) count($lines));
check('every URL line stays in the first column', $first !== null && $lines !== [] && max(array_column($lines, 1)) <= $first->x + $first->width - 6.0 + 0.1, $first && $lines !== [] ? fmt(max(array_column($lines, 1)), $first->x + $first->width) : 'missing');
check('first column takes the rest of the width (Edge: 299.2pt)', $first !== null && abs($first->width - 299.2) < 1.0, $first ? fmt($first->width) : 'missing');
$pages = pdfWords($pdf);
if ($pages === null) {
    skip('PyMuPDF not available');
} else {
    $amounts = array_values(array_filter($pages[0]['w'], static fn(array $w): bool => in_array($w[4], ['58,51', '55,00'], true)));
    // Edge: 58,51 at 414.2-441.0, 55,00 at 505.7-532.5
    check('amounts printed at the Edge positions', count($amounts) === 2 && abs($amounts[0][2] - 441.0) < 1.5 && abs($amounts[1][2] - 532.5) < 1.5, json_encode($amounts));
    $urlWords = array_values(array_filter($pages[0]['w'], static fn(array $w): bool => str_starts_with($w[4], 'https://') || preg_match('/^[A-Za-z0-9]{20,}$/', $w[4]) === 1));
    check('URL text lines end before the amount columns (Edge: line 1 ends at 346.5)', count($urlWords) >= 3 && max(array_column($urlWords, 2)) < 356.0 && abs($urlWords[0][2] - 346.5) < 3.0, json_encode(array_map(static fn(array $w): array => array_slice($w, 0, 4), $urlWords)));
}
// min-content per CSS Text 3: anywhere / break-all / break-word lower it, overflow-wrap: break-word does not
$word = str_repeat('a', 60);
foreach (['word-break:break-all' => true, 'word-break:break-word' => true, 'overflow-wrap:anywhere' => true, 'line-break:anywhere' => true, 'overflow-wrap:break-word' => false, 'normal' => false] as $st => $lowers) {
    $p = render('<div style="width:300px;overflow:hidden"><table style="border-collapse:collapse"><tr><td id="c" style="padding:0;' . $st . '">' . $word . '</td><td style="padding:0;width:80%">X</td></tr></table></div>');
    $c = byId($p, 'c');
    $ok = $c !== null && ($lowers ? $c->width < 50.0 : $c->width > 200.0);
    check("{$st}: min-content " . ($lowers ? 'is one character' : 'is the whole word'), $ok, $c ? fmt($c->width) : 'missing');
}

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
