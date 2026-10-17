<?php

declare(strict_types=1);

namespace IvoPdf\Font;

use IvoPdf\Text\Scripts;

/**
 * Manages font resolution, caching of parsed TrueType metrics, and text measurement.
 */
class FontManager
{
    /** Key offset under which shaped (non-codepoint) glyph ids are exposed in getUsedFontsData() */
    public const SHAPED_KEY_BASE = 0x200000;

    private string $fontDir;

    /** @var array<string, array{regular: string, bold?: string, italic?: string, bold_italic?: string}> */
    private array $families = [];

    /** @var array<string, string> alias => canonical family key */
    private array $aliases = [];

    /** @var array<string, TtfParser> filePath => TtfParser */
    private array $parsedFonts = [];

    /** @var array<string, string> filePath => pdfFontId (e.g. 'F1') */
    private array $usedFonts = [];

    /** @var array<string, array<int, int>> filePath => [codepoint => gid] */
    private array $usedGlyphs = [];

    /** @var array<string, array<int, string>> filePath => [gid => unicode text] for shaped glyphs (ToUnicode) */
    private array $usedGidText = [];

    /** @var array<string, array<int, int>> filePath => [gid => gid] shaped glyph ids */
    private array $usedShapedGids = [];

    /** @var array<string, array{glyphs: ?list<array{0: int, 1: float, 2: float, 3: float, 4: string}>, units: float, count: int}> */
    private array $shapeCache = [];

    /** @var list<string> Families tried (in order) for characters missing from the requested font */
    private array $fallbackFamilies = ['dejavu sans', 'dejavu serif', 'dejavu sans mono'];

    /** @var array<string, string|false> "family|weight|style|cp" => fallback family key (false = none) */
    private array $fallbackCache = [];

    /** @var array<string, string> lower-cased scriptFonts key => family key (see Options::$scriptFonts) */
    private array $scriptFonts = [];

    /** @var (callable(string): void)|null receives "no font covers script X" warnings */
    private $warningSink = null;

    /** @var array<string, true> scripts already reported as uncovered */
    private array $reportedScripts = [];

    public function __construct(string $fontDir)
    {
        $this->fontDir = rtrim($fontDir, '/\\');
        $this->registerBundledFonts();
    }

