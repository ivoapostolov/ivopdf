# IvoPDF — HTML & CSS към PDF на чист PHP

[English](README.md) · **Български**

IvoPDF превръща HTML + CSS в PDF **без външни програми** (без Chrome, Node.js или wkhtmltopdf). Работи навсякъде, където работи PHP, включително на споделен хостинг. Проектиран е като директен заместител на **dompdf** и **mPDF** и добавя истински **CSS Flexbox**, **CSS Grid**, цифров подпис и поддръжка на е-фактури (Factur-X).

Изисквания: PHP ≥ 8.1 с `ext-dom`, `ext-mbstring`, `ext-zlib`. По желание: `ext-gd` (GIF, WebP, палитрови PNG, филтри за изображения), `ext-openssl` (криптиране, подписи), `ext-intl` (пренасяне на тайландски и кхмерски, сортиране на показалец). С PHP ≥ 8.4 се включва HTML5 парсерът.

## Възможности

**Оформление и CSS**
- Block, inline, inline-block, плаващи елементи (плаващ елемент в средата на абзац се поставя на реда, където се среща: в началото на този ред, ако се събира до съдържанието му, иначе в началото на следващия, а редовете го обтичат, CSS 2.1 9.5.1; блок, който започва до по-ранен плаващ елемент, запазва цялата си ширина и само редовете му се скъсяват, всеки според плаващите елементи до него, така че редовете под плаващия елемент отново заемат цялата ширина; кутия, която създава нов блоков контекст за форматиране, например таблица или `overflow: hidden`, се поставя до плаващия елемент; плаващ елемент никога не се издига над по-ранен плаващ елемент, започва под долното поле на блока преди него и се рисува върху блоковете, които застъпва; `clear` поставя блока точно под плаващите елементи, като полетата над него се поглъщат), позициониране absolute / fixed / relative, `z-index`, **Flexbox**, **CSS Grid** (`fr`, `repeat()`, `minmax()`, `auto-fit/fill`, span, автоматично разполагане, вкл. `grid-auto-flow: column | dense`, `grid-auto-columns/rows`, `grid-template-areas`, `grid-area`, съкращенията `grid-template` / `grid` и именувани линии като `[main-start]` / `main-end`; текстът обтича плаващите елементи ред по ред), **колони** (`column-count`, `column-width`, `column-rule`, `column-span`, балансиране през страници).
- Таблици: `thead/tbody/tfoot`, `colspan`/`rowspan`, `<caption>`, ширини от `<col>`, `table-layout: fixed`, слети и разделени рамки (`border-spacing`; при слети рамки кутията на таблицата съдържа цялата външна рамка, а рамка, обща за две колони, се брои веднъж, както в Edge), ширини на клетки в проценти като дял на колоната от таблицата (заедно с отстъпите), колони, които никога не са по-тесни от най-дългата си дума (таблица, която не се събира, става по-широка от контейнера си, вместо думите да се застъпват), `vertical-align: top | middle | bottom` и в клетки със зададена височина, надписи (`<caption>`) извън рамката, които никога не се разделят по средата на дума (минималната им ширина разширява таблицата), таблици с `dir="rtl"`, подредени от дясно наляво, заглавен ред, повтарян на всяка страница (заглавен ред, който би останал сам в дъното на страница, преминава заедно с таблицата на следващата), редове, които никога не се разрязват, свиване на твърде широки таблици (като `shrink_tables_to_fit` в mPDF).
- Пълен механизъм за селектори (`:is()`, `:where()`, `:has()`, `:nth-*()`, атрибутни оператори…), специфичност и `!important`, `var()`, `calc()`, `inherit`/`initial`/`unset`, `@media` (print, ширина, ориентация), `@supports`, `@import`, `@font-face`, `::before`/`::after` с `content`, CSS броячи, височини в проценти, `aspect-ratio`, `display: contents`.
- HTML5 парсване по алгоритъма на WHATWG (PHP ≥ 8.4).

