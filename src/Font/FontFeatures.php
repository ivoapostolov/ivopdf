<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use IvoPdf\Css\ComputedStyle;
use WeakMap;

/**
 * Derives the OpenType feature set (tag => value) from CSS font-feature-settings, font-kerning,
 * font-variant-ligatures / -caps / -numeric and letter-spacing.
 */
final class FontFeatures
{
    /** @var WeakMap<ComputedStyle, array<string, int>>|null */
    private static ?WeakMap $cache = null;

    /**
     * @return array<string, int>
     */
    public static function fromStyle(ComputedStyle $style, float $letterSpacingPt = 0.0): array
    {
        self::$cache ??= new WeakMap();
        if (!isset(self::$cache[$style])) {
            self::$cache[$style] = self::compute($style);
        }
        $features = self::$cache[$style];
        if (abs($letterSpacingPt) > 0.001) {
            // CSS: optional ligatures are disabled when letter-spacing is non-zero
            $features += ['liga' => 0, 'clig' => 0];
        }
        return $features;
    }

    /**
     * Stable cache key for a feature set.
     *
     * @param array<string, int> $features
     */
    public static function key(array $features): string
    {
        if ($features === []) {
            return '';
        }
        ksort($features);
        $k = '';
        foreach ($features as $tag => $v) {
            $k .= $tag . $v . ',';
        }
        return $k;
    }

    /**
     * @return array<string, int>
     */
    private static function compute(ComputedStyle $style): array
    {
        $f = [];
        $kerning = strtolower($style->get('font-kerning', 'auto'));
        if ($kerning === 'none') {
            $f['kern'] = 0;
        }

        $tokens = [];
        foreach (['font-variant', 'font-variant-ligatures', 'font-variant-caps', 'font-variant-numeric'] as $prop) {
            $v = strtolower(trim($style->get($prop, 'normal')));
            if ($v !== '' && $v !== 'normal') {
                foreach (preg_split('/\s+/', $v) ?: [] as $t) {
                    $tokens[$t] = true;
                }
            }
        }
        if ($tokens !== []) {
            if (isset($tokens['none'])) {
                $f['liga'] = 0;
                $f['clig'] = 0;
                $f['dlig'] = 0;
                $f['hlig'] = 0;
                $f['calt'] = 0;
            }
            foreach ([
                'no-common-ligatures' => ['liga' => 0, 'clig' => 0],
                'common-ligatures' => ['liga' => 1, 'clig' => 1],
                'discretionary-ligatures' => ['dlig' => 1],
                'no-discretionary-ligatures' => ['dlig' => 0],
                'historical-ligatures' => ['hlig' => 1],
                'contextual' => ['calt' => 1],
                'no-contextual' => ['calt' => 0],
                'small-caps' => ['smcp' => 1],
                'all-small-caps' => ['smcp' => 1, 'c2sc' => 1],
                'petite-caps' => ['pcap' => 1],
                'all-petite-caps' => ['pcap' => 1, 'c2pc' => 1],
                'unicase' => ['unic' => 1],
                'titling-caps' => ['titl' => 1],
                'lining-nums' => ['lnum' => 1],
                'oldstyle-nums' => ['onum' => 1],
                'proportional-nums' => ['pnum' => 1],
                'tabular-nums' => ['tnum' => 1],
                'diagonal-fractions' => ['frac' => 1],
                'stacked-fractions' => ['afrc' => 1],
                'ordinal' => ['ordn' => 1],
                'slashed-zero' => ['zero' => 1],
            ] as $token => $set) {
                if (isset($tokens[$token])) {
                    foreach ($set as $k => $val) {
                        $f[$k] = $val;
                    }
                }
            }
        }

        $settings = $style->get('font-feature-settings', 'normal');
        if ($settings !== '' && strtolower(trim($settings)) !== 'normal'
            && preg_match_all('/["\']([A-Za-z0-9 ]{4})["\']\s*(on|off|\d+)?/', $settings, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $val = strtolower($match[2] ?? '');
                $f[$match[1]] = $val === 'off' ? 0 : ($val === '' || $val === 'on' ? 1 : (int) $val);
            }
        }
        return $f;
    }
}
