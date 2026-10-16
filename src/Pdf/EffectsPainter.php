<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssColor;
use IvoPdf\Css\CssUnit;

/**
 * Visual effects that sit on top of the basic box painter: text-shadow, clip-path, CSS filter parsing and blend modes.
 * Output is raw PDF content; coordinates in are document coordinates (pt, y down).
 */
final class EffectsPainter
{
    private const K = 0.5522847498;

    /** @var array<string, string> CSS mix-blend-mode keyword => PDF blend mode name */
    private const BLEND_MODES = [
        'multiply' => 'Multiply',
        'screen' => 'Screen',
        'overlay' => 'Overlay',
        'darken' => 'Darken',
        'lighten' => 'Lighten',
        'color-dodge' => 'ColorDodge',
        'color-burn' => 'ColorBurn',
        'hard-light' => 'HardLight',
        'soft-light' => 'SoftLight',
        'difference' => 'Difference',
        'exclusion' => 'Exclusion',
        'hue' => 'Hue',
        'saturation' => 'Saturation',
        'color' => 'Color',
        'luminosity' => 'Luminosity',
    ];

    public function __construct(private PdfDocument $pdf, private float $pageHeight)
    {
    }

    /**
     * Returns the PDF blend mode name for a CSS mix-blend-mode value (null for normal / unknown).
     */
    public static function blendModeName(string $css): ?string
    {
        return self::BLEND_MODES[strtolower(trim($css))] ?? null;
    }

    /**
     * Parses a comma separated shadow list (text-shadow / drop-shadow): offsets, blur, optional color.
     *
     * @return list<array{dx: float, dy: float, blur: float, color: array{0: float, 1: float, 2: float, 3: float}|null}>
     */
    public function parseShadowList(string $css, float $fontSizePt): array
    {
        $css = trim($css);
        if ($css === '' || strtolower($css) === 'none') {
            return [];
        }
        $out = [];
        foreach (self::splitTop($css, ',') as $item) {
            preg_match_all('/(?:rgba?|hsla?)\([^)]*\)|\S+/i', $item, $mm);
            $lengths = [];
            $color = null;
            foreach ($mm[0] as $tok) {
                if (preg_match('/^[+-]?(\d|\.\d)/', $tok)) {
                    $lengths[] = CssUnit::toPt($tok, 0.0, $fontSizePt);
                } else {
                    $c = CssColor::parse($tok);
                    if ($c !== null) {
                        $color = $c;
                    }
                }
            }
            if (count($lengths) < 2) {
                continue;
            }
            $out[] = ['dx' => $lengths[0], 'dy' => $lengths[1], 'blur' => max(0.0, $lengths[2] ?? 0.0), 'color' => $color];
        }
        return $out;
    }

    /**
     * Inserts text-shadow copies of the text drawn since offset $at (the already-emitted run) beneath that text.
     * The shadow is built from the very same operators (recolored, offset and alpha-scaled) so it follows any shaping.
     */
    public function applyTextShadow(string $out, int $at, string $shadowCss, float $fontSizePt): string
    {
        $shadowCss = trim($shadowCss);
        if ($shadowCss === '' || strtolower($shadowCss) === 'none' || strlen($out) <= $at) {
            return $out;
        }
        $segment = substr($out, $at);
        if (!str_contains($segment, 'BT')) {
            return $out;
        }
        $shadows = $this->parseShadowList($shadowCss, $fontSizePt);
        if ($shadows === []) {
            return $out;
        }
        $built = '';
        // First shadow is painted on top, so emit in reverse order
        foreach (array_reverse($shadows) as $sh) {
            $built .= $this->shadowCopies($segment, $sh);
        }
        return substr($out, 0, $at) . $built . $segment;
    }

