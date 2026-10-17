<?php

declare(strict_types=1);

/**
 * Regression tests for the sixth audit (differential probes against Microsoft Edge, links, shared styles):
 *  - browser defaults: line-height normal from the font metrics, body margin 8px (0 through the mPDF API), lists,
 *    figure, th, table border-spacing / caption / vertical-align, pre / blockquote / hr, fieldset / legend, forms
 *  - small-caps /ActualText, position: fixed in the page area, absolute boxes and their containing block,
 *    position: relative on inline elements, forced breaks keep the next box's margin
 *  - floats met in the middle of a paragraph are placed at their line (CSS 2.1 9.5.1)
 *  - SVG: width="100%", inherited font, <a href> inside the SVG; RTL tables; links (/QuadPoints, floated image
 *    in a link, empty <a name>); .ico images
 *  - shared (read-only) table-cell styles never reach a code path that modifies them
 *
 * Run: php tests/test_audit_probes6.php   (PyMuPDF is used for the PDF-side checks; they are skipped without it)
 */

require_once __DIR__ . '/../autoload.php';

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

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIP] {$label}\n";
}

function render(string $html, array $options = []): IvoPdf
{
    $pdf = new IvoPdf(array_merge(['compress' => false, 'defaultFont' => 'DejaVu Sans', 'basePath' => sys_get_temp_dir()], $options));
    $pdf->loadHtml($html)->output();
    return $pdf;
}

/**
 * Per page: words [x0, y0, x1, y1, text], spans [x, baseline, size, font, text], links [x0, y0, x1, y1, uri, page, y],
 * images [x0, y0, x1, y1]; null without PyMuPDF.
 *
 * @return list<array{w: list<array>, s: list<array>, l: list<array>, i: list<array>, h: float}>|null
 */
function inspect(IvoPdf $pdf): ?array
{
    static $script = null;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $file = $dir . 'ivopdf-probes6-' . md5(uniqid('', true)) . '.pdf';
    file_put_contents($file, $pdf->output());
    if ($script === null) {
        $script = $dir . 'ivopdf-probes6.py';
        file_put_contents($script, <<<'PY'
import fitz, json, sys
d = fitz.open(sys.argv[1])
out = []
for p in d:
    spans = []
    for b in p.get_text('rawdict')['blocks']:
        for l in b.get('lines', []):
            for s in l['spans']:
                if s['chars']:
                    spans.append([s['chars'][0]['origin'][0], s['chars'][0]['origin'][1], s['size'], s['font'], ''.join(c['c'] for c in s['chars'])])
    links = [[l['from'].x0, l['from'].y0, l['from'].x1, l['from'].y1, l.get('uri') or '', l.get('page', -1), (l['to'].y if l.get('to') is not None else None)] for l in p.get_links()]
    imgs = [list(i['bbox']) for i in p.get_image_info()]
    out.append({'w': [list(w[:5]) for w in p.get_text('words')], 's': spans, 'l': links, 'i': imgs, 'h': p.rect.height})
print(json.dumps(out))
PY);
    }
    $json = @shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    @unlink($file);
    if (!is_string($json) || $json === '' || $json[0] !== '[') {
        return null;
    }
    $pages = json_decode($json, true);
    return is_array($pages) ? $pages : null;
}

/** First word (or span with $spans) on $page starting with $prefix, or null. */
function word(?array $pages, string $prefix, int $page = 0, bool $spans = false): ?array
{
    foreach ($pages[$page][$spans ? 's' : 'w'] ?? [] as $w) {
        if (str_starts_with((string) $w[4], $prefix)) {
            return $w;
        }
    }
    return null;
}

function findById(LayoutBox $box, string $id): ?LayoutBox
{
    if ($box->domElement?->getAttribute('id') === $id) {
        return $box;
    }
    foreach ($box->children as $c) {
        $f = findById($c, $id);
        if ($f !== null) {
            return $f;
        }
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null && ($f = findById($run->inlineBox, $id)) !== null) {
                return $f;
            }
        }
    }
    return null;
}

function box(IvoPdf $pdf, string $id): ?LayoutBox
{
    return findById($pdf->getLayoutTree(), $id);
}

$fmt = static fn(?LayoutBox $b): string => $b === null ? 'missing' : sprintf('x=%.2f y=%.2f w=%.2f h=%.2f', $b->x, $b->y, $b->width, $b->height);

