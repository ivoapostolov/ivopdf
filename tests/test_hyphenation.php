<?php

declare(strict_types=1);

/**
 * Tests for hyphenation: Liang patterns (bg, en-us), soft hyphens (&shy;), `hyphens`, `hyphenate-character`,
 * `hyphenate-limit-chars`, lang selection and line breaking with a drawn hyphen.
 *
 * Run: php tests/test_hyphenation.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Text\Hyphenator;

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

/** Hyphenates a word and renders it as "hy-phen-ation". */
function hy(string $lang, string $word, int $minWord = 5, int $l = 2, int $r = 2): string
{
    $h = Hyphenator::forLang($lang);
    if ($h === null) {
        return $word;
    }
    $points = $h->hyphenate($word, $minWord, $l, $r);
    $out = '';
    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $ch) {
        $out .= (in_array($i, $points, true) ? '-' : '') . $ch;
    }
    return $out;
}

/** @return list<string> text of every laid-out line */
function lines(string $html): array
{
    $pdf = new IvoPdf();
    $pdf->loadHtml($html)->render();
    $out = [];
    $walk = static function (LayoutBox $b) use (&$walk, &$out): void {
        foreach ($b->lines as $line) {
            $t = '';
            foreach ($line->runs as $r) {
                $t .= $r->text;
            }
            $out[] = rtrim($t);
        }
        foreach ($b->children as $c) {
            $walk($c);
        }
    };
    $tree = $pdf->getLayoutTree();
    if ($tree !== null) {
        $walk($tree);
    }
    return $out;
}

function para(string $lang, string $style, string $text, int $width = 110): string
{
    $langAttr = $lang === '' ? '' : ' lang="' . $lang . '"';
    return '<html' . $langAttr . '><body style="font:11pt DejaVu Sans"><p style="width:' . $width . 'pt;text-align:justify;margin:0;' . $style . '">' . $text . '</p></body></html>';
}

function endsWithHyphen(array $lines, string $char = '-'): bool
{
    foreach ($lines as $l) {
        if (preg_match('/\p{L}' . preg_quote($char, '/') . '$/u', $l) === 1) {
            return true;
        }
    }
    return false;
}

$bgText = 'Програмирането на електроенергийните системи изисква документация и университетски познания за българският език.';
$enText = 'Hyphenation of international typography algorithms makes justified paragraphs in narrow columns look beautiful.';

echo "\n1. Hyphenator (Liang patterns)\n";
check('EN hyphenation -> hy-phen-ation', hy('en-US', 'hyphenation') === 'hy-phen-ation', hy('en-US', 'hyphenation'));
check('EN algorithm -> al-go-rithm', hy('en', 'algorithm') === 'al-go-rithm', hy('en', 'algorithm'));
check('EN exception list: present is never split', hy('en', 'present') === 'present');
check('EN exception list: ta-ble', hy('en', 'table') === 'ta-ble');
check('EN uppercase handled', hy('en', 'ALGORITHM') === 'AL-GO-RITHM', hy('en', 'ALGORITHM'));
check('BG хифенация -> хи-фе-на-ция', hy('bg', 'хифенация') === 'хи-фе-на-ция', hy('bg', 'хифенация'));
check('BG компютър -> ком-пю-тър', hy('bg', 'компютър') === 'ком-пю-тър', hy('bg', 'компютър'));
check('BG програмиране keeps syllable boundaries', str_ends_with(hy('bg', 'програмиране'), '-ми-ра-не'), hy('bg', 'програмиране'));
check('BG електроенергия splits after "елек" and before "гия"', str_starts_with(hy('bg', 'електроенергия'), 'елек-') && str_ends_with(hy('bg', 'електроенергия'), '-нер-гия'), hy('bg', 'електроенергия'));
check('BG университет -> ..-си-тет', str_ends_with(hy('bg', 'университет'), '-си-тет'), hy('bg', 'университет'));
check('min word length respected', hy('en', 'table', 6) === 'table');
check('left/right min respected', hy('en', 'hyphenation', 5, 5, 5) === 'hyphen-ation', hy('en', 'hyphenation', 5, 5, 5));
check('unknown language has no hyphenator', Hyphenator::forLang('xx') === null && Hyphenator::forLang('') === null);
check('bg-BG and en_GB map to shipped sets', Hyphenator::forLang('bg-BG') !== null && Hyphenator::forLang('en_GB') !== null);
check('pattern data files carry licence headers', str_contains((string) file_get_contents(__DIR__ . '/../resources/hyphenation/bg.php'), 'Anton Zinoviev')
    && str_contains((string) file_get_contents(__DIR__ . '/../resources/hyphenation/en-us.php'), 'Kuiken'));

