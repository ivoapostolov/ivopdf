<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Full CSS Grid Layout Engine.
 * Supports grid-template-columns/rows, repeat(N / auto-fit / auto-fill), minmax(), fr units,
 * percentages, auto tracks, grid-column/grid-row spans, negative line indices (1 / -1),
 * gaps, and justify/align-items/self. Placement (named lines, grid-template-areas, grid-area, auto-flow row/column/dense,
 * implicit tracks via grid-auto-columns/rows) lives in {@see GridPlacement}.
 */
class GridLayout
{
    private LayoutEngine $engine;

    /** @var array<string, list<int>> named lines of the most recently parsed track list */
    private array $lastLineNames = [];

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

        // Step 1: Parse explicit track definitions, named lines and grid-template-areas
        $areasInfo = GridPlacement::parseAreas($container->style->get('grid-template-areas', 'none'));
        $areas = $areasInfo['areas'] ?? [];

        $colTemplate = $container->style->get('grid-template-columns', 'none');
        $colExplicit = !in_array(strtolower(trim($colTemplate)), ['', 'none'], true);
        $colTracks = $colExplicit ? $this->parseTrackList($colTemplate, $contentWidth, $colGap, $container->fontSizePt, count($gridItems)) : [];
        $colNames = $colExplicit ? $this->lastLineNames : [];

        $rowTemplate = $container->style->get('grid-template-rows', 'none');
        $rowExplicit = !in_array(strtolower(trim($rowTemplate)), ['', 'none'], true);
        $rowTracks = $rowExplicit ? $this->parseTrackList($rowTemplate, 0.0, $rowGap, $container->fontSizePt, count($gridItems)) : [];
        $rowNames = $rowExplicit ? $this->lastLineNames : [];

        $explicitCols = max(count($colTracks), $areasInfo['cols'] ?? 0);
        $explicitRows = max(count($rowTracks), $areasInfo['rows'] ?? 0);
        $colNames = GridPlacement::withAreaLines($colNames, $areas, true);
        $rowNames = GridPlacement::withAreaLines($rowNames, $areas, false);

        // Step 2: Resolve every item's grid lines (numbers, names, spans, areas), then auto-place the rest
        $specs = [];
        foreach ($gridItems as $item) {
            [$colStart, $colSpan] = GridPlacement::resolveAxis(
                $item->style->get('grid-column-start', 'auto'),
                $item->style->get('grid-column-end', 'auto'),
                $colNames,
                $explicitCols
            );
            [$rowStart, $rowSpan] = GridPlacement::resolveAxis(
                $item->style->get('grid-row-start', 'auto'),
                $item->style->get('grid-row-end', 'auto'),
                $rowNames,
                $explicitRows
            );
            $specs[] = ['rowStart' => $rowStart, 'rowSpan' => $rowSpan, 'colStart' => $colStart, 'colSpan' => $colSpan];
        }
        $flowCss = strtolower(trim($container->style->get('grid-auto-flow', 'row')));
        $placed = GridPlacement::place($specs, $explicitCols, $explicitRows, str_contains($flowCss, 'column') ? 'column' : 'row', str_contains($flowCss, 'dense'));
        $numCols = max(1, $placed['cols']);
        $numRows = max(1, $placed['rows']);

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
        foreach ($gridItems as $k => $item) {
            $o = $placed['items'][$k];
            $placements[] = ['box' => $item, 'colStart' => $o['c'], 'colSpan' => $o['cs'], 'rowStart' => $o['r'], 'rowSpan' => $o['rs']];
        }

        // Implicit columns (grid-auto-columns, cycled) complete the explicit track list
        $autoColCss = $container->style->get('grid-auto-columns', 'auto');
        if (count($colTracks) < $numCols) {
            $legacy = !$colExplicit && $numCols === 1 && strtolower(trim($autoColCss)) === 'auto';
            $implicit = $this->parseTrackList($autoColCss, $contentWidth, $colGap, $container->fontSizePt, count($gridItems));
            $base = count($colTracks);
            for ($c = $base; $c < $numCols; $c++) {
                $colTracks[] = $legacy
                    ? ['type' => 'fr', 'value' => 1.0, 'min' => 0.0, 'max' => 0.0, 'maxFr' => 1.0]
                    : $implicit[($c - $base) % count($implicit)];
            }
        }

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

        // Implicit rows follow grid-auto-rows (a list is cycled)
        $autoRows = $this->parseTrackList($container->style->get('grid-auto-rows', 'auto'), 0.0, $rowGap, $container->fontSizePt, count($gridItems));
        $explicitRowTracks = count($rowTracks);

        for ($r = 0; $r < $numRows; $r++) {
            $trackSpec = $rowTracks[$r] ?? $autoRows[($r - $explicitRowTracks) % count($autoRows)];
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
                    $box->forcedBorderBoxWidth = $this->engine->clampToMinMaxWidth($box, min($cellWidth, $intrinsic['max']), $cellWidth);
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
        $this->lastLineNames = [];
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
        $this->lastLineNames = GridPlacement::lineNamesFromTokens($tokens);
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
                        $ovf = strtolower(trim($pl['box']->style->get('overflow-x', $pl['box']->style->get('overflow', 'visible'))));
                        if ($ovf !== 'visible' && $ovf !== '') {
                            $intr['min'] = 0.0; // grid items that are scroll containers have an automatic minimum of 0
                        }
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
