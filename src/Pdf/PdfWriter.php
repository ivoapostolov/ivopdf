<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Serializes PDF objects into a file with an xref table. Optionally encrypts strings/streams per object
 * and finalizes a digital signature (patching /ByteRange and /Contents) after everything else is written.
 */
final class PdfWriter
{
    /**
     * @param array<int, string> $objects objNum => body (dictionary, optionally followed by "\nstream\n...\nendstream")
     * @param string $documentId 16 raw bytes written as both halves of the trailer /ID
     * @param ?int $encryptObjId object holding the /Encrypt dictionary (never encrypted)
     * @param ?int $sigObjId object holding the /Sig dictionary (its /Contents is never encrypted)
     */
    public function write(
        array $objects,
        int $maxId,
        int $catalogId,
        int $infoId,
        string $documentId,
        string $version = '1.7',
        ?PdfEncryption $encryption = null,
        ?int $encryptObjId = null,
        ?PdfSignature $signature = null,
        ?int $sigObjId = null
    ): string {
        ksort($objects);
        $out = "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $sigOffset = 0;

        foreach ($objects as $id => $body) {
            if ($encryption !== null && $id !== $encryptObjId) {
                $body = $this->encryptObject($body, $id, $encryption, $id === $sigObjId);
            }
            $offsets[$id] = strlen($out);
            if ($id === $sigObjId) {
                $sigOffset = strlen($out);
            }
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out .= "xref\n0 " . ($maxId + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $idHex = strtoupper(bin2hex($documentId));
        $out .= sprintf(
            "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /ID [<%s> <%s>]%s >>\nstartxref\n%d\n%%%%EOF\n",
            $maxId + 1,
            $catalogId,
            $infoId,
            $idHex,
            $idHex,
            $encryptObjId !== null ? " /Encrypt {$encryptObjId} 0 R" : '',
            $xrefOffset
        );

        if ($signature !== null && $sigObjId !== null) {
            $out = $this->applySignature($out, $sigOffset, $signature);
        }
        return $out;
    }

    private function applySignature(string $pdf, int $sigOffset, PdfSignature $signature): string
    {
        $placeholder = '/Contents <' . str_repeat('0', PdfSignature::CONTENTS_BYTES * 2) . '>';
        $contentsPos = strpos($pdf, $placeholder, $sigOffset);
        $brPos = strpos($pdf, '/ByteRange [', $sigOffset);
        if ($contentsPos === false || $brPos === false) {
            throw new \RuntimeException('Signature placeholders not found in the generated PDF.');
        }
        $hexStart = $contentsPos + strlen('/Contents ');
        $hexEnd = $hexStart + PdfSignature::CONTENTS_BYTES * 2 + 2; // after the closing '>'
        $range = PdfSignature::byteRange($hexStart, $hexEnd, strlen($pdf) - $hexEnd);
        $placeholderRange = PdfSignature::byteRange(0, 0, 0);
        if (substr($pdf, $brPos, strlen($placeholderRange)) !== $placeholderRange) {
            throw new \RuntimeException('Unexpected /ByteRange placeholder.');
        }
        $pdf = substr_replace($pdf, $range, $brPos, strlen($placeholderRange));

        $signed = substr($pdf, 0, $hexStart) . substr($pdf, $hexEnd);
        $der = $signature->sign($signed);
        $hex = strtoupper(bin2hex($der));
        $hex .= str_repeat('0', PdfSignature::CONTENTS_BYTES * 2 - strlen($hex));
        return substr_replace($pdf, '<' . $hex . '>', $hexStart, $hexEnd - $hexStart);
    }

    private function encryptObject(string $body, int $objNum, PdfEncryption $enc, bool $isSignature): string
    {
        $skipNames = $isSignature ? ['Contents'] : [];
        $isMetadata = !$enc->encryptsMetadata() && str_contains(substr($body, 0, 200), '/Type /Metadata');

        $sepFull = ">>\nstream\n";
        $sep = "\nstream\n";
        $pos = strpos($body, $sepFull);
        if ($pos === false) {
            return $this->encryptStrings($body, $objNum, $enc, $skipNames);
        }
        $dict = substr($body, 0, $pos + 2);
        $data = substr($body, $pos + strlen($sepFull), -strlen("\nendstream"));

        if ($isMetadata) {
            $dict = substr($dict, 0, -2) . ' /Filter [/Crypt] /DecodeParms [<< /Type /CryptFilterDecodeParms /Name /Identity >>] >>';
            return $dict . $sep . $data . "\nendstream";
        }
        $dict = $this->encryptStrings($dict, $objNum, $enc, []);
        $data = $enc->encrypt($data, $objNum);
        $count = 0;
        $dict = (string) preg_replace('~/Length(\s+)\d+(?![\d]|\s+\d+\s+R)~', '/Length${1}' . strlen($data), $dict, 1, $count);
        if ($count === 0) {
            $dict = substr($dict, 0, -2) . ' /Length ' . strlen($data) . ' >>';
        }
        return $dict . $sep . $data . "\nendstream";
    }

    /**
     * Rewrites every literal and hex string token of a PDF dictionary/array body as an encrypted hex string.
     *
     * @param list<string> $skipAfterNames strings directly following these names stay untouched
     */
    private function encryptStrings(string $s, int $objNum, PdfEncryption $enc, array $skipAfterNames): string
    {
        $len = strlen($s);
        $out = '';
        $pos = 0;
        $lastName = '';
        while ($pos < $len) {
            $next = $pos + strcspn($s, '(</', $pos);
            $out .= substr($s, $pos, $next - $pos);
            if ($next >= $len) {
                break;
            }
            $c = $s[$next];
            if ($c === '/') {
                preg_match('~/[^\s()<>\[\]{}/%]*~A', $s, $m, 0, $next);
                $lastName = substr($m[0], 1);
                $out .= $m[0];
                $pos = $next + strlen($m[0]);
                continue;
            }
            if ($c === '<' && ($s[$next + 1] ?? '') === '<') {
                $out .= '<<';
                $pos = $next + 2;
                continue;
            }
            if ($c === '<') {
                $end = strpos($s, '>', $next);
                $end = $end === false ? $len - 1 : $end;
                $token = substr($s, $next, $end - $next + 1);
                $pos = $end + 1;
                if (in_array($lastName, $skipAfterNames, true)) {
                    $out .= $token;
                    continue;
                }
                $hex = (string) preg_replace('~[^0-9A-Fa-f]~', '', $token);
                if (strlen($hex) % 2 === 1) {
                    $hex .= '0';
                }
                $out .= '<' . strtoupper(bin2hex($enc->encrypt((string) hex2bin($hex), $objNum))) . '>';
                continue;
            }
            // literal string "(...)" with nesting and escapes
            $depth = 0;
            $raw = '';
            $i = $next;
            while ($i < $len) {
                $ch = $s[$i];
                if ($ch === '\\') {
                    $esc = $s[$i + 1] ?? '';
                    $i += 2;
                    if ($esc >= '0' && $esc <= '7') {
                        $oct = $esc;
                        while (strlen($oct) < 3 && $i < $len && $s[$i] >= '0' && $s[$i] <= '7') {
                            $oct .= $s[$i++];
                        }
                        $raw .= chr(octdec($oct) & 0xFF);
                    } elseif ($esc === "\r") {
                        if (($s[$i] ?? '') === "\n") {
                            $i++;
                        }
                    } elseif ($esc === "\n") {
                        // line continuation
                    } else {
                        $raw .= match ($esc) {
                            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                            default => $esc,
                        };
                    }
                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                    if ($depth > 1) {
                        $raw .= $ch;
                    }
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $i++;
                        break;
                    }
                    $raw .= $ch;
                } else {
                    $raw .= $ch;
                }
                $i++;
            }
            if (in_array($lastName, $skipAfterNames, true)) {
                $out .= substr($s, $next, $i - $next);
            } else {
                $out .= '<' . strtoupper(bin2hex($enc->encrypt($raw, $objNum))) . '>';
            }
            $pos = $i;
        }
        return $out;
    }
}
