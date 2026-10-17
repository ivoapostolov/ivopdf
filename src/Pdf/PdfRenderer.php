<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;
use IvoPdf\Font\FontManager;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LineBox;
use IvoPdf\Layout\TextRun;

/**
 * Renders laid-out LayoutBox trees onto PDF pages with full support for rounded corners,
 * linear gradients, box-shadows, borders, opacity, transforms, images, SVGs, and Unicode/Cyrillic text.
 */
class PdfRenderer
{
    private PdfDocument $pdf;
    private FontManager $fontManager;
    private ImageLoader $imageLoader;
    private SvgRenderer $svgRenderer;
    private BorderPainter $borderPainter;
    private EffectsPainter $effects;
    private BorderImagePainter $borderImagePainter;

    private float $pageWidth;
    private float $pageHeight;
    private float $topMargin;
    private float $printableHeight;
    /** Virtual y where the current page's content slice starts (null = uniform pages) */
    private ?float $virtualTopOverride = null;
    private int $currentPageIndex = 0;
    private int $totalPages = 1;

    /**
     * False while painting a line (or a box inside a line) that is not owned by the current page. A line belongs to
     * exactly one page: the one whose slice contains its top edge. Links, form widgets and destinations are only
     * emitted for owned lines, so that content the page clip hides never leaves ghost annotations behind.
     */
    private bool $annotOwned = true;

    public function __construct(
        PdfDocument $pdf,
        FontManager $fontManager,
        ImageLoader $imageLoader,
        float $pageWidth,
        float $pageHeight,
        float $topMargin,
        float $printableHeight
    ) {
        $this->pdf = $pdf;
        $this->fontManager = $fontManager;
        $this->imageLoader = $imageLoader;
        $this->svgRenderer = new SvgRenderer(
            $fontManager,
            static fn(float $a): string => $pdf->registerAlphaState($a),
            $pdf->paints(),
            static fn(string $src): ?array => $imageLoader->load($src)
        );
        $this->svgRenderer->setWarningSink(static fn(string $m) => $imageLoader->addWarning($m));
        $this->pageWidth = $pageWidth;
        $this->pageHeight = $pageHeight;
        $this->borderPainter = new BorderPainter($pdf, $pageHeight);
        $this->effects = new EffectsPainter($pdf, $pageHeight);
        $this->borderImagePainter = new BorderImagePainter($this, $imageLoader, $pageHeight);
        $this->topMargin = $topMargin;
        $this->printableHeight = max(50.0, $printableHeight);
    }

    private ?FormAppearance $formAppearance = null;
    /** Subtree extents + fixed-box list cached across pages (skips boxes that cannot touch the current page) */
    private ?RenderCull $cull = null;
    private bool $activeForms = true;

    /**
     * Enables/disables interactive AcroForm widgets (false = form controls are drawn as static graphics only).
     */
    public function setActiveForms(bool $active): void
    {
        $this->activeForms = $active;
        $this->formAppearance?->setActiveForms($active);
    }

    private function formAppearance(): FormAppearance
    {
        return $this->formAppearance ??= new FormAppearance($this->fontManager, $this->pdf, $this->activeForms);
    }

    /**
     * Per-page geometry for documents with :first / :left / :right / named @page rules or per-page headers:
     * page size, effective top margin, printable height and the virtual y where this page's slice starts.
     */
    public function setPageGeometry(float $pageWidth, float $pageHeight, float $topMargin, float $printableHeight, float $virtualTop): void
    {
        $this->pageWidth = $pageWidth;
        $this->pageHeight = $pageHeight;
        $this->topMargin = $topMargin;
        $this->printableHeight = max(50.0, $printableHeight);
        $this->virtualTopOverride = $virtualTop;
    }

    /**
     * Renders a single page of the document (0-based $pageIndex).
     */
    public function renderPage(
        LayoutBox $root,
        int $pageIndex,
        int $totalPages,
        ?LayoutBox $headerBox = null,
        ?LayoutBox $footerBox = null,
        ?LayoutBox $overlayBox = null
    ): void {
        $this->currentPageIndex = $pageIndex;
        $this->totalPages = $totalPages;

        $virtualPageTop = $this->virtualTopOverride ?? ($this->topMargin + ($pageIndex * $this->printableHeight));
        $virtualPageBottom = $virtualPageTop + $this->printableHeight;
        $yShift = $virtualPageTop - $this->topMargin;

        $stream = '';

        // 0. Overlay parts marked data-ivo-underlay (dompdf begin_page_render drawing) go below everything else
        foreach ($overlayBox !== null ? self::takeUnderlays($overlayBox, 0) : [] as $underBox) {
            $stream .= $this->renderBoxTree($underBox, 0.0, 0.0, $this->pageHeight, true);
        }

        // 1. Render optional running header
        if ($headerBox !== null) {
            $stream .= $this->renderBoxTree($headerBox, 0.0, 0.0, $this->pageHeight, true);
        }

        // 2. Render fixed elements anywhere in the document on every page
        $fixedBoxes = [];
        $this->cull ??= new RenderCull();
        $fixedBoxes = $this->cull->fixedBoxes($root);
        foreach ($fixedBoxes as $fixedBox) {
            $stream .= $this->renderBoxTree($fixedBox, 0.0, 0.0, $this->pageHeight, true);
        }

        // 3. Render main page content clipped to the printable vertical region (unless single page full-bleed)
        $stream .= "q\n";
        if ($totalPages > 1) {
            $clipBottomPdfY = $this->pageHeight - ($this->topMargin + $this->printableHeight);
            $stream .= sprintf("0.00 %.2F %.2F %.2F re W n\n", $clipBottomPdfY, $this->pageWidth, $this->printableHeight);
        }
        $stream .= $this->renderBoxTree($root, $yShift, $virtualPageTop, $virtualPageBottom, false);
        $stream .= "Q\n";

        // 4. Render optional running footer
        if ($footerBox !== null) {
            $stream .= $this->renderBoxTree($footerBox, 0.0, 0.0, $this->pageHeight, true);
        }

        // 5. Optional overlay (watermark) on top of everything
        if ($overlayBox !== null) {
            $stream .= $this->renderBoxTree($overlayBox, 0.0, 0.0, $this->pageHeight, true);
        }

        $this->pdf->appendPageContent($pageIndex, $stream);
    }

    /**
     * Detaches (and returns) the boxes of an overlay tree whose element carries data-ivo-underlay.
     *
     * @return list<LayoutBox>
     */
    private static function takeUnderlays(LayoutBox $box, int $depth): array
    {
        $found = [];
        if ($depth > 4) {
            return $found;
        }
        foreach ($box->children as $i => $child) {
            if ($child->domElement !== null && $child->domElement->hasAttribute('data-ivo-underlay')) {
                $found[] = $child;
                unset($box->children[$i]);
                continue;
            }
            array_push($found, ...self::takeUnderlays($child, $depth + 1));
        }
        if ($found !== [] && !array_is_list($box->children)) {
            $box->children = array_values($box->children);
        }
        return $found;
    }

    /**
     * @param list<LayoutBox> $out
     */
    private function collectFixedBoxes(LayoutBox $box, array &$out): void
    {
        if (strtolower($box->style->get('position', 'static')) === 'fixed') {
            $out[] = $box;
            return;
        }
        foreach ($box->children as $child) {
            $this->collectFixedBoxes($child, $out);
        }
    }

    /**
     * Drops children whose whole subtree lies outside the page slice (cheap, order-preserving; keeps paint-order sorting small).
     *
     * @return list<LayoutBox>
     */
    private function childrenTouchingPage(LayoutBox $box, bool $isFixedContext, float $top, float $bottom): array
    {
        $children = $box->children;
        if ($isFixedContext || count($children) < 6) {
            return $children;
        }
        return ($this->cull ??= new RenderCull())->touching($box, $top, $bottom);
    }

