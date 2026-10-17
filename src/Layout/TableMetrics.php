<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Table helpers: intrinsic (shrink-to-fit) widths and `table-layout: fixed` column widths.
 */
final class TableMetrics
{
    /**
     * True for `table-layout: fixed` with a definite table width (otherwise browsers fall back to auto layout).
     */
    public static function isFixed(LayoutBox $table): bool
    {
        if (strtolower(trim($table->style->get('table-layout', 'auto'))) !== 'fixed') {
            return false;
        }
        $w = strtolower(trim($table->style->get('width', 'auto')));
        return $w !== 'auto' && $w !== '';
    }

    /**
     * Column widths for fixed layout: <col> widths first, then first-row cell widths; the rest is split equally.
     *
     * @param list<CellPlacement> $cellPlacements
     * @param array<int, float> $colElementWidths
     * @return list<float>
     */
    public static function fixedColumnWidths(LayoutEngine $engine, array $cellPlacements, int $numCols, float $usableWidth, array $colElementWidths): array
    {
        $w = array_fill(0, $numCols, null);
        foreach ($colElementWidths as $c => $v) {
            if ($v > 0.0 && $c < $numCols) {
                $w[$c] = $v;
            }
        }
        foreach ($cellPlacements as $cp) {
            if ($cp->rowIdx !== 0) {
                continue;
            }
            $cell = $cp->cell;
            $engine->resolveBoxMetrics($cell, $usableWidth);
            $css = strtolower(trim($cell->style->get('width', 'auto')));
            if ($css === 'auto' || $css === '') {
                continue;
            }
            $px = $engine->resolveBoxHorizontalDimension($cell, $css, $usableWidth);
            if ($px <= 0.0) {
                continue;
            }
            $span = max(1, $cp->colSpan);
            for ($k = 0; $k < $span; $k++) {
                $c = $cp->colIdx + $k;
                if ($c < $numCols && $w[$c] === null) {
                    $w[$c] = $px / $span;
                }
            }
        }
        $auto = [];
        $sum = 0.0;
        for ($c = 0; $c < $numCols; $c++) {
            if ($w[$c] === null) {
                $auto[] = $c;
            } else {
                $sum += $w[$c];
            }
        }
        $out = [];
        if ($auto !== []) {
            $each = max(0.0, $usableWidth - $sum) / count($auto);
            for ($c = 0; $c < $numCols; $c++) {
                $out[$c] = $w[$c] ?? $each;
            }
            return $out;
        }
        $scale = ($sum > 0.01 && $sum < $usableWidth) ? $usableWidth / $sum : 1.0;
        for ($c = 0; $c < $numCols; $c++) {
            $out[$c] = $w[$c] * $scale;
        }
        return $out;
    }

    /**
     * Reads widths from <colgroup>/<col> elements (width attribute or style).
     *
     * @return array<int, float>
     */
    public static function columnElementWidths(LayoutEngine $engine, LayoutBox $table, int $numCols, float $usableWidth): array
    {
        $widths = [];
        $dom = $table->domElement;
        if ($dom === null) {
            return $widths;
        }
        $col = 0;
        foreach ($dom->getElementsByTagName('col') as $colEl) {
            if (!$colEl instanceof \DOMElement || $col >= $numCols) {
                break;
            }
            $span = max(1, min(100, (int) ($colEl->getAttribute('span') ?: 1)));
            $w = trim($colEl->getAttribute('width'));
            if (preg_match('/(?:^|;)\s*width\s*:\s*([^;]+)/i', $colEl->getAttribute('style'), $sm)) {
                $w = trim($sm[1]);
            }
            for ($k = 0; $k < $span && $col < $numCols; $k++, $col++) {
                if ($w !== '' && $w !== 'auto') {
                    $widths[$col] = $engine->resolveLength(is_numeric($w) ? $w . 'px' : $w, $usableWidth, $table->fontSizePt);
                }
            }
        }
        return $widths;
    }

