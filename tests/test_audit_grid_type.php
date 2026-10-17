<?php

declare(strict_types=1);

/**
 * Regression tests for the grid / typography audit work:
 *  - CSS Grid: grid-template-areas, grid-area, grid-template / grid shorthands, named lines, implicit
 *    name-start / name-end lines, grid-auto-columns, grid-auto-flow (column, dense)
 *  - ::first-letter (drop caps), ::first-line, font-variant small-caps (synthesized and native)
 *  - text shaping performance (Inter GSUB/GPOS) and output equivalence
 *
 * Run: php tests/test_audit_grid_type.php
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../examples/Templates.php';

use IvoPdf\Css\GridShorthand;
use IvoPdf\Examples\Templates;
use IvoPdf\Font\FontManager;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\GridPlacement;
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

function render(string $html): IvoPdf
{
    $pdf = new IvoPdf(['compress' => false]);
    $pdf->loadHtml($html)->output();
    return $pdf;
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

/** @return array{0: float, 1: float, 2: float, 3: float} x, y, w, h */
function rect(IvoPdf $pdf, string $id): array
{
    $b = byId($pdf, $id);
    return $b === null ? [-1.0, -1.0, -1.0, -1.0] : [$b->x, $b->y, $b->width, $b->height];
}

function near(float $a, float $b, float $tol = 0.6): bool
{
    return abs($a - $b) <= $tol;
}

// ---------------------------------------------------------------------------
echo "1. Grid areas\n";
// ---------------------------------------------------------------------------
$parsed = GridPlacement::parseAreas('"h h h" "n m s" "f f f"');
check('grid-template-areas parses rectangles', $parsed !== null && $parsed['rows'] === 3 && $parsed['cols'] === 3
    && $parsed['areas']['h'] === [0, 0, 1, 3] && $parsed['areas']['m'] === [1, 1, 2, 2]);
check('areas: "." marks empty cells', GridPlacement::parseAreas('"a . b"')['areas']['b'] === [0, 2, 1, 3]);
check('areas: non-rectangular area is invalid', GridPlacement::parseAreas('"a b" "a a"') === null);
check('areas: unequal row lengths are invalid', GridPlacement::parseAreas('"a b" "a"') === null);
check('areas: none -> null', GridPlacement::parseAreas('none') === null);

$page = render('<style>
.g{display:grid;width:400pt;column-gap:0;row-gap:0;grid-template-areas:"header header header" "nav main aside" "footer footer footer";grid-template-columns:80pt 1fr 80pt;grid-template-rows:30pt 60pt 24pt}
.g>div{margin:0;padding:0}
</style>
<div class="g"><div id="h" style="grid-area:header">h</div><div id="n" style="grid-area:nav">n</div><div id="m" style="grid-area:main">m</div>
<div id="s" style="grid-area:aside">s</div><div id="f" style="grid-area:footer">f</div></div>');
[$hx, $hy, $hw, $hh] = rect($page, 'h');
[$nx, $ny, $nw, $nh] = rect($page, 'n');
[$mx, , $mw] = rect($page, 'm');
[$sx, , $sw] = rect($page, 's');
[, $fy, $fw, $fh] = rect($page, 'f');
check('grid-area: header spans all three columns', near($hw, 400.0) && near($hh, 30.0), json_encode([$hw, $hh]));
check('grid-area: nav is column 1, row 2', near($nw, 80.0) && near($ny, $hy + 30.0) && near($nh, 60.0), json_encode([$nw, $ny - $hy, $nh]));
check('grid-area: main takes the 1fr column', near($mx, $nx + 80.0) && near($mw, 240.0), json_encode([$mx - $nx, $mw]));
check('grid-area: aside is column 3', near($sx, $nx + 320.0) && near($sw, 80.0));
check('grid-area: footer is row 3 and full width', near($fy, $hy + 90.0) && near($fw, 400.0) && near($fh, 24.0));

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-areas:"a a" "b ." ;grid-template-columns:100pt 200pt;grid-template-rows:20pt 20pt}</style>
<div class="g"><div id="a" style="grid-area:a">a</div><div id="b" style="grid-area:b">b</div><div id="c">c</div></div>');
[$ax, $ay, $aw] = rect($page, 'a');
[$bx, $by, $bw] = rect($page, 'b');
[$cx, $cy] = rect($page, 'c');
check('"." cells stay empty; unplaced item auto-flows into them', near($bw, 100.0) && near($by, $ay + 20.0) && near($cx, $bx + 100.0) && near($cy, $by), json_encode([$bw, $by - $ay, $cx - $bx, $cy - $by]));

