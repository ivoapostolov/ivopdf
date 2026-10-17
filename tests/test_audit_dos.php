<?php

declare(strict_types=1);

/**
 * Regression tests for the performance / denial-of-service audit findings:
 *  - nested inline-block took exponential time (target-counter / leader pass walked inline boxes twice per level)
 *  - chained SVG <use> groups expanded exponentially (instance budget)
 *  - tens of thousands of <br> were quadratic in pagination and painting (and exhausted memory)
 *  - very long <barcode> values (Options::$maxBarcodeLength)
 *  - CSS rules x elements matching (per-parent style prototypes) and per-cell memory of tables
 *  - Options::$maxRenderTime / Options::$maxMemory and LimitExceededException
 *
 * All time bounds are deliberately generous (the fixed code needs a small fraction of them), so slow CI machines pass.
 *
 * Run: php tests/test_audit_dos.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Exception\LimitExceededException;
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
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** @return array{0: IvoPdf, 1: float, 2: string} pdf object, seconds, output */
function timed(string $html, array $options = []): array
{
    $pdf = new IvoPdf(array_merge(['compress' => false, 'defaultFont' => 'DejaVu Sans'], $options));
    $t = microtime(true);
    $out = $pdf->loadHtml($html)->output();
    return [$pdf, microtime(true) - $t, $out];
}

function findNode(?LayoutBox $box, callable $pred): ?LayoutBox
{
    if ($box === null) {
        return null;
    }
    if ($pred($box)) {
        return $box;
    }
    foreach ($box->children as $c) {
        $f = findNode($c, $pred);
        if ($f !== null) {
            return $f;
        }
    }
    return null;
}

function colorOf(IvoPdf $pdf, string $id): string
{
    $box = findNode($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement !== null && $b->domElement->getAttribute('id') === $id);
    return $box !== null ? strtolower($box->style->get('color')) : '(missing)';
}

// ---------------------------------------------------------------------------
echo "--- 1. nested inline-block ---\n";
foreach ([24, 60, 200] as $n) {
    [$pdf, $secs] = timed(str_repeat('<span style="display:inline-block;padding:2pt">', $n) . 'x' . str_repeat('</span>', $n));
    check("{$n} nested inline-blocks render in time", $secs < 8.0 && $pdf->getPageCount() >= 1, sprintf('%.2fs', $secs));
}
[$pdf] = timed('<style>a::after{content:leader(".") target-counter(attr(href), page)}</style><p><a href="#t">Link</a></p><div style="page-break-before:always" id="t"><span style="display:inline-block">in <span style="display:inline-block">side</span></span></div>');
check('target-counter / leader pass still resolves page numbers with inline-blocks', $pdf->getPageCount() === 2);

// ---------------------------------------------------------------------------
echo "\n--- 2. SVG <use> expansion ---\n";
$svg = static function (int $levels, int $fan): string {
    $s = '<svg width="100" height="100"><defs><g id="g0"><rect width="5" height="5"/></g>';
    for ($i = 1; $i <= $levels; $i++) {
        $s .= "<g id=\"g{$i}\">" . str_repeat('<use href="#g' . ($i - 1) . '"/>', $fan) . '</g>';
    }
    return $s . "</defs><use href=\"#g{$levels}\"/></svg>";
};
[$pdf, $secs] = timed($svg(8, 10));
$warned = false;
foreach ($pdf->getWarnings() as $w) {
    $warned = $warned || str_contains($w, 'truncated');
}
check('exponential <use> chain (10^8 instances) is cut off quickly', $secs < 10.0, sprintf('%.2fs', $secs));
check('truncation adds a warning', $warned, json_encode($pdf->getWarnings()));
$count = 0;
foreach ($pdf->getWarnings() as $w) {
    $count += str_contains($w, 'truncated') ? 1 : 0;
}
check('exactly one truncation warning', $count === 1, (string) $count);
[$pdf, , $out] = timed($svg(2, 3));
check('a small <use> tree is not truncated', $pdf->getWarnings() === [] && substr_count($out, ' re') >= 9, json_encode($pdf->getWarnings()));
[$pdf, $secs] = timed('<svg width="50" height="50"><defs><g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g></defs><use href="#a"/><g id="c"><use href="#c"/></g></svg>');
check('cyclic <use> references are still ignored', $secs < 3.0 && $pdf->getPageCount() === 1);

