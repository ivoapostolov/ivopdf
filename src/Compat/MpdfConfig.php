<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\Font\FontManager;
use IvoPdf\Options;

/**
 * mPDF constructor configuration keys that have no direct IvoPDF option. They are accepted (so existing
 * `new Mpdf([...])` calls keep working), stored in `Options::$compat` and, where meaningful, acted upon:
 * `fontDir` + `fontdata` (custom font registration), `margin_header` / `margin_footer`, `showImageErrors`.
 */
final class MpdfConfig
{
    /** Keys that are stored verbatim in Options::$compat. */
    private const KEYS = [
        'mode', 'tempDir', 'autoScriptToLang', 'autoLangToFont', 'autoVietnamese', 'autoArabic', 'setAutoTopMargin',
        'setAutoBottomMargin', 'margin_header', 'margin_footer', 'fontdata', 'img_dpi', 'showImageErrors', 'debug',
        'allow_output_buffering', 'useSubstitutions', 'simpleTables', 'packTableData', 'mirrorMargins', 'biDirectional',
        'useKerning', 'curlTimeout', 'curlAllowUnsafeSslRequests', 'enableImports', 'backupSubsFont', 'backupSIPFont',
        'useAdobeCJK', 'SHYlang', 'defaultCssFile', 'default_lineheight_ratio', 'orientation_mpdf', 'PDFAauto',
        'ignore_invalid_utf8', 'watermarkImgBehind', 'showWatermarkText', 'showWatermarkImage',
        'list_indent_first_level', 'defaultheaderline', 'defaultfooterline', 'defaultheaderfontsize', 'defaultfooterfontsize',
    ];

    /** True when the settings key belongs in Options::$compat (an array `fontDir` is mPDF's list of font folders). */
    public static function captures(string $key, mixed $value): bool
    {
        if ($key === 'fontDir') {
            return is_array($value);
        }
        return in_array($key, self::KEYS, true);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        foreach (['margin_header', 'margin_footer'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                $raw[$k] = (float) $raw[$k] * 72 / 25.4; // mm -> pt
            } else {
                unset($raw[$k]);
            }
        }
        return $raw;
    }

    /**
     * Registers the `fontdata` families (['family' => ['R' => 'file.ttf', 'B' => .., 'I' => .., 'BI' => ..]]),
     * looking the files up in `fontDir` (list) and the bundled font folder.
     */
    public static function registerFonts(Options $options, FontManager $fonts): void
    {
        $data = $options->compat['fontdata'] ?? null;
        if (!is_array($data)) {
            return;
        }
        $dirs = [];
        foreach ((array) ($options->compat['fontDir'] ?? []) as $d) {
            if (is_string($d) && $d !== '') {
                $dirs[] = rtrim($d, '/\\');
            }
        }
        $dirs[] = rtrim($options->fontDir, '/\\');

        $map = ['R' => 'regular', 'B' => 'bold', 'I' => 'italic', 'BI' => 'bold_italic'];
        foreach ($data as $family => $files) {
            if (!is_array($files) || !isset($files['R'])) {
                continue;
            }
            $variants = [];
            foreach ($map as $mk => $vk) {
                if (!isset($files[$mk]) || !is_string($files[$mk])) {
                    continue;
                }
                $path = self::locate($files[$mk], $dirs);
                if ($path === null) {
                    $options->addWarning("fontdata '{$family}': file not found: {$files[$mk]}");
                    continue;
                }
                $variants[$vk] = $path;
            }
            if (isset($variants['regular'])) {
                $fonts->registerFamily((string) $family, $variants);
            }
        }
    }

    /** @param list<string> $dirs */
    private static function locate(string $file, array $dirs): ?string
    {
        if (is_file($file)) {
            return $file;
        }
        foreach ($dirs as $d) {
            if (is_file($d . '/' . $file)) {
                return $d . '/' . $file;
            }
        }
        return null;
    }
}
