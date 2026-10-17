<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use IvoPdf\Text\ArabicShaper;
use IvoPdf\Text\Bidi;
use IvoPdf\Text\IndicShaper;

/**
 * Converts a codepoint sequence into positioned glyphs: Arabic joining (GSUB init/medi/fina or Presentation Forms-B
 * fallback), GSUB substitutions (ligatures etc.), GPOS/kern positioning, mark attachment and RTL reversal.
 *
 * Glyph format: [gid, advance, xOffset, yOffset, clusterText] with metrics in 1/1000 em, in visual (left-to-right) order.
 */
final class TextShaper
{
    private const BIT_ALL = 1;
    private const BIT_ISOL = 2;
    private const BIT_INIT = 4;
    private const BIT_MEDI = 8;
    private const BIT_FINA = 16;

    /** Default-on substitution features (besides the Arabic form features). */
    private const DEFAULT_GSUB = ['rlig', 'rclt', 'calt', 'liga', 'clig'];

    /** Characters that produce no glyph (zero-width/format controls). */
    private const IGNORABLE = [
        0x00AD => true, 0x200B => true, 0x200C => true, 0x200D => true, 0x200E => true, 0x200F => true, 0x061C => true,
        0x202A => true, 0x202B => true, 0x202C => true, 0x202D => true, 0x202E => true,
        0x2060 => true, 0x2066 => true, 0x2067 => true, 0x2068 => true, 0x2069 => true, 0xFEFF => true,
    ];

