<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Full HTML & CSS Table Layout Engine.
 * Supports thead/tbody/tfoot/tr/th/td, colspan, rowspan, automatic column width balancing,
 * border-collapse, and vertical-align (top, middle, bottom).
 */
class TableLayout
{
    private LayoutEngine $engine;
    /** Sum of the columns' minimum content widths from the last calculateColumnWidths() run */
    private float $lastRequiredWidth = 0.0;

    public function __construct(LayoutEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Lays out a table container and returns its total content height.
     */
    public function layout(LayoutBox $table): float
    {
        TableShrink::clampToContainer($table, TableShrink::factorFor($table, $this->engine->getOptions()->shrinkTablesToFit));
        $borderCollapse = strtolower($table->style->get('border-collapse', 'collapse')) === 'collapse';
        if ($borderCollapse) {
            // Collapsed borders are centered on the grid lines: the table's own border is shared with the outer cells
            $contentX = $table->x + $table->borderLeft / 2.0;
            $contentY = $table->y + $table->borderTop / 2.0;
            $contentWidth = max(0.0, $table->width - ($table->borderLeft + $table->borderRight) / 2.0);
        } else {
            $contentX = $table->getContentX();
            $contentY = $table->getContentY();
            $contentWidth = $table->getContentWidth();
        }

        // <caption> boxes are laid out above the grid (caption-side: top) or below it (caption-side: bottom)
        $captionHeight = 0.0;
        $bottomCaptionHeight = 0.0;
        /** @var list<LayoutBox> $bottomCaptions */
        $bottomCaptions = [];
        foreach ($table->children as $child) {
            if ($child->tagName === 'caption' || $child->getDisplay() === 'table-caption') {
                // The caption box is outside the table's border box (CSS 2.1 17.4): as wide as the table, above or below it
                $this->engine->resolveBoxMetrics($child, $table->width);
                $child->forcedBorderBoxWidth = max(0.0, $table->width - $child->marginLeft - $child->marginRight);
                if (strtolower(trim($child->style->get('caption-side', 'top'))) === 'bottom') {
                    // positioned after the grid height is known (see below)
                    $this->engine->layoutElement($child, $table->x + $child->marginLeft, $table->y + $child->marginTop, $table->width);
                    $bottomCaptions[] = $child;
                    $bottomCaptionHeight += $child->getOuterHeight();
                    continue;
                }
                $this->engine->layoutElement($child, $table->x + $child->marginLeft, $table->y + $captionHeight + $child->marginTop, $table->width);
                $captionHeight += $child->getOuterHeight();
            }
        }
        $table->captionTop = $captionHeight;
        $table->captionBottom = $bottomCaptionHeight;
        if ($bottomCaptions !== []) {
            // Bottom captions come last in child order so pagination keeps them after the last row
            $kept = [];
            foreach ($table->children as $child) {
                if (!in_array($child, $bottomCaptions, true)) {
                    $kept[] = $child;
                }
            }
            $table->children = array_merge($kept, $bottomCaptions);
        }
        $contentY += $captionHeight;
        $spacingPt = $borderCollapse
            ? 0.0
            : $this->engine->resolveLength($table->style->get('border-spacing', '0'), $contentWidth, $table->fontSizePt);

        // Collect all table rows in visual order (thead -> tbody/direct tr -> tfoot)
        /** @var list<array{rowBox: LayoutBox, groupBox: ?LayoutBox}> $orderedRows */
        $orderedRows = [];
        $headRows = [];
        $bodyRows = [];
        $footRows = [];

        foreach ($table->children as $child) {
            $tag = $child->tagName;
            $disp = $child->getDisplay();
            if ($tag === 'thead' || $disp === 'table-header-group') {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $headRows[] = ['rowBox' => $r, 'groupBox' => $child];
                    }
                }
            } elseif ($tag === 'tfoot' || $disp === 'table-footer-group') {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $footRows[] = ['rowBox' => $r, 'groupBox' => $child];
                    }
                }
            } elseif ($tag === 'tbody' || $disp === 'table-row-group') {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $bodyRows[] = ['rowBox' => $r, 'groupBox' => $child];
                    }
                }
            } elseif ($tag === 'tr' || $disp === 'table-row') {
                $bodyRows[] = ['rowBox' => $child, 'groupBox' => null];
            }
        }

        $orderedRows = array_merge($headRows, $bodyRows, $footRows);
        if (empty($orderedRows)) {
            $this->placeBottomCaptions($bottomCaptions, $table->x, $contentY + $this->gridBottomEdge($table, $borderCollapse));
            return $captionHeight + $bottomCaptionHeight;
        }

        // Build 2D occupancy grid for cells (handling colspan & rowspan)
        /** @var array<int, array<int, bool>> $occupied */
        $occupied = [];
        /** @var list<CellPlacement> $cellPlacements */
        $cellPlacements = [];
        $numCols = 0;
        $numRows = count($orderedRows);
        $groupOfRow = [];
        foreach ($orderedRows as $rowEntry) {
            $groupOfRow[] = $rowEntry['groupBox'] === null ? -1 : spl_object_id($rowEntry['groupBox']);
        }

        foreach ($orderedRows as $rIdx => $rowEntry) {
            $rowBox = $rowEntry['rowBox'];
            $colCursor = 0;

            foreach ($rowBox->children as $cell) {
                if (!in_array($cell->tagName, ['td', 'th'], true) && $cell->getDisplay() !== 'table-cell') {
                    continue;
                }

                while (!empty($occupied[$rIdx][$colCursor])) {
                    $colCursor++;
                }

                $cSpan = max(1, min(100, $cell->colspan));
                $rSpan = CellPlacement::rowSpanOf($cell, $rIdx, $groupOfRow);

                for ($rr = $rIdx; $rr < $rIdx + $rSpan; $rr++) {
                    for ($cc = $colCursor; $cc < $colCursor + $cSpan; $cc++) {
                        $occupied[$rr][$cc] = true;
                    }
                }

                if ($rr > $numRows) {
                    $numRows = $rr;
                }
                if ($colCursor + $cSpan > $numCols) {
                    $numCols = $colCursor + $cSpan;
                }

                $cellPlacements[] = new CellPlacement($cell, $rIdx, $colCursor, $rSpan, $cSpan);

                $colCursor += $cSpan;
            }
        }

        if ($numCols === 0) {
            $this->placeBottomCaptions($bottomCaptions, $table->x, $contentY + $this->gridBottomEdge($table, $borderCollapse));
            return $captionHeight + $bottomCaptionHeight;
        }

        // Collapsed borders: the table's outer border is the widest of its own border and the outer cells' borders
        // (CSS 2.1 17.6.2); like browsers, the table box holds all of it, so the grid starts half of it inside
        $outer = null;
        if ($borderCollapse) {
            // Every cell takes the winning border of each of its edges (cell, neighbour, row, row group, table)
            $rowsByIdx = [];
            $groupsByIdx = [];
            foreach ($orderedRows as $ri => $entry) {
                $rowsByIdx[$ri] = $entry['rowBox'];
                $groupsByIdx[$ri] = $entry['groupBox'];
            }
            CollapsedBorders::apply($this->engine, $table, $cellPlacements, $rowsByIdx, $groupsByIdx, $numRows, $numCols, $contentWidth);
            $outer = TableMetrics::collapsedOuterBorders($this->engine, $table, $cellPlacements, $numRows, $numCols, $contentWidth);
            $contentX += ($outer['left'] - $table->borderLeft) / 2.0;
            $contentY += ($outer['top'] - $table->borderTop) / 2.0;
            $contentWidth = max(0.0, $contentWidth - ($outer['left'] - $table->borderLeft + $outer['right'] - $table->borderRight) / 2.0);
        }

        // Resolve column widths
        // Separated borders: border-spacing between the columns and between the outer columns and the table edge
        $totalHorizontalSpacing = ($numCols + 1) * $spacingPt;
        $usableWidth = max(0.0, $contentWidth - $totalHorizontalSpacing);
        $colElementWidths = $this->readColumnWidths($table, $numCols, $usableWidth);
        $colWidths = TableMetrics::isFixed($table)
            ? TableMetrics::fixedColumnWidths($this->engine, $cellPlacements, $numCols, $usableWidth, $colElementWidths)
            : $this->calculateColumnWidths($cellPlacements, $numCols, $usableWidth, $colElementWidths, $borderCollapse);

        // mPDF shrink_tables_to_fit: scale the content down (up to 1/factor) when the minimum content width overflows
        if (!TableMetrics::isFixed($table)) {
            $shrinkFactor = TableShrink::factorFor($table, $this->engine->getOptions()->shrinkTablesToFit);
            for ($pass = 0; $shrinkFactor > 1.0 && $pass < 3
                && TableShrink::shrink($table, $this->lastRequiredWidth, $usableWidth, $shrinkFactor); $pass++) {
                $colWidths = $this->calculateColumnWidths($cellPlacements, $numCols, $usableWidth, $colElementWidths, $borderCollapse);
            }
        }

        // Options::$pageOverflowWrap: columns widen to their longest word only up to the edge of the page; the
        // widest columns are narrowed so that the table ends there, and their words break at the cell edge
        $narrowed = TableMetrics::isFixed($table) ? [] : $this->fitColumnsToPage($table, $colWidths, $contentX, $contentWidth, $totalHorizontalSpacing);

        // Columns never get narrower than their content minimum: a table whose words do not fit grows past its
        // width (and its container), as in browsers, instead of letting the words overlap the next cells
        $gridW = array_sum($colWidths) + $totalHorizontalSpacing;
        if (!TableMetrics::isFixed($table) && $gridW > $contentWidth + 0.01) {
            $table->width += $gridW - $contentWidth;
            $contentWidth = $gridW;
        }

        // Compute X offset for each column
        $colOffsets = [];
        $accX = $contentX + $spacingPt;
        for ($c = 0; $c < $numCols; $c++) {
            $colOffsets[$c] = $accX;
            $accX += $colWidths[$c] + $spacingPt;
        }

        // Layout each cell inside its spanned column width to determine row heights
        $rtl = strtolower(trim($table->style->get('direction', 'ltr'))) === 'rtl';
        $rowHeights = array_fill(0, $numRows, 0.0);
        foreach ($orderedRows as $rIdx => $rowEntry) {
            $rBox = $rowEntry['rowBox'];
            $explicitRowH = $this->engine->getExplicitContentHeight($rBox, $contentWidth);
            if ($explicitRowH !== null && $explicitRowH > 0) {
                $rowHeights[$rIdx] = $explicitRowH;
            }
        }

        foreach ($cellPlacements as $cp) {
            $cell = $cp->cell;
            $cIdx = $cp->colIdx;
            $cSpan = $cp->colSpan;

            $cellW = 0.0;
            for ($c = $cIdx; $c < $cIdx + $cSpan; $c++) {
                $cellW += $colWidths[$c] ?? 0.0;
            }
            if ($cSpan > 1) {
                $cellW += ($cSpan - 1) * $spacingPt;
            }
            $cp->cellWidth = $cellW;

            $this->engine->resolveBoxMetrics($cell, $cellW);
            $collapseExtraW = $borderCollapse ? ($cell->borderLeft + $cell->borderRight) / 2.0 : 0.0;
            $cell->forcedBorderBoxWidth = $cellW + $collapseExtraW;
            $cell->forcedBorderBoxHeight = null;
            // Laid out at x = 0, moved to its column below: the inline layout learns the move (page-edge breaking)
            $frameDx = $this->cellX($cp, $colOffsets, $contentX, $contentWidth, $rtl, $borderCollapse) - $cell->marginLeft;
            $narrow = false;
            for ($c = $cIdx; $c < $cIdx + $cSpan; $c++) {
                $narrow = $narrow || isset($narrowed[$c]);
            }
            $this->engine->frameDx += $frameDx;
            $this->engine->boxEdgeDepth += $narrow ? 1 : 0;
            try {
                $this->engine->layoutElement($cell, 0.0, 0.0, $cellW);
            } finally {
                $this->engine->frameDx -= $frameDx;
                $this->engine->boxEdgeDepth -= $narrow ? 1 : 0;
            }

            if ($cp->rowSpan === 1) {
                $rIdx = $cp->rowIdx;
                $needH = $cell->height - ($borderCollapse ? ($cell->borderTop + $cell->borderBottom) / 2.0 : 0.0);
                if ($needH > $rowHeights[$rIdx]) {
                    $rowHeights[$rIdx] = $needH;
                }
            }
        }
        unset($cp);

        // Accommodate multi-row (rowspan > 1) cells
        foreach ($cellPlacements as $cp) {
            if ($cp->rowSpan > 1) {
                $rStart = $cp->rowIdx;
                $rSpan = $cp->rowSpan;
                $spanH = ($rSpan - 1) * $spacingPt;
                for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                    $spanH += $rowHeights[$r];
                }
                $needed = $cp->cell->height - $spanH - ($borderCollapse ? ($cp->cell->borderTop + $cp->cell->borderBottom) / 2.0 : 0.0);
                if ($needed > 0.01) {
                    $add = $needed / $rSpan;
                    for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                        $rowHeights[$r] += $add;
                    }
                }
            }
        }

        // A table taller than its rows (`height`, or stretched as a flex / grid item): the rows grow to fill it
        $edges = $outer !== null ? ($outer['top'] + $outer['bottom']) / 2.0 : $table->getVerticalSpacing();
        $rowHeights = $this->fillTableHeight($table, $orderedRows, $rowHeights, $contentWidth,
            $captionHeight + $bottomCaptionHeight + $edges + ($numRows + 1) * $spacingPt);

        // Compute Y offset for each row and set rowBox & groupBox coordinates
        $rowOffsets = [];
        $accY = $contentY + $spacingPt;
        for ($r = 0; $r < $numRows; $r++) {
            $rowOffsets[$r] = $accY;
            if (isset($orderedRows[$r])) {
                $rBox = $orderedRows[$r]['rowBox'];
                $rBox->x = $contentX;
                $rBox->y = $accY;
                $rBox->width = $contentWidth;
                $rBox->height = $rowHeights[$r];
            }
            $accY += $rowHeights[$r] + $spacingPt;
        }

        // Update groupBox (thead, tbody, tfoot) coordinates
        $groupBounds = [];
        foreach ($orderedRows as $rIdx => $rowEntry) {
            $gBox = $rowEntry['groupBox'];
            if ($gBox !== null) {
                $oid = spl_object_id($gBox);
                $rY = $rowOffsets[$rIdx];
                $rBottom = $rY + $rowHeights[$rIdx];
                if (!isset($groupBounds[$oid])) {
                    $groupBounds[$oid] = ['box' => $gBox, 'minY' => $rY, 'maxBottom' => $rBottom];
                } else {
                    $groupBounds[$oid]['minY'] = min($groupBounds[$oid]['minY'], $rY);
                    $groupBounds[$oid]['maxBottom'] = max($groupBounds[$oid]['maxBottom'], $rBottom);
                }
            }
        }
        foreach ($groupBounds as $gb) {
            /** @var LayoutBox $gBox */
            $gBox = $gb['box'];
            $gBox->x = $contentX;
            $gBox->y = $gb['minY'];
            $gBox->width = $contentWidth;
            $gBox->height = $gb['maxBottom'] - $gb['minY'];
        }

        // Position cells and apply vertical-align (top | middle | bottom)
        foreach ($cellPlacements as $cp) {
            $cell = $cp->cell;
            $rStart = $cp->rowIdx;
            $rSpan = $cp->rowSpan;

            $cellX = $this->cellX($cp, $colOffsets, $contentX, $contentWidth, $rtl, false);
            $cellY = $rowOffsets[$rStart] ?? $contentY;

            $targetHeight = ($rSpan - 1) * $spacingPt;
            for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                $targetHeight += $rowHeights[$r] ?? 0.0;
            }

            if ($borderCollapse) {
                $cellX -= $cell->borderLeft / 2.0;
                $cellY -= $cell->borderTop / 2.0;
                $targetHeight += ($cell->borderTop + $cell->borderBottom) / 2.0;
            }
            $naturalHeight = $cell->height;
            $cell->translate($cellX - $cell->x, $cellY - $cell->y);
            $cell->height = max($naturalHeight, $targetHeight);

            $vAlign = strtolower($cell->style->get('vertical-align', 'middle'));
            // Free space below the content (a cell with an explicit height has it too, not only a cell in a taller row)
            $contentBottom = self::contentBottom($cell);
            $extraVertical = $contentBottom === null
                ? max(0.0, $cell->height - $naturalHeight)
                : max(0.0, ($cell->y + $cell->height - $cell->paddingBottom - $cell->borderBottom) - $contentBottom);
            if ($extraVertical > 0.1 && ($vAlign === 'middle' || $vAlign === 'bottom')) {
                $shiftY = ($vAlign === 'middle') ? ($extraVertical / 2.0) : $extraVertical;
                foreach ($cell->lines as $line) {
                    $line->translate(0.0, $shiftY);
                }
                foreach ($cell->children as $child) {
                    $child->translate(0.0, $shiftY);
                }
            }
        }

        $totalHeight = 0.0;
        for ($r = 0; $r < $numRows; $r++) {
            $totalHeight += $rowHeights[$r];
        }
        $totalHeight += ($numRows + 1) * $spacingPt;
        $this->placeBottomCaptions($bottomCaptions, $table->x, $contentY + $totalHeight + ($outer !== null ? $outer['bottom'] / 2.0 : $this->gridBottomEdge($table, $borderCollapse)));
        $totalHeight += $captionHeight + $bottomCaptionHeight;

        if ($outer !== null) {
            // The cells paint the resolved borders, the table's own ones took part in that resolution: the table
            // paints none (browsers draw each collapsed border once)
            $table->borderTop = 0.0;
            $table->borderRight = 0.0;
            $table->borderBottom = 0.0;
            $table->borderLeft = 0.0;
            // LayoutEngine adds the table's own padding; a collapsed table has no padding and its border box ends
            // half of the outer collapsed border beyond the first and last grid lines
            return max(0.0, $totalHeight + ($outer['top'] + $outer['bottom']) / 2.0 - $table->paddingTop - $table->paddingBottom);
        }
        return max(0.0, $totalHeight);
    }

    /**
     * Left edge of a cell's column span (direction: rtl: the first column is the rightmost one); a collapsed-border cell
     * starts half of its left border before it.
     *
     * @param array<int, float> $colOffsets
     */
    private function cellX(CellPlacement $cp, array $colOffsets, float $contentX, float $contentWidth, bool $rtl, bool $collapseShift): float
    {
        $cellX = $colOffsets[$cp->colIdx] ?? $contentX;
        if ($rtl) {
            $cellX = $contentX + $contentWidth - ($cellX - $contentX) - $cp->cellWidth;
        }
        return $collapseShift ? $cellX - $cp->cell->borderLeft / 2.0 : $cellX;
    }

    /**
     * Options::$pageOverflowWrap: when the columns (widened to their longest words) would carry the table past the
     * right edge of the page, the widest columns are narrowed - never below the table's own width - so that it ends
     * there. Returns the narrowed column indexes (their cells then break overlong words at the cell edge).
     *
     * @param array<int, float> $colWidths
     * @return array<int, true>
     */
    private function fitColumnsToPage(LayoutBox $table, array &$colWidths, float $contentX, float $contentWidth, float $spacing): array
    {
        $edge = $this->engine->pageEdgeFor($table, $table);
        if ($edge === null || $colWidths === []) {
            return [];
        }
        $sum = array_sum($colWidths);
        $gridW = $sum + $spacing;
        if ($gridW <= $contentWidth + 0.01) {
            return []; // the columns fit the table's own width
        }
        $this->engine->markEdgeUsed($table, $table, $edge); // PageOverflow checks that the table ends up where expected
        $outside = max(0.0, $table->x + $table->width - ($contentX + $contentWidth)); // right padding + border
        $room = $edge[1] - $contentX - $outside;
        if ($gridW <= $room + 0.5) {
            return [];
        }
        $target = max($contentWidth, $room) - $spacing;
        if ($target <= 1.0 || $target >= $sum) {
            return [];
        }
        // The widest columns give up width first: all columns wider than the level $c are cut to $c
        $lo = 0.0;
        $hi = max($colWidths);
        for ($i = 0; $i < 50; $i++) {
            $c = ($lo + $hi) / 2.0;
            $s = 0.0;
            foreach ($colWidths as $w) {
                $s += min($w, $c);
            }
            if ($s > $target) {
                $hi = $c;
            } else {
                $lo = $c;
            }
        }
        $narrowed = [];
        foreach ($colWidths as $i => $w) {
            if ($w > $lo + 0.01) {
                $colWidths[$i] = $lo;
                $narrowed[$i] = true;
            }
        }
        return $narrowed;
    }

    /**
     * Distributes the height a table has beyond its rows (`height` / `min-height`, or a stretched flex / grid item:
     * forcedBorderBoxHeight) over the rows like Blink: to the rows without an explicit height, in proportion to their
     * heights (equally when they are all empty); when every row has an explicit height, over all rows.
     *
     * @param list<array{rowBox: LayoutBox, groupBox: ?LayoutBox}> $orderedRows
     * @param array<int, float> $rowHeights
     * @param float $outside border-box height outside the rows (captions, borders, padding, spacing)
     * @return array<int, float>
     */
    private function fillTableHeight(LayoutBox $table, array $orderedRows, array $rowHeights, float $contentWidth, float $outside): array
    {
        if ($table->forcedBorderBoxHeight !== null) {
            $target = $table->forcedBorderBoxHeight;
        } else {
            $explicit = $this->engine->getExplicitContentHeight($table, $contentWidth);
            if ($explicit === null) {
                return $rowHeights;
            }
            $target = $explicit + $table->getVerticalSpacing();
        }
        $extra = $target - $outside - array_sum($rowHeights);
        if (!is_finite($extra) || $extra <= 0.01 || $rowHeights === []) {
            return $rowHeights;
        }
        $targets = [];
        foreach ($rowHeights as $r => $h) {
            $rBox = $orderedRows[$r]['rowBox'] ?? null;
            if ($rBox === null || $this->engine->getExplicitContentHeight($rBox, $contentWidth) === null) {
                $targets[$r] = $h;
            }
        }
        if ($targets === []) {
            $targets = $rowHeights;
        }
        $sum = array_sum($targets);
        foreach ($targets as $r => $h) {
            $rowHeights[$r] += $sum > 0.01 ? $extra * $h / $sum : $extra / count($targets);
        }
        return $rowHeights;
    }

    /** Padding and border below the grid: a bottom caption goes below them. */
    private function gridBottomEdge(LayoutBox $table, bool $borderCollapse): float
    {
        return $borderCollapse ? $table->borderBottom / 2.0 : $table->paddingBottom + $table->borderBottom;
    }

    /** Bottom edge of a cell's in-flow content (lines and child boxes with their bottom margins), null when empty. */
    private static function contentBottom(LayoutBox $cell): ?float
    {
        $bottom = null;
        foreach ($cell->lines as $line) {
            $b = $line->y + $line->height;
            $bottom = $bottom === null ? $b : max($bottom, $b);
        }
        foreach ($cell->children as $child) {
            if ($child->isPositionedOutOfFlow() || $child->isTextNode() || $child->isInlineLevel()) {
                continue;
            }
            $b = $child->y + $child->height + max(0.0, $child->marginBottom);
            $bottom = $bottom === null ? $b : max($bottom, $b);
        }
        return $bottom;
    }

    /**
     * Moves `caption-side: bottom` captions (laid out at the grid origin) to $topY, stacked downwards.
     *
     * @param list<LayoutBox> $captions
     */
    private function placeBottomCaptions(array $captions, float $contentX, float $topY): void
    {
        $y = $topY;
        foreach ($captions as $cap) {
            $cap->translate($contentX + $cap->marginLeft - $cap->x, $y + $cap->marginTop - $cap->y);
            $y += $cap->getOuterHeight();
        }
    }

    /**
     * Reads widths from <colgroup>/<col> elements (width attribute or style).
     *
     * @return array<int, float>
     */
    private function readColumnWidths(LayoutBox $table, int $numCols, float $usableWidth): array
    {
        return TableMetrics::columnElementWidths($this->engine, $table, $numCols, $usableWidth);
    }

    /**
     * @param list<CellPlacement> $cellPlacements
     * @return list<float>
     */
    private function calculateColumnWidths(array $cellPlacements, int $numCols, float $usableWidth, array $colElementWidths = [], bool $collapse = false): array
    {
        $explicitWidths = array_fill(0, $numCols, null);
        foreach ($colElementWidths as $c => $w) {
            if ($w > 0.0) {
                $explicitWidths[$c] = $w;
            }
        }
        $minWidths = array_fill(0, $numCols, 0.0);
        $maxWidths = array_fill(0, $numCols, 0.0);

        foreach ($cellPlacements as $cp) {
            if ($cp->colSpan !== 1) {
                continue;
            }
            $c = $cp->colIdx;
            $cell = $cp->cell;

            $this->engine->resolveBoxMetrics($cell, $usableWidth);
            // Collapsed borders are shared by neighbouring columns: a column holds half of each side's border
            $half = $collapse ? ($cell->borderLeft + $cell->borderRight) / 2.0 : 0.0;
            $wCss = strtolower(trim($cell->style->get('width', 'auto')));
            if ($wCss !== 'auto' && $wCss !== '') {
                // A percentage is the share of the table that the whole column takes (padding and borders included,
                // as in browsers); a length is the cell's content (or border-box) width
                $wPt = str_ends_with($wCss, '%') && is_numeric(substr($wCss, 0, -1))
                    ? max(0.0, (float) substr($wCss, 0, -1)) / 100.0 * $usableWidth
                    : $this->engine->resolveBoxHorizontalDimension($cell, $wCss, $usableWidth) - $half;
                if ($wPt > 0.0) {
                    $explicitWidths[$c] = max($explicitWidths[$c] ?? 0.0, $wPt);
                }
            }

            $intr = $this->engine->measureIntrinsicWidth($cell, $usableWidth);
            // A cell's min-width raises the column's minimum (and preferred) width
            $cellMin = $this->engine->clampToMinMaxWidth($cell, 0.0, $usableWidth);
            if ($cellMin > 0.0) {
                $intr['min'] = max($intr['min'], $cellMin);
                $intr['max'] = max($intr['max'], $cellMin);
            }
            $intr['min'] = max(0.0, $intr['min'] - $half);
            $intr['max'] = max(0.0, $intr['max'] - $half);
            if ($intr['min'] > $minWidths[$c]) {
                $minWidths[$c] = $intr['min'];
            }
            if ($intr['max'] > $maxWidths[$c]) {
                $maxWidths[$c] = $intr['max'];
            }
        }

        // Spanning cells: make sure the spanned columns together satisfy the cell's minimum width
        foreach ($cellPlacements as $cp) {
            if ($cp->colSpan <= 1) {
                continue;
            }
            $intr = $this->engine->measureIntrinsicWidth($cp->cell, $usableWidth);
            if ($collapse) {
                $this->engine->resolveBoxMetrics($cp->cell, $usableWidth);
                $half = ($cp->cell->borderLeft + $cp->cell->borderRight) / 2.0;
                $intr['min'] = max(0.0, $intr['min'] - $half);
                $intr['max'] = max(0.0, $intr['max'] - $half);
            }
            $cols = range($cp->colIdx, min($numCols - 1, $cp->colIdx + $cp->colSpan - 1));
            $sumMin = 0.0;
            $sumMax = 0.0;
            foreach ($cols as $c) {
                $sumMin += $minWidths[$c];
                $sumMax += $maxWidths[$c];
            }
            if ($intr['min'] > $sumMin) {
                $add = ($intr['min'] - $sumMin) / count($cols);
                foreach ($cols as $c) {
                    $minWidths[$c] += $add;
                    $maxWidths[$c] = max($maxWidths[$c], $minWidths[$c]);
                }
            }
            if ($intr['max'] > $sumMax) {
                $add = ($intr['max'] - $sumMax) / count($cols);
                foreach ($cols as $c) {
                    $maxWidths[$c] += $add;
                }
            }
        }

        $this->lastRequiredWidth = array_sum($minWidths);

        $colWidths = array_fill(0, $numCols, 0.0);
        $usedExplicit = 0.0;
        $autoCols = [];

        for ($c = 0; $c < $numCols; $c++) {
            if ($explicitWidths[$c] !== null) {
                $colWidths[$c] = $explicitWidths[$c];
                $usedExplicit += $colWidths[$c];
            } else {
                $autoCols[] = $c;
            }
        }

        if (empty($autoCols)) {
            // Scale all explicit columns to fit usableWidth if they don't match
            if ($usedExplicit > 0.01 && abs($usedExplicit - $usableWidth) > 0.5) {
                $scale = $usableWidth / $usedExplicit;
                for ($c = 0; $c < $numCols; $c++) {
                    $colWidths[$c] *= $scale;
                }
            }
            return $colWidths;
        }

        $totalMaxAuto = 0.0;
        $totalMinAuto = 0.0;
        foreach ($autoCols as $c) {
            $totalMaxAuto += $maxWidths[$c];
            $totalMinAuto += $minWidths[$c];
        }
        // Explicit columns may not starve auto columns below their minimum content width
        if ($usedExplicit > 0.01 && $usableWidth - $usedExplicit < $totalMinAuto) {
            $scale = max(0.0, $usableWidth - min($totalMinAuto, $usableWidth * 0.9)) / $usedExplicit;
            for ($c = 0; $c < $numCols; $c++) {
                if ($explicitWidths[$c] !== null) {
                    $colWidths[$c] *= $scale;
                }
            }
            $usedExplicit *= $scale;
        }
        $remainingForAuto = max(0.0, $usableWidth - $usedExplicit);

        if ($totalMaxAuto <= 0.01) {
            $equal = $remainingForAuto / count($autoCols);
            foreach ($autoCols as $c) {
                $colWidths[$c] = $equal;
            }
            return $colWidths;
        }

        if ($remainingForAuto >= $totalMaxAuto) {
            foreach ($autoCols as $c) {
                $colWidths[$c] = $remainingForAuto * ($maxWidths[$c] / $totalMaxAuto);
            }
        } elseif ($remainingForAuto > $totalMinAuto && ($totalMaxAuto - $totalMinAuto) > 0.01) {
            $extra = $remainingForAuto - $totalMinAuto;
            $flexRange = $totalMaxAuto - $totalMinAuto;
            foreach ($autoCols as $c) {
                $colWidths[$c] = $minWidths[$c] + $extra * (($maxWidths[$c] - $minWidths[$c]) / $flexRange);
            }
        } else {
            foreach ($autoCols as $c) {
                $colWidths[$c] = $minWidths[$c]; // not enough room: the table grows (see layout())
            }
        }

        return $colWidths;
    }
}