**Текст и шрифтове**
- UTF-8 навсякъде; вградени **Inter** (шрифт по подразбиране, пълна кирилица), **Liberation** Sans/Serif/Mono (със същите ширини като Arial, Times New Roman и Courier New, така че шаблоните за тези шрифтове се пренасят по същия начин), **Sofia Sans** и **Sofia Sans Semi Condensed** (български форми на кирилицата) и DejaVu Sans/Serif/Mono (латиница, кирилица, гръцки, арабски, иврит…; ползват се автоматично и за знаци, които липсват в другите шрифтове).
- **Изрязване на шрифтовете** (типична фактура е ≈ 40 KB вместо ~900 KB), TrueType и **OpenType/CFF (`.otf`)**, колекции `.ttc`, `@font-face`, автоматични **резервни шрифтове** и избор на шрифт според писмеността.
- **OpenType оформяне**: лигатури, кернинг, `font-feature-settings`, `font-variant-*`; `font-variant: small-caps` / `all-small-caps` ползват знаците `smcp`/`c2sc` на шрифта, а при шрифтове без тях се синтезират (главни букви на 70 % размер), както правят браузърите и mPDF; синтезираните малки главни букви носят `/ActualText`, затова копирането и търсенето виждат оригиналния текст с малки букви. GSUB/GPOS правилата се филтрират предварително за всеки знак, затова Inter е почти толкова бърз, колкото DejaVu.
- **Типографски псевдо-елементи**: `::first-letter` (буквица чрез `float` / `initial-letter`) и `::first-line` (шрифт, цвят, `text-transform` върху първия ред, който се пренарежда при промяна на размерите).
- **От дясно наляво** (Unicode bidi алгоритъм, `dir="rtl|auto"`, `<bdi>`, `<bdo>`), свързване на **арабски**, **иврит**.
- Пренасяне на **китайски, японски и корейски** (kinsoku, `word-break`, `line-break`), оформяне на **индийски писмености** (деванагари, бенгалски, гуджарати, гурмукхи, тамилски…), пренасяне на **тайландски, лаоски и кхмерски** по думи.
- **Пренасяне с тире**: `&shy;` и `hyphens: auto` за български и английски (TeX шаблони).
- Правилно пренасяне между тагове, `&nbsp;`, двустранно подравняване, `text-overflow: ellipsis`, `text-shadow`, подчертаване, непрекъснат фон на многословни inline елементи.
- **Блокификация** (CSS Display 3): плаващ или абсолютно позициониран елемент и всеки flex / grid елемент получават блоковия еквивалент на своя `display` (`inline` / `inline-block` -> `block`, `inline-flex` -> `flex`, `inline-table` -> `table`), затова `<h3>Дата <span style="float:right">12.05.2026</span></h3>` или плаващ `<a href>` се подреждат като float със ширина по съдържание на реда, където се срещат (и в клетки на таблици и заглавия), вместо да губят текста си.
- **Номерация на списъци**: `decimal`, `decimal-leading-zero`, `lower/upper-alpha|latin|roman|greek`, `cyrillic`, `armenian` / `lower-armenian`, `georgian`, `hebrew`, `cjk-decimal`, `cjk-ideographic` (диапазони по CSS Counter Styles 3; стойност извън диапазона на стила се показва десетично), също и в `counter(име, стил)`.
- `<details>` без `open` показва само `<summary>` (или стандартно „Details“, ако липсва), както браузърите при печат; с `open` се показва всичко.

**Графика и изображения**
- `border-radius`, всички стилове рамки (double, groove, ridge, inset, outset), `border-image`, `outline`, няколко `box-shadow`, `opacity` (истинска групова прозрачност), `mix-blend-mode`, `clip-path`, филтри за изображения, списъци с `transform` и `transform-origin`.
- `linear-gradient()` / `radial-gradient()` като вградени PDF shading-и, `background-size / position / repeat`, SVG като фон.
- Изображения: JPEG (вкл. CMYK и завъртане по EXIF), PNG (прозрачност, палитра, interlaced, 16 бита), GIF, WebP, BMP, TIFF, ICO / CUR (най-голямото изображение в иконата; PNG или BMP записи, AND маската става прозрачност), SVG, WMF, EMF; `object-fit` / `object-position`. Вграден `<svg width="100%">` заема ширината на контейнера си (viewBox се вписва в нея), а текстът му наследява шрифта на околния HTML.
- SVG: всички фигури и команди за пътища, текст, `transform`, `viewBox`, истински **градиенти**, **шарки**, **изрязване**, **маски**, **стрелки (marker)**, `<use>`, `<style>`, `<image>`.

**Документи**
- **Отметки (bookmarks)** от заглавията, кликаеми външни и вътрешни (`#id`) линкове, **съдържание** с номера на страници (`<toc />`, `target-counter()`, точки до номера), **азбучен показалец** като в mPDF.
  Линкове: една `/Link` анотация за линк: фрагментите на един ред са един правоъгълник (линк от шест думи е един правоъгълник, а не единадесет), а линк, пренесен на няколко реда, получава по един правоъгълник за всеки ред, както го записват браузърите; `<a href>` около `<img>` / `<svg>` (и около плаващо изображение) е кликаем, както и `<a href>` вътре в SVG кода; целите `#id` работят за всеки елемент с `id` (блок, ред `<span>`/`<a>`, `<img>`, `<svg>`, празни котви) и за `<a name>`, на страницата и реда, където е нарисуван. Несъществуваща цел `#...` добавя едно предупреждение, а линкът се пропуска (`#` и `#top` водят към началото на документа). Линковете и полетата на формуляри принадлежат на страницата, която рисува техния ред: в полето на предишната страница не се записва нищо.
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
- `<script type="text/php">` от dompdf нарочно **не** се поддържа (предупреждение насочва към `getCanvas()->page_text()` / `setFooterHtml()`).

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

