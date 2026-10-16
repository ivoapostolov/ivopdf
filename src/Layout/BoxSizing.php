<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Helpers for percentage heights (against a definite containing-block height) and the `aspect-ratio` property.
 */
final class BoxSizing
{
    /**
     * The definite border-box height of $box (explicit px height, stretched by a parent, or a % of a definite parent), else null.
     */
    public static function definiteBorderBoxHeight(LayoutEngine $engine, LayoutBox $box, int $depth = 0): ?float
    {
        if ($box->forcedBorderBoxHeight !== null) {
            return $box->forcedBorderBoxHeight;
        }
        if ($depth > 40) {
            return null;
        }
        $hCss = strtolower(trim($box->style->get('height', 'auto')));
        if ($hCss === 'auto' || $hCss === '' || $hCss === 'fit-content' || $hCss === 'max-content' || $hCss === 'min-content') {
            return null;
        }
        if (str_ends_with($hCss, '%')) {
            $base = self::percentBase($engine, $box, $depth + 1);
            return $base === null ? null : $engine->resolveBoxVerticalDimension($box, $hCss, $base);
        }
        return $engine->resolveBoxVerticalDimension($box, $hCss, 0.0);
    }

    /**
     * The content-box height of the containing block that percentage heights of $box resolve against (null = indefinite).
     */
    public static function percentBase(LayoutEngine $engine, LayoutBox $box, int $depth = 0): ?float
    {
        $parent = $box->parent;
        if ($parent === null) {
            return null;
        }
        $h = self::definiteBorderBoxHeight($engine, $parent, $depth + 1);
        if ($h === null) {
            return null;
        }
        return max(0.0, $h - $parent->getVerticalSpacing());
    }

    /**
     * Parses `aspect-ratio` ("16 / 9", "1.5", "auto 3/2") into width / height; null when not set.
     */
    public static function aspectRatio(LayoutBox $box): ?float
    {
        $css = strtolower(trim($box->style->get('aspect-ratio', 'auto')));
        if ($css === '' || $css === 'auto') {
            return null;
        }
        $css = trim(str_replace('auto', '', $css));
        if (preg_match('/^([\d.]+)\s*(?:\/\s*([\d.]+))?$/', $css, $m) !== 1) {
            return null;
        }
        $a = (float) $m[1];
        $b = isset($m[2]) && $m[2] !== '' ? (float) $m[2] : 1.0;
        return ($a > 0.0 && $b > 0.0) ? $a / $b : null;
    }
}
