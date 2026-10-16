<?php

declare(strict_types=1);

/**
 * Tests for interactive PDF forms (AcroForm): HTML form controls become fields with widget annotations,
 * appearance streams and /AcroForm entries; formFields=false renders static drawings only.
 *
 * Run: php tests/test_forms.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;

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

/**
 * @param array<string, mixed> $options
 */
function renderForm(string $html, array $options = []): string
{
    $pdf = new IvoPdf($options);
    $pdf->loadHtml($html);
    $pdf->render();
    return $pdf->output();
}

/**
 * @return array<int, string> objectId => dictionary/body text
 */
function parseObjects(string $pdf): array
{
    preg_match_all('/(\d+) 0 obj\n(.*?)\nendobj/s', $pdf, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $row) {
        $out[(int) $row[1]] = $row[2];
    }
    return $out;
}

/**
 * Widget annotation dictionaries (merged field widgets and radio kids), keyed by object id.
 *
 * @param array<int, string> $objs
 * @return array<int, string>
 */
function widgets(array $objs): array
{
    return array_filter($objs, static fn(string $b): bool => str_contains($b, '/Subtype /Widget'));
}

/**
 * @param array<int, string> $objs
 * @return array<string, string> field name => dict (merged widgets and radio parents)
 */
function fieldsByName(array $objs): array
{
    $out = [];
    foreach ($objs as $body) {
        if (preg_match('~/FT /(Tx|Btn|Ch)~', $body) === 1 && preg_match('~/T \(([^)]*)\)~', $body, $m) === 1) {
            $out[$m[1]] = $body;
        }
    }
    return $out;
}

function hexUtf16(string $s): string
{
    return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
}

$FORM = <<<'HTML'
<html><body>
<form action="https://example.com/submit" method="post">
  <input type="text" name="name" value="Иван Петров" required maxlength="40" title="Your name">
  <input type="email" name="email" placeholder="you@example.com">
  <input type="password" name="pw" value="secret">
  <input type="number" name="age" value="30" readonly>
  <input type="tel" name="phone" disabled value="123">
  <input type="checkbox" name="agree" checked>
  <input type="checkbox" name="news">
  <input type="radio" name="g" value="m" checked> <input type="radio" name="g" value="f">
  <select name="country"><option value="bg">България</option><option value="de" selected>Germany</option></select>
  <select name="langs" multiple><option>PHP</option><option selected>JS</option><option selected>Go</option></select>
  <textarea name="msg" rows="3" cols="30">Line one
Ред две</textarea>
  <input type="submit" value="Send">
  <input type="reset" value="Clear">
  <button type="button" name="plain">Plain</button>
  <input type="text" name="dup" value="a"><input type="text" name="dup" value="b">
</form>
<form action="/relative"><button name="rel">Rel</button></form>
</body></html>
HTML;

echo "== Field types and values ==\n";
$pdf = renderForm($FORM);
$objs = parseObjects($pdf);
$f = fieldsByName($objs);

check('PDF renders', str_starts_with($pdf, '%PDF-'));
check('Exactly one /AcroForm in the catalog', substr_count($pdf, '/AcroForm') === 1);
check('/AcroForm has /Fields, /DA and /DR with Helv', preg_match('~/AcroForm.*?/Fields \[[^\]]+\]~s', $pdf) === 1
    && str_contains($pdf, '/DA (/Helv 0 Tf 0 g)') && str_contains($pdf, '/BaseFont /Helvetica'));

$t = $f['name'] ?? '';
check('Text field: /FT /Tx with name', str_contains($t, '/FT /Tx') && str_contains($t, '/Subtype /Widget'));
check('Text field: Cyrillic value as UTF-16', str_contains($t, '/V ' . hexUtf16('Иван Петров')), $t);
check('Text field: /MaxLen 40', str_contains($t, '/MaxLen 40'));
check('Text field: required flag (bit 2) set', preg_match('~/Ff (\d+)~', $t, $m) === 1 && ((int) $m[1] & 2) === 2);
check('Text field: title becomes /TU', str_contains($t, '/TU (Your name)'));
check('Text field: appearance stream /AP /N', preg_match('~/AP << /N \d+ 0 R >>~', $t) === 1);
check('Text field: Cyrillic value switches /DA to the embedded font', preg_match('~/DA \(/F\d+ ~', $t) === 1, $t);

