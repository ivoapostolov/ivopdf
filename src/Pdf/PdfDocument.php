<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Font\FontManager;
use IvoPdf\Font\TtfParser;
use IvoPdf\Font\TtfSubsetter;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Options;

/**
 * Pure-PHP PDF 1.7 binary document builder.
 * Supports TrueType Unicode CID fonts (/Type0 + /CIDFontType2 + /ToUnicode CMap),
 * JPEG/PNG XObjects with alpha SMask, ExtGState transparency, Axial Gradients, and Link Annotations.
 */
class PdfDocument
{
    private Options $options;
    private FontManager $fontManager;
    private ImageLoader $imageLoader;

    /**
     * @var list<array{
     *   width: float,
     *   height: float,
     *   content: string,
     *   links: list<array{x: float, y: float, w: float, h: float, uri: string}>
     * }>
     */
    private array $pages = [];

    /** @var array<string, float> gsId => alpha (0.0..1.0) */
    private array $alphaStates = [];

    /** @var array<string, string> gsId => PDF blend mode name (mix-blend-mode) */
    private array $blendStates = [];

    /**
     * @var array<string, array{
     *   coords: array{0: float, 1: float, 2: float, 3: float},
     *   stops: list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}>
     * }>
     */
    private array $shadings = [];

    /** @var array<string, array{coords: list<float>, stops: list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}>}> */
    private array $radialShadings = [];

    /** @var array<string, array{page: int, y: float}> Named destinations (element ids) */
    private array $destinations = [];
    /** @var array<int, true> spl_object_id of every FormControl that already has a widget */
    private array $widgetControls = [];
    /** @var array<string, true> internal link targets already reported as missing */
    private array $unresolvedLinks = [];

    /** @var list<array{level: int, title: string, page: int, y: float}> Document outline (bookmarks) */
    private array $outlines = [];

    /** @var array<int, string> objectNumber => serialized object body */
    private array $objects = [];

    private int $objectCounter = 0;

    private ?Import\ImportRegistry $imports = null;
    private ?Attachments $attachments = null;

    /** @var list<string> Extra XMP rdf:Description snippets (e.g. Factur-X extension schema) for the PDF/A XMP builder */
    private array $xmpExtensions = [];

    /** Imported PDF pages (Form XObjects) registry: backgrounds, merged pages, <img src="x.pdf">. */
    public function imports(): Import\ImportRegistry
    {
        return $this->imports ??= new Import\ImportRegistry();
    }

    private ?TransparencyGroups $groups = null;

    /** Transparency-group Form XObjects (CSS group opacity). */
    public function groups(): TransparencyGroups
    {
        return $this->groups ??= new TransparencyGroups();
    }

    private ?SvgPaintRegistry $svgPaints = null;

    /** SVG paint servers: gradient shadings/patterns, tiling patterns, soft masks and Form XObjects. */
    public function paints(): SvgPaintRegistry
    {
        return $this->svgPaints ??= new SvgPaintRegistry();
    }

    /** Embedded file attachments (/Names /EmbeddedFiles + /AF). */
    public function attachments(): Attachments
    {
        return $this->attachments ??= new Attachments();
    }

    /** Stores an extra <rdf:Description> XMP snippet; the PDF/A XMP builder includes getXmpExtensions(). */
    public function addXmpExtension(string $xmlSnippet): void
    {
        if ($xmlSnippet !== '' && !in_array($xmlSnippet, $this->xmpExtensions, true)) {
            $this->xmpExtensions[] = $xmlSnippet;
        }
    }

    /** @return list<string> */
    public function getXmpExtensions(): array
    {
        return $this->xmpExtensions;
    }

    /** @var list<int> Object ids of AcroForm fields (widgets); merged into the single catalog /AcroForm at compile time */
    private array $acroFormFields = [];

    /** @var array<string, string> Extra raw /AcroForm entries (key without slash => raw PDF value), e.g. 'NeedAppearances' => 'true' */
    private array $acroFormEntries = [];

    /**
     * Registers a field object id for the document's single /AcroForm /Fields array (call during compile()).
     */
    public function addAcroFormField(int $objId): void
    {
        $this->acroFormFields[] = $objId;
    }

    /**
     * Sets an extra /AcroForm dictionary entry, e.g. setAcroFormEntry('DA', '(/Helv 0 Tf 0 g)').
     */
    public function setAcroFormEntry(string $key, string $rawValue): void
    {
        $this->acroFormEntries[ltrim($key, '/')] = $rawValue;
    }

    /** @var list<FormWidget> Interactive form widgets collected while rendering (see FormFieldWriter) */
    private array $formWidgets = [];

    /**
     * Registers an interactive form widget on a page. $rect is [x1, y1, x2, y2] in PDF page coordinates.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $rect
     */
    public function addFormWidget(int $pageIndex, array $rect, FormWidget $widget): void
    {
        // A control has exactly one widget: painting it again for a neighbouring page must never add a twin
        $oid = spl_object_id($widget->control);
        if (isset($this->pages[$pageIndex]) && !isset($this->widgetControls[$oid])) {
            $this->widgetControls[$oid] = true;
            $widget->page = $pageIndex;
            $widget->rect = $rect;
            $this->formWidgets[] = $widget;
        }
    }

    public function __construct(Options $options, FontManager $fontManager, ImageLoader $imageLoader)
    {
        $this->options = $options;
        $this->fontManager = $fontManager;
        $this->imageLoader = $imageLoader;
    }

    public function addPage(float $widthPt, float $heightPt): int
    {
        $this->packPage(count($this->pages) - 1); // pages are painted in order: the previous one is finished
        $this->pages[] = [
            'width' => $widthPt,
            'height' => $heightPt,
            'content' => '',
            'packed' => '',
            'links' => [],
        ];
        return count($this->pages) - 1;
    }

    public function appendPageContent(int $pageIndex, string $commands): void
    {
        if (isset($this->pages[$pageIndex])) {
            $this->pages[$pageIndex]['content'] .= $commands;
        }
    }

    /**
     * Keeps the content of a finished page deflated (fast level) instead of as the raw operator text, which is
     * several times larger: a 100-page table would otherwise hold tens of MB of drawing operators until compile().
     */
    private function packPage(int $pageIndex): void
    {
        if (!isset($this->pages[$pageIndex]) || $this->pages[$pageIndex]['content'] === '' || !function_exists('gzcompress')) {
            return;
        }
        $page = &$this->pages[$pageIndex];
        $packed = (string) gzcompress($this->pageContent($pageIndex), 1);
        if ($packed !== '') {
            $page['packed'] = $packed;
            $page['content'] = '';
        }
    }

    private function pageContent(int $pageIndex): string
    {
        $page = $this->pages[$pageIndex];
        if ($page['packed'] === '') {
            return $page['content'];
        }
        return (string) gzuncompress($page['packed']) . $page['content'];
    }