// ---------------------------------------------------------------------------
echo "\n--- 3. many <br> / long barcodes ---\n";
[$pdf, $secs] = timed(str_repeat('<br>', 20000));
check('20 000 <br> render in time', $secs < 12.0, sprintf('%.2fs', $secs));
$pages20 = $pdf->getPageCount();
[$pdf, $secs2] = timed(str_repeat('<br>', 40000));
check('40 000 <br> take about twice as long, not four times (linear)', $secs2 < 25.0 && $secs2 < max(6.0 * $secs, 3.0), sprintf('%.2fs vs %.2fs', $secs2, $secs));
check('page count scales with the number of lines', $pdf->getPageCount() > $pages20 * 1.8 && $pdf->getPageCount() < $pages20 * 2.2, $pages20 . ' / ' . $pdf->getPageCount());
$mem = memory_get_peak_usage();
check('<br> markup does not need hundreds of MB', $mem < 400 * 1048576, sprintf('%.0f MB', $mem / 1048576));

[$pdf, $secs] = timed('<barcode type="C128" value="' . str_repeat('A', 100000) . '"/>');
$msg = implode(' | ', $pdf->getWarnings());
check('100 000-character Code128 is refused fast with a warning', $secs < 5.0 && str_contains($msg, 'maxBarcodeLength'), sprintf('%.2fs %s', $secs, $msg));
[$pdf, , $out] = timed('<barcode type="C128" value="' . str_repeat('A', 300) . '"/>');
check('a 300-character Code128 still renders', $pdf->getWarnings() === [] && str_contains($out, ' re'));
[$pdf] = timed('<barcode type="C128" value="' . str_repeat('A', 300) . '"/>', ['maxBarcodeLength' => 100]);
check('Options::$maxBarcodeLength is configurable', $pdf->getWarnings() !== []);
[$pdf] = timed('<barcode type="C128" value="' . str_repeat('A', 6000) . '"/>', ['maxBarcodeLength' => 0]);
check('maxBarcodeLength = 0 lifts the limit', $pdf->getWarnings() === []);

// ---------------------------------------------------------------------------
echo "\n--- 4. CSS matching ---\n";
[$pdf, $secs] = timed('<style>' . str_repeat('div.a{color:red}', 1000) . '</style>' . str_repeat('<div class="a">x</div>', 2000));
check('1 000 matching rules x 2 000 elements', $secs < 6.0, sprintf('%.2fs', $secs));

$css = '';
for ($i = 0; $i < 1500; $i++) {
    $css .= match ($i % 3) {
        0 => ".c{$i}{color:#" . dechex(0x100000 + $i) . '}',
        1 => ".c" . ($i - 1) . " .c{$i}{margin:2px;padding:1px 2px}",
        default => "div.c{$i} > span{font-weight:bold}",
    };
}
$html = '<style>' . $css . '</style>';
for ($i = 0; $i < 700; $i++) {
    $a = ($i * 7) % 1500;
    $html .= "<div class=\"c{$a} c" . (($i * 13) % 1500) . "\"><span class=\"c{$a}\">t{$i}</span><ul><li class=\"c{$a}\">x</li></ul></div>";
}
[$pdf, $secs] = timed($html);
check('Bootstrap-sized stylesheet (1 500 rules) on 2 800 elements', $secs < 6.0, sprintf('%.2fs', $secs));

// Cascade semantics stay exactly the same (specificity, source order, !important, structural selectors)
[$pdf] = timed('<style>.a{color:#111111} .a.b{color:#222222} #i1{color:#333333} p{color:#444444} .z{color:#555555}'
    . ' .a{color:#666666} #i4{color:#777777 !important} .a.b.c{color:#888888}</style>'
    . '<p id="i0" class="a">1</p><p id="i1" class="a b">2</p><p id="i2" class="a b c">3</p><p id="i3" class="z">4</p><p id="i4" class="a" style="color:#999999">5</p>'
    . '<p id="i5" style="color:#aaaaaa">6</p>');
