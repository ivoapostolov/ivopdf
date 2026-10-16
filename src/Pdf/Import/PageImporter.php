<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Converts pages of a parsed source PDF into Form XObjects (ImportedPage), deep-copying every resource they
 * reference into an ImportedPool. Several pages of the same file share one pool, so shared fonts/images are
 * copied once.
 */
final class PageImporter
{
    private ImportedPool $pool;
    /** @var array<int, int> source object number => pool id */
    private array $map = [];
    /** @var list<int> source object numbers waiting to be serialized */
    private array $queue = [];
    /** @var array<int, ImportedPage> */
    private array $pageCache = [];
    /** @var ?\Closure(PdfRef): ?string  consulted for every serialized reference (used for page references in annotations) */
    private ?\Closure $refHook = null;
    /** @var array<int, int>|null spl_object_id of a page dictionary => 1-based page number */
    private ?array $pageIds = null;

    public function __construct(private readonly PdfReader $reader)
    {
        $this->pool = new ImportedPool();
    }

    public function getReader(): PdfReader
    {
        return $this->reader;
    }

    public function getPageCount(): int
    {
        return $this->reader->getPageCount();
    }

    public function importPage(int $number): ImportedPage
    {
        if (isset($this->pageCache[$number])) {
            return $this->pageCache[$number];
        }
        $page = $this->reader->getPage($number);
        $dict = $page['dict'];
        $attrs = $page['attrs'];

        $media = $this->box($attrs['MediaBox'] ?? null) ?? [0.0, 0.0, 612.0, 792.0];
        $crop = $this->box($attrs['CropBox'] ?? null) ?? $media;
        $x0 = max($media[0], $crop[0]);
        $y0 = max($media[1], $crop[1]);
        $x1 = min($media[2], $crop[2]);
        $y1 = min($media[3], $crop[3]);
        if ($x1 - $x0 < 1.0 || $y1 - $y0 < 1.0) {
            [$x0, $y0, $x1, $y1] = $media;
        }
        $w = $x1 - $x0;
        $h = $y1 - $y0;
        if ($w < 1.0 || $h < 1.0 || $w > 200000.0 || $h > 200000.0) {
            throw new PdfImportException("Page {$number} has an invalid page box");
        }

        $rot = $this->reader->resolve($attrs['Rotate'] ?? 0);
        $rot = is_int($rot) || is_float($rot) ? (((int) $rot % 360) + 360) % 360 : 0;
        $rot = intdiv($rot, 90) * 90;

        // Matrix mapping the rotated box to the unit square: u = a*x + c*y + e, v = b*x + d*y + f
        [$a, $b, $c, $d, $e, $f] = match ($rot) {
            90 => [0.0, -1.0 / $w, 1.0 / $h, 0.0, -$y0 / $h, 1.0 + $x0 / $w],
            180 => [-1.0 / $w, 0.0, 0.0, -1.0 / $h, 1.0 + $x0 / $w, 1.0 + $y0 / $h],
            270 => [0.0, 1.0 / $w, -1.0 / $h, 0.0, 1.0 + $y0 / $h, -$x0 / $w],
            default => [1.0 / $w, 0.0, 0.0, 1.0 / $h, -$x0 / $w, -$y0 / $h],
        };

        // Content: decode and concatenate every content stream
        $contents = $this->reader->resolve($dict->get('Contents'));
        $streams = [];
        if ($contents instanceof PdfStream) {
            $streams[] = $contents;
        } elseif (is_array($contents)) {
            foreach ($contents as $cRef) {
                $cs = $this->reader->resolve($cRef);
                if ($cs instanceof PdfStream) {
                    $streams[] = $cs;
                }
            }
        }
        $parts = [];
        foreach ($streams as $cStream) {
            $parts[] = $this->reader->decodeStream($cStream);
        }
        $content = implode("\n", $parts);

        $resources = $this->reader->resolve($attrs['Resources'] ?? null);
        $resStr = $resources instanceof PdfDict && !($resources instanceof PdfStream) ? $this->ser($resources, 0) : '<< >>';

        $group = $this->reader->resolve($dict->get('Group'));
        $groupStr = '';
        if ($group instanceof PdfDict && !($group instanceof PdfStream) && $group->name('S') === 'Transparency') {
            $groupStr = ' /Group ' . $this->ser($group, 0);
        }

        $rootId = $this->pool->allocate();
        $this->pool->set(
            $rootId,
            sprintf(
                '<< /Type /XObject /Subtype /Form /FormType 1 /BBox [%s %s %s %s] /Matrix [%s %s %s %s %s %s] /Resources %s%s >>',
                $this->num($x0),
                $this->num($y0),
                $this->num($x1),
                $this->num($y1),
                $this->num($a, 9),
                $this->num($b, 9),
                $this->num($c, 9),
                $this->num($d, 9),
                $this->num($e, 9),
                $this->num($f, 9),
                $resStr,
                $groupStr
            ),
            $content,
            true
        );
        $this->drain();

        $vw = ($rot === 90 || $rot === 270) ? $h : $w;
        $vh = ($rot === 90 || $rot === 270) ? $w : $h;
        $imported = new ImportedPage($this->pool, $rootId, $vw, $vh);
        $imported->sourcePage = $number;
        try {
            AnnotationImporter::import($this, $imported, $dict, [$x0, $y0, $x1, $y1], $rot);
        } catch (\Throwable $e) {
            $imported->annots = []; // annotations are best-effort: a damaged one never prevents the page import
        }
        return $this->pageCache[$number] = $imported;
    }

