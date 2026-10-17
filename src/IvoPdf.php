<?php

declare(strict_types=1);

namespace IvoPdf;

use IvoPdf\Compat\MpdfArgs;
use IvoPdf\Compose\PdfComposeApi;
use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssParser;
use IvoPdf\Css\CssUnit;
use IvoPdf\Dom\DomBuilder;
use IvoPdf\Font\FontManager;
use IvoPdf\Font\TtfParser;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LayoutEngine;
use IvoPdf\Layout\Paginator;
use IvoPdf\Pdf\ColorOps;
use IvoPdf\Pdf\PdfDocument;
use IvoPdf\Pdf\PdfEncryption;
use IvoPdf\Pdf\PdfRenderer;
use IvoPdf\Pdf\PdfX;
use IvoPdf\Pdf\SpotColors;
use IvoPdf\Paged\MarginBoxes;
use IvoPdf\Paged\PageLayoutPlan;
use IvoPdf\Paged\PageRefResolver;
use IvoPdf\Paged\RunningElements;
use IvoPdf\Paged\StringSets;
use IvoPdf\Paged\TocGenerator;
use RuntimeException;

/**
 * IvoPDF — Pure-PHP HTML & CSS to PDF Engine with Flexbox, CSS Grid, Tables, Images, SVG, and UTF-8/Cyrillic support.
 */
class IvoPdf
{
    use PdfComposeApi;
    use \IvoPdf\Compat\MpdfApi;
    use \IvoPdf\Compat\CompatExtras;

    private Options $options;
    private FontManager $fontManager;
    private ImageLoader $imageLoader;

    private string $html = '';
    private ?string $overrideBasePath = null;
    private ?string $headerHtml = null;
    private ?string $footerHtml = null;
    /** @var array<string, ?string> Header variants for 'first' | 'odd' | 'even' pages ('' = none on those pages) */
    private array $headerVariants = [];
    /** @var array<string, ?string> Footer variants for 'first' | 'odd' | 'even' pages */
    private array $footerVariants = [];
    private bool $tocEnabled = false;
    private string $tocTitle = '';
    private int $tocMaxLevel = 3;

    private ?LayoutBox $layoutTree = null;
    private ?string $renderedPdf = null;
    private int $pageCount = 0;
    /** Bumped by every change other than appended HTML (mPDF PageNo() reuses its layout while only HTML was appended) */
    private int $stateVersion = 0;
    /**
     * @internal mPDF PageNo(): called after the layout ('layout') and after the pagination ('paginate') of renderCore();
     * returning false stops renderCore() there (a measuring pass, no PDF is built)
     * @var ?\Closure(string, LayoutBox, ?Paginator, int, PageLayoutPlan): bool
     */
    private ?\Closure $layoutProbe = null;

    /**
     * @param Options|array<string, mixed> $options
     */
    public function __construct(Options|array $options = [])
    {
        $this->options = $options instanceof Options ? $options : new Options($options);
        $this->fontManager = new FontManager($this->options->fontDir);
        $this->imageLoader = new ImageLoader($this->options->basePath, $this->options->isRemoteEnabled, $this->options);
        $this->mpdfInit(); // mPDF fontdata / fontDir
    }

    /** mPDF compatibility: `$pdf->useActiveForms = true` maps to the `formFields` option; other mPDF properties are kept in Options::$compat. */
    public function __set(string $name, mixed $value): void
    {
        if ($name === 'useActiveForms') {
            $this->options->formFields = (bool) $value;
            return;
        }
        $this->mpdfSetProperty($name, $value);
    }

