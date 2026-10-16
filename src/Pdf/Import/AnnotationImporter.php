<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Converts the /Annots of a source page into position-independent records stored on the ImportedPage:
 *
 *  - Link annotations with URI actions and with /Dest or /A /GoTo destinations (named destinations are resolved).
 *    A destination is kept as ['page' => source page number, 'tail' => '/XYZ x y z'] and re-pointed to the output
 *    page object when the page is emitted (links to pages that were not imported are dropped there).
 *  - Markup annotations (Text, FreeText, Highlight, Underline, Squiggly, StrikeOut, Square, Circle, Line, Polygon,
 *    PolyLine, Ink, Stamp, Caret) with their appearance streams (deep-copied into the pool).
 *  - Widget annotations of AcroForm fields (values, appearances, flags and the inherited field attributes are kept;
 *    the field hierarchy is flattened to the field's fully qualified name).
 *
 * Geometry (/Rect, /QuadPoints, ...) is converted into "visible page space" (crop box origin removed, /Rotate
 * applied) so that it only needs the placement transform when the page is emitted. Actions other than URI /
 * GoTo (JavaScript, Launch, SubmitForm, ...), additional actions (/AA), pop-ups and annotation relationships
 * (/IRT) are intentionally dropped.
 *
 * Record layout: ['kind' => 'link'|'markup'|'widget', 'entries' => raw dictionary entries (pool placeholders),
 * 'geom' => array<string, mixed> (visible space numbers), plus 'dest' (link), 'name' / 'group' / 'fieldEntries' / 'da' (widget)].
 */
final class AnnotationImporter
{
    private const GEOM_KEYS = ['QuadPoints', 'Vertices', 'InkList', 'L', 'CL'];
    private const MARKUP = ['Text', 'FreeText', 'Line', 'Square', 'Circle', 'Polygon', 'PolyLine', 'Highlight', 'Underline', 'Squiggly', 'StrikeOut', 'Stamp', 'Caret', 'Ink'];
    /** Keys never copied from an annotation dictionary */
    private const SKIP = ['Type', 'Subtype', 'Rect', 'P', 'Parent', 'Popup', 'IRT', 'RT', 'A', 'AA', 'Dest', 'OC', 'StructParent', 'Next', 'Prev', 'AP', 'Kids', 'T', 'Lock', 'SV', 'DA', 'AF', 'Measure', 'NM'];
    /** Field-level keys (inheritable) */
    private const FIELD_KEYS = ['FT', 'Ff', 'V', 'DV', 'Opt', 'MaxLen', 'Q', 'TU', 'I', 'TI', 'RV', 'DS'];

    private PageImporter $imp;
    private PdfReader $reader;
    /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} page space -> visible page space */
    private array $m;
    private int $rot;
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    private array $box;

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $box visible box in source coordinates
     */
    public static function import(PageImporter $imp, ImportedPage $page, PdfDict $pageDict, array $box, int $rot): void
    {
        $self = new self();
        $self->imp = $imp;
        $self->reader = $imp->getReader();
        $self->box = $box;
        $self->rot = $rot;
        [$x0, $y0, $x1, $y1] = $box;
        $self->m = match ($rot) {
            90 => [0.0, -1.0, 1.0, 0.0, -$y0, $x1],
            180 => [-1.0, 0.0, 0.0, -1.0, $x1, $y1],
            270 => [0.0, 1.0, -1.0, 0.0, $y1, -$x0],
            default => [1.0, 0.0, 0.0, 1.0, -$x0, -$y0],
        };
        $self->run($page, $pageDict);
    }

    private function run(ImportedPage $page, PdfDict $pageDict): void
    {
        $r = $this->reader;
        $imp = $this->imp;
        $imp->setRefHook(null);

        // AcroForm defaults of the source (only needed when the page has widgets)
        $catalog = $r->getCatalog();
        $acro = $catalog !== null ? $r->resolve($catalog->get('AcroForm')) : null;
        $acro = $acro instanceof PdfDict && !($acro instanceof PdfStream) ? $acro : null;

        $annots = $r->resolve($pageDict->get('Annots'));
        if (!is_array($annots)) {
            return;
        }
        $hasWidget = false;
        foreach (array_slice($annots, 0, 2000) as $ref) {
            $a = $r->resolve($ref);
            if (!$a instanceof PdfDict || $a instanceof PdfStream) {
                continue;
            }
            $sub = $a->name('Subtype') ?? '';
            $flags = $r->resolve($a->get('F'));
            if (is_int($flags) && ($flags & 2) !== 0) {
                continue; // hidden
            }
            $rect = $this->rect($a->get('Rect'));
            if ($rect === null) {
                continue;
            }
            $rec = null;
            if ($sub === 'Link') {
                $rec = $this->link($a, $rect);
            } elseif ($sub === 'Widget') {
                $rec = $this->widget($a, $rect, $acro);
                $hasWidget = $hasWidget || $rec !== null;
            } elseif (in_array($sub, self::MARKUP, true)) {
                $rec = $this->markup($a, $rect);
            }
            if ($rec !== null) {
                $page->annots[] = $rec;
            }
        }

        if ($hasWidget && $acro !== null) {
            $page->needAppearances = $r->resolve($acro->get('NeedAppearances')) === true;
            $da = $r->resolve($acro->get('DA'));
            $page->acroDa = $da instanceof PdfString ? $da->bytes : null;
            $dr = $r->resolve($acro->get('DR'));
            $fonts = $dr instanceof PdfDict ? $r->resolve($dr->get('Font')) : null;
            if ($fonts instanceof PdfDict) {
                foreach (array_slice($fonts->items, 0, 64, true) as $name => $fontRef) {
                    $page->acroFonts[(string) $name] = $imp->serializeValue($fontRef);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Links
    // ------------------------------------------------------------------

    /** @param array{0: float, 1: float, 2: float, 3: float} $rect */
    private function link(PdfDict $a, array $rect): ?array
    {
        $r = $this->reader;
        $entries = $this->plainEntries($a, ['Border', 'BS', 'C', 'H', 'F', 'Contents']);
        if (!str_contains($entries, '/Border')) {
            $entries .= ' /Border [0 0 0]';
        }
        $geom = ['Rect' => $rect] + $this->geometry($a);
        $rec = ['kind' => 'link', 'entries' => $entries, 'geom' => $geom, 'dest' => null];

        $dest = $r->resolve($a->get('Dest'));
        $action = $r->resolve($a->get('A'));
        if ($dest === null && $action instanceof PdfDict && !($action instanceof PdfStream)) {
            $s = $action->name('S');
            if ($s === 'URI') {
                $uri = $r->resolve($action->get('URI'));
                if (!$uri instanceof PdfString || $uri->bytes === '' || preg_match('~^\s*(javascript|vbscript|data|file):~i', $uri->bytes) === 1) {
                    return null;
                }
                $rec['entries'] .= ' /A << /Type /Action /S /URI /URI <' . strtoupper(bin2hex($uri->bytes)) . '> >>';
                return $rec;
            }
            if ($s !== 'GoTo') {
                return null; // JavaScript, Launch, GoToR, Named, SubmitForm ... are not carried over
            }
            $dest = $r->resolve($action->get('D'));
        }
        if ($dest === null) {
            return null;
        }
        $d = $this->destination($dest);
        if ($d === null) {
            return null;
        }
        $rec['dest'] = $d;
        return $rec;
    }

    /** @return ?array{page: int, tail: string} */
    private function destination(mixed $dest, int $depth = 0): ?array
    {
        $r = $this->reader;
        if ($depth > 3) {
            return null;
        }
        $dest = $r->resolve($dest);
        if ($dest instanceof PdfString || $dest instanceof PdfName) {
            $name = $dest instanceof PdfString ? $dest->bytes : $dest->name;
            $resolved = $this->namedDestination($name);
            return $resolved === null ? null : $this->destination($resolved, $depth + 1);
        }
        if ($dest instanceof PdfDict && !($dest instanceof PdfStream)) {
            return $this->destination($dest->get('D'), $depth + 1);
        }
        if (!is_array($dest) || count($dest) < 2 || !$dest[0] instanceof PdfRef) {
            return null;
        }
        $page = $this->imp->pageNumberOfRef($dest[0]);
        if ($page === null) {
            return null;
        }
        $type = $r->resolve($dest[1]);
        $mode = $type instanceof PdfName ? $type->name : 'Fit';
        $num = function (int $i) use ($dest, $r): ?float {
            $v = $r->resolve($dest[$i] ?? null);
            return is_int($v) || is_float($v) ? (float) $v : null;
        };
        $tail = '/Fit';
        if ($mode === 'XYZ') {
            $left = $num(2);
            $top = $num(3);
            $zoom = $num(4);
            if ($this->rot === 0 || ($left !== null && $top !== null)) {
                [$vx, $vy] = $this->point($left ?? 0.0, $top ?? 0.0);
                $tail = '/XYZ ' . ($left === null ? 'null' : $this->n($vx)) . ' ' . ($top === null ? 'null' : $this->n($vy)) . ' ' . ($zoom === null ? 'null' : $this->n($zoom));
            }
        } elseif ($this->rot === 0 && ($mode === 'FitH' || $mode === 'FitBH') && $num(2) !== null) {
            $tail = '/' . $mode . ' ' . $this->n($num(2) - $this->box[1]);
        } elseif ($this->rot === 0 && ($mode === 'FitV' || $mode === 'FitBV') && $num(2) !== null) {
            $tail = '/' . $mode . ' ' . $this->n($num(2) - $this->box[0]);
        } elseif ($mode === 'FitB') {
            $tail = '/FitB';
        }
        return ['page' => $page, 'tail' => $tail];
    }

    private function namedDestination(string $name): mixed
    {
        $r = $this->reader;
        $cat = $r->getCatalog();
        if ($cat === null) {
            return null;
        }
        $dests = $r->resolve($cat->get('Dests'));
        if ($dests instanceof PdfDict && $dests->has($name)) {
            return $dests->get($name);
        }
        $names = $r->resolve($cat->get('Names'));
        $tree = $names instanceof PdfDict ? $r->resolve($names->get('Dests')) : null;
        $budget = 5000;
        return $tree instanceof PdfDict ? $this->searchNameTree($tree, $name, $budget) : null;
    }

    private function searchNameTree(PdfDict $node, string $name, int &$budget): mixed
    {
        $r = $this->reader;
        if (--$budget < 0) {
            return null;
        }
        $pairs = $r->resolve($node->get('Names'));
        if (is_array($pairs)) {
            for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                $k = $r->resolve($pairs[$i]);
                if ($k instanceof PdfString && $k->bytes === $name) {
                    return $pairs[$i + 1];
                }
            }
        }
        $kids = $r->resolve($node->get('Kids'));
        if (is_array($kids)) {
            foreach ($kids as $kid) {
                $k = $r->resolve($kid);
                if ($k instanceof PdfDict && !($k instanceof PdfStream)) {
                    $found = $this->searchNameTree($k, $name, $budget);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Markup annotations
    // ------------------------------------------------------------------

    /** @param array{0: float, 1: float, 2: float, 3: float} $rect */
    private function markup(PdfDict $a, array $rect): ?array
    {
        $ap = $this->appearance($a);
        if ($ap === '') {
            return null; // without an appearance stream the annotation would be invisible in many viewers
        }
        $entries = $this->plainEntries($a, null) . $ap;
        return ['kind' => 'markup', 'entries' => '/Subtype /' . $a->name('Subtype') . $entries, 'geom' => ['Rect' => $rect] + $this->geometry($a)];
    }

    // ------------------------------------------------------------------
    // Widgets / form fields
    // ------------------------------------------------------------------

    /** @param array{0: float, 1: float, 2: float, 3: float} $rect */
    private function widget(PdfDict $w, array $rect, ?PdfDict $acro): ?array
    {
        $r = $this->reader;
        // Field attributes are inherited through the /Parent chain
        $chain = [$w];
        $cur = $w;
        for ($i = 0; $i < 16; $i++) {
            $p = $r->resolve($cur->get('Parent'));
            if (!$p instanceof PdfDict || $p instanceof PdfStream || in_array($p, $chain, true)) {
                break;
            }
            $chain[] = $p;
            $cur = $p;
        }
        $inherited = [];
        foreach (self::FIELD_KEYS as $k) {
            foreach ($chain as $d) {
                if ($d->has($k)) {
                    $inherited[$k] = $d->get($k);
                    break;
                }
            }
        }
        if (!isset($inherited['FT'])) {
            return null;
        }
        $names = [];
        foreach (array_reverse($chain) as $d) {
            $t = $r->resolve($d->get('T'));
            if ($t instanceof PdfString) {
                $names[] = $this->text($t->bytes);
            }
        }
        $name = str_replace('.', '_', implode('.', $names));
        $da = null;
        foreach ($chain as $d) {
            $v = $r->resolve($d->get('DA'));
            if ($v instanceof PdfString) {
                $da = $v->bytes;
                break;
            }
        }

        $own = $r->resolve($w->get('T')) instanceof PdfString;
        $parent = $chain[1] ?? null;
        // Kid widget without /T: the parent is the terminal field; several kids share it (radio buttons)
        $group = (!$own && $parent !== null) ? 'g' . spl_object_id($parent) : null;

        $fieldEntries = '';
        foreach ($inherited as $k => $v) {
            $fieldEntries .= ' /' . $k . ' ' . $this->imp->serializeValue($v);
        }
        $widgetEntries = ' /Subtype /Widget' . $this->plainEntries($w, null, self::FIELD_KEYS) . $this->appearance($w);
        if ($this->reader->resolve($w->get('AP')) === null && !isset($inherited['V'])) {
            // no appearance and no value: still a valid (empty) field
        }
        return [
            'kind' => 'widget',
            'entries' => $widgetEntries,
            'fieldEntries' => $fieldEntries,
            'geom' => ['Rect' => $rect],
            'name' => $name,
            'group' => $group,
            'da' => $da ?? ($acro !== null && ($d = $r->resolve($acro->get('DA'))) instanceof PdfString ? $d->bytes : null),
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Serializes the dictionary entries that are not special-cased.
     *
     * @param ?list<string> $only restrict to these keys (null = everything except SKIP / geometry)
     * @param list<string> $extraSkip
     */
    private function plainEntries(PdfDict $a, ?array $only, array $extraSkip = []): string
    {
        $out = '';
        foreach ($a->items as $key => $val) {
            $key = (string) $key;
            if ($only !== null ? !in_array($key, $only, true) : (in_array($key, self::SKIP, true) || in_array($key, $extraSkip, true))) {
                continue;
            }
            if (in_array($key, self::GEOM_KEYS, true)) {
                continue;
            }
            $out .= ' ' . $this->nameToken($key) . ' ' . $this->imp->serializeValue($val);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function geometry(PdfDict $a): array
    {
        $g = [];
        foreach (self::GEOM_KEYS as $k) {
            $v = $this->reader->resolve($a->get($k));
            if (!is_array($v)) {
                continue;
            }
            $conv = $this->numbersDeep($v);
            if ($conv !== null) {
                $g[$k] = $conv;
            }
        }
        return $g;
    }

    /** Converts nested arrays of numbers (pairs = points) into visible-space numbers. */
    private function numbersDeep(array $v): ?array
    {
        $flat = [];
        $nested = [];
        foreach ($v as $item) {
            $item = $this->reader->resolve($item);
            if (is_int($item) || is_float($item)) {
                $flat[] = (float) $item;
            } elseif (is_array($item)) {
                $sub = $this->numbersDeep($item);
                if ($sub === null) {
                    return null;
                }
                $nested[] = $sub;
            } else {
                return null;
            }
        }
        if ($nested !== []) {
            return $nested;
        }
        $out = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            [$x, $y] = $this->point($flat[$i], $flat[$i + 1]);
            $out[] = $x;
            $out[] = $y;
        }
        return $out;
    }

    /** /AP entry (normal, rollover and down appearances; rotated for rotated pages) as raw entry text. */
    private function appearance(PdfDict $a): string
    {
        $r = $this->reader;
        $ap = $r->resolve($a->get('AP'));
        if (!$ap instanceof PdfDict || $ap instanceof PdfStream || !$ap->has('N')) {
            return '';
        }
        $parts = [];
        foreach (['N', 'D', 'R'] as $k) {
            if (!$ap->has($k)) {
                continue;
            }
            $v = $this->apValue($ap->get($k));
            if ($v !== null) {
                $parts[] = '/' . $k . ' ' . $v;
            }
        }
        if ($parts === [] || !isset($parts[0]) || !str_starts_with($parts[0], '/N ')) {
            return '';
        }
        $out = ' /AP << ' . implode(' ', $parts) . ' >>';
        $as = $r->resolve($a->get('AS'));
        if ($as instanceof PdfName) {
            $out .= ' /AS ' . $this->nameToken($as->name);
        }
        return $out;
    }

    private function apValue(mixed $raw): ?string
    {
        $r = $this->reader;
        $v = $r->resolve($raw);
        if ($v instanceof PdfStream) {
            return $this->apStream($raw, $v);
        }
        if ($v instanceof PdfDict) { // appearance states
            $out = [];
            foreach (array_slice($v->items, 0, 32, true) as $state => $sRaw) {
                $s = $r->resolve($sRaw);
                if ($s instanceof PdfStream) {
                    $out[] = $this->nameToken((string) $state) . ' ' . $this->apStream($sRaw, $s);
                }
            }
            return $out === [] ? null : '<< ' . implode(' ', $out) . ' >>';
        }
        return null;
    }

    private function apStream(mixed $raw, PdfStream $s): string
    {
        if ($this->rot === 0) {
            return $this->imp->serializeValue($raw);
        }
        // Rotated page: rotate the appearance so that it fits the rotated /Rect
        $old = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $mx = $this->reader->resolve($s->get('Matrix'));
        if (is_array($mx) && count($mx) === 6) {
            $tmp = [];
            foreach ($mx as $x) {
                $x = $this->reader->resolve($x);
                $tmp[] = is_int($x) || is_float($x) ? (float) $x : 0.0;
            }
            $old = $tmp;
        }
        [$a, $b, $c, $d] = [$this->m[0], $this->m[1], $this->m[2], $this->m[3]];
        $new = [
            $old[0] * $a + $old[1] * $c,
            $old[0] * $b + $old[1] * $d,
            $old[2] * $a + $old[3] * $c,
            $old[2] * $b + $old[3] * $d,
            0.0,
            0.0,
        ];
        $items = $s->items;
        $items['Matrix'] = array_map(static fn(float $f): float => $f, $new);
        return $this->imp->importStreamCopy($s, $items);
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float} normalized rectangle in visible space
     */
    private function rect(mixed $v): ?array
    {
        $v = $this->reader->resolve($v);
        if (!is_array($v) || count($v) < 4) {
            return null;
        }
        $n = [];
        for ($i = 0; $i < 4; $i++) {
            $x = $this->reader->resolve($v[$i]);
            if (!is_int($x) && !is_float($x)) {
                return null;
            }
            $n[] = (float) $x;
        }
        [$ax, $ay] = $this->point($n[0], $n[1]);
        [$bx, $by] = $this->point($n[2], $n[3]);
        return [min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)];
    }

    /** @return array{0: float, 1: float} */
    private function point(float $x, float $y): array
    {
        [$a, $b, $c, $d, $e, $f] = $this->m;
        return [$a * $x + $c * $y + $e, $b * $x + $d * $y + $f];
    }

    private function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function nameToken(string $n): string
    {
        return '/' . (string) preg_replace_callback(
            '/[^\x21-\x7E]|[#()<>\[\]{}\/%]/',
            static fn(array $m): string => sprintf('#%02X', ord($m[0])),
            $n
        );
    }

    /** PDF text string (PDFDocEncoding / UTF-16BE with BOM / UTF-8 with BOM) to UTF-8. */
    private function text(string $b): string
    {
        if (str_starts_with($b, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($b, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($b, "\xEF\xBB\xBF")) {
            return substr($b, 3);
        }
        return (string) mb_convert_encoding($b, 'UTF-8', 'Windows-1252');
    }
}
