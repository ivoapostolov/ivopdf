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
    public ?DOMElement $domElement = null;

    /**
     * Rarely used properties live in a BoxExtra that exists only while one of them holds a non-default value:
     * href, imageSrc, listMarker, listMarkerString, listMarkerImage, markerStyle, firstLineStyle, firstLineProps, formControl, formBaseline,
     * collapsedMarginTop, collapsedMarginBottom, colspan, rowspan, fixedHeight, columnFlow, columnRects, bookmarkLevel and bookmarkLabel. They read and write like
     * ordinary properties (through __get / __set), but an array one is replaced as a whole: no `$box->columnRects[] = x`.
     */
    private ?BoxExtra $extra = null;

    // Border-box width / height set explicitly by the parent layout (Flex / Grid / Table); every table cell has them
    public ?float $forcedBorderBoxWidth = null;
    public ?float $forcedBorderBoxHeight = null;
    private static ?BoxExtra $defaults = null;

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

    // Resolved font size in pt
    public float $fontSizePt = 10.5;

    public function __construct(string $tagName, ComputedStyle $style, ?DOMElement $domElement = null)
    {
        $this->tagName = strtolower($tagName);
        // Text nodes never carry the parent's positioning/float/transform (they would be laid out as out-of-flow)
        $this->style = $this->tagName === '#text' ? $style->forTextNode() : $style;
        $this->domElement = $domElement;
    }

    public function __clone()
    {
        if ($this->extra !== null) {
            $this->extra = clone $this->extra;
        }
    }

    public function __get(string $name): mixed
    {
        return ($this->extra ?? self::$defaults ??= new BoxExtra())->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($this->extra === null) {
            $default = (self::$defaults ??= new BoxExtra())->$name;
            if ($value === $default || (is_int($value) && is_float($default) && (float) $value === $default)) {
                return; // still the default: nothing to allocate
            }
            $this->extra = new BoxExtra();
        }
        $this->extra->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return property_exists(BoxExtra::class, $name) && ($this->extra ?? self::$defaults ??= new BoxExtra())->$name !== null;
    }

    public function __unset(string $name): void
    {
        $this->__set($name, (self::$defaults ??= new BoxExtra())->$name);
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

        if ($this->extra !== null && $this->extra->columnRects !== []) {
            $rects = $this->extra->columnRects;
            foreach ($rects as $i => $r) {
                $rects[$i]['x'] = $r['x'] + $dx;
                $rects[$i]['y'] = $r['y'] + $dy;
            }
            $this->extra->columnRects = $rects;
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