$e = $f['email'] ?? '';
check('E-mail: empty value, placeholder as /TU', str_contains($e, '/V ()') && str_contains($e, '/TU (you@example.com)'));
$p = $f['pw'] ?? '';
check('Password flag (bit 14)', preg_match('~/Ff (\d+)~', $p, $m) === 1 && ((int) $m[1] & 8192) === 8192);
$a = $f['age'] ?? '';
check('Readonly flag (bit 1)', preg_match('~/Ff (\d+)~', $a, $m) === 1 && ((int) $m[1] & 1) === 1);
$ph = $f['phone'] ?? '';
check('Disabled -> read-only bit', preg_match('~/Ff (\d+)~', $ph, $m) === 1 && ((int) $m[1] & 1) === 1);

$cb = $f['agree'] ?? '';
check('Checkbox checked: /AS /Yes, /V /Yes', str_contains($cb, '/FT /Btn') && str_contains($cb, '/AS /Yes') && str_contains($cb, '/V /Yes'));
check('Checkbox has /Yes and /Off appearance states', preg_match('~/AP << /N << /Yes \d+ 0 R /Off \d+ 0 R >> >>~', $cb) === 1);
$cb2 = $f['news'] ?? '';
check('Checkbox unchecked: /AS /Off', str_contains($cb2, '/AS /Off') && str_contains($cb2, '/V /Off'));

$rg = $f['g'] ?? '';
check('Radio group: one parent field, two kids', preg_match('~/Kids \[(\d+ 0 R) (\d+ 0 R)\]~', $rg) === 1, $rg);
check('Radio group: radio flags + /V /m', preg_match('~/Ff (\d+)~', $rg, $m) === 1 && ((int) $m[1] & 49152) === 49152 && str_contains($rg, '/V /m'));
$radioKids = array_filter($objs, static fn(string $b): bool => str_contains($b, '/Parent') && str_contains($b, '/Subtype /Widget'));
check('Radio kids: states m (on) and f (off)', count($radioKids) === 2
    && count(array_filter($radioKids, static fn(string $b): bool => str_contains($b, '/AS /m'))) === 1
    && count(array_filter($radioKids, static fn(string $b): bool => str_contains($b, '/AS /Off'))) === 1);

$sel = $f['country'] ?? '';
check('Select: combo box with /Opt and /V', str_contains($sel, '/FT /Ch') && str_contains($sel, '/V (de)')
    && str_contains($sel, '/Opt [[(bg) ' . hexUtf16('България') . '] [(de) (Germany)]]'), $sel);
check('Select: combo flag (bit 18)', preg_match('~/Ff (\d+)~', $sel, $m) === 1 && ((int) $m[1] & 131072) === 131072);
$ml = $f['langs'] ?? '';
check('Select multiple: list box, multi-select flag, two values', preg_match('~/Ff (\d+)~', $ml, $m) === 1
    && ((int) $m[1] & 131072) === 0 && ((int) $m[1] & 2097152) !== 0 && str_contains($ml, '/V [(JS) (Go)]') && str_contains($ml, '/I [1 2]'), $ml);

$ta = $f['msg'] ?? '';
check('Textarea: multiline flag (bit 13) and value with CR', preg_match('~/Ff (\d+)~', $ta, $m) === 1
    && ((int) $m[1] & 4096) === 4096 && str_contains($ta, '/V ' . hexUtf16("Line one\rРед две")), $ta);

$sub = '';
foreach ($objs as $b) {
    if (str_contains($b, '/CA (Send)')) {
        $sub = $b;
    }
}
check('Submit button: push button + caption', str_contains($sub, '/FT /Btn') && preg_match('~/Ff (\d+)~', $sub, $m) === 1 && ((int) $m[1] & 65536) !== 0);
check('Submit button: SubmitForm to http(s) action, POST, HTML flag', str_contains($sub, '/S /SubmitForm') && str_contains($sub, '/F (https://example.com/submit)') && str_contains($sub, '/Flags 4 '), $sub);
$reset = '';
$plain = '';
$rel = '';
foreach ($objs as $b) {
    if (str_contains($b, '/CA (Clear)')) {
        $reset = $b;
    }
    if (str_contains($b, '/CA (Plain)')) {
        $plain = $b;
    }
    if (str_contains($b, '/CA (Rel)')) {
        $rel = $b;
    }
}
check('Reset button: /ResetForm', str_contains($reset, '/S /ResetForm'));
check('Plain button: no action', $plain !== '' && !str_contains($plain, '/A '));
check('Submit with non-http action: no SubmitForm', $rel !== '' && !str_contains($rel, 'SubmitForm'));

