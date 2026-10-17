<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Layout\LayoutBox;
use WeakMap;

/**
 * Per-render cache of the vertical extent (in virtual document coordinates) covered by a box and everything
 * painted inside it. Lets the page renderer skip whole subtrees that cannot touch the current page instead of
 * walking the complete tree for every page (O(pages x boxes) on long documents).
 *
 * Anything that can paint outside the layout rectangles (transforms, shadows, filters, clip paths, outlines,
 * text shadows) makes the extent unbounded so those subtrees are always visited.
 */
final class RenderCull
{
    /** Safety margin for borders, collapsed table borders and anti-aliasing (pt) */
    public const SLACK = 30.0;

    /** @var WeakMap<LayoutBox, array{0: float, 1: float}> */
    private WeakMap $extents;

    /** @var WeakMap<LayoutBox, array{n: int, lo: list<float>, idx: list<int>, maxHi: list<float>}> children of a box ordered by top edge (long child lists) */
    private WeakMap $childIndex;

    /** @var WeakMap<LayoutBox, array{0: bool, 1: int, 2: float}> per box: line y values ascending?, first line not above the last visible window, that window's top */
    private WeakMap $lineWindow;

    /** @var list<LayoutBox>|null */
    private ?array $fixed = null;
    private ?LayoutBox $fixedRoot = null;

    public function __construct()
    {
        $this->extents = new WeakMap();
        $this->childIndex = new WeakMap();
        $this->lineWindow = new WeakMap();
    }

    /**
     * The children of $parent whose subtree can touch the page slice [$top, $bottom], in their original order.
     * Long child lists are indexed once (sorted by top edge with a running maximum of the bottom edges), so a page
     * only looks at the children around it instead of every child of the box (O(pages x children) otherwise).
     *
     * @return list<LayoutBox>
     */
    public function touching(LayoutBox $parent, float $top, float $bottom): array
    {
        $children = $parent->children;
        $n = count($children);
        $lo = $top - self::SLACK;
        $hi = $bottom + self::SLACK;
        if ($n < 64) {
            $out = [];
            foreach ($children as $child) {
                [$clo, $chi] = $this->extent($child);
                if ($chi >= $lo && $clo <= $hi) {
                    $out[] = $child;
                }
            }
            return $out;
        }

        $ix = $this->childIndex[$parent] ?? null;
        if ($ix === null || $ix['n'] !== $n) {
            $tops = [];
            $bottoms = [];
            foreach ($children as $i => $child) {
                [$tops[$i], $bottoms[$i]] = $this->extent($child);
            }
            $order = array_keys($tops);
            usort($order, static fn(int $a, int $b): int => $tops[$a] <=> $tops[$b] ?: $a <=> $b);
            $sortedLo = [];
            $maxHi = [];
            $run = -INF;
            foreach ($order as $pos => $i) {
                $sortedLo[$pos] = $tops[$i];
                $run = max($run, $bottoms[$i]);
                $maxHi[$pos] = $run;
            }
            $ix = ['n' => $n, 'lo' => $sortedLo, 'idx' => $order, 'maxHi' => $maxHi];
            $this->childIndex[$parent] = $ix;
        }

        // Children whose top edge is <= $hi form a prefix of the sorted order
        $a = 0;
        $b = $n;
        while ($a < $b) {
            $mid = ($a + $b) >> 1;
            if ($ix['lo'][$mid] <= $hi) {
                $a = $mid + 1;
            } else {
                $b = $mid;
            }
        }
        $picked = [];
        for ($pos = $a - 1; $pos >= 0 && $ix['maxHi'][$pos] >= $lo; $pos--) {
            $i = $ix['idx'][$pos];
            if ($this->extents[$children[$i]][1] >= $lo) {
                $picked[] = $i;
            }
        }
        sort($picked);
        $out = [];
        foreach ($picked as $i) {
            $out[] = $children[$i];
        }
        return $out;
    }

