<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

/**
 * Resolves the geometry of every page: size and margins from the `@page` cascade (plain, :first, :left/:right,
 * named pages) plus the vertical space reserved for the page's running header / footer.
 */
final class PageLayoutPlan
{
    /** @var array<string, array{w: float, h: float, m: array{top: float, right: float, bottom: float, left: float}, eTop: float, eBottom: float, ph: float, boxes: array<string, array<string, string>>}> */
    private array $cache = [];

    /**
     * @param \Closure(array<string, string>): array{0: float, 1: float, 2: array{top: float, right: float, bottom: float, left: float}} $applyProps
     *        maps @page declarations to [width, height, margins]
     * @param \Closure(int, float, array{top: float, right: float, bottom: float, left: float}, float): array{0: float, 1: float} $reserve
     *        (pageIndex, contentWidth, margins, pageHeight) => [effective top margin, effective bottom margin]
     */
    public function __construct(
        private PageRules $rules,
        private \Closure $applyProps,
        private \Closure $reserve,
        private bool $runningVaries
    ) {
    }

    /** @var bool mPDF `mirrorMargins`: even (left-hand) pages swap their left and right margins */
    private bool $mirror = false;

    public function setMirrorMargins(bool $mirror): void
    {
        $this->mirror = $mirror;
        $this->cache = [];
    }

    /** @var (\Closure(int, float, float): float)|null (pageIndex, contentWidth, pageHeight) => extra space at the bottom (footnotes) */
    private ?\Closure $extraBottom = null;

    /** Reserves extra space at the bottom of individual pages (CSS footnotes); makes the geometry per page. */
    public function setExtraBottom(?\Closure $extra): void
    {
        $this->extraBottom = $extra;
        $this->cache = [];
    }

    public function isUniform(): bool
    {
        return !$this->rules->hasPageVariants() && !$this->runningVaries && !$this->mirror && $this->extraBottom === null;
    }

    /**
     * @return array{w: float, h: float, m: array{top: float, right: float, bottom: float, left: float}, eTop: float, eBottom: float, ph: float, boxes: array<string, array<string, string>>}
     */
    public function box(int $pageIndex, ?string $name = null): array
    {
        $key = ($pageIndex === 0 ? 'F' : '') . ($this->extraBottom !== null ? 'P' . $pageIndex : '') . ($pageIndex % 2) . '|' . ($name ?? '');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $resolved = $this->rules->resolve($pageIndex, $name);
        [$w, $h, $m] = ($this->applyProps)($resolved['props']);
        if ($this->mirror && $pageIndex % 2 === 1) {
            [$m['left'], $m['right']] = [$m['right'], $m['left']];
        }
        $cw = max(50.0, $w - $m['left'] - $m['right']);
        [$eTop, $eBottom] = ($this->reserve)($pageIndex, $cw, $m, $h);
        $extra = $this->extraBottom !== null ? max(0.0, ($this->extraBottom)($pageIndex, $cw, $h)) : 0.0;
        $eBottom += $extra;
        return $this->cache[$key] = [
            'w' => $w,
            'h' => $h,
            'm' => $m,
            'eTop' => $eTop,
            'eBottom' => $eBottom,
            'fn' => $extra,
            'ph' => max(50.0, $h - $eTop - $eBottom),
            'boxes' => $resolved['boxes'],
        ];
    }

    /**
     * Narrowest content width of the unnamed pages (the layout width); named pages with another width are
     * handled by giving their elements an explicit width.
     */
    public function layoutWidth(): float
    {
        $min = INF;
        foreach ([0, 1, 2] as $i) {
            $b = $this->box($i, null);
            $min = min($min, $b['w'] - $b['m']['left'] - $b['m']['right']);
        }
        return max(50.0, $min);
    }

    /**
     * Content width of a named page (narrowest over first/left/right variants).
     */
    public function namedWidth(string $name): float
    {
        $min = INF;
        foreach ([0, 1, 2] as $i) {
            $b = $this->box($i, $name);
            $min = min($min, $b['w'] - $b['m']['left'] - $b['m']['right']);
        }
        return max(50.0, $min);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->rules->getNames();
    }

    public function hasMarginBoxes(): bool
    {
        return $this->rules->hasMarginBoxes();
    }
}
