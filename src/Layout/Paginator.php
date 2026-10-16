<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Multi-page Paginator.
 * Ensures text lines, images, SVGs, table rows, headings, and `page-break-inside: avoid` blocks
 * are never sliced across page boundaries, repeats `<thead>` on multi-page tables,
 * and handles explicit `page-break-before` / `page-break-after`.
 */
class Paginator
{
    private float $topMargin;
    private float $printableHeight;

    /** Per-page geometry (null = uniform pages): fn(pageIndex, pageName): printable height */
    private ?\Closure $heightFn = null;
    /** @var list<float> */
    private array $pageHeights = [];
    /** @var array<int, float> Virtual top y of each page (index = page) */
    private array $pageTops = [];
    /** @var array<int, ?string> Named page set at a page index (inherited by following pages) */
    private array $pageNames = [];
    private ?string $flowName = null;
    private ?LayoutBox $root = null;

    public function __construct(float $topMargin, float $printableHeight)
    {
        $this->topMargin = $topMargin;
        $this->printableHeight = max(50.0, $printableHeight);
    }

    /**
     * Adjusts the layout tree in-place so atomic boxes/lines do not straddle page boundaries
     * and returns the total number of pages.
     */
    public function paginate(LayoutBox $root): int
    {
        $this->root = $root;
        $this->processBox($root);
        $this->flushAll($root);

        $maxBottom = $this->findMaxBottom($root);
        if ($this->heightFn !== null) {
            $k = 0;
            while ($k < 5000) {
                $this->ensurePage($k);
                if ($this->pageTops[$k + 1] >= $maxBottom - 0.5) {
                    break;
                }
                $k++;
            }
            return $k + 1;
        }
        $totalContentSpan = max(1.0, $maxBottom - $this->topMargin);
        $pages = (int) ceil(($totalContentSpan - 0.5) / $this->printableHeight);

        return max(1, $pages);
    }

    /**
     * Enables per-page geometry (first/left/right pages, named pages): $heightFn(pageIndex, pageName) returns the
     * printable height of that page. $firstPageName is the named page the document starts on (if any).
     *
     * @param \Closure(int, ?string): float $heightFn
     */
    public function setPageGeometry(\Closure $heightFn, ?string $firstPageName = null): void
    {
        $this->heightFn = $heightFn;
        $this->pageHeights = [];
        $this->pageTops = [0 => $this->topMargin];
        $this->pageNames = [0 => $firstPageName];
        $this->flowName = null;
    }

    /** Name of the `@page` rule in effect on a page (null = default). */
    public function getPageName(int $pageIndex): ?string
    {
        for ($q = $pageIndex; $q >= 0; $q--) {
            if (array_key_exists($q, $this->pageNames)) {
                return $this->pageNames[$q];
            }
        }
        return null;
    }

    /** 0-based page index containing the virtual y coordinate. */
    public function pageOfY(float $y): int
    {
        return $this->getPageIndexForY($y);
    }

    private function ensurePage(int $k): void
    {
        for ($j = count($this->pageHeights); $j <= $k; $j++) {
            $h = max(50.0, (float) ($this->heightFn)($j, $this->getPageName($j)));
            $this->pageHeights[$j] = $h;
            $this->pageTops[$j + 1] = $this->pageTops[$j] + $h;
        }
    }

    private function setPageName(int $k, ?string $name): void
    {
        $this->pageNames[$k] = $name;
        $this->pageHeights = array_slice($this->pageHeights, 0, $k);
        $this->pageTops = array_slice($this->pageTops, 0, $k + 1, true);
    }

    /**
     * Named pages (`page: name`): a box whose page name differs from the page it would start on begins a new page.
     */
    private function enforcePageName(LayoutBox $box, ?string $required): void
    {
        $k = $this->getPageIndexForY($box->y);
        if ($this->getPageName($k) === $required) {
            return;
        }
        [$pageTop, $pageBottom] = $this->getPageBounds($k);
        if ($box->y - max(0.0, $box->marginTop) <= $pageTop + 1.0) {
            if ($k > 0) {
                $this->setPageName($k, $required);
            }
            return;
        }
        $this->pushBoxAndFollowing($box, $pageBottom - $box->y);
        $this->setPageName($k + 1, $required);
    }

    /**
     * Returns the virtual Y start and end for a 0-based page index.
     *
     * @return array{0: float, 1: float} [pageTopY, pageBottomY]
     */
    public function getPageBounds(int $pageIndex): array
    {
        if ($this->heightFn !== null) {
            $this->ensurePage($pageIndex);
            return [$this->pageTops[$pageIndex], $this->pageTops[$pageIndex + 1]];
        }
        $start = $this->topMargin + ($pageIndex * $this->printableHeight);
        return [$start, $start + $this->printableHeight];
    }

