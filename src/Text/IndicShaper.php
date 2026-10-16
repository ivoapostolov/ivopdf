<?php

declare(strict_types=1);

namespace IvoPdf\Text;

use IvoPdf\Font\OpenTypeLayout;
use IvoPdf\Font\TtfParser;

/**
 * Indic shaping engine (OpenType "v2" script tags: dev2, bng2, gur2, gjr2, tml2, ...), a compact take on the
 * Microsoft / HarfBuzz Indic model:
 *
 *  1. split the text into orthographic syllables (consonant clusters, independent vowels, modifiers),
 *  2. find the base consonant, the reph (Ra + virama) and assign every character a position class,
 *  3. initial reordering (pre-base vowel signs move before the cluster, reph to the front for rphf),
 *  4. basic GSUB features in order (nukt, akhn, rphf, rkrf, pref, blwf, abvf, half, pstf, vatu, cjct),
 *  5. final reordering (formed reph moves behind the base, before post-base signs),
 *  6. presentation features (pres, abvs, blws, psts, haln) and default substitutions.
 *
 * Mark positioning (abvm, blwm, dist, mark, mkmk) is left to the GPOS stage of {@see \IvoPdf\Font\TextShaper}.
 * Glyph items use the buffer format of {@see OpenTypeLayout}: ['g' gid, 's' first source index, 'e' last source
 * index, 'm' feature mask] plus 'p' (position class).
 */
final class IndicShaper
{
    // character categories
    private const C_X = 0;     // other
    private const C_C = 1;     // consonant
    private const C_RA = 2;    // the consonant Ra
    private const C_V = 3;     // independent vowel
    private const C_N = 4;     // nukta
    private const C_H = 5;     // virama / halant
    private const C_ZWNJ = 6;
    private const C_ZWJ = 7;
    private const C_M = 8;     // dependent vowel sign
    private const C_SM = 9;    // syllable modifier / accent (candrabindu, anusvara, visarga, vedic signs)

    // position classes (sort keys inside a syllable)
    private const P_START = 0;
    private const P_REPH = 1;
    private const P_PRE_M = 2;
    private const P_PRE_C = 3;
    private const P_BASE = 4;
    private const P_AFTER_MAIN = 5;
    private const P_ABOVE = 6;
    private const P_BEFORE_SUB = 7;
    private const P_BELOW = 8;
    private const P_AFTER_SUB = 9;
    private const P_BEFORE_POST = 10;
    private const P_POST = 11;
    private const P_AFTER_POST = 12;
    private const P_FINAL = 13;
    private const P_SMVD = 14;

    // feature mask bits
    private const M_ALL = 1;
    private const M_RPHF = 2;
    private const M_PRE = 4;

