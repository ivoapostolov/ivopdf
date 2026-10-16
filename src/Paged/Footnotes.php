<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LayoutEngine;

/**
 * CSS GCPM footnotes: `float: footnote` removes an element from the flow, leaves a footnote call (a superscript
 * number) in its place and shows the element in a footnote area at the bottom of the page that holds the call.
 *
 * Implementation (multi-pass, like the mPDF index / NEXT-ODD handling in IvoPdf::render()):
 *  1. extract(): every `float: footnote` element is replaced by a call box and kept as a pristine copy
 *  2. after pagination the page of every call is read from its text run (afterPaginate()); IvoPdf::render() lays the
 *     document out again when this differs from the pages assumed by the previous pass
 *  3. the page layout plan reserves the height of the notes of a page at its bottom (reserve()) and an overlay layer
 *     paints the notes there (layer())
 *
 * Styling: `::footnote-call { ... }` and `::footnote-marker { ... }` rules (declarations apply to every call / marker,
 * the selector is ignored; `content` may mix strings and `counter(footnote)`), numbering is continuous unless an
 * `@page { counter-reset: footnote }` rule restarts it on every page.
 *
 * Limitations: the note area is a plain block below a short rule (no ::footnote-area styling, no footnote-display,
 * no footnote-policy); a call inside a table cell / multicolumn container is placed on the page of its line; notes
 * that do not fit the 40 % of the page reserved for them are clipped by the page.
 */
final class Footnotes
{
    public const STYLE_KEY = 'ivo-fncall';

    /** @var list<LayoutBox> pristine note boxes in document order */
    private array $notes = [];
    /** @var array<int, int> note index => 0-based page of its call (this pass, valid after afterPaginate) */
    private array $actual = [];

    /**
     * @param array<int, int>                                           $assumed note index => page from the previous pass
     * @param array{call: array<string, string>, marker: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} $css
     */
    private function __construct(private array $assumed, private array $css)
    {
    }

    /**
     * Replaces the footnote elements of the tree; returns null when the document has none.
     *
     * @param array<int, int> $assumed
     * @param array{call: array<string, string>, marker: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} $css
     */
    public static function extract(LayoutBox $root, array $assumed, array $css): ?self
    {
        $self = new self($assumed, $css);
        $self->walk($root);
        return $self->notes === [] ? null : $self;
    }

    /** Reads and removes the `::footnote-call` / `::footnote-marker` rules and the page-wise reset from the document CSS. */
    public static function scanCss(string $html): array
    {
        $css = ['call' => [], 'marker' => [], 'callContent' => null, 'markerContent' => null, 'perPage' => false];
        if (stripos($html, 'footnote') === false) {
            return [$html, $css];
        }
        $html = (string) preg_replace_callback(
            '~[^{}]*::footnote-(call|marker)\s*\{([^{}]*)\}~i',
            static function (array $m) use (&$css): string {
                $kind = strtolower($m[1]);
                foreach (explode(';', $m[2]) as $decl) {
                    if (!str_contains($decl, ':')) {
                        continue;
                    }
                    [$p, $v] = explode(':', $decl, 2);
                    $p = strtolower(trim($p));
                    $v = trim($v);
                    if ($p === 'content') {
                        $css[$kind . 'Content'] = $v;
                    } elseif ($p !== '' && $v !== '') {
                        $css[$kind][$p] = $v;
                    }
                }
                return '';
            },
            $html
        );
        if (preg_match('~@page[^{]*\{[^}]*counter-reset\s*:[^;}]*\bfootnote\b~i', $html) === 1) {
            $css['perPage'] = true;
        }
        return [$html, $css];
    }

    public function count(): int
    {
        return count($this->notes);
    }

    /**
     * Reads the page of every call from the paginated tree.
     *
     * @param \Closure(float): int $pageOfY
     * @return array<int, int> note index => page
     */
    public function afterPaginate(LayoutBox $root, \Closure $pageOfY): array
    {
        $this->actual = [];
        $this->scan($root, $pageOfY);
        ksort($this->actual);
        return $this->actual;
    }

    /** Height (pt) the notes assumed for a page need at its bottom, 0 when none. */
    public function reserve(int $pageIndex, LayoutEngine $engine, float $width): float
    {
        $indices = $this->notesOnPage($this->assumed, $pageIndex);
        if ($indices === []) {
            return 0.0;
        }
        $area = $this->buildArea($indices, $this->assumed);
        $engine->layoutDocument($area, 0.0, 0.0, $width);
        return $area->height + 6.0;
    }

    /**
     * Footnote area of a page (laid out, bottom edge at $bottomY), or null when the page has no notes.
     */
    public function layer(int $pageIndex, LayoutEngine $engine, float $x, float $width, float $bottomY): ?LayoutBox
    {
        $indices = $this->notesOnPage($this->actual, $pageIndex);
        if ($indices === []) {
            return null;
        }
        $area = $this->buildArea($indices, $this->actual);
        $engine->layoutDocument($area, $x, 0.0, $width);
        $area->translate(0.0, $bottomY - $area->height - $area->y);
        return $area;
    }

    // ------------------------------------------------------------------

