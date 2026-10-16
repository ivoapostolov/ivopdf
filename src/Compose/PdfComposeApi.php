<?php

declare(strict_types=1);

namespace IvoPdf\Compose;

use IvoPdf\Pdf\Import\ImportedPage;
use IvoPdf\Pdf\Import\PageImporter;
use IvoPdf\Pdf\Import\PageSelector;
use IvoPdf\Pdf\Import\PdfImportException;
use IvoPdf\Pdf\Import\PdfReader;
use IvoPdf\Pdf\PdfDocument;
use RuntimeException;

/**
 * Document composition API for IvoPdf: file attachments (Factur-X / ZUGFeRD / XRechnung) and PDF import
 * (page templates / letterheads, merging, reusable templates). Used by IvoPdf via `use PdfComposeApi;`.
 *
 * @property \IvoPdf\Options $options
 * @property ?string $renderedPdf
 * @property string $html
 */
trait PdfComposeApi
{
    /** @var list<ImportedPage> templates created by importPdfPages() (template id = index + 1) */
    private array $composeTemplates = [];
    private ?ImportedPage $composeBackground = null;
    /** @var list<ImportedPage> */
    private array $composeAppend = [];
    /** @var list<array{bytes: string, name: string, description: string, mime: string, relationship: string, modTime: ?int}> */
    private array $composeAttachments = [];
    /** @var list<string> */
    private array $composeXmp = [];
    /** @var array<string, PageImporter> */
    private array $composeSources = [];
    private ?PageImporter $composeCurrentSource = null;

    // ------------------------------------------------------------------
    // Attachments
    // ------------------------------------------------------------------