// ---------------------------------------------------------------------------
echo "\n2. grid-area / grid-row / grid-column forms\n";
// ---------------------------------------------------------------------------
$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-columns:repeat(3,100pt);grid-template-rows:repeat(3,20pt)}</style>
<div class="g"><div id="a" style="grid-area:2 / 2 / 4 / 4">a</div><div id="b" style="grid-row:1 / span 2;grid-column:3">b</div></div>');
[$ax, $ay, $aw, $ah] = rect($page, 'a');
[$bx, $by, $bw, $bh] = rect($page, 'b');
check('grid-area: r1 / c1 / r2 / c2', near($aw, 200.0) && near($ah, 40.0) && near($ax, $bx - 100.0) && near($ay, $by + 20.0), json_encode([$aw, $ah]));
check('grid-row: 1 / span 2 with grid-column: 3', near($bh, 40.0) && near($bw, 100.0));
$e = GridShorthand::expand('grid-area', 'main');
check('grid-area: single ident names all four edges', $e === ['grid-row-start' => 'main', 'grid-column-start' => 'main', 'grid-row-end' => 'main', 'grid-column-end' => 'main']);
$e = GridShorthand::expand('grid-area', '1 / 2');
check('grid-area: missing values default to auto', $e['grid-row-start'] === '1' && $e['grid-column-start'] === '2' && $e['grid-row-end'] === 'auto' && $e['grid-column-end'] === 'auto');

// ---------------------------------------------------------------------------
echo "\n3. Named lines\n";
// ---------------------------------------------------------------------------
$page = render('<style>.g{display:grid;width:400pt;gap:0;grid-template-columns:[full-start] 1fr [main-start] 2fr [main-end] 1fr [full-end]}</style>
<div class="g"><div id="x" style="grid-column:main-start / main-end">x</div><div id="y" style="grid-column:full-start / span 2">y</div>
<div id="z" style="grid-column:main-end / full-end">z</div><div id="w" style="grid-column:main / span 1">w</div></div>');
[$xx, $xy, $xw] = rect($page, 'x');
[$yx, $yy, $yw] = rect($page, 'y');
[$zx, $zy, $zw] = rect($page, 'z');
check('named lines: main-start / main-end', near($xw, 200.0) && near($xx, $yx + 100.0), json_encode([$xx - $yx, $xw]));
check('named lines: full-start / span 2', near($yw, 300.0) && $yy > $xy);
check('named lines: main-end / full-end', near($zw, 100.0) && near($zx, $yx + 300.0));
check('implicit lines: grid-column: main w/o area falls back to auto-placement', byId($page, 'w') !== null);

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-columns:100pt 100pt 100pt;grid-template-areas:". main main"}</style>
<div class="g"><div id="m" style="grid-column:main">m</div><div id="n" style="grid-column:main-start / span 1">n</div></div>');
[$mx, $my, $mw] = rect($page, 'm');
[$nx, $ny, $nw] = rect($page, 'n');
check('implicit area lines: grid-column: main spans the area', near($mw, 200.0) && near($nx, $mx), json_encode([$mx, $mw, $nx]));
check('implicit area lines: main-start / span 1', near($nw, 100.0), (string) $nw);

