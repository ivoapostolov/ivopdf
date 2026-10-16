<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Detects Windows metafiles (WMF / EMF) by their magic bytes and converts them to SVG markup.
 */
final class MetafileConverter
{
    public static function isMetafile(string $bytes): bool
    {
        return WmfConverter::isWmf($bytes) || EmfConverter::isEmf($bytes);
    }

    /**
     * @return string|null SVG markup, or null when $bytes is not a convertible metafile
     */
    public static function toSvg(string $bytes): ?string
    {
        try {
            if (EmfConverter::isEmf($bytes)) {
                return EmfConverter::toSvg($bytes);
            }
            if (WmfConverter::isWmf($bytes)) {
                return WmfConverter::toSvg($bytes);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }
}
