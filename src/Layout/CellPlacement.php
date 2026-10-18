<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Grid position of one table cell (a plain object: a table of 50 000 cells keeps one of these per cell for the
 * duration of its layout, and an object is a fraction of the size of the equivalent associative array).
 *
 * @internal
 */
final class CellPlacement
{
    public function __construct(
        public LayoutBox $cell,
        public int $rowIdx,
        public int $colIdx,
        public int $rowSpan,
        public int $colSpan,
        public float $cellWidth = 0.0,
    ) {
    }

    /**
     * Rows spanned by a cell starting in row $rowIdx: its rowspan (1..500), or for rowspan="0" every remaining row of
     * its row group (HTML: the cell extends to the end of the thead / tbody / tfoot it is in).
     *
     * @param list<int> $groupOfRow a group key per row index (rows of one row group share the key, in row order)
     */
    public static function rowSpanOf(LayoutBox $cell, int $rowIdx, array $groupOfRow): int
    {
        $rs = $cell->rowspan;
        if ($rs !== 0) {
            return max(1, min(500, $rs));
        }
        $key = $groupOfRow[$rowIdx] ?? null;
        $n = 1;
        for ($r = $rowIdx + 1, $count = count($groupOfRow); $r < $count && $groupOfRow[$r] === $key && $n < 500; $r++) {
            $n++;
        }
        return $n;
    }
}