    /**
     * Index of the first line of $box that can be visible in the slice starting at $top, and whether the lines are
     * ordered top to bottom (then the caller may stop at the first line below the slice). Pages are normally painted
     * in ascending order, so the answer of the previous page is the starting point (linear over all pages).
     *
     * @return array{0: int, 1: bool}
     */
    public function lineStart(LayoutBox $box, float $top): array
    {
        $lines = $box->lines;
        $n = count($lines);
        $st = $this->lineWindow[$box] ?? null;
        if ($st === null) {
            $ordered = true;
            $prev = -INF;
            foreach ($lines as $line) {
                if ($line->y < $prev) {
                    $ordered = false;
                    break;
                }
                $prev = $line->y;
            }
            $st = [$ordered, 0, -INF];
        }
        [$ordered, $start, $lastTop] = $st;
        if ($top < $lastTop) {
            $start = 0; // painted out of order: rescan from the beginning
        }
        while ($start < $n && $lines[$start]->y + $lines[$start]->height < $top - 0.5) {
            $start++;
        }
        $this->lineWindow[$box] = [$ordered, $start, $top];
        return [$start, $ordered];
    }

    /**
     * @return array{0: float, 1: float} [minY, maxY]
     */
    public function extent(LayoutBox $box): array
    {
        if (isset($this->extents[$box])) {
            return $this->extents[$box];
        }
        // Iterative post-order walk (deep trees must not exhaust the PHP stack)
        $stack = [[$box, false]];
        while ($stack !== []) {
            [$b, $expanded] = array_pop($stack);
            if (isset($this->extents[$b])) {
                continue;
            }
            if (!$expanded) {
                $stack[] = [$b, true];
                foreach ($this->painted($b) as $c) {
                    if (!isset($this->extents[$c])) {
                        $stack[] = [$c, false];
                    }
                }
                continue;
            }
            $this->extents[$b] = $this->compute($b);
        }
        return $this->extents[$box];
    }

    /**
     * Boxes with position: fixed anywhere in the tree (found once per root).
     *
     * @return list<LayoutBox>
     */
    public function fixedBoxes(LayoutBox $root): array
    {
        if ($this->fixed !== null && $this->fixedRoot === $root) {
            return $this->fixed;
        }
        $out = [];
        $stack = [$root];
        while ($stack !== []) {
            $b = array_pop($stack);
            if (strtolower($b->style->get('position', 'static')) === 'fixed') {
                $out[] = $b;
                continue;
            }
            for ($i = count($b->children) - 1; $i >= 0; $i--) {
                $stack[] = $b->children[$i];
            }
        }
        $this->fixedRoot = $root;
        return $this->fixed = $out;
    }

    /**
     * Child boxes that are painted through this box's own coordinates: block-level children, positioned children and
     * atomic inline boxes held by line runs. Text nodes and inline children already flattened into lines carry no
     * layout position of their own; fixed boxes are painted by the fixed-box path on every page.
     *
     * @return list<LayoutBox>
     */
    private function painted(LayoutBox $b): array
    {
        $out = [];
        $hasLines = $b->lines !== [];
        foreach ($b->children as $c) {
            if ($c->isTextNode()) {
                continue;
            }
            if ($c->isPositionedOutOfFlow()) {
                if (strtolower($c->style->get('position', 'static')) === 'fixed') {
                    continue;
                }
            } elseif ($hasLines && $c->isInlineLevel()) {
                continue;
            }
            $out[] = $c;
        }
        foreach ($b->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->inlineBox !== null) {
                    $out[] = $run->inlineBox;
                }
            }
        }
        return $out;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function compute(LayoutBox $b): array
    {
        $style = $b->style;
        if (
            strtolower(trim($style->get('transform', 'none'))) !== 'none'
            || strtolower(trim($style->get('box-shadow', 'none'))) !== 'none'
            || strtolower(trim($style->get('filter', 'none'))) !== 'none'
            || strtolower(trim($style->get('clip-path', 'none'))) !== 'none'
            || strtolower(trim($style->get('text-shadow', 'none'))) !== 'none'
            || strtolower(trim($style->get('position', 'static'))) === 'sticky'
            || $b->columnRects !== []
        ) {
            return [-INF, INF];
        }
        $outline = $style->get('outline-style', 'none');
        if ($outline !== 'none' && $outline !== '') {
            return [-INF, INF];
        }

        $lo = $b->y;
        $hi = $b->y + $b->height;
        foreach ($b->lines as $line) {
            if ($line->y < $lo) {
                $lo = $line->y;
            }
            $bottom = $line->y + $line->height;
            if ($bottom > $hi) {
                $hi = $bottom;
            }
        }
        foreach ($this->painted($b) as $c) {
            [$clo, $chi] = $this->extents[$c] ?? [-INF, INF];
            if ($clo < $lo) {
                $lo = $clo;
            }
            if ($chi > $hi) {
                $hi = $chi;
            }
        }
        return [$lo, $hi];
    }
}