    /** @param array<int, int> $pages @return list<int> */
    private function notesOnPage(array $pages, int $pageIndex): array
    {
        $out = [];
        foreach ($pages as $i => $p) {
            if ($p === $pageIndex) {
                $out[] = $i;
            }
        }
        return $out;
    }

    /** Display number of note $i (continuous, or restarting on every page with `@page { counter-reset: footnote }`). */
    private function number(int $i, array $pages): int
    {
        if (!$this->css['perPage'] || !isset($pages[$i])) {
            return $i + 1;
        }
        $n = 1;
        for ($k = 0; $k < $i; $k++) {
            if (($pages[$k] ?? -1) === $pages[$i]) {
                $n++;
            }
        }
        return $n;
    }

    /** @param list<int> $indices @param array<int, int> $pages */
    private function buildArea(array $indices, array $pages): LayoutBox
    {
        $first = $this->notes[$indices[0]];
        $area = new LayoutBox('body', ($first->parent?->style ?? $first->style)->inheritForElement('div'));
        $area->style->set('margin', '0');
        $area->style->set('padding', '0');

        $rule = new LayoutBox('div', $area->style->inheritForElement('div'));
        foreach (['width' => '33%', 'height' => '0', 'border-top' => '0.5pt solid #777777', 'margin' => '0 0 3pt 0', 'padding' => '0'] as $p => $v) {
            $rule->style->set($p, $v);
        }
        $area->addChild($rule);

        foreach ($indices as $i) {
            $note = self::deepClone($this->notes[$i], $area);
            $note->style->set('float', 'none');
            if ($note->isInlineLevel()) {
                $note->style->set('display', 'block');
            }
            $this->insertMarker($note, $this->marker($this->number($i, $pages)));
            $area->addChild($note);
        }
        return $area;
    }

    private function marker(int $n): string
    {
        return $this->content($this->css['markerContent'], $n, $n . '. ');
    }

    private function callText(int $n): string
    {
        return $this->content($this->css['callContent'], $n, (string) $n);
    }

    /** Evaluates `content` made of strings and counter(footnote); falls back to $default. */
    private function content(?string $value, int $n, string $default): string
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }
        $out = '';
        if (preg_match_all('~"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|counter\(\s*footnote[^)]*\)~i', $value, $mm, PREG_SET_ORDER) === 0) {
            return $default;
        }
        foreach ($mm as $m) {
            if (stripos($m[0], 'counter(') === 0) {
                $out .= (string) $n;
            } else {
                $out .= stripslashes(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            }
        }
        return $out;
    }

    private function insertMarker(LayoutBox $note, string $text): void
    {
        $target = $note;
        while (true) {
            $first = null;
            foreach ($target->children as $c) {
                if (!$c->isTextNode() || trim((string) $c->textContent) !== '') {
                    $first = $c;
                    break;
                }
            }
            if ($first !== null && !$first->isTextNode() && !$first->isInlineLevel() && $first->children !== []) {
                $target = $first;
                continue;
            }
            break;
        }
        $span = new LayoutBox('span', $target->style->inheritForElement('span'));
        foreach ($this->css['marker'] as $p => $v) {
            $span->style->set($p, $v);
        }
        $t = new LayoutBox('#text', clone $span->style);
        $t->textContent = $text;
        $t->parent = $span;
        $span->children[] = $t;
        $span->parent = $target;
        array_unshift($target->children, $span);
    }

    private function walk(LayoutBox $box): void
    {
        foreach ($box->children as $i => $child) {
            if ($child->isTextNode()) {
                continue;
            }
            if (strtolower(trim($child->style->get('float', 'none'))) === 'footnote') {
                $idx = count($this->notes);
                $this->notes[] = self::deepClone($child, null);

                $call = new LayoutBox('sup', $box->style->inheritForElement('sup'));
                foreach ($this->css['call'] as $p => $v) {
                    $call->style->set($p, $v);
                }
                $text = new LayoutBox('#text', clone $call->style);
                $text->style->set(self::STYLE_KEY, (string) $idx);
                $text->textContent = $this->callText($this->number($idx, $this->assumed));
                $text->parent = $call;
                $call->children[] = $text;
                $call->parent = $box;
                $box->children[$i] = $call;
                continue;
            }
            $this->walk($child);
        }
    }

    /** @param \Closure(float): int $pageOfY */
    private function scan(LayoutBox $box, \Closure $pageOfY): void
    {
        foreach ($box->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->inlineBox !== null) {
                    $this->scan($run->inlineBox, $pageOfY);
                    continue;
                }
                $idx = $run->style->all()[self::STYLE_KEY] ?? null;
                if ($idx !== null && !isset($this->actual[(int) $idx])) {
                    $this->actual[(int) $idx] = $pageOfY($run->y);
                }
            }
        }
        foreach ($box->children as $child) {
            $this->scan($child, $pageOfY);
        }
    }

    private static function deepClone(LayoutBox $b, ?LayoutBox $parent): LayoutBox
    {
        $c = clone $b;
        $c->style = clone $b->style;
        if ($b->markerStyle !== null) {
            $c->markerStyle = clone $b->markerStyle;
        }
        $c->parent = $parent;
        $c->lines = [];
        $c->children = [];
        foreach ($b->children as $child) {
            $c->children[] = self::deepClone($child, $c);
        }
        return $c;
    }
}
