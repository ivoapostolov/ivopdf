<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * Converts decoded RGB image XObject data (Flate/PNG-predictor or JPEG via GD) to DeviceCMYK at embed time and,
 * when transparency is forbidden, composites soft-masked images onto white. Uses the same RGB to CMYK conversion as
 * ColorOps (replaceable through ColorOps::setConverter()). Results are cached per image.
 */
final class ImageCmykConverter
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

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
        $raw = self::decodeRaw($img, $w, $h, $channels);
        if ($raw === null) {
            $opt->addWarning('Image could not be decoded for CMYK/transparency conversion; left as is.');
            return $img;
        }
        $alpha = null;
        if ($hasMask && $flatten) {
            $a = @gzuncompress((string) $img['smask']);
            if ($a !== false && strlen($a) >= $w * $h) {
                $alpha = $a;
            }
            $opt->addWarning('PDF/X: an image alpha channel was composited onto white (transparency is not allowed).');
        }

        if ($space === 'DeviceRGB' && $toCmyk) {
            $data = self::rgbToCmyk($raw, $w, $h, $alpha);
            $res = ['colorSpace' => 'DeviceCMYK', 'data' => (string) gzcompress($data, 6), 'filter' => 'FlateDecode', 'decodeParms' => null, 'decode' => null];
        } elseif ($space === 'DeviceRGB') {
            $res = ['data' => (string) gzcompress(self::compositeOnWhite($raw, $w, $h, 3, (string) $alpha), 6), 'filter' => 'FlateDecode', 'decodeParms' => null, 'decode' => null];
        } else {
            $res = ['data' => (string) gzcompress(self::compositeOnWhite($raw, $w, $h, 1, (string) $alpha), 6), 'filter' => 'FlateDecode', 'decodeParms' => null, 'decode' => null];
        }
        if ($hasMask && $flatten) {
            $res['smask'] = null;
        }
        if (count(self::$cache) >= 12) {
            self::$cache = [];
        }
        self::$cache[$key] = $res;
        return array_replace($img, $res);
    }

    /** Raw 8-bit interleaved pixel bytes (w * h * channels) or null. */
    private static function decodeRaw(array $img, int $w, int $h, int $channels): ?string
    {
        $filter = (string) ($img['filter'] ?? '');
        $data = (string) $img['data'];
        $need = $w * $h * $channels;
        if ($filter === 'FlateDecode') {
            $z = @gzuncompress($data);
            if ($z === false) {
                return null;
            }
            if (!empty($img['decodeParms']) && str_contains((string) $img['decodeParms'], '/Predictor')) {
                $z = self::unPredict($z, $w, $h, $channels);
            }
            return $z !== null && strlen($z) >= $need ? $z : null;
        }
        if ($filter === 'DCTDecode' && function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($data);
            if ($im === false) {
                return null;
            }
            $out = [];
            for ($y = 0; $y < $h; $y++) {
                $row = '';
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($im, $x, $y);
                    $row .= $channels === 3 ? chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255) : chr($c & 255);
                }
                $out[] = $row;
            }
            return implode('', $out);
        }
        return null;
    }

    /** Reverses PNG row filters (Predictor 10..15) for 8-bit data. */
    private static function unPredict(string $z, int $w, int $h, int $bpp): ?string
    {
        $stride = $w * $bpp;
        if (strlen($z) < ($stride + 1) * $h) {
            return null;
        }
        $out = [];
        $prev = array_fill(0, $stride, 0);
        $pos = 0;
        for ($y = 0; $y < $h; $y++) {
            $type = ord($z[$pos++]);
            $line = array_values(unpack('C*', substr($z, $pos, $stride)) ?: []);
            $pos += $stride;
            if (count($line) !== $stride) {
                return null;
            }
            switch ($type) {
                case 1:
                    for ($i = $bpp; $i < $stride; $i++) {
                        $line[$i] = ($line[$i] + $line[$i - $bpp]) & 255;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $stride; $i++) {
                        $line[$i] = ($line[$i] + $prev[$i]) & 255;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $bpp ? $line[$i - $bpp] : 0;
                        $line[$i] = ($line[$i] + (($left + $prev[$i]) >> 1)) & 255;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $stride; $i++) {
                        $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $line[$i] = ($line[$i] + $pr) & 255;
                    }
                    break;
            }
            $out[] = pack('C*', ...$line);
            $prev = $line;
        }
        return implode('', $out);
    }

    private static function rgbToCmyk(string $raw, int $w, int $h, ?string $alpha): string
    {
        $stride = $w * 3;
        $cache = [];
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            $px = str_split(substr($raw, $y * $stride, $stride), 3);
            $line = '';
            $ao = $y * $w;
            foreach ($px as $x => $p) {
                $key = $alpha !== null ? $p . $alpha[$ao + $x] : $p;
                if (!isset($cache[$key])) {
                    if (count($cache) > 150000) {
                        $cache = [];
                    }
                    $r = ord($p[0]) / 255.0;
                    $g = ord($p[1]) / 255.0;
                    $b = ord($p[2]) / 255.0;
                    if ($alpha !== null) {
                        $a = ord($alpha[$ao + $x]) / 255.0;
                        $r = 1.0 - $a * (1.0 - $r);
                        $g = 1.0 - $a * (1.0 - $g);
                        $b = 1.0 - $a * (1.0 - $b);
                    }
                    $k = ColorOps::cmyk([$r, $g, $b, 1.0]);
                    $cache[$key] = chr((int) round($k[0] * 255)) . chr((int) round($k[1] * 255)) . chr((int) round($k[2] * 255)) . chr((int) round($k[3] * 255));
                }
                $line .= $cache[$key];
            }
            $out[] = $line;
        }
        return implode('', $out);
    }

    private static function compositeOnWhite(string $raw, int $w, int $h, int $channels, string $alpha): string
    {
        if ($alpha === '') {
            return substr($raw, 0, $w * $h * $channels);
        }
        $out = '';
        $n = $w * $h;
        for ($i = 0; $i < $n; $i++) {
            $a = ord($alpha[$i]);
            for ($c = 0; $c < $channels; $c++) {
                $v = ord($raw[$i * $channels + $c]);
                $out .= chr((int) round(255 - $a * (255 - $v) / 255));
            }
        }
        return $out;
    }
}
