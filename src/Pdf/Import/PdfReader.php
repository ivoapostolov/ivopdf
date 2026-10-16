<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Read-only PDF parser: classic xref tables, cross-reference streams, hybrid files, incremental updates (/Prev),
 * object streams, damaged-file reconstruction, and the page tree with inherited attributes.
 * Nothing in the file is ever executed or included; encrypted files are refused.
 */
final class PdfReader
{
    public const MAX_FILE_BYTES = 134_217_728; // 128 MiB
    public const MAX_PAGES = 20000;

    private string $data;
    private int $headerOffset = 0;
    private string $version = '1.4';

    /** @var array<int, array{0: int, 1: int, 2: int}> objNum => [type(0 free/1 offset/2 objstm), a, b] */
    private array $xref = [];
    private ?PdfDict $trailer = null;
    private bool $rebuilt = false;

    /** @var array<int, mixed> */
    private array $cache = [];
    /** @var array<int, bool> */
    private array $loading = [];
    /** @var array<int, array{data: string, offsets: array<int, int>, first: int}> */
    private array $objStms = [];
    /** @var list<array{dict: PdfDict, attrs: array<string, mixed>}>|null */
    private ?array $pages = null;

    public function __construct(string $data)
    {
        if (strlen($data) > self::MAX_FILE_BYTES) {
            throw new PdfImportException('PDF file exceeds the import size limit');
        }
        $this->data = $data;
        $head = strpos(substr($data, 0, 1024), '%PDF-');
        if ($head === false) {
            throw new PdfImportException('Not a PDF file (missing %PDF- header)');
        }
        $this->headerOffset = $head;
        if (preg_match('/%PDF-(\d\.\d)/', substr($data, $head, 16), $m)) {
            $this->version = $m[1];
        }

        try {
            $this->readXrefChain();
            $this->assertRootOk();
        } catch (\Throwable $e) {
            $this->rebuildXref();
        }
        if ($this->trailer === null) {
            throw new PdfImportException('Unable to locate the PDF trailer');
        }
        if ($this->trailer->has('Encrypt')) {
            throw new PdfImportException('Encrypted PDFs cannot be imported (remove the password protection first)');
        }
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /** The trailer dictionary (null when none could be located). */
    public function getTrailer(): ?PdfDict
    {
        return $this->trailer;
    }

    /** The document catalog (/Root), or null. */
    public function getCatalog(): ?PdfDict
    {
        $root = $this->resolve($this->trailer?->get('Root'));
        return $root instanceof PdfDict && !($root instanceof PdfStream) ? $root : null;
    }

    public function getPageCount(): int
    {
        return count($this->pageList());
    }

    /**
     * @return array{dict: PdfDict, attrs: array<string, mixed>}
     */
    public function getPage(int $number): array
    {
        $pages = $this->pageList();
        if ($number < 1 || $number > count($pages)) {
            throw new PdfImportException("Page {$number} does not exist (document has " . count($pages) . ' pages)');
        }
        return $pages[$number - 1];
    }

    /** Follows indirect references until a direct value is reached. */
    public function resolve(mixed $v): mixed
    {
        $guard = 0;
        while ($v instanceof PdfRef) {
            if (++$guard > 64) {
                return null;
            }
            $v = $this->getObject($v->num);
        }
        return $v;
    }

    /** @return PdfDict|PdfStream|list<mixed>|PdfName|PdfString|int|float|bool|null */
    public function getObject(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $entry = $this->xref[$num] ?? null;
        if ($entry === null || $entry[0] === 0) {
            return null;
        }
        if (isset($this->loading[$num])) {
            return null; // reference cycle through /Length etc.
        }
        $this->loading[$num] = true;
        try {
            if ($entry[0] === 1) {
                $val = $this->parseIndirectAt($entry[1] + $this->headerOffset, $num);
            } else {
                $val = $this->readFromObjStm($entry[1], $num);
            }
        } catch (PdfImportException $e) {
            if (!$this->rebuilt && $entry[0] === 1) {
                unset($this->loading[$num]);
                $this->rebuildXref();
                return $this->getObject($num);
            }
            $val = null;
        } finally {
            unset($this->loading[$num]);
        }
        $this->cache[$num] = $val;
        return $val;
    }

    /** Decoded stream bytes (all supported filters applied). */
    public function decodeStream(PdfStream $s, int $max = StreamDecoder::DEFAULT_MAX_OUTPUT): string
    {
        return StreamDecoder::decode($s, fn(mixed $v): mixed => $this->resolve($v), $max);
    }

    public function isDecodable(PdfStream $s): bool
    {
        return StreamDecoder::isDecodable($s, fn(mixed $v): mixed => $this->resolve($v));
    }

    /** Highest object number known to the xref. */
    public function getObjectCount(): int
    {
        return $this->xref === [] ? 0 : max(array_keys($this->xref));
    }

    // ------------------------------------------------------------------
    // Cross-reference reading
    // ------------------------------------------------------------------

    private function readXrefChain(): void
    {
        $tail = substr($this->data, max(0, strlen($this->data) - 2048));
        $p = strrpos($tail, 'startxref');
        if ($p === false || !preg_match('/startxref\s+(\d+)/', $tail, $m, 0, $p)) {
            throw new PdfImportException('startxref not found');
        }
        $queue = [(int) $m[1]];
        $seen = [];
        while ($queue !== []) {
            $off = array_shift($queue);
            if (isset($seen[$off]) || count($seen) > 1000) {
                continue;
            }
            $seen[$off] = true;
            $off += $this->headerOffset;
            $trailer = $this->readXrefSection($off);
            if ($this->trailer === null) {
                $this->trailer = $trailer;
            } else {
                foreach ($trailer->items as $k => $v) {
                    if (!$this->trailer->has($k)) {
                        $this->trailer->items[$k] = $v;
                    }
                }
            }
            $stm = $trailer->get('XRefStm');
            if (is_int($stm)) {
                array_unshift($queue, $stm);
            }
            $prev = $trailer->get('Prev');
            if (is_int($prev) || is_float($prev)) {
                $queue[] = (int) $prev;
            }
        }
    }

    private function readXrefSection(int $off): PdfDict
    {
        if ($off < 0 || $off >= strlen($this->data)) {
            throw new PdfImportException('Invalid xref offset');
        }
        if (!preg_match('/\G[\x00\t\n\x0C\r ]*xref/', $this->data, $m, 0, $off)) {
            return $this->readXrefStream($off);
        }
        $pos = $off + strlen($m[0]);
        while (true) {
            if (!preg_match('/\G\s*(\d+)\s+(\d+)[ \t]*(?:\r\n|\r|\n)?/', $this->data, $h, 0, $pos)) {
                break;
            }
            $pos += strlen($h[0]);
            $first = (int) $h[1];
            $count = (int) $h[2];
            if ($count > 8_000_000) {
                throw new PdfImportException('xref subsection too large');
            }
            for ($i = 0; $i < $count; $i++) {
                if (!preg_match('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->data, $e, 0, $pos)) {
                    throw new PdfImportException('Malformed xref entry');
                }
                $pos += strlen($e[0]);
                $num = $first + $i;
                if (!isset($this->xref[$num])) {
                    $this->xref[$num] = $e[3] === 'n' ? [1, (int) $e[1], (int) $e[2]] : [0, 0, 0];
                }
            }
        }
        if (!preg_match('/\G\s*trailer/', $this->data, $t, 0, $pos)) {
            throw new PdfImportException('trailer keyword not found');
        }
        $lx = new PdfLexer($this->data, $pos + strlen($t[0]));
        $trailer = $lx->readValue();
        if (!$trailer instanceof PdfDict) {
            throw new PdfImportException('Malformed trailer');
        }
        return $trailer;
    }

    private function readXrefStream(int $off): PdfDict
    {
        $obj = $this->parseIndirectAt($off, null);
        if (!$obj instanceof PdfStream || $obj->name('Type') !== 'XRef') {
            throw new PdfImportException('Malformed cross-reference stream');
        }
        $w = $obj->get('W');
        if (!is_array($w) || count($w) < 3) {
            throw new PdfImportException('Malformed /W in xref stream');
        }
        [$w0, $w1, $w2] = [(int) $w[0], (int) $w[1], (int) $w[2]];
        if ($w0 < 0 || $w1 < 0 || $w2 < 0 || $w0 > 8 || $w1 > 8 || $w2 > 8 || ($w0 + $w1 + $w2) === 0) {
            throw new PdfImportException('Unsupported /W in xref stream');
        }
        $size = (int) ($obj->get('Size') ?? 0);
        $index = $obj->get('Index');
        if (!is_array($index) || count($index) < 2) {
            $index = [0, $size];
        }
        $data = StreamDecoder::decode($obj, static fn(mixed $v): mixed => $v);
        $rowLen = $w0 + $w1 + $w2;
        $pos = 0;
        $total = strlen($data);
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            $first = (int) $index[$s];
            $count = (int) $index[$s + 1];
            if ($count > 8_000_000) {
                throw new PdfImportException('xref subsection too large');
            }
            for ($i = 0; $i < $count; $i++) {
                if ($pos + $rowLen > $total) {
                    break 2;
                }
                $type = $w0 > 0 ? $this->be($data, $pos, $w0) : 1;
                $a = $this->be($data, $pos + $w0, $w1);
                $b = $this->be($data, $pos + $w0 + $w1, $w2);
                $pos += $rowLen;
                $num = $first + $i;
                if (isset($this->xref[$num])) {
                    continue;
                }
                $this->xref[$num] = match ($type) {
                    0 => [0, 0, 0],
                    1 => [1, $a, $b],
                    2 => [2, $a, $b],
                    default => [0, 0, 0],
                };
            }
        }
        return new PdfDict($obj->items);
    }

    private function be(string $data, int $pos, int $width): int
    {
        $v = 0;
        for ($i = 0; $i < $width; $i++) {
            $v = ($v << 8) | ord($data[$pos + $i]);
        }
        return $v;
    }

    private function assertRootOk(): void
    {
        $root = $this->resolve($this->trailer?->get('Root'));
        if (!$root instanceof PdfDict || $this->resolve($root->get('Pages')) === null) {
            throw new PdfImportException('Catalog or page tree not found');
        }
    }

    /** Scans the whole file for "N G obj" markers when the xref data is missing or damaged. */
    private function rebuildXref(): void
    {
        $this->rebuilt = true;
        $this->xref = [];
        $this->cache = [];
        $this->objStms = [];
        $this->pages = null;
        $oldTrailer = $this->trailer;
        $this->trailer = null;

        $found = preg_match_all(
            '/(?<![0-9])(\d{1,10})[ \t\r\n\x0C\x00]+(\d{1,5})[ \t\r\n\x0C\x00]+obj(?![A-Za-z])/',
            $this->data,
            $m,
            PREG_OFFSET_CAPTURE
        );
        if ($found) {
            foreach ($m[1] as $i => $cap) {
                $this->xref[(int) $cap[0]] = [1, $cap[1] - $this->headerOffset, (int) $m[2][$i][0]];
            }
        }

        // Trailer dictionaries
        $trailer = null;
        if (preg_match_all('/trailer/', $this->data, $tm, PREG_OFFSET_CAPTURE)) {
            foreach ($tm[0] as $cap) {
                try {
                    $d = (new PdfLexer($this->data, $cap[1] + 7))->readValue();
                } catch (PdfImportException) {
                    continue;
                }
                if ($d instanceof PdfDict && $d->has('Root')) {
                    $trailer = $d; // last one wins (newest update)
                }
            }
        }

        // Object streams and xref streams (PDF 1.5 files without a classic trailer)
        $objStmNums = [];
        foreach ($this->xref as $num => $e) {
            if ($e[0] !== 1) {
                continue;
            }
            $abs = $e[1] + $this->headerOffset;
            $peek = substr($this->data, $abs, 400);
            if (str_contains($peek, '/ObjStm')) {
                $objStmNums[] = $num;
            } elseif ($trailer === null && str_contains($peek, '/XRef')) {
                $o = $this->getObject($num);
                if ($o instanceof PdfStream && $o->name('Type') === 'XRef' && $o->has('Root')) {
                    $trailer = new PdfDict($o->items);
                }
            }
        }
        foreach ($objStmNums as $sn) {
            try {
                $info = $this->loadObjStm($sn);
            } catch (PdfImportException) {
                continue;
            }
            foreach ($info['offsets'] as $num => $_off) {
                if (!isset($this->xref[$num])) {
                    $this->xref[$num] = [2, $sn, 0];
                }
            }
        }

        if ($trailer === null) {
            foreach ($this->xref as $num => $_e) {
                $o = $this->getObject($num);
                if ($o instanceof PdfDict && $o->name('Type') === 'Catalog') {
                    $trailer = new PdfDict(['Root' => new PdfRef($num)]);
                }
            }
        }
        if ($trailer === null && $oldTrailer !== null) {
            $trailer = $oldTrailer;
        }
        $this->trailer = $trailer;
    }

    // ------------------------------------------------------------------
    // Objects
    // ------------------------------------------------------------------

    private function parseIndirectAt(int $off, ?int $expectedNum): mixed
    {
        if ($off < 0 || $off >= strlen($this->data)) {
            throw new PdfImportException('Object offset out of range');
        }
        if (!preg_match('/\G[\x00\t\n\x0C\r ]*(\d+)[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+obj/', $this->data, $m, 0, $off)) {
            throw new PdfImportException('Indirect object header not found at ' . $off);
        }
        if ($expectedNum !== null && (int) $m[1] !== $expectedNum) {
            throw new PdfImportException('Object number mismatch at ' . $off);
        }
        $lx = new PdfLexer($this->data, $off + strlen($m[0]));
        $val = $lx->readValue();
        if (!$val instanceof PdfDict || !$lx->peekKeyword('stream')) {
            return $val;
        }
        $pos = $lx->pos + 6;
        if (($this->data[$pos] ?? '') === "\r" && ($this->data[$pos + 1] ?? '') === "\n") {
            $pos += 2;
        } elseif (in_array($this->data[$pos] ?? '', ["\n", "\r"], true)) {
            $pos++;
        }
        $len = $this->resolve($val->get('Length'));
        $dataLen = strlen($this->data);
        $ok = false;
        if (is_int($len) && $len >= 0 && $pos + $len <= $dataLen) {
            $after = $pos + $len;
            $ok = (bool) preg_match('/\G[\x00\t\n\x0C\r ]*endstream/', $this->data, $mm, 0, $after);
        }
        if ($ok && is_int($len)) {
            $raw = substr($this->data, $pos, $len);
        } else {
            $end = strpos($this->data, 'endstream', $pos);
            if ($end === false) {
                throw new PdfImportException('Unterminated stream');
            }
            $raw = substr($this->data, $pos, $end - $pos);
            if (str_ends_with($raw, "\r\n")) {
                $raw = substr($raw, 0, -2);
            } elseif (str_ends_with($raw, "\n") || str_ends_with($raw, "\r")) {
                $raw = substr($raw, 0, -1);
            }
        }
        return new PdfStream($val->items, $raw);
    }

    /** @return array{data: string, offsets: array<int, int>, first: int} */
    private function loadObjStm(int $stmNum): array
    {
        if (isset($this->objStms[$stmNum])) {
            return $this->objStms[$stmNum];
        }
        $stm = $this->getObject($stmNum);
        if (!$stm instanceof PdfStream || $stm->name('Type') !== 'ObjStm') {
            throw new PdfImportException('Object stream not found');
        }
        $n = (int) $this->resolve($stm->get('N'));
        $first = (int) $this->resolve($stm->get('First'));
        if ($n < 0 || $n > 1_000_000 || $first < 0) {
            throw new PdfImportException('Malformed object stream');
        }
        $data = $this->decodeStream($stm);
        $offsets = [];
        if ($n > 0 && preg_match_all('/\d+/', substr($data, 0, $first), $nums)) {
            $list = $nums[0];
            for ($i = 0; $i + 1 < count($list) && $i < $n * 2; $i += 2) {
                $offsets[(int) $list[$i]] = (int) $list[$i + 1];
            }
        }
        return $this->objStms[$stmNum] = ['data' => $data, 'offsets' => $offsets, 'first' => $first];
    }

    private function readFromObjStm(int $stmNum, int $num): mixed
    {
        $info = $this->loadObjStm($stmNum);
        if (!isset($info['offsets'][$num])) {
            return null;
        }
        $lx = new PdfLexer($info['data'], $info['first'] + $info['offsets'][$num]);
        return $lx->readValue();
    }

    // ------------------------------------------------------------------
    // Page tree
    // ------------------------------------------------------------------

    /** @return list<array{dict: PdfDict, attrs: array<string, mixed>}> */
    private function pageList(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $pages = [];
        $root = $this->resolve($this->trailer?->get('Root'));
        $seen = [];
        $budget = 200_000;
        if ($root instanceof PdfDict) {
            $this->walkPages($root->get('Pages'), [], $pages, $seen, 0, $budget);
        }
        if ($pages === []) {
            // Broken tree: fall back to scanning every object for /Type /Page
            ksort($this->xref);
            foreach (array_keys($this->xref) as $num) {
                $o = $this->getObject($num);
                if ($o instanceof PdfDict && !($o instanceof PdfStream) && $o->name('Type') === 'Page') {
                    $pages[] = ['dict' => $o, 'attrs' => $this->inheritFrom($o, [])];
                    if (count($pages) >= self::MAX_PAGES) {
                        break;
                    }
                }
            }
        }
        return $this->pages = $pages;
    }

    /**
     * @param array<string, mixed> $inherited
     * @param list<array{dict: PdfDict, attrs: array<string, mixed>}> $pages
     * @param array<int, bool> $seen
     */
    private function walkPages(mixed $node, array $inherited, array &$pages, array &$seen, int $depth, int &$budget): void
    {
        if ($depth > 64 || --$budget < 0 || count($pages) >= self::MAX_PAGES) {
            return;
        }
        if ($node instanceof PdfRef) {
            if (isset($seen[$node->num])) {
                return;
            }
            $seen[$node->num] = true;
        }
        $dict = $this->resolve($node);
        if (!$dict instanceof PdfDict || $dict instanceof PdfStream) {
            return;
        }
        if (in_array($dict->name('Type'), ['Catalog', 'Pages'], true) && !is_array($this->resolve($dict->get('Kids')))) {
            return; // empty or self-referencing /Pages node
        }
        $attrs = $this->inheritFrom($dict, $inherited);
        $kids = $this->resolve($dict->get('Kids'));
        if (is_array($kids) && $dict->name('Type') !== 'Page') {
            foreach ($kids as $kid) {
                $this->walkPages($kid, $attrs, $pages, $seen, $depth + 1, $budget);
            }
            return;
        }
        $pages[] = ['dict' => $dict, 'attrs' => $attrs];
    }

    /**
     * @param array<string, mixed> $inherited
     * @return array<string, mixed>
     */
    private function inheritFrom(PdfDict $dict, array $inherited): array
    {
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            if ($dict->has($key) && $dict->get($key) !== null) {
                $inherited[$key] = $dict->get($key);
            }
        }
        return $inherited;
    }
}
