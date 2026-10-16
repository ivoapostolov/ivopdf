# IvoPDF — Pure-PHP HTML & CSS to PDF

**English** · [Български](README.bg.md)

IvoPDF converts HTML + CSS into PDF with **no external binaries** (no Chrome, Node.js or wkhtmltopdf). It runs anywhere PHP runs, including shared hosting, and is designed as a drop-in replacement for **dompdf** and **mPDF**, adding real **CSS Flexbox**, **CSS Grid**, digital signatures and e-invoice (Factur-X) support.

Requirements: PHP ≥ 8.1 with `ext-dom`, `ext-mbstring`, `ext-zlib`. Optional: `ext-gd` (GIF, WebP, palette PNG, image filters), `ext-openssl` (encryption, signatures), `ext-intl` (Thai/Khmer word breaking, index collation). PHP ≥ 8.4 enables the HTML5 parser.

## Features

**Layout and CSS**
- Block, inline, inline-block, floats, absolute / fixed / relative positioning, `z-index`, **Flexbox**, **CSS Grid** (`fr`, `repeat()`, `minmax()`, `auto-fit/fill`, spans, auto-placement), **multi-column** (`column-count`, `column-width`, `column-rule`, `column-span`, balancing across pages).
- Tables: `thead/tbody/tfoot`, `colspan`/`rowspan`, `<caption>`, `<col>` widths, `table-layout: fixed`, collapsed borders, header row repeated on every page, rows never sliced, wide tables shrunk to fit (mPDF `shrink_tables_to_fit`).
- Full selector engine (`:is()`, `:where()`, `:has()`, `:nth-*()`, attribute operators…), specificity and `!important`, `var()`, `calc()`, `inherit`/`initial`/`unset`, `@media` (print, width, orientation), `@supports`, `@import`, `@font-face`, `::before`/`::after` with `content`, CSS counters, percentage heights, `aspect-ratio`, `display: contents`.
- HTML5 parsing per the WHATWG algorithm (PHP ≥ 8.4).

**Text and fonts**
- UTF-8 everywhere; bundled **Inter** (default sans-serif, full Cyrillic) and DejaVu Sans/Serif/Mono (Latin, Cyrillic, Greek, Arabic, Hebrew…, also used automatically for characters Inter lacks).
- **Font subsetting** (a typical invoice is ≈ 40 KB instead of ~900 KB), TrueType and **OpenType/CFF (`.otf`)**, `.ttc` collections, `@font-face`, automatic **fallback fonts** and per-script font selection.
- **OpenType shaping**: ligatures, kerning, `font-feature-settings`, `font-variant-*`.
- **Right-to-left** (Unicode bidi algorithm, `dir="rtl|auto"`, `<bdi>`, `<bdo>`), **Arabic** joining, **Hebrew**.
- **CJK** line breaking (kinsoku, `word-break`, `line-break`), **Indic** shaping (Devanagari, Bengali, Gujarati, Gurmukhi, Tamil…), **Thai / Lao / Khmer** word breaking.
- **Hyphenation**: `&shy;` and `hyphens: auto` for Bulgarian and English (TeX patterns).
- Correct wrapping across inline tags, `&nbsp;`, justification, `text-overflow: ellipsis`, `text-shadow`, decorations, continuous backgrounds on multi-word inline elements.

**Graphics and images**
- `border-radius`, all border styles (double, groove, ridge, inset, outset), `border-image`, `outline`, multiple `box-shadow`s, `opacity` (true group opacity), `mix-blend-mode`, `clip-path`, image `filter`s, `transform` lists with `transform-origin`.
- `linear-gradient()` / `radial-gradient()` as native PDF shadings, `background-size / position / repeat`, SVG backgrounds.
- Images: JPEG (incl. CMYK and EXIF orientation), PNG (alpha, palette, interlaced, 16-bit), GIF, WebP, BMP, TIFF, SVG, WMF, EMF; `object-fit` / `object-position`.
- SVG: all shapes and path commands, text, `transform`, `viewBox`, real **gradients**, **patterns**, **clip paths**, **masks**, **markers**, `<use>`, `<style>`, `<image>`.