// ---------------------------------------------------------------------------
echo "1. Browser defaults: line-height normal, body margin, lists, figure, pre / blockquote / hr\n";
// ---------------------------------------------------------------------------
$lh = render('<style>body{font-size:11pt}</style><p id="a">x<br>y<br>z</p><p id="b" style="font-family:Inter">x<br>y</p><p id="c" style="line-height:1.5">x<br>y</p>');
$a = box($lh, 'a');
check('line-height: normal for DejaVu Sans 11pt is 12.75pt (ascent + descent rounded to px, like Edge)', $a !== null && abs($a->height - 3 * 12.75) < 0.05, $fmt($a));
$b = box($lh, 'b');
check('line-height: normal follows the font: Inter (USE_TYPO_METRICS) gives 1.21', $b !== null && abs($b->height / 2 - 13.5) < 0.05, $fmt($b));
check('an explicit line-height still wins', ($c = box($lh, 'c')) !== null && abs($c->height - 2 * 16.5) < 0.05, $fmt($c));
$p1 = box($lh, 'a');
check('body has the browser margin of 8px (6pt), its top margin collapses with the first paragraph', $p1 !== null && abs($p1->x - 42.0) < 0.01 && abs($p1->y - 47.0) < 0.01, $fmt($p1));
$mp = new IvoPdf(['compress' => false, 'defaultFont' => 'DejaVu Sans']);
$mp->WriteHTML('<p id="m" style="margin:0">mPDF</p>');
$mp->output();
check('documents written through the mPDF API keep mPDF\'s body margin of 0', ($m = box($mp, 'm')) !== null && abs($m->x - $mp->getLayoutTree()->x) < 0.01 && abs($m->x - 36.0) < 0.01, $fmt($m ?? null));
$own = render('<style>body{margin:20pt}</style><p id="o" style="margin:0">x</p>');
check('an author body margin replaces the default', ($o = box($own, 'o')) !== null && abs($o->x - 56.0) < 0.01 && abs($o->y - 56.0) < 0.01, $fmt($o));

$lists = render('<ul id="u1"><li id="l1">a<ul id="u2"><li id="l2">b<ul id="u3"><li id="l3">c</li></ul></li></ul></li></ul><ol id="o1"><li>x</li></ol>');
$u1 = box($lists, 'u1');
$u2 = box($lists, 'u2');
check('lists: padding-inline-start 40px (30pt), margin 1em', $u1 !== null && abs($u1->paddingLeft - 30.0) < 0.01 && abs($u1->marginTop - 12.0) < 0.01, $fmt($u1));
check('a nested list has no vertical margins', $u2 !== null && $u2->marginTop === 0.0 && $u2->marginBottom === 0.0);
check('bullets by depth: disc, circle, square', box($lists, 'l1')?->listMarker === '•' && box($lists, 'l2')?->listMarker === '◦' && box($lists, 'l3')?->listMarker === '▪',
    implode(',', [box($lists, 'l1')?->listMarker, box($lists, 'l2')?->listMarker, box($lists, 'l3')?->listMarker]));
check('list items have no extra margin', box($lists, 'l1')?->marginBottom === 0.0);
$fig = render('<figure id="f"><figcaption id="fc">cap</figcaption></figure><blockquote id="bq">q</blockquote><pre id="pr">p</pre><hr id="hr">');
check('figure: margin 1em 40px', ($f = box($fig, 'f')) !== null && abs($f->marginLeft - 30.0) < 0.01 && abs($f->marginRight - 30.0) < 0.01, $fmt($f));
check('figcaption keeps the inherited font size', ($fc = box($fig, 'fc')) !== null && abs($fc->fontSizePt - 12.0) < 0.01);
check('blockquote: margin 1em 40px, no border', ($bq = box($fig, 'bq')) !== null && abs($bq->marginLeft - 30.0) < 0.01 && $bq->borderLeft === 0.0);
check('pre: monospace, white-space: pre, no padding or background', ($pr = box($fig, 'pr')) !== null && $pr->paddingLeft === 0.0 && $pr->style->get('white-space') === 'pre'
    && str_contains($pr->style->get('font-family'), 'Mono') && $pr->style->get('background-color') === 'transparent');
