<?php

declare(strict_types=1);

namespace IvoPdf\Image;

use IvoPdf\Options;

/**
 * Pure-PHP image loader and parser for JPEG, PNG (including palette, tRNS, interlaced, and alpha SMask),
 * GIF, WebP, and base64 data URIs with SSRF and LFI protection.
 */
class ImageLoader
{
    private ?string $basePath;
    private bool $allowRemote;
    private ?Options $options;

    /**
     * @var array<string, array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string,
     *   decodeParms?: string
     * }>
     */
    private array $loadedImages = [];

    public function __construct(?string $basePath = null, bool $allowRemote = false, ?Options $options = null)
    {
        $this->basePath = $basePath;
        $this->allowRemote = $allowRemote;
        $this->options = $options;
    }

    /**
     * Resolves and parses an image from a path, URL, or base64 data URI.
     *
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string,
     *   decodeParms?: string
     * }|null
     */
    public function load(string $src): ?array
    {
        $src = trim($src, " \t\n\r\0\x0B'\"");
        if ($src === '') {
            return null;
        }

        $cacheKey = md5($src);
        if (isset($this->loadedImages[$cacheKey])) {
            return $this->loadedImages[$cacheKey];
        }

        // "file.pdf#page=2" selects the page of an imported PDF (drawn as a vector Form XObject)
        $pdfPage = 1;
        $fetchSrc = $src;
        if (preg_match('/^(.+?)#page=(\d{1,6})$/i', $src, $pm)) {
            $fetchSrc = $pm[1];
            $pdfPage = max(1, (int) $pm[2]);
        }
        $rawBytes = $this->fetchBytes($fetchSrc);
        $shortSrc = strlen($src) > 80 ? substr($src, 0, 77) . '...' : $src;
        if ($rawBytes === null || strlen($rawBytes) < 8) {
            $this->options?->addWarning("Image not found or not allowed (configure basePath / allowedPaths / isRemoteEnabled): {$shortSrc}");
            return null;
        }

        $parsed = null;
        if (str_starts_with($rawBytes, "\xFF\xD8\xFF")) {
            $parsed = $this->parseJpeg($rawBytes);
            if ($parsed !== null && ImageVariants::jpegOrientation($rawBytes) > 1) {
                // EXIF orientation: pre-rotate the pixels so the image shows upright
                $oriented = ImageVariants::rasterFromBytes($rawBytes, $this->getMaxPixels());
                $parsed = $oriented !== null ? $oriented->toParsed('jpeg') : $parsed;
            }
        } elseif (str_starts_with($rawBytes, "\x89PNG\x0D\x0A\x1A\x0A")) {
            $parsed = $this->parsePng($rawBytes);
        } elseif (str_starts_with($rawBytes, 'GIF87a') || str_starts_with($rawBytes, 'GIF89a')) {
            $parsed = $this->parseViaGd($rawBytes);
        } elseif (str_starts_with($rawBytes, 'RIFF') && substr($rawBytes, 8, 4) === 'WEBP') {
            $parsed = $this->parseViaGd($rawBytes);
        } elseif (str_starts_with($rawBytes, '%PDF-')) {
            try {
                $parsed = \IvoPdf\Pdf\Import\PdfPageImage::fromBytes($rawBytes, $pdfPage);
            } catch (\Throwable $e) {
                $this->options?->addWarning("PDF page not imported ({$shortSrc}): " . $e->getMessage());
                return null;
            }
        } elseif (BmpDecoder::isBmp($rawBytes)) {
            $parsed = BmpDecoder::decode($rawBytes, $this->getMaxPixels())?->toParsed('bmp');
        } elseif (IcoDecoder::isIco($rawBytes)) {
            $ico = IcoDecoder::decode($rawBytes, $this->getMaxPixels());
            $parsed = is_string($ico) ? $this->parsePng($ico) : $ico?->toParsed('ico');
        } elseif (TiffDecoder::isTiff($rawBytes)) {
            $parsed = TiffDecoder::decode($rawBytes, $this->getMaxPixels())?->toParsed('tiff');
        } elseif (stripos($rawBytes, '<svg') !== false) {
            $parsed = $this->parseSvgFallback($rawBytes);
        }

        if ($parsed === null) {
            $this->options?->addWarning("Unsupported or corrupt image (or larger than maxImagePixels): {$shortSrc}");
            return null;
        }

        $id = 'Im' . (count($this->loadedImages) + 1);
        $parsed['id'] = $id;
        $this->loadedImages[$cacheKey] = $parsed;

        return $parsed;
    }

