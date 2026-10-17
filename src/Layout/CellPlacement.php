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
}
