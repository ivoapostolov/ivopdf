<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Converts CSS length values and calc() expressions into PDF points (pt, 1/72 inch).
 */
class CssUnit
{
    public const PX_TO_PT = 0.75;       // 72 / 96
    public const MM_TO_PT = 2.83464567; // 72 / 25.4
    public const CM_TO_PT = 28.3464567; // 72 / 2.54
    public const IN_TO_PT = 72.0;
    public const PC_TO_PT = 12.0;

    private static float $dpi = 96.0;

    public static function setDpi(float $dpi): void
    {
        self::$dpi = max(36.0, $dpi);
    }

    public static function getPxToPt(): float
    {
        return 72.0 / self::$dpi;
    }

    /**
     * Converts a CSS length string into PDF points (pt).
     */
    public static function toPt(
        string|float|int|null $value,
        float $referenceSizePt = 0.0,
        float $fontSizePt = 12.0,
        float $rootFontSizePt = 12.0,
        float $defaultPt = 0.0,
        float $viewportWidthPt = 595.28,
        float $viewportHeightPt = 841.89
    ): float {
        if ($value === null) {
            return $defaultPt;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $str = strtolower(trim($value));
        if ($str === '' || $str === 'auto' || $str === 'none' || $str === 'normal' || $str === 'initial') {
            return $defaultPt;
        }

        if ($str === '0') {
            return 0.0;
        }

        $pxToPt = self::getPxToPt();

        if ($str === 'thin') {
            return $pxToPt;
        }
        if ($str === 'medium') {
            return 2.0 * $pxToPt;
        }
        if ($str === 'thick') {
            return 4.0 * $pxToPt;
        }

        // calc(), min(), max(), clamp() - nested, mixed units; invalid (e.g. division by zero) -> default
        if (CssCalc::isMathFunction($str)) {
            $r = CssCalc::evaluate($str, $referenceSizePt, $fontSizePt, $rootFontSizePt, $viewportWidthPt, $viewportHeightPt);
            return $r ?? $defaultPt;
        }

        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(%|[a-z]+)?$/', $str, $m) !== 1) {
            return $defaultPt;
        }
        $num = (float) $m[1];
        return match ($m[2] ?? '') {
            '', 'px' => $num * $pxToPt,
            '%' => ($num / 100.0) * $referenceSizePt,
            'rem' => $num * $rootFontSizePt,
            'em' => $num * $fontSizePt,
            'ex', 'ch' => $num * $fontSizePt * 0.5,
            'pt' => $num,
            'mm' => $num * self::MM_TO_PT,
            'cm' => $num * self::CM_TO_PT,
            'q' => $num * self::MM_TO_PT / 4.0,
            'in' => $num * self::IN_TO_PT,
            'pc' => $num * self::PC_TO_PT,
            'vw' => ($num / 100.0) * $viewportWidthPt,
            'vh' => ($num / 100.0) * $viewportHeightPt,
            'vmin' => ($num / 100.0) * min($viewportWidthPt, $viewportHeightPt),
            'vmax' => ($num / 100.0) * max($viewportWidthPt, $viewportHeightPt),
            default => $defaultPt,
        };
    }
}
