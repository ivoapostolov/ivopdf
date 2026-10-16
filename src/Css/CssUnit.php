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

        // Handle calc(...) expressions
        if (str_starts_with($str, 'calc(') && str_ends_with($str, ')')) {
            $expr = trim(substr($str, 5, -1));
            return self::evaluateCalc(
                $expr,
                $referenceSizePt,
                $fontSizePt,
                $rootFontSizePt,
                $viewportWidthPt,
                $viewportHeightPt
            );
        }

        if (str_ends_with($str, '%')) {
            $pct = (float) substr($str, 0, -1);
            return ($pct / 100.0) * $referenceSizePt;
        }
        if (str_ends_with($str, 'rem')) {
            return ((float) substr($str, 0, -3)) * $rootFontSizePt;
        }
        if (str_ends_with($str, 'em')) {
            return ((float) substr($str, 0, -2)) * $fontSizePt;
        }
        if (str_ends_with($str, 'px')) {
            return ((float) substr($str, 0, -2)) * $pxToPt;
        }
        if (str_ends_with($str, 'pt')) {
            return (float) substr($str, 0, -2);
        }
        if (str_ends_with($str, 'mm')) {
            return ((float) substr($str, 0, -2)) * self::MM_TO_PT;
        }
        if (str_ends_with($str, 'cm')) {
            return ((float) substr($str, 0, -2)) * self::CM_TO_PT;
        }
        if (str_ends_with($str, 'in')) {
            return ((float) substr($str, 0, -2)) * self::IN_TO_PT;
        }
        if (str_ends_with($str, 'pc')) {
            return ((float) substr($str, 0, -2)) * self::PC_TO_PT;
        }
        if (str_ends_with($str, 'vw')) {
            return (((float) substr($str, 0, -2)) / 100.0) * $viewportWidthPt;
        }
        if (str_ends_with($str, 'vh')) {
            return (((float) substr($str, 0, -2)) / 100.0) * $viewportHeightPt;
        }

        if (is_numeric($str)) {
            return ((float) $str) * $pxToPt;
        }

        return $defaultPt;
    }

    /**
     * Evaluates CSS calc() expressions like "100% - 24px", "50% - 10pt + 4px", "(100% - 30px) / 2".
     */
    private static function evaluateCalc(
        string $expr,
        float $referenceSizePt,
        float $fontSizePt,
        float $rootFontSizePt,
        float $viewportWidthPt,
        float $viewportHeightPt
    ): float {
        $converted = preg_replace_callback(
            '/(-?\d+(?:\.\d+)?)\s*(%|rem|em|px|pt|mm|cm|in|pc|vw|vh)/i',
            static function (array $m) use (
                $referenceSizePt,
                $fontSizePt,
                $rootFontSizePt,
                $viewportWidthPt,
                $viewportHeightPt
            ): string {
                $val = self::toPt(
                    $m[1] . $m[2],
                    $referenceSizePt,
                    $fontSizePt,
                    $rootFontSizePt,
                    0.0,
                    $viewportWidthPt,
                    $viewportHeightPt
                );
                return sprintf('%.6F', $val);
            },
            $expr
        );

        if ($converted === null) {
            return 0.0;
        }

        if (!preg_match('/^[\d.\s+\-*\/()]+$/', $converted)) {
            return 0.0;
        }

        return self::safeMathEval($converted);
    }

    private static function safeMathEval(string $expr): float
    {
        while (preg_match('/\(([^()]+)\)/', $expr, $m)) {
            $subVal = self::evalFlatMath($m[1]);
            $expr = str_replace($m[0], sprintf('%.6F', $subVal), $expr);
        }
        return self::evalFlatMath($expr);
    }

    private static function evalFlatMath(string $expr): float
    {
        preg_match_all('/(-?\d+(?:\.\d+)?)|([+\-*\/])/', $expr, $matches, PREG_SET_ORDER);
        if (!$matches) {
            return 0.0;
        }

        $tokens = [];
        foreach ($matches as $m) {
            $tokens[] = $m[0];
        }

        $pass1 = [];
        $i = 0;
        $n = count($tokens);
        while ($i < $n) {
            $tok = $tokens[$i];
            if (($tok === '*' || $tok === '/') && !empty($pass1) && isset($tokens[$i + 1])) {
                $left = (float) array_pop($pass1);
                $right = (float) $tokens[$i + 1];
                $pass1[] = $tok === '*' ? ($left * $right) : ($right != 0.0 ? $left / $right : 0.0);
                $i += 2;
            } else {
                $pass1[] = $tok;
                $i++;
            }
        }

        $result = isset($pass1[0]) ? (float) $pass1[0] : 0.0;
        $i = 1;
        $n = count($pass1);
        while ($i < $n) {
            $op = $pass1[$i];
            if (($op === '+' || $op === '-') && isset($pass1[$i + 1])) {
                $right = (float) $pass1[$i + 1];
                $result = $op === '+' ? ($result + $right) : ($result - $right);
                $i += 2;
            } else {
                if (is_numeric($op)) {
                    $result += (float) $op;
                }
                $i++;
            }
        }

        return $result;
    }
}
