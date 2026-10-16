<?php

declare(strict_types=1);

namespace IvoPdf\Image;

/**
 * Converts an Enhanced Metafile (EMF) into SVG markup so it renders as vector graphics.
 *
 * Supported records: window / viewport / mapping mode, world transform, pens (CreatePen, ExtCreatePen), brushes,
 * fonts, stock objects, Save/RestoreDC, paths (BeginPath ... FillPath / StrokePath / StrokeAndFillPath),
 * MoveTo/LineTo, polygons / polylines / poly-Béziers (16 and 32 bit), rectangles, rounded rectangles, ellipses,
 * arcs / pies / chords, ExtTextOut (A and W), IntersectClipRect and DIB bitmaps (StretchDIBits, StretchBlt, BitBlt).
 * EMF+ drawing (GDI+) records are not interpreted: only files that carry the classic GDI records render.
 */
final class EmfConverter
{
    public static function isEmf(string $d): bool
    {
        return strlen($d) >= 88 && unpack('V', $d)[1] === 1 && substr($d, 40, 4) === ' EMF';
    }

    public static function toSvg(string $d): ?string
    {
        if (!self::isEmf($d)) {
            return null;
        }
        $len = strlen($d);
        $h = unpack('lbl/lbt/lbr/lbb/lfl/lft/lfr/lfb', substr($d, 8, 32));
        $devW = unpack('l', substr($d, 72, 4))[1];
        $devH = unpack('l', substr($d, 76, 4))[1];
        $mmW = unpack('l', substr($d, 80, 4))[1];
        $mmH = unpack('l', substr($d, 84, 4))[1];

        $cv = new GdiSvgCanvas();
        if ($devW > 0 && $mmW > 0) {
            $cv->setDeviceDpi($devW / $mmW * 25.4);
        }

        /** @var array<int, array<string, mixed>> $objs */
        $objs = [];
        $pos = 0;
        $count = 0;
        while ($pos + 8 <= $len && $count++ < 400000) {
            $type = unpack('V', $d, $pos)[1];
            $size = unpack('V', $d, $pos + 4)[1];
            if ($size < 8 || $pos + $size > $len) {
                break;
            }
            $rec = substr($d, $pos, $size);
            $pos += $size;
            if ($type === 14) { // EOF
                break;
            }
            self::record($cv, $objs, $type, $rec);
        }

        // Bounds are inclusive device pixels; the frame (0.01 mm) gives the intended output size
        $bounds = [(float) $h['bl'], (float) $h['bt'], (float) $h['br'] + 1.0, (float) $h['bb'] + 1.0];
        $vb = $cv->windowViewBox();
        if ($bounds[2] - $bounds[0] > 1 && $bounds[3] - $bounds[1] > 1) {
            $vb = $bounds;
            $vb = [$vb[0], $vb[1], $vb[2] - $vb[0], $vb[3] - $vb[1]];
        }
        $frameW = ($h['fr'] - $h['fl']) / 100.0 / 25.4 * 96.0;
        $frameH = ($h['fb'] - $h['ft']) / 100.0 / 25.4 * 96.0;
        if ($frameW <= 0 || $frameH <= 0) {
            $frameW = $vb !== null ? $vb[2] : 0.0;
            $frameH = $vb !== null ? $vb[3] : 0.0;
            if ($devW > 0 && $mmW > 0 && $vb !== null) {
                $frameW = $vb[2] / ($devW / $mmW) / 25.4 * 96.0;
                $frameH = $vb[3] / ($devH / max(1, $mmH)) / 25.4 * 96.0;
            }
        }
        return $cv->toSvg($frameW, $frameH, $vb);
    }

