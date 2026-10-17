<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;
use IvoPdf\Font\FontFeatures;
use IvoPdf\Font\FontManager;
use IvoPdf\Text\Bidi;
use IvoPdf\Text\ScriptBreaker;
use IvoPdf\Text\SoftBreaker;

/**
 * Lays out inline text runs and inline-block elements into LineBoxes with word wrapping (respecting
 * break opportunities across inline elements and after hyphens), long-word breaking, white-space handling,
 * non-breaking spaces, baseline alignment of inline-blocks, sub/sup, and text alignment.
 */
class InlineLayout
{
    private LayoutEngine $engine;
    private FontManager $fontManager;

    /** @var array<string, array{0: ComputedStyle, 1: ComputedStyle}> fallback-font style clones, shared so equal neighbours can merge */
    private array $fallbackStyles = [];

    /** Set while collecting tokens when the paragraph contains RTL text or explicit bidi controls */
    private bool $bidiFlag = false;

    /** @var list<array{members: list<TextRun>, oid: int, decorated: bool, left: float, right: float, leftEdge: float, rightEdge: float}> inline element edges, innermost first */
    private array $spanRecords = [];

    /** @var list<array{0: int, 1: LayoutBox}> empty inline elements with an id / name: [index of the token that follows, element] */
    private array $emptyAnchors = [];

    public function __construct(LayoutEngine $engine, FontManager $fontManager)
    {
        $this->engine = $engine;
        $this->fontManager = $fontManager;
    }

    /**
     * Lays out a sequence of inline-level children inside $container starting at ($startX, $startY)
     * within $availableWidth, appending resulting LineBoxes to $container->lines and returning the new Y cursor.
     *
     * @param list<LayoutBox> $inlineNodes
     */
    public function layoutInlineSequence(
        LayoutBox $container,
        array $inlineNodes,
        float $startX,
        float $startY,
        float $availableWidth
    ): float {
        if ($container->firstLineStyle === null || $container->lines !== [] || $this->firstLineProbe === $container) {
            return $this->layoutInlineSequenceCore($container, $inlineNodes, $startX, $startY, $availableWidth, null);
        }
        return $this->layoutWithFirstLine($container, $inlineNodes, $startX, $startY, $availableWidth);
    }

    /**
     * Set by the block layout right before layoutInlineSequence(): returns the free horizontal span [left, right]
     * between floats for a line at (y, height), so paragraphs wrap around floats line by line.
     *
     * @var (\Closure(float, float): array{0: float, 1: float})|null
     */
    public ?\Closure $bandProvider = null;

    /**
     * Set by the block layout together with $bandProvider when the inline content contains floats: 'place'
     * (fn(LayoutBox $float, float $y): array handle) places a float at or below y, 'move' (fn(array $handle,
     * float $dy)) shifts it to the final line position, 'reset' undoes all placements (::first-line trial passes).
     *
     * @var array{place: \Closure, move: \Closure, reset: \Closure}|null
     */
    public ?array $floatHooks = null;

    /** Container currently being laid out in ::first-line probe passes */
    private ?LayoutBox $firstLineProbe = null;

    /**
     * ::first-line: the first line box takes the pseudo-element's font/colour/text-transform. Because the style
     * changes glyph widths, the paragraph is laid out on a scratch copy of the container with the first N characters
     * restyled until the first line holds exactly the restyled text (or a stable compromise is found).
     *
     * @param list<LayoutBox> $inlineNodes
     */
    private function layoutWithFirstLine(LayoutBox $container, array $inlineNodes, float $startX, float $startY, float $availableWidth): float
    {
        $this->firstLineProbe = $container;
        $band = $this->bandProvider;
        $hooks = $this->floatHooks;
        try {
            $trial = function (int $budget, ?int $breakAt) use ($container, $inlineNodes, $startX, $startY, $availableWidth, $band, $hooks): array {
                $probe = clone $container;
                $probe->lines = [];
                $this->bandProvider = $band;
                if ($hooks !== null) {
                    ($hooks['reset'])(); // floats placed by an earlier trial pass
                    $this->floatHooks = $hooks;
                }
                $y = $this->layoutInlineSequenceCore($probe, $inlineNodes, $startX, $startY, $availableWidth, $budget, $breakAt);
                $chars = 0;
                if (isset($probe->lines[0])) {
                    foreach ($probe->lines[0]->runs as $r) {
                        $chars += $r->inlineBox !== null ? 1 : mb_strlen($r->text, 'UTF-8');
                    }
                }
                return [$probe, $y, $chars];
            };

            // Unstyled baseline: how much text the first line holds
            [$probe, $y, $chars] = $trial(0, null);
            if ($chars > 0) {
                // Find the largest K such that the first K characters, restyled, still fit on the first line
                $good = 0;
                $budget = $chars;
                $visited = [];
                for ($iter = 0; $iter < 6 && !isset($visited[$budget]); $iter++) {
                    $visited[$budget] = true;
                    [, , $c] = $trial($budget, null);
                    if ($c >= $budget) {
                        $good = max($good, $budget);
                        if ($c === $budget) {
                            break;
                        }
                    }
                    $budget = $c; // longer than expected: try the larger prefix, shorter: shrink to what fits
                }
                if ($good > 0) {
                    // Final pass: restyled prefix, forced line break right after it (later lines keep the normal style)
                    [$probe, $y] = $trial($good, $good);
                }
            }
            $container->lines = $probe->lines;
            return $y;
        } finally {
            $this->bandProvider = null;
            $this->floatHooks = null;
            $this->firstLineProbe = null;
        }
    }

    /**
     * Applies the ::first-line style to the tokens that make up the first $budget characters.
     *
     * @param list<TextRun> $tokens
     */
    private function restyleFirstLine(array $tokens, LayoutBox $container, int $budget, ?int $breakAt = null): void
    {
        $fl = $container->firstLineStyle;
        if ($fl === null || $budget <= 0) {
            return;
        }
        $props = $container->firstLineProps;
        $styles = [];
        $offset = 0;
        foreach ($tokens as $token) {
            if ($offset >= $budget) {
                if ($breakAt !== null && !$token->isWhitespace && !$token->isLineBreak) {
                    $token->lineStart = true; // the first line ends right before this word
                }
                if ($breakAt === null || !$token->isWhitespace) {
                    break;
                }
                continue;
            }
            if ($token->isLineBreak) {
                break; // a forced break ends the first line
            }
            if ($token->inlineBox !== null) {
                $offset++;
                continue;
            }
            $offset += mb_strlen($token->text, 'UTF-8');
            $key = spl_object_id($token->style);
            if (!isset($styles[$key])) {
                $ns = clone $token->style;
                $baseFamily = $container->style->get('font-family', '');
                foreach ($props as $p) {
                    if ($p === 'font-family' && $token->style->get('font-family', '') !== $baseFamily) {
                        continue; // keep the fallback family chosen for missing glyphs
                    }
                    $ns->set($p, $fl->get($p));
                }
                $styles[$key] = $ns;
            }
            $ns = $styles[$key];
            $token->style = $ns;
            $fontSizePt = $ns->getFontSizePt();
            $token->fontSizePt = $fontSizePt;
            $ttf = $this->fontManager->resolveFont($ns->get('font-family', 'DejaVu Sans'), $ns->get('font-weight', 'normal'), $ns->get('font-style', 'normal'));
            $token->ascent = ($ttf->ascent / 1000.0) * $fontSizePt;
            $token->descent = (abs($ttf->descent) / 1000.0) * $fontSizePt;
            $token->letterSpacingPt = $this->engine->resolveLength($ns->get('letter-spacing', '0'), 0.0, $fontSizePt);
            if (!$token->isWhitespace && in_array('text-transform', $props, true)) {
                $token->text = TextEffects::transform($token->text, $ns->get('text-transform', 'none'));
            }
            if ($token->isWhitespace) {
                $wordSpacing = $this->engine->resolveLength($ns->get('word-spacing', '0'), 0.0, $fontSizePt);
                $token->width = $this->fontManager->measureText(
                    $token->text,
                    $ns->get('font-family', 'DejaVu Sans'),
                    $ns->get('font-weight', 'normal'),
                    $ns->get('font-style', 'normal'),
                    $fontSizePt,
                    $token->letterSpacingPt
                ) + $wordSpacing * strlen($token->text);
            } else {
                $this->shapeRun($token);
            }
        }
    }