    private function registerBundledFonts(): void
    {
        // Inter (SIL OFL 1.1) is the default sans-serif face; DejaVu covers the scripts Inter lacks via the fallback chain
        $this->registerFamily('inter', [
            'regular' => $this->fontDir . '/Inter-Regular.ttf',
            'bold' => $this->fontDir . '/Inter-Bold.ttf',
            'italic' => $this->fontDir . '/Inter-Italic.ttf',
            'bold_italic' => $this->fontDir . '/Inter-BoldItalic.ttf',
        ]);

        $this->registerFamily('dejavu sans', [
            'regular' => $this->fontDir . '/DejaVuSans.ttf',
            'bold' => $this->fontDir . '/DejaVuSans-Bold.ttf',
            'italic' => $this->fontDir . '/DejaVuSans-Oblique.ttf',
            'bold_italic' => $this->fontDir . '/DejaVuSans-BoldOblique.ttf',
        ]);

        $this->registerFamily('dejavu serif', [
            'regular' => $this->fontDir . '/DejaVuSerif.ttf',
            'bold' => $this->fontDir . '/DejaVuSerif-Bold.ttf',
            'italic' => $this->fontDir . '/DejaVuSerif-Italic.ttf',
            'bold_italic' => $this->fontDir . '/DejaVuSerif-BoldItalic.ttf',
        ]);

        $this->registerFamily('dejavu sans mono', [
            'regular' => $this->fontDir . '/DejaVuSansMono.ttf',
            'bold' => $this->fontDir . '/DejaVuSansMono-Bold.ttf',
            'italic' => $this->fontDir . '/DejaVuSansMono-Oblique.ttf',
            'bold_italic' => $this->fontDir . '/DejaVuSansMono-BoldOblique.ttf',
        ]);

        // Common web & CSS generic font aliases mapped to bundled Unicode/Cyrillic TTFs
        $sansAliases = [
            'sans-serif', 'sans', 'system-ui', '-apple-system', 'blinkmacsystemfont',
            'segoe ui', 'roboto', 'helvetica', 'helvetica neue', 'arial',
            'verdana', 'tahoma', 'trebuchet ms', 'open sans', 'lato', 'montserrat',
            'nunito', 'poppins', 'ubuntu', 'noto sans', 'inter display', 'inter var',
        ];
        foreach ($sansAliases as $alias) {
            $this->aliases[$alias] = 'inter';
        }
        $this->aliases['dejavusans'] = 'dejavu sans';

        $serifAliases = [
            'serif', 'times', 'times new roman', 'georgia', 'garamond',
            'palatino', 'bookman', 'cambria', 'noto serif', 'playfair display',
            'merriweather', 'pt serif', 'dejavuserif',
        ];
        foreach ($serifAliases as $alias) {
            $this->aliases[$alias] = 'dejavu serif';
        }

        $monoAliases = [
            'monospace', 'mono', 'courier', 'courier new', 'consolas',
            'monaco', 'menlo', 'fira code', 'jetbrains mono', 'source code pro',
            'lucida console', 'dejavusansmono',
        ];
        foreach ($monoAliases as $alias) {
            $this->aliases[$alias] = 'dejavu sans mono';
        }

        // Liberation (SIL OFL 1.1) is metric-compatible with Arial / Times New Roman / Courier New, so templates
        // written for those fonts keep their line breaks and page counts
        foreach (['sans' => 'Sans', 'serif' => 'Serif', 'mono' => 'Mono'] as $key => $file) {
            $this->registerFamily('liberation ' . $key, [
                'regular' => $this->fontDir . "/Liberation{$file}-Regular.ttf",
                'bold' => $this->fontDir . "/Liberation{$file}-Bold.ttf",
                'italic' => $this->fontDir . "/Liberation{$file}-Italic.ttf",
                'bold_italic' => $this->fontDir . "/Liberation{$file}-BoldItalic.ttf",
            ]);
        }
        $metricAliases = [
            'arial' => 'liberation sans', 'helvetica' => 'liberation sans', 'helvetica neue' => 'liberation sans',
            'arimo' => 'liberation sans', 'liberationsans' => 'liberation sans',
            'times' => 'liberation serif', 'times new roman' => 'liberation serif', 'tinos' => 'liberation serif',
            'liberationserif' => 'liberation serif',
            'courier' => 'liberation mono', 'courier new' => 'liberation mono', 'cousine' => 'liberation mono',
            'liberationmono' => 'liberation mono',
        ];
        foreach ($metricAliases as $alias => $family) {
            $this->aliases[$alias] = $family;
        }

        // Sofia Sans (SIL OFL 1.1, Lettersoup): Bulgarian Cyrillic forms by default (Russian forms with lang="ru")
        foreach (['sofia sans' => 'SofiaSans', 'sofia sans semi condensed' => 'SofiaSansSemiCondensed'] as $family => $file) {
            $this->registerFamily($family, [
                'regular' => $this->fontDir . "/{$file}-Regular.ttf",
                'bold' => $this->fontDir . "/{$file}-Bold.ttf",
                'italic' => $this->fontDir . "/{$file}-Italic.ttf",
                'bold_italic' => $this->fontDir . "/{$file}-BoldItalic.ttf",
            ]);
            $this->aliases[str_replace(' ', '', $family)] = $family;
        }
    }

    /**
     * Registers a custom TrueType font family.
     *
     * @param array{regular: string, bold?: string, italic?: string, bold_italic?: string} $variants
     */
    public function registerFamily(string $familyName, array $variants): void
    {
        $this->unitMetricsCache = []; // ex / ch of an earlier fallback must not outlive the registration
        $this->lineMetricsCache = [];
        $key = strtolower(trim($familyName, " \t\n\r\0\x0B'\""));
        if (!isset($variants['regular'])) {
            $variants['regular'] = $variants['bold'] ?? $variants['italic'] ?? $variants['bold_italic'] ?? '';
        }
        $this->families[$key] = $variants;
        $this->aliases[$key] = $key;
        $this->fallbackCache = [];
    }