    /**
     * Embeds a file (path or raw bytes) in the PDF (PDF/A-3 "associated file").
     * $relationship: Alternative | Data | Source | Supplement | Unspecified.
     * A string that is an existing file is read from disk (subject to basePath / allowedPaths); anything else is
     * treated as the file content. Use attachData() to force "bytes".
     */
    public function attachFile(string $pathOrBytes, string $name, string $description = '', string $mime = 'application/octet-stream', string $relationship = 'Unspecified'): self
    {
        $modTime = null;
        $isPath = strlen($pathOrBytes) <= 4096 && !str_contains($pathOrBytes, "\0") && !str_contains($pathOrBytes, "\n") && @is_file($pathOrBytes);
        if ($isPath) {
            $path = $this->composeResolvePath($pathOrBytes);
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException("Unable to read attachment: {$pathOrBytes}");
            }
            $modTime = @filemtime($path) ?: null;
        } else {
            $bytes = $pathOrBytes;
        }
        return $this->composeAddAttachment($bytes, $name, $description, $mime, $relationship, $modTime);
    }

    /** Embeds raw bytes as an attached file (never interpreted as a path). */
    public function attachData(string $bytes, string $name, string $description = '', string $mime = 'application/octet-stream', string $relationship = 'Unspecified'): self
    {
        return $this->composeAddAttachment($bytes, $name, $description, $mime, $relationship, null);
    }

    /**
     * Attaches a Factur-X / ZUGFeRD CII invoice as "factur-x.xml" (AFRelationship Alternative) and registers the
     * Factur-X XMP extension schema (fx:DocumentFileName, fx:DocumentType, fx:Version, fx:ConformanceLevel)
     * for the PDF/A XMP packet. Combine with the 'pdfa' => '3b' option for a conformant e-invoice.
     *
     * @param string $profile MINIMUM | BASIC WL | BASIC | EN 16931 | EXTENDED | XRECHNUNG
     */
    public function attachFacturX(string $xml, string $profile = 'EN 16931', string $fileName = 'factur-x.xml'): self
    {
        $level = FacturX::normalizeProfile($profile);
        $this->composeAddAttachment($xml, $fileName, 'Factur-X Invoice', 'text/xml', 'Alternative', null);
        $this->composeXmp = array_values(array_filter(
            $this->composeXmp,
            static fn(string $s): bool => !str_contains($s, FacturX::NAMESPACE_URI)
        ));
        $this->composeXmp[] = FacturX::xmpExtension($level, $fileName);
        return $this;
    }

    /** The Factur-X XMP extension snippet for a profile (for custom XMP builders). */
    public function getFacturXXmpExtension(string $profile = 'EN 16931', string $fileName = 'factur-x.xml'): string
    {
        return FacturX::xmpExtension($profile, $fileName);
    }

    /**
     * mPDF-compatible: SetAssociatedFiles([['name' => ..., 'mime' => ..., 'description' => ..., 'AFRelationship' => ..., 'path' => ... | 'content' => ...]]).
     *
     * @param list<array<string, string>> $files
     */
    public function SetAssociatedFiles(array $files): self
    {
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? '');
            $rel = (string) ($f['AFRelationship'] ?? 'Unspecified');
            $mime = (string) ($f['mime'] ?? 'application/octet-stream');
            $desc = (string) ($f['description'] ?? '');
            if (isset($f['path'])) {
                $this->attachFile((string) $f['path'], $name !== '' ? $name : basename((string) $f['path']), $desc, $mime, $rel);
            } elseif (isset($f['content'])) {
                $this->attachData((string) $f['content'], $name, $desc, $mime, $rel);
            }
        }
        return $this;
    }

    // ------------------------------------------------------------------
    // PDF import
    // ------------------------------------------------------------------

    /**
     * Parses a PDF and converts the selected pages ('all', '2', '1-3,5' or [1, 3]) to reusable templates.
     *
     * @param array<int|string>|string $pages
     * @return list<int> template ids (usable with appendTemplate() / setPageTemplate())
     */
    public function importPdfPages(string $path, array|string $pages = 'all'): array
    {
        $src = $this->composeOpenSource($path);
        $ids = [];
        foreach (PageSelector::parse($pages, $src->getPageCount()) as $n) {
            $this->composeTemplates[] = $src->importPage($n);
            $ids[] = count($this->composeTemplates);
        }
        return $ids;
    }

    /**
     * Uses a page of an existing PDF as background (letterhead / stationery) under every page of the HTML output.
     * Pass a template id from importPdfPages() instead of a path, or null to remove the background.
     */
    public function setPageTemplate(string|int|null $pdfPath, int $page = 1): self
    {
        if ($pdfPath === null || $pdfPath === '') {
            $this->composeBackground = null;
        } elseif (is_int($pdfPath)) {
            $this->composeBackground = $this->composeTemplate($pdfPath);
        } else {
            $this->composeBackground = $this->composeOpenSource($pdfPath)->importPage($page);
        }
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * Appends pages of an existing PDF as whole pages after the HTML pages (merge PDFs).
     *
     * @param array<int|string>|string $pages
     */
    public function appendPdf(string $path, array|string $pages = 'all'): self
    {
        foreach ($this->importPdfPages($path, $pages) as $id) {
            $this->composeAppend[] = $this->composeTemplate($id);
        }
        $this->renderedPdf = null;
        return $this;
    }

    /** Appends a previously imported template as a whole page. */
    public function appendTemplate(int $templateId): self
    {
        $this->composeAppend[] = $this->composeTemplate($templateId);
        $this->renderedPdf = null;
        return $this;
    }

    /** mPDF/FPDI-compatible: opens a source PDF and returns its page count. */
    public function SetSourceFile(string $path): int
    {
        $this->composeCurrentSource = $this->composeOpenSource($path);
        return $this->composeCurrentSource->getPageCount();
    }

    /** mPDF/FPDI-compatible: imports a page of the file opened by SetSourceFile() and returns its template id. */
    public function ImportPage(int $pageNo = 1): int
    {
        if ($this->composeCurrentSource === null) {
            throw new RuntimeException('Call SetSourceFile() before ImportPage()');
        }
        $this->composeTemplates[] = $this->composeCurrentSource->importPage($pageNo);
        return count($this->composeTemplates);
    }

    /** mPDF/FPDI-compatible: adds the template as an additional page after the HTML pages. */
    public function UseTemplate(int $templateId): self
    {
        return $this->appendTemplate($templateId);
    }

    /** mPDF-compatible alias of setPageTemplate(). */
    public function SetDocTemplate(string $path = '', bool $continue = false): self
    {
        return $this->setPageTemplate($path === '' ? null : $path, 1);
    }

    // ------------------------------------------------------------------
    // Render hooks
    // ------------------------------------------------------------------

    /** True when only imported pages should be emitted (no HTML content was provided). */
    private function composeMergeOnly(): bool
    {
        return $this->composeAppend !== [] && trim(strip_tags($this->html)) === '' && !str_contains(strtolower($this->html), '<img');
    }

    /** Applies backgrounds, appended pages, attachments and XMP extensions to the document being compiled. */
    private function composeApply(PdfDocument $doc, int $htmlPages): void
    {
        $reg = $doc->imports();
        if ($this->composeBackground !== null) {
            $name = $reg->register($this->composeBackground);
            for ($p = 0; $p < $htmlPages; $p++) {
                $reg->setBackground($p, $name);
            }
        }
        foreach ($this->composeAppend as $tpl) {
            $name = $reg->register($tpl);
            $idx = $doc->addPage($tpl->width, $tpl->height);
            $doc->appendPageContent($idx, $reg->fullPageContent($name));
            $reg->markAppended($idx, $name); // its annotations / form fields are carried over
        }
        $this->pageCount = $htmlPages + count($this->composeAppend);

        foreach ($this->composeAttachments as $a) {
            $doc->attachments()->add($a['bytes'], $a['name'], $a['description'], $a['mime'], $a['relationship'], $a['modTime']);
        }
        foreach ($this->composeXmp as $snippet) {
            $doc->addXmpExtension($snippet);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function composeAddAttachment(string $bytes, string $name, string $description, string $mime, string $relationship, ?int $modTime): self
    {
        if ($mime === 'application/octet-stream') {
            $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
                'xml' => 'text/xml',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'html', 'htm' => 'text/html',
                default => $mime,
            };
        }
        // Validate eagerly (throws InvalidArgumentException on a bad name / relationship)
        (new \IvoPdf\Pdf\Attachments())->add('', $name, $description, $mime, $relationship);
        $this->composeAttachments[] = [
            'bytes' => $bytes,
            'name' => $name,
            'description' => $description,
            'mime' => $mime,
            'relationship' => $relationship,
            'modTime' => $modTime,
        ];
        $this->renderedPdf = null;
        return $this;
    }

    private function composeTemplate(int $id): ImportedPage
    {
        if ($id < 1 || !isset($this->composeTemplates[$id - 1])) {
            throw new \InvalidArgumentException("Unknown PDF template id {$id}");
        }
        return $this->composeTemplates[$id - 1];
    }

    /** Applies the same containment rule as loadHtmlFile(): enforced as soon as basePath / allowedPaths is set. */
    private function composeResolvePath(string $path): string
    {
        if (!empty($this->options->allowedPaths) || $this->options->basePath !== null) {
            $safe = $this->options->resolveAllowedLocalFile($path);
            if ($safe === null) {
                throw new RuntimeException("Access denied or file not found: {$path}");
            }
            return $safe;
        }
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        return $path;
    }

    private function composeOpenSource(string $path): PageImporter
    {
        $real = $this->composeResolvePath($path);
        $size = @filesize($real);
        if ($size === false || $size > PdfReader::MAX_FILE_BYTES) {
            throw new PdfImportException('PDF file is unreadable or exceeds the import size limit: ' . $path);
        }
        $key = $real . '|' . $size . '|' . (string) @filemtime($real);
        if (!isset($this->composeSources[$key])) {
            $bytes = @file_get_contents($real);
            if ($bytes === false) {
                throw new RuntimeException("Unable to read PDF: {$path}");
            }
            $source = new PageImporter(new PdfReader($bytes));
            if ($source->getPageCount() === 0) {
                throw new PdfImportException('The PDF contains no pages: ' . $path);
            }
            $this->composeSources[$key] = $source;
        }
        return $this->composeSources[$key];
    }
}
