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
    /** Forces a (soft) line break before this run: set by InlineLayout for the end of the ::first-line */
    public bool $lineStart = false;
    public ?string $href = null;

    /**
     * Shaped glyphs in visual (left-to-right) order, or null when plain codepoint->glyph encoding applies.
     * Each glyph: [gid, advance, xOffset, yOffset, clusterText] with metrics in 1/1000 em; clusterText is the
     * Unicode text the glyph represents (several codepoints for ligatures) and feeds the ToUnicode CMap.
     *
     * @var list<array{0: int, 1: float, 2: float, 3: float, 4: string}>|null
     */
    public ?array $glyphs = null;

    /** Resolved Unicode bidi embedding level (odd = right-to-left) */
    public int $bidiLevel = 0;

    /** @var list<int> Bidi control codepoints (embeddings/isolates/overrides) opened before this run's text */
    public array $bidiPre = [];

    /** @var list<int> Bidi control codepoints closed after this run's text */
    public array $bidiPost = [];

    // Optional inline box decorations (for <span style="background:...; padding:...; border-radius:...">, <code>, <mark>)
    public float $paddingTop = 0.0;
    public float $paddingRight = 0.0;
    public float $paddingBottom = 0.0;
    public float $paddingLeft = 0.0;

    // Optional inline-block child box (e.g. inline <img>, <svg>, or inline-block badge)
    public ?LayoutBox $inlineBox = null;

    /** Whether a line break is allowed immediately before this run (false = glued to the previous run) */
    public bool $breakBefore = true;

    /** Fragment of a word in a script without word spaces (CJK, Thai, ...): break opportunities follow ScriptBreaker */
    public bool $wide = false;

    /** Set on the second half of a word split after a hyphen ("well-" | "known") */
    public bool $afterHyphen = false;

    /** Hyphenation character drawn at the end of the line when a break occurs before this run (soft hyphen / hyphens:auto split) */
    public ?string $softBreak = null;

    /** Width of {@see $softBreak} measured in the previous fragment's font */
    public float $softBreakWidth = 0.0;

    /** @var list<LayoutBox> Decorated inline ancestors (background/border), outermost first */
    public array $decorations = [];

    /** @var array<int, float> spl_object_id(decoration) => distance from the text start to the decoration's left border edge */
    public array $decoLeft = [];

    /** @var array<int, float> spl_object_id(decoration) => distance from the text end to the decoration's right border edge */
    public array $decoRight = [];

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
