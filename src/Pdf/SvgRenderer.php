<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use Closure;
use DOMElement;
use DOMText;
use DOMXPath;
use IvoPdf\Css\CssColor;
use IvoPdf\Font\FontManager;

/**
 * Renders inline <svg> DOMElements directly into native PDF vector graphics operations.
 *
 * Supports rect, circle, ellipse, line, polyline, polygon, full path commands (M, L, H, V, C, S, Q, T, A, Z),
 * text/tspan/textPath, g/a/switch, nested svg, use/symbol, the transform attribute, preserveAspectRatio, opacity
 * (through an optional ExtGState registrar), stroke styling (dash, cap, join, miter limit), fill-rule,
 * presentation attributes and inline style declarations. With a paint registry it also renders real
 * linear/radial gradients, tiling patterns, clip-path, mask, markers, <style> rules and <image>
 * (see SvgPaintServersTrait / SvgEffectsTrait); without one, gradients fall back to their first stop.
 */
class SvgRenderer
{
    use SvgPaintServersTrait;
    use SvgEffectsTrait;

    private const KAPPA = 0.5522847498;
    private const MAX_USE_DEPTH = 8;
    /** Elements that may be instantiated through <use> in one SVG (chained groups of <use> grow exponentially) */
    private const MAX_USE_INSTANCES = 50000;
    private const NUMBER_RE = '[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** Elements that are never painted directly. */
    private const NON_RENDERED = [
        'defs', 'symbol', 'clippath', 'mask', 'title', 'desc', 'style', 'metadata', 'script',
        'lineargradient', 'radialgradient', 'pattern', 'marker', 'filter', 'stop',
    ];

    /** Properties accepted both as presentation attributes and as inline style declarations. */
    private const STYLE_PROPS = [
        'fill', 'stroke', 'stroke-width', 'color', 'opacity', 'fill-opacity', 'stroke-opacity',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'fill-rule', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-anchor', 'display', 'visibility',
        'clip-rule', 'marker', 'marker-start', 'marker-mid', 'marker-end',
    ];

    /** CSS absolute font-size keywords in px. */
    private const FONT_SIZE_KEYWORDS = [
        'xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0, 'xxx-large' => 48.0,
    ];

    private FontManager $fontManager;

    /** Callable fn(float $alpha): string returning an ExtGState resource name (e.g. 'GS3'). */
    private ?Closure $alphaRegistrar;

    /** @var array<string, DOMElement|null> id => element cache for the SVG being rendered */
    private array $idMap = [];

    private ?DOMElement $rootSvg = null;

    /** Remaining <use> instance budget of the SVG being rendered */
    private int $useBudget = self::MAX_USE_INSTANCES;

    /** @var Closure(string): void|null */
    private ?Closure $warn = null;

    /**
     * Receives a message when an SVG had to be truncated (e.g. exponential <use> expansion).
     *
     * @param Closure(string): void|null $warn
     */
    public function setWarningSink(?Closure $warn): void
    {
        $this->warn = $warn;
    }

    /**
     * @param SvgPaintRegistry|null $paints Enables gradients / patterns / masks (PDF objects registered on the document)
     * @param Closure|null $imageResolver fn(string $src): ?array returning ImageLoader::load() data for <image href>
     */
    public function __construct(FontManager $fontManager, ?Closure $alphaRegistrar = null, ?SvgPaintRegistry $paints = null, ?Closure $imageResolver = null)
    {
        $this->fontManager = $fontManager;
        $this->alphaRegistrar = $alphaRegistrar;
        $this->paints = $paints;
        $this->imageResolver = $imageResolver;
    }

    /**
     * Generates PDF content stream commands to draw an <svg> element inside ($boxX, $boxY, $boxW, $boxH)
     * where $boxY is in top-left document coordinates and $pageHeight is the PDF page height.
     *
     * @param array{size?: float, family?: string, weight?: string, style?: string}|null $inheritedFont font of the
     *        HTML context (size in pt) that inline SVG text inherits
     */
    /**
     * Link areas of the last renderSvg() call (<a href> elements): [x0, y0, x1, y1, href] in top-left document
     * coordinates, read by the page renderer to add /Link annotations.
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float, 4: string}>
     */
    public array $links = [];

    /** @var array{0: float, 1: float, 2: float, 3: float}|null bounding box of what an open <a> draws */
    private ?array $linkBox = null;

    public function renderSvg(
        DOMElement $svg,
        float $boxX,
        float $boxY,
        float $boxW,
        float $boxH,
        float $pageHeight,
        string $inheritedColorHex = '#111827',
        ?array $inheritedFont = null
    ): string {
        if ($boxW <= 0.1 || $boxH <= 0.1) {
            return '';
        }

        $this->rootSvg = $svg;
        $this->links = [];
        $this->linkBox = null;
        $this->useBudget = self::MAX_USE_INSTANCES;
        $this->idMap = [];
        $this->indexIds($svg);
        $this->sink = null;
        $this->patternUsed = false;
        SvgStyleSheet::apply($svg);

        $clip = false;
        $viewBox = $this->parseViewBox($svg);
        if ($viewBox !== null) {
            [$vbX, $vbY, $vbW, $vbH] = $viewBox;
            [$sx, $sy, $tx, $ty, $clip] = $this->viewBoxTransform(
                $vbW,
                $vbH,
                $boxW,
                $boxH,
                (string) $this->attr($svg, 'preserveAspectRatio')
            );
        } else {
            $vbX = 0.0;
            $vbY = 0.0;
            $vbW = $boxW;
            $vbH = $boxH;
            $plain = ['fontSize' => 12.0, 'vpW' => 0.0, 'vpH' => 0.0];
            $wAttr = $this->parseLength((string) $this->attr($svg, 'width'), $plain, 'x');
            $hAttr = $this->parseLength((string) $this->attr($svg, 'height'), $plain, 'y');
            if ($wAttr > 0 && $hAttr > 0) {
                $vbW = $wAttr;
                $vbH = $hAttr;
            }
            // Without a viewBox the content is stretched to the box (legacy behaviour).
            $sx = $boxW / $vbW;
            $sy = $boxH / $vbH;
            $tx = 0.0;
            $ty = 0.0;
        }

        $state = [
            'fill' => '#000000',
            'stroke' => 'none',
            'strokeWidth' => 1.0,
            'currentColor' => $inheritedColorHex,
            'opacity' => 1.0,
            'fillOpacity' => 1.0,
            'strokeOpacity' => 1.0,
            'dashArray' => [],
            'dashOffset' => 0.0,
            'lineCap' => 0,
            'lineJoin' => 0,
            'miterLimit' => 4.0,
            'fillRule' => 'nonzero',
            // Inline <svg> text inherits the font of the HTML context (CSS px = SVG user units), as in browsers
            'fontSize' => isset($inheritedFont['size']) ? (float) $inheritedFont['size'] / 0.75 : 12.0,
            'fontFamily' => $inheritedFont['family'] ?? 'DejaVu Sans',
            'fontWeight' => $inheritedFont['weight'] ?? 'normal',
            'fontStyle' => $inheritedFont['style'] ?? 'normal',
            'textAnchor' => 'start',
            'visible' => true,
            'ctm' => [$sx, 0.0, 0.0, $sy, $boxX + $tx - $vbX * $sx, $boxY + $ty - $vbY * $sy],
            'vpW' => $vbW,
            'vpH' => $vbH,
            'pageHeight' => $pageHeight,
            'useDepth' => 0,
        ];

        $out = '';
        if ($this->applyElementAttributes($svg, $state)) {
            $out = "q\n";
            if ($clip) {
                $out .= sprintf("%.2F %.2F %.2F %.2F re W n\n", $boxX, $pageHeight - $boxY - $boxH, $boxW, $boxH);
            }
            $this->renderChildren($svg, $state, $out);
            $out .= "Q\n";
        }

        $this->idMap = [];
        $this->rootSvg = null;

        return $this->wrapPatternContent($out);
    }

    // ------------------------------------------------------------------
    // Structure / state
    // ------------------------------------------------------------------