echo "\n== Names ==\n";
check('Duplicate names get unique suffix', isset($f['dup']) && isset($f['dup_2']) && str_contains($f['dup'], '/V (a)') && str_contains($f['dup_2'], '/V (b)'));
$names = array_keys($f);
check('All field names unique', count($names) === count(array_unique($names)));

echo "\n== Page structure ==\n";
$pageDicts = array_filter($objs, static fn(string $b): bool => str_contains($b, '/Type /Page ') && str_contains($b, '/MediaBox'));
$page = reset($pageDicts) ?: '';
preg_match('~/Annots \[([^\]]+)\]~', $page, $am);
$annotIds = isset($am[1]) ? array_map('intval', explode(' R', rtrim($am[1]))) : [];
check('Page /Annots lists widgets', count(array_filter($annotIds)) >= count(widgets($objs)) - 0 && count(widgets($objs)) >= 17, 'widgets=' . count(widgets($objs)));
check('Widgets are print-visible and reference the page', count(array_filter(widgets($objs), static fn(string $b): bool => str_contains($b, '/F 4 ') && str_contains($b, '/P '))) === count(widgets($objs)));
check('Appearance streams are Form XObjects with BBox', count(array_filter($objs, static fn(string $b): bool => str_contains($b, '/Subtype /Form') && str_contains($b, '/BBox'))) >= 10);

echo "\n== Layout: controls are replaced boxes ==\n";
$pdfObj = new IvoPdf();
$pdfObj->loadHtml('<p>Pick <select name="s"><option>Option text A</option><option>B</option></select> and <textarea name="t">Hidden body text</textarea> <input type="text" value="v" style="width:200px;height:30px;background:#eef">.</p>');
$pdfObj->render();
$tree = $pdfObj->getLayoutTree();
$controls = [];
$walk = static function ($box) use (&$walk, &$controls): void {
    if ($box->formControl !== null) {
        $controls[spl_object_id($box)] = $box;
    }
    foreach ($box->children as $c) {
        $walk($c);
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null) {
                $walk($run->inlineBox);
            }
        }
    }
};
$walk($tree);
$controls = array_values($controls);
check('Three form control boxes in layout', count($controls) === 3, (string) count($controls));
check('Controls have no child boxes (option/textarea text not rendered as text)', count(array_filter($controls, static fn($b): bool => $b->children !== [])) === 0);
$styled = $controls[2] ?? null;
check('CSS width/height apply to controls', $styled !== null && abs($styled->width - 157.5) < 0.5 && abs($styled->height - 28.0) < 0.5, $styled ? "{$styled->width}x{$styled->height}" : 'none');
$plainInput = (new IvoPdf());
$plainInput->loadHtml('<input type="text"><input type="checkbox"><button>Go</button>');
$plainInput->render();
$ctrls = [];
$walk2 = static function ($box) use (&$walk2, &$ctrls): void {
    if ($box->formControl !== null) {
        $ctrls[spl_object_id($box)] = $box;
    }
    foreach ($box->children as $c) {
        $walk2($c);
    }
    foreach ($box->lines as $line) {
        foreach ($line->runs as $run) {
            if ($run->inlineBox !== null) {
                $walk2($run->inlineBox);
            }
        }
    }
};
$walk2($plainInput->getLayoutTree());
$ctrls = array_values($ctrls);
check('Default text input ~150pt wide', isset($ctrls[0]) && abs($ctrls[0]->width - 150.0) < 1.0 && $ctrls[0]->height > 14.0 && $ctrls[0]->height < 22.0);
check('Default checkbox is 10pt square', isset($ctrls[1]) && abs($ctrls[1]->width - 10.0) < 0.1 && abs($ctrls[1]->height - 10.0) < 0.1);
check('Button shrinks to its caption', isset($ctrls[2]) && $ctrls[2]->width > 15.0 && $ctrls[2]->width < 60.0);