    /**
     * Adds a clickable URI link annotation in PDF page coordinates (bottom-left origin).
     */
    public function registerDestination(string $name, int $pageIndex, float $pdfY): void
    {
        if (!isset($this->destinations[$name])) {
            $this->destinations[$name] = ['page' => $pageIndex, 'y' => $pdfY];
        }
    }

    public function addOutline(int $level, string $title, int $pageIndex, float $pdfY): void
    {
        $this->outlines[] = ['level' => max(1, min(6, $level)), 'title' => $title, 'page' => $pageIndex, 'y' => $pdfY];
    }

    /** @var array<int, list<array{x: float, y: float, contents: string, title: string, icon: string, color: ?array{0: float, 1: float, 2: float}, opacity: float}>> /Text (sticky note) annotations per page */
    private array $textAnnots = [];
    /** @var array{zoom: string, layout: string} mPDF SetDisplayMode */
    private array $viewer = ['zoom' => '', 'layout' => ''];
    private string $documentJs = '';

    /**
     * Adds a /Text (sticky note) annotation whose icon sits at the given PDF coordinates (top-left, bottom-left origin page space).
     *
     * @param ?array{0: float, 1: float, 2: float} $rgb
     */
    public function addTextAnnotation(int $pageIndex, float $pdfX, float $pdfY, string $contents, string $title = '', string $icon = 'Note', ?array $rgb = null, float $opacity = 1.0): void
    {
        if (!isset($this->pages[$pageIndex])) {
            return;
        }
        $this->textAnnots[$pageIndex][] = [
            'x' => $pdfX,
            'y' => $pdfY,
            'contents' => $contents,
            'title' => $title,
            'icon' => $icon,
            'color' => $rgb,
            'opacity' => $opacity,
        ];
    }

    /** @var (\Closure(int): ?list<int>)|null page count => output order of the source page indexes (mPDF DeletePages / MovePages) */
    private ?\Closure $pageOrderResolver = null;

    /**
     * Rearranges / drops pages at compile() time: the resolver receives the page count and returns the 0-based indexes
     * of the pages to output, in order (null: unchanged). Links, named destinations, bookmarks, notes, form fields and
     * imported page templates follow their pages; those of dropped pages are removed.
     *
     * @param \Closure(int): ?list<int> $resolver
     */
    public function setPageOrderResolver(\Closure $resolver): void
    {
        $this->pageOrderResolver = $resolver;
    }

    private function applyPageOrder(): void
    {
        $resolver = $this->pageOrderResolver;
        $this->pageOrderResolver = null;
        $order = $resolver !== null ? $resolver(count($this->pages)) : null;
        if ($order === null || $order === array_keys($this->pages)) {
            return;
        }
        $map = [];
        $pages = [];
        foreach ($order as $old) {
            if (isset($this->pages[$old]) && !isset($map[$old])) {
                $map[$old] = count($pages);
                $pages[] = $this->pages[$old];
            }
        }
        if ($pages === []) {
            return;
        }
        $this->pages = $pages;
        foreach ($this->destinations as $name => $d) {
            if (isset($map[$d['page']])) {
                $this->destinations[$name]['page'] = $map[$d['page']];
            } else {
                unset($this->destinations[$name]);
            }
        }
        $outlines = [];
        foreach ($this->outlines as $o) {
            if (isset($map[$o['page']])) {
                $o['page'] = $map[$o['page']];
                $outlines[] = $o;
            }
        }
        $this->outlines = $outlines;
        $notes = [];
        foreach ($this->textAnnots as $old => $list) {
            if (isset($map[$old])) {
                $notes[$map[$old]] = $list;
            }
        }
        $this->textAnnots = $notes;
        $widgets = [];
        foreach ($this->formWidgets as $w) {
            if (isset($map[$w->page])) {
                $w->page = $map[$w->page];
                $widgets[] = $w;
            }
        }
        $this->formWidgets = $widgets;
        $this->imports?->remapPages($map);
    }

    /** Height in pt of an already added page (0.0 when unknown). */
    public function pageHeightOf(int $pageIndex): float
    {
        return (float) ($this->pages[$pageIndex]['height'] ?? 0.0);
    }

    /** Initial view: $zoom 'fullpage' | 'fullwidth' | 'real' | 'default' | percent, $layout 'single' | 'continuous' | 'two' | 'default'. */
    public function setViewerPreferences(string $zoom, string $layout): void
    {
        $this->viewer = ['zoom' => strtolower(trim($zoom)), 'layout' => strtolower(trim($layout))];
    }

    /** Document-level JavaScript run when the PDF opens (catalog /Names /JavaScript). */
    public function setDocumentJavaScript(string $js): void
    {
        $this->documentJs = $js;
    }

    /**
     * Registers a radial shading ([x0 y0 r0 x1 y1 r1]) and returns its resource name.
     *
     * @param list<float> $coords
     * @param list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}> $stops
     */
    public function registerRadialShading(array $coords, array $stops): string
    {
        $id = 'Sh' . (count($this->shadings) + count($this->radialShadings) + 1);
        $this->radialShadings[$id] = ['coords' => $coords, 'stops' => $stops];
        return $id;
    }

    public function addLinkAnnotation(int $pageIndex, float $pdfX, float $pdfY, float $width, float $height, string $uri): void
    {
        if (!isset($this->pages[$pageIndex]) || $uri === '') {
            return;
        }
        // One /Link per link: a rectangle that lies inside, or sits on the same line right next to, a fragment of a
        // recent link with the same target is folded into that fragment (a link of 6 words is one rectangle, not 11)
        $x1 = $pdfX + $width;
        $y1 = $pdfY + $height;
        $links = &$this->pages[$pageIndex]['links'];
        for ($i = count($links) - 1, $stop = max(0, $i - 5); $i >= $stop; $i--) {
            if ($links[$i]['uri'] !== $uri) {
                continue;
            }
            foreach ($links[$i]['quads'] as $qi => [$qx, $qy, $qw, $qh]) {
                $qx1 = $qx + $qw;
                $qy1 = $qy + $qh;
                if ($pdfX >= $qx - 0.5 && $x1 <= $qx1 + 0.5 && $pdfY >= $qy - 0.5 && $y1 <= $qy1 + 0.5) {
                    return; // already covered
                }
                $overlapV = min($y1, $qy1) - max($pdfY, $qy);
                $minH = min($height, $qh);
                $gap = max($pdfX - $qx1, $qx - $x1);
                if ($minH > 0.0 && $overlapV >= 0.6 * $minH && $gap <= max(1.0, 0.45 * $minH)) {
                    $nx = min($pdfX, $qx);
                    $ny = min($pdfY, $qy);
                    $links[$i]['quads'][$qi] = [$nx, $ny, max($x1, $qx1) - $nx, max($y1, $qy1) - $ny];
                    $this->unionLinkRect($links[$i]);
                    return;
                }
            }
            // Each line of a wrapped link stays its own /Link (as browsers write it): a single annotation with
            // /QuadPoints would make the whole union rectangle clickable in viewers that ignore QuadPoints (PDFium)
            break;
        }
        $links[] = ['x' => $pdfX, 'y' => $pdfY, 'w' => $width, 'h' => $height, 'uri' => $uri, 'quads' => [[$pdfX, $pdfY, $width, $height]]];
    }

