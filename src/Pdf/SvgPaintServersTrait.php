<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use DOMElement;
use IvoPdf\Css\CssColor;

/**
 * SvgRenderer part: gradients (linearGradient / radialGradient), tiling <pattern>, geometry bounding boxes and the
 * painting of fills / strokes / text with those paint servers.
 *
 * Gradient fills are drawn as `clip + sh` (robust against an enclosing CSS transform); strokes use shading patterns
 * (PatternType 2). Stop opacity is realised with a luminosity soft mask built from an alpha ramp shading.
 *
 * @internal Used by {@see SvgRenderer} only.
 */
trait SvgPaintServersTrait
{
    private ?SvgPaintRegistry $paints = null;

    /** @var list<array{0: string, 1: string}>|null Captured (path, clip-rule) pairs instead of painting (bbox / clipPath mode) */
    private ?array $sink = null;

    /** Last path handed to paint() (PDF operators), used for marker placement. */
    private ?string $lastPath = null;

    private int $serverDepth = 0;

    /** True once content referencing a pattern (page-space matrix) was emitted for the SVG being rendered. */
    private bool $patternUsed = false;

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /**
     * Reads a non-inherited property from the inline style first, then from the presentation attribute.
     */
    private function rawProp(DOMElement $el, string $name): ?string
    {
        $style = $el->getAttribute('style');
        if ($style !== '' && stripos($style, $name) !== false) {
            foreach (explode(';', $style) as $decl) {
                $colon = strpos($decl, ':');
                if ($colon !== false && strtolower(trim(substr($decl, 0, $colon))) === $name) {
                    return trim((string) preg_replace('/!\s*important\s*$/i', '', substr($decl, $colon + 1)));
                }
            }
        }
        return $el->hasAttribute($name) ? trim($el->getAttribute($name)) : null;
    }

