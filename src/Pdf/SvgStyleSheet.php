<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use DOMElement;

/**
 * Minimal CSS for <style> blocks inside an SVG: selectors by tag, .class, #id, [attr], [attr=value], :first-child,
 * the universal selector, comma lists and descendant / child combinators. Matching declarations are folded into the
 * element's `style` attribute (stylesheet declarations first, so an inline style still wins), which the renderer's
 * presentation-attribute pipeline then picks up.
 */
final class SvgStyleSheet
{
    private const MARK = 'data-ivopdf-css';

    /**
     * Applies all <style> rules found in $root's subtree to the matching elements (idempotent per root).
     */
    public static function apply(DOMElement $root): void
    {
        if ($root->hasAttribute(self::MARK)) {
            return;
        }
        $root->setAttribute(self::MARK, '1');

        $css = '';
        foreach ($root->getElementsByTagName('style') as $style) {
            $type = strtolower(trim($style->getAttribute('type')));
            if ($type === '' || $type === 'text/css') {
                $css .= "\n" . $style->textContent;
            }
        }
        if (trim($css) === '') {
            return;
        }

        $rules = self::parse($css);
        if ($rules === []) {
            return;
        }

        // Cursor walk in document order: iterating getElementsByTagName('*') is quadratic before PHP 8.3
        $elements = [$root];
        $el = $root->firstElementChild;
        while ($el !== null) {
            $elements[] = $el;
            $next = $el->firstElementChild;
            while ($next === null && $el !== null && $el !== $root) {
                $next = $el->nextElementSibling;
                $el = $next === null ? $el->parentNode : $el;
            }
            $el = $next;
        }
        foreach ($elements as $el) {
            $matched = [];
            foreach ($rules as $order => $rule) {
                foreach ($rule['selectors'] as $sel) {
                    if (self::matches($el, $sel['parts'])) {
                        $matched[] = [$sel['spec'], $order, $rule['decls']];
                        break;
                    }
                }
            }
            if ($matched === []) {
                continue;
            }
            usort($matched, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $decl = '';
            foreach ($matched as $m) {
                $decl .= $m[2] . ';';
            }
            $inline = $el->getAttribute('style');
            $el->setAttribute('style', $decl . $inline);
        }
    }

    /**
     * @return list<array{selectors: list<array{parts: list<array{0: string, 1: array<string, mixed>}>, spec: int}>, decls: string}>
     */
    private static function parse(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace('~<!\[CDATA\[|\]\]>|<!--|-->~', '', $css);
        $rules = [];
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $brace = strpos($css, '{', $i);
            if ($brace === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $brace - $i));
            // Find the matching closing brace (nested at-rules)
            $depth = 1;
            $j = $brace + 1;
            while ($j < $len && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($css, $brace + 1, $j - $brace - 2);
            $i = $j;
            if ($prelude === '' || $prelude[0] === '@') {
                continue; // @media / @font-face / @keyframes etc. are ignored
            }
            $decls = [];
            foreach (explode(';', $body) as $d) {
                $colon = strpos($d, ':');
                if ($colon !== false) {
                    $decls[] = trim(substr($d, 0, $colon)) . ':' . trim((string) preg_replace('/!\s*important\s*$/i', '', substr($d, $colon + 1)));
                }
            }
            if ($decls === []) {
                continue;
            }
            $selectors = [];
            foreach (explode(',', $prelude) as $selText) {
                $parsed = self::parseSelector(trim($selText));
                if ($parsed !== null) {
                    $selectors[] = $parsed;
                }
            }
            if ($selectors !== []) {
                $rules[] = ['selectors' => $selectors, 'decls' => implode(';', $decls)];
            }
        }
        return $rules;
    }

