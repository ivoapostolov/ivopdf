<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Multi-column flow of a column container: holds the pieces of every segment (column segments split by
 * `column-span: all` elements) and distributes them over the columns, optionally page by page.
 */
final class ColumnFlow
{
    /** Height of the content area after the last placement */
    public float $contentHeight = 0.0;

    /**
     * @param list<array{span: bool, pieces: list<ColumnPiece>, height: float, tail: float, boxes: list<array{box: LayoutBox, pieces: list<ColumnPiece>, dx: float}>}> $segments
     */
    public function __construct(
        public int $count,
        public float $colWidth,
        public float $gap,
        public bool $balance,
        public ?float $fixedHeight,
        public array $segments
    ) {
    }

    /**
     * Places all pieces. With page geometry, columns fill up to each page bottom and continue on the next page;
     * only the final chunk is balanced.
     */
    public function place(LayoutBox $box, ?float $topMargin = null, ?float $pageHeight = null): void
    {
        $paged = $topMargin !== null && $pageHeight !== null && $pageHeight > 20.0;
        $x0 = $box->getContentX();
        $y0 = $box->getContentY();
        $y = $y0;
        $step = $this->colWidth + $this->gap;
        $rects = [];
        $chunkNo = 0;

        foreach ($this->segments as $si => $seg) {
            $pieces = $seg['pieces'];
            $n = count($pieces);
            if ($n === 0) {
                $y += $seg['height'];
                continue;
            }
            if ($seg['span']) {
                $p = $pieces[0];
                $top = $y + $p->ot;
                if ($paged) {
                    [$pt, $pb] = $this->pageBounds($top, (float) $topMargin, (float) $pageHeight);
                    if ($top + $p->h > $pb + 0.5 && $top > $pt + 0.5) {
                        $y += $pb - $top;
                        $top = $pb;
                    }
                }
                $p->moveTo($top, 0.0);
                $y += $seg['height'];
                $chunkNo++;
                continue;
            }

            $k = 0;
            while ($k < $n) {
                $avail = INF;
                $pageBottom = INF;
                if ($paged) {
                    [$pt, $pageBottom] = $this->pageBounds($y, (float) $topMargin, (float) $pageHeight);
                    $avail = $pageBottom - $y;
                    if ($pieces[$k]->h > $avail + 0.5 && $y > $pt + 0.5) {
                        $y = $pageBottom;
                        continue;
                    }
                }

                if (!$this->balance) {
                    $cap = $this->fixedHeight !== null ? min($avail, $this->fixedHeight) : $avail;
                    $cols = $this->greedy($pieces, $k, $cap, $this->count);
                } else {
                    $all = $this->greedy($pieces, $k, $avail, PHP_INT_MAX);
                    if (count($all) <= $this->count) {
                        $total = $pieces[$n - 1]->ot + $pieces[$n - 1]->h - $pieces[$k]->ot;
                        $lo = 0.0;
                        $hi = min($avail, $total);
                        for ($it = 0; $it < 40 && $hi - $lo > 0.02; $it++) {
                            $mid = ($lo + $hi) / 2.0;
                            if (count($this->greedy($pieces, $k, $mid, PHP_INT_MAX)) <= $this->count) {
                                $hi = $mid;
                            } else {
                                $lo = $mid;
                            }
                        }
                        $cols = $this->greedy($pieces, $k, $hi + 0.01, $this->count);
                    } else {
                        $cols = $this->greedy($pieces, $k, $avail, $this->count);
                    }
                }

                $chunkUsed = 0.0;
                foreach ($cols as [$s, $e]) {
                    $chunkUsed = max($chunkUsed, $pieces[$e - 1]->ot + $pieces[$e - 1]->h - $pieces[$s]->ot);
                }
                foreach ($cols as $ci => [$s, $e]) {
                    for ($i = $s; $i < $e; $i++) {
                        $pieces[$i]->moveTo($y + ($pieces[$i]->ot - $pieces[$s]->ot), $ci * $step);
                    }
                    $rects[] = ['x' => $x0 + $ci * $step, 'y' => $y, 'w' => $this->colWidth, 'h' => $chunkUsed, 'chunk' => $chunkNo, 'col' => $ci];
                }
                $k = $cols[count($cols) - 1][1];
                if ($k < $n) {
                    $y = $paged ? max($y + $chunkUsed, $pageBottom) : $y + $chunkUsed;
                    $chunkNo++;
                } else {
                    $y += $chunkUsed;
                }
            }
            $y += $seg['tail'];
            $chunkNo++;

            $this->segments[$si]['boxes'] = $this->fixBoxes($seg['boxes']);
        }

        $this->contentHeight = max(0.0, $y - $y0);
        $box->columnRects = $rects;
    }