    /**
     * Records a non-fatal rendering warning (delegates to Options when available).
     */
    public function addWarning(string $message): void
    {
        $this->options?->addWarning($message);
    }

    /**
     * Loads an image with CSS colour filters applied (grayscale, sepia, brightness, ...) and/or with
     * nearest-neighbour rendering (image-rendering: pixelated -> /Interpolate false). The variants are cached.
     *
     * @return array{id: string, type: string, width: int, height: int, colorSpace: string, bitsPerComponent: int, filter: string, data: string, smask?: string, decodeParms?: string}|null
     */
    public function loadVariant(string $src, string $filterCss = 'none', bool $pixelated = false): ?array
    {
        $base = $this->load($src);
        if ($base === null) {
            return null;
        }
        $filters = ImageVariants::colorFilters($filterCss);
        if ($filters === [] && !$pixelated) {
            return $base;
        }
        $key = md5(trim($src, " \t\n\r\0\x0B'\"")) . '|' . md5(json_encode($filters) ?: '') . ($pixelated ? '|px' : '');
        if (isset($this->loadedImages[$key])) {
            return $this->loadedImages[$key];
        }
        $variant = $base;
        if ($filters !== []) {
            $raw = $this->fetchBytes(trim($src, " \t\n\r\0\x0B'\""));
            $raster = $raw !== null ? ImageVariants::rasterFromBytes($raw, $this->getMaxPixels()) : null;
            if ($raster !== null) {
                $variant = $raster->applyFilters($filters)->toParsed('png');
                $variant['id'] = '';
            } else {
                $this->options?->addWarning('CSS filter could not be applied to image (unsupported format): ' . substr($src, 0, 60));
            }
        }
        $variant['id'] = 'Im' . (count($this->loadedImages) + 1);
        $variant['interpolate'] = !$pixelated;
        $this->loadedImages[$key] = $variant;
        return $variant;
    }

    /**
     * Resolves raw bytes from a source (data URI, remote URL, or allowed local file).
     * Useful for SVG `<img src="...">` loading as well.
     */
    public function fetchRawBytes(string $src): ?string
    {
        return $this->fetchBytes(trim($src, " \t\n\r\0\x0B'\""));
    }

    /**
     * @return array<string, array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string,
     *   decodeParms?: string
     * }>
     */
    public function getLoadedImages(): array
    {
        return $this->loadedImages;
    }

    public function reset(): void
    {
        $this->loadedImages = [];
    }

    private function getMaxPixels(): int
    {
        return $this->options !== null ? $this->options->maxImagePixels : 25_000_000;
    }