    public function __get(string $name): mixed
    {
        if ($name === 'page') {
            return $this->PageNo(); // mPDF: the current page number
        }
        return $name === 'useActiveForms' ? $this->options->formFields : ($this->options->compat[$name] ?? null);
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getFontManager(): FontManager
    {
        return $this->fontManager;
    }

    public function getLayoutTree(): ?LayoutBox
    {
        return $this->layoutTree;
    }

    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    /**
     * Non-fatal problems from the last render (images not found / not allowed, rejected fonts, blocked URLs).
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->options->getWarnings();
    }

    /**
     * Draws a large diagonal semi-transparent text on every page (e.g. "DRAFT", "COPY").
     */
    public function setWatermarkText(mixed $text, mixed $opacity = 0.08): self
    {
        $opacity = MpdfArgs::num($opacity, 0.08);
        $this->options->watermarkText = MpdfArgs::str($text);
        $this->options->watermarkOpacity = $opacity < 0 ? 0.2 : max(0.01, min(1.0, $opacity)); // mPDF: -1 = default alpha 0.2
        $this->invalidate();
        return $this;
    }

    /**
     * Adds a registered font family to the fallback chain used for characters missing from the requested font.
     */
    public function addFallbackFont(string $familyName): self
    {
        $this->fontManager->addFallbackFamily($familyName);
        $this->invalidate();
        return $this;
    }

    /**
     * Loads an HTML string for rendering.
     */
    public function loadHtml(string $html, ?string $basePath = null): self
    {
        $this->html = $html;
        $this->mpdfWritten = false;
        $this->overrideBasePath = $basePath;
        $effectiveBase = $basePath ?? $this->options->basePath;
        $this->imageLoader = new ImageLoader($effectiveBase, $this->options->isRemoteEnabled, $this->options);
        $this->mpdfChunkStarts = [];
        $this->invalidate();
        return $this;
    }

    /** Marks the rendered PDF (and every cached layout) as out of date. */
    private function invalidate(): void
    {
        $this->renderedPdf = null;
        $this->stateVersion++;
    }

    /**
     * Alias of loadHtml() for mPDF compatibility.
     */
    public function WriteHTML(mixed $html, mixed $mode = 0, mixed $init = true, mixed $close = true): self
    {
        return $this->mpdfWriteHtml(MpdfArgs::str($html), MpdfArgs::int($mode)); // mode 1 = \Mpdf\HTMLParserMode::HEADER_CSS (stylesheet text)
    }

    /**
     * Loads an HTML file from disk (subject to allowedPaths / basePath containment if configured).
     */
    public function loadHtmlFile(string $filePath): self
    {
        if (Options::isNetworkPath($filePath)) {
            throw new RuntimeException("Network / non-local path refused (UNC, protocol-relative or stream wrapper): {$filePath}");
        }
        if (!empty($this->options->allowedPaths) || $this->options->basePath !== null) {
            $safe = $this->options->resolveAllowedLocalFile($filePath);
            if ($safe === null) {
                throw new RuntimeException("Access denied or HTML file not found: {$filePath}");
            }
            $filePath = $safe;
        } elseif (!is_file($filePath)) {
            throw new RuntimeException("HTML file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read HTML file: {$filePath}");
        }
        return $this->loadHtml($content, dirname(realpath($filePath) ?: $filePath));
    }

    /**
     * Sets paper size ('A4', 'A3', 'A5', 'Letter', 'Legal' or [widthPt, heightPt]) and orientation ('portrait' | 'landscape').
     *
     * @param string|array{0: float, 1: float} $size
     */
    public function setPaper(string|array $size = 'A4', string $orientation = 'portrait'): self
    {
        $this->options->paperSize = $size;
        $this->options->orientation = $orientation;
        $this->invalidate();
        return $this;
    }

    /**
     * Sets page margins in PDF points (pt).
     */
    public function setMargins(mixed $top, mixed $right = null, mixed $bottom = null, mixed $left = null): self
    {
        $opt = static fn(mixed $v): ?float => $v === null ? null : MpdfArgs::num($v);
        $this->options->setMargin(MpdfArgs::num($top), $opt($right), $opt($bottom), $opt($left));
        $this->invalidate();
        return $this;
    }

    /**
     * Sets running HTML header rendered at the top of every page.
     * Supports {PAGE_NUM} and {PAGE_COUNT} placeholders.
     */
    public function setHeaderHtml(?string $html, string $pages = 'all'): self
    {
        $this->setRunningVariant($this->headerHtml, $this->headerVariants, $html, $pages);
        $this->invalidate();
        return $this;
    }

    /**
     * Sets running HTML footer rendered at the bottom of every page.
     * Supports {PAGE_NUM} and {PAGE_COUNT} placeholders.
     */
    public function setFooterHtml(?string $html, string $pages = 'all'): self
    {
        $this->setRunningVariant($this->footerHtml, $this->footerVariants, $html, $pages);
        $this->invalidate();
        return $this;
    }

    /**
     * Generates a clickable table of contents (h1..h$maxLevel, dotted leaders, page numbers) at the start of the
     * document, followed by a page break. A `<toc />` / `<tocpagebreak>` placeholder in the HTML places it explicitly.
     */
    public function setTableOfContents(bool $enable = true, string $title = '', int $maxLevel = 3): self
    {
        $this->tocEnabled = $enable;
        $this->tocTitle = $title;
        $this->tocMaxLevel = max(1, min(6, $maxLevel));
        $this->invalidate();
        return $this;
    }

    /**
     * Registers a custom TrueType / OpenType font family ("file.ttc#n" selects a face of a collection). Missing bold /
     * italic files are synthesized at render time (emboldened / slanted regular face), as browsers do.
     *
     * @param array{regular: string, bold?: string, italic?: string, bold_italic?: string} $variants
     * @throws \InvalidArgumentException when a file does not exist / is not readable (or is a UNC / stream-wrapper path)
     */
    public function registerFontFamily(string $familyName, array $variants): self
    {
        $this->fontManager->assertFontFiles($familyName, $variants);
        $this->fontManager->registerFamily($familyName, $variants);
        $this->invalidate();
        return $this;
    }

    /**
     * Parses HTML & CSS, computes Block/Flexbox/Grid/Table layout, paginates, and compiles the PDF binary.
     */
    private function renderCore(): self
    {
        CssUnit::setDpi($this->options->dpi);
        ColorOps::configure($this->options); // output color space / PDF/X (validates option combinations)
        ColorOps::startRender();
        $this->fontManager->resetUsedFonts();
        $this->imageLoader->reset();
        $this->options->clearWarnings();
        $this->options->getMaxMemoryBytes(); // reports a unit mistake in maxMemory again (render() read the budget before)
        foreach (array_reverse($this->options->fallbackFonts) as $fallbackFamily) {
            $this->fontManager->addFallbackFamily($fallbackFamily);
        }
        $this->fontManager->setScriptFonts($this->options->scriptFonts, fn(string $m) => $this->options->addWarning($m));

        $savedBasePath = $this->options->basePath;
        $savedTitle = $this->options->title;
        $savedLang = $this->options->lang;
        if ($this->overrideBasePath !== null) {
            $this->options->basePath = $this->overrideBasePath;
        }

        $fm = $this->fontManager;
        ComputedStyle::$fontUnitResolver = static fn(string $family, string $weight, string $style): array => $fm->fontRelativeUnits($family, $weight, $style);
        RenderLimits::begin($this->options); // Options::$maxRenderTime / $maxMemory
        try {
            $cssParser = new CssParser();
            $domBuilder = new DomBuilder($this->options, $cssParser);
            // @font-face families must exist before styles are computed (ex / ch units use the real font metrics)
            $this->fontFacesRegistered = 0;
            $domBuilder->afterStylesheets = fn() => $this->registerCssFontFaces($cssParser->getFontFaces());
            $rootBox = $domBuilder->build(TocGenerator::process(self::gaugeDefaults($this->mpdfTransform($this->compatScan($this->html))), $this->tocEnabled, $this->tocTitle, $this->tocMaxLevel));
            $this->registerCssFontFaces($cssParser->getFontFaces());
            $firstPageLeft = $domBuilder->getDocumentDirection() === 'rtl';
            $cssParser->getPageRules()->firstPageLeft = $firstPageLeft;
            $running = RunningElements::extract($rootBox); // position: running(name) elements leave the flow
            $this->footnoteExtract($rootBox); // float: footnote elements become calls + notes at the page bottom

            if ($this->options->title === '' && $domBuilder->getDocumentTitle() !== '') {
                $this->options->title = $domBuilder->getDocumentTitle();
            }
            if ($this->options->lang === '' && $domBuilder->getDocumentLang() !== '') {
                $this->options->lang = $domBuilder->getDocumentLang();
            }

            // Apply @page rules if defined in CSS
            [$pageWidth, $pageHeight] = $this->options->getPageDimensions();
            $margins = $this->options->margins;
            $this->applyCssPageRules($cssParser->getPageProperties(), $pageWidth, $pageHeight, $margins);

            $printableWidth = max(50.0, $pageWidth - $margins['left'] - $margins['right']);

            $layoutEngine = new LayoutEngine(
                $this->options,
                $this->fontManager,
                $this->imageLoader,
                $pageWidth,
                $pageHeight
            );

            // Page geometry plan: @page cascade (:first/:left/:right/named) + space reserved for running headers/footers
            $plan = new PageLayoutPlan(
                $cssParser->getPageRules(),
                function (array $props): array {
                    [$w, $h] = $this->options->getPageDimensions();
                    $m = $this->options->margins;
                    $this->applyCssPageRules($props, $w, $h, $m);
                    return [$w, $h, $m];
                },
                function (int $index, float $cw, array $m, float $ph) use ($layoutEngine): array {
                    $eTop = $m['top'];
                    $eBottom = $m['bottom'];
                    // mPDF named headers / footers: space for the tallest variant that can appear
                    foreach ($this->mpdfCandidates('header', $this->pickRunning($this->headerHtml, $this->headerVariants, $index)) as $hdr) {
                        if ($hdr !== null && trim($hdr) !== '') {
                            $startY = $this->mpdfHeaderY($m['top']);
                            $sample = $this->buildRunningBox($hdr, 888, 888, $layoutEngine, $m['left'], $startY, $cw);
                            $headerBottom = $sample->y + $sample->height + 6.0;
                            if ($headerBottom > $eTop) {
                                $eTop = min($ph * 0.4, $headerBottom);
                            }
                        }
                    }
                    foreach ($this->mpdfCandidates('footer', $this->pickRunning($this->footerHtml, $this->footerVariants, $index)) as $ftr) {
                        if ($ftr !== null && trim($ftr) !== '') {
                            $sample = $this->buildRunningBox($ftr, 888, 888, $layoutEngine, $m['left'], 0.0, $cw);
                            $neededBottom = $this->mpdfFooterReserve($sample->height);
                            if ($neededBottom > $eBottom) {
                                $eBottom = min($ph * 0.4, $neededBottom);
                            }
                        }
                    }
                    return [$eTop, $eBottom];
                },
                $this->headerVariants !== [] || $this->footerVariants !== []
            );
            $plan->setMirrorMargins(!empty($this->options->compat['mirrorMargins'])); // mPDF mirrorMargins
            $this->footnoteAttach($plan, $layoutEngine);
            $firstName = $this->firstPageName($rootBox);
            $first = $plan->box(0, $firstName);
            $effectiveTopMargin = $first['eTop'];
            $printableHeight = $first['ph'];
            $layoutX = $first['m']['left'];
            $printableWidth = $plan->layoutWidth();
            $bodyMarginX = $layoutEngine->bodyHorizontalMargins($rootBox, $printableWidth);
            if ($plan->names() !== []) {
                $this->applyNamedPageWidths($rootBox, $plan, $printableWidth, $bodyMarginX);
            }
            $layoutEngine->setPageGeometry($effectiveTopMargin, $printableHeight);
            $layoutEngine->setFixedContainingBlock($this->mpdfWritten ? null : [$layoutX, $effectiveTopMargin, $printableWidth, $printableHeight]);

            // Layout main document tree inside the effective printable area
            $layoutEngine->layoutDocument($rootBox, $layoutX, $effectiveTopMargin, $printableWidth, true);
            if ($this->layoutProbe !== null && ($this->layoutProbe)('layout', $rootBox, null, 0, $plan) === false) {
                return $this;
            }

            $this->footnotePrepare($rootBox); // footnote-policy: calls whose note must move start a page

            // Paginate (including repeating <thead> on multi-page tables)
            $paginator = new Paginator($effectiveTopMargin, $printableHeight);
            $paginator->setFirstPageLeft($firstPageLeft);
            if (!$plan->isUniform()) {
                $paginator->setPageGeometry(static fn(int $i, ?string $n): float => $plan->box($i, $n)['ph'], $firstName);
            }
            $totalPages = $paginator->paginate($rootBox);
            if ($this->options->maxPages > 0 && $totalPages > $this->options->maxPages) {
                $this->options->addWarning("Document needs {$totalPages} pages; output truncated to Options::\$maxPages ({$this->options->maxPages}).");
                $totalPages = $this->options->maxPages;
            }
            $this->pageCount = $totalPages;
            $this->layoutTree = $rootBox;

            // target-counter() / leader(): the page of every target is known now
            $refResolver = new PageRefResolver($this->fontManager, static fn(float $y): int => $paginator->pageOfY($y));
            $refResolver->resolve($rootBox);
            $this->footnoteAfterPaginate($rootBox, $paginator);
            $this->mpdfAfterPaginate($refResolver->getTargets(), $paginator, $totalPages); // mPDF header switching, page numbers, index
            if ($this->layoutProbe !== null && ($this->layoutProbe)('paginate', $rootBox, $paginator, $totalPages, $plan) === false) {
                return $this;
            }
            $strings = $plan->hasMarginBoxes() ? StringSets::collect($rootBox, static fn(float $y): int => $paginator->pageOfY($y)) : null;

            // Render pages into PdfDocument
            $pdfDoc = new PdfDocument($this->options, $this->fontManager, $this->imageLoader);
            $renderer = new PdfRenderer(
                $pdfDoc,
                $this->fontManager,
                $this->imageLoader,
                $first['w'],
                $first['h'],
                $effectiveTopMargin,
                $printableHeight
            );
            $renderer->setActiveForms($this->options->formFields && PdfX::level($this->options) === ''); // PDF/X forbids form fields
            $this->mpdfConfigureDocument($pdfDoc); // mPDF SetDisplayMode / SetJS

            /** @var array<string, LayoutBox> $watermarks */
            $watermarks = [];
            if ($this->composeMergeOnly()) {
                $totalPages = 0; // no HTML content: output consists of the appended PDF pages only
            }
            // mPDF DeletePages() / MovePages(): {PAGENO} / {nbpg} (and the other page numbers) count the output pages
            $outputOrder = $this->mpdfPageOps !== [] ? $this->mpdfPageOrder($totalPages) : null;
            $outputIndex = $outputOrder !== null ? array_flip($outputOrder) : [];
            $outputCount = $outputOrder !== null ? count($outputOrder) : $totalPages;
            for ($p = 0; $p < $totalPages; $p++) {
                RenderLimits::check();
                $pageNo = isset($outputIndex[$p]) ? $outputIndex[$p] + 1 : $p + 1; // a deleted page is never output
                $geo = $plan->box($p, $paginator->getPageName($p));
                $pageWidth = $geo['w'];
                $pageHeight = $geo['h'];
                $pm = $geo['m'];
                $pageContentWidth = max(50.0, $pageWidth - $pm['left'] - $pm['right']);

                // Running header/footer are laid out per page with the real numbers, so alignment stays exact
                $headerBox = null;
                $hdr = $this->mpdfRunning('header', $p, $this->pickRunning($this->headerHtml, $this->headerVariants, $p));
                if ($hdr !== null && trim($hdr) !== '') {
                    $headerStartY = $this->mpdfHeaderY($pm['top']);
                    $headerBox = $this->buildRunningBox($hdr, $pageNo, $outputCount, $layoutEngine, $pm['left'], $headerStartY, $pageContentWidth);
                }
                $footerBox = null;
                $ftr = $this->mpdfRunning('footer', $p, $this->pickRunning($this->footerHtml, $this->footerVariants, $p));
                if ($ftr !== null && trim($ftr) !== '') {
                    $footerBox = $this->buildRunningBox($ftr, $pageNo, $outputCount, $layoutEngine, $pm['left'], 0.0, $pageContentWidth);
                    $footerY = $this->mpdfFooterY($pageHeight, $geo['eBottom'] - ($geo['fn'] ?? 0.0), $footerBox->height);
                    $footerBox->translate(0.0, $footerY - $footerBox->y);
                }

                // Overlay layer: watermark + @page margin boxes
                $layers = [];
                if (trim($this->options->watermarkText) !== '') {
                    $wmKey = sprintf('%.2F x %.2F', $pageWidth, $pageHeight);
                    $watermarks[$wmKey] ??= $this->buildWatermarkBox($layoutEngine, $pageWidth, $pageHeight);
                    $layers[] = $watermarks[$wmKey];
                }
                foreach ($this->mpdfOverlayLayers($layoutEngine, $geo) as $mpdfLayer) {
                    $layers[] = $mpdfLayer; // mPDF image watermark
                }
                foreach ($this->compatOverlayLayers($layoutEngine, $geo, $pageNo, $outputCount) as $compatLayer) {
                    $layers[] = $compatLayer; // dompdf Canvas::page_text() / page_script()
                }
                foreach (MarginBoxes::bands($geo['boxes'], $geo, $pageNo, $outputCount, $strings, $this->options->title, $running) as $band) {
                    $layers[] = $this->layoutHtmlBox($band['html'], $layoutEngine, $band['x'], $band['y'], $band['width'], $running);
                }
                $overlayBox = null;
                if (count($layers) === 1) {
                    $overlayBox = $layers[0];
                } elseif ($layers !== []) {
                    $overlayBox = new LayoutBox('body', $layers[0]->style);
                    foreach ($layers as $layer) {
                        $overlayBox->addChild($layer);
                    }
                }

                // Pages whose left margin differs (:left / :right) shift the content horizontally
                $dx = $pm['left'] - $layoutX;
                $pdfDoc->addPage($pageWidth, $pageHeight);
                $renderer->setPageGeometry($pageWidth, $pageHeight, $geo['eTop'], $geo['ph'], $paginator->getPageBounds($p)[0]);
                $rootBox->translate($dx, 0.0);
                $renderer->renderPage($rootBox, $p, $totalPages, $headerBox, $footerBox, $overlayBox);
                $rootBox->translate(-$dx, 0.0);
            }

            $this->composeApply($pdfDoc, $totalPages); // backgrounds, appended PDF pages, attachments
            $this->renderedPdf = $pdfDoc->compile();
            foreach (ColorOps::warnings() as $colorWarning) {
                $this->options->addWarning($colorWarning); // PDF/A: CMYK written as RGB for the sRGB output intent
            }
            // The decoded images are in the PDF now: only the layout tree (getLayoutTree()) stays after output()
            $this->imageLoader->reset();
        } finally {
            RenderLimits::end();
            ComputedStyle::$fontUnitResolver = null;
            $this->options->basePath = $savedBasePath;
            $this->options->title = $savedTitle;
            $this->options->lang = $savedLang;
        }

        return $this;
    }

    /**
     * Browser defaults of <progress> / <meter> (Chromium: inline-block, 10em / 5em x 1em, vertical-align -0.2em), put
     * before the document's own CSS so that it can override them; PdfRenderer draws the bar (FormGauges).
     */
    private static function gaugeDefaults(string $html): string
    {
        if (stripos($html, '<progress') === false && stripos($html, '<meter') === false) {
            return $html;
        }
        return \IvoPdf\Compat\MpdfTags::injectStyle($html, 'progress,meter{display:inline-block;box-sizing:border-box;height:1em;vertical-align:-0.2em;overflow:hidden}progress{width:10em}meter{width:5em}');
    }

    /**
     * Stores a running header/footer for 'all' pages or for the 'first' / 'odd' / 'even' variant
     * (mPDF sides 'O' / 'E' are accepted). null removes the variant; '' means "no header/footer on those pages".
     *
     * @param array<string, ?string> $variants
     */
    private function setRunningVariant(?string &$all, array &$variants, ?string $html, string $pages): void
    {
        $key = match (strtolower(trim($pages))) {
            'first', 'f' => 'first',
            'odd', 'o' => 'odd',
            'even', 'e' => 'even',
            default => 'all',
        };
        if ($key === 'all') {
            $all = $html;
            return;
        }
        if ($html === null) {
            unset($variants[$key]);
        } else {
            $variants[$key] = $html;
        }
    }

    /**
     * Picks the running header/footer HTML for a 0-based page index: first-page variant, then odd/even, then 'all'.
     *
     * @param array<string, ?string> $variants
     */
    private function pickRunning(?string $all, array $variants, int $pageIndex): ?string
    {
        if ($pageIndex === 0 && isset($variants['first'])) {
            return $variants['first'];
        }
        $side = $pageIndex % 2 === 0 ? 'odd' : 'even';
        return $variants[$side] ?? $all;
    }

    /** Name of the `page:` rule the document starts on (first in-flow chain), or null. */
    private function firstPageName(LayoutBox $root): ?string
    {
        $cur = $root;
        while ($cur !== null) {
            $decl = strtolower(trim($cur->style->get('page', 'auto')));
            if ($decl !== '' && $decl !== 'auto') {
                return $decl;
            }
            $next = null;
            foreach ($cur->children as $child) {
                if (!$child->isTextNode() && !$child->isPositionedOutOfFlow()) {
                    $next = $child;
                    break;
                }
            }
            $cur = $next;
        }
        return null;
    }

    /**
     * Elements on a named page whose @page content width differs from the document layout width get that width,
     * so landscape sections are laid out at their own width.
     */
    private function applyNamedPageWidths(LayoutBox $box, PageLayoutPlan $plan, float $layoutWidth, float $bodyMarginX = 0.0): void
    {
        foreach ($box->children as $child) {
            if ($child->isTextNode() || $child->isPositionedOutOfFlow()) {
                continue;
            }
            $decl = strtolower(trim($child->style->get('page', 'auto')));
            if ($decl !== '' && $decl !== 'auto') {
                $w = $plan->namedWidth($decl) - $bodyMarginX;
                $cw = strtolower(trim($child->style->get('width', 'auto')));
                if (abs($w - ($layoutWidth - $bodyMarginX)) > 1.0 && ($cw === '' || $cw === 'auto')) {
                    $child->style = $child->style->unshared();
                    $child->style->set('box-sizing', 'border-box');
                    $child->style->set('width', sprintf('%.2Fpt', $w));
                }
                continue;
            }
            $this->applyNamedPageWidths($child, $plan, $layoutWidth, $bodyMarginX);
        }
    }

    private function layoutHtmlBox(string $html, LayoutEngine $engine, float $x, float $y, float $width, ?RunningElements $running = null): LayoutBox
    {
        $builder = new DomBuilder($this->options, new CssParser());
        $box = $builder->build($html);
        $running?->inject($box);
        $engine->layoutDocument($box, $x, $y, $width);
        return $box;
    }

    private function buildRunningBox(string $html, int $pageNum, int $pageCount, LayoutEngine $engine, float $x, float $y, float $width): LayoutBox
    {
        $resolved = strtr($html, [
            '{PAGE_NUM}' => (string) $pageNum,
            '{PAGENO}' => (string) $pageNum,
            '{PAGE_COUNT}' => (string) $pageCount,
            '{nbpg}' => (string) $pageCount,
            '{DATE}' => date('d.m.Y'),
            '{TITLE}' => htmlspecialchars($this->options->title, ENT_QUOTES, 'UTF-8'),
        ]);
        if (str_contains($resolved, '{DATE ')) {
            // mPDF style {DATE j-m-Y}: PHP date() format
            $resolved = (string) preg_replace_callback('/\{DATE ([^}]+)\}/', static fn(array $m): string => date($m[1]), $resolved);
        }
        $builder = new DomBuilder($this->options, new CssParser());
        $box = $builder->build($resolved);
        $engine->layoutDocument($box, $x, $y, $width);
        return $box;
    }

    private function buildWatermarkBox(LayoutEngine $engine, float $pageWidth, float $pageHeight): LayoutBox
    {
        $fontSize = max(24.0, min(110.0, $pageWidth * 1.3 / max(4, mb_strlen($this->options->watermarkText))));
        $html = sprintf(
            '<div style="display:flex; align-items:center; justify-content:center; width:%.2Fpt; height:%.2Fpt; margin:0; padding:0;">'
            . '<div style="transform:rotate(-35deg); font-size:%.1Fpt; font-weight:bold; color:%s; opacity:%.3F; white-space:nowrap;">%s</div></div>',
            $pageWidth,
            $pageHeight,
            $fontSize,
            ColorOps::isFlattening() ? sprintf('rgb(%1$d,%1$d,%1$d)', (int) round(255 * (1.0 - $this->options->watermarkOpacity))) : '#000', // no opacity in PDF/X-1a / X-3
            ColorOps::isFlattening() ? 1.0 : $this->options->watermarkOpacity,
            htmlspecialchars($this->options->watermarkText, ENT_QUOTES, 'UTF-8')
        );
        $builder = new DomBuilder($this->options, new CssParser());
        $box = $builder->build($html);
        $engine->layoutDocument($box, 0.0, 0.0, $pageWidth);
        return $box;
    }

    /** Number of parsed @font-face rules already registered in the current render */
    private int $fontFacesRegistered = 0;

    /**
     * Registers @font-face rules (TrueType sources from data: URIs, allowed local files or safe remote URLs).
     *
     * @param list<array<string, string>> $faces
     */
    private function registerCssFontFaces(array $faces): void
    {
        $faces = array_slice($faces, $this->fontFacesRegistered); // already handled when the stylesheets were parsed
        $this->fontFacesRegistered += count($faces);
        foreach ($faces as $face) {
            $family = trim($face['font-family'] ?? '', " \t\n\r\0\x0B'\"");
            $src = $face['src'] ?? '';
            if ($family === '' || $src === '') {
                continue;
            }
            // Manual scan (a regex would hit the PCRE backtrack limit on large base64 data URIs)
            $urls = [];
            $offset = 0;
            while (($pos = stripos($src, 'url(', $offset)) !== false) {
                $close = strpos($src, ')', $pos + 4);
                if ($close === false) {
                    break;
                }
                $urls[] = trim(substr($src, $pos + 4, $close - $pos - 4), " \t\n\r'\"");
                $offset = $close + 1;
            }

            $path = null;
            $bytes = null;
            foreach ($urls as $url) {
                [$path, $bytes] = $this->loadFontSource(trim($url));
                if ($path !== null || $bytes !== null) {
                    break;
                }
            }
            if ($path === null && $bytes === null) {
                $this->options->addWarning("@font-face '{$family}': no usable TrueType (.ttf) source found");
                continue;
            }
            $weight = strtolower(trim($face['font-weight'] ?? 'normal'));
            $style = strtolower(trim($face['font-style'] ?? 'normal'));
            $isBold = $this->fontManager->isBoldWeight($weight);
            $isItalic = $style === 'italic' || $style === 'oblique';
            $variant = $isBold ? ($isItalic ? 'bold_italic' : 'bold') : ($isItalic ? 'italic' : 'regular');
            try {
                if ($bytes !== null) {
                    // data: URIs and downloaded fonts stay in memory: no temporary file to leak, pre-create or poison
                    $this->fontManager->registerFontData($family, $variant, $bytes);
                } else {
                    new TtfParser((string) $path);
                    $this->fontManager->registerFontVariant($family, $variant, (string) $path);
                }
            } catch (\Throwable $e) {
                $this->options->addWarning("@font-face '{$family}': " . $e->getMessage());
            }
        }
    }

    /**
     * Resolves one @font-face url(): an allowed local file path, or the font bytes of a data: / http(s) source.
     *
     * @return array{0: ?string, 1: ?string} [local path, bytes]
     */
    private function loadFontSource(string $url): array
    {
        $bytes = null;
        $url = Options::normalizeProtocolRelativeUrl($url); // //host/f.ttf is remote, never a UNC path
        if (str_starts_with(strtolower($url), 'data:')) {
            $comma = strpos($url, ',');
            if ($comma !== false) {
                $meta = substr($url, 5, $comma - 5);
                $payload = substr($url, $comma + 1);
                $bytes = str_contains($meta, ';base64') ? base64_decode($payload, true) : rawurldecode($payload);
            }
        } elseif (preg_match('/^https?:\/\//i', $url)) {
            $bytes = $this->options->fetchSafeRemoteUrl($url);
        } else {
            return [$this->options->resolveAllowedLocalFile($url), null];
        }
        return is_string($bytes) && strlen($bytes) >= 12 ? [null, $bytes] : [null, null];
    }

    /**
     * Returns the raw binary PDF content (automatically renders first); dompdf's output([...]) too.
     *
     * Also accepts mPDF's Output($name, $dest) with mPDF's semantics: $dest 'S' returns the bytes, 'F' writes the file
     * $name, 'D' sends it as a download, 'I' inline; an empty $dest means 'F' when $name is given (and 'I' otherwise);
     * a boolean $dest means 'D' (true) or 'F' (false). 'F' / 'D' / 'I' return null, like mPDF. A file name without a
     * destination (`output('/path/doc.pdf')`) writes the file.
     *
     * @param string|array<string, mixed>|null $name
     * @param string|bool|null $dest
     * @throws \InvalidArgumentException for an unknown destination
     * @throws RuntimeException when the file cannot be written
     */
    public function output(mixed $name = '', mixed $dest = null): ?string
    {
        if (is_array($name)) {
            return $this->pdfBytes(); // dompdf output(['compress' => 0])
        }
        $name = MpdfArgs::str($name);
        if ($dest === null) {
            if ($name === '') {
                return $this->pdfBytes(); // IvoPDF / dompdf: output() returns the bytes
            }
            $dest = 'F'; // mPDF Output($file)
        }
        return $this->mpdfOutput($name, $dest);
    }

    /**
     * mPDF Output() destinations (see output()).
     *
     * @throws \InvalidArgumentException for an unknown destination
     */
    protected function mpdfOutput(string $name, mixed $dest): ?string
    {
        if (is_bool($dest)) {
            $dest = $dest ? 'D' : 'F';
        }
        $dest = strtoupper(trim(MpdfArgs::str($dest)));
        if ($dest === '') {
            $dest = $name === '' ? 'I' : 'F';
        }
        switch ($dest) {
            case 'S':
                return $this->pdfBytes();
            case 'F':
                if ($name === '') {
                    throw new RuntimeException('Output(): a file name is required for destination F');
                }
                $this->save($name);
                return null;
            case 'D':
                $this->stream($name !== '' ? basename($name) : 'mpdf.pdf', ['Attachment' => true]);
                return null;
            case 'I':
                $this->stream($name !== '' ? basename($name) : 'mpdf.pdf', ['Attachment' => false]);
                return null;
            default:
                throw new \InvalidArgumentException("Incorrect output destination {$dest}");
        }
    }

    /** The PDF bytes (renders first when needed). */
    private function pdfBytes(): string
    {
        if ($this->renderedPdf === null) {
            $this->render();
        }
        return (string) $this->renderedPdf;
    }

    /**
     * Saves the generated PDF to a file on disk (missing directories are created).
     *
     * @throws RuntimeException when the file cannot be written (without emitting a PHP warning)
     */
    public function save(string $filePath): self
    {
        $pdfBytes = $this->pdfBytes();
        $dir = dirname($filePath);
        // Failures are reported through the exception only: PHP's own warnings are swallowed (even by custom error handlers)
        $reason = '';
        set_error_handler(static function (int $no, string $msg) use (&$reason): bool {
            $reason = $msg;
            return true;
        });
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $written = file_put_contents($filePath, $pdfBytes);
        } finally {
            restore_error_handler();
        }
        if ($written === false) {
            throw new RuntimeException("Failed to write PDF file to: {$filePath}" . ($reason !== '' ? " ({$reason})" : ''));
        }
        return $this;
    }