    /**
     * Per script: [block start, block end, OpenType script tags, reph position (0 after main, 1 before post,
     * 2 after post), Ra codepoint offset or -1, below-form consonant offsets, post-form consonant offsets,
     * pre-base vowel sign offsets, above vowel sign offsets, below vowel sign offsets, has nukta].
     *
     * @var array<string, array{0: int, 1: int, 2: list<string>, 3: int, 4: int, 5: list<int>, 6: list<int>, 7: list<int>, 8: list<int>, 9: list<int>, 10: bool}>
     */
    private const SCRIPTS = [
        'deva' => [0x0900, 0x097F, ['dev2', 'deva'], 1, 0x30, [0x30], [], [0x3F, 0x4E], [0x45, 0x46, 0x47, 0x48, 0x55, 0x3A], [0x41, 0x42, 0x43, 0x44, 0x56, 0x57, 0x62, 0x63], true],
        'beng' => [0x0980, 0x09FF, ['bng2', 'beng'], 2, 0x30, [0x30, 0x2C], [0x2F], [0x3F, 0x47, 0x48], [], [0x41, 0x42, 0x43, 0x44, 0x62, 0x63], true],
        'guru' => [0x0A00, 0x0A7F, ['gur2', 'guru'], 1, 0x30, [0x30, 0x39, 0x35], [0x2F], [0x3F], [0x47, 0x48, 0x4B, 0x4C], [0x41, 0x42], true],
        'gujr' => [0x0A80, 0x0AFF, ['gjr2', 'gujr'], 1, 0x30, [0x30], [], [0x3F], [0x45, 0x47, 0x48], [0x41, 0x42, 0x43, 0x44, 0x62, 0x63], true],
        'orya' => [0x0B00, 0x0B7F, ['ory2', 'orya'], 0, 0x30, [0x30, 0x2C], [], [], [0x40, 0x47], [0x41, 0x42, 0x43, 0x44], true],
        'taml' => [0x0B80, 0x0BFF, ['tml2', 'taml'], 2, 0x30, [], [], [0x46, 0x47, 0x48], [], [], false],
        'telu' => [0x0C00, 0x0C7F, ['tel2', 'telu'], 2, 0x30, [0x30, 0x2F, 0x35], [], [], [0x46, 0x47, 0x48, 0x55], [0x41, 0x42, 0x43, 0x44, 0x56], true],
        'knda' => [0x0C80, 0x0CFF, ['knd2', 'knda'], 2, 0x30, [0x30, 0x2F, 0x35], [], [], [0x3F, 0x46, 0x47, 0x48, 0x4A, 0x4B], [0x41, 0x42, 0x43, 0x44], true],
        'mlym' => [0x0D00, 0x0D7F, ['mlm2', 'mlym'], 0, 0x30, [0x30, 0x2F, 0x35], [], [0x46, 0x47, 0x48], [], [0x41, 0x42, 0x43, 0x44], false],
    ];

    /** Two-part vowel signs that must be split so that the pre-base part can move: cp => [pre part, post part]. */
    private const SPLIT_VOWELS = [
        0x09CB => [0x09C7, 0x09BE], 0x09CC => [0x09C7, 0x09D7],
        0x0B4B => [0x0B47, 0x0B3E], 0x0B4C => [0x0B47, 0x0B57], 0x0B48 => [0x0B47, 0x0B56],
        0x0BCA => [0x0BC6, 0x0BBE], 0x0BCB => [0x0BC7, 0x0BBE], 0x0BCC => [0x0BC6, 0x0BD7],
        0x0D4A => [0x0D46, 0x0D3E], 0x0D4B => [0x0D47, 0x0D3E], 0x0D4C => [0x0D46, 0x0D57],
        0x0CC0 => [0x0CBF, 0x0CD5], 0x0CC7 => [0x0CC6, 0x0CD5], 0x0CC8 => [0x0CC6, 0x0CD6],
        0x0CCA => [0x0CC6, 0x0CC2], 0x0CCB => [0x0CCA, 0x0CD5],
        0x0C48 => [0x0C46, 0x0C56],
    ];

    /** Presentation / positional GSUB features applied after the final reordering. */
    private const PRESENTATION = ['pres', 'abvs', 'blws', 'psts', 'haln'];

    /** @var array<int, string|null> cp => script key cache */
    private static array $scriptCache = [];

    /**
     * Script key ('deva', 'beng', ...) of an Indic codepoint, or null.
     */
    public static function scriptOf(int $cp): ?string
    {
        if ($cp < 0x0900 || $cp > 0x0D7F) {
            return null;
        }
        if (array_key_exists($cp, self::$scriptCache)) {
            return self::$scriptCache[$cp];
        }
        $found = null;
        foreach (self::SCRIPTS as $key => $cfg) {
            if ($cp >= $cfg[0] && $cp <= $cfg[1]) {
                $found = $key;
                break;
            }
        }
        return self::$scriptCache[$cp] = $found;
    }

