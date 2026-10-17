<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use RuntimeException;

/**
 * TrueType/OpenType Collection (.ttc/.otc) support: splits a font reference like "msyh.ttc#1" and extracts one face
 * into a standalone sfnt (so parsing, subsetting and embedding work on ordinary single-font data).
 */
final class TtcExtractor
{
    /**
     * Splits "path#index" into [path, face index]. A trailing "#n" is only taken as a face index when the literal
     * path does not exist as a file.
     *
     * @return array{0: string, 1: int}
     */
    public static function splitReference(string $reference): array
    {
        if (\IvoPdf\Options::isNetworkPath($reference)) {
            return [$reference, 0]; // never stat()ed (UNC / stream wrapper); TtfParser refuses it
        }
        if (preg_match('/^(.*)#(\d{1,3})$/s', $reference, $m) === 1 && !is_file($reference)) {
            return [$m[1], (int) $m[2]];
        }
        return [$reference, 0];
    }

    public static function isCollection(string $data): bool
    {
        return strncmp($data, 'ttcf', 4) === 0;
    }

    /**
     * Number of faces in a collection (1 for a plain font).
     */
    public static function faceCount(string $data): int
    {
        return self::isCollection($data) && strlen($data) >= 12 ? (int) unpack('N', substr($data, 8, 4))[1] : 1;
    }

    /**
     * Builds a standalone sfnt from face $index of a collection.
     */
    public static function extractFace(string $data, int $index): string
    {
        $count = self::faceCount($data);
        if ($index < 0 || $index >= $count) {
            throw new RuntimeException("Font collection has {$count} face(s); face index {$index} does not exist");
        }
        $faceOffset = (int) unpack('N', substr($data, 12 + 4 * $index, 4))[1];
        if ($faceOffset + 12 > strlen($data)) {
            throw new RuntimeException('Corrupt font collection header');
        }
        $sfntVersion = substr($data, $faceOffset, 4);
        $numTables = (int) unpack('n', substr($data, $faceOffset + 4, 2))[1];
        $entries = [];
        $dataLen = strlen($data);
        for ($i = 0; $i < $numTables; $i++) {
            $pos = $faceOffset + 12 + $i * 16;
            if ($pos + 16 > $dataLen) {
                break;
            }
            $e = unpack('a4tag/Nsum/Noff/Nlen', substr($data, $pos, 16));
            if ($e['off'] + $e['len'] > $dataLen) {
                continue;
            }
            $entries[] = $e;
        }
        $n = count($entries);
        $pow = 1;
        $log = 0;
        while ($pow * 2 <= $n) {
            $pow *= 2;
            $log++;
        }
        $header = $sfntVersion . pack('nnnn', $n, $pow * 16, $log, $n * 16 - $pow * 16);
        $dir = '';
        $body = '';
        $offset = 12 + $n * 16;
        foreach ($entries as $e) {
            $dir .= pack('a4NNN', $e['tag'], $e['sum'], $offset + strlen($body), $e['len']);
            $chunk = substr($data, $e['off'], $e['len']);
            $body .= $chunk . str_repeat("\0", (4 - strlen($chunk) % 4) % 4);
        }
        return $header . $dir . $body;
    }
}
