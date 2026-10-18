# IvoPDF API reference

## Contents
1. Constructor options
2. Methods
3. Fonts
4. Header, footer, watermark
5. dompdf → IvoPDF
6. mPDF → IvoPDF
7. Advanced features (security, PDF/A, print, attachments, TOC, forms, barcodes)

## 1. Constructor options

`new IvoPdf(array|Options $options)` — unknown keys are ignored.

| Option | Default | Notes |
|---|---|---|
| `paperSize` | `'A4'` | `A0`–`A6`, `B4`, `B5`, `LETTER`, `LEGAL`, `TABLOID`, `EXECUTIVE`, `FOLIO`, or `[wPt, hPt]` |
| `orientation` | `'portrait'` | `landscape` swaps width/height |
| `margins` | 36 pt each | `['top'=>…, 'right'=>…, 'bottom'=>…, 'left'=>…]` in pt; CSS `@page { margin }` wins |
| `defaultFont` / `defaultFontSize` | `Inter` / 12 | |
| `defaultBoxSizing` | `content-box` | |
| `dpi` | 96 | px → pt conversion |
| `basePath` | `null` | folder for relative local resources |
| `allowedPaths` | `[]` | extra folders local files may be read from |
| `isRemoteEnabled` | `false` | allow http(s) images/CSS/fonts (SSRF-protected, TLS verified, no redirects) |
| `maxRemoteBytes` / `maxImagePixels` | 10 MB / 25 MP | images that would not fit the memory left are skipped with a warning |
| `maxRenderTime` | 120 | seconds per `render()`/`output()` (all layout passes); `0` = unlimited |
| `maxMemory` | `'auto'` | 90 % of `memory_limit`, or `'256M'` / bytes, `0` = off; both limits throw `IvoPdf\Exception\LimitExceededException` (`getLimit()` = `'time'` / `'memory'`) |
| `maxHtmlBytes` / `maxNestingDepth` / `maxWordLength` / `maxPages` / `maxBarcodeLength` | 32 MB / 200 / 2000 / 5000 / 5000 | input guards |
| `pageOverflowWrap` | `true` | a word / `pre` line that would cross the page edge is broken there (one warning counts them); `false` = browser behaviour, the rest is lost (with a warning) |
| `inputEncoding` | `'auto'` | BOM → `<meta charset>` → UTF-8 → Windows-1251 (Cyrillic-looking) / Windows-1252; or an explicit charset |
| `html5Parser` | `true` | WHATWG parser on PHP ≥ 8.4; on PHP < 8.4 libxml with HTML5 normalisation |
| `shrinkTablesToFit` | 1.4 | max shrink factor for too-wide tables, `0` = off |
| `scriptFonts` | `[]` | font per script, e.g. `['Hani' => 'Noto Sans SC']` |
| `formFields` | `true` | `false` draws form controls as static graphics (needed for PDF/A) |
| `pdfa`, `pdfx`, `colorSpace`, `bleed`, `cropMarks` | | see section 7 |
| `subsetFonts` | `true` | embed only used glyphs |
| `bookmarks` | `true` | outline from h1–h6 |
| `fallbackFonts` | `[]` | registered families tried for missing glyphs |
| `compress` | `true` | |
| `title`, `author`, `subject`, `keywords`, `creator`, `lang` | | metadata; `title`/`lang` default to `<title>` / `<html lang>` |
| `watermarkText`, `watermarkOpacity` | `''`, `0.08` | |

Compatibility keys: `format` (`'A4'`, `'A4-L'`, `[w,h]` in mm), `orientation` `'P'`/`'L'`, `margin_top|right|bottom|left` (mm), `default_font`, `default_font_size`, `chroot` (→ `allowedPaths`), `enable_remote`, `defaultPaperSize`, `defaultPaperOrientation`.

## 2. Methods

