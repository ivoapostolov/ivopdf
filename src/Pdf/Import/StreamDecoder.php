<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Decodes PDF stream filters needed to read structural data and page content:
 * FlateDecode (with PNG/TIFF predictors), LZWDecode, ASCII85Decode, ASCIIHexDecode, RunLengthDecode.
 * Image filters (DCT/JPX/CCITT/JBIG2) are never decoded here; such streams are copied verbatim by the importer.
 */
final class StreamDecoder
{
    public const DEFAULT_MAX_OUTPUT = 134_217_728; // 128 MiB

    /**
     * @param callable(mixed): mixed $resolve resolves indirect references inside /Filter and /DecodeParms
     */
    public static function decode(PdfStream $stream, callable $resolve, int $maxOutput = self::DEFAULT_MAX_OUTPUT): string
    {
        $filters = $resolve($stream->get('Filter') ?? $stream->get('F'));
        $parms = $resolve($stream->get('DecodeParms') ?? $stream->get('DP'));
        if ($filters === null) {
            return $stream->raw;
        }
        $filterList = is_array($filters) ? $filters : [$filters];
        $parmList = is_array($parms) ? $parms : [$parms];

        $data = $stream->raw;
        foreach ($filterList as $i => $f) {
            $f = $resolve($f);
            if (!$f instanceof PdfName) {
                throw new PdfImportException('Malformed /Filter entry');
            }
            $p = $resolve($parmList[$i] ?? null);
            $p = $p instanceof PdfDict ? $p : new PdfDict();
            $data = match ($f->name) {
                'FlateDecode', 'Fl' => self::predictor(self::flate($data, $maxOutput), $p, $resolve),
                'LZWDecode', 'LZW' => self::predictor(self::lzw($data, (int) ($resolve($p->get('EarlyChange')) ?? 1), $maxOutput), $p, $resolve),
                'ASCII85Decode', 'A85' => self::ascii85($data),
                'ASCIIHexDecode', 'AHx' => self::asciiHex($data),
                'RunLengthDecode', 'RL' => self::runLength($data, $maxOutput),
                default => throw new PdfImportException("Unsupported stream filter /{$f->name}"),
            };
            if (strlen($data) > $maxOutput) {
                throw new PdfImportException('Decoded stream exceeds the size limit');
            }
        }
        return $data;
    }

    /** True when every filter of the stream is one that decode() can handle. */
    public static function isDecodable(PdfStream $stream, callable $resolve): bool
    {
        $filters = $resolve($stream->get('Filter') ?? $stream->get('F'));
        if ($filters === null) {
            return true;
        }
        foreach (is_array($filters) ? $filters : [$filters] as $f) {
            $f = $resolve($f);
            if (!$f instanceof PdfName || !in_array($f->name, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW', 'ASCII85Decode', 'A85', 'ASCIIHexDecode', 'AHx', 'RunLengthDecode', 'RL'], true)) {
                return false;
            }
        }
        return true;
    }

    public static function flate(string $data, int $maxOutput = self::DEFAULT_MAX_OUTPUT): string
    {
        if ($data === '') {
            return '';
        }
        $out = @gzuncompress($data, $maxOutput);
        if (is_string($out)) {
            return $out;
        }
        // Damaged / truncated / headerless streams: inflate incrementally and keep what decodes
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $enc) {
            $payload = $enc === ZLIB_ENCODING_RAW ? substr($data, 2) : $data;
            $ctx = @inflate_init($enc);
            if ($ctx === false) {
                continue;
            }
            $buf = '';
            $len = strlen($payload);
            for ($i = 0; $i < $len; $i += 8192) {
                $chunk = @inflate_add($ctx, substr($payload, $i, 8192), ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    break;
                }
                $buf .= $chunk;
                if (strlen($buf) > $maxOutput) {
                    throw new PdfImportException('Decoded stream exceeds the size limit');
                }
            }
            if ($buf !== '') {
                return $buf;
            }
        }
        throw new PdfImportException('Corrupt Flate stream');
    }

