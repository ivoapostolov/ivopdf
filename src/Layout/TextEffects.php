<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Font\TtfParser;

/**
 * Typographic effects that work on text before/after line breaking: synthesized small capitals,
 * ::first-letter extraction and the property overlay used by ::first-line.
 */
final class TextEffects
{
    /** Scale of synthesized small capitals relative to the surrounding font size */
    public const SMALL_CAPS_SCALE = 0.7;

    /**
     * Factor for synthesized small capitals at $fontSizePt: 0.7 of the size, rounded to a whole CSS pixel like
     * Chrome / Edge (20pt = 26.67px -> 18.67px -> 19px = 14.25pt), so line breaks match the browser.
     */
    public static function smallCapsFactor(float $fontSizePt): float
    {
        if ($fontSizePt <= 0.0) {
            return self::SMALL_CAPS_SCALE;
        }
        $px = max(1.0, round($fontSizePt / 0.75 * self::SMALL_CAPS_SCALE));
        return $px * 0.75 / $fontSizePt;
    }

    /** Properties a ::first-line rule may change (CSS Pseudo-Elements 4) */
    public const FIRST_LINE_PROPS = [
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-variant-caps', 'font-variant-ligatures',
        'font-variant-numeric', 'font-feature-settings', 'font-kerning', 'color', 'opacity', 'letter-spacing', 'word-spacing',
        'text-transform', 'text-decoration', 'text-decoration-line', 'text-decoration-color', 'text-decoration-style',
        'text-shadow', 'line-height', 'vertical-align', 'background-color', 'background',
    ];

    /**
     * 0 = normal caps, 1 = small-caps, 2 = all-small-caps (petite caps are treated as small caps).
     */
    public static function smallCapsMode(ComputedStyle $style): int
    {
        $mode = 0;
        foreach (['font-variant', 'font-variant-caps'] as $prop) {
            $v = strtolower($style->get($prop, 'normal'));
            if ($v === '' || $v === 'normal') {
                continue;
            }
            if (str_contains($v, 'all-small-caps') || str_contains($v, 'all-petite-caps')) {
                $mode = 2;
            } elseif ((str_contains($v, 'small-caps') || str_contains($v, 'petite-caps')) && $mode < 1) {
                $mode = 1;
            }
        }
        return $mode;
    }

    /**
     * Whether the font itself provides the small-caps glyph substitutions (OpenType smcp, and c2sc for all-small-caps).
     */
    public static function fontHasSmallCaps(TtfParser $font, int $mode): bool
    {
        $layout = $font->getLayout();
        if ($layout === null || !$layout->hasTable('GSUB')) {
            return false;
        }
        if ($layout->featureLookups('GSUB', ['latn'], 'smcp') === []) {
            return false;
        }
        return $mode < 2 || $layout->featureLookups('GSUB', ['latn'], 'c2sc') !== [];
    }

    /**
     * Splits text into runs of full-size and small-cap characters. Small segments still hold the original
     * (lowercase) characters; the caller upper-cases them.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function smallCapsSegments(string $text, int $mode): array
    {
        if (preg_match('/\p{L}/u', $text) !== 1) {
            return [[$text, false]];
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $out = [];
        $cur = '';
        $curSmall = null;
        foreach ($chars as $ch) {
            $small = $mode === 2 ? preg_match('/^\p{L}$/u', $ch) === 1 : preg_match('/^\p{Ll}$/u', $ch) === 1;
            if ($curSmall !== null && $small !== $curSmall) {
                $out[] = [$cur, $curSmall];
                $cur = '';
            }
            $curSmall = $small;
            $cur .= $ch;
        }
        if ($cur !== '') {
            $out[] = [$cur, (bool) $curSmall];
        }
        return $out;
    }

    /**
     * Length in bytes of the ::first-letter prefix of $text (leading punctuation, the first letter/digit and
     * trailing punctuation), or null when the text has none. $lead receives the length of skippable leading whitespace.
     *
     * @return array{0: int, 1: int}|null [whitespaceBytes, letterBytes]
     */
    public static function firstLetterSpan(string $text): ?array
    {
        if (preg_match('/^(\s*)((?:[\p{Ps}\p{Pi}\p{Po}\p{Pf}"\'](?!\s))*[\p{L}\p{N}][\p{Pe}\p{Pf}"\']*)/u', $text, $m) !== 1) {
            return null;
        }
        return [strlen($m[1]), strlen($m[2])];
    }

    /**
     * Applies text-transform to a string (used for ::first-line restyling).
     */
    public static function transform(string $text, string $transform): string
    {
        return match (strtolower(trim($transform))) {
            'uppercase' => mb_strtoupper($text, 'UTF-8'),
            'lowercase' => mb_strtolower($text, 'UTF-8'),
            'capitalize' => (string) preg_replace_callback('/^(\p{Ll})/u', static fn(array $m): string => mb_strtoupper($m[1], 'UTF-8'), $text),
            default => $text,
        };
    }
}