    /**
     * @param list<int> $cps
     * @param array<string, int> $features OpenType feature tag => value (0 disables, >0 enables)
     * @return array{glyphs: ?list<array{0: int, 1: float, 2: float, 3: float, 4: string}>, units: float, count: int}
     *         glyphs is null when the plain codepoint->glyph mapping is exact (nothing to shape, LTR)
     */
    public static function shape(TtfParser $font, array $cps, array $features, bool $rtl): array
    {
        $n = count($cps);
        $layout = $font->getLayout();

        // Fast path: pure ASCII/Latin-1 without layout tables
        $items = [];
        $used = [];
        $arabicForms = null;
        $hasArabic = false;
        $script = 'latn';
        $indic = false;
        $lang = '';
        if (isset($features[FontFeatures::LANG_KEY])) {
            $lang = pack('N', $features[FontFeatures::LANG_KEY]);
            unset($features[FontFeatures::LANG_KEY]);
        }
        foreach ($cps as $cp) {
            if ($cp >= 0x0370 && $cp < 0x0530) {
                if ($script === 'latn') {
                    $script = $cp < 0x0400 ? 'grek' : 'cyrl';
                }
            } elseif ($cp >= 0x0590) {
                if (ArabicShaper::isArabicLike($cp)) {
                    $hasArabic = true;
                    $script = 'arab';
                } elseif ($cp <= 0x05FF) {
                    $script = $script === 'arab' ? $script : 'hebr';
                } elseif ($cp >= 0x0900 && $cp <= 0x0D7F && IndicShaper::scriptOf($cp) !== null) {
                    $indic = true;
                } elseif ($script === 'latn') {
                    $script = self::scriptTag($cp) ?? $script;
                }
            }
        }
        $indicRes = null;
        if ($indic) {
            // Indic scripts: syllable reordering and the basic/presentation feature sequence
            $indicRes = IndicShaper::process($font, $layout, $cps, $features);
            $script = IndicShaper::scriptTags($cps);
        }
        if ($hasArabic) {
            $arabicForms = ArabicShaper::analyze($cps);
        }

        // Does the font substitute Arabic forms through GSUB?
        $gsubForms = $hasArabic && $layout !== null && $layout->hasTable('GSUB') && (
            $layout->featureLookups('GSUB', ['arab'], 'init') !== []
            || $layout->featureLookups('GSUB', ['arab'], 'medi') !== []
            || $layout->featureLookups('GSUB', ['arab'], 'fina') !== []
        );

        $skipNext = -1;
        for ($i = 0; $i < ($indicRes === null ? $n : 0); $i++) {
            $cp = $cps[$i];
            if ($i === $skipNext || isset(self::IGNORABLE[$cp])) {
                continue;
            }
            $mask = self::BIT_ALL;
            if ($arabicForms !== null && $arabicForms[$i] !== ArabicShaper::F_NONE) {
                $form = $arabicForms[$i];
                if ($gsubForms) {
                    $mask |= match ($form) {
                        ArabicShaper::F_ISOL => self::BIT_ISOL,
                        ArabicShaper::F_INIT => self::BIT_INIT,
                        ArabicShaper::F_MEDI => self::BIT_MEDI,
                        default => self::BIT_FINA,
                    };
                } else {
                    // Presentation Forms-B fallback (with lam-alef ligatures)
                    if ($cp === 0x0644 && ($form === ArabicShaper::F_INIT || $form === ArabicShaper::F_MEDI)) {
                        $j = $i + 1;
                        while ($j < $n && ArabicShaper::joiningType($cps[$j]) === ArabicShaper::J_T) {
                            $j++;
                        }
                        if ($j < $n) {
                            $lig = ArabicShaper::lamAlef($cps[$j], $form === ArabicShaper::F_MEDI);
                            if ($lig !== null && $font->hasGlyph($lig)) {
                                $items[] = ['g' => $font->getGlyphId($lig), 's' => $i, 'e' => $j, 'm' => $mask];
                                $used[$i] = $cp;
                                $skipNext = $j;
                                // transparent marks between lam and alef stay as separate glyphs
                                for ($k = $i + 1; $k < $j; $k++) {
                                    $items[] = ['g' => $font->getGlyphId($cps[$k]), 's' => $k, 'e' => $k, 'm' => $mask];
                                    $used[$k] = $cps[$k];
                                }
                                // consume the alef: mark positions up to j as handled
                                $i = $j;
                                continue;
                            }
                        }
                    }
                    $pf = ArabicShaper::presentationForm($cp, $form);
                    if ($pf !== null && $font->hasGlyph($pf)) {
                        $items[] = ['g' => $font->getGlyphId($pf), 's' => $i, 'e' => $i, 'm' => $mask];
                        $used[$i] = $cp;
                        continue;
                    }
                }
            }
            $glyphCp = $cp === 0xA0 ? 0x20 : $cp;
            if ($rtl) {
                $glyphCp = Bidi::mirror($glyphCp);
            }
            $items[] = ['g' => $font->getGlyphId($glyphCp), 's' => $i, 'e' => $i, 'm' => $mask];
            $used[$i] = $glyphCp;
        }

        if ($indicRes !== null) {
            $items = $indicRes['items'];
            $used = $indicRes['used'];
        }
        $initial = [];
        foreach ($items as $it) {
            $initial[] = $it['g'];
        }

        // ---- GSUB ----
        $buf = $indicRes['buf'] ?? $items;
        if ($indicRes === null && $layout !== null && $layout->hasTable('GSUB') && $buf !== []) {
            $stages = self::gsubStages($layout, (string) $script, $features, $gsubForms, $lang);
            if ($stages !== []) {
                $buf = $layout->applyGsub($buf, $stages);
            }
        }

        // ---- GPOS / kern ----
        $glyphs = [];
        $count = count($buf);
        $trivial = !$rtl && $count === count($items) && $indicRes === null;
        $posBuf = null;
        if ($layout !== null && $layout->hasTable('GPOS') && $buf !== []) {
            $lookups = self::gposLookups($layout, $script, $features);
            if ($lookups !== []) {
                $posBuf = $layout->applyGpos($buf, $lookups);
            }
        }
        $legacy = $layout !== null && $posBuf === null && ($features['kern'] ?? 1) !== 0 && $layout->hasLegacyKern();

        $advances = [];
        $xoff = [];
        $yoff = [];
        $classes = [];
        $hasGdef = $layout !== null && $layout->hasGdef();
        for ($k = 0; $k < $count; $k++) {
            $gid = $buf[$k]['g'];
            $w = (float) $font->getGlyphWidthByGid($gid);
            $x = 0.0;
            $y = 0.0;
            if ($posBuf !== null) {
                $w += $posBuf[$k]['xa'];
                $x = $posBuf[$k]['x'];
                $y = $posBuf[$k]['y'];
            }
            if ($legacy && $k + 1 < $count) {
                $w += $layout->legacyKern($gid, $buf[$k + 1]['g']);
            }
            $isMark = isset($posBuf[$k]['att']) || ($hasGdef && $layout->glyphClass($gid) === 3)
                || Bidi::classOf($cps[$buf[$k]['s']]) === Bidi::NSM;
            if ($isMark) {
                $w = 0.0;
            }
            $advances[$k] = $w;
            $xoff[$k] = $x;
            $yoff[$k] = $y;
        }

        // Resolve mark attachments in logical order
        if ($posBuf !== null) {
            $total = array_sum($advances);
            $pen = [];
            $acc = 0.0;
            foreach ($advances as $k => $a) {
                $pen[$k] = $rtl ? $total - $acc - $a : $acc;
                $acc += $a;
            }
            for ($k = 0; $k < $count; $k++) {
                if (isset($posBuf[$k]['att'])) {
                    [$j, $dx, $dy] = $posBuf[$k]['att'];
                    if ($j < $k) {
                        $xoff[$k] = $xoff[$j] + $dx + $pen[$j] - $pen[$k];
                        $yoff[$k] = $yoff[$j] + $dy;
                    }
                }
            }
        }

        $units = 0.0;
        for ($k = 0; $k < $count; $k++) {
            $gid = $buf[$k]['g'];
            $units += $advances[$k];
            if ($trivial && ($gid !== $initial[$k] || abs($advances[$k] - $font->getGlyphWidthByGid($gid)) > 0.001
                || abs($xoff[$k]) > 0.001 || abs($yoff[$k]) > 0.001)) {
                $trivial = false;
            }
        }
        if ($trivial) {
            return ['glyphs' => null, 'units' => $units, 'count' => $count];
        }

        for ($k = 0; $k < $count; $k++) {
            $s = $buf[$k]['s'];
            $e = $buf[$k]['e'];
            if (!empty($buf[$k]['nt'])) {
                $text = ''; // second half of a split vowel sign: the text belongs to the first half
            } elseif ($s === $e) {
                $text = mb_chr($used[$s] ?? $cps[$s], 'UTF-8');
            } else {
                $text = '';
                for ($c = $s; $c <= $e; $c++) {
                    if (isset($used[$c])) {
                        $text .= mb_chr($used[$c], 'UTF-8');
                    }
                }
            }
            if ($k > 0 && $buf[$k - 1]['s'] === $s && $buf[$k - 1]['e'] === $e) {
                $text = ''; // further glyph of a one-to-many substitution (e.g. Thai SARA AM): the first glyph carries the text
            }
            $glyphs[] = [$buf[$k]['g'], $advances[$k], $xoff[$k], $yoff[$k], $text];
        }
        if ($rtl) {
            $glyphs = array_reverse($glyphs);
        }
        return ['glyphs' => $glyphs, 'units' => $units, 'count' => $count];
    }

