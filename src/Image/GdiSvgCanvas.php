<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * A small GDI device-context emulation that records drawing as SVG markup. WMF and EMF converters drive it:
 * mapping modes / window & viewport transforms, world transform, pens, brushes, fonts, clipping rectangles,
 * paths (BeginPath ... FillPath), polygons, rectangles, ellipses, arcs / pies / chords, text and DIB bitmaps.
 */
final class GdiSvgCanvas
{
    public const MM_TEXT = 1;

    /** Millimetres per logical unit for the fixed-scale mapping modes (y axis points up). */
    private const MODE_UNITS_IN = [2 => 1 / 254, 3 => 1 / 2540, 4 => 1 / 100, 5 => 1 / 1000, 6 => 1 / 1440];

    /** Windows charset id => iconv encoding. */
    private const CHARSETS = [
        0 => 'CP1252', 1 => 'CP1252', 161 => 'CP1253', 162 => 'CP1254', 163 => 'CP1258', 177 => 'CP1255', 178 => 'CP1256',
        186 => 'CP1257', 204 => 'CP1251', 238 => 'CP1250', 222 => 'CP874', 128 => 'CP932', 134 => 'CP936', 136 => 'CP950', 129 => 'CP949',
    ];

    private string $body = '';
    private int $clipCounter = 0;
    private string $defs = '';

    /** Whether any drawing has happened (window / viewport changes afterwards are ignored). */
    private bool $drawn = false;

    private int $mapMode = self::MM_TEXT;
    /** @var array{0: float, 1: float} */
    private array $winOrg = [0.0, 0.0];
    /** @var array{0: float, 1: float} */
    private array $winExt = [1.0, 1.0];
    /** @var array{0: float, 1: float} */
    private array $vpOrg = [0.0, 0.0];
    /** @var array{0: float, 1: float} */
    private array $vpExt = [1.0, 1.0];
    private bool $winExtSet = false;
    private bool $vpExtSet = false;
    /** Device pixels per inch used by physical mapping modes. */
    private float $deviceDpi = 96.0;

