<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Converts a Windows Metafile (WMF, placeable or standard) into SVG markup so it renders as vector graphics.
 *
 * Supported records: window / viewport origin & extent, mapping mode, pens, brushes (solid / hollow / hatched as a
 * tint), fonts, Save/RestoreDC, polygon fill mode, MoveTo/LineTo, Polyline, Polygon, PolyPolygon, Rectangle,
 * RoundRect, Ellipse, Arc, Pie, Chord, TextOut, ExtTextOut, IntersectClipRect and DIB bitmaps
 * (StretchDIBits, DibStretchBlt, DibBitBlt). Everything else is ignored.
 */
final class WmfConverter
{
    private const PLACEABLE_KEY = 0x9AC6CDD7;

    public static function isWmf(string $d): bool
    {
        if (strlen($d) < 18) {
            return false;
        }
        if (unpack('V', $d)[1] === self::PLACEABLE_KEY) {
            return true;
        }
        $h = unpack('vtype/vhsize/vver', $d);
        return ($h['type'] === 1 || $h['type'] === 2) && $h['hsize'] === 9 && ($h['ver'] === 0x0100 || $h['ver'] === 0x0300);
    }

    /**
     * @return string|null SVG document, or null when the data is not a usable WMF
     */
    public static function toSvg(string $d): ?string
    {
        if (!self::isWmf($d)) {
            return null;
        }
        $len = strlen($d);
        $pos = 0;
        $bounds = null;
        $inch = 96;
        if (unpack('V', $d)[1] === self::PLACEABLE_KEY) {
            if ($len < 40) {
                return null;
            }
            $b = unpack('s4', substr($d, 6, 8));
            $bounds = [(float) $b[1], (float) $b[2], (float) $b[3], (float) $b[4]];
            $inch = max(1, unpack('v', substr($d, 14, 2))[1]);
            $pos = 22;
        }
        $pos += 18; // standard header

        $cv = new GdiSvgCanvas();
        $cv->setDeviceDpi((float) $inch);
        /** @var array<int, array<string, mixed>|null> $objs */
        $objs = [];
        $count = 0;

        while ($pos + 6 <= $len && $count++ < 200000) {
            $sizeWords = unpack('V', $d, $pos)[1];
            $fn = unpack('v', $d, $pos + 4)[1];
            $size = $sizeWords * 2;
            if ($size < 6 || $pos + $size > $len) {
                break;
            }
            $p = substr($d, $pos + 6, $size - 6);
            $pos += $size;
            if ($fn === 0x0000) {
                break;
            }
            self::record($cv, $objs, $fn, $p);
        }

        $vb = $cv->windowViewBox();
        if ($vb === null && $bounds !== null) {
            $vb = [$bounds[0], $bounds[1], $bounds[2] - $bounds[0], $bounds[3] - $bounds[1]];
        }
        if ($bounds !== null && ($bounds[2] - $bounds[0]) > 0 && ($bounds[3] - $bounds[1]) > 0) {
            $w = ($bounds[2] - $bounds[0]) * 96.0 / $inch;
            $h = ($bounds[3] - $bounds[1]) * 96.0 / $inch;
        } else {
            $w = $vb !== null ? $vb[2] : 0.0;
            $h = $vb !== null ? $vb[3] : 0.0;
        }
        return $cv->toSvg($w, $h, $vb);
    }

