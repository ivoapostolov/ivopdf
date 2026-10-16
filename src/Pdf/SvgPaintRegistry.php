<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Registry of the PDF objects SVG needs beyond plain path painting: axial/radial shadings (with stitching
 * functions, optional spread-method repetition), shading patterns, tiling patterns, generic Form XObjects and
 * luminosity soft masks (ExtGState /SMask). Named resources are merged into the document's shared resources
 * by {@see PdfDocument::compile()}: shadings SvS#, patterns SvP#, form XObjects SvF#, ExtGStates SvG#.
 */
final class SvgPaintRegistry
{
    private const BIG_BBOX = '[-20000 -20000 40000 40000]';

    /** @var array<string, array{type: int, coords: list<float>, domain: array{0: float, 1: float}, stops: list<array{0: float, 1: float, 2: float, 3: float, 4: float}>, channel: string}> */
    private array $shadings = [];

    /** @var array<string, array{shading: string, matrix: list<float>}> */
    private array $shadingPatterns = [];

    /** @var array<string, array{content: string, bbox: list<float>, matrix: list<float>, xstep: float, ystep: float}> */
    private array $tilings = [];

    /** @var array<string, array{content: string, bbox: string, group: bool}> */
    private array $forms = [];

    /** @var array<string, array{form: string, alpha: ?float}> */
    private array $softMasks = [];

    /** @var array<string, string> dedupe key => name */
    private array $keys = [];

    public function isEmpty(): bool
    {
        return $this->shadings === [] && $this->tilings === [] && $this->forms === [] && $this->softMasks === [];
    }

    /**
     * Registers a shading. $stops are [offset, r, g, b, a] over the given function domain; for channel 'alpha'
     * the alpha component is rendered as a DeviceGray ramp (used for soft masks of stop-opacity).
     *
     * @param list<float> $coords x0 y0 x1 y1 (axial) or x0 y0 r0 x1 y1 r1 (radial)
     * @param array{0: float, 1: float} $domain
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $stops
     */
    public function addShading(int $type, array $coords, array $domain, array $stops, string $channel = 'rgb'): string
    {
        $key = 'sh:' . md5(serialize([$type, $coords, $domain, $stops, $channel]));
        if (isset($this->keys[$key])) {
            return $this->keys[$key];
        }
        $name = 'SvS' . (count($this->shadings) + 1);
        $this->shadings[$name] = ['type' => $type, 'coords' => $coords, 'domain' => $domain, 'stops' => $stops, 'channel' => $channel];
        return $this->keys[$key] = $name;
    }

    /**
     * Registers a shading pattern (PatternType 2) mapping shading space through $matrix (PDF page space).
     *
     * @param list<float> $matrix
     */
    public function addShadingPattern(string $shading, array $matrix): string
    {
        $key = 'sp:' . md5(serialize([$shading, $matrix]));
        if (isset($this->keys[$key])) {
            return $this->keys[$key];
        }
        $name = 'SvP' . (count($this->shadingPatterns) + count($this->tilings) + 1);
        $this->shadingPatterns[$name] = ['shading' => $shading, 'matrix' => $matrix];
        return $this->keys[$key] = $name;
    }

    /**
     * Registers a coloured tiling pattern (PatternType 1).
     *
     * @param list<float> $bbox
     * @param list<float> $matrix
     */
    public function addTiling(string $content, array $bbox, array $matrix, float $xstep, float $ystep): string
    {
        $name = 'SvP' . (count($this->shadingPatterns) + count($this->tilings) + 1);
        $this->tilings[$name] = ['content' => $content, 'bbox' => $bbox, 'matrix' => $matrix, 'xstep' => $xstep, 'ystep' => $ystep];
        return $name;
    }

    /**
     * Registers a transparency-group Form XObject (resources = the shared document resources) and returns its name.
     *
     * @param list<float>|null $bbox [x0 y0 x1 y1]; null = a very large box
     */
    public function addForm(string $content, ?array $bbox = null, bool $group = true): string
    {
        $name = 'SvF' . (count($this->forms) + 1);
        $this->forms[$name] = [
            'content' => $content,
            'bbox' => $bbox === null ? self::BIG_BBOX : sprintf('[%.2F %.2F %.2F %.2F]', ...$bbox),
            'group' => $group,
        ];
        return $name;
    }

