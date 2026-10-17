<?php

declare(strict_types=1);

namespace IvoPdf\Compat\Dompdf;

use IvoPdf\Font\FontManager;

/**
 * Stand-in for `Dompdf\FontMetrics`. A "font" is an opaque descriptor string (`family|weight|style`) that
 * Canvas::page_text() understands; text widths come from the engine's own font manager.
 */
final class FontMetrics
{
    private ?FontManager $fonts = null;

    public function __construct(private ?Options $options = null)
    {
    }

    public function setOptions(Options $options): void
    {
        $this->options = $options;
    }

    /** @return string font descriptor usable as the `$font` argument of the canvas methods */
    public function getFont(?string $family = null, string $subtype = 'normal'): string
    {
        $family = $family === null || $family === '' ? (string) ($this->options?->get('defaultFont') ?? 'serif') : $family;
        $subtype = strtolower($subtype ?: 'normal');
        $weight = str_contains($subtype, 'bold') ? 'bold' : 'normal';
        $style = (str_contains($subtype, 'italic') || str_contains($subtype, 'oblique')) ? 'italic' : 'normal';
        return $family . '|' . $weight . '|' . $style;
    }

    /**
     * dompdf: registers a font file under a family (`$style` = ['family' => .., 'weight' => 'bold', 'style' => 'italic']).
     * The font is added to a pending list that the engine registers before rendering.
     *
     * @param array<string, string>|string $style
     */
    public function registerFont($style, string $remoteFile, $context = null): bool
    {
        $style = is_array($style) ? $style : ['family' => (string) $style];
        $family = (string) ($style['family'] ?? '');
        if ($family === '' || \IvoPdf\Options::isNetworkPath($remoteFile) || !is_file($remoteFile)) {
            return false;
        }
        // like dompdf (php-font-lib), a file that is not a TrueType / OpenType font (collection) is refused
        set_error_handler(static fn(): bool => true); // an unreadable file is refused without a PHP warning
        try {
            $fh = fopen($remoteFile, 'rb');
            $magic = $fh !== false ? (string) fread($fh, 4) : '';
            if ($fh !== false) {
                fclose($fh);
            }
        } finally {
            restore_error_handler();
        }
        if (!in_array($magic, ["\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf'], true)) {
            return false;
        }
        $bold = str_contains(strtolower((string) ($style['weight'] ?? 'normal')), 'bold') || (int) ($style['weight'] ?? 400) >= 600;
        $italic = in_array(strtolower((string) ($style['style'] ?? 'normal')), ['italic', 'oblique'], true);
        $variant = $bold ? ($italic ? 'bold_italic' : 'bold') : ($italic ? 'italic' : 'regular');
        self::$registered[strtolower($family)][$variant] = $remoteFile;
        if ($this->fonts !== null) {
            $this->fonts->registerFontVariant($family, $variant, $remoteFile);
        }
        return true;
    }

    /** @param array<string, string>|string $style */
    public function register_font($style, string $remote_file, $context = null): bool
    {
        return $this->registerFont($style, $remote_file, $context);
    }

    /**
     * dompdf: the font files of a family as ['normal' => file, 'bold' => .., 'italic' => .., 'bold_italic' => ..]
     * (registered and bundled families, CSS aliases such as "arial" resolve to the bundled face), or null.
     *
     * @return array<string, string>|null
     */
    public function getFamily($family): ?array
    {
        $key = str_replace(["'", '"'], '', mb_strtolower(trim((string) $family), 'UTF-8'));
        $variants = self::$registered[$key] ?? $this->manager()->getFamilyVariants($key);
        if ($variants === null || $variants === []) {
            return null;
        }
        $out = [];
        foreach (['regular' => 'normal', 'bold' => 'bold', 'italic' => 'italic', 'bold_italic' => 'bold_italic'] as $ivo => $dompdf) {
            if (isset($variants[$ivo]) && $variants[$ivo] !== '') {
                $out[$dompdf] = $variants[$ivo];
            }
        }
        return $out;
    }

    /** @return array<string, string>|null */
    public function get_family($family): ?array
    {
        return $this->getFamily($family);
    }

    /**
     * dompdf: defines a family from font files, $entry = ['normal' => file, 'bold' => .., 'italic' => .., 'bold_italic' => ..].
     * dompdf stores the paths without extension; ".ttf" / ".otf" is appended when the bare path does not exist. Entries
     * whose file does not exist are skipped (missing bold / italic faces are synthesized).
     *
     * @param array<string, string> $entry
     */
    public function setFontFamily($fontname, $entry): void
    {
        $family = (string) $fontname;
        if ($family === '' || !is_array($entry)) {
            return;
        }
        foreach (['normal' => 'regular', 'regular' => 'regular', 'bold' => 'bold', 'italic' => 'italic', 'bold_italic' => 'bold_italic'] as $dompdf => $variant) {
            $file = isset($entry[$dompdf]) ? (string) $entry[$dompdf] : '';
            if ($file === '' || \IvoPdf\Options::isNetworkPath($file)) {
                continue;
            }
            foreach (['', '.ttf', '.otf', '.TTF', '.OTF'] as $ext) {
                if (is_file($file . $ext)) {
                    $this->registerFont(['family' => $family, 'weight' => str_contains($variant, 'bold') ? 'bold' : 'normal', 'style' => str_contains($variant, 'italic') ? 'italic' : 'normal'], $file . $ext);
                    break;
                }
            }
        }
    }

    /** @param array<string, string> $entry */
    public function set_font_family($fontname, $entry): void
    {
        $this->setFontFamily($fontname, $entry);
    }

    /** @return array<string, array<string, string>> every family registered with registerFont() / setFontFamily() */
    public function getFontFamilies(): array
    {
        $out = [];
        foreach (array_keys(self::$registered) as $family) {
            $out[$family] = (array) $this->getFamily($family);
        }
        return $out;
    }

    /** @return array<string, array<string, string>> */
    public function get_font_families(): array
    {
        return $this->getFontFamilies();
    }

    /** dompdf compatibility: font families are kept in memory, nothing is written to fontDir. */
    public function saveFontFamilies(): void
    {
    }

    public function save_font_families(): void
    {
    }

    public function loadFontFamilies(): void
    {
    }

    public function load_font_families(): void
    {
    }

    public function get_font(?string $family_raw = null, string $subtype_raw = 'normal'): string
    {
        return $this->getFont($family_raw, $subtype_raw);
    }

    public function getOptions(): ?Options
    {
        return $this->options;
    }

    /** dompdf: 'bold', 'italic', 'bold_italic' or 'normal' for a CSS weight / style string such as "bold italic". */
    public function getType($type): string
    {
        $t = strtolower((string) $type);
        $bold = str_contains($t, 'bold') || preg_match('/\b([6-9]00)\b/', $t) === 1;
        $italic = str_contains($t, 'italic') || str_contains($t, 'oblique');
        return $bold ? ($italic ? 'bold_italic' : 'bold') : ($italic ? 'italic' : 'normal');
    }

    public function get_type($type): string
    {
        return $this->getType($type);
    }

    /** dompdf keeps a JSON file of installed fonts; IvoPDF keeps them in memory (empty string). */
    public function getUserFontsFilePath(): string
    {
        return '';
    }

    /** True when the font of the descriptor has a glyph for the (first) character of $char. */
    public function supportsChar(string $font, string $char): bool
    {
        if ($char === '') {
            return true;
        }
        [$family, $weight, $style] = self::parse($font, $this->options);
        try {
            return $this->manager()->resolveFont($family, $weight, $style)->hasGlyph(mb_ord($char, 'UTF-8') ?: 0);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @var array<string, array<string, string>> family => variant => file (applied to every engine created afterwards) */
    public static array $registered = [];

    /** Registers every font added with registerFont() in a font manager. */
    public static function applyRegistered(FontManager $fonts): void
    {
        foreach (self::$registered as $family => $variants) {
            foreach ($variants as $variant => $file) {
                $fonts->registerFontVariant($family, $variant, $file);
            }
        }
    }

    public function getTextWidth(string $text, $font, float $size, float $wordSpacing = 0.0, float $charSpacing = 0.0): float
    {
        [$family, $weight, $style] = self::parse((string) $font, $this->options);
        $w = $this->manager()->measureText($text, $family, $weight, $style, $size, $charSpacing);
        return $w + $wordSpacing * substr_count($text, ' ');
    }

    public function getFontHeight($font, float $size): float
    {
        return $size * 1.15;
    }

    /**
     * Ascent and descent (pt, both positive) of a font descriptor at a size, from the font the engine actually uses.
     *
     * @return array{0: float, 1: float}
     */
    public function ascentDescent($font, float $size): array
    {
        [$family, $weight, $style] = self::parse((string) $font, $this->options);
        try {
            $ttf = $this->manager()->resolveFont($family, $weight, $style);
            return [$ttf->ascent / 1000.0 * $size, abs($ttf->descent) / 1000.0 * $size];
        } catch (\Throwable) {
            return [$size * 0.9, $size * 0.25];
        }
    }

    /** dompdf: distance from the top of a text() line to its baseline - the font height (ascent + descent). */
    public function getFontBaseline($font, float $size): float
    {
        [$asc, $desc] = $this->ascentDescent($font, $size);
        return $asc + $desc;
    }

    public function get_font_baseline($font, float $size): float
    {
        return $this->getFontBaseline($font, $size);
    }

    public function get_text_width(string $text, $font, float $size, float $wordSpacing = 0.0, float $charSpacing = 0.0): float
    {
        return $this->getTextWidth($text, $font, $size, $wordSpacing, $charSpacing);
    }

    public function get_font_height($font, float $size): float
    {
        return $this->getFontHeight($font, $size);
    }

    /** @return array{0: string, 1: string, 2: string} [css family, weight, style] */
    public static function parse(string $font, ?Options $options = null): array
    {
        $parts = explode('|', $font);
        $family = $parts[0] !== '' ? $parts[0] : (string) ($options?->get('defaultFont') ?? 'serif');
        $weight = ($parts[1] ?? 'normal') === 'bold' ? 'bold' : 'normal';
        $style = ($parts[2] ?? 'normal') === 'italic' ? 'italic' : 'normal';
        // a bare "Helvetica-Bold" / "dejavu sans bold" style name
        if (!isset($parts[1]) && preg_match('~[-_ ](bold)?(?:[-_ ]?(italic|oblique))?$~i', $family, $m) === 1 && ($m[1] !== '' || ($m[2] ?? '') !== '')) {
            $weight = $m[1] !== '' ? 'bold' : 'normal';
            $style = ($m[2] ?? '') !== '' ? 'italic' : 'normal';
            $family = (string) preg_replace('~[-_ ](bold)?(?:[-_ ]?(italic|oblique))?$~i', '', $family);
        }
        return [$family, $weight, $style];
    }

    /** @internal measures with the engine's own font manager (mPDF fontdata / registered families included). */
    public function useFontManager(FontManager $fonts): void
    {
        $this->fonts = $fonts;
    }

    private function manager(): FontManager
    {
        if ($this->fonts === null) {
            $this->fonts = new FontManager(dirname(__DIR__, 3) . '/resources/fonts');
            self::applyRegistered($this->fonts);
        }
        return $this->fonts;
    }
}