    /**
     * @param list<LayoutBox> $inlineNodes
     */
    private function layoutInlineSequenceCore(
        LayoutBox $container,
        array $inlineNodes,
        float $startX,
        float $startY,
        float $availableWidth,
        ?int $firstLineBudget,
        ?int $firstLineBreakAt = null
    ): float {
        $tokens = [];
        $band = $this->bandProvider;
        $this->bandProvider = null; // only this paragraph wraps around floats, not nested inline-blocks
        $floatHooks = $this->floatHooks;
        $this->floatHooks = null;
        $outerBidiFlag = $this->bidiFlag;
        $outerSpans = $this->spanRecords;
        $outerAnchors = $this->emptyAnchors;
        $this->bidiFlag = false;
        $this->spanRecords = [];
        $this->emptyAnchors = [];
        $outerCollecting = $this->collectingFloats;
        $this->collectingFloats = $floatHooks !== null;
        foreach ($inlineNodes as $node) {
            if ($floatHooks !== null && $this->isFloatNode($node)) {
                $this->collectInlineTokens($node, $availableWidth, $tokens);
                continue;
            }
            $this->collectingFloats = false; // floats nested deeper belong to other block containers
            $this->collectInlineTokens($node, $availableWidth, $tokens);
            $this->collectingFloats = $floatHooks !== null;
        }
        $this->collectingFloats = $outerCollecting;
        $needsBidi = $this->bidiFlag;
        $spans = $this->spanRecords;
        $this->attachEmptyAnchors($tokens);
        $this->bidiFlag = $outerBidiFlag;
        $this->spanRecords = $outerSpans;
        $this->emptyAnchors = $outerAnchors;

        if (empty($tokens)) {
            return $startY;
        }
        if ($firstLineBudget !== null && $firstLineBudget > 0) {
            $this->restyleFirstLine($tokens, $container, $firstLineBudget, $firstLineBreakAt);
        }

        // Bidi: resolve embedding levels on the logical paragraph before line breaking
        $baseLevel = strtolower(trim($container->style->get('direction', 'ltr'))) === 'rtl' ? 1 : 0;
        if ($baseLevel === 1 || $needsBidi) {
            $plain = strtolower(trim($container->style->get('unicode-bidi', 'normal'))) === 'plaintext';
            $baseLevel = $this->applyBidi($tokens, $plain ? null : $baseLevel, $spans);
        }
        $reorder = $baseLevel === 1 || $needsBidi;

        $this->computeBreakOpportunities($tokens);

        $textAlign = strtolower(trim($container->style->get('text-align', 'start')));
        if ($textAlign === 'start' || $textAlign === 'match-parent') {
            $textAlign = $baseLevel === 1 ? 'right' : 'left';
        } elseif ($textAlign === 'end') {
            $textAlign = $baseLevel === 1 ? 'left' : 'right';
        }
        $textIndentPt = $this->engine->resolveLength($container->style->get('text-indent', '0'), $availableWidth, $container->fontSizePt);

        /** @var list<array{runs: list<TextRun>, forcedBreak: bool}> $rawLines */
        $rawLines = [];
        $currentRuns = [];
        $currentWidth = max(0.0, $textIndentPt);
        /** @var TextRun|null $pendingLeft whitespace dropped at a line start whose left-side decorations move to the next run */
        $pendingLeft = null;

        // Text wrapping around floats: every line may have its own left edge and width (see $bandProvider)
        $availW = $availableWidth;
        $lineDx = 0.0;
        $lineY = $startY;
        $strutLh = $this->resolveLineHeight($container->style, $container->fontSizePt);
        // Floats in the inline content: those that did not fit beside their line wait for the top of the next line;
        // placed ones remember their line (index into $rawLines) and the estimated line top used for the placement
        /** @var list<LayoutBox> $pendingFloats */
        $pendingFloats = [];
        /** @var list<array{handle: array, line: int, y: float}> $placedFloats */
        $placedFloats = [];
        if ($floatHooks !== null && $band === null) {
            $band = static fn(float $y, float $h): array => [$startX, $startX + $availableWidth];
        }

        $flushLine = function (bool $forced) use (&$rawLines, &$currentRuns, &$currentWidth, &$availW, &$lineDx, &$lineY, $band, $startX, $strutLh, $container, &$pendingFloats, &$placedFloats, $floatHooks): void {
            \IvoPdf\RenderLimits::tick();
            $this->trimTrailingWhitespace($currentRuns);
            $rawLines[] = ['runs' => $currentRuns, 'forcedBreak' => $forced, 'dx' => $lineDx, 'w' => $availW];
            if ($band !== null) {
                $h = $strutLh;
                foreach ($currentRuns as $r) {
                    $h = max($h, $r->inlineBox !== null ? $r->ascent + $r->descent : $this->resolveLineHeight($r->style, $r->fontSizePt));
                }
                $lineY += $h;
                foreach ($pendingFloats as $pf) {
                    // CSS 2.1 9.5.1: a float that did not fit on its line goes to the top of the next line
                    $placedFloats[] = ['handle' => ($floatHooks['place'])($pf, $lineY), 'line' => count($rawLines), 'y' => $lineY];
                }
                $pendingFloats = [];
                [$bl, $br] = $band($lineY, $strutLh);
                $lineDx = $bl - $startX;
                $availW = max(10.0, $br - $bl);
            }
            $currentRuns = [];
            $currentWidth = 0.0;
        };

        foreach ($tokens as $token) {
            \IvoPdf\RenderLimits::tick();
            if ($token->isLineBreak) {
                $flushLine(true);
                continue;
            }
            if ($token->floatBox !== null) {
                if ($floatHooks === null || $band === null) {
                    continue;
                }
                $fb = $token->floatBox;
                $fw = $fb->getOuterWidth();
                $lineContent = 0.0;
                foreach ($currentRuns as $r) {
                    if (!$r->isWhitespace || $this->preservesSpaces($r)) {
                        $lineContent = $currentWidth;
                        break;
                    }
                }
                if ($pendingFloats === [] && ($lineContent + $fw <= $availW + 0.05 || $currentRuns === [])) {
                    // Fits beside the content of the current line: at the top of this line, which re-wraps around it
                    $handle = ($floatHooks['place'])($fb, $lineY);
                    $placedFloats[] = ['handle' => $handle, 'line' => count($rawLines), 'y' => $lineY];
                    [$bl, $br] = $band($lineY, $strutLh);
                    $lineDx = $bl - $startX;
                    $availW = max(10.0, $br - $bl);
                } else {
                    $pendingFloats[] = $fb;
                }
                continue;
            }

            if ($token->lineStart && !empty($currentRuns)) {
                $flushLine(false); // ::first-line probe: the first line ends before this token
            }

            $preserve = $this->preservesSpaces($token);
            $isNoWrap = $this->isNoWrap($token);

            if ($token->isWhitespace && !$preserve) {
                // Collapse leading whitespace and whitespace sequences spanning element boundaries
                if (empty($currentRuns)) {
                    $pendingLeft = $this->mergeLeftEdge($pendingLeft, $token);
                    continue;
                }
                $last = $currentRuns[count($currentRuns) - 1];
                if ($last->isWhitespace && !$this->preservesSpaces($last)) {
                    $this->mergeRightEdge($last, $token);
                    if ($token->paddingLeft > 0.0 || !empty($token->decoLeft)) {
                        $pendingLeft = $this->mergeLeftEdge($pendingLeft, $token);
                    }
                    continue;
                }
            }

            if ($pendingLeft !== null && !$token->isWhitespace) {
                $this->applyLeftEdge($token, $pendingLeft);
                $pendingLeft = null;
            }

            $tokenTotalWidth = $token->width + $token->paddingLeft + $token->paddingRight;

            if (!empty($currentRuns) && $availW > 1.0 && ($currentWidth + $tokenTotalWidth > $availW + 0.05)) {
                if ($token->isWhitespace && !$isNoWrap && !$preserve) {
                    // The space itself overflows: break here and drop it
                    $pendingLeft = $this->mergeLeftEdge($pendingLeft, $token);
                    $flushLine(false);
                    continue;
                }

                if ($token->breakBefore && !$isNoWrap && ($token->softBreak === null || $currentWidth + $token->softBreakWidth <= $availW + 0.05)) {
                    $this->appendBreakHyphen($currentRuns, $token);
                    $flushLine(false);
                } else {
                    // Glued to the previous run (e.g. "foo<b>bar</b>"): move the whole unbreakable word to the next line
                    $breakIdx = null;
                    for ($k = count($currentRuns) - 1; $k >= 1; $k--) {
                        if ($currentRuns[$k]->breakBefore && $this->breakHyphenFits($currentRuns, $k, $availW)) {
                            $breakIdx = $k;
                            break;
                        }
                    }
                    if ($breakIdx === null && $token->softBreak !== null && !$isNoWrap) {
                        // No earlier opportunity: break at the soft hyphen even though the hyphen slightly overflows
                        $this->appendBreakHyphen($currentRuns, $token);
                        $flushLine(false);
                    }
                    if ($breakIdx !== null) {
                        $carry = array_splice($currentRuns, $breakIdx);
                        $this->appendBreakHyphen($currentRuns, $carry[0]);
                        $flushLine(false);
                        while (!empty($carry) && $carry[0]->isWhitespace && !$this->preservesSpaces($carry[0])) {
                            $dropped = array_shift($carry);
                            if (!empty($carry)) {
                                $this->applyLeftEdge($carry[0], $dropped);
                            }
                        }
                        $currentRuns = $carry;
                        $currentWidth = 0.0;
                        foreach ($carry as $c) {
                            $currentWidth += $c->width + $c->paddingLeft + $c->paddingRight;
                        }
                    }
                }
            }

            // A single word still wider than the line: with `overflow-wrap: anywhere | break-word` (or `word-break:
            // break-word | break-all`) it is broken by characters; otherwise it overflows the box, as in browsers
            if (!$isNoWrap && !$token->isWhitespace && $token->inlineBox === null && ($currentWidth + $tokenTotalWidth > $availW + 0.05) && $availW > 5.0 && $tokenTotalWidth > $availW + 0.05
                && self::breaksOverflowingWords($token->style)) {
                $pieces = $this->splitOversizedWordToken($token, max(5.0, $availW - $currentWidth), $availW);
                $pieceCount = count($pieces);
                foreach ($pieces as $pi => $piece) {
                    $pieceWidth = $piece->width + $piece->paddingLeft + $piece->paddingRight;
                    if (!empty($currentRuns) && ($currentWidth + $pieceWidth > $availW + 0.05)) {
                        $flushLine(false);
                    }
                    $currentRuns[] = $piece;
                    $currentWidth += $pieceWidth;
                    if ($pi < $pieceCount - 1) {
                        $flushLine(false);
                    }
                }
                continue;
            }

            $currentRuns[] = $token;
            $currentWidth += $tokenTotalWidth;
        }

        if (!empty($currentRuns)) {
            $flushLine(true);
        }
        foreach ($pendingFloats as $pf) {
            $placedFloats[] = ['handle' => ($floatHooks['place'])($pf, $lineY), 'line' => count($rawLines), 'y' => $lineY];
        }
        $pendingFloats = [];

        // Drop empty trailing lines produced only by collapsed whitespace
        while (!empty($rawLines) && empty($rawLines[count($rawLines) - 1]['runs']) && !$rawLines[count($rawLines) - 1]['forcedBreak']) {
            array_pop($rawLines);
        }

        // text-overflow: ellipsis (requires a clipped container)
        if (strtolower(trim($container->style->get('text-overflow', 'clip'))) === 'ellipsis'
            && strtolower($container->style->get('overflow', 'visible')) !== 'visible') {
            foreach ($rawLines as $li => $ld) {
                $rawLines[$li]['runs'] = $this->applyEllipsis($ld['runs'], $availableWidth);
            }
        }

        $cursorY = $startY;
        $totalLines = count($rawLines);
        /** @var array<int, float> $lineTops */
        $lineTops = [];

        foreach ($rawLines as $lineIdx => $lineData) {
            \IvoPdf\RenderLimits::tick(); // one LineBox per line: 30 000 lines of one paragraph
            $runs = $lineData['runs'];
            if ($reorder) {
                $runs = $this->reorderLine($runs);
            }
            $isLastLine = ($lineIdx === $totalLines - 1) || $lineData['forcedBreak'];

            // CJK / Thai fragments: justified lines spread space between characters, other lines merge the
            // per-character runs back into few text objects
            $charGaps = [];
            foreach ($runs as $r) {
                if ($r->wide) {
                    if ($textAlign === 'justify' && !$isLastLine
                        && !in_array(strtolower(trim($container->style->get('text-justify', 'auto'))), ['inter-word', 'none'], true)) {
                        $runList = array_values($this->splitWideRuns($runs));
                        $runs = $runList;
                        for ($ri = 0, $rn = count($runList) - 1; $ri < $rn; $ri++) {
                            if ($runList[$ri]->wide && $runList[$ri + 1]->wide && !$runList[$ri]->isWhitespace && !$runList[$ri + 1]->isWhitespace) {
                                $charGaps[spl_object_id($runList[$ri])] = true;
                            }
                        }
                    } else {
                        $runs = $this->mergeWideRuns($runs);
                    }
                    break;
                }
            }

            $lineTops[$lineIdx] = $cursorY;
            $lineBox = new LineBox();
            $lineStartX = $startX + $lineData['dx'];
            $lineBox->x = $lineStartX;
            $lineBox->y = $cursorY;

            // Negative half-leading (line-height smaller than the font's content area) can make either side negative
            $maxAscent = -INF;
            $maxDescent = -INF;
            $maxLineHeight = 0.0;
            $runsTotalWidth = 0.0;
            $spaceCount = 0;
            // vertical-align: top / bottom boxes do not take part in the baseline alignment (CSS 2.1 10.8): the line
            // only grows to their margin-box height
            $topBoxH = 0.0;
            $bottomBoxH = 0.0;

            foreach ($runs as $run) {
                $runsTotalWidth += $run->width + $run->paddingLeft + $run->paddingRight;
                if ($run->isWhitespace) {
                    $spaceCount++;
                }
                if ($run->inlineBox !== null) {
                    $edgeAlign = strtolower(trim($run->style->get('vertical-align', 'baseline')));
                    if ($edgeAlign === 'top' || $edgeAlign === 'bottom') {
                        $boxH = $run->inlineBox->height + $run->inlineBox->marginTop + $run->inlineBox->marginBottom;
                        if ($edgeAlign === 'top') {
                            $topBoxH = max($topBoxH, $boxH);
                        } else {
                            $bottomBoxH = max($bottomBoxH, $boxH);
                        }
                        continue;
                    }
                }

                $lhPt = $this->resolveLineHeight($run->style, $run->fontSizePt);
                $halfLeading = $run->inlineBox !== null ? 0.0 : ($lhPt - ($run->ascent + $run->descent)) / 2.0; // may be negative (CSS 2.1 10.8.1)
                $vOffset = $this->verticalOffset($run);

                // Vertical padding and borders of inline (non-replaced) boxes do not enlarge the line box (CSS 2.1
                // 10.8.1): a padded <span> badge overlaps the lines around it, as in browsers
                $asc = $run->ascent + $halfLeading - $vOffset;
                $desc = $run->descent + $halfLeading + $vOffset;

                $maxAscent = max($maxAscent, $asc);
                $maxDescent = max($maxDescent, $desc);
                if ($run->inlineBox === null) {
                    $maxLineHeight = max($maxLineHeight, $lhPt);
                }
            }

            // The strut (CSS 2.1 10.8.1): every line starts with a zero-width box of the container's font and
            // line-height, so an image-only line still keeps the descent below the baseline, as in browsers
            $strutLh = $this->resolveLineHeight($container->style, $container->fontSizePt);
            [$strutAsc, $strutDesc] = $this->strutMetrics($container, $strutLh);
            $maxAscent = max($maxAscent, $strutAsc);
            $maxDescent = max($maxDescent, $strutDesc);
            if (!is_finite($maxAscent) || !is_finite($maxDescent)) {
                $maxAscent = $strutLh * 0.78;
                $maxDescent = $strutLh * 0.22;
            }

            $lineHeight = max(0.0, $maxLineHeight, $maxAscent + $maxDescent);
            if ($maxAscent + $maxDescent < $lineHeight) {
                // Distribute remaining leading evenly so the glyphs sit centered inside the line box
                $extra = ($lineHeight - $maxAscent - $maxDescent) / 2.0;
                $maxAscent += $extra;
                $maxDescent += $extra;
            }
            if ($bottomBoxH > $lineHeight) {
                $maxAscent += $bottomBoxH - $lineHeight; // the baseline-aligned content sits at the bottom of the line
                $lineHeight = $bottomBoxH;
            }
            if ($topBoxH > $lineHeight) {
                $maxDescent += $topBoxH - $lineHeight; // ... and at its top
                $lineHeight = $topBoxH;
            }
            $lineBox->height = $lineHeight;
            $lineBox->baselineOffset = $maxAscent;

            $indent = ($lineIdx === 0) ? $textIndentPt : 0.0;
            $freeSpace = max(0.0, $lineData['w'] - $runsTotalWidth - $indent);
            $extraSpacePerGap = 0.0;

            $offsetX = $lineStartX + ($baseLevel === 1 ? 0.0 : $indent);
            if ($textAlign === 'center') {
                $offsetX += $freeSpace / 2.0;
            } elseif ($textAlign === 'right' || $textAlign === 'end') {
                $offsetX += $freeSpace;
            } elseif ($textAlign === 'justify' && !$isLastLine && $spaceCount + count($charGaps) > 0 && $freeSpace > 0.0) {
                $extraSpacePerGap = $freeSpace / ($spaceCount + count($charGaps));
            } elseif ($textAlign === 'justify' && $baseLevel === 1) {
                $offsetX += $freeSpace;
            }

            $runCursorX = $offsetX;
            foreach ($runs as $run) {
                $vOffset = $this->verticalOffset($run);

                $run->x = $runCursorX + $run->paddingLeft;
                $run->y = $cursorY + ($maxAscent - $run->ascent) + $vOffset;

                if ($run->inlineBox !== null) {
                    $boxY = $cursorY + $maxAscent - $run->ascent + $vOffset + $run->inlineBox->marginTop;
                    $valign = strtolower($run->style->get('vertical-align', 'baseline'));
                    if ($valign === 'top' || $valign === 'text-top') {
                        $boxY = $cursorY + $run->inlineBox->marginTop;
                    } elseif ($valign === 'bottom' || $valign === 'text-bottom') {
                        $boxY = $cursorY + $lineHeight - $run->inlineBox->height - $run->inlineBox->marginBottom;
                    }
                    $run->inlineBox->translate($run->x - $run->inlineBox->x, $boxY - $run->inlineBox->y);
                }
                if ($run->relDx !== 0.0 || $run->relDy !== 0.0) {
                    $run->x += $run->relDx;
                    $run->y += $run->relDy;
                    $run->inlineBox?->translate($run->relDx, $run->relDy);
                }

                $advance = $run->width + $run->paddingLeft + $run->paddingRight;
                if (($run->isWhitespace || isset($charGaps[spl_object_id($run)])) && $extraSpacePerGap > 0.0) {
                    $run->width += $extraSpacePerGap;
                    $advance += $extraSpacePerGap;
                }

                $runCursorX += $advance;
                $lineBox->addRun($run);
            }

            $lineBox->width = $runCursorX - $offsetX;
            if ($extraSpacePerGap === 0.0 && count($lineBox->runs) > 1) {
                $lineBox->runs = $this->mergeLineRuns($lineBox->runs);
            }
            $container->lines[] = $lineBox;
            $cursorY += $lineHeight;
        }

        // Floats were placed at estimated line tops: align them with the final ones
        foreach ($placedFloats as $pf) {
            $top = $lineTops[$pf['line']] ?? $cursorY;
            if ($pf['handle'][2] === $pf['y'] && abs($top - $pf['y']) > 0.01) {
                ($floatHooks['move'])($pf['handle'], $top - $pf['y']);
            }
        }

        return $cursorY;
    }

