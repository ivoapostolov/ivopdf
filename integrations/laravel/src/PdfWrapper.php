<?php

declare(strict_types=1);

namespace IvoPdf\Laravel;

use BadMethodCallException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailables\Attachment;
use InvalidArgumentException;
use IvoPdf\IvoPdf;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Laravel-friendly wrapper around {@see IvoPdf}, API-compatible with barryvdh/laravel-dompdf's PDF class.
 *
 * Configuration is collected first and the engine is built lazily, so setOption()/setPaper() can be called
 * in any order. Any other IvoPdf method (setProtection, setPdfA, setSignature, attachFile, setHeaderHtml, ...)
 * is available through __call() and returns the wrapper for chaining.
 *
 * @mixin IvoPdf
 */
class PdfWrapper
{
    /** @var array<string, mixed> */
    protected array $options;
    /** @var array<string, mixed> */
    protected array $fonts;
    protected bool $rewriteAssetUrls;
    protected string $html = '';
    protected ?string $htmlFile = null;
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    protected array $calls = [];
    protected ?IvoPdf $engine = null;

    /**
     * @param array<string, mixed> $config the `ivopdf` config array
     */
    public function __construct(array $config, protected Container $app)
    {
        $this->options = self::optionsFromConfig($config);
        $fonts = $config['fonts'] ?? [];
        $this->fonts = is_array($fonts) ? $fonts : [];
        $this->rewriteAssetUrls = (bool) ($config['rewrite_asset_urls'] ?? true);
    }

    /**
     * Translates the `ivopdf` config array into IvoPdf\Options keys.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function optionsFromConfig(array $config): array
    {
        $map = [
            'paper' => 'paperSize',
            'orientation' => 'orientation',
            'default_font' => 'defaultFont',
            'default_font_size' => 'defaultFontSize',
            'margins' => 'margins',
            'base_path' => 'basePath',
            'allowed_paths' => 'allowedPaths',
            'remote_enabled' => 'isRemoteEnabled',
            'compress' => 'compress',
            'subset_fonts' => 'subsetFonts',
            'bookmarks' => 'bookmarks',
            'form_fields' => 'formFields',
            'lang' => 'lang',
            'creator' => 'creator',
            'pdfa' => 'pdfa',
            'fallback_fonts' => 'fallbackFonts',
        ];
        $options = [];
        foreach ($map as $configKey => $optionKey) {
            if (array_key_exists($configKey, $config) && $config[$configKey] !== null && $config[$configKey] !== '') {
                $options[$optionKey] = $config[$configKey];
            }
        }
        $extra = $config['options'] ?? [];
        return is_array($extra) ? array_merge($options, $extra) : $options;
    }

    // ------------------------------------------------------------------
    // Input
    // ------------------------------------------------------------------

    /** Loads an HTML string. */
    public function loadHTML(string $html, ?string $encoding = null): static
    {
        $this->html = $html;
        $this->htmlFile = null;
        return $this->touch();
    }

