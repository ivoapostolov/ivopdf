<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * PDF/X helper (X-1a:2003, X-3:2003, X-4): option normalization, option-conflict validation, page boxes with bleed
 * and crop marks, OutputIntent, Info entries and XMP metadata.
 *
 * X-1a and X-3 forbid transparency and device RGB (everything is converted to CMYK/Gray, no ICC conversion is done);
 * X-4 keeps RGB and transparency and requires only the CMYK OutputIntent.
 */
final class PdfX
{
    private const MM = 72.0 / 25.4;

    /** Normalizes a pdfx option ('', true, 'X-1a', 'x1a:2003', 'X-3', 'X-4', 'PDF/X-4') to '' | 'X-1a' | 'X-3' | 'X-4'. */
    public static function normalize(string|bool|null $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return 'X-1a';
        }
        $v = strtolower(trim($value));
        if ($v === '' || $v === '0' || $v === 'false' || $v === 'off' || $v === 'no') {
            return '';
        }
        if (preg_match('~x[-_ ]?4~', $v) === 1) {
            return 'X-4';
        }
        if (preg_match('~x[-_ ]?3~', $v) === 1) {
            return 'X-3';
        }
        return 'X-1a'; // 'x1a', 'x-1a:2003', '1', 'true' ...
    }

    public static function level(Options $o): string
    {
        return self::normalize($o->pdfx);
    }

    /** True when the document must not contain any device RGB (PDF/X-1a, X-3 or colorSpace = cmyk). */
    public static function forcesCmyk(Options $o): bool
    {
        $lvl = self::level($o);
        return strtolower(trim($o->colorSpace)) === 'cmyk' || $lvl === 'X-1a' || $lvl === 'X-3';
    }

    public static function forbidsTransparency(Options $o): bool
    {
        $lvl = self::level($o);
        return $lvl === 'X-1a' || $lvl === 'X-3';
    }

    /** PDF header version for the level. */
    public static function version(string $level): string
    {
        return $level === 'X-4' ? '1.6' : '1.4';
    }

    /**
     * @throws \LogicException for option combinations PDF/X forbids
     */
    public static function validate(Options $o): void
    {
        $lvl = self::level($o);
        if ($lvl === '') {
            return;
        }
        if (PdfFinisher::parsePdfa($o->pdfa) !== null) {
            throw new \LogicException('PDF/X and PDF/A are mutually exclusive: disable either the "pdfx" or the "pdfa" option.');
        }
        if (!empty($o->encryption)) {
            throw new \LogicException('PDF/X forbids encryption: disable either the "pdfx" option or setProtection()/"encryption".');
        }
        if (!empty($o->signature)) {
            throw new \LogicException('PDF/X forbids interactive signature fields: disable either the "pdfx" option or setSignature().');
        }
        if ($o->pdfxIccProfile !== '' && \IvoPdf\Options::isNetworkPath($o->pdfxIccProfile)) {
            throw new \LogicException('PDF/X ICC profile refused (UNC / network or stream-wrapper paths are not read): ' . $o->pdfxIccProfile);
        }
        if ($o->pdfxIccProfile !== '' && !is_file($o->pdfxIccProfile)) {
            throw new \LogicException('PDF/X ICC profile not found: ' . $o->pdfxIccProfile);
        }
    }

    public static function bleedPt(Options $o): float
    {
        return max(0.0, $o->bleed) * self::MM;
    }

    /** True when pages need explicit TrimBox / BleedBox (PDF/X, bleed or crop marks). */
    public static function needsBoxes(Options $o): bool
    {
        return self::level($o) !== '' || $o->bleed > 0.0 || $o->cropMarks;
    }

    /**
     * Page boxes for a trim-size page: /MediaBox /TrimBox /BleedBox (the page content keeps its coordinates, the
     * boxes extend into negative space for bleed and marks).
     */
    public static function pageBoxes(float $w, float $h, Options $o): string
    {
        $b = self::bleedPt($o);
        $ext = $b;
        if ($o->cropMarks) {
            $ext = max($b, 3.0 * self::MM) + 5.0 * self::MM;
        }
        return sprintf(
            '/MediaBox [%.2F %.2F %.2F %.2F] /TrimBox [0 0 %.2F %.2F] /BleedBox [%.2F %.2F %.2F %.2F]',
            -$ext,
            -$ext,
            $w + $ext,
            $h + $ext,
            $w,
            $h,
            -$b,
            -$b,
            $w + $b,
            $h + $b
        );
    }

    /** Crop mark drawing operators (registration color) outside the bleed area, or '' when disabled. */
    public static function cropMarks(float $w, float $h, Options $o): string
    {
        if (!$o->cropMarks) {
            return '';
        }
        $off = max(self::bleedPt($o), 3.0 * self::MM);
        $len = 5.0 * self::MM;
        $color = ColorOps::isCmykTarget() ? '1 1 1 1 K' : '0 G';
        $out = "q 0.25 w {$color}\n";
        foreach ([0.0, $h] as $y) {
            $out .= sprintf("%.2F %.2F m %.2F %.2F l S\n%.2F %.2F m %.2F %.2F l S\n", -$off - $len, $y, -$off, $y, $w + $off, $y, $w + $off + $len, $y);
        }
        foreach ([0.0, $w] as $x) {
            $out .= sprintf("%.2F %.2F m %.2F %.2F l S\n%.2F %.2F m %.2F %.2F l S\n", $x, -$off - $len, $x, -$off, $x, $h + $off, $x, $h + $off + $len);
        }
        return $out . "Q\n";
    }

    /**
     * Extra /Info dictionary entries (raw PDF syntax).
     */
    public static function infoEntries(Options $o): string
    {
        $lvl = self::level($o);
        if ($lvl === '') {
            return '';
        }
        $entries = ' /Trapped /False';
        $ver = self::versionString($lvl);
        $entries .= ' /GTS_PDFXVersion (' . $ver . ')';
        if ($lvl !== 'X-4') {
            $entries .= ' /GTS_PDFXConformance (' . $ver . ')';
        }
        return $entries;
    }

    public static function versionString(string $level): string
    {
        return match ($level) {
            'X-3' => 'PDF/X-3:2003',
            'X-4' => 'PDF/X-4',
            default => 'PDF/X-1a:2003',
        };
    }

    /**
     * Adds the XMP metadata stream and the GTS_PDFX OutputIntent; returns the catalog entries.
     *
     * @param array<int, string> $objects
     */
    public static function build(array &$objects, int &$counter, Options $o, int $timestamp): string
    {
        $lvl = self::level($o);
        $cond = trim($o->pdfxOutputCondition) !== '' ? trim($o->pdfxOutputCondition) : 'FOGRA39';
        $esc = static fn(string $s): string => addcslashes($s, '()\\');

        $profileRef = '';
        if ($o->pdfxIccProfile !== '') {
            $icc = (string) file_get_contents($o->pdfxIccProfile);
            $space = substr($icc, 16, 4);
            $n = match ($space) {
                'CMYK' => 4,
                'GRAY' => 1,
                default => 3,
            };
            $iccId = ++$counter;
            if ($o->compress) {
                $z = (string) gzcompress($icc, 6);
                $objects[$iccId] = sprintf("<< /N %d /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", $n, strlen($z), $z);
            } else {
                $objects[$iccId] = sprintf("<< /N %d /Length %d >>\nstream\n%s\nendstream", $n, strlen($icc), $icc);
            }
            $profileRef = " /DestOutputProfile {$iccId} 0 R";
        }
        $intentId = ++$counter;
        $objects[$intentId] = '<< /Type /OutputIntent /S /GTS_PDFX /OutputConditionIdentifier (' . $esc($cond) . ') /OutputCondition (' . $esc($cond)
            . ') /RegistryName (http://www.color.org) /Info (' . $esc($cond) . ')' . $profileRef . ' >>';

        $xmp = self::xmp($o, $lvl, $timestamp);
        $metaId = ++$counter;
        $objects[$metaId] = sprintf("<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream", strlen($xmp), $xmp);

        return " /Metadata {$metaId} 0 R /OutputIntents [{$intentId} 0 R]";
    }

    private static function xmp(Options $o, string $lvl, int $timestamp): string
    {
        $x = static fn(string $s): string => htmlspecialchars((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $date = gmdate('Y-m-d\TH:i:s', $timestamp) . '+00:00';
        $title = $o->title !== '' ? $o->title : 'Untitled';
        $dc = '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $x($title) . '</rdf:li></rdf:Alt></dc:title>';
        if ($o->author !== '') {
            $dc .= '<dc:creator><rdf:Seq><rdf:li>' . $x($o->author) . '</rdf:li></rdf:Seq></dc:creator>';
        }
        $dc .= '<dc:format>application/pdf</dc:format>';
        $pdfProps = '<pdf:Producer>' . $x($o->creator) . '</pdf:Producer><pdf:Trapped>False</pdf:Trapped>';
        if ($o->keywords !== '') {
            $pdfProps .= '<pdf:Keywords>' . $x($o->keywords) . '</pdf:Keywords>';
        }
        $ver = self::versionString($lvl);
        $pdfx = '<pdfx:GTS_PDFXVersion>' . $ver . '</pdfx:GTS_PDFXVersion>';
        if ($lvl !== 'X-4') {
            $pdfx .= '<pdfx:GTS_PDFXConformance>' . $ver . '</pdfx:GTS_PDFXConformance>';
        }
        return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/"><pdfxid:GTS_PDFXVersion>' . $ver . '</pdfxid:GTS_PDFXVersion></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdfx="http://ns.adobe.com/pdfx/1.3/">' . $pdfx . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">' . $dc . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">'
            . '<xmp:CreatorTool>' . $x($o->creator) . '</xmp:CreatorTool>'
            . '<xmp:CreateDate>' . $date . '</xmp:CreateDate><xmp:ModifyDate>' . $date . '</xmp:ModifyDate><xmp:MetadataDate>' . $date . '</xmp:MetadataDate></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . $pdfProps . '</rdf:Description>'
            . "</rdf:RDF></x:xmpmeta>\n<?xpacket end=\"w\"?>";
    }
}