    /** @param array{x: float, y: float, w: float, h: float, uri: string, quads: list<array{0: float, 1: float, 2: float, 3: float}>} $link */
    private function unionLinkRect(array &$link): void
    {
        $x0 = INF;
        $y0 = INF;
        $x1 = -INF;
        $y1 = -INF;
        foreach ($link['quads'] as [$qx, $qy, $qw, $qh]) {
            $x0 = min($x0, $qx);
            $y0 = min($y0, $qy);
            $x1 = max($x1, $qx + $qw);
            $y1 = max($y1, $qy + $qh);
        }
        $link['x'] = $x0;
        $link['y'] = $y0;
        $link['w'] = $x1 - $x0;
        $link['h'] = $y1 - $y0;
    }

    /**
     * Registers an opacity value (0.0..1.0) and returns its ExtGState resource name (e.g. 'GS1').
     */
    public function registerAlphaState(float $alpha): string
    {
        $clamped = round(max(0.0, min(1.0, $alpha)), 3);
        foreach ($this->alphaStates as $id => $val) {
            if (abs($val - $clamped) < 0.001) {
                return $id;
            }
        }
        $id = 'GS' . (count($this->alphaStates) + 1);
        $this->alphaStates[$id] = $clamped;
        return $id;
    }

    /**
     * Registers a blend mode (e.g. 'Multiply') and returns its ExtGState resource name (e.g. 'GB1').
     */
    public function registerBlendState(string $mode): string
    {
        $existing = array_search($mode, $this->blendStates, true);
        if ($existing !== false) {
            return (string) $existing;
        }
        $id = 'GB' . (count($this->blendStates) + 1);
        $this->blendStates[$id] = $mode;
        return $id;
    }

    /**
     * Registers an axial linear-gradient shading and returns its PDF Shading resource name (e.g. 'Sh1').
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $coords [x0, y0, x1, y1] in PDF page coordinates
     * @param list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}> $stops
     */
    public function registerAxialShading(array $coords, array $stops): string
    {
        $id = 'Sh' . (count($this->shadings) + count($this->radialShadings) + 1);
        $this->shadings[$id] = [
            'coords' => $coords,
            'stops' => $stops,
        ];
        return $id;
    }