    /**
     * Registers a luminosity soft mask whose mask group draws $content; returns the ExtGState name to apply with `gs`.
     * The content is in the coordinate space that is current when the ExtGState is applied.
     */
    public function addLuminosityMask(string $content): string
    {
        $form = $this->addForm($content);
        $name = 'SvG' . (count($this->softMasks) + 1);
        $this->softMasks[$name] = ['form' => $form, 'alpha' => null];
        return $name;
    }

    /**
     * Allocates and writes the self-contained objects; returns the resource entries to merge plus the pending streams
     * (forms / tilings) that need the shared resources object id via {@see fill()}.
     *
     * @param callable(): int $alloc
     * @param callable(int, string): void $set
     * @return array{xobject: array<string, int>, pattern: array<string, int>, extgstate: array<string, int>, shading: array<string, int>, pending: array<string, array{id: int, kind: string}>}
     */
    public function build(callable $alloc, callable $set): array
    {
        $res = ['xobject' => [], 'pattern' => [], 'extgstate' => [], 'shading' => [], 'pending' => []];
        if ($this->isEmpty() && $this->shadingPatterns === []) {
            return $res;
        }

        foreach ($this->forms as $name => $_f) {
            $id = $alloc();
            $res['xobject'][$name] = $id;
            $res['pending'][$name] = ['id' => $id, 'kind' => 'form'];
        }
        foreach ($this->tilings as $name => $_t) {
            $id = $alloc();
            $res['pattern'][$name] = $id;
            $res['pending'][$name] = ['id' => $id, 'kind' => 'tiling'];
        }
        foreach ($this->shadings as $name => $sh) {
            $id = $alloc();
            $res['shading'][$name] = $id;
            $set($id, $this->shadingBody($sh));
        }
        foreach ($this->shadingPatterns as $name => $pat) {
            $id = $alloc();
            $res['pattern'][$name] = $id;
            $set($id, sprintf(
                '<< /Type /Pattern /PatternType 2 /Shading %d 0 R /Matrix [%s] >>',
                $res['shading'][$pat['shading']],
                $this->nums($pat['matrix'])
            ));
        }
        foreach ($this->softMasks as $name => $mask) {
            $id = $alloc();
            $res['extgstate'][$name] = $id;
            $set($id, sprintf(
                '<< /Type /ExtGState /SMask << /Type /Mask /S /Luminosity /G %d 0 R >> >>',
                $res['xobject'][$mask['form']]
            ));
        }

        return $res;
    }