    /**
     * Intrinsic min / max border-box width of a table: the sum of its column widths (content based).
     *
     * @return array{min: float, max: float}
     */
    public static function intrinsicWidth(LayoutEngine $engine, LayoutBox $table, float $referenceWidth): array
    {
        $rows = [];
        foreach ($table->children as $child) {
            $d = $child->getDisplay();
            if ($child->tagName === 'tr' || $d === 'table-row') {
                $rows[] = $child;
            } elseif (in_array($child->tagName, ['thead', 'tbody', 'tfoot'], true) || in_array($d, ['table-row-group', 'table-header-group', 'table-footer-group'], true)) {
                foreach ($child->children as $r) {
                    if ($r->tagName === 'tr' || $r->getDisplay() === 'table-row') {
                        $rows[] = $r;
                    }
                }
            }
        }
        $occupied = [];
        $colMin = [];
        $colMax = [];
        $spans = [];
        foreach ($rows as $ri => $row) {
            $col = 0;
            foreach ($row->children as $cell) {
                if (!in_array($cell->tagName, ['td', 'th'], true) && $cell->getDisplay() !== 'table-cell') {
                    continue;
                }
                while (!empty($occupied[$ri][$col])) {
                    $col++;
                }
                $cs = max(1, min(100, $cell->colspan));
                $rs = max(1, min(500, $cell->rowspan));
                for ($r = $ri; $r < $ri + $rs; $r++) {
                    for ($c = $col; $c < $col + $cs; $c++) {
                        $occupied[$r][$c] = true;
                    }
                }
                $i = $engine->measureIntrinsicWidth($cell, $referenceWidth);
                if ($cs === 1) {
                    $colMin[$col] = max($colMin[$col] ?? 0.0, $i['min']);
                    $colMax[$col] = max($colMax[$col] ?? 0.0, $i['max']);
                } else {
                    $spans[] = [$col, $cs, $i['min'], $i['max']];
                }
                $col += $cs;
            }
        }
        foreach ($spans as [$col, $cs, $mn, $mx]) {
            $curMin = 0.0;
            $curMax = 0.0;
            for ($c = $col; $c < $col + $cs; $c++) {
                $curMin += $colMin[$c] ?? 0.0;
                $curMax += $colMax[$c] ?? 0.0;
            }
            for ($c = $col; $c < $col + $cs; $c++) {
                if ($mn > $curMin) {
                    $colMin[$c] = ($colMin[$c] ?? 0.0) + ($mn - $curMin) / $cs;
                }
                if ($mx > $curMax) {
                    $colMax[$c] = ($colMax[$c] ?? 0.0) + ($mx - $curMax) / $cs;
                }
            }
        }
        // <col width> fixes a column's width (percentages are ignored here: they depend on the table width)
        foreach (self::columnElementWidths($engine, $table, 100, 0.0) as $c => $cw) {
            if ($cw > 0.0 && isset($colMax[$c])) {
                $colMin[$c] = max($colMin[$c] ?? 0.0, $cw);
                $colMax[$c] = max($colMin[$c], $cw);
            }
        }
        $sumMin = array_sum($colMin);
        $sumMax = array_sum($colMax);
        $n = count($colMax);
        $sp = 0.0;
        if (strtolower($table->style->get('border-collapse', 'collapse')) !== 'collapse' && $n > 0) {
            $sp = $engine->resolveLength($table->style->get('border-spacing', '0'), $referenceWidth, $table->fontSizePt) * ($n + 1);
        }
        $edge = $table->getHorizontalSpacing();
        $min = $sumMin + $edge + $sp;
        $max = max($sumMin, $sumMax) + $edge + $sp;
        // A caption never breaks inside a word: its min-content width widens the table (as in browsers)
        foreach ($table->children as $child) {
            if ($child->tagName === 'caption' || $child->getDisplay() === 'table-caption') {
                $engine->resolveBoxMetrics($child, $referenceWidth);
                $capMin = $engine->measureIntrinsicWidth($child, $referenceWidth)['min'] + $child->marginLeft + $child->marginRight; // the caption is outside the border box
                $min = max($min, $capMin);
                $max = max($max, $capMin);
            }
        }
        return ['min' => $min, 'max' => $max];
    }
}