    /**
     * @param list<LayoutBox> $children
     * @return list<LayoutBox>
     */
    private function paintOrder(array $children): array
    {
        $layers = [];
        foreach ($children as $i => $child) {
            $pos = strtolower($child->style->get('position', 'static'));
            $zCss = trim($child->style->get('z-index', 'auto'));
            $z = is_numeric($zCss) ? (int) $zCss : 0;
            if ($pos === 'static') {
                $rank = $z < 0 && is_numeric($zCss) ? -1 : 0;
                // CSS 2.1 Appendix E: floats paint after the in-flow blocks they overlap (a float beside a block
                // with a background stays visible)
                $isFloat = $rank === 0 && in_array(strtolower(trim($child->style->get('float', 'none'))), ['left', 'right'], true);
                $layer = [$rank, $isFloat ? 1 : 0, $i];
            } else {
                $layer = $z < 0 ? [-1, $z, $i] : [1, $z, $i];
            }
            $layers[] = [$layer, $child];
        }
        usort($layers, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return array_map(static fn(array $l): LayoutBox => $l[1], $layers);
    }

    private function renderBoxTree(
        LayoutBox $box,
        float $yShift,
        float $visibleVirtualTop,
        float $visibleVirtualBottom,
        bool $isFixedContext,
        bool $skipChildren = false
    ): string {
        \IvoPdf\RenderLimits::tick();
        if ($box->isTextNode()) {
            return '';
        }
        if (!$isFixedContext && strtolower($box->style->get('position', 'static')) === 'fixed') {
            return '';
        }
        if (!$isFixedContext && $box->tagName !== 'body') {
            // Whole subtree (boxes, lines, positioned descendants) outside this page's slice: nothing to paint
            [$extLo, $extHi] = ($this->cull ??= new RenderCull())->extent($box);
            if ($extHi < $visibleVirtualTop - RenderCull::SLACK || $extLo > $visibleVirtualBottom + RenderCull::SLACK) {
                return '';
            }
        }
        // visibility: hidden hides only the box itself; descendants may re-enable it with visibility: visible
        $selfHidden = in_array(strtolower($box->style->get('visibility', 'visible')), ['hidden', 'collapse'], true);
        if ($selfHidden && empty($box->children) && empty($box->lines)) {
            return '';
        }

        $boxTop = $box->y;
        $boxBottom = $box->y + $box->height;

        // Skip boxes completely outside this page's vertical slice (unless it has out-of-flow children)
        if (!$isFixedContext && empty($box->children)) {
            if ($boxBottom < $visibleVirtualTop - 1.0 || $boxTop > $visibleVirtualBottom + 1.0) {
                return '';
            }
        }

        $localX = $box->x;
        $localY = $box->y - $yShift;
        $boxW = $box->width;
        $boxH = $box->height;
        // A table's border and background enclose its grid; captions sit outside them
        $paintTop = $boxTop + $box->captionTop;
        $paintBottom = $boxBottom - $box->captionBottom;
        if ($paintTop !== $boxTop || $paintBottom !== $boxBottom) {
            $localY += $box->captionTop;
            $boxH = max(0.0, $paintBottom - $paintTop);
        }

        // If a large wrapper container spans multiple pages, clamp its background/border rect to the current page
        if (!$isFixedContext && $this->totalPages > 1 && $box->tagName !== 'body') {
            $clampedTop = max($paintTop, $visibleVirtualTop);
            $clampedBottom = min($paintBottom, $visibleVirtualBottom);
            if ($clampedBottom > $clampedTop && ($paintTop < $visibleVirtualTop || $paintBottom > $visibleVirtualBottom)) {
                $localY = $clampedTop - $yShift;
                $boxH = $clampedBottom - $clampedTop;
            }
        }

        $out = '';
        $needsGraphicsRestore = false;

        // dompdf Canvas::add_named_dest(): a destination drawn in the page overlay (a fixed context, whose ids are
        // otherwise not link targets because running content repeats on every page)
        if ($isFixedContext && $box->domElement !== null && $box->domElement->hasAttribute('data-ivo-dest')) {
            $this->pdf->registerDestination($box->domElement->getAttribute('data-ivo-dest'), $this->currentPageIndex, $this->pageHeight - $localY);
        }

        // Named destinations (id / a[name]) and heading bookmarks
        // (a display: inline element has no box of its own: its anchors are registered at its line fragments or,
        // when empty, by registerEmptyInlineAnchors())
        if (!$isFixedContext && $this->annotOwned && $box->domElement !== null && $boxTop >= $visibleVirtualTop - 0.5 && $boxTop < $visibleVirtualBottom - 0.5
            && ($box->getDisplay() !== 'inline' || $box->isPositionedOutOfFlow() || $box->parent === null)) {
            $destY = $this->pageHeight - $localY;
            foreach (['id', 'name'] as $attr) {
                $anchor = trim($box->domElement->getAttribute($attr));
                if ($anchor !== '' && ($attr === 'id' || $box->tagName === 'a')) {
                    $this->pdf->registerDestination($anchor, $this->currentPageIndex, $destY);
                }
            }
            if (preg_match('/^h([1-6])$/', $box->tagName, $hm) && !$box->domElement->hasAttribute('data-ivo-nobm')) {
                $title = trim((string) preg_replace('/\s+/u', ' ', (string) $box->domElement->textContent));
                if ($title !== '') {
                    $this->pdf->addOutline((int) $hm[1], $title, $this->currentPageIndex, $destY);
                }
            }
            \IvoPdf\Compat\MpdfRenderHooks::apply($this->pdf, $box->domElement, $this->currentPageIndex, $localX, $destY); // mPDF <bookmark>, <annotation>
        }

        // Empty inline anchors (<a name="x"></a>) between block siblings, in a box without any text line
        if (!$isFixedContext && $this->annotOwned && empty($box->lines) && !empty($box->children)) {
            $this->registerEmptyInlineAnchors($box, $yShift, $visibleVirtualTop, $visibleVirtualBottom);
        }

        // Handle opacity
        $filterCss = $box->style->get('filter', 'none');
        $opacity = (float) $box->style->get('opacity', '1');
        if ($filterCss !== 'none') {
            $opacity *= EffectsPainter::filterOpacity($filterCss);
            if (str_contains(strtolower($filterCss), 'blur(')) {
                $this->imageLoader->addWarning('CSS filter: blur() is not supported and was ignored');
            }
        }
        $groupGs = null;
        if ($opacity < 0.999) {
            $out .= "q\n";
            $needsGraphicsRestore = true;
            $gsId = $this->pdf->registerAlphaState($opacity);
            if (!empty($box->children) || !empty($box->lines)) {
                $groupGs = $gsId; // painted into a transparency group and composited once (true group opacity)
            } else {
                $out .= "/{$gsId} gs\n";
            }
        }

        // mix-blend-mode -> PDF blend mode (applies to the box's own painting operations)
        $blendName = EffectsPainter::blendModeName($box->style->get('mix-blend-mode', 'normal'));
        if ($blendName !== null) {
            if (!$needsGraphicsRestore) {
                $out .= "q
";
                $needsGraphicsRestore = true;
            }
            $out .= '/' . $this->pdf->registerBlendState($blendName) . " gs
";
        }

        // Handle CSS transform (rotate, scale, translate)
        $transformCss = trim($box->style->get('transform', 'none'));
        if ($transformCss !== '' && strtolower($transformCss) !== 'none') {
            if (!$needsGraphicsRestore) {
                $out .= "q\n";
                $needsGraphicsRestore = true;
            }
            $out .= $this->buildTransformMatrixCommand($transformCss, $localX, $localY, $boxW, $boxH, $box->style->get('transform-origin', '50% 50%'));
        }

        // clip-path: inset() / circle() / ellipse() / polygon() around the whole painting of the element
        $clipPathCss = $box->style->get('clip-path', 'none');
        if ($clipPathCss !== 'none' && $boxW > 0.05 && $boxH > 0.05) {
            $clipCmd = $this->effects->clipPath($clipPathCss, $localX, $localY, $boxW, $boxH, $box->fontSizePt);
            if ($clipCmd !== '') {
                if (!$needsGraphicsRestore) {
                    $out .= "q
";
                    $needsGraphicsRestore = true;
                }
                $out .= $clipCmd;
            }
        }

        $groupStart = strlen($out);

        // Draw box visuals (shadow, background, gradient, borders) if visible on this page
        $isVisibleOnPage = !$selfHidden && ($isFixedContext || ($boxBottom >= $visibleVirtualTop - 0.5 && $boxTop <= $visibleVirtualBottom + 0.5));
        $radii = $this->resolveBorderRadii($box, $boxW, $boxH);

        if ($isVisibleOnPage && $boxW > 0.05 && $boxH > 0.05) {
            // 1. Box shadow
            $shadowCss = trim($box->style->get('box-shadow', 'none'));
            if ($filterCss !== 'none' && ($ds = EffectsPainter::dropShadowAsBoxShadow($filterCss)) !== '') {
                $shadowCss = strtolower($shadowCss) === 'none' || $shadowCss === '' ? $ds : $shadowCss . ', ' . $ds;
            }
            if ($shadowCss !== '' && strtolower($shadowCss) !== 'none') {
                $out .= $this->drawBoxShadow($shadowCss, $localX, $localY, $boxW, $boxH, $radii, $box->fontSizePt);
            }

            // 2. Background color
            $bgColor = CssColor::parse($box->style->get('background-color', 'transparent'));
            if ($bgColor !== null) {
                $out .= $this->drawFilledRect($localX, $localY, $boxW, $boxH, $radii, $bgColor);
            }

            // 3. Background gradient or background-image
            $out .= $this->drawBackgroundImage($box, $localX, $localY, $boxW, $boxH, $radii);

            // 4. Clickable box link if <a> is styled as block/inline-block/flex
            if ($box->href !== null && $box->href !== '' && ($box->tagName === 'a' || !$box->isInlineLevel())
                && ($isFixedContext || ($this->annotOwned && $boxBottom > $visibleVirtualTop + 0.5 && $boxTop < $visibleVirtualBottom - 0.5))) {
                $pdfY = $this->pageHeight - ($localY + $boxH);
                $this->pdf->addLinkAnnotation($this->currentPageIndex, $localX, $pdfY, $boxW, $boxH, $box->href);
            }
        }

        // 4b. Column rules of multi-column containers
        if (!empty($box->columnRects) && !$isFixedContext) {
            $out .= $this->drawColumnRules($box, $yShift, $visibleVirtualTop, $visibleVirtualBottom);
        }

        // 5. Clipping if overflow: hidden
        $overflow = strtolower($box->style->get('overflow', 'visible'));
        $clipped = false;
        if (($overflow === 'hidden' || $overflow === 'clip' || $overflow === 'auto' || $overflow === 'scroll') && $isVisibleOnPage && $boxW > 0.1 && $boxH > 0.1) {
            $out .= "q\n";
            $out .= $this->buildRoundedRectPath($localX, $localY, $boxW, $boxH, $radii) . "W n\n";
            $clipped = true;
        }

        // 6. Replaced content: <img>, <svg> or a form control (static frame + interactive AcroForm widget)
        if ($isVisibleOnPage) {
            if ($box->formControl !== null) {
                $register = !$isFixedContext && $this->annotOwned && $boxTop >= $visibleVirtualTop - 0.5 && $boxTop < $visibleVirtualBottom - 0.5;
                $out .= $this->formAppearance()->render($box, $yShift, $this->pageHeight, $this->currentPageIndex, $register);
            } elseif ($box->tagName === 'progress' || $box->tagName === 'meter') {
                // drawn like Edge; the fallback content between the tags is not shown (browsers do not show it either)
                $out .= FormGauges::gauge($box, $localX, $this->pageHeight - ($localY + $boxH), $boxW, $boxH);
                $skipChildren = true;
            } elseif ($box->tagName === 'img' && $box->imageSrc !== null) {
                $objectFit = strtolower($box->style->get('object-fit', 'fill'));
                $cx = $localX + $box->borderLeft + $box->paddingLeft;
                $cy = $localY + $box->borderTop + $box->paddingTop;
                $cw = $box->getContentWidth();
                $ch = $box->getContentHeight();
                $out .= $this->drawImageInRect($box->imageSrc, $cx, $cy, $cw, $ch, $radii, $objectFit, $box->style->get('object-position', '50% 50%'), $filterCss, strtolower($box->style->get('image-rendering', 'auto')) !== 'auto' && strtolower($box->style->get('image-rendering', 'auto')) !== 'smooth');
            } elseif ($box->tagName === 'svg' && $box->domElement !== null) {
                $cx = $localX + $box->borderLeft + $box->paddingLeft;
                $cy = $localY + $box->borderTop + $box->paddingTop;
                $cw = $box->getContentWidth();
                $ch = $box->getContentHeight();
                $out .= $this->svgRenderer->renderSvg(
                    $box->domElement,
                    $cx,
                    $cy,
                    $cw,
                    $ch,
                    $this->pageHeight,
                    $box->style->get('color', '#111827'),
                    [
                        'size' => $box->fontSizePt,
                        'family' => $box->style->get('font-family', 'DejaVu Sans'),
                        'weight' => $box->style->get('font-weight', 'normal'),
                        'style' => $box->style->get('font-style', 'normal'),
                    ]
                );
                // <a href> inside the SVG markup
                if ($this->svgRenderer->links !== [] && ($isFixedContext || ($this->annotOwned && $boxBottom > $visibleVirtualTop + 0.5 && $boxTop < $visibleVirtualBottom - 0.5))) {
                    foreach ($this->svgRenderer->links as [$lx0, $ly0, $lx1, $ly1, $lhref]) {
                        $this->pdf->addLinkAnnotation($this->currentPageIndex, $lx0, $this->pageHeight - $ly1, $lx1 - $lx0, $ly1 - $ly0, $lhref);
                    }
                }
            }

            // List item marker (bullet or number)
            if (($box->listMarker !== null && $box->listMarker !== '') || $box->listMarkerImage !== null) {
                $out .= $this->drawListMarker($box, $localX, $localY);
            }
        }

        // 7. Render inline LineBoxes belonging to this box
        $gaugeBox = $skipChildren && ($box->tagName === 'progress' || $box->tagName === 'meter');
        $lineFrom = $gaugeBox ? count($box->lines) : 0;
        $linesOrdered = false;
        if (!$gaugeBox && !$isFixedContext && count($box->lines) > 48) {
            [$lineFrom, $linesOrdered] = ($this->cull ??= new RenderCull())->lineStart($box, $visibleVirtualTop);
        }
        for ($li = $lineFrom, $lineCount = count($box->lines); $li < $lineCount; $li++) {
            $line = $box->lines[$li];
            $lineTop = $line->y;
            $lineBottom = $line->y + $line->height;
            if (!$isFixedContext && $lineTop >= $visibleVirtualBottom - 0.5) {
                if ($linesOrdered) {
                    break; // every following line starts even lower
                }
                continue;
            }
            if (!$isFixedContext && $lineBottom < $visibleVirtualTop - 0.5) {
                continue;
            }
            // The page owns a line when its top edge lies in the slice; a line that only reaches into the slice from
            // above is painted (clipped) but its links, widgets and anchors belong to the previous page
            $wasOwned = $this->annotOwned;
            $this->annotOwned = $wasOwned && ($isFixedContext || $this->currentPageIndex === 0 || $lineTop >= $visibleVirtualTop - 0.5);
            $out .= $this->renderLineBox($line, $yShift, $isFixedContext, $visibleVirtualTop, $visibleVirtualBottom, $wasOwned);
            $this->annotOwned = $wasOwned;
        }

        // 8. Render child boxes in CSS painting order (negative z-index, in-flow, positioned, positive z-index).
        //    Table sections paint every row background first and the cells afterwards, so row-spanning cells stay visible.
        $isTableSection = in_array($box->getDisplay(), ['table', 'table-row-group', 'table-header-group', 'table-footer-group'], true);
        $deferredRows = [];
        foreach ($skipChildren ? [] : $this->paintOrder($this->childrenTouchingPage($box, $isFixedContext, $visibleVirtualTop, $visibleVirtualBottom)) as $child) {
            if ($isTableSection && $child->getDisplay() === 'table-row') {
                $out .= $this->renderBoxTree($child, $yShift, $visibleVirtualTop, $visibleVirtualBottom, $isFixedContext, true);
                $deferredRows[] = $child;
                continue;
            }
            if ($child->isInlineLevel() && !$child->isPositionedOutOfFlow() && !empty($box->lines)) {
                // Inline-level children were already tokenized into $box->lines by InlineLayout
                continue;
            }
            if ($child->isInlineLevel() && !$child->isPositionedOutOfFlow() && $child->isTextNode()) {
                continue;
            }
            $out .= $this->renderBoxTree($child, $yShift, $visibleVirtualTop, $visibleVirtualBottom, $isFixedContext);
        }
        foreach ($deferredRows as $row) {
            foreach ($this->paintOrder($row->children) as $cell) {
                $out .= $this->renderBoxTree($cell, $yShift, $visibleVirtualTop, $visibleVirtualBottom, $isFixedContext);
            }
        }

        if ($clipped) {
            $out .= "Q\n";
        }

        // 9. Draw Borders on top of background/children
        if ($isVisibleOnPage && ($boxW > 0.05 || $boxH > 0.05)) {
            if ($box->legendGap !== null) {
                // <fieldset>: no border behind the <legend> (even-odd clip around the legend's rectangle)
                [$gx0, $gx1, $gy0, $gy1] = $box->legendGap;
                $gapTop = $box->y - $yShift + $gy0;
                $out .= sprintf(
                    "q %.2F %.2F %.2F %.2F re %.2F %.2F %.2F %.2F re W* n\n",
                    $localX - 50.0,
                    $this->pageHeight - ($localY + $boxH + 50.0),
                    $boxW + 100.0,
                    $boxH + 100.0 + ($localY - ($box->y - $yShift)),
                    $box->x + $gx0,
                    $this->pageHeight - ($gapTop + $gy1),
                    $gx1 - $gx0,
                    $gy1 - $gy0
                );
                $out .= $this->drawBorders($box, $localX, $localY, $boxW, $boxH, $radii) . "Q\n";
            } else {
                $out .= $this->drawBorders($box, $localX, $localY, $boxW, $boxH, $radii);
            }
        }

        // 10. Outline (outside the border box, never affects layout)
        if ($isVisibleOnPage && $boxW > 0.05 && $boxH > 0.05) {
            $out .= $this->borderPainter->paintOutline($box, $localX, $localY, $boxW, $boxH, $radii);
        }

        if ($groupGs !== null && strlen($out) > $groupStart) {
            $inner = substr($out, $groupStart);
            $out = substr($out, 0, $groupStart) . '/' . $groupGs . ' gs /' . $this->pdf->groups()->register($inner) . " Do\n";
        }

        if ($needsGraphicsRestore) {
            $out .= "Q\n";
        }

        return $out;
    }

    /**
     * Inter-word spaces are separate whitespace runs that are not drawn; extractors infer them from the gap between
     * glyphs. That fails next to a run in another (fallback) font, next to symbols (arrows, emoji) and with
     * /ActualText (.notdef glyphs, Indic), so the run then gets an extractable space after its last glyph (a space
     * glyph, or a trailing space in its /ActualText): nothing moves on the page.
     */
    private function needsExtractableSpace(LineBox $line, int $runIdx, \IvoPdf\Font\TtfParser $ttf): bool
    {
        $space = $line->runs[$runIdx + 1] ?? null;
        $next = $line->runs[$runIdx + 2] ?? null;
        if ($space === null || $next === null || !$space->isWhitespace || $space->inlineBox !== null || $next->inlineBox !== null
            || $next->isWhitespace || $next->text === '' || strtolower($space->style->get('text-decoration', 'none')) !== 'none') {
            return false;
        }
        if ($this->bidiSpaceOwner($line, $runIdx + 1) !== null) {
            return false; // a space next to right-to-left text is drawn on its own (see bidiSpaceOwner())
        }
        // Every inter-word space that is not drawn as part of a run (justified lines, letter- / word-spacing, style
        // changes, fallback fonts) is written as a real space glyph after the word: extractors that only read the
        // text operators (smalot/pdfparser, pypdf) otherwise glue the words together
        return true;
    }

    /**
     * TJ operation drawing the whitespace run $space as one space glyph of $ttf (a space has no ink) whose advance is
     * exactly the run's width, so the next glyph follows without a gap or an overlap.
     */
    private function spaceTj(\IvoPdf\Font\TtfParser $ttf, \IvoPdf\Layout\TextRun $space, float $fontSizePt, float $letterSpacingPt): string
    {
        $hex = $ttf->getGlyphId(0x20) !== 0 ? $this->fontManager->encodeTextToHexGids($ttf, ' ') : '';
        if ($hex === '') {
            return '';
        }
        $adjust = $ttf->getCharWidth(0x20) - ($space->width - $letterSpacingPt) * 1000.0 / max(0.01, $fontSizePt);
        return sprintf('[<%s> %.1F] TJ', $hex, $adjust);
    }

    /**
     * Inter-word space next to right-to-left text: the neighbouring run that draws it as a space glyph, or null when
     * the space stays undrawn (extractors infer it from the gap). Bidi-aware extractors (MuPDF, PDFium, Acrobat) give a
     * glyph the direction of the text it is shown with and reverse the right-to-left stretches of a line; a gap between
     * runs of different directions is not seen as a space there, and a space shown with the wrong side moves to the
     * other end of the right-to-left stretch. So, as Chrome / Edge do, every space beside right-to-left text is a glyph
     * shown with the neighbour that has its resolved direction (bidi level parity): the LTR side in an LTR paragraph
     * ("contains |שלום| in"), the RTL side between right-to-left words. Nothing moves on the page.
     */
    private function bidiSpaceOwner(LineBox $line, int $spaceIdx): ?\IvoPdf\Layout\TextRun
    {
        $space = $line->runs[$spaceIdx] ?? null;
        $prev = $line->runs[$spaceIdx - 1] ?? null;
        $next = $line->runs[$spaceIdx + 1] ?? null;
        if ($space === null || $prev === null || $next === null || !$space->isWhitespace || $space->inlineBox !== null
            || $prev->isWhitespace || $prev->inlineBox !== null || $next->isWhitespace || $next->inlineBox !== null
            || $prev->text === '' || $next->text === '') {
            return null;
        }
        $level = $space->bidiLevel & 1;
        if ((($space->bidiLevel | $prev->bidiLevel | $next->bidiLevel) & 1) === 0) {
            return null;
        }
        if (strtolower($space->style->get('text-decoration', 'none')) !== 'none') {
            return null; // drawn with its decoration already
        }
        $prevSide = ($prev->bidiLevel & 1) === $level;
        $nextSide = ($next->bidiLevel & 1) === $level;
        if ($prevSide && $nextSide) {
            // both have its direction: the one it shares its font and colour with (a space after a link goes with the
            // plain text that follows, as in Chrome)
            $same = static fn(\IvoPdf\Layout\TextRun $r): bool => $r->style->get('color', '') === $space->style->get('color', '')
                && $r->style->get('font-family', '') === $space->style->get('font-family', '');
            return $same($prev) || !$same($next) ? $prev : $next;
        }
        return $prevSide || !$nextSide ? $prev : $next;
    }

    /**
     * Text-showing operators of the glyphs of a right-to-left run (visual order). A mirrored glyph (the "[" drawn for a
     * logical "]") is shown on its own with its logical character as /ActualText: bidi-aware extractors reverse the run
     * but do not mirror its brackets back. The pieces are joined with relative Td moves at the glyphs' positions.
     * $reversed false (see rtlReversedSpan()): extractors keep the run in visual order, so a mirrored glyph is already
     * the character a reader expects and gets no /ActualText.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: string}> $glyphs
     * @return array{0: string, 1: float} operators, and how far the last Td moved the start of the text line (pt)
     */
    private function rtlShowOps(\IvoPdf\Font\TtfParser $ttf, array $glyphs, float $fontSizePt, float $letterSpacingPt, bool $reversed = true): array
    {
        $pieces = [];
        $piece = [];
        $at = 0.0;
        $start = 0.0;
        foreach ($glyphs as $g) {
            $cp = mb_strlen($g[4], 'UTF-8') === 1 ? mb_ord($g[4], 'UTF-8') : 0;
            $mirrored = $reversed && $cp > 0 && \IvoPdf\Text\Bidi::mirror($cp) !== $cp ? mb_chr(\IvoPdf\Text\Bidi::mirror($cp), 'UTF-8') : null;
            if ($mirrored !== null) {
                if ($piece !== []) {
                    $pieces[] = [$start, $piece, null];
                }
                $pieces[] = [$at, [$g], $mirrored];
                $piece = [];
                $start = $at + $g[1] * $fontSizePt / 1000.0 + $letterSpacingPt;
            } else {
                $piece[] = $g;
            }
            $at += $g[1] * $fontSizePt / 1000.0 + $letterSpacingPt;
        }
        if ($piece !== []) {
            $pieces[] = [$start, $piece, null];
        }
        if (count($pieces) === 1 && $pieces[0][2] === null) {
            return [$this->fontManager->encodeShapedGlyphs($ttf, $glyphs, $fontSizePt), 0.0];
        }
        $ops = '';
        $moved = 0.0;
        foreach ($pieces as [$x, $gs, $actual]) {
            $x = round($x, 2);
            if ($x !== $moved) {
                $ops .= sprintf('%.2F 0 Td ', $x - $moved);
                $moved = $x;
            }
            $show = $this->fontManager->encodeShapedGlyphs($ttf, $gs, $fontSizePt);
            $ops .= ($actual !== null ? '/Span << /ActualText <' . strtoupper(bin2hex("\xFE\xFF" . (string) mb_convert_encoding($actual, 'UTF-16BE', 'UTF-8'))) . '> >> BDC ' . $show . ' EMC' : $show) . ' ';
        }
        return [rtrim($ops), $moved];
    }

    /**
     * Whether bidi-aware extractors (MuPDF, PDFium) reverse the characters of run $idx: they reverse a stretch of
     * text in one font that holds right-to-left letters. A run of brackets / punctuation alone in its font (Arabic text
     * in a fallback font, the brackets in the paragraph's font) is not reversed and keeps its visual order.
     */
    private function rtlReversedSpan(LineBox $line, int $idx, \IvoPdf\Font\TtfParser $ttf): bool
    {
        $strong = static fn(string $t): bool => preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFC}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u', $t) === 1;
        $run = $line->runs[$idx] ?? null;
        if ($run === null || $strong($run->text)) {
            return true;
        }
        foreach ([-1, 1] as $step) {
            for ($k = $idx + $step; isset($line->runs[$k]); $k += $step) {
                $n = $line->runs[$k];
                if ($n->isWhitespace) {
                    continue;
                }
                if ($n->inlineBox === null && $n->text !== '' && abs($n->fontSizePt - $run->fontSizePt) < 0.01 && $strong($n->text)
                    && $this->fontManager->resolveFont($n->style->get('font-family', 'DejaVu Sans'), $n->style->get('font-weight', 'normal'), $n->style->get('font-style', 'normal')) === $ttf) {
                    return true; // shown in the same font next to it: one stretch for the extractor
                }
                break;
            }
        }
        return false;
    }

