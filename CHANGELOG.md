# Changelog

All notable changes to IvoPDF are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] – 2026-10-17

First public release: a pure-PHP HTML/CSS to PDF engine meant as a drop-in replacement for dompdf and mPDF.

### Rendering
- HTML5 parsing (WHATWG parser on PHP ≥ 8.4; libxml with HTML5 normalisation below), input encoding detection (UTF-8/16 BOM, `<meta charset>`, Windows-1251/1252 sniffing).
- CSS cascade with modern selectors (`:is`, `:where`, `:has`, `:not`, `:nth-*`, `:dir`, `:lang`), custom properties, `calc()`/`min()`/`max()`/`clamp()`, `@media`, `@supports`, `@import`, `@font-face`, `@page` with margin boxes and named pages.
- Layout: block, inline, inline-block, floats (placed at the line where they occur, text returns to full width below), absolute/fixed/relative positioning, Flexbox, CSS Grid (areas, named lines, auto-placement incl. dense), multi-column, tables (auto and fixed layout, collapsing borders with winning-border resolution, `caption-side`, repeated header/footer groups, RTL mirroring).
- Browser default styles and metrics, checked against Microsoft Edge with a differential test of 210+ probes: body margin, `line-height: normal` from font metrics, separate table borders, list indents, `th` centering, overflow of definite heights, `overflow-wrap: normal`.
- Typography: OpenType shaping (GSUB/GPOS, kerning, ligatures, features), language-aware letterforms (`locl`, e.g. Bulgarian forms in Sofia Sans), Arabic/Hebrew bidi, Indic scripts with a registered font, hyphenation patterns, small caps, `::first-letter`/`::first-line`, synthesized bold/oblique.
- Paged media: page breaks (`always|avoid|left|right|recto|verso`), `orphans`/`widows`, running headers/footers, CSS footnotes (`float: footnote`), bookmarks, tables of contents, internal links to any element.
- Images: JPEG (CMYK, EXIF), PNG (alpha, palette, 16-bit, interlaced), GIF, WebP, BMP, TIFF, ICO, SVG (gradients, patterns, masks, clip paths, markers, `<use>`, links), WMF/EMF, PDF pages as images.
- Forms (AcroForm fields), barcodes (QR, Data Matrix, PDF417, Code 128, EAN/UPC, …).

### PDF output
- Font subsetting with correct `/W` widths; bundled Inter, Liberation (Arial/Times/Courier metrics), Sofia Sans and DejaVu.
- PDF/A-1b, 2b, 3b (validated with veraPDF), PDF/X-1a, X-3, X-4, CMYK and spot colours, bleed and crop marks.
- Encryption (AES-256, AES-128, RC4), digital signatures, attachments, Factur-X, page templates and appended PDFs.

### Compatibility
- dompdf: `Dompdf\Dompdf`, `Options`, `Canvas` (page_text, page_script, drawing, objects, callbacks), `FontMetrics`; `<script type="text/php">` is never executed.
- mPDF: `Mpdf\Mpdf` with `WriteHTML`, headers/footers, mPDF tags (`<pagebreak>`, `<htmlpageheader>`, `<columns>`, `<tocpagebreak>`, `<indexentry>`, `<barcode>`, `<dottab>`, `<textcircle>`, …), the imperative drawing API and `__call` warnings for unsupported methods.
- Laravel and Symfony integrations (`integrations/`).

### Robustness and security
- Time and memory budgets per render (`maxRenderTime`, `maxMemory = 'auto'`) that throw a catchable `LimitExceededException` instead of PHP fatal errors, including image decompression bombs and huge DOMs.
- Linear-time hot paths for pagination, floats, footnotes, selectors and very long text; input guards for size, nesting, word length, pages and barcodes.
- SSRF-protected remote loading (off by default), sandboxed local paths, UNC/protocol-relative paths never touch the filesystem, escaped PDF strings, no temporary font files.
- Tested on PHP 8.1–8.5.
