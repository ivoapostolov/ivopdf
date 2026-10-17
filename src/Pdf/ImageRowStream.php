<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Reads the pixel rows of a Flate-compressed (zlib) 8-bit image stream one at a time, undoing PNG row predictors
 * (/Predictor 10..15) on the way. The compressed data is inflated in small pieces, so memory stays at a few rows
 * whatever the declared image size: a decompression bomb (a few KB declaring hundreds of MB of pixels) never exists
 * decoded in memory at once. Corrupt or truncated data ends the stream (next() returns null) without PHP warnings.
 *
 * Used by the CMYK / PDF/X / PDF/A-1 image conversions (ImageCmykConverter, PdfA1Profile).
 */
final class ImageRowStream
{
    /** Compressed bytes fed to zlib at a time: 2 KB inflate to at most ~2 MB. */
    private const PIECE = 2048;

    /** @var \InflateContext */
    private $inflate;
    private string $buf = '';
    private int $off = 0;
    private int $in = 0;
    private string $prev;

    private function __construct(
        private readonly string $data,
        private readonly int $rowBytes,
        private readonly int $bpp,
        private readonly bool $predicted
    ) {
        $this->prev = str_repeat("\0", $rowBytes);
    }

    /**
     * @param string $data zlib-compressed stream data
     * @param int $rowBytes bytes per decoded row (width x components)
     * @param int $bpp bytes per pixel (PNG predictor distance)
     * @param bool $predicted true when every row starts with a PNG filter-type byte (/Predictor >= 10)
     */
    public static function open(string $data, int $rowBytes, int $bpp, bool $predicted): ?self
    {
        if ($rowBytes < 1 || $bpp < 1 || $data === '') {
            return null;
        }
        $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            return null;
        }
        $s = new self($data, $rowBytes, $bpp, $predicted);
        $s->inflate = $ctx;
        return $s;
    }

    /**
     * Runs $fn with PHP warnings silenced, also for the application's own error handler (which an @ does not stop):
     * zlib and GD warn about corrupt data, which is reported through Options::addWarning() instead.
     */
    public static function quietly(callable $fn): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** True when the /DecodeParms text of an image announces PNG predictors. */
    public static function hasPngPredictor(?string $decodeParms): bool
    {
        return $decodeParms !== null && preg_match('~/Predictor\s+(\d+)~', $decodeParms, $m) === 1 && (int) $m[1] >= 10;
    }

    /** True when /DecodeParms announce a predictor this reader cannot undo (TIFF predictor 2). */
    public static function hasUnsupportedPredictor(?string $decodeParms): bool
    {
        return $decodeParms !== null && preg_match('~/Predictor\s+(\d+)~', $decodeParms, $m) === 1 && (int) $m[1] > 1 && (int) $m[1] < 10;
    }

    /** The next decoded row ($rowBytes bytes), or null when the data is exhausted, truncated or corrupt. */
    public function next(): ?string
    {
        $need = $this->rowBytes + ($this->predicted ? 1 : 0);
        while (strlen($this->buf) - $this->off < $need) {
            if ($this->in >= strlen($this->data)) {
                return null;
            }
            $piece = substr($this->data, $this->in, self::PIECE);
            $chunk = self::quietly(fn() => inflate_add($this->inflate, $piece, ZLIB_SYNC_FLUSH));
            if ($chunk === false) {
                $this->in = strlen($this->data);
                return null;
            }
            $this->in += self::PIECE;
            if ($this->off > 0) {
                $this->buf = substr($this->buf, $this->off);
                $this->off = 0;
            }
            $this->buf .= $chunk;
        }
        if (!$this->predicted) {
            $row = substr($this->buf, $this->off, $this->rowBytes);
            $this->off += $this->rowBytes;
            return $row;
        }
        $type = ord($this->buf[$this->off]);
        $row = substr($this->buf, $this->off + 1, $this->rowBytes);
        $this->off += $need;
        $row = $this->unfilter($type, $row);
        $this->prev = $row;
        return $row;
    }

    private function unfilter(int $type, string $row): string
    {
        $n = $this->rowBytes;
        $bpp = $this->bpp;
        $prev = $this->prev;
        switch ($type) {
            case 1: // Sub
                if (trim($row, "\0") === '') {
                    return $row;
                }
                for ($i = $bpp; $i < $n; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bpp])) & 255);
                }
                return $row;
            case 2: // Up
                if (trim($row, "\0") === '') {
                    return $prev;
                }
                if (trim($prev, "\0") === '') {
                    return $row;
                }
                for ($i = 0; $i < $n; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ord($prev[$i])) & 255);
                }
                return $row;
            case 3: // Average
                for ($i = 0; $i < $n; $i++) {
                    $left = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                    $row[$i] = chr((ord($row[$i]) + (($left + ord($prev[$i])) >> 1)) & 255);
                }
                return $row;
            case 4: // Paeth
                for ($i = 0; $i < $n; $i++) {
                    $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    $row[$i] = chr((ord($row[$i]) + $pr) & 255);
                }
                return $row;
            default: // None (and unknown filter types, kept as is)
                return $row;
        }
    }
}
