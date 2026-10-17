<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use IvoPdf\Font\FontManager;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LineBox;
use IvoPdf\Layout\TextRun;

/**
 * Second pass over the paginated layout tree: replaces the fixed-width `target-counter()` placeholders with the real
 * page number of the target id, right-aligns them and stretches `leader()` runs over the remaining line width.
 */
final class PageRefResolver
{
    /** @var array<string, float> id / anchor name => virtual y of the target box */
    private array $targets = [];

    /** @var array<int, bool> */
    private array $seen = [];

    /** @var array<int, bool> boxes already walked (an atomic inline box is reachable both as a child and from a line run) */
    private array $visited = [];

    /** @var list<array{0: LayoutBox, 1: LineBox}> */
    private array $lines = [];

    /** @var \Closure(float): int */
    private \Closure $pageOfY;

    /**
     * @param \Closure(float): int $pageOfY maps a virtual y coordinate to its 0-based page index
     */
    public function __construct(private FontManager $fonts, \Closure $pageOfY)
    {
        $this->pageOfY = $pageOfY;
    }

    /**
     * Returns the number of resolved page references.
     */
    public function resolve(LayoutBox $root): int
    {
        $this->targets = [];
        $this->lines = [];
        $this->seen = [];
        $this->visited = [];
        $this->collect($root, $root);
        if ($this->lines === []) {
            return 0;
        }
        $count = 0;
        foreach ($this->lines as [$owner, $line]) {
            $count += $this->resolveLine($owner, $line);
        }
        return $count;
    }

    /**
     * Page number (1-based) of an element id, or null when unknown. Valid after resolve().
     */
    public function pageOfTarget(string $id): ?int
    {
        return isset($this->targets[$id]) ? ($this->pageOfY)($this->targets[$id]) + 1 : null;
    }

    /**
     * @return array<string, float> id / anchor name => virtual y (valid after resolve())
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    private function collect(LayoutBox $box, LayoutBox $owner): void
    {
        $oid = spl_object_id($box);
        if (isset($this->visited[$oid])) {
            return; // walking it again would double the work at every nesting level of inline-blocks
        }
        $this->visited[$oid] = true;
        if ($box->domElement !== null) {
            foreach (['id', 'name'] as $attr) {
                $v = trim($box->domElement->getAttribute($attr));
                if ($v !== '' && ($attr === 'id' || $box->tagName === 'a') && !isset($this->targets[$v])) {
                    $this->targets[$v] = $this->anchorY($box);
                }
            }
        }

        foreach ($box->lines as $line) {
            $has = false;
            foreach ($line->runs as $run) {
                if ($run->inlineBox !== null) {
                    $this->collect($run->inlineBox, $run->inlineBox);
                    continue;
                }
                if ($run->style->has('ivo-leader') || $run->style->has('ivo-pageref')) {
                    $has = true;
                }
            }
            if ($has && !isset($this->seen[spl_object_id($line)])) {
                $this->seen[spl_object_id($line)] = true;
                $this->lines[] = [$box, $line];
            }
        }

        foreach ($box->children as $child) {
            $this->collect($child, $child->isTextNode() ? $owner : $child);
        }
    }

    /** Inline anchors take the position of their containing block. */
    private function anchorY(LayoutBox $box): float
    {
        $b = $box;
        while ($b->isInlineLevel() && $b->parent !== null && !$b->isTextNode()) {
            $b = $b->parent;
        }
        return $b->y;
    }

    private function resolveLine(LayoutBox $owner, LineBox $line): int
    {
        $count = 0;
        $runs = $line->runs;
        $n = count($runs);
        $lastLeader = -1;

        // 1. Page numbers: real text, shrink the placeholder width and pull the following runs along
        $shift = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $run = $runs[$i];
            $run->x -= $shift;
            if ($run->style->has('ivo-leader')) {
                $lastLeader = $i;
            }
            if (!$run->style->has('ivo-pageref')) {
                continue;
            }
            $page = $this->pageOfTarget($run->style->get('ivo-pageref'));
            $text = $page !== null ? (string) $page : '';
            $newW = $this->measure($run, $text);
            $diff = $run->width - $newW;
            $run->text = $text;
            $run->width = $newW;
            $shift += $diff;
            $count++;
        }

        // 2. Leader: push everything after it to the right content edge and fill the gap
        if ($lastLeader >= 0) {
            $leader = $runs[$lastLeader];
            $containerRight = $owner->getContentX() + $owner->getContentWidth();
            if ($leader->style->has('ivo-leader-outdent')) {
                // mPDF <dottab outdent>: the tab stop (end of the tail text) is that far left of the right edge
                $containerRight -= max(0.0, (float) $leader->style->get('ivo-leader-outdent'));
            }
            $tailEnd = $leader->x + $leader->width;
            for ($i = $lastLeader + 1; $i < $n; $i++) {
                $tailEnd = max($tailEnd, $runs[$i]->x + $runs[$i]->width + $runs[$i]->paddingRight);
            }
            $delta = max(0.0, $containerRight - $tailEnd);
            for ($i = $lastLeader + 1; $i < $n; $i++) {
                $runs[$i]->x += $delta;
                if ($runs[$i]->inlineBox !== null) {
                    $runs[$i]->inlineBox->translate($delta, 0.0);
                }
            }
            $tailStart = $lastLeader + 1 < $n ? $runs[$lastLeader + 1]->x : $containerRight;
            $this->fillLeader($leader, $tailStart);
        }
        return $count;
    }

    private function measure(TextRun $run, string $text): float
    {
        return $this->fonts->measureText(
            $text,
            $run->style->get('font-family', 'DejaVu Sans'),
            $run->style->get('font-weight', 'normal'),
            $run->style->get('font-style', 'normal'),
            $run->fontSizePt,
            $run->letterSpacingPt
        );
    }

    /** Repeats the leader character so that it ends flush (minus a small gap) with the trailing text. */
    private function fillLeader(TextRun $leader, float $tailStart): void
    {
        $spec = $leader->style->has('ivo-leader') ? $leader->style->get('ivo-leader') : '.';
        $ch = $spec === 'space' ? "\u{00A0}" : $spec;
        $charW = $this->measure($leader, $ch);
        if ($charW < 0.1) {
            return;
        }
        $solid = $ch === '_' || $ch === '-' || $ch === "\u{2014}" || $ch === "\u{2500}";
        $spacing = $solid ? 0.0 : $charW * ($charW < 4.0 ? 1.0 : 0.6);
        $pitch = $charW + $spacing;
        $gap = 3.0;
        $avail = $tailStart - $gap - $leader->x;
        // a leader is never wider than a page: absurd geometry (outdent="-1e20") must not build a giant string
        $n = (int) max(0.0, min(2000.0, floor(($avail + $spacing) / $pitch)));
        if ($n < 1 || $spec === 'space') {
            $leader->text = $spec === 'space' ? "\u{00A0}" : '';
            $leader->width = max(0.0, $avail);
            return;
        }
        $used = $n * $pitch - $spacing;
        $leader->text = str_repeat($ch, $n);
        $leader->letterSpacingPt = $spacing;
        $leader->x += $avail - $used;
        $leader->width = $used;
        $leader->isWhitespace = false;
    }
}