check('hr: 1px inset border on every side, margin 0.5em', ($hr = box($fig, 'hr')) !== null && abs($hr->height - 1.5) < 0.01 && abs($hr->marginTop - 6.0) < 0.01, $fmt($hr));
$ws = render('<input id="i1" value="a"> <input id="i2" value="b">');
$i1 = box($ws, 'i1');
$i2 = box($ws, 'i2');
check('white space between inline boxes directly in <body> is a space', $i1 !== null && $i2 !== null && $i2->x - ($i1->x + $i1->width) > 3.0, $fmt($i1) . ' / ' . $fmt($i2));

// ---------------------------------------------------------------------------
echo "\n2. Tables: th, vertical-align, caption, border-spacing, border attribute, rtl\n";
// ---------------------------------------------------------------------------
$t = render('<table id="t" border="1"><caption id="cap" style="caption-side:bottom">Qcaption</caption><tr><th id="th">H</th><td id="td" style="height:60pt;vertical-align:bottom"><span id="tdtext">Qbottom</span></td></tr></table>');
$pages = inspect($t);
$tb = box($t, 't');
check('th is centred by default', box($t, 'th')?->style->get('text-align') === 'center');
check('tables default to border-collapse: separate with border-spacing 2px', $tb !== null && $tb->style->get('border-collapse') === 'separate' && $tb->style->get('border-spacing') === '1.5pt');
check('table[border]: outset table border, inset cell borders', $tb?->style->get('border-top-style') === 'outset' && box($t, 'td')?->style->get('border-top-style') === 'inset');
$cap = box($t, 'cap');
check('the caption is outside the table border (below it)', $cap !== null && $tb !== null && $cap->y >= $tb->y + $tb->height - $cap->height - 0.01 && $tb->captionBottom > 0.0, $fmt($cap) . ' table ' . $fmt($tb));
check('a caption never breaks inside a word: its min-content widens the table', $pages === null || (word($pages, 'Qcaption') !== null), json_encode(array_column($pages[0]['w'] ?? [], 4)));
if ($pages !== null) {
    $q = word($pages, 'Qbottom');
    $tdb = box($t, 'td');
    check('vertical-align: bottom in a cell with an explicit height', $q !== null && $tdb !== null && $q[3] > $tdb->y + $tdb->height - 16.0, json_encode($q) . ' ' . $fmt($tdb));
} else {
    skip('vertical-align: bottom');
}
$cs0 = render('<table id="z" cellspacing="0" border="1"><tr><td>a</td></tr></table>');
check('cellspacing="0" removes the border spacing', box($cs0, 'z')?->style->get('border-spacing') === '0px');
$rtl = render('<table dir="rtl" border="1"><tr><td id="r1">Qr1</td><td id="r2">Qr2</td></tr></table>');
check('dir=rtl table: the first cell is the rightmost one', ($r1 = box($rtl, 'r1')) !== null && ($r2 = box($rtl, 'r2')) !== null && $r1->x > $r2->x, $fmt($r1 ?? null) . ' ' . $fmt($r2 ?? null));

