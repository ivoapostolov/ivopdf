<?php

declare(strict_types=1);

namespace IvoPdf;

/**
 * Configuration options for IvoPdf engine.
 */
class Options
{
    public string $defaultFont = 'Inter';
    public float $defaultFontSize = 12.0; // in pt
    /** @var string|array{0: float, 1: float} */
    public string|array $paperSize = 'A4';
    public string $orientation = 'portrait';
    public float $dpi = 96.0;
    public string $defaultBoxSizing = 'content-box';

    /** @var array{top: float, right: float, bottom: float, left: float} Default page margins in pt */
    public array $margins = [
        'top' => 36.0,
        'right' => 36.0,
        'bottom' => 36.0,
        'left' => 36.0,
    ];

    // Security & Resource Limits
    public bool $isRemoteEnabled = false;
    /** @var list<string> Allowed local filesystem directories (chroot allow-list) */
    public array $allowedPaths = [];
    public int $maxRemoteBytes = 10485760; // 10 MB
    public int $maxImagePixels = 25000000; // 25 Megapixels
    /** Largest HTML input accepted (bytes); bigger documents throw an exception instead of exhausting memory. 0 = unlimited */
    public int $maxHtmlBytes = 33554432; // 32 MB
    /** Elements nested deeper than this are flattened (with a warning) to protect the recursive layout code */
    public int $maxNestingDepth = 200;
    /** Unbroken words longer than this many bytes get a space inserted (protects shaping/layout memory). 0 = off */
    public int $maxWordLength = 2000;
    /** Output is truncated (with a warning) after this many pages (runaway-layout guard). 0 = unlimited */
    public int $maxPages = 5000;
    /**
     * Longest value (characters) a `<barcode>` accepts; longer values are skipped with a warning instead of building
     * an enormous symbol. 0 = unlimited. (QR: up to ~4300 alphanumeric characters, the others far fewer.)
     */
    public int $maxBarcodeLength = 5000;
    /**
     * Processing time budget of one rendering in seconds (parse, layout, pagination and painting). When it runs out a
     * catchable \IvoPdf\Exception\LimitExceededException is thrown instead of letting a pathological document block the
     * process. 0 = unlimited. Default 120.
     */
    public float $maxRenderTime = 120.0;
    /**
     * Memory budget of one rendering: bytes (int), a php.ini style string ('256M', '1G'), or 'auto' (90 % of PHP's
     * memory_limit). Exceeding it throws \IvoPdf\Exception\LimitExceededException while PHP still has room to unwind.
     * With a PHP memory_limit set, the budget never exceeds 90 % of it, so the exception always comes before PHP's own
     * fatal "Allowed memory size exhausted". Default 'auto'; 0 = off. Budgets below 1 MB are almost certainly a unit
     * mistake (64 meaning 64 MB) and are ignored with a warning.
     */
    public int|string $maxMemory = 'auto';
    /** Parse HTML with the WHATWG HTML5 algorithm (PHP >= 8.4, \Dom\HTMLDocument); false = libxml's HTML4 parser */
    public bool $html5Parser = true;
    /** Charset of the HTML input: 'auto' (BOM, then valid UTF-8, then <meta charset>, else Windows-1252) or a charset name such as 'windows-1251'. */
    public string $inputEncoding = 'auto';
    /** mPDF shrink_tables_to_fit: tables wider than the page are scaled down by at most this factor (0 = off, 1.4 = mPDF default) */
    public float $shrinkTablesToFit = 1.4;

    public bool $compress = true;
    /** Embed only the glyphs that are actually used (dramatically smaller PDFs) */
    public bool $subsetFonts = true;
    /** Generate PDF bookmarks (outline) from h1-h6 headings */
    public bool $bookmarks = true;
    /** Turn HTML form controls into interactive AcroForm fields (false = static drawings only, "print view"); mPDF alias: useActiveForms */
    public bool $formFields = true;
    /** Document language written to the PDF catalog (/Lang), e.g. 'bg' or 'en'; auto-detected from <html lang> */
    public string $lang = '';
    public ?string $basePath = null;
    public string $fontDir;

    // Document Metadata
    public string $title = '';
    public string $author = '';
    public string $subject = '';
    public string $keywords = '';
    public string $creator = 'IvoPDF Pure-PHP Engine (Flexbox & Grid)';

