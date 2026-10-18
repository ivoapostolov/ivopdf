# IvoPDF — Pure-PHP HTML & CSS to PDF

**English** · [Български](README.bg.md)

IvoPDF converts HTML + CSS into PDF with **no external binaries** (no Chrome, Node.js or wkhtmltopdf). It runs anywhere PHP runs, including shared hosting, and is designed as a drop-in replacement for **dompdf** and **mPDF**, adding real **CSS Flexbox**, **CSS Grid**, digital signatures and e-invoice (Factur-X) support.

Requirements: PHP ≥ 8.1 with `ext-dom`, `ext-mbstring`, `ext-zlib`. Optional: `ext-gd` (GIF, WebP, palette PNG, image filters), `ext-openssl` (encryption, signatures), `ext-intl` (Thai/Khmer word breaking, index collation). PHP ≥ 8.4 enables the HTML5 parser.

## Features

**Layout and CSS**
- Block, inline, inline-block, floats (a float met in the middle of a paragraph is placed at the line where it occurs: at the top of that line when it fits beside the line's content, otherwise at the top of the next one, with the lines wrapped around it, CSS 2.1 9.5.1; a block that starts beside an earlier float keeps its full width and only its lines are shortened, each by the floats beside it, so the lines below the float use the full width again; a box that starts a new block formatting context such as a table, a flex / grid container or `overflow: hidden` is placed beside the float instead when it fits there (its percentage width is of the containing block, an auto-width table needs its min-content width), otherwise it moves down below the float, as in browsers; a float never rises above an earlier float, starts below the bottom margin of the block before it and is painted over the blocks it overlaps; `clear` puts a block right below the floats with the margins above it absorbed), absolute / fixed / relative positioning, `z-index`, **Flexbox**, **CSS Grid** (`fr`, `repeat()`, `minmax()`, `auto-fit/fill`, spans, auto-placement incl. `grid-auto-flow: column | dense`, `grid-auto-columns/rows`, `grid-template-areas`, `grid-area`, the `grid-template` / `grid` shorthands and named lines such as `[main-start]` / `main-end`; text wraps around floats line by line), **multi-column** (`column-count`, `column-width`, `column-rule`, `column-span`, balancing across pages).
- Tables: `thead/tbody/tfoot`, `colspan`/`rowspan` (`rowspan="0"`: to the end of the row group), `<caption>`, `<col>` widths, `table-layout: fixed`, collapsed and separated borders (`border-spacing`; with collapsed borders the table box holds the whole outer border and a border shared by two columns counts once, as in Edge; the borders of the cells, rows, row groups and the table that meet on an edge are resolved as in CSS 2.1 17.6.2.1: `hidden` suppresses them, then the widest wins, so the cell content is inset by half of the winning border and every edge is painted once), `width` / `height` of a table is its border box (Chromium's UA style), percentage cell widths as the column's share of the table (padding included), columns never narrower than their widest word (a table that cannot fit grows past its container instead of overlapping words - up to the page edge, see `pageOverflowWrap`), `vertical-align: top | middle | bottom` also in cells with an explicit height, captions outside the table border that never break inside a word (their min-content width widens the table), `dir="rtl"` tables laid out right to left, header row repeated on every page (a header that would be left alone at the bottom of a page moves to the next one with the table), rows never sliced, wide tables shrunk to fit (mPDF `shrink_tables_to_fit`).
- Full selector engine (`:is()`, `:where()`, `:has()`, `:nth-*()`, attribute operators…), specificity and `!important`, `var()`, `calc()`, `inherit`/`initial`/`unset`, `@media` (print, width, orientation), `@supports`, `@import`, `@font-face`, `::before`/`::after` with `content`, CSS counters, percentage heights, `aspect-ratio`, `display: contents`.
- HTML5 parsing per the WHATWG algorithm (PHP ≥ 8.4).

**Text and fonts**
- UTF-8 everywhere; bundled **Inter** (default sans-serif, full Cyrillic), **Liberation** Sans/Serif/Mono (metric-compatible with Arial, Times New Roman and Courier New, so templates written for those fonts keep their line breaks), **Sofia Sans** and **Sofia Sans Semi Condensed** (Bulgarian Cyrillic letterforms) and DejaVu Sans/Serif/Mono (Latin, Cyrillic, Greek, Arabic, Hebrew…, also used automatically for characters the other fonts lack), plus DejaVu Sans/Serif Condensed (`dejavusanscondensed` / `dejavuserifcondensed`, mPDF's default faces).
- **Font subsetting** (a typical invoice is ≈ 40 KB instead of ~900 KB), TrueType and **OpenType/CFF (`.otf`)**, `.ttc` collections, `@font-face`, automatic **fallback fonts** and per-script font selection.
- **OpenType shaping**: ligatures, kerning, `font-feature-settings`, `font-variant-*`; `font-variant: small-caps` / `all-small-caps` use the font's `smcp`/`c2sc` glyphs and are synthesized (capitals at 70 % size) for fonts without them, as browsers and mPDF do; synthesized small capitals carry `/ActualText`, so copying and searching see the original lowercase text. GSUB/GPOS lookups are pre-filtered per glyph, so Inter shapes at nearly DejaVu speed.
- **Typographic pseudo-elements**: `::first-letter` (drop caps via `float` / `initial-letter`) and `::first-line` (font, colour, `text-transform`, `font-variant: small-caps` / `font-variant-caps` on the first line box, re-broken when the metrics change).
- **Right-to-left** (Unicode bidi algorithm, `dir="rtl|auto"`, `<bdi>`, `<bdo>`), **Arabic** joining, **Hebrew**.
- **CJK** line breaking (kinsoku, `word-break`, `line-break`), **Indic** shaping (Devanagari, Bengali, Gujarati, Gurmukhi, Tamil…), **Thai / Lao / Khmer** word breaking.
- **Hyphenation**: `&shy;` and `hyphens: auto` for Bulgarian and English (TeX patterns).
- Correct wrapping across inline tags, `&nbsp;`, justification, `text-overflow: ellipsis`, `text-shadow`, decorations, continuous backgrounds on multi-word inline elements.
- **Blockification** (CSS Display 3): a floated or absolutely positioned element and every flex / grid item gets the block-level equivalent of its `display` (`inline` / `inline-block` -> `block`, `inline-flex` -> `flex`, `inline-table` -> `table`), so `<h3>Date <span style="float:right">12.05.2026</span></h3>` or a floated `<a href>` lays out as a shrink-to-fit float on the line where it occurs (also inside table cells and headings) instead of losing its text.
- **List counters**: `decimal`, `decimal-leading-zero`, `lower/upper-alpha|latin|roman|greek`, `cyrillic`, `armenian` / `lower-armenian`, `georgian`, `hebrew`, `cjk-decimal`, `cjk-ideographic` (CSS Counter Styles 3 ranges; a value outside the range of a style is shown in decimal), also in `counter(name, style)`.
- `<details>` without `open` shows only its `<summary>` (a default "Details" one when missing), as browsers do in print; with `open` everything is shown. Inside list markers (`list-style-position: inside`) and the `<summary>` triangle take the same width as in Chromium; `list-style-type: '-> '` (a string) is drawn as given.
- An explicit `height` (a length, or a percentage of a definite height) is the box's height also when the content does not fit: the content overflows (painted outside the box, or clipped by `overflow: hidden`) and the following content starts after the declared height, as in browsers; a page break inside such a box moves only its content, the box keeps its height. `min-height` / `max-height` still apply; the `height` of a table, a table row or a cell stays a minimum (the row grows to its content). A table taller than its rows (`height`, or stretched as a flex / grid item) shares the extra height among the rows without an explicit height, in proportion to their heights, as in browsers. A grid row that cannot keep the first piece of each item (a table row, the first `orphans` lines) on the page moves to the next page as a whole. A line with a `vertical-align: top | bottom` inline-block grows only to the box's height (CSS 2.1 10.8).

**Graphics and images**
- `border-radius`, all border styles (double, groove, ridge, inset, outset), `border-image`, `outline`, multiple `box-shadow`s, `opacity` (true group opacity), `mix-blend-mode`, `clip-path`, image `filter`s, `transform` lists with `transform-origin`.
- `linear-gradient()` / `radial-gradient()` as native PDF shadings, `background-size / position / repeat`, SVG backgrounds.
- Images: JPEG (incl. CMYK and EXIF orientation), PNG (alpha, palette, interlaced, 16-bit), GIF, WebP, BMP, TIFF, ICO / CUR (the largest image of the icon; PNG or BMP entries, the AND mask as transparency), SVG, WMF, EMF; `object-fit` / `object-position`. Inline `<svg width="100%">` takes the width of its container (the viewBox is fitted into it) and its text inherits the font of the surrounding HTML.
- SVG: all shapes and path commands, text, `transform`, `viewBox`, real **gradients**, **patterns**, **clip paths**, **masks**, **markers**, `<use>`, `<style>`, `<image>`.

**Documents**
- **Bookmarks** from headings, clickable external and internal (`#id`) links, **table of contents** with page numbers (`<toc />`, `target-counter()`, dotted leaders), mPDF-style **index**.
  Links: one `/Link` annotation per link: the fragments of a line are one rectangle (a six-word link is one rectangle, not eleven) and a link wrapped over several lines gets one rectangle per line, as browsers write it; `<a href>` around `<img>` / `<svg>` (also a floated image) is clickable, and so is `<a href>` inside SVG markup; `#id` targets work for any element with an `id` (block, inline `<span>`/`<a>`, `<img>`, `<svg>`, empty anchors) and for `<a name>`, at the painted page and line. A `#target` that does not exist adds one warning and the link is omitted (`#` and `#top` go to the document top). Links and form fields belong to the page that paints their line: nothing is emitted into the margin of the previous page.
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
- Dompdf's `<script type="text/php">` is intentionally **not** supported (a warning points to `getCanvas()->page_text()` / `setFooterHtml()`).

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
| `render(); output(); stream()` | `Output($name, 'I'/'D'/'F'/'S')` | `render()`, `output()`, `stream()`, `save()`, `output($name, 'S'\|'F'\|'D'\|'I')` with mPDF's rules: a file name without a destination writes the file, `true` = `'D'`, `false` = `'F'`, `'F'` / `'D'` / `'I'` return `null` |
| `counter(page)` in CSS | `SetHTMLHeader()` / `SetHTMLFooter()` with `{PAGENO}` / `{nbpg}` | all work; also `setHeaderHtml()` / `setFooterHtml()` with `{PAGE_NUM}` / `{PAGE_COUNT}` |
| — | `SetProtection()`, `SetWatermarkText()`, `SetAssociatedFiles()`, `SetDocTemplate()`… | supported |

Default styles follow browsers (and dompdf), checked against Microsoft Edge: `body { margin: 8px }` (documents written through the mPDF API, `WriteHTML()`, keep mPDF's margin of 0; the body's top margin collapses with its first child's), `line-height: normal` from the font's own metrics (hhea, or the OS/2 typo values when the font sets USE_TYPO_METRICS; ascent, descent and line gap rounded to whole CSS pixels as Blink does: DejaVu Sans 11pt gives 12.75pt), tables with `border-collapse: separate; border-spacing: 2px` (also around the outer cells) and no margin, `table[border]` with an outset table border and inset cell borders, `cellspacing="0"` for no spacing, centred `<th>`, cells with `padding: 1px` (so templates written for a browser keep their row heights; give tables their own `td { padding: … }` for roomier cells), lists with `margin: 1em 0; padding-left: 40px` (nested lists without vertical margins, bullets `disc` / `circle` / `square` by depth, an inside bullet padded by 7px), `<figure>` and `<blockquote>` with `margin: 1em 40px`, `<pre>` in a monospace font with `white-space: pre` and no box, `<caption>` without padding, outside list markers placed like Chrome's (`1.` ends one space before the text, a bullet sits where Chrome draws its disc), `<hr>` as a 1px inset gray rule, `<fieldset>` with a groove border and its `<legend>` drawn in the top border, and form controls in Arial metrics (Liberation Sans; a `<textarea>` monospace, 20 x 2 characters, sitting on the baseline with its bottom edge; a text input 132.75pt wide, date / time inputs as wide as in Edge, e.g. 87pt for `type="date"`); `<input type="range">` (a 96.75pt slider: track, filled part and thumb), `<progress>` (10em x 1em) and `<meter>` (5em x 1em, green / yellow / red by `low` / `high` / `optimum`) are drawn like Edge prints them, their fallback content is not shown, and a slider is never an AcroForm field. Floats that sit in front of a block keep it from separating the margins of its parent, and a forced page break keeps the top margin of the box after it, like in browsers.

Positioning follows browsers and dompdf: `position: fixed` is placed in the page area (inside the `@page` margins) and repeated on every page, so the classic dompdf footer `footer { position: fixed; bottom: -20pt }` sits in the bottom margin (mPDF documents: relative to the sheet, as in mPDF); `position: absolute` uses the padding box of the nearest positioned ancestor, or the page area when there is none (not the body); `position: relative` on an inline element (`<span style="position:relative; top:2pt">`) shifts its text. `counter(page)` / `counter(pages)` in generated content are laid out one digit wide and re-aligned when the number is drawn.

Tables without a `width` are shrink-to-fit like in browsers (content width, at most the available width); use `width: 100%` for a full-width table. Position them with `margin-left/right: auto`, `float`, or the legacy `align="center|left|right"` attribute (`left` / `right` float the table, as in browsers); `text-align` on the parent does not move a block table, but `<center>` and `align=` on a block container do. Also supported: `font:` shorthand with any order of style / variant / weight / stretch keywords (unspecified parts reset to their initial values), `page-break-before/after` and `break-before/after: left | right | recto | verso` (an empty page is inserted when needed; page 1 is a right page, or a left page when the root element is `direction: rtl`), `@page { size: 100mm }` / `100mm 50mm` / `A4 landscape` / `landscape`, `<wbr>` (also a break opportunity in min-content widths of shrink-to-fit boxes and table columns), `caption-side: top | bottom` (the bottom caption follows the last row on the last page of a split table), `tab-size` (number or length, default 8; one tab never advances past the line width, so `tab-size: 1e9` cannot allocate a huge string) for `white-space: pre | pre-wrap`, `orphans` / `widows` (default 2, as in browsers: a paragraph is not split so that a single line stays at the bottom of a page or starts the next one), vertical padding and borders of inline elements that do not make the line taller (a padded `<span>` badge overlaps the lines around it, as in browsers), margin boxes that inherit `font-*`, `color` and `line-height` from their `@page` rule, the `:dir(ltr | rtl)` selector (from `dir` attributes, `dir=auto` by the first strong character), list markers / `counter()` styles that stay bounded for huge values (roman numerals only for 1-3999, decimal outside; counters are clamped to a 32-bit range), and `ex` / `ch` units (x-height from the font's OS/2 table and the width of `0`, also for `@font-face` families and regardless of the declaration order; 0.5 em when the font has no x-height). `<noscript>` content is hidden, as in a browser with scripting (dompdf renders it).

For Laravel and Symfony see [Framework integrations](#framework-integrations).

### dompdf code, unchanged

`use Dompdf\Dompdf; use Dompdf\Options;` works as is (lazy aliases `Dompdf\Dompdf`, `Dompdf\Options`, `Dompdf\Canvas`, `Dompdf\FontMetrics`; they never override a real dompdf install):

```php
$options = new Options();
$options->set('isRemoteEnabled', true);          // setIsRemoteEnabled(), setChroot(), setDefaultFont(), setDpi()... also work
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('helvetica', 'bold');
$canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 35, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 10, [0, 0, 0]);
$canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {   // a PHP callable, never a string of code
    $canvas->line(36, 50, 559, 50, [0.6, 0.6, 0.6], 0.5);
});
echo $dompdf->output(['compress' => 0]);        // or $dompdf->stream('x.pdf', ['Attachment' => 0])
```

`Canvas` has the methods of dompdf's CPDF adapter: `page_text()`, `page_script()`, `page_line()`, `text()`, `line()`, `rectangle()` (and the older alias `rect()`), `filled_rectangle()`, `circle()`, `arc()`, `polygon()`, `image()`, `add_link()` (URL or `#name`), `add_named_dest()`, `add_info()`, `javascript()`, `new_page()`, `set_default_view()`, `set_opacity()` (with a blend mode), `save()` / `restore()`, `clipping_rectangle()` / `clipping_roundrectangle()` / `clipping_polygon()` / `clipping_end()`, `rotate()` / `scale()` / `skew()` / `translate()` / `transform()` (dompdf's matrices), `open_object()` / `reopen_object()` / `close_object()` / `add_object()` (`'add'`, `'all'`, `'next'`, `'even'`, `'odd'`, `'nexteven'`, `'nextodd'`) / `stop_object()`, `get_text_width()`, `get_font_height()`, `get_font_baseline()`, `font_supports_char()`, `get_width()`, `get_height()`, `get_page_count()`, `output()` / `stream()`; coordinates are dompdf's (pt from the top-left corner) and `page_text()` / `text()` put the baseline one font height (`getFontBaseline()`) below `y`, as dompdf does. Drawing after `render()` makes `output()` render again and goes to the page the canvas is on, as in dompdf: page 1 before `render()`, the last page after it, the page added by `new_page()`, or a page reopened with `reopen_object($handle)` until `close_object()` (`new_page()` returns that handle, an int like dompdf's page content id, not a page number). `get_page_number()` / `get_page_count()` are dompdf's counters: `render()` sets both to the number of pages, `new_page()` increments both, `set_page_number()` / `set_page_count()` only change the numbers and never move the drawing. After `render()`, `page_text()` / `page_line()` / `page_script()` act at once on the pages that exist, as in dompdf: `{PAGE_COUNT}` and the callback's page count come from `get_page_count()`, and `get_page_number()` inside the callback is the canvas' counter; called before `render()` they are applied to every page of the finished document (dompdf would only reach its first page). Inside `page_script()` drawing goes to that page and the graphics state (opacity, clip, transformation) starts fresh for every call. Only `page_text()` substitutes `{PAGE_NUM}` / `{PAGE_COUNT}` (`text()` prints them as they are, like dompdf) and it is not recorded into objects. `end_document` callbacks see `get_page_number()` = the last page, `end_page_render` / `begin_page_render` ones the page being drawn. Other CPDF methods (`serialize_object()`, `select()`, `input()`…) do nothing and add one warning per method to `getWarnings()` instead of a fatal error. `Dompdf` also has `getDom()` (the parsed `DOMDocument`), `loadDOM()` / `setDom()`, `set_options()`, `setCallbacks()` / `getCallbacks()` (`end_document`, `end_page_render` and `begin_page_render` — drawn below the content — are raised; the page events receive `null` as `$frame`; `begin_frame` / `end_frame` / `begin_page_reflow` are reported as unsupported), `setHttpContext()` / `getHttpContext()` (stored; remote files always use IvoPDF's SSRF-guarded client), `getTree()` (IvoPDF's layout tree instead of a frame tree), `getQuirksmode()` (false), `setDefaultView()` / `parseDefaultView()`; unknown dompdf methods warn instead of failing. `FontMetrics` has `getFont()` (dompdf's families: `serif` / `times` = Times, `sans-serif` / `helvetica` = Helvetica, `monospace` / `fixed` / `courier` = Courier, drawn with the metric-compatible Liberation faces; an unknown family gives `null` like dompdf, and measuring / drawing with `null` uses Helvetica, Cpdf's default), `getTextWidth()`, `getFontHeight()` / `getFontBaseline()` (dompdf's values: the core fonts' AFM ascender - descender, e.g. Helvetica 12pt 11.1 / 12.21 with `fontHeightRatio` 1.1, the font's own ascent + descent for TrueType fonts), `registerFont()` / `register_font()` (true / false like dompdf; a file that is not a TrueType / OpenType font is refused, remote URLs are not downloaded), `getFamily()`, `setFontFamily()` (dompdf's extension-less paths are accepted), `getFontFamilies()`, `getType()`. `Options::getFontDir()` / `getFontCache()` / `getRootDir()` default to the bundled font folder and the library. In documents rendered through the dompdf API the CSS generic families `serif` and `monospace` are Times and Courier as in dompdf (Liberation Serif / Mono), and `get_page_count()` is 1 before `render()`. Differences: dompdf's default paper size is Letter (call `setPaper('A4')`); `fontDir` / `fontCache` / `tempDir` / `pdfBackend` / debug options are accepted and ignored; `<script type="text/php">` and PHP code strings given to `page_script()` are never executed, a warning in `getWarnings()` tells you to use `getCanvas()->page_text()` / `page_script(callable)` or `setFooterHtml()`.

### mPDF templates, unchanged

`use Mpdf\Mpdf; new Mpdf([...]); $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS)` works as is (lazy aliases `Mpdf\Mpdf`, `Mpdf\HTMLParserMode`, `Mpdf\Output\Destination`; they never override a real mPDF install). `Mpdf\Mpdf` is `IvoPdf\Compat\Mpdf`, an `IvoPdf` with mPDF's defaults: body text in DejaVu Serif Condensed 11pt with mPDF's normal line height 1.33 (unless `default_font` / `default_font_size` are configured or the document sets its own), and `Output()` with mPDF's destinations (no arguments = inline, a file name alone = `'F'`, `true` = `'D'`, `false` = `'F'`). The mPDF methods are untyped like mPDF's, so `SetFont('dejavusans', '', '12')` or `Output($file, true)` also work in files with `declare(strict_types=1)`.

| mPDF | Support |
|---|---|
| `<pagebreak>` | `orientation`, `sheet-size`, `margin-*` (mm), `odd-/even-header-name` / `-footer-name` (+ `-value`), `resetpagenum`, `pagenumstyle`, `suppress`, `type="NEXT-ODD/NEXT-EVEN"` |
| `<htmlpageheader name>` / `<htmlpagefooter name>`, `<sethtmlpageheader …>`, `@page { header: html_x; footer: html_y }` | named running headers/footers switched mid-document; `{PAGENO}`, `{nbpg}`, `{nb}`, `{DATE j-m-Y}`; `DefHTMLHeaderByName()`, `SetHTMLHeaderByName()`, `SetHTMLHeader/Footer($html, 'O'\|'E')` |
| `SetHeader('L\|C\|R')` / `SetFooter(...)` | strings and the array form |
| `<bookmark>`, `Bookmark()` | PDF outline (levels start at 0) |
| `<tocpagebreak>`, `<tocentry>`, `TOC_Entry()` | `toc-preHTML`, `toc-postHTML`, `toc-bookmarkText`, `toc-` page attributes; as in mPDF, a document with `<tocentry>` tags lists exactly those entries (headings are added only to documents without them) |
| `<indexentry>`, `<indexinsert>`, `IndexEntry()`, `InsertIndex()` | alphabetical index with page numbers, sorted with `Collator` (`bg_BG`, `en_US`…) or a built-in Bulgarian/Latin order |
| `<annotation>` | PDF sticky note |
| `<watermarktext>`, `<watermarkimage>`, `SetWatermarkImage()` | text / image watermark on every page |
| `<columns>` / `<columnbreak />` / `SetColumns()` | CSS multi-column (`column-count`, `column-gap`, `vAlign` / `vertical-align="justify"` balances the columns) |
| `mirrorMargins` (constructor key or `$mpdf->mirrorMargins = 1`) | even pages swap their left / right margins |
| `<dottab outdent="2em" />` | dotted leader up to the right edge, the text after it is right-aligned; `outdent` moves the tab stop that far left of the right edge, so the tail text ends there |
| `<textcircle r="30mm" top-text bottom-text space-width char-width divider style>` | text set along a circle, one rotated glyph per character; `font-size: auto` fits the circumference. A unitless `r` is read as millimetres; `char-width` scales every glyph horizontally around its own centre on the circle and also widens its advance (mPDF's exact scaling centre could not be verified offline, so this is the closest equivalent). `top` / `bottom` are accepted for `top-text` / `bottom-text`; a circle without text, with a radius that is no positive length or with a zero `space-width` / `char-width` draws nothing and says why in `getWarnings()` |
| CSS `float: footnote` (CSS GCPM, no mPDF tag; the tag `<footnote>..</footnote>` is accepted as an IvoPdf extension and behaves exactly like `float: footnote`) | footnote call (superscript counter) + note in a footnote area at the bottom of the page of the call; `::footnote-call` / `::footnote-marker` rules (declarations + `content` with `counter(footnote)`), numbering restarts per page with `@page { counter-reset: footnote }`; `::footnote-area { margin / padding / border* / background / font* / color / text-align / max-height }` styles the area (a declared `border*` replaces the built-in short rule, `max-height` in % of the page or a length replaces the default 40 % cap); `footnote-display: block \| inline \| compact` and `footnote-policy: auto \| line \| block` on the note element (a call whose note does not fit moves, with its line or its paragraph, to the next page; `auto` behaves like `line`). The layout is repeated up to 24 times until the pages of the calls settle; after eight passes pages stop shrinking their note room, and notes that still do not fit are left out with a warning rather than overlapping the text |
| `rotate`, `autosize`, `overflow="shrink"` on tables / images | rotation via `transform`; shrink-to-fit |
| `<barcode code type size error>` | see Barcodes below |
| `WriteHTML($html, $mode)`, `SetDefaultBodyCSS()`, `SetDirectionality()`, `SetCompression()`, `SetDisplayMode()`, `SetJS()`, `AddPage(...)`, `AddPageByArray()`, `SetBasePath()`, `SetDefaultFont()` | supported |
| `SetAutoPageBreak(true, 15)`, `PageNo()`, `DeletePages()`, `MovePages()`, `TOCpagebreak()`, `TOCpagebreakByArray()`, `TOC()`, `SetLeft/Right/TopMargin()`, `AddFontDirectory()`, `DefHeaderByName()` / `SetHeaderByName()`, `Annotation()`, `OutputFile()` / `OutputBinaryData()` / `OutputHttpInline()` / `OutputHttpDownload()`, `SetImportUse()` (no-op) | supported: `SetAutoPageBreak()` sets the bottom margin (as mPDF, `false` still breaks pages, with a warning), `PageNo()` / `$mpdf->page` return the page count of what was written so far: the first call renders, later calls lay out only the HTML written since then (exactly, from the last plain block before it; floats in the way, positioned boxes, CSS counters, columns, footnotes, a table of contents / index or mPDF tags with attributes make it lay out everything again), so loops of `WriteHTML()` + `PageNo()` stay fast; `DeletePages()` / `MovePages()` rearrange the output pages (links, bookmarks, notes and form fields follow) and `{PAGENO}` / `{nbpg}` count the pages that are output, as in mPDF |
| Imperative drawing: `Image()`, `WriteFixedPosHTML()`, `Text()` / `WriteText()`, `Line()`, `Rect()`, `Circle()`, `Ellipse()`, `RoundedRect()`, `Arrow()`, `Link()` / `AddLink()` / `SetLink()`, `Rotate()`, `StartTransform()` / `StopTransform()` / `transformRotate/Scale/Translate/Skew()`, `SetAlpha()`, `SetFont()` / `SetFontSize()`, `SetTextColor/FillColor/DrawColor()`, `SetLineWidth()`, `SetDash()`, `GetStringWidth()` | mPDF units (mm from the top-left corner) on the *current page*, i.e. the page where the HTML written so far ends (after `AddPage()` the new page): a zero-size marker is placed in the document and the drawing is painted on the marker's page, above the content |
| `Cell()`, `MultiCell()`, `Write()`, `Ln()`, `SetXY()` / `SetX()` / `SetY()` | after `SetXY()` / `SetY()` at that absolute position, moving it like mPDF; otherwise as HTML appended to the document (it flows with the content) |
| any other public `Mpdf` method | accepted by `__call()`: does nothing, returns `null` and adds one warning per method to `getWarnings()` (names that mPDF itself does not have still raise PHP's "undefined method" `Error`) |
| constructor keys | `mode`, `tempDir`, `autoScriptToLang`, `autoLangToFont`, `fontDir` + `fontdata`, `margin_header` / `margin_footer`, `showImageErrors`, `PDFA` (`true` = PDF/A-1b like mPDF, or `PDFAversion` `'2-B'` / `'3-B'`), `PDFX`, `restrictColorSpace`, `shrink_tables_to_fit`… |

Not supported: `<formfeed>`, `<tts>`, `<setpageheader>` / `<setpagefooter>` (use `SetHeader()` / `SetFooter()`); text written with the imperative API does not push the following HTML down (it is drawn over the page, like mPDF's fixed-position output).

## Options

| Option | Default | Description |
|---|---|---|
| `paperSize` | `'A4'` | A0–A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio or `[widthPt, heightPt]` |
| `orientation` | `'portrait'` | `portrait` / `landscape` |
| `margins` | 36 pt each | `['top' => .., 'right' => .., 'bottom' => .., 'left' => ..]`; CSS `@page { margin }` overrides |
| `defaultFont`, `defaultFontSize` | Inter, 12 | generic `sans-serif` maps to Inter; Arial / Helvetica, Times New Roman and Courier New to Liberation Sans / Serif / Mono; `serif` / `monospace` to DejaVu Serif / Sans Mono (through the dompdf API: Times / Courier, i.e. Liberation Serif / Mono). `new \Mpdf\Mpdf()` defaults to DejaVu Serif Condensed, 11 (mPDF) |
| `defaultBoxSizing` | `content-box` | CSS default; templates usually set `* { box-sizing: border-box }` |
| `basePath`, `allowedPaths` | `null`, `[]` | folders from which local images / CSS / fonts may be read |
| `isRemoteEnabled` | `false` | allow `http(s)` resources (SSRF-protected) |
| `maxRemoteBytes`, `maxImagePixels` | 10 MB, 25 MP | resource limits; an image is also skipped (with a warning, the rendering goes on) when decoding it would not fit the memory still left: the decoded size is estimated from the header before any pixel is decoded, so a small "decompression bomb" file cannot exhaust `memory_limit` |
| `maxHtmlBytes`, `maxNestingDepth`, `maxWordLength`, `maxPages` | 32 MB, 200, 2000, 5000 | failure guards |
| `pageOverflowWrap` | `true` | a word (or a `white-space: pre` / `nowrap` line) wider than its box overflows the box as in browsers, but never the page: where its glyphs would cross the right edge of the page's content area (the left edge in right-to-left text) it is broken at a character boundary and continues on the next line; table columns widen to the longest word only up to the page edge; running headers / footers break such words at the edge of their area. `@page` margin boxes clip content taller than their margin area. `getWarnings()` says how many words / lines were broken. `false` = the browser behaviour everywhere: the part beyond the page is lost, and a warning says how many pieces of text were cut |
| `maxRenderTime` | `120` | seconds one `render()` / `output()` call may take (parse, layout, pagination, painting, including the extra layout passes of CSS footnotes, mPDF indexes and `NEXT-ODD` breaks); when it runs out a catchable `IvoPdf\Exception\LimitExceededException` (a `RuntimeException`, `getLimit() === 'time'`) is thrown. `0` = unlimited |
| `maxMemory` | `'auto'` | memory one rendering may use: `'auto'` (90 % of PHP's `memory_limit`), a string such as `'256M'` / `'1G'`, or bytes (values under 1 MB, negative numbers and strings that are no size are ignored - the `'auto'` budget applies - with a warning in `getWarnings()`). Exceeding it throws `LimitExceededException` (`getLimit() === 'memory'`); with a PHP `memory_limit` set the budget never exceeds 90 % of it, so the exception comes before PHP's uncatchable fatal error. Memory is measured as PHP's allocator counts it against `memory_limit` (`memory_get_usage(true)`); steps that allocate a lot in one call are checked before they start: the HTML parser refuses input whose tree would not fit (about 1 KB per tag on PHP ≥ 8.4) and images are skipped as described for `maxImagePixels`. `0` = off (PHP's `memory_limit` is still respected by these pre-checks) |
| `maxBarcodeLength` | `5000` | longest `<barcode>` value; longer ones are skipped with a warning (`0` = unlimited) |
| `html5Parser` | `true` | WHATWG HTML5 parser (PHP ≥ 8.4); `false` uses libxml's HTML4 parser. On the libxml path (PHP < 8.4) the source is first brought closer to HTML5: character references without a semicolon (`&nbsp`, `&amp`, `&copy`, the HTML5 legacy list) and HTML5-only names, unknown references kept as text, `<![CDATA[..]]>` outside SVG dropped, `</br>`, `<section>`/`<article>`/... closing an open `<p>`, a `<form>` around table rows, `<td>` directly in `<table>` |
| `inputEncoding` | `'auto'` | `auto`: BOM, then `<meta charset>`, then valid UTF-8; legacy bytes without a meta are read as Windows-1251 when they look Cyrillic, otherwise Windows-1252. Set it explicitly (e.g. `'ISO-8859-2'`, `'KOI8-R'`, `'Shift_JIS'`) for other legacy files without a meta |
| `shrinkTablesToFit` | `1.4` | maximum shrink factor for too-wide tables (`0` = off) |
| `subsetFonts` | `true` | embed only used glyphs |
| `fallbackFonts`, `scriptFonts` | `[]` | extra families for missing glyphs; font per script (`['Hani' => 'Noto Sans SC', 'Deva' => 'Noto Sans Devanagari']`) |
| `bookmarks` | `true` | PDF outline from headings |
| `formFields` | `true` | interactive form fields; `false` draws controls as static graphics |
| `pdfa`, `pdfaIccProfile` | `''`, `''` | `'1b'`, `'2b'`, `'3b'`; `pdfaIccProfile` (mPDF `ICCProfile`): a CMYK ICC output profile (ICC v2 for PDF/A-1) that becomes the PDF/A output intent; the document is then written in CMYK |
| `pdfx`, `pdfxOutputCondition`, `pdfxIccProfile` | `''`, `FOGRA39`, `''` | `'X-1a'`, `'X-3'`, `'X-4'`; an image that cannot be converted (corrupt pixel data, or a JPEG whose bitmap does not fit the memory left) is left out with a warning, so the file stays conforming |
| `colorSpace` | `'rgb'` | `'cmyk'` converts all colors and images to CMYK. Images are converted (and, for PDF/X-1a / X-3 and PDF/A-1b, composited onto white) row by row, so large images and decompression bombs stay within the memory budget; a JPEG is decoded by GD as a whole and is kept in RGB with a warning when its bitmap would not fit the memory left |
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

The language of the text (`<html lang="bg">`, `lang` on any element) selects the font's localized letterforms (OpenType `locl`), as browsers do. For Bulgarian typography use the bundled Sofia Sans, which draws Bulgarian Cyrillic forms by default (and Russian forms under `lang="ru"`); Semi Condensed fits more text per line:

```css
body { font-family: 'Sofia Sans', sans-serif; }
table, .compact { font-family: 'Sofia Sans Semi Condensed', sans-serif; }
```

`lang="sr"` / `lang="mk"` likewise give Serbian / Macedonian forms in DejaVu and Liberation. Inter has no Bulgarian forms.

`registerFontFamily()` checks its files: a missing or unreadable file (or a UNC / URL path) throws an `InvalidArgumentException` naming it, instead of silently falling back to DejaVu Sans; a font that cannot be parsed at render time is replaced by DejaVu Sans with a warning. Only `regular` is required: when a family has no bold or italic face, bold is synthesized by stroking the glyph outlines and italic by slanting them 14° (as browsers do), without a warning.

CSS `@font-face` works with `.ttf` / `.otf` sources (data URI, allowed local path, or remote when enabled); data URI and downloaded fonts are kept in memory, no temporary files are written. Asian and Indic scripts need fonts that are not bundled (for example Google's Noto fonts, OFL-licensed):

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

HTML `<input type="text|email|number|date|password|checkbox|radio">`, `<textarea>`, `<select>` and `<button>` become fillable PDF fields with their current values (Cyrillic included). The `placeholder` of an empty field is printed greyed, as browsers show it; it is page content, not the field's value, and the field gets an opaque background (`/MK /BG`) so a typed value covers it. Radios with the same name form one group; `required`, `readonly` and `disabled` set the field flags; a form with an `http(s)` `action` gets a submit button.

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
- An unreadable certificate, key or `.p12` bundle (or a wrong key password) throws an `InvalidArgumentException` with OpenSSL's reason in the message; PHP itself emits no warning.
- PDF/A-1b is real ISO 19005-1 output (PDF 1.4, `/CIDSet`, no transparency: semi-transparent colors become opaque, soft-masked images are composited onto white row by row, SVG masks are removed); 2b and 3b keep transparency. `validatePdfA()` is a heuristic pre-check, **not** a replacement for veraPDF.
- At every PDF/A level images are written with `/Interpolate false`, and embedded font subsets keep a width entry for every used glyph, so the program always matches `/W`. `validatePdfA()` checks both for TrueType fonts; for OpenType/CFF (`.otf`) fonts it does not compare glyph widths.
- CMYK in PDF/A: a device CMYK colour needs a CMYK output intent (ISO 19005-1 6.2.3.3, 19005-2/3 6.2.4.3). With the default sRGB intent, CSS `cmyk()` / `device-cmyk()`, spot colours, CMYK JPEGs and `colorSpace => 'cmyk'` (mPDF `restrictColorSpace => 3`) are written as their RGB equivalent, as mPDF's `PDFAauto` does, and a warning says so; with `pdfaIccProfile` (a CMYK profile) the output intent is that profile and everything is CMYK. Both pass veraPDF 1b / 2b / 3b.
- PDF/A cannot be combined with encryption; PDF/X cannot be combined with PDF/A, encryption or a signature.
- Passwords: AES-256 (the default, ISO 32000-2) takes any Unicode password. AES-128 and RC4 (`'aes128'`, `'rc4'`, mPDF's `SetProtection(…, 128)`) are defined on PDFDocEncoding bytes (ISO 32000-1 7.6.3.3, Algorithm 2): IvoPDF converts the password the way Acrobat, qpdf and MuPDF do with a typed one (`'ümlaut'` and `'€'` work in every reader). A password with characters outside PDFDocEncoding (Cyrillic, Greek, CJK…) could not be typed into any reader for these methods, so — like qpdf — `setProtection()` throws an `InvalidArgumentException` that recommends `'aes256'`.
- Under PDF/A every `<annotation>` (sticky note) gets an appearance stream (and is opaque in PDF/A-1), so annotated documents pass veraPDF 1b/2b/3b. Form fields still use the non-embedded standard fonts and `/NeedAppearances`: the render adds a warning, `validatePdfA()` reports it, and `formFields => false` prints the fields as static content that validates.
- Local files are only read from plain local paths: UNC and protocol-relative paths (`\\host\share\x.png`, `//host/x.png`, `\\?\UNC\…`) and stream wrappers (`file://`, `ftp://`, `phar://`…) never reach the filesystem functions (on Windows they would open an SMB session to the host). `//host/x.png` in HTML / CSS is a protocol-relative URL: it is fetched as `https://host/x.png` when `isRemoteEnabled` is on (SSRF guard included) and ignored otherwise; `loadHtmlFile()`, `appendPdf()`, `setPageTemplate()`, `registerFontFamily()`, the PDF/X ICC profile and dompdf's `loadHtmlFile()` refuse such paths with an exception.

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

HTML5 parsing (PHP ≥ 8.4), shrink-to-fit tables, limits for oversized input, and linear scaling for long documents (roughly 20 ms per page of body text; 500 pages in about 10 s). Pagination is near-linear: a page break owes its shift to the following boxes lazily (40 000 headings: 11 s instead of 67 s). The neighbouring words of a line that draw alike share one text run, so the layout tree (kept for `getLayoutTree()`) needs about a quarter less memory (200 invoice pages: 85 MB peak instead of 114 MB). See the options above.

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
- RTL does not mirror flex and grid layouts (tables are laid out right to left).
- When `min-width` is larger than `max-width`, `min-width` wins (as in CSS and browsers).
- PDF/A output (1b, 2b, 3b) is validated with veraPDF in development; `validatePdfA()` itself is a structural check. PDF/X output is checked structurally, not with a commercial preflight tool.
- HTML form fields use the non-embedded standard fonts Helvetica / ZapfDingbats, so documents with forms do not pass PDF/A.
- `.ttc` faces are not supported in CSS `@font-face` (use `registerFontFamily()`).
- mPDF's imperative API draws over the page (see the mPDF table above): `Cell()` / `Write()` without `SetXY()` become HTML, `SetLink()` ignores its `$page`, `Rotate()` and the `transform*()` helpers use the default page height for their pivot when page sizes vary.
- Text extraction: characters drawn without a font (.notdef) and text-shadow copies are marked with `/ActualText` / `/Artifact`, which Acrobat, MuPDF and PDFium honour; pdfminer and pypdf ignore marked content (they show `(cid:0)` / the shadow text twice). Right-to-left lines are written like Chrome / Edge write them (glyphs in visual order, one text object per direction, spaces beside right-to-left text drawn as glyphs, lam-alef mapped for the reversal, mirrored brackets with their logical character as `/ActualText` where the extractor reverses them with right-to-left letters of the same font; a bracket alone in its font, e.g. beside Arabic set in a fallback font, keeps its drawn character, which extractors leave in visual order), so bidi-aware extractors (MuPDF, PDFium, Acrobat) give Hebrew and Arabic text in logical order wherever they do for an Edge PDF; numbers and Latin words inside right-to-left text come out as their heuristics place them (as for Edge PDFs), and pdfminer / pypdf show the visual order.
- An inline-block taller than the rest of the page is split between two of its lines at a page break (no line is cut in two); Edge cuts the line at the page edge in half. Boxes that continue on the next page draw their border at both page edges.
- A right-to-left word whose letters come from a fallback font keeps its inner punctuation (the quote of דוא"ל, a hyphen) in that font when the font has it, so the word stays one run and extracts in logical order; the punctuation glyph can then differ slightly from the one Edge takes from the requested font.
- Footnotes: a call inside a table cell or a multi-column container is placed on the page of its line; a single note taller than the room of a page (`::footnote-area { max-height }`) is clipped with a warning; a note is never continued on the next page.
- dompdf `Canvas`: `add_object($id, 'all')` after `render()` reaches only the last page (as in dompdf); `begin_page_render` drawing is painted before the page content, so an opaque `<html>` / `<body>` background covers it (as in dompdf).
- A word wider than its box overflows the box on its own line (`overflow-wrap: normal`), as in browsers and dompdf (mPDF breaks it); `overflow-wrap: anywhere | break-word` (also the legacy `word-wrap: break-word`) and `word-break: break-all | break-word` break it inside the box. Unlike a browser window, a page cannot scroll: with `Options::$pageOverflowWrap` (default) a word or a `white-space: pre` / `nowrap` line whose glyphs would cross the edge of the page's content area (right; left in right-to-left text) is broken there at a character boundary (grapheme cluster), continues on the next line, and one warning per document counts the broken words / lines. This applies to the main document, including table cells, inline-blocks, flex / grid items and positioned boxes; text inside a box with `overflow: hidden | clip | auto | scroll` (the author's own clipping) or a `transform` is left alone, and running headers / footers and `@page` margin boxes keep the browser behaviour. A document needing such a break may be laid out up to four times when the text sits in a box positioned after its layout (inline-block, flex item, ...). Table columns widen to the longest word instead, but only up to the page edge (the widest columns are then narrowed and break their words at the cell edge); with `word-break: break-all | break-word`, `overflow-wrap: anywhere` or `line-break: anywhere` the min-content width is one character, so a long URL wraps inside its column (`overflow-wrap: break-word` does not lower min-content, CSS Text 3). An image counts in table columns, floats, inline-blocks and flex items with its used size (`width` / `height`, the attributes, aspect ratio, `min-*` / `max-*`); a percentage `width` / `max-width` makes it compressible (min-content 0), as in browsers.
- Text without any break opportunity longer than `Options::$maxWordLength` (2000 characters by default, `0` = off) is split with a warning; with the guard off such a word is still laid out in linear time and memory.
- Absurd lengths from untrusted input are clamped to 14 400 pt (200 in, the largest page a PDF can describe): font sizes, border widths, padding, line heights and image sizes, so `font-size: 1e9pt` or `<img width="1e9">` produce a few dozen pages instead of 5 000. Margins and box heights are not clamped.

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
- Bundled third-party data keeps its own permissive license: Inter fonts (SIL Open Font License 1.1, [resources/fonts/LICENSE-Inter.txt](resources/fonts/LICENSE-Inter.txt)), Liberation fonts (SIL Open Font License 1.1, [resources/fonts/LICENSE-Liberation.txt](resources/fonts/LICENSE-Liberation.txt)), Sofia Sans fonts by Lettersoup (SIL Open Font License 1.1, [resources/fonts/LICENSE-SofiaSans.txt](resources/fonts/LICENSE-SofiaSans.txt); static instances of the variable fonts), DejaVu fonts ([resources/fonts/LICENSE-DejaVu.txt](resources/fonts/LICENSE-DejaVu.txt)) and the TeX hyphenation patterns (license headers in `resources/hyphenation/`).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Contributors sign the [CLA](CLA.md) once, on their first pull request.
