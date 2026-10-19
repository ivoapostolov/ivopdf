<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * Central color operator emitter. Colors are the arrays produced by CssColor::parse(): [r, g, b, a] (RGB, or the RGB
 * approximation) plus, for print colors, 'cs' => 'cmyk' with 'cmyk' => [c, m, y, k] or 'cs' => 'spot' with
 * 'spot' => name, 'tint' => float and 'cmyk' => alternate [c, m, y, k] at full tint.
 *
 * The output target ('rgb' or 'cmyk', see Options::colorSpace / PDF/X) decides whether RGB colors are converted;
 * CMYK and spot colors keep their model, except in PDF/A with the sRGB output intent: a device CMYK colour needs a
 * CMYK output intent there (ISO 19005-1 6.2.3.3, 19005-2/3 6.2.4.3), so CMYK and spot colors are written as their RGB
 * equivalent (mPDF's PDFAauto does the same) and a warning tells. With a CMYK ICC output profile (Options::$pdfaIccProfile)
 * PDF/A output is CMYK instead (every RGB color converted, like colorSpace = 'cmyk'). When transparency is forbidden
 * (PDF/X-1a, X-3) translucent colors are flattened against a white page.
 */
final class ColorOps
{
    private static bool $cmykTarget = false;
    private static bool $flatten = false;
    /** PDF/A-1 or PDF/X-1a / X-3: no transparency at all (see transparencyForbidden()) */
    private static bool $noTransparency = false;
    /** PDF/A with the sRGB output intent: no device CMYK at all */
    private static bool $rgbOnly = false;
    /** a CMYK / spot color or image was written as RGB because of $rgbOnly */
    private static bool $downgraded = false;
    /** @var list<string> */
    private static array $notes = [];
    private static ?ColorConverter $converter = null;

    /** Applies the colorSpace / pdfx / pdfa options (and loads the spot color registry); validates option combinations. */
    public static function configure(Options $options): void
    {
        PdfX::validate($options);
        self::$cmykTarget = PdfX::forcesCmyk($options);
        self::$flatten = PdfX::forbidsTransparency($options);
        self::$rgbOnly = false;
        self::$notes = [];
        $pdfa = PdfFinisher::parsePdfa($options->pdfa);
        self::$noTransparency = self::$flatten || ($pdfa !== null && $pdfa['part'] === 1);
        if ($pdfa !== null && PdfX::level($options) === '') {
            if (PdfAIntent::profile($options, $pdfa['part']) !== null) {
                self::$cmykTarget = true; // CMYK output intent: everything is written in CMYK
            } else {
                if (self::$cmykTarget) {
                    self::$notes[] = "PDF/A: colorSpace 'cmyk' needs a CMYK output intent; without a CMYK ICC profile (Options::\$pdfaIccProfile, mPDF 'ICCProfile') the document is written in RGB for the sRGB output intent.";
                }
                self::$cmykTarget = false;
                self::$rgbOnly = true;
            }
        }
        SpotColors::load($options->spotColors);
    }

    /** Starts a render: forgets the conversions of the previous one (configure() runs again while compiling). */
    public static function startRender(): void
    {
        self::$downgraded = false;
    }

    /** True in PDF/A with the sRGB output intent (CMYK / spot colors and CMYK images are written as RGB). */
    public static function isRgbOnly(): bool
    {
        return self::$rgbOnly;
    }

    /** @internal records that a CMYK color / image was written as RGB (PDF/A with the sRGB output intent) */
    public static function noteDowngrade(): void
    {
        self::$downgraded = true;
    }

    /**
     * Warnings about the color conversions of the current render (configuration notes, CMYK written as RGB).
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        $out = self::$notes;
        if (self::$downgraded) {
            $out[] = 'PDF/A: CMYK / spot colors and CMYK images were converted to RGB (the output intent is sRGB; a device CMYK color would make the file non-conforming). Give a CMYK ICC profile (Options::$pdfaIccProfile, mPDF "ICCProfile") to keep CMYK.';
        }
        return $out;
    }

    public static function setConverter(?ColorConverter $converter): void
    {
        self::$converter = $converter;
    }

    public static function isCmykTarget(): bool
    {
        return self::$cmykTarget;
    }

    public static function isFlattening(): bool
    {
        return self::$flatten;
    }

    /**
     * True when the output forbids transparency (PDF/A-1, PDF/X-1a / X-3): painters that would rely on soft masks
     * or opacity states composite their colours onto the background instead.
     */
    public static function transparencyForbidden(): bool
    {
        return self::$noTransparency;
    }

    /**
     * Fill color operator, e.g. "0.100 0.200 0.300 rg", "0 0 0 1 k" or "/Sp1 cs 1.000 scn".
     *
     * @param array<int|string, mixed> $c
     */
    public static function fillOp(array $c, bool $allowSpot = true): string
    {
        return self::op($c, false, $allowSpot);
    }

    /**
     * Stroke color operator, e.g. "0.100 0.200 0.300 RG", "0 0 0 1 K" or "/Sp1 CS 1.000 SCN".
     *
     * @param array<int|string, mixed> $c
     */
    public static function strokeOp(array $c, bool $allowSpot = true): string
    {
        return self::op($c, true, $allowSpot);
    }

    /**
     * @param array<int|string, mixed> $c
     */
    private static function op(array $c, bool $stroke, bool $allowSpot): string
    {
        $c = self::flatten($c);
        $cs = $c['cs'] ?? 'rgb';
        if (self::$rgbOnly) {
            if ($cs !== 'rgb') {
                self::$downgraded = true; // [0..2] hold the RGB equivalent of a CMYK / spot color
            }
            return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg');
        }
        if ($cs === 'spot' && $allowSpot && is_string($c['spot'] ?? null)) {
            $def = SpotColors::get($c['spot']);
            if ($def !== null) {
                return sprintf('/%s %s %.3F %s', SpotColors::resourceName($def['index']), $stroke ? 'CS' : 'cs', (float) ($c['tint'] ?? 1.0), $stroke ? 'SCN' : 'scn');
            }
        }
        if ($cs === 'spot' || $cs === 'cmyk' || self::$cmykTarget) {
            $k = self::cmyk($c);
            return sprintf('%.3F %.3F %.3F %.3F %s', $k[0], $k[1], $k[2], $k[3], $stroke ? 'K' : 'k');
        }
        return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg');
    }

    /**
     * Device CMYK components of any color (spot colors give their tinted alternate).
     *
     * @param array<int|string, mixed> $c
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function cmyk(array $c): array
    {
        $cs = $c['cs'] ?? 'rgb';
        if ($cs === 'cmyk' && isset($c['cmyk'])) {
            return $c['cmyk'];
        }
        if ($cs === 'spot' && isset($c['cmyk'])) {
            $t = (float) ($c['tint'] ?? 1.0);
            return [$c['cmyk'][0] * $t, $c['cmyk'][1] * $t, $c['cmyk'][2] * $t, $c['cmyk'][3] * $t];
        }
        return (self::$converter ??= new NaiveCmykConverter())->rgbToCmyk((float) $c[0], (float) $c[1], (float) $c[2]);
    }

    /**
     * Safety net for content streams: rewrites remaining "r g b rg" / "r g b RG" operators to CMYK when the target is CMYK.
     */
    public static function rewriteRgb(string $content): string
    {
        if (!self::$cmykTarget || !str_contains($content, ' rg') && !str_contains($content, ' RG')) {
            return $content;
        }
        $num = '-?(?:\d+\.?\d*|\.\d+)';
        return (string) preg_replace_callback(
            '/(?<![\w.\/-])(' . $num . ') (' . $num . ') (' . $num . ') (rg|RG)(?![\w])/',
            static function (array $m): string {
                $k = self::cmyk([(float) $m[1], (float) $m[2], (float) $m[3], 1.0]);
                return sprintf('%.3F %.3F %.3F %.3F %s', $k[0], $k[1], $k[2], $k[3], $m[4] === 'rg' ? 'k' : 'K');
            },
            $content
        );
    }

    /** Blends a translucent color with white when transparency is not allowed. */
    private static function flatten(array $c): array
    {
        $a = (float) ($c[3] ?? 1.0);
        if (!self::$flatten || $a >= 0.999) {
            return $c;
        }
        $cs = $c['cs'] ?? 'rgb';
        if ($cs === 'spot') {
            $c['tint'] = (float) ($c['tint'] ?? 1.0) * $a;
        } elseif ($cs === 'cmyk') {
            $c['cmyk'] = [$c['cmyk'][0] * $a, $c['cmyk'][1] * $a, $c['cmyk'][2] * $a, $c['cmyk'][3] * $a];
        } else {
            $c[0] = 1.0 - $a * (1.0 - $c[0]);
            $c[1] = 1.0 - $a * (1.0 - $c[1]);
            $c[2] = 1.0 - $a * (1.0 - $c[2]);
        }
        $c[3] = 1.0;
        return $c;
    }

    /**
     * Color space a shading with these stops is written in: 'DeviceCMYK' when the target is CMYK or every stop is a
     * print color, otherwise 'DeviceRGB'.
     *
     * @param list<array{color: array<int|string, mixed>, offset: float}> $stops
     */
    public static function shadingSpace(array $stops): string
    {
        if (self::$cmykTarget) {
            return 'DeviceCMYK';
        }
        if (self::$rgbOnly) {
            foreach ($stops as $s) {
                if (($s['color']['cs'] ?? 'rgb') !== 'rgb') {
                    self::$downgraded = true;
                }
            }
            return 'DeviceRGB';
        }
        foreach ($stops as $s) {
            if (($s['color']['cs'] ?? 'rgb') === 'rgb') {
                return 'DeviceRGB';
            }
        }
        return 'DeviceCMYK';
    }

    /**
     * Color components for a function /C0 /C1 array in the given device space.
     *
     * @param array<int|string, mixed> $c
     */
    public static function components(array $c, string $space): string
    {
        $c = self::flatten($c);
        if ($space === 'DeviceCMYK') {
            $k = self::cmyk($c);
            return sprintf('%.3F %.3F %.3F %.3F', $k[0], $k[1], $k[2], $k[3]);
        }
        return sprintf('%.3F %.3F %.3F', $c[0], $c[1], $c[2]);
    }
}