$l = GridPlacement::resolveAxis('header-start', 'header-end', GridPlacement::withAreaLines([], ['header' => [0, 0, 2, 3]], true), 3);
check('resolveAxis uses implicit name-start/name-end lines', $l === [0, 3], json_encode($l));
$l = GridPlacement::resolveAxis('-1', 'auto', [], 4);
check('resolveAxis: negative line -1 is the last line', $l[0] === 4, json_encode($l));
$l = GridPlacement::resolveAxis('span 3', 'auto', [], 4);
check('resolveAxis: span 3 is auto-positioned', $l === [null, 3], json_encode($l));
$l = GridPlacement::resolveAxis('a', 'span 2 b', ['a' => [1], 'b' => [2, 5]], 6);
check('resolveAxis: span <n> <name> counts named lines', $l === [1, 4], json_encode($l));

// ---------------------------------------------------------------------------
echo "\n4. grid-template / grid shorthands\n";
// ---------------------------------------------------------------------------
$e = GridShorthand::expand('grid-template', '"a a b" 30pt "c . b" 30pt / 1fr 1fr 1fr');
check('grid-template splits areas, rows and columns', $e['grid-template-areas'] === '"a a b" "c . b"' && $e['grid-template-rows'] === '30pt 30pt' && $e['grid-template-columns'] === '1fr 1fr 1fr', json_encode($e));
$e = GridShorthand::expand('grid-template', '[top] "a" 20pt [mid] "b" 1fr [bot] / [l] 1fr');
check('grid-template keeps line names around rows', $e['grid-template-rows'] === '[top] 20pt [mid] 1fr [bot]' && $e['grid-template-columns'] === '[l] 1fr', json_encode($e));
$e = GridShorthand::expand('grid-template', '100pt 1fr / auto 1fr');
check('grid-template: rows / columns without areas', $e['grid-template-rows'] === '100pt 1fr' && $e['grid-template-columns'] === 'auto 1fr' && $e['grid-template-areas'] === 'none');
$e = GridShorthand::expand('grid', 'auto-flow dense 40pt / 1fr 1fr');
check('grid: auto-flow dense <rows> / <columns>', $e['grid-auto-flow'] === 'row dense' && $e['grid-auto-rows'] === '40pt' && $e['grid-template-columns'] === '1fr 1fr');
$e = GridShorthand::expand('grid', '1fr 1fr / auto-flow 60pt');
check('grid: <rows> / auto-flow <auto-columns>', $e['grid-auto-flow'] === 'column' && $e['grid-auto-columns'] === '60pt' && $e['grid-template-rows'] === '1fr 1fr');

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template:"a a b" 30pt "c . b" 30pt / 100pt 100pt 100pt}</style>
<div class="g"><div id="a" style="grid-area:a">a</div><div id="b" style="grid-area:b">b</div><div id="c" style="grid-area:c">c</div></div>');
[$ax, $ay, $aw, $ah] = rect($page, 'a');
[$bx, $by, $bw, $bh] = rect($page, 'b');
[$cx, $cy, $cw] = rect($page, 'c');
check('grid-template shorthand end-to-end', near($aw, 200.0) && near($bh, 60.0) && near($bx, $ax + 200.0) && near($cy, $ay + 30.0) && near($cw, 100.0), json_encode([$aw, $bh, $cw]));

