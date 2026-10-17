<?php

declare(strict_types=1);

/**
 * Layout findings of the second independent audit (positions checked against Edge headless print to PDF):
 *  H1. a box that establishes a block formatting context (table, flex / grid container, overflow != visible)
 *      next to a float keeps its width (percentages of the containing block) and moves below the float when it
 *      does not fit beside it; auto-width boxes that fit stay beside the float
 *  M5. ::first-line { font-variant: small-caps | font-variant-caps: small-caps } synthesizes small capitals
 *  M6. a table taller than its rows (height, or stretched as a grid / flex item) distributes the extra height over
 *      its rows; a grid row that cannot keep its first piece on the page moves to the next page as a whole
 *  L2. orphans / widows with an out-of-range integer: no PHP warning
 *
 * Run: php tests/test_audit_fable2_layout.php
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

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<LayoutBox> */
function boxesWhere(LayoutBox $box, callable $pred): array
{
    $out = $pred($box) ? [$box] : [];
    foreach ($box->children as $c) {
        array_push($out, ...boxesWhere($c, $pred));
    }
    return $out;
}

function byId(IvoPdf $pdf, string $id): ?LayoutBox
{
    return boxesWhere($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => !$b->isTextNode() && $b->domElement?->getAttribute('id') === $id)[0] ?? null;
}

/** @return list<LayoutBox> */
function byTag(LayoutBox $root, string $tag): array
{
    return boxesWhere($root, static fn(LayoutBox $b): bool => !$b->isTextNode() && $b->tagName === $tag);
}

function fmt(float ...$v): string
{
    return implode(' / ', array_map(static fn(float $x): string => sprintf('%.2f', $x), $v));
}

const MM = 72.0 / 25.4;
$left = 20 * MM;
$avail = 595.28 - 40 * MM;
$head = '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4;margin:20mm} body{font-family:"DejaVu Sans";font-size:11pt;margin:0}'
    . ' .fl{float:left;width:150pt;height:100pt;background:#ddd;margin:0 8pt 8pt 0} td{border:1px solid #000}</style></head><body>';
$floatBottom = 20 * MM + 108.0; // float top + height + margin-bottom

// ---------------------------------------------------------------------------
echo "H1. BFC boxes next to a float\n";
// ---------------------------------------------------------------------------
foreach (['width:100%' => 1.0, 'width:80%' => 0.8] as $w => $f) {
    $pdf = render($head . '<div class="fl"></div><p>Para beside float.</p><table id="t" style="' . $w . ';border-collapse:collapse"><tr><td>A</td><td>B</td></tr></table><p>after</p>');
    $t = byId($pdf, 't');
    check("table {$w}: resolves against the containing block, not the band", $t !== null && abs($t->width - $f * $avail) < 0.5, $t ? fmt($t->width, $f * $avail) : 'missing');
    check("table {$w}: moved below the float, at the left edge (Edge: y 165)", $t !== null && $t->y >= $floatBottom - 0.5 && abs($t->x - $left) < 0.5, $t ? fmt($t->x, $t->y, $floatBottom) : 'missing');
}
$pdf = render($head . '<div class="fl"></div><table id="t" style="width:100%;border-collapse:collapse"><tr><td>A</td><td>B</td></tr></table>');
$t = byId($pdf, 't');
check('table directly after the float goes below it at full width', $t !== null && abs($t->y - $floatBottom) < 0.5 && abs($t->width - $avail) < 0.5, $t ? fmt($t->y, $t->width) : 'missing');

$pdf = render($head . '<div class="fl"></div><p>Para beside float.</p><div id="b" style="overflow:hidden;width:100%;border:1px solid #000">BFC width 100%</div>'
    . '<div class="fl"></div><div id="c" style="overflow:hidden;border:1px solid #00f">BFC auto width</div>');
$b = byId($pdf, 'b');
$c = byId($pdf, 'c');
check('overflow:hidden; width:100% (content-box + border) moves below the float', $b !== null && $b->y >= $floatBottom - 0.5 && abs($b->width - ($avail + 1.5)) < 0.5, $b ? fmt($b->y, $b->width) : 'missing');
check('overflow:hidden with auto width narrows beside the float', $c !== null && $c->x >= $left + 158.0 - 0.5 && abs($c->x + $c->width - ($left + $avail)) < 0.5, $c ? fmt($c->x, $c->width) : 'missing');