Стиловете по подразбиране са като в браузъра (и в dompdf), сверени с Microsoft Edge: `body { margin: 8px }` (документите, подадени през mPDF API-то, `WriteHTML()`, запазват полето 0 на mPDF; горното поле на body се слива с това на първия му елемент), `line-height: normal` по метриките на самия шрифт (hhea или OS/2 typo стойностите, когато шрифтът задава USE_TYPO_METRICS; ascent, descent и line gap се закръгляват до цели CSS пиксели както в Blink: DejaVu Sans 11pt дава 12.75pt), таблици с `border-collapse: separate; border-spacing: 2px` (и около крайните клетки) и без поле, `table[border]` с изпъкнала рамка на таблицата и вдлъбнати рамки на клетките, `cellspacing="0"` за таблица без разстояния, центрирани `<th>`, клетки с `padding: 1px` (така шаблоните, писани за браузър, запазват височината на редовете; за по-просторни клетки задайте собствен `td { padding: … }`), списъци с `margin: 1em 0; padding-left: 40px` (вложените списъци са без вертикални полета, водачите са `disc` / `circle` / `square` според нивото, а водач `inside` е отделен със 7px), `<figure>` и `<blockquote>` с `margin: 1em 40px`, `<pre>` с моноширинен шрифт, `white-space: pre` и без рамка, `<hr>` като 1px сива вдлъбната линия, `<fieldset>` с релефна рамка и `<legend>`, нарисуван в горната ѝ част, и полета на формуляри с метриките на Arial (Liberation Sans; `<textarea>` е моноширинен, 20 x 2 знака и стъпва на базовата линия с долния си ръб; текстовото поле е широко 132.75pt). Плаващ елемент пред блок не разделя полетата на родителя от тези на първия му елемент, а принудителното прекъсване на страница запазва горното поле на следващия елемент, както в браузъра.

Позиционирането е като в браузъра и dompdf: `position: fixed` се поставя в областта на страницата (вътре в полетата на `@page`) и се повтаря на всяка страница, затова класическият dompdf колонтитул `footer { position: fixed; bottom: -20pt }` стои в долното поле (при документи през mPDF: спрямо листа, както в mPDF); `position: absolute` се отчита спрямо padding кутията на най-близкия позициониран предшественик, а без такъв – спрямо областта на страницата (не спрямо body); `position: relative` на редов елемент (`<span style="position:relative; top:2pt">`) премества текста му. `counter(page)` / `counter(pages)` в генерирано съдържание заемат ширината на една цифра и се подравняват отново, когато номерът се рисува.

Таблиците без `width` се оразмеряват по съдържанието си, както в браузъра (ширина на съдържанието, най-много наличната ширина); за таблица на цялата ширина ползвайте `width: 100%`. Позиционират се с `margin-left/right: auto`, `float` или старите атрибути `align="center|left|right"` (`left` / `right` плаваща таблица, както в браузъра); `text-align` на родителя не премества блокова таблица, но `<center>` и `align=` на блоков контейнер я премества. Поддържани са още: съкратеният запис `font:` с произволен ред на ключовите думи за стил / вариант / дебелина / разтегляне (непосочените части се връщат към началните стойности), `page-break-before/after` и `break-before/after: left | right | recto | verso` (при нужда се добавя празна страница; страница 1 е дясна, а лява при `direction: rtl` на корена), `@page { size: 100mm }` / `100mm 50mm` / `A4 landscape` / `landscape`, `<wbr>` (също възможност за пренос при изчисляване на минималната ширина на кутии и колони на таблици), `caption-side: top | bottom` (долното заглавие следва последния ред на последната страница на разделена таблица), `tab-size` (число или дължина, по подразбиране 8; една табулация никога не отива по-далеч от ширината на реда, затова `tab-size: 1e9` не може да задели огромен низ) за `white-space: pre | pre-wrap`, `orphans` / `widows` (по подразбиране 2, както в браузъра: абзац не се разделя така, че един-единствен ред да остане в дъното на страница или да започне следващата), вертикални отстъпи и рамки на редови елементи, които не правят реда по-висок (`<span>` значка с отстъпи застъпва съседните редове, както в браузъра), полета на страницата (margin boxes), които наследяват `font-*`, `color` и `line-height` от своето правило `@page`, надписи `<caption>` без отстъпи, външни маркери на списъци, поставени като в Chrome (`1.` завършва един интервал преди текста, точката е там, където Chrome рисува своя кръг), селектора `:dir(ltr | rtl)` (от атрибутите `dir`, `dir=auto` по първия силен знак), маркери на списъци и стилове на `counter()`, които остават ограничени при огромни стойности (римски цифри само за 1-3999, извън това десетични; броячите са ограничени до 32-битов диапазон), и единиците `ex` / `ch` (височина на малко „x“ от OS/2 таблицата на шрифта и ширина на „0“, също за семейства от `@font-face` и независимо от реда на декларациите; 0,5 em, ако шрифтът няма такава стойност). Съдържанието на `<noscript>` е скрито, както в браузър със скриптове (dompdf го показва).

