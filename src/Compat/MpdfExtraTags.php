<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * mPDF's inline layout objects, translated to plain HTML/CSS:
 *
 *  - `<dottab outdent="2em" />`: a dotted leader up to the right edge of the line (the text that follows it is pushed
 *    to the right edge); with `outdent` the tab stop sits that far left of the right edge and the tail text ends there
 *  - `<textcircle r="30mm" top-text=".." bottom-text=".." space-width="120" char-width="100" divider=".." style="..">`:
 *    text set along a circle, one rotated element per glyph (glyph widths come from the engine's font manager)
 */
final class MpdfExtraTags
{
    /** CSS added once to documents that use <dottab>. */
    public const DOTTAB_CSS = '.ivo-dottab::after{content:leader(\'.\')}';

    /** @param array<string, string> $a lower-cased tag attributes */
    public static function dotTab(array $a, ?MpdfState $s = null): string
    {
        $style = self::styleMap($a['style'] ?? '');
        $outdent = min(10000.0, self::length($a['outdent'] ?? ($style['outdent'] ?? ''), 12.0)); // absurd values: no int-cast warning
        if ($outdent <= 0 || $s === null) {
            return '<span class="ivo-dottab"></span>';
        }
        // The tab stop is the right edge minus the outdent: PageRefResolver reads `ivo-leader-outdent` from the leader run
        $cls = 'ivo-dottab-o' . (int) round($outdent * 100);
        $rule = '.' . $cls . '::after{content:leader('.');ivo-leader-outdent:' . self::n($outdent) . 'pt}';
        if (!str_contains($s->pageCss, $rule)) {
            $s->pageCss .= $rule;
        }
        return '<span class="ivo-dottab ' . $cls . '"></span>';
    }

    /**
     * @param array<string, string> $a lower-cased tag attributes
     */
    public static function textCircle(array $a, MpdfState $s): string
    {
        $style = self::styleMap($a['style'] ?? '');
        $r = min(10000.0, self::length($a['r'] ?? '20mm', 12.0, 'mm'));
        // mPDF names the texts top-text / bottom-text; plain top / bottom (a common slip) are accepted too
        $top = self::text($a['top-text'] ?? ($a['top'] ?? ''));
        $bottom = self::text($a['bottom-text'] ?? ($a['bottom'] ?? ''));
        if (trim($top) === '' && trim($bottom) === '') {
            $s->warnings[] = '<textcircle> has no top-text or bottom-text: nothing was drawn.';
            return '';
        }
        if (!($r > 0)) {
            $s->warnings[] = '<textcircle> skipped: its radius r="' . mb_substr($a['r'] ?? '', 0, 40) . '" is not a positive length (e.g. r="30mm").';
            return '';
        }
        $divider = self::text($a['divider'] ?? '');
        $kerning = (float) (($a['space-width'] ?? '') !== '' ? $a['space-width'] : 120) / 100;
        $fontWidth = (float) (($a['char-width'] ?? '') !== '' ? $a['char-width'] : 100) / 100;
        if (!($kerning > 0) || !($fontWidth > 0)) {
            $s->warnings[] = '<textcircle> skipped: space-width and char-width must be positive percentages (defaults 120 and 100).';
            return '';
        }

        $family = trim($style['font-family'] ?? '', " \t'\"");
        if ($family === '') {
            $family = $s->defaultFont;
        }
        $weight = (isset($style['font-weight']) && (strtolower($style['font-weight']) === 'bold' || (int) $style['font-weight'] >= 600)) ? 'bold' : 'normal';
        $italic = strtolower($style['font-style'] ?? '') === 'italic' ? 'italic' : 'normal';
        $auto = strtolower($style['font-size'] ?? '') === 'auto';
        $size = $auto || !isset($style['font-size']) ? 12.0 : max(1.0, self::length($style['font-size'], 12.0));

        $measure = $s->measure;
        $width = static fn(string $t, float $pt): float => $measure !== null
            ? $measure($t, $family, $weight, $italic, $pt)
            : mb_strlen($t) * $pt * 0.55;

        $u = 2 * M_PI * $r;
        $html = '';
        $draw = function (string $text, string $place, float $size) use ($r, $kerning, $fontWidth, $family, $weight, $italic, $width, $u, &$html): void {
            $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $w = [];
            $total = 0.0;
            foreach ($chars as $i => $c) {
                $w[$i] = $width($c, $size) * $kerning * $fontWidth;
                $total += $w[$i];
            }
            $span = $total / $u * 360;
            $cum = 0.0;
            foreach ($chars as $i => $c) {
                $center = ($cum + $w[$i] / 2) / $u * 360;
                $cum += $w[$i];
                if (trim($c) === '') {
                    continue;
                }
                $angle = $place === 'top' ? -$span / 2 + $center : $span / 2 - $center;
                $html .= self::glyph($c, $angle, $place, $r, $size, $fontWidth, $width($c, $size));
            }
        };

        if ($auto) {
            // font-size:auto fits the text to the circumference (half of it when both texts are given)
            $probe = $top !== '' ? $top : $bottom;
            $chars = preg_split('//u', $probe, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $t = 0.0;
            foreach ($chars as $c) {
                $t += $width($c, $size) * $kerning * $fontWidth;
            }
            $t += $width('  ', $size) + ($divider !== '' ? $width('  ', $size) : 0.0);
            $size = $t > 0 ? $size * ($top !== '' && $bottom !== '' ? 0.5 : 1.0) * $u / $t : $size;
        }

        if ($top !== '') {
            $draw($top, 'top', $size);
            if ($divider !== '') {
                $html .= self::glyph($divider, 90.0, 'top', $r, $size, $fontWidth, $width($divider, $size));
                $html .= self::glyph($divider, -90.0, 'top', $r, $size, $fontWidth, $width($divider, $size));
            }
        }
        if ($bottom !== '') {
            $draw($bottom, 'bottom', $size);
        }

        // Box properties of the object itself (margin / padding / border / background / color / font) come from its style
        $box = '';
        foreach ($style as $prop => $val) {
            if ($prop === 'font-size' || $prop === 'width' || $prop === 'height' || $prop === 'display' || $prop === 'position') {
                continue;
            }
            $box .= $prop . ':' . $val . ';';
        }
        $d = self::n(2 * $r);
        return '<span class="ivo-textcircle" style="display:inline-block;position:relative;box-sizing:content-box;width:' . $d . 'pt;height:' . $d . 'pt;line-height:1;'
            . 'vertical-align:baseline;font-family:\'' . str_replace(["'", '"', ';'], '', $family) . '\';' . $box . '">' . $html . '</span>';
    }

    /** One glyph on the circle: a square wrapper rotated around the circle centre holding the character at the top / bottom. */
    private static function glyph(string $ch, float $angle, string $place, float $r, float $size, float $fontWidth, float $natural): string
    {
        $d = self::n(2 * $r);
        $left = self::n($r - $natural / 2);
        $top = $place === 'top' ? '0' : self::n(2 * $r - $size) . 'pt';
        $scale = abs($fontWidth - 1.0) > 0.001 ? 'transform:scaleX(' . self::n($fontWidth) . ');' : '';
        return '<span style="position:absolute;left:0;top:0;width:' . $d . 'pt;height:' . $d . 'pt;transform:rotate(' . self::n($angle) . 'deg)">'
            . '<span style="position:absolute;left:' . $left . 'pt;top:' . $top . ';white-space:pre;line-height:' . self::n($size) . 'pt;font-size:' . self::n($size) . 'pt;' . $scale . '">'
            . htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') . '</span></span>';
    }

    private static function text(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.') ?: '0';
    }

    /** @return array<string, string> */
    private static function styleMap(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (str_contains($decl, ':')) {
                [$k, $v] = explode(':', $decl, 2);
                $k = strtolower(trim($k));
                if ($k !== '') {
                    $out[$k] = trim($v);
                }
            }
        }
        return $out;
    }

    /** CSS length -> pt (unitless numbers use $defaultUnit; em is relative to $em pt). */
    private static function length(string $v, float $em, string $defaultUnit = 'pt'): float
    {
        if (preg_match('~^\s*(-?[\d.]+)\s*([a-z%]*)\s*$~i', $v, $m) !== 1) {
            return 0.0;
        }
        $n = (float) $m[1];
        $unit = strtolower($m[2] !== '' ? $m[2] : $defaultUnit);
        return match ($unit) {
            'mm' => $n * 72 / 25.4,
            'cm' => $n * 72 / 2.54,
            'in' => $n * 72,
            'px' => $n * 0.75,
            'em', 'rem' => $n * $em,
            default => $n,
        };
    }
}
