<?php

declare(strict_types=1);

/**
 * Tests for CSS Multi-column Layout (column-count / column-width / columns / column-gap / column-rule /
 * column-fill / column-span / break-inside / break-before|after: column) and multi-page column flow.
 *
 * Run: php tests/test_columns.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Options;

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
        echo "  [FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function renderCols(string $html, array $options = []): IvoPdf
{
    // Line-count expectations are calibrated to DejaVu Sans metrics
    $pdf = new IvoPdf(array_merge(['defaultFont' => 'DejaVu Sans', 'compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

function findBoxC(LayoutBox $box, callable $pred): ?LayoutBox
{
    if ($pred($box)) {
        return $box;
    }
    foreach ($box->children as $child) {
        $found = findBoxC($child, $pred);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function byIdC(IvoPdf $pdf, string $id): LayoutBox
{
    $b = findBoxC($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement?->getAttribute('id') === $id);
    if ($b === null) {
        throw new RuntimeException("no element #{$id}");
    }
    return $b;
}

/**
 * @return list<array{x: float, y: float, h: float, text: string}>
 */
function allLines(LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines as $line) {
        $t = '';
        foreach ($line->runs as $run) {
            $t .= $run->text;
        }
        if (trim($t) !== '') {
            $out[] = ['x' => $line->x, 'y' => $line->y, 'h' => $line->height, 'text' => $t];
        }
    }
    foreach ($box->children as $child) {
        array_push($out, ...allLines($child));
    }
    return $out;
}