    /**
     * Glyphs of a right-to-left run with every multi-character cluster's text reversed (lam-alef "لا" -> "ال"): the
     * glyphs are in visual order and bidi-aware extractors reverse the characters again, as for Chrome / Edge PDFs.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: string}> $glyphs
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: string}>
     */
    private static function rtlClusterText(array $glyphs): array
    {
        foreach ($glyphs as $k => $g) {
            if (isset($g[4][2]) && mb_strlen($g[4], 'UTF-8') > 1 && preg_match('/^\p{Mn}+$/u', $g[4]) !== 1) {
                $glyphs[$k][4] = implode('', array_reverse(mb_str_split($g[4], 1, 'UTF-8')));
            }
        }
        return $glyphs;
    }

    /**
     * @param float $sliceTop top of the page slice being painted: an inline-block taller than a page (20 000 `<br>` in
     *        one inline-block) paints only its part on this page instead of its whole content on every page
     * @param float $sliceBottom bottom of that slice
     * @param bool|null $ownedOuter whether the page may own annotations before the line's own ownership test: the
     *        lines inside such an inline-block are owned by the page that holds their top edge
     */
    private function renderLineBox(LineBox $line, float $yShift, bool $replacePageTokens, float $sliceTop = -INF, float $sliceBottom = INF, ?bool $ownedOuter = null): string
    {
        $out = $this->drawInlineDecorations($line, $yShift);

        if ($this->annotOwned && !$replacePageTokens) {
            $this->registerInlineAnchors($line, $yShift);
        }

        // A line holding right-to-left text: neighbouring runs in the same font, size and colour share one text object
        // (see flushTextGroup())
        $bidiLine = false;
        foreach ($line->runs as $r) {
            if (($r->bidiLevel & 1) === 1 && !$r->isWhitespace && $r->inlineBox === null) {
                $bidiLine = true;
                break;
            }
        }
        $group = null;

        // Runs of `position: relative` inline elements are positioned content: painted after the line's other runs
        // (CSS painting order), so they overlap them like in browsers and extract as separate words
        $ordered = $line->runs;
        $shifted = [];
        foreach ($line->runs as $k => $r) {
            if ($r->relDx !== 0.0 || $r->relDy !== 0.0) {
                $shifted[$k] = $r;
                unset($ordered[$k]);
            }
        }
        if ($shifted !== []) {
            $ordered += $shifted;
        }

        foreach ($ordered as $runIdx => $run) {
            if ($run->inlineBox !== null) {
                $out .= $this->flushTextGroup($group);
                $linked =$run->href !== null && $run->href !== '' && $this->annotOwned
                    && $run->inlineBox->tagName !== 'a' && $run->inlineBox->isInlineLevel();
                if ($linked) {
                    // <a href><img></a> / <a href><svg></a> / inline-block content: the replaced box is the click target
                    $ib = $run->inlineBox;
                    $this->pdf->addLinkAnnotation($this->currentPageIndex, $ib->x, $this->pageHeight - ($ib->y - $yShift + $ib->height), $ib->width, $ib->height, (string) $run->href);
                }
                $ownedHere = $this->annotOwned;
                if ($ownedOuter !== null && !$replacePageTokens) {
                    $this->annotOwned = $ownedOuter;
                }
                // The slice is the inline box's painted extent: content overflowing a definite height stays visible
                [$ibTop, $ibBottom] = ($this->cull ??= new RenderCull())->extent($run->inlineBox);
                $out .= $this->renderBoxTree(
                    $run->inlineBox,
                    $yShift,
                    max(min($ibTop, $run->inlineBox->y) - 1.0, $sliceTop),
                    min(max($ibBottom, $run->inlineBox->y + $run->inlineBox->height) + 1.0, $sliceBottom),
                    $replacePageTokens
                );
                $this->annotOwned = $ownedHere;
                continue;
            }

            if ($run->style->get('visibility', 'visible') !== 'visible') {
                continue;
            }
            $runBaselineDocY = ($run->y + $run->ascent) - $yShift;
            $baselinePdfY = $this->pageHeight - $runBaselineDocY;

            $text = $run->text;
            $runX = $run->x;
            if (str_contains($text, '{')) {
                $text = strtr($text, [
                    '{PAGE_NUM}' => (string) ($this->currentPageIndex + 1),
                    '{PAGENO}' => (string) ($this->currentPageIndex + 1),
                    '{PAGE_COUNT}' => (string) $this->totalPages,
                    '{nbpg}' => (string) $this->totalPages,
                ]);
                if ($text !== $run->text) {
                    // Laid out with one-digit numbers: keep centred / right-aligned numbers in place
                    $align = strtolower(trim($run->style->get('text-align', 'start')));
                    $rtl = strtolower(trim($run->style->get('direction', 'ltr'))) === 'rtl';
                    $f = $align === 'center' ? 0.5 : (($align === 'right' || ($align === 'end' && !$rtl) || ($align === 'start' && $rtl)) ? 1.0 : 0.0);
                    if ($f > 0.0) {
                        $real = $this->fontManager->measureText($text, $run->style->get('font-family', 'DejaVu Sans'), $run->style->get('font-weight', 'normal'), $run->style->get('font-style', 'normal'), $run->fontSizePt, $run->letterSpacingPt);
                        $runX += ($run->width - $real) * $f;
                    }
                }
            }

            if ($text === '' || ($run->isWhitespace && strtolower($run->style->get('text-decoration', 'none')) === 'none')) {
                continue;
            }

            $fontFamily = $run->style->get('font-family', 'DejaVu Sans');
            $fontWeight = $run->style->get('font-weight', 'normal');
            $fontStyle = $run->style->get('font-style', 'normal');
            $ttf = $this->fontManager->resolveFont($fontFamily, $fontWeight, $fontStyle);
            $fontId = $this->fontManager->markFontUsed($ttf);

            $color = CssColor::parse($run->style->get('color', '#111827')) ?? [0.067, 0.094, 0.153, 1.0];

            $alphaGuard = '';
            $alphaRestore = '';
            if ($color[3] < 0.999) {
                $gsId = $this->pdf->registerAlphaState($color[3]);
                $alphaGuard = "q /{$gsId} gs ";
                $alphaRestore = " Q";
            }

            $textStartLen = strlen($out);
            $lineStartShift = 0.0; // relative Td moves inside $showOps (see rtlShowOps())
            $spaceAfter = $text === $run->text && $this->needsExtractableSpace($line, $runIdx, $ttf);
            if ($run->glyphs !== null && $text === $run->text) {
                // Shaped run (ligatures, kerning, Arabic forms, RTL): positioned glyph ids in visual order
                // Reordered Indic glyphs carry /ActualText so that copy/paste and search see the logical text
                $notdef = $run->actualText === null && in_array(0, array_column($run->glyphs, 0), true);
                $logical = $notdef ? null : ($run->actualText ?? (preg_match('/[\x{0900}-\x{0D7F}]/u', $text) === 1 || in_array('', array_column($run->glyphs, 4), true) ? $text : null));
                if ($logical !== null && $spaceAfter) {
                    $logical .= ' ';
                    $spaceAfter = false;
                }
                if ($logical === null && ($run->bidiLevel & 1) === 1) {
                    [$showOps, $lineStartShift] = $this->rtlShowOps($ttf, self::rtlClusterText($run->glyphs), $run->fontSizePt, $run->letterSpacingPt, $this->rtlReversedSpan($line, $runIdx, $ttf));
                } else {
                    $showOps = $this->fontManager->encodeShapedGlyphs($ttf, $run->glyphs, $run->fontSizePt, $logical);
                }
                if ($notdef && $showOps !== '') {
                    // .notdef glyphs (no font has them): one /ActualText span over the run keeps the code points
                    $actual = $text . ($spaceAfter ? ' ' : '');
                    $spaceAfter = false;
                    $showOps = '/Span << /ActualText <' . strtoupper(bin2hex("\xFE\xFF" . (string) mb_convert_encoding($actual, 'UTF-16BE', 'UTF-8'))) . '> >> BDC ' . $showOps . ' EMC';
                }
            } else {
                $hexGids = $this->fontManager->encodeTextToHexGids($ttf, $text);
                $showOps = $hexGids !== '' ? '<' . $hexGids . '> Tj' : '';
                $actual = $run->actualText !== null && $text === $run->text ? $run->actualText : null;
                if ($actual === null && $this->fontManager->hasMissingGlyphs($ttf, $text)) {
                    $actual = $text; // drawn as .notdef (no font has the glyph): /ActualText keeps the code points extractable
                }
                if ($showOps !== '' && $actual !== null) {
                    if ($spaceAfter) {
                        $actual .= ' ';
                        $spaceAfter = false;
                    }
                    $showOps = '/Span << /ActualText <' . strtoupper(bin2hex("\xFE\xFF" . (string) mb_convert_encoding($actual, 'UTF-16BE', 'UTF-8'))) . '> >> BDC ' . $showOps . ' EMC';
                }
            }
            if ($showOps !== '' && $spaceAfter && $ttf->getGlyphId(0x20) !== 0) {
                // A space glyph (no ink) shown with the word itself: the next run is positioned on its own, so nothing
                // visible moves. Keeping it inside the word's own string matters for extractors that estimate a string's
                // width from its character count (smalot): a separate one-character Tj makes the gap to the next word
                // look like a tab
                $spaceHex = $this->fontManager->encodeTextToHexGids($ttf, ' ');
                if (preg_match('/^(.*)> Tj$/s', $showOps, $m) === 1 && !str_contains($m[1], 'Tj')) {
                    $showOps = $m[1] . $spaceHex . '> Tj';
                } elseif (preg_match('/^(.*)\] TJ$/s', $showOps, $m) === 1 && !str_contains($m[1], 'TJ')) {
                    $showOps = $m[1] . ' <' . $spaceHex . '>] TJ';
                } else {
                    $showOps .= ' <' . $spaceHex . '> Tj';
                }
            }
            if ($showOps !== '' && $text === $run->text) {
                // spaces beside right-to-left text are shown with the run that has their direction (bidiSpaceOwner())
                $before = $line->runs[$runIdx - 1] ?? null;
                if ($before !== null && $before->isWhitespace && $this->bidiSpaceOwner($line, $runIdx - 1) === $run
                    && ($tj = $this->spaceTj($ttf, $before, $run->fontSizePt, $run->letterSpacingPt)) !== '') {
                    if ($lineStartShift !== 0.0) {
                        // the run moves the text line start itself (rtlShowOps()): start it again at the run
                        $move = round($runX, 2) - round($before->x, 2);
                        $showOps = sprintf('%s %.2F 0 Td %s', $tj, $move, $showOps);
                        $lineStartShift += $move;
                    } else {
                        $showOps = $tj . ' ' . $showOps;
                    }
                    $runX = $before->x;
                }
                $after = $line->runs[$runIdx + 1] ?? null;
                if ($after !== null && $after->isWhitespace && $this->bidiSpaceOwner($line, $runIdx + 1) === $run) {
                    $showOps .= ' ' . $this->spaceTj($ttf, $after, $run->fontSizePt, $run->letterSpacingPt);
                }
            }
            if ($showOps !== '') {
                $tcCmd = abs($run->letterSpacingPt) > 0.001 ? sprintf('%.3F Tc ', $run->letterSpacingPt) : '';
                $tcReset = abs($run->letterSpacingPt) > 0.001 ? ' 0 Tc' : '';
                // A family without a bold / italic face: synthesize it like browsers (fill + stroke, 14 degree slant)
                $synth = $this->fontManager->syntheticStyle($fontFamily, $fontWeight, $fontStyle);
                $posOp = $synth['oblique']
                    ? sprintf('1 0 0.25 1 %.2F %.2F Tm', $runX, $baselinePdfY)
                    : sprintf('%.2F %.2F Td', $runX, $baselinePdfY);
                $boldOn = '';
                $boldOff = '';
                if ($synth['bold']) {
                    $ratio = $run->fontSizePt <= 9.0 ? 1 / 24 : ($run->fontSizePt >= 36.0 ? 1 / 32 : 1 / 24 + (1 / 32 - 1 / 24) * ($run->fontSizePt - 9.0) / 27.0);
                    $boldOn = sprintf('%.3F w %s 2 Tr ', $run->fontSizePt * $ratio, ColorOps::strokeOp($color));
                    $boldOff = ' 0 Tr';
                }

                $plain = $bidiLine && $alphaGuard === '' && $tcCmd === '' && $boldOn === '' && !$synth['oblique']
                    && strtolower(trim($run->style->get('text-shadow', 'none'))) === 'none'
                    && strtolower(trim($run->style->get('text-decoration', 'none'))) === 'none';
                if ($plain) {
                    $x = round($runX, 2);
                    $key = $fontId . '|' . sprintf('%.2F|%.2F|%d|', $run->fontSizePt, $baselinePdfY, $run->bidiLevel & 1) . ColorOps::fillOp($color);
                    if ($group !== null && $group['key'] !== $key) {
                        $out .= $this->flushTextGroup($group);
                    }
                    if ($group === null) {
                        $group = ['key' => $key, 'x' => $x, 'ops' => sprintf('BT /%s %.2F Tf %s %.2F %.2F Td %s', $fontId, $run->fontSizePt, ColorOps::fillOp($color), $x, $baselinePdfY, $showOps)];
                    } else {
                        $group['ops'] .= sprintf(' %.2F 0 Td %s', $x - $group['x'], $showOps);
                    }
                    $group['x'] = $x + $lineStartShift;
                    $showOps = '';
                } else {
                    $out .= $this->flushTextGroup($group);
                    $textStartLen = strlen($out);
                }
            }
            if ($showOps !== '') {
                $out .= sprintf(
                    "%s%sBT /%s %.2F Tf %s%s %s%s %s%s%s ET%s%s\n",
                    $alphaGuard,
                    $boldOn !== '' ? 'q ' : '',
                    $fontId,
                    $run->fontSizePt,
                    $tcCmd,
                    ColorOps::fillOp($color),
                    $boldOn,
                    $posOp,
                    $showOps,
                    $tcReset,
                    $boldOff,
                    $boldOn !== '' ? ' Q' : '',
                    $alphaRestore
                );
            }

            $out = $this->effects->applyTextShadow($out, $textStartLen, $run->style->get('text-shadow', 'none'), $run->fontSizePt);

            // Text decorations (underline, overline, line-through)
            $deco = strtolower($run->style->get('text-decoration', 'none'));
            $decoColor = CssColor::parse($run->style->get('text-decoration-color', 'currentcolor'));
            if ($decoColor !== null && preg_match('/\b(underline|overline|line-through)\b/', $deco)) {
                $color = $decoColor;
            }
            if (str_contains($deco, 'overline')) {
                $lineY = $baselinePdfY + ($run->ascent * 0.98);
                $lw = max(0.5, $run->fontSizePt * 0.06);
                $out .= sprintf(
                    "q %.2F w %s %.2F %.2F m %.2F %.2F l S Q\n",
                    $lw,
                    ColorOps::strokeOp($color),
                    $run->x,
                    $lineY,
                    $run->x + $run->width,
                    $lineY
                );
            }
            if (str_contains($deco, 'underline')) {
                $lineY = $baselinePdfY - ($run->fontSizePt * 0.12);
                $lw = max(0.5, $run->fontSizePt * 0.06);
                $out .= sprintf(
                    "q %.2F w %s %.2F %.2F m %.2F %.2F l S Q\n",
                    $lw,
                    ColorOps::strokeOp($color),
                    $run->x,
                    $lineY,
                    $run->x + $run->width,
                    $lineY
                );
            }
            if (str_contains($deco, 'line-through')) {
                $lineY = $baselinePdfY + ($run->fontSizePt * 0.28);
                $lw = max(0.5, $run->fontSizePt * 0.06);
                $out .= sprintf(
                    "q %.2F w %s %.2F %.2F m %.2F %.2F l S Q\n",
                    $lw,
                    ColorOps::strokeOp($color),
                    $run->x,
                    $lineY,
                    $run->x + $run->width,
                    $lineY
                );
            }

            // Clickable Link Annotation (external URLs and internal #anchors)
            if ($run->href !== null && $run->href !== '' && $this->annotOwned) {
                $pdfRunBottom = $baselinePdfY - $run->descent;
                $this->pdf->addLinkAnnotation($this->currentPageIndex, $run->x, $pdfRunBottom, $run->width, $run->height, $run->href);
            }
        }
        $out .= $this->flushTextGroup($group);

        return $out;
    }

