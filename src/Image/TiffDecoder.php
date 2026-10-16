<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Pure-PHP baseline TIFF decoder (first page): uncompressed, LZW, PackBits and Deflate; bilevel/gray/palette/RGB(A)/CMYK;
 * 1/2/4/8/16 bits per sample, strips (no tiles), horizontal differencing predictor.
 */
final class TiffDecoder
{
    private bool $le = true;

    /** @var array<int, array{type: int, count: int, off: int}> */
    private array $tags = [];

    public static function isTiff(string $raw): bool
    {
        return strlen($raw) > 8 && (str_starts_with($raw, "II*\0") || str_starts_with($raw, "MM\0*"));
    }

    public static function decode(string $raw, int $maxPixels = 25000000): ?RasterImage
    {
        if (!self::isTiff($raw)) {
            return null;
        }
        return (new self())->run($raw, $maxPixels);
    }

    private function run(string $raw, int $maxPixels): ?RasterImage
    {
        $this->le = $raw[0] === 'I';
        $ifd = $this->u32($raw, 4);
        $len = strlen($raw);
        if ($ifd + 2 > $len) {
            return null;
        }
        $n = $this->u16($raw, $ifd);
        for ($i = 0; $i < $n; $i++) {
            $e = $ifd + 2 + $i * 12;
            if ($e + 12 > $len) {
                break;
            }
            $type = $this->u16($raw, $e + 2);
            $count = $this->u32($raw, $e + 4);
            $size = $this->typeSize($type) * $count;
            $off = $size <= 4 ? $e + 8 : $this->u32($raw, $e + 8);
            $this->tags[$this->u16($raw, $e)] = ['type' => $type, 'count' => $count, 'off' => $off];
        }

        $w = $this->first($raw, 256) ?? 0;
        $h = $this->first($raw, 257) ?? 0;
        if ($w <= 0 || $h <= 0 || $w * $h > $maxPixels) {
            return null;
        }
        $bps = $this->values($raw, 258) ?: [1];
        $bits = $bps[0];
        $compression = $this->first($raw, 259) ?? 1;
        $photo = $this->first($raw, 262) ?? 1;
        $spp = $this->first($raw, 277) ?? 1;
        $planar = $this->first($raw, 284) ?? 1;
        $predictor = $this->first($raw, 317) ?? 1;
        $rowsPerStrip = $this->first($raw, 278) ?? $h;
        $rowsPerStrip = max(1, min($rowsPerStrip, $h));
        $extra = $this->values($raw, 338);
        if ($planar !== 1 || isset($this->tags[322]) || !in_array($bits, [1, 2, 4, 8, 16], true)) {
            return null; // planar / tiled TIFFs are not supported
        }
        if (!in_array($compression, [1, 5, 8, 32946, 32773], true)) {
            return null;
        }
        $offsets = $this->values($raw, 273);
        $counts = $this->values($raw, 279);
        if ($offsets === []) {
            return null;
        }

        $palette = [];
        if ($photo === 3) {
            $cm = $this->values($raw, 320);
            $entries = 1 << $bits;
            if (count($cm) < $entries * 3) {
                return null;
            }
            for ($i = 0; $i < $entries; $i++) {
                $palette[$i] = [$cm[$i] >> 8, $cm[$entries + $i] >> 8, $cm[2 * $entries + $i] >> 8];
            }
        }

        $rowBytes = (int) ceil($w * $spp * $bits / 8);
        $data = '';
        foreach ($offsets as $si => $off) {
            $cnt = $counts[$si] ?? ($len - $off);
            $chunk = substr($raw, $off, $cnt);
            $stripRows = min($rowsPerStrip, $h - $si * $rowsPerStrip);
            if ($stripRows <= 0) {
                break;
            }
            $expected = $stripRows * $rowBytes;
            $dec = match ($compression) {
                5 => self::lzw($chunk, $expected),
                8, 32946 => (string) (@gzuncompress($chunk) ?: @gzinflate(substr($chunk, 2)) ?: ''),
                32773 => self::packBits($chunk, $expected),
                default => $chunk,
            };
            if (strlen($dec) < $expected) {
                $dec .= str_repeat("\0", $expected - strlen($dec));
            }
            $dec = substr($dec, 0, $expected);
            if ($predictor === 2 && $bits === 8) {
                for ($r = 0; $r < $stripRows; $r++) {
                    $o = $r * $rowBytes;
                    for ($k = $spp; $k < $rowBytes; $k++) {
                        $dec[$o + $k] = chr((ord($dec[$o + $k]) + ord($dec[$o + $k - $spp])) & 255);
                    }
                }
            }
            $data .= $dec;
        }
        if (strlen($data) < $rowBytes * $h) {
            $data .= str_repeat("\0", $rowBytes * $h - strlen($data));
        }

        $hasAlpha = $spp >= 4 && $photo === 2 && ($extra[0] ?? 0) !== 0 || ($photo === 1 || $photo === 0) && $spp === 2;
        $rgbRows = [];
        $alphaRows = [];
        $maxv = (1 << min($bits, 8)) - 1;
        for ($y = 0; $y < $h; $y++) {
            $row = substr($data, $y * $rowBytes, $rowBytes);
            $px = $this->samples($row, $w * $spp, $bits);
            $rgb = '';
            $al = '';
            for ($x = 0; $x < $w; $x++) {
                $b = $x * $spp;
                switch ($photo) {
                    case 0:
                    case 1:
                        $g = $bits === 16 ? $px[$b] >> 8 : (int) round($px[$b] * 255 / $maxv);
                        if ($photo === 0) {
                            $g = 255 - $g;
                        }
                        $rgb .= chr($g) . chr($g) . chr($g);
                        if ($spp >= 2) {
                            $al .= chr($bits === 16 ? $px[$b + 1] >> 8 : (int) round($px[$b + 1] * 255 / $maxv));
                        }
                        break;
                    case 3:
                        $c = $palette[$px[$b]] ?? [0, 0, 0];
                        $rgb .= chr($c[0]) . chr($c[1]) . chr($c[2]);
                        break;
                    case 5:
                        $k = $spp >= 4 ? $px[$b + 3] : 0;
                        $sc = $bits === 16 ? 257.0 : 1.0;
                        $kf = 1.0 - $k / (255.0 * $sc);
                        $rgb .= chr((int) round((255 - $px[$b] / $sc) * $kf)) . chr((int) round((255 - $px[$b + 1] / $sc) * $kf)) . chr((int) round((255 - $px[$b + 2] / $sc) * $kf));
                        break;
                    default: // 2 = RGB
                        $s = $bits === 16 ? 8 : 0;
                        $rgb .= chr($px[$b] >> $s) . chr($px[$b + 1] >> $s) . chr($px[$b + 2] >> $s);
                        if ($spp >= 4) {
                            $al .= chr($px[$b + 3] >> $s);
                        }
                        break;
                }
            }
            $rgbRows[] = $rgb;
            $alphaRows[] = $al;
        }

        return new RasterImage($w, $h, implode('', $rgbRows), $hasAlpha && strlen($alphaRows[0]) === $w ? implode('', $alphaRows) : null);
    }

