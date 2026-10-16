<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Pdf\Import\PdfDict;
use IvoPdf\Pdf\Import\PdfImportException;
use IvoPdf\Pdf\Import\PdfName;
use IvoPdf\Pdf\Import\PdfReader;
use IvoPdf\Pdf\Import\PdfRef;
use IvoPdf\Pdf\Import\PdfStream;
use IvoPdf\Pdf\Import\PdfString;

/**
 * Heuristic PDF/A pre-check for generated PDFs.
 *
 * IMPORTANT: this is NOT a conformance validator and does not replace veraPDF (or Acrobat's preflight). It scans
 * the objects reachable from the catalog for the most common PDF/A violations (header version, missing XMP /
 * OutputIntent / trailer ID, encryption, JavaScript and other forbidden actions, fonts that are not embedded,
 * missing /CIDSet in PDF/A-1 font subsets, transparency in PDF/A-1, LZW, annotation flags, ...). A clean result
 * means "no known violation found", never "certified conformant"; it does not parse content streams beyond a few
 * regular expressions, does not validate the XMP against the PDF/A schemas and does not check glyph widths or
 * colour-space/OutputIntent compatibility in depth.
 */
final class PdfAChecker
{
    private const FORBIDDEN_ACTIONS = ['JavaScript', 'Launch', 'Sound', 'Movie', 'ResetForm', 'ImportData', 'Hide', 'SetOCGState', 'Rendition', 'Trans', 'GoTo3DView'];

    private PdfReader $reader;
    /** @var array<string, true> */
    private array $issues = [];
    /** @var array<int, true> */
    private array $seen = [];
    private int $part = 2;
    private int $budget = 200000;

    /**
     * @param int $expectedPart 1, 2 or 3; 0 detects the part from the XMP metadata (falling back to 2)
     * @return list<string> human readable issues; empty when no known violation was found
     */
    public static function check(string $pdf, int $expectedPart = 0): array
    {
        $c = new self();
        try {
            return $c->run($pdf, $expectedPart);
        } catch (\Throwable $e) {
            // a damaged stream must never crash the pre-check: report what was found so far plus the failure
            return array_merge(array_keys($c->issues), ['Checker aborted on damaged data: ' . $e->getMessage()]);
        }
    }