$pdf = render($head . '<div class="fl"></div><table id="t" style="border-collapse:collapse"><tr><td>auto</td><td>fits</td></tr></table>');
$t = byId($pdf, 't');
check('an auto-width table that fits stays beside the float', $t !== null && abs($t->x - ($left + 158.0)) < 0.5 && $t->y < $floatBottom - 50.0, $t ? fmt($t->x, $t->y) : 'missing');
$pdf = render($head . '<div class="fl"></div><table id="t" style="border-collapse:collapse"><tr><td>A long unbreakable_cell_content_that_is_wide_enough_1234567890</td><td>Another long unbreakable_cell_content_xxxxxxxxxxxxxxxx</td></tr></table>');
$t = byId($pdf, 't');
check('an auto-width table whose min-content does not fit moves below the float', $t !== null && $t->y >= $floatBottom - 0.5 && abs($t->x - $left) < 0.5, $t ? fmt($t->x, $t->y) : 'missing');
$pdf = render($head . '<div class="fl"></div><div id="f" style="display:flex"><div>flex a</div><div>flex b</div></div><div id="g" style="display:grid;grid-template-columns:1fr 1fr;width:100%"><div>grid a</div><div>grid b</div></div>');
$f = byId($pdf, 'f');
$g = byId($pdf, 'g');
check('auto-width flex container stays beside the float (Edge)', $f !== null && $f->y < $floatBottom - 50.0 && $f->x >= $left + 158.0 - 0.5, $f ? fmt($f->x, $f->y) : 'missing');
check('grid container with width:100% moves below the float (Edge)', $g !== null && $g->y >= $floatBottom - 0.5 && abs($g->width - $avail) < 0.5, $g ? fmt($g->y, $g->width) : 'missing');

$rows = '';
for ($i = 1; $i <= 69; $i++) {
    $rows .= "<tr><td>{$i}</td><td>Row {$i} Текстът на български език с дълги думи и препинателни знаци,</td><td>3</td></tr>";
}
$pdf = render($head . '<div class="fl" style="height:120pt"></div><p>' . str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 4) . '</p>'
    . '<table id="t" style="width:100%;border-collapse:collapse"><thead><tr><th>#</th><th>Text</th><th>N</th></tr></thead><tbody>' . $rows . '</tbody></table>');
$t = byId($pdf, 't');
check('invoice: the item table is laid out at full width below the logo (Edge: 2 pages)', $t !== null && abs($t->width - $avail) < 0.5 && $pdf->getPageCount() === 2, $t ? fmt($t->width) . ' pages=' . $pdf->getPageCount() : 'missing');

// ---------------------------------------------------------------------------
echo "\nM5. ::first-line small capitals\n";
// ---------------------------------------------------------------------------
foreach (['font-variant:small-caps', 'font-variant-caps:small-caps'] as $decl) {
    $pdf = render('<style>p::first-line{' . $decl . '} body{font-family:"DejaVu Sans";font-size:11pt}</style><p id="p">first line small caps. ' . str_repeat('more words here ', 20) . '</p>');
    $p = byId($pdf, 'p');
    $first = $p?->lines[0]->runs ?? [];
    $second = $p?->lines[1]->runs ?? [];
    $small = array_filter($first, static fn($r): bool => !$r->isWhitespace && $r->text === 'FIRST' && abs($r->fontSizePt - 11 * \IvoPdf\Layout\TextEffects::smallCapsFactor(11.0)) < 0.01);
    $plain = array_filter($second, static fn($r): bool => !$r->isWhitespace && preg_match('/\p{Ll}/u', $r->text) === 1 && abs($r->fontSizePt - 11.0) < 0.01);
    check("{$decl}: the first line has synthesized small capitals", $small !== [], json_encode(array_map(static fn($r): array => [$r->text, $r->fontSizePt], array_slice($first, 0, 4))));
    check("{$decl}: the next lines keep normal lowercase", $plain !== [] && count($plain) === count(array_filter($second, static fn($r): bool => !$r->isWhitespace)));
}

// ---------------------------------------------------------------------------
echo "\nM6. Table height distributed over the rows; grid rows across pages\n";
// ---------------------------------------------------------------------------
$t = '<style>body{font-family:"DejaVu Sans";font-size:10pt;margin:0} td{border:1px solid #000;vertical-align:top;padding:0} table{border-collapse:collapse}</style>';
$pdf = render($t . '<table id="t" style="height:200pt"><tr id="r1"><td>R1<br>2<br>3</td></tr><tr id="r2"><td>R2</td></tr><tr id="r3"><td>R3</td></tr></table>'
    . '<table id="s" style="height:200pt"><tr id="s1" style="height:50pt"><td>S1</td></tr><tr id="s2"><td>S2<br>x</td></tr><tr id="s3"><td>S3</td></tr></table>');
