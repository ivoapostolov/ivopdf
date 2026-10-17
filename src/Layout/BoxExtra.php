<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;

/**
 * Rarely used LayoutBox properties (list markers, form controls, column flow, table spans, collapsed margins...).
 * A box only allocates one when it sets a non-default value, so the common box (a table cell, a paragraph, a text
 * node) carries a single null slot instead of a dozen defaults; see LayoutBox::__get() / __set().
 *
 * @internal
 */
final class BoxExtra
{
    /** Link target of the box (inside <a href>) */
    public ?string $href = null;
    public ?string $imageSrc = null;
    public ?string $listMarker = null;
    /** list-style-image source (outside markers are drawn by the renderer) */
    public ?string $listMarkerImage = null;
    /** Computed ::marker style (color / font-size) when the stylesheet defines one */
    public ?ComputedStyle $markerStyle = null;
    /** Computed ::first-line style of a block container (applied to its first line box by InlineLayout) */
    public ?ComputedStyle $firstLineStyle = null;
    /** @var list<string> properties declared by the ::first-line rules (only those override the run style) */
    public array $firstLineProps = [];
    /** Set for <input>/<textarea>/<select>/<button>: the parsed control (replaced box, no child boxes). */
    public ?FormControl $formControl = null;
    /** Baseline offset from the box top for form controls (null = bottom edge). */
    public ?float $formBaseline = null;
    /** Margins of the first/last in-flow children that collapsed through this box's edges (see LayoutEngine::layoutBlockChildren) */
    public float $collapsedMarginTop = 0.0;
    public float $collapsedMarginBottom = 0.0;
    /** Outer heights of a table's top / bottom captions (outside the table's border and background) */
    public float $captionTop = 0.0;
    public float $captionBottom = 0.0;
    /** @var array{0: float, 1: float, 2: float, 3: float}|null rendered <legend> of a <fieldset>, relative to the fieldset's top-left: [x0, x1, y0, y1] (the border leaves a gap there) */
    public ?array $legendGap = null;
    // Table cell attributes
    public int $colspan = 1;
    public int $rowspan = 1;
    /** Multi-column flow state (set on column containers by ColumnLayout) */
    public ?ColumnFlow $columnFlow = null;
    /** @var list<array{x: float, y: float, w: float, h: float, chunk: int, col: int}> Used column rects of a multicol container */
    public array $columnRects = [];
}
