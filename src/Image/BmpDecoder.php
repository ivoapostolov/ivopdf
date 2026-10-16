<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Pure-PHP BMP decoder: 1/4/8/16/24/32-bit, palette, BITFIELDS, RLE4/RLE8, top-down and bottom-up, alpha for 32-bit.
 */
final class BmpDecoder
{
    public static function isBmp(string $raw): bool
    {
        return strlen($raw) > 26 && str_starts_with($raw, 'BM');
    }

    public static function decode(string $raw, int $maxPixels = 25000000): ?RasterImage
    {
        $len = strlen($raw);
        if ($len < 26 || !str_starts_with($raw, 'BM')) {
            return null;
        }
        $dataOffset = self::u32($raw, 10);
        $hdrSize = self::u32($raw, 14);
        $palEntrySize = 4;
        if ($hdrSize === 12) {
            $w = self::u16($raw, 18);
            $h = self::u16($raw, 20);
            $bpp = self::u16($raw, 24);
            $comp = 0;
            $palEntrySize = 3;
            $colorsUsed = 0;
        } elseif ($hdrSize >= 40) {
            $w = self::s32($raw, 18);
            $h = self::s32($raw, 22);
            $bpp = self::u16($raw, 28);
            $comp = self::u32($raw, 30);
            $colorsUsed = self::u32($raw, 46);
        } else {
            return null;
        }
        $topDown = $h < 0;
        $h = abs($h);
        if ($w <= 0 || $h <= 0 || $w * $h > $maxPixels || !in_array($bpp, [1, 4, 8, 16, 24, 32], true)) {
            return null;
        }

        $masks = [0, 0, 0, 0];
        $pos = 14 + $hdrSize;
        if ($comp === 3 || $comp === 6) {
            if ($hdrSize >= 52) {
                $masks = [self::u32($raw, 54), self::u32($raw, 58), self::u32($raw, 62), $hdrSize >= 56 ? self::u32($raw, 66) : 0];
            } else {
                $masks = [self::u32($raw, $pos), self::u32($raw, $pos + 4), self::u32($raw, $pos + 8), 0];
                $pos += 12;
            }
        } elseif ($bpp === 16) {
            $masks = [0x7C00, 0x03E0, 0x001F, 0];
        } elseif ($bpp === 32) {
            $masks = [0x00FF0000, 0x0000FF00, 0x000000FF, $hdrSize >= 56 ? self::u32($raw, 66) : 0];
            if ($hdrSize < 56) {
                $masks[3] = 0xFF000000;
            }
        }

        // Palette
        $palette = [];
        if ($bpp <= 8) {
            $n = $colorsUsed > 0 ? $colorsUsed : (1 << $bpp);
            $n = min($n, 1 << $bpp);
            $palStart = 14 + $hdrSize;
            for ($i = 0; $i < $n; $i++) {
                $o = $palStart + $i * $palEntrySize;
                if ($o + 2 >= $len) {
                    break;
                }
                $palette[$i] = [ord($raw[$o + 2]), ord($raw[$o + 1]), ord($raw[$o])];
            }
        }

        $rgbRows = array_fill(0, $h, '');
        $alphaRows = array_fill(0, $h, '');
        $hasAlpha = false;

        if ($comp === 1 || $comp === 2) {
            $indices = self::decodeRle($raw, $dataOffset, $w, $h, $comp === 2);
            if ($indices === null) {
                return null;
            }
            for ($y = 0; $y < $h; $y++) {
                $row = '';
                $base = $y * $w;
                for ($x = 0; $x < $w; $x++) {
                    $c = $palette[$indices[$base + $x] ?? 0] ?? [0, 0, 0];
                    $row .= chr($c[0]) . chr($c[1]) . chr($c[2]);
                }
                // RLE data is bottom-up
                $rgbRows[$topDown ? $y : $h - 1 - $y] = $row;
                $alphaRows[$topDown ? $y : $h - 1 - $y] = str_repeat("\xFF", $w);
            }
        } else {
            $rowBytes = (int) (floor(($w * $bpp + 31) / 32) * 4);
            if ($dataOffset + $rowBytes * $h > $len + 4) {
                // Truncated file: tolerate missing trailing rows by padding
                $raw .= str_repeat("\0", max(0, $dataOffset + $rowBytes * $h - $len));
            }
            $shifts = [];
            $maxv = [];
            foreach ([0, 1, 2, 3] as $i) {
                $mk = $masks[$i];
                if ($mk === 0) {
                    $shifts[$i] = 0;
                    $maxv[$i] = 0;
                    continue;
                }
                $s = 0;
                while ((($mk >> $s) & 1) === 0) {
                    $s++;
                }
                $bits = 0;
                while ((($mk >> ($s + $bits)) & 1) === 1) {
                    $bits++;
                }
                $shifts[$i] = $s;
                $maxv[$i] = (1 << $bits) - 1;
            }
            for ($y = 0; $y < $h; $y++) {
                $srcRow = $topDown ? $y : $h - 1 - $y;
                $o = $dataOffset + $srcRow * $rowBytes;
                $row = '';
                $arow = '';
                if ($bpp === 24) {
                    for ($x = 0; $x < $w; $x++) {
                        $p = $o + $x * 3;
                        $row .= $raw[$p + 2] . $raw[$p + 1] . $raw[$p];
                    }
                    $arow = str_repeat("\xFF", $w);
                } elseif ($bpp === 32 || $bpp === 16) {
                    $bytesPer = $bpp >> 3;
                    for ($x = 0; $x < $w; $x++) {
                        $p = $o + $x * $bytesPer;
                        $px = $bpp === 32
                            ? (ord($raw[$p]) | (ord($raw[$p + 1]) << 8) | (ord($raw[$p + 2]) << 16) | (ord($raw[$p + 3]) << 24))
                            : (ord($raw[$p]) | (ord($raw[$p + 1]) << 8));
                        $px &= 0xFFFFFFFF;
                        $ch = [];
                        for ($i = 0; $i < 3; $i++) {
                            $ch[$i] = $maxv[$i] > 0 ? (int) round((($px & $masks[$i]) >> $shifts[$i]) * 255 / $maxv[$i]) : 0;
                        }
                        $a = $maxv[3] > 0 ? (int) round((($px & $masks[3]) >> $shifts[3]) * 255 / $maxv[3]) : 255;
                        if ($a !== 255) {
                            $hasAlpha = true;
                        }
                        $row .= chr($ch[0]) . chr($ch[1]) . chr($ch[2]);
                        $arow .= chr($a);
                    }
                } else {
                    $mask = (1 << $bpp) - 1;
                    for ($x = 0; $x < $w; $x++) {
                        $bitPos = $x * $bpp;
                        $byte = ord($raw[$o + ($bitPos >> 3)]);
                        $idx = ($byte >> (8 - $bpp - ($bitPos & 7))) & $mask;
                        $c = $palette[$idx] ?? [0, 0, 0];
                        $row .= chr($c[0]) . chr($c[1]) . chr($c[2]);
                    }
                    $arow = str_repeat("\xFF", $w);
                }
                $rgbRows[$y] = $row;
                $alphaRows[$y] = $arow;
            }
            // 32-bit BMPs without a real alpha channel often have all-zero alpha: treat as opaque
            if ($hasAlpha && $bpp === 32 && trim(implode('', $alphaRows), "\0") === '') {
                $hasAlpha = false;
            }
        }

        return new RasterImage($w, $h, implode('', $rgbRows), $hasAlpha ? implode('', $alphaRows) : null);
    }