    /** Serializes a value (references are imported into the pool, "<local id> placeholders" placeholders). */
    public function serializeValue(mixed $v): string
    {
        $s = $this->ser($v, 0);
        $this->drain();
        return $s;
    }

    /** Imports a copy of a stream with replaced dictionary items; returns the pool placeholder of the copy. */
    public function importStreamCopy(PdfStream $s, array $items): string
    {
        unset($items['Length'], $items['DL']);
        $id = $this->pool->allocate();
        $this->pool->set($id, $this->serDict(new PdfDict($items), 0), $s->raw, false);
        $this->drain();
        return "\x00R{$id}\x00";
    }

    /** Installs a hook that may replace the serialization of a reference (null = default handling). */
    public function setRefHook(?\Closure $hook): void
    {
        $this->refHook = $hook;
    }

    /** 1-based page number of a reference to a page dictionary of this document, or null. */
    public function pageNumberOfRef(PdfRef $ref): ?int
    {
        if ($this->pageIds === null) {
            $this->pageIds = [];
            $n = $this->reader->getPageCount();
            for ($i = 1; $i <= $n; $i++) {
                $this->pageIds[spl_object_id($this->reader->getPage($i)['dict'])] = $i;
            }
        }
        $obj = $this->reader->getObject($ref->num);
        return is_object($obj) ? ($this->pageIds[spl_object_id($obj)] ?? null) : null;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private function box(mixed $v): ?array
    {
        $v = $this->reader->resolve($v);
        if (!is_array($v) || count($v) < 4) {
            return null;
        }
        $n = [];
        for ($i = 0; $i < 4; $i++) {
            $x = $this->reader->resolve($v[$i]);
            if (!is_int($x) && !is_float($x)) {
                return null;
            }
            $n[] = (float) $x;
        }
        return [min($n[0], $n[2]), min($n[1], $n[3]), max($n[0], $n[2]), max($n[1], $n[3])];
    }

    private function drain(): void
    {
        while ($this->queue !== []) {
            $num = array_pop($this->queue);
            $id = $this->map[$num];
            $obj = $this->reader->getObject($num);
            if ($obj instanceof PdfStream) {
                $items = $obj->items;
                unset($items['Length'], $items['DL']);
                $this->pool->set($id, $this->serDict(new PdfDict($items), 0), $obj->raw, false);
            } else {
                $this->pool->set($id, $this->ser($obj, 0));
            }
        }
    }

    private function importObject(int $num): ?int
    {
        if (isset($this->map[$num])) {
            return $this->map[$num];
        }
        $obj = $this->reader->getObject($num);
        if ($obj === null) {
            return null;
        }
        if ($obj instanceof PdfDict && in_array($obj->name('Type'), ['Page', 'Pages', 'Catalog'], true)) {
            return null; // never drag the page tree along
        }
        $id = $this->pool->allocate();
        $this->map[$num] = $id;
        $this->queue[] = $num;
        return $id;
    }

    private function ser(mixed $v, int $depth): string
    {
        if ($depth > 100) {
            throw new PdfImportException('PDF object nesting too deep');
        }
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return $this->num($v);
        }
        if ($v instanceof PdfName) {
            return $this->name($v->name);
        }
        if ($v instanceof PdfString) {
            return '<' . strtoupper(bin2hex($v->bytes)) . '>';
        }
        if ($v instanceof PdfRef) {
            if ($this->refHook !== null && ($hooked = ($this->refHook)($v)) !== null) {
                return $hooked;
            }
            $id = $this->importObject($v->num);
            return $id === null ? 'null' : "\x00R{$id}\x00";
        }
        if ($v instanceof PdfStream) {
            return 'null'; // streams are only valid as indirect objects
        }
        if ($v instanceof PdfDict) {
            return $this->serDict($v, $depth);
        }
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $item) {
                $parts[] = $this->ser($item, $depth + 1);
            }
            return '[' . implode(' ', $parts) . ']';
        }
        return 'null';
    }

    private function serDict(PdfDict $d, int $depth): string
    {
        $parts = [];
        foreach ($d->items as $key => $val) {
            if ($key === 'Parent' && $val instanceof PdfRef) {
                continue;
            }
            $parts[] = $this->name((string) $key) . ' ' . $this->ser($val, $depth + 1);
        }
        return '<< ' . implode(' ', $parts) . ' >>';
    }

    private function name(string $n): string
    {
        $esc = (string) preg_replace_callback(
            '/[^\x21-\x7E]|[#()<>\[\]{}\/%]/',
            static fn(array $m): string => sprintf('#%02X', ord($m[0])),
            $n
        );
        return '/' . $esc;
    }

    private function num(float $v, int $decimals = 4): string
    {
        $s = rtrim(rtrim(sprintf('%.' . $decimals . 'F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
