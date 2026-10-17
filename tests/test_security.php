<?php

declare(strict_types=1);

/**
 * Tests for encryption (AES-256 / AES-128 / RC4-128), PDF/A output and digital signatures.
 * PyMuPDF (python `fitz`) is used for independent verification when available.
 *
 * Run: php tests/test_security.php
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Options;
use IvoPdf\Pdf\IccProfile;

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

const HTML = '<h1>Secret Report</h1><p>Confidential text: Привет свят, hello world.</p>';

function make(array $opts = [], ?callable $setup = null): string
{
    $pdf = new IvoPdf(array_merge(['title' => 'Sec Test', 'author' => 'Ivo', 'compress' => true], $opts));
    if ($setup !== null) {
        $setup($pdf);
    }
    return $pdf->loadHtml(HTML)->output();
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivopdf_sec_' . getmypid();
@mkdir($tmp);

/** Opens a PDF with PyMuPDF; returns null when python/fitz are unavailable. */
function inspect(string $bytes, string $password = ''): ?array
{
    global $tmp;
    static $available = null;
    $script = $tmp . DIRECTORY_SEPARATOR . 'inspect.py';
    if ($available === null) {
        file_put_contents($script, <<<'PY'
import sys, json
try:
    import pymupdf as fitz
except Exception:
    print("{\"nofitz\": true}"); sys.exit(0)
path, pw = sys.argv[1], sys.argv[2]
d = fitz.open(path)
np = bool(d.needs_pass)  # read once: querying it again after authenticate() resets PyMuPDF's auth state
r = {"needs_pass": np, "encrypted": bool(d.is_encrypted)}
auth = d.authenticate(pw) if np else -1
r["auth"] = auth
if np and auth == 0:
    print(json.dumps(r)); sys.exit(0)
r["perm"] = d.permissions
r["pages"] = d.page_count
r["text"] = d[0].get_text()
r["sig"] = d.get_sigflags()
r["widgets"] = [w.field_type_string for w in d[0].widgets()]
r["meta"] = d.metadata
print(json.dumps(r))
PY);
        $available = true;
    }
    $file = $tmp . DIRECTORY_SEPARATOR . 'x_' . md5($bytes . $password) . '.pdf';
    file_put_contents($file, $bytes);
    $out = shell_exec('python -I ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($password) . ' 2>&1');
    $data = json_decode((string) $out, true);
    if (!is_array($data) || isset($data['nofitz'])) {
        return null;
    }
    return $data;
}

// ---------------------------------------------------------------------------
echo "1. Trailer /ID and unchanged plain output\n";
// ---------------------------------------------------------------------------
$plain = make();
check('plain PDF has trailer /ID', (bool) preg_match('~/ID \[<[0-9A-F]{32}> <[0-9A-F]{32}>\]~', $plain));
check('plain PDF is not encrypted', !str_contains($plain, '/Encrypt'));
check('plain PDF header is 1.7', str_starts_with($plain, "%PDF-1.7\n"));

// ---------------------------------------------------------------------------
echo "\n2. Encryption\n";
// ---------------------------------------------------------------------------
foreach (['aes256' => '/AESV3', 'aes128' => '/AESV2', 'rc4' => '/V 2 /R 3'] as $method => $marker) {
    $pdf = make([], static fn(IvoPdf $p) => $p->setProtection(['print', 'copy'], 'user-pw', 'owner-pw', $method));
    check("{$method}: /Encrypt dictionary written ({$marker})", str_contains($pdf, '/Encrypt ') && str_contains($pdf, $marker));
    check("{$method}: no plaintext title in file", !str_contains($pdf, 'Sec Test') && !str_contains($pdf, bin2hex('Sec Test')));
    $r = inspect($pdf, 'user-pw');
    if ($r === null) {
        echo "  [SKIP] PyMuPDF not available\n";
        continue;
    }
    check("{$method}: PyMuPDF requires a password", $r['needs_pass'] === true);
    $none = inspect($pdf, '');
    check("{$method}: empty password refused", $none !== null && $none['auth'] === 0);
    $bad = inspect($pdf, 'wrong');
    check("{$method}: wrong password refused", $bad !== null && $bad['auth'] === 0);
    check("{$method}: user password authenticates", $r['auth'] > 0, 'auth=' . $r['auth']);
    check("{$method}: text extractable after decrypt", str_contains($r['text'], 'Secret Report') && str_contains($r['text'], 'Привет'), $r['text'] ?? '');
    $owner = inspect($pdf, 'owner-pw');
    check("{$method}: owner password authenticates", $owner !== null && $owner['auth'] >= 4, 'auth=' . ($owner['auth'] ?? 'null'));
    $perm = (int) $r['perm'];
    check("{$method}: permissions reflect print+copy only", ($perm & 4) && ($perm & 16) && !($perm & 8) && !($perm & 32), 'perm=' . $perm);
    check("{$method}: metadata title decrypted", ($r['meta']['title'] ?? '') === 'Sec Test', json_encode($r['meta']));
}

$open = make([], static fn(IvoPdf $p) => $p->SetProtection(['print'], '', 'own', 128));
$r = inspect($open);
if ($r !== null) {
    check('mPDF SetProtection(perms, "", owner, 128): opens without password', $r['needs_pass'] === false && str_contains($r['text'], 'Secret Report'));
    check('mPDF alias: copy denied, print allowed', ((int) $r['perm'] & 4) && !((int) $r['perm'] & 16), 'perm=' . $r['perm']);
}
check('dompdf-style "encryption" option array', str_contains(make(['encryption' => ['permissions' => ['print'], 'user' => 'a']]), '/AESV3'));
$all = inspect(make([], static fn(IvoPdf $p) => $p->setProtection(['all'], 'x', 'y', 'aes256')), 'x');
if ($all !== null) {
    $pm = (int) $all['perm'];
    check('permission "all" grants print/modify/copy/annotate', ($pm & 4) && ($pm & 8) && ($pm & 16) && ($pm & 32), 'perm=' . $pm);
}
$threw = false;
try {
    (new IvoPdf())->setProtection(['fly']);
} catch (\InvalidArgumentException) {
    $threw = true;
}
check('unknown permission rejected', $threw);

// ---------------------------------------------------------------------------
echo "\n3. PDF/A\n";
// ---------------------------------------------------------------------------
foreach (['2b' => 2, '3b' => 3, '1b' => 1] as $level => $part) {
    $pdf = make(['pdfa' => $level]);
    check("pdfa {$level}: no /Encrypt, has /Metadata + /OutputIntents + /ID", !str_contains($pdf, '/Encrypt') && str_contains($pdf, '/Metadata ') && str_contains($pdf, '/OutputIntents') && str_contains($pdf, '/ID ['));
    if (!preg_match('~<\?xpacket begin.*?<\?xpacket end="w"\?>~s', $pdf, $m)) {
        check("pdfa {$level}: XMP packet found", false);
        continue;
    }
    $xml = simplexml_load_string(preg_replace('~<\?xpacket[^>]*\?>~', '', $m[0]));
    check("pdfa {$level}: XMP is well-formed XML", $xml !== false);
    if ($xml === false) {
        continue;
    }
    $xml->registerXPathNamespace('pdfaid', 'http://www.aiim.org/pdfa/ns/id/');
    $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    $xml->registerXPathNamespace('xmp', 'http://ns.adobe.com/xap/1.0/');
    $xml->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
    $gotPart = (string) ($xml->xpath('//pdfaid:part')[0] ?? '');
    check("pdfa {$level}: pdfaid:part={$part} conformance=B", $gotPart === (string) $part && (string) ($xml->xpath('//pdfaid:conformance')[0] ?? '') === 'B', $gotPart);
    check("pdfa {$level}: dc:title matches", str_contains((string) ($xml->xpath('//dc:title/rdf:Alt/rdf:li')[0] ?? ''), 'Sec Test'));
    $create = (string) ($xml->xpath('//xmp:CreateDate')[0] ?? '');
    preg_match("~/CreationDate \(D:(\d{14})\+00'00'\)~", $pdf, $dm);
    $info = isset($dm[1]) ? \DateTime::createFromFormat('YmdHis', $dm[1], new \DateTimeZone('UTC')) : false;
    check("pdfa {$level}: XMP CreateDate equals Info CreationDate", $info !== false && $create === $info->format('Y-m-d\TH:i:s') . '+00:00', $create . ' vs ' . ($dm[1] ?? ''));
    check("pdfa {$level}: Info ModDate in D:...+00'00' form", (bool) preg_match("~/ModDate \(D:\d{14}\+00'00'\)~", $pdf));
}
$icc = IccProfile::srgb();
check('ICC profile: header size, signature, class, spaces', unpack('N', $icc)[1] === strlen($icc) && substr($icc, 36, 4) === 'acsp' && substr($icc, 12, 4) === 'mntr' && substr($icc, 16, 8) === 'RGB XYZ ');
$r = inspect(make(['pdfa' => true]));
if ($r !== null) {
    check('pdfa: PyMuPDF opens and extracts text', str_contains($r['text'], 'Secret Report'));
}
$threw = false;
try {
    make(['pdfa' => '2b'], static fn(IvoPdf $p) => $p->setProtection(['print'], 'a', 'b'));
} catch (\LogicException $e) {
    $threw = true;
}
check('pdfa + encryption throws a clear exception', $threw);
$linkPdf = (new IvoPdf(['pdfa' => '2b', 'compress' => false]))->loadHtml('<a href="https://example.com">x</a>')->output();
check('pdfa: link annotations get the Print flag (/F 4)', (bool) preg_match('~/Subtype /Link.*?/F 4~', $linkPdf));
check('pdfa option true => 2b', str_contains(make(['pdfa' => true]), '<pdfaid:part>2</pdfaid:part>'));
check('mPDF "PDFA" option key accepted', str_contains(make(['PDFA' => true]), 'pdfaid:part'));

// ---------------------------------------------------------------------------
echo "\n4. Digital signatures\n";
// ---------------------------------------------------------------------------
$conf = $tmp . DIRECTORY_SEPARATOR . 'openssl.cnf';
file_put_contents($conf, "[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=IvoPDF Test Signer\nO=IvoPDF\n[v3]\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,nonRepudiation\n");
$cfg = ['config' => $conf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];
$key = openssl_pkey_new($cfg);
$csr = $key ? openssl_csr_new(['commonName' => 'IvoPDF Test Signer', 'organizationName' => 'IvoPDF'], $key, $cfg) : false;
$x509 = $csr ? openssl_csr_sign($csr, null, $key, 2, $cfg) : false;
if (!$x509) {
    check('test certificate generated', false, (string) openssl_error_string());
} else {
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem, 'keypass', $cfg);

    /** @return array{bytes: string, br: list<int>, der: string, signed: string} */
    $extract = static function (string $pdf): array {
        preg_match('~/ByteRange \[(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\]~', $pdf, $m);
        $br = array_map('intval', array_slice($m, 1));
        $signed = substr($pdf, $br[0], $br[1]) . substr($pdf, $br[2], $br[3]);
        $hex = substr($pdf, $br[1] + 1, $br[2] - $br[1] - 2);
        $der = (string) hex2bin($hex);
        return ['bytes' => $pdf, 'br' => $br, 'der' => rtrim($der, "\0") === '' ? '' : $der, 'signed' => $signed];
    };
    $haveCli = trim((string) shell_exec('openssl version 2>&1')) !== '' && str_starts_with(trim((string) shell_exec('openssl version 2>&1')), 'OpenSSL');
    $verify = static function (string $signed, string $der) use ($tmp, $certPem, $haveCli): bool {
        // 1. the messageDigest attribute must hold SHA-256 of exactly the signed byte ranges
        if (!str_contains($der, hash('sha256', $signed, true))) {
            return false;
        }
        // 2. full CMS verification (signature, certificate binding) with the OpenSSL CLI, which reads files in binary mode
        if (!$haveCli) {
            return true;
        }
        $c = $tmp . DIRECTORY_SEPARATOR . 'c.bin';
        $s = $tmp . DIRECTORY_SEPARATOR . 's.der';
        file_put_contents($c, $signed);
        file_put_contents($s, $der);
        $out = [];
        exec('openssl cms -verify -inform DER -in ' . escapeshellarg($s) . ' -content ' . escapeshellarg($c) . ' -binary -noverify -out ' . escapeshellarg($tmp . DIRECTORY_SEPARATOR . 'o.bin') . ' 2>&1', $out, $code);
        return $code === 0;
    };

    $sigPdf = make([], static fn(IvoPdf $p) => $p->setSignature($certPem, $keyPem, 'keypass', ['Name' => 'Ivo', 'Reason' => 'Approval', 'Location' => 'Sofia']));
    $e = $extract($sigPdf);
    check('signature: /SubFilter, /AcroForm /SigFlags 3, widget present', str_contains($sigPdf, '/adbe.pkcs7.detached') && str_contains($sigPdf, '/SigFlags 3') && str_contains($sigPdf, '/FT /Sig'));
    check('signature: ByteRange covers the whole file except /Contents', $e['br'][0] === 0 && $e['br'][2] + $e['br'][3] === strlen($sigPdf) && $e['br'][1] > 0);
    check('signature: file still ends with %%EOF and keeps xref valid', str_ends_with($sigPdf, "%%EOF\n") && preg_match('~startxref\n(\d+)~', $sigPdf, $sm) && substr($sigPdf, (int) $sm[1], 4) === 'xref');
    check('signature: CMS verifies over the signed byte ranges (digest + openssl cms -verify)', $verify($e['signed'], $e['der']));
    $tampered = $e['signed'];
    $tampered[100] = $tampered[100] === 'A' ? 'B' : 'A';
    check('signature: tampered content fails verification', !$verify($tampered, $e['der']));
    $r = inspect($sigPdf);
    if ($r !== null) {
        check('signature: PyMuPDF get_sigflags() == 3', $r['sig'] === 3, (string) $r['sig']);
        check('signature: signature widget listed', in_array('Signature', $r['widgets'], true), json_encode($r['widgets']));
        check('signature: text still extractable', str_contains($r['text'], 'Secret Report'));
    }

    $vis = make([], static fn(IvoPdf $p) => $p->setSignature($certPem, $keyPem, 'keypass', ['Name' => 'Ivo'], [1, 50, 700, 200, 60]));
    $ev = $extract($vis);
    check('visible signature: appearance stream and non-empty /Rect', (bool) preg_match('~/Rect \[50\.00 \d+\.\d+ 250\.00 \d+\.\d+\]~', $vis) && $verify($ev['signed'], $ev['der']));
    $rv = inspect($vis);
    if ($rv !== null) {
        check('visible signature: widget listed and sigflags 3', $rv['sig'] === 3 && in_array('Signature', $rv['widgets'], true));
    }

    $both = make([], static function (IvoPdf $p) use ($certPem, $keyPem): void {
        $p->setProtection(['print'], 'pw', 'own')->setSignature($certPem, $keyPem, 'keypass', ['Reason' => 'Combo']);
    });
    $eb = $extract($both);
    check('signed + encrypted: CMS verifies over final bytes', $verify($eb['signed'], $eb['der']));
    check('signed + encrypted: /Contents hex is not encrypted (starts with DER SEQUENCE 3082)', str_starts_with(strtoupper(bin2hex($eb['der'])), '3082') || str_starts_with(strtoupper(bin2hex($eb['der'])), '3080'));
    $rb = inspect($both, 'pw');
    if ($rb !== null) {
        check('signed + encrypted: PyMuPDF opens with password, sigflags 3', $rb['auth'] > 0 && $rb['sig'] === 3 && str_contains($rb['text'], 'Secret Report'));
    }

    $sp = make(['pdfa' => '2b'], static fn(IvoPdf $p) => $p->setSignature($certPem, $keyPem, 'keypass'));
    $esp = $extract($sp);
    check('PDF/A + signature: both present and CMS verifies', str_contains($sp, 'pdfaid:part') && $verify($esp['signed'], $esp['der']));

    // PKCS#12 bundle input
    if (openssl_pkcs12_export($x509, $p12, $key, 'p12pw')) {
        $p12File = $tmp . DIRECTORY_SEPARATOR . 'sig.p12';
        file_put_contents($p12File, $p12);
        $pp = make([], static fn(IvoPdf $p) => $p->setSignature($p12File, '', 'p12pw'));
        $ep = $extract($pp);
        check('signature from PKCS#12 file', $verify($ep['signed'], $ep['der']));
    }

    $threw = false;
    try {
        make([], static fn(IvoPdf $p) => $p->setSignature($certPem, $keyPem, 'wrong-password'));
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    check('wrong key password raises InvalidArgumentException', $threw);
}

// ---------------------------------------------------------------------------
echo "\n5. Hygiene\n";
// ---------------------------------------------------------------------------
check('no PHP warnings/notices/deprecations', empty($phpIssues), count($phpIssues) . ' issues; first: ' . ($phpIssues[0] ?? ''));

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