    /**
     * Registers (or replaces) a single variant ('regular', 'bold', 'italic', 'bold_italic') of a family, e.g. from @font-face.
     */
    /** @var array<string, string> "memory:<sha1>" => font bytes of fonts that never touch the filesystem (data: URIs, downloads) */
    private array $memoryFonts = [];

    /**
     * Registers a font that exists only in memory (an @font-face data: URI or a downloaded file).
     *
     * @throws \RuntimeException when the bytes are not a usable TrueType/OpenType font
     */
    public function registerFontData(string $familyName, string $variant, string $bytes): void
    {
        $this->unitMetricsCache = [];
        $this->lineMetricsCache = [];
        $key = 'memory:' . sha1($bytes);
        if (!isset($this->parsedFonts[$key])) {
            $this->parsedFonts[$key] = new TtfParser($key, $bytes); // validates the font before it is registered
        }
        $this->memoryFonts[$key] = $bytes;
        $this->registerFontVariant($familyName, $variant, $key);
    }

    public function registerFontVariant(string $familyName, string $variant, string $filePath): void
    {
        $this->unitMetricsCache = [];
        $this->lineMetricsCache = [];
        $key = strtolower(trim($familyName, " \t\n\r\0\x0B'\""));
        $variants = $this->families[$key] ?? [];
        $variants[$variant] = $filePath;
        $this->registerFamily($key, $variants);
    }

    public function hasFamily(string $familyName): bool
    {
        return isset($this->families[strtolower(trim($familyName, " \t\n\r\0\x0B'\""))]);
    }

    /**
     * Adds a family (registered via registerFamily) to the front of the fallback chain used for missing glyphs
     * (e.g. a CJK, Arabic or emoji font).
     */
    public function addFallbackFamily(string $familyName): void
    {
        $key = strtolower(trim($familyName, " \t\n\r\0\x0B'\""));
        $this->fallbackFamilies = array_values(array_unique(array_merge([$key], $this->fallbackFamilies)));
        $this->fallbackCache = [];
    }

    /**
     * Returns the family that should render $codepoint when the requested font lacks it, or null when
     * the requested font has the glyph (or no fallback has it either).
     */
    public function findFallbackFamily(string $fontFamilyCss, string|int $fontWeight, string $fontStyle, int $codepoint, string $lang = ''): ?string
    {
        $cacheKey = $fontFamilyCss . '|' . $fontWeight . '|' . $fontStyle . '|' . $codepoint . '|' . $lang;
        if (isset($this->fallbackCache[$cacheKey])) {
            return $this->fallbackCache[$cacheKey] === false ? null : $this->fallbackCache[$cacheKey];
        }
        $result = null;
        $primary = $this->resolveFont($fontFamilyCss, $fontWeight, $fontStyle);
        if (!$primary->hasGlyph($codepoint)) {
            $result = $this->findScriptFont($primary, $fontWeight, $fontStyle, $codepoint, $lang);
            foreach ($result !== null ? [] : $this->fallbackFamilies as $family) {
                if (!isset($this->families[$family])) {
                    continue;
                }
                $candidate = $this->resolveFont($family, $fontWeight, $fontStyle);
                if ($candidate !== $primary && $candidate->hasGlyph($codepoint)) {
                    $result = $family;
                    break;
                }
            }
            if ($result === null) {
                $this->reportUncovered($codepoint);
            }
        }
        $this->fallbackCache[$cacheKey] = $result ?? false;
        return $result;
    }

    /**
     * Sets the per-script font map (Options::$scriptFonts) and the sink for "script not covered" warnings.
     * Values may be registered family names or font file paths (registered on the fly, "file.ttc#1" selects a face).
     *
     * @param array<string, string> $map
     * @param (callable(string): void)|null $warningSink
     */
    public function setScriptFonts(array $map, ?callable $warningSink = null): void
    {
        $this->scriptFonts = [];
        foreach ($map as $key => $family) {
            $family = trim((string) $family);
            if ($family === '') {
                continue;
            }
            $fk = strtolower(trim($family, " \t\n\r\0\x0B'\""));
            if (!isset($this->families[$fk]) && is_file(TtcExtractor::splitReference($family)[0])) {
                $this->registerFamily($family, ['regular' => $family]);
            }
            $this->scriptFonts[strtolower((string) $key)] = $fk;
        }
        $this->warningSink = $warningSink;
        $this->reportedScripts = [];
        $this->fallbackCache = [];
    }

