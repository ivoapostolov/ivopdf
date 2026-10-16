# IvoPDF — HTML & CSS към PDF на чист PHP

[English](README.md) · **Български**

IvoPDF превръща HTML + CSS в PDF **без външни програми** (без Chrome, Node.js или wkhtmltopdf). Работи навсякъде, където работи PHP, включително на споделен хостинг. Проектиран е като директен заместител на **dompdf** и **mPDF** и добавя истински **CSS Flexbox**, **CSS Grid**, цифров подпис и поддръжка на е-фактури (Factur-X).

Изисквания: PHP ≥ 8.1 с `ext-dom`, `ext-mbstring`, `ext-zlib`. По желание: `ext-gd` (GIF, WebP, палитрови PNG, филтри за изображения), `ext-openssl` (криптиране, подписи), `ext-intl` (пренасяне на тайландски и кхмерски, сортиране на показалец). С PHP ≥ 8.4 се включва HTML5 парсерът.

## Възможности

**Оформление и CSS**
- Block, inline, inline-block, плаващи елементи, позициониране absolute / fixed / relative, `z-index`, **Flexbox**, **CSS Grid** (`fr`, `repeat()`, `minmax()`, `auto-fit/fill`, span, автоматично разполагане), **колони** (`column-count`, `column-width`, `column-rule`, `column-span`, балансиране през страници).
- Таблици: `thead/tbody/tfoot`, `colspan`/`rowspan`, `<caption>`, ширини от `<col>`, `table-layout: fixed`, слети рамки, заглавен ред, повтарян на всяка страница, редове, които никога не се разрязват, свиване на твърде широки таблици (като `shrink_tables_to_fit` в mPDF).
- Пълен механизъм за селектори (`:is()`, `:where()`, `:has()`, `:nth-*()`, атрибутни оператори…), специфичност и `!important`, `var()`, `calc()`, `inherit`/`initial`/`unset`, `@media` (print, ширина, ориентация), `@supports`, `@import`, `@font-face`, `::before`/`::after` с `content`, CSS броячи, височини в проценти, `aspect-ratio`, `display: contents`.
- HTML5 парсване по алгоритъма на WHATWG (PHP ≥ 8.4).

**Текст и шрифтове**
- UTF-8 навсякъде; вградени **Inter** (шрифт по подразбиране, пълна кирилица) и DejaVu Sans/Serif/Mono (латиница, кирилица, гръцки, арабски, иврит…; ползват се автоматично и за знаци, които липсват в Inter).
- **Изрязване на шрифтовете** (типична фактура е ≈ 40 KB вместо ~900 KB), TrueType и **OpenType/CFF (`.otf`)**, колекции `.ttc`, `@font-face`, автоматични **резервни шрифтове** и избор на шрифт според писмеността.
- **OpenType оформяне**: лигатури, кернинг, `font-feature-settings`, `font-variant-*`.
- **От дясно наляво** (Unicode bidi алгоритъм, `dir="rtl|auto"`, `<bdi>`, `<bdo>`), свързване на **арабски**, **иврит**.
- Пренасяне на **китайски, японски и корейски** (kinsoku, `word-break`, `line-break`), оформяне на **индийски писмености** (деванагари, бенгалски, гуджарати, гурмукхи, тамилски…), пренасяне на **тайландски, лаоски и кхмерски** по думи.
- **Пренасяне с тире**: `&shy;` и `hyphens: auto` за български и английски (TeX шаблони).
- Правилно пренасяне между тагове, `&nbsp;`, двустранно подравняване, `text-overflow: ellipsis`, `text-shadow`, подчертаване, непрекъснат фон на многословни inline елементи.

**Графика и изображения**
- `border-radius`, всички стилове рамки (double, groove, ridge, inset, outset), `border-image`, `outline`, няколко `box-shadow`, `opacity` (истинска групова прозрачност), `mix-blend-mode`, `clip-path`, филтри за изображения, списъци с `transform` и `transform-origin`.
- `linear-gradient()` / `radial-gradient()` като вградени PDF shading-и, `background-size / position / repeat`, SVG като фон.
- Изображения: JPEG (вкл. CMYK и завъртане по EXIF), PNG (прозрачност, палитра, interlaced, 16 бита), GIF, WebP, BMP, TIFF, SVG, WMF, EMF; `object-fit` / `object-position`.
- SVG: всички фигури и команди за пътища, текст, `transform`, `viewBox`, истински **градиенти**, **шарки**, **изрязване**, **маски**, **стрелки (marker)**, `<use>`, `<style>`, `<image>`.