    /**
     * Compiles all objects, fonts, images, shadings, and pages into a binary PDF 1.7 string.
     */
    public function compile(): string
    {
        $this->applyPageOrder();
        $this->objects = [];
        $this->objectCounter = 0;
        ColorOps::configure($this->options);
        $pdfxActive = PdfX::level($this->options) !== '';
        $pdfaPart = PdfFinisher::parsePdfa($this->options->pdfa)['part'] ?? null;

        $catalogObjId = $this->allocObj();
        $pagesObjId = $this->allocObj();
        $infoObjId = $this->allocObj();

        // 1. Build Font Objects (Type0 + CIDFontType2 + FontDescriptor + FontFile2 + ToUnicode)
        $fontRefs = [];
        foreach ($this->fontManager->getUsedFontsData() as $fontData) {
            $pdfFontId = $fontData['id'];
            $fontObjId = $this->buildFontObjects($fontData['parser'], $fontData['glyphs'], $pdfFontId, $fontData['gidText'] ?? []);
            $fontRefs[$pdfFontId] = $fontObjId;
        }

        // 2. Build Image XObjects
        $imageRefs = [];
        foreach ($this->imageLoader->getLoadedImages() as $imgData) {
            if (isset($imgData['pdfPage'])) { // <img src="file.pdf"> — drawn as an imported Form XObject
                $this->imports()->register($imgData['pdfPage'], $imgData['id']);
                continue;
            }
            $imgObjId = $this->buildImageObject($imgData);
            $imageRefs[$imgData['id']] = $imgObjId;
        }
        if (!$this->imports()->isEmpty()) {
            $imageRefs += $this->imports()->emit(
                fn(): int => $this->allocObj(),
                function (int $id, string $body): void {
                    $this->objects[$id] = $body;
                },
                $this->options->compress
            );
        }

        $groupIds = $this->groups()->reserve(fn(): int => $this->allocObj());
        $imageRefs += $groupIds;

        // 3. Build ExtGState Dictionary
        $gsDictEntries = [];
        foreach ($this->alphaStates as $gsId => $alpha) {
            $gsObjId = $this->allocObj();
            $this->objects[$gsObjId] = sprintf(
                "<< /Type /ExtGState /CA %.3F /ca %.3F /BM /Normal >>",
                $alpha,
                $alpha
            );
            $gsDictEntries[] = "/{$gsId} {$gsObjId} 0 R";
        }

        foreach ($this->blendStates as $gsId => $mode) {
            $gsObjId = $this->allocObj();
            $this->objects[$gsObjId] = "<< /Type /ExtGState /BM /{$mode} >>";
            $gsDictEntries[] = "/{$gsId} {$gsObjId} 0 R";
        }

        // 4. Build Shading Objects
        $shadingRefs = [];
        foreach ($this->shadings as $shId => $shData) {
            $shObjId = $this->buildShadingObject($shData['coords'], $shData['stops']);
            $shadingRefs[$shId] = $shObjId;
        }
        foreach ($this->radialShadings as $shId => $shData) {
            $shadingRefs[$shId] = $this->buildShadingObject($shData['coords'], $shData['stops'], 3);
        }

        // 4b. SVG paint servers (gradient shadings/patterns, tiling patterns, soft masks, form XObjects)
        $svgPaint = $this->paints()->build(fn(): int => $this->allocObj(), function (int $id, string $body): void {
            $this->objects[$id] = $body;
        });
        $imageRefs += $svgPaint['xobject'];
        $shadingRefs += $svgPaint['shading'];
        foreach ($svgPaint['extgstate'] as $svgGsId => $svgGsObj) {
            $gsDictEntries[] = "/{$svgGsId} {$svgGsObj} 0 R";
        }

        // 5. Build Shared Resources Dictionary
        $resourcesParts = [];
        if (!empty($fontRefs)) {
            $fEntries = [];
            foreach ($fontRefs as $fId => $objId) {
                $fEntries[] = "/{$fId} {$objId} 0 R";
            }
            $resourcesParts[] = '/Font << ' . implode(' ', $fEntries) . ' >>';
        }
        if (!empty($imageRefs)) {
            $imEntries = [];
            foreach ($imageRefs as $imId => $objId) {
                $imEntries[] = "/{$imId} {$objId} 0 R";
            }
            $resourcesParts[] = '/XObject << ' . implode(' ', $imEntries) . ' >>';
        }
        if (!empty($gsDictEntries)) {
            $resourcesParts[] = '/ExtGState << ' . implode(' ', $gsDictEntries) . ' >>';
        }
        if (!empty($shadingRefs)) {
            $shEntries = [];
            foreach ($shadingRefs as $shId => $objId) {
                $shEntries[] = "/{$shId} {$objId} 0 R";
            }
            $resourcesParts[] = '/Shading << ' . implode(' ', $shEntries) . ' >>';
        }
        if ($svgPaint['pattern'] !== []) {
            $patEntries = [];
            foreach ($svgPaint['pattern'] as $patId => $patObj) {
                $patEntries[] = "/{$patId} {$patObj} 0 R";
            }
            $resourcesParts[] = '/Pattern << ' . implode(' ', $patEntries) . ' >>';
        }

        $spotEntries = SpotColors::active()
            ? SpotColors::resourceEntries(array_merge(array_map(fn(int $i): string => $this->pageContent($i), array_keys($this->pages)), $this->groups()->contents()))
            : '';
        if ($spotEntries !== '') {
            $resourcesParts[] = '/ColorSpace << ' . $spotEntries . ' >>';
        }

        $resourcesObjId = $this->allocObj();
        $this->objects[$resourcesObjId] = '<< ' . implode(' ', $resourcesParts) . ' >>';
        $this->groups()->fill($groupIds, $resourcesObjId, function (int $id, string $body): void {
            $this->objects[$id] = $body;
        }, $this->options->compress);
        $this->paints()->fill($svgPaint['pending'], $resourcesObjId, function (int $id, string $body): void {
            $this->objects[$id] = $body;
        }, $this->options->compress);

        // 6. Build Page Objects (page object ids are allocated first so internal links can reference them)
        $pageObjIds = [];
        foreach ($this->pages as $_page) {
            $pageObjIds[] = $this->allocObj();
        }
        $formAnnots = $this->buildFormFields($pageObjIds, $fontRefs);
        $importedAnnots = $this->buildImportedAnnotations($pageObjIds);
        foreach ($this->pages as $pageIdx => $page) {
            $pageObjId = $pageObjIds[$pageIdx];
            $contentObjId = $this->allocObj();

            $streamData = $this->imports()->backgroundContent($pageIdx, $page['width'], $page['height']) . $this->pageContent($pageIdx);
            $streamData = ColorOps::rewriteRgb($streamData . PdfX::cropMarks($page['width'], $page['height'], $this->options));
            $this->objects[$contentObjId] = $this->createStreamObject($streamData, $this->options->compress);

            $annotsStr = '';
            $annotRefs = [];
            if (!empty($page['links'])) {
                foreach ($page['links'] as $lnk) {
                    if (str_starts_with($lnk['uri'], '#')) {
                        $target = rawurldecode(substr($lnk['uri'], 1));
                        $dest = $this->destinations[$target] ?? null;
                        if ($dest === null && ($target === '' || strtolower($target) === 'top') && isset($pageObjIds[0])) {
                            $dest = ['page' => 0, 'y' => $this->pages[0]['height']]; // HTML: "#" and "#top" mean the document top
                        }
                        if ($dest === null || !isset($pageObjIds[$dest['page']])) {
                            if (!isset($this->unresolvedLinks[$target])) {
                                $this->unresolvedLinks[$target] = true;
                                $this->options->addWarning("Internal link '#{$target}' has no target (no element with that id or <a name>); the link was omitted.");
                            }
                            continue;
                        }
                        $action = sprintf('/Dest [%d 0 R /XYZ 0 %.2F 0]', $pageObjIds[$dest['page']], $dest['y']);
                    } else {
                        $action = '/A << /Type /Action /S /URI /URI (' . $this->escapePdfString($this->encodeUri($lnk['uri'])) . ') >>';
                    }
                    $quads = '';
                    if (count($lnk['quads'] ?? []) > 1) {
                        // A link wrapped over several lines: one annotation, one quadrilateral per line (viewers
                        // without /QuadPoints support use the /Rect around all of them)
                        foreach ($lnk['quads'] as [$qx, $qy, $qw, $qh]) {
                            $quads .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F %.2F %.2F', $qx, $qy + $qh, $qx + $qw, $qy + $qh, $qx, $qy, $qx + $qw, $qy);
                        }
                        $quads = ' /QuadPoints [' . ltrim($quads) . ']';
                    }
                    $annotObjId = $this->allocObj();
                    $this->objects[$annotObjId] = sprintf(
                        "<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F]%s /Border [0 0 0] %s >>",
                        $lnk['x'],
                        $lnk['y'],
                        $lnk['x'] + $lnk['w'],
                        $lnk['y'] + $lnk['h'],
                        $quads,
                        $action
                    );
                    $annotRefs[] = "{$annotObjId} 0 R";
                }
            }
            foreach ($pdfxActive ? [] : ($this->textAnnots[$pageIdx] ?? []) as $note) {
                $noteObjId = $this->allocObj();
                $apEntry = '';
                if ($pdfaPart !== null) {
                    // PDF/A (ISO 19005-2/3 6.3.3) requires a normal appearance stream; PDF/A-1 forbids /CA below 1
                    $apObjId = $this->allocObj();
                    $this->objects[$apObjId] = self::noteAppearanceStream($note['color']);
                    $apEntry = " /AP << /N {$apObjId} 0 R >>";
                    if ($pdfaPart === 1) {
                        $note['opacity'] = 1.0;
                    }
                }
                $this->objects[$noteObjId] = sprintf(
                    '<< /Type /Annot /Subtype /Text /Rect [%.2F %.2F %.2F %.2F] /Contents %s /T %s /Name /%s /F 28 /Open false%s /CA %.2F' . $apEntry . ' >>',
                    $note['x'],
                    $note['y'] - 20.0,
                    $note['x'] + 20.0,
                    $note['y'],
                    $this->encodeUtf16Hex($note['contents']),
                    $this->encodeUtf16Hex($note['title']),
                    $note['icon'],
                    $note['color'] !== null ? sprintf(' /C [%.3F %.3F %.3F]', $note['color'][0], $note['color'][1], $note['color'][2]) : '',
                    $note['opacity']
                );
                $annotRefs[] = "{$noteObjId} 0 R";
            }
            foreach ($formAnnots[$pageIdx] ?? [] as $formAnnotId) {
                $annotRefs[] = "{$formAnnotId} 0 R";
            }
            foreach ($importedAnnots[$pageIdx] ?? [] as $impAnnotId) {
                $annotRefs[] = "{$impAnnotId} 0 R";
            }
            $annotsStr = empty($annotRefs) ? '' : ' /Annots [' . implode(' ', $annotRefs) . ']';

            $this->objects[$pageObjId] = sprintf(
                "<< /Type /Page /Parent %d 0 R %s /Resources %d 0 R /Contents %d 0 R%s >>",
                $pagesObjId,
                PdfX::needsBoxes($this->options)
                    ? PdfX::pageBoxes($page['width'], $page['height'], $this->options)
                    : sprintf('/MediaBox [0 0 %.2F %.2F]', $page['width'], $page['height']),
                $resourcesObjId,
                $contentObjId,
                $annotsStr
            );
        }

        // 7. Populate Pages, Catalog, and Info Objects
        $kidsRefs = array_map(static fn(int $id): string => "{$id} 0 R", $pageObjIds);
        $this->objects[$pagesObjId] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', $kidsRefs),
            count($pageObjIds)
        );

