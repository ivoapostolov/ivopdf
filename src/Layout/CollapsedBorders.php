<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Css\CssUnit;

/**
 * Border conflict resolution of the collapsing border model (CSS 2.1 17.6.2.1): on every edge of the grid the
 * borders of the cells, rows and row groups meeting there and, on the outer edges, of the table compete, and the
 * winner is drawn once, centered on the grid line. Each cell gets the winning border of each of its sides, so its
 * content is inset by half of that border (not of its own one) and it paints the winner; the table paints no border
 * of its own (it took part in the resolution of the outer edges).
 *
 * @internal
 */
final class CollapsedBorders
{
    private const STYLE_RANK = ['double' => 8, 'solid' => 7, 'dashed' => 6, 'dotted' => 5, 'ridge' => 4, 'outset' => 3, 'groove' => 2, 'inset' => 1];
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /** @var \WeakMap<LayoutBox, ComputedStyle>|null the cells' own styles: a later layout pass resolves from them again */
    private static ?\WeakMap $own = null;

    /**
     * @param list<CellPlacement> $placements
     * @param array<int, LayoutBox> $rows row index => row box
     * @param array<int, ?LayoutBox> $groups row index => row group box (null for rows directly in the table)
     */
    public static function apply(LayoutEngine $engine, LayoutBox $table, array $placements, array $rows, array $groups, int $numRows, int $numCols, float $referenceWidth): void
    {
        self::$own ??= new \WeakMap();
        $grid = [];
        foreach ($placements as $i => $cp) {
            if (isset(self::$own[$cp->cell])) {
                $cp->cell->style = self::$own[$cp->cell];
            }
            for ($r = $cp->rowIdx; $r < $cp->rowIdx + $cp->rowSpan; $r++) {
                for ($c = $cp->colIdx; $c < $cp->colIdx + $cp->colSpan; $c++) {
                    $grid[$r][$c] ??= $i;
                }
            }
        }
        if (self::nothingToResolve($table, $placements, $rows, $groups)) {
            return; // every cell has the same borders and nothing else has one: each cell keeps its own
        }
        $cache = [];
        $border = static function (LayoutBox $box, string $side, int $origin, float $fs) use ($engine, &$cache): array {
            $key = spl_object_id($box) . $side;
            if (isset($cache[$key])) {
                return $cache[$key];
            }
            $style = $box->style;
            $bs = strtolower(trim($style->get("border-{$side}-style", 'none')));
            $width = $bs === 'none' || $bs === 'hidden' || $bs === ''
                ? 0.0
                : max(0.0, CssUnit::clampLength($engine->resolveLength($style->get("border-{$side}-width", '0'), 0.0, $fs)));
            $color = trim($style->get("border-{$side}-color", 'currentcolor'));
            if (strtolower($color) === 'currentcolor') {
                $color = $style->get('color', '#000000');
            }
            return $cache[$key] = ['w' => $width, 's' => $bs === '' ? 'none' : $bs, 'c' => $color, 'o' => $origin];
        };
        $tableFs = $table->fontSizePt;

        $cells = [];
        foreach ($placements as $i => $cp) {
            $engine->resolveBoxMetrics($cp->cell, $referenceWidth);
            $cells[$i] = $cp->cell;
        }
        $cellBorder = static fn(int $i, string $side): array => $border($cells[$i], $side, 5, $cells[$i]->fontSizePt);
        foreach ($cells as $i => $cell) {
            foreach (self::SIDES as $side) {
                $cellBorder($i, $side); // every own border is read before any cell takes a winner
            }
        }
        $groupStarts = static fn(int $r): bool => $r === 0 || ($groups[$r] ?? null) === null || ($groups[$r] ?? null) !== ($groups[$r - 1] ?? null);
        $groupEnds = static fn(int $r): bool => $r >= $numRows - 1 || ($groups[$r] ?? null) === null || ($groups[$r] ?? null) !== ($groups[$r + 1] ?? null);

        foreach ($placements as $i => $cp) {
            $r0 = $cp->rowIdx;
            $r1 = min($numRows, $cp->rowIdx + $cp->rowSpan) - 1;
            $c0 = $cp->colIdx;
            $c1 = min($numCols, $cp->colIdx + $cp->colSpan) - 1;
            $winners = [];

            // top: the cell above (it is further up: it wins ties), the rows and groups meeting there, the table
            $best = null;
            for ($c = $c0; $c <= $c1; $c++) {
                $cand = [];
                if ($r0 > 0 && isset($grid[$r0 - 1][$c]) && $grid[$r0 - 1][$c] !== $i) {
                    $cand[] = $cellBorder($grid[$r0 - 1][$c], 'bottom');
                }
                $cand[] = $cellBorder($i, 'top');
                if ($r0 > 0 && isset($rows[$r0 - 1])) {
                    $cand[] = $border($rows[$r0 - 1], 'bottom', 4, $tableFs);
                }
                if (isset($rows[$r0])) {
                    $cand[] = $border($rows[$r0], 'top', 4, $tableFs);
                }
                if ($groupStarts($r0)) {
                    if ($r0 > 0 && ($groups[$r0 - 1] ?? null) !== null) {
                        $cand[] = $border($groups[$r0 - 1], 'bottom', 3, $tableFs);
                    }
                    if (($groups[$r0] ?? null) !== null) {
                        $cand[] = $border($groups[$r0], 'top', 3, $tableFs);
                    }
                }
                if ($r0 === 0) {
                    $cand[] = $border($table, 'top', 0, $tableFs);
                }
                $best = self::stronger($best, self::resolve($cand));
            }
            $winners['top'] = $best;

            // bottom
            $best = null;
            for ($c = $c0; $c <= $c1; $c++) {
                $cand = [$cellBorder($i, 'bottom')];
                if (isset($grid[$r1 + 1][$c]) && $grid[$r1 + 1][$c] !== $i) {
                    $cand[] = $cellBorder($grid[$r1 + 1][$c], 'top');
                }
                if (isset($rows[$r1])) {
                    $cand[] = $border($rows[$r1], 'bottom', 4, $tableFs);
                }
                if (isset($rows[$r1 + 1])) {
                    $cand[] = $border($rows[$r1 + 1], 'top', 4, $tableFs);
                }
                if ($groupEnds($r1)) {
                    if (($groups[$r1] ?? null) !== null) {
                        $cand[] = $border($groups[$r1], 'bottom', 3, $tableFs);
                    }
                    if ($r1 + 1 < $numRows && ($groups[$r1 + 1] ?? null) !== null) {
                        $cand[] = $border($groups[$r1 + 1], 'top', 3, $tableFs);
                    }
                }
                if ($r1 >= $numRows - 1) {
                    $cand[] = $border($table, 'bottom', 0, $tableFs);
                }
                $best = self::stronger($best, self::resolve($cand));
            }
            $winners['bottom'] = $best;

            // left: the cell to the left wins ties; the first column also meets the rows, groups and the table
            $best = null;
            for ($r = $r0; $r <= $r1; $r++) {
                $cand = [];
                if ($c0 > 0 && isset($grid[$r][$c0 - 1]) && $grid[$r][$c0 - 1] !== $i) {
                    $cand[] = $cellBorder($grid[$r][$c0 - 1], 'right');
                }
                $cand[] = $cellBorder($i, 'left');
                if ($c0 === 0) {
                    if (isset($rows[$r])) {
                        $cand[] = $border($rows[$r], 'left', 4, $tableFs);
                    }
                    if (($groups[$r] ?? null) !== null) {
                        $cand[] = $border($groups[$r], 'left', 3, $tableFs);
                    }
                    $cand[] = $border($table, 'left', 0, $tableFs);
                }
                $best = self::stronger($best, self::resolve($cand));
            }
            $winners['left'] = $best;

            // right
            $best = null;
            for ($r = $r0; $r <= $r1; $r++) {
                $cand = [$cellBorder($i, 'right')];
                if (isset($grid[$r][$c1 + 1]) && $grid[$r][$c1 + 1] !== $i) {
                    $cand[] = $cellBorder($grid[$r][$c1 + 1], 'left');
                }
                if ($c1 >= $numCols - 1) {
                    if (isset($rows[$r])) {
                        $cand[] = $border($rows[$r], 'right', 4, $tableFs);
                    }
                    if (($groups[$r] ?? null) !== null) {
                        $cand[] = $border($groups[$r], 'right', 3, $tableFs);
                    }
                    $cand[] = $border($table, 'right', 0, $tableFs);
                }
                $best = self::stronger($best, self::resolve($cand));
            }
            $winners['right'] = $best;

            $cell = $cp->cell;
            $style = null;
            foreach (self::SIDES as $side) {
                $own = $cellBorder($i, $side);
                $win = $winners[$side];
                if ($win === null || ($win['w'] === $own['w'] && $win['s'] === $own['s'] && $win['c'] === $own['c'])) {
                    continue;
                }
                if ($style === null) {
                    self::$own[$cell] ??= $cell->style;
                    $style = clone $cell->style;
                }
                $style->set("border-{$side}-style", $win['s']);
                $style->set("border-{$side}-width", sprintf('%.4Fpt', $win['w']));
                $style->set("border-{$side}-color", $win['c']);
            }
            if ($style !== null) {
                $cell->style = $style;
                $engine->resolveBoxMetrics($cell, $referenceWidth);
            }
        }
    }

