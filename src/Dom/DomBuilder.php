<?php

declare(strict_types=1);

namespace IvoPdf\Dom;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssParser;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Options;

/**
 * Parses HTML + CSS into a styled LayoutBox tree.
 */
class DomBuilder
{
    private Options $options;
    private CssParser $cssParser;
    private string $documentTitle = '';
    private string $documentLang = '';

    /** @var array<string, int> CSS counters (counter-reset / counter-increment / counter()) */
    private array $counters = [];

    public function __construct(Options $options, CssParser $cssParser)
    {
        $this->options = $options;
        $this->cssParser = $cssParser;
    }

    public function getDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    public function getDocumentLang(): string
    {
        return $this->documentLang;
    }

    public function build(string $html): LayoutBox
    {
        if ($this->options->maxHtmlBytes > 0 && strlen($html) > $this->options->maxHtmlBytes) {
            throw new \LengthException(sprintf('HTML input of %d bytes exceeds Options::$maxHtmlBytes (%d).', strlen($html), $this->options->maxHtmlBytes));
        }
        $html = $this->expandBarcodeTags($html);

        // Media queries are evaluated against the printable page area
        [$pw, $ph] = $this->options->getPageDimensions();
        $m = $this->options->margins;
        $this->cssParser->setMediaViewport($pw - $m['left'] - $m['right'], $ph - $m['top'] - $m['bottom']);
        // WHATWG HTML5 parser (PHP >= 8.4) copied into a legacy DOMDocument; libxml's HTML4 parser as fallback
        $dom = $this->options->html5Parser
            ? Html5Parser::parse($html, $this->options->maxNestingDepth, fn(string $w) => $this->options->addWarning($w))
            : null;
        $dom ??= Html5Parser::parseLegacy($html);

        // Extract <title>, <style>, and <link rel="stylesheet">
        $this->extractHeadMetadataAndStyles($dom);

        // Root style
        $rootVars = $this->cssParser->getRootVariables();
        $rootStyle = new ComputedStyle(
            [
                'font-family' => $this->options->defaultFont,
                'font-size' => $this->options->defaultFontSize . 'pt',
                'color' => '#111827',
            ],
            $rootVars,
            $this->options->defaultFontSize,
            $this->options->defaultFontSize,
            $this->options->defaultBoxSizing
        );

        // Apply html element rules if <html> exists
        $htmlNodes = $dom->getElementsByTagName('html');
        if ($htmlNodes->length > 0) {
            /** @var DOMElement $htmlEl */
            $htmlEl = $htmlNodes->item(0);
            $this->documentLang = trim($htmlEl->getAttribute('lang'));
            $rootStyle = $this->cssParser->computeStyleForElement($htmlEl, $rootStyle);
        }

        $bodyNodes = $dom->getElementsByTagName('body');
        if ($bodyNodes->length > 0) {
            /** @var DOMElement $bodyEl */
            $bodyEl = $bodyNodes->item(0);
            $bodyStyle = $this->cssParser->computeStyleForElement($bodyEl, $rootStyle);
            $rootBox = new LayoutBox('body', $bodyStyle, $bodyEl);
            $this->buildChildren($bodyEl, $rootBox);
            return $rootBox;
        }

        // Fallback if no <body>
        $rootBox = new LayoutBox('body', $rootStyle);
        if ($dom->documentElement instanceof DOMElement) {
            $this->buildChildren($dom->documentElement, $rootBox);
        }
        return $rootBox;
    }

    private function extractHeadMetadataAndStyles(DOMDocument $dom): void
    {
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $this->documentTitle = trim((string) $titles->item(0)?->textContent);
        }