| Method | Purpose |
|---|---|
| `loadHtml(string $html, ?string $basePath = null)` | set the document (replaces previous) |
| `loadHtmlFile(string $path)` | load from disk (respects `allowedPaths`/`basePath`), sets basePath |
| `WriteHTML(string $html)` | append HTML (mPDF style) |
| `setPaper($size, $orientation)` | |
| `setMargins($top, $right = null, $bottom = null, $left = null)` | pt |
| `setHeaderHtml(?string)`, `setFooterHtml(?string)` | running header/footer, `{PAGE_NUM}`, `{PAGE_COUNT}` |
| `setWatermarkText(string $text, float $opacity = 0.08)` | diagonal text on every page |
| `registerFontFamily(string $name, ['regular'=>…, 'bold'=>…, 'italic'=>…, 'bold_italic'=>…])` | TTF paths |
| `addFallbackFont(string $family)` | try this family for glyphs missing from the requested font |
| `render()` | lay out + build PDF (called automatically by output/save/stream) |
| `output()` | PDF bytes; `output($name, 'S'|'F'|'D'|'I')` mPDF style |
| `save(string $path)` | write file (creates folder) |
| `stream(string $filename, ['Attachment' => bool])` | send to browser with headers (clears output buffers) |
| `getPageCount()`, `getWarnings()`, `getLayoutTree()`, `getOptions()`, `getFontManager()` | inspection |
| `SetHTMLHeader`, `SetHTMLFooter`, `AddPage`, `showWatermarkText`, `SetTitle`, `SetAuthor`, `SetSubject`, `SetKeywords`, `SetCreator` | mPDF compatibility |

PHP method names are case-insensitive: `Output()` and `output()` are the same method, as are `setWatermarkText()` and `SetWatermarkText()`.

## 3. Fonts

- Bundled (regular, bold, italic, bold italic each): Inter (default), Liberation Sans / Serif / Mono, Sofia Sans, Sofia Sans Semi Condensed, DejaVu Sans / Serif / Sans Mono.
- Aliases: `sans-serif`, Roboto, Open Sans… → Inter; Arial, Helvetica → Liberation Sans; Times, Times New Roman → Liberation Serif; Courier, Courier New → Liberation Mono (metric-compatible: same line breaks as Word/browsers); `serif` → DejaVu Serif; `monospace`, Consolas… → DejaVu Sans Mono.
- Language: `lang` (on `<html>` or any element) selects localized letterforms (OpenType `locl`): Sofia Sans draws Bulgarian Cyrillic by default and Russian forms under `lang="ru"`; `sr`/`mk` select Serbian/Macedonian forms in DejaVu and Liberation. Inter has no Bulgarian forms.
- A family without a bold or italic face gets synthesized bold (stroke) / oblique (slant), like browsers.
- Custom: `$pdf->registerFontFamily('Inter', ['regular' => '/fonts/Inter-Regular.ttf', 'bold' => '/fonts/Inter-Bold.ttf']);` then `font-family: 'Inter'`.
- CSS: `@font-face { font-family: 'Brand'; src: url(fonts/Brand.ttf); font-weight: bold; }` — the file must be inside `basePath`/`allowedPaths` (or a data URI / remote URL with `isRemoteEnabled`).
- Use TrueType/OpenType (`.ttf`, `.otf`, `.ttc#index`). `registerFontFamily()` throws `InvalidArgumentException` for a missing/unreadable file; an invalid `@font-face` font is reported in `getWarnings()` and falls back to DejaVu Sans. `data:` and downloaded fonts stay in memory (no temp files).

## 4. Header, footer, watermark

Header/footer are separate small HTML documents (their own `<style>` allowed) laid out per page with the real numbers substituted, so right-aligned "Page 3 of 12" lines up exactly. Space is reserved automatically from the margin. Alternatives: an element with `position: fixed` repeats on every page; CSS `counter(page)` / `counter(pages)` in `content`.

## 5. dompdf → IvoPDF

```php
// before
$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'chroot' => __DIR__]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('doc.pdf', ['Attachment' => false]);

// after
$pdf = new \IvoPdf\IvoPdf(['isRemoteEnabled' => true, 'chroot' => __DIR__]);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream('doc.pdf', ['Attachment' => false]);
```

Existing dompdf code also works unchanged: `use Dompdf\Dompdf; use Dompdf\Options;` resolve to IvoPDF when real dompdf is not installed (`Options` setters/getters, `setPaper`, `loadHtml($html, $encoding)`, `loadHtmlFile` with `chroot`, `render`, `output(['compress' => 0])`, `stream`, `getDom()`, `setCallbacks()`). `$dompdf->getCanvas()` offers the CPDF adapter API: `page_text()` with `{PAGE_NUM}`/`{PAGE_COUNT}`, `page_script(callable)`, `page_line()`, `text()`, `line()`, `rectangle()`, `filled_rectangle()`, `circle()`, `arc()`, `polygon()`, `image()`, `add_link()`, `add_named_dest()`, clipping, transforms, `set_opacity()`, `save()`/`restore()`, objects (`open_object`/`add_object`), `new_page()`, `set_page_number()`; `getFontMetrics()` offers `getFont()`, `getTextWidth()`, `registerFont()`. Unknown Canvas/Dompdf methods add a warning instead of a fatal error.

