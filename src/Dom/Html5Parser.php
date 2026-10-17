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
        $html = self::expandSelfClosing(self::stripNoscript($html));
        // lexbor builds its tree with PHP's allocator in one call: refuse up front what cannot fit (a catchable
        // LimitExceededException instead of PHP's fatal error halfway through the parser)
        \IvoPdf\RenderLimits::ensure(self::estimateParseBytes($html), 'parsing the HTML (' . number_format(strlen($html)) . ' bytes)');
        try {
            $src = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return null;
        }
        unset($html);

        $dst = new DOMDocument('1.0', 'UTF-8');
        $maxDepth = max(8, $maxDepth);
        $flattened = false;

        $root = $src->documentElement;
        if ($root === null) {
            return null;
        }

        // Walks the tree with a cursor (first child / next sibling / back up through the ancestors) instead of a stack of
        // every pending node: 200 000 siblings would otherwise sit in the stack at once
        /** @var list<array{0: \Dom\Node, 1: DOMNode, 2: int}> $path ancestors: node, the target it was copied into, depth */
        $path = [];
        $node = $root;
        $parent = $dst;
        $depth = 0;
        while (true) {
            \IvoPdf\RenderLimits::tick();
            $childTarget = null;
            if ($node instanceof \Dom\Text) {
                // Merge with a preceding text node (left behind when a comment between them was dropped)
                if ($parent->lastChild instanceof \DOMText) {
                    $parent->lastChild->appendData($node->data);
                } else {
                    $parent->appendChild($dst->createTextNode($node->data));
                }
            } elseif ($node instanceof \Dom\Element) {
                $name = $node->localName;
                $el = self::makeElement($dst, $name);
                self::copyAttributes($node, $el);
                $parent->appendChild($el);
                if ($name === 'template') {
                    // its content fragment is inert: nothing is rendered
                } elseif ($depth >= $maxDepth) {
                    // Too deep: the children become siblings of the element instead of nesting further
                    $flattened = true;
                    $childTarget = [$parent, $depth];
                } else {
                    $childTarget = [$el, $depth + 1];
                }
            }
            // comments, processing instructions, doctype: skipped

            if ($childTarget !== null && $node->firstChild !== null) {
                $path[] = [$node, $parent, $depth];
                [$parent, $depth] = $childTarget;
                $node = $node->firstChild;
                continue;
            }
            while ($path === [] || $node->nextSibling === null) {
                if ($path === []) {
                    break 2; // back at the root element: done
                }
                [$node, $parent, $depth] = array_pop($path);
            }
            $node = $node->nextSibling;
        }

        if ($flattened && $warn !== null) {
            $warn("HTML nesting deeper than {$maxDepth} levels was flattened (see Options::\$maxNestingDepth).");
        }
        return $dst;
    }

    /**
     * Peak memory (bytes) lexbor takes from PHP's allocator (chunk level, what memory_limit counts) to parse $html,
     * from counts that take one pass of PCRE each: about 800 bytes per tag, 800 per text node, 420 per attribute, 250
     * per comment and 4 per input byte (measured on PHP 8.5 for flat and nested elements, SVG shapes, tables, links,
     * text runs and attribute floods; the estimate stays above the measured peak).
     */
    public static function estimateParseBytes(string $html): int
    {
        $tags = (int) preg_match_all('/<[A-Za-z!]/', $html);
        $texts = (int) preg_match_all('/>[^<]/', $html);
        $comments = substr_count($html, '<!--');
        $attrs = substr_count($html, '=');
        return 800 * $tags + 800 * $texts + 420 * $attrs + 250 * $comments + 4 * strlen($html);
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
        $html = self::html5Normalize($html);
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

    /** HTML5 named character references that are also recognised without the semicolon (the "legacy" list) */
    private const LEGACY_ENTITIES = [
        'AElig' => 1, 'AMP' => 1, 'Aacute' => 1, 'Acirc' => 1, 'Agrave' => 1, 'Aring' => 1, 'Atilde' => 1, 'Auml' => 1,
        'COPY' => 1, 'Ccedil' => 1, 'ETH' => 1, 'Eacute' => 1, 'Ecirc' => 1, 'Egrave' => 1, 'Euml' => 1, 'GT' => 1,
        'Iacute' => 1, 'Icirc' => 1, 'Igrave' => 1, 'Iuml' => 1, 'LT' => 1, 'Ntilde' => 1, 'Oacute' => 1, 'Ocirc' => 1,
        'Ograve' => 1, 'Oslash' => 1, 'Otilde' => 1, 'Ouml' => 1, 'QUOT' => 1, 'REG' => 1, 'THORN' => 1, 'Uacute' => 1,
        'Ucirc' => 1, 'Ugrave' => 1, 'Uuml' => 1, 'Yacute' => 1, 'aacute' => 1, 'acirc' => 1, 'acute' => 1, 'aelig' => 1,
        'agrave' => 1, 'amp' => 1, 'aring' => 1, 'atilde' => 1, 'auml' => 1, 'brvbar' => 1, 'ccedil' => 1, 'cedil' => 1,
        'cent' => 1, 'copy' => 1, 'curren' => 1, 'deg' => 1, 'divide' => 1, 'eacute' => 1, 'ecirc' => 1, 'egrave' => 1,
        'eth' => 1, 'euml' => 1, 'frac12' => 1, 'frac14' => 1, 'frac34' => 1, 'gt' => 1, 'iacute' => 1, 'icirc' => 1,
        'iexcl' => 1, 'igrave' => 1, 'iquest' => 1, 'iuml' => 1, 'laquo' => 1, 'lt' => 1, 'macr' => 1, 'micro' => 1,
        'middot' => 1, 'nbsp' => 1, 'not' => 1, 'ntilde' => 1, 'oacute' => 1, 'ocirc' => 1, 'ograve' => 1, 'ordf' => 1,
        'ordm' => 1, 'oslash' => 1, 'otilde' => 1, 'ouml' => 1, 'para' => 1, 'plusmn' => 1, 'pound' => 1, 'quot' => 1,
        'raquo' => 1, 'reg' => 1, 'sect' => 1, 'shy' => 1, 'sup1' => 1, 'sup2' => 1, 'sup3' => 1, 'szlig' => 1,
        'thorn' => 1, 'times' => 1, 'uacute' => 1, 'ucirc' => 1, 'ugrave' => 1, 'uml' => 1, 'uuml' => 1, 'yacute' => 1,
        'yen' => 1, 'yuml' => 1,
    ];

    /** HTML5 elements whose start tag closes an open <p> but that libxml's HTML4 parser does not know */
    private const HTML5_BLOCKS = 'section|article|aside|header|footer|nav|main|figure|figcaption|details|summary|dialog|hgroup|search';

    /**
     * Brings source text closer to what the HTML5 tree builder makes of it before libxml's HTML4 parser (PHP < 8.4)
     * sees it, so the same template renders alike on every PHP version:
     *  - character references: the HTML5 names (`&hellip;`, `&NotEqual;`), the legacy names without a semicolon
     *    (`&nbsp`, `&amp`, `&copy`; in attribute values only when not followed by a letter, digit or `=`), and the
     *    longest legacy prefix of an unknown name (`&notanentity;` => `¬anentity;`); anything else stays literal text
     *  - `<![CDATA[...]]>` outside SVG / MathML is a bogus comment (dropped); `</br>` is a line break
     *  - an HTML5 sectioning element (`<section>`, `<article>`, ...) closes an open paragraph
     *  - a `<form>` between table parts wraps nothing (its rows stay in the table), a `<td>` right after `<table>` gets
     *    its implied `<tr>`
     * Comments, <script> and <style> are left untouched.
     */
    public static function html5Normalize(string $html): string
    {
        if (!str_contains($html, '&') && !str_contains($html, '<!') && stripos($html, '</br') === false
            && stripos($html, '<form') === false && stripos($html, '<table') === false
            && preg_match('~<(?:' . self::HTML5_BLOCKS . ')\b~i', $html) !== 1) {
            return $html;
        }
        $parts = preg_split('~(<!--.*?-->|<script\b.*?</script\s*>|<style\b.*?</style\s*>|<svg\b.*?</svg\s*>|<math\b.*?</math\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            $lower = strtolower(substr($part, 0, 5));
            if ($i % 2 === 1) {
                if ($lower === '<svg' || str_starts_with($lower, '<svg') || str_starts_with($lower, '<math')) {
                    $parts[$i] = self::normalizeMarkup($part, false); // entities yes, CDATA is text in foreign content
                }
                continue;
            }
            $parts[$i] = self::normalizeMarkup($part, true);
        }
        return implode('', $parts);
    }

    private static function normalizeMarkup(string $html, bool $htmlContent): string
    {
        if ($htmlContent && str_contains($html, '<![')) {
            // HTML content: a CDATA section is a bogus comment that ends at the first ">"
            $html = (string) preg_replace('~<!\[CDATA\[[^>]*>~', '', $html);
        }
        if ($htmlContent) {
            $html = (string) preg_replace('~</br\s*>~i', '<br>', $html);
            $html = (string) preg_replace('~<(' . self::HTML5_BLOCKS . ')\b~i', '</p><$1', $html);
            if (stripos($html, '<form') !== false) {
                $html = (string) preg_replace('~(<(?:table|thead|tbody|tfoot|tr)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>\s*)<form\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>~i', '$1', $html);
                $html = (string) preg_replace('~</form\s*>(?=\s*(?:</t(?:able|head|body|foot|r)\s*>|<t(?:r|head|body|foot)\b))~i', '', $html);
            }
            $html = (string) preg_replace('~(<table\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>\s*)(?=<t[dh]\b)~i', '$1<tr>', $html);
        }
        if (!str_contains($html, '&')) {
            return $html;
        }
        // Tags (attribute values follow the attribute rules) and text in between
        $pieces = preg_split('~(<[A-Za-z/!?](?:"[^"]*"|\'[^\']*\'|[^>"\'])*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            return $html;
        }
        foreach ($pieces as $k => $piece) {
            if (str_contains($piece, '&')) {
                $pieces[$k] = self::fixReferences($piece, $k % 2 === 1);
            }
        }
        return implode('', $pieces);
    }

    private static function fixReferences(string $text, bool $inTag): string
    {
        return (string) preg_replace_callback(
            '~&([A-Za-z][A-Za-z0-9]{0,40})(;?)~',
            static function (array $m) use ($text, $inTag): string {
                $name = $m[1][0];
                $semi = $m[2][0];
                if ($semi === ';') {
                    $decoded = html_entity_decode('&' . $name . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded !== '&' . $name . ';') {
                        return self::referenceText($decoded, $name);
                    }
                }
                $next = $text[$m[0][1] + strlen($m[0][0])] ?? '';
                for ($len = min(6, strlen($name)); $len >= 2; $len--) {
                    $prefix = substr($name, 0, $len);
                    if (!isset(self::LEGACY_ENTITIES[$prefix])) {
                        continue;
                    }
                    $rest = substr($name, $len) . $semi;
                    if ($inTag && ($rest !== '' ? ctype_alnum($rest[0]) : $next === '=')) {
                        break; // attribute value: "&copy=1", "&notx" stay literal
                    }
                    return self::referenceText(html_entity_decode('&' . $prefix . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8'), $prefix) . $rest;
                }
                return '&amp;' . $name . $semi; // not a reference: a literal ampersand
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
    }

    /** The decoded character, or a reference libxml decodes itself for the markup-significant ones (& < > " '). */
    private static function referenceText(string $decoded, string $name): string
    {
        return match ($decoded) {
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;',
            "'" => '&#39;',
            default => $decoded,
        };
    }

    private static function loadLegacy(string $html, ?bool &$tooDeep): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        // libxml's diagnostics are not buffered (libxml_use_internal_errors(true) keeps one LibXMLError per problem:
        // 200 000 unknown SVG tags would take more memory than the document) but read as they come and dropped
        $prevLibxml = libxml_use_internal_errors(false);
        $tooDeep = false;
        set_error_handler(static function (int $no, string $message) use (&$tooDeep): bool {
            if (stripos($message, 'depth') !== false) {
                $tooDeep = true;
            }
            return true;
        });

        // Ensure UTF-8 meta wrapper if not a complete HTML document
        $hasHtmlTag = stripos($html, '<html') !== false || stripos($html, '<!doctype') !== false;
        if (!$hasHtmlTag) {
            $wrappedHtml = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
        } elseif (stripos($html, '<?xml') === false) {
            $wrappedHtml = '<?xml encoding="UTF-8">' . $html;
        } else {
            $wrappedHtml = $html;
        }

        try {
            $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        } finally {
            restore_error_handler();
            libxml_use_internal_errors($prevLibxml);
        }
        unset($wrappedHtml);
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
        // Cursor walk over the elements (first child / next sibling / parent): a stack holding every pending sibling
        // would keep 200 000 DOM wrappers alive at once
        $el = $root;
        $depth = 0;
        while (true) {
            \IvoPdf\RenderLimits::tick();
            if ($depth < $maxDepth) {
                $child = $el->firstElementChild;
                if ($child !== null) {
                    $el = $child;
                    $depth++;
                    continue;
                }
            } else {
                self::flattenBelow($el, $changed);
            }
            while ($el !== $root && $el->nextElementSibling === null) {
                $el = $el->parentNode;
                $depth--;
            }
            if ($el === $root || !$el instanceof DOMElement) {
                break;
            }
            $el = $el->nextElementSibling;
        }
        return $changed;
    }

    /** Makes every descendant of $el a child of it (elements emptied, text kept), when it has nested content. */
    private static function flattenBelow(DOMElement $el, bool &$changed): void
    {
        $hasDeep = false;
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $child->firstChild !== null) {
                $hasDeep = true;
                break;
            }
        }
        if (!$hasDeep) {
            return;
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
