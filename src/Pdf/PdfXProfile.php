<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * Post-processing of the finished object table for PDF/X-1a and PDF/X-3 (no transparency, no device RGB):
 * opacity / blend / soft-mask states are reset, transparency groups are removed and remaining RGB shadings are
 * converted to CMYK. Everything flattened is reported through Options::addWarning().
 */
final class PdfXProfile
{
    /**
     * @param array<int, string> $objects
     */
    public static function apply(array &$objects, Options $opt): void
    {
        $flat = ['alpha' => 0, 'blend' => 0, 'softMask' => 0, 'groups' => 0];
        $cmyk = ColorOps::isCmykTarget();
        $flatten = ColorOps::isFlattening();
        $rgbLeft = 0;

        foreach ($objects as $id => $body) {
            $pos = strpos($body, ">>\nstream\n");
            $dict = $pos === false ? $body : substr($body, 0, $pos + 2);
            $tail = $pos === false ? '' : substr($body, $pos + 2);
            $new = $dict;

            if ($flatten && str_contains($new, '/Type /ExtGState')) {
                $new = (string) preg_replace_callback('~/(CA|ca)\s+(-?[\d.]+)~', static function (array $m) use (&$flat): string {
                    if ((float) $m[2] < 0.999) {
                        $flat['alpha']++;
                    }
                    return '/' . $m[1] . ' 1';
                }, $new);
                $new = (string) preg_replace_callback('~/BM\s*/(\w+)~', static function (array $m) use (&$flat): string {
                    if ($m[1] !== 'Normal' && $m[1] !== 'Compatible') {
                        $flat['blend']++;
                    }
                    return '/BM /Normal';
                }, $new);
                $new = (string) preg_replace_callback('~/SMask\s*(?:/None|\d+\s+0\s+R|<<[^<>]*>>)~', static function (array $m) use (&$flat): string {
                    if (!str_contains($m[0], '/None')) {
                        $flat['softMask']++;
                    }
                    return '/SMask /None';
                }, $new);
            }
            if ($flatten && str_contains($new, '/Group')) {
                $new = (string) preg_replace_callback('~\s*/Group\s*<<[^<>]*>>~', static function (array $m) use (&$flat): string {
                    if (str_contains($m[0], '/Transparency')) {
                        $flat['groups']++;
                        return '';
                    }
                    return $m[0];
                }, $new);
            }
            if ($cmyk && !$flatten && str_contains($new, '/Group')) {
                $new = str_replace('/CS /DeviceRGB', '/CS /DeviceCMYK', $new);
            }
            if ($cmyk && str_contains($new, '/ShadingType') && str_contains($new, '/DeviceRGB')) {
                $new = self::shadingToCmyk($new);
            }
            if ($cmyk && str_contains($new, '/DeviceRGB') && !str_contains($new, '/Subtype /Image')) {
                $rgbLeft++;
            }
            if ($new !== $dict) {
                $objects[$id] = $new . $tail;
            }
        }

        $parts = [];
        if ($flat['alpha'] > 0) {
            $parts[] = $flat['alpha'] . ' opacity state(s) reset to 1';
        }
        if ($flat['blend'] > 0) {
            $parts[] = $flat['blend'] . ' blend mode(s) reset to Normal';
        }
        if ($flat['softMask'] > 0) {
            $parts[] = $flat['softMask'] . ' soft mask(s) removed';
        }
        if ($flat['groups'] > 0) {
            $parts[] = $flat['groups'] . ' transparency group(s) removed';
        }
        if ($parts !== []) {
            $opt->addWarning('PDF/X-1a/X-3: transparency is not allowed and was flattened: ' . implode('; ', $parts) . '. Use "X-4" to keep transparency.');
        }
        if ($rgbLeft > 0) {
            $opt->addWarning("PDF/X: {$rgbLeft} object(s) still reference DeviceRGB (imported PDF content or unsupported resources).");
        }
    }

    /** Converts an inline-function RGB shading dictionary to DeviceCMYK. */
    private static function shadingToCmyk(string $dict): string
    {
        $dict = str_replace('/ColorSpace /DeviceRGB', '/ColorSpace /DeviceCMYK', $dict);
        $num = '(-?[\d.]+)';
        return (string) preg_replace_callback(
            '~/(C0|C1)\s*\[\s*' . $num . '\s+' . $num . '\s+' . $num . '\s*\]~',
            static function (array $m): string {
                $k = ColorOps::cmyk([(float) $m[2], (float) $m[3], (float) $m[4], 1.0]);
                return sprintf('/%s [%.3F %.3F %.3F %.3F]', $m[1], $k[0], $k[1], $k[2], $k[3]);
            },
            $dict
        );
    }
}
