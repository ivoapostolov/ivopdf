<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Full CSS Flexible Box Layout (Flexbox) Engine.
 * Supports row/column/reverse directions, wrap, grow/shrink/basis, gaps, auto margins,
 * justify-content, align-items, align-self, align-content, and order.
 */
class FlexLayout
{
    private LayoutEngine $engine;

    public function __construct(LayoutEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Lays out a flex container's in-flow children and returns the resulting content height.
     */
    public function layout(LayoutBox $container): float
    {
        $contentX = $container->getContentX();
        $contentY = $container->getContentY();
        $contentWidth = $container->getContentWidth();

        // Collect in-flow flex items
        $items = [];
        foreach ($container->children as $idx => $child) {
            if ($child->isPositionedOutOfFlow()) {
                continue;
            }
            if ($child->isTextNode() && trim($child->textContent ?? '') === '') {
                continue;
            }
            $items[] = ['box' => $child, 'index' => $idx, 'order' => (int) $child->style->get('order', '0')];
        }

        if (empty($items)) {
            return 0.0;
        }

        // Stable sort by CSS `order`
        usort($items, static function (array $a, array $b): int {
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return $a['index'] <=> $b['index'];
        });

        /** @var list<LayoutBox> $flexItems */
        $flexItems = array_map(static fn(array $entry): LayoutBox => $entry['box'], $items);

        $direction = strtolower($container->style->get('flex-direction', 'row'));
        $isColumn = str_starts_with($direction, 'column');

        if ($isColumn) {
            return $this->layoutColumn($container, $flexItems, $contentX, $contentY, $contentWidth, $direction === 'column-reverse');
        }

        return $this->layoutRow($container, $flexItems, $contentX, $contentY, $contentWidth, $direction === 'row-reverse');
    }

    /**
     * Lays out a row or row-reverse flex container.
     *
     * @param list<LayoutBox> $flexItems
     */
    private function layoutRow(
        LayoutBox $container,
        array $flexItems,
        float $contentX,
        float $contentY,
        float $contentWidth,
        bool $isReverse
    ): float {
        $wrapMode = strtolower($container->style->get('flex-wrap', 'nowrap'));
        $canWrap = ($wrapMode === 'wrap' || $wrapMode === 'wrap-reverse');

        $colGap = $this->engine->resolveLength($container->style->get('column-gap', '0'), $contentWidth, $container->fontSizePt);
        $rowGap = $this->engine->resolveLength($container->style->get('row-gap', '0'), $contentWidth, $container->fontSizePt);

        $justifyContent = strtolower($container->style->get('justify-content', 'flex-start'));
        $alignItems = strtolower($container->style->get('align-items', 'stretch'));

        // Step 1: Compute hypothetical main size (base width) and intrinsic bounds for each item
        /**
         * @var list<array{
         *   box: LayoutBox,
         *   baseWidth: float,
         *   minWidth: float,
         *   maxWidth: float,
         *   grow: float,
         *   shrink: float,
         *   autoMarginLeft: bool,
         *   autoMarginRight: bool
         * }> $itemInfos
         */
        $itemInfos = [];
        foreach ($flexItems as $item) {
            $this->engine->resolveBoxMetrics($item, $contentWidth);
            $intrinsic = $this->engine->measureIntrinsicWidth($item, $contentWidth);

            $basisCss = strtolower(trim($item->style->get('flex-basis', 'auto')));
            $widthCss = strtolower(trim($item->style->get('width', 'auto')));

            if ($basisCss !== 'auto' && $basisCss !== 'content' && $basisCss !== '') {
                $baseW = $this->engine->resolveBoxHorizontalDimension($item, $basisCss, $contentWidth);
            } elseif ($widthCss !== 'auto' && $widthCss !== '') {
                $baseW = $this->engine->resolveBoxHorizontalDimension($item, $widthCss, $contentWidth);
            } else {
                $baseW = min($contentWidth, $intrinsic['max']);
            }

            // Automatic minimum is the content-based min (per flexbox spec an explicit
            // `width` must still be shrinkable down to min-content), so measure it
            // with the explicit width ignored.
            $minW = $this->engine->measureIntrinsicWidth($item, $contentWidth, true)['min'];
            // Content-based minimum never exceeds a definite specified width (CSS Flexbox 4.5)
            if ($widthCss !== 'auto' && $widthCss !== '' && !str_ends_with($widthCss, '%') && !str_contains($widthCss, 'content')) {
                $minW = min($minW, $this->engine->resolveBoxHorizontalDimension($item, $widthCss, $contentWidth));
            }
            $minWidthCss = strtolower(trim($item->style->get('min-width', '0')));
            if ($minWidthCss !== '0' && $minWidthCss !== 'auto' && $minWidthCss !== '') {
                $minW = $this->engine->resolveBoxHorizontalDimension($item, $minWidthCss, $contentWidth);
            } elseif ($basisCss === '0' || $basisCss === '0%' || $basisCss === '0px' || $basisCss === '0pt') {
                $minW = 0.0;
            }

            $maxW = 100000.0;
            $maxWidthCss = strtolower(trim($item->style->get('max-width', 'none')));
            if ($maxWidthCss !== 'none' && $maxWidthCss !== '') {
                $maxW = $this->engine->resolveBoxHorizontalDimension($item, $maxWidthCss, $contentWidth);
            }

            $baseW = max($minW, min($maxW, $baseW));

            $itemInfos[] = [
                'box' => $item,
                'baseWidth' => $baseW,
                'minWidth' => $minW,
                'maxWidth' => $maxW,
                'grow' => max(0.0, (float) $item->style->get('flex-grow', '0')),
                'shrink' => max(0.0, (float) $item->style->get('flex-shrink', '1')),
                'autoMarginLeft' => strtolower(trim($item->style->get('margin-left', '0'))) === 'auto',
                'autoMarginRight' => strtolower(trim($item->style->get('margin-right', '0'))) === 'auto',
            ];
        }

        // Step 2: Partition items into flex lines
        $lines = [];
        $currentLine = [];
        $currentLineMain = 0.0;

        foreach ($itemInfos as $info) {
            $itemOuter = $info['baseWidth'] + $info['box']->marginLeft + $info['box']->marginRight;
            $gapAdded = empty($currentLine) ? 0.0 : $colGap;

            if ($canWrap && !empty($currentLine) && ($currentLineMain + $gapAdded + $itemOuter > $contentWidth + 0.1)) {
                $lines[] = $currentLine;
                $currentLine = [$info];
                $currentLineMain = $itemOuter;
            } else {
                $currentLine[] = $info;
                $currentLineMain += $gapAdded + $itemOuter;
            }
        }
        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }

        if ($wrapMode === 'wrap-reverse') {
            $lines = array_reverse($lines);
        }

        // Step 3: Resolve flexible lengths and layout items line by line
        $cursorY = $contentY;
        $lineCount = count($lines);

        foreach ($lines as $lineIdx => $lineItems) {
            if ($isReverse) {
                $lineItems = array_reverse($lineItems);
            }

            $n = count($lineItems);
            $totalGaps = ($n > 1) ? ($n - 1) * $colGap : 0.0;
            $usedMain = $totalGaps;

            $totalGrow = 0.0;
            $totalWeightedShrink = 0.0;
            $autoMarginCount = 0;

            foreach ($lineItems as $info) {
                $usedMain += $info['baseWidth'] + $info['box']->marginLeft + $info['box']->marginRight;
                $totalGrow += $info['grow'];
                $totalWeightedShrink += $info['shrink'] * max(1.0, $info['baseWidth']);
                if ($info['autoMarginLeft']) {
                    $autoMarginCount++;
                }
                if ($info['autoMarginRight']) {
                    $autoMarginCount++;
                }
            }

            $freeSpace = $contentWidth - $usedMain;

            // Resolve target widths via flex-grow or flex-shrink
            $resolvedWidths = [];
            foreach ($lineItems as $idx => $info) {
                $w = $info['baseWidth'];
                if ($freeSpace > 0.01 && $totalGrow > 0.0) {
                    $w += $freeSpace * ($info['grow'] / $totalGrow);
                    $w = min($info['maxWidth'], $w);
                } elseif ($freeSpace < -0.01 && $totalWeightedShrink > 0.0) {
                    $weight = ($info['shrink'] * max(1.0, $info['baseWidth'])) / $totalWeightedShrink;
                    $w += $freeSpace * $weight; // freeSpace is negative
                    $w = max(min($info['minWidth'], $info['baseWidth']), max(10.0, $w));
                }
                $resolvedWidths[$idx] = max(0.0, $w);
            }

            // First layout pass for each item in the line to determine natural heights
            $lineCrossHeight = 0.0;
            foreach ($lineItems as $idx => $info) {
                $box = $info['box'];
                $box->forcedBorderBoxWidth = $resolvedWidths[$idx];
                $box->forcedBorderBoxHeight = null;
                $this->engine->layoutElement($box, 0.0, 0.0, $contentWidth);
                $outerH = $box->getOuterHeight();
                if ($outerH > $lineCrossHeight) {
                    $lineCrossHeight = $outerH;
                }
            }

            // If single-line flex container has explicit height/min-height larger than lineCrossHeight, expand lineCrossHeight
            if ($lineCount === 1) {
                $explicitContainerContentH = $this->engine->getExplicitContentHeight($container, $contentWidth);
                if ($explicitContainerContentH !== null && $explicitContainerContentH > $lineCrossHeight) {
                    $lineCrossHeight = $explicitContainerContentH;
                }
            }

            // Recompute remaining free space after width clamping
            $actualUsedMain = $totalGaps;
            foreach ($lineItems as $idx => $info) {
                $actualUsedMain += $info['box']->width + $info['box']->marginLeft + $info['box']->marginRight;
            }
            $remainingSpace = max(0.0, $contentWidth - $actualUsedMain);

            // Handle auto margins or justify-content
            $startOffset = 0.0;
            $extraGap = 0.0;
            $autoMarginSize = 0.0;

            if ($autoMarginCount > 0 && $remainingSpace > 0.0) {
                $autoMarginSize = $remainingSpace / $autoMarginCount;
            } else {
                switch ($justifyContent) {
                    case 'flex-end':
                    case 'end':
                    case 'right':
                        $startOffset = $remainingSpace;
                        break;
                    case 'center':
                        $startOffset = $remainingSpace / 2.0;
                        break;
                    case 'space-between':
                        if ($n > 1) {
                            $extraGap = $remainingSpace / ($n - 1);
                        }
                        break;
                    case 'space-around':
                        if ($n > 0) {
                            $unit = $remainingSpace / $n;
                            $startOffset = $unit / 2.0;
                            $extraGap = $unit;
                        }
                        break;
                    case 'space-evenly':
                        if ($n > 0) {
                            $unit = $remainingSpace / ($n + 1);
                            $startOffset = $unit;
                            $extraGap = $unit;
                        }
                        break;
                }
            }

            // Position items horizontally and vertically within the flex line
            $cursorX = $contentX + $startOffset;
            foreach ($lineItems as $idx => $info) {
                $box = $info['box'];
                if ($info['autoMarginLeft']) {
                    $box->marginLeft = $autoMarginSize;
                }
                if ($info['autoMarginRight']) {
                    $box->marginRight = $autoMarginSize;
                }

                $selfAlign = strtolower($box->style->get('align-self', 'auto'));
                $align = ($selfAlign === 'auto' || $selfAlign === '') ? $alignItems : $selfAlign;

                $targetX = $cursorX + $box->marginLeft;
                $targetY = $cursorY + $box->marginTop;

                $heightCss = strtolower(trim($box->style->get('height', 'auto')));
                if ($align === 'stretch' && $heightCss === 'auto') {
                    $stretchedBorderBoxH = max($box->height, $lineCrossHeight - $box->marginTop - $box->marginBottom);
                    if (abs($stretchedBorderBoxH - $box->height) > 0.1) {
                        $box->forcedBorderBoxHeight = $stretchedBorderBoxH;
                        $this->engine->layoutElement($box, $targetX, $targetY, $contentWidth);
                    } else {
                        $box->translate($targetX - $box->x, $targetY - $box->y);
                    }
                } else {
                    $freeCross = max(0.0, $lineCrossHeight - $box->getOuterHeight());
                    if ($align === 'center' || $align === 'baseline') {
                        $targetY += $freeCross / 2.0;
                    } elseif ($align === 'flex-end' || $align === 'end' || $align === 'bottom') {
                        $targetY += $freeCross;
                    }
                    $box->translate($targetX - $box->x, $targetY - $box->y);
                }

                $cursorX += $box->marginLeft + $box->width + $box->marginRight + $colGap + $extraGap;
            }

            $cursorY += $lineCrossHeight;
            if ($lineIdx < $lineCount - 1) {
                $cursorY += $rowGap;
            }
        }

        return max(0.0, $cursorY - $contentY);
    }

