<?php

declare(strict_types=1);

/**
 * East Asian, Indic and Southeast Asian scripts: font collections (.ttc), CJK line breaking (kinsoku, keep-all,
 * inter-character justification), script/lang based font selection, Devanagari & co. reordering and Thai breaking.
 * System fonts from C:\Windows\Fonts are used when present (tests are skipped otherwise); no fonts ship with the repo.
 *
 * Run: php tests/test_asian_scripts.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Font\FontManager;
use IvoPdf\Font\TtcExtractor;
use IvoPdf\Font\TtfParser;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Text\IndicShaper;
use IvoPdf\Text\ScriptBreaker;
use IvoPdf\Text\Scripts;

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$str} @ {$file}:{$line}";
    return true;
});

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label} — {$why}\n";
}

function render(string $html, array $options = [], ?callable $setup = null): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false], $options));
    if ($setup !== null) {
        $setup($pdf);
    }
    $pdf->loadHtml($html);
    $pdf->output();
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

/** @return list<string> text of every line of a box (runs concatenated) */
function lineTexts(?LayoutBox $box): array
{
    $out = [];
    foreach ($box->lines ?? [] as $line) {
        $t = '';
        foreach ($line->runs as $run) {
            $t .= $run->text;
        }
        $out[] = $t;
    }
    return $out;
}

function last(array $a): mixed
{
    return $a === [] ? null : $a[array_key_last($a)];
}

