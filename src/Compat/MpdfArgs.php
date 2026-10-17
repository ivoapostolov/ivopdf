<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * Argument coercion for the mPDF-compatible methods. mPDF's methods are untyped, so applications pass numeric strings
 * ("12" from a config file), booleans or null where a number / string is meant; with `declare(strict_types=1)` in the
 * calling file typed parameters would throw a TypeError. The compatibility methods take `mixed` and convert here,
 * the way PHP's own arithmetic / string context would in mPDF.
 */
final class MpdfArgs
{
    public static function str(mixed $v, string $default = ''): string
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        if (is_scalar($v) || $v instanceof \Stringable) {
            return (string) $v;
        }
        return $default;
    }

    public static function num(mixed $v, float $default = 0.0): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if (is_string($v)) {
            $t = trim($v);
            if (is_numeric($t)) {
                return (float) $t;
            }
            // "12mm" / "5pt" style values: the leading number, as PHP's arithmetic would use it
            if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $t, $m) === 1) {
                return (float) $m[0];
            }
        }
        return $default;
    }

    public static function int(mixed $v, int $default = 0): int
    {
        $f = self::num($v, (float) $default);
        if (is_nan($f)) {
            return $default;
        }
        return (int) max(-2147483648.0, min(2147483647.0, round($f)));
    }

    /** PHP truthiness, as mPDF tests its flags ("1", 1, true are true; "", "0", 0, false are false); null gives $default. */
    public static function bool(mixed $v, bool $default = false): bool
    {
        return $v === null ? $default : (bool) $v;
    }
}
