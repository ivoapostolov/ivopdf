<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use DOMElement;
use IvoPdf\Css\ComputedStyle;

/**
 * Represents a box node in the layout tree (Block, Flex Container/Item, Grid Container/Item, Table, Inline, Text, Image, SVG).
 * Coordinates (x, y, width, height) represent the Border Box in PDF points (pt) from top-left of the document/page.
 */
class LayoutBox
{
    public string $tagName;
    public ComputedStyle $style;
    public ?LayoutBox $parent = null;

    /** @var list<LayoutBox> */
    public array $children = [];

    /** @var list<LineBox> Laid-out inline text lines inside this box */
    public array $lines = [];

    public ?string $textContent = null;
    public ?string $imageSrc = null;
    public ?DOMElement $domElement = null;
    public ?string $href = null;
    public ?string $listMarker = null;
    /** list-style-image source (outside markers are drawn by the renderer) */
    public ?string $listMarkerImage = null;
    /** Computed ::marker style (color / font-size) when the stylesheet defines one */
    public ?ComputedStyle $markerStyle = null;

    /** Set for <input>/<textarea>/<select>/<button>: the parsed control (replaced box, no child boxes). */
    public ?FormControl $formControl = null;
    /** Baseline offset from the box top for form controls (null = bottom edge). */
    public ?float $formBaseline = null;

    // Border-box coordinates in pt (top-left origin)
    public float $x = 0.0;
    public float $y = 0.0;
    public float $width = 0.0;
    public float $height = 0.0;

    // Resolved box model edges in pt
    public float $marginTop = 0.0;
    public float $marginRight = 0.0;
    public float $marginBottom = 0.0;
    public float $marginLeft = 0.0;

    public float $borderTop = 0.0;
    public float $borderRight = 0.0;
    public float $borderBottom = 0.0;
    public float $borderLeft = 0.0;

    public float $paddingTop = 0.0;
    public float $paddingRight = 0.0;
    public float $paddingBottom = 0.0;
    public float $paddingLeft = 0.0;

    /** Paginator bookkeeping: vertical shift owed to this box's whole subtree, applied lazily (see Paginator::sync) */
    public float $pendingDy = 0.0;

    // Table cell attributes
    public int $colspan = 1;
    public int $rowspan = 1;

    // Resolved font size in pt
    public float $fontSizePt = 10.5;

    // Flag indicating if width/height was explicitly set by parent layout (Flex/Grid/Table)
    public ?float $forcedBorderBoxWidth = null;
    public ?float $forcedBorderBoxHeight = null;

    /** Multi-column flow state (set on column containers by ColumnLayout) */
    public ?ColumnFlow $columnFlow = null;

    /** @var list<array{x: float, y: float, w: float, h: float, chunk: int, col: int}> Used column rects of a multicol container */
    public array $columnRects = [];

    public function __construct(string $tagName, ComputedStyle $style, ?DOMElement $domElement = null)
    {
        $this->tagName = strtolower($tagName);
        // Text nodes never carry the parent's positioning/float/transform (they would be laid out as out-of-flow)
        $this->style = $this->tagName === '#text' ? $style->forTextNode() : $style;
        $this->domElement = $domElement;
    }

    public function addChild(LayoutBox $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function isTextNode(): bool
    {
        return $this->tagName === '#text';
    }

    public function getDisplay(): string
    {
        if ($this->isTextNode()) {
            return 'inline';
        }
        return strtolower($this->style->get('display', 'block'));
    }

    public function isInlineLevel(): bool
    {
        if ($this->isTextNode()) {
            return true;
        }
        if ($this->tagName === 'br') {
            return true;
        }
        $d = $this->getDisplay();
        return in_array($d, ['inline', 'inline-block', 'inline-flex', 'inline-grid', 'inline-table'], true);
    }

    public function isPositionedOutOfFlow(): bool
    {
        $pos = strtolower($this->style->get('position', 'static'));
        return $pos === 'absolute' || $pos === 'fixed';
    }

    public function getContentX(): float
    {
        return $this->x + $this->borderLeft + $this->paddingLeft;
    }

    public function getContentY(): float
    {
        return $this->y + $this->borderTop + $this->paddingTop;
    }

    public function getContentWidth(): float
    {
        return max(0.0, $this->width - $this->getHorizontalSpacing());
    }

    public function getContentHeight(): float
    {
        return max(0.0, $this->height - $this->getVerticalSpacing());
    }

    public function getHorizontalSpacing(): float
    {
        return $this->borderLeft + $this->borderRight + $this->paddingLeft + $this->paddingRight;
    }

    public function getVerticalSpacing(): float
    {
        return $this->borderTop + $this->borderBottom + $this->paddingTop + $this->paddingBottom;
    }

    public function getOuterWidth(): float
    {
        return $this->width + $this->marginLeft + $this->marginRight;
    }

    public function getOuterHeight(): float
    {
        return $this->height + $this->marginTop + $this->marginBottom;
    }

    /**
     * Recursively shifts this box, its lines, and all descendant boxes by ($dx, $dy).
     */
    public function translate(float $dx, float $dy): void
    {
        if (abs($dx) < 0.0001 && abs($dy) < 0.0001) {
            return;
        }
        $this->x += $dx;
        $this->y += $dy;

        foreach ($this->columnRects as $i => $r) {
            $this->columnRects[$i]['x'] = $r['x'] + $dx;
            $this->columnRects[$i]['y'] = $r['y'] + $dy;
        }

        foreach ($this->lines as $line) {
            $line->translate($dx, $dy);
        }

        foreach ($this->children as $child) {
            if (!empty($this->lines) && $child->isInlineLevel()) {
                continue;
            }
            $child->translate($dx, $dy);
        }
    }
}