    /**
     * Unpacks $count samples of $bits bits each from a row.
     *
     * @return list<int>
     */
    private function samples(string $row, int $count, int $bits): array
    {
        if ($bits === 8) {
            return array_values(unpack('C*', str_pad($row, $count, "\0")) ?: []);
        }
        $out = [];
        if ($bits === 16) {
            $u = unpack($this->le ? 'v*' : 'n*', str_pad($row, $count * 2, "\0")) ?: [];
            return array_values($u);
        }
        $mask = (1 << $bits) - 1;
        for ($i = 0; $i < $count; $i++) {
            $bitPos = $i * $bits;
            $byte = ord($row[$bitPos >> 3] ?? "\0");
            $out[] = ($byte >> (8 - $bits - ($bitPos & 7))) & $mask;
        }
        return $out;
    }

    private static function packBits(string $in, int $expected): string
    {
        $out = '';
        $i = 0;
        $n = strlen($in);
        while ($i < $n && strlen($out) < $expected) {
            $c = ord($in[$i++]);
            if ($c > 127) {
                $c -= 256;
            }
            if ($c >= 0) {
                $out .= substr($in, $i, $c + 1);
                $i += $c + 1;
            } elseif ($c !== -128) {
                $out .= str_repeat($in[$i] ?? "\0", 1 - $c);
                $i++;
            }
        }
        return $out;
    }