    /**
     * Ends a text object shared by neighbouring runs of a line with right-to-left text. Chrome / Edge show such a line
     * with one text operation; bidi-aware extractors (MuPDF) reorder the right-to-left stretches within a text object,
     * so separate text objects per word give the words, numbers and punctuation of the line in a wrong order. The runs
     * keep their positions (relative Td moves); the page looks the same.
     *
     * @param array{key: string, x: float, ops: string}|null $group
     */
    private function flushTextGroup(?array &$group): string
    {
        if ($group === null) {
            return '';
        }
        $ops = $group['ops'] . " ET\n";
        $group = null;
        return $ops;
    }

    /**
     * Destinations of empty inline-level children (<a name="x"></a>, <span id="x"></span>) of a box without line boxes:
     * they take no space, so they point at the top of the next in-flow sibling (else the end of the previous one).
     */
    /** @var array<int, list<int>> spl_object_id(box) => indices of the children registerEmptyInlineAnchors() looks at */
    private array $anchorCandidates = [];

    private function registerEmptyInlineAnchors(LayoutBox $box, float $yShift, float $visTop, float $visBottom): void
    {
        $children = $box->children;
        // The candidates (empty elements with attributes) are found once per box, not on every page: a body with
        // 20 000 headings would otherwise be scanned once per page
        $candidates = $this->anchorCandidates[spl_object_id($box)] ??= array_keys(array_filter(
            $children,
            static fn(LayoutBox $c): bool => $c->domElement !== null && $c->lines === [] && !$c->isTextNode() && $c->domElement->hasAttributes()
        ));
        foreach ($candidates as $i) {
            $child = $children[$i];
            $el = $child->domElement;
            $names = [];
            foreach (['id', 'name'] as $attr) {
                $anchor = trim($el->getAttribute($attr));
                if ($anchor !== '' && ($attr === 'id' || $child->tagName === 'a')) {
                    $names[] = $anchor;
                }
            }
            if ($names === [] || !$child->isInlineLevel() || $child->isPositionedOutOfFlow() || !empty($child->lines)) {
                continue;
            }
            $y = null;
            for ($k = $i + 1, $n = count($children); $k < $n && $y === null; $k++) {
                if (!$children[$k]->isInlineLevel() && !$children[$k]->isPositionedOutOfFlow()) {
                    $y = max($box->getContentY(), $children[$k]->y - max(0.0, $children[$k]->marginTop)); // above the block's margin
                }
            }
            for ($k = $i - 1; $k >= 0 && $y === null; $k--) {
                if (!$children[$k]->isInlineLevel() && !$children[$k]->isPositionedOutOfFlow()) {
                    $y = $children[$k]->y + $children[$k]->height;
                }
            }
            // No block sibling: where its (empty) line would start, the top of the parent's content box (below any
            // padding and border), not the parent's border edge
            $y ??= $box->getContentY();
            if ($y >= $visTop - 0.5 && $y < $visBottom - 0.5) {
                foreach ($names as $name) {
                    $this->pdf->registerDestination($name, $this->currentPageIndex, $this->pageHeight - ($y - $yShift));
                }
            }
        }
    }