    private function getPageIndexForY(float $y): int
    {
        if ($y <= $this->topMargin) {
            return 0;
        }
        if ($this->heightFn !== null) {
            for ($k = 0; $k < 5000; $k++) {
                $this->ensurePage($k);
                if ($y + 0.01 < $this->pageTops[$k + 1]) {
                    return $k;
                }
            }
            return 4999;
        }
        return (int) floor(($y - $this->topMargin + 0.01) / $this->printableHeight);
    }

    private function processBox(LayoutBox $box): void
    {
        $this->sync($box);
        if ($this->heightFn === null || $box->isPositionedOutOfFlow() || $box->isTextNode()) {
            $this->processBoxCore($box);
            return;
        }
        $decl = strtolower(trim($box->style->get('page', 'auto')));
        $prevFlow = $this->flowName;
        if ($decl !== '' && $decl !== 'auto') {
            $this->flowName = $decl;
        }
        $this->enforcePageName($box, $this->flowName);
        $this->processBoxCore($box);
        $this->flowName = $prevFlow;
    }

    private function processBoxCore(LayoutBox $box): void
    {
        if ($box->isPositionedOutOfFlow() || $box->isTextNode()) {
            return;
        }

        // Handle explicit page-break-before: always
        $breakBefore = strtolower($box->style->get('page-break-before', 'auto'));
        if ($breakBefore === 'always' || $breakBefore === 'page') {
            $curPage = $this->getPageIndexForY($box->y);
            [$pageTop, $pageBottom] = $this->getPageBounds($curPage);
            // A box that merely carries its top margin below the page top is already at the start of the page
            if ($box->y - max(0.0, $box->marginTop) > $pageTop + 1.0) {
                $delta = $pageBottom - $box->y;
                $this->pushBoxAndFollowing($box, $delta);
            }
        }

        // Check if this box itself is atomic (should not be split across pages if it fits on a single page)
        if ($this->isAtomicBox($box) && $box->height <= $this->printableHeight * 0.92) {
            $startPage = $this->getPageIndexForY($box->y);
            [$pageTop, $pageBottom] = $this->getPageBounds($startPage);
            if ($box->y + $box->height > $pageBottom + 0.5 && $box->y > $pageTop + 1.0) {
                $delta = $pageBottom - $box->y;
                $this->pushBoxAndFollowing($box, $delta);
            }
        }

        // Special handling for tables with <thead> so <thead> repeats at the top of each spanned page
        if ($box->tagName === 'table' || $box->getDisplay() === 'table') {
            $this->processTablePagination($box);
            return;
        }

        // Multi-column containers distribute their (already page-aware) columns themselves
        if ($box->columnFlow !== null) {
            $this->paginateColumns($box);
        }

        // Check inline lines inside this box so no LineBox is sliced across a page break
        if (!empty($box->lines) && $box->columnFlow === null) {
            $count = count($box->lines);
            for ($i = 0; $i < $count; $i++) {
                $line = $box->lines[$i];
                $startPage = $this->getPageIndexForY($line->y);
                [$pageTop, $pageBottom] = $this->getPageBounds($startPage);

                if ($line->y + $line->height > $pageBottom + 0.5 && $line->y > $pageTop + 0.5) {
                    // Avoid a single orphan line left at the bottom of the previous page
                    if ($i === 1 && $count > 2 && $box->lines[0]->y > $pageTop + 0.5 && $this->getPageIndexForY($box->lines[0]->y) === $startPage) {
                        $i = 0;
                        $line = $box->lines[0];
                    }
                    $delta = $pageBottom - $line->y;
                    for ($j = $i; $j < $count; $j++) {
                        $box->lines[$j]->translate(0.0, $delta);
                    }
                    $this->pushDescendantsAndFollowingBelow($box, $line->y - $delta, $delta);
                }
            }
        }

        // Recurse into children
        foreach ($box->columnFlow === null ? $box->children : [] as $child) {
            $this->processBox($child);
        }

        // Headings (and page-break-after: avoid) stay with the content that follows them
        $avoidAfter = strtolower($box->style->get('page-break-after', 'auto'));
        if (($avoidAfter === 'avoid' || preg_match('/^h[1-6]$/', $box->tagName)) && $box->parent !== null) {
            $next = $this->nextInFlowSibling($box);
            if ($next !== null) {
                $this->sync($next);
                $nextFirst = !empty($next->lines) ? $next->lines[0]->y + $next->lines[0]->height : $next->y + min($next->height, 28.0);
                $page = $this->getPageIndexForY($box->y);
                [$pageTop, $pageBottom] = $this->getPageBounds($page);
                if ($nextFirst > $pageBottom + 0.5 && $box->y > $pageTop + 1.0 && $box->height < $this->printableHeight * 0.5) {
                    $this->pushBoxAndFollowing($box, $pageBottom - $box->y);
                }
            }
        }

        // Handle explicit page-break-after: always
        $breakAfter = strtolower($box->style->get('page-break-after', 'auto'));
        if ($breakAfter === 'always' || $breakAfter === 'page') {
            $bottomY = $box->y + $box->height;
            $curPage = $this->getPageIndexForY($bottomY);
            [$pageTop, $pageBottom] = $this->getPageBounds($curPage);
            if ($bottomY < $pageBottom - 1.0) {
                $delta = $pageBottom - $bottomY;
                $this->pushFollowingOnly($box, $delta);
            }
        }
    }

