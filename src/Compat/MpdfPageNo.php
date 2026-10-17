<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\Paginator;
use IvoPdf\Paged\PageLayoutPlan;

/**
 * mPDF `PageNo()` / `$mpdf->page`: the number of pages of what has been written so far, without laying out the whole
 * document again at every call (mPDF loops call it after every WriteHTML()).
 *
 * The first call renders the document. It remembers an anchor: a WriteHTML() chunk whose first element is a child of
 * <body>, with the page it starts on and its position on that page. When later calls find that only HTML was appended
 * (same settings, same earlier HTML), only the chunks from the anchor on are laid out, in a document that holds the
 * stylesheets of the earlier HTML and a spacer that puts the anchor chunk exactly where it was. Layout and pagination
 * are translation invariant at a page boundary for such content, so the page count is the same as a full layout's.
 * The shortcut is taken only when that is known to hold and is checked on the result (the anchor must land where it
 * was); anything else (floats or positioned boxes in the way, CSS counters, multi-column, footnotes, table of contents
 * / index, per-page geometry, mPDF tags with attributes, chunks that are not well-formed body-level elements, ...)
 * lays the whole document out as before.
 *
 * @property string $html
 * @property ?string $renderedPdf
 * @property int $pageCount
 * @property \IvoPdf\Options $options
 */
trait MpdfPageNo
{
    /** @var list<int> byte offset in $this->html where each WriteHTML() chunk starts */
    private array $mpdfChunkStarts = [];
    /** @var ?array{sig: string, html: string, pages: int, ph: float, anchor: ?array{chunk: int, page: int, y: float}} */
    private ?array $mpdfPnCache = null;

    /** Current page number: the number of pages of what has been written so far. */
    public function PageNo(): int
    {
        if ($this->renderedPdf !== null) {
            return $this->pageCount;
        }
        $sig = $this->mpdfPnSignature();
        $c = $this->mpdfPnCache;
        if ($sig !== null && $c !== null && $c['sig'] === $sig && strlen($this->html) >= strlen($c['html']) && str_starts_with($this->html, $c['html'])) {
            if (strlen($this->html) === strlen($c['html'])) {
                return $c['pages'];
            }
            if ($c['anchor'] !== null) {
                $pages = $this->mpdfPnIncremental($c, $sig);
                if ($pages !== null) {
                    return $pages;
                }
            }
        }
        return $this->mpdfPnFull($sig);
    }

    /** Settings fingerprint: equal => same layout for the same HTML (null: cannot tell). */
    private function mpdfPnSignature(): ?string
    {
        $o = $this->options->fingerprint();
        return $o === null ? null : $this->stateVersion . '|' . ($this->mpdfWritten ? 'w' : '-') . '|' . $o;
    }

    /** Full render (exact); remembers an anchor for the next calls. */
    private function mpdfPnFull(?string $sig): int
    {
        $anchor = null;
        $ph = 0.0;
        $tagged = null;
        $candidates = [];
        if ($sig !== null && $this->mpdfPnDocumentOk()) {
            // the last chunks that could serve as an anchor get a data attribute on their first element
            $n = count($this->mpdfChunkStarts);
            for ($i = $n - 1; $i >= 1 && count($candidates) < 4; $i--) {
                if ($this->mpdfPnChunkKind($this->mpdfPnChunk($i)) === 'block') {
                    $candidates[] = $i;
                }
            }
            if ($candidates !== []) {
                $tagged = $this->mpdfPnTag($this->html, 0, $candidates);
            }
        }
        $saved = $this->html;
        if ($tagged !== null) {
            $this->html = $tagged;
            $this->layoutProbe = function (string $stage, LayoutBox $root, ?Paginator $pg, int $pages, PageLayoutPlan $plan) use (&$anchor, &$ph): bool {
                if ($stage === 'paginate' && $pg !== null) {
                    $anchor = $this->mpdfPnAnchor($root, $pg, $plan, 0);
                    $ph = $plan->box(0)['ph'];
                }
                return true;
            };
        }
        try {
            $this->render();
        } finally {
            $this->html = $saved;
            $this->layoutProbe = null;
        }
        $this->mpdfPnCache = $sig === null ? null : ['sig' => $sig, 'html' => $this->html, 'pages' => $this->pageCount, 'ph' => $ph, 'anchor' => $anchor];
        return $this->pageCount;
    }