// ---------------------------------------------------------------------------
echo "\n3. Text: small-caps /ActualText, relative inline offsets, fixed / absolute positioning, forced breaks\n";
// ---------------------------------------------------------------------------
$sc = render('<p style="font-variant:small-caps">Qsmall Caps</p>');
$pages = inspect($sc);
if ($pages !== null) {
    $texts = implode(' ', array_column($pages[0]['w'], 4));
    check('synthesized small capitals extract as the original text', str_contains($texts, 'Qsmall') && str_contains($texts, 'Caps') && !str_contains($texts, 'SMALL'), $texts);
} else {
    skip('small-caps extraction');
}
$rel = render('<p><span id="s0">a</span> <span id="sr" style="position:relative;top:10pt;left:5pt">b</span></p>');
$runY = static function (IvoPdf $pdf, string $text): ?array {
    $stack = [$pdf->getLayoutTree()];
    while ($stack) {
        $b = array_pop($stack);
        foreach ($b->lines as $l) {
            foreach ($l->runs as $r) {
                if ($r->text === $text) {
                    return [$r->x, $r->y];
                }
            }
        }
        array_push($stack, ...$b->children);
    }
    return null;
};
$ra = $runY($rel, 'a');
$rb = $runY($rel, 'b');
check('position: relative on an inline element moves its text', $ra !== null && $rb !== null && abs($rb[1] - $ra[1] - 10.0) < 0.01, json_encode([$ra, $rb]));
$fixed = render('<div id="ff" style="position:fixed;bottom:-20pt;left:0;right:0;text-align:center">Qfooter</div>' . str_repeat('<p>Qpara text</p>', 80));
$pages = inspect($fixed);
$ff = box($fixed, 'ff');
check('position: fixed is placed in the page area (bottom:-20pt reaches into the bottom margin)', $ff !== null && abs($ff->y + $ff->height - (841.89 - 36.0 + 20.0)) < 0.05 && abs($ff->x - 36.0) < 0.01, $fmt($ff));
if ($pages !== null) {
    $onAll = count(array_filter($pages, static fn(array $p): bool => word([$p], 'Qfooter') !== null));
    check('... and drawn on every page', count($pages) > 1 && $onAll === count($pages), "{$onAll} of " . count($pages));
} else {
    skip('fixed footer on every page');
}
$mf = new IvoPdf(['compress' => false]);
$mf->WriteHTML('<div id="mfx" style="position:fixed;top:0;left:0">x</div><p>y</p>');
$mf->output();
check('mPDF documents keep position: fixed relative to the sheet', ($mfx = box($mf, 'mfx')) !== null && $mfx->x === 0.0 && $mfx->y === 0.0, $fmt($mfx ?? null));
$abs = render('<div id="cb" style="position:relative;margin-left:50pt;border:2pt solid"><div style="margin-left:40pt">x<span id="ab" style="position:absolute;left:0;top:0">ABS</span></div></div>'
    . '<div id="icb" style="position:absolute;top:100pt;left:100pt">I</div>');
$cb = box($abs, 'cb');
$ab = box($abs, 'ab');
check('position: absolute uses the padding box of the nearest positioned ancestor', $cb !== null && $ab !== null && abs($ab->x - ($cb->x + 2.0)) < 0.01 && abs($ab->y - ($cb->y + 2.0)) < 0.01, $fmt($ab) . ' cb ' . $fmt($cb));
check('without a positioned ancestor: the page area (initial containing block), not the body', ($icb = box($abs, 'icb')) !== null && abs($icb->x - 136.0) < 0.01 && abs($icb->y - 136.0) < 0.01, $fmt($icb ?? null));
$brk = render('<p>one</p><p id="two" style="page-break-before:always">two</p><div style="page-break-before:always"></div><h2 id="h">three</h2>');
$two = box($brk, 'two');
$h = box($brk, 'h');
$printable = 841.89 - 72.0; // pages follow each other in the layout coordinates (page n starts at 36 + n * printable)
check('a forced break keeps the top margin of the box after it', $two !== null && abs($two->y - (36.0 + $printable + 12.0)) < 0.05, $fmt($two));
check('... also after an empty break carrier (the next box\'s margin)', $h !== null && abs($h->y - (36.0 + 2 * $printable + 0.83 * 18.0)) < 0.05, $fmt($h));
$first = render('<div style="page-break-before:always"><h2>A</h2></div><p>b</p>');
check('a forced break at the very start of the document makes no blank page', $first->getPageCount() === 1, (string) $first->getPageCount());
$pn = render('<style>.pn::after{content:"Page " counter(page) " of " counter(pages)}</style><div id="pn" class="pn" style="position:fixed;bottom:0;right:0"></div><p>x</p>');
check('page-number placeholders are laid out one digit wide (a right-aligned number stays at the edge)', ($pnb = box($pn, 'pn')) !== null && abs($pnb->x + $pnb->width - 559.28) < 0.05 && $pnb->width < 80.0, $fmt($pnb ?? null));

// ---------------------------------------------------------------------------
echo "\n4. Floats in the middle of a paragraph (CSS 2.1 9.5.1)\n";
// ---------------------------------------------------------------------------
$long = 'alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron pi rho sigma tau upsilon phi chi psi omega ';
$fr = render('<p id="p" style="width:300pt">' . $long . '<span id="fl" style="float:right;width:80pt;height:40pt">F</span> after the float the text goes on beside it for a while and then below it again when the float ends here, '
    . 'and these last words fill one more line under the float completely so the full width shows again at the end.</p>');