    /**
     * @param array<int, array<string, mixed>|null> $objs
     */
    private static function record(GdiSvgCanvas $cv, array &$objs, int $fn, string $p): void
    {
        $s = static fn(int $i): int => ($o = $i * 2) + 2 <= strlen($p) ? unpack('s', $p, $o)[1] : 0;
        $u = static fn(int $i): int => ($o = $i * 2) + 2 <= strlen($p) ? unpack('v', $p, $o)[1] : 0;
        $dw = static fn(int $i): int => ($o = $i * 2) + 4 <= strlen($p) ? unpack('V', $p, $o)[1] : 0;
        $pt = static fn(int $i): array => [(float) $s($i), (float) $s($i + 1)]; // x, y order inside point arrays

        switch ($fn) {
            case 0x0103: // SetMapMode
                $cv->setMapMode($u(0));
                break;
            case 0x020B: // SetWindowOrg (y, x)
                $cv->setWindowOrg((float) $s(1), (float) $s(0));
                break;
            case 0x020C: // SetWindowExt (y, x)
                $cv->setWindowExt((float) $s(1), (float) $s(0));
                break;
            case 0x020D: // SetViewportOrg
                $cv->setViewportOrg((float) $s(1), (float) $s(0));
                break;
            case 0x020E: // SetViewportExt
                $cv->setViewportExt((float) $s(1), (float) $s(0));
                break;
            case 0x0102: // SetBkMode
                $cv->setBkMode($u(0));
                break;
            case 0x0106: // SetPolyFillMode
                $cv->setPolyFillMode($u(0));
                break;
            case 0x012E: // SetTextAlign
                $cv->setTextAlign($u(0));
                break;
            case 0x0209: // SetTextColor
                $cv->setTextColor(GdiSvgCanvas::colorRef($dw(0)));
                break;
            case 0x0201: // SetBkColor
                $cv->setBkColor(GdiSvgCanvas::colorRef($dw(0)));
                break;
            case 0x001E: // SaveDC
                $cv->save();
                break;
            case 0x0127: // RestoreDC
                $cv->restore($s(0));
                break;
            case 0x02FC: // CreateBrushIndirect: style, color(2), hatch
                self::alloc($objs, ['t' => 'brush', 'style' => $u(0), 'color' => GdiSvgCanvas::colorRef($dw(1)), 'hatch' => $u(3)]);
                break;
            case 0x02FA: // CreatePenIndirect: style, width x, width y, color(2)
                self::alloc($objs, ['t' => 'pen', 'style' => $u(0), 'width' => (float) abs($s(1)), 'color' => GdiSvgCanvas::colorRef($dw(3))]);
                break;
            case 0x02FB: // CreateFontIndirect
                $face = '';
                $rest = strlen($p) > 18 ? substr($p, 18) : '';
                $nul = strpos($rest, "\0");
                $face = $nul === false ? $rest : substr($rest, 0, $nul);
                $face = (string) @iconv('CP1252', 'UTF-8//IGNORE', $face);
                $bytes = strlen($p) >= 18 ? array_values(unpack('C8', substr($p, 10, 8))) : [0, 0, 0, 0, 0, 0, 0, 0];
                self::alloc($objs, ['t' => 'font', 'font' => GdiSvgCanvas::fontFromParts(
                    (float) $s(0),
                    $u(4),
                    ($bytes[0] ?? 0) !== 0,
                    ($bytes[1] ?? 0) !== 0,
                    ($bytes[2] ?? 0) !== 0,
                    $face,
                    $bytes[3] ?? 0,
                    (float) $s(2)
                )]);
                break;
            case 0x00F7: // CreatePalette
            case 0x01F9: // CreatePatternBrush
            case 0x0142: // DibCreatePatternBrush
            case 0x06FF: // CreateRegion
                self::alloc($objs, ['t' => 'other']);
                break;
            case 0x012D: // SelectObject
                $o = $objs[$u(0)] ?? null;
                if ($o === null) {
                    break;
                }
                if ($o['t'] === 'pen') {
                    $cv->selectPen($o['style'] === 5 ? null : ['style' => $o['style'], 'width' => $o['width'], 'color' => $o['color']]);
                } elseif ($o['t'] === 'brush') {
                    $cv->selectBrush($o['style'] === 1 ? null : ['style' => $o['style'], 'color' => $o['color'], 'hatch' => $o['hatch']]);
                } elseif ($o['t'] === 'font') {
                    $cv->selectFont($o['font']);
                }
                break;
            case 0x01F0: // DeleteObject
                unset($objs[$u(0)]);
                break;
            case 0x0214: // MoveTo (y, x)
                $cv->moveTo((float) $s(1), (float) $s(0));
                break;
            case 0x0213: // LineTo (y, x)
                $cv->lineTo((float) $s(1), (float) $s(0));
                break;
            case 0x0325: // Polyline: count, points
            case 0x0324: // Polygon
                $n = $u(0);
                $pts = [];
                for ($i = 0; $i < $n && ($i * 2 + 2) * 2 <= strlen($p); $i++) {
                    $pts[] = $pt(1 + $i * 2);
                }
                $fn === 0x0324 ? $cv->polygon($pts) : $cv->polyline($pts);
                break;
            case 0x0538: // PolyPolygon: nPolys, counts[], points
                $nPolys = $u(0);
                $counts = [];
                for ($i = 0; $i < $nPolys; $i++) {
                    $counts[] = $u(1 + $i);
                }
                $idx = 1 + $nPolys;
                $polys = [];
                foreach ($counts as $c) {
                    $pts = [];
                    for ($i = 0; $i < $c && ($idx + 2) * 2 <= strlen($p); $i++, $idx += 2) {
                        $pts[] = $pt($idx);
                    }
                    $polys[] = $pts;
                }
                $cv->polyPolygon($polys);
                break;
            case 0x041B: // Rectangle (bottom, right, top, left)
                $cv->rect((float) $s(3), (float) $s(2), (float) $s(1), (float) $s(0));
                break;
            case 0x061C: // RoundRect (ch, cw, bottom, right, top, left)
                $cv->roundRect((float) $s(5), (float) $s(4), (float) $s(3), (float) $s(2), (float) $s(1), (float) $s(0));
                break;
            case 0x0418: // Ellipse
                $cv->ellipse((float) $s(3), (float) $s(2), (float) $s(1), (float) $s(0));
                break;
            case 0x0817: // Arc (yEnd, xEnd, yStart, xStart, bottom, right, top, left)
            case 0x081A: // Pie
            case 0x0830: // Chord
                $kind = $fn === 0x0817 ? 'arc' : ($fn === 0x081A ? 'pie' : 'chord');
                $cv->arc($kind, (float) $s(7), (float) $s(6), (float) $s(5), (float) $s(4), (float) $s(3), (float) $s(2), (float) $s(1), (float) $s(0));
                break;
            case 0x0521: // TextOut: length, string, y, x
                $n = $u(0);
                $str = substr($p, 2, $n);
                $off = 2 + $n + ($n % 2);
                $y = strlen($p) >= $off + 4 ? unpack('s', $p, $off)[1] : 0;
                $x = strlen($p) >= $off + 4 ? unpack('s', $p, $off + 2)[1] : 0;
                $cv->text((float) $x, (float) $y, $cv->ansiToUtf8($str));
                break;
            case 0x0A32: // ExtTextOut: y, x, count, options, [rect], string, [dx]
                $n = $u(2);
                $opts = $u(3);
                $off = 8 + (($opts & 0x0006) !== 0 ? 8 : 0);
                $str = substr($p, $off, $n);
                $cv->text((float) $s(1), (float) $s(0), $cv->ansiToUtf8($str));
                break;
            case 0x0416: // IntersectClipRect (bottom, right, top, left)
                $cv->intersectClipRect((float) $s(3), (float) $s(2), (float) $s(1), (float) $s(0));
                break;
            case 0x0F43: // StretchDIBits: rop(2), usage, srcH, srcW, ySrc, xSrc, destH, destW, yDest, xDest, DIB
                if (strlen($p) > 22) {
                    $cv->dib(substr($p, 22), (float) $s(10), (float) $s(9), (float) $s(8), (float) $s(7));
                }
                break;
            case 0x0B41: // DibStretchBlt: rop(2), srcH, srcW, ySrc, xSrc, destH, destW, yDest, xDest, DIB
                if (strlen($p) > 20) {
                    $cv->dib(substr($p, 20), (float) $s(9), (float) $s(8), (float) $s(7), (float) $s(6));
                }
                break;
            case 0x0940: // DibBitBlt: rop(2), ySrc, xSrc, height, width, yDest, xDest, DIB
                if (strlen($p) > 16 + 12) {
                    $cv->dib(substr($p, 16), (float) $s(7), (float) $s(6), (float) $s(5), (float) $s(4));
                }
                break;
            default:
                break;
        }
    }

    /**
     * Stores an object in the lowest free slot, as GDI does.
     *
     * @param array<int, array<string, mixed>|null> $objs
     * @param array<string, mixed> $obj
     */
    private static function alloc(array &$objs, array $obj): void
    {
        $i = 0;
        while (isset($objs[$i])) {
            $i++;
        }
        $objs[$i] = $obj;
    }
}
