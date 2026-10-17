<?php

declare(strict_types=1);

namespace IvoPdf\Compat\Dompdf;

/**
 * Drop-in for the `Dompdf\Canvas` that `$dompdf->getCanvas()` returns (the CPDF adapter's public methods). Everything
 * drawn here becomes an overlay layer of the page (absolutely positioned HTML / inline SVG laid out by the IvoPDF
 * engine), coordinates are dompdf's: points from the top-left corner of the page.
 *
 *  - page_text() / page_line(): drawn on every page, `{PAGE_NUM}` / `{PAGE_COUNT}` substituted (only there: text()
 *    prints them literally, as in dompdf)
 *  - page_script(callable): called once per page with ($pageNumber, $pageCount, $canvas, $fontMetrics); the drawing
 *    calls it makes (text(), line(), rectangle(), image() ...) land on that page. Strings of PHP code are NOT executed.
 *    After render() page_text() / page_line() / page_script() work like dompdf's: they are applied at once to the pages
 *    that exist, with the page count get_page_count() reports (set_page_count() changes it), and get_page_number()
 *    inside the callback is the canvas' page number. Called before render() they are kept and applied to every page
 *    of the finished document (dompdf would only reach its first page).
 *  - text() / line() / rectangle() / image() ... outside page_script draw on the page the canvas is on: the first page
 *    before render(), the last page after render() (dompdf's canvas stays on its last page), the page added by
 *    new_page(), or a page reopened with reopen_object(<new_page() handle>) until close_object().
 *  - get_page_number() / get_page_count() are counters, as in dompdf: render() sets both to the number of pages,
 *    new_page() increments both, set_page_number() / set_page_count() only change the numbers (no drawing moves).
 *  - set_opacity($alpha, $mode) / save() / restore(): the opacity (and blend mode, for text and images) applies to the
 *    drawing calls that follow, until restore() returns to the saved state. The state starts fresh (opaque) for every
 *    page_script() call. Colors with an 'alpha' entry are multiplied with it.
 *  - rotate() / scale() / skew() / translate() / transform() change the coordinate system of the drawing calls that
 *    follow (same matrices as dompdf's CPDF adapter); clipping_rectangle() / clipping_roundrectangle() /
 *    clipping_polygon() clip them until clipping_end(); save() / restore() include both, as in PDF.
 *  - open_object() / close_object() / add_object($id, $where): the drawing calls between open and close are recorded and
 *    replayed on the pages chosen by $where ('add' = current page only, 'all' = current and following pages, 'next' =
 *    following pages, 'even' / 'odd' = current and following pages of that parity, 'nexteven' / 'nextodd');
 *    stop_object() ends the repetition after the current page, reopen_object() records more calls into an object.
 *    page_text() is not recorded into objects (it already repeats on every page).
 *  - add_link() / add_named_dest(): clickable areas (URL or '#name') and named destinations on the current page;
 *    add_info(): document metadata; javascript(): document-level JavaScript; new_page(): appends an empty page and
 *    returns a handle for it (like dompdf's page content object id: an int to pass to reopen_object()).
 *  - Methods of the CPDF adapter that have no equivalent here (serialize_object(), form fields select() / input() ...)
 *    are accepted, do nothing and add one warning per method to Dompdf::getWarnings().
 *
 * page_text(): like dompdf, `$y` is the top of the text and the baseline lies one font height (ascent + descent of the
 * font at that size) below it; the text rotates around its baseline origin.
 */
final class Canvas
{
    private const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    private float $width;
    private float $height;
    /** dompdf's page counters (get_page_number() / get_page_count()) */
    private int $pageCount = 0;
    private int $current = 1;
    /**
     * The page drawing calls land on: 'p<n>' = page n of the document, 'x<k>' = the k-th page added by new_page()
     * (laid out after the document, whatever its length turns out to be).
     */
    private string $target = 'p1';
    /** Pages of the document itself in the last rendering (without the new_page() pages); 0 before render(). */
    private int $docPages = 0;
    /** @var array<int, string> new_page() handle => page key */
    private array $pageHandles = [];
    /** @var list<array{0: ?int, 1: string}> open_object() / reopen_object() nesting: [object, page] to return to */
    private array $targetStack = [];
    private int $version = 0;
    private bool $rendering = false;
    /** Page being built while $rendering */
    private int $renderingPage = 1;
    private ?Dompdf $dompdf = null;

    /** @var list<array<string, mixed>> operations repeated on every page */
    private array $everyPage = [];
    /** @var list<callable> */
    private array $scripts = [];
    /** @var list<callable> callables drawn below the page content (dompdf begin_page_render callbacks) */
    private array $underScripts = [];
    /** @var list<callable> callables drawn above the page content (dompdf end_document / end_page_render callbacks) */
    private array $callbackScripts = [];
    /** @var array<int, list<array<string, mixed>>> page number => operations */
    private array $perPage = [];
    /** @var list<array<string, mixed>> operations collected while a page is being built */
    private array $buffer = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array{opacity: float, mode: string, ctm: list<float>, clips: list<list<float>>} graphics state of the calls that follow */
    private array $gs = ['opacity' => 1.0, 'mode' => 'Normal', 'ctm' => self::IDENTITY, 'clips' => []];
    /** @var list<array{opacity: float, mode: string, ctm: list<float>, clips: list<list<float>>}> */
    private array $gsStack = [];
    /** @var array<int, list<array<string, mixed>>> object id => recorded operations */
    private array $objects = [];
    private int $nextObject = 1;
    private ?int $objectTarget = null;
    /** @var list<array{id: int, where: string, page: int, until?: int}> add_object() calls made by the application */
    private array $placements = [];
    /** @var list<array{id: int, where: string, page: int, until?: int}> add_object() calls made by page_script() callbacks (this pass) */
    private array $scriptPlacements = [];
    /** @var list<int> ids of objects created by page_script() callbacks (this pass) */
    private array $scriptObjects = [];
    private int $lastRendered = 0;
    /** @var array<string, string> add_info() label (lower case) => value */
    private array $info = [];
    /** @var list<string> javascript() code */
    private array $js = [];
    private int $extraPages = 0;
    /** @var array{0: string, 1: list<mixed>}|null set_default_view() */
    private ?array $defaultView = null;
    /** @var array<string, true> methods already reported by __call() */
    private array $reported = [];