    /** @return list<string> */
    private function run(string $pdf, int $expectedPart): array
    {
        // ---- raw-file checks -------------------------------------------------
        if (preg_match('/^%PDF-(\d)\.(\d)/', $pdf, $hm) !== 1) {
            return ['Missing %PDF- header'];
        }
        $minor = (int) $hm[2];
        $major = (int) $hm[1];
        try {
            $this->reader = new PdfReader($pdf);
        } catch (PdfImportException $e) {
            $msg = $e->getMessage();
            return [str_contains($msg, 'ncrypt') ? 'Document is encrypted (forbidden in PDF/A)' : 'Unreadable PDF: ' . $msg];
        }
        $catalog = $this->reader->getCatalog();
        $trailer = $this->reader->getTrailer();
        if ($catalog === null || $trailer === null) {
            return ['No document catalog found'];
        }

        // ---- XMP / part detection ---------------------------------------------
        $xmp = '';
        $meta = $this->reader->resolve($catalog->get('Metadata'));
        if ($meta instanceof PdfStream) {
            if ($meta->has('Filter')) {
                $this->add('XMP metadata stream must not have a /Filter');
            }
            $xmp = $this->reader->decodeStream($meta);
        } else {
            $this->add('Catalog has no /Metadata (XMP) stream');
        }
        $xmpPart = null;
        $xmpConf = null;
        if ($xmp !== '') {
            if (preg_match('~<pdfaid:part>\s*(\d)\s*</pdfaid:part>~', $xmp, $m) === 1 || preg_match('~pdfaid:part="(\d)"~', $xmp, $m) === 1) {
                $xmpPart = (int) $m[1];
            }
            if (preg_match('~<pdfaid:conformance>\s*([A-Za-z])\s*</pdfaid:conformance>~', $xmp, $m) === 1 || preg_match('~pdfaid:conformance="([A-Za-z])"~', $xmp, $m) === 1) {
                $xmpConf = strtoupper($m[1]);
            }
            if ($xmpPart === null || $xmpConf === null) {
                $this->add('XMP lacks pdfaid:part / pdfaid:conformance identification');
            }
        }
        $this->part = $expectedPart > 0 ? $expectedPart : ($xmpPart ?? 2);
        if ($xmpPart !== null && $expectedPart > 0 && $xmpPart !== $expectedPart) {
            $this->add("XMP declares pdfaid:part {$xmpPart} but PDF/A-{$expectedPart} was requested");
        }
        if ($xmpConf !== null && !in_array($xmpConf, ['A', 'B', 'U'], true)) {
            $this->add("Invalid pdfaid:conformance '{$xmpConf}'");
        }

        // ---- header, trailer ---------------------------------------------------
        $maxMinor = $this->part === 1 ? 4 : 7;
        if ($major !== 1 || $minor > $maxMinor) {
            $this->add("Header version %PDF-{$major}.{$minor} is not allowed for PDF/A-{$this->part} (maximum 1.{$maxMinor})");
        }
        if (preg_match('/^%PDF-\d\.\d\r?\n%[\x80-\xFF]{4}/', $pdf) !== 1) {
            $this->add('The second line should be a comment with at least four bytes >127 (binary marker)');
        }
        if ($trailer->has('Encrypt')) {
            $this->add('Document is encrypted (forbidden in PDF/A)');
        }
        $id = $this->reader->resolve($trailer->get('ID'));
        if (!is_array($id) || count($id) < 2) {
            $this->add('Trailer has no /ID array');
        }
        if ($this->part === 1) {
            if (preg_match('~/Type\s*/ObjStm~', $pdf) === 1) {
                $this->add('Object streams (/ObjStm) are not allowed in PDF/A-1');
            }
            if (preg_match('~/Type\s*/XRef~', $pdf) === 1) {
                $this->add('Cross-reference streams are not allowed in PDF/A-1');
            }
        }
        if (preg_match('~endobj\s*\S~', $pdf) === 1 && preg_match('~startxref\s+\d+\s+%%EOF\s*(?:\z)~', $pdf) !== 1) {
            // trailing garbage / missing EOF marker
            if (preg_match('~%%EOF\s*\z~', $pdf) !== 1) {
                $this->add('The file does not end with %%EOF');
            }
        }

        // ---- catalog -----------------------------------------------------------
        $this->checkOutputIntent($catalog);
        if ($catalog->has('AA')) {
            $this->add('Catalog contains /AA (additional actions)');
        }
        if ($catalog->has('OCProperties') && $this->part === 1) {
            $this->add('Optional content (/OCProperties) is not allowed in PDF/A-1');
        }
        $names = $this->reader->resolve($catalog->get('Names'));
        if ($names instanceof PdfDict) {
            if ($names->has('JavaScript')) {
                $this->add('Catalog /Names contains /JavaScript');
            }
            if ($names->has('EmbeddedFiles') && $this->part < 3) {
                $this->add('Embedded files are only allowed in PDF/A-3');
            }
        }
        $acro = $this->reader->resolve($catalog->get('AcroForm'));
        if ($acro instanceof PdfDict) {
            if ($acro->has('XFA')) {
                $this->add('AcroForm contains /XFA');
            }
            if ($this->part === 1 && $this->reader->resolve($acro->get('NeedAppearances')) === true) {
                $this->add('AcroForm /NeedAppearances true is not allowed in PDF/A-1');
            }
        }
        if ($this->part === 1 && $catalog->has('Perms')) {
            $this->add('Catalog contains /Perms');
        }

        // ---- Info vs XMP ------------------------------------------------------
        $info = $this->reader->resolve($trailer->get('Info'));
        if ($info instanceof PdfDict && $xmp !== '') {
            $this->checkInfoAgainstXmp($info, $xmp);
        }

        // ---- object walk -------------------------------------------------------
        $this->walk($catalog, '', 0);
        if ($info instanceof PdfDict) {
            $this->walk($info, 'Info', 0);
        }

        return array_keys($this->issues);
    }

    private function add(string $issue): void
    {
        $this->issues[$issue] = true;
    }

