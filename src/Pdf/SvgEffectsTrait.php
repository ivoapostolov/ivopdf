<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use Closure;
use DOMElement;

/**
 * SvgRenderer part: clip-path, mask (luminosity soft mask), markers and the <image> element.
 *
 * @internal Used by {@see SvgRenderer} only (together with {@see SvgPaintServersTrait}).
 */
trait SvgEffectsTrait
{
    private ?Closure $imageResolver = null;

    private int $effectDepth = 0;

    // ------------------------------------------------------------------
    // clip-path / mask
    // ------------------------------------------------------------------

    /**
     * Opens a `q` block carrying the element's clip-path and mask when it has any.
     *
     * @param array<string, mixed> $s
     */
    private function openEffects(DOMElement $el, array $s, string &$out): bool
    {
        if ($this->sink !== null || $this->effectDepth > 6) {
            return false;
        }
        $clipId = $this->urlRef($this->rawProp($el, 'clip-path'));
        $maskId = $this->paints !== null ? $this->urlRef($this->rawProp($el, 'mask')) : null;
        if ($clipId === null && $maskId === null) {
            return false;
        }

        $ops = '';
        $this->effectDepth++;
        if ($clipId !== null) {
            $ops .= $this->clipOps($clipId, $el, $s) ?? '';
        }
        if ($maskId !== null) {
            $gs = $this->maskState($maskId, $el, $s);
            if ($gs !== null) {
                $ops .= "/{$gs} gs\n";
            }
        }
        $this->effectDepth--;

        if ($ops === '') {
            return false;
        }
        $out .= "q\n" . $ops;
        return true;
    }

    /**
     * Closes effects opened by {@see openEffects()} after drawing markers for the element.
     *
     * @param array<string, mixed> $s
     */
    private function closeEffects(DOMElement $el, string $tag, array $s, bool $opened, string &$out): void
    {
        if ($this->sink === null && in_array($tag, ['path', 'line', 'polyline', 'polygon'], true)) {
            $this->drawMarkers($s, $out);
        }
        if ($opened) {
            $out .= "Q\n";
        }
    }

    /**
     * Builds the clipping operators ("path W n") for a <clipPath>; null when the reference is invalid (ignored).
     *
     * @param array<string, mixed> $s
     */
    private function clipOps(string $id, DOMElement $target, array $s): ?string
    {
        $cp = $this->findById($id);
        if ($cp === null || $this->tagOf($cp) !== 'clippath') {
            return null;
        }
        $empty = "0 0 0 0 re W n\n";

        $st = $s;
        if (strtolower(trim((string) $cp->getAttribute('clipPathUnits'))) === 'objectboundingbox') {
            $bbox = $this->elementBBox($target, $s);
            if ($bbox === null || $bbox[2] - $bbox[0] < 1e-9 || $bbox[3] - $bbox[1] < 1e-9) {
                return $empty;
            }
            $st['ctm'] = $this->multiply($st['ctm'], [$bbox[2] - $bbox[0], 0.0, 0.0, $bbox[3] - $bbox[1], $bbox[0], $bbox[1]]);
        }
        $tr = $this->attr($cp, 'transform');
        if ($tr !== null && trim($tr) !== '') {
            $st['ctm'] = $this->multiply($st['ctm'], $this->parseTransform($tr));
        }
        $st['visible'] = true;

        $dummy = '';
        $saved = $this->sink;
        $this->sink = [];
        foreach ($cp->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($this->tagOf($child), ['rect', 'circle', 'ellipse', 'path', 'polygon', 'polyline', 'line', 'text', 'use'], true)) {
                $this->renderNode($child, $st, $dummy);
            }
        }
        $captured = $this->sink;
        $this->sink = $saved;

        if ($captured === []) {
            return $empty;
        }
        $paths = '';
        $allEven = true;
        $glyphs = '';
        $allText = true;
        foreach ($captured as $entry) {
            $paths .= $entry[0];
            $allEven = $allEven && $entry[1] === 'evenodd';
            $g = $entry[2] ?? '';
            $allText = $allText && $g !== '';
            $glyphs .= $g;
        }
        if ($allText) {
            // clipPath made of text only: clip to the glyph outlines (text render mode 7)
            $out = "BT\n{$glyphs}ET\n";
        } else {
            $out = $paths . ($allEven ? "W*" : "W") . " n\n";
        }

