<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Parser for the CSS gradient functions (CSS Images 3/4): linear-gradient(), radial-gradient(), conic-gradient()
 * and their repeating-* forms.
 *
 * The result keeps the colour stops with their raw positions (lengths, percentages or angles) because lengths can only
 * be resolved against the gradient line once the box size is known; {@see self::resolveStops()} does that.
 * Stop colours keep their alpha (including 0: `transparent`, `rgba(0,0,0,0)`), `currentColor` is replaced by the
 * element's colour.
 *
 * Shape of the result:
 *  - type: 'linear' | 'radial' | 'conic', repeating: bool
 *  - linear: angle (CSS degrees, 0 = to top, clockwise) or corner ('top right' ...) for `to <corner>`
 *  - radial: shape ('circle'|'ellipse'), size (keyword), radii ([?string, ?string] explicit lengths), position
 *  - conic: from (degrees), position
 *  - stops: list of ['color' => rgba|null (null = colour hint), 'pos' => ?string]
 */
final class CssGradient
{
    private const FUNC = '/^\s*(-webkit-)?(repeating-)?(linear|radial|conic)-gradient\(\s*(.*)\)\s*$/is';

    /** Name of the gradient function at the start of a CSS value ('linear-gradient', 'repeating-conic-gradient' ...) or null. */
    public static function functionName(string $css): ?string
    {
        if (preg_match('/^\s*(?:-webkit-)?((?:repeating-)?(?:linear|radial|conic)-gradient)\(/i', $css, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * @param array<int|string, mixed>|null $currentColor the element's `color` (for currentColor stops)
     * @return array<string, mixed>|null
     */
    public static function parse(string $css, ?array $currentColor = null): ?array
    {
        if (preg_match(self::FUNC, $css, $m) !== 1) {
            return null;
        }
        $prefixed = $m[1] !== '';
        $type = strtolower($m[3]);
        $args = self::splitArgs($m[4]);
        if ($args === []) {
            return null;
        }
        $g = ['type' => $type, 'repeating' => $m[2] !== ''];
        $first = strtolower(trim($args[0]));
        $hasPrelude = false;

        if ($prefixed) {
            // legacy -webkit- syntax (still drawn by Chromium): `top` names where the gradient starts and
            // angles count counter-clockwise from "to right"; a radial gradient takes `<position>, <shape> <size>`
            if ($type === 'conic') {
                return null;
            }
            [$prelude, $args] = self::legacyPrelude($type, $args);
            $g += $prelude;
        } elseif ($type === 'linear') {
            $g['angle'] = 180.0;
            $g['corner'] = null;
            if (str_starts_with($first, 'to ')) {
                $sides = preg_split('/\s+/', trim(substr($first, 3)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $v = array_values(array_intersect($sides, ['top', 'bottom']));
                $h = array_values(array_intersect($sides, ['left', 'right']));
                if (count($sides) === 1 && count($v) + count($h) === 1) {
                    $g['angle'] = match ($sides[0]) {
                        'top' => 0.0,
                        'right' => 90.0,
                        'bottom' => 180.0,
                        default => 270.0,
                    };
                } elseif (count($sides) === 2 && count($v) === 1 && count($h) === 1) {
                    $g['corner'] = $v[0] . ' ' . $h[0];
                } else {
                    return null;
                }
                $hasPrelude = true;
            } elseif (($deg = self::angle($first, true)) !== null) {
                $g['angle'] = $deg;
                $hasPrelude = true;
            }
        } elseif ($type === 'radial') {
            $g['shape'] = 'ellipse';
            $g['size'] = 'farthest-corner';
            $g['position'] = 'center';
            $g['radii'] = [null, null];
            if (!self::looksLikeStop($first)) {
                $hasPrelude = true;
                $parts = preg_split('/(?:^|\s+)at\s+/', $first, 2) ?: [$first];
                if (count($parts) === 2) {
                    $g['position'] = trim($parts[1]) !== '' ? trim($parts[1]) : 'center';
                }
                $lengths = [];
                $explicitShape = null;
                foreach (preg_split('/\s+/', trim($parts[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
                    if ($tok === 'circle' || $tok === 'ellipse') {
                        $explicitShape = $tok;
                    } elseif (in_array($tok, ['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'], true)) {
                        $g['size'] = $tok;
                    } elseif (preg_match('/^(?:[+-]?[\d.]|calc\()/', $tok) === 1) {
                        $lengths[] = $tok;
                    } else {
                        return null;
                    }
                }
                if ($lengths !== []) {
                    $g['radii'] = [$lengths[0], $lengths[1] ?? null];
                }
                $g['shape'] = $explicitShape ?? (count($lengths) === 1 ? 'circle' : 'ellipse');
            }
        } else {
            $g['from'] = 0.0;
            $g['position'] = 'center';
            if (!self::looksLikeStop($first)) {
                $hasPrelude = true;
                if (preg_match('/^(?:from\s+(\S+))?\s*(?:at\s+(.+))?$/', $first, $cm) !== 1 || trim($first) === '') {
                    return null;
                }
                if (($cm[1] ?? '') !== '') {
                    $deg = self::angle($cm[1], true);
                    if ($deg === null) {
                        return null;
                    }
                    $g['from'] = $deg;
                }
                if (isset($cm[2]) && trim($cm[2]) !== '') {
                    $g['position'] = trim($cm[2]);
                }
            }
        }

        $stops = [];
        foreach ($hasPrelude ? array_slice($args, 1) : $args as $raw) {
            $stop = self::parseStop($raw, $currentColor);
            if ($stop === null) {
                return null;
            }
            foreach ($stop as $s) {
                $stops[] = $s;
            }
        }
        // At least two colour stops (a single colour + position pair counts twice); hints only between colour stops
        $colorCount = count(array_filter($stops, static fn(array $s): bool => $s['color'] !== null));
        if ($colorCount < 2 || $stops[0]['color'] === null || $stops[count($stops) - 1]['color'] === null) {
            return null;
        }
        for ($i = 1, $n = count($stops); $i < $n; $i++) {
            if ($stops[$i]['color'] === null && $stops[$i - 1]['color'] === null) {
                return null;
            }
        }
        $g['stops'] = $stops;
        return $g;
    }

    /**
     * Prelude of the legacy prefixed syntax as the standard keys; returns the remaining (stop) arguments.
     *
     * @param list<string> $args
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function legacyPrelude(string $type, array $args): array
    {
        $first = strtolower(trim($args[0]));
        if ($type === 'linear') {
            $prelude = ['angle' => 180.0, 'corner' => null];
            if (($deg = self::angle($first, true)) !== null) {
                $prelude['angle'] = 90.0 - $deg;
                return [$prelude, array_slice($args, 1)];
            }
            $sides = preg_split('/\s+/', $first, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($sides !== [] && array_diff($sides, ['top', 'bottom', 'left', 'right', 'center']) === []) {
                // the start side: the gradient runs to the opposite side / corner
                $opposite = ['top' => 'bottom', 'bottom' => 'top', 'left' => 'right', 'right' => 'left'];
                $v = array_values(array_intersect($sides, ['top', 'bottom']));
                $h = array_values(array_intersect($sides, ['left', 'right']));
                if ($v !== [] && $h !== []) {
                    // legacy corners run along the diagonal (no "magic corners")
                    $prelude['diagonal'] = $opposite[$v[0]] . ' ' . $opposite[$h[0]];
                } elseif ($v !== [] || $h !== []) {
                    $prelude['angle'] = match ($opposite[($v[0] ?? $h[0])]) {
                        'top' => 0.0,
                        'right' => 90.0,
                        'bottom' => 180.0,
                        default => 270.0,
                    };
                }
                return [$prelude, array_slice($args, 1)];
            }
            return [$prelude, $args];
        }
        $prelude = ['shape' => 'ellipse', 'size' => 'farthest-corner', 'position' => 'center', 'radii' => [null, null]];
        if (!self::looksLikeStop($first)) {
            $prelude['position'] = $first;
            $args = array_slice($args, 1);
            $second = strtolower(trim($args[0] ?? ''));
            if ($second !== '' && !self::looksLikeStop($second)) {
                foreach (preg_split('/\s+/', $second, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
                    if ($tok === 'circle' || $tok === 'ellipse') {
                        $prelude['shape'] = $tok;
                    } else {
                        $prelude['size'] = match ($tok) {
                            'cover' => 'farthest-corner',
                            'contain' => 'closest-side',
                            default => in_array($tok, ['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'], true) ? $tok : $prelude['size'],
                        };
                    }
                }
                $args = array_slice($args, 1);
            }
        }
        return [$prelude, $args];
    }

    /**
     * Resolves stop positions to fractions of the gradient line (CSS Images 3 "color stop fixup"): the first / last
     * unpositioned stops go to 0 / 1, positions never decrease, unpositioned runs are spread evenly; colour hints
     * become a fraction between their neighbours.
     *
     * @param list<array{color: array<int|string, mixed>|null, pos: ?string}> $stops
     * @param callable(string): ?float $toFraction converts a raw position; null when it is invalid
     * @return list<array{color: array<int|string, mixed>|null, t: float}>|null
     */
    public static function resolveStops(array $stops, callable $toFraction): ?array
    {
        $n = count($stops);
        $t = [];
        foreach ($stops as $i => $s) {
            if ($s['pos'] === null) {
                $t[$i] = null;
                continue;
            }
            $v = $toFraction($s['pos']);
            if ($v === null || !is_finite($v)) {
                return null;
            }
            $t[$i] = $v;
        }
        if ($t[0] === null) {
            $t[0] = 0.0;
        }
        if ($t[$n - 1] === null) {
            $t[$n - 1] = 1.0;
        }
        $max = -INF;
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i] !== null) {
                $t[$i] = max($t[$i], $max);
                $max = $t[$i];
            }
        }
        for ($i = 1; $i < $n; $i++) {
            if ($t[$i] !== null) {
                continue;
            }
            $j = $i;
            while ($t[$j] === null) {
                $j++;
            }
            // only colour stops take part in the even spacing (a hint always has a position)
            $from = (float) $t[$i - 1];
            $to = (float) $t[$j];
            $cnt = $j - $i + 1;
            for ($k = $i; $k < $j; $k++) {
                $t[$k] = $from + ($to - $from) * (($k - $i + 1) / $cnt);
            }
            $i = $j;
        }
        $out = [];
        foreach ($stops as $i => $s) {
            $out[] = ['color' => $s['color'], 't' => (float) $t[$i]];
        }
        return $out;
    }

    /**
     * Angle in degrees from a CSS <angle> (deg, grad, rad, turn); $allowZero accepts a unitless 0.
     */
    public static function angle(string $tok, bool $allowZero = false): ?float
    {
        $tok = strtolower(trim($tok));
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(deg|grad|rad|turn)$/', $tok, $m) === 1) {
            $v = (float) $m[1];
            return match ($m[2]) {
                'grad' => $v * 0.9,
                'rad' => rad2deg($v),
                'turn' => $v * 360.0,
                default => $v,
            };
        }
        if ($allowZero && preg_match('/^[+-]?0*\.?0+$/', $tok) === 1) {
            return 0.0;
        }
        return null;
    }

    /**
     * One comma-separated stop argument: `<color> [<pos> [<pos>]]` (two positions = two stops) or a hint `<pos>`.
     *
     * @param array<int|string, mixed>|null $currentColor
     * @return list<array{color: array<int|string, mixed>|null, pos: ?string}>|null
     */
    private static function parseStop(string $raw, ?array $currentColor): ?array
    {
        $tokens = self::splitSpaces(trim($raw));
        if ($tokens === []) {
            return null;
        }
        $positions = [];
        while ($tokens !== [] && count($positions) < 2 && self::isPosition(end($tokens))) {
            array_unshift($positions, array_pop($tokens));
        }
        if ($tokens === []) {
            // colour hint: exactly one position
            return count($positions) === 1 ? [['color' => null, 'pos' => $positions[0]]] : null;
        }
        $colorStr = implode(' ', $tokens);
        $lc = strtolower($colorStr);
        if ($lc === 'currentcolor') {
            $color = $currentColor ?? [0.0, 0.0, 0.0, 1.0];
        } else {
            $color = CssColor::parseWithAlpha($colorStr);
        }
        if ($color === null) {
            return null;
        }
        if ($positions === []) {
            return [['color' => $color, 'pos' => null]];
        }
        $out = [];
        foreach ($positions as $p) {
            $out[] = ['color' => $color, 'pos' => $p];
        }
        return $out;
    }

    private static function isPosition(string $tok): bool
    {
        return preg_match('/^(?:[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?[a-z%]*|(?:calc|min|max|clamp)\(.*\))$/i', $tok) === 1;
    }

    private static function looksLikeStop(string $arg): bool
    {
        $tokens = self::splitSpaces($arg);
        if ($tokens === []) {
            return false;
        }
        $lc = strtolower($tokens[0]);
        if ($lc === 'currentcolor') {
            return true;
        }
        if (in_array($lc, ['from', 'at', 'circle', 'ellipse', 'closest-side', 'closest-corner', 'farthest-side', 'farthest-corner', 'to'], true)) {
            return false;
        }
        // a stop starts with a colour, a hint is a lone position
        if (count($tokens) === 1 && self::isPosition($tokens[0])) {
            return false; // radial size (`radial-gradient(40px, ...)`)
        }
        return CssColor::parseWithAlpha($tokens[0]) !== null;
    }

    /**
     * @return list<string>
     */
    private static function splitArgs(string $str): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $out[] = trim($cur);
        return array_values(array_filter($out, static fn(string $a): bool => $a !== ''));
    }

    /**
     * Splits on whitespace outside parentheses.
     *
     * @return list<string>
     */
    private static function splitSpaces(string $str): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r")) {
                if ($cur !== '') {
                    $out[] = $cur;
                }
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }
}
