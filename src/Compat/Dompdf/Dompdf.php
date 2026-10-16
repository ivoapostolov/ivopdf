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

    /** @param Options|array<string, mixed>|null $options */
    public function __construct($options = null)
    {
        $this->options = $options instanceof Options ? $options : new Options(is_array($options) ? $options : null);
        $this->fontMetrics = new FontMetrics($this->options);
        $this->canvas = new Canvas($this->fontMetrics);
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
        $this->dirtyInput = true;
    }

    public function load_html(string $str, ?string $encoding = null): void
    {
        $this->loadHtml($str, $encoding);
    }

    public function loadHtmlFile(string $file, ?string $encoding = null): void
    {
        $isUrl = preg_match('~^https?://~i', $file) === 1;
        if ($isUrl) {
            $content = $this->options->isRemoteEnabled() ? @file_get_contents($file) : false;
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
        return $this;
    }

    public function setBaseHost(string $host): self
    {
        return $this;
    }

    public function setHttpContext($context): self
    {
        return $this;
    }

    public function setCallbacks(array $callbacks): self
    {
        return $this;
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
        return $this->warnings;
    }

    /** The underlying IvoPDF engine after render() (null before). */
    public function getEngine(): ?IvoPdf
    {
        return $this->engine;
    }

    public function render(): void
    {
        $this->renderEngine(null);
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
            $this->renderEngine($compress);
        }
    }

    private function renderEngine(?bool $compress): void
    {
        $compress ??= $this->renderedCompress ?? true;
        $engine = new IvoPdf($this->settings($compress));
        FontMetrics::applyRegistered($engine->getFontManager());
        [$w, $h] = $this->pageSize();
        $engine->setPaper($this->paper ?? $this->options->getDefaultPaperSize(), $this->paper === null ? $this->options->getDefaultPaperOrientation() : $this->orientation);
        $engine->loadHtml($this->html, $this->basePath);
        foreach ($this->info as $label => $value) {
            match ($label) {
                'title' => $engine->SetTitle($value),
                'author' => $engine->SetAuthor($value),
                'subject' => $engine->SetSubject($value),
                'keywords' => $engine->SetKeywords($value),
                'creator' => $engine->SetCreator($value),
                default => null,
            };
        }
        $canvas = $this->canvas;
        if ($canvas->hasContent()) {
            $engine->setPageOverlay(static fn(int $pageNumber, int $pageCount, float $pw, float $ph): string => $canvas->renderPage($pageNumber, $pageCount, $pw, $ph));
        }
        $engine->render();
        $this->engine = $engine;
        $this->warnings = $engine->getWarnings();
        foreach ($canvas->takeWarnings() as $warning) {
            $engine->getOptions()->addWarning($warning);
        }
        $this->warnings = $engine->getWarnings();
        $canvas->setPageSize($w, $h);
        $canvas->setPageCount($engine->getPageCount());
        $this->renderedVersion = $canvas->version();
        $this->renderedCompress = $compress;
        $this->dirtyInput = false;
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