        // A clipPath may itself be clipped: the clips intersect
        $nested = $this->urlRef($this->rawProp($cp, 'clip-path'));
        if ($nested !== null && $nested !== $id) {
            $this->effectDepth++;
            $out .= $this->clipOps($nested, $target, $s) ?? '';
            $this->effectDepth--;
        }
        return $out;
    }

    /**
     * Registers a luminosity soft mask for a <mask> element; returns the ExtGState name to apply or null.
     *
     * @param array<string, mixed> $s
     */
    private function maskState(string $id, DOMElement $target, array $s): ?string
    {
        $mask = $this->findById($id);
        if ($mask === null || $this->tagOf($mask) !== 'mask' || $this->paints === null) {
            return null;
        }
        $unitsBox = strtolower(trim($mask->getAttribute('maskUnits'))) !== 'userspaceonuse';
        $contentBox = strtolower(trim($mask->getAttribute('maskContentUnits'))) === 'objectboundingbox';
        $bbox = null;
        if ($unitsBox || $contentBox) {
            $bbox = $this->elementBBox($target, $s);
            if ($bbox === null || $bbox[2] - $bbox[0] < 1e-9 || $bbox[3] - $bbox[1] < 1e-9) {
                return null;
            }
        }

        $get = function (string $name, string $default, string $axis) use ($mask, $unitsBox, $s): float {
            $raw = $this->attr($mask, $name);
            $raw = ($raw === null || trim($raw) === '') ? $default : trim($raw);
            if ($unitsBox) {
                return str_ends_with($raw, '%') ? (float) substr($raw, 0, -1) / 100.0 : (float) $raw;
            }
            return $this->parseLength($raw, $s, $axis);
        };
        $mx = $get('x', '-10%', 'x');
        $my = $get('y', '-10%', 'y');
        $mw = $get('width', '120%', 'x');
        $mh = $get('height', '120%', 'y');
        if ($unitsBox && $bbox !== null) {
            $bw = $bbox[2] - $bbox[0];
            $bh = $bbox[3] - $bbox[1];
            $mx = $bbox[0] + $mx * $bw;
            $my = $bbox[1] + $my * $bh;
            $mw *= $bw;
            $mh *= $bh;
        }
        if ($mw <= 0 || $mh <= 0) {
            return null;
        }

        $ctm = $s['ctm'];
        if ($contentBox && $bbox !== null) {
            $ctm = $this->multiply($ctm, [$bbox[2] - $bbox[0], 0.0, 0.0, $bbox[3] - $bbox[1], $bbox[0], $bbox[1]]);
        }
        $st = $this->freshState($s, $ctm, (float) $s['pageHeight']);
        if (!$this->applyElementAttributes($mask, $st)) {
            return null;
        }
        $st['ctm'] = $ctm;

        $clipSt = $s;
        $region = $this->pt($mx, $my, $clipSt) . " m\n" . $this->pt($mx + $mw, $my, $clipSt) . " l\n"
            . $this->pt($mx + $mw, $my + $mh, $clipSt) . " l\n" . $this->pt($mx, $my + $mh, $clipSt) . " l\nh\n";

        $body = '';
        $savedSink = $this->sink;
        $this->sink = null;
        $this->effectDepth++;
        $this->renderChildren($mask, $st, $body);
        $this->effectDepth--;
        $this->sink = $savedSink;

        return $this->paints->addLuminosityMask("q\n{$region}W n\n{$body}Q\n");
    }

    // ------------------------------------------------------------------
    // Markers
    // ------------------------------------------------------------------

    /**
     * Draws marker-start / marker-mid / marker-end for the shape painted last.
     *
     * @param array<string, mixed> $s
     */
    private function drawMarkers(array $s, string &$out): void
    {
        $ids = [
            'start' => $this->urlRef((string) ($s['markerStart'] ?? 'none')),
            'mid' => $this->urlRef((string) ($s['markerMid'] ?? 'none')),
            'end' => $this->urlRef((string) ($s['markerEnd'] ?? 'none')),
        ];
        if (($ids['start'] === null && $ids['mid'] === null && $ids['end'] === null) || $this->lastPath === null || $this->effectDepth > 3) {
            return;
        }
        $verts = $this->pathVertices($this->lastPath, $s);
        $n = count($verts);
        if ($n === 0) {
            return;
        }
        $this->effectDepth++;
        foreach ($verts as $i => $v) {
            $kind = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'mid');
            $id = $ids[$kind];
            if ($id === null) {
                continue;
            }
            $mk = $this->findById($id);
            if ($mk === null || $this->tagOf($mk) !== 'marker') {
                continue;
            }
            $this->renderMarker($mk, $v, $kind, $s, $out);
        }
        $this->effectDepth--;
    }

    /**
     * Vertices of a path (user space) with incoming / outgoing tangent vectors.
     *
     * @param array<string, mixed> $s
     * @return list<array{x: float, y: float, in: ?array{0: float, 1: float}, out: ?array{0: float, 1: float}}>
     */
    private function pathVertices(string $path, array $s): array
    {
        $inv = $this->invertMatrix($s['ctm']);
        if ($inv === null) {
            return [];
        }
        $H = (float) $s['pageHeight'];
        $verts = [];
        $cur = [0.0, 0.0];
        $startIdx = null;
        $dir = static function (array $a, array $b): ?array {
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            return hypot($dx, $dy) > 1e-9 ? [$dx, $dy] : null;
        };
        $addSeg = function (?array $dOut, ?array $dIn, array $to) use (&$verts, &$cur): void {
            $last = count($verts) - 1;
            if ($last >= 0 && $verts[$last]['out'] === null) {
                $verts[$last]['out'] = $dOut;
            }
            $verts[] = ['x' => $to[0], 'y' => $to[1], 'in' => $dIn, 'out' => null];
            $cur = $to;
        };

        foreach ($this->parsePdfPath($path) as [$op, $nn]) {
            if ($op === 'm' && count($nn) >= 2) {
                $cur = $this->pageToUser($nn[0], $nn[1], $inv, $H);
                $verts[] = ['x' => $cur[0], 'y' => $cur[1], 'in' => null, 'out' => null];
                $startIdx = count($verts) - 1;
            } elseif ($op === 'l' && count($nn) >= 2) {
                $p = $this->pageToUser($nn[0], $nn[1], $inv, $H);
                $d = $dir($cur, $p);
                $addSeg($d, $d, $p);
            } elseif ($op === 'c' && count($nn) >= 6) {
                $c1 = $this->pageToUser($nn[0], $nn[1], $inv, $H);
                $c2 = $this->pageToUser($nn[2], $nn[3], $inv, $H);
                $p = $this->pageToUser($nn[4], $nn[5], $inv, $H);
                $dOut = $dir($cur, $c1) ?? $dir($cur, $c2) ?? $dir($cur, $p);
                $dIn = $dir($c2, $p) ?? $dir($c1, $p) ?? $dir($cur, $p);
                $addSeg($dOut, $dIn, $p);
            } elseif ($op === 'h' && $startIdx !== null) {
                $first = $verts[$startIdx];
                $d = $dir($cur, [$first['x'], $first['y']]);
                if ($d !== null) {
                    $addSeg($d, $d, [$first['x'], $first['y']]);
                }
                $lastIdx = count($verts) - 1;
                if ($lastIdx > $startIdx) {
                    $verts[$startIdx]['in'] = $verts[$lastIdx]['in'];
                    $verts[$lastIdx]['out'] = $verts[$startIdx]['out'];
                }
            }
        }
        return $verts;
    }

    /**
     * @param array{x: float, y: float, in: ?array{0: float, 1: float}, out: ?array{0: float, 1: float}} $v
     * @param array<string, mixed> $s
     */
    private function renderMarker(DOMElement $mk, array $v, string $kind, array $s, string &$out): void
    {
        $orient = strtolower(trim($this->attr($mk, 'orient') ?? '0'));
        if ($orient === 'auto' || $orient === 'auto-start-reverse') {
            $in = $v['in'];
            $outV = $v['out'];
            $a = 0.0;
            if ($kind === 'start' && $outV !== null) {
                $a = atan2($outV[1], $outV[0]);
            } elseif ($kind === 'end' && $in !== null) {
                $a = atan2($in[1], $in[0]);
            } elseif ($in !== null && $outV !== null) {
                $a1 = atan2($in[1], $in[0]);
                $a2 = atan2($outV[1], $outV[0]);
                $diff = fmod($a2 - $a1 + 3 * M_PI, 2 * M_PI) - M_PI;
                $a = $a1 + $diff / 2.0;
            } elseif ($in !== null || $outV !== null) {
                $d = $in ?? $outV;
                $a = atan2($d[1], $d[0]);
            }
            $angle = rad2deg($a);
            if ($orient === 'auto-start-reverse' && $kind === 'start') {
                $angle += 180.0;
            }
        } else {
            $angle = (float) $orient;
        }

        $plain = $this->freshState($s, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], (float) $s['pageHeight']);
        $mw = $this->firstLength($mk, 'markerWidth', $plain, 'x') ?? 3.0;
        $mh = $this->firstLength($mk, 'markerHeight', $plain, 'y') ?? 3.0;
        if ($mw <= 0 || $mh <= 0) {
            return;
        }
        $scale = strtolower(trim($this->attr($mk, 'markerUnits') ?? '')) === 'userspaceonuse' ? 1.0 : (float) $s['strokeWidth'];
        if ($scale <= 0) {
            return;
        }

        $vbm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $vb = $this->parseViewBox($mk);
        $vpW = $mw;
        $vpH = $mh;
        if ($vb !== null) {
            [$sx, $sy, $tx, $ty] = $this->viewBoxTransform($vb[2], $vb[3], $mw, $mh, (string) $this->attr($mk, 'preserveAspectRatio'));
            $vbm = [$sx, 0.0, 0.0, $sy, $tx - $vb[0] * $sx, $ty - $vb[1] * $sy];
            $vpW = $vb[2];
            $vpH = $vb[3];
        }
        $refX = $this->firstLength($mk, 'refX', $plain, 'x') ?? 0.0;
        $refY = $this->firstLength($mk, 'refY', $plain, 'y') ?? 0.0;
        $rx = $vbm[0] * $refX + $vbm[4];
        $ry = $vbm[3] * $refY + $vbm[5];

        $rad = deg2rad($angle);
        $cos = cos($rad);
        $sin = sin($rad);
        $m = $this->multiply($s['ctm'], [1.0, 0.0, 0.0, 1.0, $v['x'], $v['y']]);
        $m = $this->multiply($m, [$cos, $sin, -$sin, $cos, 0.0, 0.0]);
        $m = $this->multiply($m, [$scale, 0.0, 0.0, $scale, 0.0, 0.0]);
        $viewportCtm = $this->multiply($m, [1.0, 0.0, 0.0, 1.0, -$rx, -$ry]);
        $contentCtm = $this->multiply($viewportCtm, $vbm);

        $st = $this->freshState($s, $contentCtm, (float) $s['pageHeight']);
        $st['vpW'] = $vpW;
        $st['vpH'] = $vpH;
        if (!$this->applyElementAttributes($mk, $st)) {
            return;
        }
        $st['ctm'] = $contentCtm;

        $body = '';
        $this->renderChildren($mk, $st, $body);
        if (trim($body) === '') {
            return;
        }

        $overflow = strtolower(trim($this->attr($mk, 'overflow') ?? 'hidden'));
        $clip = '';
        if ($overflow !== 'visible' && $overflow !== 'auto') {
            $cs = $s;
            $cs['ctm'] = $viewportCtm;
            $clip = $this->pt(0.0, 0.0, $cs) . " m\n" . $this->pt($mw, 0.0, $cs) . " l\n" . $this->pt($mw, $mh, $cs) . " l\n"
                . $this->pt(0.0, $mh, $cs) . " l\nh\nW n\n";
        }
        $out .= "q\n" . $clip . $body . "Q\n";
    }

    // ------------------------------------------------------------------
    // <image>
    // ------------------------------------------------------------------

    /**
     * Draws an SVG <image> (data URI or allowed file / URL through the document's image loader).
     *
     * @param array<string, mixed> $s
     */
    private function drawImage(DOMElement $el, array $s, string &$out): void
    {
        if (!$s['visible']) {
            return;
        }
        $href = $this->hrefOf($el);
        if ($href === null || $href === '' || str_starts_with($href, '#')) {
            return;
        }
        $x = $this->lengthAttr($el, 'x', $s, 'x');
        $y = $this->lengthAttr($el, 'y', $s, 'y');
        $w = $this->firstLength($el, 'width', $s, 'x');
        $h = $this->firstLength($el, 'height', $s, 'y');

        $img = null;
        if ($this->sink === null) {
            if ($this->imageResolver === null) {
                return;
            }
            $img = ($this->imageResolver)($href);
            if (!is_array($img) || !isset($img['id'], $img['width'], $img['height']) || isset($img['pdfPage'])) {
                return;
            }
        }
        $iw = $img !== null ? (float) $img['width'] : 1.0;
        $ih = $img !== null ? (float) $img['height'] : 1.0;
        $w ??= $iw;
        $h ??= $ih;
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $rect = $this->pt($x, $y, $s) . " m\n" . $this->pt($x + $w, $y, $s) . " l\n" . $this->pt($x + $w, $y + $h, $s) . " l\n"
            . $this->pt($x, $y + $h, $s) . " l\nh\n";
        if ($this->sink !== null) {
            $this->sink[] = [$rect, 'nonzero'];
            return;
        }
        if ($img === null) {
            return;
        }

        [$sx, $sy, $tx, $ty, $needsClip] = $this->viewBoxTransform($iw, $ih, $w, $h, (string) $this->attr($el, 'preserveAspectRatio'));
        // Unit square (y up) → user space
        $u = [$sx * $iw, 0.0, 0.0, -$sy * $ih, $x + $tx, $y + $ty + $sy * $ih];
        $m = $this->multiply($s['ctm'], $u);
        $alpha = $this->alphaState((float) $s['opacity']);
        $out .= "q\n" . ($alpha !== null ? "/{$alpha} gs\n" : '') . ($needsClip ? $rect . "W n\n" : '')
            . sprintf("%.5F %.5F %.5F %.5F %.4F %.4F cm\n/%s Do\nQ\n", ...[...$this->pdfMatrix($m, (float) $s['pageHeight']), (string) $img['id']]);
    }
}
