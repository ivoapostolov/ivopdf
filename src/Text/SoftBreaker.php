<?php

declare(strict_types=1);

namespace IvoPdf\Text;

use IvoPdf\Css\ComputedStyle;

/**
 * Splits a word at soft hyphens (U+00AD) and, with `hyphens: auto`, at pattern-based hyphenation points.
 */
final class SoftBreaker
{
    private const SHY = "\u{00AD}";

    /**
     * Returns the fragments of a word; a line may break between consecutive fragments (drawing the hyphenation character).
     * Soft hyphens are removed from the fragments. A word that cannot break is returned unchanged.
     *
     * @return list<string>
     */
    public static function split(string $word, ComputedStyle $style): array
    {
        $mode = strtolower(trim($style->get('hyphens', 'manual')));
        if ($mode === 'none') {
            return [$word];
        }

        if (str_contains($word, self::SHY)) {
            $frags = [];
            $carry = '';
            foreach (explode(self::SHY, $word) as $part) {
                $carry .= $part;
                if ($carry !== '') {
                    $frags[] = $carry;
                    $carry = '';
                }
            }
            return $frags === [] ? [''] : $frags;
        }

        if ($mode !== 'auto') {
            return [$word];
        }
        $hyphenator = Hyphenator::forLang($style->get('-ivopdf-lang', ''));
        if ($hyphenator === null || preg_match('/^([^\p{L}]*)(\p{L}+)([^\p{L}]*)$/u', $word, $m) !== 1) {
            return [$word];
        }

        [$minWord, $left, $right] = self::limits($style->get('hyphenate-limit-chars', 'auto'));
        $points = $hyphenator->hyphenate($m[2], $minWord, $left, $right);
        if ($points === []) {
            return [$word];
        }

        $chars = preg_split('//u', $m[2], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $frags = [];
        $start = 0;
        foreach ($points as $p) {
            $frags[] = implode('', array_slice($chars, $start, $p - $start));
            $start = $p;
        }
        $frags[] = implode('', array_slice($chars, $start));
        $frags[0] = $m[1] . $frags[0];
        $frags[count($frags) - 1] .= $m[3];
        return $frags;
    }

    /**
     * Expands hyphen-delimited word pieces into break fragments.
     *
     * @param list<string> $pieces
     * @return list<array{0: string, 1: bool, 2: bool}> [text, a break is allowed before it, it follows a soft/automatic hyphenation point]
     */
    public static function expand(array $pieces, ComputedStyle $style): array
    {
        $out = [];
        foreach ($pieces as $pi => $piece) {
            if ($piece === '') {
                continue;
            }
            foreach (self::split($piece, $style) as $fi => $frag) {
                if ($frag !== '') {
                    $out[] = [$frag, $pi > 0 && $fi === 0 || $fi > 0, $fi > 0];
                }
            }
        }
        return $out;
    }

    /**
     * The character drawn at a line end broken inside a word (CSS `hyphenate-character`, default "-").
     */
    public static function hyphenChar(ComputedStyle $style): string
    {
        $v = trim($style->get('hyphenate-character', 'auto'));
        if ($v !== '' && strtolower($v) !== 'auto' && preg_match('/^(["\'])(.*)\1$/su', $v, $m) === 1 && $m[2] !== '') {
            return $m[2];
        }
        return '-';
    }

    /**
     * Parses `hyphenate-limit-chars` into [min word length, min chars before, min chars after] (default 5 2 2).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function limits(string $value): array
    {
        $nums = [];
        foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            $nums[] = ctype_digit($tok) ? (int) $tok : null;
        }
        $word = $nums[0] ?? null;
        $left = $nums[1] ?? null;
        $right = $nums[2] ?? $left;
        return [max(2, $word ?? 5), max(1, $left ?? 2), max(1, $right ?? 2)];
    }
}
