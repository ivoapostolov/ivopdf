<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Full CSS Grid Layout Engine.
 * Supports grid-template-columns/rows, repeat(N / auto-fit / auto-fill), minmax(), fr units,
 * percentages, auto tracks, grid-column/grid-row spans, negative line indices (1 / -1),
 * gaps, and justify/align-items/self.
 */
class GridLayout
{
    private LayoutEngine $engine;

    public function __construct(LayoutEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Lays out a CSS Grid container and returns the resulting content height.
     */
    public function layout(LayoutBox $container): float
    {
        $contentX = $container->getContentX();
        $contentY = $container->getContentY();
        $contentWidth = $container->getContentWidth();

        // Collect in-flow grid items
        $items = [];
        foreach ($container->children as $idx => $child) {
            if ($child->isPositionedOutOfFlow()) {
                continue;
            }
            if ($child->isTextNode() && trim($child->textContent ?? '') === '') {
                continue;
            }
            $items[] = [
                'box' => $child,
                'index' => $idx,
                'order' => (int) $child->style->get('order', '0'),
            ];
        }

        if (empty($items)) {
            return 0.0;
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return $a['index'] <=> $b['index'];
        });

        /** @var list<LayoutBox> $gridItems */
        $gridItems = array_map(static fn(array $entry): LayoutBox => $entry['box'], $items);

        $colGap = $this->engine->resolveLength($container->style->get('column-gap', '0'), $contentWidth, $container->fontSizePt);
        $rowGap = $this->engine->resolveLength($container->style->get('row-gap', '0'), $contentWidth, $container->fontSizePt);

        // Step 1: Parse column track definitions
        $colTemplate = $container->style->get('grid-template-columns', 'none');
        $colTracks = $this->parseTrackList($colTemplate, $contentWidth, $colGap, $container->fontSizePt, count($gridItems));
        $numCols = max(1, count($colTracks));

        // Step 2: Place items into a 2D occupancy grid using CSS Grid Auto-Placement rules
        /** @var array<int, array<int, bool>> $occupied [row][col] */
        $occupied = [];
        /**
         * @var list<array{
         *   box: LayoutBox,
         *   colStart: int,
         *   colSpan: int,
         *   rowStart: int,
         *   rowSpan: int
         * }> $placements
         */
        $placements = [];
        $cursorRow = 0;
        $cursorCol = 0;
        $maxRow = 0;

        foreach ($gridItems as $item) {
            [$colStart, $colSpan] = $this->resolveGridPlacement(
                $item->style->get('grid-column-start', 'auto'),
                $item->style->get('grid-column-end', 'auto'),
                $numCols
            );
            [$rowStart, $rowSpan] = $this->resolveGridPlacement(
                $item->style->get('grid-row-start', 'auto'),
                $item->style->get('grid-row-end', 'auto'),
                0
            );

            $colSpan = max(1, min($numCols, $colSpan));
            if ($colStart !== null) {
                $colStart = max(0, min($numCols - $colSpan, $colStart));
            }
            $rowSpan = max(1, $rowSpan);

            if ($rowStart !== null && $colStart !== null) {
                $r = max(0, $rowStart);
                $c = $colStart;
            } elseif ($colStart !== null) {
                $c = $colStart;
                $r = $cursorRow;
                while (!$this->isRegionFree($occupied, $r, $c, $rowSpan, $colSpan)) {
                    $r++;
                }
            } elseif ($rowStart !== null) {
                $r = max(0, $rowStart);
                $c = 0;
                while ($c + $colSpan <= $numCols && !$this->isRegionFree($occupied, $r, $c, $rowSpan, $colSpan)) {
                    $c++;
                }
                if ($c + $colSpan > $numCols) {
                    $c = 0;
                }
            } else {
                // Both auto: find next free slot starting at ($cursorRow, $cursorCol)
                $r = $cursorRow;
                $c = $cursorCol;
                while (true) {
                    if ($c + $colSpan > $numCols) {
                        $r++;
                        $c = 0;
                    }
                    if ($this->isRegionFree($occupied, $r, $c, $rowSpan, $colSpan)) {
                        break;
                    }
                    $c++;
                }
                $cursorRow = $r;
                $cursorCol = $c + $colSpan;
                if ($cursorCol >= $numCols) {
                    $cursorRow++;
                    $cursorCol = 0;
                }
            }

            $this->markRegionOccupied($occupied, $r, $c, $rowSpan, $colSpan);
            if ($r + $rowSpan > $maxRow) {
                $maxRow = $r + $rowSpan;
            }

            $placements[] = [
                'box' => $item,
                'colStart' => $c,
                'colSpan' => $colSpan,
                'rowStart' => $r,
                'rowSpan' => $rowSpan,
            ];
        }

        $numRows = max(1, $maxRow);

        // Step 3: Resolve Column Track Widths
        $colWidths = $this->resolveColumnWidths($colTracks, $placements, $contentWidth, $colGap, $container->fontSizePt);

        // Compute X starting coordinates for each column
        $colOffsets = [];
        $accX = $contentX;
        for ($c = 0; $c < $numCols; $c++) {
            $colOffsets[$c] = $accX;
            $accX += $colWidths[$c] + $colGap;
        }

        // Step 4: First layout pass for each grid item inside its spanned column width
        $rowHeights = array_fill(0, $numRows, 0.0);

        // Parse explicit row templates if any
        $rowTemplate = $container->style->get('grid-template-rows', 'none');
        $rowTracks = ($rowTemplate !== 'none' && $rowTemplate !== '')
            ? $this->parseTrackList($rowTemplate, 0.0, $rowGap, $container->fontSizePt, $numRows)
            : [];
        $autoRowCss = $container->style->get('grid-auto-rows', 'auto');

        for ($r = 0; $r < $numRows; $r++) {
            $trackSpec = $rowTracks[$r] ?? $this->parseSingleTrack($autoRowCss, 0.0, $container->fontSizePt);
            if ($trackSpec['type'] === 'fixed') {
                $rowHeights[$r] = $trackSpec['value'];
            } elseif ($trackSpec['type'] === 'minmax' && $trackSpec['min'] > 0) {
                $rowHeights[$r] = $trackSpec['min'];
            }
        }

        $justifyItems = strtolower($container->style->get('justify-items', 'stretch'));
        $alignItems = strtolower($container->style->get('align-items', 'stretch'));

        // Measure 1-row items first, then multi-row items
        foreach ($placements as &$pl) {
            $box = $pl['box'];
            $cStart = $pl['colStart'];
            $cSpan = $pl['colSpan'];

            $cellWidth = 0.0;
            for ($c = $cStart; $c < $cStart + $cSpan; $c++) {
                $cellWidth += $colWidths[$c] ?? 0.0;
            }
            if ($cSpan > 1) {
                $cellWidth += ($cSpan - 1) * $colGap;
            }
            $pl['cellWidth'] = $cellWidth;

            $this->engine->resolveBoxMetrics($box, $cellWidth);

            $selfJustify = strtolower($box->style->get('justify-self', 'auto'));
            $justify = ($selfJustify === 'auto' || $selfJustify === '') ? $justifyItems : $selfJustify;

            $widthCss = strtolower(trim($box->style->get('width', 'auto')));
            if ($widthCss === 'auto') {
                if ($justify === 'stretch') {
                    $box->forcedBorderBoxWidth = max(0.0, $cellWidth - $box->marginLeft - $box->marginRight);
                } else {
                    $intrinsic = $this->engine->measureIntrinsicWidth($box, $cellWidth);
                    $box->forcedBorderBoxWidth = min($cellWidth, $intrinsic['max']);
                }
            }

            $box->forcedBorderBoxHeight = null;
            $this->engine->layoutElement($box, 0.0, 0.0, $cellWidth);

            if ($pl['rowSpan'] === 1) {
                $r = $pl['rowStart'];
                $outerH = $box->getOuterHeight();
                if ($outerH > $rowHeights[$r]) {
                    $rowHeights[$r] = $outerH;
                }
            }
        }
        unset($pl);

        // Now accommodate multi-row spanning items
        foreach ($placements as $pl) {
            if ($pl['rowSpan'] > 1) {
                $rStart = $pl['rowStart'];
                $rSpan = $pl['rowSpan'];
                $currentSpanHeight = ($rSpan - 1) * $rowGap;
                for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                    $currentSpanHeight += $rowHeights[$r];
                }
                $needed = $pl['box']->getOuterHeight() - $currentSpanHeight;
                if ($needed > 0.01) {
                    $addPerRow = $needed / $rSpan;
                    for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                        $rowHeights[$r] += $addPerRow;
                    }
                }
            }
        }

        // Compute Y starting coordinates for each row
        $rowOffsets = [];
        $accY = $contentY;
        for ($r = 0; $r < $numRows; $r++) {
            $rowOffsets[$r] = $accY;
            $accY += $rowHeights[$r] + $rowGap;
        }

        // Step 5: Final positioning and vertical stretching of grid items
        foreach ($placements as $pl) {
            $box = $pl['box'];
            $cStart = $pl['colStart'];
            $rStart = $pl['rowStart'];
            $rSpan = $pl['rowSpan'];
            $cellWidth = $pl['cellWidth'];

            $cellX = $colOffsets[$cStart] ?? $contentX;
            $cellY = $rowOffsets[$rStart] ?? $contentY;

            $cellHeight = ($rSpan - 1) * $rowGap;
            for ($r = $rStart; $r < $rStart + $rSpan; $r++) {
                $cellHeight += $rowHeights[$r] ?? 0.0;
            }

            $selfJustify = strtolower($box->style->get('justify-self', 'auto'));
            $justify = ($selfJustify === 'auto' || $selfJustify === '') ? $justifyItems : $selfJustify;

            $selfAlign = strtolower($box->style->get('align-self', 'auto'));
            $align = ($selfAlign === 'auto' || $selfAlign === '') ? $alignItems : $selfAlign;

            $targetX = $cellX + $box->marginLeft;
            $freeW = max(0.0, $cellWidth - $box->getOuterWidth());
            if ($justify === 'center') {
                $targetX += $freeW / 2.0;
            } elseif ($justify === 'end' || $justify === 'flex-end' || $justify === 'right') {
                $targetX += $freeW;
            }

            $targetY = $cellY + $box->marginTop;
            $heightCss = strtolower(trim($box->style->get('height', 'auto')));

            if (str_ends_with($heightCss, '%')) {
                // Percentage height resolves against the grid area
                $box->forcedBorderBoxHeight = $this->engine->resolveBoxVerticalDimension($box, $heightCss, $cellHeight);
                $this->engine->layoutElement($box, $targetX, $targetY, $cellWidth);
            } elseif ($align === 'stretch' && $heightCss === 'auto') {
                $stretchedBorderBoxH = max($box->height, $cellHeight - $box->marginTop - $box->marginBottom);
                if (abs($stretchedBorderBoxH - $box->height) > 0.1) {
                    $box->forcedBorderBoxHeight = $stretchedBorderBoxH;
                    $this->engine->layoutElement($box, $targetX, $targetY, $cellWidth);
                } else {
                    $box->translate($targetX - $box->x, $targetY - $box->y);
                }
            } else {
                $freeH = max(0.0, $cellHeight - $box->getOuterHeight());
                if ($align === 'center') {
                    $targetY += $freeH / 2.0;
                } elseif ($align === 'end' || $align === 'flex-end' || $align === 'bottom') {
                    $targetY += $freeH;
                }
                $box->translate($targetX - $box->x, $targetY - $box->y);
            }
        }

        $totalHeight = 0.0;
        for ($r = 0; $r < $numRows; $r++) {
            $totalHeight += $rowHeights[$r];
        }
        if ($numRows > 1) {
            $totalHeight += ($numRows - 1) * $rowGap;
        }

        return max(0.0, $totalHeight);
    }

    /**
     * Parses a `grid-template-columns` or `grid-template-rows` value into an array of track descriptors.
     *
     * @return list<array{type: string, value: float, min: float, max: float, maxFr: float}>
     */
    private function parseTrackList(
        string $template,
        float $availableSize,
        float $gap,
        float $fontSizePt,
        int $itemCount
    ): array {
        $tpl = trim($template);
        if ($tpl === '' || strtolower($tpl) === 'none') {
            return [['type' => 'fr', 'value' => 1.0, 'min' => 0.0, 'max' => 0.0, 'maxFr' => 1.0]];
        }

        // Expand repeat(...) functions
        $expanded = (string) preg_replace_callback(
            '/repeat\(\s*([^,]+)\s*,\s*((?:[^()]+|\([^()]*\))+)\)/i',
            function (array $m) use ($availableSize, $gap, $fontSizePt, $itemCount): string {
                $countStr = strtolower(trim($m[1]));
                $pattern = trim($m[2]);

                if ($countStr === 'auto-fill' || $countStr === 'auto-fit') {
                    $minTrackW = 100.0;
                    if (preg_match('/minmax\(\s*([^,]+)\s*,/i', $pattern, $mm)) {
                        $minTrackW = max(20.0, $this->engine->resolveLength(trim($mm[1]), $availableSize, $fontSizePt));
                    } else {
                        $parsed = $this->parseSingleTrack($pattern, $availableSize, $fontSizePt);
                        if ($parsed['value'] > 10.0) {
                            $minTrackW = $parsed['value'];
                        }
                    }
                    $fitCount = max(1, (int) floor(($availableSize + $gap) / ($minTrackW + $gap)));
                    if ($countStr === 'auto-fit' && $itemCount > 0) {
                        $fitCount = max(1, min($fitCount, $itemCount));
                    }
                    return implode(' ', array_fill(0, $fitCount, $pattern));
                }

                $count = max(1, min(24, (int) $countStr));
                return implode(' ', array_fill(0, $count, $pattern));
            },
            $tpl
        );

        $tokens = $this->splitTopLevelTokens($expanded);
        if (empty($tokens)) {
            return [['type' => 'fr', 'value' => 1.0, 'min' => 0.0, 'max' => 0.0, 'maxFr' => 1.0]];
        }

        $tracks = [];
        foreach ($tokens as $tok) {
            // Skip line name brackets like [col-start]
            if (str_starts_with($tok, '[') && str_ends_with($tok, ']')) {
                continue;
            }
            $tracks[] = $this->parseSingleTrack($tok, $availableSize, $fontSizePt);
        }

        return !empty($tracks)
            ? $tracks
            : [['type' => 'fr', 'value' => 1.0, 'min' => 0.0, 'max' => 0.0, 'maxFr' => 1.0]];
    }

    /**
     * @return array{type: string, value: float, min: float, max: float, maxFr: float}
     */
    private function parseSingleTrack(string $tok, float $availableSize, float $fontSizePt): array
    {
        $t = strtolower(trim($tok));

        if (preg_match('/^minmax\(\s*([^,]+)\s*,\s*([^)]+)\s*\)$/i', $t, $m)) {
            $minPart = trim($m[1]);
            $maxPart = trim($m[2]);

            $minPt = ($minPart === 'auto' || $minPart === 'min-content' || $minPart === 'max-content')
                ? 0.0
                : $this->engine->resolveLength($minPart, $availableSize, $fontSizePt);

            if (str_ends_with($maxPart, 'fr')) {
                $fr = max(0.01, (float) substr($maxPart, 0, -2));
                return ['type' => 'minmax', 'value' => $fr, 'min' => $minPt, 'max' => 0.0, 'maxFr' => $fr];
            }

            $maxPt = ($maxPart === 'auto' || $maxPart === 'max-content' || $maxPart === '100%')
                ? $availableSize
                : $this->engine->resolveLength($maxPart, $availableSize, $fontSizePt);

            return ['type' => 'minmax', 'value' => $maxPt, 'min' => $minPt, 'max' => $maxPt, 'maxFr' => 0.0];
        }

        if (str_ends_with($t, 'fr')) {
            $fr = max(0.01, (float) substr($t, 0, -2));
            return ['type' => 'fr', 'value' => $fr, 'min' => 0.0, 'max' => 0.0, 'maxFr' => $fr];
        }

        if ($t === 'auto' || $t === 'min-content' || $t === 'max-content') {
            return ['type' => $t, 'value' => 0.0, 'min' => 0.0, 'max' => 0.0, 'maxFr' => 0.0];
        }

        $pt = $this->engine->resolveLength($t, $availableSize, $fontSizePt);
        return ['type' => 'fixed', 'value' => max(0.0, $pt), 'min' => $pt, 'max' => $pt, 'maxFr' => 0.0];
    }

    /**
     * Resolves column widths in points for all tracks.
     *
     * @param list<array{type: string, value: float, min: float, max: float, maxFr: float}> $colTracks
     * @param list<array{box: LayoutBox, colStart: int, colSpan: int, rowStart: int, rowSpan: int}> $placements
     * @return list<float>
     */
    private function resolveColumnWidths(
        array $colTracks,
        array $placements,
        float $contentWidth,
        float $colGap,
        float $fontSizePt
    ): array {
        $numCols = count($colTracks);
        $widths = array_fill(0, $numCols, 0.0);
        $totalGaps = ($numCols > 1) ? ($numCols - 1) * $colGap : 0.0;
        $usableWidth = max(0.0, $contentWidth - $totalGaps);

        $usedWidth = 0.0;
        $totalFr = 0.0;
        $autoCols = [];

        foreach ($colTracks as $c => $track) {
            if ($track['type'] === 'fixed') {
                $widths[$c] = $track['value'];
                $usedWidth += $widths[$c];
            } elseif ($track['type'] === 'fr') {
                $totalFr += $track['value'];
            } elseif ($track['type'] === 'minmax') {
                if ($track['maxFr'] > 0.0) {
                    $widths[$c] = $track['min'];
                    $totalFr += $track['maxFr'];
                } else {
                    $widths[$c] = max($track['min'], $track['max']);
                    $usedWidth += $widths[$c];
                }
            } else {
                // auto, min-content, max-content
                $autoCols[] = $c;
            }
        }

        // Measure intrinsic widths for auto / min-content / max-content columns
        if (!empty($autoCols)) {
            foreach ($autoCols as $c) {
                $maxIntrinsic = 0.0;
                $minIntrinsic = 0.0;
                foreach ($placements as $pl) {
                    if ($pl['colStart'] === $c && $pl['colSpan'] === 1) {
                        $intr = $this->engine->measureIntrinsicWidth($pl['box'], $usableWidth);
                        $outerMin = $intr['min'] + $pl['box']->marginLeft + $pl['box']->marginRight;
                        $outerMax = $intr['max'] + $pl['box']->marginLeft + $pl['box']->marginRight;
                        if ($outerMin > $minIntrinsic) {
                            $minIntrinsic = $outerMin;
                        }
                        if ($outerMax > $maxIntrinsic) {
                            $maxIntrinsic = $outerMax;
                        }
                    }
                }
                $trackType = $colTracks[$c]['type'];
                if ($trackType === 'min-content') {
                    $widths[$c] = $minIntrinsic;
                } else {
                    $widths[$c] = min($usableWidth / max(1, count($autoCols)), $maxIntrinsic);
                }
                $usedWidth += $widths[$c];
            }
        }

        // Distribute remaining width across `fr` tracks
        $remaining = max(0.0, $usableWidth - $usedWidth);
        if ($totalFr > 0.0) {
            foreach ($colTracks as $c => $track) {
                $fr = ($track['type'] === 'fr') ? $track['value'] : $track['maxFr'];
                if ($fr > 0.0) {
                    $share = $remaining * ($fr / $totalFr);
                    $widths[$c] = max($track['min'], $share);
                }
            }
        } elseif (!empty($autoCols) && $remaining > 0.01) {
            // Expand auto columns if no `fr` tracks exist
            $extraPerAuto = $remaining / count($autoCols);
            foreach ($autoCols as $c) {
                if ($colTracks[$c]['type'] === 'auto') {
                    $widths[$c] += $extraPerAuto;
                }
            }
        }

        return $widths;
    }

    /**
     * Resolves a CSS grid-column or grid-row start/end pair into 0-based [startIndex|null, span].
     *
     * @return array{0: int|null, 1: int}
     */
    private function resolveGridPlacement(string $startCss, string $endCss, int $numTracks): array
    {
        $s = strtolower(trim($startCss));
        $e = strtolower(trim($endCss));

        $startIdx = null;
        $span = 1;

        if (str_starts_with($s, 'span ')) {
            $span = max(1, min(100, (int) trim(substr($s, 5))));
        } elseif (is_numeric($s)) {
            $line = (int) $s;
            if ($line < 0 && $numTracks > 0) {
                $startIdx = max(0, $numTracks + 1 + $line - 1);
            } elseif ($line > 0) {
                $startIdx = min(200, $line - 1);
            }
        }

        if (str_starts_with($e, 'span ')) {
            $span = max(1, min(100, (int) trim(substr($e, 5))));
        } elseif (is_numeric($e)) {
            $endLine = min(201, (int) $e);
            if ($endLine < 0 && $numTracks > 0) {
                $endLine = $numTracks + 2 + $endLine;
            }
            if ($startIdx !== null && $endLine > ($startIdx + 1)) {
                $span = min(100, $endLine - ($startIdx + 1));
            }
        }

        return [$startIdx, $span];
    }

    /**
     * @param array<int, array<int, bool>> $occupied
     */
    private function isRegionFree(array $occupied, int $row, int $col, int $rowSpan, int $colSpan): bool
    {
        for ($r = $row; $r < $row + $rowSpan; $r++) {
            for ($c = $col; $c < $col + $colSpan; $c++) {
                if (!empty($occupied[$r][$c])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<int, array<int, bool>> $occupied
     */
    private function markRegionOccupied(array &$occupied, int $row, int $col, int $rowSpan, int $colSpan): void
    {
        for ($r = $row; $r < $row + $rowSpan; $r++) {
            for ($c = $col; $c < $col + $colSpan; $c++) {
                $occupied[$r][$c] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelTokens(string $str): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif (ctype_space($ch) && $depth === 0) {
                if ($current !== '') {
                    $result[] = $current;
                    $current = '';
                }
            } else {
                $current .= $ch;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }
}