    /**
     * Applies presentation attributes, inline style and transform of $el onto $state.
     * Returns false when the element is not rendered (display:none).
     *
     * @param array<string, mixed> $state
     */
    private function applyElementAttributes(DOMElement $el, array &$state): bool
    {
        $props = [];
        foreach (self::STYLE_PROPS as $prop) {
            if ($el->hasAttribute($prop)) {
                $props[$prop] = $el->getAttribute($prop);
            }
        }
        $style = $el->getAttribute('style');
        if ($style !== '') {
            foreach (explode(';', $style) as $decl) {
                $colon = strpos($decl, ':');
                if ($colon === false) {
                    continue;
                }
                $prop = strtolower(trim(substr($decl, 0, $colon)));
                if (in_array($prop, self::STYLE_PROPS, true)) {
                    $props[$prop] = (string) preg_replace('/!\s*important\s*$/i', '', substr($decl, $colon + 1));
                }
            }
        }

        // Font size first: em-based lengths below depend on it.
        if (isset($props['font-size'])) {
            $fs = $this->parseFontSize($props['font-size'], $state);
            if ($fs !== null) {
                $state['fontSize'] = $fs;
            }
        }

        foreach ($props as $prop => $raw) {
            $val = trim($raw);
            $lower = strtolower($val);
            if ($val === '' || $lower === 'inherit' || $prop === 'font-size') {
                continue;
            }
            switch ($prop) {
                case 'display':
                    if ($lower === 'none') {
                        return false;
                    }
                    break;
                case 'visibility':
                    $state['visible'] = !in_array($lower, ['hidden', 'collapse'], true);
                    break;
                case 'fill':
                    $state['fill'] = $val;
                    break;
                case 'stroke':
                    $state['stroke'] = $val;
                    break;
                case 'color':
                    if ($lower !== 'currentcolor') {
                        $state['currentColor'] = $val;
                    }
                    break;
                case 'stroke-width':
                    $state['strokeWidth'] = max(0.0, $this->parseLength($val, $state, 'd'));
                    break;
                case 'opacity':
                    $state['opacity'] = (float) $state['opacity'] * $this->parseOpacity($val);
                    break;
                case 'fill-opacity':
                    $state['fillOpacity'] = $this->parseOpacity($val);
                    break;
                case 'stroke-opacity':
                    $state['strokeOpacity'] = $this->parseOpacity($val);
                    break;
                case 'stroke-dasharray':
                    $state['dashArray'] = $this->parseDashArray($val, $state);
                    break;
                case 'stroke-dashoffset':
                    $state['dashOffset'] = $this->parseLength($val, $state, 'd');
                    break;
                case 'stroke-linecap':
                    $state['lineCap'] = match ($lower) {
                        'round' => 1,
                        'square' => 2,
                        default => 0,
                    };
                    break;
                case 'stroke-linejoin':
                    $state['lineJoin'] = match ($lower) {
                        'round' => 1,
                        'bevel' => 2,
                        default => 0,
                    };
                    break;
                case 'stroke-miterlimit':
                    $state['miterLimit'] = max(1.0, (float) $val);
                    break;
                case 'fill-rule':
                    $state['fillRule'] = $lower === 'evenodd' ? 'evenodd' : 'nonzero';
                    break;
                case 'font-family':
                    $state['fontFamily'] = $val;
                    break;
                case 'font-weight':
                    $state['fontWeight'] = $lower;
                    break;
                case 'font-style':
                    $state['fontStyle'] = $lower;
                    break;
                case 'text-anchor':
                    $state['textAnchor'] = $lower;
                    break;
                case 'clip-rule':
                    $state['clipRule'] = $lower === 'evenodd' ? 'evenodd' : 'nonzero';
                    break;
                case 'marker':
                    $state['markerStart'] = $state['markerMid'] = $state['markerEnd'] = $val;
                    break;
                case 'marker-start':
                    $state['markerStart'] = $val;
                    break;
                case 'marker-mid':
                    $state['markerMid'] = $val;
                    break;
                case 'marker-end':
                    $state['markerEnd'] = $val;
                    break;
            }
        }

        $transform = $this->attr($el, 'transform');
        if (!empty($state['noTransformOnce'])) {
            unset($state['noTransformOnce']); // bounding-box probing: the element's own transform is excluded
        } elseif ($transform !== null && trim($transform) !== '') {
            $state['ctm'] = $this->multiply($state['ctm'], $this->parseTransform($transform));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderChildren(DOMElement $parent, array $state, string &$out): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->renderNode($child, $state, $out);
            }
        }
    }

    /**
     * Renders a single element (and its subtree) using the parent's inherited state.
     *
     * @param array<string, mixed> $state
     */
    private function renderNode(DOMElement $el, array $state, string &$out): void
    {
        \IvoPdf\RenderLimits::tick();
        $tag = $this->tagOf($el);
        if (in_array($tag, self::NON_RENDERED, true)) {
            return;
        }
        if ((int) ($state['useDepth'] ?? 0) > 0 && --$this->useBudget < 0) {
            if ($this->useBudget === -1 && $this->warn !== null) {
                ($this->warn)('SVG <use> expansion exceeded ' . self::MAX_USE_INSTANCES . ' elements and was truncated.');
            }
            return;
        }

        $s = $state;
        if (!$this->applyElementAttributes($el, $s)) {
            return;
        }

        $fxOpen = $this->openEffects($el, $s, $out);
        $this->lastPath = null;
        switch ($tag) {
            case 'a':
                $href = trim((string) ($el->getAttribute('href') !== '' ? $el->getAttribute('href') : $el->getAttributeNS('http://www.w3.org/1999/xlink', 'href')));
                if ($href === '' && $el->hasAttribute('xlink:href')) {
                    $href = trim($el->getAttribute('xlink:href'));
                }
                $outer = $this->linkBox;
                $this->linkBox = [INF, INF, -INF, -INF];
                $this->renderChildren($el, $s, $out);
                $box = $this->linkBox;
                $this->linkBox = $outer;
                if ($box[0] < $box[2] && $box[1] < $box[3]) {
                    if ($href !== '') {
                        $this->links[] = [$box[0], $box[1], $box[2], $box[3], $href];
                    }
                    if ($outer !== null) {
                        $this->extendLinkBox($box[0], $box[1]);
                        $this->extendLinkBox($box[2], $box[3]);
                    }
                }
                break;
            case 'g':
            case 'switch':
                $this->renderChildren($el, $s, $out);
                break;
            case 'image':
                $this->drawImage($el, $s, $out);
                break;
            case 'svg':
                $this->renderNestedSvg($el, $s, $out);
                break;
            case 'use':
                $this->renderUse($el, $s, $out);
                break;
            case 'rect':
                $this->drawRect($el, $s, $out);
                break;
            case 'circle':
                $this->drawCircle($el, $s, $out);
                break;
            case 'ellipse':
                $this->drawEllipse($el, $s, $out);
                break;
            case 'line':
                $this->drawLine($el, $s, $out);
                break;
            case 'polyline':
            case 'polygon':
                $this->drawPoly($el, $s, $tag === 'polygon', $out);
                break;
            case 'path':
                $this->drawPath($el, $s, $out);
                break;
            case 'text':
                $this->drawText($el, $s, $out);
                break;
        }
        $this->closeEffects($el, $tag, $s, $fxOpen, $out);
    }

    /**
     * Nested <svg>: establishes a new viewport at x/y with its own optional viewBox.
     *
     * @param array<string, mixed> $s
     */
    private function renderNestedSvg(DOMElement $el, array $s, string &$out): void
    {
        $x = $this->lengthAttr($el, 'x', $s, 'x');
        $y = $this->lengthAttr($el, 'y', $s, 'y');
        $w = $this->firstLength($el, 'width', $s, 'x') ?? (float) $s['vpW'];
        $h = $this->firstLength($el, 'height', $s, 'y') ?? (float) $s['vpH'];
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $s['ctm'] = $this->multiply($s['ctm'], [1.0, 0.0, 0.0, 1.0, $x, $y]);
        $vb = $this->parseViewBox($el);
        if ($vb !== null) {
            [$sx, $sy, $tx, $ty] = $this->viewBoxTransform($vb[2], $vb[3], $w, $h, (string) $this->attr($el, 'preserveAspectRatio'));
            $s['ctm'] = $this->multiply($s['ctm'], [$sx, 0.0, 0.0, $sy, $tx - $vb[0] * $sx, $ty - $vb[1] * $sy]);
            $s['vpW'] = $vb[2];
            $s['vpH'] = $vb[3];
        } else {
            $s['vpW'] = $w;
            $s['vpH'] = $h;
        }

        $this->renderChildren($el, $s, $out);
    }

    /**
     * <use href="#id" x y>: renders the referenced element (or <symbol> contents) with the use element's state.
     *
     * @param array<string, mixed> $s
     */
    private function renderUse(DOMElement $el, array $s, string &$out): void
    {
        if ((int) $s['useDepth'] >= self::MAX_USE_DEPTH) {
            return;
        }
        $href = $this->hrefOf($el);
        if ($href === null || !str_starts_with($href, '#')) {
            return;
        }
        $ref = $this->findById(substr($href, 1));
        if ($ref === null) {
            return;
        }
        // Refuse references to an ancestor (or self): that would recurse forever.
        for ($node = $el; $node !== null; $node = $node->parentNode) {
            if ($node->isSameNode($ref)) {
                return;
            }
        }

        $x = $this->lengthAttr($el, 'x', $s, 'x');
        $y = $this->lengthAttr($el, 'y', $s, 'y');
        $s['ctm'] = $this->multiply($s['ctm'], [1.0, 0.0, 0.0, 1.0, $x, $y]);
        $s['useDepth'] = (int) $s['useDepth'] + 1;

        if ($this->tagOf($ref) !== 'symbol') {
            $this->renderNode($ref, $s, $out);
            return;
        }

        $sym = $s;
        if (!$this->applyElementAttributes($ref, $sym)) {
            return;
        }
        $vb = $this->parseViewBox($ref);
        if ($vb !== null) {
            $w = $this->firstLength($el, 'width', $s, 'x') ?? (float) $s['vpW'];
            $h = $this->firstLength($el, 'height', $s, 'y') ?? (float) $s['vpH'];
            if ($w > 0 && $h > 0) {
                [$sx, $sy, $tx, $ty] = $this->viewBoxTransform($vb[2], $vb[3], $w, $h, (string) $this->attr($ref, 'preserveAspectRatio'));
                $sym['ctm'] = $this->multiply($sym['ctm'], [$sx, 0.0, 0.0, $sy, $tx - $vb[0] * $sx, $ty - $vb[1] * $sy]);
                $sym['vpW'] = $vb[2];
                $sym['vpH'] = $vb[3];
            }
        }
        $this->renderChildren($ref, $sym, $out);
    }

    // ------------------------------------------------------------------
    // Geometry helpers
    // ------------------------------------------------------------------

    /**
     * Converts SVG user coordinate (sx, sy) to PDF page coordinate (px, py) through the current matrix.
     *
     * @param array<string, mixed> $s
     * @return array{0: float, 1: float}
     */
    private function toPdfCoord(float $sx, float $sy, array $s): array
    {
        $m = $s['ctm'];
        $docX = $m[0] * $sx + $m[2] * $sy + $m[4];
        $docY = $m[1] * $sx + $m[3] * $sy + $m[5];
        if ($this->linkBox !== null) {
            $this->extendLinkBox($docX, $docY);
        }
        return [$docX, (float) $s['pageHeight'] - $docY];
    }

    private function extendLinkBox(float $x, float $y): void
    {
        if ($this->linkBox !== null) {
            $this->linkBox = [min($this->linkBox[0], $x), min($this->linkBox[1], $y), max($this->linkBox[2], $x), max($this->linkBox[3], $y)];
        }
    }

    /**
     * Adds the box of a text run (baseline at user-space x, y) to the open <a> area.
     *
     * @param array<string, mixed> $s
     */
    private function linkTextBox(string $text, array $s, float $x, float $y): void
    {
        if ($this->linkBox === null) {
            return;
        }
        $fs = (float) $s['fontSize'];
        $w = $this->fontManager->measureText($text, (string) $s['fontFamily'], (string) $s['fontWeight'], (string) $s['fontStyle'], $fs);
        $m = $s['ctm'];
        foreach ([[$x, $y - 0.8 * $fs], [$x + $w, $y - 0.8 * $fs], [$x, $y + 0.25 * $fs], [$x + $w, $y + 0.25 * $fs]] as [$px, $py]) {
            $this->extendLinkBox($m[0] * $px + $m[2] * $py + $m[4], $m[1] * $px + $m[3] * $py + $m[5]);
        }
    }

    /**
     * @param array<string, mixed> $s
     */
    private function pt(float $x, float $y, array $s): string
    {
        [$px, $py] = $this->toPdfCoord($x, $y, $s);
        return sprintf('%.2F %.2F', $px, $py);
    }

    /**
     * @param array<string, mixed> $s
     */
    private function curve(float $x1, float $y1, float $x2, float $y2, float $x, float $y, array $s): string
    {
        return $this->pt($x1, $y1, $s) . ' ' . $this->pt($x2, $y2, $s) . ' ' . $this->pt($x, $y, $s) . " c\n";
    }

    /**
     * Composes two affine matrices [a b c d e f]: the result applies $t first, then $p.
     *
     * @param array<int, float> $p
     * @param array<int, float> $t
     * @return array<int, float>
     */
    private function multiply(array $p, array $t): array
    {
        return [
            $p[0] * $t[0] + $p[2] * $t[1],
            $p[1] * $t[0] + $p[3] * $t[1],
            $p[0] * $t[2] + $p[2] * $t[3],
            $p[1] * $t[2] + $p[3] * $t[3],
            $p[0] * $t[4] + $p[2] * $t[5] + $p[4],
            $p[1] * $t[4] + $p[3] * $t[5] + $p[5],
        ];
    }

    /**
     * Uniform scale factor of the current matrix (used for stroke widths and dash lengths).
     *
     * @param array<string, mixed> $s
     */
    private function matrixScale(array $s): float
    {
        $m = $s['ctm'];
        return sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
    }

    /**
     * Parses an SVG transform list (applied left to right) into a single matrix.
     *
     * @return array<int, float>
     */
    private function parseTransform(string $value): array
    {
        $m = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        if (!preg_match_all('/(matrix|translate|scale|rotate|skewx|skewy)\s*\(([^)]*)\)/i', $value, $fns, PREG_SET_ORDER)) {
            return $m;
        }

        foreach ($fns as $fn) {
            $a = $this->parseNumberList($fn[2]);
            $n = count($a);
            if ($n === 0) {
                continue;
            }
            $t = null;
            switch (strtolower($fn[1])) {
                case 'matrix':
                    if ($n >= 6) {
                        $t = [$a[0], $a[1], $a[2], $a[3], $a[4], $a[5]];
                    }
                    break;
                case 'translate':
                    $t = [1.0, 0.0, 0.0, 1.0, $a[0], $a[1] ?? 0.0];
                    break;
                case 'scale':
                    $t = [$a[0], 0.0, 0.0, $a[1] ?? $a[0], 0.0, 0.0];
                    break;
                case 'rotate':
                    $rad = deg2rad($a[0]);
                    $cos = cos($rad);
                    $sin = sin($rad);
                    $cx = $n >= 3 ? $a[1] : 0.0;
                    $cy = $n >= 3 ? $a[2] : 0.0;
                    $t = [$cos, $sin, -$sin, $cos, $cx - $cos * $cx + $sin * $cy, $cy - $sin * $cx - $cos * $cy];
                    break;
                case 'skewx':
                    $t = [1.0, 0.0, tan(deg2rad($a[0])), 1.0, 0.0, 0.0];
                    break;
                case 'skewy':
                    $t = [1.0, tan(deg2rad($a[0])), 0.0, 1.0, 0.0, 0.0];
                    break;
            }
            if ($t !== null) {
                $m = $this->multiply($m, $t);
            }
        }

        return $m;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null [minX, minY, width, height]
     */
    private function parseViewBox(DOMElement $el): ?array
    {
        $raw = $this->attr($el, 'viewBox');
        if ($raw === null) {
            return null;
        }
        $parts = $this->parseNumberList($raw);
        if (count($parts) < 4 || $parts[2] <= 0 || $parts[3] <= 0) {
            return null;
        }
        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }

    /**
     * Computes the viewBox → viewport mapping for preserveAspectRatio.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: bool} [scaleX, scaleY, offsetX, offsetY, needsClip]
     */
    private function viewBoxTransform(float $vbW, float $vbH, float $boxW, float $boxH, string $par): array
    {
        $sx = $boxW / $vbW;
        $sy = $boxH / $vbH;

        $parts = preg_split('/\s+/', strtolower(trim($par)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (($parts[0] ?? '') === 'defer') {
            array_shift($parts);
        }
        $align = $parts[0] ?? 'xmidymid';
        if ($align === 'none') {
            return [$sx, $sy, 0.0, 0.0, false];
        }
        if (!preg_match('/^x(min|mid|max)y(min|mid|max)$/', $align, $m)) {
            $m = ['', 'mid', 'mid'];
        }
        $slice = ($parts[1] ?? 'meet') === 'slice';
        $scale = $slice ? max($sx, $sy) : min($sx, $sy);

        $freeX = $boxW - $vbW * $scale;
        $freeY = $boxH - $vbH * $scale;
        $tx = match ($m[1]) {
            'min' => 0.0,
            'max' => $freeX,
            default => $freeX / 2.0,
        };
        $ty = match ($m[2]) {
            'min' => 0.0,
            'max' => $freeY,
            default => $freeY / 2.0,
        };

        return [$scale, $scale, $tx, $ty, $slice];
    }

    // ------------------------------------------------------------------
    // Painting
    // ------------------------------------------------------------------

    /**
     * Paints a constructed path with the element's fill and stroke (colors, alpha, stroke style, fill rule).
     *
     * @param array<string, mixed> $s
     */
    private function paint(string $path, array $s, bool $closed, string &$out, bool $allowFill = true): void
    {
        if ($path === '' || !$s['visible']) {
            return;
        }
        $this->lastPath = $path;
        if ($this->sink !== null) { // bounding-box / clipPath probing: record the geometry instead of painting
            $this->sink[] = [$path, (string) ($s['clipRule'] ?? 'nonzero')];
            return;
        }
        if ($this->usesServerPaint($s, $allowFill)) { // gradients / patterns
            $this->paintWithServers($path, $s, $closed, $out, $allowFill);
            return;
        }

        $fill = $allowFill ? $this->resolvePaint((string) $s['fill'], $s) : null;
        $stroke = ((float) $s['strokeWidth'] > 0) ? $this->resolvePaint((string) $s['stroke'], $s) : null;
        $fillAlpha = $fill !== null ? $fill[3] * (float) $s['fillOpacity'] * (float) $s['opacity'] : 0.0;
        $strokeAlpha = $stroke !== null ? $stroke[3] * (float) $s['strokeOpacity'] * (float) $s['opacity'] : 0.0;
        if ($fillAlpha <= 0.001) {
            $fill = null;
        }
        if ($strokeAlpha <= 0.001) {
            $stroke = null;
        }
        if ($fill === null && $stroke === null) {
            return;
        }

        $evenOdd = $s['fillRule'] === 'evenodd';
        $fillGs = $fill !== null ? $this->alphaState($fillAlpha) : null;
        $strokeGs = $stroke !== null ? $this->alphaState($strokeAlpha) : null;
        $fillOps = $fill !== null ? ColorOps::fillOp($fill) . "\n" : '';
        $strokeOps = $stroke !== null ? $this->strokeStyleOps($stroke, $s) : '';

        if ($fill !== null && $stroke !== null && $fillGs !== $strokeGs) {
            // Different fill/stroke alpha: paint in two passes, each with its own ExtGState.
            $out .= "q\n" . ($fillGs !== null ? "/{$fillGs} gs\n" : '') . $fillOps . $path . ($evenOdd ? "f*\n" : "f\n") . "Q\n";
            $out .= "q\n" . ($strokeGs !== null ? "/{$strokeGs} gs\n" : '') . $strokeOps . $path . ($closed ? "s\n" : "S\n") . "Q\n";
            return;
        }

        $gs = $fill !== null ? $fillGs : $strokeGs;
        $wrap = $stroke !== null || $gs !== null;
        if ($fill !== null && $stroke !== null) {
            $op = $closed ? 'b' : 'B';
            $op .= $evenOdd ? '*' : '';
        } elseif ($fill !== null) {
            $op = $evenOdd ? 'f*' : 'f';
        } else {
            $op = $closed ? 's' : 'S';
        }

        $out .= ($wrap ? "q\n" : '') . ($gs !== null ? "/{$gs} gs\n" : '') . $fillOps . $strokeOps . $path . $op . "\n" . ($wrap ? "Q\n" : '');
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     * @param array<string, mixed> $s
     */
    private function strokeStyleOps(array $color, array $s): string
    {
        $scale = $this->matrixScale($s);
        $sw = max(0.25, (float) $s['strokeWidth'] * $scale);

        $dash = '[] 0 d';
        /** @var list<float> $dashArray */
        $dashArray = $s['dashArray'];
        if ($dashArray !== []) {
            $total = array_sum($dashArray) * $scale;
            if ($total > 0.01) {
                $vals = array_map(static fn (float $v): string => sprintf('%.2F', $v * $scale), $dashArray);
                $phase = fmod((float) $s['dashOffset'] * $scale, $total);
                if ($phase < 0) {
                    $phase += $total;
                }
                $dash = '[' . implode(' ', $vals) . '] ' . sprintf('%.2F', $phase) . ' d';
            }
        }

        return sprintf(
            "%.2F w %d J %d j %.2F M %s\n%s\n",
            $sw,
            (int) $s['lineCap'],
            (int) $s['lineJoin'],
            (float) $s['miterLimit'],
            $dash,
            ColorOps::strokeOp($color)
        );
    }

    /**
     * Returns the ExtGState name for a non-opaque alpha, or null when opaque / no registrar is available.
     */
    private function alphaState(float $alpha): ?string
    {
        if ($this->alphaRegistrar === null || $alpha >= 0.999) {
            return null;
        }
        return (string) ($this->alphaRegistrar)(max(0.0, min(1.0, $alpha)));
    }

    /**
     * Resolves a fill/stroke paint value to [r, g, b, a] or null for none.
     * url(#gradient) falls back to the gradient's first stop color.
     *
     * @param array<string, mixed> $s
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function resolvePaint(string $value, array $s): ?array
    {
        $val = trim($value);
        $lower = strtolower($val);
        if ($lower === '' || $lower === 'none') {
            return null;
        }
        if (str_starts_with($lower, 'url(')) {
            if (!preg_match('/^url\(\s*[\'"]?#([^\'")\s]+)[\'"]?\s*\)\s*(.*)$/i', $val, $m)) {
                return null;
            }
            $ref = $this->findById($m[1]);
            if ($ref !== null && in_array($this->tagOf($ref), ['lineargradient', 'radialgradient'], true)) {
                return $this->gradientFallback($ref, $s, 0);
            }
            $fallback = trim($m[2]);
            return ($fallback !== '' && !str_starts_with(strtolower($fallback), 'url(')) ? $this->resolvePaint($fallback, $s) : null;
        }
        if ($lower === 'currentcolor') {
            $val = (string) $s['currentColor'];
        }
        return CssColor::parse($val);
    }

    /**
     * First <stop> color (with stop-opacity) of a gradient, following one href hop when it has no stops.
     *
     * @param array<string, mixed> $s
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function gradientFallback(DOMElement $grad, array $s, int $depth): ?array
    {
        foreach ($grad->childNodes as $child) {
            if (!$child instanceof DOMElement || $this->tagOf($child) !== 'stop') {
                continue;
            }
            $color = $child->hasAttribute('stop-color') ? $child->getAttribute('stop-color') : 'black';
            $opacity = $child->hasAttribute('stop-opacity') ? $child->getAttribute('stop-opacity') : '1';
            foreach (explode(';', $child->getAttribute('style')) as $decl) {
                $colon = strpos($decl, ':');
                if ($colon === false) {
                    continue;
                }
                $prop = strtolower(trim(substr($decl, 0, $colon)));
                if ($prop === 'stop-color') {
                    $color = substr($decl, $colon + 1);
                } elseif ($prop === 'stop-opacity') {
                    $opacity = substr($decl, $colon + 1);
                }
            }
            $color = trim($color);
            if (strtolower($color) === 'currentcolor') {
                $color = (string) $s['currentColor'];
            }
            $rgba = CssColor::parse($color);
            if ($rgba === null) {
                return null;
            }
            $rgba[3] *= $this->parseOpacity($opacity);
            return $rgba[3] > 0.001 ? $rgba : null;
        }

        if ($depth === 0) {
            $href = $this->hrefOf($grad);
            if ($href !== null && str_starts_with($href, '#')) {
                $ref = $this->findById(substr($href, 1));
                if ($ref !== null && !$ref->isSameNode($grad) && in_array($this->tagOf($ref), ['lineargradient', 'radialgradient'], true)) {
                    return $this->gradientFallback($ref, $s, 1);
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Shapes
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $s
     */
    private function drawRect(DOMElement $el, array $s, string &$out): void
    {
        $x = $this->lengthAttr($el, 'x', $s, 'x');
        $y = $this->lengthAttr($el, 'y', $s, 'y');
        $w = $this->lengthAttr($el, 'width', $s, 'x');
        $h = $this->lengthAttr($el, 'height', $s, 'y');
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $rx = $this->radiusAttr($el, 'rx', $s, 'x');
        $ry = $this->radiusAttr($el, 'ry', $s, 'y');
        $rx ??= $ry ?? 0.0;
        $ry ??= $rx;
        $rx = min($rx, $w / 2.0);
        $ry = min($ry, $h / 2.0);

        if ($rx > 1e-6 && $ry > 1e-6) {
            $kx = self::KAPPA * $rx;
            $ky = self::KAPPA * $ry;
            $x2 = $x + $w;
            $y2 = $y + $h;
            $path = $this->pt($x + $rx, $y, $s) . " m\n";
            $path .= $this->pt($x2 - $rx, $y, $s) . " l\n";
            $path .= $this->curve($x2 - $rx + $kx, $y, $x2, $y + $ry - $ky, $x2, $y + $ry, $s);
            $path .= $this->pt($x2, $y2 - $ry, $s) . " l\n";
            $path .= $this->curve($x2, $y2 - $ry + $ky, $x2 - $rx + $kx, $y2, $x2 - $rx, $y2, $s);
            $path .= $this->pt($x + $rx, $y2, $s) . " l\n";
            $path .= $this->curve($x + $rx - $kx, $y2, $x, $y2 - $ry + $ky, $x, $y2 - $ry, $s);
            $path .= $this->pt($x, $y + $ry, $s) . " l\n";
            $path .= $this->curve($x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y, $s);
            $path .= "h\n";
        } elseif (abs($s['ctm'][1]) < 1e-9 && abs($s['ctm'][2]) < 1e-9) {
            [$x1p, $y1p] = $this->toPdfCoord($x, $y, $s);
            [$x2p, $y2p] = $this->toPdfCoord($x + $w, $y + $h, $s);
            $path = sprintf("%.2F %.2F %.2F %.2F re\n", min($x1p, $x2p), min($y1p, $y2p), abs($x2p - $x1p), abs($y2p - $y1p));
        } else {
            $path = $this->pt($x, $y, $s) . " m\n"
                . $this->pt($x + $w, $y, $s) . " l\n"
                . $this->pt($x + $w, $y + $h, $s) . " l\n"
                . $this->pt($x, $y + $h, $s) . " l\nh\n";
        }

        $this->paint($path, $s, true, $out);
    }

    /**
     * @param array<string, mixed> $s
     */
    private function drawCircle(DOMElement $el, array $s, string &$out): void
    {
        $r = $this->lengthAttr($el, 'r', $s, 'd');
        if ($r <= 0) {
            return;
        }
        $cx = $this->lengthAttr($el, 'cx', $s, 'x');
        $cy = $this->lengthAttr($el, 'cy', $s, 'y');
        $this->paint($this->ellipsePath($cx, $cy, $r, $r, $s), $s, true, $out);
    }

    /**
     * @param array<string, mixed> $s
     */
    private function drawEllipse(DOMElement $el, array $s, string &$out): void
    {
        $rx = $this->radiusAttr($el, 'rx', $s, 'x');
        $ry = $this->radiusAttr($el, 'ry', $s, 'y');
        $rx ??= $ry;
        $ry ??= $rx;
        if ($rx === null || $ry === null || $rx <= 0 || $ry <= 0) {
            return;
        }
        $cx = $this->lengthAttr($el, 'cx', $s, 'x');
        $cy = $this->lengthAttr($el, 'cy', $s, 'y');
        $this->paint($this->ellipsePath($cx, $cy, $rx, $ry, $s), $s, true, $out);
    }

    /**
     * Builds an ellipse as four cubic Béziers in user space (every control point goes through the matrix).
     *
     * @param array<string, mixed> $s
     */
    private function ellipsePath(float $cx, float $cy, float $rx, float $ry, array $s): string
    {
        $kx = self::KAPPA * $rx;
        $ky = self::KAPPA * $ry;

        $path = $this->pt($cx + $rx, $cy, $s) . " m\n";
        $path .= $this->curve($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry, $s);
        $path .= $this->curve($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy, $s);
        $path .= $this->curve($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry, $s);
        $path .= $this->curve($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy, $s);
        return $path . "h\n";
    }

    /**
     * @param array<string, mixed> $s
     */
    private function drawLine(DOMElement $el, array $s, string &$out): void
    {
        $x1 = $this->lengthAttr($el, 'x1', $s, 'x');
        $y1 = $this->lengthAttr($el, 'y1', $s, 'y');
        $x2 = $this->lengthAttr($el, 'x2', $s, 'x');
        $y2 = $this->lengthAttr($el, 'y2', $s, 'y');

        $path = $this->pt($x1, $y1, $s) . ' m ' . $this->pt($x2, $y2, $s) . " l\n";
        $this->paint($path, $s, false, $out, false);
    }

    /**
     * @param array<string, mixed> $s
     */
    private function drawPoly(DOMElement $el, array $s, bool $close, string &$out): void
    {
        $nums = $this->parseNumberList($el->getAttribute('points'));
        $count = count($nums);
        if ($count < 4) {
            return;
        }

        $path = '';
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $path .= $this->pt($nums[$i], $nums[$i + 1], $s) . ($i === 0 ? " m\n" : " l\n");
        }
        if ($close) {
            $path .= "h\n";
        }
        $this->paint($path, $s, $close, $out);
    }

    /**
     * Parses SVG <path d="..."> commands (M, m, L, l, H, h, V, v, C, c, S, s, Q, q, T, t, A, a, Z, z)
     * into PDF path operators.
     *
     * @param array<string, mixed> $s
     */
    private function drawPath(DOMElement $el, array $s, string &$out): void
    {
        [$path, $hasSubpath, $isClosed] = $this->buildPathData(trim($el->getAttribute('d')), $s);
        if ($hasSubpath) {
            $this->paint($path, $s, $isClosed, $out);
        }
    }

    /**
     * Converts SVG path data into PDF path operators through the current matrix.
     *
     * @param array<string, mixed> $s
     * @return array{0: string, 1: bool, 2: bool} operators, has a subpath, last subpath closed
     */
    private function buildPathData(string $d, array $s): array
    {
        if ($d === '' || !preg_match_all('/[MmLlHhVvCcSsQqTtAaZz]|' . self::NUMBER_RE . '/', $d, $matches)) {
            return ['', false, false];
        }

        $tokens = $matches[0];
        $n = count($tokens);
        $i = 0;
        $cmd = 'M';
        $curX = 0.0;
        $curY = 0.0;
        $startX = 0.0;
        $startY = 0.0;
        $lastCtrlX = 0.0;
        $lastCtrlY = 0.0;
        $prevCmd = '';
        $hasSubpath = false;
        $isClosed = false;
        $path = '';

        while ($i < $n) {
            $tok = $tokens[$i];
            if (ctype_alpha($tok)) {
                $cmd = $tok;
                $i++;
                if ($cmd === 'Z' || $cmd === 'z') {
                    $path .= "h\n";
                    $curX = $startX;
                    $curY = $startY;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $prevCmd = $cmd;
                    $isClosed = true;
                    continue;
                }
            }

            switch ($cmd) {
                case 'M':
                case 'm':
                    if (!$this->hasNumbers($tokens, $i, 2)) {
                        break 2;
                    }
                    $nx = (float) $tokens[$i++];
                    $ny = (float) $tokens[$i++];
                    $curX = ($cmd === 'm') ? ($curX + $nx) : $nx;
                    $curY = ($cmd === 'm') ? ($curY + $ny) : $ny;
                    $startX = $curX;
                    $startY = $curY;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $path .= $this->pt($curX, $curY, $s) . " m\n";
                    $hasSubpath = true;
                    $isClosed = false;
                    $prevCmd = $cmd;
                    $cmd = ($cmd === 'm') ? 'l' : 'L';
                    break;

                case 'L':
                case 'l':
                    if (!$this->hasNumbers($tokens, $i, 2)) {
                        break 2;
                    }
                    $nx = (float) $tokens[$i++];
                    $ny = (float) $tokens[$i++];
                    $curX = ($cmd === 'l') ? ($curX + $nx) : $nx;
                    $curY = ($cmd === 'l') ? ($curY + $ny) : $ny;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->pt($curX, $curY, $s) . " l\n";
                    break;

                case 'H':
                case 'h':
                    if (!$this->hasNumbers($tokens, $i, 1)) {
                        break 2;
                    }
                    $nx = (float) $tokens[$i++];
                    $curX = ($cmd === 'h') ? ($curX + $nx) : $nx;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->pt($curX, $curY, $s) . " l\n";
                    break;

                case 'V':
                case 'v':
                    if (!$this->hasNumbers($tokens, $i, 1)) {
                        break 2;
                    }
                    $ny = (float) $tokens[$i++];
                    $curY = ($cmd === 'v') ? ($curY + $ny) : $ny;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->pt($curX, $curY, $s) . " l\n";
                    break;

                case 'C':
                case 'c':
                    if (!$this->hasNumbers($tokens, $i, 6)) {
                        break 2;
                    }
                    $x1 = (float) $tokens[$i++];
                    $y1 = (float) $tokens[$i++];
                    $x2 = (float) $tokens[$i++];
                    $y2 = (float) $tokens[$i++];
                    $x = (float) $tokens[$i++];
                    $y = (float) $tokens[$i++];
                    if ($cmd === 'c') {
                        $x1 += $curX;
                        $y1 += $curY;
                        $x2 += $curX;
                        $y2 += $curY;
                        $x += $curX;
                        $y += $curY;
                    }
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $curX = $x;
                    $curY = $y;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->curve($x1, $y1, $x2, $y2, $x, $y, $s);
                    break;

                case 'S':
                case 's':
                    if (!$this->hasNumbers($tokens, $i, 4)) {
                        break 2;
                    }
                    $x2 = (float) $tokens[$i++];
                    $y2 = (float) $tokens[$i++];
                    $x = (float) $tokens[$i++];
                    $y = (float) $tokens[$i++];
                    if ($cmd === 's') {
                        $x2 += $curX;
                        $y2 += $curY;
                        $x += $curX;
                        $y += $curY;
                    }
                    if (in_array($prevCmd, ['C', 'c', 'S', 's'], true)) {
                        $x1 = 2.0 * $curX - $lastCtrlX;
                        $y1 = 2.0 * $curY - $lastCtrlY;
                    } else {
                        $x1 = $curX;
                        $y1 = $curY;
                    }
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $curX = $x;
                    $curY = $y;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->curve($x1, $y1, $x2, $y2, $x, $y, $s);
                    break;

                case 'Q':
                case 'q':
                    if (!$this->hasNumbers($tokens, $i, 4)) {
                        break 2;
                    }
                    $qx = (float) $tokens[$i++];
                    $qy = (float) $tokens[$i++];
                    $x = (float) $tokens[$i++];
                    $y = (float) $tokens[$i++];
                    if ($cmd === 'q') {
                        $qx += $curX;
                        $qy += $curY;
                        $x += $curX;
                        $y += $curY;
                    }
                    $cx1 = $curX + (2.0 / 3.0) * ($qx - $curX);
                    $cy1 = $curY + (2.0 / 3.0) * ($qy - $curY);
                    $cx2 = $x + (2.0 / 3.0) * ($qx - $x);
                    $cy2 = $y + (2.0 / 3.0) * ($qy - $y);
                    $lastCtrlX = $qx;
                    $lastCtrlY = $qy;
                    $curX = $x;
                    $curY = $y;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->curve($cx1, $cy1, $cx2, $cy2, $x, $y, $s);
                    break;

                case 'T':
                case 't':
                    if (!$this->hasNumbers($tokens, $i, 2)) {
                        break 2;
                    }
                    $x = (float) $tokens[$i++];
                    $y = (float) $tokens[$i++];
                    if ($cmd === 't') {
                        $x += $curX;
                        $y += $curY;
                    }
                    if (in_array($prevCmd, ['Q', 'q', 'T', 't'], true)) {
                        $qx = 2.0 * $curX - $lastCtrlX;
                        $qy = 2.0 * $curY - $lastCtrlY;
                    } else {
                        $qx = $curX;
                        $qy = $curY;
                    }
                    $cx1 = $curX + (2.0 / 3.0) * ($qx - $curX);
                    $cy1 = $curY + (2.0 / 3.0) * ($qy - $curY);
                    $cx2 = $x + (2.0 / 3.0) * ($qx - $x);
                    $cy2 = $y + (2.0 / 3.0) * ($qy - $y);
                    $lastCtrlX = $qx;
                    $lastCtrlY = $qy;
                    $curX = $x;
                    $curY = $y;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    $path .= $this->curve($cx1, $cy1, $cx2, $cy2, $x, $y, $s);
                    break;

                case 'A':
                case 'a':
                    if (!$this->hasNumbers($tokens, $i, 3)) {
                        break 2;
                    }
                    $rx = abs((float) $tokens[$i++]);
                    $ry = abs((float) $tokens[$i++]);
                    $xAxisRotation = (float) $tokens[$i++];
                    $largeArcFlag = $this->takeArcFlag($tokens, $i, $n);
                    $sweepFlag = $this->takeArcFlag($tokens, $i, $n);
                    if ($largeArcFlag === null || $sweepFlag === null || !$this->hasNumbers($tokens, $i, 2)) {
                        break 2;
                    }
                    $x = (float) $tokens[$i++];
                    $y = (float) $tokens[$i++];
                    if ($cmd === 'a') {
                        $x += $curX;
                        $y += $curY;
                    }
                    $this->emitArcAsCubicBeziers($curX, $curY, $rx, $ry, $xAxisRotation, $largeArcFlag, $sweepFlag, $x, $y, $s, $path);
                    $curX = $x;
                    $curY = $y;
                    $lastCtrlX = $curX;
                    $lastCtrlY = $curY;
                    $prevCmd = $cmd;
                    $isClosed = false;
                    break;

                default:
                    $i++;
                    break;
            }
        }

        return [$path, $hasSubpath, $isClosed];
    }

    /**
     * True when $count numeric tokens are available starting at $i.
     *
     * @param list<string> $tokens
     */
    private function hasNumbers(array $tokens, int $i, int $count): bool
    {
        for ($k = 0; $k < $count; $k++) {
            if (!isset($tokens[$i + $k]) || ctype_alpha($tokens[$i + $k])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reads one arc flag, splitting compact forms like "011" (large=0, sweep=1, x=1) used by minifiers.
     *
     * @param list<string> $tokens
     */
    private function takeArcFlag(array &$tokens, int &$i, int $n): ?bool
    {
        if ($i >= $n || ctype_alpha($tokens[$i])) {
            return null;
        }
        $tok = $tokens[$i];
        $first = $tok[0];
        if (($first === '0' || $first === '1') && strlen($tok) > 1 && ctype_digit($tok[1])) {
            $tokens[$i] = substr($tok, 1);
            return $first === '1';
        }
        $i++;
        return ((float) $tok) != 0.0;
    }

    /**
     * Converts an SVG elliptical arc (x1, y1) -> (x2, y2) into 1..4 cubic Bézier segments in PDF space
     * following W3C SVG 1.1 Appendix F.6.5 & F.6.6.
     *
     * @param array<string, mixed> $s
     */
    private function emitArcAsCubicBeziers(
        float $x1,
        float $y1,
        float $rx,
        float $ry,
        float $phiDeg,
        bool $largeArc,
        bool $sweep,
        float $x2,
        float $y2,
        array $s,
        string &$out
    ): void {
        if (abs($x1 - $x2) < 1e-5 && abs($y1 - $y2) < 1e-5) {
            return;
        }
        if ($rx < 1e-5 || $ry < 1e-5) {
            $out .= $this->pt($x2, $y2, $s) . " l\n";
            return;
        }

        $phi = deg2rad(fmod($phiDeg, 360.0));
        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        $dx2 = ($x1 - $x2) / 2.0;
        $dy2 = ($y1 - $y2) / 2.0;
        $x1p = $cosPhi * $dx2 + $sinPhi * $dy2;
        $y1p = -$sinPhi * $dx2 + $cosPhi * $dy2;

        $rxSq = $rx * $rx;
        $rySq = $ry * $ry;
        $x1pSq = $x1p * $x1p;
        $y1pSq = $y1p * $y1p;

        // Ensure radii are large enough
        $lambda = ($x1pSq / $rxSq) + ($y1pSq / $rySq);
        if ($lambda > 1.0) {
            $sqrtLambda = sqrt($lambda);
            $rx *= $sqrtLambda;
            $ry *= $sqrtLambda;
            $rxSq = $rx * $rx;
            $rySq = $ry * $ry;
        }

        $num = max(0.0, $rxSq * $rySq - $rxSq * $y1pSq - $rySq * $x1pSq);
        $den = $rxSq * $y1pSq + $rySq * $x1pSq;
        $factor = ($den > 1e-9) ? sqrt($num / $den) : 0.0;
        if ($largeArc === $sweep) {
            $factor = -$factor;
        }

        $cxp = $factor * (($rx * $y1p) / $ry);
        $cyp = $factor * (-($ry * $x1p) / $rx);

        $cx = $cosPhi * $cxp - $sinPhi * $cyp + ($x1 + $x2) / 2.0;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + ($y1 + $y2) / 2.0;

        $ux = ($x1p - $cxp) / $rx;
        $uy = ($y1p - $cyp) / $ry;
        $vx = (-$x1p - $cxp) / $rx;
        $vy = (-$y1p - $cyp) / $ry;

        $theta1 = atan2($uy, $ux);
        $deltaTheta = atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);

        if (!$sweep && $deltaTheta > 0.0) {
            $deltaTheta -= 2.0 * M_PI;
        } elseif ($sweep && $deltaTheta < 0.0) {
            $deltaTheta += 2.0 * M_PI;
        }

        $segments = max(1, (int) ceil(abs($deltaTheta) / (M_PI / 2.0)));
        $dTheta = $deltaTheta / $segments;
        $alpha = (4.0 / 3.0) * tan($dTheta / 4.0);

        $t = $theta1;
        for ($seg = 0; $seg < $segments; $seg++) {
            $tNext = $t + $dTheta;
            $cosT1 = cos($t);
            $sinT1 = sin($t);
            $cosT2 = cos($tNext);
            $sinT2 = sin($tNext);

            $ep1x = $cosT1 - $alpha * $sinT1;
            $ep1y = $sinT1 + $alpha * $cosT1;
            $ep2x = $cosT2 + $alpha * $sinT2;
            $ep2y = $sinT2 - $alpha * $cosT2;

            $c1x = $cx + $cosPhi * ($rx * $ep1x) - $sinPhi * ($ry * $ep1y);
            $c1y = $cy + $sinPhi * ($rx * $ep1x) + $cosPhi * ($ry * $ep1y);

            $c2x = $cx + $cosPhi * ($rx * $ep2x) - $sinPhi * ($ry * $ep2y);
            $c2y = $cy + $sinPhi * ($rx * $ep2x) + $cosPhi * ($ry * $ep2y);

            $endX = $cx + $cosPhi * ($rx * $cosT2) - $sinPhi * ($ry * $sinT2);
            $endY = $cy + $sinPhi * ($rx * $cosT2) + $cosPhi * ($ry * $sinT2);

            $out .= $this->curve($c1x, $c1y, $c2x, $c2y, $endX, $endY, $s);
            $t = $tNext;
        }
    }

    // ------------------------------------------------------------------
    // Text
    // ------------------------------------------------------------------

    /**
     * Renders <text> with nested <tspan> runs, text-anchor per text chunk, and the full transform matrix.
     *
     * @param array<string, mixed> $s
     */
    private function drawText(DOMElement $el, array $s, string &$out): void
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $this->tagOf($child) === 'textpath') {
                $this->drawTextWithPaths($el, $s, $out);
                return;
            }
        }
        $runs = [];
        $this->collectTextRuns($el, $s, $runs, ['x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0]);
        $clean = $this->cleanTextRuns($runs);
        $this->layoutTextRuns($clean, $out);
    }

    /**
     * <text> with <textPath> children: each textPath lays its glyphs along the referenced path; the text outside the
     * textPath elements is drawn as ordinary text.
     *
     * @param array<string, mixed> $s
     */
    private function drawTextWithPaths(DOMElement $el, array $s, string &$out): void
    {
        $plain = $el->cloneNode(true);
        $toRemove = [];
        foreach ($plain->childNodes as $child) {
            if ($child instanceof DOMElement && $this->tagOf($child) === 'textpath') {
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $child) {
            $plain->removeChild($child);
        }
        if (trim((string) $plain->textContent) !== '') {
            $runs = [];
            $this->collectTextRuns($plain, $s, $runs, ['x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0]);
            $this->layoutTextRuns($this->cleanTextRuns($runs), $out);
        }
        $textX = $this->firstLength($el, 'x', $s, 'x') ?? 0.0;
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $this->tagOf($child) === 'textpath') {
                $cs = $s;
                if ($this->applyElementAttributes($child, $cs)) {
                    $this->drawTextPath($child, $cs, $textX, $out);
                }
            }
        }
    }

    /**
     * Places the glyphs of a <textPath> along its path (SVG 1.1 / Chromium): each glyph's horizontal midpoint sits on
     * the path at its distance along it and the glyph is rotated to the path's direction there; glyphs whose midpoint
     * falls off the path are not drawn. Supports href / xlink:href, startOffset (length or %), the referenced path's
     * pathLength and transform, text-anchor, side="right", dx / dy of the runs.
     *
     * @param array<string, mixed> $s
     */
    private function drawTextPath(DOMElement $tp, array $s, float $textX, string &$out): void
    {
        $ref = $this->hrefOf($tp);
        $pathEl = $ref !== null && str_starts_with($ref, '#') ? $this->findById(substr($ref, 1)) : null;
        if ($pathEl === null || $this->tagOf($pathEl) !== 'path') {
            if ($this->warn !== null) {
                ($this->warn)('SVG <textPath> without a valid href to a <path> was not drawn (' . ($ref ?? 'no href') . ').');
            }
            return;
        }
        $points = $this->pathPolyline($pathEl);
        if (count($points) < 2) {
            return;
        }
        // cumulative lengths (a moveto between subpaths adds no length); `side` is not supported, as in Chromium
        $cum = [0.0];
        $n = count($points);
        for ($i = 1; $i < $n; $i++) {
            $cum[$i] = $cum[$i - 1] + ($points[$i][2] ? 0.0 : hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]));
        }
        $length = $cum[$n - 1];
        if ($length <= 1e-9) {
            return;
        }
        $scale = 1.0;
        $pathLengthAttr = $this->attr($pathEl, 'pathLength');
        if ($pathLengthAttr !== null && is_numeric(trim($pathLengthAttr)) && (float) $pathLengthAttr > 0) {
            $scale = $length / (float) $pathLengthAttr;
        }
        $offsetRaw = trim((string) $this->attr($tp, 'startOffset'));
        $offset = 0.0;
        if ($offsetRaw !== '') {
            $offset = str_ends_with($offsetRaw, '%')
                ? (float) substr($offsetRaw, 0, -1) / 100.0 * $length
                : $this->parseLength($offsetRaw, $s, 'x') * $scale;
        }

        $runs = [];
        $this->collectTextRuns($tp, $s, $runs, ['x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0]);
        $runs = $this->cleanTextRuns($runs);
        if ($runs === []) {
            return;
        }
        // glyph clusters with their advances
        $glyphs = [];
        $total = 0.0;
        foreach ($runs as $run) {
            $rs = $run['s'];
            $first = true;
            $clusters = preg_split('/(\X)/u', $run['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            $measure = fn(string $t): float => $this->fontManager->measureText($t, (string) $rs['fontFamily'], (string) $rs['fontWeight'], (string) $rs['fontStyle'], (float) $rs['fontSize']);
            // advances from growing prefixes keep the font's kerning (per-cluster measuring for very long runs)
            $kerned = count($clusters) <= 400;
            $prefix = '';
            $prefixW = 0.0;
            foreach ($clusters as $cl) {
                if ($kerned) {
                    $prefix .= $cl;
                    $w = $measure($prefix);
                    $adv = $w - $prefixW;
                    $prefixW = $w;
                } else {
                    $adv = $measure($cl);
                }
                $dx = $first ? (float) $run['dx'] : 0.0;
                $glyphs[] = ['text' => $cl, 's' => $rs, 'adv' => $adv, 'dx' => $dx, 'dy' => $first ? (float) $run['dy'] : 0.0];
                $total += $adv + $dx;
                $first = false;
            }
        }
        $anchor = (string) $runs[0]['s']['textAnchor'];
        $d = $textX + $offset - match ($anchor) {
            'middle' => $total / 2.0,
            'end' => $total,
            default => 0.0,
        };
        $perp = 0.0;
        $seg = 1;
        foreach ($glyphs as $g) {
            $d += $g['dx'];
            $perp += $g['dy'];
            $mid = $d + $g['adv'] / 2.0;
            $d += $g['adv'];
            if ($mid < 0.0 || $mid > $length || trim($g['text']) === '') {
                continue;
            }
            while ($seg < $n - 1 && ($cum[$seg] < $mid || $points[$seg][2])) {
                $seg++;
            }
            while ($seg > 1 && $cum[$seg - 1] > $mid) {
                $seg--;
            }
            if ($points[$seg][2]) {
                continue;
            }
            $p0 = $points[$seg - 1];
            $p1 = $points[$seg];
            $segLen = max(1e-12, $cum[$seg] - $cum[$seg - 1]);
            $f = ($mid - $cum[$seg - 1]) / $segLen;
            $cos = ($p1[0] - $p0[0]) / $segLen;
            $sin = ($p1[1] - $p0[1]) / $segLen;
            $mx = $p0[0] + ($p1[0] - $p0[0]) * $f;
            $my = $p0[1] + ($p1[1] - $p0[1]) * $f;
            // glyph frame: origin half an advance back along the tangent, y down perpendicular (user space)
            $gs = $g['s'];
            $gs['ctm'] = $this->multiply($s['ctm'], [$cos, $sin, -$sin, $cos, $mx - $cos * $g['adv'] / 2.0, $my - $sin * $g['adv'] / 2.0]);
            $this->emitTextRun($g['text'], $gs, 0.0, $perp, $out);
        }
    }

    /**
     * The geometry of a <path> (with its own transform) as one polyline in the user space of the referencing
     * element; curves are flattened. A point flagged true starts a subpath (the step to it is a jump, not a segment).
     *
     * @return list<array{0: float, 1: float, 2: bool}>
     */
    private function pathPolyline(DOMElement $pathEl): array
    {
        $k = 1000.0; // pt() keeps two decimals: scale up for precision
        $linkBox = $this->linkBox;
        $this->linkBox = null;
        [$ops] = $this->buildPathData(trim($pathEl->getAttribute('d')), ['ctm' => [$k, 0.0, 0.0, $k, 0.0, 0.0], 'pageHeight' => 0.0]);
        $this->linkBox = $linkBox;
        $transform = $this->attr($pathEl, 'transform');
        $m = $transform !== null && trim($transform) !== '' ? $this->parseTransform($transform) : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $map = static fn(float $x, float $y, bool $move = false): array => [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5], $move];

        $points = [];
        $cur = [0.0, 0.0];
        $start = [0.0, 0.0];
        $nums = [];
        foreach (preg_split('/\s+/', trim($ops), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (is_numeric($tok)) {
                $nums[] = (float) $tok;
                continue;
            }
            // pt(): x * k, 0 - (y * k)
            $xy = [];
            for ($i = 0; $i + 1 < count($nums); $i += 2) {
                $xy[] = [$nums[$i] / $k, -$nums[$i + 1] / $k];
            }
            $nums = [];
            if ($tok === 'm' && $xy !== []) {
                $cur = $start = $xy[0];
                $points[] = $map($cur[0], $cur[1], true);
            } elseif ($tok === 'l' && $xy !== []) {
                $cur = $xy[0];
                $points[] = $map($cur[0], $cur[1]);
            } elseif ($tok === 'c' && count($xy) === 3) {
                [$c1, $c2, $end] = $xy;
                $steps = 24;
                for ($t = 1; $t <= $steps; $t++) {
                    $u = $t / $steps;
                    $v = 1.0 - $u;
                    $x = $v * $v * $v * $cur[0] + 3 * $v * $v * $u * $c1[0] + 3 * $v * $u * $u * $c2[0] + $u * $u * $u * $end[0];
                    $y = $v * $v * $v * $cur[1] + 3 * $v * $v * $u * $c1[1] + 3 * $v * $u * $u * $c2[1] + $u * $u * $u * $end[1];
                    $points[] = $map($x, $y);
                }
                $cur = $end;
            } elseif ($tok === 'h') {
                $cur = $start;
                $points[] = $map($cur[0], $cur[1]);
            }
        }
        // drop repeated points (zero-length segments have no direction)
        $clean = [];
        foreach ($points as $p) {
            $last = $clean[count($clean) - 1] ?? null;
            if ($last === null || abs($last[0] - $p[0]) > 1e-9 || abs($last[1] - $p[1]) > 1e-9) {
                $clean[] = $p;
            }
        }
        return $clean;
    }

    /**
     * Whitespace handling (xml:space="default"): collapse, trim the ends, avoid double spaces across runs.
     *
     * @param list<array{text: string, s: array<string, mixed>, x: ?float, y: ?float, dx: float, dy: float}> $runs
     * @return list<array{text: string, s: array<string, mixed>, x: ?float, y: ?float, dx: float, dy: float}>
     */
    private function cleanTextRuns(array $runs): array
    {
        $clean = [];
        $carry = null;
        $prevEndsSpace = true;
        foreach ($runs as $run) {
            if ($prevEndsSpace) {
                $run['text'] = ltrim($run['text'], ' ');
            }
            if ($carry !== null) {
                $run['x'] ??= $carry['x'];
                $run['y'] ??= $carry['y'];
                $run['dx'] += $carry['dx'];
                $run['dy'] += $carry['dy'];
                $carry = null;
            }
            if ($run['text'] === '') {
                $carry = $run;
                continue;
            }
            $prevEndsSpace = str_ends_with($run['text'], ' ');
            $clean[] = $run;
        }
        $last = count($clean) - 1;
        if ($last >= 0) {
            $clean[$last]['text'] = rtrim($clean[$last]['text'], ' ');
            if ($clean[$last]['text'] === '') {
                array_pop($clean);
            }
        }
        return $clean;
    }

    /**
     * Lays out cleaned text runs: text chunks start at absolutely positioned runs and honour text-anchor.
     *
     * @param list<array{text: string, s: array<string, mixed>, x: ?float, y: ?float, dx: float, dy: float}> $clean
     */
    private function layoutTextRuns(array $clean, string &$out): void
    {
        $n = count($clean);
        $widths = [];
        foreach ($clean as $k => $run) {
            $rs = $run['s'];
            $widths[$k] = $this->fontManager->measureText(
                $run['text'],
                (string) $rs['fontFamily'],
                (string) $rs['fontWeight'],
                (string) $rs['fontStyle'],
                (float) $rs['fontSize']
            );
        }

        $curX = 0.0;
        $curY = 0.0;
        $i = 0;
        while ($i < $n) {
            // A text chunk starts at each absolutely positioned run.
            $j = $i + 1;
            while ($j < $n && $clean[$j]['x'] === null && $clean[$j]['y'] === null) {
                $j++;
            }
            $total = 0.0;
            for ($k = $i; $k < $j; $k++) {
                $total += $widths[$k] + ($k > $i ? $clean[$k]['dx'] : 0.0);
            }
            $anchor = (string) $clean[$i]['s']['textAnchor'];
            $shift = match ($anchor) {
                'middle' => $total / 2.0,
                'end' => $total,
                default => 0.0,
            };

            $cx = ($clean[$i]['x'] ?? $curX) - $shift;
            $cy = $clean[$i]['y'] ?? $curY;
            for ($k = $i; $k < $j; $k++) {
                $cx += $clean[$k]['dx'];
                $cy += $clean[$k]['dy'];
                $this->emitTextRun($clean[$k]['text'], $clean[$k]['s'], $cx, $cy, $out);
                $cx += $widths[$k];
            }
            $curX = $cx + $shift;
            $curY = $cy;
            $i = $j;
        }
    }

    /**
     * Flattens <text>/<tspan> content into runs, each with its own state and pending positioning.
     *
     * @param array<string, mixed> $s
     * @param list<array{text: string, s: array<string, mixed>, x: ?float, y: ?float, dx: float, dy: float}> $runs
     * @param array{x: ?float, y: ?float, dx: float, dy: float} $pending
     * @return array{x: ?float, y: ?float, dx: float, dy: float} positioning not yet consumed by a run
     */
    private function collectTextRuns(DOMElement $el, array $s, array &$runs, array $pending): array
    {
        $x = $this->firstLength($el, 'x', $s, 'x');
        $y = $this->firstLength($el, 'y', $s, 'y');
        if ($x !== null) {
            $pending['x'] = $x;
        }
        if ($y !== null) {
            $pending['y'] = $y;
        }
        $pending['dx'] += $this->firstLength($el, 'dx', $s, 'x') ?? 0.0;
        $pending['dy'] += $this->firstLength($el, 'dy', $s, 'y') ?? 0.0;

        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = (string) preg_replace('/[ \t\r\n]+/', ' ', $child->data);
                if ($text === '') {
                    continue;
                }
                $runs[] = ['text' => $text, 's' => $s] + $pending;
                $pending = ['x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0];
            } elseif ($child instanceof DOMElement) {
                $tag = $this->tagOf($child);
                if ($tag !== 'tspan' && $tag !== 'a') {
                    continue;
                }
                $cs = $s;
                if (!$this->applyElementAttributes($child, $cs)) {
                    continue;
                }
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href') !== '' ? $child->getAttribute('href') : $child->getAttribute('xlink:href'));
                    if ($href !== '') {
                        $cs['href'] = $href;
                    }
                }
                $pending = $this->collectTextRuns($child, $cs, $runs, $pending);
            }
        }

        return $pending;
    }

    /**
     * Emits one text run at user-space baseline position (x, y) using a full text matrix (Tm).
     *
     * @param array<string, mixed> $s
     */
    private function emitTextRun(string $text, array $s, float $x, float $y, string &$out): void
    {
        $fs = (float) $s['fontSize'];
        if (!$s['visible'] || $fs <= 0) {
            return;
        }
        if (isset($s['href']) && $s['href'] !== '') {
            // <a> inside <text>: a link area of its own
            $outer = $this->linkBox;
            $this->linkBox = [INF, INF, -INF, -INF];
            $this->linkTextBox($text, $s, $x, $y);
            $box = $this->linkBox;
            $this->linkBox = $outer;
            $this->links[] = [$box[0], $box[1], $box[2], $box[3], (string) $s['href']];
        }
        $this->linkTextBox($text, $s, $x, $y);
        if ($this->textExtras($text, $s, $x, $y, $out)) { // bbox probing / gradient & pattern fills
            return;
        }
        $color = $this->resolvePaint((string) $s['fill'], $s);
        if ($color === null) {
            return;
        }
        $alpha = $color[3] * (float) $s['fillOpacity'] * (float) $s['opacity'];
        if ($alpha <= 0.001) {
            return;
        }

        $ttf = $this->fontManager->resolveFont((string) $s['fontFamily'], (string) $s['fontWeight'], (string) $s['fontStyle']);
        $fontId = $this->fontManager->markFontUsed($ttf);
        $hexGids = $this->fontManager->encodeTextToHexGids($ttf, $text);
        if ($hexGids === '') {
            return;
        }

        // Glyph space (y up, size 1) → user space (y down, size fs) → document → PDF page (y flipped).
        $m = $this->multiply($s['ctm'], [$fs, 0.0, 0.0, -$fs, $x, $y]);
        $gs = $this->alphaState($alpha);

        $out .= ($gs !== null ? "q\n/{$gs} gs\n" : '')
            . sprintf(
                "BT\n%s\n0 Tc 0 Tw 0 Tr\n/%s 1 Tf\n%.4F %.4F %.4F %.4F %.2F %.2F Tm\n<%s> Tj\nET\n",
                ColorOps::fillOp($color),
                $fontId,
                $m[0],
                -$m[1],
                $m[2],
                -$m[3],
                $m[4],
                (float) $s['pageHeight'] - $m[5],
                $hexGids
            )
            . ($gs !== null ? "Q\n" : '');
    }

    // ------------------------------------------------------------------
    // Value parsing
    // ------------------------------------------------------------------

    /**
     * Parses an SVG length (px, pt, pc, mm, cm, in, em, ex, %) into user units.
     * Percentages resolve against the viewport width ('x'), height ('y') or normalized diagonal ('d').
     *
     * @param array<string, mixed> $s
     */
    private function parseLength(string $value, array $s, string $axis): float
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return 0.0;
        }
        if (!preg_match('/^(' . self::NUMBER_RE . ')\s*([a-z%]*)$/', $v, $m)) {
            return (float) $v;
        }
        $num = (float) $m[1];
        $fs = (float) ($s['fontSize'] ?? 12.0);

        return match ($m[2]) {
            'pt' => $num * 4.0 / 3.0,
            'pc' => $num * 16.0,
            'mm' => $num * 96.0 / 25.4,
            'cm' => $num * 96.0 / 2.54,
            'in' => $num * 96.0,
            'em', 'rem' => $num * $fs,
            'ex' => $num * $fs * 0.5,
            '%' => $num / 100.0 * match ($axis) {
                'x' => (float) $s['vpW'],
                'y' => (float) $s['vpH'],
                default => sqrt(((float) $s['vpW'] ** 2 + (float) $s['vpH'] ** 2) / 2.0),
            },
            default => $num,
        };
    }

    /**
     * @param array<string, mixed> $s
     */
    private function lengthAttr(DOMElement $el, string $name, array $s, string $axis): float
    {
        return $this->firstLength($el, $name, $s, $axis) ?? 0.0;
    }

    /**
     * Reads the first value of a (possibly list-valued) length attribute; null when absent.
     *
     * @param array<string, mixed> $s
     */
    private function firstLength(DOMElement $el, string $name, array $s, string $axis): ?float
    {
        $raw = $this->attr($el, $name);
        if ($raw === null) {
            return null;
        }
        $parts = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }
        return $this->parseLength($parts[0], $s, $axis);
    }

    /**
     * Reads rx/ry: null when absent or "auto", otherwise a non-negative length.
     *
     * @param array<string, mixed> $s
     */
    private function radiusAttr(DOMElement $el, string $name, array $s, string $axis): ?float
    {
        $raw = $this->attr($el, $name);
        if ($raw === null || trim($raw) === '' || strtolower(trim($raw)) === 'auto') {
            return null;
        }
        return max(0.0, $this->parseLength($raw, $s, $axis));
    }

    /**
     * @param array<string, mixed> $s
     */
    private function parseFontSize(string $value, array $s): ?float
    {
        $v = strtolower(trim($value));
        $parent = (float) $s['fontSize'];
        if ($v === '' || $v === 'inherit') {
            return null;
        }
        if (isset(self::FONT_SIZE_KEYWORDS[$v])) {
            return self::FONT_SIZE_KEYWORDS[$v];
        }
        if ($v === 'smaller') {
            return $parent / 1.2;
        }
        if ($v === 'larger') {
            return $parent * 1.2;
        }
        if (str_ends_with($v, '%')) {
            $size = (float) substr($v, 0, -1) / 100.0 * $parent;
        } else {
            $size = $this->parseLength($v, $s, 'y');
        }
        return $size > 0 ? $size : null;
    }

    private function parseOpacity(string $value): float
    {
        $v = trim($value);
        $num = str_ends_with($v, '%') ? (float) substr($v, 0, -1) / 100.0 : (float) $v;
        return max(0.0, min(1.0, $num));
    }

    /**
     * @param array<string, mixed> $s
     * @return list<float>
     */
    private function parseDashArray(string $value, array $s): array
    {
        if (strtolower(trim($value)) === 'none') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vals = [];
        foreach ($parts as $part) {
            $len = $this->parseLength($part, $s, 'd');
            if ($len < 0) {
                return [];
            }
            $vals[] = $len;
        }
        if ($vals === [] || array_sum($vals) <= 0) {
            return [];
        }
        if (count($vals) % 2 === 1) {
            $vals = array_merge($vals, $vals);
        }
        return $vals;
    }

    /**
     * @return list<float>
     */
    private function parseNumberList(string $value): array
    {
        if (!preg_match_all('/' . self::NUMBER_RE . '/', $value, $m)) {
            return [];
        }
        return array_map('floatval', $m[0]);
    }

    // ------------------------------------------------------------------
    // DOM helpers
    // ------------------------------------------------------------------

    private function tagOf(DOMElement $el): string
    {
        return strtolower($el->localName ?? $el->tagName);
    }

    /**
     * Reads an attribute by its SVG (camelCase) name or the lowercased name produced by the HTML parser.
     */
    private function attr(DOMElement $el, string $name): ?string
    {
        if ($el->hasAttribute($name)) {
            return $el->getAttribute($name);
        }
        $lower = strtolower($name);
        if ($lower !== $name && $el->hasAttribute($lower)) {
            return $el->getAttribute($lower);
        }
        return null;
    }

    private function hrefOf(DOMElement $el): ?string
    {
        foreach (['href', 'xlink:href'] as $name) {
            if ($el->hasAttribute($name)) {
                return trim($el->getAttribute($name));
            }
        }
        $ns = $el->getAttributeNS(self::XLINK_NS, 'href');
        return $ns !== '' ? trim($ns) : null;
    }

    private function indexIds(DOMElement $root): void
    {
        if ($root->hasAttribute('id')) {
            $this->idMap[$root->getAttribute('id')] = $root;
        }
        // Cursor walk in document order: iterating getElementsByTagName('*') is quadratic before PHP 8.3
        $el = $root->firstElementChild;
        while ($el !== null) {
            $id = $el->getAttribute('id');
            if ($id !== '' && !isset($this->idMap[$id])) {
                $this->idMap[$id] = $el;
            }
            $next = $el->firstElementChild;
            while ($next === null && $el !== null && $el !== $root) {
                $next = $el->nextElementSibling;
                $el = $next === null ? $el->parentNode : $el;
            }
            $el = $next;
        }
    }

    /**
     * Finds an element by id inside the rendered SVG first, then anywhere in the owning document.
     */
    private function findById(string $id): ?DOMElement
    {
        if ($id === '') {
            return null;
        }
        if (array_key_exists($id, $this->idMap)) {
            return $this->idMap[$id];
        }

        $found = null;
        $doc = $this->rootSvg?->ownerDocument;
        if ($doc !== null) {
            if (!str_contains($id, "'")) {
                $literal = "'" . $id . "'";
            } elseif (!str_contains($id, '"')) {
                $literal = '"' . $id . '"';
            } else {
                $literal = null;
            }
            if ($literal !== null) {
                $nodes = (new DOMXPath($doc))->query('//*[@id=' . $literal . ']');
                $node = $nodes !== false ? $nodes->item(0) : null;
                $found = $node instanceof DOMElement ? $node : null;
            }
        }

        $this->idMap[$id] = $found;
        return $found;
    }
}
