# Changelog

All notable changes to IvoPDF are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.0.1] – 2026-10-19

Fixes from the sixth audit.

### Fixed
- Long unbreakable words were silently cut off at the page edge (a 300-character token kept 82 visible characters, an 8 000-character base64 line in `<pre>` kept 77). New option `pageOverflowWrap` (default `true`): a word, or a `white-space: pre` / `nowrap` line, still overflows its box as in browsers, but where its glyphs would cross the edge of the page's content area (the left edge in right-to-left text) it is broken at a grapheme-cluster boundary and continues on the next line; one warning per document says how many words / lines were broken. Covers table cells, inline-blocks, flex / grid items, floats, multi-column and positioned boxes (a box positioned after its layout is laid out again with the position learned, at most three more passes); text clipped by `overflow: hidden | clip | auto | scroll` or inside a `transform` is left alone. Table columns widen to their longest word only up to the page edge (the widest columns are narrowed and break their words at the cell edge). `pageOverflowWrap = false` keeps the browser behaviour everywhere and warns how many pieces of text are cut off. Right-to-left lines wider than their box now overflow to the left (start-aligned, as in browsers). Running headers and footers follow the same rule.
- `<table border="1">` (inset / outset cell borders) wrote each side as a clip plus a fill of the whole border ring with zero-length corner segments: 1 000 cells took 100 KB (1.6 MB uncompressed). Square opaque solid / inset / outset sides are now written relative to their box (a `cm` per box, so equal cells repeat byte for byte), with the ring as two plain rectangles, no inner `q`/`Q` and the colour set only when it changes: 25 KB (708 KB uncompressed), smaller than the same table with CSS collapsed borders (27 KB); the pixels are the same at 72 and 144 dpi.
- `rowspan="0"` spans the remaining rows of its row group (it was treated as 1); a negative `rowspan` is 1.
- `maxMemory`: negative numbers and strings that are no size (`'64 megs'`) silently turned the memory budget off; they are now ignored with a warning (the `'auto'` budget applies), like values under 1 MB. Setting `$pdf->maxMemory` (and the other IvoPDF-only limit options) on the `IvoPdf` object now sets the option instead of being kept, unused, among the mPDF properties.
- `<textcircle>` with `top=` / `bottom=` (instead of mPDF's `top-text=` / `bottom-text=`) drew nothing without a warning: both spellings are accepted now, and a circle that cannot be drawn (no text, a radius that is no positive length, zero `space-width` / `char-width`) says so in `getWarnings()`.

### Package
- Removed a stray 659 KB test PDF (`x.pdf`) from the repository root and git-ignored root-level PDFs; `CLA.md` and `CONTRIBUTING.md` are no longer exported to the Composer package. `git archive` size: 20 049 920 → 19 425 280 bytes as tar (9 402 710 → 9 379 331 bytes as the zip Composer downloads); the 48 bundled fonts (16 MB, 8.6 MB zipped) are all registered and none is a duplicate, so they stay.

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
- Forms (AcroForm fields incl. range, progress and meter drawn like browsers), barcodes (QR, Data Matrix, PDF417, Code 128, EAN/UPC, …).
- Text extraction: real space glyphs between words also on justified lines, logical order for RTL text, `/ActualText` for synthesized small caps and missing glyphs.

### PDF output
- Font subsetting with correct `/W` widths; bundled Inter, Liberation (Arial/Times/Courier metrics), Sofia Sans and DejaVu.
- PDF/A-1b, 2b, 3b (validated with veraPDF; CMYK colours converted to the sRGB intent, or a CMYK output intent via `pdfaIccProfile`), PDF/X-1a, X-3, X-4, CMYK and spot colours, bleed and crop marks.
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