/** @return list<string> */
function chars(string $s): array
{
    return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

$win = 'C:/Windows/Fonts/';
$have = static fn(string $f): bool => is_file($win . $f);
$yahei = $have('msyh.ttc');
$gothic = $have('msgothic.ttc');
$malgun = $have('malgun.ttf');
$nirmala = $have('Nirmala.ttc');
$leelawadee = $have('LEELAWAD.TTF');

$registerAll = static function (IvoPdf $p) use ($win, $yahei, $gothic, $malgun, $nirmala, $leelawadee): void {
    if ($yahei) {
        $p->registerFontFamily('YaHei', ['regular' => $win . 'msyh.ttc#0']);
    }
    if ($gothic) {
        $p->registerFontFamily('MSGothic', ['regular' => $win . 'msgothic.ttc#0']);
    }
    if ($malgun) {
        $p->registerFontFamily('Malgun', ['regular' => $win . 'malgun.ttf']);
    }
    if ($nirmala) {
        $p->registerFontFamily('Nirmala', ['regular' => $win . 'Nirmala.ttc#0']);
    }
    if ($leelawadee) {
        $p->registerFontFamily('Leelawadee', ['regular' => $win . 'LEELAWAD.TTF']);
    }
};

// ---------------------------------------------------------------------------
echo "1. Font collections (.ttc)\n";
// ---------------------------------------------------------------------------
if ($yahei) {
    $f0 = new TtfParser($win . 'msyh.ttc#0');
    $f1 = new TtfParser($win . 'msyh.ttc#1');
    check('face #0 and #1 of msyh.ttc load as different fonts', $f0->fullName !== $f1->fullName, "{$f0->fullName} / {$f1->fullName}");
    check('face data is a standalone sfnt (not "ttcf")', str_starts_with($f0->getRawData(), "\x00\x01\x00\x00") && $f0->hasTable('glyf'));
    check('collection face count', TtcExtractor::faceCount((string) file_get_contents($win . 'msyh.ttc')) >= 2);
    check('Han glyph present, Latin glyph present', $f0->hasGlyph(0x4E2D) && $f0->hasGlyph(0x41));
    $threw = false;
    try {
        new TtfParser($win . 'msyh.ttc#99');
    } catch (RuntimeException) {
        $threw = true;
    }
    check('face index out of range throws', $threw);
    $plain = new TtfParser($win . 'msyh.ttc');
    check('no "#n" suffix selects face 0', $plain->fullName === $f0->fullName);
    $p = render('<p style="font-family:YaHei;font-size:16pt">中文测试 ABC</p>', [], $registerAll);
    $pdfData = $p->output();
    check('TTC face embedded as subset TrueType (FontFile2)', str_contains($pdfData, '/FontFile2') && str_contains($pdfData, '/FontName /'));
    check('subset of a 15 MB collection is small', strlen($pdfData) < 200000, (string) strlen($pdfData));
    check('no warnings for a registered TTC family', $p->getWarnings() === [], implode('; ', $p->getWarnings()));
} else {
    skip('TTC face loading / embedding', 'msyh.ttc not installed');
}

// ---------------------------------------------------------------------------
echo "\n2. Break classes and kinsoku (ScriptBreaker)\n";
// ---------------------------------------------------------------------------
$st = new ComputedStyle();
$pieces = ScriptBreaker::segment('日本語、「テスト」。', $st);
check('breaks between ideographs / kana', count($pieces) >= 5, implode('|', $pieces));
$noStart = true;
$noEnd = true;
foreach ($pieces as $piece) {
    $first = mb_substr($piece, 0, 1);
    $last = mb_substr($piece, -1, 1);
    if (str_contains('、。」）』】', $first)) {
        $noStart = false;
    }
    if (str_contains('「（『【', $last)) {
        $noEnd = false;
    }
}
check('no fragment starts with closing punctuation (、。」)', $noStart, implode('|', $pieces));
check('no fragment ends with an opening bracket (「)', $noEnd, implode('|', $pieces));
check('closing punctuation glued to the preceding character', in_array('語、', $pieces, true) && in_array('ト」。', $pieces, true), implode('|', $pieces));
check('opening bracket glued to the following character', in_array('「テ', $pieces, true), implode('|', $pieces));
check('Latin words stay whole inside CJK text', ScriptBreaker::segment('中文Hello世界', $st) === ['中', '文', 'Hello', '世', '界']);
$strict = new ComputedStyle();
$strict->set('line-break', 'strict');
check('line-break: strict keeps small kana / ー off line starts', in_array('ミー', ScriptBreaker::segment('ミーティング', $strict), true) && in_array('ミ', ScriptBreaker::segment('ミーティング', $st), true));
$keep = new ComputedStyle();
$keep->set('word-break', 'keep-all');
check('word-break: keep-all does not split Hangul words', ScriptBreaker::segment('한국어는 좋다', $keep) === ['한국어는 좋다'] || ScriptBreaker::segment('한국어는', $keep) === ['한국어는']);
check('word-break: normal splits Hangul between syllables', count(ScriptBreaker::segment('한국어', $st)) === 3);
$ba = new ComputedStyle();
$ba->set('word-break', 'break-all');
check('break-all splits Latin letters but keeps kinsoku', ScriptBreaker::segment('ab。', $ba) === ['a', 'b。']);
check('segment-break removal between wide characters', ScriptBreaker::joinSegmentBreaks("中文\n  测试") === '中文测试' && ScriptBreaker::joinSegmentBreaks("abc\ndef") === "abc\ndef");

// ---------------------------------------------------------------------------
echo "\n3. CJK paragraphs\n";
// ---------------------------------------------------------------------------
if ($yahei) {
    $zh = '中文测试，这是一个很长的句子，用来测试换行。「引号」（括号）。你好，世界！再来一句话，结束。';
    $p = render('<p id="p" lang="zh-CN" style="font-family:YaHei;font-size:14pt;width:150pt">' . $zh . '</p>', [], $registerAll);
    $lines = lineTexts(byId($p, 'p'));
    check('Chinese paragraph wraps without spaces into several lines', count($lines) >= 3, (string) count($lines));
    check('no characters lost or added', implode('', $lines) === $zh);
    $bad = [];
    foreach ($lines as $line) {
        $c = chars($line);
        if (str_contains('，。！」）、', $c[0]) ) {
            $bad[] = "starts with {$c[0]}: {$line}";
        }
        if (str_contains('「（', $c[count($c) - 1])) {
            $bad[] = "ends with {$c[count($c) - 1]}: {$line}";
        }
    }
    check('kinsoku: no line starts with closing / ends with opening punctuation', $bad === [], implode(' | ', $bad));
    check('lines are not separated by inserted spaces', !str_contains(implode('', $lines), ' '));

    $p = render('<p id="p" style="font-family:YaHei;font-size:14pt;width:150pt;text-align:justify;line-height:1.2">' . $zh . '</p>', [], $registerAll);
    $box = byId($p, 'p');
    $okJustify = true;
    $detail = '';
    foreach ($box->lines as $i => $line) {
        if ($i === count($box->lines) - 1) {
            continue;
        }
        $end = 0.0;
        foreach ($line->runs as $run) {
            $end = max($end, $run->x + $run->width);
        }
        if (abs($end - ($line->x + 150.0)) > 1.0) {
            $okJustify = false;
            $detail = "line {$i} ends at {$end}, line x {$line->x}";
        }
    }
    check('text-align: justify spreads CJK lines to the full measure (inter-character)', $okJustify, $detail);

    $p = render('<p id="p" style="font-family:YaHei;font-size:14pt;width:150pt">日本<b>語</b>の文章' . "\n" . 'です。</p>', [], $registerAll);
    check('newline between wide characters collapses to nothing', !str_contains(implode('', lineTexts(byId($p, 'p'))), ' '));

    // many per-character runs collapse into few text objects on non-justified lines
    $p = render('<p id="p" style="font-family:YaHei;font-size:14pt;width:300pt">' . $zh . '</p>', [], $registerAll);
    $runCount = 0;
    foreach (byId($p, 'p')->lines as $line) {
        $runCount += count($line->runs);
    }
    check('adjacent CJK fragments are merged into few runs', $runCount <= count(byId($p, 'p')->lines) * 2, (string) $runCount);
} else {
    skip('Chinese paragraph wrapping', 'msyh.ttc not installed');
}

if ($malgun) {
    $ko = '한국어는 아름답습니다 그리고 매우 재미있는 언어입니다';
    $p = render('<p id="n" style="font-family:Malgun;font-size:12pt;width:120pt">' . $ko . '</p><p id="k" style="font-family:Malgun;font-size:12pt;width:120pt;word-break:keep-all">' . $ko . '</p>', [], $registerAll);
    $normal = lineTexts(byId($p, 'n'));
    $keepAll = lineTexts(byId($p, 'k'));
    $wordSplit = false;
    foreach (explode(' ', $ko) as $w) {
        $found = false;
        foreach ($normal as $l) {
            if (str_contains($l, $w)) {
                $found = true;
            }
        }
        $wordSplit = $wordSplit || !$found;
    }
    check('Korean normal mode breaks between syllables', $wordSplit, implode(' / ', $normal));
    $keepOk = true;
    foreach (explode(' ', $ko) as $w) {
        $found = false;
        foreach ($keepAll as $l) {
            if (str_contains($l, $w)) {
                $found = true;
            }
        }
        $keepOk = $keepOk && $found;
    }
    check('Korean keep-all breaks only at spaces', $keepOk && count($keepAll) >= 2, implode(' / ', $keepAll));
} else {
    skip('Korean keep-all', 'malgun.ttf not installed');
}

// ---------------------------------------------------------------------------
echo "\n4. Script / language based font selection\n";
// ---------------------------------------------------------------------------
check('script detection', Scripts::of(0x4E2D) === 'Hani' && Scripts::of(0x3042) === 'Hira' && Scripts::of(0x30AB) === 'Kana'
    && Scripts::of(0xD55C) === 'Hang' && Scripts::of(0x0915) === 'Deva' && Scripts::of(0x0E01) === 'Thai' && Scripts::of(0x41) === 'Latn' && Scripts::of(0x3002) === 'Hani');
check('language keys', Scripts::langKey('zh-Hant-TW') === 'zh-TW' && Scripts::langKey('zh') === 'zh-CN' && Scripts::langKey('ja_JP') === 'ja');
check('font keys prefer script:lang', Scripts::fontKeys('Hani', 'ja')[0] === 'Hani:ja' && in_array('Jpan', Scripts::fontKeys('Hani', 'ja'), true));
if ($yahei && $gothic && $malgun) {
    $html = '<p lang="ja">日本語 の ひらがな</p><p lang="zh-CN">简体字</p><p lang="ko">한국어</p>';
    $p = render($html, ['scriptFonts' => ['Hani:ja' => 'MSGothic', 'Hira' => 'MSGothic', 'Hani' => 'YaHei', 'Hang' => 'Malgun']], $registerAll);
    $pdfData = $p->output();
    check('lang="ja" Han uses the Japanese font, lang="zh-CN" the Chinese one', str_contains($pdfData, 'MS-Gothic') && str_contains($pdfData, 'MicrosoftYaHei'));
    check('Hangul uses the Hang script font', str_contains($pdfData, 'MalgunGothic'));
    check('no warning when all scripts are covered', $p->getWarnings() === [], implode('; ', $p->getWarnings()));
    $p2 = render('<p lang="ja">日本語</p>', ['scriptFonts' => ['Hani' => $win . 'msgothic.ttc#0']]);
    check('scriptFonts accepts font file paths (with face index)', str_contains($p2->output(), 'MS-Gothic'));
} else {
    skip('scriptFonts selection', 'msyh/msgothic/malgun not all installed');
}
$p = render('<p>日本語 한국어 हिन्दी ไทย</p>');
$w = implode("\n", $p->getWarnings());
check('missing font warning names each script once', count($p->getWarnings()) === 4 && substr_count($w, 'glyphs for Han (CJK') === 1 && substr_count($w, 'glyphs for Hangul text') === 1 && substr_count($w, 'glyphs for Devanagari text') === 1 && substr_count($w, 'glyphs for Thai text') === 1, $w);
check('warning explains how to fix it', str_contains($w, 'registerFontFamily') && str_contains($w, 'scriptFonts'));
$p = render('<p>Привет мир, Ελληνικά, plain text</p>');
check('no warning for Latin / Cyrillic / Greek', $p->getWarnings() === [], implode('; ', $p->getWarnings()));

// ---------------------------------------------------------------------------
echo "\n5. Indic shaping\n";
// ---------------------------------------------------------------------------
$cp = static fn(string $s): array => FontManager::utf8ToCodepoints($s);
$syl = static fn(string $s): array => $cp($s);
check('Indic script detection', IndicShaper::scriptOf(0x0915) === 'deva' && IndicShaper::scriptOf(0x0995) === 'beng' && IndicShaper::scriptOf(0x0B95) === 'taml' && IndicShaper::scriptOf(0x41) === null);
if ($nirmala) {
    $fm = new FontManager(__DIR__ . '/../resources/fonts');
    $fm->registerFamily('Nirmala', ['regular' => $win . 'Nirmala.ttc#0']);
    $nir = $fm->resolveFont('Nirmala');
    $gid = static fn(int $c): int => $nir->getGlyphId($c);
    $texts = static fn(array $res): array => array_map(static fn(array $g): string => $g[4], $res['glyphs'] ?? []);

    $r = $fm->shapeWith($nir, 'कि');
    check('i-matra moves before its consonant (glyph order)', $r['glyphs'] !== null && $texts($r) === ['ि', 'क'], implode(',', $texts($r)));
    check('i-matra glyph id comes first', $r['glyphs'] !== null && $r['glyphs'][1][0] === $gid(0x0915));
    $r = $fm->shapeWith($nir, 'हिन्दी');
    check('हिन्दी: ि before ह, न्द conjunct, ी last', $r['glyphs'] !== null && $texts($r)[0] === 'ि' && $texts($r)[1] === 'ह' && last($texts($r)) !== '' && count($r['glyphs']) === 4, implode(',', $texts($r)));
    $r = $fm->shapeWith($nir, 'र्क');
    check('reph: Ra+virama before a consonant forms a reph glyph placed after the base', $r['glyphs'] !== null && count($r['glyphs']) === 2 && $texts($r) === ['क', 'र्'], implode(',', $texts($r)));
    check('reph glyph is not the plain Ra glyph', $r['glyphs'] !== null && $r['glyphs'][1][0] !== $gid(0x0930));
    $r = $fm->shapeWith($nir, 'क्षत्रिय');
    check('क्षत्रिय: conjuncts form ligature glyphs and ि is reordered', $r['glyphs'] !== null && count($r['glyphs']) < 6 && in_array('ि', $texts($r), true), implode(',', $texts($r)));
    $order = $texts($r);
    check('i-matra precedes the त्र conjunct cluster', array_search('ि', $order, true) < array_search('य', $order, true));
    $r = $fm->shapeWith($nir, 'कर्म');
    check('reph after the base of a syllable that follows another syllable', $r['glyphs'] !== null && last($texts($r)) === 'र्', implode(',', $texts($r)));
    $r = $fm->shapeWith($nir, 'र्');
    check('lone Ra+virama is not a reph (no reordering)', $r['glyphs'] === null || $texts($r) === ['र', '्'] || count($r['glyphs']) === 2);
    $r = $fm->shapeWith($nir, 'বিশ্ব');
    check('Bengali i-matra reordered', $r['glyphs'] !== null && $texts($r)[0] === 'ি', implode(',', $texts($r)));
    $r = $fm->shapeWith($nir, 'கொ');
    check('Tamil split vowel o: pre part before the consonant, post part after', $r['glyphs'] !== null && count($r['glyphs']) === 3 && $texts($r)[1] === 'க', implode(',', $texts($r)));
    $r = $fm->shapeWith($nir, 'ક્ષમા');
    check('Gujarati conjunct renders without .notdef', $r['glyphs'] !== null && !in_array(0, array_column($r['glyphs'], 0), true));
    $r = $fm->shapeWith($nir, 'English');
    check('Latin text in the same font bypasses the Indic engine', $r['glyphs'] === null || count($r['glyphs']) === 7);

    // end to end: PDF text extraction returns logical order (ActualText)
    $html = '<p style="font-family:Nirmala;font-size:20pt">हिन्दी क्षत्रिय र्क कर्म धर्म स्त्री द्वारा कि की</p>';
    $p = render($html, [], $registerAll);
    $pdfData = $p->output();
    check('Indic runs carry /ActualText with the logical text', str_contains($pdfData, '/ActualText <FEFF0939093F0928094D09260940>'));
    check('no missing-font warning when the font covers the script', $p->getWarnings() === [], implode('; ', $p->getWarnings()));

    $py = trim((string) @shell_exec('python -c "import fitz; print(1)" 2>NUL'));
    if ($py === '1') {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-asian-' . getmypid() . '.pdf';
        file_put_contents($tmp, $pdfData);
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-asian-' . getmypid() . '.py';
        file_put_contents($script, "import fitz,sys\nsys.stdout.buffer.write(''.join(p.get_text() for p in fitz.open(sys.argv[1])).replace(' ','').replace('\\n','').encode('utf-8'))\n");
        $out = (string) shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp));
        @unlink($tmp);
        @unlink($script);
        check('PyMuPDF extracts the logical Devanagari text', $out === 'हिन्दीक्षत्रियर्ककर्मधर्मस्त्रीद्वाराकिकी', $out);
    } else {
        skip('PyMuPDF logical text extraction', 'python + PyMuPDF not available');
    }
} else {
    skip('Indic shaping', 'Nirmala.ttc not installed');
}

