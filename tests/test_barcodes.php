<?php

declare(strict_types=1);

/**
 * Barcode tests: Code 128 (auto A/B/C), EAN/UPC/ISBN/ISSN, Code 39/93, I2of5, Codabar, QR (ISO 18004), Data Matrix ECC200,
 * the <barcode> HTML tag (mPDF-style attributes), PHP API and warnings.
 *
 * Reference vectors: QR "HELLO WORLD" 1-Q (Thonky tutorial, mask 6) and several QR matrices were cross-checked
 * module-for-module against the independent bacon/bacon-qr-code implementation; EAN/Code 39/93/ITF/Code 128 bar patterns
 * against TCPDF; Data Matrix "123456" codewords (142 164 186 114 25 5 88 102) from the ISO 16022 / zxing examples.
 *
 * Run: php tests/test_barcodes.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Widget\Barcode;
use IvoPdf\Widget\BarcodeSvg;
use IvoPdf\Widget\Barcode\DataMatrixEncoder;
use IvoPdf\Widget\Barcode\LinearEncoders;
use IvoPdf\Widget\Barcode\QrEncoder;

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

/** @param list<list<int>> $m */
function matrixRows(array $m): array
{
    return array_map(static fn(array $r): string => implode('', $r), $m);
}

/** Decodes a Code 128 module string back to values; returns [values incl. checksum, ok flag]. */
function decode128(string $bits): array
{
    $patterns = LinearEncoders::CODE128_PATTERNS;
    $widths = [];
    preg_match_all('/1+|0+/', $bits, $mm);
    foreach ($mm[0] as $run) {
        $widths[] = strlen($run);
    }
    $map = array_flip($patterns);
    $vals = [];
    $i = 0;
    while ($i < count($widths)) {
        $len = count($vals) > 0 && ($i + 7 <= count($widths)) && implode('', array_slice($widths, $i, 7)) === '2331112' ? 7 : 6;
        $key = implode('', array_slice($widths, $i, $len));
        if (!isset($map[$key])) {
            return [[], false];
        }
        $vals[] = $map[$key];
        $i += $len;
        if ($map[$key] === 106) {
            break;
        }
    }
    return [$vals, true];
}

