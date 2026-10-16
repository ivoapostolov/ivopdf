<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use IvoPdf\Layout\LayoutBox;

/**
 * CSS Paged Media running elements: `position: running(name)` removes an element from the normal flow and
 * `content: element(name[, first|last|start|first-except])` in a page margin box shows a rendered copy of it.
 *
 * Implementation: before layout, every running element is cloned (still unlaid-out, with its computed styles)
 * and replaced in the flow by a zero-height marker box carrying `string-set: __run_<name> "<index>"`, so the
 * existing StringSets machinery tells, per page, which running element applies (first / last / start /
 * first-except semantics). When a margin box is built, `element(name)` becomes a placeholder that inject()
 * replaces by a fresh deep clone of the element, which is then laid out inside the margin box.
 *
 * Limitations: the copy is laid out with the styles computed at its original position (so it keeps its colours,
 * fonts and borders, but selectors depending on the margin box context do not apply); `counter(page)` inside the
 * running element is not evaluated; an inline-level running element leaves a block-level marker in the flow.
 */
final class RunningElements
{
    public const PREFIX = '__run_';
    public const ATTR = 'data-ivo-running';

    /** @var list<LayoutBox> */
    private array $pristine = [];

    /** Removes running elements from the tree; returns null when the document has none. */
    public static function extract(LayoutBox $root): ?self
    {
        $self = new self();
        $self->walk($root);
        return $self->pristine === [] ? null : $self;
    }

    /** HTML placeholder for `element(name, mode)` on a page ('' when no such element applies). */
    public function placeholder(string $name, int $pageIndex, ?StringSets $strings, string $mode = 'first'): string
    {
        if ($strings === null) {
            return '';
        }
        $idx = $strings->get(self::PREFIX . strtolower($name), $pageIndex, $mode);
        if ($idx === '' || !isset($this->pristine[(int) $idx])) {
            return '';
        }
        return '<div ' . self::ATTR . '="' . (int) $idx . '"></div>';
    }

    /** Replaces placeholder boxes of a freshly built margin box tree by copies of the running elements. */
    public function inject(LayoutBox $root): void
    {
        foreach ($root->children as $i => $child) {
            if (!$child->isTextNode() && $child->domElement !== null && $child->domElement->hasAttribute(self::ATTR)) {
                $idx = (int) $child->domElement->getAttribute(self::ATTR);
                if (isset($this->pristine[$idx])) {
                    $root->children[$i] = self::deepClone($this->pristine[$idx], $root);
                }
                continue;
            }
            $this->inject($child);
        }
    }

    private function walk(LayoutBox $box): void
    {
        foreach ($box->children as $i => $child) {
            if ($child->isTextNode()) {
                continue;
            }
            $pos = trim($child->style->get('position', 'static'));
            if (preg_match('/^running\(\s*([\w-]+)\s*\)$/i', $pos, $m) === 1) {
                $idx = count($this->pristine);
                $copy = self::deepClone($child, null);
                $copy->style->set('position', 'static');
                $this->pristine[] = $copy;

                $marker = new LayoutBox('div', $box->style->inheritForElement('div'));
                foreach (['height' => '0', 'margin' => '0', 'padding' => '0', 'border-width' => '0'] as $p => $v) {
                    $marker->style->set($p, $v);
                }
                $marker->style->set('string-set', self::PREFIX . strtolower($m[1]) . ' "' . $idx . '"');
                $marker->parent = $box;
                $box->children[$i] = $marker;
                continue;
            }
            $this->walk($child);
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
