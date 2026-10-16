<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Object graph copied out of one source PDF, stored with document-local ids.
 * Cross references inside object bodies are placeholders ("\0R<localId>\0") that are rewritten to the final
 * object numbers when the pool is written into an output document.
 */
final class ImportedPool
{
    public const MAX_OBJECTS = 100_000;
    public const MAX_BYTES = 268_435_456; // 256 MiB of copied stream data

    /** @var array<int, array{v: string, data: ?string, plain: bool}> */
    private array $objects = [];
    private int $nextId = 1;
    private int $bytes = 0;

    public function allocate(): int
    {
        if ($this->nextId > self::MAX_OBJECTS) {
            throw new PdfImportException('Imported page references too many objects (limit ' . self::MAX_OBJECTS . ')');
        }
        return $this->nextId++;
    }

    /**
     * @param string $value serialized value, or for streams the dictionary ("<< ... >>" without /Length)
     * @param bool $plain true when $data is uncompressed and may be (re)compressed on output
     */
    public function set(int $id, string $value, ?string $data = null, bool $plain = false): void
    {
        $this->bytes += strlen($value) + strlen((string) $data);
        if ($this->bytes > self::MAX_BYTES) {
            throw new PdfImportException('Imported page data exceeds the size limit');
        }
        $this->objects[$id] = ['v' => $value, 'data' => $data, 'plain' => $plain];
    }

    /**
     * Writes the objects reachable from $rootIds and returns localRootId => final object number.
     *
     * @param list<int> $rootIds
     * @param callable(): int $alloc allocates a new object number
     * @param callable(int, string): void $put stores a serialized object body
     * @return array<int, int>
     */
    public function emit(array $rootIds, callable $alloc, callable $put, bool $compress): array
    {
        $reach = [];
        $queue = array_values($rootIds);
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($reach[$id]) || !isset($this->objects[$id])) {
                continue;
            }
            $reach[$id] = true;
            if (preg_match_all('/\x00R(\d+)\x00/', $this->objects[$id]['v'], $m)) {
                foreach ($m[1] as $ref) {
                    $queue[] = (int) $ref;
                }
            }
        }
        ksort($reach);
        $map = [];
        foreach (array_keys($reach) as $id) {
            $map[$id] = $alloc();
        }
        foreach (array_keys($reach) as $id) {
            $obj = $this->objects[$id];
            $body = (string) preg_replace_callback(
                '/\x00R(\d+)\x00/',
                static fn(array $m): string => isset($map[(int) $m[1]]) ? $map[(int) $m[1]] . ' 0 R' : 'null',
                $obj['v']
            );
            if ($obj['data'] !== null) {
                $data = $obj['data'];
                $extra = '';
                if ($obj['plain'] && $compress && $data !== '') {
                    $data = (string) gzcompress($data, 6);
                    $extra = ' /Filter /FlateDecode';
                }
                $body = substr($body, 0, -2) . $extra . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
            }
            $put($map[$id], $body);
        }
        return $map;
    }
}