    /**
     * @param array<int, array<string, mixed>> $objs
     */
    private static function record(GdiSvgCanvas $cv, array &$objs, int $type, string $r): void
    {
        $rl = strlen($r);
        $i32 = static fn(int $o): int => $o + 4 <= $rl ? unpack('l', $r, $o)[1] : 0;
        $u32 = static fn(int $o): int => $o + 4 <= $rl ? unpack('V', $r, $o)[1] : 0;
        $i16 = static fn(int $o): int => $o + 2 <= $rl ? unpack('s', $r, $o)[1] : 0;
        $f32 = static fn(int $o): float => $o + 4 <= $rl ? unpack('g', $r, $o)[1] : 0.0;
        $pts32 = static function (int $o, int $n) use ($rl, $i32): array {
            $pts = [];
            for ($k = 0; $k < $n && $o + ($k + 1) * 8 <= $rl; $k++) {
                $pts[] = [(float) $i32($o + $k * 8), (float) $i32($o + $k * 8 + 4)];
            }
            return $pts;
        };
        $pts16 = static function (int $o, int $n) use ($rl, $i16): array {
            $pts = [];
            for ($k = 0; $k < $n && $o + ($k + 1) * 4 <= $rl; $k++) {
                $pts[] = [(float) $i16($o + $k * 4), (float) $i16($o + $k * 4 + 2)];
            }
            return $pts;
        };
        $box = static fn(int $o): array => [(float) $i32($o), (float) $i32($o + 4), (float) $i32($o + 8), (float) $i32($o + 12)];

        switch ($type) {
            case 9: // SETWINDOWEXTEX
                $cv->setWindowExt((float) $i32(8), (float) $i32(12));
                break;
            case 10: // SETWINDOWORGEX
                $cv->setWindowOrg((float) $i32(8), (float) $i32(12));
                break;
            case 11: // SETVIEWPORTEXTEX
                $cv->setViewportExt((float) $i32(8), (float) $i32(12));
                break;
            case 12: // SETVIEWPORTORGEX
                $cv->setViewportOrg((float) $i32(8), (float) $i32(12));
                break;
            case 17: // SETMAPMODE
                $cv->setMapMode($u32(8));
                break;
            case 18: // SETBKMODE
                $cv->setBkMode($u32(8));
                break;
            case 19: // SETPOLYFILLMODE
                $cv->setPolyFillMode($u32(8));
                break;
            case 22: // SETTEXTALIGN
                $cv->setTextAlign($u32(8));
                break;
            case 24: // SETTEXTCOLOR
                $cv->setTextColor(GdiSvgCanvas::colorRef($u32(8)));
                break;
            case 25: // SETBKCOLOR
                $cv->setBkColor(GdiSvgCanvas::colorRef($u32(8)));
                break;
            case 33: // SAVEDC
                $cv->save();
                break;
            case 34: // RESTOREDC
                $cv->restore($i32(8));
                break;
            case 35: // SETWORLDTRANSFORM
                $cv->setWorldTransform([$f32(8), $f32(12), $f32(16), $f32(20), $f32(24), $f32(28)]);
                break;
            case 36: // MODIFYWORLDTRANSFORM
                $cv->modifyWorldTransform([$f32(8), $f32(12), $f32(16), $f32(20), $f32(24), $f32(28)], $u32(32));
                break;
            case 38: // CREATEPEN: ihPen, style, width(x,y), color
                $objs[$u32(8)] = ['t' => 'pen', 'style' => $u32(12) & 0xFFFF, 'width' => (float) abs($i32(16)), 'color' => GdiSvgCanvas::colorRef($u32(24))];
                break;
            case 95: // EXTCREATEPEN: ihPen, offBmi, cbBmi, offBits, cbBits, style, width, brushStyle, color, hatch
                $style = $u32(28);
                $objs[$u32(8)] = ['t' => 'pen', 'style' => $style & 0xFF, 'width' => (float) $u32(32), 'color' => GdiSvgCanvas::colorRef($u32(40))];
                break;
            case 39: // CREATEBRUSHINDIRECT: ihBrush, style, color, hatch
                $objs[$u32(8)] = ['t' => 'brush', 'style' => $u32(12), 'color' => GdiSvgCanvas::colorRef($u32(16)), 'hatch' => $u32(20)];
                break;
            case 82: // EXTCREATEFONTINDIRECTW: ihFont, LOGFONTW
                $face = '';
                if ($rl >= 12 + 28 + 4) {
                    $raw = substr($r, 12 + 28, 64);
                    $nul = 0;
                    for ($k = 0; $k + 1 < strlen($raw); $k += 2) {
                        if ($raw[$k] === "\0" && $raw[$k + 1] === "\0") {
                            break;
                        }
                        $nul = $k + 2;
                    }
                    $face = (string) @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 0, $nul));
                }
                $objs[$u32(8)] = ['t' => 'font', 'font' => GdiSvgCanvas::fontFromParts(
                    (float) $i32(12),
                    $u32(28),
                    ($r[32] ?? "\0") !== "\0",
                    ($r[33] ?? "\0") !== "\0",
                    ($r[34] ?? "\0") !== "\0",
                    $face,
                    ord($r[35] ?? "\0"),
                    (float) $i32(20)
                )];
                break;
            case 37: // SELECTOBJECT
                self::select($cv, $objs, $u32(8));
                break;
            case 40: // DELETEOBJECT
                unset($objs[$u32(8)]);
                break;
            case 27: // MOVETOEX
                $cv->moveTo((float) $i32(8), (float) $i32(12));
                break;
            case 54: // LINETO
                $cv->lineTo((float) $i32(8), (float) $i32(12));
                break;
            case 43: // RECTANGLE
                [$l, $t, $rr, $b] = $box(8);
                $cv->rect($l, $t, $rr, $b);
                break;
            case 44: // ROUNDRECT
                [$l, $t, $rr, $b] = $box(8);
                $cv->roundRect($l, $t, $rr, $b, (float) $i32(24), (float) $i32(28));
                break;
            case 42: // ELLIPSE
                [$l, $t, $rr, $b] = $box(8);
                $cv->ellipse($l, $t, $rr, $b);
                break;
            case 45: // ARC
            case 46: // CHORD
            case 47: // PIE
                [$l, $t, $rr, $b] = $box(8);
                $kind = $type === 45 ? 'arc' : ($type === 46 ? 'chord' : 'pie');
                $cv->arc($kind, $l, $t, $rr, $b, (float) $i32(24), (float) $i32(28), (float) $i32(32), (float) $i32(36));
                break;
            case 3: // POLYGON (bounds 16, count, points)
                $cv->polygon($pts32(28, $u32(24)));
                break;
            case 4: // POLYLINE
                $cv->polyline($pts32(28, $u32(24)));
                break;
            case 5: // POLYBEZIERTO
                $cv->polyBezier($pts32(28, $u32(24)), true);
                break;
            case 6: // POLYLINETO
                $cv->polyline($pts32(28, $u32(24)), true);
                break;
            case 85: // POLYBEZIER
                $cv->polyBezier($pts32(28, $u32(24)), false);
                break;
            case 86: // POLYGON16
                $cv->polygon($pts16(28, $u32(24)));
                break;
            case 87: // POLYLINE16
                $cv->polyline($pts16(28, $u32(24)));
                break;
            case 88: // POLYBEZIERTO16
                $cv->polyBezier($pts16(28, $u32(24)), true);
                break;
            case 89: // POLYLINETO16
                $cv->polyline($pts16(28, $u32(24)), true);
                break;
            case 8: // POLYPOLYGON
            case 7: // POLYPOLYLINE
            case 91: // POLYPOLYGON16
            case 90: // POLYPOLYLINE16
                $nPolys = $u32(24);
                $off = 32 + $nPolys * 4;
                $wide = $type === 8 || $type === 7;
                $polys = [];
                for ($k = 0; $k < $nPolys; $k++) {
                    $c = $u32(32 + $k * 4);
                    $polys[] = $wide ? $pts32($off, $c) : $pts16($off, $c);
                    $off += $c * ($wide ? 8 : 4);
                }
                ($type === 8 || $type === 91) ? $cv->polyPolygon($polys) : $cv->polyPolyline($polys);
                break;
            case 59: // BEGINPATH
                $cv->beginPath();
                break;
            case 60: // ENDPATH
                $cv->endPath();
                break;
            case 61: // CLOSEFIGURE
                $cv->closeFigure();
                break;
            case 62: // FILLPATH
                $cv->paintPath(true, false);
                break;
            case 63: // STROKEANDFILLPATH
                $cv->paintPath(true, true);
                break;
            case 64: // STROKEPATH
                $cv->paintPath(false, true);
                break;
            case 67: // SELECTCLIPPATH
            case 68: // ABORTPATH
                $cv->discardPath();
                break;
            case 30: // INTERSECTCLIPRECT
                [$l, $t, $rr, $b] = $box(8);
                $cv->intersectClipRect($l, $t, $rr, $b);
                break;
            case 83: // EXTTEXTOUTA
            case 84: // EXTTEXTOUTW
                $x = (float) $i32(36);
                $y = (float) $i32(40);
                $n = $u32(44);
                $offStr = $u32(48);
                if ($n > 0 && $offStr > 0 && $offStr < $rl) {
                    if ($type === 84) {
                        $str = (string) @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($r, $offStr, $n * 2));
                    } else {
                        $str = $cv->ansiToUtf8(substr($r, $offStr, $n));
                    }
                    $cv->text($x, $y, $str);
                }
                break;
            case 81: // STRETCHDIBITS: bounds(16), xDest, yDest, xSrc, ySrc, cxSrc, cySrc, offBmi, cbBmi, offBits, cbBits, usage, rop, cxDest, cyDest
                self::dibRecord($cv, $r, $i32(24), $i32(28), $i32(72), $i32(76), $u32(48), $u32(52), $u32(56), $u32(60));
                break;
            case 77: // STRETCHBLT: bounds(16), xDest, yDest, cxDest, cyDest, rop, xSrc, ySrc, xform(24), bkColor, usage, offBmi, cbBmi, offBits, cbBits, cxSrc, cySrc
                self::dibRecord($cv, $r, $i32(24), $i32(28), $i32(32), $i32(36), $u32(84), $u32(88), $u32(92), $u32(96));
                break;
            case 76: // BITBLT
                self::dibRecord($cv, $r, $i32(24), $i32(28), $i32(32), $i32(36), $u32(84), $u32(88), $u32(92), $u32(96));
                break;
            default:
                break;
        }
    }

    /**
     * Selects a created or stock object into the device context.
     *
     * @param array<int, array<string, mixed>> $objs
     */
    private static function select(GdiSvgCanvas $cv, array $objs, int $ih): void
    {
        if (($ih & 0x80000000) !== 0) {
            switch ($ih & 0x7FFFFFFF) {
                case 0:
                    $cv->selectBrush(['style' => 0, 'color' => '#ffffff', 'hatch' => 0]);
                    break;
                case 1:
                    $cv->selectBrush(['style' => 0, 'color' => '#c0c0c0', 'hatch' => 0]);
                    break;
                case 2:
                    $cv->selectBrush(['style' => 0, 'color' => '#808080', 'hatch' => 0]);
                    break;
                case 3:
                    $cv->selectBrush(['style' => 0, 'color' => '#404040', 'hatch' => 0]);
                    break;
                case 4:
                    $cv->selectBrush(['style' => 0, 'color' => '#000000', 'hatch' => 0]);
                    break;
                case 5:
                    $cv->selectBrush(null);
                    break;
                case 6:
                    $cv->selectPen(['style' => 0, 'width' => 0.0, 'color' => '#ffffff']);
                    break;
                case 7:
                    $cv->selectPen(['style' => 0, 'width' => 0.0, 'color' => '#000000']);
                    break;
                case 8:
                    $cv->selectPen(null);
                    break;
                default:
                    break;
            }
            return;
        }
        $o = $objs[$ih] ?? null;
        if ($o === null) {
            return;
        }
        if ($o['t'] === 'pen') {
            $cv->selectPen($o['style'] === 5 ? null : ['style' => $o['style'], 'width' => $o['width'], 'color' => $o['color']]);
        } elseif ($o['t'] === 'brush') {
            $cv->selectBrush($o['style'] === 1 ? null : ['style' => $o['style'], 'color' => $o['color'], 'hatch' => $o['hatch']]);
        } elseif ($o['t'] === 'font') {
            $cv->selectFont($o['font']);
        }
    }

    private static function dibRecord(GdiSvgCanvas $cv, string $r, int $x, int $y, int $w, int $h, int $offBmi, int $cbBmi, int $offBits, int $cbBits): void
    {
        if ($offBmi <= 0 || $cbBmi <= 0 || $offBits <= 0 || $cbBits <= 0 || $offBmi + $cbBmi > strlen($r) || $offBits + $cbBits > strlen($r)) {
            return;
        }
        $cv->dib(substr($r, $offBmi, $cbBmi) . substr($r, $offBits, $cbBits), (float) $x, (float) $y, (float) $w, (float) $h);
    }
}