    /** @var list<string> Families (registered via registerFontFamily) used for glyphs missing from the requested font */
    public array $fallbackFonts = [];

    /**
     * Font family per script (ISO 15924 code) used for characters the requested font lacks, e.g.
     * ['Hani' => 'Noto Sans SC', 'Hani:ja' => 'Noto Sans JP', 'Hani:zh-TW' => 'Noto Sans TC', 'Hang' => 'Noto Sans KR',
     * 'Deva' => 'Noto Sans Devanagari', 'Thai' => 'Noto Sans Thai']. Keys may be "Script:lang" (lang from the `lang`
     * attribute), "Jpan"/"Kore"/"Hans"/"Hant"; values are families registered with registerFontFamily() or font files.
     *
     * @var array<string, string>
     */
    public array $scriptFonts = [];

    /** Optional diagonal watermark text drawn on every page */
    public string $watermarkText = '';
    public float $watermarkOpacity = 0.08;

    /** @var array<string, mixed> mPDF-only settings (fontdata, fontDir list, margin_header / margin_footer in pt, showImageErrors, ...) */
    public array $compat = [];

    /** PDF/A conformance: '' (off), '1b' (produced as 2b), '2b' or '3b'; `true` means '2b'. Incompatible with encryption. */
    public string $pdfa = '';
    /**
     * Optional CMYK ICC output profile file for the PDF/A OutputIntent (mPDF 'ICCProfile'): the document is then written
     * in CMYK. Without it the intent is sRGB and CMYK / spot colors are converted to RGB (with a warning), because a
     * device CMYK color needs a CMYK output intent in PDF/A. PDF/A-1 needs an ICC version 2 profile.
     */
    public string $pdfaIccProfile = '';

    /** PDF/X conformance: '' (off), 'X-1a' (also `true`), 'X-3' or 'X-4'. X-1a/X-3 convert everything to CMYK and flatten transparency; incompatible with pdfa, encryption and signatures. */
    public string $pdfx = '';
    /** Optional ICC output profile file embedded as the PDF/X OutputIntent /DestOutputProfile (CMYK profile you are licensed to embed). */
    public string $pdfxIccProfile = '';
    /** PDF/X OutputConditionIdentifier (registered characterization, e.g. 'FOGRA39', 'CGATS TR 001'). */
    public string $pdfxOutputCondition = 'FOGRA39';
    /** Output color space: 'rgb' (default) or 'cmyk' (all RGB colors and images converted to DeviceCMYK, like mPDF restrictColorSpace=3). */
    public string $colorSpace = 'rgb';
    /** Bleed in millimetres: pages get /BleedBox + /TrimBox and a larger /MediaBox. */
    public float $bleed = 0.0;
    /** Draw crop marks outside the bleed area (enlarges the /MediaBox). */
    public bool $cropMarks = false;
    /**
     * Named spot colors, name => [c, m, y, k] in percent (0..100); see IvoPdf::addSpotColor().
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public array $spotColors = [];

    /**
     * Encryption settings: ['permissions' => list<string>, 'user' => string, 'owner' => ?string, 'method' => 'aes256'|'aes128'|'rc4'].
     * Prefer IvoPdf::setProtection().
     * @var ?array<string, mixed>
     */
    public ?array $encryption = null;

    /**
     * Digital signature settings: ['cert' => pem|path, 'key' => pem|path, 'password' => string, 'info' => array, 'rect' => ?array].
     * Prefer IvoPdf::setSignature().
     * @var ?array<string, mixed>
     */
    public ?array $signature = null;

    /** @var list<string> Non-fatal problems collected during rendering (missing images, rejected paths, bad fonts) */
    private array $warnings = [];