    private static function predictor(string $data, PdfDict $p, callable $resolve): string
    {
        $pred = (int) ($resolve($p->get('Predictor')) ?? 1);
        if ($pred <= 1) {
            return $data;
        }
        $colors = max(1, (int) ($resolve($p->get('Colors')) ?? 1));
        $bpc = max(1, (int) ($resolve($p->get('BitsPerComponent')) ?? 8));
        $cols = max(1, (int) ($resolve($p->get('Columns')) ?? 1));
        $rowLen = intdiv($colors * $bpc * $cols + 7, 8);
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));

        if ($pred === 2) { // TIFF predictor, byte-aligned samples only
            if ($bpc !== 8) {
                return $data;
            }
            $out = '';
            $rows = (int) ceil(strlen($data) / $rowLen);
            for ($r = 0; $r < $rows; $r++) {
                $row = array_values(unpack('C*', substr($data, $r * $rowLen, $rowLen)) ?: []);
                for ($i = $colors, $n = count($row); $i < $n; $i++) {
                    $row[$i] = ($row[$i] + $row[$i - $colors]) & 0xFF;
                }
                $out .= $row === [] ? '' : pack('C*', ...$row);
            }
            return $out;
        }

        // PNG predictors (10..15): every row starts with a filter-type byte
        $stride = $rowLen + 1;
        $rows = intdiv(strlen($data) + $stride - 1, $stride);
        $out = '';
        $prev = array_fill(0, $rowLen, 0);
        for ($r = 0; $r < $rows; $r++) {
            $chunk = substr($data, $r * $stride, $stride);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $type = ord($chunk[0]);
            $bytes = array_values(unpack('C*', substr($chunk, 1)) ?: []);
            $n = count($bytes);
            if ($n < $rowLen) {
                $bytes = array_pad($bytes, $rowLen, 0);
            }
            switch ($type) {
                case 1:
                    for ($i = $bpp; $i < $rowLen; $i++) {
                        $bytes[$i] = ($bytes[$i] + $bytes[$i - $bpp]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $bytes[$i] = ($bytes[$i] + $prev[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $left = $i >= $bpp ? $bytes[$i - $bpp] : 0;
                        $bytes[$i] = ($bytes[$i] + (($left + $prev[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $a = $i >= $bpp ? $bytes[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $pa = abs($b - $c);
                        $pb = abs($a - $c);
                        $pc = abs($a + $b - 2 * $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $bytes[$i] = ($bytes[$i] + $pr) & 0xFF;
                    }
                    break;
            }
            $out .= pack('C*', ...$bytes);
            $prev = $bytes;
        }
        return $out;
    }

    private static function lzw(string $data, int $early, int $maxOutput): string
    {
        $out = '';
        $table = [];
        $reset = static function () use (&$table): void {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $table[$i] = chr($i);
            }
            $table[256] = '';
            $table[257] = '';
        };
        $reset();
        $bits = 9;
        $buf = 0;
        $nbuf = 0;
        $prev = null;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buf = ($buf << 8) | ord($data[$i]);
            $nbuf += 8;
            while ($nbuf >= $bits) {
                $code = ($buf >> ($nbuf - $bits)) & ((1 << $bits) - 1);
                $nbuf -= $bits;
                $buf &= (1 << $nbuf) - 1;
                if ($code === 256) {
                    $reset();
                    $bits = 9;
                    $prev = null;
                    continue;
                }
                if ($code === 257) {
                    return $out;
                }
                if ($prev === null) {
                    $entry = $table[$code] ?? '';
                } else {
                    if (isset($table[$code])) {
                        $entry = $table[$code];
                    } else {
                        $entry = $prev . ($prev[0] ?? '');
                    }
                    $table[] = $prev . ($entry[0] ?? '');
                }
                $out .= $entry;
                $prev = $entry;
                $size = count($table) + $early;
                $bits = $size >= 2048 ? 12 : ($size >= 1024 ? 11 : ($size >= 512 ? 10 : 9));
                if (strlen($out) > $maxOutput) {
                    throw new PdfImportException('Decoded stream exceeds the size limit');
                }
            }
        }
        return $out;
    }

    private static function ascii85(string $data): string
    {
        $data = (string) preg_replace('/\s+/', '', $data);
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $out = '';
        $group = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if ($c === 'z' && $group === []) {
                $out .= "\0\0\0\0";
                continue;
            }
            $v = ord($c) - 33;
            if ($v < 0 || $v > 84) {
                continue;
            }
            $group[] = $v;
            if (count($group) === 5) {
                $n = 0;
                foreach ($group as $g) {
                    $n = $n * 85 + $g;
                }
                $out .= pack('N', $n & 0xFFFFFFFF);
                $group = [];
            }
        }
        $cnt = count($group);
        if ($cnt > 1) {
            for ($k = $cnt; $k < 5; $k++) {
                $group[] = 84;
            }
            $n = 0;
            foreach ($group as $g) {
                $n = $n * 85 + $g;
            }
            $out .= substr(pack('N', $n & 0xFFFFFFFF), 0, $cnt - 1);
        }
        return $out;
    }

    private static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $data);
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        return (string) hex2bin($hex);
    }

    private static function runLength(string $data, int $maxOutput): string
    {
        $out = '';
        $i = 0;
        $len = strlen($data);
        while ($i < $len) {
            $n = ord($data[$i++]);
            if ($n === 128) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i += $n + 1;
            } elseif ($i < $len) {
                $out .= str_repeat($data[$i++], 257 - $n);
            }
            if (strlen($out) > $maxOutput) {
                throw new PdfImportException('Decoded stream exceeds the size limit');
            }
        }
        return $out;
    }
}
