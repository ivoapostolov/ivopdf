<?php

declare(strict_types=1);

namespace IvoPdf\Font;

/**
 * Minimal CFF reader: detects CID-keyed fonts and returns the glyph id => CID charset mapping
 * (needed because PDF content addresses CID-keyed OpenType/CFF glyphs by CID, not by glyph id).
 */
final class CffInfo
{
    /**
     * @return array<int, int>|null gid => CID, or null for name-keyed CFF (CID == GID)
     */
    public static function gidToCid(string $cff, int $numGlyphs): ?array
    {
        if (strlen($cff) < 8) {
            return null;
        }
        $hdrSize = ord($cff[2]);
        $pos = self::skipIndex($cff, $hdrSize);          // Name INDEX
        if ($pos === null) {
            return null;
        }
        $topStart = $pos;
        $topCount = self::u16($cff, $topStart);
        if ($topCount < 1) {
            return null;
        }
        $offSize = ord($cff[$topStart + 2]);
        $offBase = $topStart + 3;
        $first = self::readOff($cff, $offBase, $offSize);
        $second = self::readOff($cff, $offBase + $offSize, $offSize);
        $dataStart = $offBase + ($topCount + 1) * $offSize - 1;
        $dict = substr($cff, $dataStart + $first, max(0, $second - $first));

        $isCid = false;
        $charset = 0;
        $operands = [];
        $len = strlen($dict);
        for ($i = 0; $i < $len;) {
            $b = ord($dict[$i]);
            if ($b <= 21) {
                $op = $b;
                $i++;
                if ($b === 12 && $i < $len) {
                    $op = 1200 + ord($dict[$i]);
                    $i++;
                }
                if ($op === 1230) {
                    $isCid = true;
                } elseif ($op === 15 && $operands !== []) {
                    $charset = (int) $operands[count($operands) - 1];
                }
                $operands = [];
            } elseif ($b === 28) {
                $v = self::u16($cff, 0, substr($dict, $i + 1, 2));
                $operands[] = $v >= 0x8000 ? $v - 0x10000 : $v;
                $i += 3;
            } elseif ($b === 29) {
                $u = unpack('N', substr($dict, $i + 1, 4) . "\0\0\0\0");
                $v = (int) ($u[1] ?? 0);
                $operands[] = $v >= 0x80000000 ? $v - 0x100000000 : $v;
                $i += 5;
            } elseif ($b === 30) {
                $i++;
                while ($i < $len) {
                    $n = ord($dict[$i++]);
                    if (($n & 0x0F) === 0x0F || ($n >> 4) === 0x0F) {
                        break;
                    }
                }
                $operands[] = 0;
            } elseif ($b >= 32 && $b <= 246) {
                $operands[] = $b - 139;
                $i++;
            } elseif ($b >= 247 && $b <= 250) {
                $operands[] = ($b - 247) * 256 + ord($dict[$i + 1] ?? "\0") + 108;
                $i += 2;
            } elseif ($b >= 251 && $b <= 254) {
                $operands[] = -($b - 251) * 256 - ord($dict[$i + 1] ?? "\0") - 108;
                $i += 2;
            } else {
                $i++;
            }
        }

        if (!$isCid || $charset <= 2 || $charset >= strlen($cff)) {
            return null;
        }

        $map = [0 => 0];
        $format = ord($cff[$charset]);
        $p = $charset + 1;
        $gid = 1;
        if ($format === 0) {
            while ($gid < $numGlyphs) {
                $map[$gid++] = self::u16($cff, $p);
                $p += 2;
            }
        } else {
            while ($gid < $numGlyphs && $p < strlen($cff)) {
                $cid = self::u16($cff, $p);
                $left = $format === 1 ? ord($cff[$p + 2] ?? "\0") : self::u16($cff, $p + 2);
                $p += $format === 1 ? 3 : 4;
                for ($k = 0; $k <= $left && $gid < $numGlyphs; $k++) {
                    $map[$gid++] = $cid + $k;
                }
            }
        }
        return $map;
    }

    private static function skipIndex(string $d, int $pos): ?int
    {
        if ($pos + 2 > strlen($d)) {
            return null;
        }
        $count = self::u16($d, $pos);
        if ($count === 0) {
            return $pos + 2;
        }
        $offSize = ord($d[$pos + 2] ?? "\0");
        if ($offSize < 1 || $offSize > 4) {
            return null;
        }
        $last = self::readOff($d, $pos + 3 + $count * $offSize, $offSize);
        return $pos + 3 + ($count + 1) * $offSize + $last - 1;
    }

    private static function u16(string $d, int $pos, ?string $raw = null): int
    {
        $s = $raw ?? substr($d, $pos, 2);
        return (ord($s[0] ?? "\0") << 8) | ord($s[1] ?? "\0");
    }

    private static function readOff(string $d, int $pos, int $size): int
    {
        $v = 0;
        for ($i = 0; $i < $size; $i++) {
            $v = ($v << 8) | ord($d[$pos + $i] ?? "\0");
        }
        return $v;
    }
}