$p = box($fr, 'p');
$fl = box($fr, 'fl');
$lineAt = static function (LayoutBox $p, float $y): ?\IvoPdf\Layout\LineBox {
    foreach ($p->lines as $l) {
        if ($y >= $l->y - 0.01 && $y < $l->y + $l->height - 0.01) {
            return $l;
        }
    }
    return null;
};
if ($p !== null && $fl !== null && count($p->lines) >= 4) {
    $hostLine = $lineAt($p, $fl->y);
    check('a float after several lines starts at the top of a line, not below the paragraph', $hostLine !== null && $fl->y < $p->y + $p->height - 20.0 && abs($fl->y - $hostLine->y) < 0.01, $fmt($fl));
    check('... at the right edge', abs($fl->x + $fl->width - ($p->x + 300.0)) < 0.01, $fmt($fl));
    $besides = array_filter($p->lines, static fn($l): bool => $l->y >= $fl->y - 0.01 && $l->y < $fl->y + $fl->height - 0.01);
    $narrow = array_filter($besides, static fn($l): bool => $l->x + $l->width <= $fl->x + 0.5);
    check('lines beside the float are wrapped around it', count($besides) >= 2 && count($narrow) === count($besides), count($narrow) . ' of ' . count($besides));
    $below = array_filter($p->lines, static fn($l): bool => $l->y >= $fl->y + $fl->height - 0.01);
    $wide = array_filter($below, static fn($l): bool => $l->x + $l->width > $fl->x + 1.0);
    check('lines below the float use the full width again', $below !== [] && $wide !== [], count($wide) . ' of ' . count($below));
    $before = array_filter($p->lines, static fn($l): bool => $l->y < $fl->y - 0.01);
    check('lines before the float keep the full width', count($before) >= 2);
} else {
    check('mid-paragraph float layout', false, $fmt($p) . ' ' . $fmt($fl));
}
$fit = render('<p id="p" style="width:300pt">Start short <b id="fb" style="float:left;width:60pt">F</b>rest of the text that wraps around the floated box nicely and continues.</p>');
$p = box($fit, 'p');
$fb = box($fit, 'fb');
check('a float that fits beside the current line goes to the top of that line, which moves beside it', $p !== null && $fb !== null && abs($fb->y - $p->y) < 0.01 && abs($p->lines[0]->x - ($p->x + 60.0)) < 0.01,
    $fmt($fb) . ' line0 x=' . ($p?->lines[0]->x ?? '?'));
$fi = render('<p id="p" style="width:300pt">' . $long . '<img id="im" src="ivopdf-probes6.png" style="float:left;width:60pt;height:40pt"> text continues beside the image for several words more words to wrap around the image box end.</p>');
$im = box($fi, 'im');
$p = box($fi, 'p');
check('a floated image in the middle of a paragraph is placed at its line', $im !== null && $p !== null && $im->y > $p->y + 10.0 && $im->y < $p->y + $p->height - 20.0 && abs($im->x - $p->x) < 0.01, $fmt($im));

// ---------------------------------------------------------------------------
echo "\n5. Forms, fieldset, SVG\n";
// ---------------------------------------------------------------------------
$form = render('<input id="ph" placeholder="Qplaceholder"> <textarea id="ta">Qta</textarea>');
$pages = inspect($form);
if ($pages !== null) {
    check('an empty input shows its placeholder (page content, greyed)', word($pages, 'Qplaceholder') !== null, json_encode(array_column($pages[0]['w'], 4)));
} else {
    skip('placeholder');
}
$flat = render('<input placeholder="Qflat">', ['formFields' => false]);
$pages = inspect($flat);
if ($pages !== null) {
    check('... also in print view (no form fields)', word($pages, 'Qflat') !== null);
}
check('the field of an input with a placeholder has an opaque /MK /BG (hides the placeholder once filled)', str_contains($form->output(), '/MK << /BG [1.000 1.000 1.000] >>'));
$ph = box($form, 'ph');
$ta = box($form, 'ta');
check('default text input: 132.75pt wide (Chrome: 20 characters of 13.33px Arial)', $ph !== null && abs($ph->width - 132.75) < 0.01, $fmt($ph));
check('controls use Arial metrics (Liberation Sans), a textarea a monospace font', $ph?->style->get('font-family') === 'Liberation Sans' && str_contains((string) $ta?->style->get('font-family'), 'Mono'));
check('a textarea sits on the baseline with its bottom edge', $ta !== null && abs((float) $ta->formBaseline - $ta->height) < 0.01);
$fs = render('<fieldset id="fs"><legend id="lg">Qlegend</legend><p id="fp" style="margin:0">body</p></fieldset>');
$fsb = box($fs, 'fs');
$lg = box($fs, 'lg');
check('fieldset: the legend sits in the top border, the content below it', $fsb !== null && $lg !== null && abs($lg->y - $fsb->y) < 0.01 && box($fs, 'fp')->y >= $lg->y + $lg->height - 0.01 && $fsb->legendGap !== null, $fmt($lg));
$pdfBytes = $fs->output();
check('fieldset border gets a gap behind the legend (even-odd clip)', str_contains($pdfBytes, 're W* n'));
$svg = render('<div style="width:300pt"><svg id="sv" width="100%" height="50" viewBox="0 0 200 50"><text x="10" y="30">Qpct</text></svg></div>'
    . '<svg width="200" height="40"><a href="https://example.com/svg"><text x="10" y="25">Qsvglink</text></a></svg>');
