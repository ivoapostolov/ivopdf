<?php

declare(strict_types=1);

namespace IvoPdf\Dom;

use IvoPdf\Options;

/**
 * Protections against pathological input (multi-megabyte "words", runaway documents).
 */
final class InputGuards
{
    /**
     * Integer value of an HTML attribute (colspan, rowspan, size, start, span, rows, ...), following the HTML "rules
     * for parsing integers": optional whitespace and sign, then the leading digits (" 12abc" => 12). The result is
     * clamped to [$min, $max]; $default when there are no digits. Unlike an (int) cast, absurd values such as
     * "99999999999999999999" or "1e400" never raise PHP's "not representable as an int" warning.
     */
    public static function intAttr(?string $value, int $min, int $max, int $default): int
    {
        if ($value === null || preg_match('/^[\t\n\f\r ]*([+-]?)(\d+)/', $value, $m) !== 1) {
            return $default;
        }
        $digits = ltrim($m[2], '0');
        $negative = $m[1] === '-' && $digits !== '';
        if (strlen($digits) > 18) {
            return $negative ? $min : $max; // beyond any int: saturate
        }
        $n = (int) $digits;
        return max($min, min($max, $negative ? -$n : $n));
    }

    /**
     * Inserts a space into runs of non-whitespace characters longer than $max so the line breaker, the shaper and
     * the measuring caches never see a single multi-megabyte word (such "words" are minified blobs, never prose).
     */
    public static function breakLongWords(string $text, int $max, ?Options $options = null): string
    {
        $max = max(16, min($max, 60000));
        // Only ASCII whitespace ends a word for the line breaker (U+00A0 &nbsp; stays inside it), so \S would miss nbsp runs
        $out = preg_replace('/([^ \t\n\r\f]{' . $max . '})(?=[^ \t\n\r\f])/u', '$1 ', $text);
        if ($out === null) {
            // Not valid UTF-8: byte-wise split restricted to ASCII so no multibyte sequence is cut in half
            $out = preg_replace('/([\x21-\x7E]{' . $max . '})(?=[\x21-\x7E])/', '$1 ', $text) ?? $text;
        }
        if ($out !== $text) {
            $options?->addWarning("An unbroken word longer than {$max} characters was split (see Options::\$maxWordLength).");
        }
        return $out;
    }
}
