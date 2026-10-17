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
    private bool $firstPageLeft = false;

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
        $pages = (int) min(1.0e9, ceil(($totalContentSpan - 0.5) / $this->printableHeight)); // INF heights: no int-cast warning

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
    /** Box that enforcePageName() just moved to the top of a new page: its own break-before is already satisfied */
    private ?LayoutBox $brokenForPageName = null;

    private function enforcePageName(LayoutBox $box, ?string $required): void
    {
        $k = $this->getPageIndexForY($box->y);
        if ($this->getPageName($k) === $required) {
            return;
        }
        [$pageTop, $pageBottom] = $this->getPageBounds($k);
        if ($box->y - $this->leadingSpace($box) <= $pageTop + 1.0) {
            if ($k > 0) {
                $this->setPageName($k, $required);
            }
            return;
        }
        $this->pushBoxAndFollowing($box, $this->forcedBreakDelta($box, $pageBottom));
        $this->setPageName($k + 1, $required);
        $this->brokenForPageName = $box;
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
        return (int) max(-1.0e9, min(1.0e9, floor(($y - $this->topMargin + 0.01) / $this->printableHeight)));
    }

    private function processBox(LayoutBox $box): void
    {
        \IvoPdf\RenderLimits::tick();
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
        $breakBefore = strtolower(trim($box->style->get('page-break-before', 'auto')));
        $beforeSide = $this->breakSide($breakBefore);
        if ($breakBefore === 'always' || $breakBefore === 'page' || $beforeSide !== null) {
            $curPage = $this->getPageIndexForY($box->y);
            [$pageTop, $pageBottom] = $this->getPageBounds($curPage);
            // A box that merely carries its top margin below the page top is already at the start of the page,
            // and so is one that a page-name change (mPDF <pagebreak orientation>) has just moved to a new page
            if ($this->brokenForPageName !== $box && $box->y - $this->leadingSpace($box) > $pageTop + 1.0) {
                $target = $curPage + 1;
            } else {
                $target = $curPage;
            }
            // left / right / recto / verso: the box must start on a page of that side (page 1 is a right page)
            if ($beforeSide !== null && $target % 2 !== $beforeSide) {
                $target++;
            }
            if ($target !== $curPage) {
                $this->pushBoxAndFollowing($box, $this->forcedBreakDelta($box, $this->getPageBounds($target)[0]));
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
            // Lines after a page break owe the break's shift; it is applied when the loop reaches them instead of
            // re-translating the whole tail at every break (that is quadratic for 20 000 <br>)
            $carry = 0.0;
            $applied = -1;
            $shiftChildren = false;
            foreach ($box->children as $child) {
                if (!$child->isPositionedOutOfFlow() && !$child->isInlineLevel()) {
                    $shiftChildren = true;
                    break;
                }
            }
            for ($i = 0; $i < $count; $i++) {
                $line = $box->lines[$i];
                if ($carry !== 0.0 && $i > $applied) {
                    $line->translate(0.0, $carry);
                }
                if ($i > $applied) {
                    $applied = $i;
                }
                $startPage = $this->getPageIndexForY($line->y);
                [$pageTop, $pageBottom] = $this->getPageBounds($startPage);

                $forced = $line->forceBreak && $line->y > $pageTop + 0.5;
                if (($forced || $line->y + $line->height > $pageBottom + 0.5) && $line->y > $pageTop + 0.5) {
                    if (!$forced && $i > 0) {
                        $i = $this->adjustBreakForOrphansWidows($box, $i, $pageTop, $startPage);
                        $line = $box->lines[$i];
                    }
                    $delta = $pageBottom - $line->y;
                    for ($j = $i; $j <= $applied; $j++) {
                        $box->lines[$j]->translate(0.0, $delta);
                    }
                    $carry += $delta;
                    $this->pushDescendantsAndFollowingBelow($box, $line->y - $delta, $delta, $shiftChildren);
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
        $breakAfter = strtolower(trim($box->style->get('page-break-after', 'auto')));
        $afterSide = $this->breakSide($breakAfter);
        if ($afterSide !== null) {
            // The following content starts on the next page of the requested side (an empty page is skipped over if needed)
            $bottomY = $box->y + $box->height;
            $target = $this->getPageIndexForY(max($this->topMargin, $bottomY - 0.5)) + 1;
            if ($target % 2 !== $afterSide) {
                $target++;
            }
            $this->pushFollowingOnly($box, $this->getPageBounds($target)[0] - $bottomY);
        } elseif ($breakAfter === 'always' || $breakAfter === 'page') {
            $bottomY = $box->y + $box->height;
            // A box that ends exactly at the page bottom (a full-page label or cover) belongs to that page: its
            // bottom edge is not the top of the next page, so no blank page follows (as in browsers). An empty
            // carrier at the very top of a page still breaks after itself.
            $curPage = $this->getPageIndexForY(max($this->topMargin, $box->y, $bottomY - 0.5));
            [$pageTop, $pageBottom] = $this->getPageBounds($curPage);
            if ($bottomY < $pageBottom - 1.0) {
                $delta = $pageBottom - $bottomY;
                $this->pushFollowingOnly($box, $delta);
            }
        }
    }

    /**
     * `orphans` / `widows` (initial value 2, as in browsers): moves a break before line $i of $box up so at least
     * `orphans` lines of the paragraph stay on the page before it and at least `widows` lines follow it; when both
     * cannot hold, the whole paragraph goes to the next page if it starts on this page below its top, otherwise the
     * break stays where it is. Returns the index of the first line of the next page.
     */
    private function adjustBreakForOrphansWidows(LayoutBox $box, int $i, float $pageTop, int $startPage): int
    {
        $lines = $box->lines;
        $count = count($lines);
        $first = $i; // first line of the paragraph on this page
        while ($first > 0 && $lines[$first - 1]->y > $pageTop - 0.5) {
            $first--;
        }
        $o = trim($box->style->get('orphans', '2'));
        $w = trim($box->style->get('widows', '2'));
        $orphans = ctype_digit($o) ? max(1, min(1000, (int) $o)) : 2;
        $widows = ctype_digit($w) ? max(1, min(1000, (int) $w)) : 2;
        $k = $i;
        if ($count - $k < $widows && $count - $widows > $first) {
            $tail = 0.0;
            for ($j = $count - $widows; $j < $count; $j++) {
                $tail += $lines[$j]->height;
            }
            if ($tail <= $this->printableHeight) {
                $k = $count - $widows; // the last lines must not be left alone on the next page
            }
        }
        if ($k - $first < $orphans) {
            if ($first === 0 && $lines[0]->y > $pageTop + 0.5 && $this->getPageIndexForY($lines[0]->y) === $startPage) {
                return 0;
            }
            return $i;
        }
        return $k;
    }

    /**
     * Shift that moves $box to the top of a page starting at $pageTop after a forced break. Unlike at an
     * unforced break, the margin after a forced break is kept (as browsers do): the box's own top margin, or for
     * an empty break carrier (`<div style="page-break-before:always"></div>`) the top margin of the next box.
     */
    private function forcedBreakDelta(LayoutBox $box, float $pageTop): float
    {
        if ($this->isEmptyBreakCarrier($box)) {
            $next = $this->nextInFlowSibling($box);
            if ($next !== null && $this->syncedY($next) >= $box->y - 0.01) {
                return max(0.0, $pageTop + max(0.0, $next->marginTop, $next->collapsedMarginTop) - $next->y);
            }
        }
        return $pageTop + max(0.0, $box->marginTop, $box->collapsedMarginTop) - $box->y;
    }

    /** Current y of a box that may still owe a lazy shift (see pushBoxAndFollowing()). */
    private function syncedY(LayoutBox $box): float
    {
        $this->sync($box);
        return $box->y;
    }

    /** An empty block (`<div style="page-break-before:always"></div>`, mPDF <pagebreak>): no content, no edges. */
    private function isEmptyBreakCarrier(LayoutBox $box): bool
    {
        return $box->height <= 0.01 && $box->lines === [] && $box->paddingTop + $box->borderTop <= 0.0;
    }

    /**
     * Space above a box that holds no content: its top margin plus, while it is the first in-flow child, the
     * margins, borders and padding of its ancestors (a forced break there is a break before the ancestor).
     */
    private function leadingSpace(LayoutBox $box): float
    {
        $space = max(0.0, $box->marginTop, $box->collapsedMarginTop); // margins escaped from its first child collapse into it
        if ($this->isEmptyBreakCarrier($box) && ($next = $this->nextInFlowSibling($box)) !== null && $this->syncedY($next) - $box->y < 0.5) {
            // the next box's top margin collapsed through this empty box and lies above it
            $space = max($space, $next->marginTop, $next->collapsedMarginTop);
        }
        $cur = $box;
        for ($parent = $box->parent; $parent !== null; $cur = $parent, $parent = $parent->parent) {
            $first = null;
            foreach ($parent->children as $c) {
                if ($c->isPositionedOutOfFlow() || ($c->isTextNode() && trim((string) $c->textContent) === '')) {
                    continue;
                }
                $first = $c;
                break;
            }
            if ($first !== $cur || $parent->lines !== []) {
                break;
            }
            $space += $parent->paddingTop + $parent->borderTop + max(0.0, $parent->marginTop, $parent->collapsedMarginTop);
        }
        return $space;
    }

    /**
     * Page side demanded by a `left` / `right` / `recto` / `verso` forced break: 1 = left page (odd 0-based index),
     * 0 = right page, null = not a side break. Page 1 is a right (recto) page.
     */
    private function breakSide(string $value): ?int
    {
        $side = match ($value) {
            'left', 'verso' => 1,
            'right', 'recto' => 0,
            default => null,
        };
        // Right-to-left page progression: page 1 is a left page, so the parities swap
        return $side !== null && $this->firstPageLeft ? 1 - $side : $side;
    }

    /** Page 1 is a left page (root element direction rtl): `left`/`verso` fall on even indexes, `right`/`recto` on odd ones */
    public function setFirstPageLeft(bool $left): void
    {
        $this->firstPageLeft = $left;
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
        $tfoot = null;
        /** @var list<LayoutBox> $rows */
        $rows = [];
        /** @var list<LayoutBox> $footRows */
        $footRows = [];

        // TableLayout places <tfoot> visually last wherever it is written: paginate in that same order
        $footGroups = [];
        $otherChildren = [];
        $bottomCaptions = [];
        foreach ($table->children as $child) {
            if ($child->tagName === 'tfoot' || $child->getDisplay() === 'table-footer-group') {
                $footGroups[] = $child;
            } elseif (($child->tagName === 'caption' || $child->getDisplay() === 'table-caption')
                && strtolower(trim($child->style->get('caption-side', 'top'))) === 'bottom') {
                $bottomCaptions[] = $child; // caption-side: bottom follows the last row (and the footer) across page breaks
            } else {
                $otherChildren[] = $child;
            }
        }
        if ($footGroups !== [] || $bottomCaptions !== []) {
            $table->children = array_merge($otherChildren, $footGroups, $bottomCaptions);
        }

        foreach ($table->children as $child) {
            $tag = $child->tagName;
            $disp = $child->getDisplay();
            if ($tag === 'thead' || $disp === 'table-header-group') {
                $thead = $child;
            } elseif ($tag === 'tfoot' || $disp === 'table-footer-group') {
                $tfoot ??= $child;
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $footRows[] = $r;
                    }
                }
            } elseif ($tag === 'tbody' || $disp === 'table-row-group') {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $rows[] = $r;
                    }
                }
            } elseif ($tag === 'tr' || $disp === 'table-row') {
                $rows[] = $child;
            }
        }
        // Browsers print the footer group at the bottom of every page the table spans: reserve room for it
        $canRepeatTfoot = $tfoot !== null && count($footGroups) === 1 && $rows !== [] && $tfoot->height > 0.5
            && $tfoot->height < ($this->printableHeight * 0.3)
            && $tfoot->height + ($thead?->height ?? 0.0) < ($this->printableHeight * 0.5);
        $footReserve = $canRepeatTfoot ? $tfoot->height : 0.0;
        if (!$canRepeatTfoot) {
            array_push($rows, ...$footRows);
        }

        // A header that does not fit at the bottom of the page, or would stay there without any body row below it,
        // moves to the next page together with the whole table (browsers never leave an orphaned header)
        if ($thead !== null && $thead->height > 0.5 && $thead->height < $this->printableHeight * 0.35) {
            $this->sync($thead);
            $startPage = $this->getPageIndexForY($table->y);
            [$tPageTop, $tPageBottom] = $this->getPageBounds($startPage);
            $firstRow = $rows[0] ?? null;
            if ($firstRow !== null) {
                $this->sync($firstRow);
            }
            $headBottom = $thead->y + $thead->height;
            $needBottom = $firstRow !== null && $firstRow->height < $this->printableHeight * 0.5 ? $firstRow->y + $firstRow->height : $headBottom;
            if ($table->y > $tPageTop + 1.0 && max($headBottom, $needBottom) > $tPageBottom - $footReserve + 0.5) {
                $this->pushBoxAndFollowing($table, $this->getPageBounds($startPage + 1)[0] - $table->y);
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

            if ($row->height <= $this->printableHeight * 0.90 && ($row->y + $row->height > $pageBottom - $footReserve + 0.5) && ($row->y > $pageTop + 1.0)) {
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

        // Repeat <tfoot> right below the last row of every page except the one that holds the real footer
        if ($canRepeatTfoot && $tfoot !== null) {
            $this->sync($tfoot);
            $footTopOffset = max(0.0, $tfoot->y - $this->findMinTop($tfoot));
            $realPage = $this->getPageIndexForY($tfoot->y + 0.5);
            /** @var array<int, float> $pageBottoms */
            $pageBottoms = [];
            foreach ($rows as $row) {
                $this->sync($row);
                $pg = $this->getPageIndexForY($row->y + 0.5);
                $pageBottoms[$pg] = max($pageBottoms[$pg] ?? 0.0, $row->y + $row->height);
            }
            foreach ($pageBottoms as $pg => $bottom) {
                if ($pg >= $realPage) {
                    continue;
                }
                $clone = $this->deepCloneBox($tfoot);
                $clone->translate(0.0, $bottom + $footTopOffset - $clone->y);
                $table->addChild($clone);
            }
        }

        // Handle explicit page-break-after on the table itself
        $breakAfter = strtolower(trim($table->style->get('page-break-after', 'auto')));
        $afterSide = $this->breakSide($breakAfter);
        if ($afterSide !== null) {
            $bottomY = $table->y + $table->height;
            $target = $this->getPageIndexForY(max($this->topMargin, $bottomY - 0.5)) + 1;
            if ($target % 2 !== $afterSide) {
                $target++;
            }
            $this->pushFollowingOnly($table, $this->getPageBounds($target)[0] - $bottomY);
        } elseif ($breakAfter === 'always' || $breakAfter === 'page') {
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
    /** @var array<int, bool> spl_object_id => isPositionedOutOfFlow(): fixed during pagination, asked for every following sibling on every push */
    private array $outOfFlowMemo = [];

    private function outOfFlow(LayoutBox $box): bool
    {
        return $this->outOfFlowMemo[spl_object_id($box)] ??= $box->isPositionedOutOfFlow();
    }

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
                // numeric test first: the style lookups are the cost of this loop (it runs on every page)
                if ($foundCurr && $sibling->y + $sibling->pendingDy >= $thresholdY && !$this->outOfFlow($sibling)
                    && !($parent->lines !== [] && $sibling->isInlineLevel())) {
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
                $b = $ch->y + $ch->pendingDy + $ch->height + $ch->marginBottom + $parent->paddingBottom + $parent->borderBottom;
                if ($b > $maxChildBottom && !$this->outOfFlow($ch)) {
                    $maxChildBottom = $b;
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

    private function pushDescendantsAndFollowingBelow(LayoutBox $box, float $thresholdY, float $delta, bool $shiftChildren = true): void
    {
        if ($delta <= 0.01) {
            return;
        }
        foreach ($shiftChildren ? $box->children : [] as $child) {
            // Inline-level children are positioned through the (already shifted) line boxes: never shift them twice
            if (!$child->isPositionedOutOfFlow() && $child->y + $child->pendingDy >= $thresholdY && !($child->isInlineLevel() && !empty($box->lines))) {
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
                // numeric test first: the style lookups are the cost of this loop (it runs on every page)
                if ($foundCurr && $sibling->y + $sibling->pendingDy >= $thresholdY && !$this->outOfFlow($sibling)
                    && !($parent->lines !== [] && $sibling->isInlineLevel())) {
                    $sibling->pendingDy += $delta;
                }
            }

            // Inline content (anonymous text / inline boxes) of the parent that follows $curr moves with it
            foreach ($parent->lines as $line) {
                if ($line->y >= $thresholdY) {
                    $line->translate(0.0, $delta);
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
        if ($box === $this->root && $box->paddingBottom <= 0.0 && $box->borderBottom <= 0.0
            && in_array(strtolower(trim($box->style->get('height', 'auto'))), ['auto', ''], true)) {
            // The body's height ends with its last child's bottom margin: a trailing margin does not start a page
            $max = $box->y;
        }
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
