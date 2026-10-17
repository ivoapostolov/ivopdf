<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use IvoPdf\Css\ComputedStyle;

/**
 * Derives the OpenType feature set (tag => value) from CSS font-feature-settings, font-kerning,
 * font-variant-ligatures / -caps / -numeric and letter-spacing.
 */
final class FontFeatures
{
    /** Feature-array entry carrying the OpenType language system tag (4 bytes packed big-endian), not a feature. */
    public const LANG_KEY = '~lang';

    /** BCP 47 primary language subtag => OpenType language system tag (languages with localized forms in common fonts). */
    private const OT_LANGS = [
        'bg' => 'BGR ', 'ru' => 'RUS ', 'uk' => 'UKR ', 'be' => 'BEL ', 'sr' => 'SRB ', 'mk' => 'MKD ',
        'kk' => 'KAZ ', 'ky' => 'KIR ', 'tt' => 'TAT ', 'ba' => 'BSH ', 'cv' => 'CHU ', 'mn' => 'MNG ',
        'ro' => 'ROM ', 'mo' => 'MOL ', 'tr' => 'TRK ', 'az' => 'AZE ', 'crh' => 'CRT ', 'pl' => 'PLK ',
        'nl' => 'NLD ', 'ca' => 'CAT ', 'de' => 'DEU ', 'en' => 'ENG ', 'fr' => 'FRA ', 'es' => 'ESP ',
        'it' => 'ITA ', 'pt' => 'PTG ', 'el' => 'ELL ', 'hu' => 'HUN ', 'cs' => 'CSY ', 'sk' => 'SKY ',
        'hr' => 'HRV ', 'sl' => 'SLV ', 'bs' => 'BOS ', 'lt' => 'LTH ', 'lv' => 'LVI ', 'et' => 'ETI ',
        'fi' => 'FIN ', 'sv' => 'SVE ', 'da' => 'DAN ', 'no' => 'NOR ', 'nb' => 'NOR ', 'nn' => 'NYN ',
        'is' => 'ISL ', 'ga' => 'IRI ', 'cy' => 'WEL ', 'mt' => 'MTS ', 'sq' => 'SQI ', 'vi' => 'VIT ',
        'ja' => 'JAN ', 'ko' => 'KOR ', 'zh' => 'ZHS ', 'ar' => 'ARA ', 'fa' => 'FAR ', 'ur' => 'URD ',
        'he' => 'IWR ', 'hi' => 'HIN ', 'mr' => 'MAR ', 'ne' => 'NEP ', 'sa' => 'SAN ',
    ];

    /**
     * Feature sets by the style values they derive from. Keyed by value (not by style object): a document with
     * hundreds of thousands of text styles then keeps a handful of entries instead of one weak-map slot per style.
     *
     * @var array<string, array<string, int>>
     */
    private static array $cache = [];

    /**
     * @return array<string, int>
     */
    public static function fromStyle(ComputedStyle $style, float $letterSpacingPt = 0.0): array
    {
        $key = $style->get('font-kerning', 'auto') . "\0" . $style->get('font-variant', 'normal') . "\0"
            . $style->get('font-variant-ligatures', 'normal') . "\0" . $style->get('font-variant-caps', 'normal') . "\0"
            . $style->get('font-variant-numeric', 'normal') . "\0" . $style->get('font-feature-settings', 'normal') . "\0"
            . $style->get('-ivopdf-lang', '');
        $features = self::$cache[$key] ?? null;
        if ($features === null) {
            $features = self::compute($style);
            $lang = self::otLanguage($style->get('-ivopdf-lang', ''));
            if ($lang !== null) {
                $features[self::LANG_KEY] = (int) unpack('N', $lang)[1];
            }
            if (count(self::$cache) >= 512) {
                self::$cache = [];
            }
            self::$cache[$key] = $features;
        }
        if (abs($letterSpacingPt) > 0.001) {
            // CSS: optional ligatures are disabled when letter-spacing is non-zero
            $features += ['liga' => 0, 'clig' => 0];
        }
        return $features;
    }

    /** OpenType language system tag for a CSS/HTML language ("bg", "sr-Latn", "zh-Hant"...), or null. */
    public static function otLanguage(string $lang): ?string
    {
        $lang = strtolower(trim(str_replace('_', '-', $lang)));
        if ($lang === '') {
            return null;
        }
        if ($lang === 'zh-hant' || str_starts_with($lang, 'zh-tw') || str_starts_with($lang, 'zh-hk')) {
            return 'ZHT ';
        }
        $primary = explode('-', $lang)[0];
        return self::OT_LANGS[$primary] ?? null;
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