$pages = inspect($svg);
check('<svg width="100%"> takes the width of its container', ($sv = box($svg, 'sv')) !== null && abs($sv->width - 300.0) < 0.01, $fmt($sv ?? null));
if ($pages !== null) {
    $q = word($pages, 'Qpct', 0, true);
    check('... and its viewBox content is scaled into it (centred, meet)', $q !== null && abs($q[0] - (42.0 + 75.0 + 7.5)) < 0.1, json_encode($q));
    $sl = word($pages, 'Qsvglink', 0, true);
    check('inline SVG text inherits the CSS font size (12pt here)', $sl !== null && abs($sl[2] - 12.0) < 0.01, json_encode($sl));
    $lk = $pages[0]['l'][0] ?? null;
    check('<a href> inside SVG markup is a link over its content', $lk !== null && $lk[4] === 'https://example.com/svg' && $sl !== null && $lk[0] <= $sl[0] + 0.5 && $lk[2] > $sl[0] + 30.0, json_encode($lk));
} else {
    skip('svg checks');
}

// ---------------------------------------------------------------------------
echo "\n6. Links and images\n";
// ---------------------------------------------------------------------------
$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf-probes6.png';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(40, 20);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
    imagepng($im, $png);
}
$wrapped = render('<p style="width:120pt"><a href="https://example.com/ml">a link that spans several lines of text in a narrow box</a></p>');
$bytes = $wrapped->output();
$pages = inspect($wrapped);
check('a link wrapped over several lines is one /Link per line', substr_count($bytes, '/Subtype /Link') >= 3 && !str_contains($bytes, '/QuadPoints'), substr_count($bytes, '/Subtype /Link') . ' links');
if ($pages !== null) {
    $l = $pages[0]['l'][0] ?? null;
    check('... and each /Rect covers one line only', $l !== null && $l[3] - $l[1] < 20.0, json_encode($l));
}
$fimg = render('<p><a href="https://example.com/f"><img src="ivopdf-probes6.png" style="float:right;width:60pt"></a>Text beside a linked floated image.</p>');
$pages = inspect($fimg);
if ($pages !== null) {
    $l = $pages[0]['l'][0] ?? null;
    $img = $pages[0]['i'][0] ?? null;
    check('a floated <img> inside <a href> gets a link rectangle over the image', $l !== null && $img !== null && abs($l[0] - $img[0]) < 0.5 && abs($l[2] - $img[2]) < 0.5 && abs($l[1] - $img[1]) < 0.5, json_encode([$l, $img]));
} else {
    skip('floated image link');
}
$anchor = render('<p><a href="#n">go</a></p><div style="page-break-before:always;padding:40pt;border:1pt solid"><a name="n"></a></div>');
$pages = inspect($anchor);
if ($pages !== null) {
    $lk = $pages[0]['l'][0] ?? null;
    $to = $lk !== null && $lk[5] === 1 ? $lk[6] : null; // fitz: target page and y from the page top
    check('an empty <a name> without text or block siblings targets the start of its line (content box), not the padding edge', $to !== null && abs($to - (36.0 + 0.75 + 40.0)) < 1.0, (string) $to);
} else {
    skip('empty anchor');
}
// .ico: PNG-compressed, 32-bit BMP with alpha, 8-bit BMP with an AND mask
$icoDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$bmp8 = static function (): string {
    // 4x4, 8 bpp, palette: 0 = red, 1 = blue; the left half is transparent through the AND mask
    $w = 4;
    $h = 4;
    $hdr = pack('VVVvvVVVVVV', 40, $w, $h * 2, 1, 8, 0, 0, 0, 0, 2, 0);
    $pal = "\x00\x00\xFF\x00" . "\xFF\x00\x00\x00";
    $xor = str_repeat("\x00\x01\x01\x00", $h);
    $and = str_repeat("\xC0\x00\x00\x00", $h); // 1 bits = transparent: the two left pixels
    $dib = $hdr . $pal . $xor . $and;
    return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $w, $h, 2, 0, 1, 8, strlen($dib), 22) . $dib;
};
file_put_contents($icoDir . 'ivopdf-probes6-8.ico', $bmp8());
$bmp32 = static function (): string {
    $w = 2;
    $h = 2;
    $hdr = pack('VVVvvVVVVVV', 40, $w, $h * 2, 1, 32, 0, 0, 0, 0, 0, 0);
    $xor = str_repeat("\x00\xFF\x00\x80", $w * $h); // green, alpha 128
    $and = str_repeat("\x00\x00\x00\x00", $h);
    $dib = $hdr . $xor . $and;
    return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $w, $h, 0, 0, 1, 32, strlen($dib), 22) . $dib;
};
file_put_contents($icoDir . 'ivopdf-probes6-32.ico', $bmp32());
if (is_file($png)) {
    $pngData = (string) file_get_contents($png);
    file_put_contents($icoDir . 'ivopdf-probes6-png.ico', pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 40, 20, 0, 0, 1, 32, strlen($pngData), 22) . $pngData);
}
$loader = new \IvoPdf\Image\ImageLoader(sys_get_temp_dir(), false, new \IvoPdf\Options());
$i8 = $loader->load('ivopdf-probes6-8.ico');
check('.ico with an 8-bit BMP image: decoded, AND mask as transparency', $i8 !== null && $i8['width'] === 4 && $i8['height'] === 4 && isset($i8['smask'])
    && substr((string) gzuncompress($i8['smask']), 0, 4) === "\x00\x00\xFF\xFF" && substr((string) gzuncompress($i8['data']), 3, 3) === "\x00\x00\xFF", json_encode($i8 === null ? null : [$i8['width'], $i8['height'], isset($i8['smask'])]));
