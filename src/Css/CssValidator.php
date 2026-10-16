<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Declaration validation (CSS error recovery): ComputedStyle::set() drops a declaration whose value is not valid for
 * its property, so the previous cascade value stays instead of the garbage being applied as 0 / black.
 *
 * Covers lengths/percentages/keywords/math functions of the box-model, positioning, spacing, font-size, gap and
 * border-width properties and colors (shorthands are validated component by component). Properties that are not
 * listed here are never rejected. Values still containing an unresolved var()/env()/attr() are accepted.
 */
final class CssValidator
{
    private const INTRINSIC = ['fit-content', 'min-content', 'max-content', 'stretch', 'available', '-webkit-fill-available', '-moz-available', 'content'];
    private const UNITS = ['%', 'rem', 'em', 'ex', 'ch', 'px', 'pt', 'mm', 'cm', 'q', 'in', 'pc', 'vw', 'vh', 'vmin', 'vmax',
        'svw', 'svh', 'lvw', 'lvh', 'dvw', 'dvh', 'vi', 'vb', 'cqw', 'cqh', 'cqi', 'cqb', 'cap', 'lh', 'rlh', 'ic'];
    private const BORDER_STYLES = ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'];
    private const FONT_SIZE_KW = ['xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'smaller', 'larger', 'math'];
    private const COLOR_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark',
        'cmyk', 'device-cmyk', 'spot'];

