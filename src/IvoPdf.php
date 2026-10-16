<?php

declare(strict_types=1);

namespace IvoPdf;

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
    public function setWatermarkText(string $text, float $opacity = 0.08): self
    {
        $this->options->watermarkText = $text;
        $this->options->watermarkOpacity = max(0.01, min(1.0, $opacity));
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Adds a registered font family to the fallback chain used for characters missing from the requested font.
     */
    public function addFallbackFont(string $familyName): self
    {
        $this->fontManager->addFallbackFamily($familyName);
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Loads an HTML string for rendering.
     */
    public function loadHtml(string $html, ?string $basePath = null): self
    {
        $this->html = $html;
        $this->overrideBasePath = $basePath;
        $effectiveBase = $basePath ?? $this->options->basePath;
        $this->imageLoader = new ImageLoader($effectiveBase, $this->options->isRemoteEnabled, $this->options);
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Alias of loadHtml() for mPDF compatibility.
     */
    public function WriteHTML(string $html, int $mode = 0, bool $init = true, bool $close = true): self
    {
        return $this->mpdfWriteHtml($html, $mode); // mode 1 = \Mpdf\HTMLParserMode::HEADER_CSS (stylesheet text)
    }

    /**
     * Loads an HTML file from disk (subject to allowedPaths / basePath containment if configured).
     */
    public function loadHtmlFile(string $filePath): self
    {
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
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Sets page margins in PDF points (pt).
     */
    public function setMargins(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        $this->options->setMargin($top, $right, $bottom, $left);
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Sets running HTML header rendered at the top of every page.
     * Supports {PAGE_NUM} and {PAGE_COUNT} placeholders.
     */
    public function setHeaderHtml(?string $html, string $pages = 'all'): self
    {
        $this->setRunningVariant($this->headerHtml, $this->headerVariants, $html, $pages);
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Sets running HTML footer rendered at the bottom of every page.
     * Supports {PAGE_NUM} and {PAGE_COUNT} placeholders.
     */
    public function setFooterHtml(?string $html, string $pages = 'all'): self
    {
        $this->setRunningVariant($this->footerHtml, $this->footerVariants, $html, $pages);
        $this->renderedPdf = null;
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
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Registers a custom TrueType (.ttf) font family.
     *
     * @param array{regular: string, bold?: string, italic?: string, bold_italic?: string} $variants
     */
    public function registerFontFamily(string $familyName, array $variants): self
    {
        $this->fontManager->registerFamily($familyName, $variants);
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Parses HTML & CSS, computes Block/Flexbox/Grid/Table layout, paginates, and compiles the PDF binary.
     */
    private function renderCore(): self
    {
        CssUnit::setDpi($this->options->dpi);
        ColorOps::configure($this->options); // output color space / PDF/X (validates option combinations)
        $this->fontManager->resetUsedFonts();
        $this->imageLoader->reset();
        $this->options->clearWarnings();
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

        try {
            $cssParser = new CssParser();
            $domBuilder = new DomBuilder($this->options, $cssParser);
            $rootBox = $domBuilder->build(TocGenerator::process($this->mpdfTransform($this->html), $this->tocEnabled, $this->tocTitle, $this->tocMaxLevel));
            $this->registerCssFontFaces($cssParser->getFontFaces());
            $running = RunningElements::extract($rootBox); // position: running(name) elements leave the flow

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
            $firstName = $this->firstPageName($rootBox);
            $first = $plan->box(0, $firstName);
            $effectiveTopMargin = $first['eTop'];
            $printableHeight = $first['ph'];
            $layoutX = $first['m']['left'];
            $printableWidth = $plan->layoutWidth();
            if ($plan->names() !== []) {
                $this->applyNamedPageWidths($rootBox, $plan, $printableWidth);
            }
            $layoutEngine->setPageGeometry($effectiveTopMargin, $printableHeight);

            // Layout main document tree inside the effective printable area
            $layoutEngine->layoutDocument($rootBox, $layoutX, $effectiveTopMargin, $printableWidth);

            // Paginate (including repeating <thead> on multi-page tables)
            $paginator = new Paginator($effectiveTopMargin, $printableHeight);
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
            $this->mpdfAfterPaginate($refResolver->getTargets(), $paginator, $totalPages); // mPDF header switching, page numbers, index
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
            for ($p = 0; $p < $totalPages; $p++) {
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
                    $headerBox = $this->buildRunningBox($hdr, $p + 1, $totalPages, $layoutEngine, $pm['left'], $headerStartY, $pageContentWidth);
                }
                $footerBox = null;
                $ftr = $this->mpdfRunning('footer', $p, $this->pickRunning($this->footerHtml, $this->footerVariants, $p));
                if ($ftr !== null && trim($ftr) !== '') {
                    $footerBox = $this->buildRunningBox($ftr, $p + 1, $totalPages, $layoutEngine, $pm['left'], 0.0, $pageContentWidth);
                    $footerY = $this->mpdfFooterY($pageHeight, $geo['eBottom'], $footerBox->height);
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
                foreach (MarginBoxes::bands($geo['boxes'], $geo, $p + 1, $totalPages, $strings, $this->options->title, $running) as $band) {
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
        } finally {
            $this->options->basePath = $savedBasePath;
            $this->options->title = $savedTitle;
            $this->options->lang = $savedLang;
        }

        return $this;
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
    private function applyNamedPageWidths(LayoutBox $box, PageLayoutPlan $plan, float $layoutWidth): void
    {
        foreach ($box->children as $child) {
            if ($child->isTextNode() || $child->isPositionedOutOfFlow()) {
                continue;
            }
            $decl = strtolower(trim($child->style->get('page', 'auto')));
            if ($decl !== '' && $decl !== 'auto') {
                $w = $plan->namedWidth($decl);
                $cw = strtolower(trim($child->style->get('width', 'auto')));
                if (abs($w - $layoutWidth) > 1.0 && ($cw === '' || $cw === 'auto')) {
                    $child->style->set('box-sizing', 'border-box');
                    $child->style->set('width', sprintf('%.2Fpt', $w));
                }
                continue;
            }
            $this->applyNamedPageWidths($child, $plan, $layoutWidth);
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

    /**
     * Registers @font-face rules (TrueType sources from data: URIs, allowed local files or safe remote URLs).
     *
     * @param list<array<string, string>> $faces
     */
    private function registerCssFontFaces(array $faces): void
    {
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
            foreach ($urls as $url) {
                $path = $this->materializeFontSource(trim($url));
                if ($path !== null) {
                    break;
                }
            }
            if ($path === null) {
                $this->options->addWarning("@font-face '{$family}': no usable TrueType (.ttf) source found");
                continue;
            }
            try {
                new TtfParser($path);
            } catch (\Throwable $e) {
                $this->options->addWarning("@font-face '{$family}': " . $e->getMessage());
                continue;
            }

            $weight = strtolower(trim($face['font-weight'] ?? 'normal'));
            $style = strtolower(trim($face['font-style'] ?? 'normal'));
            $isBold = $this->fontManager->isBoldWeight($weight);
            $isItalic = $style === 'italic' || $style === 'oblique';
            $variant = $isBold ? ($isItalic ? 'bold_italic' : 'bold') : ($isItalic ? 'italic' : 'regular');
            $this->fontManager->registerFontVariant($family, $variant, $path);
        }
    }

    private function materializeFontSource(string $url): ?string
    {
        $bytes = null;
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
            return $this->options->resolveAllowedLocalFile($url);
        }
        if (!is_string($bytes) || strlen($bytes) < 12) {
            return null;
        }
        $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ivopdf-font-' . md5($bytes) . '.ttf';
        if (!is_file($file) && @file_put_contents($file, $bytes) === false) {
            return null;
        }
        return $file;
    }

    /**
     * Returns the raw binary PDF content (automatically renders first).
     * Also accepts the mPDF signature Output($name, $dest) with $dest 'S' | 'F' | 'D' | 'I'.
     *
     * @param string|array<string, mixed> $name
     */
    public function output(string|array $name = '', ?string $dest = null): string
    {
        if ($this->renderedPdf === null) {
            $this->render();
        }
        // dompdf: output() / output([...]) returns the bytes.
        // mPDF: Output($name, $dest) with 'S' string, 'F' file, 'D' download, 'I' inline.
        if ($dest === null || is_array($name)) {
            return (string) $this->renderedPdf;
        }
        $file = $name !== '' ? $name : 'document.pdf';
        switch (strtoupper($dest)) {
            case 'S':
                return (string) $this->renderedPdf;
            case 'F':
                $this->save($file);
                return '';
            case 'D':
                $this->stream(basename($file), ['Attachment' => true]);
                return '';
            default:
                $this->stream(basename($file), ['Attachment' => false]);
                return '';
        }
    }

    /**
     * Saves the generated PDF to a file on disk.
     */
    public function save(string $filePath): self
    {
        $pdfBytes = $this->output();
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (file_put_contents($filePath, $pdfBytes) === false) {
            throw new RuntimeException("Failed to write PDF file to: {$filePath}");
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
        $pdfBytes = $this->output();
        $isAttachment = !empty($options['Attachment']);
        $disposition = $isAttachment ? 'attachment' : 'inline';
        $cleanName = str_replace(['"', "\r", "\n", "\\"], '', $filename);
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $cleanName) ?: 'document.pdf';
        $utf8Encoded = rawurlencode($cleanName);

        if (!headers_sent()) {
            // Discard stray buffered output (BOMs, whitespace, notices) that would corrupt the PDF
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
    public function SetHTMLHeader(string $html, string $side = ''): self
    {
        return $this->setHeaderHtml($this->convertMpdfPlaceholders($html), $side === '' ? 'all' : $side);
    }

    /** @param string $side '' (all pages), 'O' (odd) or 'E' (even) like mPDF */
    public function SetHTMLFooter(string $html, string $side = ''): self
    {
        return $this->setFooterHtml($this->convertMpdfPlaceholders($html), $side === '' ? 'all' : $side);
    }

    /** mPDF requires showWatermarkText = true; IvoPDF shows the watermark as soon as it is set. */
    public function showWatermarkText(bool $show = true): self
    {
        if (!$show) {
            $this->options->watermarkText = '';
        }
        return $this;
    }

    /**
     * mPDF AddPage($orientation, $condition, $resetpagenum, $pagenumstyle, $suppress, $mgl, $mgr, $mgt, $mgb, $mgh, $mgf,
     * $ohname, $ehname, $ofname, $efname, $ohvalue, $ehvalue, $ofvalue, $efvalue, $pagesel, $newformat) — all optional.
     */
    public function AddPage(string $orientation = '', string $condition = '', mixed ...$more): self
    {
        return $this->mpdfAddPage(array_merge([$orientation, $condition], array_values($more)));
    }

    public function SetTitle(string $title): self
    {
        $this->options->title = $title;
        return $this;
    }

    public function SetAuthor(string $author): self
    {
        $this->options->author = $author;
        return $this;
    }

    public function SetSubject(string $subject): self
    {
        $this->options->subject = $subject;
        return $this;
    }

    public function SetKeywords(string $keywords): self
    {
        $this->options->keywords = $keywords;
        return $this;
    }

    public function SetCreator(string $creator): self
    {
        $this->options->creator = $creator;
        return $this;
    }

    /**
     * Encrypts the PDF (dompdf setEncryption / mPDF SetProtection compatible; method names are case-insensitive in PHP).
     *
     * @param list<string> $permissions allowed operations: print, modify, copy, annotate (annot-forms), fill-forms,
     *                                  extract, assemble, print-high (print-highres) or 'all'; others are denied
     * @param string|int $method 'aes256' (default), 'aes128', 'rc4' (RC4-128); mPDF key lengths 40/128 => rc4, 256 => aes256
     */
    public function setProtection(array $permissions = ['print', 'copy'], string $userPassword = '', ?string $ownerPassword = null, string|int $method = 'aes256'): self
    {
        PdfEncryption::normalizeMethod($method); // validate early
        PdfEncryption::permissionValue($permissions);
        $this->options->encryption = [
            'permissions' => array_values($permissions),
            'user' => $userPassword,
            'owner' => $ownerPassword,
            'method' => $method,
        ];
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Enables PDF/A output: '2b' (default), '3b' or '1b' (real ISO 19005-1: PDF 1.4, no transparency - soft masks, opacity and
     * blend modes are flattened with a warning -, /CIDSet for font subsets). Empty string / false disables it.
     */
    public function setPdfA(string|bool $conformance = '2b'): self
    {
        $this->options->pdfa = $conformance === true ? '2b' : ($conformance === false ? '' : $conformance);
        $this->renderedPdf = null;
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
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Output color space: 'rgb' (default) or 'cmyk' (all RGB colors and images are converted to DeviceCMYK; mPDF restrictColorSpace = 3).
     */
    public function setColorSpace(string $space): self
    {
        $this->options->colorSpace = strtolower(trim($space)) === 'cmyk' ? 'cmyk' : 'rgb';
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Bleed in millimetres (pages get /BleedBox, /TrimBox and a larger /MediaBox) and optional crop marks.
     */
    public function setBleed(float $mm, bool $cropMarks = false): self
    {
        $this->options->bleed = max(0.0, $mm);
        $this->options->cropMarks = $cropMarks;
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Defines a named spot (Separation) color like mPDF's AddSpotColor(); the CMYK alternate is given in percent (0..100).
     * Use it in CSS as `color: spot(PANTONE 185 C, 100%)` (second argument: tint).
     */
    public function addSpotColor(string $name, float $c, float $m, float $y, float $k): self
    {
        SpotColors::define($name, $c, $m, $y, $k); // validates the name
        $this->options->spotColors[trim($name)] = [$c, $m, $y, $k];
        $this->renderedPdf = null;
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
        return \IvoPdf\Pdf\PdfAChecker::check($this->output(), $level['part']);
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
        $this->renderedPdf = null;
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
            $tokens = preg_split('/\s+/', trim($pageProps['size']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($tokens) === 1) {
                $t0 = strtolower($tokens[0]);
                if ($t0 === 'landscape' || $t0 === 'portrait') {
                    [$pageWidth, $pageHeight] = $this->options->getPageDimensions($this->options->paperSize, $t0);
                } else {
                    [$pageWidth, $pageHeight] = $this->options->getPageDimensions($tokens[0], $this->options->orientation);
                }
            } elseif (count($tokens) >= 2) {
                $t1 = strtolower($tokens[1]);
                if ($t1 === 'landscape' || $t1 === 'portrait') {
                    [$pageWidth, $pageHeight] = $this->options->getPageDimensions($tokens[0], $t1);
                } else {
                    $pageWidth = CssUnit::toPt($tokens[0], $pageWidth);
                    $pageHeight = CssUnit::toPt($tokens[1], $pageHeight);
                }
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