check('source order: later equal-specificity rule wins', colorOf($pdf, 'i0') === '#666666', colorOf($pdf, 'i0'));
check('specificity beats order (.a.b)', colorOf($pdf, 'i2') === '#888888' && colorOf($pdf, 'i1') === '#333333', colorOf($pdf, 'i1') . ' ' . colorOf($pdf, 'i2'));
check('class rule', colorOf($pdf, 'i3') === '#555555', colorOf($pdf, 'i3'));
check('!important beats the style attribute', colorOf($pdf, 'i4') === '#777777', colorOf($pdf, 'i4'));
check('style attribute beats element rules', colorOf($pdf, 'i5') === '#aaaaaa', colorOf($pdf, 'i5'));

// Identical siblings share a computed-style prototype only where no selector looks at their position
[$pdf] = timed('<style>li:first-child{color:#010101} li:nth-child(3){color:#030303} li + li + li + li{color:#040404} li.x ~ li{background-color:#ececec}</style>'
    . '<ul><li id="a">1</li><li id="b">2</li><li id="c">3</li><li id="d">4</li><li id="e">5</li></ul>');
check(':first-child applies to the first of identical siblings only', colorOf($pdf, 'a') === '#010101' && colorOf($pdf, 'b') !== '#010101', colorOf($pdf, 'a') . ' ' . colorOf($pdf, 'b'));
check(':nth-child(3) applies to the third only', colorOf($pdf, 'c') === '#030303' && colorOf($pdf, 'd') !== '#030303' && colorOf($pdf, 'e') !== '#030303');
check('adjacent-sibling chain applies from the fourth item', colorOf($pdf, 'd') === '#040404' && colorOf($pdf, 'e') === '#040404' && colorOf($pdf, 'c') !== '#040404');
[$pdf] = timed('<style>div p{color:#0a0a0a} div > p.k{color:#0b0b0b}</style><div><p id="a">x</p><p id="b" class="k">y</p></div><p id="c">z</p><section><div><p id="d">w</p></div></section>');
check('descendant / child selectors with shared siblings', colorOf($pdf, 'a') === '#0a0a0a' && colorOf($pdf, 'b') === '#0b0b0b' && colorOf($pdf, 'c') !== '#0a0a0a' && colorOf($pdf, 'd') === '#0a0a0a');

// Text nodes share their parent's style arrays but behave like before
[$pdf] = timed('<div id="p" style="position:absolute;float:left;transform:rotate(1deg);color:#123456;font-weight:bold;top:10pt">text <b>bold</b></div>');
$text = findNode($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->isTextNode() && ($b->textContent ?? '') === 'text ');
check('text node inside a positioned parent is in flow', $text !== null && !$text->isPositionedOutOfFlow() && $text->getDisplay() === 'inline');
check('text node keeps the inherited text properties', $text !== null && strtolower($text->style->get('color')) === '#123456' && $text->style->get('font-weight') === 'bold');
check('text node resets box-level properties', $text !== null && $text->style->get('position') === 'static' && $text->style->get('float') === 'none' && $text->style->get('transform') === 'none' && $text->style->get('top') === 'auto');
check('text style all() reflects the overrides', $text !== null && ($text->style->all()['display'] ?? '') === 'inline' && $text->style->has('display'));

// ---------------------------------------------------------------------------
echo "\n--- 5. table memory ---\n";
$before = memory_get_usage();
$tablePdf = new IvoPdf(['compress' => false, 'shrinkTablesToFit' => 0]);
$tablePdf->loadHtml('<table border="1">' . str_repeat('<tr>' . str_repeat('<td>c</td>', 10) . '</tr>', 400) . '</table>')->output();
$perCell = (memory_get_usage() - $before) / 4000;
check('a table cell retains well under 14 KB', $perCell < 14000, sprintf('%.1f KB per cell', $perCell / 1000));
unset($tablePdf);