    /**
     * Registers the link destinations (id / a[name]) of inline elements at the top of their first line fragment.
     * Anchors travel with the runs as zero-paint entries of TextRun::$decorations (see InlineLayout).
     */
    private function registerInlineAnchors(LineBox $line, float $yShift): void
    {
        foreach ($line->runs as $run) {
            if (empty($run->decorations)) {
                continue;
            }
            foreach ($run->decorations as $deco) {
                $el = $deco->domElement;
                if ($el === null || !$el->hasAttributes()) {
                    continue;
                }
                $destY = $this->pageHeight - ($line->y - $yShift);
                foreach (['id', 'name'] as $attr) {
                    $anchor = trim($el->getAttribute($attr));
                    if ($anchor !== '' && ($attr === 'id' || $deco->tagName === 'a')) {
                        $this->pdf->registerDestination($anchor, $this->currentPageIndex, $destY);
                    }
                }
            }
        }
    }

    /**
     * Paints backgrounds and borders of decorated inline elements (<span style="background:..">, <mark>, <code>, badges)
     * as one continuous box per element per line, behind the text.
     */
    private function drawInlineDecorations(LineBox $line, float $yShift): string
    {
        /** @var array<int, array{box: LayoutBox, left: float, right: float, baseline: float}> $groups */
        $groups = [];
        foreach ($line->runs as $run) {
            if (empty($run->decorations)) {
                continue;
            }
            $baseline = ($run->y + $run->ascent) - $yShift;
            foreach ($run->decorations as $deco) {
                $oid = spl_object_id($deco);
                $left = $run->x - ($run->decoLeft[$oid] ?? 0.0);
                $right = $run->x + $run->width + ($run->decoRight[$oid] ?? 0.0);
                if (!isset($groups[$oid])) {
                    $groups[$oid] = ['box' => $deco, 'left' => $left, 'right' => $right, 'baseline' => $baseline];
                } else {
                    $groups[$oid]['left'] = min($groups[$oid]['left'], $left);
                    $groups[$oid]['right'] = max($groups[$oid]['right'], $right);
                    if ($run->inlineBox === null) {
                        $groups[$oid]['baseline'] = $baseline;
                    }
                }
            }
        }

        $out = '';
        foreach ($groups as $g) {
            $el = $g['box'];
            $fs = $el->fontSizePt;
            $ttf = $this->fontManager->resolveFont(
                $el->style->get('font-family', 'DejaVu Sans'),
                $el->style->get('font-weight', 'normal'),
                $el->style->get('font-style', 'normal')
            );
            $asc = ($ttf->ascent / 1000.0) * $fs;
            $desc = (abs($ttf->descent) / 1000.0) * $fs;
            $top = $g['baseline'] - $asc - $el->paddingTop - $el->borderTop;
            $bottom = $g['baseline'] + $desc + $el->paddingBottom + $el->borderBottom;
            $w = $g['right'] - $g['left'];
            $h = $bottom - $top;
            if ($w <= 0.05 || $h <= 0.05) {
                continue;
            }
            $radii = $this->resolveBorderRadii($el, $w, $h);
            $bg = CssColor::parse($el->style->get('background-color', 'transparent'));
            if ($bg !== null) {
                $out .= $this->drawFilledRect($g['left'], $top, $w, $h, $radii, $bg);
            }
            $out .= $this->drawBackgroundImage($el, $g['left'], $top, $w, $h, $radii);
            $out .= $this->drawBorders($el, $g['left'], $top, $w, $h, $radii);
        }
        return $out;
    }