    /**
     * Lays out only the chunks from the anchor on; null when the shortcut cannot be taken (the caller renders all).
     *
     * @param array{sig: string, html: string, pages: int, ph: float, anchor: ?array{chunk: int, page: int, y: float}} $c
     */
    private function mpdfPnIncremental(array $c, string $sig): ?int
    {
        $a = $c['anchor'];
        if ($a === null) {
            return null;
        }
        $j = $a['chunk'];
        $ph = $c['ph'];
        if (!isset($this->mpdfChunkStarts[$j]) || $ph <= 50.0 || !$this->mpdfPnDocumentOk()) {
            return null;
        }
        $tagIdx = [];
        for ($i = $j, $n = count($this->mpdfChunkStarts); $i < $n; $i++) {
            $kind = $this->mpdfPnChunkKind($this->mpdfPnChunk($i));
            if ($kind === 'bad') {
                return null;
            }
            if ($kind === 'block') {
                $tagIdx[] = $i;
            }
        }
        if (!in_array($j, $tagIdx, true)) {
            return null;
        }
        $start = $this->mpdfChunkStarts[$j];
        $head = $this->mpdfPnHead(substr($this->html, 0, $start));
        $tail = $this->mpdfPnTag(substr($this->html, $start), $start, $tagIdx);
        $doc = static fn(float $h): string => $head[0] . '<div data-ivo-pn="s" style="display:block;height:' . sprintf('%.4F', $h) . 'pt;margin:0;padding:0;border:0;float:none;position:static"></div>' . $tail . $head[1];

        // 1. Where the anchor chunk's first box lands after a spacer of known height: the gap is its own top margin
        //    (collapsed with its first descendants'); in the full layout the box top was at $a['y'] on its page
        $gap = null;
        $probe1 = function (string $stage, LayoutBox $root) use (&$gap, $j): bool {
            $body = self::mpdfPnBody($root);
            if ($body !== null) {
                [$spacer, $first] = [self::mpdfPnTagged($body, 's'), self::mpdfPnTagged($body, (string) $j)];
                if ($spacer !== null && $first !== null) {
                    $gap = $first->y - ($spacer->y + $spacer->height);
                }
            }
            return false; // layout only
        };
        if (!$this->mpdfPnMeasure($doc(max(0.05, $a['y'])), $probe1) || $gap === null) {
            return null;
        }
        $base = $a['page'];
        $h = $a['y'] - $gap;
        if ($h < 0.05) {
            if ($base === 0) {
                return null;
            }
            $base--;
            $h += $ph;
        }
        if ($h > $ph + 0.01) {
            return null;
        }

        // 2. The real measuring pass
        $result = null;
        $anchor = null;
        $probe2 = function (string $stage, LayoutBox $root, ?Paginator $pg, int $pages, PageLayoutPlan $plan) use (&$result, &$anchor, $j, $a, $base, $ph): bool {
            if ($stage !== 'paginate' || $pg === null) {
                return true;
            }
            $body = self::mpdfPnBody($root);
            $spacer = $body !== null ? self::mpdfPnTagged($body, 's') : null;
            $first = $body !== null ? self::mpdfPnTagged($body, (string) $j) : null;
            [$top] = $pg->getPageBounds(0);
            if ($spacer === null || $first === null || !$plan->isUniform() || $plan->names() !== [] || abs($plan->box(0)['ph'] - $ph) > 0.01
                || abs($spacer->y - $top) > 0.05 || !$this->mpdfPnTailOk($body, $spacer)) {
                return false;
            }
            // the anchor must sit exactly where the full layout put it
            [$expectTop] = $pg->getPageBounds($a['page'] - $base);
            if (abs($first->y - ($expectTop + $a['y'])) > 0.05) {
                return false;
            }
            $result = $base + $pages;
            $anchor = $this->mpdfPnAnchor($root, $pg, $plan, $base);
            return false;
        };
        if (!$this->mpdfPnMeasure($doc($h), $probe2) || $result === null) {
            return null;
        }
        if ($this->options->maxPages > 0) {
            $result = min($result, $this->options->maxPages);
        }
        $this->mpdfPnCache = ['sig' => $sig, 'html' => $this->html, 'pages' => $result, 'ph' => $ph, 'anchor' => $anchor ?? $a];
        $this->pageCount = $result;
        return $result;
    }