    /**
     * Streams the PDF directly to the browser.
     *
     * @param array{Attachment?: bool|int} $options
     */
    public function stream(string $filename = 'document.pdf', array $options = ['Attachment' => false]): void
    {
        $pdfBytes = $this->pdfBytes();
        $isAttachment = !empty($options['Attachment']);
        $disposition = $isAttachment ? 'attachment' : 'inline';
        $cleanName = str_replace(['"', "\r", "\n", "\\"], '', $filename);
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $cleanName) ?: 'document.pdf';
        $utf8Encoded = rawurlencode($cleanName);

        if (!headers_sent() && PHP_SAPI !== 'cli') {
            // Discard stray buffered output (BOMs, whitespace, notices) that would corrupt the PDF (on the command line
            // there is no HTTP response: the caller's own output buffers are left alone)
            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($pdfBytes));
            header("Content-Disposition: {$disposition}; filename=\"{$asciiFallback}\"; filename*=UTF-8''{$utf8Encoded}");
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $pdfBytes;
    }

    // ------------------------------------------------------------------
    // mPDF-compatible API (drop-in helpers for migrating existing code)
    // ------------------------------------------------------------------

    /** @param string $side '' (all pages), 'O' (odd) or 'E' (even) like mPDF */
    public function SetHTMLHeader(mixed $html = '', mixed $side = '', mixed $write = false): self
    {
        $side = MpdfArgs::str($side);
        return $this->setHeaderHtml($this->convertMpdfPlaceholders(MpdfArgs::str($html)), $side === '' ? 'all' : $side);
    }

    /** @param string $side '' (all pages), 'O' (odd) or 'E' (even) like mPDF */
    public function SetHTMLFooter(mixed $html = '', mixed $side = ''): self
    {
        $side = MpdfArgs::str($side);
        return $this->setFooterHtml($this->convertMpdfPlaceholders(MpdfArgs::str($html)), $side === '' ? 'all' : $side);
    }

    /** mPDF requires showWatermarkText = true; IvoPDF shows the watermark as soon as it is set. */
    public function showWatermarkText(mixed $show = true): self
    {
        if (!MpdfArgs::bool($show, true)) {
            $this->options->watermarkText = '';
        }
        return $this;
    }

    /**
     * mPDF AddPage($orientation, $condition, $resetpagenum, $pagenumstyle, $suppress, $mgl, $mgr, $mgt, $mgb, $mgh, $mgf,
     * $ohname, $ehname, $ofname, $efname, $ohvalue, $ehvalue, $ofvalue, $efvalue, $pagesel, $newformat) — all optional.
     */
    public function AddPage(mixed $orientation = '', mixed $condition = '', mixed ...$more): self
    {
        return $this->mpdfAddPage(array_merge([MpdfArgs::str($orientation), MpdfArgs::str($condition)], array_values($more)));
    }

    public function SetTitle(mixed $title): self
    {
        $this->options->title = MpdfArgs::str($title);
        return $this;
    }

    public function SetAuthor(mixed $author): self
    {
        $this->options->author = MpdfArgs::str($author);
        return $this;
    }

    public function SetSubject(mixed $subject): self
    {
        $this->options->subject = MpdfArgs::str($subject);
        return $this;
    }

    public function SetKeywords(mixed $keywords): self
    {
        $this->options->keywords = MpdfArgs::str($keywords);
        return $this;
    }

    public function SetCreator(mixed $creator): self
    {
        $this->options->creator = MpdfArgs::str($creator);
        return $this;
    }

    /**
     * Encrypts the PDF (dompdf setEncryption / mPDF SetProtection compatible; method names are case-insensitive in PHP).
     *
     * @param list<string> $permissions allowed operations: print, modify, copy, annotate (annot-forms), fill-forms,
     *                                  extract, assemble, print-high (print-highres) or 'all'; others are denied
     * @param string|int $method 'aes256' (default), 'aes128', 'rc4' (RC4-128); mPDF key lengths 40/128 => rc4, 256 => aes256
     *                          ('aes128' / 'rc4' convert passwords to PDFDocEncoding and refuse e.g. Cyrillic ones)
     */
    public function setProtection(mixed $permissions = ['print', 'copy'], mixed $userPassword = '', mixed $ownerPassword = null, mixed $method = 'aes256'): self
    {
        $permissions = is_array($permissions) ? array_map(static fn(mixed $p): string => MpdfArgs::str($p), $permissions) : [];
        $userPassword = MpdfArgs::str($userPassword);
        $ownerPassword = $ownerPassword === null ? null : MpdfArgs::str($ownerPassword);
        $method = is_int($method) || (is_string($method) && ctype_digit(trim($method))) ? (int) $method : (is_float($method) ? (int) $method : MpdfArgs::str($method, 'aes256'));
        if (PdfEncryption::normalizeMethod($method) !== 'aes256') { // validate early
            PdfEncryption::legacyPasswordBytes($userPassword);
            PdfEncryption::legacyPasswordBytes((string) $ownerPassword);
        }
        PdfEncryption::permissionValue($permissions);
        $this->options->encryption = [
            'permissions' => array_values($permissions),
            'user' => $userPassword,
            'owner' => $ownerPassword,
            'method' => $method,
        ];
        $this->invalidate();
        return $this;
    }

    /**
     * Enables PDF/A output: '2b' (default), '3b' or '1b' (real ISO 19005-1: PDF 1.4, no transparency - soft masks, opacity and
     * blend modes are flattened with a warning -, /CIDSet for font subsets). Empty string / false disables it.
     */
    public function setPdfA(string|bool $conformance = '2b'): self
    {
        $this->options->pdfa = $conformance === true ? '2b' : ($conformance === false ? '' : $conformance);
        $this->invalidate();
        return $this;
    }

    /**
     * Enables PDF/X output: 'X-1a' (default, also `true`), 'X-3' or 'X-4'; '' / false disables it. X-1a and X-3 convert
     * everything to CMYK and flatten transparency; incompatible with PDF/A, encryption and signatures (LogicException on render).
     *
     * @param string $outputCondition registered characterization written as /OutputConditionIdentifier (e.g. 'FOGRA39', 'CGATS TR 001')
     * @param string $iccProfile optional ICC output profile file embedded as /DestOutputProfile (you must be licensed to embed it)
     */
    public function setPdfX(string|bool $level = 'X-1a', string $outputCondition = 'FOGRA39', string $iccProfile = ''): self
    {
        $this->options->pdfx = PdfX::normalize($level);
        $this->options->pdfxOutputCondition = $outputCondition;
        $this->options->pdfxIccProfile = $iccProfile;
        $this->invalidate();
        return $this;
    }

    /**
     * Output color space: 'rgb' (default) or 'cmyk' (all RGB colors and images are converted to DeviceCMYK; mPDF restrictColorSpace = 3).
     */
    public function setColorSpace(string $space): self
    {
        $this->options->colorSpace = strtolower(trim($space)) === 'cmyk' ? 'cmyk' : 'rgb';
        $this->invalidate();
        return $this;
    }

    /**
     * Bleed in millimetres (pages get /BleedBox, /TrimBox and a larger /MediaBox) and optional crop marks.
     */
    public function setBleed(float $mm, bool $cropMarks = false): self
    {
        $this->options->bleed = max(0.0, $mm);
        $this->options->cropMarks = $cropMarks;
        $this->invalidate();
        return $this;
    }

    /**
     * Defines a named spot (Separation) color like mPDF's AddSpotColor(); the CMYK alternate is given in percent (0..100).
     * Use it in CSS as `color: spot(PANTONE 185 C, 100%)` (second argument: tint).
     */
    public function addSpotColor(mixed $name, mixed $c, mixed $m, mixed $y, mixed $k): self
    {
        [$name, $c, $m, $y, $k] = [MpdfArgs::str($name), MpdfArgs::num($c), MpdfArgs::num($m), MpdfArgs::num($y), MpdfArgs::num($k)];
        SpotColors::define($name, $c, $m, $y, $k); // validates the name
        $this->options->spotColors[trim($name)] = [$c, $m, $y, $k];
        $this->invalidate();
        return $this;
    }

    /**
     * Heuristic PDF/A pre-check of the generated document (renders it first). Returns a list of issues found
     * for the selected level; an empty list means no known violation was detected. This is NOT a replacement for
     * a real validator such as veraPDF.
     *
     * @return list<string>
     */
    public function validatePdfA(): array
    {
        $level = \IvoPdf\Pdf\PdfFinisher::parsePdfa($this->options->pdfa);
        if ($level === null) {
            return ['PDF/A output is not enabled: call setPdfA() first'];
        }
        return \IvoPdf\Pdf\PdfAChecker::check($this->pdfBytes(), $level['part']);
    }

    /**
     * Adds a detached CMS (adbe.pkcs7.detached) digital signature.
     *
     * @param string $certificate PEM text or file path (PEM, or .p12/.pfx when $privateKey is empty)
     * @param string $privateKey PEM text or file path
     * @param array{Name?: string, Location?: string, Reason?: string, ContactInfo?: string} $info
     * @param ?array{0: int, 1: float, 2: float, 3: float, 4: float} $visibleRect [page (1-based), x, y, width, height] in pt,
     *        measured from the top-left corner of the page; null keeps the signature field invisible
     * @param string $subFilter 'adbe.pkcs7.detached' (default) or 'ETSI.CAdES.detached' (PAdES baseline-B style, no signing-time attribute)
     */
    public function setSignature(string $certificate, string $privateKey = '', string $password = '', array $info = [], ?array $visibleRect = null, string $subFilter = 'adbe.pkcs7.detached'): self
    {
        $this->options->signature = [
            'cert' => $certificate,
            'key' => $privateKey,
            'password' => $password,
            'info' => $info,
            'rect' => $visibleRect,
            'subFilter' => $subFilter,
        ];
        $this->invalidate();
        return $this;
    }

    private function convertMpdfPlaceholders(string $html): string
    {
        return strtr($html, ['{PAGENO}' => '{PAGE_NUM}', '{nbpg}' => '{PAGE_COUNT}', '{nb}' => '{PAGE_COUNT}']);
    }

    /**
     * @param array<string, string> $pageProps
     * @param array{top: float, right: float, bottom: float, left: float} $margins
     */
    private function applyCssPageRules(array $pageProps, float &$pageWidth, float &$pageHeight, array &$margins): void
    {
        if (empty($pageProps)) {
            return;
        }

        if (isset($pageProps['size'])) {
            // CSS Paged Media: auto | <length>{1,2} | [ <page-size> || [ portrait | landscape ] ]
            $tokens = preg_split('/\s+/', strtolower(trim($pageProps['size'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $orient = null;
            $lengths = [];
            $named = null;
            $valid = $tokens !== [];
            foreach ($tokens as $tok) {
                if ($tok === 'landscape' || $tok === 'portrait') {
                    $orient = $tok;
                } elseif ($tok === 'auto') {
                    continue;
                } elseif (preg_match('/^(?:\d*\.?\d+)(?:px|pt|mm|cm|in|pc|q|em|rem|ex|ch)?$/', $tok) === 1) {
                    $lengths[] = $tok;
                } elseif ($named === null && preg_match('/^[a-z0-9]+$/', $tok) === 1) {
                    $named = $tok;
                } else {
                    $valid = false;
                }
            }
            if ($valid && $lengths !== [] && count($lengths) <= 2 && $named === null) {
                $w = max(10.0, CssUnit::toPt($lengths[0], $pageWidth));
                $h = max(10.0, count($lengths) === 2 ? CssUnit::toPt($lengths[1], $pageHeight) : $w); // a single length is a square page
                if ($orient === 'landscape' && $w < $h || $orient === 'portrait' && $w > $h) {
                    [$w, $h] = [$h, $w];
                }
                [$pageWidth, $pageHeight] = [$w, $h];
            } elseif ($valid && $lengths === []) {
                [$pageWidth, $pageHeight] = $this->options->getPageDimensions($named ?? $this->options->paperSize, $orient ?? $this->options->orientation);
            }
        }

        $tmpStyle = new ComputedStyle();
        foreach (['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'] as $mp) {
            if (isset($pageProps[$mp])) {
                $tmpStyle->set($mp, $pageProps[$mp]);
            }
        }

        if (isset($pageProps['margin']) || isset($pageProps['margin-top'])) {
            $margins['top'] = CssUnit::toPt($tmpStyle->get('margin-top'), $pageHeight);
        }
        if (isset($pageProps['margin']) || isset($pageProps['margin-right'])) {
            $margins['right'] = CssUnit::toPt($tmpStyle->get('margin-right'), $pageWidth);
        }
        if (isset($pageProps['margin']) || isset($pageProps['margin-bottom'])) {
            $margins['bottom'] = CssUnit::toPt($tmpStyle->get('margin-bottom'), $pageHeight);
        }
        if (isset($pageProps['margin']) || isset($pageProps['margin-left'])) {
            $margins['left'] = CssUnit::toPt($tmpStyle->get('margin-left'), $pageWidth);
        }
    }
}