    public function __construct(private FontMetrics $metrics, float $width = 612.0, float $height = 792.0)
    {
        $this->width = $width;
        $this->height = $height;
    }

    /** @internal called by Dompdf */
    public function bindDompdf(Dompdf $dompdf): void
    {
        $this->dompdf = $dompdf;
    }

    /** Changes whenever the application drew something (Dompdf re-renders when it changed after render()). */
    public function version(): int
    {
        return $this->version;
    }

    public function hasContent(): bool
    {
        return $this->everyPage !== [] || $this->scripts !== [] || $this->underScripts !== [] || $this->callbackScripts !== []
            || $this->perPage !== [] || $this->placements !== [];
    }

    /** @return list<string> problems found while building overlays (e.g. a PHP-code string passed to page_script) */
    public function takeWarnings(): array
    {
        $w = $this->warnings;
        $this->warnings = [];
        return $w;
    }

    /** @internal @return array<string, string> metadata set with add_info() */
    public function infoEntries(): array
    {
        return $this->info;
    }

    /** @internal document JavaScript added with javascript() */
    public function javascriptCode(): string
    {
        return implode("\n", $this->js);
    }

    /** @internal number of pages added with new_page() */
    public function extraPages(): int
    {
        return $this->extraPages;
    }

    /** @internal @return array{0: string, 1: list<mixed>}|null set_default_view() arguments */
    public function defaultView(): ?array
    {
        return $this->defaultView;
    }

    /**
     * @internal Dompdf::setCallbacks() callables of the current render (replacing those of the previous one), called like
     * page_script() callables: $over above the page content (end_document, end_page_render), $under below it (begin_page_render).
     *
     * @param list<callable> $over
     * @param list<callable> $under
     */
    public function setRenderCallbacks(array $over, array $under): void
    {
        $this->callbackScripts = $over;
        $this->underScripts = $under;
    }

    public function setPageSize(float $w, float $h): void
    {
        $this->width = $w;
        $this->height = $h;
    }

    /**
     * @internal The document has $count pages (the new_page() pages included). $reset (render() called by the
     * application, or the first rendering): like dompdf's renderer, both page counters become $count and drawing moves
     * to the last page. A re-rendering done by output() keeps the counters and the drawing page as they are.
     */
    public function setPageCount(int $count, bool $reset = true): void
    {
        $this->docPages = max(1, $count - $this->extraPages);
        if ($reset) {
            $this->pageCount = $count;
            $this->current = max(1, $count);
            $this->target = $this->extraPages > 0 ? 'x' . $this->extraPages : 'p' . $this->docPages;
            $this->targetStack = [];
            $this->objectTarget = null;
        }
    }

    /**
     * @internal Runs $fn with get_page_number() reporting $number (dompdf end_document callbacks run after rendering,
     * when its counter is on the last page).
     */
    public function withPageNumber(int $number, callable $fn): mixed
    {
        $saved = $this->current;
        $this->current = $number;
        try {
            return $fn();
        } finally {
            $this->current = $saved;
        }
    }

