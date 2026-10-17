<?php

declare(strict_types=1);

namespace IvoPdf\Compat\Dompdf;

use IvoPdf\IvoPdf;

/**
 * Drop-in for `Dompdf\Dompdf` (loadHtml / loadHtmlFile / setPaper / render / output / stream / getCanvas / ...), backed by
 * the IvoPDF engine. Autoloaded as `Dompdf\Dompdf` when the real dompdf is not installed (see mpdf_aliases.php).
 *
 * Differences worth knowing: `<script type="text/php">` and string arguments of Canvas::page_script() are never
 * executed (use a PHP callable, Canvas::page_text() or setFooterHtml()); dompdf's default paper size is Letter, so
 * call setPaper('A4') for A4; fontDir / fontCache / tempDir / pdfBackend options are accepted and ignored.
 */
final class Dompdf
{
    private Options $options;
    private FontMetrics $fontMetrics;
    private Canvas $canvas;
    private string $html = '';
    private ?string $basePath = null;
    private string|array|null $paper = null;
    private string $orientation = 'portrait';
    /** @var array<string, string> */
    private array $info = [];
    private ?IvoPdf $engine = null;
    private ?int $renderedVersion = null;
    private ?bool $renderedCompress = null;
    private bool $dirtyInput = true;
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, list<callable>> setCallbacks(): event => callables */
    private array $callbacks = [];
    /** @var resource|array<string, mixed>|null */
    private $httpContext = null;
    private ?\DOMDocument $dom = null;
    private bool $quirksmode = false;
    private string $protocol = '';
    private string $baseHost = '';
    /** @var array<string, true> callback events already reported as unsupported */
    private array $reportedEvents = [];
    /** @var list<string> warnings raised before the next render */
    private array $pendingWarnings = [];

    /** @param Options|array<string, mixed>|null $options */
    public function __construct($options = null)
    {
        $this->options = $options instanceof Options ? $options : new Options(is_array($options) ? $options : null);
        $this->fontMetrics = new FontMetrics($this->options);
        $this->canvas = new Canvas($this->fontMetrics);
        $this->canvas->bindDompdf($this);
        $this->syncCanvasSize();
    }