    private function checkOutputIntent(PdfDict $catalog): void
    {
        $oi = $this->reader->resolve($catalog->get('OutputIntents'));
        if (!is_array($oi) || $oi === []) {
            $this->add('Catalog has no /OutputIntents');
            return;
        }
        $found = false;
        foreach ($oi as $ref) {
            $d = $this->reader->resolve($ref);
            if (!$d instanceof PdfDict) {
                continue;
            }
            if ($d->name('S') === 'GTS_PDFA1') {
                $found = true;
                $profile = $this->reader->resolve($d->get('DestOutputProfile'));
                if (!$profile instanceof PdfStream) {
                    $this->add('PDF/A OutputIntent has no /DestOutputProfile ICC stream');
                } else {
                    $icc = $this->reader->decodeStream($profile);
                    if (strlen($icc) < 128 || substr($icc, 36, 4) !== 'acsp') {
                        $this->add('OutputIntent /DestOutputProfile is not a valid ICC profile');
                    } elseif ($this->part === 1 && ord($icc[8]) > 2) {
                        $this->add('OutputIntent ICC profile version ' . ord($icc[8]) . ' is not allowed for PDF/A-1 (ICC v2 required)');
                    }
                }
            }
        }
        if (!$found) {
            $this->add('No OutputIntent with /S /GTS_PDFA1');
        }
    }

    private function checkInfoAgainstXmp(PdfDict $info, string $xmp): void
    {
        $text = function (mixed $v): ?string {
            $v = $this->reader->resolve($v);
            if (!$v instanceof PdfString) {
                return null;
            }
            $b = $v->bytes;
            if (str_starts_with($b, "\xFE\xFF")) {
                return (string) mb_convert_encoding(substr($b, 2), 'UTF-8', 'UTF-16BE');
            }
            return $b;
        };
        $xmpText = html_entity_decode((string) preg_replace('~<[^>]*>~', '|', $xmp), ENT_QUOTES | ENT_XML1, 'UTF-8');
        foreach (['Title' => 'dc:title', 'Author' => 'dc:creator', 'Subject' => 'dc:description', 'Keywords' => 'pdf:Keywords', 'Producer' => 'pdf:Producer', 'Creator' => 'xmp:CreatorTool'] as $key => $xmpKey) {
            $val = $text($info->get($key));
            if ($val === null || $val === '') {
                continue;
            }
            if (!str_contains($xmpText, $val)) {
                $this->add("Document Info /{$key} is not mirrored in the XMP metadata ({$xmpKey})");
            }
        }
    }