// ---------------------------------------------------------------------------
echo "\n--- 10. maxRenderTime / maxMemory ---\n";
$defaults = new Options();
check('defaults: 120 s time budget, memory limit off, barcode limit 5000', $defaults->maxRenderTime === 120.0 && $defaults->maxMemory === 0 && $defaults->getMaxMemoryBytes() === 0 && $defaults->maxBarcodeLength === 5000);
check('LimitExceededException is a RuntimeException', is_subclass_of(LimitExceededException::class, \RuntimeException::class));

$big = str_repeat('<p>Paragraph with <b>bold</b> text and more words to wrap around the line.</p>', 15000);
$t = microtime(true);
$caught = null;
try {
    (new IvoPdf(['maxRenderTime' => 0.3]))->loadHtml($big)->output();
} catch (LimitExceededException $e) {
    $caught = $e;
}
$secs = microtime(true) - $t;
check('maxRenderTime aborts with a catchable LimitExceededException', $caught !== null && $caught->getLimit() === 'time' && str_contains($caught->getMessage(), 'maxRenderTime'), $caught?->getMessage() ?? 'no exception');
check('the abort happens close to the limit', $secs < 8.0, sprintf('%.2fs', $secs));

$caught = null;
try {
    (new IvoPdf(['maxMemory' => '24M', 'maxRenderTime' => 0]))->loadHtml($big)->output();
} catch (LimitExceededException $e) {
    $caught = $e;
}
check('maxMemory aborts with a catchable LimitExceededException', $caught !== null && $caught->getLimit() === 'memory' && str_contains($caught->getMessage(), 'maxMemory'), $caught?->getMessage() ?? 'no exception');

$ok = (new IvoPdf(['maxRenderTime' => 30, 'maxMemory' => '512M']))->loadHtml('<p>small</p>')->output();
check('documents within the limits render normally', str_starts_with($ok, '%PDF'));
try {
    (new IvoPdf(['maxRenderTime' => 0.3]))->loadHtml($big)->output();
} catch (LimitExceededException) {
}
$after = (new IvoPdf(['maxRenderTime' => 30]))->loadHtml('<p>after an aborted render</p>')->output();
check('a new render works after an aborted one', str_starts_with($after, '%PDF'));
$unlimited = (new IvoPdf(['maxRenderTime' => 0, 'maxMemory' => 0]))->loadHtml('<p>x</p>')->output();
check('0 disables both limits', str_starts_with($unlimited, '%PDF'));

$o = new Options(['maxMemory' => '256M']);
check("maxMemory accepts '256M' (bytes: " . $o->getMaxMemoryBytes() . ')', $o->getMaxMemoryBytes() === 268435456 || $o->getMaxMemoryBytes() === (int) (\IvoPdf\RenderLimits::phpMemoryLimit() * 0.9));
check("maxMemory accepts '1G', '512K' and plain bytes", \IvoPdf\RenderLimits::parseBytes('1G') === 1073741824 && \IvoPdf\RenderLimits::parseBytes('512K') === 524288 && \IvoPdf\RenderLimits::parseBytes(1000) === 1000 && \IvoPdf\RenderLimits::parseBytes('junk') === 0);
$oldLimit = ini_get('memory_limit');
$limitMb = (int) ceil(memory_get_usage() / 1048576) + 600;
$limitBytes = $limitMb * 1048576;
if (@ini_set('memory_limit', $limitMb . 'M') === false) {
    echo "  [SKIP] memory_limit cannot be changed here
";
} else {
    $o = new Options(['maxMemory' => '1000G']);
    check('the budget never exceeds 90 % of PHP memory_limit', $o->getMaxMemoryBytes() === (int) ($limitBytes * 0.9), (string) $o->getMaxMemoryBytes());
    $o = new Options(['maxMemory' => 'auto']);
    check("maxMemory 'auto' is 90 % of memory_limit", $o->getMaxMemoryBytes() === (int) ($limitBytes * 0.9));
    $o = new Options(['maxMemory' => '50M']);
    check('a budget below the ceiling is kept', $o->getMaxMemoryBytes() === 52428800);
    $o = new Options([]);
    check('memory limit off by default even with a PHP memory_limit', $o->getMaxMemoryBytes() === 0);
}
ini_set('memory_limit', $oldLimit === false ? '-1' : $oldLimit);

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