    /**
     * Re-distributes a multi-column container's pieces over columns and pages now that its final position is known.
     */
    private function paginateColumns(LayoutBox $box): void
    {
        $flow = $box->columnFlow;
        if ($flow === null) {
            return;
        }
        if ($this->root !== null) {
            $this->flushAll($this->root); // ColumnFlow::shiftFollowing reads sibling positions directly
        }
        $oldBottom = $box->y + $box->height;
        $oldContent = $flow->contentHeight;
        $flow->place($box, $this->topMargin, $this->printableHeight);
        $delta = $flow->contentHeight - $oldContent;
        if (abs($delta) > 0.01) {
            $box->height += $delta;
            ColumnFlow::shiftFollowing($box, $oldBottom, $delta);
        }
    }

    /**
     * Paginates a `<table>` and repeats `<thead>` at the top of each new page when the table spans multiple pages.
     */
    private function processTablePagination(LayoutBox $table): void
    {
        $thead = null;
        /** @var list<LayoutBox> $rows */
        $rows = [];

        foreach ($table->children as $child) {
            $tag = $child->tagName;
            $disp = $child->getDisplay();
            if ($tag === 'thead' || $disp === 'table-header-group') {
                $thead = $child;
            } elseif ($tag === 'tbody' || $disp === 'table-row-group' || $tag === 'tfoot' || $disp === 'table-footer-group') {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $rows[] = $r;
                    }
                }
            } elseif ($tag === 'tr' || $disp === 'table-row') {
                $rows[] = $child;
            }
        }

        $tableStartPage = $this->getPageIndexForY($table->y);
        // Collapsed borders make header cells start half a border above the <thead> box
        $theadTopOffset = $thead !== null ? max(0.0, $thead->y - $this->findMinTop($thead)) : 0.0;
        $canRepeatThead = $thead !== null && $thead->height > 0.5 && $thead->height < ($this->printableHeight * 0.35);
        /** @var array<int, bool> $repeatedOnPage */
        $repeatedOnPage = [$tableStartPage => true];
        /** @var list<LayoutBox> $clonedHeaders */
        $clonedHeaders = [];

        foreach ($rows as $row) {
            $this->sync($row);
            $rowPage = $this->getPageIndexForY($row->y);
            [$pageTop, $pageBottom] = $this->getPageBounds($rowPage);

            // If this row already landed on a subsequent page where <thead> has not yet been repeated
            if ($canRepeatThead && $thead !== null && !isset($repeatedOnPage[$rowPage])) {
                $neededTop = $pageTop + $theadTopOffset + $thead->height;
                if ($row->y < $neededTop - 0.1) {
                    $this->pushBoxAndFollowing($row, $neededTop - $row->y);
                }
                $clone = $this->deepCloneBox($thead);
                $clone->translate(0.0, $pageTop + $theadTopOffset - $clone->y);
                $clonedHeaders[] = $clone;
                $repeatedOnPage[$rowPage] = true;
            }

            // Re-evaluate row position and check if it straddles the current page bottom
            $rowPage = $this->getPageIndexForY($row->y);
            [$pageTop, $pageBottom] = $this->getPageBounds($rowPage);

            if ($row->height <= $this->printableHeight * 0.90 && ($row->y + $row->height > $pageBottom + 0.5) && ($row->y > $pageTop + 1.0)) {
                $nextPage = $rowPage + 1;
                [$nextPageTop] = $this->getPageBounds($nextPage);

                if ($canRepeatThead && $thead !== null && !isset($repeatedOnPage[$nextPage])) {
                    $targetY = $nextPageTop + $theadTopOffset + $thead->height;
                    $this->pushBoxAndFollowing($row, $targetY - $row->y);

                    $clone = $this->deepCloneBox($thead);
                    $clone->translate(0.0, $nextPageTop + $theadTopOffset - $clone->y);
                    $clonedHeaders[] = $clone;
                    $repeatedOnPage[$nextPage] = true;
                } else {
                    $this->pushBoxAndFollowing($row, $nextPageTop - $row->y);
                }
            }
        }

        foreach ($clonedHeaders as $ch) {
            $table->addChild($ch);
        }

        // Handle explicit page-break-after on the table itself
        $breakAfter = strtolower($table->style->get('page-break-after', 'auto'));
        if ($breakAfter === 'always' || $breakAfter === 'page') {
            $bottomY = $table->y + $table->height;
            $curPage = $this->getPageIndexForY($bottomY);
            [, $pageBottom] = $this->getPageBounds($curPage);
            if ($bottomY < $pageBottom - 1.0) {
                $this->pushFollowingOnly($table, $pageBottom - $bottomY);
            }
        }
    }

    private function findMinTop(LayoutBox $box): float
    {
        $min = $box->y;
        foreach ($box->children as $child) {
            // Text nodes and inline content are positioned through line boxes, not their own coordinates
            if ($child->isTextNode() || ($child->isInlineLevel() && !empty($box->lines))) {
                continue;
            }
            $min = min($min, $this->findMinTop($child));
        }
        return $min;
    }

    private function nextInFlowSibling(LayoutBox $box): ?LayoutBox
    {
        $found = false;
        foreach ($box->parent?->children ?? [] as $sib) {
            if ($found && !$sib->isPositionedOutOfFlow() && !$sib->isTextNode()) {
                return $sib;
            }
            if ($sib === $box) {
                $found = true;
            }
        }
        return null;
    }

    private function deepCloneBox(LayoutBox $source): LayoutBox
    {
        $copy = new LayoutBox($source->tagName, clone $source->style, $source->domElement);
        $copy->x = $source->x;
        $copy->y = $source->y;
        $copy->width = $source->width;
        $copy->height = $source->height;
        $copy->marginTop = $source->marginTop;
        $copy->marginRight = $source->marginRight;
        $copy->marginBottom = $source->marginBottom;
        $copy->marginLeft = $source->marginLeft;
        $copy->paddingTop = $source->paddingTop;
        $copy->paddingRight = $source->paddingRight;
        $copy->paddingBottom = $source->paddingBottom;
        $copy->paddingLeft = $source->paddingLeft;
        $copy->borderTop = $source->borderTop;
        $copy->borderRight = $source->borderRight;
        $copy->borderBottom = $source->borderBottom;
        $copy->borderLeft = $source->borderLeft;
        $copy->fontSizePt = $source->fontSizePt;
        $copy->textContent = $source->textContent;
        $copy->imageSrc = $source->imageSrc;
        $copy->formControl = $source->formControl;
        $copy->formBaseline = $source->formBaseline;
        $copy->href = $source->href;
        $copy->listMarker = $source->listMarker;
        $copy->colspan = $source->colspan;
        $copy->rowspan = $source->rowspan;

        foreach ($source->lines as $line) {
            $lineCopy = new LineBox();
            $lineCopy->x = $line->x;
            $lineCopy->y = $line->y;
            $lineCopy->width = $line->width;
            $lineCopy->height = $line->height;
            $lineCopy->baselineOffset = $line->baselineOffset;
            foreach ($line->runs as $run) {
                $runCopy = clone $run;
                if ($run->inlineBox !== null) {
                    $runCopy->inlineBox = $this->deepCloneBox($run->inlineBox);
                }
                $lineCopy->addRun($runCopy);
            }
            $copy->lines[] = $lineCopy;
        }

        foreach ($source->children as $child) {
            $copy->addChild($this->deepCloneBox($child));
        }

        return $copy;
    }

    private function isAtomicBox(LayoutBox $box): bool
    {
        $breakInside = strtolower($box->style->get('page-break-inside', 'auto'));
        if ($breakInside === 'avoid') {
            return true;
        }
        if (in_array($box->tagName, ['img', 'svg', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Shifts $target and all boxes located vertically at or below $target->y in its ancestor chain down by $delta.
     */
    private function pushBoxAndFollowing(LayoutBox $target, float $delta): void
    {
        if ($delta <= 0.01) {
            return;
        }

        $this->sync($target);
        $thresholdY = $target->y - 0.1;
        $target->translate(0.0, $delta);

        $curr = $target;
        while ($curr->parent !== null) {
            $parent = $curr->parent;
            $foundCurr = false;

            foreach ($parent->children as $sibling) {
                if ($sibling === $curr) {
                    $foundCurr = true;
                    continue;
                }
                if ($foundCurr && !$sibling->isPositionedOutOfFlow() && $sibling->y + $sibling->pendingDy >= $thresholdY) {
                    $sibling->pendingDy += $delta; // applied lazily: O(1) instead of re-translating the whole remaining document
                }
            }

            foreach ($parent->lines as $line) {
                if ($line->y >= $thresholdY) {
                    $line->translate(0.0, $delta);
                }
            }

            // Expand parent height to enclose shifted children
            $maxChildBottom = $parent->y + $parent->height;
            foreach ($parent->children as $ch) {
                if (!$ch->isPositionedOutOfFlow()) {
                    $b = $ch->y + $ch->pendingDy + $ch->height + $ch->marginBottom + $parent->paddingBottom + $parent->borderBottom;
                    if ($b > $maxChildBottom) {
                        $maxChildBottom = $b;
                    }
                }
            }
            foreach ($parent->lines as $ln) {
                $b = $ln->y + $ln->height + $parent->paddingBottom + $parent->borderBottom;
                if ($b > $maxChildBottom) {
                    $maxChildBottom = $b;
                }
            }
            $parent->height = max($parent->height, $maxChildBottom - $parent->y);

            $curr = $parent;
        }
    }

    private function pushDescendantsAndFollowingBelow(LayoutBox $box, float $thresholdY, float $delta): void
    {
        if ($delta <= 0.01) {
            return;
        }
        foreach ($box->children as $child) {
            if (!$child->isPositionedOutOfFlow() && $child->y >= $thresholdY) {
                $child->translate(0.0, $delta);
            }
        }
        $box->height += $delta;
        $this->pushFollowingOnly($box, $delta);
    }

    private function pushFollowingOnly(LayoutBox $target, float $delta): void
    {
        if ($delta <= 0.01) {
            return;
        }
        $this->sync($target);
        $thresholdY = $target->y + $target->height - $delta - 0.1;
        $curr = $target;

        while ($curr->parent !== null) {
            $parent = $curr->parent;
            $foundCurr = false;

            foreach ($parent->children as $sibling) {
                if ($sibling === $curr) {
                    $foundCurr = true;
                    continue;
                }
                if ($foundCurr && !$sibling->isPositionedOutOfFlow() && $sibling->y + $sibling->pendingDy >= $thresholdY) {
                    $sibling->pendingDy += $delta;
                }
            }

            $parent->height += $delta;
            $curr = $parent;
        }
    }

    /**
     * Applies the lazily accumulated shifts of $box and its ancestors (top-down) so its coordinates are exact.
     */
    private function sync(LayoutBox $box): void
    {
        $chain = null;
        for ($a = $box; $a !== null; $a = $a->parent) {
            if ($a->pendingDy !== 0.0) {
                $chain[] = $a;
            }
        }
        if ($chain === null) {
            return;
        }
        foreach (array_reverse($chain) as $a) {
            $d = $a->pendingDy;
            $a->pendingDy = 0.0;
            $a->translate(0.0, $d);
        }
    }

    /** Applies every outstanding lazy shift in the tree. */
    private function flushAll(LayoutBox $root): void
    {
        $stack = [$root];
        while ($stack !== []) {
            $b = array_pop($stack);
            if ($b->pendingDy !== 0.0) {
                $d = $b->pendingDy;
                $b->pendingDy = 0.0;
                $b->translate(0.0, $d);
            }
            foreach ($b->children as $c) {
                $stack[] = $c;
            }
        }
    }

    private function findMaxBottom(LayoutBox $box): float
    {
        if ($box->isPositionedOutOfFlow() && strtolower($box->style->get('position')) === 'fixed') {
            return $this->topMargin;
        }

        $max = $box->y + $box->height;
        foreach ($box->lines as $line) {
            $b = $line->y + $line->height;
            if ($b > $max) {
                $max = $b;
            }
        }
        foreach ($box->children as $child) {
            $b = $this->findMaxBottom($child);
            if ($b > $max) {
                $max = $b;
            }
        }

        return $max;
    }
}