**Documents**
- **Bookmarks** from headings, clickable external and internal (`#id`) links, **table of contents** with page numbers (`<toc />`, `target-counter()`, dotted leaders), mPDF-style **index**.
- Running headers/footers (per first / odd / even page), `@page :first / :left / :right`, **named pages** (mixing portrait and landscape), **margin boxes** (`@top-center`, `@left-middle`…), `string-set`, `position: running()`.
- **Fillable PDF forms** (AcroForm) from HTML `<input>`, `<textarea>`, `<select>`, `<button>`.
- **Barcodes**: QR (incl. Cyrillic), DataMatrix, PDF417, Code 128/39/93, EAN-13/8, UPC-A/E, ISBN, ISSN, Interleaved 2 of 5, Codabar.
- **Security**: encryption with passwords and permissions (AES-256, AES-128, RC4), **digital signatures** (PAdES-compatible CMS, visible or invisible).
- **Archiving and print**: **PDF/A-1b, 2b, 3b**, **PDF/X-1a, X-3, X-4**, CMYK colors, spot (Pantone) colors, bleed and crop marks.
- **Attachments** and **Factur-X / ZUGFeRD** e-invoices; **importing pages** from existing PDFs (letterhead backgrounds, merging).
- Watermarks (text and image), sticky-note annotations, metadata and document language.

**Safe by default**
- Remote loading off; local files only from `basePath` / `allowedPaths` (no path traversal, no `phar://` or `file://`).
- SSRF protection: private/reserved IPv4/IPv6 blocked, DNS pinning, TLS verification, no redirects, size limits.
- Limits for HTML size, nesting depth, word length, page count and image pixels; non-fatal problems are reported by `getWarnings()`.
- Dompdf's `<script type="text/php">` is intentionally **not** supported.

## Quick start

```php
require_once __DIR__ . '/autoload.php';      // or: require 'vendor/autoload.php';

use IvoPdf\IvoPdf;

$pdf = new IvoPdf(['paperSize' => 'A4', 'orientation' => 'portrait']);
$pdf->setFooterHtml('<div style="text-align:right; font-size:9pt">Page {PAGE_NUM} of {PAGE_COUNT}</div>');
$pdf->loadHtml('<h1>Invoice #1001</h1><div style="display:grid; grid-template-columns:1fr 1fr; gap:12pt">…</div>');

$pdf->stream('invoice.pdf', ['Attachment' => false]);   // or $pdf->save(__DIR__ . '/invoice.pdf'); or $bytes = $pdf->output();
```

Local images, stylesheets and fonts are only read from allowed folders:

```php
$pdf = new IvoPdf(['basePath' => __DIR__ . '/templates', 'allowedPaths' => [__DIR__ . '/assets']]);
$pdf->loadHtmlFile(__DIR__ . '/templates/invoice.html');   // basePath is set to the file's folder automatically
print_r($pdf->getWarnings());                              // e.g. images that could not be loaded
```

## Migrating from dompdf / mPDF

| dompdf | mPDF | IvoPDF |
|---|---|---|
| `new Dompdf($options)` | `new Mpdf(['format' => 'A4-L', 'margin_left' => 10])` | `new IvoPdf($options)`; both option styles are accepted (`format`, `orientation`, `margin_*` in mm, `default_font`, `chroot`, `isRemoteEnabled`) |
| `loadHtml($html)` | `WriteHTML($html)` | `loadHtml()` / `WriteHTML()` |
| `setPaper('A4', 'landscape')` | `format` option | `setPaper('A4', 'landscape')` or `[w, h]` in pt |
| `render(); output(); stream()` | `Output($name, 'I'/'D'/'F'/'S')` | `render()`, `output()`, `stream()`, `save()`, `output($name, 'S'\|'F'\|'D'\|'I')` |
| `counter(page)` in CSS | `SetHTMLHeader()` / `SetHTMLFooter()` with `{PAGENO}` / `{nbpg}` | all work; also `setHeaderHtml()` / `setFooterHtml()` with `{PAGE_NUM}` / `{PAGE_COUNT}` |
| — | `SetProtection()`, `SetWatermarkText()`, `SetAssociatedFiles()`, `SetDocTemplate()`… | supported |