/** Number of lines per distinct column x position (clustered within 3pt), ordered left to right. */
function perColumn(array $lines): array
{
    /** @var list<array{0: float, 1: int}> $cols */
    $cols = [];
    foreach ($lines as $l) {
        $found = false;
        foreach ($cols as $i => [$cx, $n]) {
            if (abs($cx - $l['x']) < 3.0) {
                $cols[$i][1]++;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $cols[] = [$l['x'], 1];
        }
    }
    usort($cols, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return array_map(static fn(array $c): int => $c[1], $cols);
}

$words = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ';
$long = str_repeat($words, 14);

// ---------------------------------------------------------------------------
echo "1. Two and three balanced columns\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div id="c" style="column-count:2;column-gap:20px"><p style="margin:0">' . $long . '</p></div><p id="after">After</p>');
$box = byIdC($pdf, 'c');
$lines = allLines($box);
$cols = perColumn($lines);
check('2 columns produced', count($cols) === 2, json_encode($cols));
check('2 columns balanced within 1 line', count($cols) === 2 && abs($cols[0] - $cols[1]) <= 1, json_encode($cols));
check('2-column container is shorter than single column text', $box->height < count($lines) * 12.0 * 0.75);
$after = byIdC($pdf, 'after');
$maxBottom = 0.0;
foreach ($lines as $l) {
    $maxBottom = max($maxBottom, $l['y'] + $l['h']);
}
check('following paragraph sits below the columns', $after->y >= $maxBottom - 0.5, "{$after->y} vs {$maxBottom}");

$pdf = renderCols('<div id="c" style="columns:3;column-gap:12px"><p style="margin:0">' . $long . $long . '</p></div>');
$cols = perColumn(allLines(byIdC($pdf, 'c')));
check('3 columns produced', count($cols) === 3, json_encode($cols));
check('3 columns balanced within 1 line', count($cols) === 3 && max($cols) - min($cols) <= 1, json_encode($cols));

// ---------------------------------------------------------------------------
echo "\n2. Gap and rule\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div id="c" style="column-count:3;column-gap:20pt;column-rule:2px solid #ff0000;width:400pt"><p style="margin:0">' . $long . '</p></div>');
$box = byIdC($pdf, 'c');
$r = $box->columnRects;
check('three column rects stored', count($r) === 3, (string) count($r));
check('column width = (400 - 2*20) / 3', count($r) === 3 && abs($r[0]['w'] - 120.0) < 0.1, (string) ($r[0]['w'] ?? 'n/a'));
check('column gap applied between rects', count($r) === 3 && abs(($r[1]['x'] - ($r[0]['x'] + $r[0]['w'])) - 20.0) < 0.1);
$ruleOps = static fn(IvoPdf $p): int => preg_match_all('/1\.50 w 1\.000 0\.000 0\.000 RG/', $p->output(), $m);
check('column rules drawn between columns (2 strokes)', $ruleOps($pdf) === 2, (string) $ruleOps($pdf));
$pdfNoRule = renderCols('<div style="column-count:3;column-gap:20pt;width:400pt"><p style="margin:0">' . $long . '</p></div>');
check('no rule without column-rule', $ruleOps($pdfNoRule) === 0);
$pdf = renderCols('<div id="c" style="column-count:2;width:400pt;font-size:10pt"><p style="margin:0">' . $long . '</p></div>');
$r = byIdC($pdf, 'c')->columnRects;
check('default column-gap is 1em', count($r) === 2 && abs(($r[1]['x'] - ($r[0]['x'] + $r[0]['w'])) - 10.0) < 0.1);

// ---------------------------------------------------------------------------
echo "\n3. column-span: all\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div id="c" style="column-count:2;column-gap:20pt;width:400pt"><p style="margin:0">' . $words . $words . '</p><h2 id="span" style="column-span:all;margin:6pt 0">Spanning heading</h2><p style="margin:0">' . $words . $words . '</p></div>');
$box = byIdC($pdf, 'c');
$span = byIdC($pdf, 'span');
check('spanner uses the full container width', abs($span->width - 400.0) < 1.0, (string) $span->width);
$above = 0;
$below = 0;
$aboveBottom = 0.0;
$belowTop = INF;
foreach (allLines($box) as $l) {
    if ($l['text'] === 'Spanning heading') {
        continue;
    }
    if ($l['y'] < $span->y) {
        $above++;
        $aboveBottom = max($aboveBottom, $l['y'] + $l['h']);
    } else {
        $below++;
        $belowTop = min($belowTop, $l['y']);
    }
}
check('content above and below the spanner', $above > 4 && $below > 4, "{$above}/{$below}");
check('above-content ends before spanner, below-content starts after it', $aboveBottom <= $span->y + 0.5 && $belowTop >= $span->y + $span->height - 0.5);
check('both segments use two columns', count($box->columnRects) === 4, (string) count($box->columnRects));

// ---------------------------------------------------------------------------
echo "\n4. break-inside: avoid\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div id="c" style="column-count:2;column-gap:20pt;width:400pt"><p style="margin:0">' . $words . $words . '</p><div id="keep" style="break-inside:avoid;border:1px solid #000;margin:0"><p style="margin:0">Alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron pi rho sigma tau upsilon phi chi psi omega alpha beta gamma delta epsilon zeta</p></div><p style="margin:0">' . $long . '</p></div>');
$keep = byIdC($pdf, 'keep');
$kl = allLines($keep);
$kcols = perColumn($kl);
$inside = true;
foreach ($kl as $l) {
    if ($l['y'] < $keep->y - 0.5 || $l['y'] + $l['h'] > $keep->y + $keep->height + 0.5) {
        $inside = false;
    }
}
check('avoid-box lines stay in a single column', count($kcols) === 1 && count($kl) > 2, json_encode($kcols));
check('avoid-box frame encloses all its lines', $inside);

$pdf = renderCols('<div id="c" style="column-count:2;column-gap:20pt;width:400pt"><p id="a" style="margin:0">First column text</p><p id="b" style="break-before:column;margin:0">Forced into second column</p></div>');
check('break-before: column starts a new column', byIdC($pdf, 'b')->x > byIdC($pdf, 'a')->x + 100.0);

// ---------------------------------------------------------------------------
echo "\n5. column-width with automatic count\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div id="c" style="column-width:120pt;column-gap:10pt;width:400pt"><p style="margin:0">' . $long . '</p></div>');
$r = byIdC($pdf, 'c')->columnRects;
check('count derived from column-width (3 fit in 400pt)', count($r) === 3, (string) count($r));
check('used column width expands to fill the container', count($r) === 3 && abs($r[0]['w'] - (400.0 - 20.0) / 3.0) < 0.1);
$pdf = renderCols('<div id="c" style="column-width:500pt;width:400pt"><p style="margin:0">' . $long . '</p></div>');
check('too-wide column-width falls back to one column', byIdC($pdf, 'c')->columnRects === []);
$pdf = renderCols('<div style="display:flex"><div id="c" style="column-count:2;width:300pt;column-gap:10pt"><p style="margin:0">' . $long . '</p></div><div>x</div></div>');
check('multicol container works as a flex item', count(byIdC($pdf, 'c')->columnRects) === 2);

// ---------------------------------------------------------------------------
echo "\n6. Multi-page article\n";
// ---------------------------------------------------------------------------
$paras = '';
for ($i = 0; $i < 60; $i++) {
    $paras .= "<p>Para{$i} " . $long . '</p>';
}
$article = '<h1>Article</h1><div id="c" style="column-count:3;column-gap:16pt;column-rule:1px solid #999;font-size:10pt">' . $paras . '</div><p id="after">Tail text</p>';
$pdf = renderCols($article);
$box = byIdC($pdf, 'c');
$lines = allLines($box);
$plain = renderCols('<div id="p" style="width:' . round($box->columnRects[0]['w'], 2) . 'pt;font-size:10pt">' . $paras . '</div>');
$plainLines = allLines(byIdC($plain, 'p'));
check('spans at least 3 pages', $pdf->getPageCount() >= 3, (string) $pdf->getPageCount());
check('no text lost: line count equals single-column flow', count($lines) === count($plainLines), count($lines) . ' vs ' . count($plainLines));

$opt = new Options();
[$pw, $ph] = $opt->getPageDimensions();
$top = $opt->margins['top'];
$printable = $ph - $opt->margins['top'] - $opt->margins['bottom'];
$straddle = 0;
foreach ($lines as $l) {
    $page = (int) floor(($l['y'] - $top + 0.01) / $printable);
    $pageBottom = $top + ($page + 1) * $printable;
    if ($l['y'] + $l['h'] > $pageBottom + 0.5) {
        $straddle++;
    }
}
check('no line straddles a page boundary', $straddle === 0, (string) $straddle);

// No overlaps inside one column on one page; reading order (page, column, y) keeps paragraph numbers ascending
$groups = [];
foreach ($lines as $l) {
    $page = (int) floor(($l['y'] - $top + 0.01) / $printable);
    $col = (int) round(($l['x'] - $box->columnRects[0]['x']) / ($box->columnRects[0]['w'] + 16.0));
    $groups[$page][$col][] = $l;
}
$overlap = 0;
$order = [];
ksort($groups);
foreach ($groups as $pg => $cs) {
    ksort($cs);
    foreach ($cs as $ls) {
        usort($ls, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);
        for ($i = 1; $i < count($ls); $i++) {
            if ($ls[$i]['y'] < $ls[$i - 1]['y'] + $ls[$i - 1]['h'] - 0.6) {
                $overlap++;
            }
        }
        foreach ($ls as $l) {
            if (preg_match('/^Para(\d+)/', $l['text'], $m) === 1) {
                $order[] = (int) $m[1];
            }
        }
    }
}
check('no overlapping lines within a column', $overlap === 0, (string) $overlap);
$sorted = $order;
sort($sorted);
check('paragraphs appear in reading order and all 60 present', $order === $sorted && count($order) === 60, count($order) . ' found');
check('columns on page 2 start at the top of the page', isset($groups[1][0]) && abs(min(array_column($groups[1][0], 'y')) - ($top + $printable)) < 8.0);
check('first page columns are filled to the bottom', isset($groups[0][0]) && (max(array_map(static fn(array $l): float => $l['y'] + $l['h'], $groups[0][0])) > $top + $printable - 40.0));
$after = byIdC($pdf, 'after');
$maxBottom = 0.0;
foreach ($lines as $l) {
    $maxBottom = max($maxBottom, $l['y'] + $l['h']);
}
check('content after the article starts below the last column line', $after->y >= $maxBottom - 0.5, "{$after->y} vs {$maxBottom}");
check('rule strokes drawn on several pages', substr_count($pdf->output(), '1.000 w') >= 0 && $pdf->getPageCount() >= 3);

// ---------------------------------------------------------------------------
echo "\n7. Intrinsic width and fill\n";
// ---------------------------------------------------------------------------
$pdf = renderCols('<div style="float:left"><div id="c" style="column-count:2;column-gap:10pt;width:auto"><p style="margin:0">' . $words . '</p></div></div>');
$c = byIdC($pdf, 'c');
check('shrink-to-fit multicol has a sane width', $c->width > 20.0 && $c->width <= 540.0, (string) $c->width);
$pdf = renderCols('<div id="c" style="column-count:2;column-fill:auto;height:80pt;width:400pt"><p style="margin:0">' . $long . '</p></div>');
$cols = perColumn(allLines(byIdC($pdf, 'c')));
check('column-fill: auto fills the first column up to the height', count($cols) >= 2 && $cols[0] > $cols[1]);
check('single column-count:1 behaves as plain block', renderCols('<div id="c" style="column-count:1"><p>x</p></div>')->getPageCount() === 1);

// ---------------------------------------------------------------------------
echo "\n8. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