    private function fetchBytes(string $src): ?string
    {
        // Base64 or URL-encoded data URI
        if (str_starts_with($src, 'data:')) {
            $commaPos = strpos($src, ',');
            if ($commaPos === false) {
                return null;
            }
            $meta = substr($src, 5, $commaPos - 5);
            $payload = substr($src, $commaPos + 1);
            if (str_contains($meta, ';base64')) {
                $decoded = base64_decode($payload, true);
                return $decoded !== false ? $decoded : null;
            }
            return rawurldecode($payload);
        }

        // Block dangerous schemes (phar://, file://, ftp://, php://, etc.)
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $src) && !preg_match('/^https?:\/\//i', $src)) {
            if (!preg_match('/^[a-zA-Z]:[\\\\\/]/', $src)) {
                return null;
            }
        }

        // Remote HTTP/HTTPS URL
        if (preg_match('/^https?:\/\//i', $src)) {
            if ($this->options !== null) {
                if (!$this->options->isRemoteEnabled && !$this->allowRemote) {
                    return null;
                }
                $prev = $this->options->isRemoteEnabled;
                if ($this->allowRemote) {
                    $this->options->isRemoteEnabled = true;
                }
                $data = $this->options->fetchSafeRemoteUrl($src);
                $this->options->isRemoteEnabled = $prev;
                return $data;
            }

            if (!$this->allowRemote) {
                return null;
            }
            $tempOpts = new Options(['isRemoteEnabled' => true]);
            return $tempOpts->fetchSafeRemoteUrl($src);
        }

        // Local file path — enforce chroot / allowedPaths containment
        if ($this->options !== null) {
            $safePath = $this->options->resolveAllowedLocalFile($src, $this->basePath);
            if ($safePath === null) {
                return null;
            }
            $data = @file_get_contents($safePath);
            return $data !== false ? $data : null;
        }

        $tempOpts = new Options(['basePath' => $this->basePath]);
        $safePath = $tempOpts->resolveAllowedLocalFile($src, $this->basePath);
        if ($safePath !== null) {
            $data = @file_get_contents($safePath);
            return $data !== false ? $data : null;
        }

        return null;
    }

    /**
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string
     * }|null
     */
    private function parseJpeg(string $raw): ?array
    {
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width * $height > $this->getMaxPixels()) {
            return null;
        }

        $channels = $info['channels'] ?? 3;
        $colorSpace = match ($channels) {
            1 => 'DeviceGray',
            4 => 'DeviceCMYK',
            default => 'DeviceRGB',
        };

        // Adobe Photoshop CMYK JPEGs store inverted values (APP14 "Adobe" marker)
        $decode = ($channels === 4 && str_contains(substr($raw, 0, 4096), 'Adobe')) ? '[1 0 1 0 1 0 1 0]' : null;

        return [
            'id' => '',
            'type' => 'jpeg',
            'decode' => $decode,
            'width' => $width,
            'height' => $height,
            'colorSpace' => $colorSpace,
            'bitsPerComponent' => (int) ($info['bits'] ?? 8),
            'filter' => 'DCTDecode',
            'data' => $raw,
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string,
     *   decodeParms?: string
     * }|null
     */
    private function parsePng(string $raw): ?array
    {
        $len = strlen($raw);
        if ($len < 33) {
            return null;
        }

        $width = $this->readUInt32($raw, 16);
        $height = $this->readUInt32($raw, 20);
        $bitDepth = ord($raw[24]);
        $colorType = ord($raw[25]);
        $interlace = ord($raw[28]);

        if ($width <= 0 || $height <= 0 || ($width * $height) > $this->getMaxPixels()) {
            return null;
        }

        // Scan chunks for IDAT and tRNS
        $idat = '';
        $hasTrns = false;
        $pos = 8;
        while ($pos + 8 <= $len) {
            $chunkLen = $this->readUInt32($raw, $pos);
            if ($chunkLen < 0 || $pos + 12 + $chunkLen > $len) {
                break;
            }
            $chunkType = substr($raw, $pos + 4, 4);
            if ($chunkType === 'IDAT') {
                $idat .= substr($raw, $pos + 8, $chunkLen);
            } elseif ($chunkType === 'tRNS') {
                $hasTrns = true;
            } elseif ($chunkType === 'IEND') {
                break;
            }
            $pos += 12 + $chunkLen;
        }

        // Fast path ONLY for non-interlaced 8-bit RGB (colorType 2) or 8-bit Grayscale (colorType 0) without tRNS
        if ($bitDepth === 8 && ($colorType === 2 || $colorType === 0) && $interlace === 0 && !$hasTrns && $idat !== '') {
            $colors = $colorType === 2 ? 3 : 1;
            return [
                'id' => '',
                'type' => 'png',
                'width' => $width,
                'height' => $height,
                'colorSpace' => $colorType === 2 ? 'DeviceRGB' : 'DeviceGray',
                'bitsPerComponent' => 8,
                'filter' => 'FlateDecode',
                'data' => $idat,
                'decodeParms' => "<< /Predictor 15 /Colors {$colors} /BitsPerComponent 8 /Columns {$width} >>",
            ];
        }

        // Pure-PHP PNG RGBA (colorType 6) or Gray+Alpha (colorType 4) when non-interlaced
        if ($bitDepth === 8 && ($colorType === 6 || $colorType === 4) && $interlace === 0 && $idat !== '') {
            $unfiltered = $this->decodePngWithAlphaPurePhp($idat, $width, $height, $colorType);
            if ($unfiltered !== null) {
                return $unfiltered;
            }
        }

        // Fallback via GD for indexed palette PNGs (colorType 3), interlaced PNGs (Adam7), tRNS PNGs, 16-bit PNGs, etc.
        return $this->parseViaGd($raw);
    }

    /**
     * Decodes PNG (palette, interlaced, tRNS, 16-bit), GIF, or WebP via GD into RGB + optional SMask streams.
     *
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string
     * }|null
     */
    private function parseViaGd(string $raw): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($raw);
        if ($info !== false) {
            $w = (int) $info[0];
            $h = (int) $info[1];
            if ($w <= 0 || $h <= 0 || ($w * $h) > $this->getMaxPixels()) {
                return null;
            }
        }

        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            return null;
        }

        $width = imagesx($im);
        $height = imagesy($im);
        if ($width <= 0 || $height <= 0 || ($width * $height) > $this->getMaxPixels()) {
            unset($im);
            return null;
        }

        // Convert palette images (PNG colorType 3, GIF) to truecolor so imagecolorat() returns real RGB + alpha!
        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($im)) {
            @imagepalettetotruecolor($im);
        }

        $rgbChunks = [];
        $alphaChunks = [];
        $hasTransparency = false;

        for ($y = 0; $y < $height; $y++) {
            $rowRgb = '';
            $rowAlpha = '';
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $a7 = ($rgba >> 24) & 0x7F; // 0 = opaque, 127 = transparent
                $alpha = 255 - (int) round(($a7 / 127.0) * 255.0);
                if ($alpha < 255) {
                    $hasTransparency = true;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $rowRgb .= chr($r) . chr($g) . chr($b);
                $rowAlpha .= chr($alpha);
            }
            $rgbChunks[] = $rowRgb;
            $alphaChunks[] = $rowAlpha;
        }
        unset($im);

        $rgbBytes = implode('', $rgbChunks);
        $result = [
            'id' => '',
            'type' => 'png',
            'width' => $width,
            'height' => $height,
            'colorSpace' => 'DeviceRGB',
            'bitsPerComponent' => 8,
            'filter' => 'FlateDecode',
            'data' => (string) gzcompress($rgbBytes, 6),
        ];
        if ($hasTransparency) {
            $alphaBytes = implode('', $alphaChunks);
            $result['smask'] = (string) gzcompress($alphaBytes, 6);
        }
        return $result;
    }

    /**
     * Pure-PHP PNG filter reconstruction & RGB + Alpha separation (no GD required).
     *
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string
     * }|null
     */
    private function decodePngWithAlphaPurePhp(string $idat, int $width, int $height, int $colorType): ?array
    {
        $decompressed = @gzuncompress($idat);
        if ($decompressed === false) {
            $decompressed = @zlib_decode($idat);
        }
        if ($decompressed === false) {
            return null;
        }

        $bpp = $colorType === 6 ? 4 : 2; // bytes per pixel
        $stride = $width * $bpp;
        $expectedLen = $height * ($stride + 1);
        if (strlen($decompressed) < $expectedLen) {
            return null;
        }

        $colorChunks = [];
        $alphaChunks = [];
        $prevRow = str_repeat("\x00", $stride);
        $offset = 0;

        for ($y = 0; $y < $height; $y++) {
            $filter = ord($decompressed[$offset]);
            $offset++;
            $curRow = substr($decompressed, $offset, $stride);
            $offset += $stride;

            if ($filter !== 0) {
                for ($i = 0; $i < $stride; $i++) {
                    $xByte = ord($curRow[$i]);
                    $a = $i >= $bpp ? ord($curRow[$i - $bpp]) : 0;
                    $b = ord($prevRow[$i]);
                    $c = $i >= $bpp ? ord($prevRow[$i - $bpp]) : 0;

                    $recon = match ($filter) {
                        1 => ($xByte + $a) & 0xFF,
                        2 => ($xByte + $b) & 0xFF,
                        3 => ($xByte + (($a + $b) >> 1)) & 0xFF,
                        4 => ($xByte + $this->paethPredictor($a, $b, $c)) & 0xFF,
                        default => $xByte,
                    };
                    $curRow[$i] = chr($recon);
                }
            }

            $rowColor = '';
            $rowAlpha = '';
            if ($colorType === 6) {
                for ($p = 0; $p < $stride; $p += 4) {
                    $rowColor .= substr($curRow, $p, 3);
                    $rowAlpha .= $curRow[$p + 3];
                }
            } else {
                for ($p = 0; $p < $stride; $p += 2) {
                    $rowColor .= $curRow[$p];
                    $rowAlpha .= $curRow[$p + 1];
                }
            }

            $colorChunks[] = $rowColor;
            $alphaChunks[] = $rowAlpha;
            $prevRow = $curRow;
        }

        return [
            'id' => '',
            'type' => 'png',
            'width' => $width,
            'height' => $height,
            'colorSpace' => $colorType === 6 ? 'DeviceRGB' : 'DeviceGray',
            'bitsPerComponent' => 8,
            'filter' => 'FlateDecode',
            'data' => (string) gzcompress(implode('', $colorChunks), 6),
            'smask' => (string) gzcompress(implode('', $alphaChunks), 6),
        ];
    }

    /**
     * Parses basic SVG dimensions and produces a valid PDF raster XObject fallback when loaded via ImageLoader
     * (note that `<img>` tags pointing to SVG are already promoted to native vector `<svg>` in DomBuilder).
     *
     * @return array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string
     * }|null
     */
    private function parseSvgFallback(string $svgXml): ?array
    {
        $w = 100;
        $h = 100;
        if (preg_match('/\bviewBox\s*=\s*["\']\s*[-\d.]+\s+[-\d.]+\s+([\d.]+)\s+([\d.]+)/i', $svgXml, $vm)) {
            $w = max(1, min(1024, (int) round((float) $vm[1])));
            $h = max(1, min(1024, (int) round((float) $vm[2])));
        }
        if (preg_match('/<svg[^>]*\bwidth\s*=\s*["\']([\d.]+)/i', $svgXml, $wm)) {
            $w = max(1, min(1024, (int) round((float) $wm[1])));
        }
        if (preg_match('/<svg[^>]*\bheight\s*=\s*["\']([\d.]+)/i', $svgXml, $hm)) {
            $h = max(1, min(1024, (int) round((float) $hm[1])));
        }

        $rgbBytes = str_repeat("\xFF\xFF\xFF", $w * $h);
        $alphaBytes = str_repeat("\x00", $w * $h);

        return [
            'id' => '',
            'type' => 'png',
            'width' => $w,
            'height' => $h,
            'colorSpace' => 'DeviceRGB',
            'bitsPerComponent' => 8,
            'filter' => 'FlateDecode',
            'data' => (string) gzcompress($rgbBytes, 6),
            'smask' => (string) gzcompress($alphaBytes, 6),
        ];
    }

    private function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }
        return $c;
    }

    private function readUInt32(string $data, int $pos): int
    {
        $unpacked = unpack('N', substr($data, $pos, 4));
        return $unpacked ? (int) $unpacked[1] : 0;
    }
}
