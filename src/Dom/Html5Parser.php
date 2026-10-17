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
            $src = \Dom\HTMLDocument::createFromString(self::expandSelfClosing(self::stripNoscript($html)), LIBXML_NOERROR, 'UTF-8');
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
                // Merge with a preceding text node (left behind when a comment between them was dropped)
                if ($parent->lastChild instanceof \DOMText) {
                    $parent->lastChild->appendData($node->data);
                } else {
                    $parent->appendChild($dst->createTextNode($node->data));
                }
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

    /**
     * Drops <noscript> elements: a browser with scripting enabled does not render them (dompdf does; we follow browsers).
     * Done on the source so that a <noscript> in <head> cannot spill its content into the body either.
     */
    private static function stripNoscript(string $html): string
    {
        if (stripos($html, '<noscript') === false) {
            return $html;
        }
        return (string) preg_replace('~<noscript\b[^>]*>.*?</noscript\s*>~is', '', $html);
    }

    /**
     * libxml's HTML4 parser (the pre-PHP-8.4 behaviour): wraps fragments, forces UTF-8.
     *
     * libxml stops parsing at 256 nested elements and silently drops the rest, so a too-deep source is flattened
     * before parsing and the resulting tree is capped at $maxDepth, matching parse().
     *
     * @param (callable(string): void)|null $warn
     */
    public static function parseLegacy(string $html, int $maxDepth = 256, ?callable $warn = null): DOMDocument
    {
        $maxDepth = max(8, $maxDepth);
        $flattened = false;
        $html = self::stripNoscript($html);
        if (stripos($html, '<wbr') !== false) {
            // libxml's HTML4 parser does not know the void element <wbr> and would nest the following content inside it
            $html = (string) preg_replace('~<wbr\s*/?>~i', '<wbr></wbr>', $html);
        }
        $dom = self::loadLegacy($html, $tooDeep);
        if ($tooDeep) {
            $dom = self::loadLegacy(self::capSourceDepth($html, min($maxDepth, 200)), $tooDeep);
            $flattened = true;
        }
        $root = $dom->documentElement;
        if ($root !== null && self::capTreeDepth($root, $maxDepth)) {
            $flattened = true;
        }
        if ($flattened && $warn !== null) {
            $warn("HTML nesting deeper than {$maxDepth} levels was flattened (see Options::\$maxNestingDepth).");
        }
        return $dom;
    }

    private static function loadLegacy(string $html, ?bool &$tooDeep): DOMDocument
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
        $tooDeep = false;
        foreach (libxml_get_errors() as $error) {
            if (stripos($error->message, 'depth') !== false) {
                $tooDeep = true;
                break;
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);
        // Comments render nothing: drop them and glue the text nodes they separated (same result as the HTML5 path)
        $comments = [];
        foreach ((new \DOMXPath($dom))->query('//comment()') ?: [] as $c) {
            $comments[] = $c;
        }
        foreach ($comments as $c) {
            $c->parentNode?->removeChild($c);
        }
        if ($comments !== []) {
            $dom->normalize();
        }
        return $dom;
    }

    /**
     * Drops start/end tags nested deeper than $limit from the source (their content is kept), so libxml never
     * reaches its own depth limit. Only used for pathological input; implied end tags are not modelled.
     */
    private static function capSourceDepth(string $html, int $limit): string
    {
        $parts = preg_split('~(<!--.*?-->|<script\b.*?</script\s*>|<style\b.*?</style\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        $void = ['area' => 1, 'base' => 1, 'basefont' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'frame' => 1, 'hr' => 1,
            'img' => 1, 'input' => 1, 'keygen' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];
        /** @var list<array{0: string, 1: bool}> $stack */
        $stack = [];
        $kept = 0;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = (string) preg_replace_callback(
                '~<(/?)([A-Za-z][A-Za-z0-9:-]*)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>~',
                static function (array $m) use (&$stack, &$kept, $void, $limit): string {
                    $name = strtolower($m[2]);
                    if ($m[1] === '/') {
                        for ($j = count($stack) - 1; $j >= 0; $j--) {
                            if ($stack[$j][0] === $name) {
                                $keep = $stack[$j][1];
                                while (count($stack) > $j) {
                                    if (array_pop($stack)[1]) {
                                        $kept--;
                                    }
                                }
                                return $keep ? $m[0] : '';
                            }
                        }
                        return $m[0];
                    }
                    if (isset($void[$name]) || str_ends_with($m[0], '/>')) {
                        return $m[0];
                    }
                    $keep = $kept < $limit;
                    $stack[] = [$name, $keep];
                    if ($keep) {
                        $kept++;
                    }
                    return $keep ? $m[0] : '';
                },
                $part
            );
        }
        return implode('', $parts);
    }

    /** Moves everything below $maxDepth up into the ancestor at that depth, in document order. True if anything moved. */
    private static function capTreeDepth(DOMElement $root, int $maxDepth): bool
    {
        $changed = false;
        /** @var list<array{0: DOMElement, 1: int}> $stack */
        $stack = [[$root, 0]];
        while ($stack !== []) {
            [$el, $depth] = array_pop($stack);
            if ($depth < $maxDepth) {
                foreach ($el->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $stack[] = [$child, $depth + 1];
                    }
                }
                continue;
            }
            $hasDeep = false;
            foreach ($el->childNodes as $child) {
                if ($child instanceof DOMElement && $child->firstChild !== null) {
                    $hasDeep = true;
                    break;
                }
            }
            if (!$hasDeep) {
                continue;
            }
            $changed = true;
            // Flatten: every descendant becomes a child of $el (elements emptied, text kept)
            $flat = [];
            $walk = [];
            for ($c = $el->lastChild; $c !== null; $c = $c->previousSibling) {
                $walk[] = $c;
            }
            while ($walk !== []) {
                $n = array_pop($walk);
                $flat[] = $n;
                for ($c = $n->lastChild; $c !== null; $c = $c->previousSibling) {
                    $walk[] = $c;
                }
            }
            foreach ($flat as $n) {
                $n->parentNode?->removeChild($n);
            }
            foreach ($flat as $n) {
                while ($n->firstChild !== null) {
                    $n->removeChild($n->firstChild);
                }
                if ($n instanceof \DOMText && $el->lastChild instanceof \DOMText) {
                    $el->lastChild->appendData($n->data);
                } else {
                    $el->appendChild($n);
                }
            }
        }
        return $changed;
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