    /**
     * Family from the script font map covering $codepoint (language specific keys first), or null.
     */
    private function findScriptFont(TtfParser $primary, string|int $fontWeight, string $fontStyle, int $codepoint, string $lang): ?string
    {
        if ($this->scriptFonts === []) {
            return null;
        }
        foreach (Scripts::fontKeys(Scripts::of($codepoint), $lang) as $key) {
            $family = $this->scriptFonts[strtolower($key)] ?? null;
            if ($family === null || !isset($this->families[$family])) {
                continue;
            }
            $candidate = $this->resolveFont($family, $fontWeight, $fontStyle);
            if ($candidate !== $primary && $candidate->hasGlyph($codepoint)) {
                return $family;
            }
        }
        return null;
    }

    /**
     * Records (once per script) that no registered font has a glyph for a character.
     */
    private function reportUncovered(int $codepoint): void
    {
        if ($codepoint < 0x0250 || $this->warningSink === null) {
            return;
        }
        $script = Scripts::of($codepoint);
        if (!Scripts::needsFont($script) || isset($this->reportedScripts[$script])) {
            return;
        }
        $this->reportedScripts[$script] = true;
        ($this->warningSink)(sprintf(
            'No registered font has glyphs for %s text (e.g. U+%04X): register one with registerFontFamily() and the scriptFonts / fallbackFonts options (e.g. a Noto Sans font for %s).',
            Scripts::name($script),
            $codepoint,
            Scripts::name($script)
        ));
    }

    /**
     * Resolves a CSS font-family list, font-weight, and font-style to a TtfParser instance.
     */
    public function resolveFont(string $fontFamilyCss, string|int $fontWeight = 'normal', string $fontStyle = 'normal'): TtfParser
    {
        $candidates = explode(',', $fontFamilyCss);
        $resolvedKey = 'inter';

        foreach ($candidates as $candidate) {
            $clean = strtolower(trim($candidate, " \t\n\r\0\x0B'\""));
            if ($clean === '') {
                continue;
            }
            if (isset($this->families[$clean])) {
                $resolvedKey = $clean;
                break;
            }
            if (isset($this->aliases[$clean])) {
                $resolvedKey = $this->aliases[$clean];
                break;
            }
        }

        $variants = $this->families[$resolvedKey] ?? $this->families['inter'];
        if (($variants['regular'] ?? '') === '') {
            $variants = $this->families['dejavu sans'];
        }
        $isBold = $this->isBoldWeight($fontWeight);
        $isItalic = in_array(strtolower(trim($fontStyle)), ['italic', 'oblique'], true);

        if ($isBold && $isItalic) {
            $filePath = $variants['bold_italic'] ?? $variants['bold'] ?? $variants['italic'] ?? $variants['regular'];
        } elseif ($isBold) {
            $filePath = $variants['bold'] ?? $variants['regular'];
        } elseif ($isItalic) {
            $filePath = $variants['italic'] ?? $variants['regular'];
        } else {
            $filePath = $variants['regular'];
        }

        if (!isset($this->memoryFonts[$filePath]) && !is_file(TtcExtractor::splitReference($filePath)[0])) {
            $fallbackVariants = $this->families['dejavu sans'];
            if ($isBold && $isItalic) {
                $filePath = $fallbackVariants['bold_italic'] ?? $fallbackVariants['regular'];
            } elseif ($isBold) {
                $filePath = $fallbackVariants['bold'] ?? $fallbackVariants['regular'];
            } elseif ($isItalic) {
                $filePath = $fallbackVariants['italic'] ?? $fallbackVariants['regular'];
            } else {
                $filePath = $fallbackVariants['regular'];
            }
        }

        if (!isset($this->parsedFonts[$filePath])) {
            try {
                $this->parsedFonts[$filePath] = new TtfParser($filePath, $this->memoryFonts[$filePath] ?? null);
            } catch (\Throwable) {
                $fallbackPath = $this->families['dejavu sans']['regular'];
                if (!isset($this->parsedFonts[$fallbackPath])) {
                    $this->parsedFonts[$fallbackPath] = new TtfParser($fallbackPath);
                }
                return $this->parsedFonts[$fallbackPath];
            }
        }

        return $this->parsedFonts[$filePath];
    }