    /** @var \WeakMap<ComputedStyle, string>|null border declarations of a style (cells of one table share styles) */
    private static ?\WeakMap $signatures = null;

    /**
     * Fast path of large uniform tables (an invoice of 200 cells with `td { border: 1px solid }`): when every cell
     * declares the same borders and the table, its rows and row groups none, every edge's winner is the cell's own
     * border.
     *
     * @param list<CellPlacement> $placements
     * @param array<int, LayoutBox> $rows
     * @param array<int, ?LayoutBox> $groups
     */
    private static function nothingToResolve(LayoutBox $table, array $placements, array $rows, array $groups): bool
    {
        self::$signatures ??= new \WeakMap();
        $sig = static function (ComputedStyle $style): string {
            if (!isset(self::$signatures[$style])) {
                $s = '';
                foreach (self::SIDES as $side) {
                    $bs = strtolower(trim($style->get("border-{$side}-style", 'none')));
                    $s .= $bs === '' || $bs === 'none' ? 'none|' : $bs . '/' . $style->get("border-{$side}-width", '') . '/' . $style->get("border-{$side}-color", '') . '/' . $style->get('color', '') . '|';
                }
                self::$signatures[$style] = $s;
            }
            return self::$signatures[$style];
        };
        $none = str_repeat('none|', 4);
        if ($sig($table->style) !== $none) {
            return false;
        }
        $seen = [];
        foreach ($rows as $r => $row) {
            $group = $groups[$r] ?? null;
            foreach ([$row, $group] as $b) {
                if ($b !== null && !isset($seen[spl_object_id($b)])) {
                    $seen[spl_object_id($b)] = true;
                    if ($sig($b->style) !== $none) {
                        return false;
                    }
                }
            }
        }
        $first = null;
        foreach ($placements as $cp) {
            $s = $sig($cp->cell->style);
            $first ??= $s;
            if ($s !== $first) {
                return false;
            }
        }
        return true;
    }