/** Decodes Code 128 values to a string. */
function code128Text(array $vals): string
{
    $set = match ($vals[0]) {
        103 => 'A', 104 => 'B', default => 'C',
    };
    $out = '';
    for ($i = 1, $n = count($vals) - 2; $i < $n; $i++) {
        $v = $vals[$i];
        if ($v === 99) {
            $set = 'C';
        } elseif ($v === 100) {
            $set = 'B';
        } elseif ($v === 101) {
            $set = 'A';
        } elseif ($set === 'C') {
            $out .= sprintf('%02d', $v);
        } elseif ($set === 'A') {
            $out .= chr($v < 64 ? $v + 32 : $v - 64);
        } else {
            $out .= chr($v + 32);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
echo "\n1. Code 128\n";
// ---------------------------------------------------------------------------
$codes = LinearEncoders::code128Codes('PJJ123C', 'A');
check('Code 128 A "PJJ123C": codes and checksum 54 (Wikipedia example)', $codes === [103, 48, 42, 42, 17, 18, 19, 35] && LinearEncoders::code128Checksum($codes) === 54);
$codes = LinearEncoders::code128Codes('12345678');
check('auto: pure digits -> Start C + 4 pairs', $codes === [105, 12, 34, 56, 78]);
$codes = LinearEncoders::code128Codes('ABC');
check('auto: letters -> Start B', $codes === [104, 33, 34, 35]);
$codes = LinearEncoders::code128Codes("A\x01B");
check('auto: control char forces subset A (Start A, no extra switch)', $codes[0] === 103 && $codes === [103, 33, 65, 34], implode(',', $codes));
$codes = LinearEncoders::code128Codes('Hello1234567890');
check('auto: lower case + long digit run switches to C', $codes[0] === 104 && in_array(99, $codes, true) && count($codes) === 1 + 5 + 1 + 5, implode(',', $codes));
$codes = LinearEncoders::code128Codes('a12345');
check('auto: odd digit run keeps the first digit in B (Start B, a, 1, CodeC, 23, 45)', $codes === [104, 65, 17, 99, 23, 45], implode(',', $codes));
$allOk = true;
foreach (['INV-2026-104829', 'Hello World 123', "Tab\tand\nnewline", '0123456789', 'ab12cd3456ef78', str_repeat('Xy9', 20), 'A', '1', "\x00\x7f"] as $t) {
    $enc = LinearEncoders::encode('C128', $t);
    [$vals, $ok] = decode128($enc['bits']);
    $sumOk = $ok && LinearEncoders::code128Checksum(array_slice($vals, 0, -2)) === $vals[count($vals) - 2];
    if (!$ok || !$sumOk || code128Text($vals) !== $t) {
        $allOk = false;
        echo "    mismatch for " . json_encode($t) . ': ' . ($ok ? code128Text($vals) : 'undecodable') . "\n";
    }
}
check('Code 128 auto: encode -> decode round trip incl. checksum and stop (9 strings)', $allOk);
check('Code 128 total width = 11 x (start + data + check) + 13 (stop)', strlen(LinearEncoders::encode('C128', 'ABC')['bits']) === 11 * (count(LinearEncoders::code128Codes('ABC')) + 1) + 13);
check('C128C pads odd digit strings with a leading zero', LinearEncoders::code128Codes('123', 'C') === [105, 1, 23]);

// ---------------------------------------------------------------------------
echo "\n2. EAN / UPC / ISBN / ISSN\n";
// ---------------------------------------------------------------------------
$ean = LinearEncoders::encode('EAN13', '4006381333931');
check('EAN-13 4006381333931 bit pattern (95 modules)', $ean['bits'] === '10100011010100111010111101111010001001011001101010100001010000101000010111010010000101100110101');
check('EAN-13 starts/ends with guard 101 and has centre guard 01010', str_starts_with($ean['bits'], '101') && str_ends_with($ean['bits'], '101') && substr($ean['bits'], 45, 5) === '01010');
check('EAN-13 check digit computed from 12 digits', LinearEncoders::encode('EAN13', '400638133393')['digits'] === '4006381333931');
check('EAN check digit helper (workshop example 590123412345 -> 7)', LinearEncoders::eanCheckDigit('590123412345') === 7);
check('EAN-8 96385074 bit pattern length and check', strlen(LinearEncoders::encode('EAN8', '96385074')['bits']) === 67 && LinearEncoders::encode('EAN8', '9638507')['digits'] === '96385074');
check('UPC-A 036000291452 (95 modules), 11 digits computes check', strlen(LinearEncoders::encode('UPCA', '036000291452')['bits']) === 95 && LinearEncoders::encode('UPCA', '03600029145')['digits'] === '036000291452');
$e = LinearEncoders::encode('UPCE', '0123456');
check('UPC-E 0123456 computes check digit 5 (01234565), 51 modules', $e['digits'] === '01234565' && strlen($e['bits']) === 51);
check('ISBN-10 0-306-40615-2 -> EAN-13 9780306406157', LinearEncoders::encode('ISBN', '0-306-40615-2')['digits'] === '9780306406157');
check('ISBN-13 with hyphens accepted', LinearEncoders::encode('ISBN', '978-0-306-40615-7')['digits'] === '9780306406157');
check('ISSN 0317-8471 -> 9770317847001', LinearEncoders::encode('ISSN', '0317-8471')['digits'] === '9770317847001');
$threw = static function (callable $f): bool {
    try {
        $f();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
};
check('EAN-13 with wrong check digit rejected', $threw(static fn() => LinearEncoders::encode('EAN13', '4006381333932')));
check('EAN-13 with letters rejected', $threw(static fn() => LinearEncoders::encode('EAN13', '40063813339AB')));
check('ISBN-10 with wrong check digit rejected', $threw(static fn() => LinearEncoders::encode('ISBN', '0-306-40615-3')));

// ---------------------------------------------------------------------------
echo "\n3. Code 39 / 93 / ITF / Codabar\n";
// ---------------------------------------------------------------------------
$c39 = LinearEncoders::encode('C39', 'A');
check('Code 39 "A": *A* = 3 chars x 15 modules (3 wide + 6 narrow, wide=3) + 2 gaps', strlen($c39['bits']) === 3 * 15 + 2, (string) strlen($c39['bits']));
check('Code 39 start/stop "*" pattern nwnnwnwnn: 1 000 1 0 111 0 111 0 1 0 111 0 1', str_starts_with($c39['bits'], '1000' . '1' . '0' . '111' . '0' . '111' . '0' . '1' . '0' . '111' . '0' . '1' . '0'));
$a = LinearEncoders::encode('C39+', 'CODE39');
check('Code 39 mod-43 checksum of CODE39 is "W" (appended)', str_ends_with($a['text'], 'W') && $a['text'] === 'CODE39W', $a['text']);
check('Code 39 rejects characters outside its set; C39E encodes lower case via +A..+Z', $threw(static fn() => LinearEncoders::encode('C39', 'a_b')) && strlen(LinearEncoders::encode('C39E', 'abc')['bits']) === (2 + 6) * 15 + 7);
check('Code 93 TEST93: start/stop 111141 and 2 check symbols', strlen(LinearEncoders::encode('C93', 'TEST93')['bits']) === (1 + 6 + 2 + 1) * 9 + 1);
check('I25 pads odd length / adds checksum', LinearEncoders::encode('I25', '123')['text'] === '0123' && LinearEncoders::encode('I25+', '1234567')['text'] === '12345670');
check('Codabar wraps in A..A unless start/stop given', strlen(LinearEncoders::encode('CODABAR', '123')['bits']) === strlen(LinearEncoders::encode('CODABAR', 'A123A')['bits']));
check('digit-only types reject letters', $threw(static fn() => LinearEncoders::encode('I25', '12A4')));
check('Code 128 rejects non-ASCII', $threw(static fn() => LinearEncoders::encode('C128', 'Привет')));

// ---------------------------------------------------------------------------
echo "\n4. QR Code\n";
// ---------------------------------------------------------------------------
$hello = QrEncoder::encode('HELLO WORLD', 'Q');
$expected = [
    '111111100001001111111', '100000101100101000001', '101110100101101011101', '101110101111101011101',
    '101110101101001011101', '100000100100101000001', '111111101010101111111', '000000001101100000000',
    '010111101100111011010', '101111010000111101110', '001010110001001100000', '101101000101100011000',
    '110111111110111011111', '000000001000100101000', '111111100110011001111', '100000101010010010111',
    '101110101101001000111', '101110101011100010100', '101110100100001000011', '100000101110011100110',
    '111111100101000000010',
];
check('QR "HELLO WORLD" 1-Q: version 1, mask 6 (Thonky tutorial)', $hello['version'] === 1 && $hello['mask'] === 6 && $hello['size'] === 21);
check('QR "HELLO WORLD" 1-Q: module matrix identical to reference', matrixRows($hello['matrix']) === $expected);
$cap = ['L' => 2956, 'M' => 2334, 'Q' => 1666, 'H' => 1276];
$capOk = true;
foreach ($cap as $lvl => $n) {
    $capOk = $capOk && QrEncoder::dataCodewords(40, $lvl) === $n;
}
check('QR version 40 data capacity L/M/Q/H = 2956/2334/1666/1276 codewords', $capOk);
$counts = ['L' => [19, 7], 'M' => [16, 10], 'Q' => [13, 13], 'H' => [9, 17]];
$cOk = true;
foreach ($counts as $lvl => [$d]) {
    $cOk = $cOk && QrEncoder::dataCodewords(1, $lvl) === $d;
}
check('QR version 1 data codewords 19/16/13/9', $cOk);
$cyr = QrEncoder::encode('Здравей свят', 'M');
$h = md5(implode('', matrixRows($cyr['matrix'])));
check('QR byte-mode UTF-8 Cyrillic (ECI 26) v2-M matches bacon-qr-code reference', $cyr['version'] === 2 && $h === 'b08329710024db863bc1bb807ba2d9aa', $h);
$url = QrEncoder::encode('https://example.com/pay?id=12345&amt=10.50', 'Q');
$h = md5(implode('', matrixRows($url['matrix'])));
check('QR URL v4-Q (byte mode, mask 0) matches reference', $url['version'] === 4 && $h === 'c9794360b67a8f3a46d33d33c8bdc3e4', $h);
check('QR numeric segmentation beats byte: 1000 digits fits version <= 14 at L', QrEncoder::encode(str_repeat('7', 1000), 'L')['version'] <= 14);
$big = QrEncoder::encode(str_repeat('a', 2953), 'L');
check('QR 2953 bytes at L = version 40 (177x177)', $big['version'] === 40 && $big['size'] === 177);
check('QR 2954 bytes at L is rejected', $threw(static fn() => QrEncoder::encode(str_repeat('a', 2954), 'L')));
$m = QrEncoder::encode('HELLO WORLD', 'Q', 3);
check('QR forced mask is honoured', $m['mask'] === 3);
// Format information (BCH) of the finished symbol: read it back and check level + mask
$fm = 0;
for ($i = 0; $i <= 5; $i++) {
    $fm |= $hello['matrix'][$i][8] << $i;
}
$fm |= $hello['matrix'][7][8] << 6;
$fm |= $hello['matrix'][8][8] << 7;
$fm |= $hello['matrix'][8][7] << 8;
for ($i = 9; $i < 15; $i++) {
    $fm |= $hello['matrix'][8][14 - $i] << $i;
}
$fm ^= 0x5412;
check('QR format info reads back as level Q (3) + mask 6', (($fm >> 10) & 0x1F) === ((3 << 3) | 6), dechex($fm));
$allVersions = true;
foreach ([1, 6, 7, 14, 21, 32, 40] as $v) {
    $cw = QrEncoder::dataCodewords($v, 'M');
    $r = QrEncoder::encode(str_repeat('9', min(30 * $v, 900)), 'M', null, $v);
    $sz = $v * 4 + 17;
    $okSize = $r['size'] === $sz && count($r['matrix']) === $sz;
    $finder = $r['matrix'][0][0] === 1 && $r['matrix'][6][6] === 1 && $r['matrix'][$sz - 1][0] === 1 && $r['matrix'][0][$sz - 1] === 1 && $r['matrix'][$sz - 8][8] === 1;
    $allVersions = $allVersions && $okSize && $finder && $cw > 0;
}
check('QR minVersion option: sizes 21..177 with finders and dark module', $allVersions);

// ---------------------------------------------------------------------------
echo "\n5. Data Matrix ECC200\n";
// ---------------------------------------------------------------------------
$dm = DataMatrixEncoder::encode('123456');
check('Data Matrix "123456": codewords 142 164 186 + ECC 114 25 5 88 102', $dm['codewords'] === [142, 164, 186, 114, 25, 5, 88, 102]);
check('Data Matrix "123456": 10x10 module matrix (L-finder, timing, mapping)', matrixRows($dm['matrix']) === [
    '1010101010', '1100101101', '1100000100', '1100011101', '1100001000',
    '1000001111', '1110110000', '1111011001', '1001110100', '1111111111',
]);
check('Data Matrix "A" pads: 66 129 70 (ISO example)', array_slice(DataMatrixEncoder::encode('A')['codewords'], 0, 3) === [66, 129, 70]);
check('Data Matrix digit pairs compact (130+n) and upper shift for bytes >= 128', DataMatrixEncoder::encodeAscii('1234a') === [142, 164, 98] && DataMatrixEncoder::encodeAscii("\xD0") === [235, 81]);
$sizes = [];
foreach ([3, 5, 8, 12, 18, 22, 30, 36, 44, 62, 86, 114, 144, 174, 204, 280, 368, 456, 576, 696, 816, 1050, 1304, 1558] as $capacity) {
    $sizes[] = DataMatrixEncoder::encode(str_repeat('12', $capacity))['size'];
}
check('Data Matrix: capacity steps pick 10x10 .. 144x144', $sizes === [10, 12, 14, 16, 18, 20, 22, 24, 26, 32, 36, 40, 44, 48, 52, 64, 72, 80, 88, 96, 104, 120, 132, 144], implode(',', $sizes));
check('Data Matrix: 3117 digits do not fit', $threw(static fn() => DataMatrixEncoder::encode(str_repeat('1', 3117))));
$d2 = DataMatrixEncoder::encode(str_repeat('12', 100));
$okFinder = true;
for ($i = 0; $i < $d2['size']; $i++) {
    $okFinder = $okFinder && $d2['matrix'][$i][0] === 1 && $d2['matrix'][$d2['size'] - 1][$i] === 1;
}
check('Data Matrix: solid L finder on the left and bottom edges', $okFinder);

// ---------------------------------------------------------------------------
echo "\n6. SVG output and API\n";
// ---------------------------------------------------------------------------
$svg = Barcode::svg('QR', 'https://example.com', ['error' => 'H']);
check('Barcode::svg QR returns an <svg> of merged rects', str_starts_with($svg, '<svg') && substr_count($svg, '<rect') > 20 && substr_count($svg, '<rect') < 200);
check('QR quiet zone of 4 modules: viewBox = size + 8', str_contains($svg, 'viewBox="0 0 33 33"') || preg_match('/viewBox="0 0 (\d+) \1"/', $svg, $mv) === 1 && ((int) $mv[1] - 8) % 4 === 1);
$svgNoQ = Barcode::svg('QR', 'HELLO WORLD', ['quiet' => false, 'error' => 'Q']);
check('quiet=false removes the quiet zone (viewBox 21)', str_contains($svgNoQ, 'viewBox="0 0 21 21"'));
check('QRCODE alias and "QRCODE,H" TCPDF-style level', Barcode::svg('QRCODE', 'abc') === Barcode::svg('QR', 'abc') && Barcode::svg('QRCODE,H', 'abc') === Barcode::svg('QR', 'abc', ['error' => 'H']));
check('size option scales the QR', str_contains(Barcode::svg('QR', 'HELLO WORLD', ['size' => 2, 'quiet' => false]), 'width="126"'));
check('width option sets the QR side', str_contains(Barcode::svg('QR', 'HELLO WORLD', ['width' => 100, 'quiet' => false]), 'width="100" height="100"'));
$ean13 = Barcode::svg('EAN13', '4006381333931', ['width' => 190, 'height' => 60]);
check('EAN-13 SVG has bars, 13 digits as text and taller guard bars', substr_count($ean13, '<text') === 13 && preg_match_all('/height="([\d.]+)"/', $ean13, $hs) && count(array_unique($hs[1])) >= 3);
$c128 = Barcode::svg('C128', 'IVO-2026-0042', ['width' => 180, 'height' => 48, 'color' => '#000000']);
check('Code 128 SVG contains value as text', str_contains($c128, 'IVO-2026-0042') && str_contains($c128, '<rect'));
check('text=false removes the human-readable text', !str_contains(Barcode::svg('C128', 'ABC', ['text' => false]), '<text'));
check('BarcodeSvg::code128 wrapper still works', str_contains(BarcodeSvg::code128('IVO-2026-0042', 180, 48, '#000', true), 'IVO-2026-0042') && !str_contains(BarcodeSvg::code128('ABC', 100, 30, '#000', false), '<text'));
$warnings = [];
$bad = Barcode::svg('EAN13', '123', ['warn' => static function (string $m) use (&$warnings): void {
    $warnings[] = $m;
}]);
check('invalid data renders nothing and calls the warn callback', $bad === '' && count($warnings) === 1 && str_contains($warnings[0], 'EAN13'));
check('unknown type renders nothing (no exception)', Barcode::svg('NOPE', '123') === '' && str_contains(Barcode::svg('PDF417', '123'), '<rect'));
check('Barcode::svg(DATAMATRIX) works', str_contains(Barcode::svg('DATAMATRIX', 'Hello 123'), '<rect'));
check('all supported types render', (static function (): bool {
    $cases = ['C128' => 'Abc123', 'C128A' => 'ABC', 'C128B' => 'abc', 'C128C' => '123456', 'EAN13' => '4006381333931', 'EAN8' => '96385074',
        'UPCA' => '036000291452', 'UPCE' => '01234565', 'ISBN' => '9780306406157', 'ISSN' => '03178471', 'C39' => 'ABC-123', 'C39+' => 'ABC',
        'C39E' => 'abc', 'C39E+' => 'abc', 'C93' => 'Abc', 'I25' => '123456', 'I25+' => '12345', 'CODABAR' => '12345', 'QR' => 'x', 'DATAMATRIX' => 'x'];
    foreach ($cases as $t => $v) {
        if (!str_contains(Barcode::svg($t, $v), '<rect')) {
            echo "    type {$t} failed\n";
            return false;
        }
    }
    return true;
})());
$sizeQr = strlen(Barcode::svg('QR', "BCD
002
1
SCT
BNBGBGSF
Ivo Ltd
BG80BNBG96611020345678
EUR123.45


Invoice 2026-104829", ['error' => 'M']));
check('typical payment QR SVG stays compact (merged runs, one fill group) < 20 KB', $sizeQr < 20000, (string) $sizeQr);

// ---------------------------------------------------------------------------
echo "\n7. <barcode> tag in HTML\n";
// ---------------------------------------------------------------------------
$pdf = new IvoPdf(['compress' => false]);
$out = $pdf->loadHtml('<html><body><p>Pay</p>
  <barcode code="BG80BNBG96611020345678" type="QR" size="1" error="M" disableborder="1" />
  <barcode type="EAN13" value="4006381333931" width="150" height="50"></barcode>
  <barcode type="QRCODE" value="Здравей &amp; свят &gt; 1" error="Q"/>
  <barcode value="PLAIN-128" text="false"/>
  <barcode type="EAN13" value="12345"/>
  <barcode type="DATAMATRIX" code="ABC123"/>
  <barcode value="it\'s &quot;quoted&quot; > x" width="160"/>
</body></html>')->output();
$streams = '';
if (preg_match_all('/stream\r?\n(.*?)endstream/s', $out, $sm)) {
    $streams = implode("\n", $sm[1]);
}
check('HTML with mixed <barcode> tags renders a PDF with many vector fills', str_starts_with($out, '%PDF-') && substr_count($streams, ' re') > 150, 're=' . substr_count($streams, ' re'));
$w = $pdf->getWarnings();
check('invalid EAN-13 in the tag adds exactly one warning', count($w) === 1 && str_contains($w[0], 'EAN13'), json_encode($w));
check('<barcode> tags do not leak into the output as text', !str_contains($out, '<barcode'));

// Attributes
check('mPDF "code" attribute is an alias of "value"', Barcode::fromAttributes('code="HELLO WORLD" type="QR" error="Q"') === Barcode::svg('QR', 'HELLO WORLD', ['error' => 'Q']));
check('attribute values may contain > and the other quote kind', str_contains(Barcode::fromAttributes('value="a>b\'c" type="C128"'), 'a&gt;b&#039;c'));
check('HTML entities in attributes are decoded', Barcode::fromAttributes('value="A&amp;B" type="QR"') === Barcode::svg('QR', 'A&B'));
check('width/height in mm and px accepted', str_contains(Barcode::fromAttributes('type="QR" value="x" width="10mm" disableborder="1"'), 'width="37.795"'));
check('legacy tag (no type) still yields Code 128 with default 180x46', str_contains(Barcode::fromAttributes('value="INV-1"'), 'width="180" height="46"'));

// ---------------------------------------------------------------------------
echo "\n8. Engine hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
