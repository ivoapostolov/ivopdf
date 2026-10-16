<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Expansion of the CSS Grid shorthands grid-template, grid and grid-area into their longhands.
 */
final class GridShorthand
{
    /**
     * @return array<string, string>|null longhand => value, or null when the value is invalid
     */
    public static function expand(string $prop, string $val): ?array
    {
        return match ($prop) {
            'grid-template' => self::template($val),
            'grid' => self::grid($val),
            'grid-area' => self::area($val),
            default => null,
        };
    }

    /**
     * grid-area: row-start / column-start / row-end / column-end (missing values copy per the spec).
     *
     * @return array<string, string>
     */
    private static function area(string $val): array
    {
        $parts = array_map('trim', explode('/', $val));
        $isIdent = static fn(string $p): bool => preg_match('/^[A-Za-z_\-][\w\-]*$/', $p) === 1 && strtolower($p) !== 'auto' && strtolower($p) !== 'span';
        $rs = $parts[0];
        $cs = $parts[1] ?? ($isIdent($rs) ? $rs : 'auto');
        $re = $parts[2] ?? ($isIdent($rs) ? $rs : 'auto');
        $ce = $parts[3] ?? ($isIdent($cs) ? $cs : 'auto');
        return [
            'grid-row-start' => $rs, 'grid-column-start' => $cs, 'grid-row-end' => $re, 'grid-column-end' => $ce,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private static function template(string $val): ?array
    {
        $v = trim($val);
        $out = ['grid-template-areas' => 'none', 'grid-template-rows' => 'none', 'grid-template-columns' => 'none'];
        if (strtolower($v) === 'none') {
            return $out;
        }
        $slash = self::topLevelSlash($v);
        $left = $slash === null ? $v : trim(substr($v, 0, $slash));
        $right = $slash === null ? null : trim(substr($v, $slash + 1));

        if (str_contains($left, '"') || str_contains($left, "'")) {
            // [names]? "area row" size? [names]? ... / columns
            preg_match_all('/\[[^\]]*\]|"[^"]*"|\x27[^\x27]*\x27|[\w\-]+\([^)]*\)|[^\s\[\]"\x27]+/', $left, $tm);
            $rowsData = [];
            $pending = '';
            foreach ($tm[0] as $tok) {
                if ($tok[0] === '"' || $tok[0] === "'") {
                    $rowsData[] = ['pre' => $pending, 'area' => trim(substr($tok, 1, -1)), 'size' => '', 'post' => ''];
                    $pending = '';
                } elseif ($tok[0] === '[') {
                    if ($rowsData === [] || $rowsData[count($rowsData) - 1]['post'] !== '') {
                        $pending = trim($pending . ' ' . $tok);
                    } else {
                        $rowsData[count($rowsData) - 1]['post'] = $tok;
                    }
                } elseif ($rowsData !== [] && $rowsData[count($rowsData) - 1]['size'] === '' && $rowsData[count($rowsData) - 1]['post'] === '') {
                    $rowsData[count($rowsData) - 1]['size'] = $tok;
                } else {
                    return null;
                }
            }
            if ($rowsData === []) {
                return null;
            }
            $areaRows = [];
            $trackParts = [];
            foreach ($rowsData as $rd) {
                $areaRows[] = '"' . $rd['area'] . '"';
                $trackParts[] = trim($rd['pre'] . ' ' . ($rd['size'] !== '' ? $rd['size'] : 'auto') . ' ' . $rd['post']);
            }
            $out['grid-template-areas'] = implode(' ', $areaRows);
            $out['grid-template-rows'] = implode(' ', $trackParts);
            $out['grid-template-columns'] = $right !== null && $right !== '' ? $right : 'none';
            return $out;
        }
        if ($right === null) {
            return null;
        }
        $out['grid-template-rows'] = $left === '' ? 'none' : $left;
        $out['grid-template-columns'] = $right === '' ? 'none' : $right;
        return $out;
    }

    /**
     * grid: <grid-template> | <rows> / auto-flow [dense] <auto-columns>? | auto-flow [dense] <auto-rows>? / <columns>
     *
     * @return array<string, string>|null
     */
    private static function grid(string $val): ?array
    {
        $v = trim($val);
        $out = [
            'grid-template-areas' => 'none', 'grid-template-rows' => 'none', 'grid-template-columns' => 'none',
            'grid-auto-flow' => 'row', 'grid-auto-rows' => 'auto', 'grid-auto-columns' => 'auto',
        ];
        if (stripos($v, 'auto-flow') === false) {
            $t = self::template($v);
            return $t === null ? null : $t + $out;
        }
        $slash = self::topLevelSlash($v);
        if ($slash === null) {
            return null;
        }
        $left = trim(substr($v, 0, $slash));
        $right = trim(substr($v, $slash + 1));
        if (stripos($left, 'auto-flow') === 0) {
            // auto-flow [dense] <auto-rows>? / <columns>
            $rest = trim(substr($left, 9));
            $dense = false;
            if (stripos($rest, 'dense') === 0) {
                $dense = true;
                $rest = trim(substr($rest, 5));
            }
            $out['grid-auto-flow'] = $dense ? 'row dense' : 'row';
            $out['grid-auto-rows'] = $rest === '' ? 'auto' : $rest;
            $out['grid-template-columns'] = $right === '' ? 'none' : $right;
            return $out;
        }
        if (stripos($right, 'auto-flow') === 0) {
            $rest = trim(substr($right, 9));
            $dense = false;
            if (stripos($rest, 'dense') === 0) {
                $dense = true;
                $rest = trim(substr($rest, 5));
            }
            $out['grid-auto-flow'] = $dense ? 'column dense' : 'column';
            $out['grid-auto-columns'] = $rest === '' ? 'auto' : $rest;
            $out['grid-template-rows'] = $left === '' ? 'none' : $left;
            return $out;
        }
        return null;
    }

    /** Position of the first "/" outside quotes, brackets and parentheses. */
    private static function topLevelSlash(string $s): ?int
    {
        $depth = 0;
        $quote = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === '/' && $depth === 0) {
                return $i;
            }
        }
        return null;
    }
}
