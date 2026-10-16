<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use UnexpectedValueException;

/**
 * Pure-PHP TrueType subsetter that keeps original glyph IDs (for CIDToGIDMap /Identity).
 *
 * Unused glyphs become empty in glyf; the font is truncated to maxUsedGid + 1 glyphs.
 */
final class TtfSubsetter
{
    private const ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const WE_HAVE_A_SCALE = 0x0008;
    private const MORE_COMPONENTS = 0x0020;
    private const WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const WE_HAVE_A_TWO_BY_TWO = 0x0080;

    /** Tables copied verbatim when present. */
    private const COPY_TABLES = ['cvt ', 'fpgm', 'prep', 'OS/2'];

    /** name IDs kept in the reduced name table (copyright, family, style, unique id, full name, version, PS name). */
    private const KEEP_NAME_IDS = [0, 1, 2, 3, 4, 5, 6];

    /**
     * @param list<int>|array<int, int> $gids glyph ids to keep (array values are used; 0 is always kept)
     * @param bool $keepCmap copy the original cmap unchanged (only useful for rasterizer-based testing)
     * @return string|null subset TTF bytes, or null when the font cannot be subset (CFF/OTF, malformed)
     */
    public static function subset(string $ttfData, array $gids, bool $keepCmap = false): ?string
    {
        try {
            return self::doSubset($ttfData, $gids, $keepCmap);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<int, int> $gids
     */
    private static function doSubset(string $data, array $gids, bool $keepCmap): string
    {
        $tables = self::readDirectory($data);
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf'] as $required) {
            if (!isset($tables[$required])) {
                throw new UnexpectedValueException("Missing table {$required}");
            }
        }

        $head = self::tableData($data, $tables['head']);
        $hhea = self::tableData($data, $tables['hhea']);
        $maxp = self::tableData($data, $tables['maxp']);
        $hmtx = self::tableData($data, $tables['hmtx']);
        $loca = self::tableData($data, $tables['loca']);
        $glyf = self::tableData($data, $tables['glyf']);
        if (strlen($head) < 54 || strlen($hhea) < 36 || strlen($maxp) < 6) {
            throw new UnexpectedValueException('Truncated head/hhea/maxp');
        }

        $numGlyphs = self::u16($maxp, 4);
        $numHMetrics = self::u16($hhea, 34);
        $longLoca = self::i16($head, 50) === 1;
        if ($numGlyphs < 1 || $numHMetrics < 1 || $numHMetrics > $numGlyphs) {
            throw new UnexpectedValueException('Invalid glyph counts');
        }
        if (strlen($hmtx) < $numHMetrics * 4) {
            throw new UnexpectedValueException('Truncated hmtx');
        }

        // Original glyph offsets
        $offsets = [];
        $glyfLen = strlen($glyf);
        for ($i = 0; $i <= $numGlyphs; $i++) {
            $off = $longLoca ? self::u32($loca, $i * 4) : self::u16($loca, $i * 2) * 2;
            if ($off > $glyfLen) {
                throw new UnexpectedValueException('loca offset out of range');
            }
            $offsets[$i] = $off;
        }

        // Collect requested glyphs + composite dependencies
        $keep = [0 => true];
        $stack = [0];
        foreach ($gids as $gid) {
            $gid = (int) $gid;
            if ($gid > 0 && $gid < $numGlyphs && !isset($keep[$gid])) {
                $keep[$gid] = true;
                $stack[] = $gid;
            }
        }
        while ($stack !== []) {
            $gid = array_pop($stack);
            foreach (self::componentGids($glyf, $offsets[$gid], $offsets[$gid + 1]) as $component) {
                if ($component >= $numGlyphs) {
                    throw new UnexpectedValueException('Component glyph out of range');
                }
                if (!isset($keep[$component])) {
                    $keep[$component] = true;
                    $stack[] = $component;
                }
            }
        }

        $newNumGlyphs = max(array_keys($keep)) + 1;
        $newNumHMetrics = min($numHMetrics, $newNumGlyphs);

        // glyf + loca (long format)
        $newGlyf = '';
        $newLoca = '';
        for ($gid = 0; $gid < $newNumGlyphs; $gid++) {
            $newLoca .= pack('N', strlen($newGlyf));
            if (!isset($keep[$gid])) {
                continue;
            }
            $start = $offsets[$gid];
            $end = $offsets[$gid + 1];
            if ($end <= $start) {
                continue;
            }
            $newGlyf .= substr($glyf, $start, $end - $start);
            $pad = (4 - (strlen($newGlyf) % 4)) % 4;
            $newGlyf .= str_repeat("\0", $pad);
        }
        $newLoca .= pack('N', strlen($newGlyf));

        // hmtx: long metrics, then left side bearings (zeroed for dropped glyphs, compresses well)
        $newHmtx = '';
        for ($gid = 0; $gid < $newNumHMetrics; $gid++) {
            $newHmtx .= isset($keep[$gid]) ? substr($hmtx, $gid * 4, 4) : "\0\0\0\0";
        }
        for ($gid = $newNumHMetrics; $gid < $newNumGlyphs; $gid++) {
            $pos = $numHMetrics * 4 + ($gid - $numHMetrics) * 2;
            $newHmtx .= (isset($keep[$gid]) && $pos + 2 <= strlen($hmtx)) ? substr($hmtx, $pos, 2) : "\0\0";
        }

        $newHead = substr($head, 0, 8) . "\0\0\0\0" . substr($head, 12, 38) . pack('n', 1) . substr($head, 52);
        $newHhea = substr($hhea, 0, 34) . pack('n', $newNumHMetrics) . substr($hhea, 36);
        $newMaxp = substr($maxp, 0, 4) . pack('n', $newNumGlyphs) . substr($maxp, 6);

        $out = [
            'head' => $newHead,
            'hhea' => $newHhea,
            'maxp' => $newMaxp,
            'hmtx' => $newHmtx,
            'loca' => $newLoca,
            'glyf' => $newGlyf,
        ];

        if (isset($tables['post'])) {
            $post = self::tableData($data, $tables['post']);
            if (strlen($post) >= 16) {
                $out['post'] = pack('N', 0x00030000) . substr($post, 4, 12) . str_repeat("\0", 16);
            }
        }

        if (isset($tables['name'])) {
            $name = self::reduceName(self::tableData($data, $tables['name']));
            if ($name !== null) {
                $out['name'] = $name;
            }
        }

        $copy = self::COPY_TABLES;
        if ($keepCmap) {
            $copy[] = 'cmap';
        }
        foreach ($copy as $tag) {
            if (isset($tables[$tag])) {
                $out[$tag] = self::tableData($data, $tables[$tag]);
            }
        }

        return self::buildFont($out);
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private static function readDirectory(string $data): array
    {
        $version = self::u32($data, 0);
        if ($version !== 0x00010000 && $version !== 0x74727565) { // 1.0 or 'true'
            throw new UnexpectedValueException('Not a TrueType outline font');
        }
        $numTables = self::u16($data, 4);
        $tables = [];
        $len = strlen($data);
        for ($i = 0; $i < $numTables; $i++) {
            $pos = 12 + $i * 16;
            $tag = self::bytes($data, $pos, 4);
            $offset = self::u32($data, $pos + 8);
            $length = self::u32($data, $pos + 12);
            if ($offset + $length > $len) {
                throw new UnexpectedValueException("Table {$tag} out of range");
            }
            $tables[$tag] = ['offset' => $offset, 'length' => $length];
        }
        return $tables;
    }

    /**
     * @param array{offset: int, length: int} $entry
     */
    private static function tableData(string $data, array $entry): string
    {
        return self::bytes($data, $entry['offset'], $entry['length']);
    }

    /**
     * Keeps only the basic name records (drops license/description texts). Null when unparsable.
     */
    private static function reduceName(string $name): ?string
    {
        try {
            if (self::u16($name, 0) !== 0) {
                return $name; // format 1 (language tags): keep verbatim
            }
            $count = self::u16($name, 2);
            $storage = self::u16($name, 4);
            $records = '';
            $strings = '';
            $kept = 0;
            for ($i = 0; $i < $count; $i++) {
                $pos = 6 + $i * 12;
                $nameId = self::u16($name, $pos + 6);
                if (!in_array($nameId, self::KEEP_NAME_IDS, true)) {
                    continue;
                }
                $length = self::u16($name, $pos + 8);
                $str = self::bytes($name, $storage + self::u16($name, $pos + 10), $length);
                $records .= self::bytes($name, $pos, 8) . pack('nn', $length, strlen($strings));
                $strings .= $str;
                $kept++;
            }
            if ($kept === 0) {
                return null;
            }
            return pack('nnn', 0, $kept, 6 + $kept * 12) . $records . $strings;
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Returns the glyph IDs referenced by a composite glyph (empty for simple/empty glyphs).
     *
     * @return list<int>
     */
    private static function componentGids(string $glyf, int $start, int $end): array
    {
        if ($end - $start < 10 || self::i16($glyf, $start) >= 0) {
            return [];
        }
        $result = [];
        $pos = $start + 10;
        do {
            if ($pos + 4 > $end) {
                throw new UnexpectedValueException('Truncated composite glyph');
            }
            $flags = self::u16($glyf, $pos);
            $result[] = self::u16($glyf, $pos + 2);
            $pos += 4;
            $pos += ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;
            if ($flags & self::WE_HAVE_A_SCALE) {
                $pos += 2;
            } elseif ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE) {
                $pos += 4;
            } elseif ($flags & self::WE_HAVE_A_TWO_BY_TWO) {
                $pos += 8;
            }
        } while ($flags & self::MORE_COMPONENTS);
        // WE_HAVE_INSTRUCTIONS (0x100) data follows; it is copied with the glyph and needs no parsing.

        return $result;
    }

    /**
     * Assembles an sfnt from tag => table data and fixes head.checkSumAdjustment.
     *
     * @param array<string, string> $tables
     */
    private static function buildFont(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $numTables = count($tables);
        $entrySelector = (int) floor(log($numTables, 2));
        $searchRange = (1 << $entrySelector) * 16;
        $rangeShift = $numTables * 16 - $searchRange;

        $header = pack('Nnnnn', 0x00010000, $numTables, $searchRange, $entrySelector, $rangeShift);
        $directory = '';
        $body = '';
        $offset = 12 + 16 * $numTables;
        $headOffset = null;

        foreach ($tables as $tag => $table) {
            $length = strlen($table);
            $padded = $table . str_repeat("\0", (4 - ($length % 4)) % 4);
            if ($tag === 'head') {
                $headOffset = $offset;
            }
            $directory .= str_pad($tag, 4, ' ') . pack('NNN', self::checksum($padded), $offset, $length);
            $body .= $padded;
            $offset += strlen($padded);
        }

        $font = $header . $directory . $body;
        if ($headOffset !== null) {
            $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
            $font = substr_replace($font, pack('N', $adjustment), $headOffset + 8, 4);
        }
        return $font;
    }

    private static function checksum(string $data): int
    {
        $rem = strlen($data) % 4;
        if ($rem !== 0) {
            $data .= str_repeat("\0", 4 - $rem);
        }
        $sum = 0;
        foreach (str_split($data, 65536) as $chunk) {
            $words = unpack('N*', $chunk);
            if ($words !== false) {
                $sum = ($sum + array_sum($words)) & 0xFFFFFFFF;
            }
        }
        return $sum;
    }

    private static function bytes(string $data, int $pos, int $length): string
    {
        if ($pos < 0 || $length < 0 || $pos + $length > strlen($data)) {
            throw new UnexpectedValueException('Read out of bounds');
        }
        return substr($data, $pos, $length);
    }

    private static function u16(string $data, int $pos): int
    {
        $v = unpack('n', self::bytes($data, $pos, 2));
        return $v === false ? 0 : (int) $v[1];
    }

    private static function i16(string $data, int $pos): int
    {
        $v = self::u16($data, $pos);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $data, int $pos): int
    {
        $v = unpack('N', self::bytes($data, $pos, 4));
        return $v === false ? 0 : (int) $v[1];
    }
}