// ---------------------------------------------------------------------------
echo "\n5. Auto flow and implicit tracks\n";
// ---------------------------------------------------------------------------
$page = render('<style>.g{display:grid;width:400pt;gap:0;grid-auto-flow:column;grid-template-rows:20pt 20pt;grid-auto-columns:60pt}</style>
<div class="g"><div id="i1">1</div><div id="i2">2</div><div id="i3">3</div><div id="i4">4</div><div id="i5">5</div></div>');
[$x1, $y1, $w1] = rect($page, 'i1');
[$x2, $y2] = rect($page, 'i2');
[$x3, $y3] = rect($page, 'i3');
[$x5, $y5] = rect($page, 'i5');
check('grid-auto-flow: column fills rows first', near($y2, $y1 + 20.0) && near($x2, $x1) && near($x3, $x1 + 60.0) && near($y3, $y1) && near($x5, $x1 + 120.0) && near($y5, $y1), json_encode([$x2 - $x1, $y2 - $y1, $x3 - $x1, $x5 - $x1]));
check('grid-auto-columns sizes implicit columns', near($w1, 60.0), (string) $w1);

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-columns:100pt 100pt 100pt;grid-auto-flow:row dense}</style>
<div class="g"><div id="a" style="grid-column:span 2">a</div><div id="b" style="grid-column:span 2">b</div><div id="c">c</div><div id="d">d</div></div>');
[$ax, $ay] = rect($page, 'a');
[$bx, $by] = rect($page, 'b');
[$cx, $cy] = rect($page, 'c');
[$dx, $dy] = rect($page, 'd');
check('dense packing back-fills holes', near($cy, $ay) && near($cx, $ax + 200.0) && $by > $ay && near($dy, $by) && near($dx, $cx), json_encode([$cy - $ay, $by - $ay, $dy - $ay]));

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-columns:100pt 100pt 100pt}</style>
<div class="g"><div id="a" style="grid-column:span 2">a</div><div id="b" style="grid-column:span 2">b</div><div id="c">c</div></div>');
[$ax, $ay] = rect($page, 'a');
[$bx, $by] = rect($page, 'b');
[$cx, $cy] = rect($page, 'c');
check('sparse flow leaves the hole (item c follows b)', $by > $ay && near($cy, $by) && near($cx, $bx + 200.0), json_encode([$by - $ay, $cy - $by]));