    /**
     * Whether any codepoint needs the Indic engine.
     *
     * @param list<int> $cps
     */
    public static function appliesTo(array $cps): bool
    {
        foreach ($cps as $cp) {
            if ($cp >= 0x0900 && $cp <= 0x0D7F && self::scriptOf($cp) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * OpenType script tags to try for the first Indic character of the text (e.g. ['dev2', 'deva']).
     *
     * @param list<int> $cps
     * @return list<string>
     */
    public static function scriptTags(array $cps): array
    {
        foreach ($cps as $cp) {
            $s = self::scriptOf($cp);
            if ($s !== null) {
                return self::SCRIPTS[$s][2];
            }
        }
        return ['DFLT'];
    }

    /**
     * Runs the Indic pipeline: returns the glyph buffer after GSUB (reordered, joiners removed) plus the
     * pre-GSUB item list and the codepoint used for each source index.
     *
     * @param list<int> $cps
     * @param array<string, int> $features user OpenType feature settings
     * @return array{buf: list<array<string, mixed>>, items: list<array<string, mixed>>, used: array<int, int>}
     */
    public static function process(TtfParser $font, ?OpenTypeLayout $layout, array $cps, array $features): array
    {
        $n = count($cps);
        $tags = self::scriptTags($cps);

        /** @var list<array<string, mixed>> $items */
        $items = [];
        $used = [];
        /** @var list<array{0: int, 1: int, 2: string, 3: int, 4: int, 5: int}> $syllables [first source idx, end source idx, script, base source idx, reph flag, rphf script cfg] */
        $syllables = [];
        $sylOf = [];

        $i = 0;
        while ($i < $n) {
            $cp = $cps[$i];
            $script = self::scriptOf($cp);
            if ($script === null) {
                if ($cp === 0x200C || $cp === 0x200D) {
                    $items[] = ['g' => $font->getGlyphId($cp), 's' => $i, 'e' => $i, 'm' => self::M_ALL, 'p' => self::P_START, 'j' => 1];
                } elseif (!self::isIgnorable($cp)) {
                    $gcp = $cp === 0xA0 ? 0x20 : $cp;
                    $items[] = ['g' => $font->getGlyphId($gcp), 's' => $i, 'e' => $i, 'm' => self::M_ALL, 'p' => self::P_START];
                    $used[$i] = $gcp;
                }
                $i++;
                continue;
            }
            $end = self::syllableEnd($cps, $i, $script);
            $sid = count($syllables);
            $info = self::emitSyllable($font, $cps, $i, $end, $script, $items, $used);
            $syllables[] = [$i, $end, $script, $info['base'], $info['reph'] ? 1 : 0, 0];
            for ($k = $i; $k < $end; $k++) {
                $sylOf[$k] = $sid;
            }
            $i = $end;
        }

        $buf = $items;
        if ($layout === null || !$layout->hasTable('GSUB') || $buf === []) {
            return ['buf' => self::dropJoiners($buf), 'items' => $items, 'used' => $used];
        }

        $stageOf = static function (array $spec) use ($layout, $tags): array {
            $stage = [];
            foreach ($spec as $tag => $bit) {
                foreach ($layout->featureLookups('GSUB', $tags, (string) $tag) as $li) {
                    $stage[$li] = ($stage[$li] ?? 0) | $bit;
                }
            }
            ksort($stage);
            return $stage;
        };

        // 1-6: ccmp/locl, then the basic features grouped like HarfBuzz does (lookup order within a group)
        $opt = static fn(string $tag): bool => ($features[$tag] ?? 1) !== 0;
        $groups = [
            ['ccmp' => self::M_ALL, 'locl' => self::M_ALL],
            ['nukt' => self::M_ALL, 'akhn' => self::M_ALL, 'rphf' => self::M_RPHF],
            ['rkrf' => self::M_ALL, 'pref' => self::M_ALL],
            ['blwf' => self::M_ALL],
            ['abvf' => self::M_ALL, 'half' => self::M_PRE],
            ['pstf' => self::M_ALL],
            ['vatu' => self::M_ALL, 'cjct' => self::M_PRE],
        ];
        foreach ($groups as $gi => $spec) {
            $spec = array_filter($spec, static fn(int $bit, string $tag): bool => $opt($tag), ARRAY_FILTER_USE_BOTH);
            $stage = $stageOf($spec);
            if ($stage !== []) {
                $buf = $layout->applyGsub($buf, [$stage]);
            }
            if ($gi === 1) {
                $buf = self::flagReph($buf, $syllables, $items);
            }
        }

        // final reordering
        $buf = self::finalReorder($buf, $syllables, $sylOf, $font);

        // presentation forms, then default substitutions and user features
        $stages = [];
        $pres = $stageOf(array_fill_keys(array_filter(self::PRESENTATION, $opt), self::M_ALL));
        if ($pres !== []) {
            $stages[] = $pres;
        }
        $rest = [];
        foreach (['calt', 'clig', 'rclt', 'liga', 'rlig'] as $tag) {
            if ($opt($tag)) {
                $rest[$tag] = self::M_ALL;
            }
        }
        foreach ($features as $tag => $value) {
            if ($value > 0 && !in_array((string) $tag, array_merge(self::PRESENTATION, ['calt', 'clig', 'rclt', 'liga', 'rlig', 'kern', 'mark', 'mkmk', 'dist', 'abvm', 'blwm', 'curs']), true)) {
                $rest[(string) $tag] = self::M_ALL;
            }
        }
        $restStage = $stageOf($rest);
        if ($restStage !== []) {
            $stages[] = $restStage;
        }
        if ($stages !== []) {
            $buf = $layout->applyGsub($buf, $stages);
        }

        return ['buf' => self::dropJoiners($buf), 'items' => $items, 'used' => $used];
    }

    /**
     * @param list<array<string, mixed>> $buf
     * @return list<array<string, mixed>>
     */
    private static function dropJoiners(array $buf): array
    {
        $out = [];
        foreach ($buf as $it) {
            if (empty($it['j'])) {
                $out[] = $it;
            }
        }
        return $out;
    }

    private static function isIgnorable(int $cp): bool
    {
        return $cp === 0x00AD || $cp === 0x200B || $cp === 0x200E || $cp === 0x200F || $cp === 0x2060 || $cp === 0xFEFF;
    }

    /**
     * Character category within a script.
     */
    private static function category(int $cp, string $script): int
    {
        if ($cp === 0x200D) {
            return self::C_ZWJ;
        }
        if ($cp === 0x200C) {
            return self::C_ZWNJ;
        }
        $cfg = self::SCRIPTS[$script];
        if ($cp < $cfg[0] || $cp > $cfg[1]) {
            return self::C_X;
        }
        $o = $cp - $cfg[0];
        if ($o === 0x4D) {
            return self::C_H;
        }
        if ($o === 0x3C && $cfg[10]) {
            return self::C_N;
        }
        if ($o <= 0x03) {
            return $script === 'taml' && $o < 0x02 ? self::C_X : self::C_SM;
        }
        if ($o >= 0x15 && $o <= 0x39) {
            return $o === $cfg[4] ? self::C_RA : self::C_C;
        }
        if ($o >= 0x58 && $o <= 0x5F && $script !== 'taml' && $script !== 'telu' && $script !== 'knda' && $script !== 'mlym') {
            return self::C_C; // nukta-composed consonants
        }
        if (($o >= 0x05 && $o <= 0x14) || $o === 0x60 || $o === 0x61 || ($script === 'deva' && $o >= 0x72 && $o <= 0x77)) {
            return self::C_V;
        }
        if (($o >= 0x3E && $o <= 0x4C) || ($o >= 0x4E && $o <= 0x57) || $o === 0x62 || $o === 0x63 || $o === 0x3A || $o === 0x3B) {
            if ($script === 'deva' && ($o === 0x51 || $o === 0x52 || $o === 0x53 || $o === 0x54)) {
                return self::C_SM; // Vedic accents
            }
            return self::C_M;
        }
        if ($script === 'deva' && ($o === 0x51 || $o === 0x52 || $o === 0x53 || $o === 0x54)) {
            return self::C_SM;
        }
        return self::C_X;
    }

    /**
     * End (exclusive) of the syllable starting at $i.
     *
     * @param list<int> $cps
     */
    private static function syllableEnd(array $cps, int $i, string $script): int
    {
        $n = count($cps);
        $cat = static fn(int $k): int => $k < $n ? self::category($cps[$k], $script) : self::C_X;
        $inScript = static function (int $k) use ($cps, $n, $script): bool {
            return $k < $n && ($cps[$k] === 0x200C || $cps[$k] === 0x200D || self::scriptOf($cps[$k]) === $script);
        };

        $j = $i;
        $c = $cat($j);
        if ($c === self::C_C || $c === self::C_RA) {
            // C N? (H ZWJ?|ZWNJ? C N?)* H?
            while (true) {
                $j++;                                  // the consonant
                if ($cat($j) === self::C_N) {
                    $j++;
                }
                if ($cat($j) === self::C_ZWJ && $cat($j + 1) === self::C_H) {
                    $j++;                              // C ZWJ H: explicit half-form request
                }
                if ($cat($j) === self::C_H) {
                    $k = $j + 1;
                    if ($cat($k) === self::C_ZWNJ || $cat($k) === self::C_ZWJ) {
                        $k++;
                    }
                    $nc = $cat($k);
                    if ($nc === self::C_C || $nc === self::C_RA) {
                        $j = $k;
                        continue;                      // next consonant of the cluster
                    }
                    $j = $k;                           // trailing halant (with joiner)
                }
                break;
            }
        } elseif ($c === self::C_V) {
            $j++;
            if ($cat($j) === self::C_N) {
                $j++;
            }
            if ($cat($j) === self::C_ZWJ && $cat($j + 1) === self::C_H) {
                $j += 2;
                while ($cat($j) === self::C_C || $cat($j) === self::C_RA) {
                    $j++;
                    if ($cat($j) === self::C_H && ($cat($j + 1) === self::C_C || $cat($j + 1) === self::C_RA)) {
                        $j++;
                    }
                }
            }
        } else {
            return $i + 1;
        }
        // dependent vowel signs, nukta, modifiers, joiners after the base
        while ($j < $n && $inScript($j)) {
            $cc = $cat($j);
            if ($cc === self::C_M || $cc === self::C_SM || $cc === self::C_N || $cc === self::C_ZWJ || $cc === self::C_ZWNJ) {
                $j++;
            } elseif ($cc === self::C_H && $j > $i && $cat($j - 1) === self::C_M) {
                $j++;                                  // halant after a vowel sign (rare, e.g. Malayalam chillu forms)
            } else {
                break;
            }
        }
        return $j;
    }

    /**
     * Appends the (reordered) items of one syllable.
     *
     * @param list<int> $cps
     * @param list<array<string, mixed>> $items
     * @param array<int, int> $used
     * @return array{base: int, reph: bool}
     */
    private static function emitSyllable(TtfParser $font, array $cps, int $start, int $end, string $script, array &$items, array &$used): array
    {
        $cfg = self::SCRIPTS[$script];
        $b = $cfg[0];

        // characters of the syllable, with split vowel signs expanded
        /** @var list<array{cp: int, idx: int, cat: int, pos: int, nt: bool}> $ch */
        $ch = [];
        for ($k = $start; $k < $end; $k++) {
            $cp = $cps[$k];
            $cat = self::category($cp, $script);
            if (isset(self::SPLIT_VOWELS[$cp])) {
                [$a, $z] = self::SPLIT_VOWELS[$cp];
                $ch[] = ['cp' => $a, 'idx' => $k, 'cat' => self::C_M, 'pos' => self::P_START, 'nt' => false];
                $ch[] = ['cp' => $z, 'idx' => $k, 'cat' => self::C_M, 'pos' => self::P_START, 'nt' => true];
                continue;
            }
            $ch[] = ['cp' => $cp, 'idx' => $k, 'cat' => $cat, 'pos' => self::P_START, 'nt' => false];
        }
        $m = count($ch);

        // reph: Ra H at the start followed by something that is not a joiner
        $reph = $m >= 3 && $ch[0]['cat'] === self::C_RA && $ch[1]['cat'] === self::C_H
            && $ch[2]['cat'] !== self::C_ZWJ && $ch[2]['cat'] !== self::C_ZWNJ
            && ($ch[2]['cat'] === self::C_C || $ch[2]['cat'] === self::C_RA || $ch[2]['cat'] === self::C_V);
        $limit = $reph ? 2 : 0;

        $below = $post = [];
        foreach ($cfg[5] as $o) {
            $below[$b + $o] = true;
        }
        foreach ($cfg[6] as $o) {
            $post[$b + $o] = true;
        }

        // base consonant: the last consonant that has no below-base / post-base form
        $base = -1;
        $first = $ch[0]['cat'];
        if ($first === self::C_V) {
            $base = 0;
        } else {
            $seenBelow = false;
            for ($k = $m - 1; $k >= $limit; $k--) {
                $cat = $ch[$k]['cat'];
                if ($cat === self::C_C || $cat === self::C_RA) {
                    $isBelow = isset($below[$ch[$k]['cp']]) && $k > $limit;
                    $isPost = isset($post[$ch[$k]['cp']]) && $k > $limit;
                    if (!$isBelow && (!$isPost || $seenBelow)) {
                        $base = $k;
                        break;
                    }
                    if ($isBelow) {
                        $seenBelow = true;
                    }
                    $base = $k;
                } elseif ($k > $limit && $cat === self::C_ZWJ && $ch[$k - 1]['cat'] === self::C_H) {
                    break;
                }
            }
            if ($base < 0) {
                $base = $limit;
            }
        }

        // position classes
        $lastPos = self::P_AFTER_MAIN;
        for ($k = 0; $k < $m; $k++) {
            $cat = $ch[$k]['cat'];
            $cp = $ch[$k]['cp'];
            if ($reph && $k < 2) {
                $ch[$k]['pos'] = self::P_REPH;
            } elseif ($k < $base) {
                $ch[$k]['pos'] = self::P_PRE_C;
                if ($cat === self::C_M) {
                    $ch[$k]['pos'] = self::matraPosition($cp, $cfg, $b, self::P_PRE_C);
                }
            } elseif ($k === $base) {
                $ch[$k]['pos'] = self::P_BASE;
            } elseif ($cat === self::C_N || $cat === self::C_H || $cat === self::C_ZWJ || $cat === self::C_ZWNJ) {
                $ch[$k]['pos'] = $lastPos;
            } elseif ($cat === self::C_M) {
                $lastPos = self::matraPosition($cp, $cfg, $b, $lastPos);
                $ch[$k]['pos'] = $lastPos;
            } elseif ($cat === self::C_C || $cat === self::C_RA) {
                $lastPos = isset($below[$cp]) ? self::P_BELOW : (isset($post[$cp]) ? self::P_POST : self::P_POST);
                $ch[$k]['pos'] = $lastPos;
            } elseif ($cat === self::C_SM) {
                $ch[$k]['pos'] = self::P_SMVD;
            } else {
                $ch[$k]['pos'] = $lastPos;
            }
        }

        // stable sort by position
        $order = range(0, $m - 1);
        usort($order, static fn(int $x, int $y): int => $ch[$x]['pos'] <=> $ch[$y]['pos']);

        foreach ($order as $k) {
            $c = $ch[$k];
            $mask = self::M_ALL;
            if ($reph && $k < 2) {
                $mask |= self::M_RPHF;
            } elseif ($k < $base) {
                $mask |= self::M_PRE;
            }
            $it = ['g' => $font->getGlyphId($c['cp']), 's' => $c['idx'], 'e' => $c['idx'], 'm' => $mask, 'p' => $c['pos']];
            if ($c['cat'] === self::C_ZWJ || $c['cat'] === self::C_ZWNJ) {
                $it['j'] = 1;
            }
            if ($c['nt']) {
                $it['nt'] = true;
            }
            $items[] = $it;
            if (!$c['nt']) {
                $used[$c['idx']] = $cps[$c['idx']];
            }
        }
        return ['base' => $ch[$base]['idx'], 'reph' => $reph];
    }

    /**
     * Position class of a dependent vowel sign.
     *
     * @param array<int, mixed> $cfg
     */
    private static function matraPosition(int $cp, array $cfg, int $blockStart, int $fallback): int
    {
        $o = $cp - $blockStart;
        if (in_array($o, $cfg[7], true)) {
            return self::P_PRE_M;
        }
        if (in_array($o, $cfg[8], true)) {
            return self::P_ABOVE;
        }
        if (in_array($o, $cfg[9], true)) {
            return self::P_BELOW;
        }
        return self::P_AFTER_POST;
    }

    /**
     * After the rphf stage: marks the first glyph of every syllable whose Ra + virama formed a reph glyph.
     *
     * @param list<array<string, mixed>> $buf
     * @param list<array{0: int, 1: int, 2: string, 3: int, 4: int, 5: int}> $syllables
     * @param list<array<string, mixed>> $items pre-GSUB items (original Ra glyph ids)
     * @return list<array<string, mixed>>
     */
    private static function flagReph(array $buf, array $syllables, array $items): array
    {
        $rephStarts = [];
        foreach ($syllables as $sy) {
            if ($sy[4] === 1) {
                $rephStarts[$sy[0]] = true;
            }
        }
        if ($rephStarts === []) {
            return $buf;
        }
        $origGid = [];
        foreach ($items as $it) {
            if (isset($rephStarts[$it['s']]) && !isset($origGid[$it['s']])) {
                $origGid[$it['s']] = $it['g'];
            }
        }
        foreach ($buf as $k => $it) {
            if (isset($rephStarts[$it['s']]) && ($it['m'] & self::M_RPHF) !== 0 && ($it['g'] !== $origGid[$it['s']] || $it['e'] > $it['s'])) {
                $buf[$k]['rph'] = true;
                unset($rephStarts[$it['s']]);
            }
        }
        return $buf;
    }

    /**
     * Moves formed reph glyphs behind the base (before post-base signs).
     *
     * @param list<array<string, mixed>> $buf
     * @param list<array{0: int, 1: int, 2: string, 3: int, 4: int, 5: int}> $syllables
     * @param array<int, int> $sylOf source index => syllable id
     * @return list<array<string, mixed>>
     */
    private static function finalReorder(array $buf, array $syllables, array $sylOf, TtfParser $font): array
    {
        $count = count($buf);
        $k = 0;
        while ($k < $count) {
            $sid = $sylOf[$buf[$k]['s']] ?? null;
            if ($sid === null) {
                $k++;
                continue;
            }
            $from = $k;
            while ($k < $count && ($sylOf[$buf[$k]['s']] ?? -1) === $sid) {
                $k++;
            }
            $to = $k; // exclusive
            if (empty($buf[$from]['rph']) || $to - $from < 2) {
                continue;
            }
            [, , $script, $baseIdx] = $syllables[$sid];
            $mode = self::SCRIPTS[$script][3];
            $reph = $buf[$from];
            // locate the base glyph (the glyph covering the base character)
            $bi = -1;
            for ($q = $from + 1; $q < $to; $q++) {
                if ($buf[$q]['s'] <= $baseIdx && $buf[$q]['e'] >= $baseIdx) {
                    $bi = $q;
                    break;
                }
            }
            if ($bi < 0) {
                continue;
            }
            $t = $bi;
            if ($mode === 0) {
                while ($t + 1 < $to && ($buf[$t + 1]['p'] ?? 15) <= self::P_AFTER_MAIN) {
                    $t++;
                }
            } elseif ($mode === 1) {
                while ($t + 1 < $to && ($buf[$t + 1]['p'] ?? 15) < self::P_BEFORE_POST) {
                    $t++;
                }
            } else {
                $t = $to - 1;
                while ($t > $bi && ($buf[$t]['p'] ?? 0) === self::P_SMVD) {
                    $t--;
                }
            }
            // move $reph (at $from) to after $t
            array_splice($buf, $from, 1);
            array_splice($buf, $t, 0, [$reph]);
        }
        return $buf;
    }
}