    /**
     * Marks a font as used in the PDF and returns its PDF resource ID (e.g. 'F1').
     */
    public function markFontUsed(TtfParser $font): string
    {
        $path = $font->getFilePath();
        if (!isset($this->usedFonts[$path])) {
            $id = 'F' . (count($this->usedFonts) + 1);
            $this->usedFonts[$path] = $id;
            $this->usedGlyphs[$path] = [0 => 0];
        }
        return $this->usedFonts[$path];
    }

    /**
     * Records characters used in a string for a specific font and returns the hex-encoded GID stream for PDF Tj.
     */
    public function encodeTextToHexGids(TtfParser $font, string $text): string
    {
        $this->markFontUsed($font);
        $path = $font->getFilePath();
        $codepoints = self::utf8ToCodepoints($text);
        $hex = '';

        foreach ($codepoints as $cp) {
            if ($cp === 0x200B || $cp === 0xFEFF || $cp === 0x00AD) {
                continue;
            }
            if ($cp === 0x00A0) {
                $cp = 0x0020;
            }
            $gid = $font->getGlyphId($cp);
            $this->usedGlyphs[$path][$cp] = $gid;
            $hex .= sprintf('%04X', $font->pdfCid($gid));
        }

        return $hex;
    }

    /** @var array<string, array{0: float, 1: float}> */
    private array $unitMetricsCache = [];

    /** @var array<string, array{0: float, 1: float, 2: float}> */
    private array $lineMetricsCache = [];

    /**
     * `line-height: normal` in pt, as browsers compute it: the font's ascent + descent + line gap (hhea, or the
     * OS/2 typo values under USE_TYPO_METRICS), each rounded to whole CSS pixels like Blink does.
     */
    public function normalLineHeight(string $fontFamily, string|int $fontWeight, string $fontStyle, float $fontSizePt): float
    {
        $key = $fontFamily . '|' . $fontWeight . '|' . $fontStyle;
        if (!isset($this->lineMetricsCache[$key])) {
            $m = [0.928, 0.236, 0.0];
            try {
                $font = $this->resolveFont($fontFamily, $fontWeight, $fontStyle);
                if ($font->ascent > 0) {
                    $m = [$font->ascent / 1000.0, abs($font->descent) / 1000.0, max(0, $font->lineGap) / 1000.0];
                }
            } catch (\Throwable) {
                // keep the DejaVu-like fallback
            }
            $this->lineMetricsCache[$key] = $m;
        }
        [$a, $d, $g] = $this->lineMetricsCache[$key];
        $px = $fontSizePt * 4.0 / 3.0;
        return (round($a * $px) + round($d * $px) + round($g * $px)) * 0.75;
    }

    /**
     * The CSS `ex` and `ch` units of a font as fractions of its font size: the x-height (OS/2 sxHeight) and the
     * advance width of the digit zero. Falls back to 0.5 em when the font does not provide the value.
     *
     * @return array{0: float, 1: float} [ex, ch]
     */
    public function fontRelativeUnits(string $fontFamily, string|int $fontWeight = 'normal', string $fontStyle = 'normal'): array
    {
        $key = $fontFamily . '|' . $fontWeight . '|' . $fontStyle;
        if (isset($this->unitMetricsCache[$key])) {
            return $this->unitMetricsCache[$key];
        }
        $ex = 0.5;
        $ch = 0.5;
        try {
            $font = $this->resolveFont($fontFamily, $fontWeight, $fontStyle);
            if ($font->xHeight > 50 && $font->xHeight < 1000) {
                $ex = $font->xHeight / 1000.0;
            }
            $zero = $font->getCharWidth(0x30);
            if ($zero > 50 && $zero < 2000) {
                $ch = $zero / 1000.0;
            }
        } catch (\Throwable) {
            // keep the 0.5 em fallbacks
        }
        return $this->unitMetricsCache[$key] = [$ex, $ch];
    }