    /**
     * Paints the first background-image layer: linear/radial gradient or url() image honoring
     * background-size, background-position and background-repeat.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function drawBackgroundImage(LayoutBox $box, float $x, float $y, float $w, float $h, array $radii): string
    {
        $bgImage = trim($box->style->get('background-image', 'none'));
        if ($bgImage === '' || strtolower($bgImage) === 'none' || $w <= 0.05 || $h <= 0.05) {
            return '';
        }
        $layer = $this->splitTopLevelCommas($bgImage)[0] ?? $bgImage;
        $lower = strtolower($layer);

        if (str_contains($lower, 'radial-gradient(')) {
            $radial = CssColor::parseRadialGradient($layer);
            return $radial !== null ? $this->drawRadialGradient($x, $y, $w, $h, $radii, $radial) : '';
        }
        if (str_contains($lower, 'linear-gradient(')) {
            $gradient = CssColor::parseLinearGradient($layer);
            return $gradient !== null ? $this->drawLinearGradient($x, $y, $w, $h, $radii, $gradient) : '';
        }
        if (!preg_match('/url\((.+)\)/is', $layer, $um)) {
            return '';
        }

        $src = trim($um[1], " \t\n\r\0\x0B'\"");
        $svgBg = SvgBackground::load($this->imageLoader, $src);
        $img = $svgBg !== null ? $svgBg['img'] : $this->imageLoader->load($src);
        if ($img === null) {
            return '';
        }

        $natW = max(0.01, $img['width'] * CssUnit::getPxToPt());
        $natH = max(0.01, $img['height'] * CssUnit::getPxToPt());
        $ratio = $natW / $natH;

        $sizeCss = strtolower(trim($box->style->get('background-size', 'auto')));
        if ($sizeCss === 'cover' || $sizeCss === 'contain') {
            $scale = $sizeCss === 'cover' ? max($w / $natW, $h / $natH) : min($w / $natW, $h / $natH);
            $tileW = $natW * $scale;
            $tileH = $natH * $scale;
        } else {
            $sizeTokens = preg_split('/\s+/', $sizeCss, -1, PREG_SPLIT_NO_EMPTY) ?: ['auto'];
            $sw = ($sizeTokens[0] ?? 'auto') === 'auto' ? null : CssUnit::toPt($sizeTokens[0], $w, $box->fontSizePt);
            $sh = (($sizeTokens[1] ?? 'auto') === 'auto') ? null : CssUnit::toPt($sizeTokens[1], $h, $box->fontSizePt);
            if ($sw === null && $sh === null) {
                $tileW = $natW;
                $tileH = $natH;
            } elseif ($sh === null) {
                $tileW = (float) $sw;
                $tileH = $tileW / $ratio;
            } elseif ($sw === null) {
                $tileH = $sh;
                $tileW = $tileH * $ratio;
            } else {
                $tileW = $sw;
                $tileH = $sh;
            }
        }
        if ($tileW <= 0.05 || $tileH <= 0.05) {
            return '';
        }

        [$offX, $offY] = $this->resolvePosition($box->style->get('background-position', '0% 0%'), $w - $tileW, $h - $tileH);

        $repeatTokens = preg_split('/\s+/', strtolower(trim($box->style->get('background-repeat', 'repeat'))), -1, PREG_SPLIT_NO_EMPTY) ?: ['repeat'];
        if (count($repeatTokens) === 1) {
            [$repX, $repY] = match ($repeatTokens[0]) {
                'no-repeat' => [false, false],
                'repeat-x' => [true, false],
                'repeat-y' => [false, true],
                default => [true, true],
            };
        } else {
            $repX = $repeatTokens[0] !== 'no-repeat';
            $repY = $repeatTokens[1] !== 'no-repeat';
        }

        $xs = [$x + $offX];
        $ys = [$y + $offY];
        if ($repX) {
            $startX = $x + $offX - ceil($offX / $tileW) * $tileW;
            $xs = [];
            for ($tx = $startX; $tx < $x + $w - 0.01 && count($xs) < 400; $tx += $tileW) {
                $xs[] = $tx;
            }
        }
        if ($repY) {
            $startY = $y + $offY - ceil($offY / $tileH) * $tileH;
            $ys = [];
            for ($ty = $startY; $ty < $y + $h - 0.01 && count($ys) < 400; $ty += $tileH) {
                $ys[] = $ty;
            }
        }

        $out = "q\n" . $this->buildRoundedRectPath($x, $y, $w, $h, $radii) . "W n\n";
        $tiles = 0;
        foreach ($ys as $ty) {
            foreach ($xs as $tx) {
                if (++$tiles > 4000) {
                    break 2;
                }
                if ($svgBg !== null) {
                    // SVG backgrounds stay vector: rendered into each tile
                    $out .= $this->svgRenderer->renderSvg($svgBg['dom'], $tx, $ty, $tileW, $tileH, $this->pageHeight, $box->style->get('color', '#111827'));
                    continue;
                }
                $out .= sprintf("q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q\n", $tileW, $tileH, $tx, $this->pageHeight - ($ty + $tileH), $img['id']);
            }
        }
        return $out . "Q\n";
    }

    /**
     * Paints a CSS gradient string (linear / radial) into a rectangle; '' when the value is not a gradient.
     */
    public function paintGradientCss(string $css, float $x, float $y, float $w, float $h): string
    {
        $none = [0.0, 0.0, 0.0, 0.0];
        $lower = strtolower($css);
        if (str_contains($lower, 'radial-gradient(')) {
            $g = CssColor::parseRadialGradient($css);
            return $g !== null ? $this->drawRadialGradient($x, $y, $w, $h, $none, $g) : '';
        }
        if (str_contains($lower, 'linear-gradient(')) {
            $g = CssColor::parseLinearGradient($css);
            return $g !== null ? $this->drawLinearGradient($x, $y, $w, $h, $none, $g) : '';
        }
        return '';
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{shape: string, size: string, position: string, radii: array{0: ?string, 1: ?string}, stops: list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}>} $gradient
     */
    private function drawRadialGradient(float $x, float $y, float $w, float $h, array $radii, array $gradient): string
    {
        [$cxOff, $cyOff] = $this->resolvePosition($gradient['position'], $w, $h);
        $dxMin = min($cxOff, $w - $cxOff);
        $dxMax = max($cxOff, $w - $cxOff);
        $dyMin = min($cyOff, $h - $cyOff);
        $dyMax = max($cyOff, $h - $cyOff);
        $isCircle = $gradient['shape'] === 'circle';

        if ($gradient['radii'][0] !== null) {
            $rx = CssUnit::toPt($gradient['radii'][0], $w);
            $ry = $gradient['radii'][1] !== null ? CssUnit::toPt($gradient['radii'][1], $h) : $rx;
        } else {
            [$rx, $ry] = match ($gradient['size']) {
                'closest-side' => $isCircle ? [min($dxMin, $dyMin), min($dxMin, $dyMin)] : [$dxMin, $dyMin],
                'farthest-side' => $isCircle ? [max($dxMax, $dyMax), max($dxMax, $dyMax)] : [$dxMax, $dyMax],
                'closest-corner' => $isCircle
                    ? [hypot($dxMin, $dyMin), hypot($dxMin, $dyMin)]
                    : [$dxMin * M_SQRT2, $dyMin * M_SQRT2],
                default => $isCircle
                    ? [hypot($dxMax, $dyMax), hypot($dxMax, $dyMax)]
                    : [$dxMax * M_SQRT2, $dyMax * M_SQRT2],
            };
        }
        $rx = max(0.1, $rx);
        $ry = max(0.1, $ry);

        $shId = $this->pdf->registerRadialShading([0.0, 0.0, 0.0, 0.0, 0.0, $rx], $gradient['stops']);
        $cxPdf = $x + $cxOff;
        $cyPdf = $this->pageHeight - ($y + $cyOff);

        $out = "q\n" . $this->buildRoundedRectPath($x, $y, $w, $h, $radii) . "W n\n";
        $out .= sprintf("1 0 0 %.5F %.3F %.3F cm\n", $ry / $rx, $cxPdf, $cyPdf);
        $out .= "/{$shId} sh\nQ\n";
        return $out;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelCommas(string $str): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $result[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $result[] = trim($current);
        }
        return $result;
    }