    /**
     * Writes the pending form and tiling-pattern streams once the shared resources object id is known.
     *
     * @param array<string, array{id: int, kind: string}> $pending
     * @param callable(int, string): void $set
     */
    public function fill(array $pending, int $resourcesObjId, callable $set, bool $compress): void
    {
        foreach ($pending as $name => $p) {
            if ($p['kind'] === 'form') {
                $f = $this->forms[$name];
                [$data, $filter] = $this->maybeCompress($f['content'], $compress);
                $set($p['id'], sprintf(
                    "<< /Type /XObject /Subtype /Form /FormType 1 /BBox %s%s /Resources %d 0 R /Length %d%s >>\nstream\n%s\nendstream",
                    $f['bbox'],
                    $f['group'] ? ' /Group << /S /Transparency /I true /CS /DeviceRGB >>' : '',
                    $resourcesObjId,
                    strlen($data),
                    $filter,
                    $data
                ));
            } else {
                $t = $this->tilings[$name];
                [$data, $filter] = $this->maybeCompress($t['content'], $compress);
                $set($p['id'], sprintf(
                    "<< /Type /Pattern /PatternType 1 /PaintType 1 /TilingType 1 /BBox [%s] /XStep %.4F /YStep %.4F /Matrix [%s] /Resources %d 0 R /Length %d%s >>\nstream\n%s\nendstream",
                    $this->nums($t['bbox']),
                    $t['xstep'],
                    $t['ystep'],
                    $this->nums($t['matrix']),
                    $resourcesObjId,
                    strlen($data),
                    $filter,
                    $data
                ));
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function maybeCompress(string $data, bool $compress): array
    {
        if ($compress && $data !== '') {
            return [(string) gzcompress($data, 6), ' /Filter /FlateDecode'];
        }
        return [$data, ''];
    }

    /**
     * @param list<float> $nums
     */
    private function nums(array $nums): string
    {
        return implode(' ', array_map(static fn(float $n): string => rtrim(rtrim(sprintf('%.5F', $n), '0'), '.') ?: '0', $nums));
    }

    /**
     * @param array{type: int, coords: list<float>, domain: array{0: float, 1: float}, stops: list<array{0: float, 1: float, 2: float, 3: float, 4: float}>, channel: string} $sh
     */
    private function shadingBody(array $sh): string
    {
        $gray = $sh['channel'] === 'alpha';
        return sprintf(
            '<< /ShadingType %d /ColorSpace /%s /Coords [%s] /Domain [%s %s] /Function %s /Extend [true true] >>',
            $sh['type'],
            $gray ? 'DeviceGray' : 'DeviceRGB',
            $this->nums($sh['coords']),
            $this->nums([$sh['domain'][0]]),
            $this->nums([$sh['domain'][1]]),
            $this->functionBody($sh['stops'], $sh['domain'], $gray)
        );
    }

    /**
     * Builds a (stitching) function over $domain from stops [offset, r, g, b, a]; zero-length segments are dropped
     * so hard colour steps survive.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $stops
     * @param array{0: float, 1: float} $domain
     */
    private function functionBody(array $stops, array $domain, bool $gray): string
    {
        $col = static fn(array $s): string => $gray
            ? sprintf('%.4F', $s[4])
            : sprintf('%.4F %.4F %.4F', $s[1], $s[2], $s[3]);
        $fn2 = static fn(array $a, array $b): string => sprintf(
            '<< /FunctionType 2 /Domain [0 1] /C0 [%s] /C1 [%s] /N 1 >>',
            $col($a),
            $col($b)
        );

        // Normalise: monotone offsets that cover the whole domain
        $prevOff = $domain[0];
        foreach ($stops as $k => $st) {
            $stops[$k][0] = max($prevOff, min($domain[1], $st[0]));
            $prevOff = $stops[$k][0];
        }
        if ($stops[0][0] > $domain[0] + 1e-6) {
            array_unshift($stops, [$domain[0], $stops[0][1], $stops[0][2], $stops[0][3], $stops[0][4]]);
        }
        $lastIdx = count($stops) - 1;
        if ($stops[$lastIdx][0] < $domain[1] - 1e-6) {
            $stops[] = [$domain[1], $stops[$lastIdx][1], $stops[$lastIdx][2], $stops[$lastIdx][3], $stops[$lastIdx][4]];
        }

        $segs = [];
        $n = count($stops);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($stops[$i + 1][0] - $stops[$i][0] > 1e-6) {
                $segs[] = [$stops[$i], $stops[$i + 1]];
            }
        }
        if ($segs === []) {
            $last = $stops[$n - 1];
            return sprintf(
                '<< /FunctionType 2 /Domain [%s %s] /C0 [%s] /C1 [%s] /N 1 >>',
                $this->nums([$domain[0]]),
                $this->nums([$domain[1]]),
                $col($last),
                $col($last)
            );
        }

        $functions = [];
        $bounds = [];
        $encode = [];
        foreach ($segs as $k => $seg) {
            $functions[] = $fn2($seg[0], $seg[1]);
            $encode[] = '0 1';
            if ($k > 0) {
                $bounds[] = $seg[0][0];
            }
        }
        $clean = [];
        $prev = $domain[0];
        foreach ($bounds as $b) {
            $b = max($b, $prev + 1e-5);
            $clean[] = min($b, $domain[1] - 1e-5);
            $prev = $b;
        }

        return sprintf(
            '<< /FunctionType 3 /Domain [%s %s] /Functions [%s] /Bounds [%s] /Encode [%s] >>',
            $this->nums([$domain[0]]),
            $this->nums([$domain[1]]),
            implode(' ', $functions),
            $this->nums($clean),
            implode(' ', $encode)
        );
    }
}