    /**
     * TIFF flavour of LZW (MSB first, early change).
     */
    private static function lzw(string $in, int $expected): string
    {
        $out = '';
        $n = strlen($in);
        $bitBuf = 0;
        $bitCnt = 0;
        $pos = 0;
        $size = 9;
        $next = 258;
        $table = [];
        for ($i = 0; $i < 256; $i++) {
            $table[$i] = chr($i);
        }
        $prev = null;
        while (true) {
            while ($bitCnt < $size && $pos < $n) {
                $bitBuf = (($bitBuf << 8) | ord($in[$pos++])) & 0xFFFFFF;
                $bitCnt += 8;
            }
            if ($bitCnt < $size) {
                break;
            }
            $code = ($bitBuf >> ($bitCnt - $size)) & ((1 << $size) - 1);
            $bitCnt -= $size;
            if ($code === 257) {
                break;
            }
            if ($code === 256) {
                $table = array_slice($table, 0, 256);
                $next = 258;
                $size = 9;
                $prev = null;
                continue;
            }
            if ($prev === null) {
                $entry = $table[$code] ?? '';
            } else {
                $entry = $table[$code] ?? ($table[$prev] . $table[$prev][0]);
                $table[$next++] = $table[$prev] . ($entry[0] ?? '');
                if ($next + 1 >= 4096) {
                    $size = 12;
                } elseif ($next + 1 >= 2048) {
                    $size = 12;
                } elseif ($next + 1 >= 1024) {
                    $size = 11;
                } elseif ($next + 1 >= 512) {
                    $size = 10;
                }
            }
            $out .= $entry;
            $prev = $code;
            if (strlen($out) >= $expected) {
                break;
            }
        }
        return $out;
    }

    private function typeSize(int $type): int
    {
        return match ($type) {
            1, 2, 6, 7 => 1,
            3, 8 => 2,
            4, 9, 11 => 4,
            5, 10, 12 => 8,
            default => 1,
        };
    }

    private function first(string $raw, int $tag): ?int
    {
        $v = $this->values($raw, $tag);
        return $v === [] ? null : $v[0];
    }

    /**
     * @return list<int>
     */
    private function values(string $raw, int $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }
        ['type' => $type, 'count' => $count, 'off' => $off] = $this->tags[$tag];
        $count = min($count, 1000000);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if ($type === 3) {
                if ($off + $i * 2 + 2 > strlen($raw)) {
                    break;
                }
                $out[] = $this->u16($raw, $off + $i * 2);
            } elseif ($type === 4) {
                if ($off + $i * 4 + 4 > strlen($raw)) {
                    break;
                }
                $out[] = $this->u32($raw, $off + $i * 4);
            } elseif ($type === 1) {
                $out[] = ord($raw[$off + $i] ?? "\0");
            } else {
                break;
            }
        }
        return $out;
    }

    private function u16(string $d, int $o): int
    {
        $a = ord($d[$o] ?? "\0");
        $b = ord($d[$o + 1] ?? "\0");
        return $this->le ? ($a | ($b << 8)) : (($a << 8) | $b);
    }

    private function u32(string $d, int $o): int
    {
        $a = $this->u16($d, $o);
        $b = $this->u16($d, $o + 2);
        return $this->le ? ($a | ($b << 16)) : (($a << 16) | $b);
    }
}