        $outlineRef = $this->buildOutlines($pageObjIds);
        $lang = trim($this->options->lang);
        $this->objects[$catalogObjId] = sprintf(
            "<< /Type /Catalog /Pages %d 0 R%s%s >>",
            $pagesObjId,
            $outlineRef !== null ? " /Outlines {$outlineRef} 0 R /PageMode /UseOutlines" : '',
            $lang !== '' ? ' /Lang (' . $this->escapePdfString($lang) . ')' : ''
        );

        // Embedded files (/Names /EmbeddedFiles + PDF/A-3 /AF) appended to the catalog dictionary
        if ($pdfxActive && !$this->attachments()->isEmpty()) {
            $this->options->addWarning('PDF/X: embedded file attachments are not allowed and were omitted.');
            $this->attachments = null;
        }
        $attachEntries = $this->attachments()->emit(
            fn(): int => $this->allocObj(),
            function (int $id, string $body): void {
                $this->objects[$id] = $body;
            },
            $this->options->compress
        );
        // Initial view (mPDF SetDisplayMode) and document JavaScript (mPDF SetJS)
        $viewEntries = $this->viewerEntries($pageObjIds[0] ?? 0);
        if ($this->documentJs !== '' && $this->options->pdfa === '' && !$pdfxActive) {
            $jsEntry = '/JavaScript << /Names [(ivopdf) << /S /JavaScript /JS (' . $this->escapePdfString($this->documentJs) . ') >>] >>';
            if (str_contains($attachEntries, ' /Names << ')) {
                $attachEntries = (string) preg_replace('~ /Names << ~', ' /Names << ' . strtr($jsEntry, ['\\' => '\\\\', '$' => '\\$']) . ' ', $attachEntries, 1);
            } else {
                $viewEntries .= ' /Names << ' . $jsEntry . ' >>';
            }
        }
        if ($viewEntries !== '') {
            $this->objects[$catalogObjId] = rtrim(substr($this->objects[$catalogObjId], 0, -2)) . $viewEntries . ' >>';
        }
        if ($attachEntries !== '') {
            $this->objects[$catalogObjId] = rtrim(substr($this->objects[$catalogObjId], 0, -2)) . $attachEntries . ' >>';
        }

        $now = time();
        $infoParts = [
            '/Producer ' . $this->encodeUtf16Hex($this->options->creator),
            '/Creator ' . $this->encodeUtf16Hex($this->options->creator),
            "/CreationDate (D:" . gmdate('YmdHis', $now) . "+00'00')",
            "/ModDate (D:" . gmdate('YmdHis', $now) . "+00'00')",
        ];
        if ($this->options->title !== '') {
            $infoParts[] = '/Title ' . $this->encodeUtf16Hex($this->options->title);
        }
        if ($this->options->author !== '') {
            $infoParts[] = '/Author ' . $this->encodeUtf16Hex($this->options->author);
        }
        if ($this->options->subject !== '') {
            $infoParts[] = '/Subject ' . $this->encodeUtf16Hex($this->options->subject);
        }
        if ($this->options->keywords !== '') {
            $infoParts[] = '/Keywords ' . $this->encodeUtf16Hex($this->options->keywords);
        }
        if ($pdfxActive) {
            if ($this->options->title === '') {
                $infoParts[] = '/Title (Untitled)';
            }
            $infoParts[] = ltrim(PdfX::infoEntries($this->options));
        }
        $this->objects[$infoObjId] = '<< ' . implode(' ', $infoParts) . ' >>';

        // 8. Security layers (PDF/A, AcroForm, signature, encryption) and serialization with xref table
        $finisher = new PdfFinisher($this->options);
        $finisher->setXmpExtensions($this->xmpExtensions);
        $sec = $finisher->finish(
            $this->objects,
            $this->objectCounter,
            $catalogObjId,
            $pageObjIds,
            array_map(static fn(array $p): array => ['width' => $p['width'], 'height' => $p['height']], $this->pages),
            $now,
            $this->acroFormFields,
            $this->acroFormEntries
        );

