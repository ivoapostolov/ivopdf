<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * Adds the security-related layers to an already built object table: PDF/A (XMP + sRGB OutputIntent),
 * the merged /AcroForm dictionary, the digital signature field and the /Encrypt dictionary.
 */
final class PdfFinisher
{
    public function __construct(private readonly Options $options)
    {
    }

    /** @var list<string> extra <rdf:Description> XMP snippets (e.g. the Factur-X extension schema) */
    private array $xmpExtensions = [];

    /**
     * @param list<string> $snippets raw <rdf:Description ...>...</rdf:Description> blocks added to the PDF/A XMP packet
     */
    public function setXmpExtensions(array $snippets): void
    {
        $this->xmpExtensions = $snippets;
    }

    /**
     * Normalizes a pdfa option value ('', true, '1b', '2b', '3b', 'pdfa-2b' ...) to ['part' => int, 'conf' => 'B'] or null.
     * PDF/A-1b is produced as real ISO 19005-1 output (see PdfA1Profile); the A/U levels are produced as level B.
     *
     * @return ?array{part: int, conf: string}
     */
    public static function parsePdfa(string $value): ?array
    {
        $v = strtolower(trim($value));
        if ($v === '' || $v === '0' || $v === 'false' || $v === 'off') {
            return null;
        }
        if (preg_match('~([123])\s*([abu])?$~', $v, $m) === 1) {
            $part = (int) $m[1];
            return ['part' => $part, 'conf' => 'B'];
        }
        return ['part' => 2, 'conf' => 'B'];
    }

    /**
     * @param array<int, string> $objects
     * @param list<int> $pageObjIds
     * @param list<array{width: float, height: float}> $pages
     * @param list<int> $acroFields object ids of form fields / widgets for the single /AcroForm
     * @param array<string, string> $acroEntries extra raw /AcroForm entries (key without slash => raw PDF value)
     * @return array{encryption: ?PdfEncryption, encryptObjId: ?int, signature: ?PdfSignature, sigObjId: ?int, documentId: string, version: string}
     */
    public function finish(
        array &$objects,
        int &$counter,
        int $catalogId,
        array $pageObjIds,
        array $pages,
        int $timestamp,
        array $acroFields = [],
        array $acroEntries = []
    ): array {
        $opt = $this->options;
        $pdfa = self::parsePdfa($opt->pdfa);
        $encCfg = $opt->encryption;
        if ($pdfa !== null && !empty($encCfg)) {
            throw new \LogicException('PDF/A forbids encryption: disable either the "pdfa" option or setProtection()/"encryption".');
        }
        $documentId = md5($timestamp . '|' . implode("\0", $objects), true); // deterministic for identical input
        PdfX::validate($opt);
        $pdfxLevel = PdfX::level($opt);
        $catalogExtra = '';
        if ($pdfxLevel !== '') {
            $catalogExtra .= PdfX::build($objects, $counter, $opt, $timestamp);
        }

        if ($pdfa !== null) {
            $catalogExtra .= $this->buildPdfa($objects, $counter, $pdfa, $timestamp);
            foreach ($objects as $id => $body) {
                if (str_contains($body, '/Subtype /Link') && !preg_match('~/F\s+\d~', $body)) {
                    $objects[$id] = self::appendEntries($body, '/F 4');
                }
            }
        }

        // Digital signature field
        $signature = null;
        $sigObjId = null;
        $sigCfg = $opt->signature;
        if (!empty($sigCfg)) {
            $signature = new PdfSignature(
                (string) $sigCfg['cert'],
                (string) ($sigCfg['key'] ?? ''),
                (string) ($sigCfg['password'] ?? ''),
                (string) ($sigCfg['extra'] ?? '')
            );
            $signature->cades = strcasecmp((string) ($sigCfg['subFilter'] ?? ''), 'ETSI.CAdES.detached') === 0;
            $sigObjId = $this->buildSignatureField($objects, $counter, $sigCfg, $signature->cades, $pageObjIds, $pages, $timestamp, $pdfa !== null, $acroFields);
        }

        // One merged /AcroForm
        if ($sigObjId !== null) {
            $acroEntries += ['SigFlags' => '3'];
        }
        if (!empty($acroFields) || !empty($acroEntries)) {
            $catalogExtra = $this->mergeAcroForm($objects, $catalogId, $acroFields, $acroEntries) . $catalogExtra;
        }
        if ($catalogExtra !== '') {
            $objects[$catalogId] = self::appendEntries($objects[$catalogId], trim($catalogExtra));
        }

        // PDF/A-1b: flatten transparency, add /CIDSet, header 1.4 (see PdfA1Profile)
        if ($pdfa !== null && $pdfa['part'] === 1) {
            PdfA1Profile::apply($objects, $counter, $catalogId, $opt);
        }

        // PDF/X-1a / X-3 (and colorSpace = cmyk): flatten transparency, convert leftover RGB shadings
        if ($pdfxLevel !== '' || ColorOps::isCmykTarget()) {
            PdfXProfile::apply($objects, $opt);
        }

        // Encryption (last, so the /Encrypt dictionary stays out of the encrypted object set)
        $encryption = null;
        $encryptObjId = null;
        $version = ($pdfa !== null && $pdfa['part'] === 1) ? '1.4' : '1.7';
        if ($pdfxLevel !== '') {
            $version = PdfX::version($pdfxLevel);
        }
        if (!empty($encCfg)) {
            $encryption = new PdfEncryption(
                (array) ($encCfg['permissions'] ?? ['print', 'copy']),
                (string) ($encCfg['user'] ?? ''),
                isset($encCfg['owner']) ? (string) $encCfg['owner'] : null,
                $encCfg['method'] ?? 'aes256',
                $documentId,
                (bool) ($encCfg['encryptMetadata'] ?? true)
            );
            $encryptObjId = ++$counter;
            $objects[$encryptObjId] = $encryption->dictionary();
            $version = $encryption->getMinimumVersion();
        }

        return [
            'encryption' => $encryption,
            'encryptObjId' => $encryptObjId,
            'signature' => $signature,
            'sigObjId' => $sigObjId,
            'documentId' => $documentId,
            'version' => $version,
        ];
    }