    /**
     * @return array{parts: list<array{0: string, 1: array<string, mixed>}>, spec: int}|null parts = [combinator, compound]
     */
    private static function parseSelector(string $sel): ?array
    {
        if ($sel === '') {
            return null;
        }
        $sel = (string) preg_replace('/\s*>\s*/', ' > ', $sel);
        $tokens = preg_split('/\s+/', trim($sel), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        $comb = ' ';
        $spec = 0;
        foreach ($tokens as $tok) {
            if ($tok === '>') {
                $comb = '>';
                continue;
            }
            $c = ['tag' => null, 'classes' => [], 'id' => null, 'attrs' => [], 'first' => false];
            $rest = $tok;
            if (preg_match('/^(\*|[a-zA-Z][\w:-]*)/', $rest, $m)) {
                $c['tag'] = $m[1] === '*' ? null : strtolower($m[1]);
                $spec += $m[1] === '*' ? 0 : 1;
                $rest = substr($rest, strlen($m[0]));
            }
            while ($rest !== '') {
                if (preg_match('/^\.([\w-]+)/', $rest, $m)) {
                    $c['classes'][] = $m[1];
                    $spec += 10;
                } elseif (preg_match('/^#([\w-]+)/', $rest, $m)) {
                    $c['id'] = $m[1];
                    $spec += 100;
                } elseif (preg_match('/^\[\s*([\w:-]+)\s*(?:([~|^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/', $rest, $m)) {
                    $c['attrs'][] = [$m[1], $m[2] ?? '', ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? '')];
                    $spec += 10;
                } elseif (preg_match('/^:first-child/', $rest, $m)) {
                    $c['first'] = true;
                    $spec += 10;
                } else {
                    return null; // unsupported pseudo-class / syntax
                }
                $rest = substr($rest, strlen($m[0]));
            }
            $parts[] = [$comb, $c];
            $comb = ' ';
        }
        return $parts === [] ? null : ['parts' => $parts, 'spec' => $spec];
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $parts
     */
    private static function matches(DOMElement $el, array $parts): bool
    {
        $i = count($parts) - 1;
        if (!self::matchCompound($el, $parts[$i][1])) {
            return false;
        }
        $comb = $parts[$i][0];
        $cur = $el;
        while ($i > 0) {
            $i--;
            $target = $parts[$i][1];
            if ($comb === '>') {
                $p = $cur->parentNode;
                if (!$p instanceof DOMElement || !self::matchCompound($p, $target)) {
                    return false;
                }
                $cur = $p;
            } else {
                $found = null;
                for ($p = $cur->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                    if (self::matchCompound($p, $target)) {
                        $found = $p;
                        break;
                    }
                }
                if ($found === null) {
                    return false;
                }
                $cur = $found;
            }
            $comb = $parts[$i][0];
        }
        return true;
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function matchCompound(DOMElement $el, array $c): bool
    {
        if ($c['tag'] !== null && strtolower($el->localName ?? $el->tagName) !== $c['tag']) {
            return false;
        }
        if ($c['id'] !== null && $el->getAttribute('id') !== $c['id']) {
            return false;
        }
        if ($c['classes'] !== []) {
            $have = preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($c['classes'] as $cl) {
                if (!in_array($cl, $have, true)) {
                    return false;
                }
            }
        }
        foreach ($c['attrs'] as [$name, $op, $val]) {
            if (!$el->hasAttribute($name)) {
                return false;
            }
            $a = $el->getAttribute($name);
            $ok = match ($op) {
                '' => true,
                '=' => $a === $val,
                '^=' => $val !== '' && str_starts_with($a, $val),
                '$=' => $val !== '' && str_ends_with($a, $val),
                '*=' => $val !== '' && str_contains($a, $val),
                '~=' => in_array($val, preg_split('/\s+/', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        if ($c['first']) {
            $prev = $el->previousSibling;
            while ($prev !== null && !$prev instanceof DOMElement) {
                $prev = $prev->previousSibling;
            }
            if ($prev !== null) {
                return false;
            }
        }
        return true;
    }
}
