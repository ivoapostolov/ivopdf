<?php

declare(strict_types=1);

namespace IvoPdf\Text;

use IvoPdf\Css\ComputedStyle;

/**
 * `word-break: break-all` support: lets a word wrap between any two characters.
 */
final class WordBreakMode
{
    /**
     * Splits word pieces into single grapheme clusters when the style allows breaking anywhere.
     *
     * @param list<string> $pieces
     * @return list<string>
     */
    public static function apply(array $pieces, ComputedStyle $style): array
    {
        if (strtolower(trim($style->get('word-break', 'normal'))) !== 'break-all') {
            return $pieces;
        }
        $out = [];
        foreach ($pieces as $piece) {
            if (preg_match_all('/\X/u', $piece, $m) > 1) {
                array_push($out, ...$m[0]);
            } else {
                $out[] = $piece;
            }
        }
        return $out;
    }
}