    /**
     * One layout pass of $html (renderCore() stopped by the probe); the engine state that a pass changes is put back.
     *
     * @param \Closure(string, LayoutBox, ?Paginator, int, PageLayoutPlan): bool $probe
     */
    private function mpdfPnMeasure(string $html, \Closure $probe): bool
    {
        $saved = [$this->html, $this->pageCount, $this->layoutTree, $this->mpdfMem, $this->mpdfNextMem, $this->mpdfState, $this->footnoteMem, $this->footnoteState, $this->options->getWarnings(), MpdfRenderHooks::$markerPages];
        $this->html = $html;
        $this->mpdfMem = ['ix' => [], 'blank' => []];
        $this->layoutProbe = $probe;
        try {
            $this->renderCore();
            return true;
        } catch (\IvoPdf\Exception\LimitExceededException $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->layoutProbe = null;
            [$this->html, $this->pageCount, $this->layoutTree, $this->mpdfMem, $this->mpdfNextMem, $this->mpdfState, $this->footnoteMem, $this->footnoteState, $warnings, MpdfRenderHooks::$markerPages] = $saved;
            $this->options->restoreWarnings($warnings);
        }
    }

    /** Document-wide conditions of the shortcut. */
    private function mpdfPnDocumentOk(): bool
    {
        if ($this->tocEnabled || $this->mpdfPageOps !== [] || $this->composeMergeOnly()) {
            return false;
        }
        // footnotes reserve room per page, a table of contents / an index grows with later content
        return preg_match('~footnote|<toc|<tocpagebreak|<tocentry|<indexinsert|<indexentry|<columns|<formfeed~i', $this->html) !== 1;
    }

    /** HTML of chunk $i. */
    private function mpdfPnChunk(int $i): string
    {
        $start = $this->mpdfChunkStarts[$i];
        $end = $this->mpdfChunkStarts[$i + 1] ?? strlen($this->html);
        return substr($this->html, $start, max(0, $end - $start));
    }