$i32 = $loader->load('ivopdf-probes6-32.ico');
check('.ico with a 32-bit BMP image keeps its alpha channel', $i32 !== null && $i32['width'] === 2 && isset($i32['smask']) && gzuncompress($i32['smask']) === str_repeat("\x80", 4));
if (is_file($png)) {
    $ip = $loader->load('ivopdf-probes6-png.ico');
    check('.ico with a PNG image', $ip !== null && $ip['width'] === 40 && $ip['height'] === 20);
}
$drawn = render('<img id="ico" src="ivopdf-probes6-8.ico" style="width:20pt">');
check('an .ico is drawn by <img>', ($ib = box($drawn, 'ico')) !== null && abs($ib->width - 20.0) < 0.01 && ($drawn->getWarnings() === []), implode(' | ', $drawn->getWarnings()));

// ---------------------------------------------------------------------------
echo "\n7. Shared table-cell styles reach no code path that modifies them\n";
// ---------------------------------------------------------------------------
$cells = static fn(string $cell, int $n = 30): string => '<table>' . str_repeat('<tr>' . $cell . $cell . '</tr>', $n) . '</table>';
$cases = [
    '::first-line on a td' => ['<style>td::first-line{color:red;font-weight:bold}</style>' . $cells('<td>first line text in a cell</td>'), []],
    '::first-letter on a td' => ['<style>td::first-letter{font-size:20pt;float:left}</style>' . $cells('<td>Drop cap cell</td>'), []],
    'initial-letter on a td' => ['<style>td::first-letter{initial-letter:2}</style>' . $cells('<td>Initial letter</td>'), []],
    'synthesized small-caps in a cell' => [$cells('<td style="font-variant:small-caps">Small caps text</td>'), []],
    'small-caps from a td rule' => ['<style>td{font-variant:all-small-caps}</style>' . $cells('<td>All small caps</td>'), []],
    'font fallback in a cell' => [$cells('<td>Mixed 中文 текст</td>'), []],
    'footnotes in a table' => ['<style>.fn{float:footnote}</style>' . $cells('<td>Cell<span class="fn">note in a cell</span></td>', 10), []],
    'footnote-display inline from a td' => ['<style>.fn{float:footnote;footnote-display:inline}</style>' . $cells('<td><span class="fn">n</span>x</td>', 5), []],
    'running element from a td' => ['<style>@page{@top-center{content:element(hdr)}} td.h{position:running(hdr)}</style><table><tr><td class="h">Header cell</td><td>b</td></tr></table>' . $cells('<td>body</td>'), []],
    'string-set from a td' => ['<style>@page{@top-left{content:string(s)}} td{string-set:s content()}</style>' . $cells('<td>value</td>'), []],
    'mPDF tags inside tables' => [$cells('<td><barcode code="12345" type="C128A" /><dottab /><pagebreak /></td>', 4), []],
    'mPDF annotation and bookmark in a cell' => [$cells('<td><bookmark content="B" /><annotation content="note" />x</td>', 4), []],
    'forms in cells' => [$cells('<td><input placeholder="p"><textarea>t</textarea><select><option>o</option></select></td>'), []],
    'lists with inside markers in cells' => ['<style>ul{list-style-position:inside}</style>' . $cells('<td><ul><li>item</li></ul></td>', 10), []],
    'relative span in cells' => [$cells('<td><span style="position:relative;top:2pt">r</span></td>'), []],
    'absolute box in a positioned cell' => [$cells('<td style="position:relative"><span style="position:absolute;right:0">a</span>x</td>'), []],
    'float in a cell paragraph' => [$cells('<td>long text <span style="float:right">F</span> more text after the float</td>'), []],
    'shrink-to-fit table with shared cells' => ['<table style="width:100%">' . str_repeat('<tr>' . str_repeat('<td>wide content word</td>', 14) . '</tr>', 20) . '</table>', []],
    'named page widths with tables' => ['<style>@page wide{size:A4 landscape} .w{page:wide}</style><div class="w">' . $cells('<td>landscape</td>') . '</div>' . $cells('<td>portrait</td>'), []],
    'fieldset and legend in cells' => [$cells('<td><fieldset><legend>L</legend>x</fieldset></td>', 5), []],
    'mPDF API: tables, pagebreak and headers' => ['<htmlpageheader name="H"><table><tr><td>H</td></tr></table></htmlpageheader><sethtmlpageheader name="H" value="on" show-this-page="1" />'
        . $cells('<td>x</td>', 40) . '<pagebreak orientation="L" />' . $cells('<td>y</td>', 10), ['mpdf' => true]],
];
foreach ($cases as $label => [$html, $opt]) {
    try {
        if (!empty($opt['mpdf'])) {
            $m = new IvoPdf(['compress' => false]);
            $m->WriteHTML($html);
            $out = $m->output();
        } else {
            $out = render($html)->output();
        }
        check($label . ': renders', str_starts_with($out, '%PDF-'));
    } catch (\Throwable $e) {
        check($label . ': renders', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ---------------------------------------------------------------------------
echo "\n";
// A shrink-to-fit box sized to its max-content must not wrap: max-content is measured word by word like the
// line layout (Inter kerns across a space, e.g. ". 0", so the whole-string width was ~0.5pt too small)
foreach (['0', '0.5pt'] as $lsp) {
    $fit = render('<div style="display:flex"><div id="fit" style="font-family:Inter;font-size:19pt;font-weight:bold;letter-spacing:' . $lsp . '">INVOICE No. 0000104829</div></div>');
    $fitBox = findById($fit->getLayoutTree(), 'fit');
    check("flex item sized to its text stays on one line (letter-spacing {$lsp})", $fitBox !== null && count($fitBox->lines) === 1, $fitBox ? count($fitBox->lines) . ' lines' : 'missing');
}

// position: relative inline content is painted after the rest of the line (CSS painting order), as in Edge:
// it overlaps "after" and still extracts as its own word
$rel = inspect(render('<div style="height:40pt">Left <span style="position:relative;left:10pt">TARGET</span> after</div>'));
if ($rel !== null) {
    $words = array_map(static fn(array $w): string => $w[4], $rel[0]['w']);
    check('relatively positioned span extracts as its own word, painted last', $words === ['Left', 'after', 'TARGET'], implode(' ', $words));
}

check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ===\n";
exit($failed > 0 ? 1 : 0);