$page = render('<style>.g{display:grid;width:300pt;gap:0;grid-template-columns:100pt 100pt}</style>
<div class="g"><div id="a" style="grid-column:4">a</div></div>');
[$ax, , $aw] = rect($page, 'a');
$g0 = $page->getLayoutTree();
check('explicit column beyond the template creates implicit columns without warnings', $aw > 0.0 && $ax >= 0.0);

// ---------------------------------------------------------------------------
echo "\n6. ::first-letter\n";
// ---------------------------------------------------------------------------
$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip.';
$page = render('<style>p{width:300pt;font-size:11pt;line-height:1.4}.d::first-letter{float:left;font-size:30pt;line-height:1;color:#c00;padding-right:4pt}
.q::first-letter{font-size:200%;color:#080}</style><p id="d" class="d">' . $lorem . '</p><p id="q" class="q">"Quoted" start ' . $lorem . '</p>');
$d = byId($page, 'd');
$float = $d !== null ? findBox($d, static fn(LayoutBox $b): bool => $b->tagName === 'span' && $b->style->get('float', 'none') === 'left') : null;
check('::first-letter float:left creates a floated span', $float !== null && ($float->children[0]->textContent ?? '') === 'L');
$rest = $d !== null ? findBox($d, static fn(LayoutBox $b): bool => $b->isTextNode() && str_starts_with((string) $b->textContent, 'orem')) : null;
check('first letter is removed from the remaining text', $rest !== null);
$lineXs = [];
if ($d !== null) {
    foreach ($d->lines as $ln) {
        $lineXs[] = round($ln->runs[0]->x ?? 0.0, 1);
    }
}
check('text wraps around the drop cap and returns to the left edge', count($lineXs) >= 4 && $lineXs[0] > $d->x + 20.0 && end($lineXs) < $d->x + 1.0, json_encode($lineXs));
check('drop cap has the larger font size', $float !== null && near($float->style->getFontSizePt(), 30.0, 0.1));
$q = byId($page, 'q');
$qs = $q !== null ? findBox($q, static fn(LayoutBox $b): bool => $b->tagName === 'span' && str_contains((string) ($b->children[0]->textContent ?? ''), 'Q')) : null;
check('leading punctuation belongs to ::first-letter ("Q)', $qs !== null && ($qs->children[0]->textContent ?? '') === '"Q', json_encode($qs?->children[0]->textContent));
check('inline ::first-letter uses its colour and size', $qs !== null && strtolower($qs->style->get('color')) === '#080' && near($qs->style->getFontSizePt(), 22.0, 0.1));

$page = render('<style>.i::first-letter{initial-letter:3;color:#808}p{width:300pt;font-size:11pt}</style><p id="i" class="i">' . $lorem . '</p>');
$i = byId($page, 'i');
$fl = $i !== null ? findBox($i, static fn(LayoutBox $b): bool => $b->tagName === 'span' && $b->style->get('float', 'none') === 'left') : null;
check('initial-letter: N produces a left float spanning about N lines', $fl !== null && $fl->height > 2.0 * 11.0 * 1.4 && $fl->height < 4.0 * 11.0 * 1.4 + 6.0, (string) $fl?->height);

// ---------------------------------------------------------------------------
echo "\n7. ::first-line\n";
// ---------------------------------------------------------------------------
$page = render('<style>p{width:300pt;font-size:11pt;line-height:1.4}.f::first-line{font-weight:bold;color:#0050c0;text-transform:uppercase;font-size:14pt}</style>
<p id="f" class="f">' . $lorem . '</p>');
$f = byId($page, 'f');
$lines = $f?->lines ?? [];
$l0 = $lines[0]->runs ?? [];
$l1 = $lines[1]->runs ?? [];
$allStyled = $l0 !== [];
foreach ($l0 as $r) {
    if (!$r->isWhitespace && (strtolower($r->style->get('color')) !== '#0050c0' || $r->style->get('font-weight') !== 'bold' || !near($r->fontSizePt, 14.0, 0.1))) {
        $allStyled = false;
    }
}
check('::first-line styles every run of the first line', $allStyled);
$t0 = '';
foreach ($l0 as $r) {
    $t0 .= $r->text;
}
check('::first-line text-transform applies to the first line only', $t0 === mb_strtoupper($t0) && $l1 !== [] && $l1[0]->text !== mb_strtoupper($l1[0]->text), $t0);
check('::first-line leaves later lines unstyled', $l1 !== [] && near($l1[0]->fontSizePt, 11.0, 0.1) && strtolower($l1[0]->style->get('color')) !== '#0050c0');
$plain = render('<style>p{width:300pt;font-size:11pt;line-height:1.4}</style><p id="f">' . $lorem . '</p>');
$plainFirst = '';
foreach (byId($plain, 'f')->lines[0]->runs as $r) {
    $plainFirst .= $r->text;
}
check('larger ::first-line font re-breaks the first line (fewer words fit)', mb_strlen(trim($t0)) < mb_strlen(trim($plainFirst)), mb_strlen(trim($t0)) . ' vs ' . mb_strlen(trim($plainFirst)));

// ---------------------------------------------------------------------------
echo "\n8. font-variant: small-caps\n";
// ---------------------------------------------------------------------------
foreach (['DejaVu Sans', 'Inter'] as $fam) {
    $page = render('<p id="p" style="font-family:\'' . $fam . '\';font-size:20pt"><span id="s" style="font-variant:small-caps">Small Caps Text</span></p>');
    $runs = [];
    foreach (byId($page, 'p')->lines as $ln) {
        foreach ($ln->runs as $r) {
            if (!$r->isWhitespace) {
                $runs[] = $r;
            }
        }
    }
    $texts = array_map(static fn($r) => $r->text, $runs);
    $caps = array_values(array_filter($runs, static fn($r) => near($r->fontSizePt, 20.0, 0.01)));
    $small = array_values(array_filter($runs, static fn($r) => near($r->fontSizePt, 14.25, 0.01)));
    // the space before a capital is drawn at its size and joins its run (" C"): compare the letters only
    $capText = str_replace(' ', '', implode('', array_map(static fn($r) => $r->text, $caps)));
    $smallText = str_replace(' ', '', implode('', array_map(static fn($r) => $r->text, $small)));
    check("[$fam] real capitals stay full size", $capText === 'SCT', $capText);
    check("[$fam] lowercase letters become capitals at 0.7x size", $smallText === 'MALLAPSEXT', $smallText . ' / ' . implode('|', $texts));
}
$page = render('<p id="p" style="font-size:20pt"><span style="font-variant-caps:all-small-caps">All Small</span></p>');
$small = 0;
$full = 0;
foreach (byId($page, 'p')->lines[0]->runs as $r) {
    if (!$r->isWhitespace) {
        near($r->fontSizePt, 14.25, 0.01) ? $small++ : $full++;
    }
}
check('font-variant-caps: all-small-caps shrinks capitals too', $small === 2 && $full === 0, "$small small, $full full");
$page = render('<p id="p" style="font-size:20pt;font-variant:small-caps">Hyphen-ated words</p>');
check('small-caps keeps the word glued together (no break inside)', count(byId($page, 'p')->lines) === 1);
$normal = render('<p id="p" style="font-size:20pt">Small Caps Text</p>');
$sc = render('<p id="p" style="font-size:20pt;font-variant:small-caps">Small Caps Text</p>');
check('small-caps text is narrower than the same text in normal caps-and-lowercase? (synthesized sizes differ)', byId($normal, 'p')->lines[0]->width !== byId($sc, 'p')->lines[0]->width);
$pdfOut = new IvoPdf(['compress' => false]);
$bytes = $pdfOut->loadHtml('<p style="font-variant:small-caps">Small Caps Text</p>')->output();
check('small-caps output is a valid PDF', str_starts_with((string) $bytes, '%PDF-'));

// ---------------------------------------------------------------------------
echo "\n9. Text shaping performance and equivalence\n";
// ---------------------------------------------------------------------------
$fm = new FontManager(dirname(__DIR__) . '/resources/fonts');
$inter = $fm->resolveFont('Inter', 400, 'normal');
$av = $fm->shapeWith($inter, 'AVATAR To', []);
$noKern = $fm->shapeWith($inter, 'AVATAR To', ['kern' => 0]);
check('Inter pair kerning (GPOS) still applies after the lookup pre-filter', $av['units'] < $noKern['units'] - 1.0, "{$av['units']} vs {$noKern['units']}");
$arrow = $fm->shapeWith($inter, '->', []);
$plainArrow = $fm->shapeWith($inter, '->', ['calt' => 0, 'ccmp' => 0, 'liga' => 0]);
check('Inter contextual alternates (GSUB calt) still apply', $arrow['glyphs'] !== null || abs($arrow['units'] - $plainArrow['units']) > 0.01 || $arrow !== $plainArrow);
$digits = $fm->shapeWith($inter, '1234', ['tnum' => 1]);
$digitsPlain = $fm->shapeWith($inter, '1234', []);
check('Inter tabular figures (GSUB tnum) still apply', abs($digits['units'] - $digitsPlain['units']) > 0.01 || $digits['glyphs'] !== $digitsPlain['glyphs']);

$tpl = Templates::all('en')['invoice'];
$timeIt = static function (string $html): float {
    $best = INF;
    for ($i = 0; $i < 2; $i++) {
        $t = microtime(true);
        $pdf = new IvoPdf(['paperSize' => 'A4', 'isRemoteEnabled' => false, 'allowedPaths' => [dirname(__DIR__) . '/examples', dirname(__DIR__) . '/resources']]);
        $pdf->loadHtml($html)->output();
        $best = min($best, (microtime(true) - $t) * 1000.0);
    }
    return $best;
};
$tInter = $timeIt($tpl['html']);
$tDejaVu = $timeIt(str_replace("'Inter'", "'DejaVu Sans'", $tpl['html']));
printf("    invoice: Inter %.0f ms, DejaVu Sans %.0f ms (ratio %.2f)\n", $tInter, $tDejaVu, $tInter / max(1.0, $tDejaVu));
check('performance smoke: Inter invoice renders in well under 1.5 s', $tInter < 1500.0, sprintf('%.0f ms', $tInter));
check('performance smoke: Inter within 3x of DejaVu Sans (target ~1.5x)', $tInter < max(250.0, 3.0 * $tDejaVu), sprintf('%.0f vs %.0f ms', $tInter, $tDejaVu));

// ---------------------------------------------------------------------------
echo "\n10. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
