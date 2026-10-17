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
