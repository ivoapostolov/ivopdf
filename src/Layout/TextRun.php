<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;

/**
 * Represents a single styled text or inline-box fragment on a LineBox.
 */
class TextRun
{
    public string $text;
    public ComputedStyle $style;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $width = 0.0;
    public float $height = 0.0;
    public float $ascent = 0.0;
    public float $descent = 0.0;
    public float $fontSizePt = 12.0;
    public float $letterSpacingPt = 0.0;
    public bool $isWhitespace = false;
    public bool $isLineBreak = false;
    public ?string $href = null;

    /**
     * Shaped glyphs in visual (left-to-right) order, or null when plain codepoint->glyph encoding applies.
     * Each glyph: [gid, advance, xOffset, yOffset, clusterText] with metrics in 1/1000 em; clusterText is the
     * Unicode text the glyph represents (several codepoints for ligatures) and feeds the ToUnicode CMap.
     *
     * @var list<array{0: int, 1: float, 2: float, 3: float, 4: string}>|null
     */
    public ?array $glyphs = null;



    // Optional inline-block child box (e.g. inline <img>, <svg>, or inline-block badge)
    public ?LayoutBox $inlineBox = null;

    /** Whether a line break is allowed immediately before this run (false = glued to the previous run) */
    public bool $breakBefore = true;

    /** @var list<LayoutBox> Decorated inline ancestors (background/border), outermost first */
    public array $decorations = [];

    /** @var array<int, float> spl_object_id(decoration) => distance from the text start to the decoration's left border edge */
    public array $decoLeft = [];

    /** @var array<int, float> spl_object_id(decoration) => distance from the text end to the decoration's right border edge */
    public array $decoRight = [];

    /**
     * Rarely used properties live in a RunExtra that exists only while one of them holds a non-default value:
     * isWbr, lineStart, bidiLevel, bidiPre, bidiPost, wide, afterHyphen, softBreak, softBreakWidth and the inline box
     * paddings (paddingTop / Right / Bottom / Left). They read and
     * write like ordinary properties (through __get / __set), but an array one is replaced as a whole.
     */
    private ?RunExtra $extra = null;
    private static ?RunExtra $defaults = null;

    public function __clone()
    {
        if ($this->extra !== null) {
            $this->extra = clone $this->extra;
        }
    }

    public function __get(string $name): mixed
    {
        return ($this->extra ?? self::$defaults ??= new RunExtra())->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($this->extra === null) {
            $default = (self::$defaults ??= new RunExtra())->$name;
            if ($value === $default || (is_int($value) && is_float($default) && (float) $value === $default)) {
                return; // still the default: nothing to allocate
            }
            $this->extra = new RunExtra();
        }
        $this->extra->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return property_exists(RunExtra::class, $name) && ($this->extra ?? self::$defaults ??= new RunExtra())->$name !== null;
    }

    public function __unset(string $name): void
    {
        $this->__set($name, (self::$defaults ??= new RunExtra())->$name);
    }

    public function __construct(
        string $text,
        ComputedStyle $style,
        float $width = 0.0,
        float $fontSizePt = 12.0,
        float $ascent = 9.0,
        float $descent = 3.0,
        bool $isWhitespace = false,
        ?string $href = null
    ) {
        $this->text = $text;
        $this->style = $style;
        $this->width = $width;
        $this->fontSizePt = $fontSizePt;
        $this->ascent = $ascent;
        $this->descent = $descent;
        $this->height = $ascent + $descent;
        $this->isWhitespace = $isWhitespace;
        $this->href = $href;
    }
}
