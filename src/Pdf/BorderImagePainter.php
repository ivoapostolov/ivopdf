<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssUnit;
use IvoPdf\Image\ImageLoader;
use IvoPdf\Layout\LayoutBox;

/**
 * CSS `border-image` (url() images and gradients): nine-slice with `stretch`, `repeat` / `space` (tiled) and `round` edges.
 */
final class BorderImagePainter
{
    public function __construct(
        private PdfRenderer $renderer,
        private ImageLoader $imageLoader,
        private float $pageHeight
    ) {
    }

    /**
     * Paints the border image for a box; returns null when the box has no (usable) border-image.
     */
    public function paint(LayoutBox $box, float $x, float $y, float $w, float $h): ?string
    {
        $source = trim($box->style->get('border-image-source', 'none'));
        if ($source === '' || strtolower($source) === 'none') {
            return null;
        }
        $bt = $box->borderTop;
        $br = $box->borderRight;
        $bb = $box->borderBottom;
        $bl = $box->borderLeft;
        if ($bt + $br + $bb + $bl <= 0.05) {
            return null;
        }
        $fs = $box->fontSizePt;

        // Border image width: numbers multiply the border widths, lengths / percentages are absolute
        $wv = $this->four($box->style->get('border-image-width', '1'));
        $edge = [$bt, $br, $bb, $bl];
        $dest = [];
        foreach ($wv as $i => $tok) {
            $tok = strtolower($tok);
            if ($tok === 'auto') {
                $dest[$i] = $edge[$i];
            } elseif (is_numeric($tok)) {
                $dest[$i] = (float) $tok * $edge[$i];
            } else {
                $dest[$i] = CssUnit::toPt($tok, ($i % 2 === 0) ? $h : $w, $fs);
            }
        }
        [$dt, $dr, $db, $dl] = $dest;
        if ($dl + $dr >= $w || $dt + $db >= $h) {
            return '';
        }

        // Source image dimensions (image px) or the border-box for gradients
        $isGradient = str_contains(strtolower($source), 'gradient(');
        $imgId = null;
        if ($isGradient) {
            $natW = max(1.0, $w);
            $natH = max(1.0, $h);
        } else {
            if (!preg_match('/url\(\s*([^)]+?)\s*\)/i', $source, $um)) {
                return null;
            }
            $img = $this->imageLoader->load(trim($um[1], " \t'\""));
            if ($img === null) {
                return null;
            }
            $imgId = $img['id'];
            $natW = (float) $img['width'];
            $natH = (float) $img['height'];
        }

        // Slice offsets in source px
        $sliceCss = trim($box->style->get('border-image-slice', '100%'));
        $fill = (bool) preg_match('/\bfill\b/i', $sliceCss);
        $sliceCss = trim((string) preg_replace('/\bfill\b/i', '', $sliceCss));
        $sv = $this->four($sliceCss === '' ? '100%' : $sliceCss);
        $slice = [];
        foreach ($sv as $i => $tok) {
            $ref = ($i % 2 === 0) ? $natH : $natW;
            $slice[$i] = str_ends_with($tok, '%') ? ((float) $tok) / 100.0 * $ref : (float) $tok;
        }
        [$st, $sr, $sb, $sl] = $slice;
        $st = min($st, $natH);
        $sb = min($sb, $natH);
        $sl = min($sl, $natW);
        $sr = min($sr, $natW);

        $repeatTok = preg_split('/\s+/', strtolower(trim($box->style->get('border-image-repeat', 'stretch'))), -1, PREG_SPLIT_NO_EMPTY) ?: ['stretch'];
        $repH = $repeatTok[0];
        $repV = $repeatTok[1] ?? $repeatTok[0];

        // Nine regions: [srcX, srcY, srcW, srcH, destX, destY, destW, destH, hMode, vMode]
        $cw = $natW - $sl - $sr;
        $ch = $natH - $st - $sb;
        $mw = $w - $dl - $dr;
        $mh = $h - $dt - $db;
        $regions = [
            [0, 0, $sl, $st, $x, $y, $dl, $dt, 'stretch', 'stretch'],
            [$natW - $sr, 0, $sr, $st, $x + $w - $dr, $y, $dr, $dt, 'stretch', 'stretch'],
            [0, $natH - $sb, $sl, $sb, $x, $y + $h - $db, $dl, $db, 'stretch', 'stretch'],
            [$natW - $sr, $natH - $sb, $sr, $sb, $x + $w - $dr, $y + $h - $db, $dr, $db, 'stretch', 'stretch'],
            [$sl, 0, $cw, $st, $x + $dl, $y, $mw, $dt, $repH, 'stretch'],
            [$sl, $natH - $sb, $cw, $sb, $x + $dl, $y + $h - $db, $mw, $db, $repH, 'stretch'],
            [0, $st, $sl, $ch, $x, $y + $dt, $dl, $mh, 'stretch', $repV],
            [$natW - $sr, $st, $sr, $ch, $x + $w - $dr, $y + $dt, $dr, $mh, 'stretch', $repV],
        ];
        if ($fill) {
            $regions[] = [$sl, $st, $cw, $ch, $x + $dl, $y + $dt, $mw, $mh, $repH, $repV];
        }

        $out = '';
        foreach ($regions as [$sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $hm, $vm]) {
            if ($sw <= 0.0 || $sh <= 0.0 || $dw <= 0.05 || $dh <= 0.05) {
                continue;
            }
            $out .= $this->paintRegion($imgId, $source, $isGradient, $natW, $natH, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $hm, $vm);
        }
        return $out;
    }

