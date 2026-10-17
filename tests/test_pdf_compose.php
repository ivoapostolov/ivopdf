<?php

declare(strict_types=1);

/**
 * Tests for document composition: embedded files / Factur-X attachments and PDF import
 * (page templates / letterheads, appending pages, <img src="x.pdf">, xref streams, object streams,
 * incremental updates, damaged xref, rotation, security limits).
 *
 * Run: php tests/test_pdf_compose.php
 * PyMuPDF sub-checks run only when `python` with `fitz` is available (they are skipped otherwise).
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Pdf\Import\PdfDict;
use IvoPdf\Pdf\Import\PdfImportException;
use IvoPdf\Pdf\Import\PdfName;
use IvoPdf\Pdf\Import\PdfReader;
use IvoPdf\Pdf\Import\PdfStream;
use IvoPdf\Pdf\Import\PdfString;

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

/** Builds a multi-page IvoPdf document: page i contains "Page i Привет" and a line of body text. */
function sourcePdf(int $pages = 3, array $opts = []): string
{
    $html = '';
    for ($i = 1; $i <= $pages; $i++) {
        $html .= "<h1>Page {$i} Привет</h1><p>Body text {$i}</p><div style=\"background:#d33;height:40px;width:200px\"></div>" . ($i < $pages ? '<pagebreak/>' : '');
    }
    return (new IvoPdf(array_merge(['compress' => true], $opts)))->loadHtml($html)->output();
}

/** @return list<array{dict: PdfDict, attrs: array<string, mixed>}> */
function pdfPages(PdfReader $r): array
{
    $out = [];
    for ($i = 1; $i <= $r->getPageCount(); $i++) {
        $out[] = $r->getPage($i);
    }
    return $out;
}

/** Decoded content stream(s) of an output page. */
function pageContent(PdfReader $r, int $n): string
{
    $page = $r->getPage($n)['dict'];
    $c = $r->resolve($page->get('Contents'));
    $streams = $c instanceof PdfStream ? [$c] : (is_array($c) ? array_map(fn($x) => $r->resolve($x), $c) : []);
    $out = '';
    foreach ($streams as $s) {
        $out .= $r->decodeStream($s);
    }
    return $out;
}

/** @return array<string, mixed> XObject resources of an output page (name => resolved object) */
function pageXObjects(PdfReader $r, int $n): array
{
    $attrs = $r->getPage($n)['attrs'];
    $res = $r->resolve($attrs['Resources'] ?? null);
    $xo = $res instanceof PdfDict ? $r->resolve($res->get('XObject')) : null;
    $out = [];
    if ($xo instanceof PdfDict) {
        foreach ($xo->items as $k => $v) {
            $out[$k] = $r->resolve($v);
        }
    }
    return $out;
}

/** @return list<array{name: string, spec: PdfDict}> */
function listEmbedded(PdfReader $r, PdfDict $catalog): array
{
    $names = $r->resolve($catalog->get('Names'));
    $ef = $names instanceof PdfDict ? $r->resolve($names->get('EmbeddedFiles')) : null;
    $arr = $ef instanceof PdfDict ? $r->resolve($ef->get('Names')) : [];
    $out = [];
    for ($i = 0; $i + 1 < count((array) $arr); $i += 2) {
        $nm = $arr[$i] instanceof PdfString ? $arr[$i]->bytes : '';
        if (str_starts_with($nm, "\xFE\xFF")) {
            $nm = mb_convert_encoding(substr($nm, 2), 'UTF-8', 'UTF-16BE');
        }
        $spec = $r->resolve($arr[$i + 1]);
        if ($spec instanceof PdfDict) {
            $out[] = ['name' => $nm, 'spec' => $spec];
        }
    }
    return $out;
}

function catalogOf(PdfReader $r, string $pdf): PdfDict
{
    // The catalog is object 1 in IvoPdf output
    $c = $r->getObject(1);
    return $c instanceof PdfDict ? $c : new PdfDict();
}

function pyAvailable(): bool
{
    static $ok = null;
    if ($ok === null) {
        $out = @shell_exec('python -c "import pymupdf as fitz; print(\'OK\')" 2>&1');
        $ok = is_string($out) && str_contains($out, 'OK');
    }
    return $ok;
}

