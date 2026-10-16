<?php

declare(strict_types=1);

namespace IvoPdf\Symfony;

use IvoPdf\IvoPdf;

/**
 * Builds configured {@see IvoPdf} instances from the `ivopdf:` bundle configuration.
 */
final class PdfFactory
{
    private const OPTION_MAP = [
        'paper' => 'paperSize',
        'orientation' => 'orientation',
        'default_font' => 'defaultFont',
        'default_font_size' => 'defaultFontSize',
        'margins' => 'margins',
        'base_path' => 'basePath',
        'allowed_paths' => 'allowedPaths',
        'remote_enabled' => 'isRemoteEnabled',
        'compress' => 'compress',
        'subset_fonts' => 'subsetFonts',
        'bookmarks' => 'bookmarks',
        'form_fields' => 'formFields',
        'lang' => 'lang',
        'creator' => 'creator',
        'pdfa' => 'pdfa',
        'fallback_fonts' => 'fallbackFonts',
    ];

    /**
     * @param array<string, mixed> $config processed bundle configuration
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Creates a fresh engine. $options are IvoPdf\Options keys (dompdf / mPDF names accepted) that override the configuration.
     *
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): IvoPdf
    {
        $engine = new IvoPdf(array_merge($this->defaultOptions(), $options));
        $fonts = $this->config['fonts'] ?? [];
        foreach (is_array($fonts) ? $fonts : [] as $family => $variants) {
            if (is_array($variants)) {
                $engine->registerFontFamily((string) $family, $variants);
            }
        }
        return $engine;
    }

    /**
     * Options derived from the bundle configuration (raw `options:` applied last).
     *
     * @return array<string, mixed>
     */
    public function defaultOptions(): array
    {
        $out = [];
        foreach (self::OPTION_MAP as $configKey => $optionKey) {
            $value = $this->config[$configKey] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$optionKey] = $value;
            }
        }
        $extra = $this->config['options'] ?? [];
        return is_array($extra) ? array_merge($out, $extra) : $out;
    }
}
