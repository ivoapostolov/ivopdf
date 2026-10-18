<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Options::$pageOverflowWrap, after the layout of the main document: finds text that still runs past the edge of the
 * page's content area (the right edge; the left one in right-to-left text) and counts the words broken at that edge.
 *
 * The inline layout breaks a word where it would cross the page edge in its own coordinates. A box laid out first and
 * moved afterwards by an amount the layout did not know yet (inline-block, flex item, positioned box, ...) ends up
 * elsewhere: scan() reports for each such box the page edges relative to its final x, keyed by the box's path in the
 * box tree (child indexes from the root: the same in every layout pass of a render), and IvoPdf lays the document out
 * again with them (resolve() finds the boxes of the new pass).
 *
 * @internal
 */
final class PageOverflow
{
    /** @var array<string, array{0: float, 1: float}> path => page edges relative to the box's x */
    private array $needed = [];
    private int $broken = 0;
    private int $lost = 0;
    /** @var list<int> child indexes of the box being visited */
    private array $path = [];

    /**
     * @param \WeakMap<LayoutBox, array{0: float, 1: float}>|null $used edges the boxes fitted to the page edge assumed
     */
    private function __construct(private float $left, private float $right, private ?\WeakMap $used)
    {
    }

    /**
     * @param \WeakMap<LayoutBox, array{0: float, 1: float}>|null $used LayoutEngine::$edgeUsed of the layout
     * @return array{needed: array<string, array{0: float, 1: float}>, broken: int, lost: int}
     */
    public static function scan(LayoutBox $root, float $left, float $right, ?\WeakMap $used): array
    {
        $scan = new self($left, $right, $used);
        $scan->walk($root, true);
        return ['needed' => $scan->needed, 'broken' => $scan->broken, 'lost' => $scan->lost];
    }

    /**
     * The boxes of a new layout pass (before its layout) at the paths of an earlier pass.
     *
     * @param array<string, array{0: float, 1: float}> $anchors path => page edges relative to the box's x
     * @return \WeakMap<LayoutBox, array{0: float, 1: float}>|null
     */
    public static function resolve(LayoutBox $root, array $anchors): ?\WeakMap
    {
        if ($anchors === []) {
            return null;
        }
        $map = new \WeakMap();
        foreach ($anchors as $path => $rel) {
            $box = $root;
            foreach ($path === '' ? [] : explode('.', (string) $path) as $index) {
                $box = self::children($box)[(int) $index] ?? null;
                if ($box === null) {
                    continue 2;
                }
            }
            $map[$box] = $rel;
        }
        return $map;
    }

    /**
     * Children in the order the layout leaves them: a table moves its bottom captions after the rows (TableLayout).
     *
     * @return list<LayoutBox>
     */
    private static function children(LayoutBox $box): array
    {
        $d = $box->getDisplay();
        if ($d !== 'table' && $d !== 'inline-table') {
            return $box->children;
        }
        $kept = [];
        $bottom = [];
        foreach ($box->children as $child) {
            if (($child->tagName === 'caption' || $child->getDisplay() === 'table-caption')
                && strtolower(trim($child->style->get('caption-side', 'top'))) === 'bottom') {
                $bottom[] = $child;
            } else {
                $kept[] = $child;
            }
        }
        return $bottom === [] ? $box->children : array_merge($kept, $bottom);
    }

    /**
     * Whether $box was laid out for page edges other than where it ended up (it was moved afterwards).
     */
    private function movedSinceLayout(LayoutBox $box, bool $rtl): bool
    {
        if ($this->used === null || !isset($this->used[$box])) {
            return false;
        }
        $rel = $this->used[$box];
        return $rtl ? abs($rel[0] - ($this->left - $box->x)) > 0.5 : abs($rel[1] - ($this->right - $box->x)) > 0.5;
    }

    /** @return array{0: float, 1: float} the page edges relative to the box's x */
    private function anchor(LayoutBox $box): array
    {
        return [$this->left - $box->x, $this->right - $box->x];
    }

    /**
     * @param bool $fixable no clipping ancestor: the inline layout may break this box's text at the page edge
     */
    private function walk(LayoutBox $box, bool $fixable): void
    {
        if (!$box->isTextNode()) {
            $tf = strtolower(trim($box->style->get('transform', 'none')));
            if ($tf !== 'none' && $tf !== '') {
                return; // painted elsewhere than laid out (and never broken at the page edge)
            }
            if (in_array(strtolower(trim($box->style->get('overflow', 'visible'))), ['hidden', 'clip', 'auto', 'scroll'], true)) {
                if ($box->x >= $this->left - 0.5 && $box->x + $box->width <= $this->right + 0.5) {
                    return; // clipped inside the page: nothing of it can reach the page edge
                }
                $fixable = false;
            }
            if ($fixable && $box->lines === [] && $box->x + $box->width > $this->right + 0.5 && $this->movedSinceLayout($box, false)) {
                // a table fitted to the page edge where the table layout expected it to be, then moved past it
                $this->needed[implode('.', $this->path)] = $this->anchor($box);
            }
        }
        if ($box->lines !== []) {
            $this->scanLines($box, $fixable);
        }
        foreach (self::children($box) as $i => $child) {
            $this->path[] = $i;
            $this->walk($child, $fixable);
            array_pop($this->path);
        }
    }

    private function scanLines(LayoutBox $box, bool $fixable): void
    {
        $rtl = strtolower(trim($box->style->get('direction', 'ltr'))) === 'rtl';
        $need = false;
        foreach ($box->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->edgeBreak) {
                    $this->broken++;
                }
                if ($run->inlineBox !== null || $run->isWhitespace || trim($run->text) === '') {
                    continue;
                }
                $out = $rtl ? $run->x < $this->left - 0.5 : $run->x + $run->width > $this->right + 0.5;
                if (!$out || strtolower(trim($run->style->get('visibility', 'visible'))) !== 'visible') {
                    continue;
                }
                $this->lost++;
                $need = $need || $fixable;
            }
        }
        // Text still past the edge, or broken for a position the box did not keep: lay it out again with the edges
        // where the box really is (unless it already was laid out with exactly those)
        $used = $this->used !== null && isset($this->used[$box]) ? $this->used[$box] : null;
        $anchor = $this->anchor($box);
        $same = $used !== null && abs($used[$rtl ? 0 : 1] - $anchor[$rtl ? 0 : 1]) <= 0.5;
        if (($need || $used !== null) && !$same && ($fixable || $used !== null)) {
            $this->needed[implode('.', $this->path)] = $anchor;
        }
    }
}