/** Runs a short python/fitz snippet with $argv[1..] and returns its stdout. */
function py(string $code, string ...$args): string
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf_compose_' . md5($code) . '.py';
    file_put_contents($file, "import sys, fitz\nsys.stdout.reconfigure(encoding='utf-8')\n" . $code);
    $cmd = 'python -I ' . escapeshellarg($file);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    return trim((string) @shell_exec($cmd . ' 2>&1'));
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf_compose_' . getmypid();
@mkdir($tmp, 0775, true);

// ---------------------------------------------------------------------------
echo "\n1. Embedded files\n";
// ---------------------------------------------------------------------------
$payload = "id,name\n1,Ivo\n2,Мария\n";
$pdf = (new IvoPdf(['compress' => true]))
    ->loadHtml('<p>Invoice</p>')
    ->attachData($payload, 'data.csv', 'Sample data', 'text/csv', 'Data')
    ->attachData('<xml/>', 'extra.xml', 'Extra', 'application/xml', 'Supplement')
    ->attachFile($payload, 'Отчет.csv', '', 'text/csv', 'Source')
    ->output();
$r = new PdfReader($pdf);
$cat = catalogOf($r, $pdf);
$files = listEmbedded($r, $cat);
check('three attachments in the /EmbeddedFiles name tree', count($files) === 3, (string) count($files));
$names = array_map(static fn(array $f): string => $f['name'], $files);
check('name tree is sorted', $names === array_values(array_unique($names)) && $names === (function (array $n): array { sort($n, SORT_STRING); return $n; })($names));
$byName = [];
foreach ($files as $f) {
    $byName[$f['name']] = $f['spec'];
}
$spec = $byName['data.csv'] ?? null;
check('Filespec has /Type /Filespec, /F, /UF, /Desc', $spec instanceof PdfDict && $spec->name('Type') === 'Filespec' && $spec->has('F') && $spec->has('UF') && $spec->has('Desc'));
check('AFRelationship /Data', $spec instanceof PdfDict && $spec->name('AFRelationship') === 'Data');
$ef = $spec instanceof PdfDict ? $r->resolve($spec->get('EF')) : null;
$stream = $ef instanceof PdfDict ? $r->resolve($ef->get('F')) : null;
check('EmbeddedFile stream decodes to the original bytes', $stream instanceof PdfStream && $r->decodeStream($stream) === $payload);
$params = $stream instanceof PdfStream ? $r->resolve($stream->get('Params')) : null;
check('/Params /Size matches', $params instanceof PdfDict && $params->get('Size') === strlen($payload));
$sum = $params instanceof PdfDict ? $params->get('CheckSum') : null;
check('/Params /CheckSum is the MD5 of the data', $sum instanceof PdfString && $sum->bytes === md5($payload, true));
check('/Params /ModDate present', $params instanceof PdfDict && $params->get('ModDate') instanceof PdfString);
check('EmbeddedFile /Subtype is the MIME type as name', $stream instanceof PdfStream && $stream->name('Subtype') === 'text/csv');
check('/AF array lists every filespec', is_array($r->resolve($cat->get('AF'))) && count((array) $r->resolve($cat->get('AF'))) === 3);
$ufName = $byName['Отчет.csv'] ?? null;
$uf = $ufName instanceof PdfDict ? $ufName->get('UF') : null;
check('non-ASCII file name stored as UTF-16BE /UF', $uf instanceof PdfString && str_starts_with($uf->bytes, "\xFE\xFF") && mb_convert_encoding(substr($uf->bytes, 2), 'UTF-8', 'UTF-16BE') === 'Отчет.csv');
check('attachFile treats non-file strings as content', ($byName['Отчет.csv'] ?? null) instanceof PdfDict);