    /**
     * Standard paper dimensions in points (1/72 inch).
     * @var array<string, array{0: float, 1: float}>
     */
    public const PAPER_SIZES = [
        'A0' => [2383.94, 3370.39],
        'A1' => [1683.78, 2383.94],
        'A2' => [1190.55, 1683.78],
        'A3' => [841.89, 1190.55],
        'A4' => [595.28, 841.89],
        'A5' => [419.53, 595.28],
        'A6' => [297.64, 419.53],
        'B4' => [708.66, 1000.63],
        'B5' => [498.90, 708.66],
        'LETTER' => [612.00, 792.00],
        'LEGAL' => [612.00, 1008.00],
        'TABLOID' => [792.00, 1224.00],
        'EXECUTIVE' => [521.86, 756.00],
        'FOLIO' => [612.00, 936.00],
    ];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings = [])
    {
        $this->fontDir = dirname(__DIR__) . '/resources/fonts';

        foreach (self::normalizeCompatSettings($settings) as $key => $value) {
            if ($key === 'margins' && is_array($value)) {
                $this->margins = [
                    'top' => isset($value['top']) && is_numeric($value['top']) ? (float) $value['top'] : $this->margins['top'],
                    'right' => isset($value['right']) && is_numeric($value['right']) ? (float) $value['right'] : $this->margins['right'],
                    'bottom' => isset($value['bottom']) && is_numeric($value['bottom']) ? (float) $value['bottom'] : $this->margins['bottom'],
                    'left' => isset($value['left']) && is_numeric($value['left']) ? (float) $value['left'] : $this->margins['left'],
                ];
                continue;
            }
            if ($key === 'paperSize' && (is_string($value) || is_array($value))) {
                $this->paperSize = $value;
                continue;
            }
            if ($key === 'compat') {
                $this->compat = is_array($value) ? $value : [];
                continue;
            }
            if ($key === 'maxMemory') {
                $this->maxMemory = is_int($value) || is_string($value) ? $value : (is_float($value) ? (int) $value : 0);
                continue;
            }
            if ($key === 'encryption' || $key === 'signature') {
                $this->{$key} = is_array($value) && $value !== [] ? $value : null;
                continue;
            }
            if ($key === 'pdfx') {
                $this->pdfx = Pdf\PdfX::normalize(is_bool($value) || $value === null ? $value : (string) $value);
                continue;
            }
            if ($key === 'pdfa') {
                $this->pdfa = $value === true ? '2b' : ($value === false || $value === null ? '' : (string) $value);
                continue;
            }
            if ($key === 'fallbackFonts' && is_array($value)) {
                $this->fallbackFonts = array_values(array_map('strval', $value));
                continue;
            }
            if ($key === 'scriptFonts' && is_array($value)) {
                $this->scriptFonts = array_map('strval', $value);
                continue;
            }
            if ($key === 'allowedPaths' && is_array($value)) {
                $this->allowedPaths = array_values(array_map('strval', $value));
                continue;
            }
            if (property_exists($this, $key)) {
                $cur = $this->{$key};
                if (is_float($cur)) {
                    if (is_numeric($value)) {
                        $this->{$key} = (float) $value;
                    }
                } elseif (is_int($cur)) {
                    if (is_numeric($value)) {
                        $this->{$key} = (int) $value;
                    }
                } elseif (is_bool($cur)) {
                    $this->{$key} = (bool) $value;
                } elseif (is_string($cur)) {
                    if (is_scalar($value)) {
                        $this->{$key} = (string) $value;
                    }
                } elseif ($cur === null && (is_string($value) || $value === null)) {
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * Maps dompdf / mPDF option names onto IvoPDF options so existing configuration arrays keep working.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function normalizeCompatSettings(array $settings): array
    {
        $out = [];
        $margins = [];
        $compat = [];
        $icc = '';
        foreach ($settings as $key => $value) {
            if (\IvoPdf\Compat\MpdfConfig::captures((string) $key, $value)) {
                $compat[$key] = $value; // mPDF-only keys: kept in Options::$compat
                continue;
            }
            switch ($key) {
                case 'format': // mPDF: 'A4', 'A4-L', [w, h] in mm
                    if (is_string($value) && preg_match('/^(.+)-([LP])$/i', $value, $m)) {
                        $out['paperSize'] = $m[1];
                        $out['orientation'] = strtoupper($m[2]) === 'L' ? 'landscape' : 'portrait';
                    } elseif (is_array($value) && count($value) >= 2) {
                        $vals = array_values($value);
                        $out['paperSize'] = [(float) $vals[0] * 2.83464567, (float) $vals[1] * 2.83464567];
                    } else {
                        $out['paperSize'] = $value;
                    }
                    break;
                case 'orientation':
                    $o = strtolower((string) $value);
                    $out['orientation'] = ($o === 'l' || $o === 'landscape') ? 'landscape' : 'portrait';
                    break;
                case 'margin_top':
                case 'margin_right':
                case 'margin_bottom':
                case 'margin_left':
                    // mPDF margins are in millimetres
                    $margins[substr($key, 7)] = (float) $value * 2.83464567;
                    break;
                case 'default_font':
                case 'defaultFont':
                    $out['defaultFont'] = $value;
                    break;
                case 'default_font_size':
                    $out['defaultFontSize'] = $value;
                    break;
                case 'chroot': // dompdf
                    $out['allowedPaths'] = is_array($value) ? $value : [$value];
                    break;
                case 'useActiveForms': // mPDF
                    $out['formFields'] = (bool) $value;
                    break;
                case 'isRemoteEnabled':
                case 'enable_remote':
                    $out['isRemoteEnabled'] = (bool) $value;
                    break;
                case 'PDFA': // mPDF: true means its PDFAversion, '1-B' by default (native `pdfa => true` stays 2b)
                    $out['pdfa'] = $value === true ? self::mpdfPdfaVersion($settings['PDFAversion'] ?? '1-B') : $value;
                    break;
                case 'PDFAversion': // mPDF: '1-B', '2-B', '3-B'; only applies with PDFA => true
                    if (($settings['PDFA'] ?? false) === true) {
                        $out['pdfa'] = self::mpdfPdfaVersion($value);
                    }
                    break;
                case 'PDFX': // mPDF
                    $out['pdfx'] = $value;
                    break;
                case 'ICCProfile': // mPDF: the output profile of PDF/A (or PDF/X)
                    $icc = is_string($value) ? $value : '';
                    break;
                case 'restrictColorSpace': // mPDF: 3 = CMYK, 2 = RGB
                    $out['colorSpace'] = (int) $value === 3 ? 'cmyk' : 'rgb';
                    break;
                case 'bleedMargin': // mPDF, millimetres
                    $out['bleed'] = $value;
                    break;
                case 'dpi':
                    $out['dpi'] = $value;
                    break;
                case 'shrink_tables_to_fit': // mPDF
                    $out['shrinkTablesToFit'] = is_numeric($value) ? (float) $value : 0.0;
                    break;
                case 'defaultPaperSize':
                    $out['paperSize'] = $value;
                    break;
                case 'defaultPaperOrientation':
                    $out['orientation'] = $value;
                    break;
                default:
                    $out[$key] = $value;
            }
        }
        if (!empty($margins)) {
            $out['margins'] = array_merge(is_array($out['margins'] ?? null) ? $out['margins'] : [], $margins);
        }
        if ($icc !== '') {
            $out[!empty($out['pdfx']) && empty($out['pdfa']) ? 'pdfxIccProfile' : 'pdfaIccProfile'] = $icc;
        }
        if ($compat !== []) {
            $out['compat'] = \IvoPdf\Compat\MpdfConfig::normalize($compat);
        }
        return $out;
    }

    /**
     * The effective memory budget in bytes (0 = not limited): Options::$maxMemory, capped at 90 % of PHP's memory_limit.
     */
    public function getMaxMemoryBytes(): int
    {
        $phpLimit = RenderLimits::phpMemoryLimit();
        $ceiling = $phpLimit > 0 ? (int) ($phpLimit * 0.9) : 0;
        if (is_string($this->maxMemory) && strtolower(trim($this->maxMemory)) === 'auto') {
            return $ceiling;
        }
        $bytes = RenderLimits::parseBytes($this->maxMemory);
        if ($bytes <= 0) {
            return 0;
        }
        if ($bytes < 1048576) {
            $this->addWarning("maxMemory of {$bytes} bytes ignored: write the budget with a unit, e.g. '256M'.");
            return $ceiling;
        }
        return $ceiling > 0 ? min($bytes, $ceiling) : $bytes;
    }

    public function addWarning(string $message): void
    {
        if (count($this->warnings) < 200 && !in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function clearWarnings(): void
    {
        $this->warnings = $this->stickyWarnings;
    }

    /**
     * @internal Puts back the warnings of an earlier moment (a measuring layout pass must not change what
     * getWarnings() reports).
     *
     * @param list<string> $warnings
     */
    public function restoreWarnings(array $warnings): void
    {
        $this->warnings = $warnings;
    }

    /**
     * @internal A fingerprint of every setting (warnings excluded), or null when a setting cannot be serialized
     * (a closure in `compat`). Equal fingerprints mean the same layout for the same HTML.
     */
    public function fingerprint(): ?string
    {
        $vars = get_object_vars($this);
        unset($vars['warnings'], $vars['stickyWarnings']);
        try {
            return md5(serialize($vars));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @var list<string> warnings about the configuration (e.g. a missing mPDF fontdata file) that every render reports again */
    private array $stickyWarnings = [];

    /** A warning raised before render() (configuration, API calls) that survives the clearWarnings() at the start of each render. */
    public function addStickyWarning(string $message): void
    {
        if (count($this->stickyWarnings) < 50 && !in_array($message, $this->stickyWarnings, true)) {
            $this->stickyWarnings[] = $message;
        }
        $this->addWarning($message);
    }

    /**
     * Returns [widthPt, heightPt] for the configured paper size and orientation.
     *
     * @param string|array{0: float, 1: float}|null $size
     * @return array{0: float, 1: float}
     */
    public function getPageDimensions(string|array|null $size = null, ?string $orientation = null): array
    {
        $targetSize = $size ?? $this->paperSize;
        $targetOrientation = strtolower($orientation ?? $this->orientation);

        if (is_array($targetSize) && count($targetSize) >= 2) {
            $values = array_values($targetSize);
            $w = max(10.0, (float) $values[0]);
            $h = max(10.0, (float) $values[1]);
        } else {
            $key = strtoupper(trim((string) $targetSize));
            [$w, $h] = self::PAPER_SIZES[$key] ?? self::PAPER_SIZES['A4'];
        }

        if ($targetOrientation === 'landscape' && $w < $h) {
            return [$h, $w];
        }
        if ($targetOrientation === 'portrait' && $w > $h) {
            return [$h, $w];
        }

        return [$w, $h];
    }

    public function setMargin(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        $r = $right ?? $top;
        $b = $bottom ?? $top;
        $l = $left ?? $r;
        $this->margins = [
            'top' => $top,
            'right' => $r,
            'bottom' => $b,
            'left' => $l,
        ];
        return $this;
    }

    /**
     * True for references that must never reach the filesystem functions (is_file, realpath, fopen ...): UNC and
     * protocol-relative paths (`\\host\share`, `//host/share`, `/\host`, `\\?\UNC\...`, `\\.\device`), which make Windows
     * open an SMB session to an arbitrary host, and stream-wrapper URLs (`ftp://`, `phar://`, `file://`, `php://`,
     * `compress.zlib://`, `smb:` ...). Windows drive paths (`C:\...`, `C:/...`) and ordinary absolute / relative paths are local.
     * Use it for paths from untrusted input (HTML, CSS) and before any filesystem call on a configurable path.
     */
    public static function isNetworkPath(string $path): bool
    {
        $path = ltrim($path, " \t\r\n\f\v");
        if (str_contains($path, "\0")) {
            return true;
        }
        if (preg_match('#^[\\\\/]{2}#', $path) === 1) {
            return true;
        }
        // scheme: (two or more characters - a single letter followed by ':' is a Windows drive)
        return preg_match('#^[a-z][a-z0-9+.\-]+:#i', $path) === 1;
    }

    /**
     * Protocol-relative URL (`//cdn.example.com/logo.png`, forward slashes only, as in web templates) => `https://...`, so
     * it is fetched as a remote resource (subject to isRemoteEnabled and the SSRF guard) instead of being treated as a
     * local / UNC path. Anything else is returned unchanged.
     */
    public static function normalizeProtocolRelativeUrl(string $src): string
    {
        $trimmed = ltrim($src, " \t\r\n\f\v");
        if (preg_match('#^//[^/\\\\]#', $trimmed) === 1) {
            return 'https:' . $trimmed;
        }
        return $src;
    }

    /** mPDF PDFAversion ('1-B', '3-B', …) -> IvoPDF pdfa level ('1b', '3b', …) */
    private static function mpdfPdfaVersion(mixed $version): string
    {
        $v = strtolower(str_replace(['-', ' '], '', (string) $version));
        return in_array($v, ['1b', '2b', '3b'], true) ? $v : '1b';
    }

    private static function shortPath(string $path): string
    {
        $path = preg_replace('/[\x00-\x1F\x7F]/', '?', $path) ?? '';
        return strlen($path) > 120 ? substr($path, 0, 117) . '...' : $path;
    }

    /**
     * Validates if a local file path is allowed by basePath or allowedPaths (preventing LFI / directory traversal).
     * UNC, protocol-relative and stream-wrapper paths are refused before any filesystem access (see isNetworkPath()).
     */
    public function resolveAllowedLocalFile(string $path, ?string $overrideBasePath = null): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        if (self::isNetworkPath($path)) {
            // Never stat UNC / protocol-relative / stream-wrapper paths: on Windows is_file('//host/share') opens an SMB session
            $this->addWarning('Network / non-local path refused (UNC, protocol-relative or stream wrapper): ' . self::shortPath($path));
            return null;
        }

        $roots = $this->allowedPaths;
        $effectiveBase = $overrideBasePath ?? $this->basePath;
        if ($effectiveBase !== null && $effectiveBase !== '') {
            $roots[] = $effectiveBase;
        }

        if (empty($roots)) {
            return null;
        }

        $candidates = [];
        if (is_file($path)) {
            $candidates[] = $path;
        }
        // a configured UNC base (\\server\share) is the operator's choice; a URL / stream-wrapper base is never stat()ed
        if ($effectiveBase !== null && $effectiveBase !== '' && preg_match('#^[a-z][a-z0-9+.\-]+:#i', $effectiveBase) !== 1) {
            $joined = rtrim($effectiveBase, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            if (is_file($joined)) {
                $candidates[] = $joined;
            }
        }

        foreach ($candidates as $candidate) {
            $realCandidate = @realpath($candidate);
            if ($realCandidate === false || !is_file($realCandidate)) {
                continue;
            }
            $normCandidate = strtolower(str_replace('\\', '/', $realCandidate));
            foreach ($roots as $root) {
                $realRoot = @realpath($root);
                if ($realRoot === false) {
                    continue;
                }
                $normRoot = rtrim(strtolower(str_replace('\\', '/', $realRoot)), '/') . '/';
                if (str_starts_with($normCandidate . '/', $normRoot) || $normCandidate === rtrim($normRoot, '/')) {
                    return $realCandidate;
                }
            }
        }

        return null;
    }

    /**
     * Safely fetches a remote HTTP/HTTPS URL with SSRF protection (blocking loopback/private/reserved IPs),
     * TLS certificate verification, timeout, and max byte limit.
     */
    public function fetchSafeRemoteUrl(string $url): ?string
    {
        if (!$this->isRemoteEnabled) {
            return null;
        }
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }

        // Resolve host IPs (A + AAAA) and block private/loopback/link-local/reserved ranges (SSRF protection)
        $hostBare = trim($host, '[]');
        if (filter_var($hostBare, FILTER_VALIDATE_IP) !== false) {
            $ips = [$hostBare];
        } else {
            $ips = @gethostbynamel($hostBare) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (@dns_get_record($hostBare, DNS_AAAA) ?: [] as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }
        if (empty($ips)) {
            $this->addWarning("Remote resource blocked (host not resolvable): {$url}");
            return null;
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                $this->addWarning("Remote resource blocked (private/reserved address): {$url}");
                return null;
            }
        }

        // Pin the connection to the validated IP so a second DNS lookup cannot be rebound to an internal address
        $pinnedIp = $ips[0];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $pinnedHost = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $pinnedUrl = "{$scheme}://{$pinnedHost}:{$port}{$path}";

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent' => 'IvoPDF/1.0',
                'header' => 'Host: ' . $hostBare . ((isset($parts['port'])) ? ':' . $port : ''),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $hostBare,
                'SNI_enabled' => true,
            ],
        ]);

        $data = @file_get_contents($pinnedUrl, false, $ctx, 0, $this->maxRemoteBytes);
        if ($data === false || $data === '') {
            $this->addWarning("Remote resource could not be fetched: {$url}");
            return null;
        }
        return $data;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $lower = strtolower($ip);
        // IPv4-mapped / translated IPv6, unique-local, link-local, NAT64 and documentation ranges
        if (preg_match('/^(::ffff:|64:ff9b:|fc|fd|fe8|fe9|fea|feb|2001:db8)/', $lower)) {
            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $lower, $m)) {
                return $this->isPublicIp($m[1]);
            }
            return false;
        }
        // Carrier-grade NAT 100.64.0.0/10 and 0.0.0.0/8
        if (preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.)/', $ip)) {
            return false;
        }
        return true;
    }
}
