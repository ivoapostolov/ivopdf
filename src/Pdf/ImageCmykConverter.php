<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;
use IvoPdf\RenderLimits;

/**
 * Converts decoded RGB image XObject data (Flate/PNG-predictor or JPEG via GD) to DeviceCMYK at embed time and,
 * when transparency is forbidden, composites soft-masked images onto white. Uses the same RGB to CMYK conversion as
 * ColorOps (replaceable through ColorOps::setConverter()). Results are cached per image.
 */
final class ImageCmykConverter
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** Why the last conversion failed (for the warning). */
    private static string $failure = '';

    /**
     * @param array<string, mixed> $img image data as produced by ImageLoader
     * @return array<string, mixed> image data (possibly converted)
     */
    public static function convert(array $img, Options $opt, bool $toCmyk, bool $flatten): array
    {
        $space = (string) ($img['colorSpace'] ?? 'DeviceRGB');
        $hasMask = !empty($img['smask']);
        if (($space !== 'DeviceRGB' || !$toCmyk) && !($hasMask && $flatten)) {
            return $img;
        }
        if ($space === 'DeviceCMYK') {
            unset($img['smask']);
            $opt->addWarning('PDF/X: the alpha channel of a CMYK image was dropped (transparency is not allowed).');
            return $img;
        }
        if ((int) ($img['bitsPerComponent'] ?? 8) !== 8 || ($space !== 'DeviceRGB' && $space !== 'DeviceGray')) {
            $opt->addWarning("Image could not be converted to CMYK (unsupported {$space} / bit depth); left as is.");
            return $img;
        }

        $key = md5((string) $img['data'] . '|' . ($img['smask'] ?? '') . '|' . $space . ($toCmyk ? 'c' : 'r') . ($flatten ? 'f' : 'n'));
        if (isset(self::$cache[$key])) {
            return array_replace($img, self::$cache[$key]);
        }

        $w = (int) $img['width'];
        $h = (int) $img['height'];
        $channels = $space === 'DeviceRGB' ? 3 : 1;
        $res = $w > 0 && $h > 0 ? self::convertRows($img, $w, $h, $channels, $toCmyk && $space === 'DeviceRGB', $hasMask && $flatten) : null;
        if ($res === null) {
            return self::unconverted($img, $opt, $flatten);
        }
        if ($hasMask && $flatten) {
            $res['smask'] = null;
            $opt->addWarning('PDF/X: an image alpha channel was composited onto white (transparency is not allowed).');
        }
        if (count(self::$cache) >= 12) {
            self::$cache = [];
        }
        self::$cache[$key] = $res;
        return array_replace($img, $res);
    }

    /**
     * Converts the image row by row: the pixel data (and the soft mask) are inflated in small pieces and every converted
     * row is deflated at once, so neither the decoded source nor the decoded result ever sits in memory as a whole.
     * The memory budget (RenderLimits) is checked before GD decodes a JPEG and as the compressed result grows. Null
     * when the image cannot be converted (corrupt / truncated data, unsupported encoding, not enough memory; the reason
     * is kept in self::$failure).
     *
     * @param array<string, mixed> $img
     * @return ?array<string, mixed>
     */
    private static function convertRows(array $img, int $w, int $h, int $channels, bool $toCmyk, bool $flatten): ?array
    {
        self::$failure = 'its pixel data could not be decoded';
        $filter = (string) ($img['filter'] ?? '');
        $parms = isset($img['decodeParms']) ? (string) $img['decodeParms'] : null;
        $stride = $w * $channels;
        $gd = null;
        $rows = null;
        if ($filter === 'FlateDecode') {
            if (ImageRowStream::hasUnsupportedPredictor($parms)) {
                return null;
            }
            $rows = ImageRowStream::open((string) $img['data'], $stride, $channels, ImageRowStream::hasPngPredictor($parms));
            if ($rows === null) {
                return null;
            }
        } elseif ($filter === 'DCTDecode' && function_exists('imagecreatefromstring')) {
            // GD decodes a JPEG as a whole: a truecolor bitmap (4 bytes per pixel) plus libjpeg's buffers
            $need = $w * $h * 5 + 4 * $stride + 2 * strlen((string) $img['data']) + 4194304;
            if (!RenderLimits::fits($need)) {
                self::$failure = sprintf('decoding it needs about %s MB, more than the memory left (Options::$maxMemory / memory_limit)', number_format($need / 1048576, 1, '.', ''));
                return null;
            }
            $gd = ImageRowStream::quietly(static fn() => imagecreatefromstring((string) $img['data']));
            if ($gd === false || imagesx($gd) !== $w || imagesy($gd) !== $h) {
                return null;
            }
        } else {
            self::$failure = "its {$filter} encoding is not supported";
            return null;
        }

        $alpha = $flatten ? ImageRowStream::open((string) $img['smask'], $w, 1, false) : null;
        $outZ = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);
        if ($outZ === false) {
            return null;
        }
        $out = ''; // appended to: deflate_add() results keep the capacity of their input
        $cache = [];
        $lastIn = null;
        $lastOut = '';
        for ($y = 0; $y < $h; $y++) {
            RenderLimits::tick();
            if ($gd !== null) {
                $row = '';
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($gd, $x, $y);
                    $row .= $channels === 3 ? chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255) : chr($c & 255);
                }
            } else {
                $row = $rows->next();
                if ($row === null) {
                    return null; // truncated / corrupt pixel data
                }
            }
            $a = null;
            if ($alpha !== null) {
                $a = $alpha->next();
                if ($a === null) {
                    $alpha = null; // a broken soft mask: the remaining rows are taken as opaque
                }
            }
            // Identical source rows (flat areas, and every decompression bomb) are converted once
            $sig = $a === null ? $row : $row . "\0" . $a;
            if ($sig !== $lastIn) {
                if ($toCmyk) {
                    $lastOut = self::rgbRowToCmyk($row, $a, $cache);
                } else {
                    $lastOut = $a === null ? $row : self::compositeRowOnWhite($row, $w, $channels, $a);
                }
                $lastIn = $sig;
            }
            $out .= deflate_add($outZ, $lastOut, ZLIB_NO_FLUSH);
            if (($y & 31) === 31 && !RenderLimits::fits(strlen($out) + 4 * strlen($lastOut) + 4194304)) {
                self::$failure = 'the converted image does not fit the memory left (Options::$maxMemory / memory_limit)';
                return null;
            }
        }
        $gd = null;
        $out .= deflate_add($outZ, '', ZLIB_FINISH);
        $res = ['data' => $out, 'filter' => 'FlateDecode', 'decodeParms' => null, 'decode' => null];
        if ($toCmyk) {
            $res['colorSpace'] = 'DeviceCMYK';
        }
        return $res;
    }

    /**
     * What is embedded when an image cannot be converted. With colorSpace 'cmyk' alone the RGB image is kept (a
     * warning tells); under PDF/X, where an RGB or soft-masked image would make the file non-conforming, the image is
     * skipped: a single white pixel takes its place.
     *
     * @param array<string, mixed> $img
     * @return array<string, mixed>
     */
    private static function unconverted(array $img, Options $opt, bool $flatten): array
    {
        $size = (int) $img['width'] . ' x ' . (int) $img['height'] . ' px';
        if ($opt->pdfx === '' && !$flatten) {
            $opt->addWarning("Image ({$size}) could not be converted to CMYK because " . self::$failure . '; left as is.');
            return $img;
        }
        $opt->addWarning("PDF/X: image ({$size}) skipped: it could not be converted to CMYK / flattened because " . self::$failure . '.');
        $cmyk = ColorOps::isCmykTarget() && ($img['colorSpace'] ?? '') !== 'DeviceGray';
        return array_replace($img, [
            'width' => 1,
            'height' => 1,
            'colorSpace' => $cmyk ? 'DeviceCMYK' : 'DeviceGray',
            'bitsPerComponent' => 8,
            'data' => (string) gzcompress($cmyk ? "\0\0\0\0" : "\xFF"),
            'filter' => 'FlateDecode',
            'decodeParms' => null,
            'decode' => null,
            'smask' => null,
        ]);
    }

    /**
     * @param array<string, string> $cache pixel (+ alpha) => CMYK bytes, shared by the rows of one image
     */
    private static function rgbRowToCmyk(string $row, ?string $alpha, array &$cache): string
    {
        $line = '';
        foreach (str_split($row, 3) as $x => $p) {
            $key = $alpha !== null ? $p . $alpha[$x] : $p;
            if (!isset($cache[$key])) {
                if (count($cache) > 150000) {
                    $cache = [];
                }
                $r = ord($p[0]) / 255.0;
                $g = ord($p[1]) / 255.0;
                $b = ord($p[2]) / 255.0;
                if ($alpha !== null) {
                    $a = ord($alpha[$x]) / 255.0;
                    $r = 1.0 - $a * (1.0 - $r);
                    $g = 1.0 - $a * (1.0 - $g);
                    $b = 1.0 - $a * (1.0 - $b);
                }
                $k = ColorOps::cmyk([$r, $g, $b, 1.0]);
                $cache[$key] = chr((int) round($k[0] * 255)) . chr((int) round($k[1] * 255)) . chr((int) round($k[2] * 255)) . chr((int) round($k[3] * 255));
            }
            $line .= $cache[$key];
        }
        return $line;
    }

    private static function compositeRowOnWhite(string $row, int $w, int $channels, string $alpha): string
    {
        if (trim($alpha, "\xFF") === '') {
            return $row;
        }
        $out = '';
        for ($i = 0; $i < $w; $i++) {
            $a = ord($alpha[$i]);
            for ($c = 0; $c < $channels; $c++) {
                $v = ord($row[$i * $channels + $c]);
                $out .= chr((int) round(255 - $a * (255 - $v) / 255));
            }
        }
        return $out;
    }
}