    /** `overflow-wrap: anywhere | break-word` or `word-break: break-word | break-all`: an overlong word may break anywhere. */
    private static function breaksOverflowingWords(ComputedStyle $style): bool
    {
        $wrap = strtolower(trim($style->get('overflow-wrap', 'normal')));
        if ($wrap === 'anywhere' || $wrap === 'break-word') {
            return true;
        }
        $wb = strtolower(trim($style->get('word-break', 'normal')));
        return $wb === 'break-word' || $wb === 'break-all';
    }

    /** Whether the inline layout is collecting the floats of the block container being laid out. */
    private bool $collectingFloats = false;

    private function isFloatNode(LayoutBox $node): bool
    {
        return !$node->isTextNode() && in_array(strtolower(trim($node->style->get('float', 'none'))), ['left', 'right'], true);
    }

    /**
     * Shapes a word run with the font and OpenType features of its style: sets its glyph list (null when plain
     * encoding is exact) and its advance width.
     */
    private function shapeRun(TextRun $run): void
    {
        if ($run->text === '' || $run->inlineBox !== null) {
            return;
        }
        $style = $run->style;
        $features = FontFeatures::fromStyle($style, $run->letterSpacingPt);
        $res = $this->fontManager->shape(
            $run->text,
            $style->get('font-family', 'DejaVu Sans'),
            $style->get('font-weight', 'normal'),
            $style->get('font-style', 'normal'),
            $features,
            ($run->bidiLevel & 1) === 1
        );
        $run->glyphs = $res['glyphs'];
        $width = ($res['units'] / 1000.0) * $run->fontSizePt;
        if ($res['count'] > 0 && abs($run->letterSpacingPt) > 0.001) {
            $width += $res['count'] * $run->letterSpacingPt;
        }
        if (str_contains($run->text, '{') && preg_match('/\{(?:PAGE_NUM|PAGE_COUNT|PAGENO|nbpg)\}/', $run->text) === 1) {
            // Page-number placeholders are replaced while drawing: lay them out as one digit wide, not as the
            // placeholder text (the renderer re-aligns the real number)
            $width = $this->fontManager->measureText(
                (string) preg_replace('/\{(?:PAGE_NUM|PAGE_COUNT|PAGENO|nbpg)\}/', '0', $run->text),
                $style->get('font-family', 'DejaVu Sans'),
                $style->get('font-weight', 'normal'),
                $style->get('font-style', 'normal'),
                $run->fontSizePt,
                $run->letterSpacingPt
            );
        }
        $run->width = $width;
    }