[$r1, $r2, $r3, $tb] = [byId($pdf, 'r1'), byId($pdf, 'r2'), byId($pdf, 'r3'), byId($pdf, 't')];
check('table height: 200pt is filled by the rows', $tb !== null && $r3 !== null && abs($tb->height - 200.0) < 0.5 && abs($r3->y + $r3->height - ($tb->y + $tb->height)) < 1.0, $tb && $r3 ? fmt($tb->height, $r3->y + $r3->height, $tb->y + $tb->height) : 'missing');
check('extra height in proportion to the row heights (Edge: 117.7 / 40.5)', $r1 !== null && $r2 !== null && abs($r1->height - 117.7) < 2.0 && abs($r2->height - 40.5) < 2.0, $r1 && $r2 ? fmt($r1->height, $r2->height) : 'missing');
[$s1, $s2] = [byId($pdf, 's1'), byId($pdf, 's2')];
check('a row with an explicit height keeps it, the auto rows take the rest (Edge: 50.2 / 98.3)', $s1 !== null && $s2 !== null && abs($s1->height - 50.0) < 1.0 && abs($s2->height - 98.3) < 2.0, $s1 && $s2 ? fmt($s1->height, $s2->height) : 'missing');
$cellsStretched = static function (?LayoutBox $table, float $h): bool {
    if ($table === null || abs($table->height - $h) > 1.0) {
        return false;
    }
    foreach (byTag($table, 'td') as $td) {
        if ($td->y + $td->height < $table->y + $table->height - 1.5) {
            return false; // the last row's cells must reach the bottom
        }
    }
    return true;
};
$pdf = render($t . '<div style="display:grid;grid-template-columns:1fr 1fr"><div style="height:80pt">G</div><table id="g" style="width:100%"><tr><td>cell</td><td>x</td></tr></table></div>'
    . '<div style="display:flex"><div style="height:80pt">F</div><table id="f" style="width:50%"><tr><td>flex cell</td></tr></table></div>'
    . '<div style="display:flex;flex-direction:column;height:100pt"><table id="c" style="flex:1"><tr><td>C</td></tr></table></div>');
check('grid item table is stretched to the row, its cells too', $cellsStretched(byId($pdf, 'g'), 80.0), fmt(byId($pdf, 'g')?->height ?? -1));
check('flex item table is stretched to the line, its cells too', $cellsStretched(byId($pdf, 'f'), 80.0), fmt(byId($pdf, 'f')?->height ?? -1));
check('flex: 1 table in a column flex container fills it', $cellsStretched(byId($pdf, 'c'), 100.0), fmt(byId($pdf, 'c')?->height ?? -1));

$grid = '';
for ($i = 1; $i <= 13; $i++) {
    $grid .= '<div class="g"><div>Left ' . $i . '<div class="f"><div>a</div><div>b Текстът на български език с дълги думи и препинателни знаци, който трябва да се </div></div></div>'
        . '<table style="width:100%"><tr><td>' . str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 4) . '</td><td>cell</td></tr></table></div>';
}
$pdf = render('<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4;margin:20mm} body{font-family:"DejaVu Sans";font-size:11pt;margin:0}'
    . ' .g{display:grid;grid-template-columns:1fr 2fr;gap:6pt} .f{display:flex;gap:4pt} .f>div{flex:1;border:1px solid #000;padding:3px} td{border:1px solid #000;padding:3px;vertical-align:top}</style></head><body>' . $grid . '</body></html>');
$split = [];
foreach (boxesWhere($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => !$b->isTextNode() && $b->getDisplay() === 'grid') as $gi => $g) {
    $kids = array_values(array_filter($g->children, static fn(LayoutBox $c): bool => !$c->isTextNode()));
    if (count($kids) === 2 && abs($kids[0]->y - $kids[1]->y) > 0.5) {
        $split[] = $gi + 1;
    }
}
check('grid rows move to the next page as a whole (no "Left N" sliver, Edge)', $split === [], 'rows split: ' . implode(',', $split));

// ---------------------------------------------------------------------------
echo "\nL2. Out-of-range orphans / widows\n";
// ---------------------------------------------------------------------------
$before = count($phpIssues);
$pdf = render('<p style="orphans:99999999999999999999;widows:99999999999999999999">' . str_repeat('word ', 1500) . '</p>');
check('orphans / widows 99999999999999999999: no PHP warning, still paginated', count($phpIssues) === $before && $pdf->getPageCount() >= 2, implode(' | ', array_slice($phpIssues, $before, 2)));

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
