# Changelog

All notable changes to IvoPDF are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.0.2] – 2026-10-19

Fixes from the seventh audit. Every change was compared with Microsoft Edge's print-to-PDF output.

### Fixed
- Gradients with transparent stops: a stop with alpha 0 (`rgba(0,0,0,0)`, `#ff000000`) was dropped, so the common dark overlay `linear-gradient(rgba(0,0,0,0), rgba(0,0,0,.7))` drew nothing, and stops with alpha (`#ff000080`) were drawn opaque. Gradients now keep the alpha of every stop: the colours go into the shading, the alpha into a luminosity soft mask with a matching gray shading (linear, radial and conic), interpolated in premultiplied RGBA like browsers (`red → transparent` stays red while it fades). PDF/A-2b / 3b keep the soft mask (veraPDF passes); PDF/A-1b and PDF/X-1a / X-3, which forbid transparency, get the stops composited onto the element's background colour (or white) and a warning.
- `repeating-linear-gradient()` / `repeating-radial-gradient()` drew nothing and raised a PHP `Deprecated` notice (`hexdec()` on `#f00 10pt`): they are drawn now (one shading per period, repeated, so viewers that sample a shading function coarsely still show sharp stripes). Malformed hex colours are rejected without a notice.
- `conic-gradient()` / `repeating-conic-gradient()` drew nothing without a warning: they are drawn as a free-form triangle mesh shading (wedges of at most 0.5° where the colour changes, exact edges at hard stops, e.g. pie charts), with `from <angle>`, `at <position>` and angle / percentage stops.
- A stop with two positions (`#f00 0 50%`) produced only its last colour; `currentColor` stops were empty; stop positions given as lengths (`10px`, `calc()`) or outside 0–100 % and colour hints (`red, 30%, blue`) were ignored; `to top right` used 45° instead of the box diagonal ("magic corners"). All follow CSS Images 3 / Chromium now; the legacy `-webkit-linear-gradient()` / `-webkit-radial-gradient()` syntax is understood (other vendor prefixes are ignored, as in Chromium). An unsupported or invalid gradient adds a warning instead of being dropped silently.
- `background` / `background-image` with several layers painted only the first one (`linear-gradient(...), url(photo.jpg)` lost the photo): every layer is painted, each with its own `background-size` / `-position` / `-repeat`; a gradient with a `background-size` is tiled.
- `<input type="password" value="…">` wrote the value into the PDF (`/V`) and drew it in clear text. The value is never written (no `/V`, no clear text in any appearance stream, also with `formFields = false`); one bullet per character is drawn, as browsers print it, and the field keeps the password flag so viewers mask typed input.
- Nested CSS counters: `counter-reset` on a nested element overwrote the outer counter, so `counters(item, ".")` gave `1 1 1 2 3` instead of `1 1.1 1.1.1 1.2 2`. Counters are scoped as in CSS Lists 3 (a reset creates a new instance for the element, its descendants and following siblings; an increment or set without a counter in scope creates one; `counter-reset` / `counter-increment` / `counter-set` apply in that order; `counter-reset` on `<html>` / `<body>` works), `counters()` joins all instances; counters created in `::before` / `::after` stay in the pseudo-element, as in Chromium. `counters()` separators may contain commas, `counter(x, none)` is empty. The implicit `list-item` counter exists now (reset by `<ol>` / `<ul>` / `<menu>`, including `<ol start>` / `reversed`, incremented by every list item) and `::marker { content: ... }` replaces the marker, so `li::marker { content: counters(list-item, ".") " " }` gives legal numbering; `content: none` hides the marker. (For `<ol reversed>` the counter counts down from the number of items as the specification says; Chromium shows 0, -1, -2… there.)
- SVG `<textPath>` was not drawn and its text was lost: glyphs are placed along the referenced path (`href` / `xlink:href`, `startOffset` as length or %, the path's `pathLength` and `transform`, `text-anchor`, `dx` / `dy`, `<tspan>` inside), each rotated to the path direction at its midpoint with kerned advances, as Chromium does; glyphs past the end of the path are not drawn. `side` is ignored (Chromium does not support it). A missing path is reported in `getWarnings()`.
- `li { display: block }` (or any other display than `list-item`) still showed a marker and counted in its list; only list items do now.
- `box-shadow: inset` was ignored. Inset shadows are drawn inside the padding box (above the background, below the border), with offset, spread, rounded corners and a blur that matches Edge's profile (stacked layers placed on the Gaussian, no seams). In PDF/A-1 and PDF/X-1a / X-3 box shadows (outer and inset) are painted with opaque, pre-blended colours instead of turning into solid black rings when the opacity states were reset.
- Outer `box-shadow` blur was darker and shorter than Chrome's (fixed 0.65 × blur rings): the shadow is now the Gaussian Chromium paints (sigma = 0.47 × blur, measured against Edge), drawn as nested rounded rectangles on its iso-contours (corner radius from the blurred corner, small boxes fade like in Chrome, spread radii per CSS Backgrounds 3). Mean pixel difference to Edge drops from 1.6–8 to 0.1–1.0 (0–255 scale). Named colours and missing colours (`currentColor`) in outer shadows were ignored (always a 10 % black). Inset shadows with an offset now use each side's own depth and round the blurred hole corners, so the weak sides and corners match Edge too.
- CSS GCPM `bookmark-level` (`none` | integer, on any element) and `bookmark-label` (strings, `content()` / `content(before | after | text)`, `attr()`, `counter()`) were ignored; headings keep their default levels.
- Verified: a `maxMemory` number without a unit (e.g. `64`, read as bytes) is reported in `getWarnings()` whichever way it is set (options array, `Options` object, `$pdf->maxMemory`, mPDF config / property).

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
