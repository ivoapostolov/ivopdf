# IvoPDF CSS & HTML support

## Contents
1. Layout
2. Text
3. Selectors & cascade
4. Visual effects
5. Images & SVG
6. Tables
7. Paged media
8. Known limitations and workarounds

## 1. Layout
- Block, inline, inline-block, `list-item`, `float: left|right` + `clear` (floats placed at the line where they occur, text returns to full width below them; `float`/absolute on an inline element makes it a block), `position: relative|absolute|fixed` (fixed is placed in the page area and repeats on every page; absolute uses the nearest positioned ancestor), `z-index`.
- Browser default styles (checked against Edge): body margin 8px, `line-height: normal` from font metrics, list indent 40px, figure/blockquote margins, centered `th`.
- Flexbox: `flex-direction` (incl. reverse), `flex-wrap`, `flex` / `flex-grow|shrink|basis`, `gap`, `order`, `justify-content` (start/end/center/space-between/around/evenly), `align-items`, `align-self`, auto margins.
- Grid: `grid-template-columns|rows` with `fr`, `px/pt/%`, `auto`, `repeat(N, …)`, `repeat(auto-fit|auto-fill, minmax(…))`, `minmax()`; `grid-template-areas`, `grid-area`, named lines, `grid-template`/`grid` shorthands; `grid-column|row: span N`, `1 / -1`; auto-placement incl. `dense` and column flow; `gap`.
- Sizing: `width/height` (incl. % of a definite height; a definite height is kept and content overflows, as in browsers), `min-*`, `max-*`, `aspect-ratio`, `width: fit-content|max-content|min-content`, `calc()`/`min()`/`max()`/`clamp()`, `box-sizing`, margins incl. `auto` centering, negative margins, margin collapsing.
- Multi-column: `column-count`, `column-width`, `columns`, `column-gap`, `column-rule`, `column-span: all`, `break-inside: avoid`, balanced and continued across pages. `display: contents`, `inline-table`, `table-layout: fixed`.
- Units: pt, px, mm, cm, in, pc, em, rem, ex, ch, %, vw, vh.

## 2. Text
- Fonts: see api.md. `font` shorthand, `font-weight` (≥600 = bold), `font-style`.
- `text-align` (left/center/right/justify), `text-indent`, `line-height`, `letter-spacing`, `word-spacing`, `text-transform` (uppercase/lowercase/capitalize), `white-space` (normal/nowrap/pre/pre-wrap/pre-line), `text-overflow: ellipsis` (with `overflow:hidden; white-space:nowrap`), `vertical-align` (baseline/sub/super/top/middle/bottom/length).
- `text-decoration` underline / overline / line-through + `text-decoration-color`.
- `&nbsp;` never breaks; `<wbr>` and `&shy;` are break opportunities; `hyphens: auto` with `lang` (TeX patterns, incl. Bulgarian); `tab-size`. A word wider than its box overflows like in browsers unless `overflow-wrap: anywhere|break-word` or `word-break: break-all`, but never past the page: there it is broken (`Options::$pageOverflowWrap`, default on; also `pre` / `nowrap` lines and table columns).
- Backgrounds, borders, padding and border-radius on inline elements (badges, `<mark>`, `<code>`) are drawn as one continuous box per line.
- Generated content: `::before` / `::after` with `content` strings, `attr()`, `counter()`/`counters()`, `open-quote`/`close-quote`; `counter-reset`/`counter-increment`; `<q>` gets quotes automatically.
- Lists: `list-style-type` decimal, decimal-leading-zero, lower/upper-alpha, lower/upper-roman, lower/upper-greek, armenian, georgian, hebrew, cjk-decimal, cjk-ideographic, cyrillic, disc, circle, square, none; `list-style` shorthand; `<ol start reversed type>`, `<li value>`.
- `font` shorthand (any keyword order, `small-caps`), synthesized small caps, `::first-letter` (drop caps), `::first-line`.