        // Load external <link rel="stylesheet" href="..."> if allowed local or safe remote
        $links = $dom->getElementsByTagName('link');
        // @import goes through the same allowedPaths / remote checks as <link rel="stylesheet">
        $this->cssParser->setImportResolver(function (string $url): ?string {
            $css = $this->loadStylesheetContent($url);
            if ($css === null) {
                $this->options->addWarning("@import stylesheet not found or not allowed: {$url}");
            }
            return $css;
        });

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $linkMedia = trim($link->getAttribute('media'));
            if ($linkMedia !== '' && !$this->cssParser->matchesMediaQueryList($linkMedia)) {
                continue;
            }
            if (in_array('stylesheet', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $href = trim($link->getAttribute('href'));
                $cssContent = $this->loadStylesheetContent($href);
                if ($cssContent !== null) {
                    $this->cssParser->parseStylesheet($cssContent);
                }
            }
        }

        // Parse <style> blocks (respecting media="screen|print")
        $styles = $dom->getElementsByTagName('style');
        foreach ($styles as $styleNode) {
            if ($styleNode instanceof DOMElement && $styleNode->hasAttribute('media')) {
                $mediaAttr = trim($styleNode->getAttribute('media'));
                if ($mediaAttr !== '' && !$this->cssParser->matchesMediaQueryList($mediaAttr)) {
                    continue;
                }
            }
            $cssText = (string) $styleNode->textContent;
            if (trim($cssText) !== '') {
                $this->cssParser->parseStylesheet($cssText);
            }
        }
    }

    private function loadStylesheetContent(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $href)) {
            return $this->options->fetchSafeRemoteUrl($href);
        }
        $safePath = $this->options->resolveAllowedLocalFile($href);
        if ($safePath !== null) {
            $data = @file_get_contents($safePath);
            return $data !== false ? $data : null;
        }
        return null;
    }

    private function buildChildren(DOMNode $parentNode, LayoutBox $parentBox, ?string $inheritedHref = null): void
    {
        $listCounter = 1;
        $listReversed = false;
        $mpdfSectionPage = null;
        $parentTag = $parentBox->tagName;

        if ($parentNode instanceof DOMElement && $parentTag === 'ol') {
            $listReversed = $parentNode->hasAttribute('reversed');
            if ($parentNode->hasAttribute('start') && is_numeric($parentNode->getAttribute('start'))) {
                $listCounter = (int) $parentNode->getAttribute('start');
            } elseif ($listReversed) {
                $liCount = 0;
                foreach ($parentNode->childNodes as $cn) {
                    if ($cn instanceof DOMElement && strtolower($cn->tagName) === 'li') {
                        $liCount++;
                    }
                }
                $listCounter = max(1, $liCount);
            }
        }

        foreach ($parentNode->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->wholeText;
                // Preserve whitespace inside <pre>, otherwise collapse runs of whitespace
                $whiteSpace = strtolower($parentBox->style->get('white-space', 'normal'));
                if (!in_array($whiteSpace, ['pre', 'pre-wrap', 'pre-line'], true)) {
                    $text = \IvoPdf\Text\ScriptBreaker::joinSegmentBreaks($text); // CJK: a source line break between wide characters vanishes
                    $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
                }
                if ($text === '') {
                    continue;
                }
                if ($this->options->maxWordLength > 0 && strlen($text) > $this->options->maxWordLength) {
                    $text = InputGuards::breakLongWords($text, $this->options->maxWordLength, $this->options);
                }
                // Skip pure-whitespace text nodes between block/flex/grid/table elements
                if (trim($text) === '' && $this->isBlockOrStructuralContainer($parentBox)) {
                    continue;
                }

                $textStyle = clone $parentBox->style;
                $textBox = new LayoutBox('#text', $textStyle);
                $textBox->textContent = $this->applyTextTransform($text, $textStyle->get('text-transform', 'none'));
                $textBox->href = $inheritedHref;

                // Text directly inside a flex/grid container becomes an anonymous block item
                if (trim($text) !== '' && in_array($parentBox->getDisplay(), ['flex', 'inline-flex', 'grid', 'inline-grid'], true)) {
                    $anon = new LayoutBox('div', $parentBox->style->inheritForElement('div'));
                    $anon->href = $inheritedHref;
                    $anon->addChild($textBox);
                    $parentBox->addChild($anon);
                    continue;
                }
                $parentBox->addChild($textBox);
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'head', 'meta', 'link', 'title', 'noscript', 'template', 'iframe', 'object', 'embed', 'video', 'audio', 'canvas', 'map', 'param', 'source', 'track', 'col', 'colgroup'], true)) {
                continue;
            }

            $childStyle = $this->cssParser->computeStyleForElement($child, $parentBox->style);
            if ($tag === 'pagebreak') {
                // mPDF-compatible <pagebreak /> tag
                $childStyle->set('display', 'block');
                $childStyle->set('page-break-before', 'always');
            }
            // mPDF pagebreak sections: a <pagebreak style="page:name"> names the pages of its following siblings
            $ownPage = strtolower(trim($childStyle->get('page', 'auto')));
            if ($tag === 'pagebreak') {
                $mpdfSectionPage = $ownPage !== '' && $ownPage !== 'auto' ? $ownPage : null;
            } elseif ($mpdfSectionPage !== null && ($ownPage === '' || $ownPage === 'auto')) {
                $childStyle->set('page', $mpdfSectionPage);
            }
            if (strtolower($childStyle->get('display')) === 'none') {
                continue;
            }
            $this->applyCounterProperties($childStyle);

            $childBox = new LayoutBox($tag, $childStyle, $child);
            $currentHref = $inheritedHref;

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $rawHref = trim($child->getAttribute('href'));
                if ($this->isSafeHref($rawHref)) {
                    $currentHref = $rawHref;
                }
            }
            $childBox->href = $currentHref;

            if ($tag === 'img' && $child->hasAttribute('src')) {
                $src = trim($child->getAttribute('src'));
                if ($child->hasAttribute('data-page') && !str_contains($src, '#page=') && preg_match('/\.pdf(?:\?.*)?$/i', $src)) {
                    $src .= '#page=' . max(1, (int) $child->getAttribute('data-page')); // <img src="doc.pdf" data-page="2">
                }
                $svgEl = $this->tryParseSvgImage($src, $child);
                if ($svgEl !== null) {
                    $childBox->tagName = 'svg';
                    $childBox->domElement = $svgEl;
                } else {
                    $childBox->imageSrc = $src;
                }
            }

            if (in_array($tag, ['input', 'textarea', 'select', 'button'], true)) {
                // Form controls are replaced boxes: their text/options become the field value, not page text
                $childBox->formControl = \IvoPdf\Layout\FormControl::fromElement($child);
                if ($childBox->formControl === null) {
                    continue;
                }
            }

            if ($tag === 'td' || $tag === 'th') {
                if ($child->hasAttribute('colspan')) {
                    $childBox->colspan = max(1, min(100, (int) $child->getAttribute('colspan')));
                }
                if ($child->hasAttribute('rowspan')) {
                    $childBox->rowspan = max(1, min(500, (int) $child->getAttribute('rowspan')));
                }
            }

            if ($tag === 'li' || $childBox->getDisplay() === 'list-item') {
                if ($child->hasAttribute('value') && is_numeric($child->getAttribute('value'))) {
                    $listCounter = (int) $child->getAttribute('value');
                }
                $listStyleType = strtolower($childStyle->get('list-style-type', 'disc'));
                if ($listStyleType !== 'none') {
                    if (in_array($listStyleType, ['decimal', 'decimal-leading-zero', 'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin', 'lower-roman', 'upper-roman', 'lower-greek', 'cyrillic', 'lower-cyrillic', 'upper-cyrillic'], true)) {
                        $childBox->listMarker = $this->formatListNumber($listCounter, $listStyleType);
                    } else {
                        $childBox->listMarker = match ($listStyleType) {
                            'circle' => '◦',
                            'square' => '▪',
                            'dash' => '–',
                            default => '•',
                        };
                    }
                }
                $listCounter += $listReversed ? -1 : 1;
                $this->applyListMarkerExtras($child, $childBox, $childStyle);
            }

            // Do not recurse into <svg> children as LayoutBoxes; SvgRenderer renders the DOMElement directly
            if ($childBox->tagName !== 'svg' && $tag !== 'br' && $tag !== 'hr' && $tag !== 'img' && $childBox->formControl === null) {
                $this->addGeneratedContent($child, $childBox, 'before', $currentHref);
                $this->buildChildren($child, $childBox, $currentHref);
                $this->addGeneratedContent($child, $childBox, 'after', $currentHref);
            }

            if ($childBox->getDisplay() === 'contents') {
                // display: contents -> the element generates no box; its children join the parent's formatting context
                $structural = $this->isBlockOrStructuralContainer($parentBox);
                $inFlexGrid = in_array($parentBox->getDisplay(), ['flex', 'inline-flex', 'grid', 'inline-grid'], true);
                foreach ($childBox->children as $promoted) {
                    if ($promoted->isTextNode() && trim((string) $promoted->textContent) === '' && $structural) {
                        continue;
                    }
                    if ($promoted->isTextNode() && $inFlexGrid) {
                        $anon = new LayoutBox('div', $parentBox->style->inheritForElement('div'));
                        $anon->href = $promoted->href;
                        $anon->addChild($promoted);
                        $promoted = $anon;
                    }
                    $parentBox->addChild($promoted);
                }
                continue;
            }

            $parentBox->addChild($childBox);
        }
    }

    /**
     * list-style-image, list-style-position: inside and ::marker styling for a list item.
     */
    private function applyListMarkerExtras(DOMElement $el, LayoutBox $li, \IvoPdf\Css\ComputedStyle $style): void
    {
        $markerImg = null;
        if (preg_match('/url\(\s*([^)]+?)\s*\)/i', $style->get('list-style-image', 'none'), $lm) === 1) {
            $markerImg = trim($lm[1], " \t'\"");
        }
        $li->markerStyle = $this->cssParser->computePseudoStyle($el, $style, 'marker', false);
        if ($markerImg !== null && $markerImg !== '') {
            $li->listMarkerImage = $markerImg;
        }
        if (strtolower(trim($style->get('list-style-position', 'outside'))) !== 'inside') {
            return;
        }
        if ($li->listMarker === null && $li->listMarkerImage === null) {
            return;
        }
        // Inside markers become an inline box at the start of the first line
        $spanStyle = $li->markerStyle ?? $style->inheritForElement('span');
        if ($li->listMarkerImage !== null) {
            $imgStyle = $style->inheritForElement('img');
            $imgStyle->set('vertical-align', 'middle');
            $imgStyle->set('max-height', '1.2em');
            $imgStyle->set('margin-right', '0.4em');
            $img = new LayoutBox('img', $imgStyle);
            $img->imageSrc = $li->listMarkerImage;
            $li->addChild($img);
        } else {
            $span = new LayoutBox('span', $spanStyle);
            $text = new LayoutBox('#text', clone $spanStyle);
            $text->textContent = $li->listMarker . ' ';
            $span->addChild($text);
            $li->addChild($span);
        }
        $li->listMarker = null;
        $li->listMarkerImage = null;
    }

    /**
     * Creates the ::before / ::after box (CSS generated content) for an element.
     */
    private function addGeneratedContent(DOMElement $el, LayoutBox $box, string $pseudo, ?string $href): void
    {
        $pseudoStyle = $this->cssParser->computePseudoStyle($el, $box->style, $pseudo);
        if ($pseudoStyle === null && strtolower($el->tagName) === 'q') {
            // User-agent default quotes for <q>
            $pseudoStyle = $box->style->inheritForElement('span');
            $pseudoStyle->set('content', $pseudo === 'before' ? 'open-quote' : 'close-quote');
        }
        if ($pseudoStyle === null) {
            return;
        }
        $this->applyCounterProperties($pseudoStyle);
        $rawContent = $pseudoStyle->get('content', '');
        if (\IvoPdf\Paged\PagedContent::isPaged($rawContent)) {
            // leader() / target-counter(): placeholders resolved after pagination (PageRefResolver)
            $pseudoBox = \IvoPdf\Paged\PagedContent::buildBox($rawContent, $el, $pseudoStyle, $href, fn(string $c): string => $this->evaluateContent($c, $el));
            if ($pseudoBox === null) {
                return;
            }
        } else {
            $text = $this->evaluateContent($rawContent, $el);
            if ($text === '') {
                return;
            }
            $pseudoBox = new LayoutBox('span', $pseudoStyle);
            $pseudoBox->href = $href;
            $textBox = new LayoutBox('#text', clone $pseudoStyle);
            $textBox->textContent = $this->applyTextTransform($text, $pseudoStyle->get('text-transform', 'none'));
            $textBox->href = $href;
            $pseudoBox->addChild($textBox);
        }

        if ($pseudo === 'before') {
            $pseudoBox->parent = $box;
            array_unshift($box->children, $pseudoBox);
        } else {
            $box->addChild($pseudoBox);
        }
    }

    private function applyCounterProperties(\IvoPdf\Css\ComputedStyle $style): void
    {
        foreach (['counter-reset' => 0, 'counter-set' => 0, 'counter-increment' => 1] as $prop => $default) {
            $val = trim($style->get($prop, 'none'));
            if ($val === '' || strtolower($val) === 'none') {
                continue;
            }
            preg_match_all('/([a-zA-Z_][\w-]*)(?:\s+(-?\d+))?/', $val, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                $name = $m[1];
                $num = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $default;
                if ($prop === 'counter-increment') {
                    $this->counters[$name] = ($this->counters[$name] ?? 0) + $num;
                } else {
                    $this->counters[$name] = $num;
                }
            }
        }
    }

    /**
     * Evaluates a CSS `content` value: strings, attr(), counter()/counters(), quotes.
     * counter(page) / counter(pages) become the {PAGE_NUM} / {PAGE_COUNT} placeholders (dompdf compatible).
     */
    private function evaluateContent(string $content, DOMElement $el): string
    {
        $out = '';
        $len = strlen($content);
        $i = 0;
        while ($i < $len) {
            $ch = $content[$i];
            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                $str = '';
                while ($j < $len && $content[$j] !== $ch) {
                    if ($content[$j] === '\\' && $j + 1 < $len) {
                        if (preg_match('/^[0-9a-fA-F]{1,6}\s?/', substr($content, $j + 1, 7), $hm)) {
                            $str .= mb_chr((int) hexdec(trim($hm[0])), 'UTF-8');
                            $j += 1 + strlen($hm[0]);
                            continue;
                        }
                        $str .= $content[$j + 1];
                        $j += 2;
                        continue;
                    }
                    $str .= $content[$j];
                    $j++;
                }
                $out .= $str;
                $i = $j + 1;
                continue;
            }
            if (preg_match('/^(attr|counters?)\(([^)]*)\)/i', substr($content, $i), $fm)) {
                $fn = strtolower($fm[1]);
                $args = array_map(static fn(string $a): string => trim($a, " \t'\""), explode(',', $fm[2]));
                if ($fn === 'attr') {
                    $out .= $el->getAttribute($args[0]);
                } else {
                    $name = $args[0];
                    if ($name === 'page') {
                        $out .= '{PAGE_NUM}';
                    } elseif ($name === 'pages') {
                        $out .= '{PAGE_COUNT}';
                    } else {
                        $styleArg = $fn === 'counters' ? ($args[2] ?? 'decimal') : ($args[1] ?? 'decimal');
                        $value = $this->counters[$name] ?? 0;
                        $out .= $styleArg === 'decimal' ? (string) $value : rtrim($this->formatListNumber($value, $styleArg), '.)');
                    }
                }
                $i += strlen($fm[0]);
                continue;
            }
            if (preg_match('/^(open-quote|close-quote|no-open-quote|no-close-quote)/i', substr($content, $i), $qm)) {
                $out .= match (strtolower($qm[1])) {
                    'open-quote' => '“',
                    'close-quote' => '”',
                    default => '',
                };
                $i += strlen($qm[0]);
                continue;
            }
            $i++;
        }
        return $out;
    }

    private function isSafeHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }
        if (preg_match('/^\s*(javascript|vbscript|data|file|phar):/i', $href)) {
            return false;
        }
        return true;
    }

    /**
     * Converts `<img src="data:image/svg+xml,...">` or `<img src="file.svg">` into a parsed `<svg>` DOMElement.
     */
    private function tryParseSvgImage(string $src, DOMElement $imgElement): ?DOMElement
    {
        $svgXml = null;
        $isMetafile = false;
        $metaMime = preg_match('/^data:image\/(?:x-)?(?:wmf|emf|x-wmf|x-emf)(?![a-z])/i', $src) === 1;
        if ($metaMime || preg_match('/\.(?:wmf|emf)(?:\?.*)?$/i', $src)) {
            // Windows metafiles (WMF / EMF) are converted to SVG markup and rendered as vectors
            $raw = null;
            if ($metaMime) {
                $commaPos = strpos($src, ',');
                if ($commaPos !== false) {
                    $payload = substr($src, $commaPos + 1);
                    $decoded = str_contains(substr($src, 0, $commaPos), ';base64') ? base64_decode($payload, true) : rawurldecode($payload);
                    $raw = $decoded !== false ? $decoded : null;
                }
            } elseif (preg_match('/^https?:\/\//i', $src)) {
                $raw = $this->options->fetchSafeRemoteUrl($src);
            } else {
                $safePath = $this->options->resolveAllowedLocalFile($src);
                $data = $safePath !== null ? @file_get_contents($safePath) : false;
                $raw = $data !== false ? $data : null;
            }
            if ($raw !== null && \IvoPdf\Image\MetafileConverter::isMetafile($raw)) {
                $svgXml = \IvoPdf\Image\MetafileConverter::toSvg($raw);
                $isMetafile = $svgXml !== null;
            }
        } elseif (str_starts_with(strtolower($src), 'data:image/svg+xml')) {
            $commaPos = strpos($src, ',');
            if ($commaPos !== false) {
                $meta = substr($src, 5, $commaPos - 5);
                $payload = substr($src, $commaPos + 1);
                if (str_contains($meta, ';base64')) {
                    $decoded = base64_decode($payload, true);
                    $svgXml = $decoded !== false ? $decoded : null;
                } else {
                    $svgXml = rawurldecode($payload);
                }
            }
        } elseif (preg_match('/\.svg(?:\?.*)?$/i', $src)) {
            if (preg_match('/^https?:\/\//i', $src)) {
                $svgXml = $this->options->fetchSafeRemoteUrl($src);
            } else {
                $safePath = $this->options->resolveAllowedLocalFile($src);
                if ($safePath !== null) {
                    $data = @file_get_contents($safePath);
                    $svgXml = $data !== false ? $data : null;
                }
            }
        }

        if ($svgXml === null || stripos($svgXml, '<svg') === false) {
            return null;
        }

        $svgDom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = @$svgDom->loadXML($svgXml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok || !$svgDom->documentElement instanceof DOMElement || strtolower($svgDom->documentElement->tagName) !== 'svg') {
            return null;
        }

        $svgEl = $svgDom->documentElement;
        if ($imgElement->hasAttribute('width') && ($isMetafile || !$svgEl->hasAttribute('width'))) {
            $svgEl->setAttribute('width', $imgElement->getAttribute('width'));
        }
        if ($imgElement->hasAttribute('height') && ($isMetafile || !$svgEl->hasAttribute('height'))) {
            $svgEl->setAttribute('height', $imgElement->getAttribute('height'));
        }

        return $svgEl;
    }

    private function isBlockOrStructuralContainer(LayoutBox $box): bool
    {
        $display = $box->getDisplay();
        if (in_array($display, ['flex', 'inline-flex', 'grid', 'inline-grid', 'table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row'], true)) {
            return true;
        }
        if (in_array($box->tagName, ['body', 'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr'], true)) {
            return true;
        }
        return false;
    }

    private function applyTextTransform(string $text, string $transform): string
    {
        return match (strtolower(trim($transform))) {
            'uppercase' => mb_strtoupper($text, 'UTF-8'),
            'lowercase' => mb_strtolower($text, 'UTF-8'),
            'capitalize' => (string) preg_replace_callback(
                '/(^|[\s\-\/(\["\'„“])(\p{Ll})/u',
                static fn(array $m): string => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
                $text
            ),
            default => $text,
        };
    }

    private function formatListNumber(int $num, string $type): string
    {
        if ($num <= 0) {
            return $num . '.';
        }
        return match ($type) {
            'lower-alpha', 'lower-latin' => strtolower($this->toAlpha($num, range('A', 'Z'))) . '.',
            'upper-alpha', 'upper-latin' => $this->toAlpha($num, range('A', 'Z')) . '.',
            'lower-greek' => $this->toAlpha($num, preg_split('//u', 'αβγδεζηθικλμνξοπρστυφχψω', -1, PREG_SPLIT_NO_EMPTY) ?: ['α']) . '.',
            'cyrillic', 'lower-cyrillic' => $this->toAlpha($num, preg_split('//u', 'абвгдежзиклмнопрстуфхцчшщэюя', -1, PREG_SPLIT_NO_EMPTY) ?: ['а']) . ')',
            'upper-cyrillic' => $this->toAlpha($num, preg_split('//u', 'АБВГДЕЖЗИКЛМНОПРСТУФХЦЧШЩЭЮЯ', -1, PREG_SPLIT_NO_EMPTY) ?: ['А']) . ')',
            'decimal-leading-zero' => sprintf('%02d', $num) . '.',
            'lower-roman' => strtolower($this->toRoman($num)) . '.',
            'upper-roman' => $this->toRoman($num) . '.',
            default => $num . '.',
        };
    }

    /**
     * Bijective base-N numbering (a, b, ... z, aa, ab, ...).
     *
     * @param list<string> $alphabet
     */
    private function toAlpha(int $num, array $alphabet): string
    {
        $base = count($alphabet);
        $out = '';
        while ($num > 0) {
            $num--;
            $out = $alphabet[$num % $base] . $out;
            $num = intdiv($num, $base);
        }
        return $out;
    }

    private function toRoman(int $num): string
    {
        if ($num <= 0) {
            return (string) $num;
        }
        $map = [
            'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
            'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
            'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1,
        ];
        $res = '';
        foreach ($map as $roman => $val) {
            while ($num >= $val) {
                $res .= $roman;
                $num -= $val;
            }
        }
        return $res;
    }

    private function expandBarcodeTags(string $html): string
    {
        if (stripos($html, '<barcode') === false) {
            return $html;
        }
        return (string) preg_replace_callback(
            '/<barcode\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>(?:\s*<\/barcode>)?/is',
            fn(array $m): string => \IvoPdf\Widget\Barcode::fromAttributes(
                rtrim($m[1], "/ \t\r\n"),
                fn(string $msg) => $this->options->addWarning($msg)
            ),
            $html
        );
    }
}