    /** Loads an HTML file from disk. */
    public function loadFile(string $file): static
    {
        // Same containment rule as IvoPdf::loadHtmlFile(): when basePath/allowedPaths are configured the file must be inside them
        $engineOptions = new \IvoPdf\Options($this->options);
        if (!empty($engineOptions->allowedPaths) || $engineOptions->basePath !== null) {
            $safe = $engineOptions->resolveAllowedLocalFile($file);
            if ($safe === null) {
                throw new RuntimeException("HTML file outside the allowed paths (configure ivopdf.allowed_paths): {$file}");
            }
            $file = $safe;
        }
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            throw new RuntimeException("HTML file not found or unreadable: {$file}");
        }
        $this->html = $contents;
        $this->htmlFile = $file;
        return $this->touch();
    }

    /**
     * Renders a Blade view to HTML and loads it.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function loadView(string $view, array $data = [], array $mergeData = [], ?string $encoding = null): static
    {
        $html = $this->app->make('view')->make($view, $data, $mergeData)->render();
        return $this->loadHTML($html, $encoding);
    }

    // ------------------------------------------------------------------
    // Options
    // ------------------------------------------------------------------

    /**
     * Sets paper size ('a4', 'letter', [w, h] in pt) and orientation.
     *
     * @param string|array{0: float|int, 1: float|int} $paper
     */
    public function setPaper(string|array $paper, string $orientation = 'portrait'): static
    {
        $this->options['paperSize'] = $paper;
        $this->options['orientation'] = $orientation;
        return $this->touch();
    }

    public function setOrientation(string $orientation): static
    {
        $this->options['orientation'] = $orientation;
        return $this->touch();
    }

    /** Folder used to resolve relative image / CSS / font paths. */
    public function setBasePath(?string $path): static
    {
        $this->options['basePath'] = $path;
        return $this->touch();
    }

    /**
     * Sets one IvoPdf option (dompdf / mPDF style names such as `isRemoteEnabled`, `chroot`, `defaultFont` work too).
     */
    public function setOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;
        return $this->touch();
    }

    /**
     * Merges several options.
     *
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this->touch();
    }

    /** Accepted for barryvdh/laravel-dompdf compatibility; warnings are always collected, see getWarnings(). */
    public function setWarnings(bool $warnings): static
    {
        return $this;
    }

    /**
     * Sets document metadata (dompdf addInfo): Title, Author, Subject, Keywords, Creator.
     *
     * @param array<string, string>|string $info
     */
    public function addInfo(array|string $info, string $value = ''): static
    {
        $info = is_array($info) ? $info : [$info => $value];
        $keys = ['title', 'author', 'subject', 'keywords', 'creator'];
        foreach ($info as $key => $val) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $keys, true)) {
                $this->options[$lower] = (string) $val;
            }
        }
        return $this->touch();
    }

    /**
     * dompdf-style encryption: user password, optional owner password and allowed operations.
     *
     * @param list<string> $permissions
     */
    public function setEncryption(string $password, string $ownerPassword = '', array $permissions = ['print', 'copy']): static
    {
        return $this->setProtection($permissions, $password, $ownerPassword !== '' ? $ownerPassword : null);
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /** Returns the PDF bytes. */
    public function output(): string
    {
        return $this->getEngine()->output();
    }

    /** Builds the document (dompdf `render()`) and returns the wrapper. */
    public function render(): static
    {
        $this->getEngine()->render();
        return $this;
    }

    /**
     * Saves the PDF. With $disk the file is written through Laravel's Storage (e.g. `s3`), otherwise to the local path.
     */
    public function save(string $filename, ?string $disk = null): static
    {
        if ($disk !== null) {
            $this->app->make('filesystem')->disk($disk)->put($filename, $this->output());
            return $this;
        }
        $this->getEngine()->save($filename);
        return $this;
    }

    /** Response that displays the PDF in the browser (Content-Disposition: inline). */
    public function stream(string $filename = 'document.pdf'): Response
    {
        return $this->makeResponse($filename, ResponseHeaderBag::DISPOSITION_INLINE);
    }

    /** Response that makes the browser download the PDF (Content-Disposition: attachment). */
    public function download(string $filename = 'document.pdf'): Response
    {
        return $this->makeResponse($filename, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    /**
     * Mail attachment for mailables: return `[$pdf->toMailAttachment('invoice.pdf')]` from Mailable::attachments().
     */
    public function toMailAttachment(string $filename = 'document.pdf'): Attachment
    {
        $bytes = $this->output();
        return Attachment::fromData(static fn (): string => $bytes, $filename)->withMime('application/pdf');
    }

    /**
     * Builds the response with a UTF-8 safe Content-Disposition header (RFC 6266 / 5987).
     */
    protected function makeResponse(string $filename, string $disposition): Response
    {
        $bytes = $this->output();
        $filename = trim(str_replace(["\r", "\n", "\0"], '', $filename));
        $filename = $filename !== '' ? $filename : 'document.pdf';
        $fallback = preg_replace('/[^\x20-\x7E]|[%\\\\\/"]/u', '_', $filename) ?: 'document.pdf';

        try {
            $header = HeaderUtils::makeDisposition($disposition, $filename, $fallback);
        } catch (InvalidArgumentException) {
            $header = HeaderUtils::makeDisposition($disposition, 'document.pdf');
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => $header,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    // ------------------------------------------------------------------
    // Engine
    // ------------------------------------------------------------------

    /** The configured IvoPdf instance (built on first use, rebuilt after any change). */
    public function getEngine(): IvoPdf
    {
        return $this->engine ??= $this->buildEngine();
    }

    /** Migration alias of getEngine() (barryvdh/laravel-dompdf: getDomPDF()). */
    public function getDomPDF(): IvoPdf
    {
        return $this->getEngine();
    }

    /**
     * Warnings from the last render (missing images, blocked paths...). Renders first if needed.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        $engine = $this->getEngine();
        $engine->output();
        return $engine->getWarnings();
    }

    public function getPageCount(): int
    {
        $engine = $this->getEngine();
        $engine->output();
        return $engine->getPageCount();
    }

    protected function buildEngine(): IvoPdf
    {
        $options = $this->options;
        $engine = new IvoPdf($options);
        foreach ($this->fonts as $family => $variants) {
            if (is_array($variants)) {
                $engine->registerFontFamily((string) $family, $variants);
            }
        }
        foreach ($this->calls as [$method, $args]) {
            $engine->{$method}(...$args);
        }

        $basePath = isset($options['basePath']) && is_string($options['basePath']) ? $options['basePath'] : null;
        if ($this->htmlFile !== null && $basePath === null) {
            $basePath = dirname(realpath($this->htmlFile) ?: $this->htmlFile);
        }
        $engine->loadHtml($this->prepareHtml($this->html, $basePath), $basePath);
        return $engine;
    }

    /** Rewrites this application's asset() URLs to local files so they work without remote loading. */
    protected function prepareHtml(string $html, ?string $basePath): string
    {
        if (!$this->rewriteAssetUrls || $basePath === null || $html === '' || !$this->app->bound('url')) {
            return $html;
        }
        $root = rtrim(substr($this->app->make('url')->asset('x'), 0, -1), '/') . '/';
        if (!preg_match('~^https?://~i', $root) || !str_contains($html, $root)) {
            return $html;
        }
        $base = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
        return str_replace($root, $base, $html);
    }

    protected function touch(): static
    {
        $this->engine = null;
        return $this;
    }

    /**
     * Forwards any other IvoPdf method. Fluent methods are queued and replayed on the lazily built engine.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists(IvoPdf::class, $method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }
        $returnType = (new ReflectionMethod(IvoPdf::class, $method))->getReturnType();
        $fluent = $returnType instanceof ReflectionNamedType
            && in_array(strtolower($returnType->getName()), ['self', 'static', 'ivopdf\\ivopdf'], true);
        if ($fluent) {
            $this->calls[] = [$method, $arguments];
            return $this->touch();
        }
        return $this->getEngine()->{$method}(...$arguments);
    }
}