    /**
     * 'empty' (whitespace / comments), 'block' (well-formed elements only, the first one an ordinary element that can
     * carry the anchor attribute), 'plain' (well-formed elements, the first one void such as <img> or <pagebreak />) or
     * 'bad'.
     */
    private function mpdfPnChunkKind(string $chunk): string
    {
        static $void = ['area' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1, 'param' => 1,
            'pagebreak' => 1, 'bookmark' => 1, 'annotation' => 1];
        static $refused = ['html' => 1, 'head' => 1, 'body' => 1, 'style' => 1, 'link' => 1, 'meta' => 1, 'base' => 1, 'title' => 1, 'frameset' => 1, 'template' => 1];
        if (strlen($chunk) > 2_000_000) {
            return 'bad';
        }
        $s = (string) preg_replace('~<!--.*?-->~s', '', $chunk);
        if (trim($s) === '') {
            return 'empty';
        }
        if (preg_match_all('~<(/?)([a-zA-Z][a-zA-Z0-9:_-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|<!\[CDATA\[|<[!?]~', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return 'bad';
        }
        $stack = [];
        $pos = 0;
        $first = true;
        $taggable = false;
        foreach ($m as $t) {
            $off = $t[0][1];
            $text = substr($s, $pos, $off - $pos);
            if ($stack === [] && trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '') {
                return 'bad'; // text directly in <body> joins the neighbouring lines
            }
            $pos = $off + strlen($t[0][0]);
            if (!isset($t[2]) || $t[2][0] === '') {
                return 'bad'; // CDATA, doctype, processing instruction
            }
            $name = strtolower($t[2][0]);
            $closing = $t[1][0] === '/';
            $attrs = $t[3][0];
            if (isset($refused[$name]) || $name === 'script') {
                return 'bad';
            }
            if (in_array($name, ['pagebreak', 'bookmark', 'annotation'], true) || str_contains($name, ':')) {
                // only a bare mPDF <pagebreak />; <bookmark> / <annotation> are markers
                if ($name === 'pagebreak' && trim(rtrim(trim($attrs), '/')) !== '') {
                    return 'bad';
                }
                if (str_contains($name, ':')) {
                    return 'bad';
                }
            } elseif (preg_match('~^(?:tocpagebreak|toc|tocentry|indexentry|indexinsert|columns|columnbreak|column_break|newcolumn|htmlpage(?:header|footer)|sethtmlpage(?:header|footer)|watermark(?:text|image)|formfeed|footnote|dottab|textcircle)$~', $name) === 1) {
                return 'bad';
            }
            if ($first) {
                if ($closing) {
                    return 'bad';
                }
                $first = false;
                $taggable = !isset($void[$name]);
            }
            if ($closing) {
                if ($stack === [] || array_pop($stack) !== $name) {
                    return 'bad';
                }
                continue;
            }
            if (isset($void[$name])) {
                continue;
            }
            $stack[] = $name;
            if (count($stack) > 500) {
                return 'bad';
            }
        }
        if (trim(substr($s, $pos)) !== '' || $stack !== []) {
            return 'bad';
        }
        return $taggable ? 'block' : 'plain';
    }

    /**
     * Adds data-ivo-pn="<chunk>" to the first element of the given chunks of $html ($offset: position of $html in
     * $this->html).
     *
     * @param list<int> $chunks
     */
    private function mpdfPnTag(string $html, int $offset, array $chunks): string
    {
        rsort($chunks);
        foreach ($chunks as $i) {
            $at = $this->mpdfChunkStarts[$i] - $offset;
            if ($at < 0 || $at > strlen($html)) {
                continue;
            }
            if (preg_match('~\G(?:\s|<!--.*?-->)*<[a-zA-Z][a-zA-Z0-9_-]*~s', $html, $mm, 0, $at) === 1) {
                $insertAt = $at + strlen($mm[0]);
                $html = substr($html, 0, $insertAt) . ' data-ivo-pn="' . $i . '"' . substr($html, $insertAt);
            }
        }
        return $html;
    }

    /**
     * Document start and end for a tail: the <html> / <body> start tags, the stylesheets (<style>, <link>), <meta>,
     * <base> and <title> of the earlier HTML.
     *
     * @return array{0: string, 1: string}
     */
    private function mpdfPnHead(string $prefix): array
    {
        $p = (string) preg_replace('~<!--.*?-->|<script\b.*?</script\s*>~is', '', $prefix);
        $htmlTag = preg_match('~<html\b[^>]*>~i', $p, $m) === 1 ? $m[0] : '<html>';
        $bodyTag = preg_match('~<body\b[^>]*>~i', $p, $m) === 1 ? $m[0] : '<body>';
        $head = '';
        if (preg_match_all('~<style\b.*?</style\s*>|<link\b[^>]*>|<meta\b[^>]*>|<base\b[^>]*>|<title\b.*?</title\s*>~is', $p, $mm) > 0) {
            $head = implode('', $mm[0]);
        }
        return [$htmlTag . '<head>' . $head . '</head>' . $bodyTag, '</body></html>'];
    }

    private static function mpdfPnBody(LayoutBox $root): ?LayoutBox
    {
        if ($root->tagName === 'body') {
            return $root;
        }
        foreach ($root->children as $c) {
            if ($c->tagName === 'body') {
                return $c;
            }
        }
        return null;
    }

    private static function mpdfPnTagged(LayoutBox $body, string $id): ?LayoutBox
    {
        foreach ($body->children as $c) {
            if ($c->domElement !== null && $c->domElement->getAttribute('data-ivo-pn') === $id) {
                return $c;
            }
        }
        return null;
    }

    /** Body children from $from on are plain blocks without positioned boxes, columns or CSS counters. */
    private function mpdfPnTailOk(LayoutBox $body, LayoutBox $from): bool
    {
        if ($body->paddingTop > 0.0 || $body->borderTop > 0.0) {
            return false;
        }
        $on = false;
        foreach ($body->children as $c) {
            $on = $on || $c === $from;
            if (!$on) {
                continue;
            }
            if ($c->isTextNode() || $c->isInlineLevel() || !self::mpdfPnSubtreeOk($c)) {
                return false;
            }
        }
        return $on;
    }

    private static function mpdfPnSubtreeOk(LayoutBox $box): bool
    {
        if ($box->isTextNode()) {
            return true;
        }
        $st = $box->style;
        if ($box->isPositionedOutOfFlow() || $box->columnFlow !== null
            || strtolower(trim($st->get('counter-increment', 'none'))) !== 'none' || strtolower(trim($st->get('counter-reset', 'none'))) !== 'none'
            || strtolower(trim($st->get('counter-set', 'none'))) !== 'none' || strtolower(trim($st->get('string-set', 'none'))) !== 'none') {
            return false;
        }
        foreach ($box->children as $child) {
            if (!self::mpdfPnSubtreeOk($child)) {
                return false;
            }
        }
        return true;
    }

    /** Lowest bottom edge of the floats in a subtree (-INF when none). */
    private static function mpdfPnFloatBottom(LayoutBox $box): float
    {
        $b = -INF;
        if (!$box->isTextNode()) {
            $f = strtolower(trim($box->style->get('float', 'none')));
            if ($f !== 'none' && $f !== '') {
                $b = $box->y + $box->height + max(0.0, $box->marginBottom);
            }
            foreach ($box->children as $child) {
                $b = max($b, self::mpdfPnFloatBottom($child));
            }
        }
        return $b;
    }

    /**
     * The latest tagged chunk that can be an anchor: its first box is a plain block child of <body> that later content
     * cannot move (not a last heading / break-after: avoid box), nothing before it reaches below its top, and the
     * content from it on is plain block content. $pageBase: page of the full document that page 0 of this layout is.
     *
     * @return ?array{chunk: int, page: int, y: float}
     */
    private function mpdfPnAnchor(LayoutBox $root, Paginator $pg, PageLayoutPlan $plan, int $pageBase): ?array
    {
        if (!$plan->isUniform() || $plan->names() !== []) {
            return null;
        }
        $body = self::mpdfPnBody($root);
        if ($body === null) {
            return null;
        }
        $kids = [];
        foreach ($body->children as $c) {
            if (!$c->isPositionedOutOfFlow() && !($c->isTextNode() && trim((string) $c->textContent) === '')) {
                $kids[] = $c;
            }
        }
        $last = count($kids) - 1;
        // scanning backwards: is everything from index $i on plain content?
        $tailOk = true;
        for ($i = $last; $i >= 0; $i--) {
            $c = $kids[$i];
            $tailOk = $tailOk && !$c->isTextNode() && !$c->isInlineLevel() && self::mpdfPnSubtreeOk($c);
            if (!$tailOk) {
                return null;
            }
            $id = $c->domElement?->getAttribute('data-ivo-pn') ?? '';
            if ($id === '' || !ctype_digit($id)) {
                continue;
            }
            if ($i === $last && (preg_match('/^h[1-6]$/', $c->tagName) === 1 || strtolower(trim($c->style->get('page-break-after', 'auto'))) === 'avoid')) {
                continue; // later content may still pull it to the next page
            }
            $float = strtolower(trim($c->style->get('float', 'none')));
            if ($float !== 'none' && $float !== '') {
                continue; // a float is not where the flow is
            }
            $floor = -INF;
            for ($k = 0; $k < $i; $k++) {
                $floor = max($floor, self::mpdfPnFloatBottom($kids[$k]));
            }
            if ($floor > $c->y + 0.01) {
                continue;
            }
            $page = $pg->pageOfY($c->y);
            [$top] = $pg->getPageBounds($page);
            $off = $c->y - $top;
            if ($off < -0.01) {
                continue;
            }
            return ['chunk' => (int) $id, 'page' => $pageBase + $page, 'y' => max(0.0, $off)];
        }
        return null;
    }
}