    /**
     * Decodes RLE8 / RLE4 data into palette indices (bottom-up order, row 0 = bottom line).
     *
     * @return array<int, int>|null
     */
    private static function decodeRle(string $raw, int $offset, int $w, int $h, bool $rle4): ?array
    {
        $len = strlen($raw);
        $out = [];
        $x = 0;
        $y = 0;
        $i = $offset;
        while ($i + 1 < $len && $y < $h) {
            $n = ord($raw[$i]);
            $v = ord($raw[$i + 1]);
            $i += 2;
            if ($n > 0) {
                for ($k = 0; $k < $n; $k++) {
                    $idx = $rle4 ? (($k & 1) === 0 ? $v >> 4 : $v & 15) : $v;
                    if ($x < $w) {
                        $out[$y * $w + $x] = $idx;
                    }
                    $x++;
                }
                continue;
            }
            if ($v === 0) {
                $x = 0;
                $y++;
            } elseif ($v === 1) {
                break;
            } elseif ($v === 2) {
                $x += ord($raw[$i] ?? "\0");
                $y += ord($raw[$i + 1] ?? "\0");
                $i += 2;
            } else {
                $bytes = $rle4 ? (int) ceil($v / 2) : $v;
                for ($k = 0; $k < $v; $k++) {
                    if ($rle4) {
                        $b = ord($raw[$i + ($k >> 1)] ?? "\0");
                        $idx = ($k & 1) === 0 ? $b >> 4 : $b & 15;
                    } else {
                        $idx = ord($raw[$i + $k] ?? "\0");
                    }
                    if ($x < $w) {
                        $out[$y * $w + $x] = $idx;
                    }
                    $x++;
                }
                $i += $bytes + ($bytes & 1);
            }
        }
        return $out;
    }

    private static function u16(string $d, int $o): int
    {
        return ord($d[$o]) | (ord($d[$o + 1]) << 8);
    }

    private static function u32(string $d, int $o): int
    {
        return ord($d[$o]) | (ord($d[$o + 1]) << 8) | (ord($d[$o + 2]) << 16) | (ord($d[$o + 3]) << 24);
    }

    private static function s32(string $d, int $o): int
    {
        $v = self::u32($d, $o);
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }
}
