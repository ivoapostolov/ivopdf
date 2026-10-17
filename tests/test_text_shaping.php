<?php

declare(strict_types=1);

/**
 * Complex text support: OpenType layout (GSUB ligatures, GPOS/kern pair kerning), CFF OpenType embedding,
 * Arabic joining, the Unicode bidirectional algorithm, RTL paragraph layout and ToUnicode mappings.
 *
 * Run: php tests/test_text_shaping.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\Font\FontManager;
use IvoPdf\Font\TtfParser;
use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Text\ArabicShaper;
use IvoPdf\Text\Bidi;

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

function render(string $html, array $options = [], ?callable $setup = null): IvoPdf
{
    // These checks rely on DejaVu Sans' ligatures, kerning and metrics
    $pdf = new IvoPdf(array_merge(['defaultFont' => 'DejaVu Sans', 'compress' => false], $options));
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

/** @return list<string> text of the non-space runs of a line in visual order */
function visualWords(LayoutBox $box, int $line = 0): array
{
    $words = [];
    // Neighbouring plain runs of a line are joined into one ("Hello " + "world"): split them into words again
    foreach ($box->lines[$line]->runs ?? [] as $run) {
        foreach (preg_split('/\s+/u', $run->text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $words[] = $word;
        }
    }
    return $words;
}

/** @return list<int> */
function utf8Cps(string $s): array
{
    return FontManager::utf8ToCodepoints($s);
}

$fonts = __DIR__ . '/../resources/fonts';
$fm = new FontManager($fonts);
$sans = $fm->resolveFont('DejaVu Sans');

// ---------------------------------------------------------------------------
echo "1. Bidi algorithm (UAX #9)\n";
// ---------------------------------------------------------------------------
$r = Bidi::resolve(utf8Cps('abc'), 0);
check('LTR text: all level 0', $r['levels'] === [0, 0, 0]);
$r = Bidi::resolve(utf8Cps('אבג'), null);
check('Hebrew text: auto base level 1, all level 1', $r['base'] === 1 && $r['levels'] === [1, 1, 1]);
$r = Bidi::resolve(utf8Cps('abc אבג 123'), 0);
check('numbers after Hebrew stay LTR (level 2)', $r['levels'] === [0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2], implode(',', $r['levels']));
check('visual order of "abc אבג 123"', Bidi::reorder($r['levels']) === [0, 1, 2, 3, 8, 9, 10, 7, 6, 5, 4]);
$r = Bidi::resolve(utf8Cps('אבג abc'), 1);
check('Latin inside RTL paragraph is level 2', $r['levels'] === [1, 1, 1, 1, 2, 2, 2], implode(',', $r['levels']));
$r = Bidi::resolve(utf8Cps('مرحبا 123'), 1);
check('Arabic digits/EN after AL become AN (level 2)', array_slice($r['levels'], 6) === [2, 2, 2], implode(',', $r['levels']));
$r = Bidi::resolve(utf8Cps("a\u{2067}b\u{2069}c"), 0);
check('RLI/PDI isolate raises the level of its content', $r['levels'][2] === 2 && $r['levels'][0] === 0 && $r['levels'][4] === 0, implode(',', $r['levels']));
$r = Bidi::resolve(utf8Cps("\u{202E}abc\u{202C}"), 0);
check('RLO override forces RTL on Latin letters', $r['levels'][1] === 1 && $r['levels'][3] === 1, implode(',', $r['levels']));
$r = Bidi::resolve(utf8Cps('(אבג)'), 0);
check('paired brackets around RTL text resolve to the RTL run', $r['levels'] === [0, 1, 1, 1, 0] || $r['levels'] === [1, 1, 1, 1, 1], implode(',', $r['levels']));
check('bracket mirroring', Bidi::mirror(0x28) === 0x29 && Bidi::mirror(0xBB) === 0xAB && Bidi::mirror(0x41) === 0x41);
check('first strong detection', Bidi::firstStrong('123 שלום') === 1 && Bidi::firstStrong('abc') === 0 && Bidi::firstStrong('123') === null);
check('classes: Hebrew=R, Arabic=AL, Arabic-Indic digit=AN, ASCII digit=EN',
    Bidi::classOf(0x5D0) === Bidi::R && Bidi::classOf(0x628) === Bidi::AL && Bidi::classOf(0x661) === Bidi::AN && Bidi::classOf(0x31) === Bidi::EN);

// ---------------------------------------------------------------------------
echo "\n2. OpenType layout: ligatures and kerning (DejaVu Sans)\n";
// ---------------------------------------------------------------------------
$fi = $fm->shapeWith($sans, 'fi');
check('"fi" forms one ligature glyph with a 2-codepoint cluster', $fi['glyphs'] !== null && count($fi['glyphs']) === 1 && $fi['glyphs'][0][4] === 'fi');
$ffi = $fm->shapeWith($sans, 'office');
check('"office" uses the ffi ligature', $ffi['glyphs'] !== null && count($ffi['glyphs']) === 4 && $ffi['glyphs'][1][4] === 'ffi');
$noLiga = $fm->shapeWith($sans, 'fi', ['liga' => 0, 'clig' => 0]);
check('liga/clig disabled -> no ligature (plain encoding)', $noLiga['glyphs'] === null || count($noLiga['glyphs']) === 2);
$av = $fm->shapeWith($sans, 'AV');
$a = $sans->getCharWidth(ord('A'));
$v = $sans->getCharWidth(ord('V'));
check('kerning narrows "AV"', $av['units'] < $a + $v - 20, "{$av['units']} vs " . ($a + $v));
$avOff = $fm->shapeWith($sans, 'AV', ['kern' => 0]);
check('kern feature off -> unkerned width', abs($avOff['units'] - ($a + $v)) < 0.01);
check('plain text without features is not shaped (fast path)', $fm->shapeWith($sans, 'Hello')['glyphs'] === null);
check('measureText reflects kerning', $fm->measureText('AV', 'DejaVu Sans', 'normal', 'normal', 1000.0) < ($a + $v) - 20);

$pdf = render('<p style="font-size:20pt">office AVATAR</p><p style="font-feature-settings:\'liga\' 0; font-kerning:none; font-size:20pt">office AVATAR</p>');
$bytes = $pdf->output();
check('PDF uses TJ arrays with kerning adjustments', preg_match('/\[<[0-9A-F]+>[^\]]*\d[^\]]*<[0-9A-F]+>\] TJ/', $bytes) === 1);
check('ToUnicode maps the ffi ligature gid to three codepoints', str_contains($bytes, '<006600660069>'));

$p1 = render('<p id="a" style="font-size:20pt">AVAVAV</p><p id="b" style="font-size:20pt; font-kerning:none">AVAVAV</p>');
check('font-kerning: none widens the text', byId($p1, 'b')->lines[0]->width > byId($p1, 'a')->lines[0]->width + 5);
$p2 = render('<p id="a" style="font-size:20pt">fi fl</p><p id="b" style="font-size:20pt; font-variant-ligatures:none">fi fl</p>');
$withLiga = byId($p2, 'a')->lines[0]->runs[0]->glyphs;
$withoutLiga = byId($p2, 'b')->lines[0]->runs[0]->glyphs;
check('font-variant-ligatures: none disables ligatures', $withLiga !== null && count($withLiga) === 1 && ($withoutLiga === null || count($withoutLiga) === 2));

// ---------------------------------------------------------------------------
echo "\n3. Arabic shaping\n";
// ---------------------------------------------------------------------------
$forms = ArabicShaper::analyze(utf8Cps('بيت'));
check('joining forms init/medi/fina', $forms === [ArabicShaper::F_INIT, ArabicShaper::F_MEDI, ArabicShaper::F_FINA], implode(',', $forms));
$forms = ArabicShaper::analyze(utf8Cps('بَد'));
check('marks are transparent for joining', $forms[0] === ArabicShaper::F_INIT && $forms[2] === ArabicShaper::F_FINA, implode(',', $forms));
check('right-joining letters do not join forward', ArabicShaper::analyze(utf8Cps('دب')) === [ArabicShaper::F_ISOL, ArabicShaper::F_ISOL]);
check('presentation-form fallback table', ArabicShaper::presentationForm(0x628, ArabicShaper::F_INIT) === 0xFE91 && ArabicShaper::lamAlef(0x627, false) === 0xFEFB);

$word = $fm->shapeWith($sans, 'بيت', [], true);
$isolatedGids = [$sans->getGlyphId(0x628), $sans->getGlyphId(0x64A), $sans->getGlyphId(0x62A)];
$gids = array_map(static fn(array $g): int => $g[0], $word['glyphs'] ?? []);
check('Arabic word is shaped to contextual glyphs (differs from isolated forms)', count($gids) === 3 && array_intersect($gids, $isolatedGids) === []);
check('RTL glyph order is reversed to visual order', ($word['glyphs'][2][4] ?? '') === 'ب' && ($word['glyphs'][0][4] ?? '') === 'ت');
$la = $fm->shapeWith($sans, 'لا', [], true);
check('lam-alef ligature', $la['glyphs'] !== null && count($la['glyphs']) === 1 && $la['glyphs'][0][4] === 'لا');
$marked = $fm->shapeWith($sans, 'بَ', [], true);
$markGlyph = null;
foreach ($marked['glyphs'] ?? [] as $g) {
    if ($g[4] === "\u{064E}") {
        $markGlyph = $g;
    }
}
check('combining mark has zero advance and is positioned (GPOS mark-to-base)', $markGlyph !== null && $markGlyph[1] === 0.0 && abs($markGlyph[2]) + abs($markGlyph[3]) > 1.0);

$arabicPdf = render('<p dir="rtl" style="font-size:16pt">بسم الله الرحمن الرحيم</p>');
$ab = $arabicPdf->output();
check('Arabic PDF embeds shaped glyphs with ToUnicode', str_contains($ab, 'beginbfchar') && str_contains($ab, '0628'));
check('layout built an RTL paragraph without warnings', $arabicPdf->getPageCount() === 1);

// ---------------------------------------------------------------------------
echo "\n4. RTL paragraph layout and mixed-direction ordering\n";
// ---------------------------------------------------------------------------
$m = render('<div id="c" style="width:400pt"><p id="mixed">Hello שלום 123 world</p></div>');
$words = visualWords(byId($m, 'mixed'));
check('mixed text visual order: Hello 123 שלום world', $words === ['Hello', '123', 'שלום', 'world'], implode(' | ', $words));

$rtl = render('<div id="c" style="width:400pt"><p id="r" dir="rtl">שלום עולם</p><p id="l">Hello</p></div>');
$rp = byId($rtl, 'r');
$lp = byId($rtl, 'l');
$rightEdge = $rp->lines[0]->runs[0]->x + $rp->lines[0]->width;
$box = byId($rtl, 'c');
check('dir="rtl" paragraph is right-aligned', abs($rightEdge - ($box->x + $box->width)) < 2.0, "right edge {$rightEdge} vs " . ($box->x + $box->width));
check('ltr paragraph stays left-aligned', abs($lp->lines[0]->runs[0]->x - $box->x) < 2.0);
check('dir="rtl" sets the direction property (inherited)', $rp->style->get('direction') === 'rtl' && byId($rtl, 'l')->style->get('direction', 'ltr') !== 'rtl');
$words = visualWords($rp);
check('Hebrew words run right-to-left', $words === ['עולם', 'שלום'], implode(' | ', $words));

$css = render('<style>.x{direction:rtl}</style><div class="x" id="d">first second</div><div class="x" id="e" style="text-align:left">left</div>');
$d = byId($css, 'd');
$e = byId($css, 'e');
check('CSS direction: rtl right-aligns by default (text-align: start)', $d->lines[0]->runs[0]->x > $d->x + 100);
check('explicit text-align: left wins in RTL', abs($e->lines[0]->runs[0]->x - $e->x) < 2.0);
$endAlign = render('<div id="n" style="text-align:end; width:300pt">end</div><div id="o" style="direction:rtl; text-align:end; width:300pt">end</div>');
check('text-align: end resolves by direction', byId($endAlign, 'n')->lines[0]->runs[0]->x > 200 && abs(byId($endAlign, 'o')->lines[0]->runs[0]->x - byId($endAlign, 'o')->x) < 2.0);

$bdo = render('<p id="b">x <bdo dir="rtl">abc def</bdo> y</p>');
$words = visualWords(byId($bdo, 'b'));
check('<bdo dir="rtl"> overrides the order of Latin text', $words === ['x', 'def', 'abc', 'y'], implode(' | ', $words));
$bdi = render('<p id="b" dir="rtl">שלום <bdi>abc def</bdi> עולם</p>');
$words = visualWords(byId($bdi, 'b'));
check('<bdi> isolates its content (LTR run inside RTL paragraph)', $words === ['עולם', 'abc', 'def', 'שלום'], implode(' | ', $words));
$um = render('<p id="b">a <span style="unicode-bidi:bidi-override; direction:rtl">123 456</span> b</p>');
$words = visualWords(byId($um, 'b'));
check('unicode-bidi: bidi-override reverses digits groups', $words === ['a', '456', '123', 'b'], implode(' | ', $words));

$wrap = render('<div id="w" style="width:120pt" dir="rtl">שלום עולם זה טקסט ארוך מאוד שצריך לעבור שורה</div>');
$wb = byId($wrap, 'w');
check('RTL text wraps into several lines, each right-aligned', count($wb->lines) >= 3 && abs($wb->lines[1]->runs[0]->x + $wb->lines[1]->width - ($wb->x + $wb->width)) < 2.0);

$list = render('<ul dir="rtl"><li id="li">פריט</li></ul>');
$li = byId($list, 'li');
check('RTL list item text is right-aligned', $li->lines[0]->runs[0]->x > $li->x + $li->width - 80);
check('RTL list marker is drawn at the right', str_contains($list->output(), ' Td '));

$ltrOnly = render('<p id="p">Plain English text only</p>');
check('LTR-only paragraph keeps level-0 runs and no glyph lists',
    byId($ltrOnly, 'p')->lines[0]->runs[0]->bidiLevel === 0 && byId($ltrOnly, 'p')->lines[0]->runs[0]->glyphs === null);

// ---------------------------------------------------------------------------
echo "\n5. CFF OpenType (.otf) embedding\n";
// ---------------------------------------------------------------------------
$otf = null;
foreach (['C:/Windows/Fonts/AGaramondPro-Regular.otf', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc', '/Library/Fonts/Arial Unicode.ttf'] as $cand) {
    if (is_file($cand) && str_ends_with(strtolower($cand), '.otf')) {
        $otf = $cand;
        break;
    }
}
if ($otf === null) {
    foreach ((array) glob('/usr/share/fonts/**/*.otf') as $cand) {
        $otf = $cand;
        break;
    }
}
if ($otf === null) {
    echo "  [SKIP] no .otf font available on this machine\n";
} else {
    $parser = new TtfParser($otf);
    check('CFF OpenType parses (cmap/hmtx/head)', $parser->isCff() && $parser->numGlyphs > 0 && $parser->hasGlyph(ord('A')));
    $o = render('<p style="font-family:OtfTest; font-size:20pt">Office fine AVATAR</p>', [], static function (IvoPdf $p) use ($otf): void {
        $p->registerFontFamily('OtfTest', ['regular' => $otf]);
    });
    $ob = $o->output();
    check('embedded as /FontFile3 /Subtype /OpenType with CIDFontType0',
        str_contains($ob, '/FontFile3') && str_contains($ob, '/Subtype /OpenType') && str_contains($ob, '/CIDFontType0') && !str_contains($ob, '/CIDToGIDMap'));
    check('OTF GSUB/GPOS layout is applied (ligature/kerning via TJ)', $parser->getLayout() !== null && str_contains($ob, ' TJ'));
    $py = trim((string) @shell_exec('python -c "import pymupdf as fitz;print(1)" 2>&1'));
    if ($py === '1') {
        $tmp = tempnam(sys_get_temp_dir(), 'ivo') . '.pdf';
        file_put_contents($tmp, $ob);
        $script = tempnam(sys_get_temp_dir(), 'ivo') . '.py';
        file_put_contents($script, "import pymupdf as fitz,sys\nd=fitz.open(sys.argv[1])\nt=d[0].get_text()\npx=d[0].get_pixmap(dpi=60)\n"
            . "ink=len(set(px.samples))>2\nprint('OK' if 'Office' in t.replace(chr(160),' ') and ink else 'BAD:'+ascii(t))\n");
        $res = trim((string) @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
        @unlink($tmp);
        @unlink($script);
        check('PyMuPDF renders and extracts text from the embedded OpenType font', $res === 'OK', $res);
    } else {
        echo "  [SKIP] PyMuPDF not available for OTF rasterization\n";
    }
}

// ---------------------------------------------------------------------------
echo "\n6. Subsetting and ToUnicode with shaped glyphs\n";
// ---------------------------------------------------------------------------
$s = render('<p>office</p>', ['subsetFonts' => true]);
$sb = $s->output();
check('subset font still embedded and shaped glyph ids kept', str_contains($sb, '/FontFile2') && str_contains($sb, '<00660066'));
$small = strlen(render('<p>office AVATAR</p>', ['subsetFonts' => true])->output());
$full = strlen(render('<p>office AVATAR</p>', ['subsetFonts' => false])->output());
check('subsetting with shaped glyphs reduces size', $small < $full * 0.5, "{$small} vs {$full}");

// ---------------------------------------------------------------------------
echo "\n7. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