    /**
     * The winner among the borders meeting on one edge segment, earliest first on a complete tie.
     *
     * @param list<array{w: float, s: string, c: string, o: int}> $cand
     * @return array{w: float, s: string, c: string, o: int}
     */
    private static function resolve(array $cand): array
    {
        $win = $cand[0];
        foreach ($cand as $b) {
            if ($b['s'] === 'hidden') {
                return ['w' => 0.0, 's' => 'hidden', 'c' => $b['c'], 'o' => $b['o']]; // hidden suppresses every border here
            }
            if (self::beats($b, $win)) {
                $win = $b;
            }
        }
        return $win;
    }

    /**
     * The stronger of two winners of a cell side that spans several grid segments (a box has one border per side).
     *
     * @param array{w: float, s: string, c: string, o: int}|null $a
     * @param array{w: float, s: string, c: string, o: int} $b
     * @return array{w: float, s: string, c: string, o: int}
     */
    private static function stronger(?array $a, array $b): array
    {
        if ($a === null || $a['s'] === 'hidden') {
            return $b;
        }
        return $b['s'] !== 'hidden' && self::beats($b, $a) ? $b : $a;
    }

    /**
     * @param array{w: float, s: string, c: string, o: int} $b
     * @param array{w: float, s: string, c: string, o: int} $a
     */
    private static function beats(array $b, array $a): bool
    {
        $bNone = $b['s'] === 'none';
        $aNone = $a['s'] === 'none';
        if ($bNone !== $aNone) {
            return $aNone; // 'none' has the lowest priority
        }
        if (abs($b['w'] - $a['w']) > 0.0001) {
            return $b['w'] > $a['w'];
        }
        $rb = self::STYLE_RANK[$b['s']] ?? 0;
        $ra = self::STYLE_RANK[$a['s']] ?? 0;
        if ($rb !== $ra) {
            return $rb > $ra;
        }
        return $b['o'] > $a['o']; // cell > row > row group > table
    }
}
