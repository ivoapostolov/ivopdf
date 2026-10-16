<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Embedded file attachments (ISO 32000-1 7.11.4): /EmbeddedFile streams, /Filespec dictionaries,
 * the catalog /Names /EmbeddedFiles name tree and the PDF/A-3 /AF associated-files array.
 */
final class Attachments
{
    public const RELATIONSHIPS = ['Alternative', 'Data', 'Source', 'Supplement', 'Unspecified'];

    /** @var list<array{name: string, bytes: string, description: string, mime: string, relationship: string, modDate: string}> */
    private array $files = [];

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** @return list<string> attachment file names */
    public function names(): array
    {
        return array_column($this->files, 'name');
    }

    public function add(string $bytes, string $name, string $description = '', string $mime = 'application/octet-stream', string $relationship = 'Unspecified', ?int $modTime = null): void
    {
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));
        if ($name === '') {
            throw new \InvalidArgumentException('Attachment name must not be empty');
        }
        $rel = ucfirst(strtolower($relationship));
        if (!in_array($rel, self::RELATIONSHIPS, true)) {
            throw new \InvalidArgumentException("Invalid AFRelationship '{$relationship}' (use " . implode('|', self::RELATIONSHIPS) . ')');
        }
        $mime = strtolower(trim($mime));
        if (!preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $mime)) {
            $mime = 'application/octet-stream';
        }
        // Names must be unique inside the name tree
        $unique = $name;
        $n = 2;
        while (in_array($unique, array_column($this->files, 'name'), true)) {
            $dot = strrpos($name, '.');
            $unique = $dot > 0 ? substr($name, 0, $dot) . "-{$n}" . substr($name, $dot) : "{$name}-{$n}";
            $n++;
        }
        $this->files[] = [
            'name' => $unique,
            'bytes' => $bytes,
            'description' => $description,
            'mime' => $mime,
            'relationship' => $rel,
            'modDate' => 'D:' . gmdate('YmdHis', $modTime ?? time()) . 'Z',
        ];
    }

    /**
     * Allocates and stores all attachment objects and returns the catalog entries
     * ("/Names << /EmbeddedFiles ... >> /AF [...]"), or '' when nothing is attached.
     *
     * @param callable(): int $alloc
     * @param callable(int, string): void $put
     */
    public function emit(callable $alloc, callable $put, bool $compress): string
    {
        if ($this->files === []) {
            return '';
        }
        $sorted = $this->files;
        usort($sorted, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        $nameTree = [];
        $afRefs = [];
        foreach ($sorted as $f) {
            $streamId = $alloc();
            $specId = $alloc();

            $data = $f['bytes'];
            $size = strlen($data);
            $filter = '';
            if ($compress && $size > 0) {
                $packed = (string) gzcompress($data, 6);
                if (strlen($packed) < $size) {
                    $data = $packed;
                    $filter = ' /Filter /FlateDecode';
                }
            }
            $put($streamId, sprintf(
                "<< /Type /EmbeddedFile /Subtype /%s /Params << /Size %d /ModDate (%s) /CheckSum <%s> >>%s /Length %d >>\nstream\n%s\nendstream",
                str_replace('/', '#2F', $f['mime']),
                $size,
                $f['modDate'],
                md5($f['bytes']),
                $filter,
                strlen($data),
                $data
            ));

            $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $f['name']);
            $desc = $f['description'] !== '' ? ' /Desc ' . $this->textString($f['description']) : '';
            $put($specId, sprintf(
                '<< /Type /Filespec /F (%s) /UF %s%s /AFRelationship /%s /EF << /F %d 0 R /UF %d 0 R >> >>',
                $this->escape($ascii),
                $this->textString($f['name'], true),
                $desc,
                $f['relationship'],
                $streamId,
                $streamId
            ));
            $nameTree[] = $this->textString($f['name']) . " {$specId} 0 R";
            $afRefs[] = "{$specId} 0 R";
        }

        return ' /Names << /EmbeddedFiles << /Names [' . implode(' ', $nameTree) . '] >> >> /AF [' . implode(' ', $afRefs) . ']';
    }

    private function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\(', ')' => '\)', "\r" => '\r', "\n" => '\n']);
    }

    /** PDF text string: plain ASCII literal when possible, otherwise UTF-16BE hex with BOM. */
    private function textString(string $utf8, bool $forceUtf16 = false): string
    {
        if (!$forceUtf16 && preg_match('/^[\x20-\x7E]*$/', $utf8)) {
            return '(' . $this->escape($utf8) . ')';
        }
        $utf16 = (string) @mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }
}
