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
- Block, inline, inline-block, `list-item`, `float: left|right` + `clear`, `position: relative|absolute|fixed` (fixed repeats on every page), `z-index`.
- Flexbox: `flex-direction` (incl. reverse), `flex-wrap`, `flex` / `flex-grow|shrink|basis`, `gap`, `order`, `justify-content` (start/end/center/space-between/around/evenly), `align-items`, `align-self`, auto margins.
- Grid: `grid-template-columns|rows` with `fr`, `px/pt/%`, `auto`, `repeat(N, …)`, `repeat(auto-fit|auto-fill, minmax(…))`, `minmax()`; `grid-column|row: span N`, `1 / -1`; auto-placement; `gap`.
- Sizing: `width/height` (incl. % of a definite height), `min-*`, `max-*`, `aspect-ratio`, `width: fit-content|max-content|min-content`, `calc()`, `box-sizing`, margins incl. `auto` centering, negative margins.
- Multi-column: `column-count`, `column-width`, `columns`, `column-gap`, `column-rule`, `column-span: all`, `break-inside: avoid`, balanced and continued across pages. `display: contents`, `inline-table`, `table-layout: fixed`.
- Units: pt, px, mm, cm, in, pc, em, rem, %, vw, vh.

## 2. Text
- Fonts: see api.md. `font` shorthand, `font-weight` (≥600 = bold), `font-style`.
- `text-align` (left/center/right/justify), `text-indent`, `line-height`, `letter-spacing`, `word-spacing`, `text-transform` (uppercase/lowercase/capitalize), `white-space` (normal/nowrap/pre/pre-wrap/pre-line), `text-overflow: ellipsis` (with `overflow:hidden; white-space:nowrap`), `vertical-align` (baseline/sub/super/top/middle/bottom/length).
- `text-decoration` underline / overline / line-through + `text-decoration-color`.
- `&nbsp;` never breaks; long words break after hyphens or by character when they cannot fit.
- Backgrounds, borders, padding and border-radius on inline elements (badges, `<mark>`, `<code>`) are drawn as one continuous box per line.
- Generated content: `::before` / `::after` with `content` strings, `attr()`, `counter()`/`counters()`, `open-quote`/`close-quote`; `counter-reset`/`counter-increment`; `<q>` gets quotes automatically.
- Lists: `list-style-type` decimal, decimal-leading-zero, lower/upper-alpha, lower/upper-roman, lower-greek, cyrillic, disc, circle, square, none; `list-style` shorthand; `<ol start reversed>`, `<li value>`.

## 3. Selectors & cascade
Type, class, id, `*`, attributes (`[a]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`, `i` flag), combinators (descendant, `>`, `+`, `~`), `:first/last/only-child`, `:nth-child()`/`:nth-last-child()`/`:nth-of-type()`/`:nth-last-of-type()`, `:first/last/only-of-type`, `:not()`, `:is()`, `:where()`, `:has()`, `:empty`, `:root`, `:lang()`, `:link`, `:checked`, `:disabled`. Specificity and `!important` follow CSS. `:hover`, `:focus` etc. never match (print).
`var()` custom properties with fallbacks, `inherit`, `initial`, `unset`, `currentColor`.
At-rules: `@media` (print/all, `min-/max-width|height`, `orientation`, range syntax `(width >= 600px)`; `screen` never matches), `@supports` (contents applied), `@page` (size, margin), `@font-face`. `<style media>` and `<link media>` honoured.

## 4. Visual effects
`background-color`, `background-image` (url / `linear-gradient()` / `radial-gradient()` with color-stop positions), `background-size` (cover/contain/lengths), `background-position`, `background-repeat`, `background` shorthand (resets longhands like browsers), `border` per side (solid/dashed/dotted/double approx.), `border-radius` per corner, all border styles incl. double/groove/ridge/inset/outset, `border-image`, `outline`, multiple `box-shadow`s, `text-shadow`, `opacity` (group), `mix-blend-mode`, `clip-path` (inset/circle/ellipse/polygon), image `filter`s, `transform` lists (translate/X/Y, scale/X/Y, rotate, skew/X/Y, matrix) with `transform-origin`, `overflow: hidden|clip` clipping (incl. rounded).

## 5. Images & SVG
- `<img>`: JPEG (incl. CMYK, EXIF orientation), PNG (alpha, palette, interlaced, 16-bit), GIF, WebP (needs GD), BMP, TIFF, SVG, WMF/EMF (as vectors), a page of a PDF (`<img src="x.pdf" data-page="2">`). `object-fit` (fill/contain/cover/none/scale-down) + `object-position`. Width/height attributes respected.
- Inline `<svg>`: rect, circle, ellipse, line, polyline, polygon, path (all commands incl. arcs), text/tspan, `<g>`, `<use>`/`<symbol>`, `transform`, `viewBox`, `preserveAspectRatio`, fill/stroke/opacity, dash arrays, line caps/joins, `fill-rule`, real linear/radial gradients, patterns, clipPath, mask, markers, `<style>` and `<image>`. No SVG filters.

## 6. Tables
`thead/tbody/tfoot`, `colspan`, `rowspan`, `<caption>`, `<colgroup>/<col>` widths, `border-collapse: collapse|separate`, `border-spacing`, `cellpadding`/`cellspacing`/`border`/`bgcolor`/`align`/`valign`/`nowrap` attributes, cell `vertical-align` (default middle), automatic column widths from content, rows never split across pages, `<thead>` repeated on each page.

## 7. Paged media
`@page` size + margins; `page-break-before|after: always|avoid`, `break-before|after: page`, `page-break-inside|break-inside: avoid`, `<pagebreak />`; headings kept with following content; single orphan lines avoided; running header/footer; bookmarks from headings; internal `#id` links.

## 8. Known limitations and workarounds
- Vertical writing (`writing-mode: vertical-*`), hanging punctuation and SVG filters are not supported.
- RTL text works (bidi, Arabic, Hebrew), but tables, flex and grid are not mirrored for `direction: rtl` → order the cells yourself.
- Asian / Indic scripts need a registered font (`scriptFonts`, `registerFontFamily`); nothing is bundled except DejaVu.
- Form fields use non-embedded Helvetica / ZapfDingbats → documents with forms do not pass PDF/A.
- Layout is not a browser: very complex web CSS (sticky, animations, viewport tricks) is irrelevant or ignored; write print-oriented CSS.
