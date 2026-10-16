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

    /** @var list<LayoutBox>|null */
    private ?array $fixed = null;
    private ?LayoutBox $fixedRoot = null;

    public function __construct()
    {
        $this->extents = new WeakMap();
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
