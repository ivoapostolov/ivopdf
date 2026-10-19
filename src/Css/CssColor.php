<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Parses CSS color strings and linear-gradient definitions into normalized RGBA values.
 */
class CssColor
{
    /**
     * Standard CSS named colors mapped to hex.
     * @var array<string, string>
     */
    private const NAMED_COLORS = [
        'aliceblue' => '#f0f8ff', 'antiquewhite' => '#faebd7', 'aqua' => '#00ffff', 'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff', 'beige' => '#f5f5dc', 'bisque' => '#ffe4c4', 'black' => '#000000',
        'blanchedalmond' => '#ffebcd', 'blue' => '#0000ff', 'blueviolet' => '#8a2be2', 'brown' => '#a52a2a',
        'burlywood' => '#deb887', 'cadetblue' => '#5f9ea0', 'chartreuse' => '#7fff00', 'chocolate' => '#d2691e',
        'coral' => '#ff7f50', 'cornflowerblue' => '#6495ed', 'cornsilk' => '#fff8dc', 'crimson' => '#dc143c',
        'cyan' => '#00ffff', 'darkblue' => '#00008b', 'darkcyan' => '#008b8b', 'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9', 'darkgreen' => '#006400', 'darkgrey' => '#a9a9a9', 'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b', 'darkolivegreen' => '#556b2f', 'darkorange' => '#ff8c00', 'darkorchid' => '#9932cc',
        'darkred' => '#8b0000', 'darksalmon' => '#e9967a', 'darkseagreen' => '#8fbc8f', 'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f', 'darkslategrey' => '#2f4f4f', 'darkturquoise' => '#00ced1', 'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493', 'deepskyblue' => '#00bfff', 'dimgray' => '#696969', 'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff', 'firebrick' => '#b22222', 'floralwhite' => '#fffaf0', 'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff', 'gainsboro' => '#dcdcdc', 'ghostwhite' => '#f8f8ff', 'gold' => '#ffd700',
        'goldenrod' => '#daa520', 'gray' => '#808080', 'green' => '#008000', 'greenyellow' => '#adff2f',
        'grey' => '#808080', 'honeydew' => '#f0fff0', 'hotpink' => '#ff69b4', 'indianred' => '#cd5c5c',
        'indigo' => '#4b0082', 'ivory' => '#fffff0', 'khaki' => '#f0e68c', 'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5', 'lawngreen' => '#7cfc00', 'lemonchiffon' => '#fffacd', 'lightblue' => '#add8e6',
        'lightcoral' => '#f08080', 'lightcyan' => '#e0ffff', 'lightgoldenrodyellow' => '#fafad2', 'lightgray' => '#d3d3d3',
        'lightgreen' => '#90ee90', 'lightgrey' => '#d3d3d3', 'lightpink' => '#ffb6c1', 'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa', 'lightskyblue' => '#87cefa', 'lightslategray' => '#778899', 'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de', 'lightyellow' => '#ffffe0', 'lime' => '#00ff00', 'limegreen' => '#32cd32',
        'linen' => '#faf0e6', 'magenta' => '#ff00ff', 'maroon' => '#800000', 'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd', 'mediumorchid' => '#ba55d3', 'mediumpurple' => '#9370db', 'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee', 'mediumspringgreen' => '#00fa9a', 'mediumturquoise' => '#48d1cc', 'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970', 'mintcream' => '#f5fffa', 'mistyrose' => '#ffe4e1', 'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead', 'navy' => '#000080', 'oldlace' => '#fdf5e6', 'olive' => '#808000',
        'olivedrab' => '#6b8e23', 'orange' => '#ffa500', 'orangered' => '#ff4500', 'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa', 'palegreen' => '#98fb98', 'paleturquoise' => '#afeeee', 'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5', 'peachpuff' => '#ffdab9', 'peru' => '#cd853f', 'pink' => '#ffc0cb',
        'plum' => '#dda0dd', 'powderblue' => '#b0e0e6', 'purple' => '#800080', 'rebeccapurple' => '#663399',
        'red' => '#ff0000', 'rosybrown' => '#bc8f8f', 'royalblue' => '#4169e1', 'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072', 'sandybrown' => '#f4a460', 'seagreen' => '#2e8b57', 'seashell' => '#fff5ee',
        'sienna' => '#a0522d', 'silver' => '#c0c0c0', 'skyblue' => '#87ceeb', 'slateblue' => '#6a5acd',
        'slategray' => '#708090', 'slategrey' => '#708090', 'snow' => '#fffafa', 'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4', 'tan' => '#d2b48c', 'teal' => '#008080', 'thistle' => '#d8bfd8',
        'tomato' => '#ff6347', 'turquoise' => '#40e0d0', 'violet' => '#ee82ee', 'wheat' => '#f5deb3',
        'white' => '#ffffff', 'whitesmoke' => '#f5f5f5', 'yellow' => '#ffff00', 'yellowgreen' => '#9acd32',
    ];

