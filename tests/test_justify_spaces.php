<?php

declare(strict_types=1);

/**
 * Inter-word spaces are real space glyphs in the PDF, also on justified lines and on lines whose runs are not merged
 * (letter-spacing, word-spacing, style changes), so text extractors that only read the text operators
 * (smalot/pdfparser, pypdf) do not glue words together. Nothing visible may move.
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;

$passed = 0;
$failed = 0;
$skipped = 0;
$phpIssues = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$msg} ({$file}:{$line})";
    return true;
});

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

$paragraphs = [
    'bg' => '„Алфа Бета“ ЕООД, ЕИК 207438569, със седалище и адрес на управление в гр. София, представлявано от управителя, наричано по-долу за краткост Възложител, от една страна, и изпълнителя от друга страна.',
    'en' => 'The quick brown fox jumps over the lazy dog while the agreement between the parties remains valid for the whole term of the contract and its annexes.',
    'ls' => 'Justified text with letter spacing and word spacing underlined words across a stretched gap and a highlighted span crossing gaps end.',
    'left' => 'Ляво подравнен абзац с разредка между буквите: ЕООД, ЕИК 207438569, със седалище и адрес на управление.',
];
$html = '<html lang="bg"><head><style>body { font-family: "DejaVu Sans"; font-size: 11pt; line-height: 1.4 } p { width: 300pt }</style></head><body>'
    . '<p style="text-align:justify;margin-left:7.1pt;text-indent:21.25pt">' . $paragraphs['bg'] . '</p>'
    . '<p style="text-align:justify">' . $paragraphs['en'] . '</p>'
    . '<p style="text-align:justify;letter-spacing:0.3pt;word-spacing:2pt">Justified text with letter spacing and word spacing <u>underlined words across a stretched gap</u> and <span style="background:#ff0">a highlighted span crossing gaps</span> end.</p>'
    . '<p style="text-align:left;letter-spacing:0.5pt">' . $paragraphs['left'] . '</p>'
    . '<p dir="rtl" style="text-align:justify">שלום עולם זהו פסקה מיושרת לשני הצדדים עם מילים רבות כדי שהשורות יימתחו לרוחב מלא של התיבה.</p>'
    . '</body></html>';

$pdf = new IvoPdf();
$bytes = $pdf->loadHtml($html)->output();
$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-justify-' . getmypid() . '.pdf';
file_put_contents($file, $bytes);

/** Collapses extracted text: a line break after a hyphen joins the word, other breaks are spaces. */
function normaliseExtracted(string $text): string
{
    $text = str_replace("\r", '', $text);
    $text = (string) preg_replace('/-\n/u', '-', $text);
    $text = (string) preg_replace('/ *\n+ */u', ' ', $text);
    return trim($text);
}

echo "=== Text extraction (PyMuPDF) ===\n";
$script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-justify.py';
file_put_contents($script, <<<'PY'
import json, sys
import pymupdf as fitz
d = fitz.open(sys.argv[1])
p = d[0]
blocks = [b[4] for b in p.get_text('blocks', sort=False)]
spans = []
for b in p.get_text('rawdict')['blocks']:
    for l in b.get('lines', []):
        for s in l['spans']:
            spans.append(''.join(c['c'] for c in s['chars']))
print(json.dumps({'text': p.get_text(), 'blocks': blocks, 'spans': spans, 'words': [list(w[:5]) for w in p.get_text('words')], 'pages': len(d)}))
PY);
$json = @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
$res = is_string($json) ? json_decode($json, true) : null;
if (!is_array($res)) {
    skip('PyMuPDF not available');
} else {
    $all = normaliseExtracted($res['text']);
    foreach (['bg', 'en', 'ls', 'left'] as $k) {
        check("paragraph '{$k}': words separated by single spaces exactly as in the HTML", str_contains($all, $paragraphs[$k]), mb_substr($all, 0, 160));
    }
    check('no double spaces in the extracted text', !str_contains($all, '  '));
    $glued = array_filter($res['spans'], static fn(string $s): bool => preg_match('/\p{Hebrew}/u', $s) !== 1
        && preg_match('/[,.]\p{L}|\p{Ll}\p{Lu}/u', $s) === 1 && !str_contains($s, 'по-'));
    check('no span glues two words (e.g. "ЕООД,ЕИК")', $glued === [], implode(' | ', array_slice($glued, 0, 3)));
    check('Hebrew paragraph keeps its words', str_contains($all, 'שלום') && str_contains($all, 'התיבה'));
}

echo "\n=== Text extraction (pdftotext, when installed) ===\n";
$pdftotext = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null'));
if ($pdftotext === '') {
    skip('pdftotext not installed');
} else {
    $out = (string) shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($file) . ' -');
    $flat = normaliseExtracted($out);
    foreach (['bg', 'en', 'left'] as $k) {
        check("pdftotext: paragraph '{$k}' has its spaces", str_contains($flat, $paragraphs[$k]), mb_substr($flat, 0, 120));
    }
}

echo "\n=== Raw content: one space glyph per inter-word gap on justified lines ===\n";
$raw = new IvoPdf(['compress' => false]);
$rawBytes = $raw->loadHtml('<p style="width:200pt;text-align:justify;font-family:\'DejaVu Sans\';font-size:11pt">aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj kkkk llll mmmm</p>')->output();
// first line: 7 words, 6 gaps; "ffff" is a ligature run written as a TJ array
$firstLine = strstr($rawBytes, '<004B004B004B004B', true) ?: $rawBytes; // everything before the second line ("kkkk…")
$withSpace = preg_match_all('~(?:0003> Tj|<0003>\] TJ)~', $firstLine);
check("every gap of a justified line is a space glyph inside the word's own string", $withSpace === 6, "{$withSpace} of 6");
check('no Tw word spacing operator is used (it does not apply to Identity-H fonts)', !preg_match('/\sTw\b/', $rawBytes));

echo "\n";
@unlink($file);
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
