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
 * `::footnote-area { ... }` styles the area as a whole: margin, padding, border*, background, font*, color, line-height,
 * text-align. Declaring any `border*` replaces the built-in short rule above the notes. `max-height` (a percentage of
 * the page height or a length; default 40 %) is the most room a page gives its notes.
 *
 * On the note element: `footnote-display: block | inline | compact` (block = every note on its own line, inline = the
 * notes run together as one paragraph, compact = notes share a line while they fit and wrap as a unit otherwise) and
 * `footnote-policy: auto | line | block`. With `line` / `block` a call whose note does not fit the room of its page
 * moves (its line, or its whole paragraph) to the next page; with `auto` the page keeps the call and the notes that
 * do not fit are left out with a warning (they can never overlap the text).
 *
 * Stability: the notes of a page change where later text falls, so the document is laid out again until the pages of the
 * calls stop changing. When that has not happened after eight passes the room reserved for a page never shrinks again
 * (largest ever seen), which guarantees convergence in practice; a last guard in layer() drops notes that would still
 * not fit the reserved room instead of letting them run over the text.
 *
 * Limitations: a call inside a table cell / multicolumn container is placed on the page of its line; a note longer
 * than the whole room of a page is cut at the room (warning).
 */
final class Footnotes
{
    public const STYLE_KEY = 'ivo-fncall';

    /** @var list<LayoutBox> pristine note boxes in document order */
    private array $notes = [];
    /** @var array<int, \IvoPdf\Css\ComputedStyle> note index => style of the element the note was taken from */
    private array $origin = [];
    /** @var array<int, int> note index => 0-based page of its call (this pass, valid after afterPaginate) */
    private array $actual = [];

    /**
     * @param array<int, int>                                           $assumed note index => page from the previous pass
     * @param array{call: array<string, string>, marker: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} $css
     */
    /** @var array{push: array<int, bool>, floor: array<int, float>, ratchet: bool, freeze: bool, changed: bool, last: bool, memo: array<string, float>} state kept across passes */
    private array $state;
    private ?LayoutEngine $engine = null;
    private float $areaWidth = 0.0;
    private float $pageHeight = 842.0;
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<int, float> page => room reserved for its notes in this pass */
    private array $reservedAt = [];

    /**
     * @param array<int, int> $assumed
     * @param array{call: array<string, string>, marker: array<string, string>, area: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} $css
     * @param array{push: array<int, bool>, floor: array<int, float>, ratchet: bool, freeze: bool, changed: bool, last: bool, memo: array<string, float>} $state
     */
    private function __construct(private array $assumed, private array $css, array &$state)
    {
        $this->state = &$state;
    }

    /**
     * Replaces the footnote elements of the tree; returns null when the document has none.
     *
     * @param array<int, int> $assumed
     * @param array{call: array<string, string>, marker: array<string, string>, area: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} $css
     * @param array{push: array<int, bool>, floor: array<int, float>, ratchet: bool, freeze: bool, changed: bool, last: bool, memo: array<string, float>} $state
     */
    public static function extract(LayoutBox $root, array $assumed, array $css, array &$state): ?self
    {
        $self = new self($assumed, $css, $state);
        $self->walk($root);
        return $self->notes === [] ? null : $self;
    }

