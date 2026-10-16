<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssUnit;
use IvoPdf\Font\FontManager;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Options;

/**
 * Central Layout Engine orchestrating Block, Inline, Flexbox, CSS Grid, Table, and Positioned layouts.
 */
class LayoutEngine
{
    private Options $options;
    private FontManager $fontManager;
    private ImageLoader $imageLoader;

    private InlineLayout $inlineLayout;
    private FlexLayout $flexLayout;
    private GridLayout $gridLayout;
    private TableLayout $tableLayout;
    private ColumnLayout $columnLayout;
    /** @var array{0: float, 1: float}|null [topMargin, printableHeight] of the page grid, when known */
    private ?array $pageGeometry = null;

    private float $rootFontSizePt;
    private float $viewportWidthPt;
    private float $viewportHeightPt;

    public function __construct(
        Options $options,
        FontManager $fontManager,
        ImageLoader $imageLoader,
        float $viewportWidthPt,
        float $viewportHeightPt
    ) {
        $this->options = $options;
        $this->fontManager = $fontManager;
        $this->imageLoader = $imageLoader;
        $this->rootFontSizePt = $options->defaultFontSize;
        $this->viewportWidthPt = $viewportWidthPt;
        $this->viewportHeightPt = $viewportHeightPt;

        $this->inlineLayout = new InlineLayout($this, $fontManager);
        $this->flexLayout = new FlexLayout($this);
        $this->gridLayout = new GridLayout($this);
        $this->tableLayout = new TableLayout($this);
        $this->columnLayout = new ColumnLayout($this);
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * Tells the engine where page boundaries lie (used by multi-column layout).
     */
    public function setPageGeometry(float $topMargin, float $printableHeight): void
    {
        $this->pageGeometry = [$topMargin, $printableHeight];
    }

    /**
     * @return array{0: float, 1: float}|null [topMargin, printableHeight]
     */
    public function getPageGeometry(): ?array
    {
        return $this->pageGeometry;
    }

    /**
     * Lays out the in-flow children of a block formatting context (used by multi-column layout).
     */
    public function layoutBlockFlow(LayoutBox $container): float
    {
        return $this->layoutBlockChildren($container);
    }

    /**
     * Lays out the root document tree inside the printable page area.
     */
    public function layoutDocument(LayoutBox $root, float $startX, float $startY, float $printableWidth): void
    {
        $this->rootFontSizePt = $this->resolveFontSize($root->style, $this->options->defaultFontSize);
        $root->forcedBorderBoxWidth = $printableWidth;
        $this->layoutElement($root, $startX, $startY, $printableWidth);
    }

    /**
     * Lays out a single element box at border-box position ($x, $y) within a containing block of $containingWidth.
     */
    public function layoutElement(LayoutBox $box, float $x, float $y, float $containingWidth): void
    {
        $box->lines = [];
        $box->collapsedMarginTop = 0.0;
        $box->collapsedMarginBottom = 0.0;
        $this->resolveBoxMetrics($box, $containingWidth);

        $box->x = $x;
        $box->y = $y;

        // Determine border-box width
        if ($box->forcedBorderBoxWidth !== null) {
            $box->width = max(0.0, $box->forcedBorderBoxWidth);
        } else {
            $box->width = $this->computeBorderBoxWidth($box, $containingWidth);
        }

        // Special handling for replaced elements: <img> and <svg>
        if ($box->tagName === 'img') {
            $this->layoutImageBox($box, $containingWidth);
            $this->applyRelativeOffset($box, $containingWidth);
            return;
        }
        if ($box->tagName === 'svg') {
            $this->layoutSvgBox($box, $containingWidth);
            $this->applyRelativeOffset($box, $containingWidth);
            return;
        }
        if ($box->formControl !== null) {
            $this->layoutFormControlBox($box, $containingWidth);
            $this->applyRelativeOffset($box, $containingWidth);
            return;
        }
        if ($box->tagName === 'hr') {
            $box->height = max($box->getVerticalSpacing(), $this->resolveLength($box->style->get('height', '0'), $containingWidth, $box->fontSizePt));
            $this->applyRelativeOffset($box, $containingWidth);
            return;
        }

        $display = $box->getDisplay();
        $contentHeight = 0.0;

        if ($display === 'flex' || $display === 'inline-flex') {
            $contentHeight = $this->flexLayout->layout($box);
        } elseif ($display === 'grid' || $display === 'inline-grid') {
            $contentHeight = $this->gridLayout->layout($box);
        } elseif ($display === 'table' || $display === 'inline-table') {
            $contentHeight = $this->tableLayout->layout($box);
        } else {
            $contentHeight = ($this->columnLayout->isMulticol($box) ? $this->columnLayout->layout($box) : null)
                ?? $this->layoutBlockChildren($box);
        }

        // Resolve final border-box height
        if ($box->forcedBorderBoxHeight !== null) {
            $box->height = max($contentHeight + $box->getVerticalSpacing(), $box->forcedBorderBoxHeight);
        } else {
            $explicitContentH = $this->getExplicitContentHeight($box, $containingWidth);
            $finalContentH = $explicitContentH !== null ? max($contentHeight, $explicitContentH) : $contentHeight;
            $hCss = strtolower(trim($box->style->get('height', 'auto')));
            if ($explicitContentH !== null && $hCss !== 'auto' && $hCss !== '' && strtolower($box->style->get('overflow', 'visible')) !== 'visible') {
                // Fixed height with clipped overflow: the box keeps its declared height
                $finalContentH = $explicitContentH;
            }
            $box->height = $finalContentH + $box->getVerticalSpacing();
        }
        $this->applyMaxHeight($box, $containingWidth);

        // Layout out-of-flow (position: absolute | fixed) children relative to this box
        $this->layoutPositionedChildren($box);

        // Apply position: relative offset if present
        $this->applyRelativeOffset($box, $containingWidth);
    }

    private function applyMaxHeight(LayoutBox $box, float $containingWidth): void
    {
        $maxHCss = strtolower(trim($box->style->get('max-height', 'none')));
        if ($maxHCss === 'none' || $maxHCss === '') {
            return;
        }
        if (str_ends_with($maxHCss, '%')) {
            $pctBase = BoxSizing::percentBase($this, $box);
            if ($pctBase === null) {
                return;
            }
            $maxH = $this->resolveBoxVerticalDimension($box, $maxHCss, $pctBase);
        } else {
            $maxH = $this->resolveBoxVerticalDimension($box, $maxHCss, $containingWidth);
        }
        if ($maxH > 0.0 && $box->height > $maxH) {
            $box->height = max($box->getVerticalSpacing(), $maxH);
        }
    }

    /**
     * Lays out the children of a normal Block Formatting Context (handling mixed inline and block children,
     * margin collapsing, and auto-centering via margin: 0 auto).
     */
    private function layoutBlockChildren(LayoutBox $container): float
    {
        $contentX = $container->getContentX();
        $contentY = $container->getContentY();
        $contentWidth = $container->getContentWidth();

        $cursorY = $contentY;
        $prevMarginBottom = 0.0;
        $maxFloatBottom = $contentY;

        // Parent / first-child and parent / last-child margin collapsing (CSS 2.1 8.3.1)
        $canCollapse = $this->createsNoBlockContext($container);
        $topOpen = $canCollapse && $container->borderTop <= 0.0 && $container->paddingTop <= 0.0;
        $bottomOpen = $canCollapse && $container->borderBottom <= 0.0 && $container->paddingBottom <= 0.0
            && $container->forcedBorderBoxHeight === null && $this->hasAutoHeight($container);

        /** @var list<array{left: float, right: float, top: float, bottom: float}> $leftFloats */
        $leftFloats = [];
        /** @var list<array{left: float, right: float, top: float, bottom: float}> $rightFloats */
        $rightFloats = [];

        $getBandAtY = static function (float $y, float $h) use ($contentX, $contentWidth, &$leftFloats, &$rightFloats): array {
            $leftEdge = $contentX;
            $rightEdge = $contentX + $contentWidth;
            $yEnd = $y + max(1.0, $h);
            foreach ($leftFloats as $lf) {
                if ($lf['bottom'] > $y + 0.01 && $lf['top'] < $yEnd - 0.01) {
                    if ($lf['right'] > $leftEdge) {
                        $leftEdge = $lf['right'];
                    }
                }
            }
            foreach ($rightFloats as $rf) {
                if ($rf['bottom'] > $y + 0.01 && $rf['top'] < $yEnd - 0.01) {
                    if ($rf['left'] < $rightEdge) {
                        $rightEdge = $rf['left'];
                    }
                }
            }
            return [$leftEdge, max($leftEdge, $rightEdge)];
        };

        /** @var list<LayoutBox> $inlineBuffer */
        $inlineBuffer = [];

        $flushInlineBuffer = function () use (&$inlineBuffer, $container, &$cursorY, &$prevMarginBottom, $getBandAtY, &$topOpen, &$leftFloats, &$rightFloats): void {
            if (empty($inlineBuffer)) {
                return;
            }
            $topOpen = false;
            if ($prevMarginBottom > 0.0) {
                $cursorY += $prevMarginBottom;
                $prevMarginBottom = 0.0;
            }
            [$bandLeft, $bandRight] = $getBandAtY($cursorY, 14.0);
            $bandWidth = max(10.0, $bandRight - $bandLeft);
            if ($leftFloats !== [] || $rightFloats !== []) {
                // Lines below the end of a float widen again (per-line wrapping around floats)
                $this->inlineLayout->bandProvider = static fn(float $y, float $h): array => $getBandAtY($y, $h);
            }
            $cursorY = $this->inlineLayout->layoutInlineSequence($container, $inlineBuffer, $bandLeft, $cursorY, $bandWidth);
            $inlineBuffer = [];
        };

        foreach ($container->children as $child) {
            if ($child->isPositionedOutOfFlow()) {
                continue;
            }

            $floatCss = strtolower(trim($child->style->get('float', 'none')));
            $clearCss = strtolower(trim($child->style->get('clear', 'none')));

            if ($clearCss !== 'none' && $clearCss !== '') {
                $topOpen = false;
            }
            if ($clearCss === 'left' || $clearCss === 'both') {
                $flushInlineBuffer();
                foreach ($leftFloats as $lf) {
                    if ($lf['bottom'] > $cursorY) {
                        $cursorY = $lf['bottom'];
                    }
                }
            }
            if ($clearCss === 'right' || $clearCss === 'both') {
                $flushInlineBuffer();
                foreach ($rightFloats as $rf) {
                    if ($rf['bottom'] > $cursorY) {
                        $cursorY = $rf['bottom'];
                    }
                }
            }

            if ($floatCss === 'left' || $floatCss === 'right') {
                $flushInlineBuffer();
                $topOpen = false;
                $this->resolveBoxMetrics($child, $contentWidth);

                $wCss = strtolower(trim($child->style->get('width', 'auto')));
                if ($wCss === 'auto' || $wCss === '') {
                    $intr = $this->measureIntrinsicWidth($child, $contentWidth);
                    $child->forcedBorderBoxWidth = $this->clampToMinMaxWidth($child, min($contentWidth, $intr['max']), $contentWidth);
                } else {
                    $child->forcedBorderBoxWidth = $this->computeBorderBoxWidth($child, $contentWidth);
                }
                $child->forcedBorderBoxHeight = null;
                $this->layoutElement($child, 0.0, 0.0, $contentWidth);

                $outerW = $child->getOuterWidth();
                $outerH = $child->getOuterHeight();
                $placeY = $cursorY;

                // Step down if current horizontal band between floats is narrower than $outerW
                for ($attempt = 0; $attempt < 20; $attempt++) {
                    [$bLeft, $bRight] = $getBandAtY($placeY, max(1.0, $outerH));
                    if (($bRight - $bLeft) >= $outerW - 0.1 || ($bLeft <= $contentX + 0.01 && $bRight >= $contentX + $contentWidth - 0.01)) {
                        break;
                    }
                    $nextY = null;
                    foreach (array_merge($leftFloats, $rightFloats) as $fl) {
                        if ($fl['bottom'] > $placeY + 0.01 && ($nextY === null || $fl['bottom'] < $nextY)) {
                            $nextY = $fl['bottom'];
                        }
                    }
                    if ($nextY === null) {
                        break;
                    }
                    $placeY = $nextY;
                }

                [$bLeft, $bRight] = $getBandAtY($placeY, max(1.0, $outerH));
                $targetY = $placeY + $child->marginTop;
                if ($floatCss === 'left') {
                    $targetX = $bLeft + $child->marginLeft;
                    $leftFloats[] = [
                        'left' => $bLeft,
                        'right' => $bLeft + $outerW,
                        'top' => $placeY,
                        'bottom' => $placeY + $outerH,
                    ];
                } else {
                    $targetX = max($bLeft, $bRight - $child->marginRight - $child->width);
                    $rightFloats[] = [
                        'left' => max($bLeft, $bRight - $outerW),
                        'right' => $bRight,
                        'top' => $placeY,
                        'bottom' => $placeY + $outerH,
                    ];
                }

                $child->translate($targetX - $child->x, $targetY - $child->y);
                if ($placeY + $outerH > $maxFloatBottom) {
                    $maxFloatBottom = $placeY + $outerH;
                }
                continue;
            }

            if ($child->isInlineLevel()) {
                $inlineBuffer[] = $child;
                continue;
            }

            $flushInlineBuffer();

            $this->resolveBoxMetrics($child, $contentWidth);

            // Collapse adjacent vertical margins between block siblings
            $collapsedMarginTop = max($prevMarginBottom, $child->marginTop);
            if ($prevMarginBottom < 0.0 || $child->marginTop < 0.0) {
                $collapsedMarginTop = $prevMarginBottom + $child->marginTop;
            }

            $childY = $cursorY + $collapsedMarginTop;
            [$bandLeft, $bandRight] = $getBandAtY($childY, 20.0);
            $bandWidth = max(0.0, $bandRight - $bandLeft);

            // Compute child width and handle horizontal `margin: auto` centering
            $child->forcedBorderBoxWidth = null;
            $child->forcedBorderBoxHeight = null;
            $childBorderBoxW = $this->computeBorderBoxWidth($child, $bandWidth);
            $child->forcedBorderBoxWidth = $childBorderBoxW;

            $mlCss = strtolower(trim($child->style->get('margin-left', '0')));
            $mrCss = strtolower(trim($child->style->get('margin-right', '0')));
            $freeW = max(0.0, $bandWidth - $childBorderBoxW);

            if ($mlCss === 'auto' && $mrCss === 'auto') {
                $child->marginLeft = $freeW / 2.0;
                $child->marginRight = $freeW / 2.0;
            } elseif ($mlCss === 'auto') {
                $child->marginLeft = max(0.0, $freeW - $child->marginRight);
            } elseif ($mrCss === 'auto') {
                $child->marginRight = max(0.0, $freeW - $child->marginLeft);
            }

            $childX = $bandLeft + $child->marginLeft;
            $this->layoutElement($child, $childX, $childY, $bandWidth);

            // Margins that escaped from the child's own first / last children join the child's margins
            $childTopEff = self::collapseMargins($child->marginTop, $child->collapsedMarginTop);
            $childBottomEff = self::collapseMargins($child->marginBottom, $child->collapsedMarginBottom);

            if ($this->isEmptyCollapsibleBlock($child)) {
                // An empty block's top and bottom margins collapse together and with its neighbours
                $prevMarginBottom = self::collapseMargins($prevMarginBottom, $childTopEff, $childBottomEff);
                $child->translate(0.0, ($topOpen ? $cursorY : $cursorY + $prevMarginBottom) - $child->y);
                continue;
            }

            $topMargin = self::collapseMargins($prevMarginBottom, $childTopEff);
            if ($topOpen) {
                // The container has nothing above this child: the margin escapes through the container's top edge
                $container->collapsedMarginTop = $topMargin;
                $topMargin = 0.0;
                $topOpen = false;
            }
            $targetY = $cursorY + $topMargin;
            if (abs($targetY - $child->y) > 0.0001) {
                $child->translate(0.0, $targetY - $child->y);
            }

            $cursorY = $targetY + $child->height;
            $prevMarginBottom = $childBottomEff;
        }

        $flushInlineBuffer();
        if ($topOpen) {
            // Only empty blocks inside: their collapsed margin passes through the container
            $container->collapsedMarginTop = $prevMarginBottom;
            $prevMarginBottom = 0.0;
        } elseif ($bottomOpen && $maxFloatBottom <= $cursorY + 0.01) {
            $container->collapsedMarginBottom = $prevMarginBottom;
            $prevMarginBottom = 0.0;
        }
        $cursorY += $prevMarginBottom;

        return max(0.0, max($cursorY, $maxFloatBottom) - $contentY);
    }

    /** Adjoining vertical margins collapse to the largest positive plus the most negative one. */
    private static function collapseMargins(float ...$margins): float
    {
        $pos = 0.0;
        $neg = 0.0;
        foreach ($margins as $m) {
            $pos = max($pos, $m);
            $neg = min($neg, $m);
        }
        return $pos + $neg;
    }

    private function hasAutoHeight(LayoutBox $box): bool
    {
        $h = strtolower(trim($box->style->get('height', 'auto')));
        $minH = strtolower(trim($box->style->get('min-height', '0')));
        return ($h === 'auto' || $h === '')
            && ($minH === '' || $minH === 'auto' || $minH === 'none' || (is_numeric(rtrim($minH, 'ptxem%')) && (float) $minH === 0.0));
    }

    /**
     * True when $box takes part in its parent's margin collapsing with its children, i.e. it is an in-flow
     * normal-flow block that does not establish a new block formatting context.
     */
    private function createsNoBlockContext(LayoutBox $box): bool
    {
        $parent = $box->parent;
        if ($parent === null || $box->isPositionedOutOfFlow() || $box->isTextNode() || $box->columnFlow !== null) {
            return false;
        }
        $display = $box->getDisplay();
        if ($display !== 'block' && $display !== 'list-item') {
            return false;
        }
        $pd = $parent->getDisplay();
        if ($pd !== 'block' && $pd !== 'list-item') {
            return false; // flex / grid / table / inline-block parents make this box a BFC root or a cell
        }
        if ($parent->columnFlow !== null || $this->columnLayout->isMulticol($parent) || $this->columnLayout->isMulticol($box)) {
            return false;
        }
        $style = $box->style;
        if (strtolower(trim($style->get('float', 'none'))) !== 'none') {
            return false;
        }
        foreach (['overflow', 'overflow-x', 'overflow-y'] as $prop) {
            $v = strtolower(trim($style->get($prop, 'visible')));
            if ($v !== 'visible' && $v !== '') {
                return false;
            }
        }
        $pos = strtolower(trim($style->get('position', 'static')));
        if ($pos !== 'static' && $pos !== 'relative' && $pos !== '') {
            return false;
        }
        return $box->formControl === null && !in_array($box->tagName, ['img', 'svg', 'hr', 'br', 'table'], true);
    }

    /** A block without content, height, padding or border: its own top and bottom margins collapse through it. */
    private function isEmptyCollapsibleBlock(LayoutBox $box): bool
    {
        if ($box->height > 0.01 || $box->getVerticalSpacing() > 0.0 || $box->lines !== [] || !$this->createsNoBlockContext($box)) {
            return false;
        }
        if (!$this->hasAutoHeight($box) || $box->forcedBorderBoxHeight !== null) {
            return false;
        }
        foreach ($box->children as $c) {
            if (!$c->isPositionedOutOfFlow() && !$c->isTextNode() && $c->height > 0.01) {
                return false;
            }
        }
        return true;
    }

    /**
     * Lays out `position: absolute` and `position: fixed` children of a container.
     */
    private function layoutPositionedChildren(LayoutBox $container): void
    {
        foreach ($container->children as $child) {
            if (!$child->isPositionedOutOfFlow()) {
                continue;
            }

            $pos = strtolower($child->style->get('position', 'absolute'));
            $refWidth = ($pos === 'fixed') ? $this->viewportWidthPt : $container->width;
            $refHeight = ($pos === 'fixed') ? $this->viewportHeightPt : $container->height;
            $refX = ($pos === 'fixed') ? 0.0 : $container->x;
            $refY = ($pos === 'fixed') ? 0.0 : $container->y;

            $this->resolveBoxMetrics($child, $refWidth);

            $leftCss = strtolower(trim($child->style->get('left', 'auto')));
            $rightCss = strtolower(trim($child->style->get('right', 'auto')));
            $topCss = strtolower(trim($child->style->get('top', 'auto')));
            $bottomCss = strtolower(trim($child->style->get('bottom', 'auto')));
            $widthCss = strtolower(trim($child->style->get('width', 'auto')));

            if ($widthCss === 'auto') {
                if ($leftCss !== 'auto' && $rightCss !== 'auto') {
                    $lPt = $this->resolveLength($leftCss, $refWidth, $child->fontSizePt);
                    $rPt = $this->resolveLength($rightCss, $refWidth, $child->fontSizePt);
                    $child->forcedBorderBoxWidth = max(0.0, $refWidth - $lPt - $rPt - $child->marginLeft - $child->marginRight);
                } else {
                    $intr = $this->measureIntrinsicWidth($child, $refWidth);
                    $child->forcedBorderBoxWidth = $this->clampToMinMaxWidth($child, min($refWidth, $intr['max']), $refWidth);
                }
            } else {
                $child->forcedBorderBoxWidth = null;
            }

            $this->layoutElement($child, 0.0, 0.0, $refWidth);

            // Calculate X
            if ($leftCss !== 'auto') {
                $targetX = $refX + $this->resolveLength($leftCss, $refWidth, $child->fontSizePt) + $child->marginLeft;
            } elseif ($rightCss !== 'auto') {
                $rPt = $this->resolveLength($rightCss, $refWidth, $child->fontSizePt);
                $targetX = $refX + $refWidth - $rPt - $child->marginRight - $child->width;
            } else {
                $targetX = $container->getContentX() + $child->marginLeft;
            }

            // Calculate Y
            if ($topCss !== 'auto') {
                $targetY = $refY + $this->resolveLength($topCss, $refHeight, $child->fontSizePt) + $child->marginTop;
            } elseif ($bottomCss !== 'auto') {
                $bPt = $this->resolveLength($bottomCss, $refHeight, $child->fontSizePt);
                $targetY = $refY + $refHeight - $bPt - $child->marginBottom - $child->height;
            } else {
                $targetY = $container->getContentY() + $child->marginTop;
            }

            $child->translate($targetX - $child->x, $targetY - $child->y);
        }
    }

    private function applyRelativeOffset(LayoutBox $box, float $containingWidth): void
    {
        if (strtolower($box->style->get('position', 'static')) !== 'relative') {
            return;
        }
        $dx = 0.0;
        $dy = 0.0;

        $leftCss = strtolower(trim($box->style->get('left', 'auto')));
        $rightCss = strtolower(trim($box->style->get('right', 'auto')));
        $topCss = strtolower(trim($box->style->get('top', 'auto')));
        $bottomCss = strtolower(trim($box->style->get('bottom', 'auto')));

        if ($leftCss !== 'auto') {
            $dx = $this->resolveLength($leftCss, $containingWidth, $box->fontSizePt);
        } elseif ($rightCss !== 'auto') {
            $dx = -$this->resolveLength($rightCss, $containingWidth, $box->fontSizePt);
        }

        if ($topCss !== 'auto') {
            $dy = $this->resolveLength($topCss, $containingWidth, $box->fontSizePt);
        } elseif ($bottomCss !== 'auto') {
            $dy = -$this->resolveLength($bottomCss, $containingWidth, $box->fontSizePt);
        }

        $box->translate($dx, $dy);
    }

    private function layoutImageBox(LayoutBox $box, float $containingWidth): void
    {
        $imgData = $box->imageSrc !== null ? $this->imageLoader->load($box->imageSrc) : null;
        $intrinsicW = $imgData !== null ? ($imgData['width'] * CssUnit::PX_TO_PT) : 100.0;
        $intrinsicH = $imgData !== null ? ($imgData['height'] * CssUnit::PX_TO_PT) : 60.0;
        $ratio = ($intrinsicH > 0.0) ? ($intrinsicW / $intrinsicH) : 1.0;

        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        $hCss = strtolower(trim($box->style->get('height', 'auto')));

        $hasExplicitW = ($wCss !== 'auto' && $wCss !== '');
        $hasExplicitH = ($hCss !== 'auto' && $hCss !== '');

        if ($hasExplicitW && $hasExplicitH) {
            $w = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
            $h = $this->resolveBoxVerticalDimension($box, $hCss, $containingWidth);
        } elseif ($hasExplicitW) {
            $w = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
            $contentW = max(0.0, $w - $box->getHorizontalSpacing());
            $h = ($contentW / $ratio) + $box->getVerticalSpacing();
        } elseif ($hasExplicitH) {
            $h = $this->resolveBoxVerticalDimension($box, $hCss, $containingWidth);
            $contentH = max(0.0, $h - $box->getVerticalSpacing());
            $w = ($contentH * $ratio) + $box->getHorizontalSpacing();
        } else {
            $w = min($containingWidth, $intrinsicW + $box->getHorizontalSpacing());
            $contentW = max(0.0, $w - $box->getHorizontalSpacing());
            $h = ($contentW / $ratio) + $box->getVerticalSpacing();
        }

        $maxWidthCss = strtolower(trim($box->style->get('max-width', 'none')));
        if ($maxWidthCss !== 'none' && $maxWidthCss !== '') {
            $maxW = $this->resolveBoxHorizontalDimension($box, $maxWidthCss, $containingWidth);
            if ($w > $maxW) {
                $w = $maxW;
                if (!$hasExplicitH) {
                    $contentW = max(0.0, $w - $box->getHorizontalSpacing());
                    $h = ($contentW / $ratio) + $box->getVerticalSpacing();
                }
            }
        }

        $maxHeightCss = strtolower(trim($box->style->get('max-height', 'none')));
        if ($maxHeightCss !== 'none' && $maxHeightCss !== '' && !str_ends_with($maxHeightCss, '%')) {
            $maxH = $this->resolveBoxVerticalDimension($box, $maxHeightCss, $containingWidth);
            if ($h > $maxH && $maxH > 0.0) {
                $h = $maxH;
                if (!$hasExplicitW) {
                    $w = (max(0.0, $h - $box->getVerticalSpacing()) * $ratio) + $box->getHorizontalSpacing();
                }
            }
        }

        $box->width = max(0.0, $w);
        $box->height = max(0.0, $h);
    }

    /**
     * Sizes a form control (input/textarea/select/button) like a replaced inline-block: CSS width/height win,
     * otherwise browser-like intrinsic sizes apply (see FormControls::defaultSize()).
     */
    private function layoutFormControlBox(LayoutBox $box, float $containingWidth): void
    {
        $fc = new FormControls($this->fontManager);
        $def = $fc->defaultSize($box);
        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        $hCss = strtolower(trim($box->style->get('height', 'auto')));

        if ($wCss !== 'auto' && $wCss !== '' && !str_contains($wCss, 'content')) {
            $w = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
        } elseif ($box->forcedBorderBoxWidth !== null) {
            $w = $box->forcedBorderBoxWidth;
        } else {
            $w = $def['w'];
        }
        if ($hCss !== 'auto' && $hCss !== '' && !str_ends_with($hCss, '%')) {
            $h = $this->resolveBoxVerticalDimension($box, $hCss, $containingWidth);
        } elseif ($box->forcedBorderBoxHeight !== null) {
            $h = $box->forcedBorderBoxHeight;
        } else {
            $h = $def['h'];
        }
        $maxWCss = strtolower(trim($box->style->get('max-width', 'none')));
        if ($maxWCss !== 'none' && $maxWCss !== '') {
            $w = min($w, $this->resolveBoxHorizontalDimension($box, $maxWCss, $containingWidth));
        }
        $minWCss = strtolower(trim($box->style->get('min-width', '0')));
        if ($minWCss !== '0' && $minWCss !== 'auto' && $minWCss !== '') {
            $w = max($w, $this->resolveBoxHorizontalDimension($box, $minWCss, $containingWidth));
        }
        $box->width = max(0.0, $w);
        $box->height = max(0.0, $h);

        // Single-line controls keep their text vertically centred; multi-line ones start at the top
        $c = $box->formControl;
        $baseline = $def['baseline'];
        if ($c !== null && !$c->isCheckable()) {
            [$lineH, $asc] = $fc->lineMetrics($box);
            $contentH = max(0.0, $box->height - $box->getVerticalSpacing());
            $multi = $c->kind === FormControl::TEXTAREA || $c->isListBox();
            $baseline = $box->borderTop + $box->paddingTop + ($multi ? 0.0 : max(0.0, ($contentH - $lineH) / 2.0)) + $asc;
        } else {
            $baseline = $box->height;
        }
        $box->formBaseline = $baseline;
    }

    private function layoutSvgBox(LayoutBox $box, float $containingWidth): void
    {
        $svgEl = $box->domElement;
        $intrinsicW = 120.0;
        $intrinsicH = 80.0;

        if ($svgEl !== null) {
            if ($svgEl->hasAttribute('viewBox')) {
                $vbParts = preg_split('/[\s,]+/', trim($svgEl->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY);
                if ($vbParts && count($vbParts) >= 4) {
                    $intrinsicW = max(1.0, ((float) $vbParts[2]) * CssUnit::PX_TO_PT);
                    $intrinsicH = max(1.0, ((float) $vbParts[3]) * CssUnit::PX_TO_PT);
                }
            }
            if ($svgEl->hasAttribute('width')) {
                $wAttr = trim($svgEl->getAttribute('width'));
                if (!str_ends_with($wAttr, '%')) {
                    $intrinsicW = $this->resolveLength($wAttr, $containingWidth, $box->fontSizePt);
                }
            }
            if ($svgEl->hasAttribute('height')) {
                $hAttr = trim($svgEl->getAttribute('height'));
                if (!str_ends_with($hAttr, '%')) {
                    $intrinsicH = $this->resolveLength($hAttr, $containingWidth, $box->fontSizePt);
                }
            }
        }

        $ratio = ($intrinsicH > 0.0) ? ($intrinsicW / $intrinsicH) : 1.5;
        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        $hCss = strtolower(trim($box->style->get('height', 'auto')));

        if ($wCss !== 'auto' && $wCss !== '') {
            $box->width = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
        } else {
            $box->width = min($containingWidth, $intrinsicW + $box->getHorizontalSpacing());
        }

        if ($hCss !== 'auto' && $hCss !== '') {
            $box->height = $this->resolveBoxVerticalDimension($box, $hCss, $containingWidth);
        } else {
            $contentW = max(0.0, $box->width - $box->getHorizontalSpacing());
            $box->height = ($contentW / $ratio) + $box->getVerticalSpacing();
        }
    }

    /**
     * Resolves fontSizePt, margins, borders, and paddings on a LayoutBox.
     */
    public function resolveBoxMetrics(LayoutBox $box, float $referenceWidth): void
    {
        $parentFontSize = $box->parent !== null ? $box->parent->fontSizePt : $this->rootFontSizePt;
        $box->fontSizePt = $this->resolveFontSize($box->style, $parentFontSize);
        $fs = $box->fontSizePt;

        $box->marginTop = $this->resolveLength($box->style->get('margin-top', '0'), $referenceWidth, $fs);
        $box->marginRight = $this->resolveLength($box->style->get('margin-right', '0'), $referenceWidth, $fs);
        $box->marginBottom = $this->resolveLength($box->style->get('margin-bottom', '0'), $referenceWidth, $fs);
        $box->marginLeft = $this->resolveLength($box->style->get('margin-left', '0'), $referenceWidth, $fs);

        $box->paddingTop = max(0.0, $this->resolveLength($box->style->get('padding-top', '0'), $referenceWidth, $fs));
        $box->paddingRight = max(0.0, $this->resolveLength($box->style->get('padding-right', '0'), $referenceWidth, $fs));
        $box->paddingBottom = max(0.0, $this->resolveLength($box->style->get('padding-bottom', '0'), $referenceWidth, $fs));
        $box->paddingLeft = max(0.0, $this->resolveLength($box->style->get('padding-left', '0'), $referenceWidth, $fs));

        $box->borderTop = $this->resolveBorderWidth($box->style, 'top', $fs);
        $box->borderRight = $this->resolveBorderWidth($box->style, 'right', $fs);
        $box->borderBottom = $this->resolveBorderWidth($box->style, 'bottom', $fs);
        $box->borderLeft = $this->resolveBorderWidth($box->style, 'left', $fs);
    }

    private function resolveBorderWidth(ComputedStyle $style, string $side, float $fontSizePt): float
    {
        $bs = strtolower(trim($style->get("border-{$side}-style", 'none')));
        if ($bs === 'none' || $bs === 'hidden' || $bs === '') {
            return 0.0;
        }
        return max(0.0, $this->resolveLength($style->get("border-{$side}-width", '0'), 0.0, $fontSizePt));
    }

    /**
     * Applies the box's max-width and then min-width (min wins, as in CSS) to a border-box width that was
     * computed from content (shrink-to-fit: inline-block, float, absolute, flex/grid items, table cells).
     */
    public function clampToMinMaxWidth(LayoutBox $box, float $width, float $containingWidth): float
    {
        $maxWCss = strtolower(trim($box->style->get('max-width', 'none')));
        if ($maxWCss !== 'none' && $maxWCss !== '' && !str_contains($maxWCss, 'content')) {
            $width = min($width, $this->resolveBoxHorizontalDimension($box, $maxWCss, $containingWidth));
        }
        $minWCss = strtolower(trim($box->style->get('min-width', '0')));
        if ($minWCss !== '0' && $minWCss !== 'auto' && $minWCss !== '' && !str_contains($minWCss, 'content')) {
            $width = max($width, $this->resolveBoxHorizontalDimension($box, $minWCss, $containingWidth));
        }
        return max(0.0, $width);
    }

    private function computeBorderBoxWidth(LayoutBox $box, float $containingWidth): float
    {
        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        if (in_array($wCss, ['fit-content', 'max-content', 'min-content', '-moz-fit-content', '-webkit-fit-content'], true)) {
            $intr = $this->measureIntrinsicWidth($box, $containingWidth, true);
            $avail = max(0.0, $containingWidth - $box->marginLeft - $box->marginRight);
            $w = match ($wCss) {
                'max-content' => $intr['max'],
                'min-content' => $intr['min'],
                default => min($avail, max($intr['min'], $intr['max'])),
            };
        } elseif ($wCss !== 'auto' && $wCss !== '') {
            $w = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
        } else {
            $w = max(0.0, $containingWidth - $box->marginLeft - $box->marginRight);
        }

        $minWCss = strtolower(trim($box->style->get('min-width', '0')));
        if ($minWCss !== '0' && $minWCss !== 'auto' && $minWCss !== '') {
            $minW = $this->resolveBoxHorizontalDimension($box, $minWCss, $containingWidth);
            $w = max($w, $minW);
        }

        $maxWCss = strtolower(trim($box->style->get('max-width', 'none')));
        if ($maxWCss !== 'none' && $maxWCss !== '') {
            $maxW = $this->resolveBoxHorizontalDimension($box, $maxWCss, $containingWidth);
            $w = min($w, $maxW);
        }

        return max(0.0, $w);
    }

    public function resolveBoxHorizontalDimension(LayoutBox $box, string $cssValue, float $referenceWidth): float
    {
        $rawPt = $this->resolveLength($cssValue, $referenceWidth, $box->fontSizePt);
        $boxSizing = strtolower($box->style->get('box-sizing', 'border-box'));
        if ($boxSizing === 'content-box') {
            return $rawPt + $box->getHorizontalSpacing();
        }
        return $rawPt;
    }

    public function resolveBoxVerticalDimension(LayoutBox $box, string $cssValue, float $referenceWidth): float
    {
        $rawPt = $this->resolveLength($cssValue, $referenceWidth, $box->fontSizePt);
        $boxSizing = strtolower($box->style->get('box-sizing', 'border-box'));
        if ($boxSizing === 'content-box') {
            return $rawPt + $box->getVerticalSpacing();
        }
        return $rawPt;
    }

    public function getExplicitContentHeight(LayoutBox $box, float $referenceWidth): ?float
    {
        $hCss = strtolower(trim($box->style->get('height', 'auto')));
        $minHCss = strtolower(trim($box->style->get('min-height', '0')));

        $contentH = null;
        if ($box->forcedBorderBoxHeight !== null) {
            $contentH = max(0.0, $box->forcedBorderBoxHeight - $box->getVerticalSpacing());
        } elseif ($hCss !== 'auto' && $hCss !== '' && !str_ends_with($hCss, '%')) {
            $borderBoxH = $this->resolveBoxVerticalDimension($box, $hCss, $referenceWidth);
            $contentH = max(0.0, $borderBoxH - $box->getVerticalSpacing());
        } elseif (str_ends_with($hCss, '%') && ($pctBase = BoxSizing::percentBase($this, $box)) !== null) {
            // Percentage height against a definite containing-block height
            $contentH = max(0.0, $this->resolveBoxVerticalDimension($box, $hCss, $pctBase) - $box->getVerticalSpacing());
        } elseif (($hCss === 'auto' || $hCss === '') && ($ratio = BoxSizing::aspectRatio($box)) !== null && $box->width > 0.0) {
            // aspect-ratio: height derived from the used width
            $contentH = strtolower($box->style->get('box-sizing', 'border-box')) === 'content-box'
                ? max(0.0, $box->width - $box->getHorizontalSpacing()) / $ratio
                : max(0.0, $box->width / $ratio - $box->getVerticalSpacing());
        }

        $minIsPct = str_ends_with($minHCss, '%');
        $minBase = $minIsPct ? BoxSizing::percentBase($this, $box) : null;
        if ($minHCss !== '0' && $minHCss !== 'auto' && $minHCss !== '' && (!$minIsPct || $minBase !== null)) {
            $minBorderBoxH = $this->resolveBoxVerticalDimension($box, $minHCss, $minIsPct ? (float) $minBase : $referenceWidth);
            $minContentH = max(0.0, $minBorderBoxH - $box->getVerticalSpacing());
            $contentH = $contentH !== null ? max($contentH, $minContentH) : $minContentH;
        }

        return $contentH;
    }

    /**
     * Measures the intrinsic `min-content` and `max-content` border-box width of a LayoutBox.
     *
     * Plain inline boxes that contain a forced break (`<br>`) also report `lead` / `trail`: the widths of their
     * first and last line pieces, which join the parent's current line run instead of the whole content.
     *
     * @return array{min: float, max: float, lead?: float, trail?: float}
     */
    public function measureIntrinsicWidth(LayoutBox $box, float $referenceWidth, bool $ignoreExplicitWidth = false): array
    {
        $this->resolveBoxMetrics($box, $referenceWidth);
        $hSpace = $box->getHorizontalSpacing();

        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        if (!$ignoreExplicitWidth && $wCss !== 'auto' && $wCss !== '' && !str_ends_with($wCss, '%') && !str_contains($wCss, 'content')) {
            $fixedW = $this->resolveBoxHorizontalDimension($box, $wCss, $referenceWidth);
            return ['min' => $fixedW, 'max' => $fixedW];
        }

        if ($box->tagName === 'br') {
            return ['min' => 0.0, 'max' => 0.0, 'lead' => 0.0, 'trail' => 0.0];
        }

        if ($box->tagName === 'img') {
            $img = $box->imageSrc !== null ? $this->imageLoader->load($box->imageSrc) : null;
            $w = ($img !== null ? $img['width'] * CssUnit::PX_TO_PT : 80.0) + $hSpace;
            return ['min' => $w, 'max' => $w];
        }

        if ($box->formControl !== null) {
            $w = (new FormControls($this->fontManager))->defaultSize($box)['w'];
            return ['min' => $w, 'max' => $w];
        }

        if ($box->tagName === 'svg') {
            $w = 100.0 + $hSpace;
            if ($box->domElement !== null && $box->domElement->hasAttribute('width')) {
                $wAttr = trim($box->domElement->getAttribute('width'));
                if (!str_ends_with($wAttr, '%')) {
                    $w = $this->resolveLength($wAttr, $referenceWidth, $box->fontSizePt) + $hSpace;
                }
            }
            return ['min' => $w, 'max' => $w];
        }

        if ($box->isTextNode()) {
            $text = trim($box->textContent ?? '');
            if ($text === '') {
                return ['min' => 0.0, 'max' => 0.0];
            }
            $ff = $box->style->get('font-family', 'DejaVu Sans');
            $fw = $box->style->get('font-weight', 'normal');
            $fs = $box->style->get('font-style', 'normal');
            $ls = $this->resolveLength($box->style->get('letter-spacing', '0'), 0.0, $box->fontSizePt);

            $maxW = $this->fontManager->measureText($text, $ff, $fw, $fs, $box->fontSizePt, $ls);
            $words = preg_split('/[ \t\n\r\f]+|(?<=[\p{L}\p{N}][\-\/])(?=[\p{L}\p{N}])/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
            if (in_array(strtolower($box->style->get('white-space', 'normal')), ['nowrap', 'pre'], true)) {
                $words = [$text];
            }
            $minW = 0.0;
            foreach ($words as $word) {
                $ww = $this->fontManager->measureText($word, $ff, $fw, $fs, $box->fontSizePt, $ls);
                if ($ww > $minW) {
                    $minW = $ww;
                }
            }
            return ['min' => $minW, 'max' => $maxW];
        }

        $display = $box->getDisplay();
        if ($display === 'table' || $display === 'inline-table') {
            return TableMetrics::intrinsicWidth($this, $box, $referenceWidth);
        }
        $isFlexRow = ($display === 'flex' || $display === 'inline-flex')
            && !str_starts_with(strtolower($box->style->get('flex-direction', 'row')), 'column');

        $minContent = 0.0;
        $maxContent = 0.0;
        $inlineRunMax = 0.0;
        $lead = null; // width of the first line piece, once a forced break was seen

        foreach ($box->children as $child) {
            if ($child->isPositionedOutOfFlow()) {
                continue;
            }
            $childIntr = $this->measureIntrinsicWidth($child, $referenceWidth, $ignoreExplicitWidth);
            $childMin = $childIntr['min'] + $child->marginLeft + $child->marginRight;
            $childMax = $childIntr['max'] + $child->marginLeft + $child->marginRight;

            if ($isFlexRow) {
                $minContent += $childMin;
                $maxContent += $childMax;
            } elseif ($child->isInlineLevel() && isset($childIntr['lead'], $childIntr['trail'])) {
                // Forced break inside: its first piece ends the current line, its last piece starts the next one
                if ($childMin > $minContent) {
                    $minContent = $childMin;
                }
                $inlineRunMax += $childIntr['lead'] + $child->marginLeft;
                $lead ??= $inlineRunMax;
                $maxContent = max($maxContent, $inlineRunMax, $childMax);
                $inlineRunMax = $childIntr['trail'] + $child->marginRight;
                $maxContent = max($maxContent, $inlineRunMax);
            } elseif ($child->isInlineLevel()) {
                if ($childMin > $minContent) {
                    $minContent = $childMin;
                }
                $inlineRunMax += $childMax + ($child->isTextNode() ? 3.0 : 0.0);
                if ($inlineRunMax > $maxContent) {
                    $maxContent = $inlineRunMax;
                }
            } else {
                $inlineRunMax = 0.0;
                if ($childMin > $minContent) {
                    $minContent = $childMin;
                }
                if ($childMax > $maxContent) {
                    $maxContent = $childMax;
                }
            }
        }

        if ($isFlexRow && count($box->children) > 1) {
            $colGap = $this->resolveLength($box->style->get('column-gap', '0'), $referenceWidth, $box->fontSizePt);
            $gaps = (count($box->children) - 1) * $colGap;
            $minContent += $gaps;
            $maxContent += $gaps;
        }

        if ($this->columnLayout->isMulticol($box)) {
            $adj = $this->columnLayout->adjustIntrinsic($box, ['min' => $minContent, 'max' => max($minContent, $maxContent)], $referenceWidth);
            return ['min' => $adj['min'] + $hSpace, 'max' => $adj['max'] + $hSpace];
        }

        $result = [
            'min' => $minContent + $hSpace,
            'max' => max($minContent, $maxContent) + $hSpace,
        ];
        if ($lead !== null && $display === 'inline' && !$isFlexRow) {
            $result['lead'] = $lead + $hSpace / 2;
            $result['trail'] = $inlineRunMax + $hSpace / 2;
        }
        return $result;
    }

    public function resolveFontSize(ComputedStyle $style, float $parentFontSizePt): float
    {
        return $style->getFontSizePt();
    }

    public function resolveLength(string $value, float $referenceSizePt, float $fontSizePt): float
    {
        return CssUnit::toPt(
            $value,
            $referenceSizePt,
            $fontSizePt,
            $this->rootFontSizePt,
            0.0,
            $this->viewportWidthPt,
            $this->viewportHeightPt
        );
    }
}