    /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} */
    private array $world = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @var array{style: int, width: float, color: string}|null */
    private ?array $pen = ['style' => 0, 'width' => 0.0, 'color' => '#000000'];
    /** @var array{style: int, color: string, hatch: int}|null */
    private ?array $brush = ['style' => 0, 'color' => '#ffffff', 'hatch' => 0];
    /** @var array{height: float, weight: int, italic: bool, underline: bool, strike: bool, face: string, charset: int, escapement: float} */
    private array $font = ['height' => 12.0, 'weight' => 400, 'italic' => false, 'underline' => false, 'strike' => false, 'face' => 'Arial', 'charset' => 0, 'escapement' => 0.0];
    private string $textColor = '#000000';
    private string $bkColor = '#ffffff';
    private bool $bkOpaque = false;
    private int $textAlign = 0;
    private bool $evenOdd = false;
    /** @var array{0: float, 1: float}|null */
    private ?array $curPos = null;
    /** @var array{0: float, 1: float, 2: float, 3: float}|null clip rectangle in logical units (x0, y0, x1, y1) */
    private ?array $clipRect = null;
    private ?string $clipId = null;

    private ?string $pathD = null;

    /** @var list<array<string, mixed>> */
    private array $stack = [];

    /** @var array{0: float, 1: float, 2: float, 3: float}|null device-space bounds of everything drawn (min/max) */
    private ?array $bounds = null;

    public function setDeviceDpi(float $dpi): void
    {
        $this->deviceDpi = $dpi > 1.0 ? $dpi : 96.0;
    }

    public function setMapMode(int $mode): void
    {
        if (!$this->drawn) {
            $this->mapMode = $mode;
        }
    }

    public function setWindowOrg(float $x, float $y): void
    {
        if (!$this->drawn) {
            $this->winOrg = [$x, $y];
        }
    }

    public function setWindowExt(float $x, float $y): void
    {
        if (!$this->drawn && $x != 0.0 && $y != 0.0) {
            $this->winExt = [$x, $y];
            $this->winExtSet = true;
        }
    }

    public function setViewportOrg(float $x, float $y): void
    {
        if (!$this->drawn) {
            $this->vpOrg = [$x, $y];
        }
    }

    public function setViewportExt(float $x, float $y): void
    {
        if (!$this->drawn && $x != 0.0 && $y != 0.0) {
            $this->vpExt = [$x, $y];
            $this->vpExtSet = true;
        }
    }

    public function hasWindowExt(): bool
    {
        return $this->winExtSet;
    }

    /** @return array{0: float, 1: float} */
    public function windowExt(): array
    {
        return $this->winExt;
    }

    /** @return array{0: float, 1: float} */
    public function windowOrg(): array
    {
        return $this->winOrg;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m
     */
    public function setWorldTransform(array $m): void
    {
        $this->world = $m;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m
     * @param int $mode 1 identity, 2 left multiply, 3 right multiply, 4 set
     */
    public function modifyWorldTransform(array $m, int $mode): void
    {
        $this->world = match ($mode) {
            1 => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            2 => self::mul($m, $this->world),
            3 => self::mul($this->world, $m),
            4 => $m,
            default => $this->world,
        };
    }

    /**
     * Affine product: applies $a first, then $b (SVG matrix order [a b c d e f]).
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    public static function colorRef(int $c): string
    {
        return sprintf('#%02x%02x%02x', $c & 0xFF, ($c >> 8) & 0xFF, ($c >> 16) & 0xFF);
    }

    public function setPolyFillMode(int $mode): void
    {
        $this->evenOdd = $mode === 1;
    }

    public function setTextColor(string $hex): void
    {
        $this->textColor = $hex;
    }

    public function setBkColor(string $hex): void
    {
        $this->bkColor = $hex;
    }

    public function setBkMode(int $mode): void
    {
        $this->bkOpaque = $mode === 2;
    }

    public function setTextAlign(int $align): void
    {
        $this->textAlign = $align;
    }

    /**
     * @param array{style: int, width: float, color: string}|null $pen null = no pen
     */
    public function selectPen(?array $pen): void
    {
        $this->pen = $pen;
    }

    /**
     * @param array{style: int, color: string, hatch: int}|null $brush null = hollow brush
     */
    public function selectBrush(?array $brush): void
    {
        $this->brush = $brush;
    }

    /**
     * @param array{height: float, weight: int, italic: bool, underline: bool, strike: bool, face: string, charset: int, escapement: float} $font
     */
    public function selectFont(array $font): void
    {
        $this->font = $font;
    }

    public function save(): void
    {
        $this->stack[] = [
            $this->pen, $this->brush, $this->font, $this->textColor, $this->bkColor, $this->bkOpaque, $this->textAlign,
            $this->evenOdd, $this->clipRect, $this->clipId, $this->world,
        ];
    }

    /**
     * @param int $n negative = relative to the top of the stack (-1 = last save); positive = absolute index (1 based)
     */
    public function restore(int $n): void
    {
        $count = count($this->stack);
        $idx = $n < 0 ? $count + $n : $n - 1;
        if ($idx < 0 || $idx >= $count) {
            return;
        }
        [$this->pen, $this->brush, $this->font, $this->textColor, $this->bkColor, $this->bkOpaque, $this->textAlign,
            $this->evenOdd, $this->clipRect, $this->clipId, $this->world] = $this->stack[$idx];
        $this->stack = array_slice($this->stack, 0, $idx);
    }

    public function intersectClipRect(float $l, float $t, float $r, float $b): void
    {
        $rect = [min($l, $r), min($t, $b), max($l, $r), max($t, $b)];
        if ($this->clipRect !== null) {
            $rect = [max($rect[0], $this->clipRect[0]), max($rect[1], $this->clipRect[1]), min($rect[2], $this->clipRect[2]), min($rect[3], $this->clipRect[3])];
        }
        $this->clipRect = $rect;
        $id = 'c' . (++$this->clipCounter);
        $this->defs .= sprintf(
            '<clipPath id="%s"><rect x="%s" y="%s" width="%s" height="%s"/></clipPath>',
            $id,
            self::n($rect[0]),
            self::n($rect[1]),
            self::n(max(0.0, $rect[2] - $rect[0])),
            self::n(max(0.0, $rect[3] - $rect[1]))
        );
        $this->clipId = $id;
    }

    // ------------------------------------------------------------------
    // Shapes
    // ------------------------------------------------------------------

    public function moveTo(float $x, float $y): void
    {
        $this->curPos = [$x, $y];
        if ($this->pathD !== null) {
            $this->pathD .= 'M' . self::n($x) . ' ' . self::n($y) . ' ';
        }
    }

    public function lineTo(float $x, float $y): void
    {
        $from = $this->curPos ?? [$x, $y];
        $this->track($from[0], $from[1]);
        $this->track($x, $y);
        if ($this->pathD !== null) {
            $this->pathD .= 'L' . self::n($x) . ' ' . self::n($y) . ' ';
        } else {
            $this->emit('M' . self::n($from[0]) . ' ' . self::n($from[1]) . 'L' . self::n($x) . ' ' . self::n($y), false, true);
        }
        $this->curPos = [$x, $y];
    }

    /**
     * @param list<array{0: float, 1: float}> $pts
     */
    public function polygon(array $pts): void
    {
        if (count($pts) < 2) {
            return;
        }
        $this->trackAll($pts);
        $this->shape(self::polyD($pts, true), true, true);
    }

    /**
     * @param list<array{0: float, 1: float}> $pts
     */
    public function polyline(array $pts, bool $continueFromCurrent = false): void
    {
        if ($continueFromCurrent && $this->curPos !== null) {
            array_unshift($pts, $this->curPos);
        }
        if (count($pts) < 2) {
            return;
        }
        $this->trackAll($pts);
        $this->shape(self::polyD($pts, false), false, true);
        $last = $pts[count($pts) - 1];
        $this->curPos = [$last[0], $last[1]];
    }

    /**
     * Poly-Bézier: points are [c1, c2, p] triples following the start point (the first point when not continuing).
     *
     * @param list<array{0: float, 1: float}> $pts
     */
    public function polyBezier(array $pts, bool $continueFromCurrent): void
    {
        if ($continueFromCurrent) {
            if ($this->curPos === null) {
                return;
            }
            array_unshift($pts, $this->curPos);
        }
        if (count($pts) < 4) {
            return;
        }
        $this->trackAll($pts);
        $d = 'M' . self::n($pts[0][0]) . ' ' . self::n($pts[0][1]);
        for ($i = 1; $i + 2 < count($pts); $i += 3) {
            $d .= sprintf('C%s %s %s %s %s %s', self::n($pts[$i][0]), self::n($pts[$i][1]), self::n($pts[$i + 1][0]), self::n($pts[$i + 1][1]), self::n($pts[$i + 2][0]), self::n($pts[$i + 2][1]));
        }
        $last = $pts[count($pts) - 1];
        $this->curPos = [$last[0], $last[1]];
        if ($this->pathD !== null) {
            $this->pathD .= $d . ' ';
            return;
        }
        $this->emit($d, false, true);
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $polys
     */
    public function polyPolygon(array $polys): void
    {
        $d = '';
        foreach ($polys as $pts) {
            if (count($pts) >= 2) {
                $this->trackAll($pts);
                $d .= self::polyD($pts, true) . ' ';
            }
        }
        if ($d !== '') {
            $this->shape($d, true, true);
        }
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $polys
     */
    public function polyPolyline(array $polys): void
    {
        foreach ($polys as $pts) {
            $this->polyline($pts);
        }
    }

    public function rect(float $l, float $t, float $r, float $b): void
    {
        $x0 = min($l, $r);
        $x1 = max($l, $r);
        $y0 = min($t, $b);
        $y1 = max($t, $b);
        $this->trackBox($x0, $y0, $x1, $y1);
        $this->shape(sprintf('M%s %sH%sV%sH%sZ', self::n($x0), self::n($y0), self::n($x1), self::n($y1), self::n($x0)), true, true);
    }

    public function roundRect(float $l, float $t, float $r, float $b, float $cw, float $ch): void
    {
        $x0 = min($l, $r);
        $x1 = max($l, $r);
        $y0 = min($t, $b);
        $y1 = max($t, $b);
        $rx = min(abs($cw) / 2.0, ($x1 - $x0) / 2.0);
        $ry = min(abs($ch) / 2.0, ($y1 - $y0) / 2.0);
        $this->trackBox($x0, $y0, $x1, $y1);
        $a = static fn(float $x, float $y): string => sprintf('A%s %s 0 0 1 %s %s', self::n($rx), self::n($ry), self::n($x), self::n($y));
        $d = 'M' . self::n($x0 + $rx) . ' ' . self::n($y0) . 'H' . self::n($x1 - $rx) . $a($x1, $y0 + $ry)
            . 'V' . self::n($y1 - $ry) . $a($x1 - $rx, $y1) . 'H' . self::n($x0 + $rx) . $a($x0, $y1 - $ry)
            . 'V' . self::n($y0 + $ry) . $a($x0 + $rx, $y0) . 'Z';
        $this->shape($d, true, true);
    }

    public function ellipse(float $l, float $t, float $r, float $b): void
    {
        $cx = ($l + $r) / 2.0;
        $cy = ($t + $b) / 2.0;
        $rx = abs($r - $l) / 2.0;
        $ry = abs($b - $t) / 2.0;
        if ($rx <= 0 || $ry <= 0) {
            return;
        }
        $this->trackBox($cx - $rx, $cy - $ry, $cx + $rx, $cy + $ry);
        $this->shape(self::ellipseD($cx, $cy, $rx, $ry), true, true);
    }

    /**
     * Arc / pie / chord of the ellipse inscribed in the box, drawn counter-clockwise from the start to the end radial.
     *
     * @param 'arc'|'pie'|'chord' $kind
     */
    public function arc(string $kind, float $l, float $t, float $r, float $b, float $sx, float $sy, float $ex, float $ey): void
    {
        $cx = ($l + $r) / 2.0;
        $cy = ($t + $b) / 2.0;
        $rx = abs($r - $l) / 2.0;
        $ry = abs($b - $t) / 2.0;
        if ($rx <= 0 || $ry <= 0) {
            return;
        }
        $this->trackBox($cx - $rx, $cy - $ry, $cx + $rx, $cy + $ry);
        $a1 = atan2(($sy - $cy) / $ry, ($sx - $cx) / $rx);
        $a2 = atan2(($ey - $cy) / $ry, ($ex - $cx) / $rx);
        if (abs($a1 - $a2) < 1e-9) {
            $this->shape(self::ellipseD($cx, $cy, $rx, $ry), $kind !== 'arc', true);
            return;
        }
        $span = fmod($a1 - $a2 + 4 * M_PI, 2 * M_PI); // counter-clockwise on screen = decreasing angle (y down)
        $x1 = $cx + $rx * cos($a1);
        $y1 = $cy + $ry * sin($a1);
        $x2 = $cx + $rx * cos($a2);
        $y2 = $cy + $ry * sin($a2);
        $d = sprintf('M%s %sA%s %s 0 %d 0 %s %s', self::n($x1), self::n($y1), self::n($rx), self::n($ry), $span > M_PI ? 1 : 0, self::n($x2), self::n($y2));
        if ($kind === 'pie') {
            $d .= 'L' . self::n($cx) . ' ' . self::n($cy) . 'Z';
        } elseif ($kind === 'chord') {
            $d .= 'Z';
        }
        $this->shape($d, $kind !== 'arc', true);
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    public function beginPath(): void
    {
        $this->pathD = '';
    }

    public function endPath(): void
    {
        // The path stays available for FillPath / StrokePath / SelectClipPath
    }

    public function closeFigure(): void
    {
        if ($this->pathD !== null) {
            $this->pathD .= 'Z ';
        }
    }

    /**
     * Paints the accumulated path and clears it.
     */
    public function paintPath(bool $fill, bool $stroke): void
    {
        $d = $this->pathD;
        $this->pathD = null;
        if ($d !== null && trim($d) !== '') {
            $this->emit(trim($d), $fill, $stroke);
        }
    }

    public function discardPath(): void
    {
        $this->pathD = null;
    }

    // ------------------------------------------------------------------
    // Text and bitmaps
    // ------------------------------------------------------------------

    /**
     * @param array{height: float, weight: int, italic: bool, underline: bool, strike: bool, face: string, charset: int, escapement: float} $font
     */
    public static function fontFromParts(float $height, int $weight, bool $italic, bool $underline, bool $strike, string $face, int $charset, float $escapement): array
    {
        return ['height' => $height, 'weight' => $weight, 'italic' => $italic, 'underline' => $underline, 'strike' => $strike, 'face' => $face, 'charset' => $charset, 'escapement' => $escapement];
    }

    /**
     * Converts ANSI text of a Windows charset to UTF-8.
     */
    public function ansiToUtf8(string $text): string
    {
        $enc = self::CHARSETS[$this->font['charset']] ?? 'CP1252';
        $out = @iconv($enc, 'UTF-8//IGNORE', $text);
        return $out === false ? (string) @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1') : $out;
    }

    public function text(float $x, float $y, string $utf8): void
    {
        $utf8 = rtrim($utf8, "\0");
        if (trim($utf8) === '') {
            return;
        }
        $this->touch();
        $size = abs($this->font['height']);
        if ($size < 0.01) {
            $size = 12.0;
        }
        $align = $this->textAlign;
        if (($align & 1) !== 0 && $this->curPos !== null) { // TA_UPDATECP
            [$x, $y] = $this->curPos;
        }
        $anchor = match ($align & 6) {
            6 => 'middle',
            2 => 'end',
            default => 'start',
        };
        $vert = $align & 24;
        $by = $y;
        if ($vert === 0) { // TA_TOP: reference point is the top of the cell
            $by = $y + $size * 0.85;
        } elseif ($vert === 8) { // TA_BOTTOM
            $by = $y - $size * 0.2;
        }
        $rot = '';
        if ($this->font['escapement'] != 0.0) {
            $rot = sprintf(' transform="rotate(%s %s %s)"', self::n(-$this->font['escapement'] / 10.0), self::n($x), self::n($by));
        }
        $this->track($x, $by - $size);
        $this->track($x + $size * mb_strlen($utf8) * 0.55, $by + $size * 0.25);
        $this->body .= sprintf(
            '<g%s%s><text x="%s" y="%s" font-family="%s" font-size="%s" font-weight="%d" font-style="%s" text-anchor="%s" fill="%s"%s>%s</text></g>' . "\n",
            $this->worldAttr(),
            $this->clipAttr(),
            self::n($x),
            self::n($by),
            htmlspecialchars($this->font['face'] !== '' ? $this->font['face'] . ', sans-serif' : 'sans-serif', ENT_QUOTES | ENT_XML1),
            self::n($size),
            $this->font['weight'] >= 600 ? 700 : 400,
            $this->font['italic'] ? 'italic' : 'normal',
            $anchor,
            $this->textColor,
            $rot,
            htmlspecialchars($utf8, ENT_XML1 | ENT_SUBSTITUTE)
        );
    }

    /**
     * Draws a DIB (BITMAPINFO + bits) as an image in the destination rectangle.
     */
    public function dib(string $dib, float $x, float $y, float $w, float $h): void
    {
        $bmp = self::dibToBmp($dib);
        if ($bmp === null || $w == 0.0 || $h == 0.0) {
            return;
        }
        $this->touch();
        $x0 = min($x, $x + $w);
        $y0 = min($y, $y + $h);
        $this->track($x0, $y0);
        $this->track($x0 + abs($w), $y0 + abs($h));
        $this->body .= sprintf(
            '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="none" href="data:image/bmp;base64,%s"%s%s/>' . "\n",
            self::n($x0),
            self::n($y0),
            self::n(abs($w)),
            self::n(abs($h)),
            base64_encode($bmp),
            $this->worldAttr(),
            $this->clipAttr()
        );
    }

    /**
     * Wraps a packed DIB into a BMP file image (adds the 14-byte file header).
     */
    public static function dibToBmp(string $dib): ?string
    {
        if (strlen($dib) < 12) {
            return null;
        }
        $hdr = unpack('V', $dib)[1];
        if ($hdr === 12) {
            $bpp = unpack('v', substr($dib, 10, 2))[1];
            $colors = $bpp <= 8 ? (1 << $bpp) : 0;
            $palSize = $colors * 3;
        } elseif ($hdr >= 40 && strlen($dib) >= 40) {
            $bpp = unpack('v', substr($dib, 14, 2))[1];
            $comp = unpack('V', substr($dib, 16, 4))[1];
            $used = unpack('V', substr($dib, 32, 4))[1];
            $colors = $used > 0 ? $used : ($bpp <= 8 ? (1 << $bpp) : 0);
            $palSize = $colors * 4 + (($comp === 3 && $hdr === 40) ? 12 : 0);
        } else {
            return null;
        }
        $offset = 14 + $hdr + $palSize;
        return 'BM' . pack('VvvV', 14 + strlen($dib), 0, 0, $offset) . $dib;
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /**
     * Assembles the SVG document.
     *
     * @param array{0: float, 1: float}|null $viewSize explicit [width, height] of the view box in device units
     * @param array{0: float, 1: float, 2: float, 3: float}|null $viewBox explicit [x, y, w, h] device-space box
     */
    public function toSvg(float $widthPx, float $heightPx, ?array $viewBox = null): ?string
    {
        if ($this->body === '') {
            return null;
        }
        $vb = $viewBox ?? ($this->bounds !== null
            ? [$this->bounds[0], $this->bounds[1], max(1.0, $this->bounds[2] - $this->bounds[0]), max(1.0, $this->bounds[3] - $this->bounds[1])]
            : [0.0, 0.0, $widthPx, $heightPx]);
        if ($widthPx <= 0 || $heightPx <= 0) {
            $widthPx = $vb[2];
            $heightPx = $vb[3];
        }
        $scale = $vb[2] > 0 ? $widthPx / $vb[2] : 1.0;
        $hair = self::n($scale > 0 ? 1.0 / $scale : 1.0);
        $body = str_replace('__HAIR__', $hair, $this->body);

        [$a, $b, $c, $d, $e, $f] = $this->baseMatrix();
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="%s %s %s %s" preserveAspectRatio="none">%s<g transform="matrix(%s %s %s %s %s %s)">%s</g></svg>',
            self::n($widthPx),
            self::n($heightPx),
            self::n($vb[0]),
            self::n($vb[1]),
            self::n($vb[2]),
            self::n($vb[3]),
            $this->defs !== '' ? '<defs>' . $this->defs . '</defs>' : '',
            self::n($a),
            self::n($b),
            self::n($c),
            self::n($d),
            self::n($e),
            self::n($f),
            $body
        );
    }

    /**
     * Device-space box [x, y, w, h] described by the window / viewport extents, or null when none was set.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function windowViewBox(): ?array
    {
        if ($this->vpExtSet) {
            $w = $this->vpExt[0];
            $h = $this->vpExt[1];
            return [min($this->vpOrg[0], $this->vpOrg[0] + $w), min($this->vpOrg[1], $this->vpOrg[1] + $h), abs($w), abs($h)];
        }
        if ($this->winExtSet) {
            return [$this->vpOrg[0], $this->vpOrg[1], abs($this->winExt[0]), abs($this->winExt[1])];
        }
        return null;
    }

    /**
     * Logical → device mapping (window / viewport / mapping mode).
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private function baseMatrix(): array
    {
        $sx = 1.0;
        $sy = 1.0;
        if ($this->mapMode >= 2 && $this->mapMode <= 6) {
            $k = self::MODE_UNITS_IN[$this->mapMode] * $this->deviceDpi;
            $sx = $k;
            $sy = -$k;
        } elseif ($this->vpExtSet) {
            $sx = $this->vpExt[0] / $this->winExt[0];
            $sy = $this->vpExt[1] / $this->winExt[1];
        } elseif ($this->winExtSet) {
            $sx = $this->winExt[0] < 0 ? -1.0 : 1.0; // negative extents just mirror the axis
            $sy = $this->winExt[1] < 0 ? -1.0 : 1.0;
        }
        return [$sx, 0.0, 0.0, $sy, $this->vpOrg[0] - $sx * $this->winOrg[0], $this->vpOrg[1] - $sy * $this->winOrg[1]];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param list<array{0: float, 1: float}> $pts
     */
    private function trackAll(array $pts): void
    {
        foreach ($pts as $p) {
            $this->track($p[0], $p[1]);
        }
    }

    private function trackBox(float $x0, float $y0, float $x1, float $y1): void
    {
        $this->track($x0, $y0);
        $this->track($x1, $y1);
    }

    private function touch(): void
    {
        $this->drawn = true;
    }

    private function track(float $x, float $y): void
    {
        $this->drawn = true;
        // Logical → world → device
        $w = $this->world;
        $wx = $w[0] * $x + $w[2] * $y + $w[4];
        $wy = $w[1] * $x + $w[3] * $y + $w[5];
        $m = $this->baseMatrix();
        $dx = $m[0] * $wx + $m[2] * $wy + $m[4];
        $dy = $m[1] * $wx + $m[3] * $wy + $m[5];
        $b = $this->bounds;
        $this->bounds = $b === null ? [$dx, $dy, $dx, $dy] : [min($b[0], $dx), min($b[1], $dy), max($b[2], $dx), max($b[3], $dy)];
    }

    /**
     * Draws a shape, or appends it to the open path.
     */
    private function shape(string $d, bool $fill, bool $stroke): void
    {
        if ($this->pathD !== null) {
            $this->pathD .= $d . ' ';
            return;
        }
        $this->emit($d, $fill, $stroke);
    }

    private function emit(string $d, bool $fill, bool $stroke): void
    {
        $fillAttr = 'none';
        $extra = '';
        if ($fill && $this->brush !== null && $this->brush['style'] !== 1) {
            $fillAttr = $this->brush['color'];
            if ($this->brush['style'] === 2) { // hatched: approximate with a translucent fill
                $extra .= ' fill-opacity="0.4"';
            }
        }
        $strokeAttr = 'none';
        $pen = $this->pen;
        if ($stroke && $pen !== null && $pen['style'] !== 5) {
            $strokeAttr = $pen['color'];
            $w = $pen['width'] > 0 ? self::n($pen['width']) : '__HAIR__';
            $extra .= ' stroke-width="' . $w . '" stroke-linejoin="round" stroke-linecap="round"';
            $dash = match ($pen['style'] & 0xF) {
                1 => '6 3',
                2 => '1.5 3',
                3 => '6 3 1.5 3',
                4 => '6 3 1.5 3 1.5 3',
                default => '',
            };
            if ($dash !== '') {
                $unit = $pen['width'] > 0 ? $pen['width'] : 1.0;
                $parts = array_map(static fn(string $v): string => self::n((float) $v * $unit), explode(' ', $dash));
                $extra .= ' stroke-dasharray="' . implode(' ', $parts) . '"';
            }
        }
        if ($fillAttr === 'none' && $strokeAttr === 'none') {
            return;
        }
        $this->body .= sprintf(
            '<path d="%s" fill="%s" stroke="%s"%s%s%s%s/>' . "\n",
            $d,
            $fillAttr,
            $strokeAttr,
            $fillAttr !== 'none' && $this->evenOdd ? ' fill-rule="evenodd"' : '',
            $extra,
            $this->worldAttr(),
            $this->clipAttr()
        );
    }

    private function worldAttr(): string
    {
        $w = $this->world;
        if ($w === [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]) {
            return '';
        }
        return sprintf(' transform="matrix(%s %s %s %s %s %s)"', self::n($w[0]), self::n($w[1]), self::n($w[2]), self::n($w[3]), self::n($w[4]), self::n($w[5]));
    }

    private function clipAttr(): string
    {
        return $this->clipId !== null ? ' clip-path="url(#' . $this->clipId . ')"' : '';
    }

    /**
     * @param list<array{0: float, 1: float}> $pts
     */
    private static function polyD(array $pts, bool $close): string
    {
        $d = '';
        foreach ($pts as $i => $p) {
            $d .= ($i === 0 ? 'M' : 'L') . self::n($p[0]) . ' ' . self::n($p[1]);
        }
        return $d . ($close ? 'Z' : '');
    }

    private static function ellipseD(float $cx, float $cy, float $rx, float $ry): string
    {
        return sprintf(
            'M%s %sA%s %s 0 1 0 %s %sA%s %s 0 1 0 %s %sZ',
            self::n($cx - $rx),
            self::n($cy),
            self::n($rx),
            self::n($ry),
            self::n($cx + $rx),
            self::n($cy),
            self::n($rx),
            self::n($ry),
            self::n($cx - $rx),
            self::n($cy)
        );
    }

    public static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
