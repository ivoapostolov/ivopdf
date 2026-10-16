<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Tokenizer / object parser for PDF syntax. Produces null, bool, int, float, PdfName, PdfString, PdfRef,
 * PHP lists (PDF arrays) and PdfDict. Never evaluates anything from the file.
 */
final class PdfLexer
{
    private const WS = "\x00\t\n\x0C\r ";
    private const DELIM = "\x00\t\n\x0C\r ()<>[]{}/%";
    private const MAX_DEPTH = 100;

    public int $pos;
    private int $len;

    public function __construct(private readonly string $data, int $pos = 0)
    {
        $this->pos = $pos;
        $this->len = strlen($data);
    }

    public function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->data[$this->pos];
            if ($c === '%') {
                $this->pos += strcspn($this->data, "\r\n", $this->pos);
            } elseif (strpos(self::WS, $c) !== false) {
                $this->pos += strspn($this->data, self::WS, $this->pos);
            } else {
                return;
            }
        }
    }

    /** True when the next bytes (after whitespace) equal the bare keyword $kw (e.g. "stream"). */
    public function peekKeyword(string $kw): bool
    {
        $this->skipWs();
        return substr_compare($this->data, $kw, $this->pos, strlen($kw)) === 0;
    }

    public function readValue(int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PdfImportException('PDF object nesting too deep');
        }
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw new PdfImportException('Unexpected end of PDF data');
        }
        $c = $this->data[$this->pos];
        switch ($c) {
            case '/':
                return $this->readName();
            case '<':
                if (($this->data[$this->pos + 1] ?? '') === '<') {
                    return $this->readDict($depth);
                }
                return $this->readHexString();
            case '[':
                return $this->readArray($depth);
            case '(':
                return $this->readLiteralString();
            case '+':
            case '-':
            case '.':
            case '0':
            case '1':
            case '2':
            case '3':
            case '4':
            case '5':
            case '6':
            case '7':
            case '8':
            case '9':
                return $this->readNumberOrRef();
        }

        $start = $this->pos;
        $this->pos += strcspn($this->data, self::DELIM, $this->pos);
        $word = substr($this->data, $start, $this->pos - $start);
        if ($word === '') {
            $this->pos++; // stray delimiter: guarantee progress
            throw new PdfImportException('Unexpected character in PDF data at ' . $start);
        }
        return match ($word) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new PdfImportException("Unexpected keyword '{$word}' at {$start}"),
        };
    }

    private function readName(): PdfName
    {
        $this->pos++;
        $start = $this->pos;
        $this->pos += strcspn($this->data, self::DELIM, $this->pos);
        $raw = substr($this->data, $start, $this->pos - $start);
        if (str_contains($raw, '#')) {
            $raw = (string) preg_replace_callback(
                '/#([0-9A-Fa-f]{2})/',
                static fn(array $m): string => chr((int) hexdec($m[1])),
                $raw
            );
        }
        return new PdfName($raw);
    }

    private function readNumberOrRef(): int|float|PdfRef
    {
        if (!preg_match('/\G[+-]*(?:\d+\.?\d*|\.\d+)/', $this->data, $m, 0, $this->pos)) {
            $this->pos++;
            throw new PdfImportException('Malformed number in PDF data');
        }
        $this->pos += strlen($m[0]);
        $txt = $m[0];
        if (ctype_digit($txt)) {
            if (preg_match('/\G[ \t\r\n\x0C\x00]+(\d{1,5})[ \t\r\n\x0C\x00]+R(?![A-Za-z0-9])/', $this->data, $r, 0, $this->pos)) {
                $this->pos += strlen($r[0]);
                return new PdfRef((int) $txt, (int) $r[1]);
            }
            return (int) $txt;
        }
        $neg = str_contains($txt, '-');
        $txt = ltrim($txt, '+-');
        if (str_contains($txt, '.')) {
            $val = (float) $txt;
            return $neg ? -$val : $val;
        }
        $val = (int) $txt;
        return $neg ? -$val : $val;
    }

    private function readHexString(): PdfString
    {
        $end = strpos($this->data, '>', $this->pos);
        if ($end === false) {
            throw new PdfImportException('Unterminated hex string');
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->data, $this->pos + 1, $end - $this->pos - 1));
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $this->pos = $end + 1;
        return new PdfString((string) hex2bin($hex));
    }

    private function readLiteralString(): PdfString
    {
        $d = $this->data;
        $i = $this->pos + 1;
        $depth = 1;
        $out = '';
        while ($i < $this->len) {
            $n = strcspn($d, "\\()\r", $i);
            $out .= substr($d, $i, $n);
            $i += $n;
            if ($i >= $this->len) {
                break;
            }
            $c = $d[$i];
            if ($c === '(') {
                $depth++;
                $out .= '(';
                $i++;
            } elseif ($c === ')') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    $this->pos = $i;
                    return new PdfString($out);
                }
                $out .= ')';
            } elseif ($c === "\r") {
                $out .= "\n";
                $i += (($d[$i + 1] ?? '') === "\n") ? 2 : 1;
            } else { // backslash escape
                $e = $d[$i + 1] ?? '';
                $i += 2;
                if ($e >= '0' && $e <= '7' && $e !== '') {
                    $oct = $e;
                    for ($k = 0; $k < 2; $k++) {
                        $nx = $d[$i] ?? '';
                        if ($nx !== '' && $nx >= '0' && $nx <= '7') {
                            $oct .= $nx;
                            $i++;
                        } else {
                            break;
                        }
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                    continue;
                }
                switch ($e) {
                    case 'n':
                        $out .= "\n";
                        break;
                    case 'r':
                        $out .= "\r";
                        break;
                    case 't':
                        $out .= "\t";
                        break;
                    case 'b':
                        $out .= "\x08";
                        break;
                    case 'f':
                        $out .= "\x0C";
                        break;
                    case "\r":
                        if (($d[$i] ?? '') === "\n") {
                            $i++;
                        }
                        break;
                    case "\n":
                        break;
                    default:
                        $out .= $e;
                }
            }
        }
        throw new PdfImportException('Unterminated literal string');
    }

    /** @return list<mixed> */
    private function readArray(int $depth): array
    {
        $this->pos++;
        $out = [];
        while (true) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw new PdfImportException('Unterminated array');
            }
            if ($this->data[$this->pos] === ']') {
                $this->pos++;
                return $out;
            }
            if (count($out) > 2_000_000) {
                throw new PdfImportException('PDF array too large');
            }
            $out[] = $this->readValue($depth + 1);
        }
    }

    private function readDict(int $depth): PdfDict
    {
        $this->pos += 2;
        $items = [];
        while (true) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw new PdfImportException('Unterminated dictionary');
            }
            if (substr_compare($this->data, '>>', $this->pos, 2) === 0) {
                $this->pos += 2;
                return new PdfDict($items);
            }
            if ($this->data[$this->pos] !== '/') {
                // Tolerate garbage between entries but guarantee progress
                if ($this->data[$this->pos] === '>') {
                    $this->pos++;
                    continue;
                }
                $this->readValue($depth + 1);
                continue;
            }
            $key = $this->readName()->name;
            $this->skipWs();
            if (substr_compare($this->data, '>>', $this->pos, 2) === 0) {
                $items[$key] = null;
                continue;
            }
            $items[$key] = $this->readValue($depth + 1);
        }
    }
}
