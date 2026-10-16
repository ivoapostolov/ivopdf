<?php

declare(strict_types=1);

namespace IvoPdf\Dom;

use DOMElement;
use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssParser;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\TextEffects;

/**
 * ::first-letter (drop caps) and ::first-line support at box-tree build time.
 *
 * ::first-letter splits the first letter of a block off its text node into a styled span (floated for drop caps).
 * ::first-line only records the computed style on the block; InlineLayout restyles the first line box after breaking.
 */
final class FirstPseudo
{
    private const BLOCK_CONTAINERS = ['block', 'list-item', 'inline-block', 'table-cell', 'table-caption'];

    public static function apply(CssParser $css, DOMElement $el, LayoutBox $box): void
    {
        $hasLetter = $css->hasPseudoRules('first-letter');
        $hasLine = $css->hasPseudoRules('first-line');
        if (!$hasLetter && !$hasLine) {
            return;
        }
        if (!in_array($box->getDisplay(), self::BLOCK_CONTAINERS, true) || $box->formControl !== null) {
            return;
        }
        if ($hasLine) {
            $style = $css->computePseudoStyle($el, $box->style, 'first-line', false);
            if ($style !== null) {
                $box->firstLineStyle = $style;
                $box->firstLineProps = self::expandProps($css->pseudoDeclaredProps($el, 'first-line'));
            }
        }
        if ($hasLetter) {
            $style = $css->computePseudoStyle($el, $box->style, 'first-letter', false);
            if ($style !== null) {
                self::applyFirstLetter($box, $style, $css->pseudoDeclaredProps($el, 'first-letter'));
            }
        }
    }

    /**
     * @param list<string> $declared
     * @return list<string>
     */
    private static function expandProps(array $declared): array
    {
        $out = [];
        foreach ($declared as $p) {
            if ($p === 'font') {
                array_push($out, 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'font-variant');
            } elseif ($p === 'text-decoration') {
                array_push($out, 'text-decoration', 'text-decoration-line', 'text-decoration-color', 'text-decoration-style');
            } else {
                $out[] = $p;
            }
        }
        return array_values(array_intersect(array_unique($out), TextEffects::FIRST_LINE_PROPS));
    }

    /**
     * @param list<string> $declared
     */
    private static function applyFirstLetter(LayoutBox $block, ComputedStyle $style, array $declared): void
    {
        $target = self::findFirstText($block);
        if ($target === null) {
            return;
        }
        [$parent, $idx] = $target;
        $textBox = $parent->children[$idx];
        $text = (string) $textBox->textContent;
        $span = TextEffects::firstLetterSpan($text);
        if ($span === null) {
            return;
        }
        [$wsLen, $letterLen] = $span;
        $letter = substr($text, $wsLen, $letterLen);
        $rest = substr($text, $wsLen + $letterLen);

        $float = strtolower(trim($style->get('float', 'none')));
        $initial = self::initialLetterLines($style);
        if ($initial > 0 && $float === 'none') {
            $float = 'left';
            self::applyInitialLetter($block, $style, $initial);
        }
        $floated = $float === 'left' || $float === 'right';
        if ($floated) {
            $style->set('display', 'block');
            // A float must be a direct child of a block container: hoist it before the inline content
            $hostParent = $parent;
            while ($hostParent !== $block && !in_array($hostParent->getDisplay(), self::BLOCK_CONTAINERS, true) && $hostParent->parent !== null) {
                $hostParent = $hostParent->parent;
            }
        } else {
            $hostParent = $parent;
        }

        $transform = in_array('text-transform', $declared, true) ? $style->get('text-transform', 'none') : 'none';
        $letterBox = new LayoutBox('span', $style);
        $letterBox->href = $textBox->href;
        $inner = new LayoutBox('#text', clone $style);
        $inner->textContent = TextEffects::transform($letter, $transform === 'capitalize' ? 'uppercase' : $transform);
        $inner->href = $textBox->href;
        $letterBox->addChild($inner);

        if ($rest === '') {
            array_splice($parent->children, $idx, 1);
        } else {
            $textBox->textContent = $rest;
        }
        if ($floated) {
            $letterBox->parent = $hostParent;
            array_unshift($hostParent->children, $letterBox);
        } else {
            $letterBox->parent = $parent;
            array_splice($parent->children, $idx, 0, [$letterBox]);
        }
    }

    /**
     * Number of lines spanned by `initial-letter: N [M]`, or 0.
     */
    private static function initialLetterLines(ComputedStyle $style): int
    {
        $v = strtolower(trim($style->get('initial-letter', 'normal')));
        if ($v === '' || $v === 'normal' || $v === 'none') {
            return 0;
        }
        return preg_match('/^(\d+(?:\.\d+)?)/', $v, $m) === 1 ? max(0, (int) round((float) $m[1])) : 0;
    }

    /**
     * Approximation of initial-letter: a left float whose capital height spans N text lines.
     */
    private static function applyInitialLetter(LayoutBox $block, ComputedStyle $style, int $lines): void
    {
        $bodyPt = $block->style->getFontSizePt();
        $lh = trim($block->style->get('line-height', '1.4'));
        $lhPt = is_numeric($lh) ? (float) $lh * $bodyPt : (\IvoPdf\Css\CssUnit::toPt($lh, $bodyPt, $bodyPt, $bodyPt) ?: 1.4 * $bodyPt);
        $cap = 0.72;
        $size = (($lines - 1) * $lhPt + $cap * $bodyPt) / $cap;
        $style->set('font-size', round($size, 2) . 'pt');
        $style->set('line-height', round(($lines * $lhPt) / $size, 3) . '');
        $style->set('float', 'left');
        $style->set('margin-right', round($bodyPt * 0.15, 2) . 'pt');
    }

    /**
     * Locates the text box that holds the first letter: skips empty inline content and descends into inline
     * elements and nested blocks, stopping at replaced, floated, positioned and non-block formatting contexts.
     *
     * @return array{0: LayoutBox, 1: int}|null
     */
    private static function findFirstText(LayoutBox $box): ?array
    {
        foreach ($box->children as $i => $c) {
            if ($c->isTextNode()) {
                if (trim((string) $c->textContent) === '') {
                    continue;
                }
                return [$box, $i];
            }
            if (in_array($c->tagName, ['img', 'svg', 'br', 'hr', 'table', 'input', 'textarea', 'select', 'button'], true)
                || $c->formControl !== null || $c->isPositionedOutOfFlow()) {
                if ($c->tagName === 'br') {
                    continue;
                }
                return null;
            }
            $display = $c->getDisplay();
            if ($display === 'none') {
                continue;
            }
            if (strtolower(trim($c->style->get('float', 'none'))) !== 'none') {
                continue;
            }
            if (!in_array($display, ['inline', 'block', 'list-item'], true)) {
                return null;
            }
            $r = self::findFirstText($c);
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    }
}