// ---------------------------------------------------------------------------
echo "\n6. Thai\n";
// ---------------------------------------------------------------------------
$thai = 'ภาษาไทยเป็นภาษาที่มีความสวยงามมากและเขียนโดยไม่มีช่องว่างระหว่างคำ';
$thaiPieces = ScriptBreaker::segment($thai, $st);
check('Thai sentence without spaces is split into words / syllables', count($thaiPieces) >= 8, (string) count($thaiPieces));
check('no Thai fragment starts with a vowel/tone mark that must stay with the previous letter', (static function () use ($thaiPieces): bool {
    foreach ($thaiPieces as $piece) {
        $c = mb_ord(mb_substr($piece, 0, 1), 'UTF-8');
        if ($c === 0x0E31 || ($c >= 0x0E34 && $c <= 0x0E3A) || ($c >= 0x0E47 && $c <= 0x0E4E) || $c === 0x0E30 || $c === 0x0E32 || $c === 0x0E33 || $c === 0x0E46) {
            return false;
        }
    }
    return true;
})(), implode('|', $thaiPieces));
check('pieces re-join to the source text', implode('', $thaiPieces) === $thai);
if ($leelawadee) {
    $p = render('<p id="t" style="font-family:Leelawadee;font-size:16pt;width:260pt">' . $thai . ' น้ำใจ</p>', [], $registerAll);
    $lines = lineTexts(byId($p, 't'));
    check('long unspaced Thai sentence wraps onto several lines', count($lines) >= 2, (string) count($lines));
    check('Thai text preserved over the line breaks', implode('', $lines) === $thai . ' น้ำใจ' || str_replace(' ', '', implode('', $lines)) === str_replace(' ', '', $thai . ' น้ำใจ'));
    $pdfData = $p->output();
    $fm2 = new FontManager(__DIR__ . '/../resources/fonts');
    $fm2->registerFamily('Leelawadee', ['regular' => $win . 'LEELAWAD.TTF']);
    $r = $fm2->shape('น้ำ', 'Leelawadee', 'normal', 'normal');
    check('Thai mark positioning: tone mark carries a vertical offset (GPOS)', $r['glyphs'] !== null && max(array_map('abs', array_column($r['glyphs'], 3))) > 0, json_encode(array_column($r['glyphs'] ?? [], 3)));
    check('SARA AM decomposition keeps one cluster text', $r['glyphs'] !== null && implode('', array_column($r['glyphs'], 4)) === 'น้ำ', implode('|', array_column($r['glyphs'] ?? [], 4)));
} else {
    skip('Thai layout', 'LEELAWAD.TTF not installed');
}

// ---------------------------------------------------------------------------
echo "\n7. Engine hygiene\n";
// ---------------------------------------------------------------------------
$p = render('<p>Plain Latin text with fi ligatures: office AVATAR 12345</p>');
check('Latin text unaffected (no warnings)', $p->getWarnings() === []);
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