    public static function isValid(string $prop, string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return !self::isChecked($prop);
        }
        if (in_array(strtolower($v), ['inherit', 'initial', 'unset', 'revert', 'revert-layer'], true)) {
            return true;
        }
        // Unresolved substitution functions are validated at computed-value time by browsers: let them through
        if (preg_match('/\b(?:var|env|attr)\(/i', $v) === 1) {
            return true;
        }
        $spec = self::lengthSpec($prop);
        if ($spec !== null) {
            return self::validLengthList($v, $spec['max'], $spec['kw'], $spec['neg'], $spec['slash'] ?? false);
        }
        if (self::isColorProp($prop)) {
            return self::validColorList($v, $prop === 'border-color' ? 4 : 1);
        }
        if ($prop === 'border' || $prop === 'border-top' || $prop === 'border-right' || $prop === 'border-bottom' || $prop === 'border-left'
            || $prop === 'outline' || $prop === 'column-rule') {
            return self::validBorderShorthand($v);
        }
        return true;
    }

    private static function isChecked(string $prop): bool
    {
        return self::lengthSpec($prop) !== null || self::isColorProp($prop);
    }

    private static function isColorProp(string $prop): bool
    {
        return $prop === 'color' || $prop === 'background-color' || $prop === 'border-color' || $prop === 'outline-color'
            || $prop === 'text-decoration-color' || $prop === 'caret-color' || $prop === 'column-rule-color'
            || (str_starts_with($prop, 'border-') && str_ends_with($prop, '-color'));
    }

    /**
     * @return array{max: int, kw: list<string>, neg: bool, slash?: bool}|null
     */
    private static function lengthSpec(string $prop): ?array
    {
        static $cache = [];
        if (isset($cache[$prop])) {
            return $cache[$prop] ?: null;
        }
        $spec = null;
        switch (true) {
            case $prop === 'width' || $prop === 'height' || $prop === 'min-width' || $prop === 'min-height'
                || $prop === 'inline-size' || $prop === 'block-size':
                $spec = ['max' => 1, 'kw' => array_merge(['auto', 'none'], self::INTRINSIC), 'neg' => false];
                break;
            case $prop === 'max-width' || $prop === 'max-height':
                $spec = ['max' => 1, 'kw' => array_merge(['none', 'auto'], self::INTRINSIC), 'neg' => false];
                break;
            case $prop === 'margin' || $prop === 'inset':
                $spec = ['max' => 4, 'kw' => ['auto'], 'neg' => true];
                break;
            case $prop === 'margin-inline' || $prop === 'margin-block' || $prop === 'inset-inline' || $prop === 'inset-block':
                $spec = ['max' => 2, 'kw' => ['auto'], 'neg' => true];
                break;
            case in_array($prop, ['margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'top', 'right', 'bottom', 'left',
                'margin-inline-start', 'margin-inline-end', 'margin-block-start', 'margin-block-end',
                'inset-inline-start', 'inset-inline-end', 'inset-block-start', 'inset-block-end'], true):
                $spec = ['max' => 1, 'kw' => ['auto'], 'neg' => true];
                break;
            case $prop === 'padding':
                $spec = ['max' => 4, 'kw' => [], 'neg' => false];
                break;
            case $prop === 'padding-inline' || $prop === 'padding-block':
                $spec = ['max' => 2, 'kw' => [], 'neg' => false];
                break;
            case in_array($prop, ['padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'padding-inline-start', 'padding-inline-end', 'padding-block-start', 'padding-block-end'], true):
                $spec = ['max' => 1, 'kw' => [], 'neg' => false];
                break;
            case $prop === 'text-indent':
                $spec = ['max' => 3, 'kw' => ['each-line', 'hanging'], 'neg' => true];
                break;
            case $prop === 'letter-spacing' || $prop === 'word-spacing':
                $spec = ['max' => 1, 'kw' => ['normal'], 'neg' => true];
                break;
            case $prop === 'line-height':
                $spec = ['max' => 1, 'kw' => ['normal'], 'neg' => false];
                break;
            case $prop === 'gap' || $prop === 'grid-gap':
                $spec = ['max' => 2, 'kw' => ['normal'], 'neg' => false];
                break;
            case in_array($prop, ['row-gap', 'column-gap', 'grid-row-gap', 'grid-column-gap'], true):
                $spec = ['max' => 1, 'kw' => ['normal'], 'neg' => false];
                break;
            case $prop === 'flex-basis':
                $spec = ['max' => 1, 'kw' => array_merge(['auto'], self::INTRINSIC), 'neg' => false];
                break;
            case $prop === 'border-width':
                $spec = ['max' => 4, 'kw' => ['thin', 'medium', 'thick'], 'neg' => false];
                break;
            case in_array($prop, ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'outline-width'], true):
                $spec = ['max' => 1, 'kw' => ['thin', 'medium', 'thick'], 'neg' => false];
                break;
            case $prop === 'font-size':
                $spec = ['max' => 1, 'kw' => self::FONT_SIZE_KW, 'neg' => false];
                break;
            case $prop === 'border-radius':
                $spec = ['max' => 8, 'kw' => [], 'neg' => false, 'slash' => true];
                break;
            case in_array($prop, ['border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius'], true):
                $spec = ['max' => 2, 'kw' => [], 'neg' => false];
                break;
        }
        $cache[$prop] = $spec ?? false;
        return $spec;
    }

    /** @param list<string> $kw */
    private static function validLengthList(string $v, int $max, array $kw, bool $allowNeg, bool $slash): bool
    {
        if ($slash) {
            $v = str_replace('/', ' ', $v);
        }
        $tokens = self::tokens($v);
        if ($tokens === null || $tokens === [] || count($tokens) > $max) {
            return false;
        }
        foreach ($tokens as $t) {
            if (!self::validLength($t, $kw, $allowNeg)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $kw */
    public static function validLength(string $token, array $kw = [], bool $allowNeg = true): bool
    {
        $t = strtolower(trim($token));
        if ($t === '') {
            return false;
        }
        if (in_array($t, $kw, true)) {
            return true;
        }
        if (preg_match('/^([a-z-]+)\(/', $t, $fm) === 1) {
            if (CssCalc::isMathFunction($t)) {
                return CssCalc::evaluate($t, 100.0, 12.0, 12.0, 600.0, 800.0) !== null;
            }
            if ($fm[1] === 'fit-content' || $fm[1] === 'minmax') {
                return self::balanced($t);
            }
            return false;
        }
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)([a-z%]*)$/', $t, $m) !== 1) {
            return false;
        }
        if ($m[2] !== '' && !in_array($m[2], self::UNITS, true)) {
            return false;
        }
        return $allowNeg || (float) $m[1] >= 0.0;
    }

    private static function validColorList(string $v, int $max): bool
    {
        $tokens = self::tokens($v);
        if ($tokens === null || $tokens === [] || count($tokens) > $max) {
            return false;
        }
        foreach ($tokens as $t) {
            if (!self::validColor($t)) {
                return false;
            }
        }
        return true;
    }

    public static function validColor(string $token): bool
    {
        $t = strtolower(trim($token));
        if ($t === 'transparent' || $t === 'currentcolor') {
            return true;
        }
        if ($t !== '' && $t[0] === '#') {
            return preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $t) === 1;
        }
        if (preg_match('/^([a-z-]+)\(/', $t, $m) === 1) {
            return in_array($m[1], self::COLOR_FUNCTIONS, true) && self::balanced($t);
        }
        if (preg_match('/^[a-z]+$/', $t) !== 1) {
            return false;
        }
        return CssColor::parse($t) !== null;
    }

    private static function validBorderShorthand(string $v): bool
    {
        $tokens = self::tokens($v);
        if ($tokens === null || $tokens === []) {
            return false;
        }
        foreach ($tokens as $t) {
            $tl = strtolower($t);
            if (in_array($tl, self::BORDER_STYLES, true) || self::validLength($t, ['thin', 'medium', 'thick'], false) || self::validColor($t)) {
                continue;
            }
            return false;
        }
        return true;
    }

    /** True when the parentheses are balanced and the text is a single `name(...)` group. */
    private static function balanced(string $s): bool
    {
        $depth = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                if (--$depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /** Whitespace-separated tokens at parenthesis depth 0; null when parentheses are unbalanced. @return list<string>|null */
    private static function tokens(string $s): ?array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if (--$depth < 0) {
                    return null;
                }
            }
            if ($depth === 0 && ctype_space($c)) {
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                continue;
            }
            $cur .= $c;
        }
        if ($depth !== 0) {
            return null;
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }
}