    /** Reads and removes the `::footnote-call` / `::footnote-marker` rules and the page-wise reset from the document CSS. */
    public static function scanCss(string $html): array
    {
        $css = ['call' => [], 'marker' => [], 'area' => [], 'callContent' => null, 'markerContent' => null, 'perPage' => false];
        if (stripos($html, 'footnote') === false) {
            return [$html, $css];
        }
        $html = (string) preg_replace_callback(
            '~[^{}]*::footnote-(call|marker|area)\s*\{([^{}]*)\}~i',
            static function (array $m) use (&$css): string {
                $kind = strtolower($m[1]);
                foreach (explode(';', $m[2]) as $decl) {
                    if (!str_contains($decl, ':')) {
                        continue;
                    }
                    [$p, $v] = explode(':', $decl, 2);
                    $p = strtolower(trim($p));
                    $v = trim($v);
                    if ($p === 'content' && $kind !== 'area') {
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
        $this->review();
        return $this->actual;
    }

    /**
     * Height (pt) the notes assumed for a page need at its bottom (capped by the area's max-height), 0 when none.
     */
    public function reserve(int $pageIndex, LayoutEngine $engine, float $width, float $pageHeight): float
    {
        $this->engine = $engine;
        $this->areaWidth = $width;
        $this->pageHeight = $pageHeight;
        $indices = $this->notesOnPage($this->assumed, $pageIndex);
        $need = $indices === [] ? 0.0 : $this->measure($indices, $this->assumed);
        if ($this->state['ratchet']) {
            $need = $this->ratchet($pageIndex, $need);
        }
        return $this->reservedAt[$pageIndex] = min($this->cap(), $need);
    }

    /**
     * Footnote area of a page (laid out, bottom edge at $bottomY), or null when the page has no notes. Notes that do not
     * fit the $reserved room are left out (with a warning) so they cannot run over the text of the page.
     */
    public function layer(int $pageIndex, LayoutEngine $engine, float $x, float $width, float $bottomY, float $reserved = INF): ?LayoutBox
    {
        $indices = $this->notesOnPage($this->actual, $pageIndex);
        if ($indices === []) {
            return null;
        }
        $dropped = 0;
        $area = $this->buildArea($indices, $this->actual);
        $engine->layoutDocument($area, $x, 0.0, $width);
        // Only a page whose notes are the ones it reserved room for (or the last pass of an unsettled document) is guarded;
        // in any other pass the document is laid out again anyway
        $guard = $this->state['last'] || $this->notesOnPage($this->assumed, $pageIndex) === $indices;
        if ($guard && count($indices) > 1 && $this->outerHeight($area) + 6.0 > $reserved + 0.5) {
            // Largest prefix of the notes that fits the room (at least one note): a galloping search lays the area out
            // O(log n) times instead of once per dropped note (thousands of notes on a page would be quadratic)
            $areas = [];
            $best = max(1, $this->fittingPrefix(count($indices), function (int $n) use ($indices, $engine, $x, $width, $reserved, &$areas): bool {
                $probe = $this->buildArea(array_slice($indices, 0, $n), $this->actual);
                $engine->layoutDocument($probe, $x, 0.0, $width);
                if ($this->outerHeight($probe) + 6.0 > $reserved + 0.5) {
                    return false;
                }
                $areas = [$n => $probe]; // keep only the largest fitting area so far
                return true;
            }, true, $this->guessFit($indices, $this->actual, $reserved + 0.5)));
            $dropped = count($indices) - $best;
            $indices = array_slice($indices, 0, $best);
            $area = $areas[$best] ?? null;
            if ($area === null) {
                $area = $this->buildArea($indices, $this->actual);
                $engine->layoutDocument($area, $x, 0.0, $width);
            }
        }
        $cut = $guard && $this->outerHeight($area) + 6.0 > $reserved + 0.5;
        if ($cut) {
            // one note taller than the room of the page: clip it to the room instead of letting it run over the text
            $area = $this->buildArea($indices, $this->actual);
            $area->style->set('box-sizing', 'border-box');
            $area->style->set('overflow', 'hidden');
            $area->style->set('height', sprintf('%.2Fpt', max(10.0, $reserved - 6.0 - $area->marginTop - $area->marginBottom)));
            $engine->layoutDocument($area, $x, 0.0, $width);
        }
        if ($guard && ($dropped > 0 || $cut)) {
            $this->warnings[] = 'Footnotes of page ' . ($pageIndex + 1) . ' need more room than the ' . round($reserved) . ' pt reserved for them'
                . ($dropped > 0 ? "; {$dropped} note(s) were left out" : '; the last note is cut')
                . ' (raise ::footnote-area { max-height }, shorten the notes or use footnote-policy: line | block).';
        }
        $area->translate(0.0, $bottomY - $area->marginBottom - $area->height - $area->y);
        return $area;
    }

    /** @return list<string> problems found while building the areas (taken once) */
    public function takeWarnings(): array
    {
        $w = array_values(array_unique($this->warnings));
        $this->warnings = [];
        return $w;
    }

    /** Most room (pt) a page gives its notes: `::footnote-area { max-height }` (% of the page or a length), default 40 %. */
    private function cap(): float
    {
        $v = trim($this->css['area']['max-height'] ?? '');
        $cap = $this->pageHeight * 0.4;
        if (preg_match('~^([\d.]+)\s*(%|pt|px|mm|cm|in|em)?$~i', $v, $m) === 1) {
            $n = (float) $m[1];
            $cap = match (strtolower($m[2] ?? 'pt')) {
                '%' => $this->pageHeight * $n / 100.0,
                'px' => $n * 0.75,
                'mm' => $n * 72.0 / 25.4,
                'cm' => $n * 72.0 / 2.54,
                'in' => $n * 72.0,
                'em' => $n * 12.0,
                default => $n,
            };
        }
        return max(20.0, min($this->pageHeight * 0.9, $cap));
    }

    /** Height of the area including its vertical margins. */
    private function outerHeight(LayoutBox $area): float
    {
        return $area->height + $area->marginTop + $area->marginBottom;
    }

    /**
     * Outer height + the gap to the text (pt) of the notes with these indices.
     *
     * @param list<int> $indices
     * @param array<int, int> $pages
     */
    private function measure(array $indices, array $pages): float
    {
        // the same note sets are measured again in every pass: remember the result (the notes never change between passes)
        $key = (string) round($this->areaWidth, 2) . '|' . implode(',', $indices);
        if ($this->css['perPage']) {
            foreach ($indices as $i) {
                $key .= ':' . $this->number($i, $pages);
            }
        }
        if (isset($this->state['memo'][$key])) {
            return $this->state['memo'][$key];
        }
        $area = $this->buildArea($indices, $pages);
        $this->engine?->layoutDocument($area, 0.0, 0.0, $this->areaWidth);
        return $this->state['memo'][$key] = $this->outerHeight($area) + 6.0;
    }

    /** From the ninth pass on a page never reserves less than the most it has needed (guarantees convergence). */
    private function ratchet(int $pageIndex, float $need): float
    {
        $old = $this->state['floor'][$pageIndex] ?? 0.0;
        if ($need > $old + 0.01) {
            $this->state['floor'][$pageIndex] = $need;
            $this->state['changed'] = true;
            return $need;
        }
        return $old;
    }

    /**
     * Applies `footnote-policy: line | block` before pagination: the line (or the first line of the paragraph) of every
     * call whose note did not fit the room of its page is made to start a page.
     */
    public function prepare(LayoutBox $root): void
    {
        if ($this->state['push'] !== []) {
            $this->markPushed($root);
        }
    }

    private function markPushed(LayoutBox $box): void
    {
        foreach ($box->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->inlineBox !== null) {
                    $this->markPushed($run->inlineBox);
                    continue;
                }
                $idx = $run->style->has(self::STYLE_KEY) ? (int) $run->style->get(self::STYLE_KEY) : null;
                if ($idx !== null && isset($this->state['push'][$idx])) {
                    $target = $this->policy($idx) === 'block' ? ($box->lines[0] ?? $line) : $line;
                    $target->forceBreak = true;
                }
            }
        }
        foreach ($box->children as $child) {
            $this->markPushed($child);
        }
    }

    /** `block` moves the whole paragraph of the call; `line` and `auto` (nothing else is possible) its line. */
    private function policy(int $i): string
    {
        $v = strtolower(trim($this->notes[$i]->style->get('footnote-policy', 'auto')));
        return $v === 'block' ? 'block' : 'line';
    }

    /**
     * After pagination: finds the notes that do not fit the room of their page and asks the policy `line` / `block`
     * ones to move to the next page (next pass); records the room really needed in the ratchet.
     */
    private function review(): void
    {
        if ($this->engine === null) {
            return;
        }
        // A push stays only while it is still justified: its note does not fit next to the notes before it on the page of
        // the previous note. (Frozen in late passes so the document converges.)
        $this->state['futile'] ??= [];
        if (!$this->state['freeze']) {
            foreach (array_keys($this->state['push']) as $k) {
                $prev = $this->actual[$k - 1] ?? null;
                if ($prev === null) {
                    continue;
                }
                if ($prev === ($this->actual[$k] ?? null)) {
                    // The page break before the line of this call did not separate it from the previous call (both sit
                    // on one line): pushing it again would only make every later pass undo and redo the same push
                    unset($this->state['push'][$k]);
                    $this->state['futile'][$k] = true;
                    $this->state['changed'] = true;
                    continue;
                }
                $before = [];
                foreach ($this->notesOnPage($this->actual, $prev) as $i) {
                    if ($i < $k) {
                        $before[] = $i;
                    }
                }
                if ($this->measure(array_merge($before, [$k]), $this->actual) <= $this->cap() + 0.5) {
                    unset($this->state['push'][$k]);
                    $this->state['changed'] = true;
                }
            }
        }
        $byPage = [];
        foreach ($this->actual as $i => $p) {
            $byPage[$p][] = $i;
        }
        ksort($byPage);
        $cap = $this->cap() + 0.5;
        // The notes that move off an overfull page land on the next one together with that page's own notes: the cascade
        // is followed here in one go (a later pass checks every push again), so a long run of overfull pages settles in a
        // few passes instead of one page per pass
        $carry = [];
        $page = array_key_first($byPage) ?? 0;
        $lastPage = array_key_last($byPage) ?? -1;
        while ($page <= $lastPage || $carry !== []) {
            \IvoPdf\RenderLimits::tick();
            $own = $byPage[$page] ?? [];
            if ($own !== [] && $this->state['ratchet']) {
                $this->ratchet($page, $this->measure($own, $this->actual));
            }
            $page++;
            $indices = $carry === [] ? $own : array_merge($carry, $own);
            $carry = [];
            if (count($indices) < 2) {
                continue;
            }
            // Only the first note that does not fit moves: everything after it follows it to the next page anyway
            $fit = $this->fittingPrefix(count($indices), fn(int $n): bool => $this->measure(array_slice($indices, 0, $n), $this->actual) <= $cap, false, $this->guessFit($indices, $this->actual, $cap));
            if ($fit >= count($indices)) {
                continue; // all notes fit
            }
            if ($fit < 1) {
                continue; // even the first note alone is taller than the room: nothing to gain by moving it
            }
            $first = $indices[$fit]; // the first note that no longer fits
            if (isset($this->state['futile'][$first])) {
                continue; // its call cannot leave the line of the previous one: the notes stay (layer() drops what does not fit)
            }
            if (!isset($this->state['push'][$first])) {
                $this->state['push'][$first] = true;
                $this->state['changed'] = true;
            }
            $carry = array_slice($indices, $fit);
        }
    }

    /**
     * Longest prefix length k in [0, $n] for which $fits(k) holds ($fits is monotone, k = 0 always fits). Gallops
     * 1, 2, 4, ... then bisects, so the probes cost about twice the size of the answer (not of $n): pages hold tens of
     * notes while thousands may be waiting for room.
     *
     * With a $guess (an estimate of the answer) the gallop starts there, so a good estimate costs two probes.
     *
     * @param \Closure(int): bool $fits
     */
    private function fittingPrefix(int $n, \Closure $fits, bool $fullDoesNotFit = false, int $guess = 0): int
    {
        $good = 0;
        $bad = $fullDoesNotFit ? $n : $n + 1;
        if ($guess >= 1 && $guess < $bad) {
            \IvoPdf\RenderLimits::tick();
            if ($fits($guess)) {
                // gallop upwards: guess+1, guess+2, guess+4, ...
                $good = $guess;
                $step = 1;
                while ($good + $step < $bad) {
                    \IvoPdf\RenderLimits::tick();
                    if (!$fits($good + $step)) {
                        $bad = $good + $step;
                        break;
                    }
                    $good += $step;
                    $step *= 2;
                }
            } else {
                // gallop downwards: guess-1, guess-2, guess-4, ...
                $bad = $guess;
                $step = 1;
                while ($bad - $step > $good) {
                    \IvoPdf\RenderLimits::tick();
                    if ($fits($bad - $step)) {
                        $good = $bad - $step;
                        break;
                    }
                    $bad -= $step;
                    $step *= 2;
                }
            }
        } else {
            $k = 1;
            while ($k < $bad) {
                \IvoPdf\RenderLimits::tick();
                if (!$fits($k)) {
                    $bad = $k;
                    break;
                }
                $good = $k;
                $k = $k * 2 < $bad ? $k * 2 : ($k + 1 < $bad ? $bad - 1 : $bad);
            }
        }
        while ($bad - $good > 1) {
            \IvoPdf\RenderLimits::tick();
            $mid = intdiv($good + $bad, 2);
            if ($fits($mid)) {
                $good = $mid;
            } else {
                $bad = $mid;
            }
        }
        return $good;
    }

    /**
     * Estimated number of leading notes of $indices that fit $limit, from the heights of the notes measured one by one
     * (remembered across passes) and the fixed cost of the area (rule, gap): a starting point for fittingPrefix().
     *
     * @param list<int> $indices
     * @param array<int, int> $pages
     */
    private function guessFit(array $indices, array $pages, float $limit): int
    {
        if (count($indices) < 2) {
            return 0;
        }
        $h0 = $this->measure([$indices[0]], $pages);
        $overhead = max(0.0, $h0 + $this->measure([$indices[1]], $pages) - $this->measure([$indices[0], $indices[1]], $pages));
        $total = $h0;
        if ($total > $limit) {
            return 0;
        }
        $n = count($indices);
        for ($k = 1; $k < $n; $k++) {
            \IvoPdf\RenderLimits::tick();
            $total += $this->measure([$indices[$k]], $pages) - $overhead;
            if ($total > $limit) {
                return $k;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------

    /** @param array<int, int> $pages @return list<int> */
    private function notesOnPage(array $pages, int $pageIndex): array
    {
        // Grouped once per page map: scanning every note for every page would cost notes x pages per pass
        foreach ($this->pageCache as [$forPages, $byPage]) {
            if ($forPages === $pages) {
                return $byPage[$pageIndex] ?? [];
            }
        }
        $byPage = [];
        foreach ($pages as $i => $p) {
            $byPage[$p][] = $i;
        }
        if (count($this->pageCache) >= 4) {
            array_shift($this->pageCache);
        }
        $this->pageCache[] = [$pages, $byPage];
        return $byPage[$pageIndex] ?? [];
    }

    /** @var list<array{0: array<int, int>, 1: array<int, list<int>>}> page maps and the notes of each page */
    private array $pageCache = [];

    /** Display number of note $i (continuous, or restarting on every page with `@page { counter-reset: footnote }`). */
    private function number(int $i, array $pages): int
    {
        if (!$this->css['perPage'] || !isset($pages[$i])) {
            return $i + 1;
        }
        // Rank of every note on its page, computed once per page map (counting per call would be quadratic)
        $ranks = null;
        foreach ($this->rankCache as [$forPages, $cached]) {
            if ($forPages === $pages) {
                $ranks = $cached;
                break;
            }
        }
        if ($ranks === null) {
            $ranks = [];
            $seen = [];
            $sorted = $pages;
            ksort($sorted);
            foreach ($sorted as $k => $p) {
                $ranks[$k] = $seen[$p] = ($seen[$p] ?? 0) + 1;
            }
            if (count($this->rankCache) >= 4) {
                array_shift($this->rankCache);
            }
            $this->rankCache[] = [$pages, $ranks];
        }
        return $ranks[$i] ?? 1;
    }

    /** @var list<array{0: array<int, int>, 1: array<int, int>}> page maps and the per-page rank of each note */
    private array $rankCache = [];

    /**
     * @param list<int> $indices
     * @param array<int, int> $pages
     */
    private function buildArea(array $indices, array $pages): LayoutBox
    {
        $first = $this->notes[$indices[0]];
        $area = new LayoutBox('body', ($first->parent?->style ?? $first->style)->inheritForElement('div'));
        $area->style->set('margin', '0');
        $area->style->set('padding', '0');
        $hasBorder = false;
        foreach ($this->css['area'] as $p => $v) {
            if ($p === 'max-height') {
                continue;
            }
            if (str_starts_with($p, 'border')) {
                $hasBorder = true;
            }
            $area->style->set($p, $v);
        }

        if (!$hasBorder) {
            $rule = new LayoutBox('div', $area->style->inheritForElement('div'));
            foreach (['width' => '33%', 'height' => '0', 'border-top' => '0.5pt solid #777777', 'margin' => '0 0 3pt 0', 'padding' => '0'] as $p => $v) {
                $rule->style->set($p, $v);
            }
            $area->addChild($rule);
        }

        foreach ($indices as $i) {
            $note = self::deepClone($this->notes[$i], $area);
            $note->style->set('float', 'none');
            $display = strtolower(trim($this->notes[$i]->style->get('footnote-display', 'block')));
            if ($display === 'inline') {
                $note->style->set('display', 'inline');
            } elseif ($display === 'compact') {
                $note->style->set('display', 'inline-block');
            } elseif ($note->isInlineLevel()) {
                $note->style->set('display', 'block');
            }
            $this->inheritFromArea($note, $this->origin[$i]);
            $this->insertMarker($note, $this->marker($this->number($i, $pages)));
            $area->addChild($note);
            if ($display === 'inline' || $display === 'compact') {
                $gap = new LayoutBox('#text', clone $area->style);
                $gap->textContent = ' ';
                $area->addChild($gap);
            }
        }
        return $area;
    }

    /** Inherited properties */
    private const INHERITED = ['color', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'line-height', 'text-align', 'text-indent', 'letter-spacing', 'word-spacing', 'text-transform', 'direction'];

    /**
     * Properties declared on `::footnote-area` are inherited by the notes: wherever a note (or something inside it) still
     * carries the value it inherited from the place it was written, the area's value replaces it. A value the note's own
     * rules set (e.g. `.fn { font-size: 9pt }`) stays.
     */
    private function inheritFromArea(LayoutBox $b, \IvoPdf\Css\ComputedStyle $origin): void
    {
        foreach (self::INHERITED as $p) {
            if (isset($this->css['area'][$p]) && $b->style->get($p, '') === $origin->get($p, '')) {
                $b->style->set($p, $this->css['area'][$p]);
            }
        }
        foreach ($b->children as $c) {
            $this->inheritFromArea($c, $origin);
        }
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
                $this->origin[$idx] = $box->style;

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
                $idx = $run->style->has(self::STYLE_KEY) ? $run->style->get(self::STYLE_KEY) : null;
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
