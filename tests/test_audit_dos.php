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

/** Runs PHP code in a separate process; returns [peak MB, seconds, last output line]. */
function runIsolated(string $code, string $memoryLimit = '1G'): array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-dos-run-' . md5(uniqid('', true)) . '.php';
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

/** PHP code (for runIsolated) that renders a rows x 10 table and leaves the retained bytes per cell in $result. */
function tableMemoryCode(int $rows, string $options): string
{
    return '$pdf = new IvoPdf\IvoPdf(' . $options . ');'
        . '$pdf->loadHtml(\'<table border="1">\' . str_repeat(\'<tr>\' . str_repeat(\'<td>cell</td>\', 10) . \'</tr>\', ' . $rows . ') . \'</table>\');'
        . '$before = memory_get_usage(); $out = $pdf->output();'
        . '$result = (string) (int) round((memory_get_usage() - $before) / ' . ($rows * 10) . ');';
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

// A table of 10 000 / 50 000 cells in its own process (peak of the whole render, bytes retained per cell afterwards)
[$mb10, , $perCell10] = runIsolated(tableMemoryCode(1000, "['compress' => true]"));
check('10 000-cell table: peak memory below 56 MB (was 72 MB)', $mb10 < 56.0, "{$mb10} MB");
check('10 000-cell table: well under 5 KB retained per cell (was 6.8 KB)', (int) $perCell10 > 0 && (int) $perCell10 < 5000, "{$perCell10} B");
[$mb10off, , ] = runIsolated(tableMemoryCode(1000, "['shrinkTablesToFit' => 0]"));
check('10 000-cell table with shrinkTablesToFit 0: peak memory below 56 MB', $mb10off < 56.0, "{$mb10off} MB");
[$mb50, $secs50, $perCell50] = runIsolated(tableMemoryCode(5000, '[]'), '1G');
check('50 000-cell table: peak memory below 220 MB (was 326 MB)', $mb50 < 220.0, "{$mb50} MB");
check('50 000-cell table: below 4.2 KB retained per cell (was 6.2 KB)', (int) $perCell50 > 0 && (int) $perCell50 < 4200, "{$perCell50} B");
check('50 000-cell table renders in time', $secs50 < 120.0, "{$secs50} s");

// Identical cells share one style object (and one text style); a shared style is read-only
[$sharedPdf] = timed('<table border="1">' . str_repeat('<tr>' . str_repeat('<td>c</td>', 10) . '</tr>', 50) . '</table>');
$cellStyles = [];
$textStyles = [];
$walk = static function (LayoutBox $b) use (&$walk, &$cellStyles, &$textStyles): void {
    if ($b->tagName === 'td') {
        $cellStyles[spl_object_id($b->style)] = $b->style;
    }
    if ($b->isTextNode()) {
        $textStyles[spl_object_id($b->style)] = $b->style;
    }
    foreach ($b->children as $c) {
        $walk($c);
    }
};
$walk($sharedPdf->getLayoutTree());
check('500 identical cells share one style object', count($cellStyles) === 1, (string) count($cellStyles));
check('and their text nodes share one text style', count($textStyles) === 1, (string) count($textStyles));
$sharedStyle = reset($cellStyles);
$threw = false;
try {
    $sharedStyle->set('color', '#ff0000');
} catch (\LogicException) {
    $threw = true;
}
check('a shared style cannot be modified', $threw && $sharedStyle->isShared());
$copy = $sharedStyle->unshared();
$copy->set('color', '#ff0000');
check('a clone of a shared style is private and leaves the original alone', !$copy->isShared() && strtolower($sharedStyle->get('color')) !== '#ff0000' && strtolower($copy->get('color')) === '#ff0000');

// Shrinking one table must not scale the cells of an identical table next to it (their styles are shared)
$narrowHtml = '<table border="1" id="n">' . str_repeat('<tr><td>alpha</td><td>beta</td><td>gamma</td></tr>', 5) . '</table>';
$wideHtml = '<table border="1" id="w">' . str_repeat('<tr>' . str_repeat('<td>Wonderfulword</td>', 16) . '</tr>', 5) . '</table>';
$tableById = static fn(IvoPdf $pdf, string $id): ?LayoutBox => findNode($pdf->getLayoutTree(), static fn(LayoutBox $b): bool => $b->domElement !== null && $b->domElement->getAttribute('id') === $id);
$cellBoxes = static function (IvoPdf $pdf, string $tableId) use ($tableById): array {
    $out = [];
    $stack = [$tableById($pdf, $tableId)];
    while ($stack !== []) {
        $b = array_pop($stack);
        if ($b === null) {
            continue;
        }
        if ($b->tagName === 'td') {
            $out[] = [round($b->x, 2), round($b->width, 2), round($b->height, 2), $b->style->get('padding-left'), $b->style->get('font-size')];
        }
        foreach ($b->children as $c) {
            $stack[] = $c;
        }
    }
    sort($out);
    return $out;
};
[$aloneN] = timed($narrowHtml);
[$comboN] = timed($narrowHtml . $wideHtml . $narrowHtml);
[$aloneW] = timed($wideHtml);
check('the wide table is scaled down (shrink to fit)', \IvoPdf\Layout\TableShrink::appliedScale($tableById($comboN, 'w')) < 0.9);
$nAlone = $cellBoxes($aloneN, 'n');
$nCombo = $cellBoxes($comboN, 'n');
check('the narrow table next to it keeps its size (cell boxes and styles unchanged)', $nAlone !== [] && array_slice($nCombo, 0, count($nAlone)) === $nAlone, json_encode([$nAlone[0] ?? null, $nCombo[0] ?? null]));
check('the wide table shrinks the same way alone and next to a narrow one', $cellBoxes($aloneW, 'w') === $cellBoxes($comboN, 'w'));

// Rarely used box / run properties live in a side object but behave like plain properties
$box = new LayoutBox('div', new \IvoPdf\Css\ComputedStyle());
check('LayoutBox side properties have their defaults', $box->colspan === 1 && $box->rowspan === 1 && $box->listMarker === null && $box->columnRects === [] && $box->firstLineProps === [] && $box->collapsedMarginTop === 0.0 && $box->href === null);
$box->colspan = 3;
$box->href = '#x';
$box->columnRects = [['x' => 1.0, 'y' => 2.0, 'w' => 3.0, 'h' => 4.0, 'chunk' => 0, 'col' => 0]];
$box->collapsedMarginTop = 5;
$box->translate(10.0, 20.0);
check('LayoutBox side properties read back what was set', $box->colspan === 3 && $box->href === '#x' && $box->collapsedMarginTop === 5.0 && isset($box->href) && !isset($box->imageSrc) && !empty($box->columnRects));
check('translate() moves the column rects', $box->columnRects[0]['x'] === 11.0 && $box->columnRects[0]['y'] === 22.0);
$boxCopy = clone $box;
$boxCopy->colspan = 7;
$boxCopy->columnRects = [];
check('a cloned box owns its side properties', $box->colspan === 3 && $boxCopy->colspan === 7 && count($box->columnRects) === 1);
$box->colspan = 1;
$box->href = null;
check('a property set back to its default reads as the default', $box->colspan === 1 && $box->href === null);
$run = new \IvoPdf\Layout\TextRun('word', new \IvoPdf\Css\ComputedStyle(), 10.0);
check('TextRun side properties have their defaults', $run->bidiLevel === 0 && $run->bidiPre === [] && $run->softBreak === null && $run->paddingLeft === 0.0 && $run->wide === false && $run->lineStart === false);
$run->paddingLeft = 2;
$run->paddingLeft += 1.5;
$run->bidiPre = [8294];
$run->softBreak = '-';
$runCopy = clone $run;
$runCopy->bidiPre = [];
$runCopy->paddingLeft = 0.0;
check('TextRun side properties read, add and clone like plain properties', $run->paddingLeft === 3.5 && $run->bidiPre === [8294] && $run->softBreak === '-' && $runCopy->bidiPre === [] && $runCopy->paddingLeft === 0.0 && $runCopy->softBreak === '-');

// ---------------------------------------------------------------------------
echo "\n--- 10. maxRenderTime / maxMemory ---\n";
$defaults = new Options();
$phpLimit = \IvoPdf\RenderLimits::phpMemoryLimit();
check('defaults: 120 s time budget, memory budget auto (90 % of memory_limit), barcode limit 5000', $defaults->maxRenderTime === 120.0 && $defaults->maxMemory === 'auto' && $defaults->getMaxMemoryBytes() === ($phpLimit > 0 ? (int) ($phpLimit * 0.9) : 0) && $defaults->maxBarcodeLength === 5000);
$tiny = new Options(['maxMemory' => 64]);
check('maxMemory below 1 MB is ignored with a warning (unit mistake)', $tiny->getMaxMemoryBytes() === ($phpLimit > 0 ? (int) ($phpLimit * 0.9) : 0) && str_contains(implode(' ', $tiny->getWarnings()), "'256M'"));
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
    check('default budget is 90 % of PHP memory_limit', $o->getMaxMemoryBytes() === (int) ($limitBytes * 0.9));
    $o = new Options(['maxMemory' => 0]);
    check('maxMemory 0 turns the budget off', $o->getMaxMemoryBytes() === 0);
}
ini_set('memory_limit', $oldLimit === false ? '-1' : $oldLimit);

// ---------------------------------------------------------------------------
echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
