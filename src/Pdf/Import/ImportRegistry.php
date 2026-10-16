<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Per-document registry of imported pages (Form XObjects): resource names, per-page backgrounds, drawing
 * commands and the final object emission performed by PdfDocument::compile().
 */
final class ImportRegistry
{
    /** @var array<string, ImportedPage> resource name => page */
    private array $pages = [];
    /** @var array<int, string> output page index => resource name drawn underneath its content */
    private array $backgrounds = [];
    /** @var array<int, string> output page index => resource name of an imported page appended as a whole page */
    private array $appended = [];
    /** @var array<int, array<int, int>> spl_object_id(pool) => (local id => output object number), filled by emit() */
    private array $poolMaps = [];
    private int $counter = 0;

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }

    /** Registers a page and returns its XObject resource name (e.g. "Tpl1"); $name forces a specific name. */
    public function register(ImportedPage $page, ?string $name = null): string
    {
        if ($name === null) {
            foreach ($this->pages as $existing => $p) {
                if ($p === $page && str_starts_with($existing, 'Tpl')) {
                    return $existing;
                }
            }
            $name = 'Tpl' . (++$this->counter);
        }
        $this->pages[$name] = $page;
        return $name;
    }

    public function getPage(string $name): ?ImportedPage
    {
        return $this->pages[$name] ?? null;
    }

    public function setBackground(int $pageIndex, string $name): void
    {
        $this->backgrounds[$pageIndex] = $name;
    }

    /** Marks an output page as a whole imported page (its annotations are carried over). */
    public function markAppended(int $pageIndex, string $name): void
    {
        $this->appended[$pageIndex] = $name;
    }

    /**
     * Output pages that show imported pages: [['index' => output page, 'page' => ImportedPage, 'mode' => 'append'|'background']].
     *
     * @return list<array{index: int, page: ImportedPage, mode: string}>
     */
    public function placements(): array
    {
        $out = [];
        foreach ($this->appended as $idx => $name) {
            if (isset($this->pages[$name])) {
                $out[] = ['index' => $idx, 'page' => $this->pages[$name], 'mode' => 'append'];
            }
        }
        foreach ($this->backgrounds as $idx => $name) {
            if (isset($this->pages[$name])) {
                $out[] = ['index' => $idx, 'page' => $this->pages[$name], 'mode' => 'background'];
            }
        }
        return $out;
    }

    /** @return array<int, array<int, int>> pool maps of the last emit() */
    public function getPoolMaps(): array
    {
        return $this->poolMaps;
    }

    /** Content-stream snippet drawing the page's background template (fitted and centered), or ''. */
    public function backgroundContent(int $pageIndex, float $pageW, float $pageH): string
    {
        $name = $this->backgrounds[$pageIndex] ?? null;
        $page = $name !== null ? ($this->pages[$name] ?? null) : null;
        if ($name === null || $page === null || $page->width <= 0.0 || $page->height <= 0.0) {
            return '';
        }
        $s = min($pageW / $page->width, $pageH / $page->height);
        $dw = $page->width * $s;
        $dh = $page->height * $s;
        return sprintf("q\n%.4F 0 0 %.4F %.4F %.4F cm /%s Do\nQ\n", $dw, $dh, ($pageW - $dw) / 2.0, ($pageH - $dh) / 2.0, $name);
    }

    /** Content for a whole output page that shows the template at its natural size. */
    public function fullPageContent(string $name): string
    {
        $page = $this->pages[$name] ?? null;
        if ($page === null) {
            return '';
        }
        return sprintf("q\n%.4F 0 0 %.4F 0 0 cm /%s Do\nQ\n", $page->width, $page->height, $name);
    }

    /**
     * Writes all registered pages (and the objects they depend on) into the output document.
     *
     * @param callable(): int $alloc
     * @param callable(int, string): void $put
     * @return array<string, int> resource name => object number
     */
    public function emit(callable $alloc, callable $put, bool $compress): array
    {
        $byPool = [];
        foreach ($this->pages as $page) {
            $key = spl_object_id($page->pool);
            $byPool[$key]['pool'] = $page->pool;
            $byPool[$key]['roots'][$page->rootId] = $page->rootId;
        }
        // Objects referenced by the annotations that will be carried over (appearance streams, fonts, ...)
        foreach ($this->placements() as $pl) {
            $page = $pl['page'];
            $key = spl_object_id($page->pool);
            foreach ($page->annots as $a) {
                if ($pl['mode'] !== 'append' && ($a['kind'] === 'widget' || ($a['kind'] === 'link' && $a['dest'] !== null))) {
                    continue;
                }
                $text = $a['entries'] . ($a['fieldEntries'] ?? '');
                if (preg_match_all('/\x00R(\d+)\x00/', $text, $mm)) {
                    foreach ($mm[1] as $id) {
                        $byPool[$key]['roots'][(int) $id] = (int) $id;
                    }
                }
            }
            if ($pl['mode'] === 'append') {
                foreach ($page->acroFonts as $ser) {
                    if (preg_match_all('/\x00R(\d+)\x00/', $ser, $mm)) {
                        foreach ($mm[1] as $id) {
                            $byPool[$key]['roots'][(int) $id] = (int) $id;
                        }
                    }
                }
            }
        }
        $maps = [];
        foreach ($byPool as $key => $info) {
            $maps[$key] = $info['pool']->emit(array_values($info['roots']), $alloc, $put, $compress);
        }
        $this->poolMaps = $maps;
        $refs = [];
        foreach ($this->pages as $name => $page) {
            $objId = $maps[spl_object_id($page->pool)][$page->rootId] ?? null;
            if ($objId !== null) {
                $refs[$name] = $objId;
            }
        }
        return $refs;
    }
}