Notes: dompdf's inline `<script type="text/php">` is never executed (one warning) — use `page_script(callable)`, `setFooterHtml()` with `{PAGE_NUM}`/`{PAGE_COUNT}` or CSS counters. Flexbox/Grid layouts that were faked with tables in dompdf can now be written natively.

## 6. mPDF → IvoPDF

```php
// before
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15, 'margin_right' => 15]);
$mpdf->SetHTMLFooter('<div style="text-align:right">{PAGENO}/{nbpg}</div>');
$mpdf->WriteHTML($html);
$mpdf->Output('doc.pdf', 'D');

// after
$pdf = new \IvoPdf\IvoPdf(['format' => 'A4', 'margin_left' => 15, 'margin_right' => 15]);
$pdf->SetHTMLFooter('<div style="text-align:right">{PAGENO}/{nbpg}</div>');
$pdf->WriteHTML($html);
$pdf->output('doc.pdf', 'D');
```

`<pagebreak>` (with orientation/sheet-size/margins/header names/`NEXT-ODD`), `<htmlpageheader>`/`<sethtmlpageheader>`, `SetHeader('L|C|R')`, `<bookmark>`, `<tocpagebreak>`/`<tocentry>` (a document with `<tocentry>` lists only those), `<indexentry>`/`<indexinsert>`, `<annotation>`, `<watermarktext>`/`<watermarkimage>`, `<columns>`/`<columnbreak>`, `<dottab>`, `<textcircle>`, `<barcode>`, `mirrorMargins`, `SetProtection()` work; CSS `float: footnote` (and the `<footnote>` tag as an extension) gives real footnotes. `new \Mpdf\Mpdf()` resolves to IvoPDF when real mPDF is not installed.

The imperative API exists too: `SetAutoPageBreak`, `PageNo`, `DeletePages`/`MovePages`, `TOCpagebreak`, `SetFont`/`SetFontSize`, colours, `SetLineWidth`, `Image`, `WriteFixedPosHTML`, `Text`/`WriteText`, `Line`/`Rect`/`Circle`/`Ellipse`/`RoundedRect`/`Arrow`, `Link`, `Rotate`/`transform*`, `Cell`/`MultiCell`/`Write`/`Ln` with `SetXY` (drawn over the page at that position; without `SetXY` they flow as HTML). Any other mPDF method is accepted with a warning instead of a fatal error.

## 7. Advanced features

| Need | API |
|---|---|
| Password / permissions | `setProtection(['print','copy'], $userPwd, $ownerPwd, 'aes256')`; `aes128`/`rc4` accept only Latin-1/PDFDocEncoding passwords (Cyrillic → use `aes256`) |
| Digital signature | `setSignature($certPemOrPath, $keyPemOrPath, $keyPwd, ['Name'=>…,'Reason'=>…], [page, x, y, w, h])` |
| Archiving | `setPdfA('1b'\|'2b'\|'3b')`, `validatePdfA()` (structural check; outputs were validated with veraPDF); use `formFields => false` for documents with forms |
| Print | `setPdfX('X-1a'\|'X-3'\|'X-4')`, `setColorSpace('cmyk')`, `setBleed($mm, true)`, `addSpotColor($name, c, m, y, k)`; CSS `cmyk()`, `spot(NAME, 80%)` |
| E-invoice | `setPdfA('3b')->attachFacturX($xml, 'EN 16931')` |
| Attachments | `attachFile($pathOrBytes, $name, $desc, $mime)` |
| Existing PDFs | `setPageTemplate($pdf)` (letterhead), `appendPdf($pdf, 'all')`, `importPdfPages()` |
| TOC | `<toc />`, `setTableOfContents(true, 'Contents')`, CSS `target-counter(attr(href), page)` + `leader('.')` |
| Headers per page | `setHeaderHtml($html, 'first'\|'odd'\|'even')`, `{DATE}`, `{TITLE}`; CSS `@page :first`, margin boxes `@top-center` |
| Forms | plain HTML `<input>`, `<select>`, `<textarea>`, `<button>`; option `formFields => false` for static output |
| Barcodes | `<barcode type="QR\|DATAMATRIX\|PDF417\|C128\|EAN13\|…" value="…" />`, `IvoPdf\Widget\Barcode::svg()` |
| Fonts per script | option `scriptFonts`, `registerFontFamily('Noto CJK', ['regular' => 'NotoSansCJK.ttc#0'])` |
| Watermark | `setWatermarkText('DRAFT', 0.1)`, `<watermarkimage src=… />` |

