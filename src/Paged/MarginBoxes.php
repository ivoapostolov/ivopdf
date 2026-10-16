<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use IvoPdf\Css\CssUnit;

/**
 * Builds the HTML for `@page` margin boxes (`@top-left`, `@bottom-center`, ...) of one page.
 * Each band (top / bottom) becomes one flex row spanning the page width that IvoPdf lays out inside the margin.
 */
final class MarginBoxes
{
    /**
     * @param array<string, array<string, string>>                                   $boxes margin box name => declarations
     * @param array{w: float, h: float, m: array{top: float, right: float, bottom: float, left: float}} $geo
     * @return list<array{html: string, x: float, y: float, width: float}>
     */
    public static function bands(array $boxes, array $geo, int $pageNum, int $pageCount, ?StringSets $strings, string $title = '', ?RunningElements $running = null): array
    {
        $out = [];
        $m = $geo['m'];
        $contentW = max(10.0, $geo['w'] - $m['left'] - $m['right']);

        foreach (['top' => [0.0, $m['top']], 'bottom' => [$geo['h'] - $m['bottom'], $m['bottom']]] as $side => [$y, $height]) {
            $names = ["{$side}-left-corner", "{$side}-left", "{$side}-center", "{$side}-right", "{$side}-right-corner"];
            $present = array_filter($names, static fn(string $n): bool => isset($boxes[$n]));
            if ($present === [] || $height < 1.0) {
                continue;
            }

            $hasCenter = isset($boxes["{$side}-center"]);
            $hasLeft = isset($boxes["{$side}-left"]);
            $hasRight = isset($boxes["{$side}-right"]);
            $centerW = 0.0;
            if ($hasCenter) {
                $centerW = isset($boxes["{$side}-center"]['width'])
                    ? min($contentW, CssUnit::toPt($boxes["{$side}-center"]['width'], $contentW))
                    : ($hasLeft || $hasRight ? $contentW / 3.0 : $contentW);
                $sideW = ($contentW - $centerW) / 2.0;
                $widths = [$m['left'], $sideW, $centerW, $sideW, $m['right']];
            } elseif ($hasLeft && $hasRight) {
                $widths = [$m['left'], $contentW / 2.0, 0.0, $contentW / 2.0, $m['right']];
            } elseif ($hasLeft) {
                $widths = [$m['left'], $contentW, 0.0, 0.0, $m['right']];
            } elseif ($hasRight) {
                $widths = [$m['left'], 0.0, 0.0, $contentW, $m['right']];
            } else {
                $widths = [$m['left'], 0.0, 0.0, 0.0, $m['right']];
            }

            $cells = '';
            foreach ($names as $i => $name) {
                if ($widths[$i] < 0.05) {
                    continue;
                }
                $props = $boxes[$name] ?? null;
                if ($props === null) {
                    $cells .= sprintf('<div style="flex:none;width:%.3Fpt;height:%.3Fpt"></div>', $widths[$i], $height);
                    continue;
                }
                $cells .= self::cell($props, $name, $widths[$i], $height, $pageNum, $pageCount, $strings, $title, $running);
            }
            $out[] = [
                'html' => sprintf('<div style="display:flex;width:%.3Fpt;height:%.3Fpt;margin:0;padding:0">%s</div>', $geo['w'], $height, $cells),
                'x' => 0.0,
                'y' => $y,
                'width' => $geo['w'],
            ];
        }

        // Side boxes: vertical bands in the left / right page margins (content stays horizontal)
        $sideH = $geo['h'] - $m['top'] - $m['bottom'];
        foreach (['left' => [0.0, $m['left']], 'right' => [$geo['w'] - $m['right'], $m['right']]] as $side => [$x, $sideW]) {
            $present = array_filter(["{$side}-top", "{$side}-middle", "{$side}-bottom"], static fn(string $n): bool => isset($boxes[$n]));
            if ($present === [] || $sideW < 1.0 || $sideH < 1.0) {
                continue;
            }
            $hasTop = isset($boxes["{$side}-top"]);
            $hasMid = isset($boxes["{$side}-middle"]);
            $hasBot = isset($boxes["{$side}-bottom"]);
            if ($hasMid) {
                $midH = isset($boxes["{$side}-middle"]['height'])
                    ? min($sideH, CssUnit::toPt($boxes["{$side}-middle"]['height'], $sideH))
                    : ($hasTop || $hasBot ? $sideH / 3.0 : $sideH);
                $outerH = ($sideH - $midH) / 2.0;
                $heights = [$outerH, $midH, $outerH];
            } elseif ($hasTop && $hasBot) {
                $heights = [$sideH / 2.0, 0.0, $sideH / 2.0];
            } else {
                $heights = [$hasTop ? $sideH : 0.0, 0.0, $hasBot ? $sideH : 0.0];
            }
            $cells = '';
            foreach (["{$side}-top" => 'top', "{$side}-middle" => 'middle', "{$side}-bottom" => 'bottom'] as $name => $defV) {
                $i = $defV === 'top' ? 0 : ($defV === 'middle' ? 1 : 2);
                if ($heights[$i] < 0.05) {
                    continue;
                }
                $props = $boxes[$name] ?? null;
                $cells .= $props === null
                    ? sprintf('<div style="width:%.3Fpt;height:%.3Fpt"></div>', $sideW, $heights[$i])
                    : self::cell($props, $name, $sideW, $heights[$i], $pageNum, $pageCount, $strings, $title, $running, 'center', $defV);
            }
            $out[] = [
                'html' => sprintf('<div style="width:%.3Fpt;margin:0;padding:0">%s</div>', $sideW, $cells),
                'x' => $x,
                'y' => $m['top'],
                'width' => $sideW,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, string> $props
     */
    private static function cell(array $props, string $name, float $width, float $height, int $pageNum, int $pageCount, ?StringSets $strings, string $title, ?RunningElements $running = null, ?string $defaultAlign = null, string $defaultValign = 'middle'): string
    {
        $content = $props['content'] ?? '';
        $html = '';
        if ($running !== null && stripos($content, 'element(') !== false) {
            // element(name[, mode]) becomes a placeholder replaced by the running element's box; the rest stays text
            foreach (preg_split('/(element\([^)]*\))/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                if (preg_match('/^element\(([^)]*)\)$/i', $piece, $em) === 1) {
                    $args = array_map(static fn(string $a): string => trim($a, " 	'\""), explode(',', $em[1]));
                    $html .= $running->placeholder($args[0], $pageNum - 1, $strings, $args[1] ?? 'first');
                } else {
                    $html .= htmlspecialchars(self::evaluate($piece, $pageNum, $pageCount, $strings, $title), ENT_QUOTES, 'UTF-8');
                }
            }
        } else {
            $html = htmlspecialchars(self::evaluate($content, $pageNum, $pageCount, $strings, $title), ENT_QUOTES, 'UTF-8');
        }

        $align = strtolower($props['text-align'] ?? ($defaultAlign ?? (str_ends_with($name, '-center') ? 'center' : (str_ends_with($name, '-right') || str_ends_with($name, 'right-corner') ? 'right' : 'left'))));
        $valign = match (strtolower($props['vertical-align'] ?? $defaultValign)) {
            'top' => 'flex-start',
            'bottom' => 'flex-end',
            default => 'center',
        };

        $css = '';
        foreach ($props as $p => $v) {
            if ($p === 'content' || $p === 'width' || $p === 'height' || $p === 'vertical-align' || $p === 'text-align') {
                continue;
            }
            if (preg_match('/^(color|font|text-|letter-spacing|word-spacing|line-height|background|border|padding|opacity|white-space|direction)/', $p)) {
                $css .= $p . ':' . str_replace('"', "'", $v) . ';';
            }
        }

        return sprintf(
            '<div style="flex:none;box-sizing:border-box;width:%.3Fpt;height:%.3Fpt;display:flex;align-items:%s;text-align:%s;%s"><div style="width:100%%">%s</div></div>',
            $width,
            $height,
            $valign,
            $align,
            $css,
            $html
        );
    }

    /**
     * Evaluates a margin box `content` value: strings, counter(page), counter(pages), string(name[, mode]).
     */
    public static function evaluate(string $content, int $pageNum, int $pageCount, ?StringSets $strings, string $title = ''): string
    {
        $out = '';
        $len = strlen($content);
        $i = 0;
        while ($i < $len) {
            $c = $content[$i];
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                $s = '';
                while ($j < $len && $content[$j] !== $c) {
                    if ($content[$j] === '\\' && $j + 1 < $len) {
                        if (preg_match('/^[0-9a-fA-F]{1,6}\s?/', substr($content, $j + 1, 7), $hm)) {
                            $s .= mb_chr((int) hexdec(trim($hm[0])), 'UTF-8');
                            $j += 1 + strlen($hm[0]);
                            continue;
                        }
                        $j++;
                    }
                    $s .= $content[$j];
                    $j++;
                }
                $out .= $s;
                $i = $j + 1;
                continue;
            }
            if (preg_match('/^(counter|string|attr|content)\(([^)]*)\)/i', substr($content, $i), $m)) {
                $fn = strtolower($m[1]);
                $args = array_map(static fn(string $a): string => trim($a, " \t'\""), explode(',', $m[2]));
                if ($fn === 'counter') {
                    $out .= match (strtolower($args[0])) {
                        'page' => (string) $pageNum,
                        'pages' => (string) $pageCount,
                        default => '',
                    };
                } elseif ($fn === 'string') {
                    if (strtolower($args[0]) === 'title' || strtolower($args[0]) === 'document-title') {
                        $out .= $title;
                    } elseif ($strings !== null) {
                        $out .= $strings->get($args[0], $pageNum - 1, $args[1] ?? 'first');
                    }
                }
                $i += strlen($m[0]);
                continue;
            }
            $i++;
        }
        return $out;
    }
}