echo "\n2. Soft hyphens\n";
$soft = 'AAA super&shy;cali&shy;fragil&shy;istic expiali&shy;docious done';
$wrapped = lines(para('en', '', $soft, 80));
$joined = implode('|', $wrapped);
check('soft hyphen break draws a visible hyphen', endsWithHyphen($wrapped), $joined);
check('soft hyphen text never contains U+00AD', !str_contains($joined, "\u{00AD}"));
$wide = lines(para('en', '', 'super&shy;cali&shy;fragil&shy;istic', 400));
check('unbroken soft hyphen word draws nothing', $wide === ['supercalifragilistic'], implode('|', $wide));
$none = lines(para('en', 'hyphens:none', $soft, 80));
check('hyphens:none disables soft hyphen breaks', !endsWithHyphen($none), implode('|', $none));
$autoSoft = lines(para('en', 'hyphens:auto', 'incomprehensi&shy;bility', 100));
check('hyphens:auto honours soft hyphens', count($autoSoft) >= 2 && str_ends_with($autoSoft[0], '-'), implode('|', $autoSoft));

echo "\n3. hyphens: auto / manual / none, lang\n";
$manual = lines(para('en', '', $enText));
$auto = lines(para('en', 'hyphens:auto', $enText));
$noneL = lines(para('en', 'hyphens:none', $enText));
check('hyphens:manual (default) adds no hyphens to plain text', !endsWithHyphen($manual));
check('hyphens:none == default for plain text', $noneL === $manual);
check('hyphens:auto hyphenates English', endsWithHyphen($auto), implode('|', $auto));
$flat = preg_replace('/-\n/', '', implode("\n", $auto) . "\n");
check('auto hyphenation preserves the words', str_replace([' ', "\n"], '', (string) $flat) === str_replace(' ', '', $enText), (string) $flat);
$bgManual = lines(para('bg', '', $bgText));
$bgAuto = lines(para('bg', 'hyphens:auto', $bgText));
check('hyphens:auto hyphenates Bulgarian', endsWithHyphen($bgAuto), implode('|', $bgAuto));
check('BG: auto uses fewer or equal lines', count($bgAuto) <= count($bgManual), count($bgAuto) . ' vs ' . count($bgManual));
check('EN: auto uses fewer or equal lines', count($auto) <= count($manual), count($auto) . ' vs ' . count($manual));
$unknown = lines(para('xx', 'hyphens:auto', $enText));
check('unknown lang: no automatic hyphenation', $unknown === $manual, implode('|', $unknown));
$noLang = lines(para('', 'hyphens:auto', $enText));
check('missing lang: no automatic hyphenation', !endsWithHyphen($noLang));
$inherit = lines('<html lang="en"><body style="font:11pt DejaVu Sans;hyphens:auto"><div><p style="width:110pt;margin:0">' . $enText . '</p></div></body></html>');
check('hyphens and lang are inherited', endsWithHyphen($inherit), implode('|', $inherit));
$override = lines('<html lang="bg"><body style="font:11pt DejaVu Sans;hyphens:auto"><p lang="en" style="width:110pt;margin:0">' . $enText . '</p></body></html>');
check('lang on an element overrides the document language', endsWithHyphen($override), implode('|', $override));

echo "\n4. hyphenate-character / hyphenate-limit-chars\n";
$tilde = lines(para('en', 'hyphens:auto;hyphenate-character:\'~\'', $enText));
check('hyphenate-character used at the break', endsWithHyphen($tilde, '~') && !endsWithHyphen($tilde), implode('|', $tilde));
$lim = lines(para('en', 'hyphens:auto;hyphenate-limit-chars:20 2 2', $enText));
check('hyphenate-limit-chars: long minimum word disables hyphenation', $lim === $manual, implode('|', $lim));
$left = lines(para('en', 'hyphens:auto;hyphenate-limit-chars:5 4 4', $enText));
$okMin = true;
foreach ($left as $ln) {
    if (str_ends_with($ln, '-')) {
        $parts = preg_split('/\s+/', $ln) ?: [];
        $okMin = $okMin && mb_strlen((string) end($parts)) - 1 >= 4;
    }
}
check('hyphenate-limit-chars: left minimum respected', $okMin, implode('|', $left));

echo "\n5. Rendering\n";
$pdf = new IvoPdf();
$pdf->loadHtml(para('bg', 'hyphens:auto', $bgText))->render();
check('PDF with hyphenation renders', str_starts_with($pdf->output('', 'S'), '%PDF-'));

echo "\n6. Engine hygiene\n";
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