    /**
     * OpenType script tag for CJK / Southeast Asian codepoints ('hani', 'kana', 'hang', 'thai', 'lao '), or null.
     */
    private static function scriptTag(int $cp): ?string
    {
        return match (true) {
            $cp >= 0x0E01 && $cp <= 0x0E5B => 'thai',
            $cp >= 0x0E81 && $cp <= 0x0EDF => 'lao ',
            ($cp >= 0x3041 && $cp <= 0x30FF) || ($cp >= 0xFF66 && $cp <= 0xFF9F) => 'kana',
            ($cp >= 0xAC00 && $cp <= 0xD7FF) || ($cp >= 0x1100 && $cp <= 0x11FF) => 'hang',
            ($cp >= 0x2E80 && $cp <= 0x9FFF) || ($cp >= 0xF900 && $cp <= 0xFAFF) || ($cp >= 0x20000 && $cp <= 0x3FFFF) => 'hani',
            default => null,
        };
    }

    /**
     * @param array<string, int> $features
     * @return list<array<int, int>>
     */
    private static function gsubStages(OpenTypeLayout $layout, string $script, array $features, bool $arabicForms, string $lang = ''): array
    {
        $scripts = [$script];
        $stage1 = [];
        $stage2 = [];
        $stage3 = [];
        $add = static function (array &$stage, string $tag, int $bit) use ($layout, $scripts, $lang): void {
            foreach ($layout->featureLookups('GSUB', $scripts, $tag, $lang) as $li) {
                $stage[$li] = ($stage[$li] ?? 0) | $bit;
            }
        };
        foreach (['ccmp', 'locl'] as $tag) {
            if (($features[$tag] ?? 1) !== 0) {
                $add($stage1, $tag, self::BIT_ALL);
            }
        }
        if ($arabicForms) {
            $add($stage2, 'isol', self::BIT_ISOL);
            $add($stage2, 'fina', self::BIT_FINA);
            $add($stage2, 'fin2', self::BIT_FINA);
            $add($stage2, 'fin3', self::BIT_FINA);
            $add($stage2, 'medi', self::BIT_MEDI);
            $add($stage2, 'med2', self::BIT_MEDI);
            $add($stage2, 'init', self::BIT_INIT);
        }
        foreach (self::DEFAULT_GSUB as $tag) {
            if (($features[$tag] ?? 1) !== 0) {
                $add($stage3, $tag, self::BIT_ALL);
            }
        }
        foreach ($features as $tag => $value) {
            if ($value > 0 && !in_array($tag, self::DEFAULT_GSUB, true) && !in_array($tag, ['kern', 'mark', 'mkmk', 'ccmp', 'locl', 'curs', 'dist'], true)) {
                $add($stage3, (string) $tag, self::BIT_ALL);
            }
        }
        $stages = [];
        foreach ([$stage1, $stage2, $stage3] as $st) {
            if ($st !== []) {
                ksort($st);
                $stages[] = $st;
            }
        }
        return $stages;
    }

    /**
     * @param array<string, int> $features
     * @return array<int, int>
     */
    private static function gposLookups(OpenTypeLayout $layout, string|array $script, array $features): array
    {
        $lookups = [];
        foreach (['kern', 'dist', 'abvm', 'blwm', 'mark', 'mkmk', 'curs'] as $tag) {
            if (($features[$tag] ?? 1) === 0 || $tag === 'curs') {
                continue;
            }
            foreach ($layout->featureLookups('GPOS', (array) $script, $tag) as $li) {
                $lookups[$li] = ($lookups[$li] ?? 0) | self::BIT_ALL;
            }
        }
        ksort($lookups);
        return $lookups;
    }
}