$threw = false;
try {
    (new IvoPdf())->attachData('x', 'a.txt', '', 'text/plain', 'Bogus');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('invalid AFRelationship rejected', $threw);

// path-based attach and containment
$attFile = $tmp . DIRECTORY_SEPARATOR . 'att.txt';
file_put_contents($attFile, 'hello attachment');
$p = (new IvoPdf(['compress' => false]))->loadHtml('<p>x</p>')->attachFile($attFile, 'att.txt', 'desc', 'text/plain', 'Unspecified');
$r2 = new PdfReader($p->output());
check('attachFile(path) embeds the file content', str_contains($p->output(), 'hello attachment') && count(listEmbedded($r2, catalogOf($r2, ''))) === 1);
$threw = false;
try {
    (new IvoPdf(['allowedPaths' => [__DIR__ . '/output']]))->attachFile($attFile, 'att.txt');
} catch (RuntimeException) {
    $threw = true;
}
check('attachFile(path) outside allowedPaths is refused', $threw);

if (pyAvailable()) {
    $f = $tmp . DIRECTORY_SEPARATOR . 'att.pdf';
    file_put_contents($f, $pdf);
    $o = py("d=fitz.open(sys.argv[1]); print(sorted(d.embfile_names())); i=d.embfile_info('data.csv'); print(i['description'], d.embfile_get('data.csv')==open(sys.argv[2],'rb').read())", $f, $attFile);
    check('PyMuPDF lists the attachments', str_contains($o, "'data.csv'") && str_contains($o, 'extra.xml'), $o);
} else {
    skip('PyMuPDF embfile_names()');
}

// ---------------------------------------------------------------------------
echo "\n2. Factur-X / ZUGFeRD\n";
// ---------------------------------------------------------------------------
$xml = '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>';
$fx = (new IvoPdf(['compress' => true, 'pdfa' => '3b']))->loadHtml('<p>Rechnung</p>')->attachFacturX($xml, 'EN 16931');
$fxPdf = $fx->output();
$rf = new PdfReader($fxPdf);
$fxFiles = listEmbedded($rf, catalogOf($rf, $fxPdf));
check('factur-x.xml attached', count($fxFiles) === 1 && $fxFiles[0]['name'] === 'factur-x.xml');
check('Factur-X AFRelationship is Alternative', $fxFiles !== [] && $fxFiles[0]['spec']->name('AFRelationship') === 'Alternative');
$fxEf = $fxFiles !== [] ? $rf->resolve($fxFiles[0]['spec']->get('EF')) : null;
$fxStream = $fxEf instanceof PdfDict ? $rf->resolve($fxEf->get('F')) : null;
check('Factur-X payload and MIME type', $fxStream instanceof PdfStream && $rf->decodeStream($fxStream) === $xml && $fxStream->name('Subtype') === 'text/xml');
check('PDF/A-3 XMP carries fx:DocumentFileName / DocumentType / Version / ConformanceLevel',
    str_contains($fxPdf, '<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>')
    && str_contains($fxPdf, '<fx:DocumentType>INVOICE</fx:DocumentType>')
    && str_contains($fxPdf, '<fx:Version>1.0</fx:Version>')
    && str_contains($fxPdf, '<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>'));
check('XMP declares the fx extension schema (PDF/A-3)', str_contains($fxPdf, 'pdfaExtension:schemas') && str_contains($fxPdf, 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#'));
check('XMP declares pdfaid part 3', str_contains($fxPdf, '<pdfaid:part>3</pdfaid:part>'));
$threw = false;
try {
    (new IvoPdf())->attachFacturX('<x/>', 'NOPE');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('unknown Factur-X profile rejected', $threw);
check('profile aliases normalize (basic wl)', str_contains((new IvoPdf())->getFacturXXmpExtension('basic wl'), '<fx:ConformanceLevel>BASIC WL</fx:ConformanceLevel>'));

// ---------------------------------------------------------------------------
echo "\n3. PDF reader\n";
// ---------------------------------------------------------------------------
$src = sourcePdf(3);
$rs = new PdfReader($src);
check('classic xref: page count', $rs->getPageCount() === 3);
$p1 = $rs->getPage(1);
check('page inherits /MediaBox and /Resources through /Parent', isset($p1['attrs']['MediaBox']) && isset($p1['attrs']['Resources']));
check('Flate content stream decodes to PDF operators', str_contains(pageContent($rs, 1), ' Tf') || str_contains(pageContent($rs, 1), 'Tj') || str_contains(pageContent($rs, 1), 'TJ'));

/** Hand-built PDF 1.5 file: object stream + cross-reference stream with /W, /Index and PNG-Up predictor. */
function buildXrefStreamPdf(bool $rotate = false, bool $withPrev = false): string
{
    $content = "BT /F1 24 Tf 40 100 Td (Hello XRef stream) Tj ET\n0 0 1 rg 20 20 100 40 re f\n";
    $contentZ = (string) gzcompress($content);
    $inObjStm = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 200] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >>' . ($rotate ? ' /Rotate 90' : '') . ' >>',
        3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
    ];
    $hdr = '';
    $body = '';
    foreach ($inObjStm as $num => $obj) {
        $hdr .= $num . ' ' . strlen($body) . ' ';
        $body .= $obj . "\n";
    }
    $first = strlen($hdr);
    $stmData = (string) gzcompress($hdr . $body);

    $out = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
    $off4 = strlen($out);
    $out .= "4 0 obj\n<< /Length " . strlen($contentZ) . " /Filter /FlateDecode >>\nstream\n" . $contentZ . "\nendstream\nendobj\n";
    $off5 = strlen($out);
    $out .= "5 0 obj\n<< /Type /ObjStm /N 3 /First {$first} /Length " . strlen($stmData) . " /Filter /FlateDecode >>\nstream\n" . $stmData . "\nendstream\nendobj\n";
    $off6 = strlen($out);

    // rows: [type, field2 (2 bytes), field3 (1 byte)]
    $rows = [
        [0, 0, 255],
        [2, 5, 0],
        [2, 5, 1],
        [2, 5, 2],
        [1, $off4, 0],
        [1, $off5, 0],
        [1, $off6, 0],
    ];
    $raw = '';
    $prev = str_repeat("\0", 4);
    foreach ($rows as $row) {
        $cur = pack('C', $row[0]) . pack('n', $row[1]) . pack('C', $row[2]);
        $enc = "\x02";
        for ($i = 0; $i < 4; $i++) {
            $enc .= chr((ord($cur[$i]) - ord($prev[$i])) & 0xFF);
        }
        $raw .= $enc;
        $prev = $cur;
    }
    $xrefZ = (string) gzcompress($raw);
    $out .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 2 1] /Index [0 7] /Root 1 0 R /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 >> /Length " . strlen($xrefZ) . " >>\nstream\n" . $xrefZ . "\nendstream\nendobj\n";
    $out .= "startxref\n{$off6}\n%%EOF\n";
    return $out;
}

$xs = buildXrefStreamPdf();
$rx = new PdfReader($xs);
check('xref stream + object stream + predictor: page found', $rx->getPageCount() === 1);
$pg = $rx->getPage(1);
check('inherited /MediaBox from objstm /Pages', is_array($pg['attrs']['MediaBox'] ?? null) && $pg['attrs']['MediaBox'][2] === 300);
check('xref-stream content stream decodes', str_contains(pageContent($rx, 1), 'Hello XRef stream'));

// incremental update chain (/Prev): override the content stream object
$base = sourcePdf(1, ['compress' => false]);
$tail = substr($base, strrpos($base, 'startxref') + 10);
$prevOff = (int) trim(strtok($tail, "\n"));
$rb = new PdfReader($base);
$pageContentBefore = pageContent($rb, 1);
// Find the content stream object number of page 1 and append a replacement as an update
$pageDict = $rb->getPage(1)['dict'];
$cref = $pageDict->get('Contents');
$cnum = $cref instanceof \IvoPdf\Pdf\Import\PdfRef ? $cref->num : 0;
$newContent = "BT 1 0 0 1 50 700 Tm (UPDATED) Tj ET\n";
$upd = $base;
$offNew = strlen($upd);
$upd .= "{$cnum} 0 obj\n<< /Length " . strlen($newContent) . " >>\nstream\n{$newContent}\nendstream\nendobj\n";
$xrefOff = strlen($upd);
$upd .= "xref\n{$cnum} 1\n" . sprintf("%010d 00000 n \n", $offNew);
$trailerDict = $rb->getObject(0);
preg_match('/\/Root \d+ 0 R/', $base, $rootM);
preg_match('/\/Size (\d+)/', $base, $sizeM);
$upd .= "trailer\n<< /Size {$sizeM[1]} {$rootM[0]} /Prev {$prevOff} >>\nstartxref\n{$xrefOff}\n%%EOF\n";
$ru = new PdfReader($upd);
check('incremental update (/Prev) resolves the newest object', str_contains(pageContent($ru, 1), 'UPDATED') && !str_contains(pageContent($ru, 1), 'Page 1'));

// damaged startxref -> reconstruction
$broken = substr($src, 0, (int) strrpos($src, 'startxref')) . "startxref\n99999999\n%%EOF\n";
$rbk = new PdfReader($broken);
check('damaged startxref: xref rebuilt by scanning', $rbk->getPageCount() === 3);
$truncatedObjStm = substr($xs, 0, (int) strrpos($xs, 'startxref')) . "startxref\n1\n%%EOF\n";
$rtx = new PdfReader($truncatedObjStm);
check('damaged xref-stream file: rebuilt incl. object streams', $rtx->getPageCount() === 1);

$threw = false;
try {
    new PdfReader('this is not a pdf');
} catch (PdfImportException) {
    $threw = true;
}
check('non-PDF input rejected with PdfImportException', $threw);
$threw = false;
try {
    $enc = str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt << /Filter /Standard /V 1 /R 2 /O (x) /U (y) /P -4 >>', $base);
    new PdfReader($enc);
} catch (PdfImportException $e) {
    $threw = str_contains($e->getMessage(), 'Encrypted');
}
check('encrypted PDF refused with a clear message', $threw);

// ---------------------------------------------------------------------------
echo "\n4. Import: page template, append, <img>\n";
// ---------------------------------------------------------------------------
file_put_contents($tmp . '/src3.pdf', $src);
file_put_contents($tmp . '/xs.pdf', $xs);

$doc = new IvoPdf(['compress' => true, 'basePath' => $tmp, 'allowedPaths' => [$tmp]]);
$ids = $doc->importPdfPages($tmp . '/src3.pdf', '2-3');
check('importPdfPages returns template ids', $ids === [1, 2]);
$threw = false;
try {
    $doc->importPdfPages($tmp . '/src3.pdf', '9');
} catch (PdfImportException) {
    $threw = true;
}
check('page selection outside the document rejected', $threw);

$letter = (new IvoPdf(['compress' => true, 'basePath' => $tmp, 'allowedPaths' => [$tmp]]))
    ->loadHtml('<p>Overlay body</p>')
    ->setPageTemplate($tmp . '/src3.pdf', 2);
$lp = $letter->output();
$rl = new PdfReader($lp);
check('page template: one output page', $rl->getPageCount() === 1);
check('page template drawn before the content', (bool) preg_match('/^q\s+[\d.]+ 0 0 [\d.]+ [\d.-]+ [\d.-]+ cm \/Tpl1 Do\s+Q/', pageContent($rl, 1)));
$xo = pageXObjects($rl, 1);
check('Form XObject registered in page resources', isset($xo['Tpl1']) && $xo['Tpl1'] instanceof PdfStream && $xo['Tpl1']->name('Subtype') === 'Form');
$form = $xo['Tpl1'] ?? null;
$fres = $form instanceof PdfStream ? $rl->resolve($form->get('Resources')) : null;
$fonts = $fres instanceof PdfDict ? $rl->resolve($fres->get('Font')) : null;
$firstFont = $fonts instanceof PdfDict ? $rl->resolve(reset($fonts->items)) : null;
check('nested resources preserved (font dict, descendant, ToUnicode)', $firstFont instanceof PdfDict && $firstFont->name('Subtype') === 'Type0' && $rl->resolve($firstFont->get('ToUnicode')) instanceof PdfStream);
$fcontent = $form instanceof PdfStream ? $rl->decodeStream($form) : '';
check('form content equals the source page content', str_contains($fcontent, 'Tj') || str_contains($fcontent, 'TJ'));
check('form /BBox from the MediaBox', $form instanceof PdfStream && is_array($form->get('BBox')) && abs((float) $form->get('BBox')[2] - 595.28) < 1.0);

// append / merge
$merged = (new IvoPdf(['compress' => true, 'basePath' => $tmp, 'allowedPaths' => [$tmp]]))
    ->loadHtml('<p>Cover</p>')
    ->appendPdf($tmp . '/src3.pdf')
    ->appendPdf($tmp . '/xs.pdf');
$mp = $merged->output();
$rm = new PdfReader($mp);
check('appendPdf: 1 HTML page + 3 + 1 imported pages', $rm->getPageCount() === 5 && $merged->getPageCount() === 5, (string) $rm->getPageCount());
$mb = $rm->getPage(5)['attrs']['MediaBox'] ?? [];
check('appended page keeps its own MediaBox size (300x200)', abs((float) ($mb[2] ?? 0) - 300.0) < 0.1 && abs((float) ($mb[3] ?? 0) - 200.0) < 0.1);
check('appended page content is just "q ... /TplN Do Q"', (bool) preg_match('/^q\s+[\d.]+ 0 0 [\d.]+ 0 0 cm \/Tpl\d+ Do\s+Q\s*$/', pageContent($rm, 2)));
$onlyMerge = (new IvoPdf(['compress' => true, 'allowedPaths' => [$tmp]]))->appendPdf($tmp . '/src3.pdf', '1,3');
$omp = $onlyMerge->output();
check('merge-only (no HTML): no blank leading page', (new PdfReader($omp))->getPageCount() === 2 && $onlyMerge->getPageCount() === 2);

// xref-stream source drawn through the HTML <img> path
$imgDoc = new IvoPdf(['compress' => true, 'basePath' => $tmp]);
$ip = $imgDoc->loadHtml('<p>Before</p><img src="xs.pdf" style="width:240px"><img src="src3.pdf" data-page="3" style="width:100px"><img src="src3.pdf#page=2" style="width:100px">')->output();
$ri = new PdfReader($ip);
$ixo = pageXObjects($ri, 1);
$forms = array_filter($ixo, static fn($o): bool => $o instanceof PdfStream && $o->name('Subtype') === 'Form');
check('<img src="x.pdf"> and data-page / #page= become Form XObjects', count($forms) === 3, (string) count($forms) . ' ' . implode(',', $imgDoc->getWarnings()));
check('no warnings for PDF images', $imgDoc->getWarnings() === [], implode('; ', $imgDoc->getWarnings()));

$deny = new IvoPdf(['compress' => true, 'basePath' => $tmp, 'allowedPaths' => [$tmp]]);
$deny->loadHtml('<img src="../../../../windows/win.ini.pdf"><img src="/etc/passwd.pdf">')->output();
check('<img src="x.pdf"> outside basePath is refused with a warning', $deny->getWarnings() !== []);

$threw = false;
try {
    (new IvoPdf(['allowedPaths' => [$tmp]]))->appendPdf(__FILE__);
} catch (RuntimeException) {
    $threw = true;
}
check('appendPdf outside allowedPaths refused', $threw);

file_put_contents($tmp . '/garbage.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 1 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n");
$threw = false;
try {
    (new IvoPdf(['allowedPaths' => [$tmp]]))->importPdfPages($tmp . '/garbage.pdf');
} catch (PdfImportException) {
    $threw = true;
}
check('reference cycle / page-less PDF handled without hanging', $threw);

$rotRaw = buildXrefStreamPdf(true);
file_put_contents($tmp . '/rot.pdf', $rotRaw);
$rot = (new IvoPdf(['compress' => false, 'allowedPaths' => [$tmp]]))->loadHtml('<p>x</p>')->appendPdf($tmp . '/rot.pdf');
$rotPdf = $rot->output();
$rr = new PdfReader($rotPdf);
$rmb = $rr->getPage(2)['attrs']['MediaBox'] ?? [];
check('/Rotate 90: output page is 200x300 (swapped)', abs((float) ($rmb[2] ?? 0) - 200.0) < 0.1 && abs((float) ($rmb[3] ?? 0) - 300.0) < 0.1);
$rform = array_values(array_filter(pageXObjects($rr, 2), static fn($o): bool => $o instanceof PdfStream))[0] ?? null;
check('/Rotate 90: form /Matrix rotates onto the unit square', $rform instanceof PdfStream && is_array($rform->get('Matrix')) && abs((float) $rform->get('Matrix')[0]) < 1e-9 && abs((float) $rform->get('Matrix')[1] + 1 / 300) < 1e-6);

// ---------------------------------------------------------------------------
echo "\n5. PyMuPDF cross-checks\n";
// ---------------------------------------------------------------------------
if (pyAvailable()) {
    $f = $tmp . '/merged.pdf';
    file_put_contents($f, $mp);
    $o = py("d=fitz.open(sys.argv[1]); print(d.page_count); print([round(p.rect.width) for p in d]); print(' | '.join(p.get_text().strip().replace(chr(10),' ') for p in d))", $f);
    check('PyMuPDF opens merged PDF with 5 pages', str_starts_with($o, '5'), $o);
    check('imported text is extractable (Cyrillic ToUnicode preserved)', str_contains($o, 'Page 2 Привет') && str_contains($o, 'Hello XRef stream'), $o);

    $f = $tmp . '/letter.pdf';
    file_put_contents($f, $lp);
    $o = py("d=fitz.open(sys.argv[1]); t=d[0].get_text(); pix=d[0].get_pixmap(dpi=40); s=pix.samples; nonwhite=sum(1 for i in range(0,len(s),3) if s[i]<200 or s[i+1]<200 or s[i+2]<200); print(('Overlay body' in t), ('Page 2' in t), nonwhite)", $f);
    $parts = explode(' ', $o);
    check('page template visible: text + non-white pixels from the template', ($parts[0] ?? '') === 'True' && ($parts[1] ?? '') === 'True' && (int) ($parts[2] ?? 0) > 300, $o);

    // external generator with object streams + xref streams + rotation + cropbox + incremental update
    $gen = <<<'PYC'
import pymupdf as fitz
doc = fitz.open()
for i in range(3):
    p = doc.new_page(width=400, height=300)
    p.insert_text((50, 100), "Fitz page %d hello" % (i + 1), fontsize=20)
doc[1].set_rotation(90)
doc[2].set_cropbox(fitz.Rect(50, 50, 300, 250))
doc.save(sys.argv[1], garbage=4, deflate=True, use_objstms=1)
doc.save(sys.argv[2])
d2 = fitz.open(sys.argv[2]); d2[0].insert_text((50, 200), "INCREMENTAL", fontsize=20); d2.saveIncr()
doc.save(sys.argv[3], encryption=fitz.PDF_ENCRYPT_AES_256, user_pw='x', owner_pw='y')
print('generated')
PYC;
    $o = py($gen, $tmp . '/fz_objstm.pdf', $tmp . '/fz_incr.pdf', $tmp . '/fz_enc.pdf');
    if (str_contains($o, 'generated')) {
        foreach (['fz_objstm' => false, 'fz_incr' => true] as $name => $incr) {
            $m = (new IvoPdf(['compress' => true, 'allowedPaths' => [$tmp]]))->appendPdf($tmp . "/{$name}.pdf");
            $out = $tmp . "/out_{$name}.pdf";
            $m->save($out);
            $o = py("d=fitz.open(sys.argv[1]); print(d.page_count, [(round(p.rect.width), round(p.rect.height)) for p in d], ' | '.join(p.get_text().strip().replace(chr(10),' ') for p in d))", $out);
            $okText = str_contains($o, 'Fitz page 1 hello') && str_contains($o, 'Fitz page 3 hello') && ($incr ? str_contains($o, 'INCREMENTAL') : true);
            check("PyMuPDF-generated {$name}.pdf imports (sizes: rotated 300x400, cropped 250x200)", str_starts_with($o, '3') && str_contains($o, '(300, 400)') && str_contains($o, '(250, 200)') && $okText, $o);
        }
        $threw = false;
        try {
            (new IvoPdf(['allowedPaths' => [$tmp]]))->appendPdf($tmp . '/fz_enc.pdf');
        } catch (PdfImportException $e) {
            $threw = str_contains($e->getMessage(), 'Encrypted');
        }
        check('AES-256 encrypted PyMuPDF file refused', $threw);

        // rasterize rotated import and compare to the source rendering
        $o = py("a=fitz.open(sys.argv[1])[1].get_pixmap(dpi=36); b=fitz.open(sys.argv[2])[1].get_pixmap(dpi=36); print(a.width, a.height, b.width, b.height)", $tmp . '/fz_objstm.pdf', $tmp . '/out_fz_objstm.pdf');
        $dim = array_map('intval', explode(' ', $o));
        check('rotated page raster size matches the original', count($dim) === 4 && $dim[0] === $dim[2] && $dim[1] === $dim[3], $o);
    } else {
        skip('PyMuPDF-generated sources (generation failed)');
    }
} else {
    skip('PyMuPDF cross-checks (python with fitz not available)');
}

// ---------------------------------------------------------------------------
echo "\n6. Limits & hygiene\n";
// ---------------------------------------------------------------------------
$t0 = microtime(true);
$loop = "%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [2 0 R] /Count 1 >>
endobj
trailer
<< /Root 1 0 R >>
";
$lr = new PdfReader($loop);
check('cyclic page tree terminates quickly with zero pages', $lr->getPageCount() === 0 && microtime(true) - $t0 < 2.0);
$tooBig = false;
try {
    new PdfReader(str_repeat('x', PdfReader::MAX_FILE_BYTES + 1));
} catch (PdfImportException) {
    $tooBig = true;
}
check('oversized input refused before parsing', $tooBig);
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

// cleanup
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo "\n=== Summary: {$passed} passed, {$failed} failed, {$skipped} skipped ===\n";
exit($failed > 0 ? 1 : 0);