    /**
     * Parses a CSS color value into normalized [r, g, b, a] where r, g, b, a are floats in 0.0..1.0.
     * Returns null if transparent or invalid.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function parse(?string $value): ?array
    {
        return self::parseInternal($value, false);
    }

    /**
     * Like {@see parse()} but keeps fully transparent colours: `transparent` is [0, 0, 0, 0] (transparent black, as
     * CSS defines it) and `rgba(255, 0, 0, 0)` keeps its RGB. Used for gradient stops, where a transparent stop takes
     * part in the interpolation. Null only for invalid values.
     *
     * @return array<int|string, mixed>|null
     */
    public static function parseWithAlpha(?string $value): ?array
    {
        if ($value !== null && strtolower(trim($value)) === 'transparent') {
            return [0.0, 0.0, 0.0, 0.0];
        }
        return self::parseInternal($value, true);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private static function parseInternal(?string $value, bool $keepTransparent): ?array
    {
        if ($value === null) {
            return null;
        }
        $val = strtolower(trim($value));
        if ($val === '' || $val === 'transparent' || $val === 'none' || $val === 'initial') {
            return null;
        }
        $min = $keepTransparent ? -1.0 : 0.001;

        if (isset(self::NAMED_COLORS[$val])) {
            $val = self::NAMED_COLORS[$val];
        }

        // Hex color: #rgb, #rgba, #rrggbb, #rrggbbaa
        if (str_starts_with($val, '#')) {
            $hex = substr($val, 1);
            $len = strlen($hex);
            if (!ctype_xdigit($hex)) {
                return null;
            }
            if ($len === 3) {
                $r = hexdec($hex[0] . $hex[0]) / 255.0;
                $g = hexdec($hex[1] . $hex[1]) / 255.0;
                $b = hexdec($hex[2] . $hex[2]) / 255.0;
                return [$r, $g, $b, 1.0];
            }
            if ($len === 4) {
                $r = hexdec($hex[0] . $hex[0]) / 255.0;
                $g = hexdec($hex[1] . $hex[1]) / 255.0;
                $b = hexdec($hex[2] . $hex[2]) / 255.0;
                $a = hexdec($hex[3] . $hex[3]) / 255.0;
                return $a <= $min ? null : [$r, $g, $b, $a];
            }
            if ($len === 6) {
                $r = hexdec(substr($hex, 0, 2)) / 255.0;
                $g = hexdec(substr($hex, 2, 2)) / 255.0;
                $b = hexdec(substr($hex, 4, 2)) / 255.0;
                return [$r, $g, $b, 1.0];
            }
            if ($len === 8) {
                $r = hexdec(substr($hex, 0, 2)) / 255.0;
                $g = hexdec(substr($hex, 2, 2)) / 255.0;
                $b = hexdec(substr($hex, 4, 2)) / 255.0;
                $a = hexdec(substr($hex, 6, 2)) / 255.0;
                return $a <= $min ? null : [$r, $g, $b, $a];
            }
        }

        // rgb(...) or rgba(...)
        if (preg_match('/^rgba?\((.+)\)$/', $val, $m)) {
            $inner = str_replace('/', ',', $m[1]);
            $parts = preg_split('/[\s,]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts && count($parts) >= 3) {
                $r = self::parseChannel($parts[0], 255.0);
                $g = self::parseChannel($parts[1], 255.0);
                $b = self::parseChannel($parts[2], 255.0);
                $a = isset($parts[3]) ? self::parseAlpha($parts[3]) : 1.0;
                return $a <= $min ? null : [$r, $g, $b, $a];
            }
        }

        // hsl(...) or hsla(...)
        if (preg_match('/^hsla?\((.+)\)$/', $val, $m)) {
            $inner = str_replace('/', ',', $m[1]);
            $parts = preg_split('/[\s,]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts && count($parts) >= 3) {
                $hueStr = strtolower($parts[0]);
                $h = (float) $hueStr;
                if (str_ends_with($hueStr, 'turn')) {
                    $h *= 360.0;
                } elseif (str_ends_with($hueStr, 'rad')) {
                    $h = rad2deg($h);
                }
                $h = fmod($h, 360.0);
                if ($h < 0) {
                    $h += 360.0;
                }
                $s = max(0.0, min(1.0, ((float) rtrim($parts[1], '%')) / 100.0));
                $l = max(0.0, min(1.0, ((float) rtrim($parts[2], '%')) / 100.0));
                $a = isset($parts[3]) ? self::parseAlpha($parts[3]) : 1.0;
                if ($a <= $min) {
                    return null;
                }
                [$r, $g, $b] = self::hslToRgb($h / 360.0, $s, $l);
                return [$r, $g, $b, $a];
            }
        }

        // Print colors: cmyk(c m y k [/ a]) / device-cmyk(...) and spot(name, tint)
        if (preg_match('/^(?:device-)?cmyk\((.+)\)$/', $val, $m) === 1) {
            return self::parseCmyk($m[1]);
        }
        if (preg_match('/^spot\((.+)\)$/', $val, $m) === 1) {
            return self::parseSpot($m[1]);
        }

        return null;
    }

    /**
     * Parses a CSS linear-gradient(...) value (legacy shape; the renderer uses {@see CssGradient}). Percentage stop
     * positions only, clamped to 0..1; `to <corner>` gives the square-box angle.
     *
     * @return array{angle: float, stops: list<array{color: array<int|string, mixed>, offset: float}>}|null
     */
    public static function parseLinearGradient(?string $value): ?array
    {
        $g = $value === null ? null : CssGradient::parse($value);
        if ($g === null || $g['type'] !== 'linear') {
            return null;
        }
        $angle = (float) $g['angle'];
        if ($g['corner'] !== null) {
            $angle = match ($g['corner']) {
                'top right' => 45.0,
                'bottom right' => 135.0,
                'bottom left' => 225.0,
                default => 315.0,
            };
        }
        $stops = self::legacyStops($g['stops']);
        return $stops === null ? null : ['angle' => $angle, 'stops' => $stops];
    }

    /**
     * Parses a CSS radial-gradient(...) value (legacy shape; the renderer uses {@see CssGradient}).
     *
     * @return array{shape: string, size: string, position: string, radii: array{0: ?string, 1: ?string}, stops: list<array{color: array<int|string, mixed>, offset: float}>}|null
     */
    public static function parseRadialGradient(?string $value): ?array
    {
        $g = $value === null ? null : CssGradient::parse($value);
        if ($g === null || $g['type'] !== 'radial') {
            return null;
        }
        $stops = self::legacyStops($g['stops']);
        if ($stops === null) {
            return null;
        }
        return ['shape' => $g['shape'], 'size' => $g['size'], 'position' => $g['position'], 'radii' => $g['radii'], 'stops' => $stops];
    }

    /**
     * @param list<array{color: array<int|string, mixed>|null, pos: ?string}> $stops
     * @return list<array{color: array<int|string, mixed>, offset: float}>|null
     */
    private static function legacyStops(array $stops): ?array
    {
        $resolved = CssGradient::resolveStops(
            array_values(array_filter($stops, static fn(array $s): bool => $s['color'] !== null)),
            static fn(string $p): ?float => str_ends_with($p, '%') ? ((float) substr($p, 0, -1)) / 100.0 : ((float) $p === 0.0 ? 0.0 : null)
        );
        if ($resolved === null) {
            return null;
        }
        $out = [];
        foreach ($resolved as $r) {
            $out[] = ['color' => $r['color'], 'offset' => max(0.0, min(1.0, $r['t']))];
        }
        return $out;
    }

    /**
     * Rich color value: ['space' => 'rgb'|'cmyk'|'spot', 'components' => list<float> (0..1), 'alpha' => float] plus
     * 'name' / 'tint' for spot colors; null for transparent or invalid values. parse() returns the same color as
     * RGB approximation (with the print-color model in extra keys for cmyk()/spot()).
     *
     * @return array{space: string, components: list<float>, alpha: float, name?: string, tint?: float}|null
     */
    public static function parseColor(?string $value): ?array
    {
        $c = self::parse($value);
        if ($c === null) {
            return null;
        }
        $space = (string) ($c['cs'] ?? 'rgb');
        if ($space === 'cmyk') {
            return ['space' => 'cmyk', 'components' => array_values($c['cmyk']), 'alpha' => $c[3]];
        }
        if ($space === 'spot') {
            return ['space' => 'spot', 'components' => array_values($c['cmyk']), 'alpha' => $c[3], 'name' => (string) $c['spot'], 'tint' => (float) $c['tint']];
        }
        return ['space' => 'rgb', 'components' => [$c[0], $c[1], $c[2]], 'alpha' => $c[3]];
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private static function parseCmyk(string $inner): ?array
    {
        $parts = preg_split('/[\s,\/]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) < 4) {
            return null;
        }
        $max = 0.0;
        foreach (array_slice($parts, 0, 4) as $p) {
            if (!str_ends_with($p, '%')) {
                $max = max($max, (float) $p);
            }
        }
        $v = [];
        foreach (array_slice($parts, 0, 4) as $p) {
            if (str_ends_with($p, '%')) {
                $v[] = max(0.0, min(1.0, ((float) $p) / 100.0));
            } else {
                $v[] = max(0.0, min(1.0, $max > 1.0 ? ((float) $p) / 100.0 : (float) $p));
            }
        }
        $a = isset($parts[4]) ? self::parseAlpha($parts[4]) : 1.0;
        if ($a <= 0.001) {
            return null;
        }
        return self::cmykToRgba($v, $a) + ['cs' => 'cmyk', 'cmyk' => $v];
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private static function parseSpot(string $inner): ?array
    {
        $comma = strrpos($inner, ',');
        $name = trim($comma === false ? $inner : substr($inner, 0, $comma), " \t\n\r'\"");
        $tintStr = $comma === false ? '100%' : trim(substr($inner, $comma + 1));
        $def = \IvoPdf\Pdf\SpotColors::get($name);
        if ($def === null) {
            return null;
        }
        if (str_ends_with($tintStr, '%')) {
            $tint = ((float) $tintStr) / 100.0;
        } else {
            $tint = (float) $tintStr;
            $tint = $tint > 1.0 ? $tint / 100.0 : $tint;
        }
        $tint = max(0.0, min(1.0, $tint));
        $scaled = [$def['cmyk'][0] * $tint, $def['cmyk'][1] * $tint, $def['cmyk'][2] * $tint, $def['cmyk'][3] * $tint];
        return self::cmykToRgba($scaled, 1.0) + ['cs' => 'spot', 'spot' => $def['name'], 'tint' => $tint, 'cmyk' => $def['cmyk']];
    }

    /**
     * @param list<float> $cmyk
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function cmykToRgba(array $cmyk, float $alpha): array
    {
        $k = 1.0 - $cmyk[3];
        return [(1.0 - $cmyk[0]) * $k, (1.0 - $cmyk[1]) * $k, (1.0 - $cmyk[2]) * $k, $alpha];
    }

    private static function parseChannel(string $val, float $max): float
    {
        $val = trim($val);
        if (str_ends_with($val, '%')) {
            return max(0.0, min(1.0, ((float) substr($val, 0, -1)) / 100.0));
        }
        return max(0.0, min(1.0, ((float) $val) / $max));
    }

    private static function parseAlpha(string $val): float
    {
        $val = trim($val);
        if (str_ends_with($val, '%')) {
            return max(0.0, min(1.0, ((float) substr($val, 0, -1)) / 100.0));
        }
        return max(0.0, min(1.0, (float) $val));
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s == 0.0) {
            return [$l, $l, $l];
        }
        $q = $l < 0.5 ? $l * (1.0 + $s) : $l + $s - $l * $s;
        $p = 2.0 * $l - $q;
        return [
            self::hueToRgb($p, $q, $h + 1.0 / 3.0),
            self::hueToRgb($p, $q, $h),
            self::hueToRgb($p, $q, $h - 1.0 / 3.0),
        ];
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0.0) {
            $t += 1.0;
        }
        if ($t > 1.0) {
            $t -= 1.0;
        }
        if ($t < 1.0 / 6.0) {
            return $p + ($q - $p) * 6.0 * $t;
        }
        if ($t < 1.0 / 2.0) {
            return $q;
        }
        if ($t < 2.0 / 3.0) {
            return $p + ($q - $p) * (2.0 / 3.0 - $t) * 6.0;
        }
        return $p;
    }
}