        return (new PdfWriter())->write(
            $this->objects,
            $this->objectCounter,
            $catalogObjId,
            $infoObjId,
            $sec['documentId'],
            $sec['version'],
            $sec['encryption'],
            $sec['encryptObjId'],
            $sec['signature'],
            $sec['sigObjId']
        );
    }

    /**
     * @param array<int, int> $usedGlyphs [codepoint => gid]
     */
    private function buildFontObjects(TtfParser $parser, array $usedGlyphs, string $pdfFontId = 'F1', array $gidText = []): int
    {
        $type0ObjId = $this->allocObj();
        $cidFontObjId = $this->allocObj();
        $descriptorObjId = $this->allocObj();
        $fontFileObjId = $this->allocObj();
        $toUnicodeObjId = $this->allocObj();

        $rawTtf = $parser->getRawData();
        $isCff = $parser->isCff();
        // CFF-flavoured OpenType fonts are embedded whole (no subsetter); TrueType outlines are subset by glyph id
        $subset = ($this->options->subsetFonts && !$isCff) ? TtfSubsetter::subset($rawTtf, array_values($usedGlyphs)) : null;
        if ($subset !== null) {
            $rawTtf = $subset;
        }
        $compressedTtf = (string) gzcompress($rawTtf, 6);
        $this->objects[$fontFileObjId] = sprintf(
            "<< %s/Length %d /Length1 %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
            $isCff ? '/Subtype /OpenType ' : '',
            strlen($compressedTtf),
            strlen($rawTtf),
            $compressedTtf
        );

        $psName = preg_replace('/[^A-Za-z0-9_.+-]/', '', $parser->postScriptName) ?: 'Font';
        if ($subset !== null) {
            // Subset fonts carry a unique 6-letter tag prefix (ISO 32000-1, 9.6.4)
            $tag = '';
            $hash = crc32($pdfFontId . $psName . implode(',', array_keys($usedGlyphs)));
            for ($i = 0; $i < 6; $i++) {
                $tag .= chr(65 + ($hash % 26));
                $hash = intdiv($hash, 26) + 7 * ($i + 3);
            }
            $psName = $tag . '+' . $psName;
        }
        $bbox = sprintf('[%d %d %d %d]', $parser->bbox[0], $parser->bbox[1], $parser->bbox[2], $parser->bbox[3]);

        $this->objects[$descriptorObjId] = sprintf(
            "<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox %s /ItalicAngle %.1F /Ascent %d /Descent %d /CapHeight %d /StemV %d /%s %d 0 R >>",
            $psName,
            $parser->flags,
            $bbox,
            $parser->italicAngle,
            $parser->ascent,
            $parser->descent,
            $parser->capHeight,
            $parser->stemV,
            $isCff ? 'FontFile3' : 'FontFile2',
            $fontFileObjId
        );

        // Build /W width array for all used GIDs
        $gidWidths = [];
        foreach ($usedGlyphs as $cp => $gid) {
            $gidWidths[$parser->pdfCid($gid)] = $parser->getGlyphWidthByGid($gid);
        }
        ksort($gidWidths);

        $wParts = [];
        foreach ($gidWidths as $gid => $w) {
            $wParts[] = sprintf('%d [%d]', $gid, $w);
        }
        $wArrayStr = '[' . implode(' ', $wParts) . ']';

        $this->objects[$cidFontObjId] = sprintf(
            "<< /Type /Font /Subtype /%s /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d /W %s%s >>",
            $isCff ? 'CIDFontType0' : 'CIDFontType2',
            $psName,
            $descriptorObjId,
            $parser->missingWidth,
            $wArrayStr,
            $isCff ? '' : ' /CIDToGIDMap /Identity'
        );

        // Build /ToUnicode CMap so copy/paste and search work for Cyrillic & Unicode
        $cmapStream = $this->buildToUnicodeCMap($usedGlyphs, $gidText, $parser);
        $this->objects[$toUnicodeObjId] = $this->createStreamObject($cmapStream, $this->options->compress);

        $this->objects[$type0ObjId] = sprintf(
            "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>",
            $psName,
            $cidFontObjId,
            $toUnicodeObjId
        );

        return $type0ObjId;
    }

    /**
     * @param array<int, int> $usedGlyphs [codepoint => gid]
     */
    private function buildToUnicodeCMap(array $usedGlyphs, array $gidText = [], ?TtfParser $parser = null): string
    {
        $gidToCp = [];
        foreach ($usedGlyphs as $cp => $gid) {
            if ($gid > 0 && $cp > 0 && $cp <= 0x10FFFF) {
                $gidToCp[$gid] = $cp;
            }
        }
        // Glyphs produced by shaping (ligatures, contextual forms) map to their whole source text
        $gidToText = [];
        foreach ($gidText as $gid => $text) {
            if ($gid > 0 && $text !== '') {
                $gidToText[$gid] = $text;
                unset($gidToCp[$gid]);
            }
        }

        // Keys of the bfchar table are CIDs (== glyph ids except for CID-keyed CFF fonts)
        $entries = [];
        foreach ($gidToCp as $gid => $cp) {
            $entries[$parser !== null ? $parser->pdfCid($gid) : $gid] = mb_chr($cp, 'UTF-8');
        }
        foreach ($gidToText as $gid => $text) {
            $entries[$parser !== null ? $parser->pdfCid($gid) : $gid] = $text;
        }
        ksort($entries);

        $lines = [];
        foreach ($entries as $cid => $text) {
            // UTF-16BE (surrogate pairs for codepoints > U+FFFF; several codepoints for ligatures)
            $lines[] = sprintf('<%04X> <%s>', $cid, strtoupper(bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))));
        }

        $chunks = array_chunk($lines, 100);
        $bfcharBlocks = '';
        foreach ($chunks as $chunk) {
            $cnt = count($chunk);
            $bfcharBlocks .= "{$cnt} beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\n"
            . "begincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n"
            . "/CMapType 2 def\n"
            . "1 begincodespacerange\n"
            . "<0000> <FFFF>\n"
            . "endcodespacerange\n"
            . $bfcharBlocks
            . "endcmap\n"
            . "CMapName currentdict /CMap defineresource pop\n"
            . "end\n"
            . "end";
    }

    /**
     * @param array{
     *   id: string,
     *   type: string,
     *   width: int,
     *   height: int,
     *   colorSpace: string,
     *   bitsPerComponent: int,
     *   filter: string,
     *   data: string,
     *   smask?: string,
     *   decodeParms?: string
     * } $imgData
     */
    private function buildImageObject(array $imgData): int
    {
        if (ColorOps::isCmykTarget() || ColorOps::isFlattening()) {
            $imgData = ImageCmykConverter::convert($imgData, $this->options, ColorOps::isCmykTarget(), ColorOps::isFlattening());
        }
        $smaskRef = '';
        if (!empty($imgData['smask'])) {
            $smaskObjId = $this->allocObj();
            $smaskBytes = $imgData['smask'];
            $this->objects[$smaskObjId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                $imgData['width'],
                $imgData['height'],
                strlen($smaskBytes),
                $smaskBytes
            );
            $smaskRef = " /SMask {$smaskObjId} 0 R";
        }

        $decodeParmsStr = !empty($imgData['decodeParms']) ? (' /DecodeParms ' . $imgData['decodeParms']) : '';
        if (!empty($imgData['decode'])) {
            $decodeParmsStr .= ' /Decode ' . $imgData['decode'];
        }
        $imgObjId = $this->allocObj();
        $raw = $imgData['data'];

        $this->objects[$imgObjId] = sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Interpolate %s /Filter /%s%s%s /Length %d >>\nstream\n%s\nendstream",
            $imgData['width'],
            $imgData['height'],
            $imgData['colorSpace'],
            $imgData['bitsPerComponent'],
            // PDF/A (ISO 19005-1 6.2.4 / 19005-2 6.2.8) forbids /Interpolate true at every conformance level
            (($imgData['interpolate'] ?? true) && $this->options->pdfa === '') ? 'true' : 'false',
            $imgData['filter'],
            $decodeParmsStr,
            $smaskRef,
            strlen($raw),
            $raw
        );

        return $imgObjId;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $coords
     * @param list<array{color: array{0: float, 1: float, 2: float, 3: float}, offset: float}> $stops
     */
    private function buildShadingObject(array $coords, array $stops, int $shadingType = 2): int
    {
        $shObjId = $this->allocObj();
        // Normalize stops so the function domain [0, 1] covers the first/last stop positions
        if ($stops[0]['offset'] > 0.0001) {
            array_unshift($stops, ['color' => $stops[0]['color'], 'offset' => 0.0]);
        }
        if ($stops[count($stops) - 1]['offset'] < 0.9999) {
            $stops[] = ['color' => $stops[count($stops) - 1]['color'], 'offset' => 1.0];
        }
        $first = $stops[0]['color'];
        $last = $stops[count($stops) - 1]['color'];
        $csSpace = ColorOps::shadingSpace($stops);

        if (count($stops) === 2) {
            $fnStr = sprintf(
                "<< /FunctionType 2 /Domain [0.0 1.0] /C0 [%s] /C1 [%s] /N 1.0 >>",
                ColorOps::components($first, $csSpace),
                ColorOps::components($last, $csSpace)
            );
        } else {
            // Multi-stop stitching FunctionType 3
            $subFunctions = [];
            $bounds = [];
            $encode = [];
            $count = count($stops);

            for ($i = 0; $i < $count - 1; $i++) {
                $c0 = $stops[$i]['color'];
                $c1 = $stops[$i + 1]['color'];
                $subFunctions[] = sprintf(
                    "<< /FunctionType 2 /Domain [0.0 1.0] /C0 [%s] /C1 [%s] /N 1.0 >>",
                    ColorOps::components($c0, $csSpace),
                    ColorOps::components($c1, $csSpace)
                );
                if ($i > 0) {
                    $prevBound = $i > 1 ? (float) end($bounds) : 0.0;
                    $bounds[] = sprintf('%.4F', min(0.9999, max($prevBound + 0.0001, $stops[$i]['offset'])));
                }
                $encode[] = '0.0 1.0';
            }

            $fnStr = sprintf(
                "<< /FunctionType 3 /Domain [0.0 1.0] /Functions [%s] /Bounds [%s] /Encode [%s] >>",
                implode(' ', $subFunctions),
                implode(' ', $bounds),
                implode(' ', $encode)
            );
        }

        $this->objects[$shObjId] = sprintf(
            "<< /ShadingType %d /ColorSpace /%s /Coords [%s] /Function %s /Extend [true true] >>",
            $shadingType,
            $csSpace,
            implode(' ', array_map(static fn(float $c): string => sprintf('%.3F', $c), $coords)),
            $fnStr
        );

        return $shObjId;
    }

    /**
     * Writes all collected form widgets as AcroForm fields, registers them (plus /DA, /DR, /NeedAppearances)
     * with the single /AcroForm and returns the widget object ids per page for the /Annots arrays.
     *
     * @param list<int> $pageObjIds
     * @param array<string, int> $fontRefs embedded font resource name => object id
     * @return array<int, list<int>>
     */
    private function buildFormFields(array $pageObjIds, array $fontRefs): array
    {
        if ($this->formWidgets === []) {
            return [];
        }
        $compress = $this->options->compress;
        $res = (new FormFieldWriter())->build(
            $this->formWidgets,
            $pageObjIds,
            $fontRefs,
            fn(): int => $this->allocObj(),
            function (string $data, string $dictEntries) use ($compress): string {
                $body = $this->createStreamObject(ColorOps::rewriteRgb($data), $compress);
                return (string) preg_replace('/^<< /', '<< ' . $dictEntries . ' ', $body, 1);
            }
        );
        foreach ($res['objects'] as $id => $body) {
            $this->objects[$id] = $body;
        }
        if ($res['fields'] === []) {
            return [];
        }
        foreach ($res['fields'] as $fieldId) {
            $this->addAcroFormField($fieldId);
        }
        if ($this->options->pdfa !== '') {
            $this->options->addWarning('PDF/A: interactive form fields use the non-embedded standard fonts Helvetica / ZapfDingbats (and /NeedAppearances), so this document will not pass PDF/A validation; set formFields => false to print the fields as static content.');
        }
        $fontEntries = [];
        foreach ($res['fonts'] as $name => $objId) {
            $fontEntries[] = "/{$name} {$objId} 0 R";
        }
        $this->setAcroFormEntry('DA', '(/Helv 0 Tf 0 g)');
        $this->setAcroFormEntry('DR', '<< /Font << ' . implode(' ', $fontEntries) . ' >> >>');
        if ($res['needAppearances']) {
            $this->setAcroFormEntry('NeedAppearances', 'true');
        }
        return $res['annots'];
    }

    /**
     * Carries over the annotations / form fields of imported pages (see Import\ImportedAnnotations) and merges
     * their fields and /DR fonts into the single /AcroForm. Returns the annotation object ids per page index.
     *
     * @param list<int> $pageObjIds
     * @return array<int, list<int>>
     */
    private function buildImportedAnnotations(array $pageObjIds): array
    {
        $reg = $this->imports();
        if ($reg->isEmpty() || $reg->placements() === []) {
            return [];
        }
        $res = Import\ImportedAnnotations::build(
            $reg->placements(),
            $reg->getPoolMaps(),
            $pageObjIds,
            array_map(static fn(array $p): array => [(float) $p['width'], (float) $p['height']], $this->pages),
            $this->objects,
            $this->acroFormEntries['DR'] ?? '',
            fn(): int => $this->allocObj(),
            function (int $id, string $body): void {
                $this->objects[$id] = $body;
            }
        );
        foreach ($res['fields'] as $fieldId) {
            $this->addAcroFormField($fieldId);
        }
        if ($res['fonts'] !== []) {
            $fonts = '';
            foreach ($res['fonts'] as $name => $ref) {
                $fonts .= ' /' . $name . ' ' . $ref;
            }
            $dr = $this->acroFormEntries['DR'] ?? null;
            if ($dr === null) {
                $dr = '<< /Font <<' . $fonts . ' >> >>';
            } elseif (preg_match('~/Font\s*<<~', $dr) === 1) {
                $dr = (string) preg_replace('~/Font\s*<<~', '/Font <<' . $fonts, $dr, 1);
            } else {
                $dr = (string) preg_replace('~^<<~', '<< /Font <<' . $fonts . ' >>', $dr, 1);
            }
            $this->setAcroFormEntry('DR', $dr);
        }
        if ($res['da'] !== null && !isset($this->acroFormEntries['DA'])) {
            $this->setAcroFormEntry('DA', PdfFinisher::pdfText($res['da']));
        }
        if ($res['needAppearances'] && !isset($this->acroFormEntries['NeedAppearances'])) {
            $this->setAcroFormEntry('NeedAppearances', 'true');
        }
        return $res['annots'];
    }

    /**
     * Normal appearance of a /Text (sticky note) annotation, 20 x 20 pt: a folded note in the annotation colour
     * (yellow by default) with three text lines. Used under PDF/A, which requires /AP for every non-Link annotation.
     *
     * @param ?array{0: float, 1: float, 2: float} $rgb
     */
    private static function noteAppearanceStream(?array $rgb): string
    {
        [$r, $g, $b] = $rgb ?? [1.0, 0.86, 0.2];
        $ops = sprintf("q 0.6 w %.3F %.3F %.3F rg 0.25 0.25 0.25 RG
", $r, $g, $b)
            . "1 1 m 1 19 l 19 19 l 19 6 l 14 1 l h B
"
            . "14 1 m 14 6 l 19 6 l S
"
            . "0.8 w 4 15 m 16 15 l S 4 11.5 m 16 11.5 l S 4 8 m 11 8 l S Q
";
        return sprintf(
            "<< /Type /XObject /Subtype /Form /FormType 1 /BBox [0 0 20 20] /Resources << >> /Length %d >>
stream
%s
endstream",
            strlen($ops),
            $ops
        );
    }

    private function createStreamObject(string $data, bool $compress): string
    {
        if ($compress && $data !== '') {
            $compressed = (string) gzcompress($data, 6);
            return sprintf(
                "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                strlen($compressed),
                $compressed
            );
        }

        return sprintf(
            "<< /Length %d >>\nstream\n%s\nendstream",
            strlen($data),
            $data
        );
    }

    /**
     * Builds the /Outlines tree from heading bookmarks; returns the outline root object id or null.
     *
     * @param list<int> $pageObjIds
     */
    private function buildOutlines(array $pageObjIds): ?int
    {
        if (!$this->options->bookmarks || empty($this->outlines)) {
            return null;
        }
        $rootId = $this->allocObj();
        $items = [];
        foreach ($this->outlines as $o) {
            if (!isset($pageObjIds[$o['page']])) {
                continue;
            }
            $items[] = $o + ['id' => $this->allocObj(), 'parent' => $rootId, 'children' => []];
        }
        if (empty($items)) {
            unset($this->objects[$rootId]);
            $this->objects[$rootId] = '<< /Type /Outlines /Count 0 >>';
            return $rootId;
        }

        // Nest by heading level using a stack
        $stack = [];
        $topLevel = [];
        foreach ($items as $idx => $item) {
            while (!empty($stack) && $items[end($stack)]['level'] >= $item['level']) {
                array_pop($stack);
            }
            if (empty($stack)) {
                $topLevel[] = $idx;
            } else {
                $parentIdx = end($stack);
                $items[$idx]['parent'] = $items[$parentIdx]['id'];
                $items[$parentIdx]['children'][] = $idx;
            }
            $stack[] = $idx;
        }

        $emitLevel = function (array $indices) use (&$items, &$emitLevel, $pageObjIds): int {
            $n = count($indices);
            $total = 0;
            foreach ($indices as $pos => $idx) {
                $it = $items[$idx];
                $childCount = empty($it['children']) ? 0 : $emitLevel($it['children']);
                $parts = [
                    '/Title ' . $this->encodeUtf16Hex(mb_substr($it['title'], 0, 200)),
                    "/Parent {$it['parent']} 0 R",
                    sprintf('/Dest [%d 0 R /XYZ 0 %.2F 0]', $pageObjIds[$it['page']], $it['y']),
                ];
                if ($pos > 0) {
                    $parts[] = '/Prev ' . $items[$indices[$pos - 1]]['id'] . ' 0 R';
                }
                if ($pos < $n - 1) {
                    $parts[] = '/Next ' . $items[$indices[$pos + 1]]['id'] . ' 0 R';
                }
                if ($childCount > 0) {
                    $parts[] = '/First ' . $items[$it['children'][0]]['id'] . ' 0 R';
                    $parts[] = '/Last ' . $items[$it['children'][count($it['children']) - 1]]['id'] . ' 0 R';
                    $parts[] = '/Count ' . ($it['level'] <= 2 ? $childCount : -$childCount);
                }
                $this->objects[$it['id']] = '<< ' . implode(' ', $parts) . ' >>';
                $total += 1 + ($it['level'] <= 2 ? $childCount : 0);
            }
            return $total;
        };
        $count = $emitLevel($topLevel);

        $this->objects[$rootId] = sprintf(
            '<< /Type /Outlines /First %d 0 R /Last %d 0 R /Count %d >>',
            $items[$topLevel[0]]['id'],
            $items[$topLevel[count($topLevel) - 1]]['id'],
            $count
        );
        return $rootId;
    }

    /** Catalog entries for the initial zoom (/OpenAction) and page layout (/PageLayout). */
    private function viewerEntries(int $firstPageObjId): string
    {
        $out = '';
        $zoom = $this->viewer['zoom'];
        if ($firstPageObjId > 0 && $zoom !== '' && $zoom !== 'default') {
            if ($zoom === 'fullpage') {
                $out .= " /OpenAction [{$firstPageObjId} 0 R /Fit]";
            } elseif ($zoom === 'fullwidth') {
                $out .= " /OpenAction [{$firstPageObjId} 0 R /FitH null]";
            } elseif ($zoom === 'real') {
                $out .= " /OpenAction [{$firstPageObjId} 0 R /XYZ null null 1]";
            } elseif (is_numeric($zoom) && (float) $zoom > 0) {
                $out .= sprintf(' /OpenAction [%d 0 R /XYZ null null %.3F]', $firstPageObjId, (float) $zoom / 100);
            }
        }
        $layout = match ($this->viewer['layout']) {
            'single' => 'SinglePage',
            'continuous' => 'OneColumn',
            'two', 'twoleft' => 'TwoColumnLeft',
            'tworight' => 'TwoColumnRight',
            default => '',
        };
        return $layout !== '' ? $out . ' /PageLayout /' . $layout : $out;
    }

    /**
     * Percent-encodes non-ASCII and unsafe characters in a URI (PDF URI actions must be 7-bit ASCII).
     */
    private function encodeUri(string $uri): string
    {
        return (string) preg_replace_callback(
            '/[^\x21-\x7E]/',
            static fn(array $m): string => rawurlencode($m[0]),
            $uri
        );
    }

    private function allocObj(): int
    {
        return ++$this->objectCounter;
    }

    private function escapePdfString(string $str): string
    {
        return strtr($str, [
            '\\' => '\\\\',
            '(' => '\(',
            ')' => '\)',
            "\r" => '\r',
            "\n" => '\n',
        ]);
    }

    private function encodeUtf16Hex(string $utf8): string
    {
        $utf16be = @mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
        return '<FEFF' . strtoupper(bin2hex((string) $utf16be)) . '>';
    }
}
