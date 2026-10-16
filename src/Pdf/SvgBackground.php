<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use DOMDocument;
use DOMElement;
use IvoPdf\Image\ImageLoader;

/**
 * Loads SVG files / data URIs used as CSS background images so they can be drawn as vectors.
 */
final class SvgBackground
{
    /** @var array<string, array{dom: DOMElement, img: array{id: string, width: float, height: float}}|null> */
    private static array $cache = [];

    /**
     * @return array{dom: DOMElement, img: array{id: string, width: float, height: float}}|null natural size in CSS px
     */
    public static function load(ImageLoader $loader, string $src): ?array
    {
        $src = trim($src, " \t\n\r\0\x0B'\"");
        $key = spl_object_id($loader) . ":" . md5($src);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $isSvgSrc = str_starts_with(strtolower($src), 'data:image/svg+xml') || (bool) preg_match('/\.svg(?:[?#].*)?$/i', $src);
        if (!$isSvgSrc) {
            return self::$cache[$key] = null;
        }
        $raw = $loader->fetchRawBytes($src);
        if ($raw === null || stripos($raw, '<svg') === false) {
            return self::$cache[$key] = null;
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = @$dom->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $el = $dom->documentElement;
        if (!$ok || !$el instanceof DOMElement || strtolower($el->tagName) !== 'svg') {
            return self::$cache[$key] = null;
        }
        $w = 300.0;
        $h = 150.0;
        $vb = preg_split('/[\s,]+/', trim($el->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($vb) >= 4 && (float) $vb[2] > 0 && (float) $vb[3] > 0) {
            $w = (float) $vb[2];
            $h = (float) $vb[3];
        }
        $wa = trim($el->getAttribute('width'));
        $ha = trim($el->getAttribute('height'));
        if ($wa !== '' && !str_ends_with($wa, '%') && (float) $wa > 0) {
            $w = (float) $wa;
        }
        if ($ha !== '' && !str_ends_with($ha, '%') && (float) $ha > 0) {
            $h = (float) $ha;
        }
        return self::$cache[$key] = ['dom' => $el, 'img' => ['id' => '', 'width' => $w, 'height' => $h]];
    }
}