    /**
     * Greedy column fill starting at piece $k with columns of height $hc; stops after $maxCols columns.
     *
     * @param list<ColumnPiece> $p
     * @return list<array{0: int, 1: int}> [start, endExclusive] per column
     */
    private function greedy(array $p, int $k, float $hc, int $maxCols): array
    {
        $n = count($p);
        $cols = [];
        $i = $k;
        while ($i < $n && count($cols) < $maxCols) {
            $s = $i;
            $i++;
            $byHeight = false;
            while ($i < $n) {
                if ($p[$i]->forceBefore || $p[$i - 1]->forceAfter) {
                    break;
                }
                if ($p[$i]->ot + $p[$i]->h - $p[$s]->ot > $hc + 0.01) {
                    $byHeight = true;
                    break;
                }
                $i++;
            }
            if ($byHeight) {
                // Keep headings (and `break-after: avoid` pieces) together with what follows
                while ($i - 1 > $s && $p[$i - 1]->keepNext) {
                    $i--;
                }
            }
            $cols[] = [$s, $i];
        }
        return $cols;
    }

    /**
     * Updates the bounds of the descended (non-atomic) boxes so they cover their relocated pieces.
     *
     * @param list<array{box: LayoutBox, pieces: list<ColumnPiece>, dx: float}> $boxes
     * @return list<array{box: LayoutBox, pieces: list<ColumnPiece>, dx: float}>
     */
    private function fixBoxes(array $boxes): array
    {
        foreach ($boxes as $idx => $entry) {
            $b = $entry['box'];
            $ps = $entry['pieces'];
            if ($ps === []) {
                continue;
            }
            $first = $ps[0];
            $maxBottom = $first->bottom();
            foreach ($ps as $p) {
                $maxBottom = max($maxBottom, $p->bottom());
            }
            $b->x += $first->dx - $entry['dx'];
            $boxes[$idx]['dx'] = $first->dx;
            $b->y = $first->top() - $b->borderTop - $b->paddingTop;
            $b->height = max(0.0, $maxBottom + $b->paddingBottom + $b->borderBottom - $b->y);
        }
        return $boxes;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function pageBounds(float $y, float $topMargin, float $pageHeight): array
    {
        $idx = $y <= $topMargin ? 0 : (int) floor(($y - $topMargin + 0.01) / $pageHeight);
        $start = $topMargin + $idx * $pageHeight;
        return [$start, $start + $pageHeight];
    }

    /**
     * Shifts everything that follows $box (siblings and the trailing content of its ancestors) by $delta
     * and grows/shrinks the ancestors accordingly.
     */
    public static function shiftFollowing(LayoutBox $box, float $oldBottom, float $delta): void
    {
        if (abs($delta) < 0.01) {
            return;
        }
        $threshold = $oldBottom - 0.5;
        $curr = $box;
        while ($curr->parent !== null) {
            $parent = $curr->parent;
            $found = false;
            foreach ($parent->children as $sibling) {
                if ($sibling === $curr) {
                    $found = true;
                    continue;
                }
                if ($found && !$sibling->isPositionedOutOfFlow() && $sibling->y >= $threshold
                    && !(!empty($parent->lines) && $sibling->isInlineLevel())) {
                    $sibling->translate(0.0, $delta);
                }
            }
            foreach ($parent->lines as $line) {
                if ($line->y >= $threshold) {
                    $line->translate(0.0, $delta);
                }
            }
            $parent->height += $delta;
            $curr = $parent;
        }
    }
}