**Документи**
- **Отметки (bookmarks)** от заглавията, кликаеми външни и вътрешни (`#id`) линкове, **съдържание** с номера на страници (`<toc />`, `target-counter()`, точки до номера), **азбучен показалец** като в mPDF.
- Горен и долен колонтитул (за първа / нечетна / четна страница), `@page :first / :left / :right`, **именувани страници** (смесване на портрет и пейзаж), **колонтитули в полетата** (`@top-center`, `@left-middle`…), `string-set`, `position: running()`.
- **Попълваеми PDF формуляри** (AcroForm) от HTML `<input>`, `<textarea>`, `<select>`, `<button>`.
- **Баркодове**: QR (вкл. кирилица), DataMatrix, PDF417, Code 128/39/93, EAN-13/8, UPC-A/E, ISBN, ISSN, Interleaved 2 of 5, Codabar.
- **Сигурност**: криптиране с пароли и права (AES-256, AES-128, RC4), **цифров подпис** (CMS, съвместим с PAdES, видим или невидим).
- **Архивиране и печат**: **PDF/A-1b, 2b, 3b**, **PDF/X-1a, X-3, X-4**, CMYK цветове, spot (Pantone) цветове, bleed и маркери за рязане.
- **Прикачени файлове** и е-фактури **Factur-X / ZUGFeRD**; **вграждане на страници** от съществуващи PDF файлове (бланка като фон, сливане).
- Водни знаци (текст и изображение), бележки (sticky notes), метаданни и език на документа.

**Сигурен по подразбиране**
- Отдалеченото зареждане е изключено; локалните файлове се четат само от `basePath` / `allowedPaths` (без path traversal, без `phar://` или `file://`).
- Защита от SSRF: блокирани частни и резервирани IPv4/IPv6 адреси, фиксиране на проверения IP, проверка на TLS, без пренасочвания, лимит на размера.
- Лимити за размера на HTML, дълбочината на влагане, дължината на думите, броя страници и пикселите на изображенията; некритичните проблеми се връщат чрез `getWarnings()`.
- `<script type="text/php">` от dompdf нарочно **не** се поддържа.

## Бърз старт

```php
require_once __DIR__ . '/autoload.php';      // или: require 'vendor/autoload.php';

use IvoPdf\IvoPdf;

$pdf = new IvoPdf(['paperSize' => 'A4', 'orientation' => 'portrait']);
$pdf->setFooterHtml('<div style="text-align:right; font-size:9pt">Страница {PAGE_NUM} от {PAGE_COUNT}</div>');
$pdf->loadHtml('<h1>Фактура № 1001</h1><div style="display:grid; grid-template-columns:1fr 1fr; gap:12pt">…</div>');

$pdf->stream('faktura.pdf', ['Attachment' => false]);   // или $pdf->save(__DIR__ . '/faktura.pdf'); или $bytes = $pdf->output();
```

Локални изображения, стилове и шрифтове се четат само от разрешени папки:

```php
$pdf = new IvoPdf(['basePath' => __DIR__ . '/templates', 'allowedPaths' => [__DIR__ . '/assets']]);
$pdf->loadHtmlFile(__DIR__ . '/templates/faktura.html');   // basePath автоматично става папката на файла
print_r($pdf->getWarnings());                              // напр. изображения, които не са заредени
```

## Миграция от dompdf / mPDF

| dompdf | mPDF | IvoPDF |
|---|---|---|
| `new Dompdf($options)` | `new Mpdf(['format' => 'A4-L', 'margin_left' => 10])` | `new IvoPdf($options)`; приемат се и двата стила опции (`format`, `orientation`, `margin_*` в mm, `default_font`, `chroot`, `isRemoteEnabled`) |
| `loadHtml($html)` | `WriteHTML($html)` | `loadHtml()` / `WriteHTML()` |
| `setPaper('A4', 'landscape')` | опция `format` | `setPaper('A4', 'landscape')` или `[w, h]` в pt |
| `render(); output(); stream()` | `Output($name, 'I'/'D'/'F'/'S')` | `render()`, `output()`, `stream()`, `save()`, `output($name, 'S'\|'F'\|'D'\|'I')` |
| `counter(page)` в CSS | `SetHTMLHeader()` / `SetHTMLFooter()` с `{PAGENO}` / `{nbpg}` | работят всички; също `setHeaderHtml()` / `setFooterHtml()` с `{PAGE_NUM}` / `{PAGE_COUNT}` |
| — | `SetProtection()`, `SetWatermarkText()`, `SetAssociatedFiles()`, `SetDocTemplate()`… | поддържат се |

