<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\IvoPdf;
use IvoPdf\Options;

/**
 * The class behind the `Mpdf\Mpdf` alias: IvoPdf with mPDF's defaults, so unchanged mPDF code produces what mPDF
 * produces.
 *
 *  - Body text in DejaVu Serif Condensed 11pt (mPDF's default stylesheet: `font-family: serif`, which mPDF maps to
 *    `dejavuserifcondensed`, `font-size: 11pt`), unless `default_font` / `default_font_size` are configured, with
 *    mPDF's normal line height (1.33; a document's own line-height wins).
 *  - Output() with mPDF's destinations: no arguments streams the PDF inline (as mPDF), a file name alone writes the
 *    file, a boolean destination means download (true) / file (false), 'S' returns the bytes.
 *
 * Everything else is IvoPdf (an instance of this class is an IvoPdf).
 */
class Mpdf extends IvoPdf
{
    /** mPDF's default body font and size */
    public const DEFAULT_FONT = 'DejaVu Serif Condensed';
    public const DEFAULT_FONT_SIZE = 11.0;

    /**
     * @param Options|array<string, mixed> $config mPDF configuration (or IvoPDF options)
     * @param mixed $container mPDF's service container (accepted and ignored)
     */
    public function __construct(Options|array $config = [], mixed $container = null)
    {
        if (is_array($config)) {
            $font = $config['default_font'] ?? $config['defaultFont'] ?? null;
            if ($font === null || $font === '') {
                $config['defaultFont'] = self::DEFAULT_FONT;
                unset($config['default_font']);
            }
            $size = $config['default_font_size'] ?? $config['defaultFontSize'] ?? null;
            if (!is_numeric($size) || (float) $size <= 0) {
                $config['defaultFontSize'] = self::DEFAULT_FONT_SIZE;
                unset($config['default_font_size']);
            }
        }
        parent::__construct($config);
        // mPDF's line-height "normal" is (ascent + descent) x 1.14 of the font: 1.33 for its default DejaVu faces
        $this->mpdfDefaultCss = 'body{line-height:1.33}';
    }

    /**
     * mPDF Output($name = '', $dest = ''): 'I' inline (the default without a name), 'F' file (the default with a name),
     * 'D' download, 'S' string; true = 'D', false = 'F'. Returns the bytes for 'S', otherwise null.
     *
     * @param string|array<string, mixed>|null $name
     * @param string|bool|null $dest
     */
    public function output(mixed $name = '', mixed $dest = ''): ?string
    {
        if (is_array($name)) {
            return parent::output($name);
        }
        return $this->mpdfOutput(MpdfArgs::str($name), $dest ?? '');
    }
}