    /**
     * Appends raw dictionary entries before the closing ">>" of a dictionary object body.
     */
    public static function appendEntries(string $body, string $entries): string
    {
        $trim = rtrim($body);
        if (!str_ends_with($trim, '>>')) {
            return $body;
        }
        return substr($trim, 0, -2) . rtrim($entries) . ' >>' . substr($body, strlen($trim));
    }

    /**
     * @param array<int, string> $objects
     * @param array{part: int, conf: string} $pdfa
     */
    private function buildPdfa(array &$objects, int &$counter, array $pdfa, int $timestamp): string
    {
        $opt = $this->options;

        $icc = IccProfile::srgb();
        $iccId = ++$counter;
        if ($opt->compress) {
            $z = (string) gzcompress($icc, 6);
            $objects[$iccId] = sprintf("<< /N 3 /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($z), $z);
        } else {
            $objects[$iccId] = sprintf("<< /N 3 /Length %d >>\nstream\n%s\nendstream", strlen($icc), $icc);
        }

        $intentId = ++$counter;
        $objects[$intentId] = "<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /RegistryName (http://www.color.org) /Info (sRGB IEC61966-2.1) /DestOutputProfile {$iccId} 0 R >>";

        $xmp = $this->buildXmp($pdfa, $timestamp);
        $metaId = ++$counter;
        $objects[$metaId] = sprintf("<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream", strlen($xmp), $xmp);

        return " /Metadata {$metaId} 0 R /OutputIntents [{$intentId} 0 R]";
    }

    /**
     * @param array{part: int, conf: string} $pdfa
     */
    private function buildXmp(array $pdfa, int $timestamp): string
    {
        $opt = $this->options;
        $x = static fn(string $s): string => htmlspecialchars((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $date = gmdate('Y-m-d\TH:i:s', $timestamp) . '+00:00';

        $dc = '';
        if ($opt->title !== '') {
            $dc .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $x($opt->title) . '</rdf:li></rdf:Alt></dc:title>';
        }
        if ($opt->author !== '') {
            $dc .= '<dc:creator><rdf:Seq><rdf:li>' . $x($opt->author) . '</rdf:li></rdf:Seq></dc:creator>';
        }
        if ($opt->subject !== '') {
            $dc .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $x($opt->subject) . '</rdf:li></rdf:Alt></dc:description>';
        }
        $dc .= '<dc:format>application/pdf</dc:format>';

        $pdfProps = '<pdf:Producer>' . $x($opt->creator) . '</pdf:Producer>';
        if ($opt->keywords !== '') {
            $pdfProps .= '<pdf:Keywords>' . $x($opt->keywords) . '</pdf:Keywords>';
        }

        return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">'
            . '<pdfaid:part>' . $pdfa['part'] . '</pdfaid:part><pdfaid:conformance>' . $pdfa['conf'] . '</pdfaid:conformance></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">' . $dc . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">'
            . '<xmp:CreatorTool>' . $x($opt->creator) . '</xmp:CreatorTool>'
            . '<xmp:CreateDate>' . $date . '</xmp:CreateDate><xmp:ModifyDate>' . $date . '</xmp:ModifyDate><xmp:MetadataDate>' . $date . '</xmp:MetadataDate></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . $pdfProps . '</rdf:Description>'
            . implode('', $this->xmpExtensions)
            . "</rdf:RDF></x:xmpmeta>\n<?xpacket end=\"w\"?>";
    }

    /**
     * Creates the /Sig dictionary, the signature widget and its appearance; returns the /Sig object id.
     *
     * @param array<int, string> $objects
     * @param array<string, mixed> $cfg
     * @param list<int> $pageObjIds
     * @param list<array{width: float, height: float}> $pages
     * @param list<int> $acroFields
     */
    private function buildSignatureField(array &$objects, int &$counter, array $cfg, bool $cades, array $pageObjIds, array $pages, int $timestamp, bool $pdfa, array &$acroFields): int
    {
        $info = array_change_key_case((array) ($cfg['info'] ?? []), CASE_LOWER);
        $dict = '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter ' . ($cades ? '/ETSI.CAdES.detached' : '/adbe.pkcs7.detached')
            . ' ' . PdfSignature::byteRange(0, 0, 0) . ' /Contents <' . str_repeat('0', PdfSignature::CONTENTS_BYTES * 2) . '>'
            . " /M (D:" . gmdate('YmdHis', $timestamp) . "+00'00')";
        foreach (['name' => 'Name', 'location' => 'Location', 'reason' => 'Reason', 'contactinfo' => 'ContactInfo'] as $k => $key) {
            if (isset($info[$k]) && trim((string) $info[$k]) !== '') {
                $dict .= " /{$key} " . self::pdfText((string) $info[$k]);
            }
        }
        $dict .= ' >>';
        $sigId = ++$counter;
        $objects[$sigId] = $dict;

        $rect = $cfg['rect'] ?? null;
        $pageIdx = 0;
        $r = [0.0, 0.0, 0.0, 0.0];
        if (is_array($rect) && count($rect) >= 5) {
            $pageIdx = max(0, min(count($pageObjIds) - 1, (int) $rect[0] - 1));
            $h = (float) ($pages[$pageIdx]['height'] ?? 842.0);
            $r = [(float) $rect[1], $h - (float) $rect[2] - (float) $rect[4], (float) $rect[1] + (float) $rect[3], $h - (float) $rect[2]];
        }
        $w = $r[2] - $r[0];
        $hgt = $r[3] - $r[1];

        // Appearance stream
        $content = '';
        $resources = '';
        if ($w > 0 && $hgt > 0) {
            $content = sprintf("0.6 G 0.5 w 0.25 0.25 %.2F %.2F re S\n", $w - 0.5, $hgt - 0.5);
            if (!$pdfa) {
                $lines = ['Digitally signed' . (isset($info['name']) && $info['name'] !== '' ? ' by ' . $info['name'] : '')];
                foreach (['reason' => 'Reason', 'location' => 'Location'] as $k => $label) {
                    if (!empty($info[$k])) {
                        $lines[] = "{$label}: " . $info[$k];
                    }
                }
                $lines[] = 'Date: ' . gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
                $size = max(5.0, min(9.0, ($hgt - 6) / (count($lines) * 1.2)));
                $content .= sprintf("BT /Helv %.1F Tf 0 g %.1F TL 3 %.2F Td\n", $size, $size * 1.2, $hgt - 3 - $size);
                foreach ($lines as $i => $line) {
                    $win = (string) @mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
                    $esc = strtr($win, ['\\' => '\\\\', '(' => '\(', ')' => '\)', "\r" => '', "\n" => ' ']);
                    $content .= ($i > 0 ? 'T* ' : '') . "({$esc}) Tj\n";
                }
                $content .= "ET\n";
                $resources = ' /Resources << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >>';
            }
        }
        $apId = ++$counter;
        $objects[$apId] = sprintf(
            "<< /Type /XObject /Subtype /Form /BBox [0 0 %.2F %.2F]%s /Length %d >>\nstream\n%s\nendstream",
            max(0.0, $w),
            max(0.0, $hgt),
            $resources,
            strlen($content),
            $content
        );

        $widgetId = ++$counter;
        $objects[$widgetId] = sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /V %d 0 R /F 132 /Rect [%.2F %.2F %.2F %.2F] /P %d 0 R /AP << /N %d 0 R >> >>',
            $sigId,
            $r[0],
            $r[1],
            $r[2],
            $r[3],
            $pageObjIds[$pageIdx],
            $apId
        );
        $this->addPageAnnot($objects, $pageObjIds[$pageIdx], $widgetId);
        $acroFields[] = $widgetId;
        return $sigId;
    }

    /**
     * @param array<int, string> $objects
     */
    private function addPageAnnot(array &$objects, int $pageObjId, int $annotId): void
    {
        $body = $objects[$pageObjId];
        if (preg_match('~/Annots\s*\[~', $body) === 1) {
            $objects[$pageObjId] = (string) preg_replace('~(/Annots\s*\[)~', '${1}' . "{$annotId} 0 R ", $body, 1);
        } elseif (preg_match('~/Annots\s+(\d+)\s+0\s+R~', $body, $m) === 1 && isset($objects[(int) $m[1]])) {
            $arr = $objects[(int) $m[1]];
            $objects[(int) $m[1]] = (string) preg_replace('~\[~', "[{$annotId} 0 R ", $arr, 1);
        } else {
            $objects[$pageObjId] = self::appendEntries($body, "/Annots [{$annotId} 0 R]");
        }
    }

    /**
     * Builds the catalog fragment for /AcroForm (or merges into one that already exists).
     *
     * @param array<int, string> $objects
     * @param list<int> $fields
     * @param array<string, string> $entries
     */
    private function mergeAcroForm(array &$objects, int $catalogId, array $fields, array $entries): string
    {
        $refs = implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", array_values(array_unique($fields))));
        $extra = '';
        foreach ($entries as $k => $v) {
            $extra .= " /{$k} {$v}";
        }
        $cat = $objects[$catalogId];
        if (preg_match('~/AcroForm\s+(\d+)\s+0\s+R~', $cat, $m) === 1 && isset($objects[(int) $m[1]])) {
            $objects[(int) $m[1]] = $this->mergeIntoAcroDict($objects[(int) $m[1]], $refs, $entries);
            return '';
        }
        if (str_contains($cat, '/AcroForm')) {
            $objects[$catalogId] = $this->mergeIntoAcroDict($cat, $refs, $entries, true);
            return '';
        }
        return " /AcroForm << /Fields [{$refs}]{$extra} >>";
    }

    /**
     * @param array<string, string> $entries
     */
    private function mergeIntoAcroDict(string $body, string $refs, array $entries, bool $inCatalog = false): string
    {
        if ($refs !== '') {
            if (preg_match('~/Fields\s*\[~', $body) === 1) {
                $body = (string) preg_replace('~(/Fields\s*\[)~', '${1}' . $refs . ' ', $body, 1);
            } else {
                $body = (string) preg_replace('~(/AcroForm\s*<<|^<<)~', '${1} /Fields [' . $refs . ']', $body, 1);
            }
        }
        foreach ($entries as $k => $v) {
            if (preg_match('~/' . preg_quote($k, '~') . '\b~', $body) !== 1) {
                $body = (string) preg_replace('~(/AcroForm\s*<<|^<<)~', '${1} /' . $k . ' ' . $v, $body, 1);
            }
        }
        return $body;
    }

    /** PDF text string: literal for plain ASCII, UTF-16BE hex otherwise. */
    public static function pdfText(string $s): string
    {
        if (preg_match('~^[\x20-\x7E]*$~', $s) === 1) {
            return '(' . strtr($s, ['\\' => '\\\\', '(' => '\(', ')' => '\)']) . ')';
        }
        return '<FEFF' . strtoupper(bin2hex((string) @mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
