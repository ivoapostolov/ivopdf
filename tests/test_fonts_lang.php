<?php

declare(strict_types=1);

/**
 * Language-specific glyph forms (OpenType `locl` chosen from the HTML/CSS language) and the bundled Sofia Sans families.
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Font\FontFeatures;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;

$passed = 0;
$failed = 0;
$phpIssues = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$msg} ({$file}:{$line})";
    return true;
});

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** First text run of the document: [glyph ids, width]. */
function firstRun(string $html): array
{
    $pdf = new IvoPdf();
    $pdf->loadHtml($html);
    $pdf->render();
    $find = static function (LayoutBox $b) use (&$find): ?object {
        foreach ($b->lines as $line) {
            foreach ($line->runs ?? $line as $run) {
                if (is_object($run) && isset($run->text) && trim($run->text) !== '') {
                    return $run;
                }
            }
        }
        foreach ($b->children as $c) {
            if (($r = $find($c)) !== null) {
                return $r;
            }
        }
        return null;
    };
    $run = $find($pdf->getLayoutTree());
    return [$run !== null ? array_map(static fn(array $g): int => $g[0], $run->glyphs ?? []) : null, $run?->width ?? 0.0];
}

echo "=== Language tags ===\n";
check('bg maps to BGR', FontFeatures::otLanguage('bg') === 'BGR ');
check('region subtags are ignored (sr-Latn-RS)', FontFeatures::otLanguage('sr-Latn-RS') === 'SRB ');
check('underscore form (ru_RU)', FontFeatures::otLanguage('ru_RU') === 'RUS ');
check('Traditional Chinese', FontFeatures::otLanguage('zh-Hant') === 'ZHT ');
check('unknown language gives null', FontFeatures::otLanguage('xx') === null && FontFeatures::otLanguage('') === null);

echo "\n=== Sofia Sans: Bulgarian forms by default, Russian with lang=\"ru\" ===\n";
foreach (['Sofia Sans', 'Sofia Sans Semi Condensed'] as $family) {
    $p = "<p style=\"font-family:'{$family}'\">джоб</p>";
    [$bg] = firstRun("<html lang=\"bg\"><body>{$p}</body></html>");
    [$ru] = firstRun("<html lang=\"ru\"><body>{$p}</body></html>");
    [$none] = firstRun("<html><body>{$p}</body></html>");
    check("{$family}: glyphs are shaped", is_array($bg) && count($bg) === 4, json_encode($bg));
    check("{$family}: lang=ru selects other forms for д and ж", $bg !== null && $ru !== null && $bg[0] !== $ru[0] && $bg[1] !== $ru[1] && $bg[2] === $ru[2]);
    check("{$family}: no lang keeps the font's default (Bulgarian) forms", $none === $bg);
    [$inner] = firstRun("<html lang=\"bg\"><body><p lang=\"ru\" style=\"font-family:'{$family}'\">джоб</p></body></html>");
    check("{$family}: lang on an element overrides the document", $inner === $ru);
}
[, $wSofia] = firstRun("<p style=\"font-family:'Sofia Sans'\">Бързата кафява лисица</p>");
[, $wSemi] = firstRun("<p style=\"font-family:'Sofia Sans Semi Condensed'\">Бързата кафява лисица</p>");
check('Semi Condensed is narrower than Sofia Sans', $wSemi < $wSofia, sprintf('%.2f vs %.2f', $wSemi, $wSofia));
[, $wAlias] = firstRun('<p style="font-family:SofiaSansSemiCondensed">Бързата кафява лисица</p>');
check('SofiaSansSemiCondensed alias resolves', abs($wAlias - $wSemi) < 0.01);

echo "\n=== Serbian forms in DejaVu Sans italic ===\n";
$sr = '<p style="font-family:\'DejaVu Sans\';font-style:italic">бгдпт</p>';
[$srGlyphs] = firstRun("<html lang=\"sr\"><body>{$sr}</body></html>");
[$ruGlyphs] = firstRun("<html lang=\"ru\"><body>{$sr}</body></html>");
check('lang=sr substitutes Serbian italic forms', is_array($srGlyphs) && $srGlyphs !== [] && $srGlyphs !== $ruGlyphs, json_encode([$srGlyphs, $ruGlyphs]));

echo "\n=== Embedding ===\n";
$pdf = new IvoPdf(['pdfa' => '2b']);
$pdf->loadHtml("<html lang=\"bg\"><body><h1 style=\"font-family:'Sofia Sans'\">Фактура</h1><p style=\"font-family:'Sofia Sans Semi Condensed'\"><b>Общо:</b> <i>120 лв.</i></p></body></html>");
$bytes = $pdf->output();
check('Sofia Sans Regular and Semi Condensed Bold/Italic are embedded', str_contains($bytes, 'SofiaSans-Bold') && str_contains($bytes, 'SofiaSansSemiCondensed-Bold') && str_contains($bytes, 'SofiaSansSemiCondensed-Italic'));
check('PDF/A-2b checker passes with Sofia Sans', $pdf->validatePdfA() === [], json_encode($pdf->validatePdfA()));

echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
