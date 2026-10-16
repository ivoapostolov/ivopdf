<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Decoded 8-bit RGB raster (+ optional 8-bit alpha plane) with helpers shared by the pure-PHP decoders:
 * conversion to a PDF image description, EXIF-style orientation and CSS colour filters.
 */
final class RasterImage
{
    /**
     * @param string $rgb width*height*3 bytes (row-major, top-down)
     * @param string|null $alpha width*height bytes or null when fully opaque
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $rgb,
        public ?string $alpha = null
    ) {
    }

    /**
     * Builds a raster from a GD image (any type); the GD image is not freed.
     */
    public static function fromGd(\GdImage $im): ?self
    {
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($im)) {
            @imagepalettetotruecolor($im);
        }
        $rgb = [];
        $alpha = [];
        $hasAlpha = false;
        for ($y = 0; $y < $h; $y++) {
            $rowRgb = '';
            $rowA = '';
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = 255 - (int) round((($c >> 24) & 0x7F) / 127.0 * 255.0);
                if ($a < 255) {
                    $hasAlpha = true;
                }
                $rowRgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $rowA .= chr($a);
            }
            $rgb[] = $rowRgb;
            $alpha[] = $rowA;
        }
        return new self($w, $h, implode('', $rgb), $hasAlpha ? implode('', $alpha) : null);
    }

    /**
     * Converts to the loader's PDF image structure (Flate RGB + optional soft mask).
     *
     * @return array{id: string, type: string, width: int, height: int, colorSpace: string, bitsPerComponent: int, filter: string, data: string, smask?: string}
     */
    public function toParsed(string $type = 'png'): array
    {
        $res = [
            'id' => '',
            'type' => $type,
            'width' => $this->width,
            'height' => $this->height,
            'colorSpace' => 'DeviceRGB',
            'bitsPerComponent' => 8,
            'filter' => 'FlateDecode',
            'data' => (string) gzcompress($this->rgb, 6),
        ];
        if ($this->alpha !== null) {
            $res['smask'] = (string) gzcompress($this->alpha, 6);
        }
        return $res;
    }

    /**
     * Applies an EXIF orientation (1..8) and returns the transformed raster.
     */
    public function orient(int $orientation): self
    {
        if ($orientation < 2 || $orientation > 8) {
            return $this;
        }
        $w = $this->width;
        $h = $this->height;
        $swap = $orientation >= 5;
        $nw = $swap ? $h : $w;
        $nh = $swap ? $w : $h;
        $rgb = str_repeat("\0", $nw * $nh * 3);
        $alpha = $this->alpha !== null ? str_repeat("\0", $nw * $nh) : null;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                [$dx, $dy] = match ($orientation) {
                    2 => [$w - 1 - $x, $y],
                    3 => [$w - 1 - $x, $h - 1 - $y],
                    4 => [$x, $h - 1 - $y],
                    5 => [$y, $x],
                    6 => [$h - 1 - $y, $x],
                    7 => [$h - 1 - $y, $w - 1 - $x],
                    default => [$y, $w - 1 - $x],
                };
                $si = ($y * $w + $x);
                $di = ($dy * $nw + $dx);
                $rgb[$di * 3] = $this->rgb[$si * 3];
                $rgb[$di * 3 + 1] = $this->rgb[$si * 3 + 1];
                $rgb[$di * 3 + 2] = $this->rgb[$si * 3 + 2];
                if ($alpha !== null) {
                    $alpha[$di] = $this->alpha[$si];
                }
            }
        }
        return new self($nw, $nh, $rgb, $alpha);
    }

    /**
     * Applies CSS colour filter functions (grayscale, sepia, brightness, contrast, invert, saturate, hue-rotate, opacity).
     *
     * @param list<array{0: string, 1: string}> $filters [name, argument] pairs
     */
    public function applyFilters(array $filters): self
    {
        $m = self::identity();
        $opacity = 1.0;
        $contrast = 1.0;
        $brightness = 1.0;
        $invert = 0.0;
        foreach ($filters as [$name, $arg]) {
            $v = self::amount($arg);
            switch ($name) {
                case 'grayscale':
                    $a = 1.0 - max(0.0, min(1.0, $v));
                    $m = self::mul([
                        0.2126 + 0.7874 * $a, 0.7152 - 0.7152 * $a, 0.0722 - 0.0722 * $a,
                        0.2126 - 0.2126 * $a, 0.7152 + 0.2848 * $a, 0.0722 - 0.0722 * $a,
                        0.2126 - 0.2126 * $a, 0.7152 - 0.7152 * $a, 0.0722 + 0.9278 * $a,
                    ], $m);
                    break;
                case 'sepia':
                    $a = 1.0 - max(0.0, min(1.0, $v));
                    $m = self::mul([
                        0.393 + 0.607 * $a, 0.769 - 0.769 * $a, 0.189 - 0.189 * $a,
                        0.349 - 0.349 * $a, 0.686 + 0.314 * $a, 0.168 - 0.168 * $a,
                        0.272 - 0.272 * $a, 0.534 - 0.534 * $a, 0.131 + 0.869 * $a,
                    ], $m);
                    break;
                case 'saturate':
                    $s = max(0.0, $v);
                    $m = self::mul([
                        0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s,
                        0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s,
                        0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s,
                    ], $m);
                    break;
                case 'hue-rotate':
                    $deg = (float) $arg;
                    if (str_ends_with(strtolower($arg), 'rad')) {
                        $deg = rad2deg($deg);
                    } elseif (str_ends_with(strtolower($arg), 'turn')) {
                        $deg *= 360.0;
                    }
                    $c = cos(deg2rad($deg));
                    $s = sin(deg2rad($deg));
                    $m = self::mul([
                        0.213 + $c * 0.787 - $s * 0.213, 0.715 - $c * 0.715 - $s * 0.715, 0.072 - $c * 0.072 + $s * 0.928,
                        0.213 - $c * 0.213 + $s * 0.143, 0.715 + $c * 0.285 + $s * 0.140, 0.072 - $c * 0.072 - $s * 0.283,
                        0.213 - $c * 0.213 - $s * 0.787, 0.715 - $c * 0.715 + $s * 0.715, 0.072 + $c * 0.928 + $s * 0.072,
                    ], $m);
                    break;
                case 'brightness':
                    $brightness *= max(0.0, $v);
                    break;
                case 'contrast':
                    $contrast *= max(0.0, $v);
                    break;
                case 'invert':
                    $invert = max(0.0, min(1.0, $v));
                    break;
                case 'opacity':
                    $opacity *= max(0.0, min(1.0, $v));
                    break;
            }
        }

        $w = $this->width;
        $h = $this->height;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $row = unpack('C*', substr($this->rgb, $y * $w * 3, $w * 3));
            $rowOut = '';
            for ($i = 1, $n = $w * 3; $i <= $n; $i += 3) {
                $r = $row[$i];
                $g = $row[$i + 1];
                $b = $row[$i + 2];
                $nr = $m[0] * $r + $m[1] * $g + $m[2] * $b;
                $ng = $m[3] * $r + $m[4] * $g + $m[5] * $b;
                $nb = $m[6] * $r + $m[7] * $g + $m[8] * $b;
                if ($brightness !== 1.0) {
                    $nr *= $brightness;
                    $ng *= $brightness;
                    $nb *= $brightness;
                }
                if ($contrast !== 1.0) {
                    $nr = ($nr - 127.5) * $contrast + 127.5;
                    $ng = ($ng - 127.5) * $contrast + 127.5;
                    $nb = ($nb - 127.5) * $contrast + 127.5;
                }
                if ($invert > 0.0) {
                    $nr = $nr * (1.0 - $invert) + (255.0 - $nr) * $invert;
                    $ng = $ng * (1.0 - $invert) + (255.0 - $ng) * $invert;
                    $nb = $nb * (1.0 - $invert) + (255.0 - $nb) * $invert;
                }
                $rowOut .= chr(max(0, min(255, (int) round($nr)))) . chr(max(0, min(255, (int) round($ng)))) . chr(max(0, min(255, (int) round($nb))));
            }
            $out .= $rowOut;
        }

        $alpha = $this->alpha;
        if ($opacity < 0.999) {
            $src = $alpha ?? str_repeat("\xFF", $w * $h);
            $scaled = '';
            $len = strlen($src);
            for ($i = 0; $i < $len; $i++) {
                $scaled .= chr((int) round(ord($src[$i]) * $opacity));
            }
            $alpha = $scaled;
        }
        return new self($w, $h, $out, $alpha);
    }

    /**
     * @return list<float>
     */
    private static function identity(): array
    {
        return [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0];
    }

    /**
     * 3x3 matrix product a * b.
     *
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function mul(array $a, array $b): array
    {
        $o = [];
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $o[] = $a[$r * 3] * $b[$c] + $a[$r * 3 + 1] * $b[3 + $c] + $a[$r * 3 + 2] * $b[6 + $c];
            }
        }
        return $o;
    }

    private static function amount(string $arg): float
    {
        $arg = trim($arg);
        if ($arg === '') {
            return 1.0;
        }
        if (str_ends_with($arg, '%')) {
            return ((float) $arg) / 100.0;
        }
        return is_numeric($arg) ? (float) $arg : 1.0;
    }
}