echo "\n== formFields=false (print view) ==\n";
$flat = renderForm($FORM, ['formFields' => false]);
check('No AcroForm / widgets in print view', !str_contains($flat, '/AcroForm') && !str_contains($flat, '/Widget'));
check('Controls are still drawn (page content present)', strlen($flat) > 3000);
$mpdf = new IvoPdf();
$mpdf->useActiveForms = false;
$mpdf->loadHtml($FORM);
$mpdf->render();
check('mPDF alias useActiveForms=false disables fields', !str_contains($mpdf->output(), '/AcroForm') && $mpdf->useActiveForms === false);
$viaOpt = renderForm($FORM, ['useActiveForms' => false]);
check('useActiveForms option key maps to formFields', !str_contains($viaOpt, '/AcroForm'));

echo "\n== Two pages ==\n";
$long = '<html><body><input type="text" name="first" value="p1"><div style="height:1500pt"></div><input type="text" name="second" value="p2"><input type="checkbox" name="chk" checked></body></html>';
$pdf2 = renderForm($long);
$o2 = parseObjects($pdf2);
$pages = array_filter($o2, static fn(string $b): bool => str_contains($b, '/Type /Page ') && str_contains($b, '/MediaBox'));
ksort($pages);
$pages = array_values($pages);
check('Document has multiple pages', count($pages) >= 2, (string) count($pages));
$f2 = fieldsByName($o2);
$idOf = static function (string $name) use ($o2, $f2): int {
    foreach ($o2 as $id => $b) {
        if ($b === ($f2[$name] ?? null)) {
            return $id;
        }
    }
    return -1;
};
$annotsOf = static function (string $pageDict): array {
    if (preg_match('~/Annots \[([^\]]*)\]~', $pageDict, $m) !== 1) {
        return [];
    }
    preg_match_all('~(\d+) 0 R~', $m[1], $ids);
    return array_map('intval', $ids[1]);
};
$last = count($pages) - 1;
check('First field is on page 1', in_array($idOf('first'), $annotsOf($pages[0]), true));
check('Second field and checkbox are on a later page, not page 1', !in_array($idOf('second'), $annotsOf($pages[0]), true)
    && in_array($idOf('second'), $annotsOf($pages[$last]), true) && in_array($idOf('chk'), $annotsOf($pages[$last]), true));
check('Single /AcroForm /Fields has all three', preg_match('~/Fields \[(\d+ 0 R ){2}\d+ 0 R\]~', $pdf2) === 1);

echo "\n== Optional: PyMuPDF validation ==\n";
$py = trim((string) @shell_exec('python --version 2>&1'));
if (str_starts_with($py, 'Python')) {
    $tmpPdf = tempnam(sys_get_temp_dir(), 'frm') . '.pdf';
    $tmpPy = tempnam(sys_get_temp_dir(), 'frm') . '.py';
    file_put_contents($tmpPdf, $pdf);
    file_put_contents($tmpPy, <<<'PY'
import fitz, sys, json
try:
    d = fitz.open(sys.argv[1])
except Exception:
    print("{}"); sys.exit(0)
out = {}
for p in d:
    for w in p.widgets():
        out.setdefault(w.field_name, []).append([w.field_type_string, w.field_value])
print(json.dumps(out, ensure_ascii=True))
PY);
    $json = (string) @shell_exec('python -I ' . escapeshellarg($tmpPy) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1');
    @unlink($tmpPdf);
    @unlink($tmpPy);
    $w = json_decode(trim($json), true);
    if (is_array($w) && $w !== []) {
        check('PyMuPDF reads all widgets', count($w) >= 14, (string) count($w));
        check('PyMuPDF: Cyrillic text value', ($w['name'][0][1] ?? '') === 'Иван Петров');
        check('PyMuPDF: field types', ($w['agree'][0][0] ?? '') === 'CheckBox' && ($w['g'][0][0] ?? '') === 'RadioButton' && count($w['g'] ?? []) === 2 && ($w['country'][0][0] ?? '') === 'ComboBox' && ($w['msg'][0][0] ?? '') === 'Text');
        check('PyMuPDF: select value and radio value', ($w['country'][0][1] ?? '') === 'de' && ($w['g'][0][1] ?? '') === 'm');
    } else {
        echo "  [SKIP] PyMuPDF not available\n";
    }
} else {
    echo "  [SKIP] python not available\n";
}

echo "\n== PHP diagnostics ==\n";
check('No PHP warnings/notices/deprecations', $phpIssues === [], implode('; ', array_slice($phpIssues, 0, 3)));

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