За Laravel и Symfony вижте [Интеграции с фреймуърци](#интеграции-с-фреймуърци).

### dompdf код без промени

`use Dompdf\Dompdf; use Dompdf\Options;` работи както е (псевдоними `Dompdf\Dompdf`, `Dompdf\Options`, `Dompdf\Canvas`, `Dompdf\FontMetrics`; никога не заместват истинска инсталация на dompdf):

```php
$options = new Options();
$options->set('isRemoteEnabled', true);          // работят и setIsRemoteEnabled(), setChroot(), setDefaultFont(), setDpi()...
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('helvetica', 'bold');
$canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 35, 'Страница {PAGE_NUM} от {PAGE_COUNT}', $font, 10, [0, 0, 0]);
$canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {   // PHP callable, никога низ с код
    $canvas->line(36, 50, 559, 50, [0.6, 0.6, 0.6], 0.5);
});
echo $dompdf->output(['compress' => 0]);        // или $dompdf->stream('x.pdf', ['Attachment' => 0])
```

`Canvas` има методите на CPDF адаптера на dompdf: `page_text()`, `page_script()`, `page_line()`, `text()`, `line()`, `rectangle()` (и по-старото име `rect()`), `filled_rectangle()`, `circle()`, `arc()`, `polygon()`, `image()`, `add_link()` (URL или `#име`), `add_named_dest()`, `add_info()`, `javascript()`, `new_page()`, `set_default_view()`, `set_opacity()` (с режим на смесване), `save()` / `restore()`, `clipping_rectangle()` / `clipping_roundrectangle()` / `clipping_polygon()` / `clipping_end()`, `rotate()` / `scale()` / `skew()` / `translate()` / `transform()` (матриците на dompdf), `open_object()` / `reopen_object()` / `close_object()` / `add_object()` (`'add'`, `'all'`, `'next'`, `'even'`, `'odd'`, `'nexteven'`, `'nextodd'`) / `stop_object()`, `get_text_width()`, `get_font_height()`, `get_font_baseline()`, `font_supports_char()`, `get_width()`, `get_height()`, `get_page_count()`, `output()` / `stream()`; координатите са като в dompdf (pt от горния ляв ъгъл), а `page_text()` / `text()` поставят базовата линия на височина на шрифта (ascent + descent) под `y`, както прави dompdf. Рисуване след `render()` кара `output()` да рендира отново и отива на *текущата* страница: последната след `render()` (както в dompdf), първата преди това, добавената с `new_page()` или избраната със `set_page_number()` (dompdf там само преномерира); вътре в `page_script()` отива на страницата, която се строи, а графичното състояние (прозрачност, изрязване, трансформация) започва наново при всяко извикване. `page_text()` се повтаря на всяка страница и не се записва в обекти. Останалите методи на CPDF (`serialize_object()`, `select()`, `input()`…) не правят нищо и добавят по едно предупреждение на метод в `getWarnings()` вместо фатална грешка. `Dompdf` има и `getDom()` (разобраният `DOMDocument`), `loadDOM()` / `setDom()`, `set_options()`, `setCallbacks()` / `getCallbacks()` (изпълняват се `end_document`, `end_page_render` и `begin_page_render` — рисува под съдържанието; събитията за страница получават `null` като `$frame`; `begin_frame` / `end_frame` / `begin_page_reflow` се отчитат като неподдържани), `setHttpContext()` / `getHttpContext()` (пази се; отдалечените файлове винаги минават през защитения от SSRF клиент на IvoPDF), `getTree()` (дървото на подредбата на IvoPDF вместо дърво от frame-ове), `getQuirksmode()` (false), `setDefaultView()` / `parseDefaultView()`; непознати методи на dompdf дават предупреждение вместо грешка. `FontMetrics` има `getFont()`, `getTextWidth()`, `getFontHeight()`, `getFontBaseline()`, `registerFont()` / `register_font()`, `getFamily()`, `setFontFamily()` (приема пътищата без разширение като dompdf), `getFontFamilies()`, `getType()`. `Options::getFontDir()` / `getFontCache()` / `getRootDir()` по подразбиране сочат папката с вградените шрифтове и библиотеката. Разлики: форматът по подразбиране на dompdf е Letter (извикайте `setPaper('A4')`); `fontDir` / `fontCache` / `tempDir` / `pdfBackend` и debug опциите се приемат и се игнорират; `<script type="text/php">` и низове с PHP код към `page_script()` никога не се изпълняват, а предупреждение в `getWarnings()` насочва към `getCanvas()->page_text()` / `page_script(callable)` или `setFooterHtml()`.

### mPDF шаблони без промени

`use Mpdf\Mpdf; new Mpdf([...]); $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS)` работи както е (псевдоними `Mpdf\Mpdf`, `Mpdf\HTMLParserMode`, `Mpdf\Output\Destination`; никога не заместват истинска инсталация на mPDF).

| mPDF | Поддръжка |
|---|---|
| `<pagebreak>` | `orientation`, `sheet-size`, `margin-*` (mm), `odd-/even-header-name` / `-footer-name` (+ `-value`), `resetpagenum`, `pagenumstyle`, `suppress`, `type="NEXT-ODD/NEXT-EVEN"` |
| `<htmlpageheader name>` / `<htmlpagefooter name>`, `<sethtmlpageheader …>`, `@page { header: html_x; footer: html_y }` | именувани колонтитули, сменяни по средата на документа; `{PAGENO}`, `{nbpg}`, `{nb}`, `{DATE j-m-Y}`; `DefHTMLHeaderByName()`, `SetHTMLHeaderByName()`, `SetHTMLHeader/Footer($html, 'O'\|'E')` |
| `SetHeader('L\|C\|R')` / `SetFooter(...)` | низове и формата с масив |
| `<bookmark>`, `Bookmark()` | отметки в PDF (нивата започват от 0) |
| `<tocpagebreak>`, `<tocentry>`, `TOC_Entry()` | `toc-preHTML`, `toc-postHTML`, `toc-bookmarkText`, атрибути `toc-` за страницата на съдържанието; както в mPDF, документ с тагове `<tocentry>` показва точно тези записи (заглавията се добавят само в документи без такива тагове) |
| `<indexentry>`, `<indexinsert>`, `IndexEntry()`, `InsertIndex()` | азбучен показалец с номера на страници, сортиран с `Collator` (`bg_BG`, `en_US`…) или с вграден български/латински ред |
| `<annotation>` | бележка (sticky note) в PDF |
| `<watermarktext>`, `<watermarkimage>`, `SetWatermarkImage()` | воден знак с текст или изображение на всяка страница |
| `<columns>` / `<columnbreak />` / `SetColumns()` | CSS колони (`column-count`, `column-gap`, `vAlign` / `vertical-align="justify"` балансира колоните) |
| `mirrorMargins` (ключ в конструктора или `$mpdf->mirrorMargins = 1`) | четните страници разменят левия и десния си margin |
| `<dottab outdent="2em" />` | линия от точки до десния край, текстът след нея е подравнен надясно; `outdent` измества табулацията толкова вляво от десния край, така че текстът след нея завършва там |
| `<textcircle r="30mm" top-text bottom-text space-width char-width divider style>` | текст по окръжност, завъртян знак по знак; `font-size: auto` напасва към обиколката. `r` без мерна единица се чете като милиметри; `char-width` разтяга всеки знак по хоризонтала около собствения му център върху окръжността и разширява и стъпката му (точният център на мащабиране в mPDF не можа да се провери офлайн, това е най-близкият еквивалент) |
| CSS `float: footnote` (CSS GCPM, не е таг на mPDF; тагът `<footnote>..</footnote>` се приема като разширение на IvoPDF и се държи точно като `float: footnote`) | препратка към бележка (горен индекс) + бележка в зона най-долу на страницата с препратката; правила `::footnote-call` / `::footnote-marker` (декларации + `content` с `counter(footnote)`), номерацията започва отначало на всяка страница с `@page { counter-reset: footnote }`; `::footnote-area { margin / padding / border* / background / font* / color / text-align / max-height }` оформя зоната (деклариран `border*` заменя вградената къса линия, `max-height` в % от страницата или дължина заменя ограничението по подразбиране от 40 %); `footnote-display: block \| inline \| compact` и `footnote-policy: auto \| line \| block` върху елемента на бележката (препратка, чиято бележка не се събира, се премества с реда или с абзаца си на следващата страница; `auto` се държи като `line`). Подредбата се повтаря до 24 пъти, докато страниците на препратките се уталожат; след осмия опит страниците вече не намаляват мястото за бележки, а бележки, които пак не се събират, се пропускат с предупреждение вместо да се наслагват върху текста |
| `rotate`, `autosize`, `overflow="shrink"` за таблици / изображения | завъртане чрез `transform`; свиване, за да се побере |
| `<barcode code type size error>` | вижте „Баркодове“ по-долу |
| `WriteHTML($html, $mode)`, `SetDefaultBodyCSS()`, `SetDirectionality()`, `SetCompression()`, `SetDisplayMode()`, `SetJS()`, `AddPage(...)`, `AddPageByArray()`, `SetBasePath()`, `SetDefaultFont()` | поддържат се |
| `SetAutoPageBreak(true, 15)`, `PageNo()`, `DeletePages()`, `MovePages()`, `TOCpagebreak()`, `TOCpagebreakByArray()`, `TOC()`, `SetLeft/Right/TopMargin()`, `AddFontDirectory()`, `DefHeaderByName()` / `SetHeaderByName()`, `Annotation()`, `OutputFile()` / `OutputBinaryData()` / `OutputHttpInline()` / `OutputHttpDownload()`, `SetImportUse()` (не прави нищо) | поддържат се: `SetAutoPageBreak()` задава долното поле (както mPDF; при `false` страниците пак се пренасят, с предупреждение), `PageNo()` рендира написаното досега и връща страницата, на която то свършва, `DeletePages()` / `MovePages()` пренареждат изходните страници (връзките, отметките, бележките и полетата на формуляри ги следват) |
| Рисуване: `Image()`, `WriteFixedPosHTML()`, `Text()` / `WriteText()`, `Line()`, `Rect()`, `Circle()`, `Ellipse()`, `RoundedRect()`, `Arrow()`, `Link()` / `AddLink()` / `SetLink()`, `Rotate()`, `StartTransform()` / `StopTransform()` / `transformRotate/Scale/Translate/Skew()`, `SetAlpha()`, `SetFont()` / `SetFontSize()`, `SetTextColor/FillColor/DrawColor()`, `SetLineWidth()`, `SetDash()`, `GetStringWidth()` | в единиците на mPDF (mm от горния ляв ъгъл) върху *текущата страница*, т.е. страницата, на която свършва написаният досега HTML (след `AddPage()` — новата): в документа се поставя маркер с нулев размер и рисунката се изобразява върху неговата страница, над съдържанието |
| `Cell()`, `MultiCell()`, `Write()`, `Ln()`, `SetXY()` / `SetX()` / `SetY()` | след `SetXY()` / `SetY()` — на тази абсолютна позиция, която се мести като в mPDF; иначе като HTML, добавен в края на документа (тече заедно със съдържанието) |
| всеки друг публичен метод на `Mpdf` | приема се от `__call()`: не прави нищо, връща `null` и добавя по едно предупреждение на метод в `getWarnings()` (имена, които и mPDF няма, пак дават обичайната PHP грешка „undefined method“) |
| ключове в конструктора | `mode`, `tempDir`, `autoScriptToLang`, `autoLangToFont`, `fontDir` + `fontdata`, `margin_header` / `margin_footer`, `showImageErrors`, `PDFA`, `PDFX`, `restrictColorSpace`, `shrink_tables_to_fit`… |

Не се поддържат: `<formfeed>`, `<tts>`, `<setpageheader>` / `<setpagefooter>` (използвайте `SetHeader()` / `SetFooter()`); текст, написан с рисуващия API, не избутва следващия HTML надолу (рисува се върху страницата, както изходът с фиксирана позиция на mPDF).

## Опции

| Опция | По подразбиране | Описание |
|---|---|---|
| `paperSize` | `'A4'` | A0–A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio или `[ширинаPt, височинаPt]` |
| `orientation` | `'portrait'` | `portrait` / `landscape` |
| `margins` | по 36 pt | `['top' => .., 'right' => .., 'bottom' => .., 'left' => ..]`; CSS `@page { margin }` има предимство |
| `defaultFont`, `defaultFontSize` | Inter, 12 | общият `sans-serif` води към Inter; Arial / Helvetica, Times New Roman и Courier New към Liberation Sans / Serif / Mono; `serif` / `monospace` към DejaVu Serif / Sans Mono |
| `defaultBoxSizing` | `content-box` | стандартното за CSS; шаблоните обикновено задават `* { box-sizing: border-box }` |
| `basePath`, `allowedPaths` | `null`, `[]` | папки, от които може да се четат локални изображения, CSS и шрифтове |
| `isRemoteEnabled` | `false` | разрешава `http(s)` ресурси (със защита от SSRF) |
| `maxRemoteBytes`, `maxImagePixels` | 10 MB, 25 MP | лимити на ресурсите; изображение се пропуска (с предупреждение, рендерът продължава) и когато декодирането му не би се събрало в оставащата памет: декодираният размер се оценява от заглавието преди да се декодира и един пиксел, така че малък файл „бомба“ не може да изчерпи `memory_limit` |
| `maxHtmlBytes`, `maxNestingDepth`, `maxWordLength`, `maxPages` | 32 MB, 200, 2000, 5000 | защитни лимити |
| `maxRenderTime` | `120` | секунди, които може да отнеме едно извикване на `render()` / `output()` (разбор, подредба, разделяне на страници, рисуване, включително допълнителните проходи за CSS бележки под линия, mPDF показалец и `NEXT-ODD` прекъсвания); при изтичане се хвърля прихващаемото `IvoPdf\Exception\LimitExceededException` (наследник на `RuntimeException`, `getLimit() === 'time'`). `0` = без лимит |
| `maxMemory` | `'auto'` | памет, която може да ползва един рендер: `'auto'` (90 % от `memory_limit` на PHP), низ като `'256M'` / `'1G'` или байтове (стойности под 1 MB се пренебрегват с предупреждение). При превишаване се хвърля `LimitExceededException` (`getLimit() === 'memory'`); ако PHP има `memory_limit`, лимитът не надвишава 90 % от него, така че изключението идва преди неприхващаемата фатална грешка на PHP. Паметта се мери така, както разпределителят на PHP я брои спрямо `memory_limit` (`memory_get_usage(true)`); стъпките, които заделят много наведнъж, се проверяват преди да започнат: HTML парсерът отказва вход, чието дърво не би се събрало (около 1 KB на таг при PHP ≥ 8.4), а изображенията се пропускат, както е описано при `maxImagePixels`. `0` = изключено (тези предварителни проверки пак спазват `memory_limit` на PHP) |
| `maxBarcodeLength` | `5000` | най-дълга стойност на `<barcode>`; по-дългите се пропускат с предупреждение (`0` = без лимит) |
| `html5Parser` | `true` | HTML5 парсер на WHATWG (PHP ≥ 8.4); `false` ползва HTML4 парсера на libxml. При libxml (PHP < 8.4) изходният код първо се доближава до HTML5: символни препратки без точка и запетая (`&nbsp`, `&amp`, `&copy`, наследеният списък на HTML5) и имена само от HTML5, непознатите препратки остават като текст, `<![CDATA[..]]>` извън SVG отпада, `</br>`, `<section>`/`<article>`/... затварят отворен `<p>`, `<form>` около редове на таблица, `<td>` направо в `<table>` |
| `inputEncoding` | `'auto'` | `auto`: BOM, после `<meta charset>`, после валиден UTF-8; стари байтове без meta се четат като Windows-1251, ако приличат на кирилица, иначе като Windows-1252. За други стари файлове без meta я задайте изрично (напр. `'ISO-8859-2'`, `'KOI8-R'`, `'Shift_JIS'`) |
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

Езикът на текста (`<html lang="bg">` или `lang` на всеки елемент) избира локалните форми на буквите в шрифта (OpenType `locl`), както в браузъра. За българска типография ползвайте вградения Sofia Sans, който рисува български форми на кирилицата по подразбиране (а руски при `lang="ru"`); Semi Condensed събира повече текст на ред:

```css
body { font-family: 'Sofia Sans', sans-serif; }
table, .compact { font-family: 'Sofia Sans Semi Condensed', sans-serif; }
```

По същия начин `lang="sr"` / `lang="mk"` дават сръбски / македонски форми в DejaVu и Liberation. Inter няма български форми.

`registerFontFamily()` проверява файловете си: липсващ или нечетим файл (или UNC / URL път) хвърля `InvalidArgumentException` с името му, вместо тихо да се ползва DejaVu Sans; шрифт, който не може да се прочете при рендиране, се заменя с DejaVu Sans и предупреждение. Задължителен е само `regular`: когато семейството няма получер или курсивен вариант, получерният се синтезира с обвеждане на контурите на знаците, а курсивът — с наклон 14° (както в браузърите), без предупреждение.

CSS `@font-face` работи с `.ttf` / `.otf` източници (data URI, разрешен локален път или отдалечен, ако е включено); шрифтовете от data URI и изтеглените се държат в паметта, без временни файлове. Азиатските и индийските писмености изискват шрифтове, които не са включени в проекта (например шрифтовете Noto на Google, с лиценз OFL):

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

HTML `<input type="text|email|number|date|password|checkbox|radio">`, `<textarea>`, `<select>` и `<button>` стават попълваеми PDF полета с текущите си стойности (включително на кирилица). Подсказката `placeholder` на празно поле се отпечатва в сиво, както я показва браузърът; тя е съдържание на страницата, а не стойност на полето, и полето получава непрозрачен фон (`/MK /BG`), който я скрива, щом се въведе стойност. Радио бутоните с еднакво име образуват една група; `required`, `readonly` и `disabled` задават флаговете на полето; формуляр с `http(s)` `action` получава бутон за изпращане.

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
- Нечетим сертификат, ключ или `.p12` файл (или грешна парола на ключа) хвърля `InvalidArgumentException` с причината от OpenSSL в съобщението; PHP не издава предупреждение.
- PDF/A-1b е истински ISO 19005-1 (PDF 1.4, `/CIDSet`, без прозрачност: полупрозрачните цветове стават плътни); 2b и 3b запазват прозрачността. `validatePdfA()` е евристична проверка и **не** замества veraPDF.
- При всяко ниво на PDF/A изображенията се записват с `/Interpolate false`, а изрязаните шрифтове пазят ширина за всеки използван знак, така че съвпадат с `/W`. `validatePdfA()` проверява и двете неща за TrueType шрифтове; при OpenType/CFF (`.otf`) не сравнява ширините на знаците.
- PDF/A не може да се комбинира с криптиране; PDF/X не може да се комбинира с PDF/A, криптиране или подпис.
- Пароли: AES-256 (по подразбиране, ISO 32000-2) приема всяка Unicode парола. AES-128 и RC4 (`'aes128'`, `'rc4'`, `SetProtection(…, 128)` на mPDF) са дефинирани върху байтове в PDFDocEncoding (ISO 32000-1 7.6.3.3, алгоритъм 2): IvoPDF преобразува паролата така, както Acrobat, qpdf и MuPDF преобразуват въведена парола (`'ümlaut'` и `'€'` се отварят във всеки четец). Парола със знаци извън PDFDocEncoding (кирилица, гръцки, CJK…) не може да се въведе в никой четец при тези методи, затова — както qpdf — `setProtection()` хвърля `InvalidArgumentException`, който препоръчва `'aes256'`.
- При PDF/A всяка `<annotation>` (бележка) получава поток за изглед (и е непрозрачна в PDF/A-1), така че документи с бележки минават veraPDF 1b/2b/3b. Полетата на формуляри още ползват невградените стандартни шрифтове и `/NeedAppearances`: рендирането добавя предупреждение, `validatePdfA()` го отчита, а `formFields => false` отпечатва полетата като статично съдържание, което минава проверката.
- Локални файлове се четат само от обикновени локални пътища: UNC пътища и пътища без протокол (`\\host\share\x.png`, `//host/x.png`, `\\?\UNC\…`) и stream wrapper-и (`file://`, `ftp://`, `phar://`…) никога не стигат до файловите функции (под Windows те биха отворили SMB връзка към хоста). `//host/x.png` в HTML / CSS е URL без протокол: изтегля се като `https://host/x.png`, когато `isRemoteEnabled` е включено (със защитата от SSRF), и се пренебрегва иначе; `loadHtmlFile()`, `appendPdf()`, `setPageTemplate()`, `registerFontFamily()`, ICC профилът за PDF/X и `loadHtmlFile()` на dompdf отказват такива пътища с изключение.

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
- При писане от дясно наляво flex и grid не се огледализират (таблиците се подреждат от дясно наляво).
- Когато `min-width` е по-голямо от `max-width`, печели `min-width` (както в CSS и браузърите).
- PDF/A и PDF/X са проверени структурно, не с veraPDF или професионален инструмент за предпечат.
- Полетата на формулярите ползват невградените стандартни шрифтове Helvetica / ZapfDingbats, затова документи с формуляри не минават PDF/A.
- Шрифтове от `.ttc` колекции не се поддържат в CSS `@font-face` (използвайте `registerFontFamily()`).
- Рисуващият API на mPDF рисува върху страницата (вижте таблицата за mPDF по-горе): `Cell()` / `Write()` без `SetXY()` стават HTML, `SetLink()` пренебрегва своя `$page`, `Rotate()` и помощниците `transform*()` ползват височината на страницата по подразбиране за центъра си, когато страниците са с различен размер.
- Извличане на текст: знаци, нарисувани без шрифт (.notdef), и копията на `text-shadow` се маркират с `/ActualText` / `/Artifact`, които Acrobat, MuPDF и PDFium зачитат; pdfminer и pypdf пренебрегват маркираното съдържание (показват `(cid:0)` / текста на сянката втори път).
- Бележки под линия: препратка в клетка на таблица или в многоколонен контейнер се слага на страницата на своя ред; една бележка, по-висока от мястото на страницата (`::footnote-area { max-height }`), се отрязва с предупреждение; бележка никога не продължава на следващата страница.
- `Canvas` на dompdf: `add_object($id, 'all')` след `render()` стига само до последната страница (както в dompdf); рисуването от `begin_page_render` се изобразява преди съдържанието на страницата, така че плътен фон на `<html>` / `<body>` го закрива (както в dompdf).
- Дума, по-широка от кутията си, излиза извън нея на отделен ред (`overflow-wrap: normal`), както в браузъра и в dompdf (mPDF я разделя); `overflow-wrap: anywhere | break-word` (и старото `word-wrap: break-word`) и `word-break: break-all | break-word` я пренасят вътре в кутията. Колоните на таблиците вместо това се разширяват до най-дългата дума.
- Текст без възможност за пренасяне, по-дълъг от `Options::$maxWordLength` (2000 знака по подразбиране, `0` = изключено), се разделя с предупреждение; при изключена защита такава дума пак се подрежда с линейно време и памет.
- Абсурдни дължини от непроверен вход се ограничават до 14 400 pt (200 инча, най-голямата страница, която PDF може да опише): размери на шрифта, дебелини на рамки, отстъпи, височини на ред и размери на изображения, така че `font-size: 1e9pt` или `<img width="1e9">` дават няколко десетки страници вместо 5 000. Полетата (margin) и височините на кутиите не се ограничават.

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
- Вградените чужди данни запазват собствения си свободен лиценз: шрифтовете Inter (SIL Open Font License 1.1, [resources/fonts/LICENSE-Inter.txt](resources/fonts/LICENSE-Inter.txt)), шрифтовете Liberation (SIL Open Font License 1.1, [resources/fonts/LICENSE-Liberation.txt](resources/fonts/LICENSE-Liberation.txt)), шрифтовете Sofia Sans на Lettersoup (SIL Open Font License 1.1, [resources/fonts/LICENSE-SofiaSans.txt](resources/fonts/LICENSE-SofiaSans.txt); статични варианти на вариативните шрифтове), шрифтовете DejaVu ([resources/fonts/LICENSE-DejaVu.txt](resources/fonts/LICENSE-DejaVu.txt)) и TeX шаблоните за пренасяне (лицензите са в началото на файловете в `resources/hyphenation/`).

## Принос

Вижте [CONTRIBUTING.md](CONTRIBUTING.md) (на английски). Всеки, който допринася, подписва [CLA](CLA.md) веднъж, при първия си pull request.