    /**
     * Splits CJK fragments of a line into one run per character (for inter-character justification).
     *
     * @param list<TextRun> $runs
     * @return list<TextRun>
     */
    private function splitWideRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            if (!$run->wide || $run->isWhitespace || $run->inlineBox !== null || $run->softBreak !== null
                || !ScriptBreaker::isCjkText($run->text) || preg_match_all('/\X/u', $run->text, $m) < 2) {
                $out[] = $run;
                continue;
            }
            $clusters = $m[0];
            $last = count($clusters) - 1;
            foreach ($clusters as $ci => $cl) {
                $piece = clone $run;
                $piece->text = $cl;
                $piece->glyphs = null;
                if ($ci > 0) {
                    $piece->paddingLeft = 0.0;
                    $piece->decoLeft = [];
                }
                if ($ci < $last) {
                    $piece->paddingRight = 0.0;
                    $piece->decoRight = [];
                }
                $this->shapeRun($piece);
                $out[] = $piece;
            }
        }
        return $out;
    }

    /** @var \WeakMap<ComputedStyle, bool>|null whether the runs of a style may be merged (see mergeLineRuns()) */
    private ?\WeakMap $mergeableStyles = null;

    /**
     * Joins neighbouring runs of a placed line that draw exactly as one: plain (unshaped) text of the same style, link
     * and decorations that touch, so a paragraph line keeps one or a few runs instead of one per word and space
     * (a run is about 0.4 KB: the runs were half of the memory of a long document's layout tree).
     *
     * @param list<TextRun> $runs
     * @return list<TextRun>
     */
    private function mergeLineRuns(array $runs): array
    {
        $out = [];
        $prev = null;
        foreach ($runs as $run) {
            if ($prev !== null && $run->style === $prev->style && $run->glyphs === null && $prev->glyphs === null
                && $run->inlineBox === null && $prev->inlineBox === null && !$run->hasExtra() && !$prev->hasExtra()
                && $run->href === $prev->href && $run->decorations === $prev->decorations
                && $prev->decoRight === [] && $run->decoLeft === [] && !$run->isLineBreak && !$prev->isLineBreak
                && $run->letterSpacingPt === 0.0 && $prev->letterSpacingPt === 0.0 && $run->fontSizePt === $prev->fontSizePt
                && $run->ascent === $prev->ascent && $run->descent === $prev->descent && abs($run->y - $prev->y) < 0.0001
                && abs($prev->x + $prev->width - $run->x) < 0.001 && $this->mergeableStyle($run->style)
                && strpbrk($run->text, "{\t") === false && strpbrk($prev->text, "{\t") === false) {
                $prev->text .= $run->text;
                $prev->width = $run->x + $run->width - $prev->x;
                $prev->isWhitespace = $prev->isWhitespace && $run->isWhitespace;
                continue;
            }
            $out[] = $run;
            $prev = $run;
        }
        return $out;
    }

    /**
     * Runs of this style draw their text with the font's own advances (no word-spacing added to the spaces, no
     * generated page numbers or leaders that are rewritten after pagination).
     */
    private function mergeableStyle(ComputedStyle $style): bool
    {
        $this->mergeableStyles ??= new \WeakMap();
        return $this->mergeableStyles[$style] ??= !$style->has('ivo-leader') && !$style->has('ivo-pageref')
            && in_array(strtolower(trim($style->get('word-spacing', 'normal'))), ['normal', '0', '0px', '0pt', ''], true);
    }

    /**
     * Merges neighbouring per-character CJK / Thai runs of one line that share style and have no box edges
     * between them, so that a paragraph becomes a few text objects instead of one per character.
     *
     * @param list<TextRun> $runs
     * @return list<TextRun>
     */
    private function mergeWideRuns(array $runs): array
    {
        $out = [];
        $prev = null;
        $merged = false;
        foreach ($runs as $run) {
            if ($prev !== null && $prev->wide && $run->wide && !$prev->isWhitespace && !$run->isWhitespace
                && $prev->inlineBox === null && $run->inlineBox === null && $prev->style === $run->style
                && $prev->paddingRight == 0.0 && $run->paddingLeft == 0.0 && $run->softBreak === null
                && $prev->href === $run->href && $prev->bidiLevel === $run->bidiLevel
                && $prev->letterSpacingPt === $run->letterSpacingPt && $prev->decorations === $run->decorations
                && $prev->paddingTop === $run->paddingTop && $prev->paddingBottom === $run->paddingBottom
                && $run->bidiPre === [] && $prev->bidiPost === []) {
                $prev->text .= $run->text;
                $prev->width += $run->width;
                $prev->paddingRight = $run->paddingRight;
                $prev->decoRight = $run->decoRight;
                $prev->glyphs = null;
                $prev->bidiPost = $run->bidiPost;
                $merged = true;
                continue;
            }
            if ($merged && $prev !== null) {
                $this->shapeRun($prev);
                $merged = false;
            }
            $out[] = $run;
            $prev = $run;
        }
        if ($merged && $prev !== null) {
            $this->shapeRun($prev);
        }
        return $out;
    }

    /**
     * Runs the Unicode bidi algorithm over the paragraph's tokens, splits word tokens at embedding-level
     * boundaries, assigns levels and re-shapes right-to-left pieces.
     *
     * @param list<TextRun> $tokens
     * @param int|null $baseLevel 0/1, or null to detect the paragraph direction from its first strong character
     * @param list<array{members: list<TextRun>, oid: int, decorated: bool, left: float, right: float, leftEdge: float, rightEdge: float}> $spans
     * @return int the resolved paragraph level
     */
    private function applyBidi(array &$tokens, ?int $baseLevel, array $spans = []): int
    {
        $cps = [];
        $span = [];
        foreach ($tokens as $ti => $t) {
            foreach ($t->bidiPre as $c) {
                $cps[] = $c;
            }
            $start = count($cps);
            if ($t->inlineBox !== null) {
                $cps[] = 0xFFFC;
            } elseif ($t->isLineBreak) {
                $cps[] = 0x2028;
            } else {
                foreach (FontManager::utf8ToCodepoints($t->text) as $c) {
                    $cps[] = $c;
                }
            }
            $span[$ti] = [$start, count($cps) - $start];
            foreach ($t->bidiPost as $c) {
                $cps[] = $c;
            }
        }

        $res = Bidi::resolve($cps, $baseLevel);
        $levels = $res['levels'];
        $base = $res['base'];

        $out = [];
        /** @var array<int, list<TextRun>> $pieces original token id => resulting tokens */
        $pieces = [];
        foreach ($tokens as $ti => $t) {
            [$st, $len] = $span[$ti];
            if ($len === 0) {
                $t->bidiLevel = $base;
                $out[] = $t;
                continue;
            }
            if ($t->inlineBox !== null || $t->isLineBreak || $t->isWhitespace) {
                $t->bidiLevel = $levels[$st];
                $out[] = $t;
                continue;
            }
            $segs = [];
            $from = 0;
            $cur = $levels[$st];
            for ($k = 1; $k < $len; $k++) {
                if ($levels[$st + $k] !== $cur) {
                    $segs[] = [$from, $k, $cur];
                    $from = $k;
                    $cur = $levels[$st + $k];
                }
            }
            $segs[] = [$from, $len, $cur];

            if (count($segs) === 1) {
                $t->bidiLevel = $cur;
                if (($cur & 1) === 1) {
                    $this->shapeRun($t);
                }
                $out[] = $t;
                continue;
            }

            $last = count($segs) - 1;
            $template = clone $t;
            $made = [];
            foreach ($segs as $si => [$a, $b, $lvl]) {
                // The first piece keeps the identity of the original token (inline element records refer to it)
                $piece = $si === 0 ? $t : clone $template;
                $piece->text = '';
                for ($k = $a; $k < $b; $k++) {
                    $piece->text .= mb_chr($cps[$st + $k], 'UTF-8');
                }
                $piece->bidiLevel = $lvl;
                $piece->bidiPre = $si === 0 ? $template->bidiPre : [];
                $piece->bidiPost = $si === $last ? $template->bidiPost : [];
                if ($si !== 0) {
                    $piece->paddingLeft = 0.0;
                    $piece->decoLeft = [];
                }
                if ($si !== $last) {
                    $piece->paddingRight = 0.0;
                    $piece->decoRight = [];
                }
                if ($si > 0) {
                    $piece->breakBefore = false;
                    $piece->afterHyphen = false;
                    $piece->softBreak = null;
                    $piece->softBreakWidth = 0.0;
                }
                $this->shapeRun($piece);
                $out[] = $piece;
                $made[] = $piece;
            }
            $pieces[spl_object_id($t)] = $made;
        }
        $tokens = $out;
        $this->relocateSpanEdges($spans, $pieces);
        return $base;
    }

    /**
     * An inline element whose content is entirely right-to-left shows its logical start at the right: moves its
     * physical left/right padding, border and decoration offsets from the first/last logical token to the last/first.
     *
     * @param list<array{members: list<TextRun>, oid: int, decorated: bool, left: float, right: float, leftEdge: float, rightEdge: float}> $spans
     * @param array<int, list<TextRun>> $pieces
     */
    private function relocateSpanEdges(array $spans, array $pieces): void
    {
        foreach ($spans as $i => $rec) {
            $members = $rec['members'];
            $allRtl = true;
            foreach ($members as $m) {
                foreach ($pieces[spl_object_id($m)] ?? [$m] as $p) {
                    if (($p->bidiLevel & 1) === 0) {
                        $allRtl = false;
                        break 2;
                    }
                }
            }
            if (!$allRtl) {
                continue;
            }
            $firstTok = ($pieces[spl_object_id($members[0])] ?? [$members[0]])[0];
            $lastList = $pieces[spl_object_id($members[count($members) - 1])] ?? [$members[count($members) - 1]];
            $lastTok = $lastList[count($lastList) - 1];
            if ($firstTok === $lastTok) {
                continue;
            }
            $lt = $rec['left'];
            $rt = $rec['right'];
            $oid = $rec['oid'];
            $firstTok->paddingLeft -= $lt;
            $firstTok->paddingRight += $rt;
            $lastTok->paddingRight -= $rt;
            $lastTok->paddingLeft += $lt;
            if ($rec['decorated']) {
                unset($firstTok->decoLeft[$oid], $lastTok->decoRight[$oid]);
                $lastTok->decoLeft[$oid] = $lastTok->paddingLeft - $lt + $rec['leftEdge'];
                $firstTok->decoRight[$oid] = $firstTok->paddingRight - $rt + $rec['rightEdge'];
            }
            for ($j = $i + 1; $j < count($spans); $j++) {
                $o = $spans[$j]['oid'];
                if (isset($firstTok->decoLeft[$o])) {
                    $firstTok->decoLeft[$o] -= $lt;
                }
                if (isset($lastTok->decoRight[$o])) {
                    $lastTok->decoRight[$o] -= $rt;
                }
            }
        }
    }

    /**
     * Visual (left-to-right) order of the runs of one finished line (UAX #9 rule L2).
     *
     * @param list<TextRun> $runs
     * @return list<TextRun>
     */
    private function reorderLine(array $runs): array
    {
        $levels = [];
        $mixed = false;
        foreach ($runs as $run) {
            $levels[] = $run->bidiLevel;
            if ($run->bidiLevel !== 0) {
                $mixed = true;
            }
        }
        if (!$mixed) {
            return $runs;
        }
        $out = [];
        foreach (Bidi::reorder($levels) as $i) {
            $out[] = $runs[$i];
        }
        return $out;
    }

    /**
     * Records the Unicode bidi controls implied by an inline element's unicode-bidi / direction on its first and last token.
     */
    private function addBidiControls(LayoutBox $node, TextRun $first, TextRun $last): void
    {
        $ub = strtolower(trim($node->style->get('unicode-bidi', 'normal')));
        if ($ub === 'normal' || $ub === '') {
            return;
        }
        $rtl = strtolower(trim($node->style->get('direction', 'ltr'))) === 'rtl';
        switch ($ub) {
            case 'embed':
                $pre = [$rtl ? 0x202B : 0x202A];
                $post = [0x202C];
                break;
            case 'bidi-override':
                $pre = [$rtl ? 0x202E : 0x202D];
                $post = [0x202C];
                break;
            case 'isolate':
                $pre = [$rtl ? 0x2067 : 0x2066];
                $post = [0x2069];
                break;
            case 'isolate-override':
                $pre = [$rtl ? 0x2067 : 0x2066, $rtl ? 0x202E : 0x202D];
                $post = [0x202C, 0x2069];
                break;
            case 'plaintext':
                $pre = [0x2068];
                $post = [0x2069];
                break;
            default:
                return;
        }
        $this->bidiFlag = true;
        $first->bidiPre = array_merge($pre, $first->bidiPre);
        $last->bidiPost = array_merge($last->bidiPost, $post);
    }

    private function verticalOffset(TextRun $run): float
    {
        $valign = strtolower($run->style->get('vertical-align', 'baseline'));
        if ($valign === 'super') {
            return -$run->fontSizePt * 0.45;
        }
        if ($valign === 'sub') {
            return $run->fontSizePt * 0.22;
        }
        if ($valign !== 'baseline' && $valign !== '' && preg_match('/^-?[\d.]/', $valign)) {
            // Length / percentage: positive raises the box
            $ref = $this->resolveLineHeight($run->style, $run->fontSizePt);
            return -CssUnit::toPt($valign, $ref, $run->fontSizePt);
        }
        return 0.0;
    }

    private function preservesSpaces(TextRun $run): bool
    {
        return in_array(strtolower($run->style->get('white-space', 'normal')), ['pre', 'pre-wrap', 'break-spaces'], true);
    }

    private function isNoWrap(TextRun $run): bool
    {
        return in_array(strtolower($run->style->get('white-space', 'normal')), ['nowrap', 'pre'], true);
    }

    /**
     * Draws the hyphenation character at the end of a line that breaks right before a soft-break run.
     *
     * @param list<TextRun> $lineRuns
     */
    private function appendBreakHyphen(array $lineRuns, TextRun $next): void
    {
        $last = end($lineRuns);
        if ($next->softBreak === null || $last === false || $last->isWhitespace || $last->inlineBox !== null) {
            return;
        }
        $last->text .= $next->softBreak;
        $last->width += $next->softBreakWidth;
    }

    /**
     * Whether breaking before $runs[$idx] leaves room for the hyphenation character on the finished line.
     *
     * @param list<TextRun> $runs
     */
    private function breakHyphenFits(array $runs, int $idx, float $availableWidth): bool
    {
        if ($runs[$idx]->softBreak === null) {
            return true;
        }
        $w = $runs[$idx]->softBreakWidth;
        for ($i = 0; $i < $idx; $i++) {
            $w += $runs[$i]->width + $runs[$i]->paddingLeft + $runs[$i]->paddingRight;
        }
        return $w <= $availableWidth + 0.05;
    }

    /**
     * Marks for every token whether the line may break before it.
     *
     * @param list<TextRun> $tokens
     */
    private function computeBreakOpportunities(array $tokens): void
    {
        $prev = null;
        foreach ($tokens as $token) {
            if ($prev === null || $prev->isLineBreak || $token->isLineBreak) {
                $token->breakBefore = true;
            } elseif ($this->isNoWrap($token) && $this->isNoWrap($prev)) {
                $token->breakBefore = false;
            } elseif ($token->isWbr) {
                $token->breakBefore = true; // <wbr>: a break opportunity without a hyphen
            } elseif ($token->isWhitespace || $prev->isWhitespace) {
                $token->breakBefore = true;
            } elseif ($token->inlineBox !== null || $prev->inlineBox !== null) {
                $token->breakBefore = true;
            } elseif (($prev->wide || $token->wide) && !$token->afterHyphen) {
                // CJK / Thai text glued across inline element boundaries ("日本<b>語</b>")
                $token->breakBefore = ScriptBreaker::breakBetween($prev->text, $token->text, $token->style);
            } else {
                $token->breakBefore = $token->afterHyphen;
            }
            $prev = $token;
        }
    }

    /**
     * Removes trailing collapsible whitespace, moving its right-side decorations onto the previous run.
     *
     * @param list<TextRun> $runs
     */
    private function trimTrailingWhitespace(array &$runs): void
    {
        while (!empty($runs)) {
            $last = $runs[count($runs) - 1];
            if (!$last->isWhitespace || $this->preservesSpaces($last)) {
                break;
            }
            array_pop($runs);
            if (!empty($runs)) {
                $this->mergeRightEdge($runs[count($runs) - 1], $last);
            }
        }
    }

    private function mergeRightEdge(TextRun $target, TextRun $dropped): void
    {
        if ($dropped->paddingRight > 0.0) {
            $target->paddingRight += $dropped->paddingRight;
        }
        foreach ($dropped->decoRight as $oid => $off) {
            $target->decoRight[$oid] = ($target->decoRight[$oid] ?? 0.0) + $off;
        }
    }

    private function mergeLeftEdge(?TextRun $pending, TextRun $dropped): ?TextRun
    {
        if ($dropped->paddingLeft <= 0.0 && empty($dropped->decoLeft)) {
            return $pending;
        }
        if ($pending === null) {
            $pending = new TextRun('', $dropped->style);
        }
        $pending->paddingLeft += $dropped->paddingLeft;
        foreach ($dropped->decoLeft as $oid => $off) {
            $pending->decoLeft[$oid] = $off;
        }
        return $pending;
    }

    private function applyLeftEdge(TextRun $target, TextRun $source): void
    {
        $target->paddingLeft += $source->paddingLeft;
        foreach ($source->decoLeft as $oid => $off) {
            if (!isset($target->decoLeft[$oid])) {
                $target->decoLeft[$oid] = $off + ($target->paddingLeft - $source->paddingLeft);
            }
        }
    }

    /** Longest string (bytes) shaped as one word; longer unbroken fragments are cut into consecutive chunks without a break opportunity. */
    private const MAX_SHAPE_CHUNK = 8192;

    /**
     * Shaping a multi-megabyte fragment at once needs ~1 KB per character: cut such fragments into chunks that follow
     * each other with no break opportunity (the forced character breaking of splitOversizedWordToken() still applies).
     *
     * @param list<array{0: string, 1: bool, 2: bool}> $frags
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    private static function chunkFragments(array $frags): array
    {
        $needs = false;
        foreach ($frags as $f) {
            if (strlen($f[0]) > self::MAX_SHAPE_CHUNK) {
                $needs = true;
                break;
            }
        }
        if (!$needs) {
            return $frags;
        }
        $out = [];
        foreach ($frags as [$text, $breakAllowed, $isSoft]) {
            $len = strlen($text);
            for ($o = 0; $o < $len;) {
                $chunk = $len <= self::MAX_SHAPE_CHUNK ? $text : mb_strcut($text, $o, self::MAX_SHAPE_CHUNK, 'UTF-8');
                if ($chunk === '') {
                    $chunk = substr($text, $o, self::MAX_SHAPE_CHUNK);
                }
                $out[] = [$chunk, $o === 0 ? $breakAllowed : false, $o === 0 ? $isSoft : false];
                $o += strlen($chunk);
            }
        }
        return $out;
    }

    /**
     * Splits a single oversized word TextRun into multiple character-level TextRuns that each fit within $maxWidth.
     *
     * @return list<TextRun>
     */
    private function splitOversizedWordToken(TextRun $token, float $firstMaxWidth, float $lineMaxWidth): array
    {
        $chars = preg_split('//u', $token->text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars || count($chars) <= 1) {
            return [$token];
        }

        $style = $token->style;
        $fontFamily = $style->get('font-family', 'DejaVu Sans');
        $fontWeight = $style->get('font-weight', 'normal');
        $fontStyle = $style->get('font-style', 'normal');
        $fontSizePt = $token->fontSizePt;
        $letterSpacingPt = $token->letterSpacingPt;

        $pieces = [];
        $currentStr = '';
        $currentW = 0.0;
        $limit = $firstMaxWidth;

        $makePiece = function (string $text, float $w) use ($token, $style, $fontSizePt, $letterSpacingPt, &$pieces): TextRun {
            $r = new TextRun($text, $style, $w, $fontSizePt, $token->ascent, $token->descent, false, $token->href);
            $r->letterSpacingPt = $letterSpacingPt;
            $r->bidiLevel = $token->bidiLevel;
            $this->shapeRun($r);
            $r->paddingTop = $token->paddingTop;
            $r->paddingBottom = $token->paddingBottom;
            $r->decorations = $token->decorations;
            if (empty($pieces)) {
                $r->paddingLeft = $token->paddingLeft;
                $r->decoLeft = $token->decoLeft;
                $r->breakBefore = $token->breakBefore;
            }
            return $r;
        };

        // Linear: the advance of every distinct character is measured once and summed; a finished line is measured
        // once as a whole (kerning), so the work is proportional to the word length, not to length x line length
        $adv = [];
        $sum = 0.0;
        $n = count($chars);
        $start = 0;
        for ($k = 0; $k < $n; $k++) {
            $ch = $chars[$k];
            $a = $adv[$ch] ?? ($adv[$ch] = $this->fontManager->measureText($ch, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt));
            $padLeft = empty($pieces) ? $token->paddingLeft : 0.0;
            if ($k > $start && $sum + $a + $padLeft > $limit) {
                $text = implode('', array_slice($chars, $start, $k - $start));
                $w = $this->fontManager->measureText($text, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt);
                while ($k - $start > 1 && $w + $padLeft > $limit) { // kerning / shaping made the line wider than the sum
                    $k--;
                    $text = implode('', array_slice($chars, $start, $k - $start));
                    $w = $this->fontManager->measureText($text, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt);
                }
                $pieces[] = $makePiece($text, $w);
                $start = $k;
                $sum = 0.0;
                $limit = $lineMaxWidth;
                $k--; // re-examine the first character of the next line
                continue;
            }
            $sum += $a;
        }
        if ($start < $n) {
            $currentStr = implode('', array_slice($chars, $start));
            $currentW = $this->fontManager->measureText($currentStr, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt);
        }

        if ($currentStr !== '') {
            $r = $makePiece($currentStr, $currentW);
            $r->paddingRight = $token->paddingRight;
            $r->decoRight = $token->decoRight;
            $pieces[] = $r;
        }

        return $pieces;
    }

    /**
     * Recursively flattens an inline node into word/whitespace/inline-block TextRun tokens.
     *
     * @param list<TextRun> $tokens
     */
    private function collectInlineTokens(LayoutBox $node, float $availableWidth, array &$tokens): void
    {
        \IvoPdf\RenderLimits::tick(); // 100 000 <br> / links make 200 000 tokens before the first line is built
        if (!$node->isTextNode() && $node->parent !== null && $this->collectingFloats
            && in_array(strtolower(trim($node->style->get('float', 'none'))), ['left', 'right'], true)) {
            // A float of the block container met in its inline content (already sized by the block layout)
            $marker = new TextRun('', $node->style, 0.0, $node->fontSizePt, 0.0, 0.0);
            $marker->floatBox = $node;
            $marker->breakBefore = false;
            $tokens[] = $marker;
            return;
        }
        if ($node->tagName === 'br') {
            $brRun = new TextRun('', $node->style, 0.0, $node->fontSizePt);
            $brRun->isLineBreak = true;
            $tokens[] = $brRun;
            return;
        }
        if ($node->tagName === 'wbr') {
            $wbrRun = new TextRun('', $node->style, 0.0, $node->fontSizePt, $node->fontSizePt * 0.8, $node->fontSizePt * 0.2);
            $wbrRun->isWbr = true;
            $tokens[] = $wbrRun;
            return;
        }

        $display = $node->getDisplay();
        if (in_array($display, ['inline-block', 'inline-flex', 'inline-grid', 'inline-table'], true) || $node->tagName === 'img' || $node->tagName === 'svg') {
            $this->engine->resolveBoxMetrics($node, $availableWidth);
            $node->forcedBorderBoxWidth = null;
            $wCss = strtolower(trim($node->style->get('width', 'auto')));
            if ($wCss === 'auto' || $wCss === '' || in_array($wCss, ['fit-content', 'max-content', 'min-content'], true)) {
                $intrinsic = $this->engine->measureIntrinsicWidth($node, $availableWidth);
                $node->forcedBorderBoxWidth = $this->engine->clampToMinMaxWidth(
                    $node,
                    min(max(0.0, $availableWidth - $node->marginLeft - $node->marginRight), $wCss === 'min-content' ? $intrinsic['min'] : $intrinsic['max']),
                    $availableWidth
                );
            }
            $this->engine->layoutElement($node, 0.0, 0.0, $availableWidth);

            // Inline-blocks sit on the baseline of their last line box (replaced elements: on their bottom margin edge)
            $baselineFromTop = null;
            if ($node->formControl !== null) {
                $baselineFromTop = $node->formBaseline; // form controls: text baseline inside the box
            } elseif ($node->tagName !== 'img' && $node->tagName !== 'svg' && strtolower($node->style->get('overflow', 'visible')) === 'visible') {
                $baselineFromTop = $this->findLastBaseline($node);
            }
            $valign = strtolower($node->style->get('vertical-align', 'baseline'));
            if ($baselineFromTop !== null && $baselineFromTop > $node->height && in_array($valign, ['top', 'text-top', 'bottom', 'text-bottom'], true)) {
                // A top / bottom aligned box adds its margin box to the line, also when its content overflows a
                // definite height (its last line, and so its baseline, lies below the box)
                $baselineFromTop = $node->height;
            }
            if ($valign === 'middle') {
                $xMid = $node->parent !== null ? $node->parent->fontSizePt * 0.3 : $node->fontSizePt * 0.3;
                $ascent = $node->marginTop + $node->height / 2.0 + $xMid;
                $descent = $node->height / 2.0 - $xMid + $node->marginBottom;
            } elseif ($baselineFromTop !== null) {
                $ascent = $node->marginTop + $baselineFromTop;
                $descent = max(0.0, $node->height - $baselineFromTop) + $node->marginBottom;
            } else {
                $ascent = $node->marginTop + $node->height;
                $descent = $node->marginBottom;
            }

            $run = new TextRun('', $node->style, $node->width, $node->fontSizePt, $ascent, $descent, false, $node->href);
            $run->inlineBox = $node;
            $run->paddingLeft = $node->marginLeft;
            $run->paddingRight = $node->marginRight;
            $tokens[] = $run;
            return;
        }

        if ($node->isTextNode()) {
            $text = $node->textContent ?? '';
            if ($text === '') {
                return;
            }

            if (!$this->bidiFlag && preg_match(Bidi::RTL_REGEX, $text) === 1) {
                $this->bidiFlag = true;
            }

            $style = $node->style;
            $fontFamily = $style->get('font-family', 'DejaVu Sans');
            $fontWeight = $style->get('font-weight', 'normal');
            $fontStyle = $style->get('font-style', 'normal');
            $fontSizePt = $style->getFontSizePt();
            $letterSpacingPt = $this->engine->resolveLength($style->get('letter-spacing', '0'), 0.0, $fontSizePt);
            $wordSpacingPt = $this->engine->resolveLength($style->get('word-spacing', '0'), 0.0, $fontSizePt);

            $ttf = $this->fontManager->resolveFont($fontFamily, $fontWeight, $fontStyle);
            $ascentPt = ($ttf->ascent / 1000.0) * $fontSizePt;
            $descentPt = (abs($ttf->descent) / 1000.0) * $fontSizePt;

            $whiteSpace = strtolower($style->get('white-space', 'normal'));
            $preserveSpaces = in_array($whiteSpace, ['pre', 'pre-wrap', 'break-spaces'], true);
            $segments = in_array($whiteSpace, ['pre', 'pre-wrap', 'pre-line', 'break-spaces'], true)
                ? (preg_split('/\r\n|\r|\n/', $text) ?: [$text])
                : [$text];
            $segmentCount = count($segments);

            foreach ($segments as $si => $segment) {
                $this->tabLimit = $availableWidth;
                $this->tokenizeStringIntoRuns(
                    $segment,
                    $style,
                    $fontFamily,
                    $fontWeight,
                    $fontStyle,
                    $fontSizePt,
                    $letterSpacingPt,
                    $wordSpacingPt,
                    $ascentPt,
                    $descentPt,
                    $node->href,
                    $tokens,
                    $preserveSpaces
                );
                if ($si < $segmentCount - 1) {
                    $brRun = new TextRun('', $style, 0.0, $fontSizePt, $ascentPt, $descentPt);
                    $brRun->isLineBreak = true;
                    $tokens[] = $brRun;
                }
            }
            return;
        }

        // Inline element (e.g. <span>, <strong>, <em>, <a>, <code>, <mark>, <sub>, <sup>)
        $this->engine->resolveBoxMetrics($node, $availableWidth);
        $padTop = $node->paddingTop;
        $padBottom = $node->paddingBottom;
        $leftEdge = $node->paddingLeft + $node->borderLeft;
        $rightEdge = $node->paddingRight + $node->borderRight;

        $beforeCount = count($tokens);
        foreach ($node->children as $child) {
            $this->collectInlineTokens($child, $availableWidth, $tokens);
        }
        $afterCount = count($tokens);

        // Find first/last real (non line-break) tokens of this element
        $first = null;
        $last = null;
        for ($i = $beforeCount; $i < $afterCount; $i++) {
            if ($tokens[$i]->isLineBreak) {
                continue;
            }
            $first ??= $i;
            $last = $i;
        }

        if ($first === null) {
            if (self::hasAnchor($node)) {
                $this->emptyAnchors[] = [$afterCount, $node]; // <a name="x"></a>: lands on the next token, takes no space
            }
            // Empty inline element with padding/border still occupies horizontal space
            if ($leftEdge + $rightEdge + $node->marginLeft + $node->marginRight > 0.05 && $this->hasInlineDecoration($node)) {
                $run = new TextRun("\u{200B}", $node->style, 0.0, $node->fontSizePt, $node->fontSizePt * 0.8, $node->fontSizePt * 0.2);
                $run->paddingLeft = $leftEdge + $node->marginLeft;
                $run->paddingRight = $rightEdge + $node->marginRight;
                $run->decorations[] = $node;
                $oid = spl_object_id($node);
                $run->decoLeft[$oid] = $leftEdge;
                $run->decoRight[$oid] = $rightEdge;
                $tokens[] = $run;
            }
            return;
        }

        $this->addBidiControls($node, $tokens[$first], $tokens[$last]);

        if (strtolower(trim($node->style->get('position', 'static'))) === 'relative') {
            // position: relative on an inline element shifts its fragments (and its background) after line layout
            [$rdx, $rdy] = $this->relativeOffset($node, $availableWidth);
            if ($rdx !== 0.0 || $rdy !== 0.0) {
                for ($i = $beforeCount; $i < $afterCount; $i++) {
                    $tokens[$i]->relDx += $rdx;
                    $tokens[$i]->relDy += $rdy;
                }
            }
        }

        $decorated = $this->hasInlineDecoration($node);
        $anchored = !$decorated && self::hasAnchor($node);
        $oid = spl_object_id($node);
        for ($i = $beforeCount; $i < $afterCount; $i++) {
            $tokens[$i]->paddingTop = max($tokens[$i]->paddingTop, $padTop + $node->borderTop);
            $tokens[$i]->paddingBottom = max($tokens[$i]->paddingBottom, $padBottom + $node->borderBottom);
            if ($decorated || $anchored) {
                array_unshift($tokens[$i]->decorations, $node);
            }
        }
        if ($decorated) {
            $tokens[$first]->decoLeft[$oid] = $tokens[$first]->paddingLeft + $leftEdge;
            $tokens[$last]->decoRight[$oid] = $tokens[$last]->paddingRight + $rightEdge;
        }
        $tokens[$first]->paddingLeft += $leftEdge + $node->marginLeft;
        $tokens[$last]->paddingRight += $rightEdge + $node->marginRight;
        if ($first !== $last && ($leftEdge + $node->marginLeft > 0.0 || $rightEdge + $node->marginRight > 0.0)) {
            // Remembered so that bidi reordering can move the physical edges of fully right-to-left spans
            $this->spanRecords[] = [
                'members' => array_slice($tokens, $first, $last - $first + 1),
                'oid' => $oid,
                'decorated' => $decorated,
                'left' => $leftEdge + $node->marginLeft,
                'right' => $rightEdge + $node->marginRight,
                'leftEdge' => $leftEdge,
                'rightEdge' => $rightEdge,
            ];
        }
    }

    /**
     * `position: relative` offsets of an inline element: left wins over right, top over bottom.
     *
     * @return array{0: float, 1: float}
     */
    private function relativeOffset(LayoutBox $node, float $availableWidth): array
    {
        $len = function (string $prop) use ($node, $availableWidth): ?float {
            $v = strtolower(trim($node->style->get($prop, 'auto')));
            if ($v === 'auto' || $v === '' || ($prop !== 'left' && $prop !== 'right' && str_ends_with($v, '%'))) {
                return null;
            }
            return $this->engine->resolveLength($v, $availableWidth, $node->fontSizePt);
        };
        $dx = $len('left') ?? (($r = $len('right')) !== null ? -$r : 0.0);
        $dy = $len('top') ?? (($b = $len('bottom')) !== null ? -$b : 0.0);
        return [$dx, $dy];
    }

    /** Whether the element is a link destination (id attribute, or name on <a>). */
    private static function hasAnchor(LayoutBox $node): bool
    {
        $el = $node->domElement;
        if ($el === null || !$el->hasAttributes()) {
            return false;
        }
        return trim($el->getAttribute('id')) !== '' || ($node->tagName === 'a' && trim($el->getAttribute('name')) !== '');
    }

    /**
     * Gives the anchors of empty inline elements to the nearest following (else preceding) visible token. They ride in
     * TextRun::$decorations, which paints nothing for an element without background or border; the renderer reads them
     * to register named destinations at the position of the first line fragment.
     *
     * @param list<TextRun> $tokens
     */
    private function attachEmptyAnchors(array $tokens): void
    {
        foreach ($this->emptyAnchors as [$index, $node]) {
            $target = null;
            for ($i = $index, $n = count($tokens); $i < $n; $i++) {
                if (!$tokens[$i]->isLineBreak && !$tokens[$i]->isWhitespace) {
                    $target = $tokens[$i];
                    break;
                }
            }
            for ($i = min($index, count($tokens)) - 1; $target === null && $i >= 0; $i--) {
                if (!$tokens[$i]->isLineBreak && !$tokens[$i]->isWhitespace) {
                    $target = $tokens[$i];
                }
            }
            if ($target !== null) {
                $target->decorations[] = $node;
            }
        }
    }

    private function hasInlineDecoration(LayoutBox $node): bool
    {
        if (CssColor::parse($node->style->get('background-color', 'transparent')) !== null) {
            return true;
        }
        $bg = strtolower($node->style->get('background-image', 'none'));
        if ($bg !== 'none' && $bg !== '') {
            return true;
        }
        return $node->borderTop > 0.01 || $node->borderRight > 0.01 || $node->borderBottom > 0.01 || $node->borderLeft > 0.01;
    }

    /**
     * Distance from the top of $box to the baseline of its last line box (searching descendants), or null.
     */
    private function findLastBaseline(LayoutBox $box): ?float
    {
        $best = null;
        $this->walkLastBaseline($box, $best);
        return $best !== null ? $best - $box->y : null;
    }

    private function walkLastBaseline(LayoutBox $box, ?float &$best): void
    {
        foreach ($box->lines as $line) {
            $b = $line->y + $line->baselineOffset;
            if ($best === null || $b > $best) {
                $best = $b;
            }
        }
        foreach ($box->children as $child) {
            if (!$child->isTextNode() && !$child->isPositionedOutOfFlow()) {
                $this->walkLastBaseline($child, $best);
            }
        }
    }

    /**
     * @param list<TextRun> $tokens
     */
    private function tokenizeStringIntoRuns(
        string $text,
        ComputedStyle $style,
        string $fontFamily,
        string $fontWeight,
        string $fontStyle,
        float $fontSizePt,
        float $letterSpacingPt,
        float $wordSpacingPt,
        float $ascentPt,
        float $descentPt,
        ?string $href,
        array &$tokens,
        bool $preserveSpaces = false
    ): void {
        if (!$preserveSpaces && ScriptBreaker::needsSegmentation($text)) {
            $text = ScriptBreaker::joinSegmentBreaks($text);
        }
        // Only ASCII whitespace separates words; U+00A0 (&nbsp;) stays inside the word and never breaks or collapses
        $parts = preg_split('/([ \t\n\r\f]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return;
        }

        $scMode = TextEffects::smallCapsMode($style);
        if ($scMode > 0 && TextEffects::fontHasSmallCaps($this->fontManager->resolveFont($fontFamily, $fontWeight, $fontStyle), $scMode)) {
            $scMode = 0; // the font substitutes small capitals itself (smcp / c2sc through FontFeatures)
        }

        foreach ($parts as $part) {
            $isSpace = trim($part, " \t\n\r\f") === '';
            if ($isSpace) {
                if ($preserveSpaces && str_contains($part, "\t")) {
                    [$part, $w] = $this->expandTabs($part, $tokens, $style, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt, $wordSpacingPt);
                } else {
                    $part = $preserveSpaces ? $part : ' ';
                    $w = $this->fontManager->measureText($part, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt)
                        + $wordSpacingPt * strlen($part);
                }
                $run = new TextRun($part, $style, $w, $fontSizePt, $ascentPt, $descentPt, true, $href);
                $run->letterSpacingPt = $letterSpacingPt;
                $tokens[] = $run;
                continue;
            }

            // Break opportunities after hyphens / dashes / slashes inside long words ("well-known", "a/b")
            $pieces = preg_split('/(?<=[\p{L}\p{N}][\-\x{2010}\x{2013}\x{2014}\/])(?=[\p{L}\p{N}])/u', $part) ?: [$part];
            $wide = ScriptBreaker::needsSegmentation($part);
            if ($wide) {
                // CJK / Hangul / Thai ...: UAX #14 break opportunities, kinsoku, keep-all / break-all
                $flat = [];
                foreach ($pieces as $pc) {
                    array_push($flat, ...ScriptBreaker::segment($pc, $style));
                }
                $pieces = $flat;
            } else {
                $pieces = \IvoPdf\Text\WordBreakMode::apply($pieces, $style);
            }
            foreach (self::chunkFragments(SoftBreaker::expand($pieces, $style)) as [$piece, $breakAllowed, $isSoft]) {
                // font-variant: small-caps without native glyphs: lowercase letters become capitals at a reduced size
                $subs = $scMode > 0 ? TextEffects::smallCapsSegments($piece, $scMode) : [[$piece, false]];
                foreach ($subs as $subIdx => [$subText, $isSmall]) {
                    $cStyle = $style;
                    $cSize = $fontSizePt;
                    $cAscent = $ascentPt;
                    $cDescent = $descentPt;
                    $origText = $subText;
                    if ($isSmall) {
                        $cStyle = $this->smallCapsStyle($style, $fontSizePt);
                        $cSize = $fontSizePt * TextEffects::SMALL_CAPS_SCALE;
                        $cAscent = $ascentPt * TextEffects::SMALL_CAPS_SCALE;
                        $cDescent = $descentPt * TextEffects::SMALL_CAPS_SCALE;
                        $subText = mb_strtoupper($subText, 'UTF-8');
                    }
                    // Characters missing from the font are rendered with a fallback family (glued to the same word)
                    $segments = $this->splitByFontCoverage($subText, $cStyle, $fontFamily, $fontWeight, $fontStyle);
                    foreach ($segments as $si => [$segment, $segStyle]) {
                        $run = new TextRun($segment, $segStyle, 0.0, $cSize, $cAscent, $cDescent, false, $href);
                        if ($isSmall && count($segments) === 1 && $origText !== $segment) {
                            $run->actualText = $origText; // extracted / searched as the original lowercase text
                        }
                        $run->letterSpacingPt = $letterSpacingPt;
                        $run->wide = $wide;
                        $this->shapeRun($run);
                        $run->afterHyphen = $breakAllowed && $si === 0 && $subIdx === 0;
                        if ($isSoft && $si === 0 && $subIdx === 0) {
                            $run->softBreak = SoftBreaker::hyphenChar($style);
                            $run->softBreakWidth = $this->fontManager->measureText($run->softBreak, $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt);
                        }
                        $tokens[] = $run;
                    }
                }
            }
        }
    }

    /** Width of the line box the text being tokenized is laid out in: the upper bound of one tab stop interval */
    private float $tabLimit = 0.0;

    /** @var array{n: int, id: int, w: float}|null running width of the current line in the token list (tab stops) */
    private ?array $tabColumn = null;

    /**
     * Width of the tokens on the current (source) line: from the last forced break to the end of $tokens.
     *
     * @param list<TextRun> $tokens
     */
    private function currentLineWidth(array $tokens): float
    {
        $n = count($tokens);
        $c = $this->tabColumn;
        if ($c !== null && $c['n'] <= $n && ($c['n'] === 0 || spl_object_id($tokens[$c['n'] - 1]) === $c['id'])) {
            $w = $c['w'];
            $from = $c['n'];
        } else {
            $w = 0.0;
            $from = 0;
        }
        for ($i = $from; $i < $n; $i++) {
            $w = $tokens[$i]->isLineBreak ? 0.0 : $w + $tokens[$i]->width;
        }
        $this->tabColumn = ['n' => $n, 'id' => $n > 0 ? spl_object_id($tokens[$n - 1]) : 0, 'w' => $w];
        return $w;
    }

    /**
     * Expands tab characters of a preserved-whitespace run to tab stops (CSS `tab-size`: a number of space
     * advances or a length), measured from the start of the line. Returns the replacement text (spaces
     * of equivalent width) and its total width.
     *
     * @param list<TextRun> $tokens
     * @return array{0: string, 1: float}
     */
    private function expandTabs(
        string $part,
        array $tokens,
        ComputedStyle $style,
        string $fontFamily,
        string $fontWeight,
        string $fontStyle,
        float $fontSizePt,
        float $letterSpacingPt,
        float $wordSpacingPt
    ): array {
        $spaceW = $this->fontManager->measureText(' ', $fontFamily, $fontWeight, $fontStyle, $fontSizePt, $letterSpacingPt) + $wordSpacingPt;
        $raw = trim($style->get('tab-size', '8'));
        if (is_numeric($raw)) {
            $tabW = max(0.0, (float) $raw) * $spaceW;
        } else {
            $tabW = $this->engine->resolveLength($raw, 0.0, $fontSizePt);
            if ($tabW < 0.0) {
                $tabW = 8 * $spaceW;
            }
        }
        // `tab-size: 1e9` must not build a gigabyte of spaces: a tab never advances further than the line width
        // (the text after it overflows the line either way)
        $tabW = min($tabW, max($this->tabLimit, 8 * $spaceW, 1.0));
        $col = $this->currentLineWidth($tokens);
        $start = $col;
        $text = '';
        foreach (str_split($part) as $ch) {
            if ($ch === "\t") {
                $next = $tabW > 0.01 ? (floor(($col + 0.01) / $tabW) + 1) * $tabW : $col;
                $adv = $next - $col;
                $text .= str_repeat(' ', $spaceW > 0.01 ? max(1, min(1000, (int) round($adv / $spaceW))) : 1);
                $col = $next;
            } else {
                $text .= ' ';
                $col += $spaceW;
            }
        }
        return [$text, $col - $start];
    }

    /** @var array<string, array{0: ComputedStyle, 1: ComputedStyle}> synthesized small-caps style clones */
    private array $smallCapsStyles = [];

    /**
     * Style of synthesized small capitals: the base style at 70% of the font size without further caps variants.
     */
    private function smallCapsStyle(ComputedStyle $style, float $fontSizePt): ComputedStyle
    {
        $key = spl_object_id($style);
        if (!isset($this->smallCapsStyles[$key]) || $this->smallCapsStyles[$key][0] !== $style) {
            $small = clone $style;
            $small->set('font-size', round($fontSizePt * TextEffects::SMALL_CAPS_SCALE, 3) . 'pt');
            $small->set('font-variant', 'normal');
            $small->set('font-variant-caps', 'normal');
            $this->smallCapsStyles[$key] = [$style, $small];
        }
        return $this->smallCapsStyles[$key][1];
    }

    /**
     * A right-to-left word drawn by a fallback font keeps the punctuation inside it (the quote of דוא"ל, the hyphen of
     * אבג-דהו) in that font when the font has it: one run instead of three, so the word keeps one bidi run and
     * extractors read it in logical order (as when one font draws the whole word) instead of reordering the pieces
     * of each font separately. Punctuation at the word's ends keeps the requested font.
     *
     * @param list<int> $codepoints
     * @param list<?string> $families fallback family per code point (null = the requested font), updated in place
     */
    private function keepRtlPunctuationInFallback(array $codepoints, array &$families, string $fontWeight, string $fontStyle): void
    {
        $n = count($codepoints);
        $isPunct = static fn(int $cp): bool => preg_match('/^[\p{P}\p{S}]$/u', mb_chr($cp, 'UTF-8')) === 1;
        for ($i = 1; $i < $n - 1; $i++) {
            $fb = $families[$i - 1];
            if ($families[$i] !== null || $fb === null || !$isPunct($codepoints[$i])) {
                continue;
            }
            $next = $i;
            while ($next < $n && $families[$next] === null && $isPunct($codepoints[$next])) {
                $next++;
            }
            if ($next >= $n || $families[$next] !== $fb) {
                $i = $next;
                continue;
            }
            $ttf = $this->fontManager->resolveFont($fb, $fontWeight, $fontStyle);
            $covered = true;
            for ($k = $i; $k < $next; $k++) {
                $covered = $covered && $ttf->getGlyphId($codepoints[$k]) !== 0;
            }
            if ($covered) {
                for ($k = $i; $k < $next; $k++) {
                    $families[$k] = $fb;
                }
            }
            $i = $next;
        }
    }

    /**
     * Splits a word into segments rendered with the requested font or a fallback family covering missing glyphs.
     *
     * @return list<array{0: string, 1: ComputedStyle}>
     */
    private function splitByFontCoverage(string $word, ComputedStyle $style, string $fontFamily, string $fontWeight, string $fontStyle): array
    {
        $codepoints = FontManager::utf8ToCodepoints($word);
        $lang = $style->get('-ivopdf-lang', '');
        $families = [];
        $needsSplit = false;
        foreach ($codepoints as $cp) {
            $fb = ($cp < 0x80 || $cp === 0xA0 || $cp === 0x200B || $cp === 0xAD) ? null : $this->fontManager->findFallbackFamily($fontFamily, $fontWeight, $fontStyle, $cp, $lang);
            $families[] = $fb;
            if ($fb !== null) {
                $needsSplit = true;
            }
        }
        if (!$needsSplit) {
            return [[$word, $style]];
        }
        if (preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $word) === 1) {
            $this->keepRtlPunctuationInFallback($codepoints, $families, $fontWeight, $fontStyle);
        }

        $segments = [];
        $current = '';
        $currentFamily = $families[0];
        foreach ($codepoints as $i => $cp) {
            if ($families[$i] !== $currentFamily && $current !== '') {
                $segments[] = [$current, $currentFamily];
                $current = '';
            }
            $currentFamily = $families[$i];
            $current .= mb_chr($cp, 'UTF-8');
        }
        if ($current !== '') {
            $segments[] = [$current, $currentFamily];
        }

        $result = [];
        foreach ($segments as [$text, $family]) {
            if ($family === null) {
                $result[] = [$text, $style];
            } else {
                $memoKey = spl_object_id($style) . '|' . $family;
                if (!isset($this->fallbackStyles[$memoKey]) || $this->fallbackStyles[$memoKey][0] !== $style) {
                    $fbStyle = clone $style;
                    $fbStyle->set('font-family', $family);
                    $this->fallbackStyles[$memoKey] = [$style, $fbStyle];
                }
                $result[] = [$text, $this->fallbackStyles[$memoKey][1]];
            }
        }
        return $result;
    }

    /**
     * text-overflow: ellipsis on single-line clipped containers.
     *
     * @param list<TextRun> $runs
     * @return list<TextRun>
     */
    private function applyEllipsis(array $runs, float $availableWidth): array
    {
        $total = 0.0;
        foreach ($runs as $r) {
            $total += $r->width + $r->paddingLeft + $r->paddingRight;
        }
        if ($total <= $availableWidth + 0.05 || empty($runs)) {
            return $runs;
        }
        $last = $runs[count($runs) - 1];
        $ell = '…';
        $ff = $last->style->get('font-family', 'DejaVu Sans');
        $fw = $last->style->get('font-weight', 'normal');
        $fst = $last->style->get('font-style', 'normal');
        $ellW = $this->fontManager->measureText($ell, $ff, $fw, $fst, $last->fontSizePt, $last->letterSpacingPt);
        $limit = $availableWidth - $ellW;

        $kept = [];
        $used = 0.0;
        foreach ($runs as $r) {
            $rw = $r->width + $r->paddingLeft + $r->paddingRight;
            if ($used + $rw <= $limit) {
                $kept[] = $r;
                $used += $rw;
                continue;
            }
            if ($r->inlineBox === null && !$r->isWhitespace && $r->text !== '') {
                $chars = preg_split('//u', $r->text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $text = '';
                foreach ($chars as $ch) {
                    $w = $this->fontManager->measureText($text . $ch, $r->style->get('font-family', 'DejaVu Sans'), $r->style->get('font-weight', 'normal'), $r->style->get('font-style', 'normal'), $r->fontSizePt, $r->letterSpacingPt);
                    if ($used + $r->paddingLeft + $w > $limit) {
                        break;
                    }
                    $text .= $ch;
                }
                if ($text !== '') {
                    $cut = clone $r;
                    $cut->text = $text;
                    $cut->glyphs = null;
                    $this->shapeRun($cut);
                    $cut->paddingRight = 0.0;
                    $kept[] = $cut;
                }
            }
            break;
        }
        $this->trimTrailingWhitespace($kept);
        $ellRun = new TextRun($ell, $last->style, $ellW, $last->fontSizePt, $last->ascent, $last->descent, false, $last->href);
        $ellRun->letterSpacingPt = $last->letterSpacingPt;
        $ellRun->breakBefore = false;
        $kept[] = $ellRun;
        return $kept;
    }

    /**
     * Ascent and descent of the strut of $container, each including half the leading of its line-height.
     *
     * @return array{0: float, 1: float}
     */
    private function strutMetrics(LayoutBox $container, float $strutLh): array
    {
        $fs = $container->fontSizePt;
        if ($fs <= 0.0) {
            return [0.0, 0.0];
        }
        $st = $container->style;
        $ttf = $this->fontManager->resolveFont($st->get('font-family', 'DejaVu Sans'), $st->get('font-weight', 'normal'), $st->get('font-style', 'normal'));
        $a = ($ttf->ascent / 1000.0) * $fs;
        $d = (abs($ttf->descent) / 1000.0) * $fs;
        $half = ($strutLh - ($a + $d)) / 2.0;
        return [$a + $half, $d + $half];
    }

    private function resolveLineHeight(ComputedStyle $style, float $fontSizePt): float
    {
        return CssUnit::clampLength($this->resolveLineHeightRaw($style, $fontSizePt)); // `line-height: 1e9`
    }

    private function resolveLineHeightRaw(ComputedStyle $style, float $fontSizePt): float
    {
        $v = strtolower(trim($style->get('line-height', 'normal')));
        if ($v === '' || $v === 'normal') {
            return $this->fontManager->normalLineHeight($style->get('font-family', 'DejaVu Sans'), $style->get('font-weight', 'normal'), $style->get('font-style', 'normal'), $fontSizePt);
        }
        if (is_numeric($v)) {
            return ((float) $v) * $fontSizePt;
        }
        if (str_ends_with($v, '%')) {
            return (((float) substr($v, 0, -1)) / 100.0) * $fontSizePt;
        }
        return CssUnit::toPt($v, $fontSizePt, $fontSizePt, 12.0, $fontSizePt * 1.2);
    }
}