    /**
     * @param array{dx: float, dy: float, blur: float, color: array{0: float, 1: float, 2: float, 3: float}|null} $sh
     */
    private function shadowCopies(string $segment, array $sh): string
    {
        $color = $sh['color'];
        $alpha = $color !== null ? $color[3] : 1.0;
        $recolored = $color !== null
            ? (string) preg_replace('/(?<![\d.])(?:-?[\d.]+ -?[\d.]+ -?[\d.]+ -?[\d.]+ k|-?[\d.]+ -?[\d.]+ -?[\d.]+ rg|\/Sp\d+ cs -?[\d.]+ scn)(?![\w])/', ColorOps::fillOp($color), $segment)
            : $segment;
        // Own alpha guards inside the segment would override ours; drop them
        $recolored = (string) preg_replace('/q \/GS\d+ gs /', 'q ', $recolored);

        $blur = $sh['blur'];
        $offsets = [[0.0, 0.0]];
        if ($blur > 0.4) {
            $r = $blur * 0.45;
            $n = $blur > 3.0 ? 10 : 6;
            for ($i = 0; $i < $n; $i++) {
                $a = 2.0 * M_PI * $i / $n;
                $offsets[] = [cos($a) * $r, sin($a) * $r];
            }
            if ($blur > 3.0) {
                for ($i = 0; $i < 6; $i++) {
                    $a = 2.0 * M_PI * ($i + 0.5) / 6.0;
                    $offsets[] = [cos($a) * $r * 0.5, sin($a) * $r * 0.5];
                }
            }
        }
        $count = count($offsets);
        // Overlapping layers accumulate: choose a per-layer alpha so the dense centre reaches roughly $alpha
        $perLayer = $count > 1 ? 1.0 - pow(1.0 - min(0.98, $alpha), 1.0 / max(1.0, $count * 0.55)) : $alpha;
        $gs = $perLayer < 0.999 ? '/' . $this->pdf->registerAlphaState($perLayer) . " gs\n" : '';

        $out = '';
        foreach ($offsets as [$ox, $oy]) {
            $out .= sprintf("q\n%s1 0 0 1 %.3F %.3F cm\n%sQ\n", $gs, $sh['dx'] + $ox, -($sh['dy'] + $oy), $recolored);
        }
        return $out;
    }

