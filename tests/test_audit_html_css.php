<?php

declare(strict_types=1);

/**
 * Regression tests for audit #3 items: HTML comments, input encodings, <noscript>, <ol type>,
 * invalid CSS values being ignored, calc()/min()/max()/clamp(), browser-like heading defaults.
 *
 * Run: php tests/test_audit_html_css.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\CssUnit;
use IvoPdf\Css\CssValidator;
use IvoPdf\Dom\InputEncoding;
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
        echo "  [FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/** @return list<string> */
function runTexts(LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            $out[] = $run->text;
        }
    }
    foreach ($box->children as $child) {
        array_push($out, ...runTexts($child));
    }
    return $out;
}

function allText(IvoPdf $pdf): string
{
    return implode('', runTexts($pdf->getLayoutTree()));
}

function firstByTag(IvoPdf $pdf, string $tag): ?LayoutBox
{
    $find = static function (LayoutBox $b) use (&$find, $tag): ?LayoutBox {
        if ($b->tagName === $tag) {
            return $b;
        }
        foreach ($b->children as $c) {
            if (($f = $find($c)) !== null) {
                return $f;
            }
        }
        return null;
    };
    return $find($pdf->getLayoutTree());
}

/** @return list<string> */
function markers(IvoPdf $pdf): array
{
    $out = [];
    $walk = static function (LayoutBox $b) use (&$walk, &$out): void {
        if ($b->listMarker !== null && $b->listMarker !== '') {
            $out[] = $b->listMarker;
        }
        foreach ($b->children as $c) {
            $walk($c);
        }
    };
    $walk($pdf->getLayoutTree());
    return $out;
}

echo "1. HTML comments do not duplicate text\n";
foreach ([true, false] as $h5) {
    $mode = $h5 ? 'html5' : 'legacy';
    $p = render('<p>a<!--c-->b</p>', ['html5Parser' => $h5]);
    check("[$mode] a<!--c-->b renders 'ab' once", allText($p) === 'ab', allText($p));
    $p = render('<p>one <!-- c --> two</p>', ['html5Parser' => $h5]);
    $t = allText($p);
    check("[$mode] 'one <!-- c --> two' renders once", substr_count($t, 'one') === 1 && substr_count($t, 'two') === 1, $t);
}

echo "\n2. Input encodings\n";
$bg = 'Здравей свят';
$cp = iconv('UTF-8', 'windows-1251', $bg);
check('meta charset windows-1251', str_contains(InputEncoding::toUtf8('<meta charset="windows-1251"><p>' . $cp . '</p>'), $bg));
check('http-equiv Content-Type', str_contains(InputEncoding::toUtf8('<meta http-equiv=Content-Type content="text/html; charset=windows-1251"><p>' . $cp . '</p>'), $bg));
check('explicit inputEncoding value', InputEncoding::toUtf8($cp, 'windows-1251') === $bg);
check('valid UTF-8 untouched in auto mode', InputEncoding::toUtf8("<p>$bg</p>") === "<p>$bg</p>");
check('invalid UTF-8 without meta becomes valid UTF-8', preg_match('//u', InputEncoding::toUtf8("<p>caf\xE9</p>")) === 1);
check('UTF-8 BOM stripped', InputEncoding::toUtf8("\xEF\xBB\xBF<p>x</p>") === '<p>x</p>');
foreach ([true, false] as $h5) {
    $mode = $h5 ? 'html5' : 'legacy';
    $p = render('<html><head><meta charset="windows-1251"></head><body><p>' . $cp . '</p></body></html>', ['html5Parser' => $h5]);
    check("[$mode] windows-1251 document renders Cyrillic", str_contains(allText($p), 'Здравей'), allText($p));
    $p = render('<p>' . $cp . '</p>', ['html5Parser' => $h5, 'inputEncoding' => 'windows-1251']);
    check("[$mode] raw bytes with inputEncoding option", str_contains(allText($p), 'свят'), allText($p));
}

echo "\n3. noscript and ol type\n";
$p = render('<p>visible</p><noscript><p>NOSCRIPTTEXT</p></noscript>');
check('noscript content is not rendered (dompdf/mPDF compatible)', !str_contains(allText($p), 'NOSCRIPTTEXT') && str_contains(allText($p), 'visible'));
$p = render('<ol type="a"><li>x</li><li>y</li></ol><ol type="A"><li>x</li></ol><ol type="i"><li>x</li><li>y</li></ol><ol type="I"><li>x</li><li>y</li><li>z</li></ol><ol type="1"><li>x</li></ol>');
$flat = implode(' ', markers($p));
check('ol type=a/A/i/I/1 markers', str_starts_with($flat, 'a.') && str_contains($flat, 'b.') && str_contains($flat, 'A.') && str_contains($flat, 'ii.') && str_contains($flat, 'III.') && str_ends_with($flat, '1.'), $flat);
check('li type attribute', (markers(render('<ol><li type="I">x</li></ol>'))[0] ?? '') === 'I.');
check('author CSS overrides the type attribute', (markers(render('<style>ol{list-style-type:upper-roman}</style><ol type="a"><li>x</li></ol>'))[0] ?? '') === 'I.');

