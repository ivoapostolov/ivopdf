<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Helpers for derived images: decoding any supported raw format to a raster, EXIF orientation and CSS colour filters.
 */
final class ImageVariants
{
    private const COLOR_FILTERS = ['grayscale', 'sepia', 'brightness', 'contrast', 'invert', 'saturate', 'hue-rotate', 'opacity'];

    /**
     * Extracts the pixel-level filter functions (as [name, arg]) from a CSS `filter` value.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function colorFilters(string $filterCss): array
    {
        $filterCss = trim($filterCss);
        if ($filterCss === '' || strtolower($filterCss) === 'none') {
            return [];
        }
        preg_match_all('/([a-z-]+)\(([^()]*)\)/i', $filterCss, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $f) {
            $name = strtolower($f[1]);
            if (in_array($name, self::COLOR_FILTERS, true)) {
                $out[] = [$name, trim($f[2])];
            }
        }
        return $out;
    }

    /**
     * Decodes raw image bytes (JPEG with EXIF orientation applied, PNG, GIF, WebP, BMP, TIFF) into an RGB(A) raster.
     */
    public static function rasterFromBytes(string $raw, int $maxPixels): ?RasterImage
    {
        if (BmpDecoder::isBmp($raw)) {
            return BmpDecoder::decode($raw, $maxPixels);
        }
        if (TiffDecoder::isTiff($raw)) {
            return TiffDecoder::decode($raw, $maxPixels);
        }
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] * $info[1] > $maxPixels) {
            return null;
        }
        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            return null;
        }
        $raster = RasterImage::fromGd($im);
        unset($im);
        if ($raster !== null && str_starts_with($raw, "\xFF\xD8\xFF")) {
            $raster = $raster->orient(self::jpegOrientation($raw));
        }
        return $raster;
    }

    /**
     * Reads the EXIF Orientation tag (1..8) of a JPEG; 1 when absent.
     */
    public static function jpegOrientation(string $raw): int
    {
        $len = strlen($raw);
        $i = 2;
        while ($i + 4 < $len && $raw[$i] === "\xFF") {
            $marker = ord($raw[$i + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $segLen = (ord($raw[$i + 2]) << 8) | ord($raw[$i + 3]);
            if ($marker === 0xE1 && substr($raw, $i + 4, 6) === "Exif\0\0") {
                $t = $i + 10; // TIFF header
                $le = substr($raw, $t, 2) === 'II';
                $u16 = static function (int $o) use ($raw, $le): int {
                    $a = ord($raw[$o] ?? "\0");
                    $b = ord($raw[$o + 1] ?? "\0");
                    return $le ? ($a | ($b << 8)) : (($a << 8) | $b);
                };
                $u32 = static function (int $o) use ($u16, $le): int {
                    return $le ? ($u16($o) | ($u16($o + 2) << 16)) : (($u16($o) << 16) | $u16($o + 2));
                };
                $ifd = $t + $u32($t + 4);
                $n = $u16($ifd);
                for ($k = 0; $k < $n && $k < 64; $k++) {
                    $e = $ifd + 2 + $k * 12;
                    if ($u16($e) === 0x0112) {
                        $v = $u16($e + 8);
                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }
                return 1;
            }
            $i += 2 + $segLen;
        }
        return 1;
    }
}