    /** Extracts the id of a `url(#id)` reference. */
    private function urlRef(?string $value): ?string
    {
        if ($value === null || !preg_match('/^url\(\s*[\'"]?#([^\'")\s]+)[\'"]?\s*\)/i', trim($value), $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * @param array<int, float> $m
     * @return array<int, float>|null
     */
    private function invertMatrix(array $m): ?array
    {
        $det = $m[0] * $m[3] - $m[1] * $m[2];
        if (abs($det) < 1e-12) {
            return null;
        }
        return [
            $m[3] / $det,
            -$m[1] / $det,
            -$m[2] / $det,
            $m[0] / $det,
            ($m[2] * $m[5] - $m[3] * $m[4]) / $det,
            ($m[1] * $m[4] - $m[0] * $m[5]) / $det,
        ];
    }

    /**
     * Document matrix [a b c d e f] (y down) → PDF page matrix (y up).
     *
     * @param array<int, float> $m
     * @return list<float>
     */
    private function pdfMatrix(array $m, float $pageHeight): array
    {
        return [$m[0], -$m[1], $m[2], -$m[3], $m[4], $pageHeight - $m[5]];
    }

    /**
     * @param array<int, float> $m
     */
    private function matrixOps(array $m, float $pageHeight): string
    {
        return sprintf("%.5F %.5F %.5F %.5F %.4F %.4F cm\n", ...$this->pdfMatrix($m, $pageHeight));
    }

    /**
     * Splits PDF path operators into [op, numbers] entries.
     *
     * @return list<array{0: string, 1: list<float>}>
     */
    private function parsePdfPath(string $path): array
    {
        $ops = [];
        $nums = [];
        if (!preg_match_all('/(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|(re|[mlch])(?![a-z])/', $path, $mm, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($mm as $t) {
            if (($t[2] ?? '') === '') {
                $nums[] = (float) $t[1];
                continue;
            }
            $ops[] = [$t[2], $nums];
            $nums = [];
        }
        return $ops;
    }

    /**
     * Maps a PDF page point back to SVG user space.
     *
     * @param array<int, float> $inv
     * @return array{0: float, 1: float}
     */
    private function pageToUser(float $px, float $py, array $inv, float $pageHeight): array
    {
        $dy = $pageHeight - $py;
        return [$inv[0] * $px + $inv[2] * $dy + $inv[4], $inv[1] * $px + $inv[3] * $dy + $inv[5]];
    }

    /**
     * User-space bounding box [x0, y0, x1, y1] of PDF path operators drawn under matrix $ctm (curve extrema included).
     *
     * @param array<int, float> $ctm
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function pathBBoxUser(string $path, array $ctm, float $pageHeight): ?array
    {
        $inv = $this->invertMatrix($ctm);
        if ($inv === null) {
            return null;
        }
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        $add = static function (float $x, float $y) use (&$minX, &$minY, &$maxX, &$maxY): void {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        };
        $cur = null;
        foreach ($this->parsePdfPath($path) as [$op, $n]) {
            if (($op === 'm' || $op === 'l') && count($n) >= 2) {
                $cur = $this->pageToUser($n[0], $n[1], $inv, $pageHeight);
                $add($cur[0], $cur[1]);
            } elseif ($op === 'c' && count($n) >= 6 && $cur !== null) {
                $p1 = $this->pageToUser($n[0], $n[1], $inv, $pageHeight);
                $p2 = $this->pageToUser($n[2], $n[3], $inv, $pageHeight);
                $p3 = $this->pageToUser($n[4], $n[5], $inv, $pageHeight);
                $add($p3[0], $p3[1]);
                foreach ([0, 1] as $ax) {
                    foreach ($this->cubicExtrema($cur[$ax], $p1[$ax], $p2[$ax], $p3[$ax]) as $t) {
                        $mt = 1 - $t;
                        $pt = [
                            $mt ** 3 * $cur[0] + 3 * $mt * $mt * $t * $p1[0] + 3 * $mt * $t * $t * $p2[0] + $t ** 3 * $p3[0],
                            $mt ** 3 * $cur[1] + 3 * $mt * $mt * $t * $p1[1] + 3 * $mt * $t * $t * $p2[1] + $t ** 3 * $p3[1],
                        ];
                        $add($pt[0], $pt[1]);
                    }
                }
                $cur = $p3;
            } elseif ($op === 're' && count($n) >= 4) {
                foreach ([[0, 0], [1, 0], [1, 1], [0, 1]] as [$fx, $fy]) {
                    $p = $this->pageToUser($n[0] + $fx * $n[2], $n[1] + $fy * $n[3], $inv, $pageHeight);
                    $add($p[0], $p[1]);
                }
            }
        }
        return is_finite($minX) ? [$minX, $minY, $maxX, $maxY] : null;
    }

    /**
     * Parameters t in (0,1) where a 1-D cubic Bézier has zero derivative.
     *
     * @return list<float>
     */
    private function cubicExtrema(float $p0, float $p1, float $p2, float $p3): array
    {
        $a = -$p0 + 3 * $p1 - 3 * $p2 + $p3;
        $b = 2 * ($p0 - 2 * $p1 + $p2);
        $c = $p1 - $p0;
        $ts = [];
        if (abs($a) < 1e-12) {
            if (abs($b) > 1e-12) {
                $ts[] = -$c / $b;
            }
        } else {
            $disc = $b * $b - 4 * $a * $c;
            if ($disc >= 0) {
                $sq = sqrt($disc);
                $ts[] = (-$b + $sq) / (2 * $a);
                $ts[] = (-$b - $sq) / (2 * $a);
            }
        }
        return array_values(array_filter($ts, static fn(float $t): bool => $t > 0.0 && $t < 1.0));
    }

    /**
     * Bounding box of an element's geometry in its own user space (own transform excluded), via sink-mode rendering.
     *
     * @param array<string, mixed> $s
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function elementBBox(DOMElement $el, array $s): ?array
    {
        $saved = $this->sink;
        $savedLast = $this->lastPath;
        $this->sink = [];
        $st = $s;
        $st['ctm'] = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $st['pageHeight'] = 0.0;
        $st['noTransformOnce'] = true;
        $st['visible'] = true;
        $dummy = '';
        $this->renderNode($el, $st, $dummy);
        $captured = $this->sink;
        $this->sink = $saved;
        $this->lastPath = $savedLast;

        $box = null;
        foreach ($captured as [$path]) {
            $b = $this->pathBBoxUser($path, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0.0);
            if ($b === null) {
                continue;
            }
            $box = $box === null ? $b : [min($box[0], $b[0]), min($box[1], $b[1]), max($box[2], $b[2]), max($box[3], $b[3])];
        }
        return $box;
    }

    // ------------------------------------------------------------------
    // Paint server resolution
    // ------------------------------------------------------------------

    /**
     * The gradient / pattern element a paint value refers to, or null for ordinary paints.
     */
    private function serverElement(string $value): ?DOMElement
    {
        if ($this->paints === null || $this->serverDepth > 4) {
            return null;
        }
        $id = $this->urlRef($value);
        if ($id === null) {
            return null;
        }
        $ref = $this->findById($id);
        if ($ref !== null && in_array($this->tagOf($ref), ['lineargradient', 'radialgradient', 'pattern'], true)) {
            return $ref;
        }
        return null;
    }

    /**
     * Resolves a paint value into a drawing spec.
     *
     * @param array<string, mixed> $s
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
     * @return array{rgba?: array{0: float, 1: float, 2: float, 3: float}, sh?: string, matrix?: list<float>, smask?: ?string, pat?: string}|null
     */
    private function paintSpec(string $value, array $s, ?array $bbox): ?array
    {
        $ref = $this->serverElement($value);
        if ($ref === null) {
            $rgba = $this->resolvePaint($value, $s);
            return $rgba === null ? null : ['rgba' => $rgba];
        }
        if ($bbox === null) {
            return null;
        }
        if ($this->tagOf($ref) === 'pattern') {
            $name = $this->buildTilingPattern($ref, $s, $bbox);
            return $name === null ? null : ['pat' => $name];
        }
        return $this->buildGradientSpec($ref, $s, $bbox);
    }

    /**
     * Chain of gradients linked through href (nearest first).
     *
     * @return list<DOMElement>
     */
    private function hrefChain(DOMElement $el): array
    {
        $chain = [$el];
        $cur = $el;
        for ($i = 0; $i < 8; $i++) {
            $href = $this->hrefOf($cur);
            if ($href === null || !str_starts_with($href, '#')) {
                break;
            }
            $ref = $this->findById(substr($href, 1));
            if ($ref === null || !in_array($this->tagOf($ref), ['lineargradient', 'radialgradient', 'pattern'], true)) {
                break;
            }
            foreach ($chain as $c) {
                if ($c->isSameNode($ref)) {
                    return $chain;
                }
            }
            $chain[] = $ref;
            $cur = $ref;
        }
        return $chain;
    }

    /**
     * @param list<DOMElement> $chain
     */
    private function chainAttr(array $chain, string $name, ?string $sameTag = null): ?string
    {
        foreach ($chain as $c) {
            if ($sameTag !== null && $this->tagOf($c) !== $sameTag) {
                continue;
            }
            $v = $this->attr($c, $name);
            if ($v !== null && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $s
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @return array{rgba?: array{0: float, 1: float, 2: float, 3: float}, sh?: string, matrix?: list<float>, smask?: ?string}|null
     */
    private function buildGradientSpec(DOMElement $g, array $s, array $bbox): ?array
    {
        $reg = $this->paints;
        if ($reg === null) {
            return null;
        }
        $chain = $this->hrefChain($g);
        $units = strtolower($this->chainAttr($chain, 'gradientUnits') ?? 'objectBoundingBox');
        $bboxUnits = $units !== 'userspaceonuse';
        $bw = $bbox[2] - $bbox[0];
        $bh = $bbox[3] - $bbox[1];
        if ($bboxUnits && ($bw < 1e-9 || $bh < 1e-9)) {
            return null;
        }

        $stops = $this->gradientStops($chain, $s);
        if ($stops === []) {
            return null;
        }
        $lastStop = $stops[count($stops) - 1];
        $solid = ['rgba' => [$lastStop[1], $lastStop[2], $lastStop[3], $lastStop[4]]];
        if (count($stops) === 1) {
            return $solid;
        }

        $radial = $this->tagOf($g) === 'radialgradient';
        $tag = $radial ? 'radialgradient' : 'lineargradient';
        $len = function (string $name, string $default, string $axis) use ($chain, $tag, $bboxUnits, $s): float {
            $raw = $this->chainAttr($chain, $name, $tag) ?? $default;
            if ($bboxUnits) {
                $raw = trim($raw);
                return str_ends_with($raw, '%') ? (float) substr($raw, 0, -1) / 100.0 : (float) $raw;
            }
            return $this->parseLength($raw, $s, $axis);
        };

        if ($radial) {
            $cx = $len('cx', '50%', 'x');
            $cy = $len('cy', '50%', 'y');
            $r = $len('r', '50%', 'd');
            $fxRaw = $this->chainAttr($chain, 'fx', $tag);
            $fyRaw = $this->chainAttr($chain, 'fy', $tag);
            $fx = $fxRaw !== null ? $len('fx', '0', 'x') : $cx;
            $fy = $fyRaw !== null ? $len('fy', '0', 'y') : $cy;
            if ($r <= 1e-9) {
                return $solid;
            }
            $dist = hypot($fx - $cx, $fy - $cy);
            if ($dist > $r * 0.99) {
                $k = $r * 0.99 / $dist;
                $fx = $cx + ($fx - $cx) * $k;
                $fy = $cy + ($fy - $cy) * $k;
            }
            $coords = [$fx, $fy, 0.0, $cx, $cy, $r];
        } else {
            $x1 = $len('x1', '0%', 'x');
            $y1 = $len('y1', '0%', 'y');
            $x2 = $len('x2', '100%', 'x');
            $y2 = $len('y2', '0%', 'y');
            if (abs($x1 - $x2) < 1e-9 && abs($y1 - $y2) < 1e-9) {
                return $solid;
            }
            $coords = [$x1, $y1, $x2, $y2];
        }

        $spread = strtolower($this->chainAttr($chain, 'spreadMethod') ?? 'pad');
        [$expanded, $domain] = $this->expandStops($stops, $spread, $radial);
        if ($domain[0] !== 0.0 || $domain[1] !== 1.0) {
            // The shading axis spans exactly the domain: stretch the geometry so t = 0..1 keeps the original vector
            if ($radial) {
                $coords = [
                    $coords[0], $coords[1], 0.0,
                    $coords[0] + $domain[1] * ($coords[3] - $coords[0]),
                    $coords[1] + $domain[1] * ($coords[4] - $coords[1]),
                    $domain[1] * $coords[5],
                ];
            } else {
                $dx = $coords[2] - $coords[0];
                $dy = $coords[3] - $coords[1];
                $coords = [
                    $coords[0] + $domain[0] * $dx, $coords[1] + $domain[0] * $dy,
                    $coords[0] + $domain[1] * $dx, $coords[1] + $domain[1] * $dy,
                ];
            }
        }

        $gm = $bboxUnits ? [$bw, 0.0, 0.0, $bh, $bbox[0], $bbox[1]] : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $gt = $this->chainAttr($chain, 'gradientTransform');
        if ($gt !== null) {
            $gm = $this->multiply($gm, $this->parseTransform($gt));
        }
        $matrix = $this->pdfMatrix($this->multiply($s['ctm'], $gm), (float) $s['pageHeight']);

        $type = $radial ? 3 : 2;
        $sh = $reg->addShading($type, $coords, $domain, $expanded);
        $smask = null;
        foreach ($stops as $st) {
            if ($st[4] < 0.999) {
                $alphaSh = $reg->addShading($type, $coords, $domain, $expanded, 'alpha');
                $smask = $reg->addLuminosityMask(sprintf("q\n%s cm\n/%s sh\nQ\n", implode(' ', array_map(static fn(float $v): string => sprintf('%.5F', $v), $matrix)), $alphaSh));
                break;
            }
        }

        return ['sh' => $sh, 'matrix' => $matrix, 'smask' => $smask];
    }

    /**
     * Gradient stops [offset, r, g, b, a] taken from the first element of the href chain that has <stop> children.
     *
     * @param list<DOMElement> $chain
     * @param array<string, mixed> $s
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    private function gradientStops(array $chain, array $s): array
    {
        foreach ($chain as $g) {
            $stops = [];
            $prev = 0.0;
            foreach ($g->childNodes as $child) {
                if (!$child instanceof DOMElement || $this->tagOf($child) !== 'stop') {
                    continue;
                }
                $offRaw = trim($child->getAttribute('offset'));
                $off = str_ends_with($offRaw, '%') ? (float) substr($offRaw, 0, -1) / 100.0 : (float) $offRaw;
                $off = max($prev, min(1.0, max(0.0, $off)));
                $prev = $off;
                $color = $this->rawProp($child, 'stop-color') ?? 'black';
                $opacity = $this->rawProp($child, 'stop-opacity') ?? '1';
                if (strtolower($color) === 'currentcolor') {
                    $color = (string) $s['currentColor'];
                }
                $rgba = CssColor::parse($color) ?? [0.0, 0.0, 0.0, 1.0];
                $stops[] = [$off, $rgba[0], $rgba[1], $rgba[2], $rgba[3] * $this->parseOpacity($opacity)];
            }
            if ($stops !== []) {
                return $stops;
            }
        }
        return [];
    }

    /**
     * Applies spreadMethod by repeating (and mirroring) the stop ramp over an extended function domain.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $stops
     * @return array{0: list<array{0: float, 1: float, 2: float, 3: float, 4: float}>, 1: array{0: float, 1: float}}
     */
    private function expandStops(array $stops, string $spread, bool $radial): array
    {
        if ($spread !== 'repeat' && $spread !== 'reflect') {
            return [$stops, [0.0, 1.0]];
        }
        // Cover [0, 1] completely so every period is a full ramp
        $full = $stops;
        if ($full[0][0] > 1e-6) {
            array_unshift($full, [0.0, $full[0][1], $full[0][2], $full[0][3], $full[0][4]]);
        }
        $n = count($full);
        if ($full[$n - 1][0] < 1 - 1e-6) {
            $full[] = [1.0, $full[$n - 1][1], $full[$n - 1][2], $full[$n - 1][3], $full[$n - 1][4]];
        }
        $from = $radial ? 0 : -6;
        $to = $radial ? 12 : 7;
        $out = [];
        for ($p = $from; $p < $to; $p++) {
            $rev = $spread === 'reflect' && (($p % 2) !== 0);
            $list = $rev ? array_reverse($full) : $full;
            foreach ($list as $st) {
                $o = $rev ? 1.0 - $st[0] : $st[0];
                $out[] = [$p + $o, $st[1], $st[2], $st[3], $st[4]];
            }
        }
        return [$out, [(float) $from, (float) $to]];
    }

    // ------------------------------------------------------------------
    // Tiling pattern
    // ------------------------------------------------------------------

    /**
     * Builds a PatternType 1 tiling pattern for a <pattern> element; returns its resource name.
     *
     * @param array<string, mixed> $s
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    private function buildTilingPattern(DOMElement $pat, array $s, array $bbox): ?string
    {
        $reg = $this->paints;
        if ($reg === null || $this->serverDepth > 2) {
            return null;
        }
        $chain = $this->hrefChain($pat);
        $bw = $bbox[2] - $bbox[0];
        $bh = $bbox[3] - $bbox[1];
        $unitsBox = strtolower($this->chainAttr($chain, 'patternUnits') ?? 'objectBoundingBox') !== 'userspaceonuse';
        $contentBox = strtolower($this->chainAttr($chain, 'patternContentUnits') ?? 'userSpaceOnUse') === 'objectboundingbox';
        if (($unitsBox || $contentBox) && ($bw < 1e-9 || $bh < 1e-9)) {
            return null;
        }

        $lenOf = function (string $name, string $axis) use ($chain, $unitsBox, $s): float {
            $raw = $this->chainAttr($chain, $name) ?? '0';
            if ($unitsBox) {
                return str_ends_with($raw, '%') ? (float) substr($raw, 0, -1) / 100.0 : (float) $raw;
            }
            return $this->parseLength($raw, $s, $axis);
        };
        $x = $lenOf('x', 'x');
        $y = $lenOf('y', 'y');
        $w = $lenOf('width', 'x');
        $h = $lenOf('height', 'y');
        if ($unitsBox) {
            $x = $bbox[0] + $x * $bw;
            $y = $bbox[1] + $y * $bh;
            $w *= $bw;
            $h *= $bh;
        }
        if ($w < 0.01 || $h < 0.01) {
            return null;
        }

        // Pattern space → user space: patternTransform, then the tile origin
        $pm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $pt = $this->chainAttr($chain, 'patternTransform');
        if ($pt !== null) {
            $pm = $this->parseTransform($pt);
        }
        $pm = $this->multiply($pm, [1.0, 0.0, 0.0, 1.0, $x, $y]);
        $toPage = $this->multiply($s['ctm'], $pm);
        // Tile space (u right, v down) → PDF pattern space (u right, -v): matrix [a, -b, -c, d, e, H-f]
        $H = (float) $s['pageHeight'];
        $matrix = [$toPage[0], -$toPage[1], -$toPage[2], $toPage[3], $toPage[4], $H - $toPage[5]];

        // Tile content
        $content = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $vbRaw = $this->chainAttr($chain, 'viewBox');
        if ($vbRaw !== null) {
            $vb = $this->parseNumberList($vbRaw);
            if (count($vb) >= 4 && $vb[2] > 0 && $vb[3] > 0) {
                [$sx, $sy, $tx, $ty] = $this->viewBoxTransform($vb[2], $vb[3], $w, $h, $this->chainAttr($chain, 'preserveAspectRatio') ?? '');
                $content = [$sx, 0.0, 0.0, $sy, $tx - $vb[0] * $sx, $ty - $vb[1] * $sy];
            }
        } elseif ($contentBox) {
            $content = [$bw, 0.0, 0.0, $bh, 0.0, 0.0];
        }

        $holder = $pat;
        foreach ($chain as $c) {
            foreach ($c->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $holder = $c;
                    break 2;
                }
            }
        }

        $st = $this->freshState($s, $content, 0.0);
        $st['vpW'] = $w;
        $st['vpH'] = $h;
        if (!$this->applyElementAttributes($pat, $st)) {
            return null;
        }
        $st['ctm'] = $content;
        $body = '';
        $this->serverDepth++;
        $savedSink = $this->sink;
        $this->sink = null;
        $this->renderChildren($holder, $st, $body);
        $this->sink = $savedSink;
        $this->serverDepth--;
        if (trim($body) === '') {
            return null;
        }

        return $reg->addTiling("q\n0 {$this->fmt(-$h)} {$this->fmt($w)} {$this->fmt($h)} re W n\n" . $body . "Q\n", [0.0, -$h, $w, 0.0], $matrix, $w, $h);
    }

    /**
     * Patterns have a matrix in the default space of their content stream and ignore the CTM; drawing the SVG inside a
     * Form XObject makes that space follow an enclosing CSS transform.
     */
    private function wrapPatternContent(string $content): string
    {
        if (!$this->patternUsed || $this->paints === null || trim($content) === '') {
            return $content;
        }
        return '/' . $this->paints->addForm($content, null, false) . " Do
";
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') ?: '0';
    }

    /**
     * A default rendering state (black fill, no stroke) for detached content such as patterns, masks and markers.
     *
     * @param array<string, mixed> $s
     * @param array<int, float> $ctm
     * @return array<string, mixed>
     */
    private function freshState(array $s, array $ctm, float $pageHeight): array
    {
        return [
            'fill' => '#000000',
            'stroke' => 'none',
            'strokeWidth' => 1.0,
            'currentColor' => $s['currentColor'],
            'opacity' => 1.0,
            'fillOpacity' => 1.0,
            'strokeOpacity' => 1.0,
            'dashArray' => [],
            'dashOffset' => 0.0,
            'lineCap' => 0,
            'lineJoin' => 0,
            'miterLimit' => 4.0,
            'fillRule' => 'nonzero',
            'fontSize' => 12.0,
            'fontFamily' => $s['fontFamily'],
            'fontWeight' => 'normal',
            'fontStyle' => 'normal',
            'textAnchor' => 'start',
            'visible' => true,
            'ctm' => $ctm,
            'vpW' => $s['vpW'],
            'vpH' => $s['vpH'],
            'pageHeight' => $pageHeight,
            'useDepth' => $s['useDepth'],
        ];
    }

    // ------------------------------------------------------------------
    // Painting with paint servers
    // ------------------------------------------------------------------

    /**
     * True when the fill or stroke of $s refers to a gradient or pattern.
     *
     * @param array<string, mixed> $s
     */
    private function usesServerPaint(array $s, bool $allowFill): bool
    {
        if ($this->paints === null) {
            return false;
        }
        if ($allowFill && $this->serverElement((string) $s['fill']) !== null) {
            return true;
        }
        return (float) $s['strokeWidth'] > 0 && $this->serverElement((string) $s['stroke']) !== null;
    }

    /**
     * paint() variant for shapes whose fill and/or stroke is a gradient or pattern.
     *
     * @param array<string, mixed> $s
     */
    private function paintWithServers(string $path, array $s, bool $closed, string &$out, bool $allowFill): void
    {
        $bbox = $this->pathBBoxUser($path, $s['ctm'], (float) $s['pageHeight']);
        $evenOdd = $s['fillRule'] === 'evenodd';
        $opacity = (float) $s['opacity'];

        if ($allowFill) {
            $spec = $this->paintSpec((string) $s['fill'], $s, $bbox);
            if ($spec !== null) {
                $alpha = (float) $s['fillOpacity'] * $opacity * (isset($spec['rgba']) ? $spec['rgba'][3] : 1.0);
                $gs = $this->alphaState($alpha);
                $gsCmd = $gs !== null ? "/{$gs} gs\n" : '';
                if ($alpha > 0.001) {
                    if (isset($spec['rgba'])) {
                        $c = $spec['rgba'];
                        $out .= "q\n{$gsCmd}" . ColorOps::fillOp($c) . "\n" . $path . ($evenOdd ? "f*\n" : "f\n") . "Q\n";
                    } elseif (isset($spec['sh'])) {
                        $out .= "q\n" . (($spec['smask'] ?? null) !== null ? "/{$spec['smask']} gs\n" : '') . $gsCmd
                            . $path . ($evenOdd ? "W*" : "W") . " n\n"
                            . implode(' ', array_map(static fn(float $v): string => sprintf('%.5F', $v), $spec['matrix'])) . " cm\n/{$spec['sh']} sh\nQ\n";
                    } else {
                        $this->patternUsed = true;
                        $out .= "q\n{$gsCmd}/Pattern cs /{$spec['pat']} scn\n" . $path . ($evenOdd ? "f*\n" : "f\n") . "Q\n";
                    }
                }
            }
        }

        if ((float) $s['strokeWidth'] > 0) {
            $spec = $this->paintSpec((string) $s['stroke'], $s, $bbox);
            if ($spec !== null) {
                $alpha = (float) $s['strokeOpacity'] * $opacity * (isset($spec['rgba']) ? $spec['rgba'][3] : 1.0);
                $gs = $this->alphaState($alpha);
                $gsCmd = $gs !== null ? "/{$gs} gs\n" : '';
                $op = $closed ? "s\n" : "S\n";
                if ($alpha > 0.001) {
                    if (isset($spec['rgba'])) {
                        $out .= "q\n{$gsCmd}" . $this->strokeStyleOps($spec['rgba'], $s) . $path . $op . "Q\n";
                    } else {
                        $style = $this->strokeStyleOps([0.0, 0.0, 0.0, 1.0], $s);
                        $style = substr($style, 0, (int) strrpos(rtrim($style), "\n") + 1); // drop the trailing colour operator
                        if (isset($spec['sh'])) {
                            $name = $this->paints?->addShadingPattern($spec['sh'], $spec['matrix']) ?? '';
                            $smask = ($spec['smask'] ?? null) !== null ? "/{$spec['smask']} gs\n" : '';
                        } else {
                            $name = $spec['pat'];
                            $smask = '';
                        }
                        $this->patternUsed = true;
                        $out .= "q\n{$smask}{$gsCmd}{$style}/Pattern CS /{$name} SCN\n" . $path . $op . "Q\n";
                    }
                }
            }
        }
    }

    /**
     * Text hook: captures the run's box in sink mode and paints gradient / pattern fills.
     * Returns true when the run has been handled (the caller must not draw it again).
     *
     * @param array<string, mixed> $s
     */
    private function textExtras(string $text, array $s, float $x, float $y, string &$out): bool
    {
        $isSink = $this->sink !== null;
        $ref = !$isSink ? $this->serverElement((string) $s['fill']) : null;
        if (!$isSink && $ref === null) {
            return false;
        }
        $fs = (float) $s['fontSize'];
        if (!$s['visible'] || $fs <= 0) {
            return true;
        }
        $width = $this->fontManager->measureText($text, (string) $s['fontFamily'], (string) $s['fontWeight'], (string) $s['fontStyle'], $fs);
        $boxPath = $this->pt($x, $y - $fs * 0.8, $s) . " m\n" . $this->pt($x + $width, $y - $fs * 0.8, $s) . " l\n"
            . $this->pt($x + $width, $y + $fs * 0.25, $s) . " l\n" . $this->pt($x, $y + $fs * 0.25, $s) . " l\nh\n";
        if ($isSink) {
            // The third element carries real glyph clipping operators (used when a clipPath holds only text)
            $ttf = $this->fontManager->resolveFont((string) $s['fontFamily'], (string) $s['fontWeight'], (string) $s['fontStyle']);
            $hex = $this->fontManager->encodeTextToHexGids($ttf, $text);
            $glyphs = '';
            if ($hex !== '' && (float) $s['pageHeight'] !== 0.0) {
                $m = $this->multiply($s['ctm'], [$fs, 0.0, 0.0, -$fs, $x, $y]);
                $glyphs = sprintf(
                    "7 Tr\n/%s 1 Tf\n%.4F %.4F %.4F %.4F %.2F %.2F Tm\n<%s> Tj\n",
                    $this->fontManager->markFontUsed($ttf),
                    $m[0],
                    -$m[1],
                    $m[2],
                    -$m[3],
                    $m[4],
                    (float) $s['pageHeight'] - $m[5],
                    $hex
                );
            }
            $this->sink[] = [$boxPath, (string) ($s['clipRule'] ?? 'nonzero'), $glyphs];
            return true;
        }

        $bbox = $this->pathBBoxUser($boxPath, $s['ctm'], (float) $s['pageHeight']);
        $spec = $ref !== null ? $this->paintSpec((string) $s['fill'], $s, $bbox) : null;
        if ($spec === null) {
            return true;
        }
        $alpha = (float) $s['fillOpacity'] * (float) $s['opacity'] * (isset($spec['rgba']) ? $spec['rgba'][3] : 1.0);
        if ($alpha <= 0.001) {
            return true;
        }
        $ttf = $this->fontManager->resolveFont((string) $s['fontFamily'], (string) $s['fontWeight'], (string) $s['fontStyle']);
        $fontId = $this->fontManager->markFontUsed($ttf);
        $hexGids = $this->fontManager->encodeTextToHexGids($ttf, $text);
        if ($hexGids === '') {
            return true;
        }
        $m = $this->multiply($s['ctm'], [$fs, 0.0, 0.0, -$fs, $x, $y]);
        $gs = $this->alphaState($alpha);
        $tm = sprintf("%.4F %.4F %.4F %.4F %.2F %.2F Tm", $m[0], -$m[1], $m[2], -$m[3], $m[4], (float) $s['pageHeight'] - $m[5]);
        $gsCmd = $gs !== null ? "/{$gs} gs\n" : '';

        if (isset($spec['sh'])) {
            $out .= "q\n" . (($spec['smask'] ?? null) !== null ? "/{$spec['smask']} gs\n" : '') . $gsCmd
                . "BT\n7 Tr\n/{$fontId} 1 Tf\n{$tm}\n<{$hexGids}> Tj\nET\n"
                . implode(' ', array_map(static fn(float $v): string => sprintf('%.5F', $v), $spec['matrix'])) . " cm\n/{$spec['sh']} sh\nQ\n";
        } elseif (isset($spec['pat'])) {
            $this->patternUsed = true;
            $out .= "q\n{$gsCmd}/Pattern cs /{$spec['pat']} scn\nBT\n0 Tr\n/{$fontId} 1 Tf\n{$tm}\n<{$hexGids}> Tj\nET\nQ\n";
        } else {
            $c = $spec['rgba'];
            $out .= "q\n{$gsCmd}" . ColorOps::fillOp($c) . "\n"
                . "BT\n0 Tr\n/{$fontId} 1 Tf\n{$tm}\n<{$hexGids}> Tj\nET\nQ\n";
        }
        return true;
    }
}
