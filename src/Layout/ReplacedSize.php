<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Used size of a replaced element with a natural size and aspect ratio (`<img>`): `width` / `height` (the HTML
 * attributes map to them), the aspect ratio for an auto dimension and the min / max constraints (CSS 2.1 §10.4).
 *
 * The same size is the element's min-content / max-content contribution, so a table column, a float or an
 * inline-block holding a scaled image is sized to the image as painted, not to its natural size. For intrinsic
 * sizing (no containing block yet) percentages behave as in browsers: `width: %` is auto and `max-width: %` none
 * (they do not limit the max-content contribution), and such a "compressible" replaced element contributes 0 to
 * min-content (CSS Sizing 3 §5.2.2).
 *
 * @internal
 */
final class ReplacedSize
{
    /**
     * Border-box [width, height] in pt.
     *
     * @param float      $naturalW         natural content width (pt)
     * @param float      $naturalH         natural content height (pt)
     * @param float|null $containingWidth  null = intrinsic sizing (percentages unresolvable)
     * @return array{0: float, 1: float}
     */
    public static function used(LayoutEngine $engine, LayoutBox $box, float $naturalW, float $naturalH, ?float $containingWidth): array
    {
        $ratio = $naturalH > 0.0 && $naturalW > 0.0 ? $naturalW / $naturalH : 1.0;
        $hSpace = $box->getHorizontalSpacing();
        $vSpace = $box->getVerticalSpacing();
        $ref = $containingWidth ?? 0.0;

        // content-box lengths, null = auto / none
        $len = static function (string $prop, string $none, bool $horizontal) use ($engine, $box, $containingWidth, $ref, $hSpace, $vSpace): ?float {
            $css = strtolower(trim($box->style->get($prop, $none)));
            if ($css === '' || $css === $none || $css === 'auto' || $css === 'none' || str_contains($css, 'content')) {
                return null;
            }
            $vBase = null;
            if (!$horizontal && str_contains($css, '%')) {
                // height / min-height / max-height: % of the containing block's height when it is definite
                // (a parent with a fixed height, an absolutely positioned box...), otherwise auto / none
                $vBase = BoxSizing::percentBase($engine, $box);
                if ($vBase === null) {
                    return null;
                }
            } elseif ($containingWidth === null && str_contains($css, '%')) {
                return null;
            }
            $v = $horizontal
                ? $engine->resolveBoxHorizontalDimension($box, $css, $ref) - $hSpace
                : $engine->resolveBoxVerticalDimension($box, $css, $vBase ?? $ref) - $vSpace;
            return is_nan($v) ? null : max(0.0, $v);
        };

        $width = $len('width', 'auto', true);
        $height = $len('height', 'auto', false);
        $minW = $len('min-width', '0', true) ?? 0.0;
        $maxW = $len('max-width', 'none', true) ?? INF;
        $minH = $len('min-height', '0', false) ?? 0.0;
        $maxH = $len('max-height', 'none', false) ?? INF;
        $maxW = max($maxW, $minW);
        $maxH = max($maxH, $minH);

        if ($width !== null && $height !== null) {
            $w = min(max($width, $minW), $maxW);
            $h = min(max($height, $minH), $maxH);
        } elseif ($width !== null) {
            $w = min(max($width, $minW), $maxW);
            $h = min(max($w / $ratio, $minH), $maxH);
        } elseif ($height !== null) {
            $h = min(max($height, $minH), $maxH);
            $w = min(max($h * $ratio, $minW), $maxW);
        } else {
            $w = $naturalW;
            $h = $naturalH > 0.0 ? $naturalH : $naturalW / $ratio;
            if ($containingWidth !== null && $w + $hSpace > $containingWidth) {
                // an auto-sized image never overflows its containing block (an implicit max-width: 100%)
                $w = max(0.0, $containingWidth - $hSpace);
                $h = $w / $ratio;
            }
            [$w, $h] = self::constrainAuto($w, $h, $minW, $maxW, $minH, $maxH);
        }
        return [max(0.0, $w) + $hSpace, max(0.0, $h) + $vSpace];
    }

    /** Whether a percentage `width` / `max-width` makes the element compressible (min-content contribution 0). */
    public static function isCompressible(LayoutBox $box): bool
    {
        return str_contains($box->style->get('width', 'auto'), '%') || str_contains($box->style->get('max-width', 'none'), '%');
    }

    /**
     * CSS 2.1 §10.4 table: both dimensions auto, min / max constraints applied keeping the aspect ratio.
     *
     * @return array{0: float, 1: float}
     */
    private static function constrainAuto(float $w, float $h, float $minW, float $maxW, float $minH, float $maxH): array
    {
        if ($w <= 0.0 || $h <= 0.0) {
            return [min(max($w, $minW), $maxW), min(max($h, $minH), $maxH)];
        }
        $wHi = $w > $maxW;
        $wLo = $w < $minW;
        $hHi = $h > $maxH;
        $hLo = $h < $minH;
        if ($wHi && $hHi) {
            return $maxW / $w <= $maxH / $h ? [$maxW, max($minH, $maxW * $h / $w)] : [max($minW, $maxH * $w / $h), $maxH];
        }
        if ($wLo && $hLo) {
            return $minW / $w <= $minH / $h ? [min($maxW, $minH * $w / $h), $minH] : [$minW, min($maxH, $minW * $h / $w)];
        }
        if ($wLo && $hHi) {
            return [$minW, $maxH];
        }
        if ($wHi && $hLo) {
            return [$maxW, $minH];
        }
        if ($wHi) {
            return [$maxW, max($maxW * $h / $w, $minH)];
        }
        if ($wLo) {
            return [$minW, min($minW * $h / $w, $maxH)];
        }
        if ($hHi) {
            return [max($maxH * $w / $h, $minW), $maxH];
        }
        if ($hLo) {
            return [min($minH * $w / $h, $maxW), $minH];
        }
        return [$w, $h];
    }
}