    /**
     * Measures the width of a UTF-8 string in PDF points (pt).
     */
    public function measureText(
        string $text,
        string $fontFamily,
        string|int $fontWeight,
        string $fontStyle,
        float $fontSizePt,
        float $letterSpacingPt = 0.0
    ): float {
        if ($text === '') {
            return 0.0;
        }
        $font = $this->resolveFont($fontFamily, $fontWeight, $fontStyle);
        $shaped = $this->shapeWith($font, $text, [], false);
        $widthPt = ($shaped['units'] / 1000.0) * $fontSizePt;
        // CSS letter-spacing is applied after every character (matching the PDF Tc operator)
        if ($shaped['count'] > 0 && abs($letterSpacingPt) > 0.001) {
            $widthPt += $shaped['count'] * $letterSpacingPt;
        }

        return $widthPt;
    }

    /**
     * @return array<string, array{id: string, parser: TtfParser, glyphs: array<int, int>, gidText: array<int, string>}>
     *         glyphs maps codepoint => gid; gids produced by shaping are added under keys >= SHAPED_KEY_BASE (no codepoint);
     *         gidText maps gid => Unicode text for shaped glyphs (ligatures, contextual forms)
     */
    public function getUsedFontsData(): array
    {
        $result = [];
        foreach ($this->usedFonts as $path => $id) {
            $glyphs = $this->usedGlyphs[$path] ?? [0 => 0];
            foreach ($this->usedShapedGids[$path] ?? [] as $gid) {
                $glyphs[self::SHAPED_KEY_BASE + $gid] = $gid;
            }
            $result[$path] = [
                'id' => $id,
                'parser' => $this->parsedFonts[$path],
                'glyphs' => $glyphs,
                'gidText' => $this->usedGidText[$path] ?? [],
            ];
        }
        return $result;
    }

    public function resetUsedFonts(): void
    {
        $this->usedFonts = [];
        $this->usedGlyphs = [];
        $this->usedGidText = [];
        $this->usedShapedGids = [];
    }

    /**
     * Shapes a UTF-8 string (GSUB/GPOS/kern, Arabic joining, optional RTL reversal) with the font resolved from CSS values.
     *
     * @param array<string, int> $features OpenType feature tag => value (0 disables)
     * @return array{glyphs: ?list<array{0: int, 1: float, 2: float, 3: float, 4: string}>, units: float, count: int}
     *         glyphs is null when plain codepoint->glyph encoding is exact; units = total advance in 1/1000 em
     */
    public function shape(string $text, string $fontFamily, string|int $fontWeight, string $fontStyle, array $features = [], bool $rtl = false): array
    {
        return $this->shapeWith($this->resolveFont($fontFamily, $fontWeight, $fontStyle), $text, $features, $rtl);
    }

    /**
     * @param array<string, int> $features
     * @return array{glyphs: ?list<array{0: int, 1: float, 2: float, 3: float, 4: string}>, units: float, count: int}
     */
    public function shapeWith(TtfParser $font, string $text, array $features = [], bool $rtl = false): array
    {
        if ($text === '') {
            return ['glyphs' => null, 'units' => 0.0, 'count' => 0];
        }
        $key = $font->getFilePath() . "\0" . FontFeatures::key($features) . ($rtl ? "\1" : "\2") . $text;
        if (isset($this->shapeCache[$key])) {
            return $this->shapeCache[$key];
        }
        if (count($this->shapeCache) > 30000) {
            $this->shapeCache = [];
        }
        return $this->shapeCache[$key] = TextShaper::shape($font, self::utf8ToCodepoints($text), $features, $rtl);
    }