    /**
     * Lays out a column or column-reverse flex container.
     *
     * @param list<LayoutBox> $flexItems
     */
    private function layoutColumn(
        LayoutBox $container,
        array $flexItems,
        float $contentX,
        float $contentY,
        float $contentWidth,
        bool $isReverse
    ): float {
        if ($isReverse) {
            $flexItems = array_reverse($flexItems);
        }

        $rowGap = $this->engine->resolveLength($container->style->get('row-gap', '0'), $contentWidth, $container->fontSizePt);
        $justifyContent = strtolower($container->style->get('justify-content', 'flex-start'));
        $alignItems = strtolower($container->style->get('align-items', 'stretch'));

        $n = count($flexItems);
        $totalGaps = ($n > 1) ? ($n - 1) * $rowGap : 0.0;

        $totalGrow = 0.0;
        $autoMarginCount = 0;
        $usedMainHeight = $totalGaps;

        foreach ($flexItems as $item) {
            $this->engine->resolveBoxMetrics($item, $contentWidth);

            $selfAlign = strtolower($item->style->get('align-self', 'auto'));
            $align = ($selfAlign === 'auto' || $selfAlign === '') ? $alignItems : $selfAlign;

            $widthCss = strtolower(trim($item->style->get('width', 'auto')));
            if ($widthCss === 'auto') {
                if ($align === 'stretch') {
                    $item->forcedBorderBoxWidth = max(0.0, $contentWidth - $item->marginLeft - $item->marginRight);
                } else {
                    $intrinsic = $this->engine->measureIntrinsicWidth($item, $contentWidth);
                    $item->forcedBorderBoxWidth = $this->engine->clampToMinMaxWidth($item, min($contentWidth, $intrinsic['max']), $contentWidth);
                }
            }

            $this->engine->layoutElement($item, 0.0, 0.0, $contentWidth);

            $usedMainHeight += $item->getOuterHeight();
            $totalGrow += max(0.0, (float) $item->style->get('flex-grow', '0'));
            if (strtolower(trim($item->style->get('margin-top', '0'))) === 'auto') {
                $autoMarginCount++;
            }
            if (strtolower(trim($item->style->get('margin-bottom', '0'))) === 'auto') {
                $autoMarginCount++;
            }
        }

        // Check if container has an explicit or forced content height to distribute vertical space
        $explicitContentH = $this->engine->getExplicitContentHeight($container, $contentWidth);
        $targetMainHeight = $explicitContentH !== null ? max($usedMainHeight, $explicitContentH) : $usedMainHeight;
        $freeSpace = max(0.0, $targetMainHeight - $usedMainHeight);

        if ($freeSpace > 0.01 && $totalGrow > 0.0) {
            $usedMainHeight = $totalGaps;
            foreach ($flexItems as $item) {
                $grow = max(0.0, (float) $item->style->get('flex-grow', '0'));
                if ($grow > 0.0) {
                    $extraH = $freeSpace * ($grow / $totalGrow);
                    $item->forcedBorderBoxHeight = $item->height + $extraH;
                    $this->engine->layoutElement($item, 0.0, 0.0, $contentWidth);
                }
                $usedMainHeight += $item->getOuterHeight();
            }
            $freeSpace = max(0.0, $targetMainHeight - $usedMainHeight);
        }

        $startOffset = 0.0;
        $extraGap = 0.0;
        $autoMarginSize = 0.0;

        if ($autoMarginCount > 0 && $freeSpace > 0.0) {
            $autoMarginSize = $freeSpace / $autoMarginCount;
        } elseif ($freeSpace > 0.0) {
            switch ($justifyContent) {
                case 'flex-end':
                case 'end':
                    $startOffset = $freeSpace;
                    break;
                case 'center':
                    $startOffset = $freeSpace / 2.0;
                    break;
                case 'space-between':
                    if ($n > 1) {
                        $extraGap = $freeSpace / ($n - 1);
                    }
                    break;
                case 'space-around':
                    if ($n > 0) {
                        $unit = $freeSpace / $n;
                        $startOffset = $unit / 2.0;
                        $extraGap = $unit;
                    }
                    break;
                case 'space-evenly':
                    if ($n > 0) {
                        $unit = $freeSpace / ($n + 1);
                        $startOffset = $unit;
                        $extraGap = $unit;
                    }
                    break;
            }
        }

        $cursorY = $contentY + $startOffset;
        foreach ($flexItems as $item) {
            if (strtolower(trim($item->style->get('margin-top', '0'))) === 'auto') {
                $item->marginTop = $autoMarginSize;
            }
            if (strtolower(trim($item->style->get('margin-bottom', '0'))) === 'auto') {
                $item->marginBottom = $autoMarginSize;
            }

            $selfAlign = strtolower($item->style->get('align-self', 'auto'));
            $align = ($selfAlign === 'auto' || $selfAlign === '') ? $alignItems : $selfAlign;

            $freeCross = max(0.0, $contentWidth - $item->getOuterWidth());
            $targetX = $contentX + $item->marginLeft;
            if ($align === 'center') {
                $targetX += $freeCross / 2.0;
            } elseif ($align === 'flex-end' || $align === 'end' || $align === 'right') {
                $targetX += $freeCross;
            }

            $targetY = $cursorY + $item->marginTop;
            $item->translate($targetX - $item->x, $targetY - $item->y);

            $cursorY += $item->marginTop + $item->height + $item->marginBottom + $rowGap + $extraGap;
        }

        return max($targetMainHeight, $cursorY - $contentY - ($n > 0 ? ($rowGap + $extraGap) : 0.0));
    }
}
