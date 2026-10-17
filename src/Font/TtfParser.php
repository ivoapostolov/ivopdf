<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use RuntimeException;

/**
 * Pure-PHP TrueType (.ttf) binary parser for Unicode/Cyrillic glyph mapping and metrics.
 */
class TtfParser
{
    private string $filePath;
    private string $data;

    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];

    public string $postScriptName = 'DejaVuSans';
    public string $fullName = 'DejaVu Sans';
    public string $familyName = 'DejaVu Sans';
    public int $unitsPerEm = 2048;

    /** @var array{0: int, 1: int, 2: int, 3: int} [xMin, yMin, xMax, yMax] scaled to 1000 units */
    public array $bbox = [-500, -300, 1500, 1000];

    public int $ascent = 900;     // scaled to 1000 units
    public int $descent = -240;   // scaled to 1000 units
    public int $lineGap = 0;      // scaled to 1000 units
    public int $capHeight = 700;  // scaled to 1000 units
    public int $xHeight = 500;    // scaled to 1000 units
    public float $italicAngle = 0.0;
    public int $stemV = 80;
    public int $flags = 32;
    public int $numGlyphs = 0;
    public int $numberOfHMetrics = 0;
    public int $missingWidth = 600;

    /** @var array<int, int> gid => advanceWidth (in 1000-unit PDF space) */
    private array $glyphWidths = [];

    /** @var array<int, int> unicode codepoint => gid */
    private array $codepointToGid = [];

    /** True for CFF-flavoured OpenType (OTTO) fonts: no glyf/loca, embedded as /FontFile3 */
    private bool $isCff = false;

    private ?OpenTypeLayout $layout = null;
    private bool $layoutLoaded = false;

    /** @var array<int, int>|null gid => CID for CID-keyed CFF fonts (null = identity) */
    private ?array $cffGidToCid = null;
    private bool $cffCidLoaded = false;

    /**
     * @param string  $filePath path of the font file; with $data it is only an identity key (e.g. "memory:<sha1>")
     * @param ?string $data     font bytes already in memory (data: URIs, downloaded fonts): no file is read
     */
    public function __construct(string $filePath, ?string $data = null)
    {
        // "file.ttc#2" selects face 2 of a font collection (the reference stays the cache/identity key)
        [$realPath, $faceIndex] = TtcExtractor::splitReference($filePath);
        if ($data === null && \IvoPdf\Options::isNetworkPath($realPath)) {
            throw new RuntimeException("Font path refused (UNC / network or stream-wrapper paths are not read): {$filePath}");
        }
        if ($data === null && !is_file($realPath)) {
            throw new RuntimeException("TTF font file not found: {$filePath}");
        }
        $this->filePath = $filePath;
        $raw = $data ?? file_get_contents($realPath);
        if ($raw === false) {
            throw new RuntimeException("Unable to read TTF font file: {$filePath}");
        }
        if (TtcExtractor::isCollection($raw)) {
            $raw = TtcExtractor::extractFace($raw, $faceIndex);
        }
        $this->data = $raw;
        $magic = substr($raw, 0, 4);
        if (strlen($raw) < 12 || ($magic !== "\x00\x01\x00\x00" && $magic !== 'true' && $magic !== 'OTTO')) {
            throw new RuntimeException("Not a valid TrueType/OpenType font file: {$filePath}");
        }
        $this->isCff = $magic === 'OTTO';
        $this->parse();
        if (!isset($this->tables['cmap'], $this->tables['hmtx'], $this->tables['head']) || empty($this->codepointToGid)) {
            throw new RuntimeException("TrueType font is missing required tables (cmap/hmtx/head): {$filePath}");
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getRawData(): string
    {
        return $this->data;
    }

    public function isCff(): bool
    {
        return $this->isCff;
    }

    public function hasTable(string $tag): bool
    {
        return isset($this->tables[$tag]);
    }

    /**
     * Raw bytes of an sfnt table, or null when absent.
     */
    public function getTable(string $tag): ?string
    {
        if (!isset($this->tables[$tag])) {
            return null;
        }
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    /**
     * OpenType layout (GSUB/GPOS/GDEF/kern) access, or null when the font has none of these tables.
     */
    public function getLayout(): ?OpenTypeLayout
    {
        if (!$this->layoutLoaded) {
            $this->layoutLoaded = true;
            if (isset($this->tables['GSUB']) || isset($this->tables['GPOS']) || isset($this->tables['kern'])) {
                $this->layout = new OpenTypeLayout(
                    $this->getTable('GSUB'),
                    $this->getTable('GPOS'),
                    $this->getTable('GDEF'),
                    $this->getTable('kern'),
                    1000.0 / max(1, $this->unitsPerEm)
                );
            }
        }
        return $this->layout;
    }

    /**
     * The CID used in PDF content for a glyph: the glyph id, except for CID-keyed CFF fonts (charset lookup).
     */
    public function pdfCid(int $gid): int
    {
        if (!$this->isCff) {
            return $gid;
        }
        if (!$this->cffCidLoaded) {
            $this->cffCidLoaded = true;
            $cff = $this->getTable('CFF ');
            $this->cffGidToCid = $cff !== null ? CffInfo::gidToCid($cff, $this->numGlyphs) : null;
        }
        return $this->cffGidToCid[$gid] ?? $gid;
    }

    /**
     * Maps a Unicode codepoint to its TrueType Glyph ID (GID).
     */
    public function hasGlyph(int $codepoint): bool
    {
        return isset($this->codepointToGid[$codepoint]);
    }

    public function getGlyphId(int $codepoint): int
    {
        return $this->codepointToGid[$codepoint] ?? 0;
    }

    /**
     * Returns glyph width in 1/1000 em units for a given GID.
     */
    public function getGlyphWidthByGid(int $gid): int
    {
        return $this->glyphWidths[$gid] ?? $this->missingWidth;
    }

    /**
     * Returns character width in 1/1000 em units for a Unicode codepoint.
     */
    public function getCharWidth(int $codepoint): int
    {
        // Zero-width spaces / soft hyphens / control chars
        if ($codepoint === 0x200B || $codepoint === 0xFEFF || $codepoint === 0x00AD) {
            return 0;
        }
        if ($codepoint === 0x00A0) {
            // Non-breaking space -> fallback to normal space if not mapped
            $gid = $this->codepointToGid[0x00A0] ?? ($this->codepointToGid[0x0020] ?? 0);
            return $this->glyphWidths[$gid] ?? $this->missingWidth;
        }
        $gid = $this->codepointToGid[$codepoint] ?? 0;
        return $this->glyphWidths[$gid] ?? $this->missingWidth;
    }

    private function parse(): void
    {
        $numTables = min(256, $this->readUInt16(4));
        $pos = 12;
        $dataLen = strlen($this->data);
        for ($i = 0; $i < $numTables; $i++) {
            if ($pos + 16 > $dataLen) {
                break;
            }
            $tag = substr($this->data, $pos, 4);
            $offset = $this->readUInt32($pos + 8);
            $length = $this->readUInt32($pos + 12);
            if ($offset + $length <= $dataLen) {
                $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
            }
            $pos += 16;
        }

        $this->parseHead();
        $this->parseHhea();
        $this->parseMaxp();
        $this->parseOs2();
        $this->parsePost();
        $this->parseName();
        $this->parseHmtx();
        $this->parseCmap();
    }

    private function scale(int|float $val): int
    {
        return (int) round(($val * 1000.0) / max(1, $this->unitsPerEm));
    }

    private function parseHead(): void
    {
        if (!isset($this->tables['head'])) {
            return;
        }
        $offset = $this->tables['head']['offset'];
        $this->unitsPerEm = max(1, $this->readUInt16($offset + 18));
        $xMin = $this->readInt16($offset + 36);
        $yMin = $this->readInt16($offset + 38);
        $xMax = $this->readInt16($offset + 40);
        $yMax = $this->readInt16($offset + 42);
        $this->bbox = [
            $this->scale($xMin),
            $this->scale($yMin),
            $this->scale($xMax),
            $this->scale($yMax),
        ];
    }

    private function parseHhea(): void
    {
        if (!isset($this->tables['hhea'])) {
            return;
        }
        $offset = $this->tables['hhea']['offset'];
        $this->ascent = $this->scale($this->readInt16($offset + 4));
        $this->descent = $this->scale($this->readInt16($offset + 6));
        $this->lineGap = $this->scale($this->readInt16($offset + 8));
        $this->numberOfHMetrics = $this->readUInt16($offset + 34);
    }

    private function parseMaxp(): void
    {
        if (!isset($this->tables['maxp'])) {
            return;
        }
        $offset = $this->tables['maxp']['offset'];
        $this->numGlyphs = $this->readUInt16($offset + 4);
    }

    private function parseOs2(): void
    {
        if (!isset($this->tables['OS/2'])) {
            return;
        }
        $offset = $this->tables['OS/2']['offset'];
        $length = $this->tables['OS/2']['length'];
        $version = $this->readUInt16($offset);
        $usWeightClass = $this->readUInt16($offset + 4);
        if ($usWeightClass >= 600) {
            $this->stemV = 120;
        }

        if ($length >= 78) {
            $sTypoAscender = $this->readInt16($offset + 68);
            $sTypoDescender = $this->readInt16($offset + 70);
            $sTypoLineGap = $this->readInt16($offset + 72);
            // Line metrics as browsers use them: the OS/2 typo values when the font sets USE_TYPO_METRICS
            // (fsSelection bit 7) or has no hhea values, otherwise hhea (equal to usWin* in well-made fonts,
            // which is what DirectWrite/Edge reads)
            $fsSelection = $this->readUInt16($offset + 62);
            $useTypo = ($fsSelection & 0x80) !== 0 || $this->ascent <= 0;
            if ($sTypoAscender > 0 && $useTypo) {
                $this->ascent = $this->scale($sTypoAscender);
                $this->descent = $this->scale($sTypoDescender);
                $this->lineGap = $this->scale($sTypoLineGap);
            }
        }

        if ($version >= 2 && $length >= 90) {
            $sxHeight = $this->readInt16($offset + 86);
            $sCapHeight = $this->readInt16($offset + 88);
            if ($sxHeight > 0) {
                $this->xHeight = $this->scale($sxHeight);
            }
            if ($sCapHeight > 0) {
                $this->capHeight = $this->scale($sCapHeight);
            } else {
                $this->capHeight = (int) round($this->ascent * 0.75);
            }
        } else {
            $this->capHeight = (int) round($this->ascent * 0.75);
        }
    }

    private function parsePost(): void
    {
        if (!isset($this->tables['post'])) {
            return;
        }
        $offset = $this->tables['post']['offset'];
        $italicMantissa = $this->readInt16($offset + 4);
        $italicFraction = $this->readUInt16($offset + 6);
        $this->italicAngle = $italicMantissa + ($italicFraction / 65536.0);
        $isFixedPitch = $this->readUInt32($offset + 12);

        $flags = 32; // Nonsymbolic
        if ($isFixedPitch !== 0) {
            $flags |= 1;
        }
        if (abs($this->italicAngle) > 0.1) {
            $flags |= 64;
        }
        $this->flags = $flags;
    }

    private function parseName(): void
    {
        if (!isset($this->tables['name'])) {
            return;
        }
        $offset = $this->tables['name']['offset'];
        $count = $this->readUInt16($offset + 2);
        $stringOffset = $offset + $this->readUInt16($offset + 4);

        $pos = $offset + 6;
        for ($i = 0; $i < $count; $i++) {
            $platformID = $this->readUInt16($pos);
            $nameID = $this->readUInt16($pos + 6);
            $length = $this->readUInt16($pos + 8);
            $strOff = $this->readUInt16($pos + 10);
            $pos += 12;

            if (!in_array($nameID, [1, 4, 6], true)) {
                continue;
            }

            $raw = substr($this->data, $stringOffset + $strOff, $length);
            if ($platformID === 3 || $platformID === 0) {
                $str = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            } elseif ($platformID === 1) {
                $str = $raw;
            } else {
                continue;
            }

            $str = trim((string) preg_replace('/[^\x20-\x7E]/', '', (string) $str));
            if ($str === '') {
                continue;
            }

            if ($nameID === 1) {
                $this->familyName = $str;
            } elseif ($nameID === 4) {
                $this->fullName = $str;
            } elseif ($nameID === 6) {
                $this->postScriptName = preg_replace('/\s+/', '', $str) ?: 'CustomFont';
            }
        }
    }

    private function parseHmtx(): void
    {
        if (!isset($this->tables['hmtx'])) {
            return;
        }
        $offset = $this->tables['hmtx']['offset'];
        $numHMetrics = max(1, $this->numberOfHMetrics);
        $numGlyphs = max($numHMetrics, $this->numGlyphs);

        $lastAdvance = 600;
        $pos = $offset;
        for ($gid = 0; $gid < $numHMetrics; $gid++) {
            $advance = $this->readUInt16($pos);
            $lastAdvance = $this->scale($advance);
            $this->glyphWidths[$gid] = $lastAdvance;
            $pos += 4;
        }

        for ($gid = $numHMetrics; $gid < $numGlyphs; $gid++) {
            $this->glyphWidths[$gid] = $lastAdvance;
        }

        $this->missingWidth = $this->glyphWidths[0] ?? 600;
    }

    private function parseCmap(): void
    {
        if (!isset($this->tables['cmap'])) {
            return;
        }
        $offset = $this->tables['cmap']['offset'];
        $numSubTables = $this->readUInt16($offset + 2);

        $format4Offset = null;
        $format12Offset = null;

        $pos = $offset + 4;
        for ($i = 0; $i < $numSubTables; $i++) {
            $platformID = $this->readUInt16($pos);
            $encodingID = $this->readUInt16($pos + 2);
            $subOffset = $offset + $this->readUInt32($pos + 4);
            $pos += 8;

            $format = $this->readUInt16($subOffset);
            if (($platformID === 3 && $encodingID === 10) || ($platformID === 0 && $format === 12)) {
                if ($format === 12) {
                    $format12Offset = $subOffset;
                }
            }
            if (($platformID === 3 && $encodingID === 1) || $platformID === 0) {
                if ($format === 4 && $format4Offset === null) {
                    $format4Offset = $subOffset;
                }
            }
        }

        if ($format12Offset !== null) {
            $this->parseCmapFormat12($format12Offset);
        } elseif ($format4Offset !== null) {
            $this->parseCmapFormat4($format4Offset);
        }
    }

    private function parseCmapFormat4(int $offset): void
    {
        $segCountX2 = $this->readUInt16($offset + 6);
        $segCount = (int) ($segCountX2 / 2);

        $endCodesOffset = $offset + 14;
        $startCodesOffset = $endCodesOffset + $segCountX2 + 2;
        $idDeltasOffset = $startCodesOffset + $segCountX2;
        $idRangeOffsetsOffset = $idDeltasOffset + $segCountX2;

        for ($i = 0; $i < $segCount; $i++) {
            $endCode = $this->readUInt16($endCodesOffset + $i * 2);
            $startCode = $this->readUInt16($startCodesOffset + $i * 2);
            $idDelta = $this->readInt16($idDeltasOffset + $i * 2);
            $rangeOffsetPos = $idRangeOffsetsOffset + $i * 2;
            $idRangeOffset = $this->readUInt16($rangeOffsetPos);

            if ($startCode === 0xFFFF && $endCode === 0xFFFF) {
                break;
            }

            for ($c = $startCode; $c <= $endCode; $c++) {
                if ($idRangeOffset === 0) {
                    $gid = ($c + $idDelta) & 0xFFFF;
                } else {
                    $glyphIndexPos = $rangeOffsetPos + $idRangeOffset + 2 * ($c - $startCode);
                    if ($glyphIndexPos + 2 > strlen($this->data)) {
                        $gid = 0;
                    } else {
                        $gid = $this->readUInt16($glyphIndexPos);
                        if ($gid !== 0) {
                            $gid = ($gid + $idDelta) & 0xFFFF;
                        }
                    }
                }
                if ($gid > 0) {
                    $this->codepointToGid[$c] = $gid;
                }
            }
        }
    }

    private function parseCmapFormat12(int $offset): void
    {
        $numGroups = $this->readUInt32($offset + 12);
        $pos = $offset + 16;
        for ($i = 0; $i < $numGroups; $i++) {
            $startCharCode = $this->readUInt32($pos);
            $endCharCode = $this->readUInt32($pos + 4);
            $startGlyphID = $this->readUInt32($pos + 8);
            $pos += 12;

            // Avoid excessive memory if a font maps huge ranges above U+2FFFF
            $limit = min($endCharCode, 0x2FFFF);
            for ($c = $startCharCode; $c <= $limit; $c++) {
                $gid = $startGlyphID + ($c - $startCharCode);
                if ($gid > 0) {
                    $this->codepointToGid[$c] = $gid;
                }
            }
        }
    }

    private function readUInt16(int $pos): int
    {
        if ($pos < 0 || $pos + 2 > strlen($this->data)) {
            return 0;
        }
        $unpacked = unpack('n', substr($this->data, $pos, 2));
        return $unpacked ? (int) $unpacked[1] : 0;
    }

    private function readInt16(int $pos): int
    {
        $val = $this->readUInt16($pos);
        return $val >= 0x8000 ? $val - 0x10000 : $val;
    }

    private function readUInt32(int $pos): int
    {
        if ($pos < 0 || $pos + 4 > strlen($this->data)) {
            return 0;
        }
        $unpacked = unpack('N', substr($this->data, $pos, 4));
        return $unpacked ? (int) $unpacked[1] : 0;
    }
}