    private function drawListMarker(LayoutBox $liBox, float $localX, float $localY): string
    {
        if ($liBox->listMarkerImage !== null) {
            $imgMarker = $this->drawListImageMarker($liBox, $localX, $localY);
            if ($imgMarker !== '') {
                return $imgMarker;
            }
        }
        $marker = $liBox->listMarker ?? '•';
        $fontFamily = $liBox->style->get('font-family', 'DejaVu Sans');
        $fontWeight = $liBox->style->get('font-weight', 'normal');
        $ttf = $this->fontManager->resolveFont($fontFamily, $fontWeight, 'normal');
        $fontId = $this->fontManager->markFontUsed($ttf);

        $markerStyle = $liBox->markerStyle;
        $fs = $markerStyle !== null ? $markerStyle->getFontSizePt() : $liBox->fontSizePt;
        $color = CssColor::parse(($markerStyle ?? $liBox->style)->get('color', '#111827')) ?? [0.067, 0.094, 0.153, 1.0];

        $markerW = $this->fontManager->measureText($marker, $fontFamily, $fontWeight, 'normal', $fs);
        if (in_array($marker, ['•', '◦', '▪'], true)) {
            // Chromium's outside symbol marker: its box starts (ascent * 2/3 + 8) px before the content edge and the
            // symbol, (ascent * 2/3 + 1) / 2 px wide, is drawn 1 px into it; the glyph is centred on that symbol
            $offsetPx = intdiv((int) round($ttf->ascent / 1000.0 * $fs / 0.75) * 2, 3);
            $markerX = $localX - ($offsetPx + 8) * 0.75 + (1 + intdiv($offsetPx + 1, 2) / 2.0) * 0.75 - $markerW / 2.0;
        } elseif (in_array(substr(ltrim($liBox->style->get('list-style-type', 'disc')), 0, 1), ['"', "'"], true)) {
            $markerX = $localX - $markerW; // list-style-type: '-> ': the string ends at the content edge
        } else {
            // "1. ": the marker text and a space end at the content edge
            $markerX = $localX - $markerW - $this->fontManager->measureText(' ', $fontFamily, $fontWeight, 'normal', $fs);
        }
        if (strtolower(trim($liBox->style->get('direction', 'ltr'))) === 'rtl') {
            // RTL list items keep the marker on the right side
            $markerX = $localX + $liBox->width + 5.0;
        }

        if (!empty($liBox->lines)) {
            $firstLine = $liBox->lines[0];
            $baselineDocY = ($firstLine->y - $liBox->y + $localY) + $firstLine->baselineOffset;
        } else {
            $baselineDocY = $localY + $liBox->paddingTop + ($fs * 0.85);
        }

        $baselinePdfY = $this->pageHeight - $baselineDocY;
        $hexGids = $this->fontManager->encodeTextToHexGids($ttf, $marker);

        return sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $fontId,
            $fs,
            ColorOps::fillOp($color),
            $markerX,
            $baselinePdfY,
            $hexGids
        );
    }

    /**
     * Draws a list-style-image marker left of the list item's first line (outside position).
     */
    private function drawListImageMarker(LayoutBox $liBox, float $localX, float $localY): string
    {
        $img = $this->imageLoader->load((string) $liBox->listMarkerImage);
        if ($img === null) {
            return '';
        }
        $fs = $liBox->fontSizePt;
        $natW = $img['width'] * CssUnit::getPxToPt();
        $natH = $img['height'] * CssUnit::getPxToPt();
        $scale = min(1.0, ($fs * 1.2) / max(0.01, $natH));
        $w = $natW * $scale;
        $h = $natH * $scale;
        if (!empty($liBox->lines)) {
            $line = $liBox->lines[0];
            $midDocY = ($line->y - $liBox->y + $localY) + $line->baselineOffset - $fs * 0.3;
        } else {
            $midDocY = $localY + $liBox->paddingTop + $fs * 0.55;
        }
        $x = $localX - $w - 5.0;
        if (strtolower(trim($liBox->style->get('direction', 'ltr'))) === 'rtl') {
            $x = $localX + $liBox->width + 5.0;
        }
        return $this->placeImage($img['id'], $x, $midDocY - $h / 2.0, $w, $h, $x, $midDocY - $h / 2.0, $w, $h, [0.0, 0.0, 0.0, 0.0], false);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii [tl, tr, br, bl]
     * @param array{0: float, 1: float, 2: float, 3: float} $color [r, g, b, a]
     */
    private function drawFilledRect(float $x, float $y, float $w, float $h, array $radii, array $color): string
    {
        $out = "q\n";
        if ($color[3] < 0.999) {
            $gsId = $this->pdf->registerAlphaState($color[3]);
            $out .= "/{$gsId} gs\n";
        }
        $out .= ColorOps::fillOp($color) . "\n";
        $out .= $this->buildRoundedRectPath($x, $y, $w, $h, $radii);
        $out .= "f\nQ\n";
        return $out;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{angle: float, stops: list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}>} $gradient
     */
    private function drawLinearGradient(float $x, float $y, float $w, float $h, array $radii, array $gradient): string
    {
        $pdfBottom = $this->pageHeight - ($y + $h);
        $pdfTop = $this->pageHeight - $y;
        $cx = $x + $w / 2.0;
        $cy = ($pdfBottom + $pdfTop) / 2.0;

        // Convert CSS angle (0deg = to top, 90deg = to right, 180deg = to bottom) into math radians in PDF space
        $rad = deg2rad(90.0 - $gradient['angle']);
        $dx = cos($rad);
        $dy = sin($rad);

        $halfLen = (abs($w * $dx) + abs($h * $dy)) / 2.0;
        $x0 = $cx - $dx * $halfLen;
        $y0 = $cy - $dy * $halfLen;
        $x1 = $cx + $dx * $halfLen;
        $y1 = $cy + $dy * $halfLen;

        $shId = $this->pdf->registerAxialShading([$x0, $y0, $x1, $y1], $gradient['stops']);

        $out = "q\n";
        $out .= $this->buildRoundedRectPath($x, $y, $w, $h, $radii);
        $out .= "W n\n";
        $out .= "/{$shId} sh\n";
        $out .= "Q\n";
        return $out;
    }

    /**
     * Renders a soft CSS box-shadow behind a box.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function drawBoxShadow(
        string $shadowCss,
        float $x,
        float $y,
        float $w,
        float $h,
        array $radii,
        float $fontSizePt
    ): string {
        $layers = $this->splitTopLevelCommas($shadowCss);
        $out = '';
        // The first shadow is painted on top, so paint in reverse order
        foreach (array_reverse($layers) as $layer) {
            $out .= $this->drawSingleBoxShadow($layer, $x, $y, $w, $h, $radii, $fontSizePt);
        }
        if ($out === '') {
            return '';
        }
        // Outer shadows are only visible outside the border box (even-odd clip: page minus box)
        return "q
" . sprintf("0 0 %.2F %.2F re
", $this->pageWidth, $this->pageHeight)
            . $this->buildRoundedRectPath($x, $y, $w, $h, $radii) . "W* n
" . $out . "Q
";
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function drawSingleBoxShadow(
        string $shadowCss,
        float $x,
        float $y,
        float $w,
        float $h,
        array $radii,
        float $fontSizePt
    ): string {
        if (str_contains(strtolower($shadowCss), 'inset')) {
            return '';
        }

        // Extract color (rgba/rgb/hex) from shadow string
        $shadowColor = [0.0, 0.0, 0.0, 0.10];
        $cleaned = $shadowCss;
        if (preg_match('/(rgba?\([^)]+\)|hsla?\([^)]+\)|#[0-9a-fA-F]{3,8})/i', $shadowCss, $cm)) {
            $parsedColor = CssColor::parse($cm[1]);
            if ($parsedColor !== null) {
                $shadowColor = $parsedColor;
            }
            $cleaned = str_replace($cm[1], '', $shadowCss);
        }

        $lengths = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($lengths) < 2) {
            return '';
        }

        $offsetX = CssUnit::toPt($lengths[0], 0.0, $fontSizePt);
        $offsetY = CssUnit::toPt($lengths[1], 0.0, $fontSizePt);
        $blur = isset($lengths[2]) ? max(0.0, CssUnit::toPt($lengths[2], 0.0, $fontSizePt)) : 0.0;
        $spread = isset($lengths[3]) ? CssUnit::toPt($lengths[3], 0.0, $fontSizePt) : 0.0;

        $steps = $blur > 1.0 ? min(5, max(2, (int) ceil($blur / 2.5))) : 1;
        $baseAlpha = $shadowColor[3] / $steps;
        if ($baseAlpha <= 0.004) {
            return '';
        }

        $out = '';
        for ($s = $steps; $s >= 1; $s--) {
            $expand = $spread + ($blur * ($s / $steps) * 0.65);
            $sx = $x + $offsetX - $expand;
            $sy = $y + $offsetY - $expand;
            $sw = $w + 2.0 * $expand;
            $sh = $h + 2.0 * $expand;
            if ($sw <= 0.1 || $sh <= 0.1) {
                continue;
            }
            $sRadii = [
                max(0.0, $radii[0] + $expand * 0.5),
                max(0.0, $radii[1] + $expand * 0.5),
                max(0.0, $radii[2] + $expand * 0.5),
                max(0.0, $radii[3] + $expand * 0.5),
            ];
            $out .= $this->drawFilledRect($sx, $sy, $sw, $sh, $sRadii, [$shadowColor[0], $shadowColor[1], $shadowColor[2], $baseAlpha]);
        }

        return $out;
    }

    /**
     * Draws an image inside ($x, $y, $w, $h) with rounded corner clipping and object-fit support.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function drawImageInRect(
        string $src,
        float $x,
        float $y,
        float $w,
        float $h,
        array $radii,
        string $objectFit = 'fill',
        string $objectPosition = '50% 50%',
        string $filterCss = 'none',
        bool $pixelated = false
    ): string {
        if ($w <= 0.1 || $h <= 0.1) {
            return '';
        }

        $imgData = $this->imageLoader->loadVariant($src, $filterCss, $pixelated);
        if ($imgData === null) {
            return '';
        }

        if ($objectFit === 'none' || $objectFit === 'scale-down') {
            $natW = $imgData['width'] * CssUnit::getPxToPt();
            $natH = $imgData['height'] * CssUnit::getPxToPt();
            if ($objectFit === 'scale-down' && ($natW > $w || $natH > $h)) {
                $objectFit = 'contain';
            } else {
                [$px, $py] = $this->resolvePosition($objectPosition, $w - $natW, $h - $natH);
                return $this->placeImage($imgData['id'], $x, $y, $w, $h, $x + $px, $y + $py, $natW, $natH, $radii, true);
            }
        }

        $drawX = $x;
        $drawY = $y;
        $drawW = $w;
        $drawH = $h;

        $imgRatio = ($imgData['height'] > 0) ? ($imgData['width'] / $imgData['height']) : 1.0;
        $boxRatio = $w / $h;

        if ($objectFit === 'contain') {
            if ($imgRatio > $boxRatio) {
                $drawW = $w;
                $drawH = $w / $imgRatio;
                $drawY = $y + ($h - $drawH) / 2.0;
            } else {
                $drawH = $h;
                $drawW = $h * $imgRatio;
                $drawX = $x + ($w - $drawW) / 2.0;
            }
        } elseif ($objectFit === 'cover') {
            if ($imgRatio > $boxRatio) {
                $drawH = $h;
                $drawW = $h * $imgRatio;
                $drawX = $x - ($drawW - $w) / 2.0;
            } else {
                $drawW = $w;
                $drawH = $w / $imgRatio;
                $drawY = $y - ($drawH - $h) / 2.0;
            }
        }

        if ($objectFit === 'contain' || $objectFit === 'cover') {
            [$px, $py] = $this->resolvePosition($objectPosition, $w - $drawW, $h - $drawH);
            $drawX = $x + $px;
            $drawY = $y + $py;
        }

        $hasRadius = ($radii[0] + $radii[1] + $radii[2] + $radii[3]) > 0.2;
        return $this->placeImage($imgData['id'], $x, $y, $w, $h, $drawX, $drawY, $drawW, $drawH, $radii, $hasRadius || $objectFit === 'cover');
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function placeImage(string $imgId, float $clipX, float $clipY, float $clipW, float $clipH, float $drawX, float $drawY, float $drawW, float $drawH, array $radii, bool $clip): string
    {
        $pdfY = $this->pageHeight - ($drawY + $drawH);
        $out = "q\n";
        if ($clip) {
            $out .= $this->buildRoundedRectPath($clipX, $clipY, $clipW, $clipH, $radii) . "W n\n";
        }
        $out .= sprintf("%.2F 0 0 %.2F %.2F %.2F cm /%s Do\nQ\n", $drawW, $drawH, $drawX, $pdfY, $imgId);
        return $out;
    }

    /**
     * Resolves a CSS <position> ("left top", "50% 20%", "right 10pt bottom") into offsets inside the free space.
     *
     * @return array{0: float, 1: float}
     */
    private function resolvePosition(string $css, float $freeW, float $freeH): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($css)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $x = null;
        $y = null;
        $resolve = static fn(string $t, float $free): float => match ($t) {
            'left', 'top' => 0.0,
            'right', 'bottom' => $free,
            'center' => $free / 2.0,
            default => CssUnit::toPt($t, $free),
        };
        if (count($tokens) === 1) {
            $t = $tokens[0];
            if ($t === 'top' || $t === 'bottom') {
                return [$freeW / 2.0, $resolve($t, $freeH)];
            }
            return [$resolve($t, $freeW), $freeH / 2.0];
        }
        foreach ($tokens as $t) {
            if ($t === 'top' || $t === 'bottom') {
                $y = $resolve($t, $freeH);
            } elseif ($t === 'left' || $t === 'right') {
                $x = $resolve($t, $freeW);
            } elseif ($x === null) {
                $x = $resolve($t, $freeW);
            } elseif ($y === null) {
                $y = $resolve($t, $freeH);
            }
        }
        return [$x ?? $freeW / 2.0, $y ?? $freeH / 2.0];
    }

    /**
     * Draws borders around a box (either uniform rounded border or per-side borders).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private function drawBorders(LayoutBox $box, float $x, float $y, float $w, float $h, array $radii): string
    {
        $bt = $box->borderTop;
        $br = $box->borderRight;
        $bb = $box->borderBottom;
        $bl = $box->borderLeft;

        if ($bt <= 0.01 && $br <= 0.01 && $bb <= 0.01 && $bl <= 0.01) {
            return '';
        }

        $borderImage = $this->borderImagePainter->paint($box, $x, $y, $w, $h);
        if ($borderImage !== null) {
            return $borderImage;
        }

        $complex = $this->borderPainter->paintIfComplex($box, $x, $y, $w, $h, $radii);
        if ($complex !== null) {
            return $complex;
        }

        $hasRadius = ($radii[0] + $radii[1] + $radii[2] + $radii[3]) > 0.2;
        $uniformWidth = abs($bt - $br) < 0.05 && abs($bt - $bb) < 0.05 && abs($bt - $bl) < 0.05 && $bt > 0.05;

        if ($uniformWidth && $hasRadius) {
            $color = CssColor::parse($box->style->get('border-top-color', '#000000'));
            $style = strtolower($box->style->get('border-top-style', 'solid'));
            if ($color !== null) {
                $inset = $bt / 2.0;
                $adjRadii = [
                    max(0.0, $radii[0] - $inset),
                    max(0.0, $radii[1] - $inset),
                    max(0.0, $radii[2] - $inset),
                    max(0.0, $radii[3] - $inset),
                ];
                return $this->drawStrokedRoundedRect($x + $inset, $y + $inset, $w - $bt, $h - $bt, $adjRadii, $bt, $color, $style);
            }
        }

        $out = '';
        $pdfTop = $this->pageHeight - $y;
        $pdfBottom = $this->pageHeight - ($y + $h);

        // Solid borders: paint exact filled trapezoid-free bands so corners never overlap (no double alpha)
        $sides = [
            'top' => [$bt, $x, $y, $w, $bt],
            'bottom' => [$bb, $x, $y + $h - $bb, $w, $bb],
            'left' => [$bl, $x, $y + $bt, $bl, max(0.0, $h - $bt - $bb)],
            'right' => [$br, $x + $w - $br, $y + $bt, $br, max(0.0, $h - $bt - $bb)],
        ];
        $allSolid = true;
        foreach ($sides as $side => $info) {
            if ($info[0] > 0.05 && !in_array(strtolower($box->style->get("border-{$side}-style", 'solid')), ['solid', 'inset', 'outset', 'groove', 'ridge'], true)) {
                $allSolid = false;
            }
        }
        if ($allSolid && !$hasRadius) {
            foreach ($sides as $side => [$bw, $rx, $ry, $rw, $rh]) {
                if ($bw <= 0.05 || $rw <= 0.0 || $rh <= 0.0) {
                    continue;
                }
                $c = CssColor::parse($box->style->get("border-{$side}-color", '#000000'));
                if ($c !== null) {
                    $out .= $this->drawFilledRect($rx, $ry, $rw, $rh, [0.0, 0.0, 0.0, 0.0], $c);
                }
            }
            return $out;
        }

        // Top border
        if ($bt > 0.05) {
            $c = CssColor::parse($box->style->get('border-top-color', '#000000'));
            $st = strtolower($box->style->get('border-top-style', 'solid'));
            if ($c !== null) {
                $ly = $pdfTop - ($bt / 2.0);
                $out .= $this->strokeLineSegment($x, $ly, $x + $w, $ly, $bt, $c, $st);
            }
        }
        // Bottom border
        if ($bb > 0.05) {
            $c = CssColor::parse($box->style->get('border-bottom-color', '#000000'));
            $st = strtolower($box->style->get('border-bottom-style', 'solid'));
            if ($c !== null) {
                $ly = $pdfBottom + ($bb / 2.0);
                $out .= $this->strokeLineSegment($x, $ly, $x + $w, $ly, $bb, $c, $st);
            }
        }
        // Left border
        if ($bl > 0.05) {
            $c = CssColor::parse($box->style->get('border-left-color', '#000000'));
            $st = strtolower($box->style->get('border-left-style', 'solid'));
            if ($c !== null) {
                $lx = $x + ($bl / 2.0);
                $out .= $this->strokeLineSegment($lx, $pdfTop, $lx, $pdfBottom, $bl, $c, $st);
            }
        }
        // Right border
        if ($br > 0.05) {
            $c = CssColor::parse($box->style->get('border-right-color', '#000000'));
            $st = strtolower($box->style->get('border-right-style', 'solid'));
            if ($c !== null) {
                $lx = $x + $w - ($br / 2.0);
                $out .= $this->strokeLineSegment($lx, $pdfTop, $lx, $pdfBottom, $br, $c, $st);
            }
        }

        return $out;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function drawStrokedRoundedRect(
        float $x,
        float $y,
        float $w,
        float $h,
        array $radii,
        float $lineWidth,
        array $color,
        string $borderStyle
    ): string {
        $out = "q\n";
        if ($color[3] < 0.999) {
            $gsId = $this->pdf->registerAlphaState($color[3]);
            $out .= "/{$gsId} gs\n";
        }
        $out .= $this->buildDashPattern($borderStyle, $lineWidth);
        $out .= sprintf("%.2F w %s\n", $lineWidth, ColorOps::strokeOp($color));
        $out .= $this->buildRoundedRectPath($x, $y, $w, $h, $radii);
        $out .= "S\nQ\n";
        return $out;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function strokeLineSegment(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lineWidth,
        array $color,
        string $borderStyle
    ): string {
        $out = "q\n";
        if ($color[3] < 0.999) {
            $gsId = $this->pdf->registerAlphaState($color[3]);
            $out .= "/{$gsId} gs\n";
        }
        $out .= $this->buildDashPattern($borderStyle, $lineWidth);
        $out .= sprintf(
            "%.2F w %s %.2F %.2F m %.2F %.2F l S\nQ\n",
            $lineWidth,
            ColorOps::strokeOp($color),
            $x1,
            $y1,
            $x2,
            $y2
        );
        return $out;
    }

    /**
     * Draws the `column-rule` between adjacent columns of the same page chunk.
     */
    private function drawColumnRules(LayoutBox $box, float $yShift, float $visTop, float $visBottom): string
    {
        $style = strtolower($box->style->get('column-rule-style', 'none'));
        if ($style === 'none' || $style === 'hidden') {
            return '';
        }
        $w = CssUnit::toPt($box->style->get('column-rule-width', 'medium'), 0.0, $box->fontSizePt, $box->fontSizePt);
        $colorCss = $box->style->get('column-rule-color', 'currentcolor');
        if (strtolower($colorCss) === 'currentcolor') {
            $colorCss = $box->style->get('color', '#000000');
        }
        $color = CssColor::parse($colorCss);
        if ($w <= 0.01 || $color === null) {
            return '';
        }
        $out = '';
        $rects = $box->columnRects;
        for ($i = 0; $i + 1 < count($rects); $i++) {
            $a = $rects[$i];
            $b = $rects[$i + 1];
            if ($a['chunk'] !== $b['chunk'] || $a['h'] < 0.5) {
                continue;
            }
            if ($a['y'] + $a['h'] < $visTop - 0.5 || $a['y'] > $visBottom + 0.5) {
                continue;
            }
            $x = ($a['x'] + $a['w'] + $b['x']) / 2.0;
            $out .= $this->strokeLineSegment(
                $x,
                $this->pageHeight - ($a['y'] - $yShift),
                $x,
                $this->pageHeight - ($a['y'] + $a['h'] - $yShift),
                $w,
                $color,
                $style
            );
        }
        return $out;
    }

    private function buildDashPattern(string $borderStyle, float $lineWidth): string
    {
        if ($borderStyle === 'dotted') {
            // Round dots: zero-length dashes with round caps
            return sprintf("1 J [0 %.2F] 0 d\n", max(1.0, $lineWidth * 2.0));
        }
        if ($borderStyle === 'dashed') {
            $dash = max(2.0, $lineWidth * 3.0);
            $gap = max(1.5, $lineWidth * 2.0);
            return sprintf("[%.2F %.2F] 0 d\n", $dash, $gap);
        }
        if ($borderStyle === 'dotted') {
            $dot = max(1.0, $lineWidth);
            return sprintf("[%.2F %.2F] 0 d\n", $dot, $dot * 1.5);
        }
        return '';
    }

    /**
     * Builds a PDF path for a rectangle or rounded rectangle using cubic Bézier curves.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $radii [tl, tr, br, bl]
     */
    private function buildRoundedRectPath(float $x, float $y, float $w, float $h, array $radii): string
    {
        [$tl, $tr, $br, $bl] = $radii;
        $pdfBottom = $this->pageHeight - ($y + $h);
        $pdfTop = $this->pageHeight - $y;

        if ($tl <= 0.1 && $tr <= 0.1 && $br <= 0.1 && $bl <= 0.1) {
            return sprintf("%.2F %.2F %.2F %.2F re\n", $x, $pdfBottom, $w, $h);
        }

        $k = 0.5522847498;
        $xLeft = $x;
        $xRight = $x + $w;

        $path = sprintf("%.2F %.2F m\n", $xLeft + $tl, $pdfTop);
        // Top edge -> top-right corner
        $path .= sprintf("%.2F %.2F l\n", $xRight - $tr, $pdfTop);
        if ($tr > 0.1) {
            $path .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xRight - $tr + ($k * $tr),
                $pdfTop,
                $xRight,
                $pdfTop - $tr + ($k * $tr),
                $xRight,
                $pdfTop - $tr
            );
        }
        // Right edge -> bottom-right corner
        $path .= sprintf("%.2F %.2F l\n", $xRight, $pdfBottom + $br);
        if ($br > 0.1) {
            $path .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xRight,
                $pdfBottom + $br - ($k * $br),
                $xRight - $br + ($k * $br),
                $pdfBottom,
                $xRight - $br,
                $pdfBottom
            );
        }
        // Bottom edge -> bottom-left corner
        $path .= sprintf("%.2F %.2F l\n", $xLeft + $bl, $pdfBottom);
        if ($bl > 0.1) {
            $path .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xLeft + $bl - ($k * $bl),
                $pdfBottom,
                $xLeft,
                $pdfBottom + $bl - ($k * $bl),
                $xLeft,
                $pdfBottom + $bl
            );
        }
        // Left edge -> top-left corner
        $path .= sprintf("%.2F %.2F l\n", $xLeft, $pdfTop - $tl);
        if ($tl > 0.1) {
            $path .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xLeft,
                $pdfTop - $tl + ($k * $tl),
                $xLeft + $tl - ($k * $tl),
                $pdfTop,
                $xLeft + $tl,
                $pdfTop
            );
        }
        $path .= "h\n";

        return $path;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} [tl, tr, br, bl]
     */
    private function resolveBorderRadii(LayoutBox $box, float $w, float $h): array
    {
        $maxR = min($w, $h) / 2.0;
        if ($maxR <= 0.1) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $ref = min($w, $h);
        $fs = $box->fontSizePt;

        $tl = min($maxR, max(0.0, CssUnit::toPt($box->style->get('border-top-left-radius', '0'), $ref, $fs)));
        $tr = min($maxR, max(0.0, CssUnit::toPt($box->style->get('border-top-right-radius', '0'), $ref, $fs)));
        $br = min($maxR, max(0.0, CssUnit::toPt($box->style->get('border-bottom-right-radius', '0'), $ref, $fs)));
        $bl = min($maxR, max(0.0, CssUnit::toPt($box->style->get('border-bottom-left-radius', '0'), $ref, $fs)));

        return [$tl, $tr, $br, $bl];
    }

    /**
     * Builds a PDF `cm` command for a CSS transform list (translate/X/Y, scale/X/Y, rotate, skew/X/Y, matrix),
     * applied left-to-right around the transform-origin (default: box center).
     */
    private function buildTransformMatrixCommand(string $transformCss, float $x, float $y, float $w, float $h, string $origin = '50% 50%'): string
    {
        $m = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        if (!preg_match_all('/([a-zA-Z0-9]+)\(([^)]*)\)/', $transformCss, $fns, PREG_SET_ORDER)) {
            return '';
        }

        foreach ($fns as $fn) {
            $name = strtolower($fn[1]);
            $args = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', trim($fn[2])) ?: []), static fn(string $a): bool => $a !== ''));
            $f = null;
            switch ($name) {
                case 'translate':
                    $f = [1.0, 0.0, 0.0, 1.0, CssUnit::toPt($args[0] ?? '0', $w), CssUnit::toPt($args[1] ?? '0', $h)];
                    break;
                case 'translatex':
                    $f = [1.0, 0.0, 0.0, 1.0, CssUnit::toPt($args[0] ?? '0', $w), 0.0];
                    break;
                case 'translatey':
                    $f = [1.0, 0.0, 0.0, 1.0, 0.0, CssUnit::toPt($args[0] ?? '0', $h)];
                    break;
                case 'scale':
                    $sx = (float) ($args[0] ?? 1);
                    $sy = isset($args[1]) ? (float) $args[1] : $sx;
                    $f = [$sx, 0.0, 0.0, $sy, 0.0, 0.0];
                    break;
                case 'scalex':
                    $f = [(float) ($args[0] ?? 1), 0.0, 0.0, 1.0, 0.0, 0.0];
                    break;
                case 'scaley':
                    $f = [1.0, 0.0, 0.0, (float) ($args[0] ?? 1), 0.0, 0.0];
                    break;
                case 'rotate':
                case 'rotatez':
                    $a = $this->parseAngle($args[0] ?? '0');
                    $f = [cos($a), sin($a), -sin($a), cos($a), 0.0, 0.0];
                    break;
                case 'skew':
                    $f = [1.0, tan($this->parseAngle($args[1] ?? '0')), tan($this->parseAngle($args[0] ?? '0')), 1.0, 0.0, 0.0];
                    break;
                case 'skewx':
                    $f = [1.0, 0.0, tan($this->parseAngle($args[0] ?? '0')), 1.0, 0.0, 0.0];
                    break;
                case 'skewy':
                    $f = [1.0, tan($this->parseAngle($args[0] ?? '0')), 0.0, 1.0, 0.0, 0.0];
                    break;
                case 'matrix':
                    if (count($args) >= 6) {
                        $px = CssUnit::getPxToPt();
                        $f = [(float) $args[0], (float) $args[1], (float) $args[2], (float) $args[3], (float) $args[4] * $px, (float) $args[5] * $px];
                    }
                    break;
            }
            if ($f !== null) {
                $m = $this->multiplyMatrix($m, $f);
            }
        }

        // Transform around the origin (in document space, y pointing down)
        [$ox, $oy] = $this->resolvePosition($origin, $w, $h);
        $ox += $x;
        $oy += $y;
        $m = $this->multiplyMatrix($this->multiplyMatrix([1.0, 0.0, 0.0, 1.0, $ox, $oy], $m), [1.0, 0.0, 0.0, 1.0, -$ox, -$oy]);

        // Convert from top-left document space to PDF bottom-left space: F * M * F with F(x, y) = (x, H - y)
        [$a, $b, $c, $d, $e, $f] = $m;
        $hp = $this->pageHeight;
        return sprintf("%.5F %.5F %.5F %.5F %.3F %.3F cm\n", $a, -$b, -$c, $d, $c * $hp + $e, $hp - $d * $hp - $f);
    }

    private function parseAngle(string $value): float
    {
        $v = strtolower(trim($value));
        $num = (float) $v;
        return match (true) {
            str_ends_with($v, 'grad') => deg2rad($num * 0.9),
            str_ends_with($v, 'rad') => $num,
            str_ends_with($v, 'turn') => $num * 2.0 * M_PI,
            default => deg2rad($num),
        };
    }

    /**
     * Multiplies two affine matrices [a b c d e f] (result applies $n first, then $m).
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $n
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private function multiplyMatrix(array $m, array $n): array
    {
        return [
            $m[0] * $n[0] + $m[2] * $n[1],
            $m[1] * $n[0] + $m[3] * $n[1],
            $m[0] * $n[2] + $m[2] * $n[3],
            $m[1] * $n[2] + $m[3] * $n[3],
            $m[0] * $n[4] + $m[2] * $n[5] + $m[4],
            $m[1] * $n[4] + $m[3] * $n[5] + $m[5],
        ];
    }
}