For Laravel and Symfony see [Framework integrations](#framework-integrations).

### mPDF templates, unchanged

`use Mpdf\Mpdf; new Mpdf([...]); $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS)` works as is (lazy aliases `Mpdf\Mpdf`, `Mpdf\HTMLParserMode`, `Mpdf\Output\Destination`; they never override a real mPDF install).

| mPDF | Support |
|---|---|
| `<pagebreak>` | `orientation`, `sheet-size`, `margin-*` (mm), `odd-/even-header-name` / `-footer-name` (+ `-value`), `resetpagenum`, `pagenumstyle`, `suppress`, `type="NEXT-ODD/NEXT-EVEN"` |
| `<htmlpageheader name>` / `<htmlpagefooter name>`, `<sethtmlpageheader …>`, `@page { header: html_x; footer: html_y }` | named running headers/footers switched mid-document; `{PAGENO}`, `{nbpg}`, `{nb}`, `{DATE j-m-Y}`; `DefHTMLHeaderByName()`, `SetHTMLHeaderByName()`, `SetHTMLHeader/Footer($html, 'O'\|'E')` |
| `SetHeader('L\|C\|R')` / `SetFooter(...)` | strings and the array form |
| `<bookmark>`, `Bookmark()` | PDF outline (levels start at 0) |
| `<tocpagebreak>`, `<tocentry>`, `TOC_Entry()` | `toc-preHTML`, `toc-postHTML`, `toc-bookmarkText`, `toc-` page attributes |
| `<indexentry>`, `<indexinsert>`, `IndexEntry()`, `InsertIndex()` | alphabetical index with page numbers, sorted with `Collator` (`bg_BG`, `en_US`…) or a built-in Bulgarian/Latin order |
| `<annotation>` | PDF sticky note |
| `<watermarktext>`, `<watermarkimage>`, `SetWatermarkImage()` | text / image watermark on every page |
| `<columns>` / `<columnbreak />` / `SetColumns()` | CSS multi-column |
| `rotate`, `autosize`, `overflow="shrink"` on tables / images | rotation via `transform`; shrink-to-fit |
| `<barcode code type size error>` | see Barcodes below |
| `WriteHTML($html, $mode)`, `SetDefaultBodyCSS()`, `SetDirectionality()`, `SetCompression()`, `SetDisplayMode()`, `SetJS()`, `AddPage(...)`, `AddPageByArray()`, `SetBasePath()`, `SetDefaultFont()` | supported |
| constructor keys | `mode`, `tempDir`, `autoScriptToLang`, `autoLangToFont`, `fontDir` + `fontdata`, `margin_header` / `margin_footer`, `showImageErrors`, `PDFA`, `PDFX`, `restrictColorSpace`, `shrink_tables_to_fit`… |

Not supported: the imperative drawing API (`Cell`, `Ln`, `Rect`, `Image`, `WriteText`, `SetFont`…), `mirrorMargins`, `<dottab>`, `<formfeed>`, `<tts>`, `<setpageheader>` / `<setpagefooter>` (use `SetHeader()` / `SetFooter()`).

## Options

| Option | Default | Description |
|---|---|---|
| `paperSize` | `'A4'` | A0–A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio or `[widthPt, heightPt]` |
| `orientation` | `'portrait'` | `portrait` / `landscape` |
| `margins` | 36 pt each | `['top' => .., 'right' => .., 'bottom' => .., 'left' => ..]`; CSS `@page { margin }` overrides |
| `defaultFont`, `defaultFontSize` | Inter, 12 | generic `sans-serif`, Arial, Helvetica… map to Inter; `serif` / `monospace` to DejaVu Serif / Sans Mono |
| `defaultBoxSizing` | `content-box` | CSS default; templates usually set `* { box-sizing: border-box }` |
| `basePath`, `allowedPaths` | `null`, `[]` | folders from which local images / CSS / fonts may be read |
| `isRemoteEnabled` | `false` | allow `http(s)` resources (SSRF-protected) |
| `maxRemoteBytes`, `maxImagePixels` | 10 MB, 25 MP | resource limits |
| `maxHtmlBytes`, `maxNestingDepth`, `maxWordLength`, `maxPages` | 32 MB, 200, 2000, 5000 | failure guards |
| `html5Parser` | `true` | WHATWG HTML5 parser (PHP ≥ 8.4); `false` uses libxml's HTML4 parser |
| `shrinkTablesToFit` | `1.4` | maximum shrink factor for too-wide tables (`0` = off) |
| `subsetFonts` | `true` | embed only used glyphs |
| `fallbackFonts`, `scriptFonts` | `[]` | extra families for missing glyphs; font per script (`['Hani' => 'Noto Sans SC', 'Deva' => 'Noto Sans Devanagari']`) |
| `bookmarks` | `true` | PDF outline from headings |
| `formFields` | `true` | interactive form fields; `false` draws controls as static graphics |
| `pdfa` | `''` | `'1b'`, `'2b'`, `'3b'` |
| `pdfx`, `pdfxOutputCondition`, `pdfxIccProfile` | `''`, `FOGRA39`, `''` | `'X-1a'`, `'X-3'`, `'X-4'` |
| `colorSpace` | `'rgb'` | `'cmyk'` converts all colors and images to CMYK |
| `bleed`, `cropMarks` | `0`, `false` | bleed in mm, crop marks |
| `compress` | `true` | deflate content streams |
| `title`, `author`, `subject`, `keywords`, `creator`, `lang` | | metadata (`title` / `lang` default to `<title>` / `<html lang>`) |
| `watermarkText`, `watermarkOpacity` | `''`, `0.08` | diagonal text watermark |

## Feature guide

### Fonts

```php
$pdf->registerFontFamily('Inter', ['regular' => '/fonts/Inter.ttf', 'bold' => '/fonts/Inter-Bold.ttf']);
$pdf->addFallbackFont('Noto Sans Symbols');
```

CSS `@font-face` works with `.ttf` / `.otf` sources (data URI, allowed local path, or remote when enabled). Asian and Indic scripts need fonts that are not bundled (for example Google's Noto fonts, OFL-licensed):

```php
$pdf = new IvoPdf(['scriptFonts' => ['Hani' => 'Noto CJK SC', 'Hani:ja' => 'Noto CJK JP', 'Hang' => 'Noto CJK KR', 'Deva' => 'Noto Deva']]);
$pdf->registerFontFamily('Noto CJK SC', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#2']);   // face index after '#'
$pdf->registerFontFamily('Noto CJK JP', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#0']);
$pdf->registerFontFamily('Noto CJK KR', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#1']);
$pdf->registerFontFamily('Noto Deva',   ['regular' => '/fonts/NotoSansDevanagari-Regular.ttf']);
```

Characters with no available font render as empty boxes and add one warning per script to `getWarnings()`.

### Headers, footers and paged media

- `setHeaderHtml($html, 'all'|'first'|'odd'|'even')`, same for footers; placeholders `{PAGE_NUM}`, `{PAGE_COUNT}`, `{DATE}`, `{DATE d.m.Y}`, `{TITLE}`. Space is reserved automatically.
- CSS: `@page :first { margin-top: 40mm }`, `@page :left / :right`, named pages (`@page wide { size: A4 landscape } table.big { page: wide }`), margin boxes (`@top-center { content: string(chapter) }`, `@bottom-right { content: counter(page) " / " counter(pages) }`), `string-set`, `position: running(header)` + `content: element(header)`.
- Table of contents: `<toc levels="3" title="Contents" />` or `setTableOfContents(true, 'Contents')`, or your own list with `a::after { content: leader('.') target-counter(attr(href), page) }`.

### Forms

HTML `<input type="text|email|number|date|password|checkbox|radio">`, `<textarea>`, `<select>` and `<button>` become fillable PDF fields with their current values (Cyrillic included). Radios with the same name form one group; `required`, `readonly` and `disabled` set the field flags; a form with an `http(s)` `action` gets a submit button.

### Barcodes

```html
<barcode type="QR" value="https://example.com/invoice/1001" size="1.5" error="M" />
<barcode type="EAN13" value="400638133393" />
<barcode type="C128" value="INV-2026-001" width="160" height="40" />
<barcode type="DATAMATRIX" value="ABC-123" />
<barcode type="PDF417" value="..." columns="6" />
```

Types: `QR`, `DATAMATRIX` (square and `shape="rect"`, GS1), `PDF417`, `C128` (auto A/B/C), `C128A/B/C`, `C39` (+ checksum, extended), `C93`, `EAN13`, `EAN8`, `UPCA`, `UPCE`, `ISBN`, `ISSN`, `I25`, `CODABAR`. PHP API: `IvoPdf\Widget\Barcode::svg($type, $value, $options)`.

### Security, signatures and archiving

```php
$pdf->setProtection(['print', 'copy'], 'user-password', 'owner-password');      // AES-256 by default; 'aes128', 'rc4'
$pdf->setSignature('/certs/cert.pem', '/certs/key.pem', 'key-password',
    ['Name' => 'Ivo Apostolov', 'Reason' => 'Approval', 'Location' => 'Sofia'],
    [1, 400, 760, 150, 50]);                                                     // optional visible box: page, x, y, w, h (pt)
$pdf->setPdfA('2b');                                                             // '1b', '2b', '3b'
print_r($pdf->validatePdfA());                                                   // heuristic pre-check
```

- Signatures are detached CMS (`adbe.pkcs7.detached` or `ETSI.CAdES.detached`) with SHA-256, RSA or ECDSA keys; `.p12` / `.pfx` bundles are accepted.
- PDF/A-1b is real ISO 19005-1 output (PDF 1.4, `/CIDSet`, no transparency: semi-transparent colors become opaque); 2b and 3b keep transparency. `validatePdfA()` is a heuristic pre-check, **not** a replacement for veraPDF.
- At every PDF/A level images are written with `/Interpolate false`, and embedded font subsets keep a width entry for every used glyph, so the program always matches `/W`. `validatePdfA()` checks both for TrueType fonts; for OpenType/CFF (`.otf`) fonts it does not compare glyph widths.
- PDF/A cannot be combined with encryption; PDF/X cannot be combined with PDF/A, encryption or a signature.

### Print production: CMYK, spot colors, PDF/X

```php
$pdf->addSpotColor('PANTONE 185 C', 0, 91, 76, 0);    // CMYK alternate in percent
$pdf->setPdfX('X-1a', 'FOGRA39');                     // or 'X-3', 'X-4'; optional ICC profile path
$pdf->setBleed(3, true);                              // 3 mm bleed with crop marks
```

CSS: `color: cmyk(0 0.91 0.76 0)`, `device-cmyk(...)`, `spot(PANTONE 185 C, 80%)`. X-1a / X-3 convert everything to CMYK and flatten transparency (reported by `getWarnings()`); X-4 keeps RGB and transparency. The RGB→CMYK conversion is naive (no ICC); plug in your own `IvoPdf\Pdf\ColorConverter`. No FOGRA/SWOP profile is bundled (license).

### Attachments, Factur-X and existing PDFs

```php
$pdf->attachFile('/data/report.xlsx', 'report.xlsx', 'Source data');
$pdf->setPdfA('3b')->attachFacturX($facturXml, 'EN 16931');   // EU e-invoice
$pdf->setPageTemplate('/templates/letterhead.pdf');           // background on every page
$pdf->appendPdf('/terms/terms-and-conditions.pdf');           // merge pages at the end
```

HTML `<img src="drawing.pdf" data-page="2">` places a PDF page as a vector graphic. Imported pages keep their links, annotations and form fields. Encrypted source PDFs are refused.

### Robustness and performance

HTML5 parsing (PHP ≥ 8.4), shrink-to-fit tables, limits for oversized input, and linear scaling for long documents (roughly 20 ms per page of body text; 500 pages in about 10 s). See the options above.

## Framework integrations

Separate Composer packages live in [`integrations/`](integrations/):

- **Laravel**: [`ivopdf/laravel`](integrations/laravel/README.md). Auto-discovered service provider, publishable `config/ivopdf.php`, and a `Pdf` / `PDF` facade with the `barryvdh/laravel-dompdf` API (`Pdf::loadView('invoices.show', $data)->setPaper('a4', 'landscape')->download('invoice.pdf')`).
- **Symfony**: [`ivopdf/symfony-bundle`](integrations/symfony/README.md). Bundle with `ivopdf:` config and an autowirable `PdfGenerator` (`fromHtml()`, `fromTwig()`, `toResponse()`).

## Demos (English & Bulgarian)

- **Web playground**: open `index.php` from localhost (e.g. `http://localhost/ivopdf/`), switch **BG | EN** and edit HTML live. Public access is disabled unless `IVOPDF_ALLOW_PUBLIC_STUDIO=1`.
- **CLI**: `php examples/generate.php [outDir]` renders every demo in both languages (`invoice`, `dashboard`, `certificate`, `catalog`, `showcase`) to `examples/output/{key}-{lang}.pdf`.
- In code: `IvoPdf\Examples\Templates::all('en')` / `all('bg')`.

## Tests

Each file in `tests/` is a standalone script that prints a summary and exits non-zero on failure:

```bash
for t in tests/*.php; do php "$t" || echo "FAILED: $t"; done
```

Some checks use Python with PyMuPDF (`pip install pymupdf`), the `openssl` CLI or Windows system fonts and are skipped when they are not available.

## Known limitations

- Vertical writing (`writing-mode: vertical-*`), hanging punctuation, SVG filters and EMF+ records are not supported.
- RTL does not mirror table, flex and grid layouts.
- When `min-width` is larger than `max-width`, `min-width` wins (as in CSS and browsers).
- PDF/A and PDF/X output is checked structurally, not with veraPDF or a commercial preflight tool.
- HTML form fields use the non-embedded standard fonts Helvetica / ZapfDingbats, so documents with forms do not pass PDF/A.
- `.ttc` faces are not supported in CSS `@font-face` (use `registerFontFamily()`).
- mPDF's imperative drawing API is not available (see the mPDF table above).

## AI assistant skill (Claude)

The repository ships a skill that teaches Claude how to build, debug and migrate PDF templates with IvoPDF: [`skills/ivopdf`](skills/ivopdf/SKILL.md).

- **Claude Code**: copy the folder to `~/.claude/skills/ivopdf` (all projects) or `.claude/skills/ivopdf` inside your project.
- **Claude.ai / Claude desktop**: zip the `skills/ivopdf` folder and upload it under Settings → Capabilities → Skills.

It includes `scripts/render.php` (render an HTML file, print pages and warnings) and `scripts/preview.py` (rasterize pages to PNG for review).

## License

Copyright © 2026 Ivo Apostolov.

IvoPDF is free and open-source software licensed under the **GNU Affero General Public License v3.0 or later** ([LICENSE](LICENSE)).

- You may use, study, modify and share it freely, including in commercial projects.
- If you distribute software that includes IvoPDF, or let users interact with it over a network (web apps, SaaS, APIs), the **whole application must be released under the AGPL** with its source code available to those users. Embedding IvoPDF into closed-source products is not permitted.
- Bundled third-party data keeps its own permissive license: Inter fonts (SIL Open Font License 1.1, [resources/fonts/LICENSE-Inter.txt](resources/fonts/LICENSE-Inter.txt)), DejaVu fonts ([resources/fonts/LICENSE-DejaVu.txt](resources/fonts/LICENSE-DejaVu.txt)) and the TeX hyphenation patterns (license headers in `resources/hyphenation/`).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Contributors sign the [CLA](CLA.md) once, on their first pull request.