    /**
     * Builds clipping operators ("path W n") for a CSS clip-path basic shape, or '' when unsupported / none.
     */
    public function clipPath(string $css, float $x, float $y, float $w, float $h, float $fontSizePt): string
    {
        $css = trim($css);
        if ($css === '' || strtolower($css) === 'none' || $w <= 0.05 || $h <= 0.05) {
            return '';
        }
        if (!preg_match('/^([a-z-]+)\s*\((.*)\)\s*(?:border-box|padding-box|content-box|margin-box|fill-box|stroke-box|view-box)?\s*$/is', $css, $m)) {
            return '';
        }
        $fn = strtolower($m[1]);
        $args = trim($m[2]);
        $H = $this->pageHeight;
        $len = static fn(string $v, float $ref): float => CssUnit::toPt($v, $ref, $fontSizePt);

        switch ($fn) {
            case 'inset':
                $radiusPart = '';
                if (preg_match('/^(.*?)\s+round\s+(.*)$/is', $args, $rm)) {
                    $args = trim($rm[1]);
                    $radiusPart = trim($rm[2]);
                }
                $v = preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: ['0'];
                $v = match (count($v)) {
                    1 => [$v[0], $v[0], $v[0], $v[0]],
                    2 => [$v[0], $v[1], $v[0], $v[1]],
                    3 => [$v[0], $v[1], $v[2], $v[1]],
                    default => [$v[0], $v[1], $v[2], $v[3]],
                };
                $top = $len($v[0], $h);
                $right = $len($v[1], $w);
                $bottom = $len($v[2], $h);
                $left = $len($v[3], $w);
                $cw = $w - $left - $right;
                $ch = $h - $top - $bottom;
                if ($cw <= 0.05 || $ch <= 0.05) {
                    return sprintf("0 0 0 0 re\nW n\n");
                }
                $r = [0.0, 0.0, 0.0, 0.0];
                if ($radiusPart !== '') {
                    $rv = preg_split('/\s+/', trim(explode('/', $radiusPart)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: ['0'];
                    $rv = match (count($rv)) {
                        1 => [$rv[0], $rv[0], $rv[0], $rv[0]],
                        2 => [$rv[0], $rv[1], $rv[0], $rv[1]],
                        3 => [$rv[0], $rv[1], $rv[2], $rv[1]],
                        default => [$rv[0], $rv[1], $rv[2], $rv[3]],
                    };
                    $maxR = min($cw, $ch) / 2.0;
                    foreach ($rv as $i => $rs) {
                        $r[$i] = min($maxR, max(0.0, $len($rs, min($cw, $ch))));
                    }
                }
                return $this->roundedRect($x + $left, $y + $top, $cw, $ch, $r) . "W n\n";

            case 'circle':
            case 'ellipse':
                $pos = '50% 50%';
                if (preg_match('/^(.*?)\s*\bat\s+(.*)$/is', $args, $pm)) {
                    $args = trim($pm[1]);
                    $pos = trim($pm[2]);
                }
                [$cx, $cy] = $this->resolvePos($pos, $w, $h, $len);
                $dxMin = min($cx, $w - $cx);
                $dxMax = max($cx, $w - $cx);
                $dyMin = min($cy, $h - $cy);
                $dyMax = max($cy, $h - $cy);
                $tok = preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($fn === 'circle') {
                    $ref = sqrt(($w * $w + $h * $h) / 2.0);
                    $t0 = strtolower($tok[0] ?? 'closest-side');
                    $rx = match ($t0) {
                        'closest-side' => min($dxMin, $dyMin),
                        'farthest-side' => max($dxMax, $dyMax),
                        default => $len($t0, $ref),
                    };
                    $ry = $rx;
                } else {
                    $rxs = strtolower($tok[0] ?? 'closest-side');
                    $rys = strtolower($tok[1] ?? ($tok[0] ?? 'closest-side'));
                    $rx = match ($rxs) {
                        'closest-side' => $dxMin,
                        'farthest-side' => $dxMax,
                        default => $len($rxs, $w),
                    };
                    $ry = match ($rys) {
                        'closest-side' => $dyMin,
                        'farthest-side' => $dyMax,
                        default => $len($rys, $h),
                    };
                }
                if ($rx <= 0.01 || $ry <= 0.01) {
                    return "0 0 0 0 re\nW n\n";
                }
                $ex = $x + $cx;
                $ey = $y + $cy;
                $k = self::K;
                $p = fn(float $a, float $b): string => sprintf('%.2F %.2F', $a, $H - $b);
                $s = $p($ex + $rx, $ey) . " m\n";
                $s .= $p($ex + $rx, $ey + $k * $ry) . ' ' . $p($ex + $k * $rx, $ey + $ry) . ' ' . $p($ex, $ey + $ry) . " c\n";
                $s .= $p($ex - $k * $rx, $ey + $ry) . ' ' . $p($ex - $rx, $ey + $k * $ry) . ' ' . $p($ex - $rx, $ey) . " c\n";
                $s .= $p($ex - $rx, $ey - $k * $ry) . ' ' . $p($ex - $k * $rx, $ey - $ry) . ' ' . $p($ex, $ey - $ry) . " c\n";
                $s .= $p($ex + $k * $rx, $ey - $ry) . ' ' . $p($ex + $rx, $ey - $k * $ry) . ' ' . $p($ex + $rx, $ey) . " c\nh\n";
                return $s . "W n\n";

            case 'polygon':
                $rule = 'W';
                if (preg_match('/^(nonzero|evenodd)\s*,\s*(.*)$/is', $args, $fm)) {
                    $rule = strtolower($fm[1]) === 'evenodd' ? 'W*' : 'W';
                    $args = trim($fm[2]);
                }
                $s = '';
                $n = 0;
                foreach (self::splitTop($args, ',') as $pt) {
                    $xy = preg_split('/\s+/', trim($pt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (count($xy) < 2) {
                        continue;
                    }
                    $s .= sprintf("%.2F %.2F %s\n", $x + $len($xy[0], $w), $H - ($y + $len($xy[1], $h)), $n === 0 ? 'm' : 'l');
                    $n++;
                }
                return $n >= 3 ? $s . "h\n{$rule} n\n" : '';
        }
        return '';
    }

    /**
     * @param callable(string, float): float $len
     * @return array{0: float, 1: float}
     */
    private function resolvePos(string $pos, float $w, float $h, callable $len): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($pos)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $x = null;
        $y = null;
        $one = static fn(string $t, float $free, callable $len): float => match ($t) {
            'left', 'top' => 0.0,
            'right', 'bottom' => $free,
            'center' => $free / 2.0,
            default => $len($t, $free),
        };
        if (count($tokens) === 1) {
            return in_array($tokens[0], ['top', 'bottom'], true)
                ? [$w / 2.0, $one($tokens[0], $h, $len)]
                : [$one($tokens[0], $w, $len), $h / 2.0];
        }
        foreach ($tokens as $t) {
            if ($t === 'top' || $t === 'bottom') {
                $y = $one($t, $h, $len);
            } elseif ($t === 'left' || $t === 'right') {
                $x = $one($t, $w, $len);
            } elseif ($x === null) {
                $x = $one($t, $w, $len);
            } elseif ($y === null) {
                $y = $one($t, $h, $len);
            }
        }
        return [$x ?? $w / 2.0, $y ?? $h / 2.0];
    }

    /**
     * Rounded rectangle path in PDF coordinates.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $r [tl, tr, br, bl]
     */
    private function roundedRect(float $x, float $y, float $w, float $h, array $r): string
    {
        $H = $this->pageHeight;
        if ($r[0] + $r[1] + $r[2] + $r[3] < 0.1) {
            return sprintf("%.2F %.2F %.2F %.2F re\n", $x, $H - ($y + $h), $w, $h);
        }
        $k = self::K;
        $x1 = $x + $w;
        $y1 = $y + $h;
        $p = static fn(float $a, float $b) => sprintf('%.2F %.2F', $a, $b);
        $s = $p($x + $r[0], $H - $y) . " m\n" . $p($x1 - $r[1], $H - $y) . " l\n";
        $s .= $p($x1 - $r[1] + $k * $r[1], $H - $y) . ' ' . $p($x1, $H - ($y + $r[1] - $k * $r[1])) . ' ' . $p($x1, $H - ($y + $r[1])) . " c\n";
        $s .= $p($x1, $H - ($y1 - $r[2])) . " l\n";
        $s .= $p($x1, $H - ($y1 - $r[2] + $k * $r[2])) . ' ' . $p($x1 - $r[2] + $k * $r[2], $H - $y1) . ' ' . $p($x1 - $r[2], $H - $y1) . " c\n";
        $s .= $p($x + $r[3], $H - $y1) . " l\n";
        $s .= $p($x + $r[3] - $k * $r[3], $H - $y1) . ' ' . $p($x, $H - ($y1 - $r[3] + $k * $r[3])) . ' ' . $p($x, $H - ($y1 - $r[3])) . " c\n";
        $s .= $p($x, $H - ($y + $r[0])) . " l\n";
        $s .= $p($x, $H - ($y + $r[0] - $k * $r[0])) . ' ' . $p($x + $r[0] - $k * $r[0], $H - $y) . ' ' . $p($x + $r[0], $H - $y) . " c\nh\n";
        return $s;
    }

    /**
     * Parses a CSS filter list into [name, argument-string] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseFilterList(string $css): array
    {
        $css = trim($css);
        if ($css === '' || strtolower($css) === 'none') {
            return [];
        }
        preg_match_all('/([a-z-]+)\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)/i', $css, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $f) {
            $out[] = [strtolower($f[1]), trim($f[2])];
        }
        return $out;
    }

    /**
     * Resolves a filter amount ("0.5", "50%") into a float (1.0 = 100%).
     */
    public static function filterAmount(string $arg, float $default = 1.0): float
    {
        $arg = trim($arg);
        if ($arg === '') {
            return $default;
        }
        if (str_ends_with($arg, '%')) {
            return ((float) $arg) / 100.0;
        }
        return is_numeric($arg) ? (float) $arg : $default;
    }

    /**
     * Product of all opacity() filter functions (1.0 when none).
     */
    public static function filterOpacity(string $filterCss): float
    {
        $o = 1.0;
        foreach (self::parseFilterList($filterCss) as [$name, $arg]) {
            if ($name === 'opacity') {
                $o *= max(0.0, min(1.0, self::filterAmount($arg)));
            }
        }
        return $o;
    }

    /**
     * Converts drop-shadow() filter functions into a box-shadow list ("dx dy blur color, ...").
     */
    public static function dropShadowAsBoxShadow(string $filterCss): string
    {
        $parts = [];
        foreach (self::parseFilterList($filterCss) as [$name, $arg]) {
            if ($name === 'drop-shadow') {
                $parts[] = $arg;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * True when the filter list contains pixel-level colour filters that need an image variant.
     */
    public static function hasColorFilters(string $filterCss): bool
    {
        foreach (self::parseFilterList($filterCss) as [$name]) {
            if (in_array($name, ['grayscale', 'sepia', 'brightness', 'contrast', 'invert', 'saturate', 'hue-rotate'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function splitTop(string $str, string $sep): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $n = strlen($str);
        for ($i = 0; $i < $n; $i++) {
            $c = $str[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === $sep && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') {
            $out[] = trim($cur);
        }
        return $out;
    }
}
