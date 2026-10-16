<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * CSS Grid item placement: grid-template-areas parsing, named lines (explicit `[name]` and implicit
 * `area-start` / `area-end`), line/span resolution for grid-row/-column, and the auto-placement algorithm
 * (sparse and dense, row and column flow).
 *
 * Lines are 0-based here: line k is the start edge of track k; a placement is [startTrack|null, span].
 */
final class GridPlacement
{
    /**
     * Parses `grid-template-areas` into a rectangle per area name.
     *
     * @return array{rows: int, cols: int, areas: array<string, array{0: int, 1: int, 2: int, 3: int}>}|null
     *         areas map name => [rowStart, colStart, rowEnd, colEnd] (0-based lines, end exclusive); null when "none" or invalid
     */
    public static function parseAreas(string $css): ?array
    {
        $css = trim($css);
        if ($css === '' || strtolower($css) === 'none') {
            return null;
        }
        if (preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $css, $m, PREG_SET_ORDER) < 1) {
            return null;
        }
        // Everything outside the strings must be blank
        $rest = trim((string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $css));
        if ($rest !== '') {
            return null;
        }
        $grid = [];
        $cols = null;
        foreach ($m as $match) {
            $row = trim($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            $cells = preg_split('/\s+/', $row, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $expanded = [];
            foreach ($cells as $cell) {
                if (preg_match('/^\.+$/', $cell) === 1) {
                    $expanded[] = '.';
                } elseif (preg_match('/^[A-Za-z_\-\x{80}-\x{10FFFF}][\w\-\x{80}-\x{10FFFF}]*$/u', $cell) === 1) {
                    $expanded[] = $cell;
                } else {
                    return null; // invalid token
                }
            }
            if ($expanded === [] || ($cols !== null && count($expanded) !== $cols)) {
                return null; // every row needs the same, non-zero number of cells
            }
            $cols = count($expanded);
            $grid[] = $expanded;
        }
        $areas = [];
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $name) {
                if ($name === '.') {
                    continue;
                }
                if (!isset($areas[$name])) {
                    $areas[$name] = [$r, $c, $r + 1, $c + 1];
                } else {
                    $a = &$areas[$name];
                    $a[0] = min($a[0], $r);
                    $a[1] = min($a[1], $c);
                    $a[2] = max($a[2], $r + 1);
                    $a[3] = max($a[3], $c + 1);
                    unset($a);
                }
            }
        }
        // Each named area must be a filled rectangle
        foreach ($areas as $name => [$r1, $c1, $r2, $c2]) {
            for ($r = $r1; $r < $r2; $r++) {
                for ($c = $c1; $c < $c2; $c++) {
                    if ($grid[$r][$c] !== $name) {
                        return null;
                    }
                }
            }
        }
        return ['rows' => count($grid), 'cols' => (int) $cols, 'areas' => $areas];
    }

    /**
     * Named lines of a track list ("[a b] 1fr [c] 2fr [d]"), after repeat() expansion, as name => sorted line indices.
     *
     * @param list<string> $tokens top-level tokens of the expanded template
     * @return array<string, list<int>>
     */
    public static function lineNamesFromTokens(array $tokens): array
    {
        $names = [];
        $track = 0;
        foreach ($tokens as $tok) {
            if (str_starts_with($tok, '[') && str_ends_with($tok, ']')) {
                foreach (preg_split('/\s+/', trim(substr($tok, 1, -1)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                    $names[$n][] = $track;
                }
            } else {
                $track++;
            }
        }
        return $names;
    }

    /**
     * Adds the implicit `name-start` / `name-end` lines of grid areas to a named-line map for one axis.
     *
     * @param array<string, list<int>> $names
     * @param array<string, array{0: int, 1: int, 2: int, 3: int}> $areas
     * @return array<string, list<int>>
     */
    public static function withAreaLines(array $names, array $areas, bool $columns): array
    {
        foreach ($areas as $name => [$r1, $c1, $r2, $c2]) {
            $names[$name . '-start'][] = $columns ? $c1 : $r1;
            $names[$name . '-end'][] = $columns ? $c2 : $r2;
        }
        foreach ($names as $n => $list) {
            sort($list);
            $names[$n] = array_values(array_unique($list));
        }
        return $names;
    }

    /**
     * Resolves a grid-{row,column}-start/-end pair to [startTrack|null, span].
     *
     * @param array<string, list<int>> $names named lines of the axis (including implicit area lines)
     * @return array{0: int|null, 1: int}
     */
    public static function resolveAxis(string $startCss, string $endCss, array $names, int $explicitTracks): array
    {
        $s = self::parseLine($startCss);
        $e = self::parseLine($endCss);
        // `grid-column: main` / `grid-area: main`: a lone identifier names both edges
        if ($e['kind'] === 'auto' && $s['kind'] === 'line' && $s['name'] !== null && $s['n'] === null) {
            $e = $s;
        }

        $startLine = $s['kind'] === 'line' ? self::lineIndex($s, $names, $explicitTracks, false) : null;
        $endLine = $e['kind'] === 'line' ? self::lineIndex($e, $names, $explicitTracks, true) : null;
        $spanS = $s['kind'] === 'span' ? self::spanCount($s, $names, $startLine ?? 0) : null;
        $spanE = $e['kind'] === 'span' ? self::spanCount($e, $names, $startLine ?? 0) : null;

        if ($startLine !== null && $endLine !== null) {
            if ($endLine < $startLine) {
                [$startLine, $endLine] = [$endLine, $startLine];
            }
            return [$startLine, max(1, $endLine - $startLine)];
        }
        if ($startLine !== null) {
            return [$startLine, $spanE ?? 1];
        }
        if ($endLine !== null) {
            $span = $spanS ?? 1;
            return [max(0, $endLine - $span), $span];
        }
        return [null, $spanS ?? $spanE ?? 1];
    }

    /**
     * @return array{kind: string, n: int|null, name: string|null}
     */
    private static function parseLine(string $css): array
    {
        $t = strtolower(trim($css));
        if ($t === '' || $t === 'auto') {
            return ['kind' => 'auto', 'n' => null, 'name' => null];
        }
        $kind = 'line';
        $n = null;
        $name = null;
        foreach (preg_split('/\s+/', trim($css)) ?: [] as $tok) {
            if (strtolower($tok) === 'span') {
                $kind = 'span';
            } elseif (preg_match('/^[+-]?\d+$/', $tok) === 1) {
                $n = (int) $tok;
            } elseif ($name === null) {
                $name = $tok;
            }
        }
        return ['kind' => $kind, 'n' => $n, 'name' => $name];
    }

    /**
     * 0-based line index of a line specifier, or null when it cannot be resolved.
     *
     * @param array{kind: string, n: int|null, name: string|null} $spec
     * @param array<string, list<int>> $names
     */
    private static function lineIndex(array $spec, array $names, int $explicitTracks, bool $isEnd): ?int
    {
        $n = $spec['n'];
        if ($spec['name'] === null) {
            if ($n === null || $n === 0) {
                return null;
            }
            return $n > 0 ? min(200, $n - 1) : max(0, $explicitTracks + 1 + $n);
        }
        $name = $spec['name'];
        $list = null;
        if ($n === null) {
            // an area edge wins over a plain named line
            $list = $names[$name . ($isEnd ? '-end' : '-start')] ?? null;
        }
        $list ??= $names[$name] ?? null;
        if ($list === null || $list === []) {
            return null;
        }
        $k = $n ?? 1;
        if ($k > 0) {
            return $list[min($k, count($list)) - 1];
        }
        return $list[max(0, count($list) + $k)];
    }

    /**
     * @param array{kind: string, n: int|null, name: string|null} $spec
     * @param array<string, list<int>> $names
     */
    private static function spanCount(array $spec, array $names, int $from): int
    {
        if ($spec['name'] !== null) {
            $count = max(1, $spec['n'] ?? 1);
            $seen = 0;
            foreach ($names[$spec['name']] ?? [] as $line) {
                if ($line > $from && ++$seen === $count) {
                    return $line - $from;
                }
            }
            return $count;
        }
        return max(1, min(100, $spec['n'] ?? 1));
    }

    /**
     * Auto-placement (CSS Grid 1, 8.5). Items are given in order-modified document order.
     *
     * @param list<array{rowStart: int|null, rowSpan: int, colStart: int|null, colSpan: int}> $items
     * @return array{items: list<array{r: int, c: int, rs: int, cs: int}>, rows: int, cols: int}
     */
    public static function place(array $items, int $explicitCols, int $explicitRows, string $flow, bool $dense): array
    {
        $column = $flow === 'column';
        if ($column) {
            // column flow is row flow on the transposed grid
            $items = array_map(static fn(array $i): array => [
                'rowStart' => $i['colStart'], 'rowSpan' => $i['colSpan'], 'colStart' => $i['rowStart'], 'colSpan' => $i['rowSpan'],
            ], $items);
            [$explicitCols, $explicitRows] = [$explicitRows, $explicitCols];
        }

        $numCols = max(1, $explicitCols);
        foreach ($items as $it) {
            $numCols = max($numCols, $it['colSpan'], ($it['colStart'] ?? 0) + $it['colSpan']);
        }
        $numCols = min($numCols, 500);

        $occ = [];
        $free = static function (int $r, int $c, int $rs, int $cs) use (&$occ): bool {
            for ($i = $r; $i < $r + $rs; $i++) {
                for ($j = $c; $j < $c + $cs; $j++) {
                    if (isset($occ[$i][$j])) {
                        return false;
                    }
                }
            }
            return true;
        };
        $mark = static function (int $r, int $c, int $rs, int $cs) use (&$occ): void {
            for ($i = $r; $i < $r + $rs; $i++) {
                for ($j = $c; $j < $c + $cs; $j++) {
                    $occ[$i][$j] = true;
                }
            }
        };

        $out = array_fill(0, count($items), null);
        // 1. items with a definite position on both axes
        foreach ($items as $k => $it) {
            if ($it['rowStart'] !== null && $it['colStart'] !== null) {
                $out[$k] = ['r' => $it['rowStart'], 'c' => $it['colStart'], 'rs' => $it['rowSpan'], 'cs' => $it['colSpan']];
                $mark($it['rowStart'], $it['colStart'], $it['rowSpan'], $it['colSpan']);
            }
        }
        // 2. items locked to a row
        $rowCursor = [];
        foreach ($items as $k => $it) {
            if ($out[$k] !== null || $it['rowStart'] === null) {
                continue;
            }
            $r = $it['rowStart'];
            $c = $dense ? 0 : ($rowCursor[$r] ?? 0);
            for ($guard = 0; !$free($r, $c, $it['rowSpan'], $it['colSpan']) && $guard < 1000; $guard++) {
                $c++;
            }
            $out[$k] = ['r' => $r, 'c' => $c, 'rs' => $it['rowSpan'], 'cs' => $it['colSpan']];
            $mark($r, $c, $it['rowSpan'], $it['colSpan']);
            $rowCursor[$r] = $c + $it['colSpan'];
            $numCols = max($numCols, $c + $it['colSpan']);
        }
        // 3. everything else
        $cr = 0;
        $cc = 0;
        foreach ($items as $k => $it) {
            if ($out[$k] !== null) {
                continue;
            }
            $rs = $it['rowSpan'];
            $cs = min($it['colSpan'], $numCols);
            if ($it['colStart'] !== null) {
                $c = $it['colStart'];
                if ($dense) {
                    $r = 0;
                } else {
                    if ($c < $cc) {
                        $cr++;
                    }
                    $r = $cr;
                }
                for ($guard = 0; !$free($r, $c, $rs, $cs) && $guard < 5000; $guard++) {
                    $r++;
                }
                $cr = $r;
                $cc = $c + $cs;
            } else {
                $r = $dense ? 0 : $cr;
                $c = $dense ? 0 : $cc;
                for ($guard = 0; $guard < 20000; $guard++) {
                    if ($c + $cs > $numCols) {
                        $r++;
                        $c = 0;
                    }
                    if ($free($r, $c, $rs, $cs)) {
                        break;
                    }
                    $c++;
                }
                if (!$dense) {
                    $cr = $r;
                    $cc = $c + $cs;
                }
            }
            $out[$k] = ['r' => $r, 'c' => $c, 'rs' => $rs, 'cs' => $cs];
            $mark($r, $c, $rs, $cs);
        }

        $rows = max(1, $explicitRows);
        $cols = $numCols;
        foreach ($out as $o) {
            $rows = max($rows, $o['r'] + $o['rs']);
            $cols = max($cols, $o['c'] + $o['cs']);
        }
        if ($column) {
            $out = array_map(static fn(array $o): array => ['r' => $o['c'], 'c' => $o['r'], 'rs' => $o['cs'], 'cs' => $o['rs']], $out);
            [$rows, $cols] = [$cols, $rows];
        }
        return ['items' => $out, 'rows' => $rows, 'cols' => $cols];
    }
}
