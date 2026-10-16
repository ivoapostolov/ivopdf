<?php

declare(strict_types=1);

/**
 * Input encoding sniffing (Windows-1251 without a meta charset) and var() fallbacks with nested parentheses.
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Dom\InputEncoding;
use IvoPdf\IvoPdf;

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

echo "=== Input encoding ===\n";
$bg = 'Фактура № 42 — Общо: 120 лв. Здравейте, това е тест на кирилица.';
$cp1251 = (string) mb_convert_encoding("<p>{$bg}</p>", 'Windows-1251', 'UTF-8');
check('Windows-1251 without meta is detected', InputEncoding::toUtf8($cp1251) === "<p>{$bg}</p>");

$fr = 'Le café est très bon, à côté de la gare. Voilà une phrase en français.';
$cp1252 = (string) mb_convert_encoding("<p>{$fr}</p>", 'Windows-1252', 'UTF-8');
check('Windows-1252 without meta stays Windows-1252', InputEncoding::toUtf8($cp1252) === "<p>{$fr}</p>");

$withMeta = (string) mb_convert_encoding('<meta charset="koi8-r"><p>Привет мир</p>', 'KOI8-R', 'UTF-8');
check('meta charset still wins', str_contains(InputEncoding::toUtf8($withMeta), 'Привет мир'));

check('valid UTF-8 is unchanged', InputEncoding::toUtf8("<p>{$bg}</p>") === "<p>{$bg}</p>");

$markupHeavy = (string) mb_convert_encoding('<table class="invoice-table"><tr><td class="label">Сума</td></tr></table>', 'Windows-1251', 'UTF-8');
check('markup is ignored when sniffing', str_contains(InputEncoding::toUtf8($markupHeavy), 'Сума'));

echo "\n=== var() fallbacks ===\n";
function colorOf(string $css): string
{
    $pdf = new IvoPdf(['defaultFont' => 'DejaVu Sans']);
    $pdf->loadHtml('<style>' . $css . '</style><p id="t">x</p>');
    $pdf->render();
    $find = static function ($box) use (&$find): ?string {
        if (($box->domElement?->getAttribute('id') ?? '') === 't') {
            return (string) $box->style->get('color');
        }
        foreach ($box->children as $c) {
            $r = $find($c);
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    };
    return (string) $find($pdf->getLayoutTree());
}

$c = colorOf('#t { color: var(--missing, rgb(255, 0, 0)); }');
check('fallback with nested parentheses', str_contains(str_replace(' ', '', $c), '255,0,0') || $c === '#ff0000', $c);

$c = colorOf(':root { --b: rgb(0, 0, 255); } #t { color: var(--a, var(--b)); }');
check('nested var() fallback', str_contains(str_replace(' ', '', $c), '0,0,255') || $c === '#0000ff', $c);

$c = colorOf(':root { --a: green; } #t { color: var(--a, rgb(1, 2, 3)); }');
check('defined variable wins over fallback', $c === 'green' || $c === '#008000', $c);

$c = colorOf('#t { color: var(--missing, var(--also-missing, rgba(0, 128, 0, 1))); }');
check('two-level fallback chain', str_contains(str_replace(' ', '', $c), '0,128,0'), $c);

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
