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
| `maxRemoteBytes` / `maxImagePixels` | 10 MB / 25 MP | |
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

- Bundled: Inter (default) and DejaVu Sans / Serif / Sans Mono, each with regular, bold, italic, bold italic.
- Aliases: `sans-serif`, Arial, Helvetica, Roboto, Open Sans… → Inter; `serif`, Times, Georgia… → DejaVu Serif; `monospace`, Courier, Consolas… → DejaVu Sans Mono.
- Custom: `$pdf->registerFontFamily('Inter', ['regular' => '/fonts/Inter-Regular.ttf', 'bold' => '/fonts/Inter-Bold.ttf']);` then `font-family: 'Inter'`.
- CSS: `@font-face { font-family: 'Brand'; src: url(fonts/Brand.ttf); font-weight: bold; }` — the file must be inside `basePath`/`allowedPaths` (or a data URI / remote URL with `isRemoteEnabled`).
- Use TrueType (`.ttf`). Invalid font files are rejected and reported in `getWarnings()`.

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

Notes: dompdf's inline `<script type="text/php">` page scripts are not supported — use `setFooterHtml()` with `{PAGE_NUM}`/`{PAGE_COUNT}` or CSS counters. dompdf's `Options` object maps by key name. Flexbox/Grid layouts that were faked with tables in dompdf can now be written natively.

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

`<pagebreak>` (with orientation/sheet-size/margins/header names), `<htmlpageheader>`/`<sethtmlpageheader>`, `<bookmark>`, `<tocpagebreak>`/`<tocentry>`, `<indexentry>`/`<indexinsert>`, `<annotation>`, `<watermarktext>`/`<watermarkimage>`, `<columns>`/`<columnbreak>`, `<barcode>` and `SetProtection()` work. `new \Mpdf\Mpdf()` resolves to IvoPDF when real mPDF is not installed. Not available: the imperative drawing API (`Cell`, `Rect`, `SetFont`…) and `mirrorMargins`.

## 7. Advanced features

| Need | API |
|---|---|
| Password / permissions | `setProtection(['print','copy'], $userPwd, $ownerPwd, 'aes256')` |
| Digital signature | `setSignature($certPemOrPath, $keyPemOrPath, $keyPwd, ['Name'=>…,'Reason'=>…], [page, x, y, w, h])` |
| Archiving | `setPdfA('1b'\|'2b'\|'3b')`, `validatePdfA()` (heuristic) |
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

