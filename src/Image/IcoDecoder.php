<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Windows icon / cursor (.ico / .cur) reader: picks the largest image of the file (most colours on a tie). PNG
 * compressed entries are returned as PNG bytes; BMP (DIB) entries are decoded with BmpDecoder, their 1-bit AND mask
 * becoming transparency when the image has no alpha channel of its own.
 */
final class IcoDecoder
{
    public static function isIco(string $raw): bool
    {
        if (strlen($raw) < 22 || self::u16($raw, 0) !== 0 || !in_array(self::u16($raw, 2), [1, 2], true)) {
            return false;
        }
        $count = self::u16($raw, 4);
        if ($count < 1 || 6 + 16 * $count > strlen($raw)) {
            return false;
        }
        $size = self::u32($raw, 6 + 8);
        $offset = self::u32($raw, 6 + 12);
        return $size > 0 && $offset >= 6 + 16 * $count && $offset + min($size, 8) <= strlen($raw);
    }

    /**
     * The best image of the icon: PNG bytes (string) or a decoded raster, null when unreadable.
     */
    public static function decode(string $raw, int $maxPixels = 25000000): string|RasterImage|null
    {
        if (!self::isIco($raw)) {
            return null;
        }
        $len = strlen($raw);
        $best = null;
        for ($i = 0, $count = self::u16($raw, 4); $i < $count; $i++) {
            $e = 6 + 16 * $i;
            $w = ord($raw[$e]) ?: 256;
            $h = ord($raw[$e + 1]) ?: 256;
            $bpp = self::u16($raw, $e + 6);
            $size = self::u32($raw, $e + 8);
            $offset = self::u32($raw, $e + 12);
            if ($size < 8 || $offset + $size > $len) {
                continue;
            }
            $score = [$w * $h, $bpp];
            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score, 'size' => $size, 'offset' => $offset];
            }
        }
        if ($best === null) {
            return null;
        }
        $data = substr($raw, $best['offset'], $best['size']);
        if (str_starts_with($data, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return $data;
        }
        return self::decodeDib($data, $maxPixels);
    }

    private static function decodeDib(string $dib, int $maxPixels): ?RasterImage
    {
        if (strlen($dib) < 40) {
            return null;
        }
        $hdrSize = self::u32($dib, 0);
        if ($hdrSize < 40 || $hdrSize > strlen($dib)) {
            return null;
        }
        $w = self::s32($dib, 4);
        $h = intdiv(abs(self::s32($dib, 8)), 2); // the stored height covers the colour image and the AND mask
        $bpp = self::u16($dib, 14);
        $comp = self::u32($dib, 16);
        $colorsUsed = self::u32($dib, 32);
        if ($w <= 0 || $h <= 0 || $w * $h > $maxPixels) {
            return null;
        }
        $palette = $bpp <= 8 ? min($colorsUsed > 0 ? $colorsUsed : (1 << $bpp), 1 << $bpp) * 4 : 0;
        $masks = $comp === 3 && $hdrSize === 40 ? 12 : 0;
        $pixelOffset = 14 + $hdrSize + $palette + $masks;
        // A BITMAPFILEHEADER in front and the real (bottom-up) height make it an ordinary BMP file
        $header = substr($dib, 0, 8) . pack('V', $h) . substr($dib, 12, $hdrSize - 12);
        $bmp = 'BM' . pack('V', 14 + strlen($dib)) . "\0\0\0\0" . pack('V', $pixelOffset) . $header . substr($dib, $hdrSize);
        $img = BmpDecoder::decode($bmp, $maxPixels);
        if ($img === null || $img->alpha !== null) {
            return $img;
        }
        // No alpha channel: the 1-bit AND mask after the colour rows marks the transparent pixels
        $xorRow = (int) (floor(($w * $bpp + 31) / 32) * 4);
        $andRow = (int) (floor(($w + 31) / 32) * 4);
        $andStart = $hdrSize + $palette + $masks + $xorRow * $h;
        if ($andStart + $andRow * $h > strlen($dib)) {
            return $img;
        }
        $alpha = '';
        $transparent = false;
        for ($y = 0; $y < $h; $y++) {
            $o = $andStart + ($h - 1 - $y) * $andRow; // bottom-up
            for ($x = 0; $x < $w; $x++) {
                $bit = (ord($dib[$o + ($x >> 3)]) >> (7 - ($x & 7))) & 1;
                $alpha .= $bit === 1 ? "\x00" : "\xFF";
                $transparent = $transparent || $bit === 1;
            }
        }
        if ($transparent) {
            $img->alpha = $alpha;
        }
        return $img;
    }

    private static function u16(string $s, int $o): int
    {
        return ord($s[$o]) | (ord($s[$o + 1]) << 8);
    }

    private static function u32(string $s, int $o): int
    {
        return (ord($s[$o]) | (ord($s[$o + 1]) << 8) | (ord($s[$o + 2]) << 16) | (ord($s[$o + 3]) << 24)) & 0xFFFFFFFF;
    }

    private static function s32(string $s, int $o): int
    {
        $v = self::u32($s, $o);
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }
}
