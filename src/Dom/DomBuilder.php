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
    private string $documentDirection = 'ltr';

    /** @var array<string, int> CSS counters (counter-reset / counter-increment / counter()) */
    private array $counters = [];

    /** Table parts whose DOM element is dropped from the box when it has no attributes */
    private const PLAIN_TABLE_PARTS = ['td' => true, 'th' => true, 'tr' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true];

    /** @var array<string, string> lower-cased tag names by their source spelling (shared by all boxes of that tag) */
    private array $tagNames = [];

    /** Called once the document's stylesheets are parsed and before any element style is computed (@font-face registration) */
    public ?\Closure $afterStylesheets = null;

    public function __construct(Options $options, CssParser $cssParser)
    {
        $this->options = $options;
        $this->cssParser = $cssParser;
    }

    public function getDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    /** Resolved `direction` of the root element (ltr / rtl): rtl documents start on a left page */
    public function getDocumentDirection(): string
    {
        return $this->documentDirection;
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
        $html = InputEncoding::toUtf8($html, $this->options->inputEncoding);
        $html = $this->expandBarcodeTags($html);

        // Media queries are evaluated against the printable page area
        [$pw, $ph] = $this->options->getPageDimensions();
        $m = $this->options->margins;
        $this->cssParser->setMediaViewport($pw - $m['left'] - $m['right'], $ph - $m['top'] - $m['bottom']);
        // WHATWG HTML5 parser (PHP >= 8.4) copied into a legacy DOMDocument; libxml's HTML4 parser as fallback
        $dom = $this->options->html5Parser
            ? Html5Parser::parse($html, $this->options->maxNestingDepth, fn(string $w) => $this->options->addWarning($w))
            : null;
        $dom ??= Html5Parser::parseLegacy($html, $this->options->maxNestingDepth, fn(string $w) => $this->options->addWarning($w));

        // Extract <title>, <style>, and <link rel="stylesheet">
        $this->extractHeadMetadataAndStyles($dom);
        if ($this->afterStylesheets !== null) {
            ($this->afterStylesheets)();
        }

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
        $this->documentDirection = strtolower(trim($rootStyle->get('direction', 'ltr'))) === 'rtl' ? 'rtl' : 'ltr';

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
                $listCounter = self::clampCounter($parentNode->getAttribute('start'));
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

        // <details> without `open` shows only its first <summary> (a default "Details" one when there is none), like browsers
        $closedSummary = null;
        $closedDetails = $parentNode instanceof DOMElement && $parentTag === 'details' && !$parentNode->hasAttribute('open');
        if ($closedDetails) {
            foreach ($parentNode->childNodes as $cn) {
                if ($cn instanceof DOMElement && strtolower($cn->tagName) === 'summary') {
                    $closedSummary = $cn;
                    break;
                }
            }
            if ($closedSummary === null) {
                $defSummary = new LayoutBox('summary', $parentBox->style->inheritForElement('summary'));
                $defSummary->style->set('display', 'block');
                $defText = new LayoutBox('#text', $defSummary->style);
                $defText->textContent = "\u{25B8} Details";
                $defSummary->addChild($defText);
                $parentBox->addChild($defSummary);
            }
        }

        foreach ($parentNode->childNodes as $child) {
            \IvoPdf\RenderLimits::tick();
            if ($closedDetails && $child !== $closedSummary) {
                continue;
            }
            if ($child instanceof DOMText) {
                $text = $child->data; // not wholeText: adjacent text nodes (around a dropped comment) would be duplicated
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
                // Skip pure-whitespace text nodes between block/flex/grid/table elements (a space after inline content
                // directly in <body>, <ul> or <ol> separates it from the next inline box, as in browsers)
                if (trim($text) === '' && $this->isBlockOrStructuralContainer($parentBox)) {
                    $prev = $parentBox->children === [] ? null : $parentBox->children[count($parentBox->children) - 1];
                    $keep = $prev !== null && in_array($parentBox->getDisplay(), ['block', 'list-item'], true)
                        && ($prev->isTextNode() ? trim((string) $prev->textContent) !== '' : $prev->isInlineLevel())
                        && !$prev->isPositionedOutOfFlow();
                    if (!$keep) {
                        continue;
                    }
                }

                $textBox = new LayoutBox('#text', $parentBox->style); // the box derives its own (possibly shared) text style
                $textBox->textContent = $this->applyTextTransform($text, $textBox->style->get('text-transform', 'none'));
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

            $tag = $this->tagNames[$child->tagName] ??= strtolower($child->tagName); // one string per tag name, not one per box
            if (count($this->tagNames) > 512) {
                $this->tagNames = [];
            }
            if (in_array($tag, ['script', 'style', 'head', 'meta', 'link', 'title', 'noscript', 'template', 'iframe', 'object', 'embed', 'video', 'audio', 'canvas', 'map', 'param', 'source', 'track', 'col', 'colgroup'], true)) {
                continue;
            }

            $childStyle = $this->cssParser->computeStyleForElement($child, $parentBox->style);
            if ($tag === 'pagebreak') {
                // mPDF-compatible <pagebreak /> tag
                $childStyle = $childStyle->unshared();
                $childStyle->set('display', 'block');
                $childStyle->set('page-break-before', 'always');
            }
            // mPDF pagebreak sections: a <pagebreak style="page:name"> names the pages of its following siblings
            $ownPage = strtolower(trim($childStyle->get('page', 'auto')));
            if ($tag === 'pagebreak') {
                $mpdfSectionPage = $ownPage !== '' && $ownPage !== 'auto' ? $ownPage : null;
            } elseif ($mpdfSectionPage !== null && ($ownPage === '' || $ownPage === 'auto')) {
                $childStyle = $childStyle->unshared();
                $childStyle->set('page', $mpdfSectionPage);
            }
            if (strtolower($childStyle->get('display')) === 'none') {
                continue;
            }
            if ($tag === 'footnote' && strtolower(trim($childStyle->get('float', 'none'))) === 'none') {
                // IvoPdf extension (mPDF has no <footnote> tag): <footnote>..</footnote> behaves like `float: footnote`
                $childStyle = $childStyle->unshared();
                $childStyle->set('float', 'footnote');
            }
            $childStyle = self::blockify($childStyle, $parentBox->style);
            if ($tag === 'summary' && $parentTag === 'details' && $parentNode instanceof DOMElement && $parentNode->hasAttribute('open')
                && strtolower($childStyle->get('list-style-type', '')) === 'disclosure-closed') {
                $childStyle = $childStyle->unshared();
                $childStyle->set('list-style-type', 'disclosure-open');
            }
            $this->applyCounterProperties($childStyle);

            // A table part without attributes has nothing a later pass could look up in the DOM (anchors, bookmarks,
            // attr() values, spans are read here); not keeping the element lets 10 000 cells free their DOM nodes
            $keepElement = !isset(self::PLAIN_TABLE_PARTS[$tag]) || $child->hasAttributes()
                || strtolower(trim($childStyle->get('string-set', 'none'))) !== 'none';
            $childBox = new LayoutBox($tag, $childStyle, $keepElement ? $child : null);
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
                    $listCounter = self::clampCounter($child->getAttribute('value'));
                }
                $listStyleType = strtolower($childStyle->get('list-style-type', 'disc'));
                if ($listStyleType !== 'none') {
                    if (in_array($listStyleType, ['decimal', 'decimal-leading-zero', 'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin', 'lower-roman', 'upper-roman', 'lower-greek', 'cyrillic', 'lower-cyrillic', 'upper-cyrillic'], true) || \IvoPdf\Text\CounterStyles::supports($listStyleType)) {
                        $childBox->listMarker = $this->formatListNumber($listCounter, $listStyleType);
                    } else {
                        $childBox->listMarker = match ($listStyleType) {
                            'circle' => '◦',
                            'square' => '▪',
                            'dash' => '–',
                            'disclosure-closed' => "\u{25B8}",
                            'disclosure-open' => "\u{25BE}",
                            default => '•',
                        };
                    }
                }
                $listCounter = self::clampCounter($listCounter + ($listReversed ? -1 : 1));
                $this->applyListMarkerExtras($child, $childBox, $childStyle);
            }

            // Do not recurse into <svg> children as LayoutBoxes; SvgRenderer renders the DOMElement directly
            if ($childBox->tagName !== 'svg' && $tag !== 'br' && $tag !== 'hr' && $tag !== 'img' && $childBox->formControl === null) {
                $this->addGeneratedContent($child, $childBox, 'before', $currentHref);
                $this->buildChildren($child, $childBox, $currentHref);
                $this->addGeneratedContent($child, $childBox, 'after', $currentHref);
                FirstPseudo::apply($this->cssParser, $child, $childBox);
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

            if ($childBox->children !== [] && $childBox->getDisplay() === 'inline') {
                // A float inside an inline box belongs to the nearest block container: hoist it in front of the inline box
                $kept = [];
                foreach ($childBox->children as $inner) {
                    if (!$inner->isTextNode() && in_array(strtolower(trim($inner->style->get('float', 'none'))), ['left', 'right'], true)) {
                        $parentBox->addChild($inner);
                    } else {
                        $kept[] = $inner;
                    }
                }
                $childBox->children = $kept;
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
            if (in_array($li->listMarker, ['•', '◦', '▪'], true)) {
                // Browsers pad an inside symbol marker by 7px after its "• " text
                $spanStyle = clone $spanStyle;
                $spanStyle->set('margin-right', '5.25pt');
            }
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
     * CSS Display 3 section 2.7 blockification: a floated or absolutely positioned element, and a flex / grid item,
     * has the block-level equivalent of its specified display (inline, inline-block, run-in -> block; inline-table ->
     * table; inline-flex -> flex; inline-grid -> grid; internal table display types -> block). list-item stays.
     * Without this a floated <span> was laid out as inline content and its text vanished.
     */
    private static function blockify(ComputedStyle $style, ComputedStyle $parentStyle): ComputedStyle
    {
        $display = strtolower(trim($style->get('display', 'inline')));
        $block = match ($display) {
            'inline', 'inline-block', 'run-in', 'table-cell', 'table-row', 'table-row-group', 'table-header-group',
            'table-footer-group', 'table-column', 'table-column-group', 'table-caption' => 'block',
            'inline-table' => 'table',
            'inline-flex' => 'flex',
            'inline-grid' => 'grid',
            default => null,
        };
        if ($block === null) {
            return $style;
        }
        $float = strtolower(trim($style->get('float', 'none')));
        if ($float !== 'left' && $float !== 'right') {
            $float = 'none'; // float: footnote and unknown values do not float the box
        }
        $position = strtolower(trim($style->get('position', 'static')));
        $outOfFlow = ($float !== 'none' && $float !== '') || $position === 'absolute' || $position === 'fixed';
        if (!$outOfFlow) {
            // Flex and grid items are blockified too (internal table boxes stay what they are: they are not items)
            $pd = strtolower(trim($parentStyle->get('display', 'block')));
            if (!in_array($pd, ['flex', 'inline-flex', 'grid', 'inline-grid'], true) || str_starts_with($display, 'table-')) {
                return $style;
            }
            if ($display === 'inline-table' || $display === 'inline-flex' || $display === 'inline-grid') {
                return $style;
            }
        } elseif (str_starts_with($display, 'table-') && ($float === 'none' || $float === '') && $position !== 'absolute' && $position !== 'fixed') {
            return $style;
        }
        $style = $style->unshared();
        $style->set('display', $block);
        return $style;
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
        $pseudoStyle = self::blockify($pseudoStyle, $box->style);
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
                $num = isset($m[2]) && $m[2] !== '' ? self::clampCounter($m[2]) : $default;
                if ($prop === 'counter-increment') {
                    $this->counters[$name] = self::clampCounter(($this->counters[$name] ?? 0) + $num);
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
                        $out .= $styleArg === 'decimal' ? (string) $value : (\IvoPdf\Text\CounterStyles::supports($styleArg)
                            ? \IvoPdf\Text\CounterStyles::body(self::clampCounter($value), $styleArg)
                            : rtrim($this->formatListNumber($value, $styleArg), '.)'));
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

    /** Largest counter magnitude kept (browsers clamp counters to a 32-bit range; keeps all arithmetic overflow-safe). */
    private const COUNTER_LIMIT = 2147483647;

    /** Parses/clamps a counter value to [-COUNTER_LIMIT, COUNTER_LIMIT] without int overflow or float-to-int casts. */
    private static function clampCounter(int|float|string $v): int
    {
        if (is_string($v)) {
            $v = trim($v);
            if (!is_numeric($v)) {
                return 0;
            }
            $v = $v + 0;
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return 0;
            }
            if ($v >= self::COUNTER_LIMIT) {
                return self::COUNTER_LIMIT;
            }
            if ($v <= -self::COUNTER_LIMIT) {
                return -self::COUNTER_LIMIT;
            }
            return (int) $v;
        }
        return max(-self::COUNTER_LIMIT, min(self::COUNTER_LIMIT, $v));
    }

    private function formatListNumber(int $num, string $type): string
    {
        $num = self::clampCounter($num);
        if (\IvoPdf\Text\CounterStyles::supports($type)) {
            return \IvoPdf\Text\CounterStyles::marker($num, $type);
        }
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
        $num = self::clampCounter($num);
        while ($num > 0) {
            $num--;
            $out = $alphabet[$num % $base] . $out;
            $num = intdiv($num, $base);
        }
        return $out;
    }

    private function toRoman(int $num): string
    {
        if ($num <= 0 || $num > 3999) {
            return (string) $num; // outside 1..3999 browsers fall back to decimal
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
                fn(string $msg) => $this->options->addWarning($msg),
                $this->options->maxBarcodeLength
            ),
            $html
        );
    }
}
