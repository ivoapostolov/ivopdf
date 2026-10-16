<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

use IvoPdf\Css\CssUnit;

/**
 * Adapter that lets ImageLoader treat a PDF page as an "image" (<img src="doc.pdf#page=2">).
 */
final class PdfPageImage
{
    /** @var array<string, PageImporter> small cache so several pages of one file share their resources */
    private static array $sources = [];

    /**
     * @return array{id: string, type: string, width: int, height: int, colorSpace: string, bitsPerComponent: int, filter: string, data: string, pdfPage: ImportedPage}
     */
    public static function fromBytes(string $bytes, int $page): array
    {
        $key = md5($bytes);
        if (!isset(self::$sources[$key])) {
            if (count(self::$sources) >= 3) {
                array_shift(self::$sources);
            }
            self::$sources[$key] = new PageImporter(new PdfReader($bytes));
        }
        $imported = self::$sources[$key]->importPage($page);
        return [
            'id' => '',
            'type' => 'pdf',
            'width' => max(1, (int) round($imported->width / CssUnit::PX_TO_PT)),
            'height' => max(1, (int) round($imported->height / CssUnit::PX_TO_PT)),
            'colorSpace' => 'DeviceRGB',
            'bitsPerComponent' => 8,
            'filter' => '',
            'data' => '',
            'pdfPage' => $imported,
        ];
    }
}
