<?php

declare(strict_types=1);

namespace IvoPdf\Dom;

use DOMDocument;
use DOMElement;
use DOMException;
use DOMNode;

/**
 * WHATWG-compliant HTML parsing for the legacy DOM classes the engine works with.
 *
 * PHP >= 8.4 ships \Dom\HTMLDocument (lexbor, the HTML5 tree-construction algorithm: implied end tags, table
 * foster-parenting, <template>, foreign content, entities without semicolon...). The engine uses the legacy
 * DOMDocument/DOMElement classes everywhere, so the parsed tree is copied into a plain DOMDocument:
 * element names keep their case (SVG `foreignObject`), attribute names keep their case (`viewBox`,
 * `preserveAspectRatio`), no namespaces are attached, comments are dropped and <template> content is not copied.
 */
final class Html5Parser
{
    /** True when the running PHP provides the HTML5 parser (PHP >= 8.4 with ext-dom). */
    public static function available(): bool
    {
        return class_exists(\Dom\HTMLDocument::class);
    }

    /**
     * Parses a complete document or a fragment. Returns null when the HTML5 parser is unavailable or fails.
     *
     * @param int $maxDepth elements nested deeper than this are flattened into the ancestor at that depth
     * @param (callable(string): void)|null $warn
     */
    public static function parse(string $html, int $maxDepth = 256, ?callable $warn = null): ?DOMDocument
    {
        if (!self::available()) {
            return null;
        }
        try {
            $src = \Dom\HTMLDocument::createFromString(self::expandSelfClosing($html), LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return null;
        }

        $dst = new DOMDocument('1.0', 'UTF-8');
        $maxDepth = max(8, $maxDepth);
        $flattened = false;

        /** @var list<array{0: \Dom\Node, 1: DOMNode, 2: int}> $stack */
        $stack = [];
        // Children are processed in document order: push them reversed
        $push = static function (\Dom\Node $parent, DOMNode $target, int $depth) use (&$stack): void {
            for ($c = $parent->lastChild; $c !== null; $c = $c->previousSibling) {
                $stack[] = [$c, $target, $depth];
            }
        };

        $root = $src->documentElement;
        if ($root === null) {
            return null;
        }
        $stack[] = [$root, $dst, 0];

        while ($stack !== []) {
            [$node, $parent, $depth] = array_pop($stack);

            if ($node instanceof \Dom\Text) {
                $parent->appendChild($dst->createTextNode($node->data));
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue; // comments, processing instructions, doctype
            }

            $name = $node->localName;
            if ($name === 'template') {
                $el = self::makeElement($dst, $name);
                self::copyAttributes($node, $el);
                $parent->appendChild($el); // its content fragment is inert: nothing is rendered
                continue;
            }

            $el = self::makeElement($dst, $name);
            self::copyAttributes($node, $el);

            if ($depth >= $maxDepth) {
                // Too deep: keep the element as a sibling instead of nesting further
                $flattened = true;
                $parent->appendChild($el);
                $push($node, $parent, $depth);
                continue;
            }
            $parent->appendChild($el);
            $push($node, $el, $depth + 1);
        }

        if ($flattened && $warn !== null) {
            $warn("HTML nesting deeper than {$maxDepth} levels was flattened (see Options::\$maxNestingDepth).");
        }
        return $dst;
    }

    /**
     * HTML4/XHTML-era documents write `<div class="clear"/>`, `<td/>` or `<pagebreak />`; libxml treats those as empty
     * elements, while HTML5 ignores the slash and would swallow the following content. Non-void tags written that
     * way are expanded to `<tag></tag>` (SVG, MathML, script, style and comments are left untouched).
     */
    public static function expandSelfClosing(string $html): string
    {
        if (!str_contains($html, '/>')) {
            return $html;
        }
        $parts = preg_split(
            '~(<!--.*?-->|<svg\b.*?</svg\s*>|<math\b.*?</math\s*>|<script\b.*?</script\s*>|<style\b.*?</style\s*>)~is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return $html;
        }
        $void = 'area|base|basefont|br|col|embed|frame|hr|img|input|keygen|link|meta|param|source|track|wbr';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || !str_contains($part, '/>')) {
                continue;
            }
            $parts[$i] = (string) preg_replace(
                '~<(?!(?:' . $void . ')\b)([A-Za-z][A-Za-z0-9:-]*)((?:\s+(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)?)\s*/>~',
                '<$1$2></$1>',
                $part
            );
        }
        return implode('', $parts);
    }

    /** libxml's HTML4 parser (the pre-PHP-8.4 behaviour): wraps fragments, forces UTF-8. */
    public static function parseLegacy(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prevLibxml = libxml_use_internal_errors(true);

        // Ensure UTF-8 meta wrapper if not a complete HTML document
        $hasHtmlTag = stripos($html, '<html') !== false || stripos($html, '<!doctype') !== false;
        if (!$hasHtmlTag) {
            $wrappedHtml = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
        } elseif (stripos($html, '<?xml') === false) {
            $wrappedHtml = '<?xml encoding="UTF-8">' . $html;
        } else {
            $wrappedHtml = $html;
        }

        @$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);
        return $dom;
    }

    private static function makeElement(DOMDocument $doc, string $name): DOMElement
    {
        try {
            return $doc->createElement($name);
        } catch (DOMException) {
            $clean = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '-', $name);
            if ($clean === '' || !preg_match('/^[A-Za-z_]/', $clean)) {
                $clean = 'x-' . $clean;
            }
            return $doc->createElement($clean);
        }
    }

    private static function copyAttributes(\Dom\Element $from, DOMElement $to): void
    {
        foreach ($from->attributes as $attr) {
            try {
                $to->setAttribute($attr->nodeName, $attr->value);
            } catch (DOMException) {
                // Names like "@click" or "[prop]" are valid HTML but not valid XML names: they carry no meaning here
            }
        }
    }
}