    /**
     * Records shaped glyphs as used and returns PDF text-showing operators (TJ with kerning/offset adjustments
     * and Ts rises) to follow a Td, e.g. "[<0041> -80 <0056>] TJ".
     *
     * With $actualText (logical text of reordered glyphs, e.g. Indic) the first glyph is wrapped in a marked-content
     * span carrying /ActualText and the remaining glyphs in an empty one, which MuPDF, pdf.js and Acrobat all
     * extract as the logical text.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: string}> $glyphs
     */
    public function encodeShapedGlyphs(TtfParser $font, array $glyphs, float $fontSizePt, ?string $actualText = null): string
    {
        $this->markFontUsed($font);
        $path = $font->getFilePath();
        $ops = '';
        $arr = '';
        $carry = 0.0;
        $rise = 0.0;
        $fmt = static fn(float $v): string => rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        $span = static fn(string $t): string => '/Span << /ActualText <' . strtoupper(bin2hex("\xFE\xFF" . (string) mb_convert_encoding($t, 'UTF-16BE', 'UTF-8'))) . '> >> BDC ';
        // The glyph carrying the full text: the first one whose own text differs from the cluster's last character
        // (MuPDF ignores an ActualText that merely repeats the glyph's own ToUnicode text)
        $carrier = 0;
        if ($actualText !== null) {
            $lastChar = mb_substr($actualText, -1, 1, 'UTF-8');
            foreach ($glyphs as $k => $g) {
                if ($g[4] !== $lastChar) {
                    $carrier = $k;
                    break;
                }
            }
        }
        $glyphIndex = 0;
        foreach ($glyphs as [$gid, $adv, $dx, $dy, $text]) {
            if ($actualText !== null) {
                $gi = $glyphIndex++;
                if ($gi === 0 || $gi === $carrier || $gi === $carrier + 1) {
                    // span boundary: flush pending glyphs, close the previous span, open the next one
                    if ($arr !== '') {
                        $ops .= '[' . $arr . '] TJ ';
                        $arr = '';
                    }
                    if ($gi > 0) {
                        $ops .= 'EMC ';
                    }
                    $ops .= $span($gi === $carrier ? $actualText : '');
                }
            }
            $this->usedShapedGids[$path][$gid] = $gid;
            if ($text !== '' && !isset($this->usedGidText[$path][$gid])) {
                $this->usedGidText[$path][$gid] = $text;
            }
            if (abs($dy - $rise) > 0.01) {
                if ($arr !== '') {
                    $ops .= '[' . $arr . '] TJ ';
                    $arr = '';
                }
                $ops .= sprintf('%.3F Ts ', $dy / 1000.0 * $fontSizePt);
                $rise = $dy;
            }
            $before = $carry - $dx;
            if (abs($before) > 0.01) {
                $arr .= $fmt($before) . ' ';
            }
            $arr .= sprintf('<%04X>', $font->pdfCid($gid));
            $carry = $dx + $font->getGlyphWidthByGid($gid) - $adv;
        }
        if ($arr !== '') {
            $ops .= '[' . $arr . '] TJ';
        }
        if ($rise !== 0.0) {
            $ops .= ' 0 Ts';
        }
        if ($actualText !== null) {
            $ops .= ' EMC';
        }
        return $ops;
    }

    public function isBoldWeight(string|int $weight): bool
    {
        if (is_int($weight) || is_numeric($weight)) {
            return (int) $weight >= 600;
        }
        $w = strtolower(trim((string) $weight));
        return in_array($w, ['bold', 'bolder', 'semibold', 'extrabold', 'black'], true);
    }

    /**
     * Fast conversion of UTF-8 string into an array of Unicode codepoints.
     *
     * @return int[]
     */
    public static function utf8ToCodepoints(string $str): array
    {
        if ($str === '') {
            return [];
        }
        $utf32 = @mb_convert_encoding($str, 'UTF-32BE', 'UTF-8');
        if ($utf32 === false || $utf32 === '') {
            return [];
        }
        $unpacked = unpack('N*', $utf32);
        return $unpacked ? array_values($unpacked) : [];
    }
}
