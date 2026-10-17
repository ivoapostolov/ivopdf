<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Rarely used TextRun properties (bidi controls, hyphenation, line-start flags). A run only allocates one when it
 * sets a non-default value; see TextRun::__get() / __set().
 *
 * @internal
 */
final class RunExtra
{
    /** `<wbr>`: an empty, zero-width run that only offers a line break opportunity */
    public bool $isWbr = false;
    /** Forces a (soft) line break before this run: set by InlineLayout for the end of the ::first-line */
    public bool $lineStart = false;
    /** Resolved Unicode bidi embedding level (odd = right-to-left) */
    public int $bidiLevel = 0;
    /** @var list<int> Bidi control codepoints (embeddings/isolates/overrides) opened before this run's text */
    public array $bidiPre = [];
    /** @var list<int> Bidi control codepoints closed after this run's text */
    public array $bidiPost = [];
    /** Fragment of a word in a script without word spaces (CJK, Thai, ...): break opportunities follow ScriptBreaker */
    public bool $wide = false;
    /** Set on the second half of a word split after a hyphen ("well-" | "known") */
    public bool $afterHyphen = false;
    /** Hyphenation character drawn at the end of the line when a break occurs before this run (soft hyphen / hyphens:auto split) */
    public ?string $softBreak = null;
    /** Width of {@see $softBreak} measured in the previous fragment's font */
    public float $softBreakWidth = 0.0;
    /** A float met in the inline content: a zero-width marker placed by InlineLayout at the line where it occurs */
    public ?LayoutBox $floatBox = null;
    /** `position: relative` offset of the enclosing inline elements (applied after the line is placed) */
    public float $relDx = 0.0;
    public float $relDy = 0.0;
    /** Text that copy / paste and search should see instead of the drawn glyphs (synthesized small capitals) */
    public ?string $actualText = null;
    // Optional inline box decorations (for <span style="background:...; padding:...; border-radius:...">, <code>, <mark>)
    public float $paddingTop = 0.0;
    public float $paddingRight = 0.0;
    public float $paddingBottom = 0.0;
    public float $paddingLeft = 0.0;
}