    public function get_dompdf(): ?Dompdf
    {
        return $this->dompdf;
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

    /** dompdf: renumbers only; drawing stays on the page the canvas is on. */
    public function set_page_number($num): void
    {
        $this->current = (int) $num;
    }

    public function set_page_count($count): void
    {
        $this->pageCount = (int) $count;
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

    /** dompdf CPDF::add_info(): document metadata (Title, Author, Subject, Keywords, Creator, Producer). */
    public function add_info(string $label, string $value): void
    {
        $this->version++;
        $this->info[strtolower($label)] = $value;
    }

    /** dompdf CPDF::javascript(): document-level JavaScript (run by the viewer when the document opens). */
    public function javascript($code): void
    {
        $this->version++;
        $this->js[] = (string) $code;
    }

    /**
     * dompdf CPDF::new_page(): appends an empty page after the document, draws on it from now on and increments the
     * page number and page count. Returns a handle of the page (dompdf returns the id of the page's content object): an
     * int that reopen_object() accepts to draw on that page again, not a page number.
     */
    public function new_page(): int
    {
        $this->version++;
        $this->extraPages++;
        $this->pageCount++;
        $this->current++;
        $this->target = 'x' . $this->extraPages;
        $handle = $this->nextObject++;
        $this->pageHandles[$handle] = $this->target;
        return $handle;
    }

    /** dompdf CPDF::set_default_view(): initial view ('Fit', 'FitH', 'FitV', 'FitB', 'FitBH', 'XYZ' with [left, top, zoom]). */
    public function set_default_view($view, $options = []): void
    {
        $this->version++;
        $this->defaultView = [(string) $view, array_values((array) $options)];
    }

    // ------------------------------------------------------------------
    // every-page drawing
    // ------------------------------------------------------------------

    /**
     * @param mixed $font    descriptor from FontMetrics::getFont() (or a plain family name)
     * @param mixed $color   [r, g, b] 0..1, [c, m, y, k] / ['c' => ..], '#rrggbb', optionally with 'alpha'
     */
    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0): void
    {
        $this->version++;
        $op = $this->textOp($x, $y, (string) $text, $font, $size, $color, $word_space, $char_space, $angle);
        if ($this->docPages === 0) {
            $this->everyPage[] = $op + ['pageText' => true];
            return;
        }
        // after render(), as dompdf: written on the pages that exist now, numbered 1..n of get_page_count()
        $pages = $this->docPages + $this->extraPages;
        for ($n = 1; $n <= $pages; $n++) {
            $this->perPage[$this->keyOf($n, $this->docPages)][] = ['text' => strtr($op['text'], ['{PAGE_NUM}' => (string) $n, '{PAGE_COUNT}' => (string) $this->pageCount])] + $op;
        }
    }

    public function page_line($x1, $y1, $x2, $y2, $color = [0, 0, 0], $width = 1.0, $style = []): void
    {
        $this->version++;
        $op = ['op' => 'line', 'x1' => (float) $x1, 'y1' => (float) $y1, 'x2' => (float) $x2, 'y2' => (float) $y2, 'color' => $color, 'w' => (float) $width, 'dash' => (array) $style, 'cap' => 'butt'];
        if ($this->docPages === 0) {
            $this->everyPage[] = $op;
            return;
        }
        $pages = $this->docPages + $this->extraPages;
        for ($n = 1; $n <= $pages; $n++) {
            $this->perPage[$this->keyOf($n, $this->docPages)][] = $op;
        }
    }

    /** dompdf: `$code` is PHP source when isPhpEnabled; IvoPDF only accepts a callable (security). */
    public function page_script($callback): void
    {
        $this->version++;
        if (is_callable($callback) && !(is_string($callback) && preg_match('~^[\w\\\\:]+$~', $callback) !== 1)) {
            if ($this->docPages === 0) {
                $this->scripts[] = $callback;
                return;
            }
            // after render(), as dompdf: called at once for every page that exists, drawing on that page
            $saved = [$this->target, $this->objectTarget, $this->targetStack, $this->gs, $this->gsStack];
            $pages = $this->docPages + $this->extraPages;
            try {
                for ($n = 1; $n <= $pages; $n++) {
                    $this->target = $this->keyOf($n, $this->docPages);
                    $this->objectTarget = null;
                    $this->targetStack = [];
                    $this->gs = self::freshState();
                    $this->gsStack = [];
                    $callback($n, $this->pageCount, $this, $this->metrics);
                }
            } finally {
                [$this->target, $this->objectTarget, $this->targetStack, $this->gs, $this->gsStack] = $saved;
            }
            return;
        }
        $this->warnings[] = 'Canvas::page_script() received a string of PHP code; IvoPDF never executes PHP code from documents - pass a PHP callable (function ($pageNumber, $pageCount, $canvas, $fontMetrics) { ... }) or use page_text().';
    }

    // ------------------------------------------------------------------
    // current-page drawing
    // ------------------------------------------------------------------

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0): void
    {
        $this->add($this->textOp($x, $y, (string) $text, $font, $size, $color, $word_space, $char_space, $angle));
    }

    public function line($x1, $y1, $x2, $y2, $color = [0, 0, 0], $width = 1.0, $style = [], $cap = 'butt'): void
    {
        $this->add(['op' => 'line', 'x1' => (float) $x1, 'y1' => (float) $y1, 'x2' => (float) $x2, 'y2' => (float) $y2, 'color' => $color, 'w' => (float) $width, 'dash' => (array) $style, 'cap' => (string) $cap]);
    }

    /**
     * dompdf Canvas::rectangle(): outline of the rectangle whose top-left corner is ($x1, $y1).
     *
     * @param array<int, float|int> $style dash pattern
     */
    public function rectangle($x1, $y1, $w, $h, $color = [0, 0, 0], $width = 1.0, $style = [], $cap = 'butt'): void
    {
        $this->add(['op' => 'rect', 'x' => (float) $x1, 'y' => (float) $y1, 'w' => (float) $w, 'h' => (float) $h, 'color' => $color, 'lw' => (float) $width, 'dash' => (array) $style, 'fill' => false, 'cap' => (string) $cap]);
    }

    /** Alias of rectangle() kept for code written against earlier IvoPDF versions. */
    public function rect($x, $y, $w, $h, $color = [0, 0, 0], $width = 1.0, $style = []): void
    {
        $this->rectangle($x, $y, $w, $h, $color, $width, $style);
    }

    public function filled_rectangle($x1, $y1, $w, $h, $color = [0, 0, 0]): void
    {
        $this->add(['op' => 'rect', 'x' => (float) $x1, 'y' => (float) $y1, 'w' => (float) $w, 'h' => (float) $h, 'color' => $color, 'lw' => 0.0, 'dash' => [], 'fill' => true]);
    }

    public function circle($x, $y, $r, $color = [0, 0, 0], $width = null, $style = [], $fill = false): void
    {
        $this->add(['op' => 'circle', 'x' => (float) $x, 'y' => (float) $y, 'r' => (float) $r, 'color' => $color, 'lw' => (float) ($width ?? 1.0), 'dash' => (array) $style, 'fill' => (bool) $fill]);
    }