За Laravel и Symfony вижте [Интеграции с фреймуърци](#интеграции-с-фреймуърци).

### mPDF шаблони без промени

`use Mpdf\Mpdf; new Mpdf([...]); $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS)` работи както е (псевдоними `Mpdf\Mpdf`, `Mpdf\HTMLParserMode`, `Mpdf\Output\Destination`; никога не заместват истинска инсталация на mPDF).

| mPDF | Поддръжка |
|---|---|
| `<pagebreak>` | `orientation`, `sheet-size`, `margin-*` (mm), `odd-/even-header-name` / `-footer-name` (+ `-value`), `resetpagenum`, `pagenumstyle`, `suppress`, `type="NEXT-ODD/NEXT-EVEN"` |
| `<htmlpageheader name>` / `<htmlpagefooter name>`, `<sethtmlpageheader …>`, `@page { header: html_x; footer: html_y }` | именувани колонтитули, сменяни по средата на документа; `{PAGENO}`, `{nbpg}`, `{nb}`, `{DATE j-m-Y}`; `DefHTMLHeaderByName()`, `SetHTMLHeaderByName()`, `SetHTMLHeader/Footer($html, 'O'\|'E')` |
| `SetHeader('L\|C\|R')` / `SetFooter(...)` | низове и формата с масив |
| `<bookmark>`, `Bookmark()` | отметки в PDF (нивата започват от 0) |
| `<tocpagebreak>`, `<tocentry>`, `TOC_Entry()` | `toc-preHTML`, `toc-postHTML`, `toc-bookmarkText`, атрибути `toc-` за страницата на съдържанието |
| `<indexentry>`, `<indexinsert>`, `IndexEntry()`, `InsertIndex()` | азбучен показалец с номера на страници, сортиран с `Collator` (`bg_BG`, `en_US`…) или с вграден български/латински ред |
| `<annotation>` | бележка (sticky note) в PDF |
| `<watermarktext>`, `<watermarkimage>`, `SetWatermarkImage()` | воден знак с текст или изображение на всяка страница |
| `<columns>` / `<columnbreak />` / `SetColumns()` | CSS колони |
| `rotate`, `autosize`, `overflow="shrink"` за таблици / изображения | завъртане чрез `transform`; свиване, за да се побере |
| `<barcode code type size error>` | вижте „Баркодове“ по-долу |
| `WriteHTML($html, $mode)`, `SetDefaultBodyCSS()`, `SetDirectionality()`, `SetCompression()`, `SetDisplayMode()`, `SetJS()`, `AddPage(...)`, `AddPageByArray()`, `SetBasePath()`, `SetDefaultFont()` | поддържат се |
| ключове в конструктора | `mode`, `tempDir`, `autoScriptToLang`, `autoLangToFont`, `fontDir` + `fontdata`, `margin_header` / `margin_footer`, `showImageErrors`, `PDFA`, `PDFX`, `restrictColorSpace`, `shrink_tables_to_fit`… |

Не се поддържат: рисуващият API (`Cell`, `Ln`, `Rect`, `Image`, `WriteText`, `SetFont`…), `mirrorMargins`, `<dottab>`, `<formfeed>`, `<tts>`, `<setpageheader>` / `<setpagefooter>` (използвайте `SetHeader()` / `SetFooter()`).

## Опции

| Опция | По подразбиране | Описание |
|---|---|---|
| `paperSize` | `'A4'` | A0–A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio или `[ширинаPt, височинаPt]` |
| `orientation` | `'portrait'` | `portrait` / `landscape` |
| `margins` | по 36 pt | `['top' => .., 'right' => .., 'bottom' => .., 'left' => ..]`; CSS `@page { margin }` има предимство |
| `defaultFont`, `defaultFontSize` | Inter, 12 | общите `sans-serif`, Arial, Helvetica… водят към Inter; `serif` / `monospace` към DejaVu Serif / Sans Mono |
| `defaultBoxSizing` | `content-box` | стандартното за CSS; шаблоните обикновено задават `* { box-sizing: border-box }` |
| `basePath`, `allowedPaths` | `null`, `[]` | папки, от които може да се четат локални изображения, CSS и шрифтове |
| `isRemoteEnabled` | `false` | разрешава `http(s)` ресурси (със защита от SSRF) |
| `maxRemoteBytes`, `maxImagePixels` | 10 MB, 25 MP | лимити на ресурсите |
| `maxHtmlBytes`, `maxNestingDepth`, `maxWordLength`, `maxPages` | 32 MB, 200, 2000, 5000 | защитни лимити |
| `html5Parser` | `true` | HTML5 парсер на WHATWG (PHP ≥ 8.4); `false` ползва HTML4 парсера на libxml |
| `shrinkTablesToFit` | `1.4` | максимално свиване на твърде широки таблици (`0` = изключено) |
| `subsetFonts` | `true` | вграждат се само използваните знаци |
| `fallbackFonts`, `scriptFonts` | `[]` | допълнителни шрифтове за липсващи знаци; шрифт според писмеността (`['Hani' => 'Noto Sans SC', 'Deva' => 'Noto Sans Devanagari']`) |
| `bookmarks` | `true` | отметки в PDF от заглавията |
| `formFields` | `true` | попълваеми полета; `false` рисува контролите като статична графика |
| `pdfa` | `''` | `'1b'`, `'2b'`, `'3b'` |
| `pdfx`, `pdfxOutputCondition`, `pdfxIccProfile` | `''`, `FOGRA39`, `''` | `'X-1a'`, `'X-3'`, `'X-4'` |
| `colorSpace` | `'rgb'` | `'cmyk'` превръща всички цветове и изображения в CMYK |
| `bleed`, `cropMarks` | `0`, `false` | bleed в mm, маркери за рязане |
| `compress` | `true` | компресира съдържанието |
| `title`, `author`, `subject`, `keywords`, `creator`, `lang` | | метаданни (`title` / `lang` се вземат от `<title>` / `<html lang>`) |
| `watermarkText`, `watermarkOpacity` | `''`, `0.08` | диагонален текстов воден знак |

## Ръководство по функции

### Шрифтове

```php
$pdf->registerFontFamily('Inter', ['regular' => '/fonts/Inter.ttf', 'bold' => '/fonts/Inter-Bold.ttf']);
$pdf->addFallbackFont('Noto Sans Symbols');
```

CSS `@font-face` работи с `.ttf` / `.otf` източници (data URI, разрешен локален път или отдалечен, ако е включено). Азиатските и индийските писмености изискват шрифтове, които не са включени в проекта (например шрифтовете Noto на Google, с лиценз OFL):

```php
$pdf = new IvoPdf(['scriptFonts' => ['Hani' => 'Noto CJK SC', 'Hani:ja' => 'Noto CJK JP', 'Hang' => 'Noto CJK KR', 'Deva' => 'Noto Deva']]);
$pdf->registerFontFamily('Noto CJK SC', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#2']);   // номер на шрифта след '#'
$pdf->registerFontFamily('Noto CJK JP', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#0']);
$pdf->registerFontFamily('Noto CJK KR', ['regular' => '/fonts/NotoSansCJK-Regular.ttc#1']);
$pdf->registerFontFamily('Noto Deva',   ['regular' => '/fonts/NotoSansDevanagari-Regular.ttf']);
```

Знаците без наличен шрифт излизат като празни квадратчета и добавят по едно предупреждение за всяка писменост в `getWarnings()`.

### Колонтитули и оформление на страниците

- `setHeaderHtml($html, 'all'|'first'|'odd'|'even')`, същото за долния колонтитул; заместители `{PAGE_NUM}`, `{PAGE_COUNT}`, `{DATE}`, `{DATE d.m.Y}`, `{TITLE}`. Мястото се резервира автоматично.
- CSS: `@page :first { margin-top: 40mm }`, `@page :left / :right`, именувани страници (`@page wide { size: A4 landscape } table.big { page: wide }`), колонтитули в полетата (`@top-center { content: string(chapter) }`, `@bottom-right { content: counter(page) " / " counter(pages) }`), `string-set`, `position: running(header)` + `content: element(header)`.
- Съдържание: `<toc levels="3" title="Съдържание" />` или `setTableOfContents(true, 'Съдържание')`, или собствен списък с `a::after { content: leader('.') target-counter(attr(href), page) }`.

### Формуляри

HTML `<input type="text|email|number|date|password|checkbox|radio">`, `<textarea>`, `<select>` и `<button>` стават попълваеми PDF полета с текущите си стойности (включително на кирилица). Радио бутоните с еднакво име образуват една група; `required`, `readonly` и `disabled` задават флаговете на полето; формуляр с `http(s)` `action` получава бутон за изпращане.

### Баркодове

```html
<barcode type="QR" value="https://example.com/invoice/1001" size="1.5" error="M" />
<barcode type="EAN13" value="400638133393" />
<barcode type="C128" value="INV-2026-001" width="160" height="40" />
<barcode type="DATAMATRIX" value="ABC-123" />
<barcode type="PDF417" value="..." columns="6" />
```

Типове: `QR`, `DATAMATRIX` (квадратни и `shape="rect"`, GS1), `PDF417`, `C128` (автоматично A/B/C), `C128A/B/C`, `C39` (+ контролна сума, разширен), `C93`, `EAN13`, `EAN8`, `UPCA`, `UPCE`, `ISBN`, `ISSN`, `I25`, `CODABAR`. PHP API: `IvoPdf\Widget\Barcode::svg($type, $value, $options)`.

### Сигурност, подписи и архивиране

```php
$pdf->setProtection(['print', 'copy'], 'парола-за-четене', 'парола-на-собственика');   // AES-256 по подразбиране; 'aes128', 'rc4'
$pdf->setSignature('/certs/cert.pem', '/certs/key.pem', 'парола-на-ключа',
    ['Name' => 'Ivo Apostolov', 'Reason' => 'Одобрение', 'Location' => 'София'],
    [1, 400, 760, 150, 50]);                                                     // видима рамка по желание: страница, x, y, ширина, височина (pt)
$pdf->setPdfA('2b');                                                             // '1b', '2b', '3b'
print_r($pdf->validatePdfA());                                                   // евристична предварителна проверка
```

- Подписите са отделен CMS (`adbe.pkcs7.detached` или `ETSI.CAdES.detached`) със SHA-256 и ключове RSA или ECDSA; приемат се и `.p12` / `.pfx` файлове.
- PDF/A-1b е истински ISO 19005-1 (PDF 1.4, `/CIDSet`, без прозрачност: полупрозрачните цветове стават плътни); 2b и 3b запазват прозрачността. `validatePdfA()` е евристична проверка и **не** замества veraPDF.
- При всяко ниво на PDF/A изображенията се записват с `/Interpolate false`, а изрязаните шрифтове пазят ширина за всеки използван знак, така че съвпадат с `/W`. `validatePdfA()` проверява и двете неща за TrueType шрифтове; при OpenType/CFF (`.otf`) не сравнява ширините на знаците.
- PDF/A не може да се комбинира с криптиране; PDF/X не може да се комбинира с PDF/A, криптиране или подпис.

### Печат: CMYK, spot цветове, PDF/X

```php
$pdf->addSpotColor('PANTONE 185 C', 0, 91, 76, 0);    // CMYK еквивалент в проценти
$pdf->setPdfX('X-1a', 'FOGRA39');                     // или 'X-3', 'X-4'; по желание път до ICC профил
$pdf->setBleed(3, true);                              // 3 mm bleed с маркери за рязане
```

CSS: `color: cmyk(0 0.91 0.76 0)`, `device-cmyk(...)`, `spot(PANTONE 185 C, 80%)`. X-1a / X-3 превръщат всичко в CMYK и премахват прозрачността (с предупреждения в `getWarnings()`); X-4 запазва RGB и прозрачността. Превръщането от RGB в CMYK е опростено (без ICC); може да добавите свой `IvoPdf\Pdf\ColorConverter`. FOGRA/SWOP профил не е включен заради лиценза.

### Прикачени файлове, Factur-X и съществуващи PDF файлове

```php
$pdf->attachFile('/data/report.xlsx', 'report.xlsx', 'Изходни данни');
$pdf->setPdfA('3b')->attachFacturX($facturXml, 'EN 16931');   // е-фактура по стандарта на ЕС
$pdf->setPageTemplate('/templates/blanka.pdf');               // фон на всяка страница
$pdf->appendPdf('/terms/obshti-uslovia.pdf');                 // добавя страници в края
```

HTML `<img src="chertezh.pdf" data-page="2">` поставя страница от PDF като векторна графика. Вградените страници запазват линковете, бележките и полетата на формулярите си. Криптирани PDF файлове не се приемат.

### Надеждност и бързина

HTML5 парсване (PHP ≥ 8.4), свиване на таблици, лимити за прекалено голям вход и линейно време при дълги документи (около 20 ms на страница текст; 500 страници за около 10 s). Вижте опциите по-горе.

## Интеграции с фреймуърци

Отделни Composer пакети в [`integrations/`](integrations/):

- **Laravel**: [`ivopdf/laravel`](integrations/laravel/README.md). Автоматично откриван service provider, публикуем `config/ivopdf.php` и фасада `Pdf` / `PDF` със същия API като `barryvdh/laravel-dompdf` (`Pdf::loadView('invoices.show', $data)->setPaper('a4', 'landscape')->download('faktura.pdf')`).
- **Symfony**: [`ivopdf/symfony-bundle`](integrations/symfony/README.md). Bundle с конфигурация `ivopdf:` и услуга `PdfGenerator` (`fromHtml()`, `fromTwig()`, `toResponse()`).

## Демота (български и английски)

- **Уеб playground**: отворете `index.php` от localhost (напр. `http://localhost/ivopdf/`), превключете **BG | EN** и редактирайте HTML на живо. Публичният достъп е забранен, освен ако `IVOPDF_ALLOW_PUBLIC_STUDIO=1`.
- **CLI**: `php examples/generate.php [папка]` генерира всяко демо на двата езика (`invoice`, `dashboard`, `certificate`, `catalog`, `showcase`) в `examples/output/{key}-{lang}.pdf`.
- От код: `IvoPdf\Examples\Templates::all('bg')` / `all('en')`.

## Тестове

Всеки файл в `tests/` е самостоятелен скрипт, който отпечатва резултат и завършва с ненулев код при грешка:

```bash
for t in tests/*.php; do php "$t" || echo "FAILED: $t"; done
```

Някои проверки ползват Python с PyMuPDF (`pip install pymupdf`), командата `openssl` или системни шрифтове на Windows и се пропускат, ако ги няма.

## Известни ограничения

- Не се поддържат вертикален текст (`writing-mode: vertical-*`), висяща пунктуация, SVG филтри и EMF+ записи.
- При писане от дясно наляво таблиците, flex и grid не се огледализират.
- Когато `min-width` е по-голямо от `max-width`, печели `min-width` (както в CSS и браузърите).
- PDF/A и PDF/X са проверени структурно, не с veraPDF или професионален инструмент за предпечат.
- Полетата на формулярите ползват невградените стандартни шрифтове Helvetica / ZapfDingbats, затова документи с формуляри не минават PDF/A.
- Шрифтове от `.ttc` колекции не се поддържат в CSS `@font-face` (използвайте `registerFontFamily()`).
- Рисуващият API на mPDF не е наличен (вижте таблицата за mPDF по-горе).

## Skill за AI асистент (Claude)

В хранилището има skill, който учи Claude как да създава, поправя и мигрира PDF шаблони с IvoPDF: [`skills/ivopdf`](skills/ivopdf/SKILL.md).

- **Claude Code**: копирайте папката в `~/.claude/skills/ivopdf` (за всички проекти) или в `.claude/skills/ivopdf` в проекта си.
- **Claude.ai / Claude desktop**: архивирайте папката `skills/ivopdf` като zip и я качете от Settings → Capabilities → Skills.

Включва `scripts/render.php` (генерира PDF от HTML файл и показва страници и предупреждения) и `scripts/preview.py` (прави PNG на страниците за преглед).

## Лиценз

Copyright © 2026 Ivo Apostolov.

IvoPDF е безплатен софтуер с отворен код под лиценза **GNU Affero General Public License v3.0 или по-нова версия** ([LICENSE](LICENSE)).

- Можете свободно да го използвате, изучавате, променяте и споделяте, включително в търговски проекти.
- Ако разпространявате софтуер, който включва IvoPDF, или давате достъп до него през мрежа (уеб приложения, SaaS, API), **цялото приложение трябва да бъде пуснато под AGPL** с изходен код, достъпен за потребителите му. Вграждането на IvoPDF в продукти със затворен код не е разрешено.
- Вградените чужди данни запазват собствения си свободен лиценз: шрифтовете Inter (SIL Open Font License 1.1, [resources/fonts/LICENSE-Inter.txt](resources/fonts/LICENSE-Inter.txt)), шрифтовете DejaVu ([resources/fonts/LICENSE-DejaVu.txt](resources/fonts/LICENSE-DejaVu.txt)) и TeX шаблоните за пренасяне (лицензите са в началото на файловете в `resources/hyphenation/`).

## Принос

Вижте [CONTRIBUTING.md](CONTRIBUTING.md) (на английски). Всеки, който допринася, подписва [CLA](CLA.md) веднъж, при първия си pull request.
