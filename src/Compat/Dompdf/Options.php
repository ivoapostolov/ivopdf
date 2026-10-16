<?php

declare(strict_types=1);

namespace IvoPdf\Compat\Dompdf;

/**
 * Drop-in for `Dompdf\Options`: the same getters / setters / `set()` / `get()` (camelCase and the legacy snake_case
 * `enable_remote` names). Values that IvoPDF understands are mapped by Dompdf::buildEngine(); the others
 * (fontCache, tempDir, pdfBackend, debug flags, ...) are stored and ignored.
 */
final class Options
{
    /** @var array<string, mixed> */
    private array $v = [
        'rootDir' => '', 'tempDir' => '', 'fontDir' => '', 'fontCache' => '', 'chroot' => [],
        'logOutputFile' => '', 'defaultMediaType' => 'screen', 'defaultPaperSize' => 'letter',
        'defaultPaperOrientation' => 'portrait', 'defaultFont' => 'serif', 'dpi' => 96, 'fontHeightRatio' => 1.1,
        'isPhpEnabled' => false, 'isRemoteEnabled' => false, 'isJavascriptEnabled' => true, 'isHtml5ParserEnabled' => true,
        'isFontSubsettingEnabled' => true, 'isPdfAEnabled' => false, 'pdfBackend' => 'CPDF', 'pdflibLicense' => '',
        'allowedProtocols' => ['data://', 'file://', 'http://', 'https://'], 'allowedRemoteHosts' => null,
        'artifactPathValidation' => null, 'httpContext' => null, 'imageByteSizeLimit' => 0,
        'debugPng' => false, 'debugKeepTemp' => false, 'debugCss' => false, 'debugLayout' => false,
        'debugLayoutLines' => true, 'debugLayoutBlocks' => true, 'debugLayoutInline' => true, 'debugLayoutPaddingBox' => true,
    ];

    /** @var array<string, true> options the application set explicitly (only those override IvoPDF's own defaults) */
    private array $touched = [];

    /** @param array<string, mixed>|null $attributes */
    public function __construct(?array $attributes = null)
    {
        $this->v['tempDir'] = sys_get_temp_dir();
        if ($attributes !== null) {
            $this->set($attributes);
        }
    }

    /**
     * @param array<string, mixed>|string $attributes
     */
    public function set(array|string $attributes, mixed $value = null): self
    {
        if (!is_array($attributes)) {
            $attributes = [$attributes => $value];
        }
        foreach ($attributes as $key => $val) {
            $name = self::canonical((string) $key);
            if ($name !== null) {
                $this->{'set' . ucfirst($name)}($val);
            }
        }
        return $this;
    }

    public function get(string $key): mixed
    {
        $name = self::canonical($key);
        return $name === null ? null : $this->v[$name];
    }

    /** True when the application assigned this option (so it may override IvoPDF's default). */
    public function wasSet(string $name): bool
    {
        return isset($this->touched[$name]);
    }

    /** Chroot as a list of directories. */
    public function getChrootList(): array
    {
        return array_values(array_filter((array) $this->v['chroot'], static fn($d) => is_string($d) && $d !== ''));
    }

    public function setChroot(string|array $chroot, string $delimiter = ','): self
    {
        $this->touched['chroot'] = true;
        $this->v['chroot'] = is_array($chroot) ? array_values($chroot) : array_map('trim', explode($delimiter, $chroot));
        return $this;
    }

    public function getChroot(): string|array
    {
        $c = $this->v['chroot'];
        return is_array($c) && count($c) === 1 ? $c[0] : $c;
    }

    public function setDefaultPaperSize(mixed $size): self
    {
        $this->touched['defaultPaperSize'] = true;
        $this->v['defaultPaperSize'] = $size;
        return $this;
    }

    public function setDefaultPaperOrientation(string $o): self
    {
        $this->touched['defaultPaperOrientation'] = true;
        $this->v['defaultPaperOrientation'] = $o;
        return $this;
    }

    public function setIsRemoteEnabled(mixed $b): self
    {
        return $this->put('isRemoteEnabled', (bool) $b);
    }

    public function isRemoteEnabled(): bool
    {
        return (bool) $this->v['isRemoteEnabled'];
    }

    public function isPhpEnabled(): bool
    {
        return (bool) $this->v['isPhpEnabled'];
    }

    public function isJavascriptEnabled(): bool
    {
        return (bool) $this->v['isJavascriptEnabled'];
    }

    public function isHtml5ParserEnabled(): bool
    {
        return (bool) $this->v['isHtml5ParserEnabled'];
    }

    public function isFontSubsettingEnabled(): bool
    {
        return (bool) $this->v['isFontSubsettingEnabled'];
    }

    public function isPdfAEnabled(): bool
    {
        return (bool) $this->v['isPdfAEnabled'];
    }

    public function __call(string $method, array $args): mixed
    {
        if (preg_match('~^(get|set)([A-Z]\w*)$~', $method, $m) === 1) {
            $name = lcfirst($m[2]);
            if (array_key_exists($name, $this->v)) {
                if ($m[1] === 'get') {
                    return $this->v[$name];
                }
                return $this->put($name, $args[0] ?? null);
            }
        }
        throw new \BadMethodCallException('Call to undefined method Dompdf\Options::' . $method . '()');
    }

    private function put(string $name, mixed $value): self
    {
        $this->touched[$name] = true;
        $this->v[$name] = $value;
        return $this;
    }

    private static function canonical(string $key): ?string
    {
        static $legacy = [
            'enablePhp' => 'isPhpEnabled', 'enableRemote' => 'isRemoteEnabled', 'enablePdfA' => 'isPdfAEnabled',
            'enableJavascript' => 'isJavascriptEnabled', 'enableHtml5Parser' => 'isHtml5ParserEnabled',
            'enableFontSubsetting' => 'isFontSubsettingEnabled',
        ];
        $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
        $name = $legacy[$name] ?? $name;
        $probe = new \ReflectionClass(self::class);
        $defaults = $probe->getDefaultProperties()['v'] ?? [];
        return array_key_exists($name, $defaults) ? $name : null;
    }
}