echo "\n4. Invalid CSS values are ignored\n";
$p = render('<div style="width:200pt;width:abc">x</div>');
check('width:abc keeps previous width', abs(firstByTag($p, 'div')->width - 200.0) < 0.01, (string) firstByTag($p, 'div')->width);
$p = render('<p style="color:;:red;;width:calc(">junk</p>');
check('unclosed calc( does not collapse the box', firstByTag($p, 'p')->width > 100.0 && allText($p) === 'junk', allText($p));
$d = firstByTag(render('<div style="margin-left:10pt;margin-left:10qq;padding-left:5pt;padding-left:-4pt">x</div>'), 'div');
check('margin 10qq and negative padding ignored', $d->style->get('margin-left') === '10pt' && $d->style->get('padding-left') === '5pt', $d->style->get('margin-left') . ' / ' . $d->style->get('padding-left'));
$d = firstByTag(render('<div style="color:#ff0000;color:notacolor">x</div>'), 'div');
check('color:notacolor keeps previous color', $d->style->get('color') === '#ff0000', $d->style->get('color'));
$s = firstByTag(render('<div style="font-size:20pt"><span style="font-size:big">x</span></div>'), 'span');
check('font-size:big ignored (inherits 20pt)', abs($s->style->getFontSizePt() - 20.0) < 0.01, (string) $s->style->getFontSizePt());
foreach (['10 px', '50%%', 'abc', 'calc(', 'calc(1px/0)', '-50pt', 'auto auto', '10qq'] as $bad) {
    check("width:$bad is invalid", !CssValidator::isValid('width', $bad));
}
foreach (['auto', '50%', '10pt', 'fit-content', 'min-content', 'calc(100% - 10px)', 'min(100%, 400px)', 'clamp(10pt, 2vw, 14pt)', '0', '.5em', 'fit-content(100pt)'] as $good) {
    check("width:$good is valid", CssValidator::isValid('width', $good));
}
check('margin shorthand components', CssValidator::isValid('margin', '0 auto') && CssValidator::isValid('margin', '-5pt 1em 2px 3mm') && !CssValidator::isValid('margin', '1px 2px 3px 4px 5px') && !CssValidator::isValid('margin', '1px foo'));
check('border shorthand components', CssValidator::isValid('border', '1px solid #ccc') && CssValidator::isValid('border', 'none') && !CssValidator::isValid('border', '1px wavy purple-ish'));
check('colors', CssValidator::validColor('rgb(1,2,3)') && CssValidator::validColor('#abc') && CssValidator::validColor('transparent') && !CssValidator::validColor('#12') && !CssValidator::validColor('notacolor') && !CssValidator::validColor('rgb(1,2'));
check('line-height number/normal valid; negative word-spacing valid', CssValidator::isValid('line-height', '1.5') && CssValidator::isValid('line-height', 'normal') && CssValidator::isValid('word-spacing', '-2pt'));
$d = firstByTag(render('<div style="margin:5pt 7pt;margin:5pt 1px 2 3 4">x</div>'), 'div');
check('invalid shorthand dropped as a whole', $d->style->get('margin-left') === '7pt', $d->style->get('margin-left'));

echo "\n5. calc(), min(), max(), clamp()\n";
$cases = [
    ['calc(10pt*2 + 5%)', 200.0, 30.0],
    ['calc(calc(10pt*2) + 5%)', 200.0, 30.0],
    ['calc(100% - (2 * 10pt))', 200.0, 180.0],
    ['calc(-10pt + 20pt)', 200.0, 10.0],
    ['calc(10pt * -2)', 200.0, -20.0],
    ['calc((100% - 30pt) / 2)', 200.0, 85.0],
    ['min(100pt, 50%)', 150.0, 75.0],
    ['max(10pt, 5%)', 200.0, 10.0],
    ['clamp(10pt, 50%, 100pt)', 400.0, 100.0],
    ['clamp(10pt, 1%, 100pt)', 400.0, 10.0],
    ['min(100%, 400px)', 600.0, 300.0],
    ['calc(max(10pt, 5%) + min(4pt, 8pt))', 400.0, 24.0],
    ['calc(1in + 1pc)', 0.0, 84.0],
];
foreach ($cases as [$expr, $ref, $want]) {
    $got = CssUnit::toPt($expr, $ref, 12.0, 12.0, -999.0);
    check("$expr = $want", abs($got - $want) < 0.01, (string) $got);
}
check('division by zero is invalid', CssUnit::toPt('calc(1pt / 0)', 100.0, 12.0, 12.0, -999.0) === -999.0 && !CssValidator::isValid('width', 'calc(1pt / 0)'));
check('unbalanced calc is invalid', CssUnit::toPt('calc((1pt)', 100.0, 12.0, 12.0, -999.0) === -999.0);
$d = firstByTag(render('<style>:root{--a:10pt;--b:calc(var(--a)*2)}</style><div style="width:calc(var(--b) + 5pt)">x</div>'), 'div');
check('var() chain inside calc', abs($d->width - 25.0) < 0.01, (string) $d->width);
$d = firstByTag(render('<div style="width:min(100%, 100pt)">x</div>'), 'div');
check('top-level min() as width', abs($d->width - 100.0) < 0.01, (string) $d->width);

echo "\n6. Browser-like heading defaults\n";
$p = render('<style>body{font-size:10pt}</style><h1>a</h1><h2>a</h2><h3>a</h3><h4>a</h4><h5>a</h5><h6>a</h6>');
foreach (['h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67] as $tag => $em) {
    $size = firstByTag($p, $tag)->style->getFontSizePt();
    check("$tag font-size = {$em}em of the 10pt body", abs($size - 10.0 * $em) < 0.05, (string) $size);
}
$w = strtolower(firstByTag($p, 'h1')->style->get('font-weight'));
check('h1 stays bold', $w === 'bold' || (int) $w >= 700, $w);
$h2 = firstByTag(render('<style>body{font-size:10pt}</style><div style="font-size:20pt"><h2>a</h2></div>'), 'h2');
check('heading size is relative to the parent (1.5 x 20pt)', abs($h2->style->getFontSizePt() - 30.0) < 0.05);

echo "\n7. Hygiene\n";
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