## 3. Selectors & cascade
Type, class, id, `*`, attributes (`[a]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`, `i` flag), combinators (descendant, `>`, `+`, `~`), `:first/last/only-child`, `:nth-child()`/`:nth-last-child()`/`:nth-of-type()`/`:nth-last-of-type()`, `:first/last/only-of-type`, `:not()`, `:is()`, `:where()`, `:has()`, `:empty`, `:root`, `:lang()`, `:dir()`, `:link`, `:checked`, `:disabled`. Specificity and `!important` follow CSS. `:hover`, `:focus` etc. never match (print).
`var()` custom properties with fallbacks, `inherit`, `initial`, `unset`, `currentColor`.
At-rules: `@media` (print/all, `min-/max-width|height`, `orientation`, range syntax `(width >= 600px)`; `screen` never matches), `@supports` (contents applied), `@page` (size, margin), `@font-face`. `<style media>` and `<link media>` honoured.

## 4. Visual effects
`background-color`, `background-image` (url / `linear-gradient()` / `radial-gradient()` with color-stop positions), `background-size` (cover/contain/lengths), `background-position`, `background-repeat`, `background` shorthand (resets longhands like browsers), `border` per side (solid/dashed/dotted/double approx.), `border-radius` per corner, all border styles incl. double/groove/ridge/inset/outset, `border-image`, `outline`, multiple `box-shadow`s, `text-shadow`, `opacity` (group), `mix-blend-mode`, `clip-path` (inset/circle/ellipse/polygon), image `filter`s, `transform` lists (translate/X/Y, scale/X/Y, rotate, skew/X/Y, matrix) with `transform-origin`, `overflow: hidden|clip` clipping (incl. rounded).

## 5. Images & SVG
- `<img>`: JPEG (incl. CMYK, EXIF orientation), PNG (alpha, palette, interlaced, 16-bit), GIF, WebP (needs GD), BMP, TIFF, SVG, WMF/EMF (as vectors), a page of a PDF (`<img src="x.pdf" data-page="2">`). `object-fit` (fill/contain/cover/none/scale-down) + `object-position`. Width/height attributes respected.
- Inline `<svg>`: rect, circle, ellipse, line, polyline, polygon, path (all commands incl. arcs), text/tspan, `<g>`, `<use>`/`<symbol>`, `transform`, `viewBox`, `preserveAspectRatio`, fill/stroke/opacity, dash arrays, line caps/joins, `fill-rule`, real linear/radial gradients, patterns, clipPath, mask, markers, `<style>` and `<image>`. No SVG filters.

## 6. Tables
`thead/tbody/tfoot` (repeated on each page), `colspan`, `rowspan`, `<caption>` with `caption-side: top|bottom`, `<colgroup>/<col>` widths, `border-collapse: collapse|separate` (browser default separate with 2px spacing; collapsed borders resolve the winning border), `border-spacing`, `cellpadding`/`cellspacing`/`border`/`bgcolor`/`align`/`valign`/`nowrap` attributes, cell `vertical-align` (default middle), shrink-to-fit width without `width` (use `width:100%` for full width), `margin: auto` / `align` centering, automatic column widths, rows never split across pages, RTL tables mirrored.

## 7. Paged media
`@page` size (incl. one length = square, named sizes + orientation, named pages) + margins + margin boxes (`@top-center` …); `page-break-before|after: always|avoid|left|right`, `break-before|after: page|recto|verso`, `page-break-inside|break-inside: avoid`, `<pagebreak />`; `orphans`/`widows` (default 2); headings kept with following content; running header/footer; CSS footnotes (`float: footnote`, `::footnote-area`); bookmarks from headings; internal `#id` links to any element (also inline, images, `<a name>`).
Elements: `<details>`/`<summary>` (closed shows only the summary), `<noscript>` hidden, form controls incl. placeholder.

## 8. Known limitations and workarounds
- Vertical writing (`writing-mode: vertical-*`), hanging punctuation and SVG filters are not supported.
- RTL text works (bidi, Arabic, Hebrew; tables are mirrored), but flex and grid are not mirrored for `direction: rtl` → order the items yourself.
- Asian / Indic scripts need a registered font (`scriptFonts`, `registerFontFamily`); characters without any font are drawn as empty boxes but still copy correctly.
- Form fields use non-embedded Helvetica / ZapfDingbats → documents with forms do not pass PDF/A; use `formFields => false`.
- A tall inline-block that crosses a page break is split between its lines (Edge cuts the line in half).
- Footnotes: a note is never continued on the next page; a call inside a table cell or columns is placed on its line's page.
- Layout is not a browser: very complex web CSS (sticky, animations, viewport tricks) is irrelevant or ignored; write print-oriented CSS.