    /**
     * dompdf Canvas::arc(): elliptical arc around ($x, $y) with radii $r1 / $r2 from $astart to $aend degrees
     * (counter-clockwise, 0 = 3 o'clock, as in the CPDF adapter).
     */
    public function arc($x, $y, $r1, $r2, $astart, $aend, $color = [0, 0, 0], $width = 1.0, $style = [], $cap = 'butt'): void
    {
        $r1 = (float) $r1;
        $r2 = (float) $r2 != 0.0 ? (float) $r2 : $r1;
        $a0 = (float) $astart;
        $a1 = (float) $aend;
        if ($r1 == 0.0) {
            return;
        }
        if ($a1 < $a0) {
            $a1 += 360.0 * ceil(($a0 - $a1) / 360.0);
        }
        $steps = max(2, (int) ceil(($a1 - $a0) / 5.0));
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $a = deg2rad($a0 + ($a1 - $a0) * $i / $steps);
            $pts[] = (float) $x + $r1 * cos($a);
            $pts[] = (float) $y - $r2 * sin($a);
        }
        $this->add(['op' => 'poly', 'pts' => $pts, 'color' => $color, 'lw' => (float) $width, 'dash' => (array) $style, 'fill' => false, 'open' => true, 'cap' => (string) $cap]);
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