    public function setOptions(Options $options): self
    {
        $this->options = $options;
        $this->fontMetrics->setOptions($options);
        $this->dirtyInput = true;
        return $this;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getFontMetrics(): FontMetrics
    {
        return $this->fontMetrics;
    }

    public function setFontMetrics(FontMetrics $metrics): self
    {
        $this->fontMetrics = $metrics;
        return $this;
    }

    public function getCanvas(): Canvas
    {
        return $this->canvas;
    }

    public function get_canvas(): Canvas
    {
        return $this->canvas;
    }

    public function loadHtml(string $str, ?string $encoding = null): void
    {
        if ($encoding !== null && $encoding !== '' && !in_array(strtoupper($encoding), ['UTF-8', 'UTF8'], true)) {
            $str = mb_convert_encoding($str, 'UTF-8', $encoding);
        }
        $this->html = $str;
        $this->dom = null;
        $this->dirtyInput = true;
    }

    public function load_html(string $str, ?string $encoding = null): void
    {
        $this->loadHtml($str, $encoding);
    }

    public function loadHtmlFile(string $file, ?string $encoding = null): void
    {
        $file = \IvoPdf\Options::normalizeProtocolRelativeUrl($file); // //host/page.html is a remote URL
        $isUrl = preg_match('~^https?://~i', $file) === 1;
        if ($isUrl) {
            // same SSRF guard (private / reserved addresses, size limit, DNS pinning) as every other remote resource
            $fetched = $this->options->isRemoteEnabled() ? (new \IvoPdf\Options(['isRemoteEnabled' => true]))->fetchSafeRemoteUrl($file) : null;
            $content = $fetched ?? false;
        } elseif (\IvoPdf\Options::isNetworkPath($file)) {
            throw new \Exception('File \'' . $file . '\' refused: UNC / network and stream-wrapper paths are not read.');
        } else {
            $chroot = $this->options->getChrootList();
            $real = realpath($file);
            if ($real !== false && $chroot !== []) {
                $ok = false;
                foreach ($chroot as $dir) {
                    $d = realpath($dir);
                    if ($d !== false && str_starts_with($real, rtrim($d, '/\\') . DIRECTORY_SEPARATOR)) {
                        $ok = true;
                    }
                }
                if (!$ok) {
                    throw new \Exception('File \'' . $file . '\' not found within the allowed chroot tree: ' . implode(', ', $chroot));
                }
            }
            $content = $real !== false ? file_get_contents($real) : false;
            if ($content !== false && $this->basePath === null) {
                $this->basePath = dirname($real);
            }
        }
        if ($content === false) {
            throw new \Exception('File \'' . $file . '\' not found.');
        }
        $this->loadHtml($content, $encoding);
    }

    public function load_html_file(string $file): void
    {
        $this->loadHtmlFile($file);
    }

    /**
     * @param string|array<int, float|int> $size 'a4' / 'letter' / ... or [x0, y0, x1, y1] (dompdf) / [width, height], in pt
     */
    public function setPaper($size, string $orientation = 'portrait'): self
    {
        if (is_array($size)) {
            $v = array_values($size);
            $size = count($v) >= 4 ? [(float) $v[2] - (float) $v[0], (float) $v[3] - (float) $v[1]] : [(float) ($v[0] ?? 595.28), (float) ($v[1] ?? 841.89)];
        }
        $this->paper = $size;
        $this->orientation = $orientation;
        $this->dirtyInput = true;
        $this->syncCanvasSize();
        return $this;
    }

    public function set_paper($size, string $orientation = 'portrait'): self
    {
        return $this->setPaper($size, $orientation);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function getPaperSize(): array
    {
        [$w, $h] = $this->pageSize();
        return [0.0, 0.0, $w, $h];
    }

    public function getPaperOrientation(): string
    {
        return $this->paper === null ? (string) $this->options->getDefaultPaperOrientation() : $this->orientation;
    }

    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;
        $this->dirtyInput = true;
        return $this;
    }

    public function set_base_path(string $basePath): self
    {
        return $this->setBasePath($basePath);
    }

    public function getBasePath(): ?string
    {
        return $this->basePath;
    }

    /** Accepted for compatibility (protocol / host only matter for relative remote URLs). */
    public function setProtocol(string $protocol): self
    {
        $this->protocol = $protocol;
        return $this;
    }

    public function setBaseHost(string $host): self
    {
        $this->baseHost = $host;
        return $this;
    }

    /**
     * Stored (getHttpContext()); remote resources are fetched by IvoPDF's own SSRF-guarded client (TLS verification,
     * private / reserved addresses blocked, size limit), so a custom stream context is not applied.
     *
     * @param resource|array<string, mixed>|null $httpContext
     */
    public function setHttpContext($httpContext): self
    {
        $this->httpContext = $httpContext;
        return $this;
    }

    /** @return resource|array<string, mixed>|null */
    public function getHttpContext()
    {
        return $this->httpContext;
    }

    /** @param resource|array<string, mixed>|null $httpContext */
    public function set_http_context($httpContext): self
    {
        return $this->setHttpContext($httpContext);
    }

    /** @return resource|array<string, mixed>|null */
    public function get_http_context()
    {
        return $this->httpContext;
    }

    /**
     * dompdf callbacks: a list of ['event' => name, 'f' => callable]. Supported events:
     *  - `end_document`: f($pageNumber, $pageCount, $canvas, $fontMetrics) for every page after rendering (= page_script())
     *  - `end_page_render`: f($frame, $canvas, $fontMetrics) for every page, drawn above the page content
     *  - `begin_page_render`: f($frame, $canvas, $fontMetrics) for every page, drawn below the page content
     * For the page events `$frame` is null (IvoPDF has no dompdf frame tree), `$canvas->get_page_number()` is the page.
     * `begin_frame`, `end_frame` and `begin_page_reflow` (per-frame layout hooks) are kept in getCallbacks() but never
     * called (one warning per event).
     *
     * @param list<array{event?: string, f?: callable}> $callbacks
     */
    public function setCallbacks(array $callbacks): self
    {
        $this->callbacks = [];
        foreach ($callbacks as $c) {
            if (is_array($c) && isset($c['event'], $c['f']) && is_string($c['event']) && is_callable($c['f'])) {
                $this->callbacks[$c['event']][] = $c['f'];
            }
        }
        $this->dirtyInput = true;
        return $this;
    }

    /** @return array<string, list<callable>> event => callables */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /** @param list<array{event?: string, f?: callable}> $callbacks */
    public function set_callbacks($callbacks): self
    {
        return $this->setCallbacks((array) $callbacks);
    }

    /** @return array<string, list<callable>> */
    public function get_callbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * The loaded document as a DOMDocument (parsed like the engine parses it: HTML5 on PHP 8.4+, libxml's HTML parser
     * below). Changes made to it are not rendered; use setDom() / loadDOM() to render a modified tree.
     */
    public function getDom(): \DOMDocument
    {
        if ($this->dom === null) {
            $this->dom = \IvoPdf\Dom\Html5Parser::parse($this->html) ?? \IvoPdf\Dom\Html5Parser::parseLegacy($this->html);
            foreach ((new \DOMXPath($this->dom))->query('//*[@id]') ?: [] as $el) {
                if ($el instanceof \DOMElement) {
                    $el->setIdAttribute('id', true); // getElementById() works as on dompdf's DOM
                }
            }
        }
        return $this->dom;
    }

    public function get_dom(): \DOMDocument
    {
        return $this->getDom();
    }

    /** Renders the given DOM tree (it is serialized to HTML and laid out by the engine). */
    public function setDom(\DOMDocument $dom): self
    {
        $this->loadDOM($dom);
        return $this;
    }

    /** dompdf loadDOM(): renders a DOMDocument built by the application. */
    public function loadDOM($doc, $quirksmode = false): void
    {
        if (!$doc instanceof \DOMDocument) {
            throw new \InvalidArgumentException('loadDOM() expects a DOMDocument');
        }
        $this->html = (string) $doc->saveHTML();
        $this->dom = $doc;
        $this->quirksmode = (bool) $quirksmode;
        $this->dirtyInput = true;
    }

    /**
     * dompdf returns its frame tree; IvoPDF has none and returns the engine's layout tree instead (the root
     * IvoPdf\Layout\LayoutBox of the last render(), null before).
     */
    public function getTree(): ?\IvoPdf\Layout\LayoutBox
    {
        return $this->engine?->getLayoutTree();
    }

    public function get_tree(): ?\IvoPdf\Layout\LayoutBox
    {
        return $this->getTree();
    }

    /** Always false unless loadDOM() said otherwise: documents are parsed in standards mode (as dompdf's HTML5 parser does). */
    public function getQuirksmode(): bool
    {
        return $this->quirksmode;
    }

    public function get_quirksmode(): bool
    {
        return $this->quirksmode;
    }

    /** dompdf set_options(array): applies option values to a copy of the current options. */
    public function set_options(array $options): self
    {
        $new = clone $this->options;
        $new->set($options);
        return $this->setOptions($new);
    }

    /**
     * Initial view of the viewer: 'Fit' / 'FitB' (whole page), 'FitH' / 'FitBH' (page width), 'XYZ' with
     * [left, top, zoom] (zoom 1 = 100 %).
     *
     * @param array<int, mixed> $options
     */
    public function setDefaultView($defaultView, $options = []): self
    {
        $this->canvas->set_default_view($defaultView, (array) $options);
        return $this;
    }

    /** @param array<int, mixed> $options */
    public function set_default_view($defaultView, $options = []): self
    {
        return $this->setDefaultView($defaultView, $options);
    }

    /** dompdf caches parsed documents; IvoPDF renders from scratch (accepted and ignored). */
    public function enableCaching($cacheId): void
    {
    }

    public function enable_caching($cacheId): void
    {
    }

    public function set_protocol(string $protocol): self
    {
        return $this->setProtocol($protocol);
    }

    public function get_protocol(): string
    {
        return $this->protocol;
    }

    public function set_host(string $host): self
    {
        return $this->setBaseHost($host);
    }

    public function get_host(): string
    {
        return $this->baseHost;
    }

    public function get_base_path(): ?string
    {
        return $this->basePath;
    }

    public function output_html(): string
    {
        return $this->outputHtml();
    }

    /** dompdf: parses a "dompdf.view" value ('Fit', 'FitH 0', 'XYZ 0 0 1.5') and makes it the initial view; false when invalid. */
    public function parseDefaultView($value): bool
    {
        $parts = preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $view = array_shift($parts);
        if ($view === null || !in_array(strtolower($view), ['xyz', 'fit', 'fith', 'fitv', 'fitr', 'fitb', 'fitbh', 'fitbv'], true)) {
            return false;
        }
        $this->setDefaultView($view, $parts);
        return true;
    }

    public function parse_default_view($value): bool
    {
        return $this->parseDefaultView($value);
    }

    /** Replaces the canvas (its page size follows the paper). */
    public function setCanvas(Canvas $canvas): self
    {
        $this->canvas = $canvas;
        $canvas->bindDompdf($this);
        $this->syncCanvasSize();
        return $this;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getBaseHost(): string
    {
        return $this->baseHost;
    }

    public function set_option(string $key, mixed $value): self
    {
        $this->options->set($key, $value);
        $this->dirtyInput = true;
        return $this;
    }

    public function get_option(string $key): mixed
    {
        return $this->options->get($key);
    }

    public function addInfo(string $label, string $value): void
    {
        $this->info[strtolower($label)] = $value;
        $this->dirtyInput = true;
    }

    public function add_info(string $label, string $value): void
    {
        $this->addInfo($label, $value);
    }

    /** The loaded HTML (dompdf returns the parsed DOM serialised; here: the source). */
    public function outputHtml(): string
    {
        return $this->html;
    }

    /** Non-fatal problems of the last render (missing images, ignored PHP scripts, ...). */
    public function getWarnings(): array
    {
        // canvas calls made after render() (unsupported CPDF methods) report here without forcing a re-render
        foreach ($this->canvas->takeWarnings() as $warning) {
            $this->warnings[] = $warning;
        }
        return $this->warnings;
    }

    /** The underlying IvoPDF engine after render() (null before). */
    public function getEngine(): ?IvoPdf
    {
        return $this->engine;
    }

    /**
     * Methods of dompdf's API that IvoPDF does not implement (setCss(), getCss(), setTree(), ...) do nothing, return null
     * and add one warning per method to getWarnings() instead of failing with a fatal "undefined method" Error.
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (!isset($this->reportedEvents['method:' . $method])) {
            $this->reportedEvents['method:' . $method] = true;
            $this->warnings[] = "Dompdf::{$method}() is not supported by IvoPDF and was ignored.";
            $this->pendingWarnings[] = "Dompdf::{$method}() is not supported by IvoPDF and was ignored.";
        }
        return null;
    }

    public function render(): void
    {
        $this->renderEngine(null, true);
    }

    /**
     * @param array{compress?: int|bool} $options
     */
    public function output($options = []): ?string
    {
        $compress = array_key_exists('compress', (array) $options) ? (bool) $options['compress'] : true;
        $this->ensureRendered($compress);
        return $this->engine?->output();
    }

    /**
     * @param array{compress?: int|bool, Attachment?: int|bool} $options dompdf's default is Attachment = 1 (download)
     */
    public function stream($filename = 'document.pdf', $options = []): void
    {
        $options = (array) $options;
        $compress = array_key_exists('compress', $options) ? (bool) $options['compress'] : true;
        $this->ensureRendered($compress);
        $this->engine?->stream((string) $filename, ['Attachment' => (bool) ($options['Attachment'] ?? true)]);
    }

    // ------------------------------------------------------------------

    private function ensureRendered(bool $compress): void
    {
        if ($this->engine === null || $this->dirtyInput || $this->renderedVersion !== $this->canvas->version() || $this->renderedCompress !== $compress) {
            $this->renderEngine($compress, $this->engine === null);
        }
    }

    /**
     * @param bool $explicit render() called by the application (or the first rendering): the canvas page counters are
     *                       reset to the page count, as dompdf's renderer does; re-renderings done by output() after
     *                       more canvas drawing keep them
     */
    private function renderEngine(?bool $compress, bool $explicit = false): void
    {
        $compress ??= $this->renderedCompress ?? true;
        $engine = new IvoPdf($this->settings($compress));
        // dompdf's generic families are the PDF core fonts: serif = Times, monospace = Courier (metric-compatible
        // Liberation faces), so templates written for dompdf wrap the same way
        foreach (['serif' => 'liberation serif', 'times-roman' => 'liberation serif', 'monospace' => 'liberation mono', 'fixed' => 'liberation mono'] as $generic => $face) {
            $engine->getFontManager()->setAlias($generic, $face);
        }
        FontMetrics::applyRegistered($engine->getFontManager());
        [$w, $h] = $this->pageSize();
        $engine->setPaper($this->paper ?? $this->options->getDefaultPaperSize(), $this->paper === null ? $this->options->getDefaultPaperOrientation() : $this->orientation);
        $canvas = $this->canvas;
        // Canvas::new_page(): empty pages after the document
        $engine->loadHtml(self::withExtraPages($this->html, $canvas->extraPages()), $this->basePath);
        foreach ([...$this->info, ...$canvas->infoEntries()] as $label => $value) {
            match ($label) {
                'title' => $engine->SetTitle($value),
                'author' => $engine->SetAuthor($value),
                'subject' => $engine->SetSubject($value),
                'keywords' => $engine->SetKeywords($value),
                'creator' => $engine->SetCreator($value),
                default => null,
            };
        }
        if ($canvas->javascriptCode() !== '') {
            $engine->SetJS($canvas->javascriptCode());
        }
        if (($view = $canvas->defaultView()) !== null) {
            $engine->SetDisplayMode(self::zoomOf($view[0], $view[1]), 'default');
        }
        $this->applyCallbacks();
        if ($canvas->hasContent()) {
            $engine->setPageOverlay(static fn(int $pageNumber, int $pageCount, float $pw, float $ph): string
                => $canvas->renderPage($pageNumber, $pageCount, $pw, $ph, true) . $canvas->renderPage($pageNumber, $pageCount, $pw, $ph));
        }
        $engine->render();
        $this->engine = $engine;
        $this->warnings = $engine->getWarnings();
        foreach ([...$this->pendingWarnings, ...$canvas->takeWarnings()] as $warning) {
            $engine->getOptions()->addWarning($warning);
        }
        $this->pendingWarnings = [];
        $this->warnings = $engine->getWarnings();
        $canvas->setPageSize($w, $h);
        $canvas->setPageCount($engine->getPageCount(), $explicit);
        $this->renderedVersion = $canvas->version();
        $this->renderedCompress = $compress;
        $this->dirtyInput = false;
    }

    /** Appends $n empty pages (Canvas::new_page()) before </body>. */
    private static function withExtraPages(string $html, int $n): string
    {
        if ($n <= 0) {
            return $html;
        }
        // every marker starts a page (consecutive forced breaks each start one, as in browsers)
        $pages = str_repeat('<div style="page-break-before:always;height:1pt;margin:0;padding:0"></div>', $n);
        $pos = strripos($html, '</body');
        return $pos === false ? $html . $pages : substr($html, 0, $pos) . $pages . substr($html, $pos);
    }

    /** Hands the callbacks set with setCallbacks() to the canvas for this render. */
    private function applyCallbacks(): void
    {
        $over = [];
        $under = [];
        foreach ($this->callbacks as $event => $fs) {
            foreach ($fs as $f) {
                if ($event === 'end_document') {
                    // dompdf runs these through page_script() after rendering: get_page_number() is the last page
                    $over[] = static fn(int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) => $canvas->withPageNumber($pageCount, static fn() => $f($pageNumber, $pageCount, $canvas, $fontMetrics));
                } elseif ($event === 'end_page_render' || $event === 'begin_page_render') {
                    if (!self::acceptsNull($f)) {
                        $this->reportEvent($event, 'the callback requires a dompdf Frame as first argument, which IvoPDF cannot provide (declare it nullable, e.g. ?Frame $frame)');
                        continue;
                    }
                    $page = static fn(int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) => $f(null, $canvas, $fontMetrics);
                    if ($event === 'end_page_render') {
                        $over[] = $page;
                    } else {
                        $under[] = $page;
                    }
                } else {
                    $this->reportEvent($event, 'IvoPDF has no dompdf frame tree, so per-frame / reflow events are not raised');
                }
            }
        }
        $this->canvas->setRenderCallbacks($over, $under);
    }

    private function reportEvent(string $event, string $why): void
    {
        if (!isset($this->reportedEvents[$event])) {
            $this->reportedEvents[$event] = true;
            $this->pendingWarnings[] = "Dompdf callback event '{$event}' is not supported: {$why}.";
        }
    }

    /** True when the callable can be called with null as first argument (untyped, mixed, nullable or no parameter). */
    private static function acceptsNull(callable $f): bool
    {
        try {
            $r = is_array($f) ? new \ReflectionMethod($f[0], (string) $f[1])
                : (is_object($f) && !$f instanceof \Closure ? new \ReflectionMethod($f, '__invoke') : new \ReflectionFunction(\Closure::fromCallable($f)));
        } catch (\ReflectionException) {
            return true;
        }
        $first = $r->getParameters()[0] ?? null;
        return $first === null || !$first->hasType() || ($first->getType()?->allowsNull() ?? true);
    }

    /** @param list<mixed> $options */
    private static function zoomOf(string $view, array $options): string
    {
        $v = strtolower($view);
        if (in_array($v, ['fith', 'fitbh', 'fitr'], true)) {
            return 'fullwidth';
        }
        if ($v === 'xyz' && isset($options[2]) && is_numeric($options[2]) && (float) $options[2] > 0) {
            return (string) round((float) $options[2] * 100);
        }
        return 'fullpage';
    }

    /** @return array<string, mixed> IvoPDF constructor settings derived from the dompdf options */
    private function settings(bool $compress): array
    {
        $o = $this->options;
        $s = ['compress' => $compress, 'isRemoteEnabled' => $o->isRemoteEnabled()];
        if ($o->wasSet('chroot') && $o->getChrootList() !== []) {
            $s['allowedPaths'] = $o->getChrootList();
        }
        if ($o->wasSet('defaultFont') && (string) $o->get('defaultFont') !== '') {
            $s['defaultFont'] = (string) $o->get('defaultFont');
        }
        if ($o->wasSet('dpi')) {
            $s['dpi'] = (float) $o->get('dpi');
        }
        if ($o->isPdfAEnabled()) {
            $s['pdfa'] = '2b';
        }
        return $s;
    }

    /** @return array{0: float, 1: float} */
    private function pageSize(): array
    {
        $opt = new \IvoPdf\Options();
        if ($this->paper === null) {
            return $opt->getPageDimensions($this->options->getDefaultPaperSize(), (string) $this->options->getDefaultPaperOrientation());
        }
        return $opt->getPageDimensions($this->paper, $this->orientation);
    }

    private function syncCanvasSize(): void
    {
        [$w, $h] = $this->pageSize();
        $this->canvas->setPageSize($w, $h);
    }
}
