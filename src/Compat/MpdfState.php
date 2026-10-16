<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * Everything the mPDF tag pre-pass (MpdfTags) extracted from the HTML for one render pass: named running
 * headers/footers, the tags that switch them, page-number changes, watermarks and index entries. After pagination
 * resolve() turns the document-order events into per-page decisions.
 */
final class MpdfState
{
    /** @var array<string, string> name => HTML of `<htmlpageheader name="..">` */
    public array $headers = [];
    /** @var array<string, string> name => HTML of `<htmlpagefooter name="..">` */
    public array $footers = [];

    /** @var array{O: ?string, E: ?string} initial header selection from `@page { header: html_x }` (null = unchanged, '' = none) */
    public array $baseHeader = ['O' => null, 'E' => null];
    /** @var array{O: ?string, E: ?string} */
    public array $baseFooter = ['O' => null, 'E' => null];

    /**
     * Document-order events. Keys: id (marker element id), kind ('pb'|'set'), show (bool), hdr / ftr
     * (['O' => ?string, 'E' => ?string]; null = unchanged, '' = off, otherwise a name), reset (?int), style (?string),
     * suppress (?bool), type (?string NEXT-ODD|NEXT-EVEN).
     *
     * @var list<array<string, mixed>>
     */
    public array $events = [];

    /** @var array<string, string> index entry marker id => term */
    public array $indexEntries = [];

    /** @var ?array{content: string, alpha: float} */
    public ?array $watermarkText = null;
    /** @var ?array{src: string, alpha: float, size: string, pos: string} */
    public ?array $watermarkImage = null;

    /** CSS (`@page ivo-pgN { ... }`) generated for pagebreak geometry */
    public string $pageCss = '';

    /** @var array<string, int> marker id => 0-based page (valid after IvoPdf::mpdfAfterPaginate) */
    public array $markerPages = [];
    /** @var array<string, float> marker id => virtual y */
    public array $markerY = [];

    /** @var list<array{O: ?string, E: ?string}> resolved per-page header decisions, see resolve() */
    private array $pageHeader = [];
    /** @var list<?string> */
    private array $pageFooter = [];
    /** @var list<string> */
    private array $labels = [];
    /** @var list<?string> */
    private array $hdrByPage = [];

    public function usesRunning(): bool
    {
        return $this->headers !== [] || $this->footers !== [] || $this->events !== []
            || $this->baseHeader !== ['O' => null, 'E' => null] || $this->baseFooter !== ['O' => null, 'E' => null];
    }

    public function usesPageLabels(): bool
    {
        foreach ($this->events as $e) {
            if (($e['reset'] ?? null) !== null || ($e['style'] ?? null) !== null || ($e['suppress'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves the events against the real marker pages. Fills the per-page header / footer names and page labels.
     *
     * @param float $firstPageTop virtual y at which page 0 starts (a `sethtml*` tag before any content applies to page 1)
     */
    public function resolve(int $pageCount, float $firstPageTop): void
    {
        $evByPage = [];
        foreach ($this->events as $order => $e) {
            $id = (string) $e['id'];
            if (!isset($this->markerPages[$id])) {
                continue;
            }
            $page = $this->markerPages[$id];
            if (($e['kind'] ?? '') === 'set' && empty($e['show'])) {
                $atDocStart = $page === 0 && ($this->markerY[$id] ?? 1e9) <= $firstPageTop + 1.0;
                if (!$atDocStart) {
                    $page++;
                }
            }
            $evByPage[$page][] = $e;
        }

        $hdr = $this->baseHeader;
        $ftr = $this->baseFooter;
        $number = 1;
        $style = '1';
        $suppress = false;
        $this->pageHeader = [];
        $this->pageFooter = [];
        $this->labels = [];
        $footerSides = [];
        for ($p = 0; $p < $pageCount; $p++) {
            $reset = null;
            foreach ($evByPage[$p] ?? [] as $e) {
                foreach (['O', 'E'] as $side) {
                    if (isset($e['hdr']) && is_array($e['hdr']) && array_key_exists($side, $e['hdr']) && $e['hdr'][$side] !== null) {
                        $hdr[$side] = $e['hdr'][$side];
                    }
                    if (isset($e['ftr']) && is_array($e['ftr']) && array_key_exists($side, $e['ftr']) && $e['ftr'][$side] !== null) {
                        $ftr[$side] = $e['ftr'][$side];
                    }
                }
                if (($e['reset'] ?? null) !== null) {
                    $reset = (int) $e['reset'];
                }
                if (($e['style'] ?? null) !== null) {
                    $style = (string) $e['style'];
                }
                if (($e['suppress'] ?? null) !== null) {
                    $suppress = (bool) $e['suppress'];
                }
            }
            if ($reset !== null) {
                $number = $reset;
            }
            $this->pageHeader[$p] = $hdr;
            $footerSides[$p] = $ftr;
            $this->labels[$p] = $suppress ? '' : self::formatNumber($number, $style);
            $number++;
        }
        $this->pageFooter = [];
        foreach ($footerSides as $p => $f) {
            $this->pageFooter[$p] = $f[$p % 2 === 0 ? 'O' : 'E'];
        }
        $this->hdrByPage = [];
        foreach ($this->pageHeader as $p => $h) {
            $this->hdrByPage[$p] = $h[$p % 2 === 0 ? 'O' : 'E'];
        }
    }

    /** Selected header name for a page: null = not controlled by mPDF tags, '' = none. */
    public function headerName(int $page): ?string
    {
        return $this->hdrByPage[$page] ?? null;
    }

    public function footerName(int $page): ?string
    {
        return $this->pageFooter[$page] ?? null;
    }

    /** Page number as shown by `{PAGENO}` (resetpagenum / pagenumstyle / suppress applied). */
    public function pageLabel(int $page): string
    {
        return $this->labels[$page] ?? (string) ($page + 1);
    }

    /** Formats a page number: '1', 'A', 'a', 'I', 'i' (mPDF pagenumstyle). */
    public static function formatNumber(int $n, string $style): string
    {
        switch ($style) {
            case 'A':
            case 'a':
                if ($n <= 0) {
                    return (string) $n;
                }
                $s = '';
                $m = $n;
                while ($m > 0) {
                    $m--;
                    $s = chr(65 + $m % 26) . $s;
                    $m = intdiv($m, 26);
                }
                return $style === 'a' ? strtolower($s) : $s;
            case 'I':
            case 'i':
                if ($n <= 0 || $n >= 4000) {
                    return (string) $n;
                }
                $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
                $s = '';
                foreach ($map as $r => $v) {
                    while ($n >= $v) {
                        $s .= $r;
                        $n -= $v;
                    }
                }
                return $style === 'i' ? strtolower($s) : $s;
            default:
                return (string) $n;
        }
    }
}