    /**
     * Draws one source region into a destination rectangle, stretching or tiling per axis.
     */
    private function paintRegion(
        ?string $imgId,
        string $source,
        bool $isGradient,
        float $natW,
        float $natH,
        float $sx,
        float $sy,
        float $sw,
        float $sh,
        float $dx,
        float $dy,
        float $dw,
        float $dh,
        string $hMode,
        string $vMode
    ): string {
        $scaleX = $dw / $sw;
        $scaleY = $dh / $sh;
        // Tiling keeps the aspect ratio of the other axis (edges: scale given by the border thickness)
        $tileW = $dw;
        $tileH = $dh;
        if ($hMode !== 'stretch') {
            $scaleX = $scaleY;
            $tileW = $sw * $scaleX;
            if ($hMode === 'round' && $tileW > 0.0) {
                $n = max(1, (int) round($dw / $tileW));
                $tileW = $dw / $n;
                $scaleX = $tileW / $sw;
            }
        }
        if ($vMode !== 'stretch') {
            $scaleY = $scaleX;
            $tileH = $sh * $scaleY;
            if ($vMode === 'round' && $tileH > 0.0) {
                $n = max(1, (int) round($dh / $tileH));
                $tileH = $dh / $n;
                $scaleY = $tileH / $sh;
            }
        }
        $tileW = max(0.5, $tileW);
        $tileH = max(0.5, $tileH);

        $out = "q\n" . sprintf("%.2F %.2F %.2F %.2F re W n\n", $dx, $this->pageHeight - ($dy + $dh), $dw, $dh);
        $count = 0;
        // Centre the tile pattern on the region
        $startX = $hMode === 'stretch' ? $dx : $this->tileStart($dx, $dw, $tileW);
        $startY = $vMode === 'stretch' ? $dy : $this->tileStart($dy, $dh, $tileH);
        for ($ty = $startY; $ty < $dy + $dh - 0.01; $ty += $tileH) {
            for ($tx = $startX; $tx < $dx + $dw - 0.01; $tx += $tileW) {
                if (++$count > 600) {
                    break 2;
                }
                // Full image placed so that the source region lands on the tile
                $ix = $tx - $sx * $scaleX;
                $iy = $ty - $sy * $scaleY;
                $iw = $natW * $scaleX;
                $ih = $natH * $scaleY;
                $out .= "q\n" . sprintf("%.2F %.2F %.2F %.2F re W n\n", $tx, $this->pageHeight - ($ty + $tileH), $tileW, $tileH);
                if ($isGradient) {
                    $out .= $this->renderer->paintGradientCss($source, $ix, $iy, $iw, $ih);
                } else {
                    $out .= sprintf("%.3F 0 0 %.3F %.3F %.3F cm /%s Do\n", $iw, $ih, $ix, $this->pageHeight - ($iy + $ih), $imgId);
                }
                $out .= "Q\n";
            }
        }
        return $out . "Q\n";
    }

    /**
     * First tile origin so that the repeated tiles are centred on the span.
     */
    private function tileStart(float $origin, float $span, float $tile): float
    {
        $off = ($span - $tile) / 2.0;
        return $off > 0.0 ? $origin + $off - ceil($off / $tile) * $tile : $origin + $off;
    }

    /**
     * Expands a 1-4 value list (CSS box shorthand order top right bottom left).
     *
     * @return list<string>
     */
    private function four(string $css): array
    {
        $v = preg_split('/\s+/', trim($css), -1, PREG_SPLIT_NO_EMPTY) ?: ['1'];
        return match (count($v)) {
            1 => [$v[0], $v[0], $v[0], $v[0]],
            2 => [$v[0], $v[1], $v[0], $v[1]],
            3 => [$v[0], $v[1], $v[2], $v[1]],
            default => [$v[0], $v[1], $v[2], $v[3]],
        };
    }
}