    private function walk(mixed $v, string $parentKey, int $depth): void
    {
        if ($depth > 60 || --$this->budget < 0) {
            return;
        }
        if ($v instanceof PdfRef) {
            if (isset($this->seen[$v->num])) {
                return;
            }
            $this->seen[$v->num] = true;
            $v = $this->reader->getObject($v->num);
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                $this->walk($item, $parentKey === 'Annots' ? 'Annots' : '', $depth + 1);
            }
            return;
        }
        if (!$v instanceof PdfDict) {
            return;
        }
        $this->inspect($v, $parentKey);
        foreach ($v->items as $key => $val) {
            if ($key === 'Parent' || $key === 'P' || $key === 'Prev' || $key === 'Next') {
                continue; // upward / sibling links: reached through the owning tree anyway
            }
            $pk = (string) $key;
            if ($parentKey === 'ExtGState' || ($parentKey === 'ExtGStateMap')) {
                $pk = 'ExtGStateEntry';
            }
            if ($key === 'ExtGState') {
                $res = $this->reader->resolve($val);
                if ($res instanceof PdfDict) {
                    foreach ($res->items as $gv) {
                        $gs = $this->reader->resolve($gv);
                        if ($gs instanceof PdfDict) {
                            $this->inspectExtGState($gs);
                        }
                        $this->walk($gv, '', $depth + 2);
                    }
                    continue;
                }
            }
            $this->walk($val, $pk, $depth + 1);
        }
    }

    private function inspect(PdfDict $d, string $parentKey): void
    {
        $type = $d->name('Type');
        $sub = $d->name('Subtype');

        // actions
        $s = $d->name('S');
        if ($s !== null && in_array($s, self::FORBIDDEN_ACTIONS, true)) {
            $this->add("Forbidden action /S /{$s}");
        }
        if ($d->has('JS')) {
            $this->add('JavaScript (/JS) found');
        }
        if ($d->has('AA') && $type !== 'Catalog') {
            $this->add('Additional-actions dictionary (/AA) found');
        }

        // streams
        if ($d instanceof PdfStream) {
            $this->inspectStream($d, $sub);
        }

        if ($type === 'Font' && $sub !== null) {
            $this->inspectFont($d, $sub);
        }
        if ($type === 'ExtGState') {
            $this->inspectExtGState($d);
        }
        if ($type === 'Page' || $type === 'XObject' || $sub === 'Form') {
            $g = $this->reader->resolve($d->get('Group'));
            if ($g instanceof PdfDict && $g->name('S') === 'Transparency' && $this->part === 1) {
                $this->add('Transparency group (/Group /S /Transparency) is not allowed in PDF/A-1');
            }
        }
        if ($parentKey === 'Annots' || $type === 'Annot') {
            $this->inspectAnnot($d, $sub ?? '');
        }
        if ($type === 'Page') {
            $c = $this->reader->resolve($d->get('Contents'));
            foreach (is_array($c) ? $c : [$c] as $part) {
                $part = $this->reader->resolve($part);
                if ($part instanceof PdfStream) {
                    $this->checkContentColors(new PdfStream($part->items + ['Subtype' => new PdfName('Form')], $part->raw));
                }
            }
        }
        if ($type === 'XObject' && $sub === 'PS') {
            $this->add('PostScript XObject found');
        }
        if ($d->has('Ref') && $sub === 'Form') {
            $this->add('Reference XObject (/Ref) found');
        }
    }

    private function inspectStream(PdfStream $s, ?string $sub): void
    {
        $filters = $this->reader->resolve($s->get('Filter'));
        $list = is_array($filters) ? $filters : ($filters === null ? [] : [$filters]);
        foreach ($list as $f) {
            $f = $this->reader->resolve($f);
            if ($f instanceof PdfName) {
                if ($f->name === 'LZWDecode' || $f->name === 'LZW') {
                    $this->add('LZW filter is forbidden');
                }
                if ($f->name === 'Crypt') {
                    $this->add('Crypt filter is forbidden');
                }
            }
        }
        if ($s->has('F') || $s->has('FFilter') || $s->has('FDecodeParms')) {
            $this->add('Stream refers to an external file (/F)');
        }
        if ($sub === 'Image') {
            if ($s->has('Alternates') && $this->part < 3) {
                $this->add('Image /Alternates is forbidden');
            }
            if ($s->has('OPI')) {
                $this->add('Image /OPI is forbidden');
            }
            if ($this->part === 1) {
                if ($s->has('SMask')) {
                    $this->add('Image with /SMask (transparency) is not allowed in PDF/A-1');
                }
                if ($this->reader->resolve($s->get('Interpolate')) === true) {
                    $this->add('Image /Interpolate true is not allowed in PDF/A-1');
                }
            }
            $cs = $this->reader->resolve($s->get('ColorSpace'));
            if ($cs instanceof PdfName && $cs->name === 'DeviceCMYK') {
                $this->add('DeviceCMYK image while the OutputIntent is an RGB profile');
            }
        } elseif ($sub === 'Form') {
            $this->checkContentColors($s);
        }
    }

    private function checkContentColors(PdfStream $s): void
    {
        if (strlen($s->raw) > 8_000_000 || !$this->reader->isDecodable($s)) {
            return;
        }
        $data = $this->reader->decodeStream($s);
        if ($data !== '' && preg_match('~(?:-?[\d.]+\s+){4}[kK]\s~', $data) === 1) {
            $this->add('DeviceCMYK colour operator (k/K) used while the OutputIntent is an RGB profile');
        }
    }

    private function inspectExtGState(PdfDict $gs): void
    {
        if ($this->part === 1) {
            foreach (['CA', 'ca'] as $k) {
                $v = $this->reader->resolve($gs->get($k));
                if (is_int($v) || is_float($v)) {
                    if (abs((float) $v - 1.0) > 1e-6) {
                        $this->add("ExtGState /{$k} {$v}: transparency is not allowed in PDF/A-1");
                    }
                }
            }
            $bm = $this->reader->resolve($gs->get('BM'));
            if ($bm instanceof PdfName && !in_array($bm->name, ['Normal', 'Compatible'], true)) {
                $this->add("ExtGState blend mode /{$bm->name} is not allowed in PDF/A-1");
            }
            $sm = $this->reader->resolve($gs->get('SMask'));
            if ($sm !== null && !($sm instanceof PdfName && $sm->name === 'None')) {
                $this->add('ExtGState soft mask (/SMask) is not allowed in PDF/A-1');
            }
        }
        if ($gs->has('TR') || ($gs->has('TR2') && $this->reader->resolve($gs->get('TR2')) instanceof PdfName === false)) {
            $this->add('ExtGState transfer function (/TR, /TR2) is forbidden');
        }
    }

    private function inspectFont(PdfDict $font, string $sub): void
    {
        $base = $font->name('BaseFont') ?? '?';
        if ($sub === 'Type3') {
            return;
        }
        if ($sub === 'Type0') {
            $desc = $this->reader->resolve($font->get('DescendantFonts'));
            $cid = is_array($desc) ? $this->reader->resolve($desc[0] ?? null) : null;
            if (!$cid instanceof PdfDict) {
                $this->add("Font {$base}: Type0 font without a descendant font");
                return;
            }
            $this->inspectDescendant($cid, $base);
            return;
        }
        if (in_array($sub, ['CIDFontType0', 'CIDFontType2'], true)) {
            return; // reached through its Type0 parent
        }
        $fd = $this->reader->resolve($font->get('FontDescriptor'));
        if (!$fd instanceof PdfDict || !($fd->has('FontFile') || $fd->has('FontFile2') || $fd->has('FontFile3'))) {
            $this->add("Font {$base} is not embedded");
        }
    }

    private function inspectDescendant(PdfDict $cid, string $base): void
    {
        $fd = $this->reader->resolve($cid->get('FontDescriptor'));
        if (!$fd instanceof PdfDict || !($fd->has('FontFile') || $fd->has('FontFile2') || $fd->has('FontFile3'))) {
            $this->add("Font {$base} is not embedded");
            return;
        }
        if ($cid->name('Subtype') === 'CIDFontType2' && !$cid->has('CIDToGIDMap')) {
            $this->add("Font {$base}: CIDFontType2 without /CIDToGIDMap");
        }
        $isSubset = preg_match('~^[A-Z]{6}\+~', $base) === 1;
        if ($this->part === 1 && $isSubset) {
            $set = $this->reader->resolve($fd->get('CIDSet'));
            if (!$set instanceof PdfStream) {
                $this->add("Font {$base}: font subset without /CIDSet (required by PDF/A-1)");
                return;
            }
            $bits = $this->reader->decodeStream($set);
            foreach ($this->widthCids($cid) as $c) {
                $byte = $c >> 3;
                if (!isset($bits[$byte]) || (ord($bits[$byte]) & (0x80 >> ($c & 7))) === 0) {
                    $this->add("Font {$base}: /CIDSet does not list CID {$c} used in /W");
                    break;
                }
            }
        }
    }

    /** @return list<int> CIDs that have an explicit entry in the /W array */
    private function widthCids(PdfDict $cid): array
    {
        $w = $this->reader->resolve($cid->get('W'));
        if (!is_array($w)) {
            return [];
        }
        $out = [];
        $n = count($w);
        for ($i = 0; $i < $n; $i++) {
            $first = $this->reader->resolve($w[$i]);
            $second = $this->reader->resolve($w[$i + 1] ?? null);
            if (!is_int($first)) {
                continue;
            }
            if (is_array($second)) {
                foreach (array_keys($second) as $k) {
                    $out[] = $first + (int) $k;
                }
                $i += 1;
            } elseif (is_int($second)) {
                for ($c = $first; $c <= min($second, $first + 70000); $c++) {
                    $out[] = $c;
                }
                $i += 2;
            }
        }
        return $out;
    }

    private function inspectAnnot(PdfDict $a, string $sub): void
    {
        if (in_array($sub, ['Sound', 'Movie', 'Screen', '3D', 'RichMedia'], true) || ($sub === 'FileAttachment' && $this->part < 3)) {
            $this->add("Annotation subtype /{$sub} is not allowed");
        }
        if ($sub === 'Popup') {
            return;
        }
        $f = $this->reader->resolve($a->get('F'));
        if (!is_int($f) || ($f & 4) === 0) {
            $this->add("Annotation /{$sub} lacks the Print flag (/F 4)");
        } elseif (($f & (1 | 2 | 32)) !== 0) {
            $this->add("Annotation /{$sub} has the Invisible/Hidden/NoView flag set");
        }
        if ($sub !== 'Link') {
            $ap = $this->reader->resolve($a->get('AP'));
            if (!$ap instanceof PdfDict || !$ap->has('N')) {
                $this->add("Annotation /{$sub} has no normal appearance stream (/AP /N)");
            } elseif ($this->part === 1 && (count($ap->items) > 1)) {
                $this->add("Annotation /{$sub}: /AP may only contain /N in PDF/A-1");
            }
        }
        if ($this->part === 1 && $this->reader->resolve($a->get('CA')) !== null) {
            $ca = $this->reader->resolve($a->get('CA'));
            if ((is_int($ca) || is_float($ca)) && abs((float) $ca - 1.0) > 1e-6) {
                $this->add("Annotation /{$sub}: /CA below 1 (transparency) is not allowed in PDF/A-1");
            }
        }
    }
}
