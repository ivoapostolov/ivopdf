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
        if ($family === '' || !is_file($remoteFile)) {
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

    public function getFontBaseline($font, float $size): float
    {
        return $size * 0.9;
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

    private function manager(): FontManager
    {
        if ($this->fonts === null) {
            $this->fonts = new FontManager(dirname(__DIR__, 3) . '/resources/fonts');
            self::applyRegistered($this->fonts);
        }
        return $this->fonts;
    }
}
