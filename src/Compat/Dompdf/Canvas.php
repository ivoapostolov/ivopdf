<?php

declare(strict_types=1);

namespace IvoPdf\Compat\Dompdf;

/**
 * Drop-in for the `Dompdf\Canvas` that `$dompdf->getCanvas()` returns. Everything drawn here becomes an overlay layer
 * of the page (absolutely positioned HTML / inline SVG laid out by the IvoPDF engine), coordinates are dompdf's:
 * points from the top-left corner of the page.
 *
 *  - page_text() / page_line(): drawn on every page, `{PAGE_NUM}` / `{PAGE_COUNT}` substituted
 *  - page_script(callable): called once per page with ($pageNumber, $pageCount, $canvas, $fontMetrics); the drawing
 *    calls it makes (text(), line(), rect(), image() ...) land on that page. Strings of PHP code are NOT executed.
 *  - text() / line() / rect() / image() ... outside page_script draw on the current page (the last one after render()).
 */
final class Canvas
{
    private float $width;
    private float $height;
    private int $pageCount = 0;
    private int $current = 1;
    private int $version = 0;
    private bool $rendering = false;

    /** @var list<array<string, mixed>> operations repeated on every page */
    private array $everyPage = [];
    /** @var list<callable> */
    private array $scripts = [];
    /** @var array<int, list<array<string, mixed>>> page number => operations */
    private array $perPage = [];
    /** @var list<array<string, mixed>> operations collected while a page is being built */
    private array $buffer = [];
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private FontMetrics $metrics, float $width = 612.0, float $height = 792.0)
    {
        $this->width = $width;
        $this->height = $height;
    }

    /** Changes whenever the application drew something (Dompdf re-renders when it changed after render()). */
    public function version(): int
    {
        return $this->version;
    }

    public function hasContent(): bool
    {
        return $this->everyPage !== [] || $this->scripts !== [] || $this->perPage !== [];
    }

    /** @return list<string> problems found while building overlays (e.g. a PHP-code string passed to page_script) */
    public function takeWarnings(): array
    {
        $w = $this->warnings;
        $this->warnings = [];
        return $w;
    }

    public function setPageSize(float $w, float $h): void
    {
        $this->width = $w;
        $this->height = $h;
    }

    public function setPageCount(int $count): void
    {
        $this->pageCount = $count;
        $this->current = max(1, $count);
    }

    public function get_width(): float
    {
        return $this->width;
    }

    public function get_height(): float
    {
        return $this->height;
    }

    public function get_page_number(): int
    {
        return $this->current;
    }

    public function get_page_count(): int
    {
        return $this->pageCount;
    }

    public function set_page_number(int $n): void
    {
        $this->current = max(1, $n);
    }

    public function set_page_count(int $n): void
    {
        $this->pageCount = $n;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function get_cpdf(): self
    {
        return $this;
    }

    // ------------------------------------------------------------------
    // every-page drawing
    // ------------------------------------------------------------------

    /**
     * @param mixed $font    descriptor from FontMetrics::getFont() (or a plain family name)
     * @param mixed $color   [r, g, b] 0..1, [c, m, y, k] / ['c' => ..], '#rrggbb', optionally with 'alpha'
     */
    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $wordSpace = 0.0, $charSpace = 0.0, $angle = 0.0): void
    {
        $this->version++;
        $this->everyPage[] = $this->textOp($x, $y, (string) $text, $font, $size, $color, $wordSpace, $charSpace, $angle);
    }

    public function page_line($x1, $y1, $x2, $y2, $color = [0, 0, 0], $width = 1.0, $style = []): void
    {
        $this->version++;
        $this->everyPage[] = ['op' => 'line', 'x1' => (float) $x1, 'y1' => (float) $y1, 'x2' => (float) $x2, 'y2' => (float) $y2, 'color' => $color, 'w' => (float) $width, 'dash' => (array) $style];
    }

    /** dompdf: `$code` is PHP source when isPhpEnabled; IvoPDF only accepts a callable (security). */
    public function page_script($callback): void
    {
        $this->version++;
        if (is_callable($callback) && !(is_string($callback) && preg_match('~^[\w\\\\:]+$~', $callback) !== 1)) {
            $this->scripts[] = $callback;
            return;
        }
        $this->warnings[] = 'Canvas::page_script() received a string of PHP code; IvoPDF never executes PHP code from documents - pass a PHP callable (function ($pageNumber, $pageCount, $canvas, $fontMetrics) { ... }) or use page_text().';
    }

    // ------------------------------------------------------------------
    // current-page drawing
    // ------------------------------------------------------------------

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $wordSpace = 0.0, $charSpace = 0.0, $angle = 0.0): void
    {
        $this->add($this->textOp($x, $y, (string) $text, $font, $size, $color, $wordSpace, $charSpace, $angle));
    }

    public function line($x1, $y1, $x2, $y2, $color = [0, 0, 0], $width = 1.0, $style = []): void
    {
        $this->add(['op' => 'line', 'x1' => (float) $x1, 'y1' => (float) $y1, 'x2' => (float) $x2, 'y2' => (float) $y2, 'color' => $color, 'w' => (float) $width, 'dash' => (array) $style]);
    }

    public function rect($x, $y, $w, $h, $color = [0, 0, 0], $width = 1.0, $style = []): void
    {
        $this->add(['op' => 'rect', 'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $w, 'h' => (float) $h, 'color' => $color, 'lw' => (float) $width, 'dash' => (array) $style, 'fill' => false]);
    }

    public function filled_rectangle($x, $y, $w, $h, $color = [0, 0, 0]): void
    {
        $this->add(['op' => 'rect', 'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $w, 'h' => (float) $h, 'color' => $color, 'lw' => 0.0, 'dash' => [], 'fill' => true]);
    }

    public function circle($x, $y, $r, $color = [0, 0, 0], $width = null, $style = [], $fill = false): void
    {
        $this->add(['op' => 'circle', 'x' => (float) $x, 'y' => (float) $y, 'r' => (float) $r, 'color' => $color, 'lw' => (float) ($width ?? 1.0), 'dash' => (array) $style, 'fill' => (bool) $fill]);
    }

    /** @param array<int, float> $points flat list x1, y1, x2, y2, ... */
    public function polygon($points, $color = [0, 0, 0], $width = null, $style = [], $fill = false): void
    {
        $this->add(['op' => 'poly', 'pts' => array_map('floatval', (array) $points), 'color' => $color, 'lw' => (float) ($width ?? 1.0), 'dash' => (array) $style, 'fill' => (bool) $fill]);
    }

    public function image($img, $x, $y, $w, $h, $resolution = 'normal'): void
    {
        $this->add(['op' => 'image', 'src' => (string) $img, 'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $w, 'h' => (float) $h]);
    }

    public function image_jpeg($img, $x, $y, $w, $h): void
    {
        $this->image($img, $x, $y, $w, $h);
    }

    public function open_object(): int
    {
        return 0;
    }

    public function close_object(): void
    {
    }

    public function add_object($object, $where = 'all'): void
    {
    }

    public function set_opacity($opacity, $mode = 'Normal'): void
    {
    }

    public function save(): void
    {
    }

    public function restore(): void
    {
    }

    public function get_text_width($text, $font, $size, $wordSpacing = 0.0, $charSpacing = 0.0): float
    {
        return $this->metrics->getTextWidth((string) $text, $font, (float) $size, (float) $wordSpacing, (float) $charSpacing);
    }

    public function get_font_height($font, $size): float
    {
        return $this->metrics->getFontHeight($font, (float) $size);
    }

    // ------------------------------------------------------------------
    // engine side
    // ------------------------------------------------------------------

    /**
     * Overlay HTML of one page (page-sized relative box); empty string when nothing is drawn on it.
     */
    public function renderPage(int $pageNumber, int $pageCount, float $w, float $h): string
    {
        $this->width = $w;
        $this->height = $h;
        $this->pageCount = $pageCount;
        $saved = [$this->current, $this->version];
        $this->current = $pageNumber;
        $this->rendering = true;
        $this->buffer = [];
        try {
            foreach ($this->everyPage as $op) {
                $this->buffer[] = $op;
            }
            foreach ($this->scripts as $cb) {
                $cb($pageNumber, $pageCount, $this, $this->metrics);
            }
            foreach ($this->perPage[$pageNumber] ?? [] as $op) {
                $this->buffer[] = $op;
            }
            $ops = $this->buffer;
        } finally {
            $this->rendering = false;
            $this->buffer = [];
            [$this->current, $this->version] = $saved; // drawing done by callbacks is not an application change
        }
        return $ops === [] ? '' : $this->toHtml($ops, $pageNumber, $pageCount, $w, $h);
    }

    /** @param array<string, mixed> $op */
    private function add(array $op): void
    {
        if ($this->rendering) {
            $this->buffer[] = $op;
            return;
        }
        $this->version++;
        $this->perPage[$this->current][] = $op;
    }

    /** @return array<string, mixed> */
    private function textOp($x, $y, string $text, $font, $size, $color, $wordSpace, $charSpace, $angle): array
    {
        return ['op' => 'text', 'x' => (float) $x, 'y' => (float) $y, 'text' => $text, 'font' => (string) $font, 'size' => (float) $size, 'color' => $color, 'ws' => (float) $wordSpace, 'cs' => (float) $charSpace, 'angle' => (float) $angle];
    }

    /** @param list<array<string, mixed>> $ops */
    private function toHtml(array $ops, int $pageNumber, int $pageCount, float $w, float $h): string
    {
        $html = '';
        $svg = '';
        $flush = function () use (&$svg, &$html, $w, $h): void {
            if ($svg !== '') {
                $html .= sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2F" height="%2$.2F" viewBox="0 0 %1$.2F %2$.2F" style="position:absolute;left:0;top:0">%3$s</svg>', $w, $h, $svg);
                $svg = '';
            }
        };
        foreach ($ops as $op) {
            switch ($op['op']) {
                case 'text':
                    $flush();
                    $html .= $this->textHtml($op, $pageNumber, $pageCount);
                    break;
                case 'image':
                    $flush();
                    $html .= sprintf(
                        '<img src="%s" style="position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt">',
                        htmlspecialchars($op['src'], ENT_QUOTES, 'UTF-8'),
                        $op['x'],
                        $op['y'],
                        $op['w'],
                        $op['h']
                    );
                    break;
                case 'line':
                    [$rgb, $alpha] = self::color($op['color']);
                    $svg .= sprintf('<line x1="%.2F" y1="%.2F" x2="%.2F" y2="%.2F" stroke="%s" stroke-opacity="%.3F" stroke-width="%.2F"%s/>', $op['x1'], $op['y1'], $op['x2'], $op['y2'], $rgb, $alpha, $op['w'], self::dash($op['dash']));
                    break;
                case 'rect':
                    $svg .= sprintf('<rect x="%.2F" y="%.2F" width="%.2F" height="%.2F"%s/>', $op['x'], $op['y'], $op['w'], $op['h'], self::paint($op));
                    break;
                case 'circle':
                    $svg .= sprintf('<circle cx="%.2F" cy="%.2F" r="%.2F"%s/>', $op['x'], $op['y'], $op['r'], self::paint($op));
                    break;
                case 'poly':
                    $pts = [];
                    for ($i = 0; $i + 1 < count($op['pts']); $i += 2) {
                        $pts[] = sprintf('%.2F,%.2F', $op['pts'][$i], $op['pts'][$i + 1]);
                    }
                    $svg .= '<polygon points="' . implode(' ', $pts) . '"' . self::paint($op) . '/>';
                    break;
            }
        }
        $flush();
        return sprintf('<div style="position:relative;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0;overflow:hidden">%s</div>', $w, $h, $html);
    }

    /** @param array<string, mixed> $op */
    private function textHtml(array $op, int $pageNumber, int $pageCount): string
    {
        $text = strtr($op['text'], ['{PAGE_NUM}' => (string) $pageNumber, '{PAGE_COUNT}' => (string) $pageCount]);
        [$family, $weight, $style] = FontMetrics::parse($op['font']);
        [$rgb, $alpha] = self::color($op['color']);
        $css = sprintf(
            'position:absolute;left:%.2Fpt;top:%.2Fpt;margin:0;padding:0;white-space:pre;line-height:normal;font-family:\'%s\',sans-serif;font-size:%.2Fpt;font-weight:%s;font-style:%s;color:%s',
            $op['x'],
            $op['y'] + 0.23 * $op['size'], // dompdf's y is the top of the text line; a CSS line box starts above the baseline
            str_replace(["'", '"', '\\', ';'], '', $family),
            $op['size'],
            $weight,
            $style,
            $rgb
        );
        if ($op['ws'] != 0.0) {
            $css .= sprintf(';word-spacing:%.2Fpt', $op['ws']);
        }
        if ($op['cs'] != 0.0) {
            $css .= sprintf(';letter-spacing:%.2Fpt', $op['cs']);
        }
        if ($alpha < 1.0) {
            $css .= sprintf(';opacity:%.3F', $alpha);
        }
        if ($op['angle'] != 0.0) {
            $css .= sprintf(';transform-origin:0 0;transform:rotate(%.3Fdeg)', $op['angle']);
        }
        return '<div style="' . $css . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /** @param array<string, mixed> $op */
    private static function paint(array $op): string
    {
        [$rgb, $alpha] = self::color($op['color']);
        if ($op['fill']) {
            return sprintf(' fill="%s" fill-opacity="%.3F" stroke="none"', $rgb, $alpha);
        }
        return sprintf(' fill="none" stroke="%s" stroke-opacity="%.3F" stroke-width="%.2F"%s', $rgb, $alpha, $op['lw'], self::dash($op['dash']));
    }

    /** @param array<int, float|int> $dash */
    private static function dash(array $dash): string
    {
        $d = array_values(array_filter($dash, 'is_numeric'));
        return $d === [] ? '' : ' stroke-dasharray="' . implode(' ', array_map(static fn($v): string => (string) (float) $v, $d)) . '"';
    }

    /**
     * dompdf colors: [r, g, b] (0..1), [c, m, y, k], ['r' => ..], ['c' => ..], 'rrggbb' / '#rrggbb', optional 'alpha'.
     *
     * @return array{0: string, 1: float} [CSS color, alpha]
     */
    public static function color(mixed $c): array
    {
        $alpha = 1.0;
        if (is_string($c)) {
            $c = ltrim(trim($c), '#');
            return preg_match('~^[0-9a-f]{6}$~i', $c) === 1 ? ['#' . $c, 1.0] : ['#000000', 1.0];
        }
        if (!is_array($c)) {
            return ['#000000', 1.0];
        }
        if (isset($c['alpha']) && is_numeric($c['alpha'])) {
            $alpha = max(0.0, min(1.0, (float) $c['alpha']));
        }
        $n = static fn($v): float => max(0.0, min(1.0, (float) $v));
        if (isset($c['c'], $c['m'], $c['y'], $c['k']) || count(array_filter(array_keys($c), 'is_int')) === 4) {
            [$cc, $mm, $yy, $kk] = isset($c['c']) ? [$c['c'], $c['m'], $c['y'], $c['k']] : [$c[0], $c[1], $c[2], $c[3]];
            $r = (1 - $n($cc)) * (1 - $n($kk));
            $g = (1 - $n($mm)) * (1 - $n($kk));
            $b = (1 - $n($yy)) * (1 - $n($kk));
        } elseif (isset($c['r'], $c['g'], $c['b'])) {
            [$r, $g, $b] = [$n($c['r']), $n($c['g']), $n($c['b'])];
        } elseif (isset($c['gray']) || (array_key_exists(0, $c) && !isset($c[1]))) {
            $r = $g = $b = $n($c['gray'] ?? $c[0]);
        } else {
            [$r, $g, $b] = [$n($c[0] ?? 0), $n($c[1] ?? 0), $n($c[2] ?? 0)];
        }
        return [sprintf('rgb(%d,%d,%d)', round($r * 255), round($g * 255), round($b * 255)), $alpha];
    }
}
