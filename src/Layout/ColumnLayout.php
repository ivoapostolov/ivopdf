<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * CSS Multi-column Layout (`column-count`, `column-width`, `columns`, `column-gap`, `column-rule`, `column-fill`,
 * `column-span: all`, `break-inside: avoid`, `break-before/after: column`).
 *
 * The in-flow content is first laid out in a single column of the final column width. It is then cut into
 * unbreakable pieces (line boxes and atomic blocks) that {@see ColumnFlow} distributes over the columns.
 * The Paginator re-runs the distribution once the container's final page position is known.
 */
class ColumnLayout
{
    private LayoutEngine $engine;

    public function __construct(LayoutEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * True when the box declares columns (column-count and/or column-width) and can host them.
     */
    public function isMulticol(LayoutBox $box): bool
    {
        if ($box->isTextNode() || !in_array($box->getDisplay(), ['block', 'list-item', 'flow-root'], true)) {
            return false;
        }
        $count = strtolower(trim($box->style->get('column-count', 'auto')));
        $width = strtolower(trim($box->style->get('column-width', 'auto')));
        return ($count !== 'auto' && $count !== '') || ($width !== 'auto' && $width !== '');
    }

    /**
     * Resolves the used column count, column width and gap for a container content width.
     *
     * @return array{0: int, 1: float, 2: float} [count, columnWidth, gap]
     */
    public function resolveGeometry(LayoutBox $box, float $contentWidth): array
    {
        $gapCss = strtolower(trim($box->style->get('column-gap', 'normal')));
        $gap = ($gapCss === 'normal' || $gapCss === '')
            ? $box->fontSizePt
            : max(0.0, $this->engine->resolveLength($gapCss, $contentWidth, $box->fontSizePt));

        $countCss = strtolower(trim($box->style->get('column-count', 'auto')));
        $widthCss = strtolower(trim($box->style->get('column-width', 'auto')));
        $count = ctype_digit($countCss) ? max(1, (int) $countCss) : 0;
        $width = ($widthCss !== 'auto' && $widthCss !== '')
            ? max(1.0, $this->engine->resolveLength($widthCss, $contentWidth, $box->fontSizePt))
            : 0.0;

        if ($width > 0.0) {
            $fit = max(1, (int) floor(($contentWidth + $gap) / ($width + $gap)));
            $n = $count > 0 ? min($count, $fit) : $fit;
        } else {
            $n = max(1, $count);
        }
        $colWidth = max(1.0, ($contentWidth - ($n - 1) * $gap) / $n);
        return [$n, $colWidth, $gap];
    }

    /**
     * Lays out the multicol container's children and returns the content height, or null when the container
     * resolves to a single column (the caller then uses the regular block layout).
     */
    public function layout(LayoutBox $box): ?float
    {
        $contentWidth = $box->getContentWidth();
        [$n, $colWidth, $gap] = $this->resolveGeometry($box, $contentWidth);
        if ($n <= 1) {
            return null;
        }

        $geom = $this->engine->getPageGeometry();
        $limit = $geom !== null ? $geom[1] * 0.9 : INF;

        // Split the in-flow children into column segments and `column-span: all` spanners
        $groups = [];
        $current = [];
        foreach ($box->children as $child) {
            if ($this->isSpanner($child)) {
                if ($current !== []) {
                    $groups[] = ['span' => false, 'children' => $current];
                    $current = [];
                }
                $groups[] = ['span' => true, 'children' => [$child]];
            } else {
                $current[] = $child;
            }
        }
        if ($current !== []) {
            $groups[] = ['span' => false, 'children' => $current];
        }

        $origChildren = $box->children;
        $origY = $box->y;
        $origWidth = $box->width;
        $hSpace = $box->getHorizontalSpacing();
        $segments = [];
        $allLines = [];
        $cursor = $box->getContentY();

        foreach ($groups as $g) {
            $box->children = $g['children'];
            $box->lines = [];
            $box->y = $cursor - $box->borderTop - $box->paddingTop;
            $box->width = $g['span'] ? $origWidth : $colWidth + $hSpace;
            $segHeight = $this->engine->layoutBlockFlow($box);
            $segLines = $box->lines;
            $boxes = [];
            $pieces = $this->collectItems($segLines, $g['children'], !empty($segLines), $cursor, $limit, $boxes);
            if ($g['span']) {
                // A spanner is always one atomic unit, whatever its size
                $c = $g['children'][0];
                $pieces = [new ColumnPiece(null, $c, $c->y - $cursor, $c->height)];
                $boxes = [];
            }
            $maxBottom = 0.0;
            foreach ($pieces as $p) {
                $maxBottom = max($maxBottom, $p->ot + $p->h);
            }
            $segments[] = [
                'span' => $g['span'],
                'pieces' => $pieces,
                'height' => $segHeight,
                'tail' => max(0.0, $segHeight - $maxBottom),
                'boxes' => $boxes,
            ];
            foreach ($segLines as $l) {
                $allLines[] = $l;
            }
            $cursor += $segHeight;
        }

        $box->children = $origChildren;
        $box->y = $origY;
        $box->width = $origWidth;
        $box->lines = $allLines;

        $fill = strtolower(trim($box->style->get('column-fill', 'balance')));
        $hCss = strtolower(trim($box->style->get('height', 'auto')));
        $fixed = ($hCss !== 'auto' && $hCss !== '' && !str_ends_with($hCss, '%'))
            ? $this->engine->getExplicitContentHeight($box, $box->width)
            : null;

        $flow = new ColumnFlow($n, $colWidth, $gap, $fill !== 'auto', $fixed, $segments);
        $flow->place($box);
        $box->columnFlow = $flow;
        return $flow->contentHeight;
    }

    /**
     * Adjusts the intrinsic widths of a multicol container.
     *
     * @param array{min: float, max: float} $intr Intrinsic widths of the content (excluding own padding/border)
     * @return array{min: float, max: float}
     */
    public function adjustIntrinsic(LayoutBox $box, array $intr, float $referenceWidth): array
    {
        $countCss = strtolower(trim($box->style->get('column-count', 'auto')));
        $widthCss = strtolower(trim($box->style->get('column-width', 'auto')));
        $count = ctype_digit($countCss) ? max(1, (int) $countCss) : 0;
        $colW = ($widthCss !== 'auto' && $widthCss !== '')
            ? max(1.0, $this->engine->resolveLength($widthCss, $referenceWidth, $box->fontSizePt))
            : 0.0;
        $gapCss = strtolower(trim($box->style->get('column-gap', 'normal')));
        $gap = ($gapCss === 'normal' || $gapCss === '')
            ? $box->fontSizePt
            : max(0.0, $this->engine->resolveLength($gapCss, $referenceWidth, $box->fontSizePt));

        $n = max(1, $count);
        $minCol = max($intr['min'], $colW);
        return [
            'min' => $minCol,
            'max' => max($minCol, $n * max($intr['max'], $colW) + ($n - 1) * $gap),
        ];
    }

    private function isSpanner(LayoutBox $child): bool
    {
        return !$child->isTextNode()
            && !$child->isPositionedOutOfFlow()
            && !$child->isInlineLevel()
            && strtolower(trim($child->style->get('column-span', 'none'))) === 'all'
            && strtolower(trim($child->style->get('float', 'none'))) === 'none';
    }

    /**
     * Builds the ordered piece list for a set of lines and child boxes.
     *
     * @param list<LineBox> $lines
     * @param list<LayoutBox> $children
     * @param list<array{box: LayoutBox, pieces: list<ColumnPiece>, dx: float}> $boxes Collects descended boxes
     * @return list<ColumnPiece>
     */
    private function collectItems(array $lines, array $children, bool $hasLines, float $origin, float $limit, array &$boxes): array
    {
        /** @var list<array{0: float, 1: list<ColumnPiece>}> $items */
        $items = [];
        foreach ($lines as $line) {
            $items[] = [$line->y, [new ColumnPiece($line, null, $line->y - $origin, $line->height)]];
        }
        foreach ($children as $child) {
            if ($child->isTextNode() || $child->isPositionedOutOfFlow()) {
                continue;
            }
            if ($child->isInlineLevel() && $hasLines) {
                continue;
            }
            $group = $this->collectBox($child, $origin, $limit, $boxes);
            if ($group !== []) {
                $items[] = [$group[0]->top(), $group];
            }
        }
        usort($items, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($items as [, $grp]) {
            foreach ($grp as $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * @param list<array{box: LayoutBox, pieces: list<ColumnPiece>, dx: float}> $boxes
     * @return list<ColumnPiece>
     */
    private function collectBox(LayoutBox $c, float $origin, float $limit, array &$boxes): array
    {
        if ($this->isAtomic($c) && $c->height <= $limit) {
            $pieces = [new ColumnPiece(null, $c, $c->y - $origin, $c->height)];
        } else {
            $pieces = $this->collectItems($c->lines, $c->children, !empty($c->lines), $origin, $limit, $boxes);
            if ($pieces === []) {
                if ($c->height > 0.5 && empty($c->children) && empty($c->lines)) {
                    $pieces = [new ColumnPiece(null, $c, $c->y - $origin, $c->height)];
                } else {
                    return [];
                }
            } else {
                $boxes[] = ['box' => $c, 'pieces' => $pieces, 'dx' => 0.0];
            }
        }

        $first = $pieces[0];
        $last = $pieces[count($pieces) - 1];
        if (strtolower(trim($c->style->get('page-break-before', 'auto'))) === 'column') {
            $first->forceBefore = true;
        }
        if (strtolower(trim($c->style->get('page-break-after', 'auto'))) === 'column') {
            $last->forceAfter = true;
        }
        if (strtolower(trim($c->style->get('page-break-after', 'auto'))) === 'avoid' || preg_match('/^h[1-6]$/', $c->tagName) === 1) {
            $last->keepNext = true;
        }
        return $pieces;
    }

    /**
     * Boxes that must not be split between columns.
     */
    private function isAtomic(LayoutBox $c): bool
    {
        if (in_array($c->tagName, ['img', 'svg', 'hr', 'table', 'tr'], true)) {
            return true;
        }
        if (strtolower(trim($c->style->get('page-break-inside', 'auto'))) === 'avoid') {
            return true;
        }
        if (strtolower(trim($c->style->get('float', 'none'))) !== 'none') {
            return true;
        }
        if (in_array($c->getDisplay(), ['flex', 'inline-flex', 'grid', 'inline-grid', 'table', 'inline-block', 'table-row'], true)) {
            return true;
        }
        // Decorated boxes (background, border, shadow) keep their frame in one piece
        if ($c->borderTop + $c->borderRight + $c->borderBottom + $c->borderLeft > 0.01) {
            return true;
        }
        $bg = strtolower(trim($c->style->get('background-color', 'transparent')));
        if ($bg !== '' && $bg !== 'transparent' && $bg !== 'none') {
            return true;
        }
        $bgi = strtolower(trim($c->style->get('background-image', 'none')));
        if ($bgi !== '' && $bgi !== 'none') {
            return true;
        }
        $shadow = strtolower(trim($c->style->get('box-shadow', 'none')));
        return $shadow !== '' && $shadow !== 'none';
    }
}
