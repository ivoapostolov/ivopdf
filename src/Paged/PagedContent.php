<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use DOMElement;
use IvoPdf\Css\ComputedStyle;
use IvoPdf\Layout\LayoutBox;

/**
 * Parses CSS generated content that needs the final pagination: `leader('.')` and `target-counter(attr(href), page)`.
 * Such content becomes a span with fixed-width placeholder text boxes that carry `ivo-leader` / `ivo-pageref`
 * style markers; PageRefResolver fills them in once the page of every target is known.
 */
final class PagedContent
{
    public const PLACEHOLDER = '999';

    public static function isPaged(string $content): bool
    {
        return stripos($content, 'leader(') !== false || stripos($content, 'target-counter(') !== false;
    }

    /**
     * Builds the generated-content span. $evaluate renders ordinary content segments (strings, attr(), counter()).
     *
     * @param callable(string): string $evaluate
     */
    public static function buildBox(string $content, DOMElement $el, ComputedStyle $pseudoStyle, ?string $href, callable $evaluate): ?LayoutBox
    {
        $pseudoBox = new LayoutBox('span', $pseudoStyle);
        $pseudoBox->href = $href;
        $added = false;

        foreach (self::segments($content, $el) as $seg) {
            $style = clone $pseudoStyle;
            if ($seg['type'] === 'raw') {
                $text = $evaluate($seg['value']);
                if ($text === '') {
                    continue;
                }
            } elseif ($seg['type'] === 'leader') {
                $style->set('ivo-leader', $seg['value']);
                $text = $seg['value'] === 'space' ? "\u{00A0}" : $seg['value'];
            } else {
                $style->set('ivo-pageref', $seg['value']);
                $text = self::PLACEHOLDER;
            }
            $textBox = new LayoutBox('#text', $style);
            $textBox->textContent = $text;
            $textBox->href = $href;
            $pseudoBox->addChild($textBox);
            $added = true;
        }
        return $added ? $pseudoBox : null;
    }

    /**
     * Splits a `content` value into raw segments, leaders and page references.
     *
     * @return list<array{type: string, value: string}>
     */
    public static function segments(string $content, DOMElement $el): array
    {
        $segments = [];
        $raw = '';
        $len = strlen($content);
        $i = 0;
        $flush = static function () use (&$segments, &$raw): void {
            if (trim($raw) !== '') {
                $segments[] = ['type' => 'raw', 'value' => $raw];
            }
            $raw = '';
        };

        while ($i < $len) {
            $ch = $content[$i];
            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                while ($j < $len && $content[$j] !== $ch) {
                    $j += $content[$j] === '\\' ? 2 : 1;
                }
                $raw .= substr($content, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if (preg_match('/^(leader|target-counter)\(/i', substr($content, $i, 16), $m)) {
                $open = $i + strlen($m[0]);
                $close = self::matchingParen($content, $open - 1);
                $arg = substr($content, $open, $close - $open);
                $flush();
                if (strtolower($m[1]) === 'leader') {
                    $segments[] = ['type' => 'leader', 'value' => self::leaderChar($arg)];
                } else {
                    $target = self::resolveTarget($arg, $el);
                    $segments[] = ['type' => 'pageref', 'value' => $target];
                }
                $i = $close + 1;
                continue;
            }
            $raw .= $ch;
            $i++;
        }
        $flush();
        return $segments;
    }

    private static function matchingParen(string $s, int $openPos): int
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $openPos; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $len && $s[$j] !== $c) {
                    $j += $s[$j] === '\\' ? 2 : 1;
                }
                $i = $j;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return $len;
    }

    private static function leaderChar(string $arg): string
    {
        $arg = trim($arg);
        if (preg_match('/^(["\'])(.*)\1$/s', $arg, $m)) {
            return $m[2] === '' || trim($m[2]) === '' ? 'space' : trim($m[2]);
        }
        return match (strtolower($arg)) {
            'solid' => '_',
            'space' => 'space',
            default => '.',
        };
    }

    /**
     * Resolves the first argument of target-counter(): attr(href), url(#id) or a '#id' string. Returns the id.
     */
    private static function resolveTarget(string $arg, DOMElement $el): string
    {
        // Only the part before the last top-level comma ("<target>, page")
        $depth = 0;
        $cut = strlen($arg);
        for ($i = strlen($arg) - 1; $i >= 0; $i--) {
            $c = $arg[$i];
            if ($c === ')') {
                $depth++;
            } elseif ($c === '(') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $cut = $i;
                break;
            }
        }
        $target = trim(substr($arg, 0, $cut));
        if (preg_match('/^attr\(\s*([^)\s,]+)/i', $target, $m)) {
            $value = $el->getAttribute($m[1]);
        } elseif (preg_match('/^url\(\s*["\']?([^"\')]*)/i', $target, $m)) {
            $value = $m[1];
        } else {
            $value = trim($target, " \t'\"");
        }
        $value = trim($value);
        return ltrim($value, '#');
    }
}