    /** dompdf Canvas::add_link(): a clickable area; `$url` is a URL or '#name' (a named destination / element id). */
    public function add_link($url, $x, $y, $width, $height): void
    {
        $url = (string) $url;
        if ($url === '' || $url === '#') {
            return;
        }
        $this->add(['op' => 'link', 'url' => $url, 'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $width, 'h' => (float) $height]);
    }

    /** dompdf Canvas::add_named_dest(): a destination named $anchorname at the top of the current page (target of '#name' links). */
    public function add_named_dest($anchorname): void
    {
        $name = (string) $anchorname;
        if ($name !== '') {
            $this->add(['op' => 'dest', 'name' => $name]);
        }
    }

    /**
     * @internal HTML laid out in a box at ($x, $y) of size $w x $h pt (mPDF WriteFixedPosHTML()); $overflow 'hidden' clips it.
     */
    public function html(string $html, float $x, float $y, float $w, float $h, string $overflow = 'visible'): void
    {
        $this->add(['op' => 'html', 'html' => $html, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'overflow' => strtolower($overflow)]);
    }

    /** @internal a named destination at $y pt from the top of the current page (mPDF SetLink()). */
    public function namedDestAt(string $name, float $y): void
    {
        if ($name !== '') {
            $this->add(['op' => 'dest', 'name' => $name, 'y' => $y]);
        }
    }

    /**
     * @internal Overlay HTML of the given recorded objects for one page (mPDF imperative drawing, see MpdfDraw).
     *
     * @param list<int> $ids
     */
    public function renderObjectsHtml(array $ids, int $pageNumber, int $pageCount, float $w, float $h): string
    {
        $ops = [];
        foreach ($ids as $id) {
            array_push($ops, ...($this->objects[$id] ?? []));
        }
        return $ops === [] ? '' : $this->toHtml($ops, $pageNumber, $pageCount, $w, $h);
    }

    // ------------------------------------------------------------------
    // clipping and transformations (affect the drawing calls that follow)
    // ------------------------------------------------------------------

    public function clipping_rectangle($x1, $y1, $w, $h): void
    {
        [$x1, $y1, $w, $h] = [(float) $x1, (float) $y1, (float) $w, (float) $h];
        $this->clip([$x1, $y1, $x1 + $w, $y1, $x1 + $w, $y1 + $h, $x1, $y1 + $h]);
    }

    public function clipping_roundrectangle($x1, $y1, $w, $h, $rTL, $rTR, $rBR, $rBL): void
    {
        [$x1, $y1, $w, $h] = [(float) $x1, (float) $y1, (float) $w, (float) $h];
        $max = min($w, $h) / 2;
        $pts = [];
        $corner = static function (float $cx, float $cy, float $r, float $from) use (&$pts): void {
            for ($i = 0; $i <= 6; $i++) {
                $a = deg2rad($from + 15.0 * $i);
                $pts[] = $cx + $r * cos($a);
                $pts[] = $cy + $r * sin($a);
            }
        };
        $tl = max(0.0, min($max, (float) $rTL));
        $tr = max(0.0, min($max, (float) $rTR));
        $br = max(0.0, min($max, (float) $rBR));
        $bl = max(0.0, min($max, (float) $rBL));
        // clockwise on the page (y grows downwards): top-left, top-right, bottom-right, bottom-left
        $corner($x1 + $tl, $y1 + $tl, $tl, 180.0);
        $corner($x1 + $w - $tr, $y1 + $tr, $tr, 270.0);
        $corner($x1 + $w - $br, $y1 + $h - $br, $br, 0.0);
        $corner($x1 + $bl, $y1 + $h - $bl, $bl, 90.0);
        $this->clip($pts);
    }

    /** @param array<int, float|int> $points flat list x1, y1, x2, y2, ... */
    public function clipping_polygon(array $points): void
    {
        $this->clip(array_map('floatval', array_values($points)));
    }

    /** Ends the clipping started by the last clipping_*() call (it restores the state saved by that call). */
    public function clipping_end(): void
    {
        $this->restore();
    }

    /** Rotates the coordinate system $angle degrees around ($x, $y), as dompdf's CPDF adapter. */
    public function rotate($angle, $x, $y): void
    {
        $y = $this->height - (float) $y;
        $a = deg2rad((float) $angle);
        $cos = cos($a);
        $sin = sin($a);
        $this->transform($cos, -$sin, $sin, $cos, (float) $x - $sin * $y - $cos * (float) $x, $y - $cos * $y + $sin * (float) $x);
    }

    public function skew($angle_x, $angle_y, $x, $y): void
    {
        $y = $this->height - (float) $y;
        $tx = tan(deg2rad((float) $angle_x));
        $ty = tan(deg2rad((float) $angle_y));
        $this->transform(1.0, -$ty, -$tx, 1.0, $tx * $y, $ty * (float) $x);
    }

    public function scale($s_x, $s_y, $x, $y): void
    {
        $y = $this->height - (float) $y;
        $this->transform((float) $s_x, 0.0, 0.0, (float) $s_y, (float) $x * (1 - (float) $s_x), $y * (1 - (float) $s_y));
    }

    /** Moves the origin $t_x to the right and $t_y down. */
    public function translate($t_x, $t_y): void
    {
        $this->transform(1.0, 0.0, 0.0, 1.0, (float) $t_x, -(float) $t_y);
    }

    /** Concatenates a PDF matrix (`a b c d e f cm`, PDF user space: origin bottom-left, y up), as dompdf's CPDF adapter. */
    public function transform($a, $b, $c, $d, $e, $f): void
    {
        $this->gs['ctm'] = self::multiply([(float) $a, (float) $b, (float) $c, (float) $d, (float) $e, (float) $f], $this->gs['ctm']);
    }

    // ------------------------------------------------------------------
    // objects and state
    // ------------------------------------------------------------------

    /** Starts recording drawing calls into a reusable object; returns its id. */
    public function open_object(): int
    {
        $id = $this->nextObject++;
        $this->objects[$id] = [];
        if ($this->rendering) {
            $this->scriptObjects[] = $id;
        }
        $this->targetStack[] = [$this->objectTarget, $this->target];
        $this->objectTarget = $id;
        return $id;
    }

    /**
     * Records the drawing calls that follow into an existing object again, or (with a handle returned by new_page())
     * draws on that page again, until close_object().
     */
    public function reopen_object($object): void
    {
        $id = (int) $object;
        if (isset($this->pageHandles[$id])) {
            $this->targetStack[] = [$this->objectTarget, $this->target];
            $this->objectTarget = null;
            $this->target = $this->pageHandles[$id];
        } elseif (isset($this->objects[$id])) {
            $this->targetStack[] = [$this->objectTarget, $this->target];
            $this->objectTarget = $id;
        }
    }

    /** Ends open_object() / reopen_object(): drawing returns to where it went before. */
    public function close_object(): void
    {
        if ($this->targetStack !== []) {
            [$this->objectTarget, $this->target] = array_pop($this->targetStack);
        } else {
            $this->objectTarget = null;
        }
        if (!$this->rendering) {
            $this->version++;
        }
    }

    /** @param int|mixed $object id returned by open_object() */
    public function add_object($object, $where = 'all'): void
    {
        $id = (int) $object;
        if (!isset($this->objects[$id])) {
            return;
        }
        $where = strtolower((string) $where);
        if (!in_array($where, ['all', 'add', 'next', 'even', 'odd', 'nexteven', 'nextodd'], true)) {
            $where = 'add';
        }
        if ($this->rendering) {
            $placement = ['id' => $id, 'where' => $where, 'page' => $this->renderingPage];
            $this->scriptPlacements[] = $placement;
            if (self::placed($placement, $this->renderingPage)) {
                array_push($this->buffer, ...$this->objects[$id]);
            }
            return;
        }
        $this->version++;
        $this->placements[] = ['id' => $id, 'where' => $where, 'page' => $this->target];
    }

    /** Stops repeating an object (added with 'all', 'next', ...) on the pages after the current one. */
    public function stop_object($object): void
    {
        $id = (int) $object;
        foreach (['placements', 'scriptPlacements'] as $list) {
            foreach ($this->{$list} as $k => $p) {
                if ($p['id'] === $id && !isset($p['until'])) {
                    $this->{$list}[$k]['until'] = $this->rendering ? $this->renderingPage : $this->target;
                }
            }
        }
        if (!$this->rendering) {
            $this->version++;
        }
    }

    /** @param string $mode PDF blend mode name (Normal, Multiply, Screen, ...), applied to text and images */
    public function set_opacity($opacity, $mode = 'Normal'): void
    {
        $this->gs['opacity'] = max(0.0, min(1.0, (float) $opacity));
        $this->gs['mode'] = (string) $mode;
    }

    public function save(): void
    {
        $this->gsStack[] = $this->gs;
    }

    public function restore(): void
    {
        if ($this->gsStack !== []) {
            $this->gs = array_pop($this->gsStack);
        }
    }

    /**
     * @param array{id: int, where: string, page: int|string, until?: int|string} $p pages as numbers or page keys
     * @param int $docPages pages of the document being rendered (to place the new_page() pages)
     */
    private static function placed(array $p, int $page, int $docPages = 0): bool
    {
        if (isset($p['until']) && $page > self::physical($p['until'], $docPages)) {
            return false;
        }
        $from = self::physical($p['page'], $docPages);
        return match ($p['where']) {
            'add' => $page === $from,
            'all' => $page >= $from,
            'next' => $page > $from,
            'even' => $page >= $from && $page % 2 === 0,
            'odd' => $page >= $from && $page % 2 === 1,
            'nexteven' => $page > $from && $page % 2 === 0,
            'nextodd' => $page > $from && $page % 2 === 1,
            default => false,
        };
    }

    /** Page key ('p<n>' / 'x<k>', see $target) of page $n of a document whose own pages are $docPages. */
    private function keyOf(int $n, int $docPages): string
    {
        return $docPages > 0 && $n > $docPages ? 'x' . ($n - $docPages) : 'p' . $n;
    }

    /** Page number of a page key (or a page number) in a document whose own pages are $docPages. */
    private static function physical(int|string $key, int $docPages): int
    {
        if (is_int($key)) {
            return $key;
        }
        return $key[0] === 'x' ? $docPages + (int) substr($key, 1) : (int) substr($key, 1);
    }

    // ------------------------------------------------------------------
    // fonts
    // ------------------------------------------------------------------

    public function get_text_width($text, $font, $size, $word_spacing = 0.0, $char_spacing = 0.0): float
    {
        return $this->metrics->getTextWidth((string) $text, $font, (float) $size, (float) $word_spacing, (float) $char_spacing);
    }

    public function get_font_height($font, $size): float
    {
        return $this->metrics->getFontHeight($font, (float) $size);
    }

    /** Distance from the top of a text line drawn with text() to its baseline (ascent + descent of the font). */
    public function get_font_baseline($font, $size): float
    {
        return $this->metrics->getFontBaseline($font, (float) $size);
    }

    public function font_supports_char(string $font, string $char): bool
    {
        return $this->metrics->supportsChar($font, $char);
    }

    /** dompdf CPDF::output(): the PDF of the bound Dompdf instance (renders it when needed). */
    public function output($options = []): ?string
    {
        return $this->dompdf?->output((array) $options);
    }

    /** dompdf CPDF::stream(): sends the PDF of the bound Dompdf instance to the browser. */
    public function stream($filename = 'document.pdf', $options = []): void
    {
        $this->dompdf?->stream($filename, (array) $options);
    }

    /** dompdf CPDF::get_messages(): diagnostics of the PDF backend (the warnings collected so far). */
    public function get_messages(): string
    {
        return implode("\n", $this->warnings);
    }

    /**
     * Other CPDF adapter methods (serialize_object(), reopen_serialized_object(), select(), textarea(), input(), ...) have
     * no IvoPDF equivalent: they do nothing, return null and add one warning per method (Dompdf::getWarnings()).
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (!isset($this->reported[$method])) {
            $this->reported[$method] = true;
            $this->warnings[] = "Dompdf Canvas::{$method}() is not supported by IvoPDF and was ignored.";
        }
        return null;
    }

    // ------------------------------------------------------------------
    // engine side
    // ------------------------------------------------------------------

    /**
     * Overlay HTML of one page (page-sized relative box); empty string when nothing is drawn on it. With $under the
     * HTML of the callbacks that draw below the page content (begin_page_render).
     */
    public function renderPage(int $pageNumber, int $pageCount, float $w, float $h, bool $under = false): string
    {
        $this->width = $w;
        $this->height = $h;
        $docPages = max(1, $pageCount - $this->extraPages);
        $saved = [$this->current, $this->pageCount, $this->version, $this->gs, $this->gsStack, $this->objectTarget, $this->targetStack];
        // callbacks that run while the pages are built (page_script() before render(), setCallbacks()) see the page
        $this->current = $pageNumber;
        $this->pageCount = $pageCount;
        $this->renderingPage = $pageNumber;
        $this->rendering = true;
        $this->buffer = [];
        $this->gs = self::freshState();
        $this->gsStack = [];
        $this->objectTarget = null;
        $this->targetStack = [];
        if (!$under && $pageNumber <= $this->lastRendered) {
            // a new overlay pass: forget the objects the callbacks created during the previous one
            foreach ($this->scriptObjects as $id) {
                unset($this->objects[$id]);
            }
            $this->scriptObjects = [];
            $this->scriptPlacements = [];
        }
        try {
            if ($under) {
                foreach ($this->underScripts as $cb) {
                    $this->gs = self::freshState();
                    $this->gsStack = [];
                    $cb($pageNumber, $pageCount, $this, $this->metrics);
                    $this->objectTarget = null;
                    $this->targetStack = [];
                }
            } else {
                $this->lastRendered = $pageNumber;
                foreach ($this->everyPage as $op) {
                    $this->buffer[] = $op;
                }
                foreach ($this->placements as $p) {
                    if (self::placed($p, $pageNumber, $docPages) && isset($this->objects[$p['id']])) {
                        array_push($this->buffer, ...$this->objects[$p['id']]);
                    }
                }
                foreach ($this->scriptPlacements as $p) {
                    if ($p['page'] < $pageNumber && self::placed($p, $pageNumber) && isset($this->objects[$p['id']])) {
                        array_push($this->buffer, ...$this->objects[$p['id']]);
                    }
                }
                foreach ([...$this->scripts, ...$this->callbackScripts] as $cb) {
                    $this->gs = self::freshState();
                    $this->gsStack = [];
                    $cb($pageNumber, $pageCount, $this, $this->metrics);
                    $this->objectTarget = null;
                    $this->targetStack = [];
                }
                foreach ($this->perPage[$this->keyOf($pageNumber, $docPages)] ?? [] as $op) {
                    $this->buffer[] = $op;
                }
            }
            $ops = $this->buffer;
        } finally {
            $this->rendering = false;
            $this->buffer = [];
            [$this->current, $this->pageCount, $this->version, $this->gs, $this->gsStack, $this->objectTarget, $this->targetStack] = $saved; // drawing done by callbacks is not an application change
        }
        if ($ops === []) {
            return '';
        }
        $html = $this->toHtml($ops, $pageNumber, $pageCount, $w, $h);
        // the renderer paints a box marked data-ivo-underlay before the page content
        return $under ? '<div data-ivo-underlay="1" style="position:absolute;left:0;top:0;margin:0;padding:0">' . $html . '</div>' : $html;
    }

    /** @return array{opacity: float, mode: string, ctm: list<float>, clips: list<list<float>>} */
    private static function freshState(): array
    {
        return ['opacity' => 1.0, 'mode' => 'Normal', 'ctm' => self::IDENTITY, 'clips' => []];
    }

    /** @param array<string, mixed> $op */
    private function add(array $op): void
    {
        if ($this->gs['opacity'] < 1.0) {
            $op['opacity'] = $this->gs['opacity'];
        }
        if ($this->gs['mode'] !== 'Normal') {
            $op['mode'] = $this->gs['mode'];
        }
        if ($this->gs['ctm'] !== self::IDENTITY) {
            $op['m'] = $this->deviceMatrix($this->gs['ctm']);
        }
        if ($this->gs['clips'] !== []) {
            $op['clips'] = $this->gs['clips'];
        }
        if ($this->objectTarget !== null) {
            $this->objects[$this->objectTarget][] = $op;
            return;
        }
        if ($this->rendering) {
            $this->buffer[] = $op;
            return;
        }
        $this->version++;
        $this->perPage[$this->target][] = $op;
    }

    /** Saves the state and intersects the clip with a polygon given in the current (transformed) coordinates. */
    private function clip(array $points): void
    {
        $this->save();
        if (count($points) < 6) {
            return;
        }
        $m = $this->deviceMatrix($this->gs['ctm']);
        $poly = [];
        for ($i = 0; $i + 1 < count($points); $i += 2) {
            $poly[] = $m[0] * $points[$i] + $m[2] * $points[$i + 1] + $m[4];
            $poly[] = $m[1] * $points[$i] + $m[3] * $points[$i + 1] + $m[5];
        }
        $this->gs['clips'][] = $poly;
    }

    /**
     * The CTM (PDF user space, y up) as a matrix of dompdf's top-left page coordinates (y down): F * CTM * F with
     * F(x, y) = (x, H - y).
     *
     * @param list<float> $ctm
     * @return list<float>
     */
    private function deviceMatrix(array $ctm): array
    {
        [$a, $b, $c, $d, $e, $f] = $ctm;
        $h = $this->height;
        return [$a, -$b, -$c, $d, $c * $h + $e, $h - $d * $h - $f];
    }

    /**
     * Product of two PDF matrices: the result applies $first, then $second.
     *
     * @param list<float> $first
     * @param list<float> $second
     * @return list<float>
     */
    private static function multiply(array $first, array $second): array
    {
        [$a1, $b1, $c1, $d1, $e1, $f1] = $first;
        [$a2, $b2, $c2, $d2, $e2, $f2] = $second;
        return [
            $a1 * $a2 + $b1 * $c2,
            $a1 * $b2 + $b1 * $d2,
            $c1 * $a2 + $d1 * $c2,
            $c1 * $b2 + $d1 * $d2,
            $e1 * $a2 + $f1 * $c2 + $e2,
            $e1 * $b2 + $f1 * $d2 + $f2,
        ];
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
        $svgKey = '';
        $svgOp = [];
        $flush = function () use (&$svg, &$svgOp, &$html, $w, $h): void {
            if ($svg !== '') {
                $el = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2Fpt" height="%2$.2Fpt" viewBox="0 0 %1$.2F %2$.2F" style="position:absolute;left:0;top:0%4$s">%3$s</svg>', $w, $h, $svg, self::blend((string) ($svgOp['mode'] ?? 'Normal')));
                $html .= $this->wrapState($el, $svgOp, $w, $h);
                $svg = '';
            }
        };
        foreach ($ops as $op) {
            if (in_array($op['op'], ['line', 'rect', 'circle', 'poly'], true)) {
                $key = json_encode([$op['mode'] ?? 'Normal', $op['m'] ?? null, $op['clips'] ?? null]);
                if ($svg !== '' && $key !== $svgKey) {
                    $flush();
                }
                $svgKey = (string) $key;
                $svgOp = $op;
            }
            switch ($op['op']) {
                case 'text':
                    $flush();
                    $html .= $this->wrapState($this->textHtml($op, $pageNumber, $pageCount), $op, $w, $h);
                    break;
                case 'image':
                    $flush();
                    $html .= $this->wrapState(sprintf(
                        '<img src="%s" style="position:absolute;left:%.2Fpt;top:%.2Fpt%s%s%s">',
                        htmlspecialchars($op['src'], ENT_QUOTES, 'UTF-8'),
                        $op['x'],
                        $op['y'],
                        $op['w'] > 0 ? sprintf(';width:%.2Fpt', $op['w']) : '', // 0: natural size / keep the aspect ratio
                        $op['h'] > 0 ? sprintf(';height:%.2Fpt', $op['h']) : '',
                        (isset($op['opacity']) ? sprintf(';opacity:%.3F', $op['opacity']) : '') . self::blend((string) ($op['mode'] ?? 'Normal'))
                    ), $op, $w, $h);
                    break;
                case 'link':
                    $flush();
                    $html .= $this->wrapState(sprintf(
                        '<a href="%s" style="position:absolute;display:block;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0"></a>',
                        htmlspecialchars($op['url'], ENT_QUOTES, 'UTF-8'),
                        $op['x'],
                        $op['y'],
                        max(0.0, $op['w']),
                        max(0.0, $op['h'])
                    ), $op, $w, $h);
                    break;
                case 'dest':
                    $flush();
                    $name = htmlspecialchars($op['name'], ENT_QUOTES, 'UTF-8');
                    $html .= sprintf('<div data-ivo-dest="%s" style="position:absolute;left:0;top:%.2Fpt;width:1pt;height:1pt;margin:0;padding:0"></div>', $name, (float) ($op['y'] ?? 0.0));
                    break;
                case 'html':
                    $flush();
                    $html .= $this->wrapState(sprintf(
                        '<div style="position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0%s">%s</div>',
                        $op['x'],
                        $op['y'],
                        max(0.0, $op['w']),
                        max(0.0, $op['h']),
                        $op['overflow'] === 'hidden' ? ';overflow:hidden' : '',
                        $op['html']
                    ), $op, $w, $h);
                    break;
                case 'line':
                    [$rgb, $alpha] = self::color($op['color']);
                    $alpha *= (float) ($op['opacity'] ?? 1.0);
                    $svg .= sprintf('<line x1="%.2F" y1="%.2F" x2="%.2F" y2="%.2F" stroke="%s" stroke-opacity="%.3F" stroke-width="%.2F"%s%s/>', $op['x1'], $op['y1'], $op['x2'], $op['y2'], $rgb, $alpha, $op['w'], self::dash($op['dash']), self::cap($op));
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
                    $svg .= '<' . (!empty($op['open']) ? 'polyline' : 'polygon') . ' points="' . implode(' ', $pts) . '"' . self::paint($op) . '/>';
                    break;
            }
        }
        $flush();
        return sprintf('<div style="position:relative;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0;overflow:hidden">%s</div>', $w, $h, $html);
    }

    /**
     * Wraps an element (positioned in page coordinates) in page-sized boxes that apply the clip polygons (outermost first)
     * and the transformation matrix that were current when it was drawn.
     *
     * @param array<string, mixed> $op
     */
    private function wrapState(string $el, array $op, float $w, float $h): string
    {
        $box = sprintf('position:absolute;left:0;top:0;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0', $w, $h);
        if (isset($op['m'])) {
            [$a, $b, $c, $d, $e, $f] = $op['m'];
            $el = sprintf('<div style="%s;transform-origin:0 0;transform:translate(%.3Fpt, %.3Fpt) matrix(%.6F, %.6F, %.6F, %.6F, 0, 0)">%s</div>', $box, $e, $f, $a, $b, $c, $d, $el);
        }
        foreach (array_reverse($op['clips'] ?? []) as $poly) {
            $pts = [];
            for ($i = 0; $i + 1 < count($poly); $i += 2) {
                $pts[] = sprintf('%.2Fpt %.2Fpt', $poly[$i], $poly[$i + 1]);
            }
            $el = sprintf('<div style="%s;clip-path:polygon(%s)">%s</div>', $box, implode(', ', $pts), $el);
        }
        return $el;
    }

    /** @param array<string, mixed> $op */
    private function textHtml(array $op, int $pageNumber, int $pageCount): string
    {
        // only page_text() substitutes the placeholders (dompdf's text() prints them as they are)
        $text = !empty($op['pageText']) ? strtr($op['text'], ['{PAGE_NUM}' => (string) $pageNumber, '{PAGE_COUNT}' => (string) $pageCount]) : $op['text'];
        [$family, $weight, $style] = FontMetrics::parse($op['font']);
        [$rgb, $alpha] = self::color($op['color']);
        $alpha *= (float) ($op['opacity'] ?? 1.0);
        // dompdf puts the baseline one font height (ascent + descent) below y. A line box as tall as the font's content
        // area has its baseline `ascent` below its top, so the box starts at y + descent.
        [$asc, $desc] = $this->metrics->ascentDescent($op['font'], $op['size']);
        $css = sprintf(
            'position:absolute;left:%.2Fpt;top:%.2Fpt;margin:0;padding:0;white-space:pre;line-height:%.3Fpt;font-family:\'%s\',sans-serif;font-size:%.2Fpt;font-weight:%s;font-style:%s;color:%s',
            $op['x'],
            $op['y'] + $desc,
            $asc + $desc,
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
            $css .= sprintf(';transform-origin:0 %.3Fpt;transform:rotate(%.3Fdeg)', $asc, -$op['angle']);
        }
        $css .= self::blend((string) ($op['mode'] ?? 'Normal'));
        $body = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (empty($op['pageText']) && str_contains($body, '{')) {
            // the engine fills page-number placeholders in any text run: a brace in a run of its own stays literal
            $body = str_replace('{', '<span>{</span>', $body);
        }
        return '<div style="' . $css . '">' . $body . '</div>';
    }

    /** CSS for a PDF blend mode name ("Multiply" -> mix-blend-mode), empty for Normal / unknown. */
    private static function blend(string $mode): string
    {
        $m = strtolower(preg_replace('~(?<!^)(?=[A-Z])~', '-', $mode) ?? '');
        $known = ['multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'];
        return in_array($m, $known, true) ? ';mix-blend-mode:' . $m : '';
    }

    /** @param array<string, mixed> $op */
    private static function paint(array $op): string
    {
        [$rgb, $alpha] = self::color($op['color']);
        $alpha *= (float) ($op['opacity'] ?? 1.0);
        if ($op['fill']) {
            return sprintf(' fill="%s" fill-opacity="%.3F" stroke="none"', $rgb, $alpha);
        }
        return sprintf(' fill="none" stroke="%s" stroke-opacity="%.3F" stroke-width="%.2F"%s%s', $rgb, $alpha, $op['lw'], self::dash($op['dash']), self::cap($op));
    }

    /** @param array<string, mixed> $op */
    private static function cap(array $op): string
    {
        $cap = strtolower((string) ($op['cap'] ?? 'butt'));
        return in_array($cap, ['round', 'square'], true) ? ' stroke-linecap="' . $cap . '"' : '';
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
