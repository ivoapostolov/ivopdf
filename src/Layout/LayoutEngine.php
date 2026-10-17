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

    /**
     * Floats of an enclosing block formatting context that intrude into the next block laid out by layoutElement()
     * (a block that starts beside a float keeps its full width; only its line boxes are shortened, line by line):
     * [left floats, right floats] in page coordinates. Consumed by the next layoutElement() call.
     *
     * @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}|null
     */
    private ?array $pendingFloats = null;
    /** @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}|null pendingFloats handed to layoutBlockChildren() */
    private ?array $inheritedFloats = null;

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
    public function layoutDocument(LayoutBox $root, float $startX, float $startY, float $printableWidth, bool $bodyMargins = false): void
    {
        $this->rootFontSizePt = $this->resolveFontSize($root->style, $this->options->defaultFontSize);
        if (!$bodyMargins) {
            $root->forcedBorderBoxWidth = $printableWidth;
            $this->layoutElement($root, $startX, $startY, $printableWidth);
            return;
        }
        // The document body keeps its margins (UA default 8px, like browsers and dompdf). Its top margin collapses
        // with the first child's top margin, as the body is not a block formatting context root in a browser.
        $this->resolveBoxMetrics($root, $printableWidth);
        $ml = $root->marginLeft;
        $mr = $root->marginRight;
        $root->forcedBorderBoxWidth = max(0.0, $printableWidth - $ml - $mr);
        $this->collapseRoot = $root;
        $this->mainRoot = $root;
        $this->pageAreaBoxes = [];
        try {
            $this->layoutElement($root, $startX + $ml, $startY, $printableWidth);
        } finally {
            $this->collapseRoot = null;
        }
        $top = self::collapseMargins($root->marginTop, $root->collapsedMarginTop);
        if (abs($top) > 0.0001) {
            $root->translate(0.0, $top);
            foreach ($this->pageAreaBoxes as $anchored) {
                $anchored->translate(0.0, -$top); // placed in the page area, not in the body: they keep their place
            }
        }
        $this->pageAreaBoxes = [];
    }

    /** @var list<LayoutBox> boxes positioned in the page area (fixed, or absolute without a positioned ancestor) */
    private array $pageAreaBoxes = [];

    /** @var array{0: float, 1: float, 2: float, 3: float}|null containing block of position: fixed [x, y, width, height] */
    private ?array $fixedArea = null;

    /** Positions `position: fixed` boxes in this area (the page area of the first page) instead of the whole sheet. */
    public function setFixedContainingBlock(?array $area): void
    {
        $this->fixedArea = $area;
    }

    /** The document body of the main layout: without a positioned ancestor, absolute boxes use the page area. */
    private ?LayoutBox $mainRoot = null;

    /** The document body while layoutDocument() lays it out with its margins (its first child's top margin escapes). */
    private ?LayoutBox $collapseRoot = null;

    /** Horizontal margins of the document body, as layoutDocument(..., true) applies them. */
    public function bodyHorizontalMargins(LayoutBox $root, float $printableWidth): float
    {
        $this->resolveBoxMetrics($root, $printableWidth);
        return $root->marginLeft + $root->marginRight;
    }

    /**
     * Lays out a single element box at border-box position ($x, $y) within a containing block of $containingWidth.
     */
    public function layoutElement(LayoutBox $box, float $x, float $y, float $containingWidth): void
    {
        \IvoPdf\RenderLimits::tick();
        $outerFloats = $this->pendingFloats;
        $this->pendingFloats = null;
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
        } elseif ($box->tagName === 'fieldset' && ($legend = $this->renderedLegend($box)) !== null) {
            $contentHeight = $this->layoutFieldset($box, $legend);
        } else {
            $contentHeight = $this->columnLayout->isMulticol($box) ? $this->columnLayout->layout($box) : null;
            if ($contentHeight === null) {
                $this->inheritedFloats = $outerFloats;
                $contentHeight = $this->layoutBlockChildren($box);
            }
        }

        // Resolve final border-box height
        $fixed = false;
        if ($box->forcedBorderBoxHeight !== null) {
            $box->height = max($contentHeight + $box->getVerticalSpacing(), $box->forcedBorderBoxHeight);
        } else {
            $explicitContentH = $this->getExplicitContentHeight($box, $containingWidth);
            $finalContentH = $explicitContentH !== null ? max($contentHeight, $explicitContentH) : $contentHeight;
            $fixed = $explicitContentH !== null && $this->hasDefiniteHeight($box, $display);
            if ($fixed) {
                // A definite height is the used height whatever the overflow: content that does not fit overflows
                // the box (painted outside it, or clipped by overflow: hidden) and the following content starts
                // after the declared height, as in browsers. min-height is already in $explicitContentH.
                $finalContentH = $explicitContentH;
            }
            $box->height = $finalContentH + $box->getVerticalSpacing();
        }
        $this->applyMaxHeight($box, $containingWidth);
        $box->fixedHeight = $fixed || $box->height < $contentHeight + $box->getVerticalSpacing() - 0.01; // max-height also caps it

        // Layout out-of-flow (position: absolute | fixed) children relative to this box
        $this->layoutPositionedChildren($box);

        // Apply position: relative offset if present
        $this->applyRelativeOffset($box, $containingWidth);
    }

    /** The first child <legend> of a fieldset (in flow, not floated): it is drawn in the fieldset's top border. */
    private function renderedLegend(LayoutBox $fieldset): ?LayoutBox
    {
        foreach ($fieldset->children as $child) {
            if ($child->isTextNode()) {
                if (trim((string) $child->textContent) === '') {
                    continue;
                }
                return null;
            }
            if ($child->tagName === 'legend' && !$child->isPositionedOutOfFlow()
                && strtolower(trim($child->style->get('float', 'none'))) === 'none') {
                return $child;
            }
            return null;
        }
        return null;
    }

    /**
     * Fieldset with a legend (as browsers draw it): the legend sits at the start of the top border, the border runs
     * through the legend's middle with a gap behind it, and the content starts below the legend.
     */
    private function layoutFieldset(LayoutBox $box, LayoutBox $legend): float
    {
        $contentX = $box->getContentX();
        $contentW = $box->getContentWidth();
        $this->resolveBoxMetrics($legend, $contentW);
        $legend->forcedBorderBoxHeight = null;
        $legend->forcedBorderBoxWidth = $this->clampToMinMaxWidth(
            $legend,
            min(max(0.0, $contentW - $legend->marginLeft - $legend->marginRight), $this->measureIntrinsicWidth($legend, $contentW)['max']),
            $contentW
        );
        $this->layoutElement($legend, $contentX + $legend->marginLeft, $box->y + $legend->marginTop, $contentW);
        $legendH = $legend->getOuterHeight();

        // The top border is drawn through the middle of the legend; the content starts below it
        $extra = max(0.0, $legendH - $box->borderTop);
        $box->captionTop = $extra / 2.0;
        $box->legendGap = [$legend->x - $box->x, $legend->x + $legend->width - $box->x, 0.0, $legendH];

        $children = $box->children;
        $box->children = array_values(array_filter($children, static fn(LayoutBox $c): bool => $c !== $legend));
        $box->paddingTop += $extra;
        try {
            $h = $this->layoutBlockChildren($box);
        } finally {
            $box->children = $children;
        }
        return $h;
    }

    /**
     * Whether the box's `height` is definite: a length, or a percentage of a definite containing-block height (not
     * auto / *-content). Tables only treat their height as a minimum (CSS 2.1 17.5.3), as do flex / grid items and
     * table cells sized by their parent layout.
     */
    private function hasDefiniteHeight(LayoutBox $box, string $display): bool
    {
        if ($box->forcedBorderBoxHeight !== null || $display === 'table' || $display === 'inline-table') {
            return false;
        }
        if (in_array($display, ['table-cell', 'table-row', 'table-row-group', 'table-header-group', 'table-footer-group'], true)) {
            return false; // a cell / row height is a minimum: the row grows to its content (CSS 2.1 17.5.3)
        }
        $hCss = strtolower(trim($box->style->get('height', 'auto')));
        if ($hCss === '' || $hCss === 'auto' || str_contains($hCss, 'content') || str_contains($hCss, 'stretch') || str_contains($hCss, 'fill')) {
            return false;
        }
        return !str_contains($hCss, '%') || BoxSizing::percentBase($this, $box) !== null;
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
        $topOpen = ($canCollapse || ($container === $this->collapseRoot && $container->getDisplay() === 'block'))
            && $container->borderTop <= 0.0 && $container->paddingTop <= 0.0;
        $bottomOpen = $canCollapse && $container->borderBottom <= 0.0 && $container->paddingBottom <= 0.0
            && $container->forcedBorderBoxHeight === null && $this->hasAutoHeight($container);

        // Floats of the enclosing block formatting context beside this block: its lines wrap around them too.
        // Floats are keyed by a stable id (the handles of $moveFloat refer to it). Floats that end above the point
        // where the last float was placed are retired from the active lists (each query would otherwise scan every
        // float: quadratic for thousands of floats); queries above $retireY still see them.
        $inherited = $this->inheritedFloats;
        $this->inheritedFloats = null;
        $nextFloatId = 0;
        /** @var array<int, array<string, mixed>> $leftFloats */
        $leftFloats = [];
        /** @var array<int, array<string, mixed>> $rightFloats */
        $rightFloats = [];
        foreach ($inherited[0] ?? [] as $f) {
            $f['id'] = $nextFloatId;
            $leftFloats[$nextFloatId++] = $f;
        }
        foreach ($inherited[1] ?? [] as $f) {
            $f['id'] = $nextFloatId;
            $rightFloats[$nextFloatId++] = $f;
        }
        /** @var array<int, array<string, mixed>> $retired */
        $retired = [];
        $retireY = -INF;
        // CSS 2.1 9.5.1 rule 5: a float's top may not be higher than the top of an earlier float of this context
        $lastFloatTop = -INF;
        $lastFloatId = -1;
        foreach ([...$leftFloats, ...$rightFloats] as $f) {
            $lastFloatTop = max($lastFloatTop, $f['top']);
        }

        // All floats that may still reach below $y, as [left, right] lists
        $floatsBelow = static function (float $y) use (&$leftFloats, &$rightFloats, &$retired, &$retireY): array {
            if ($y >= $retireY || $retired === []) {
                return [$leftFloats, $rightFloats];
            }
            $l = $leftFloats;
            $r = $rightFloats;
            foreach ($retired as $id => $f) {
                if (!isset($l[$id]) && !isset($r[$id])) {
                    if ($f['side'] === 'left') {
                        $l[$id] = $f;
                    } else {
                        $r[$id] = $f;
                    }
                }
            }
            return [$l, $r];
        };

        $getBandAtY = static function (float $y, float $h) use ($contentX, $contentWidth, &$leftFloats, &$rightFloats, &$retireY, $floatsBelow): array {
            $leftEdge = $contentX;
            $rightEdge = $contentX + $contentWidth;
            $yEnd = $y + max(1.0, $h);
            [$lfs, $rfs] = $y >= $retireY ? [$leftFloats, $rightFloats] : $floatsBelow($y);
            foreach ($lfs as $lf) {
                if ($lf['bottom'] > $y + 0.01 && $lf['top'] < $yEnd - 0.01) {
                    if ($lf['right'] > $leftEdge) {
                        $leftEdge = $lf['right'];
                    }
                }
            }
            foreach ($rfs as $rf) {
                if ($rf['bottom'] > $y + 0.01 && $rf['top'] < $yEnd - 0.01) {
                    if ($rf['left'] < $rightEdge) {
                        $rightEdge = $rf['left'];
                    }
                }
            }
            return [$leftEdge, max($leftEdge, $rightEdge)];
        };

        // Places a laid-out float at the first position at or below $placeY where it fits beside earlier floats and
        // registers it for the line bands; returns a handle for $moveFloat: [side, id, y]
        $placeFloat = static function (LayoutBox $child, float $placeY) use (&$leftFloats, &$rightFloats, &$retired, &$retireY, &$nextFloatId, &$lastFloatTop, &$lastFloatId, &$maxFloatBottom, $getBandAtY, $floatsBelow, $contentX, $contentWidth): array {
            $placeY = max($placeY, $lastFloatTop);
            if ($placeY > $retireY && count($leftFloats) + count($rightFloats) > 32) {
                foreach ($leftFloats as $id => $f) {
                    if ($f['bottom'] <= $placeY) {
                        $f['side'] = 'left';
                        $retired[$id] = $f;
                        unset($leftFloats[$id]);
                    }
                }
                foreach ($rightFloats as $id => $f) {
                    if ($f['bottom'] <= $placeY) {
                        $f['side'] = 'right';
                        $retired[$id] = $f;
                        unset($rightFloats[$id]);
                    }
                }
                $retireY = $placeY;
            }
            $outerW = $child->getOuterWidth();
            $outerH = $child->getOuterHeight();
            $isLeft = strtolower(trim($child->style->get('float', 'none'))) === 'left';
            // Step down if the current horizontal band between floats is narrower than the float
            for ($attempt = 0; $attempt < 20; $attempt++) {
                [$bLeft, $bRight] = $getBandAtY($placeY, max(1.0, $outerH));
                if (($bRight - $bLeft) >= $outerW - 0.1 || ($bLeft <= $contentX + 0.01 && $bRight >= $contentX + $contentWidth - 0.01)) {
                    break;
                }
                $nextY = null;
                foreach ($floatsBelow($placeY) as $list) {
                    foreach ($list as $fl) {
                        if ($fl['bottom'] > $placeY + 0.01 && ($nextY === null || $fl['bottom'] < $nextY)) {
                            $nextY = $fl['bottom'];
                        }
                    }
                }
                if ($nextY === null) {
                    break;
                }
                $placeY = $nextY;
            }

            [$bLeft, $bRight] = $getBandAtY($placeY, max(1.0, $outerH));
            $targetY = $placeY + $child->marginTop;
            $id = $nextFloatId++;
            if ($isLeft) {
                $targetX = $bLeft + $child->marginLeft;
                $leftFloats[$id] = ['id' => $id, 'left' => $bLeft, 'right' => $bLeft + $outerW, 'top' => $placeY, 'bottom' => $placeY + $outerH, 'box' => $child];
                $handle = ['left', $id, $placeY];
            } else {
                $targetX = max($bLeft, $bRight - $child->marginRight - $child->width);
                $rightFloats[$id] = ['id' => $id, 'left' => max($bLeft, $bRight - $outerW), 'right' => $bRight, 'top' => $placeY, 'bottom' => $placeY + $outerH, 'box' => $child];
                $handle = ['right', $id, $placeY];
            }
            $child->translate($targetX - $child->x, $targetY - $child->y);
            $lastFloatTop = $placeY;
            $lastFloatId = $id;
            if ($placeY + $outerH > $maxFloatBottom) {
                $maxFloatBottom = $placeY + $outerH;
            }
            return $handle;
        };
        // Moves a float placed by the inline layout to the final top of its line
        $moveFloat = static function (array $handle, float $dy) use (&$leftFloats, &$rightFloats, &$retired, &$lastFloatTop, &$lastFloatId, &$maxFloatBottom): void {
            [$side, $i] = $handle;
            if ($i === $lastFloatId) {
                $lastFloatTop += $dy;
            }
            if ($side === 'left') {
                if (!isset($leftFloats[$i]) && isset($retired[$i])) {
                    $leftFloats[$i] = $retired[$i]; // back to the active list: it may now reach below the retired zone
                    unset($retired[$i]);
                }
                $leftFloats[$i]['top'] += $dy;
                $leftFloats[$i]['bottom'] += $dy;
                $leftFloats[$i]['box']->translate(0.0, $dy);
                $maxFloatBottom = max($maxFloatBottom, $leftFloats[$i]['bottom']);
            } else {
                if (!isset($rightFloats[$i]) && isset($retired[$i])) {
                    $rightFloats[$i] = $retired[$i];
                    unset($retired[$i]);
                }
                $rightFloats[$i]['top'] += $dy;
                $rightFloats[$i]['bottom'] += $dy;
                $rightFloats[$i]['box']->translate(0.0, $dy);
                $maxFloatBottom = max($maxFloatBottom, $rightFloats[$i]['bottom']);
            }
        };

        /** @var list<LayoutBox> $inlineBuffer */
        $inlineBuffer = [];

        $flushInlineBuffer = function () use (&$inlineBuffer, $container, &$cursorY, &$prevMarginBottom, $getBandAtY, &$topOpen, &$leftFloats, &$rightFloats, &$retired, &$lastFloatTop, &$lastFloatId, &$maxFloatBottom, $placeFloat, $moveFloat): void {
            if (empty($inlineBuffer)) {
                return;
            }
            // Collapsible white space between blocks generates no line box: it must not separate their margins
            $onlySpace = true;
            foreach ($inlineBuffer as $n) {
                if (!$n->isTextNode() || trim((string) $n->textContent, " \t\n\r\f") !== ''
                    || in_array(strtolower(trim($n->style->get('white-space', 'normal'))), ['pre', 'pre-wrap', 'break-spaces'], true)) {
                    $onlySpace = false;
                    break;
                }
            }
            if ($onlySpace) {
                $inlineBuffer = [];
                return;
            }
            $topOpen = false;
            if ($prevMarginBottom > 0.0) {
                $cursorY += $prevMarginBottom;
                $prevMarginBottom = 0.0;
            }
            [$bandLeft, $bandRight] = $getBandAtY($cursorY, 14.0);
            $bandWidth = max(10.0, $bandRight - $bandLeft);
            $hasFloat = false;
            foreach ($inlineBuffer as $n) {
                if (!$n->isTextNode() && in_array(strtolower(trim($n->style->get('float', 'none'))), ['left', 'right'], true)) {
                    $hasFloat = true;
                    break;
                }
            }
            if ($hasFloat) {
                $snapshot = [$leftFloats, $rightFloats, $maxFloatBottom, $lastFloatTop, $lastFloatId];
                $this->inlineLayout->floatHooks = [
                    'place' => $placeFloat,
                    'move' => $moveFloat,
                    'reset' => static function () use (&$leftFloats, &$rightFloats, &$maxFloatBottom, &$lastFloatTop, &$lastFloatId, $snapshot): void {
                        [$leftFloats, $rightFloats, $maxFloatBottom, $lastFloatTop, $lastFloatId] = $snapshot;
                    },
                ];
            }
            if ($hasFloat || $leftFloats !== [] || $rightFloats !== [] || $retired !== []) {
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
            $isFloat = $floatCss === 'left' || $floatCss === 'right';
            $clearance = false;
            if ($clearCss === 'left' || $clearCss === 'right' || $clearCss === 'both') {
                $flushInlineBuffer();
                $clearTo = -INF;
                [$clearLeft, $clearRight] = $floatsBelow($cursorY);
                foreach ($clearCss === 'right' ? [] : $clearLeft as $lf) {
                    $clearTo = max($clearTo, $lf['bottom']);
                }
                foreach ($clearCss === 'left' ? [] : $clearRight as $rf) {
                    $clearTo = max($clearTo, $rf['bottom']);
                }
                if ($isFloat || $child->isInlineLevel()) {
                    $cursorY = max($cursorY, $clearTo);
                } else {
                    // Clearance (CSS 2.1 9.5.2): when the block's border edge, after collapsing the margins above
                    // it, would still be beside a float, it goes right below the float; the margins above it are
                    // absorbed by the clearance instead of being added on top
                    $this->resolveBoxMetrics($child, $contentWidth);
                    if ($cursorY + self::collapseMargins($prevMarginBottom, $child->marginTop) < $clearTo - 0.01) {
                        $cursorY = $clearTo;
                        $prevMarginBottom = 0.0;
                        $clearance = true;
                    }
                }
            }

            if ($isFloat) {
                // A float does not separate the container's top margin from its first in-flow child's: when that
                // margin escapes, the whole container (float included) moves down with it, as in browsers
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

                if ($inlineBuffer !== []) {
                    // A float met in the middle of inline content is placed by the inline layout at the line where it
                    // occurs (CSS 2.1 9.5.1): at the top of that line when it fits beside the line's content so far,
                    // otherwise at the top of the next line; the lines are wrapped around it
                    $inlineBuffer[] = $child;
                    continue;
                }
                // Browsers place a float below the bottom margin of the block before it (that margin is not
                // collapsed with the top margin of the block after the float, which may still move further down)
                $placeFloat($child, $cursorY + ($topOpen ? 0.0 : $prevMarginBottom));
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
            if ($clearance) {
                $collapsedMarginTop = 0.0; // the border edge sits at the bottom of the cleared floats
            }

            $childY = $cursorY + $collapsedMarginTop;
            // A block beside a float keeps the full width (browsers: the float overlaps its box, only its line boxes
            // are shortened, each by the floats beside that line); a box that establishes a new block formatting
            // context (table, overflow != visible, flow-root, ...) is placed beside the floats instead
            $beside = self::floatsFrom(...$floatsBelow($childY), y: $childY);
            $wrapsFloats = $beside !== null && !$this->establishesFormattingContext($child);
            $cbWidth = null; // containing width of a BFC box beside floats whose width does not depend on the band
            if ($wrapsFloats) {
                $bandLeft = $contentX;
                $bandWidth = $contentWidth;
            } else {
                [$bandLeft, $bandRight] = $getBandAtY($childY, 20.0);
                $bandWidth = max(0.0, $bandRight - $bandLeft);
                $need = $beside !== null ? $this->bfcWidthBesideFloats($child, $contentWidth) : null;
                if ($need !== null) {
                    // CSS 2.1 9.5 / Blink: a BFC box keeps its width (percentages of the containing block, not of the
                    // band beside the floats); it goes beside the floats only where it fits, else down below them
                    $cbWidth = $need['explicit'] ? $contentWidth : null;
                    $y = $childY;
                    for ($guard = 0; $bandWidth < $need['width'] - 0.01 && $guard < 10000; $guard++) {
                        $next = INF;
                        foreach (self::floatsFrom(...$floatsBelow($y), y: $y) ?? [[], []] as $side) {
                            foreach ($side as $f) {
                                $next = min($next, $f['bottom']);
                            }
                        }
                        if ($next === INF) {
                            break;
                        }
                        // the margins above the box stay above it at its new place
                        $cursorY += $next - $y;
                        $childY = $cursorY + $collapsedMarginTop;
                        $y = $next;
                        [$bandLeft, $bandRight] = $getBandAtY($childY, 20.0);
                        $bandWidth = max(0.0, $bandRight - $bandLeft);
                        $y = max($y, $childY);
                    }
                }
            }

            // Compute child width and handle horizontal `margin: auto` centering
            $child->forcedBorderBoxWidth = null;
            $child->forcedBorderBoxHeight = null;
            $childBorderBoxW = $this->computeBorderBoxWidth($child, $cbWidth ?? $bandWidth);
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
            } else {
                // <center> / align=center on a container (text-align: -webkit-center) moves narrower blocks and tables too
                $blockAlign = $container->style->get('-ivopdf-block-align', '');
                if (($blockAlign === 'center' || $blockAlign === 'right') && $freeW > $child->marginLeft + $child->marginRight + 0.01) {
                    $room = $freeW - $child->marginLeft - $child->marginRight;
                    $child->marginLeft += $blockAlign === 'center' ? $room / 2.0 : $room;
                }
            }

            $childX = $bandLeft + $child->marginLeft;
            $this->pendingFloats = $wrapsFloats ? $beside : null;
            $this->layoutElement($child, $childX, $childY, $cbWidth ?? $bandWidth);

            // Margins that escaped from the child's own first / last children join the child's margins
            $childTopEff = self::collapseMargins($child->marginTop, $child->collapsedMarginTop);
            $childBottomEff = self::collapseMargins($child->marginBottom, $child->collapsedMarginBottom);

            if ($this->isEmptyCollapsibleBlock($child)) {
                // An empty block's top and bottom margins collapse together and with its neighbours
                $prevMarginBottom = self::collapseMargins($prevMarginBottom, $childTopEff, $childBottomEff);
                $child->translate(0.0, ($topOpen ? $cursorY : $cursorY + $prevMarginBottom) - $child->y);
                continue;
            }

            $topMargin = $clearance ? 0.0 : self::collapseMargins($prevMarginBottom, $childTopEff);
            if ($topOpen) {
                // The container has nothing above this child: the margin escapes through the container's top edge
                $container->collapsedMarginTop = $topMargin;
                $topMargin = 0.0;
                $topOpen = false;
            }
            $targetY = $cursorY + $topMargin;
            if ($wrapsFloats && abs($targetY - $child->y) > 0.01) {
                // The lines were wrapped around the floats at a provisional position (collapsed margins move the
                // box): lay it out again where it ends up, so every line gets the band of its final y
                $this->pendingFloats = self::floatsFrom(...$floatsBelow($targetY), y: $targetY);
                $this->layoutElement($child, $childX, $targetY, $bandWidth);
                $childBottomEff = self::collapseMargins($child->marginBottom, $child->collapsedMarginBottom);
            }
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

    /**
     * The floats that still extend below $y, as [left, right] lists for a nested block's line bands; null if none.
     *
     * @param list<array<string, mixed>> $left
     * @param list<array<string, mixed>> $right
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}|null
     */
    private static function floatsFrom(array $left, array $right, float $y): ?array
    {
        $l = [];
        foreach ($left as $f) {
            if ($f['bottom'] > $y + 0.01) {
                $l[] = $f;
            }
        }
        $r = [];
        foreach ($right as $f) {
            if ($f['bottom'] > $y + 0.01) {
                $r[] = $f;
            }
        }
        return $l === [] && $r === [] ? null : [$l, $r];
    }

    /**
     * The margin-box width a BFC box needs beside floats, null when it simply fills the band (an auto-width block,
     * flex or grid container). `explicit`: a definite `width` resolved against the containing block; an auto-width
     * table needs its min-content width.
     *
     * @return array{width: float, explicit: bool}|null
     */
    private function bfcWidthBesideFloats(LayoutBox $box, float $containingWidth): ?array
    {
        if ($box->isTextNode() || in_array($box->tagName, ['img', 'svg', 'hr'], true) || $box->formControl !== null) {
            return null;
        }
        $margins = static fn(): float => $box->marginLeft + $box->marginRight; // auto margins resolve to 0 here
        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        if ($wCss !== 'auto' && $wCss !== '' && !str_contains($wCss, 'content') && !str_contains($wCss, 'stretch') && !str_contains($wCss, 'fill')) {
            return ['width' => $this->computeBorderBoxWidth($box, $containingWidth) + $margins(), 'explicit' => true];
        }
        $display = $box->getDisplay();
        if ($display === 'table' || $display === 'inline-table') {
            return ['width' => $this->measureIntrinsicWidth($box, $containingWidth)['min'] + $margins(), 'explicit' => false];
        }
        return null;
    }

    /**
     * Whether a block-level box establishes a new block formatting context (CSS 2.1 9.4.1): such a box does not
     * overlap floats of its parent's context but is placed beside them, as a whole.
     */
    private function establishesFormattingContext(LayoutBox $box): bool
    {
        if ($box->isTextNode() || $box->formControl !== null || $box->columnFlow !== null || $box->isPositionedOutOfFlow()) {
            return true;
        }
        $display = $box->getDisplay();
        if (($display !== 'block' && $display !== 'list-item') || in_array($box->tagName, ['img', 'svg', 'hr', 'table', 'fieldset'], true)) {
            return true; // Chromium's UA style gives <hr> overflow: hidden; <fieldset> is always a BFC root
        }
        $style = $box->style;
        if (strtolower(trim($style->get('float', 'none'))) !== 'none') {
            return true;
        }
        foreach (['overflow', 'overflow-x', 'overflow-y'] as $prop) {
            $v = strtolower(trim($style->get($prop, 'visible')));
            if ($v !== 'visible' && $v !== '') {
                return true;
            }
        }
        return $this->columnLayout->isMulticol($box);
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
        $jobs = [];
        foreach ($container->children as $child) {
            if ($child->isPositionedOutOfFlow() && strtolower(trim($child->style->get('position', 'absolute'))) === 'fixed') {
                $jobs[] = $child;
            }
        }
        // position: absolute boxes belong to the nearest positioned ancestor (CSS 2.1 10.1): a positioned box lays
        // out the absolute descendants that no nearer positioned box claims, once its own size is known
        if ($this->establishesAbsoluteContainingBlock($container)) {
            $this->collectAbsoluteDescendants($container, $jobs);
        }
        foreach ($jobs as $child) {
            $pos = strtolower($child->style->get('position', 'absolute'));
            if ($pos === 'fixed') {
                // Browsers and dompdf: the page area (inside the page margins), so `bottom: -20pt` reaches into the
                // bottom margin; mPDF documents: the whole sheet
                [$refX, $refY, $refWidth, $refHeight] = $this->fixedArea ?? [0.0, 0.0, $this->viewportWidthPt, $this->viewportHeightPt];
                if ($this->collapseRoot !== null) {
                    $this->pageAreaBoxes[] = $child; // placed on the page / sheet: the body's collapsed margin does not move it
                }
            } elseif ($container === $this->mainRoot && $this->fixedArea !== null) {
                // No positioned ancestor: the initial containing block, i.e. the page area of the first page
                [$refX, $refY, $refWidth, $refHeight] = $this->fixedArea;
                if ($this->collapseRoot !== null) {
                    $this->pageAreaBoxes[] = $child;
                }
            } else {
                // The padding box of the positioned ancestor
                $refX = $container->x + $container->borderLeft;
                $refY = $container->y + $container->borderTop;
                $refWidth = max(0.0, $container->width - $container->borderLeft - $container->borderRight);
                $refHeight = max(0.0, $container->height - $container->borderTop - $container->borderBottom);
            }
            // Static position (left / top auto): where the box would be in the flow, the content box of its block parent
            $staticBox = $child->parent ?? $container;
            while ($staticBox->parent !== null && $staticBox->isInlineLevel() && !$staticBox->isPositionedOutOfFlow()) {
                $staticBox = $staticBox->parent;
            }

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
                $targetX = $staticBox->getContentX() + $child->marginLeft;
            }

            // Calculate Y
            if ($topCss !== 'auto') {
                $targetY = $refY + $this->resolveLength($topCss, $refHeight, $child->fontSizePt) + $child->marginTop;
            } elseif ($bottomCss !== 'auto') {
                $bPt = $this->resolveLength($bottomCss, $refHeight, $child->fontSizePt);
                $targetY = $refY + $refHeight - $bPt - $child->marginBottom - $child->height;
            } else {
                $targetY = $staticBox->getContentY() + $child->marginTop;
            }

            $child->translate($targetX - $child->x, $targetY - $child->y);
        }
    }

    private function establishesAbsoluteContainingBlock(LayoutBox $box): bool
    {
        if ($box->parent === null) {
            return true;
        }
        $pos = strtolower(trim($box->style->get('position', 'static')));
        if ($pos !== 'static' && $pos !== '') {
            return true;
        }
        $t = strtolower(trim($box->style->get('transform', 'none')));
        return $t !== 'none' && $t !== '';
    }

    /**
     * Absolute descendants of $box that are not claimed by a nearer positioned box (nor fixed).
     *
     * @param list<LayoutBox> $out
     */
    private function collectAbsoluteDescendants(LayoutBox $box, array &$out): void
    {
        foreach ($box->children as $child) {
            if ($child->isTextNode()) {
                continue;
            }
            if ($child->isPositionedOutOfFlow()) {
                if (strtolower(trim($child->style->get('position', 'absolute'))) !== 'fixed') {
                    $out[] = $child;
                }
                continue;
            }
            if (!$this->establishesAbsoluteContainingBlock($child)) {
                $this->collectAbsoluteDescendants($child, $out);
            }
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
        [$intrinsicW, $intrinsicH] = $this->imageNaturalSize($box);
        $ratio = ($intrinsicH > 0.0) ? ($intrinsicW / $intrinsicH) : 1.0;
        [$w, $h] = ReplacedSize::used($this, $box, $intrinsicW, $intrinsicH, $containingWidth);

        // `<img width="1e9">`: scaled down (keeping the aspect ratio) to CssUnit::MAX_LENGTH_PT
        $over = max(is_finite($w) ? $w : INF, is_finite($h) ? $h : INF) / CssUnit::MAX_LENGTH_PT;
        if ($over > 1.0) {
            $w = is_finite($over) ? $w / $over : min(CssUnit::MAX_LENGTH_PT, $containingWidth);
            $h = is_finite($over) ? $h / $over : min(CssUnit::MAX_LENGTH_PT, $w / max(1e-6, $ratio));
        }
        $box->width = max(0.0, is_nan($w) ? 0.0 : $w);
        $box->height = max(0.0, is_nan($h) ? 0.0 : $h);
    }

    /** Whether the text of $box may wrap between any two characters for min-content (see measureIntrinsicWidth()). */
    private static function wrapsAnywhere(LayoutBox $box): bool
    {
        $wb = strtolower(trim($box->style->get('word-break', 'normal')));
        return $wb === 'break-all' || $wb === 'break-word'
            || strtolower(trim($box->style->get('overflow-wrap', 'normal'))) === 'anywhere'
            || strtolower(trim($box->style->get('line-break', 'auto'))) === 'anywhere';
    }

    /**
     * Natural size of an `<img>` in pt (a missing or broken image is 100 x 60).
     *
     * @return array{0: float, 1: float}
     */
    private function imageNaturalSize(LayoutBox $box): array
    {
        $imgData = $box->imageSrc !== null ? $this->imageLoader->load($box->imageSrc) : null;
        return $imgData !== null
            ? [$imgData['width'] * CssUnit::PX_TO_PT, $imgData['height'] * CssUnit::PX_TO_PT]
            : [100.0, 60.0];
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
            // A textarea (a scroll container) sits on the baseline with its bottom edge, as in browsers
            $baseline = $c->kind === FormControl::TEXTAREA
                ? $box->height
                : $box->borderTop + $box->paddingTop + ($multi ? 0.0 : max(0.0, ($contentH - $lineH) / 2.0)) + $asc;
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
        $pctW = null; // width="100%": a percentage of the containing block, like the CSS width it maps to

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
                } elseif (is_numeric(substr($wAttr, 0, -1))) {
                    $pctW = max(0.0, (float) substr($wAttr, 0, -1)) / 100.0 * $containingWidth;
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

        $explicitH = $svgEl !== null && $svgEl->hasAttribute('height') && !str_ends_with(trim($svgEl->getAttribute('height')), '%');
        if ($wCss !== 'auto' && $wCss !== '') {
            $box->width = $this->resolveBoxHorizontalDimension($box, $wCss, $containingWidth);
        } elseif ($pctW !== null) {
            $box->width = $pctW + $box->getHorizontalSpacing();
        } else {
            $box->width = min($containingWidth, $intrinsicW + $box->getHorizontalSpacing());
        }

        if ($hCss !== 'auto' && $hCss !== '') {
            $box->height = $this->resolveBoxVerticalDimension($box, $hCss, $containingWidth);
        } elseif ($pctW !== null && $explicitH) {
            $box->height = $intrinsicH + $box->getVerticalSpacing(); // the viewBox is fitted into width x height
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

        // Padding and borders are clamped to CssUnit::MAX_LENGTH_PT (`padding: 1e9%` must not make 5 000 pages)
        $box->paddingTop = max(0.0, CssUnit::clampLength($this->resolveLength($box->style->get('padding-top', '0'), $referenceWidth, $fs)));
        $box->paddingRight = max(0.0, CssUnit::clampLength($this->resolveLength($box->style->get('padding-right', '0'), $referenceWidth, $fs)));
        $box->paddingBottom = max(0.0, CssUnit::clampLength($this->resolveLength($box->style->get('padding-bottom', '0'), $referenceWidth, $fs)));
        $box->paddingLeft = max(0.0, CssUnit::clampLength($this->resolveLength($box->style->get('padding-left', '0'), $referenceWidth, $fs)));

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
        return max(0.0, CssUnit::clampLength($this->resolveLength($style->get("border-{$side}-width", '0'), 0.0, $fontSizePt)));
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
            if ($box->getDisplay() === 'table' && !TableMetrics::isFixed($box)) {
                // An auto-layout table is never narrower than its content's minimum width (as in browsers)
                $w = max($w, TableMetrics::intrinsicWidth($this, $box, $containingWidth)['min']);
            }
        } elseif ($box->getDisplay() === 'table') {
            // An auto-width table is shrink-to-fit: its max-content width, but never wider than the available space
            $avail = max(0.0, $containingWidth - $box->marginLeft - $box->marginRight);
            $w = min($avail, $this->measureIntrinsicWidth($box, $containingWidth)['max']);
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
        \IvoPdf\RenderLimits::tick();
        $this->resolveBoxMetrics($box, $referenceWidth);
        $hSpace = $box->getHorizontalSpacing();

        if ($box->tagName === 'img') {
            // The used size after CSS (width / height, aspect ratio, min / max), not the natural size; a percentage
            // width / max-width makes the image compressible: min-content contribution 0 (CSS Sizing 3 §5.2.2)
            [$nw, $nh] = $this->imageNaturalSize($box);
            $w = min(CssUnit::MAX_LENGTH_PT, ReplacedSize::used($this, $box, $nw, $nh, null)[0]);
            return ['min' => ReplacedSize::isCompressible($box) ? 0.0 : $w, 'max' => $w];
        }

        $wCss = strtolower(trim($box->style->get('width', 'auto')));
        if (!$ignoreExplicitWidth && $wCss !== 'auto' && $wCss !== '' && !str_ends_with($wCss, '%') && !str_contains($wCss, 'content')) {
            $fixedW = $this->resolveBoxHorizontalDimension($box, $wCss, $referenceWidth);
            return ['min' => $fixedW, 'max' => $fixedW];
        }

        if ($box->tagName === 'br') {
            return ['min' => 0.0, 'max' => 0.0, 'lead' => 0.0, 'trail' => 0.0];
        }

        if ($box->tagName === 'wbr') {
            // <wbr> is a break opportunity: it ends the unbreakable run before it and starts a new one after it
            return ['min' => 0.0, 'max' => 0.0, 'minLead' => 0.0, 'minTrail' => 0.0, 'hasBreak' => false, 'startsBreak' => true, 'endsBreak' => true];
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
            $rawText = $box->textContent ?? '';
            $text = trim($rawText);
            if (str_contains($text, '{')) {
                $text = (string) preg_replace('/\{(?:PAGE_NUM|PAGE_COUNT|PAGENO|nbpg)\}/', '0', $text); // see InlineLayout::shapeRun()
            }
            if ($text === '') {
                // collapsible white space between inline boxes is a break opportunity
                return ['min' => 0.0, 'max' => 0.0, 'minLead' => 0.0, 'minTrail' => 0.0, 'hasBreak' => false,
                    'startsBreak' => $rawText !== '', 'endsBreak' => $rawText !== '',
                    'spW' => $rawText === '' ? 0.0 : $this->fontManager->measureText(' ', $box->style->get('font-family', 'DejaVu Sans'), $box->style->get('font-weight', 'normal'), $box->style->get('font-style', 'normal'), $box->fontSizePt)];
            }
            $ff = $box->style->get('font-family', 'DejaVu Sans');
            $fw = $box->style->get('font-weight', 'normal');
            $fs = $box->style->get('font-style', 'normal');
            $ls = $this->resolveLength($box->style->get('letter-spacing', '0'), 0.0, $box->fontSizePt);

            $maxW = $this->fontManager->measureText($text, $ff, $fw, $fs, $box->fontSizePt, $ls);
            if (str_contains($text, ' ')) {
                // Lines are laid out word by word with separate spaces (no kerning across a space): measure the
                // max-content the same way, or a box sized to it wraps its last word (Inter kerns e.g. ". 0")
                $pieces = explode(' ', (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text));
                $sum = (count($pieces) - 1) * $this->fontManager->measureText(' ', $ff, $fw, $fs, $box->fontSizePt, $ls);
                foreach ($pieces as $piece) {
                    if ($piece !== '') {
                        $sum += $this->fontManager->measureText($piece, $ff, $fw, $fs, $box->fontSizePt, $ls);
                    }
                }
                $maxW = max($maxW, $sum);
            }
            $words = preg_split('/[ \t\n\r\f]+|(?<=[\p{L}\p{N}][\-\/])(?=[\p{L}\p{N}])/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
            $noWrap = in_array(strtolower($box->style->get('white-space', 'normal')), ['nowrap', 'pre'], true);
            if ($noWrap) {
                $words = [$text];
            }
            $minW = 0.0;
            $leadW = 0.0;
            $trailW = 0.0;
            $lastIdx = count($words) - 1;
            $hasBreak = $lastIdx > 0;
            // `word-break: break-all | break-word`, `overflow-wrap: anywhere`, `line-break: anywhere`: soft wrap
            // opportunities between the characters, so min-content is the widest character (CSS Text 3 §5.2, §5.5;
            // `overflow-wrap: break-word` does not lower min-content)
            $anywhere = !$noWrap && self::wrapsAnywhere($box);
            $charW = [];
            foreach ($words as $wi => $word) {
                if ($anywhere && preg_match('/^.$/su', $word) !== 1) {
                    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [$word];
                    $ww = 0.0;
                    foreach ($chars as $ch) {
                        $ww = max($ww, $charW[$ch] ??= $this->fontManager->measureText($ch, $ff, $fw, $fs, $box->fontSizePt, $ls));
                    }
                    $hasBreak = true;
                    $first = $charW[$chars[0]];
                    $last = $charW[$chars[count($chars) - 1]];
                } else {
                    $ww = $this->fontManager->measureText($word, $ff, $fw, $fs, $box->fontSizePt, $ls);
                    $first = $last = $ww;
                }
                if ($ww > $minW) {
                    $minW = $ww;
                }
                if ($wi === 0) {
                    $leadW = $first;
                }
                if ($wi === $lastIdx) {
                    $trailW = $last;
                }
            }
            return [
                'min' => $minW, 'max' => $maxW, 'minLead' => $leadW, 'minTrail' => $trailW, 'hasBreak' => $hasBreak,
                'startsBreak' => !$noWrap && strspn($rawText, " \t\n\r\f") > 0,
                'endsBreak' => !$noWrap && strspn(strrev($rawText), " \t\n\r\f") > 0,
                // collapsible white space at the edges: one space between this text and its inline neighbours
                'spW' => $this->fontManager->measureText(' ', $ff, $fw, $fs, $box->fontSizePt, $ls),
            ];
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
        // min-content of inline children: widths of the unbreakable runs, split at spaces, hyphens, <wbr> and atomic boxes
        $minRun = 0.0;
        $minRunLead = null; // first unbreakable run of this box (reported to an enclosing inline box)
        $hasInnerBreak = false;
        $startsBreakOut = null;
        $endsBreakOut = false;
        $pendingSpace = 0.0; // collapsed white space between inline pieces of the current line (dropped at line edges)
        $lastSpW = 0.0;
        $flushMin = function () use (&$minRun, &$minContent, &$minRunLead, &$hasInnerBreak): void {
            $minContent = max($minContent, $minRun);
            $minRunLead ??= $minRun;
            $hasInnerBreak = true;
            $minRun = 0.0;
        };

        foreach ($box->children as $child) {
            if ($child->isPositionedOutOfFlow()) {
                continue;
            }
            $childIntr = $this->measureIntrinsicWidth($child, $referenceWidth, $ignoreExplicitWidth);
            // A text node shares its parent's style object: the parent's margins are not the text's
            $mL = $child->isTextNode() ? 0.0 : $child->marginLeft;
            $mR = $child->isTextNode() ? 0.0 : $child->marginRight;
            $childMargins = $mL + $mR;
            $childMin = $childIntr['min'] + $childMargins;
            $childMax = $childIntr['max'] + $childMargins;

            if ($isFlexRow) {
                $minContent += $childMin;
                $maxContent += $childMax;
            } elseif (in_array(strtolower(trim($child->style->get('float', 'none'))), ['left', 'right'], true)) {
                // A float sits on the line of the surrounding inline content: its width adds to that line
                $minContent = max($minContent, $childMin);
                $inlineRunMax += $childMax + ($inlineRunMax > 0.0 ? $pendingSpace : 0.0); // the text before it keeps its space
                $pendingSpace = 0.0;
                $maxContent = max($maxContent, $inlineRunMax);
            } elseif ($child->isInlineLevel() && isset($childIntr['lead'], $childIntr['trail'])) {
                // Forced break inside: its first piece ends the current line, its last piece starts the next one
                $minRun += $childIntr['lead'] + $mL;
                $flushMin();
                $minContent = max($minContent, $childMin);
                $minRun = $childIntr['trail'] + $mR;
                $startsBreakOut ??= false;
                $endsBreakOut = false;
                $inlineRunMax += $childIntr['lead'] + $mL;
                $lead ??= $inlineRunMax;
                $maxContent = max($maxContent, $inlineRunMax, $childMax);
                $inlineRunMax = $childIntr['trail'] + $mR;
                $maxContent = max($maxContent, $inlineRunMax);
            } elseif ($child->isInlineLevel()) {
                if (isset($childIntr['minLead'])) {
                    // text / inline element: break opportunities at its edges and inside
                    $startsBreakOut ??= $childIntr['startsBreak'];
                    if ($childIntr['startsBreak']) {
                        $flushMin();
                    }
                    if ($childIntr['hasBreak']) {
                        $minRun += $childIntr['minLead'] + $mL;
                        $flushMin();
                        $minContent = max($minContent, $childMin);
                        $minRun = $childIntr['minTrail'] + $mR;
                    } else {
                        $minRun += $childMin;
                    }
                    $endsBreakOut = $childIntr['endsBreak'];
                    if ($endsBreakOut) {
                        $flushMin();
                    }
                } else {
                    // atomic inline (image, inline-block, ...): may break on either side
                    $startsBreakOut ??= true;
                    $flushMin();
                    $minContent = max($minContent, $childMin);
                    $endsBreakOut = true;
                }
                $spW = (float) ($childIntr['spW'] ?? 0.0);
                if ($spW > 0.0) {
                    $lastSpW = $spW;
                }
                if (isset($childIntr['minLead']) && $childIntr['startsBreak']) {
                    $pendingSpace = max($pendingSpace, $spW > 0.0 ? $spW : $lastSpW);
                }
                if ($childMax > 0.0) {
                    if ($inlineRunMax > 0.0) {
                        $inlineRunMax += $pendingSpace;
                    }
                    $inlineRunMax += $childMax;
                    $pendingSpace = isset($childIntr['minLead']) && $childIntr['endsBreak'] ? ($spW > 0.0 ? $spW : $lastSpW) : 0.0;
                }
                if ($inlineRunMax > $maxContent) {
                    $maxContent = $inlineRunMax;
                }
            } else {
                $inlineRunMax = 0.0;
                $minContent = max($minContent, $minRun);
                $minRun = 0.0;
                $startsBreakOut ??= true;
                $endsBreakOut = true;
                if ($childMin > $minContent) {
                    $minContent = $childMin;
                }
                if ($childMax > $maxContent) {
                    $maxContent = $childMax;
                }
            }
        }

        $minContent = max($minContent, $minRun);

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
        if ($display === 'inline' && !$isFlexRow) {
            $result['spW'] = $lastSpW;
            // the first / last unbreakable runs let an enclosing inline box join them to its neighbouring text
            $result['minLead'] = ($minRunLead ?? $minRun) + $hSpace / 2;
            $result['minTrail'] = $minRun + $hSpace / 2;
            $result['hasBreak'] = $hasInnerBreak;
            $result['startsBreak'] = $startsBreakOut ?? false;
            $result['endsBreak'] = $endsBreakOut;
        }
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
