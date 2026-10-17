<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\Image\ImageLoader;
use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LayoutEngine;
use IvoPdf\Layout\Paginator;
use IvoPdf\Pdf\PdfDocument;
use RuntimeException;

/**
 * mPDF compatibility layer for IvoPdf (used via `use MpdfApi;`): custom-tag support (see MpdfTags), named / switched
 * running headers and footers, page-number styles, image watermarks, plus the mPDF methods that have no IvoPDF
 * counterpart (SetHeader / SetFooter strings, SetDisplayMode, SetJS, Bookmark, TOC_Entry, IndexEntry, ...).
 *
 * @property \IvoPdf\Options $options
 * @property ?string $renderedPdf
 * @property string $html
 */
trait MpdfApi
{
    private ?MpdfState $mpdfState = null;
    /** @var array{ix: array<string, string>, blank: array<string, bool>} results of the previous render pass */
    private array $mpdfMem = ['ix' => [], 'blank' => []];
    /** @var ?array{ix: array<string, string>, blank: array<string, bool>} */
    private ?array $mpdfNextMem = null;
    private string $mpdfHeadCss = '';
    /** @var array<string, string> */
    private array $mpdfBodyCss = [];

    /** Whether the document came through WriteHTML() (mPDF defaults) rather than loadHtml() (browser defaults) */
    private bool $mpdfWritten = false;
    private string $mpdfZoom = '';
    private string $mpdfLayout = '';
    private string $mpdfJs = '';
    private bool $mpdfRtl = false;
    /** @var ?array{src: string, alpha: float, size: string, pos: string} */
    private ?array $mpdfWatermarkImage = null;
    /** @var array<string, string> */
    private array $mpdfDefHeaders = [];
    /** @var array<string, string> */
    private array $mpdfDefFooters = [];

    /** Registers mPDF `fontdata` families (called from the constructor). */
    private function mpdfInit(): void
    {
        MpdfConfig::registerFonts($this->options, $this->fontManager);
    }

    // ------------------------------------------------------------------
    // Rendering (multi-pass: index page numbers and NEXT-ODD pages depend on the pagination)
    // ------------------------------------------------------------------

    /**
     * Parses HTML & CSS, lays out, paginates and compiles the PDF. Documents with mPDF index entries or
     * `type="NEXT-ODD"` page breaks are laid out again (at most four times) until their page numbers are stable.
     */
    public function render(): self
    {
        $this->mpdfMem = ['ix' => [], 'blank' => []];
        $this->footnoteMem = [];
        $this->footnoteState = ['push' => [], 'floor' => [], 'ratchet' => false, 'freeze' => false, 'changed' => false, 'last' => false, 'memo' => []];
        $savedWm = [$this->options->watermarkText, $this->options->watermarkOpacity];
        try {
            // CSS footnotes may need more passes to settle; from the ninth on every page keeps the largest room it has needed, from the thirteenth on pushed calls stay pushed
            for ($pass = 0; $pass < 24; $pass++) {
                $this->footnoteState['ratchet'] = $pass >= 8;
                $this->footnoteState['freeze'] = $pass >= 12;
                $this->footnoteState['changed'] = false;
                $this->footnoteState['last'] = $pass === 23;
                $this->renderCore();
                $again = false;
                $next = $this->mpdfNextMem;
                if ($next !== null && $next != $this->mpdfMem) {
                    $this->mpdfMem = $next;
                    $again = true;
                }
                // CSS footnotes: the pages of the calls decide how much room every page reserves
                if ($this->footnoteNext !== null && ($this->footnoteNext != $this->footnoteMem || $this->footnoteState['changed'])) {
                    $this->footnoteMem = $this->footnoteNext;
                    $again = true;
                }
                if (!$again || ($pass >= 3 && $this->footnoteNext === null)) {
                    break;
                }
            }
        } finally {
            [$this->options->watermarkText, $this->options->watermarkOpacity] = $savedWm;
        }
        if (!empty($this->options->compat['showImageErrors'])) {
            foreach ($this->options->getWarnings() as $w) {
                if (str_starts_with($w, 'Image not found') || str_starts_with($w, 'Unsupported or corrupt image')) {
                    throw new RuntimeException($w);
                }
            }
        }
        return $this;
    }

    /** HTML pre-pass: mPDF tags -> plain HTML/CSS, plus the CSS collected by WriteHTML(HEADER_CSS) / SetDefaultBodyCSS. */
    private function mpdfTransform(string $html): string
    {
        $state = new MpdfState();
        $state->headers = $this->mpdfDefHeaders;
        $state->footers = $this->mpdfDefFooters;
        $state->defaultFont = $this->options->defaultFont;
        $state->measure = fn(string $t, string $family, string $weight, string $style, float $pt): float => $this->fontManager->measureText($t, $family, $weight, $style, $pt);
        $this->mpdfNextMem = null;
        $docCss = '';
        if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $styleBlocks)) {
            $docCss = implode(' ', $styleBlocks[1]);
        }
        $state->docCss = trim($docCss . ' ' . $this->mpdfHeadCss);
        $out = MpdfTags::transform($html, $state, $this->mpdfMem);
        $this->mpdfState = $state;

        // Documents written through the mPDF API get mPDF's default body margin, 0 (browsers and dompdf: 8px)
        $css = $this->mpdfWritten ? 'body{margin:0}' : '';
        $body = $this->mpdfBodyCss;
        if ($this->mpdfRtl) {
            $body['direction'] = 'rtl';
        }
        if ($body !== []) {
            $decl = '';
            foreach ($body as $k => $v) {
                $decl .= $k . ':' . $v . ';';
            }
            $css .= 'body{' . $decl . '}';
        }
        $css .= $this->mpdfHeadCss;
        if ($css !== '') {
            $out = MpdfTags::injectStyle($out, $css);
        }
        if ($state->watermarkText !== null) {
            $this->options->watermarkText = $state->watermarkText['content'];
            $this->options->watermarkOpacity = $state->watermarkText['alpha'];
        }
        return $out;
    }

    /**
     * Called after pagination: maps the marker elements to pages and resolves header / footer / page-number decisions.
     *
     * @param array<string, float> $targets element id => virtual y
     */
    private function mpdfAfterPaginate(array $targets, Paginator $paginator, int $totalPages): void
    {
        $st = $this->mpdfState;
        if ($st === null) {
            return;
        }
        foreach ($targets as $id => $y) {
            if (str_starts_with((string) $id, 'ivo-')) {
                $st->markerPages[$id] = $paginator->pageOfY($y);
                $st->markerY[$id] = $y;
            }
        }
        MpdfRenderHooks::$markerPages = $st->markerPages;
        $st->resolve($totalPages, $paginator->getPageBounds(0)[0]);

        $ix = [];
        foreach (array_keys($st->indexEntries) as $id) {
            if (isset($st->markerPages[$id])) {
                $ix[$id] = $st->pageLabel($st->markerPages[$id]);
            }
        }
        $blank = [];
        foreach ($st->events as $e) {
            $id = (string) $e['id'];
            if (empty($e['type']) || !isset($st->markerPages[$id])) {
                continue;
            }
            $had = !empty($this->mpdfMem['blank'][$id]) ? 1 : 0;
            $oddWithout = (($st->markerPages[$id] + 1 - $had) % 2) === 1;
            if ($oddWithout !== ($e['type'] === 'NEXT-ODD')) {
                $blank[$id] = true;
            }
        }
        $this->mpdfNextMem = ['ix' => $ix, 'blank' => $blank];
    }

    /**
     * Header / footer HTML for a 0-based page after applying `<sethtmlpageheader>`, `<pagebreak odd-header-name>` and
     * `@page { header: html_x }`; `{PAGENO}` shows the mPDF page label (resetpagenum / pagenumstyle / suppress).
     */
    private function mpdfRunning(string $kind, int $page, ?string $fallback): ?string
    {
        $st = $this->mpdfState;
        if ($st === null) {
            return $fallback;
        }
        $name = $kind === 'header' ? $st->headerName($page) : $st->footerName($page);
        $html = $fallback;
        if ($name !== null) {
            $defs = $kind === 'header' ? $st->headers : $st->footers;
            if ($name === '') {
                $html = '';
            } elseif (isset($defs[$name])) {
                $html = $st->withDocCss($defs[$name]);
            } else {
                $this->options->addWarning("mPDF: unknown html{$kind} '{$name}'");
            }
        }
        if ($html === null || $html === '') {
            return $html;
        }
        $html = strtr($html, ['{nb}' => '{PAGE_COUNT}']);
        if ($st->usesPageLabels()) {
            $label = htmlspecialchars($st->pageLabel($page), ENT_QUOTES, 'UTF-8');
            $html = strtr($html, ['{PAGENO}' => $label, '{PAGE_NUM}' => $label]);
        }
        return $html;
    }

    /**
     * All header / footer variants that can appear on some page (their tallest decides the reserved space).
     *
     * @return list<?string>
     */
    private function mpdfCandidates(string $kind, ?string $fallback): array
    {
        $out = [$fallback];
        if ($this->mpdfState !== null) {
            foreach ($kind === 'header' ? $this->mpdfState->headers : $this->mpdfState->footers as $html) {
                $out[] = strtr($this->mpdfState->withDocCss($html), ['{nb}' => '{PAGE_COUNT}']);
            }
        }
        return $out;
    }

    /** Top of the page header in pt (mPDF `margin_header`, otherwise IvoPDF's automatic offset). */
    private function mpdfHeaderY(float $topMargin): float
    {
        $mh = $this->options->compat['margin_header'] ?? null;
        return is_float($mh) ? max(0.0, $mh) : min(max(8.0, $topMargin * 0.25), 24.0);
    }

    /** Space a footer of the given height needs below the body (mPDF `margin_footer` + footer + gap). */
    private function mpdfFooterReserve(float $footerHeight): float
    {
        $mf = $this->options->compat['margin_footer'] ?? null;
        return $footerHeight + (is_float($mf) ? max(0.0, $mf) + 4.0 : 14.0);
    }

    /** Y of the footer's top edge on a page. */
    private function mpdfFooterY(float $pageHeight, float $effectiveBottom, float $footerHeight): float
    {
        $mf = $this->options->compat['margin_footer'] ?? null;
        return is_float($mf) ? $pageHeight - max(0.0, $mf) - $footerHeight : $pageHeight - $effectiveBottom + 4.0;
    }

    /**
     * Extra overlay layers of a page: the image watermark (`<watermarkimage>` / SetWatermarkImage).
     *
     * @param array{w: float, h: float, m: array{top: float, right: float, bottom: float, left: float}} $geo
     * @return list<LayoutBox>
     */
    private function mpdfOverlayLayers(LayoutEngine $engine, array $geo): array
    {
        $wm = $this->mpdfState?->watermarkImage ?? $this->mpdfWatermarkImage;
        if ($wm === null) {
            return [];
        }
        $W = $geo['w'];
        $H = $geo['h'];
        $m = $geo['m'];
        $src = htmlspecialchars($wm['src'], ENT_QUOTES, 'UTF-8');
        $alpha = sprintf('%.3F', $wm['alpha']);
        $size = strtoupper(trim($wm['size']));
        $pos = strtoupper(trim($wm['pos']));
        $mm = 72 / 25.4;
        // Area the watermark is centred in: printable area ('P') or the full page ('F')
        [$ax, $ay, $aw, $ah] = $pos === 'F' ? [0.0, 0.0, $W, $H] : [$m['left'], $m['top'], $W - $m['left'] - $m['right'], $H - $m['top'] - $m['bottom']];

        if (preg_match('~^\s*([\d.]+)\s*,\s*([\d.]+)\s*$~', $wm['pos'], $pm) === 1) {
            $inner = sprintf('<img src="%s" style="position:absolute;left:%.2Fpt;top:%.2Fpt;%s;opacity:%s">', $src, (float) $pm[1] * $mm, (float) $pm[2] * $mm, $this->mpdfWmSize($size, $W, $H, true), $alpha);
        } else {
            $imgStyle = $this->mpdfWmSize($size, $aw, $ah, false);
            $inner = sprintf(
                '<div style="position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;display:flex;align-items:center;justify-content:center;opacity:%s"><img src="%s" style="%s"></div>',
                $ax,
                $ay,
                $aw,
                $ah,
                $alpha,
                $src,
                $imgStyle
            );
        }
        $html = sprintf('<div style="position:relative;width:%.2Fpt;height:%.2Fpt;margin:0;padding:0">%s</div>', $W, $H, $inner);
        return [$this->layoutHtmlBox($html, $engine, 0.0, 0.0, $W)];
    }

    /** CSS sizing for the watermark image: D (natural size), P (fit the printable area), F (fit the page), [w,h] in mm. */
    private function mpdfWmSize(string $size, float $w, float $h, bool $absolute): string
    {
        if (preg_match('~^\s*([\d.]+)\s*,\s*([\d.]+)\s*$~', $size, $sm) === 1) {
            return sprintf('width:%.2Fpt;height:%.2Fpt', (float) $sm[1] * 72 / 25.4, (float) $sm[2] * 72 / 25.4);
        }
        if (($size === 'P' || $size === 'F') && !$absolute) {
            return sprintf('width:%.2Fpt;height:%.2Fpt;object-fit:contain', $w, $h);
        }
        return 'max-width:100%';
    }

    /** Applies SetDisplayMode / SetJS to the catalog. */
    private function mpdfConfigureDocument(PdfDocument $pdf): void
    {
        if ($this->mpdfZoom !== '' || $this->mpdfLayout !== '') {
            $pdf->setViewerPreferences($this->mpdfZoom, $this->mpdfLayout);
        }
        if ($this->mpdfJs !== '') {
            if ($this->options->pdfa !== '') {
                $this->options->addWarning('SetJS ignored: JavaScript is not allowed in PDF/A');
            } else {
                $pdf->setDocumentJavaScript($this->mpdfJs);
            }
        }
    }

    /** Stores unknown mPDF properties assigned as `$mpdf->name = value` (shrink_tables_to_fit, showImageErrors, ...). */
    private function mpdfSetProperty(string $name, mixed $value): void
    {
        $this->options->compat[$name] = $value;
        if ($name === 'shrink_tables_to_fit' && is_numeric($value)) {
            $this->options->shrinkTablesToFit = (float) $value; // 0 = off, 1.4 = mPDF default
        }
        $this->renderedPdf = null;
    }

    // ------------------------------------------------------------------
    // mPDF methods
    // ------------------------------------------------------------------

    /**
     * mPDF `WriteHTML($html, $mode)` modes: 0 default, 1 CSS only (stylesheet text), 2 body HTML.
     */
    private function mpdfWriteHtml(string $html, int $mode): self
    {
        $this->mpdfWritten = true;
        if ($mode === 1) {
            $css = (string) preg_replace('~</?style\b[^>]*>~i', '', $html);
            $this->mpdfHeadCss .= $css . "\n";
        } else {
            $this->html .= $html;
        }
        $this->renderedPdf = null;
        return $this;
    }

    /** Page break with mPDF AddPage() arguments (positional, mPDF order). */
    private function mpdfAddPage(array $args): self
    {
        $names = [
            'orientation', 'type', 'resetpagenum', 'pagenumstyle', 'suppress', 'margin-left', 'margin-right', 'margin-top',
            'margin-bottom', 'margin-header', 'margin-footer', 'odd-header-name', 'even-header-name', 'odd-footer-name',
            'even-footer-name', 'odd-header-value', 'even-header-value', 'odd-footer-value', 'even-footer-value', 'pagesel', 'sheet-size',
        ];
        $attrs = [];
        foreach ($args as $i => $v) {
            // '' / null / false / 0 mean "not given" in mPDF's signature, except margins where 0 is a real value
            if (isset($names[$i]) && $v !== '' && $v !== null && $v !== false && ($v !== 0 || str_starts_with($names[$i], 'margin-'))) {
                $attrs[$names[$i]] = $v;
            }
        }
        return $this->mpdfPageBreakTag($attrs);
    }

    /** @param array<string, mixed> $attrs */
    private function mpdfPageBreakTag(array $attrs): self
    {
        $s = '';
        foreach ($attrs as $k => $v) {
            $s .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $this->WriteHTML('<pagebreak' . $s . ' />');
    }

    /**
     * mPDF AddPageByArray(['orientation' => 'L', 'mgl' => 20, 'ohname' => 'x', 'ohvalue' => 1, ...]).
     *
     * @param array<string, mixed> $a
     */
    public function AddPageByArray(array $a = []): self
    {
        $alias = [
            'condition' => 'type', 'mgl' => 'margin-left', 'mgr' => 'margin-right', 'mgt' => 'margin-top', 'mgb' => 'margin-bottom',
            'mgh' => 'margin-header', 'mgf' => 'margin-footer', 'ohname' => 'odd-header-name', 'ehname' => 'even-header-name',
            'ofname' => 'odd-footer-name', 'efname' => 'even-footer-name', 'ohvalue' => 'odd-header-value',
            'ehvalue' => 'even-header-value', 'ofvalue' => 'odd-footer-value', 'efvalue' => 'even-footer-value',
            'newformat' => 'sheet-size',
        ];
        $attrs = [];
        foreach ($a as $k => $v) {
            $key = $alias[strtolower((string) $k)] ?? strtolower((string) $k);
            if ($v !== '' && $v !== null && $v !== false) {
                $attrs[$key] = $v;
            }
        }
        return $this->mpdfPageBreakTag($attrs);
    }

    /** `$mpdf->SetHeader('Left|{PAGENO}|Right')` or the array form (['odd' => [...], 'even' => [...], 'line' => 1]). */
    public function SetHeader(string|array $header = '', string $side = '', bool $write = false): self
    {
        return $this->mpdfSetBar($header, $side, true);
    }

    /** `$mpdf->SetFooter('Left|{PAGENO}|Right')`. */
    public function SetFooter(string|array $footer = '', string $side = ''): self
    {
        return $this->mpdfSetBar($footer, $side, false);
    }

    /** @param string|array<string, mixed> $spec */
    private function mpdfSetBar(string|array $spec, string $side, bool $header): self
    {
        $variants = $this->mpdfBarVariants($spec, $header);
        $apply = fn(?string $html, string $pages) => $header ? $this->setHeaderHtml($html, $pages) : $this->setFooterHtml($html, $pages);
        $side = strtoupper(substr(trim($side), 0, 1));
        if ($side === 'O' || $side === 'E') {
            $apply($variants['odd'] ?? $variants['all'] ?? '', $side);
        } elseif (isset($variants['odd']) || isset($variants['even'])) {
            $apply($variants['odd'] ?? '', 'O');
            $apply($variants['even'] ?? '', 'E');
        } else {
            $apply($variants['all'] ?? '', 'all');
        }
        return $this;
    }

    /**
     * @param string|array<string, mixed> $spec
     * @return array<string, string> keys 'all' | 'odd' | 'even'
     */
    private function mpdfBarVariants(string|array $spec, bool $header): array
    {
        if (is_string($spec)) {
            $parts = explode('|', $spec);
            $lcr = count($parts) === 1 ? ['L' => '', 'C' => $parts[0], 'R' => ''] : ['L' => $parts[0], 'C' => $parts[1] ?? '', 'R' => $parts[2] ?? ''];
            return ['all' => $this->convertMpdfPlaceholders($this->mpdfBarHtml($lcr, $header, true))];
        }
        $out = [];
        $line = !isset($spec['line']) || (bool) $spec['line'];
        $sets = [];
        foreach (['odd', 'even'] as $k) {
            if (isset($spec[$k]) && is_array($spec[$k])) {
                $sets[$k] = $spec[$k];
            }
        }
        if ($sets === []) {
            $sets['all'] = $spec;
        }
        foreach ($sets as $k => $set) {
            $out[$k] = $this->convertMpdfPlaceholders($this->mpdfBarHtml($set, $header, $line && (!isset($set['line']) || (bool) $set['line'])));
        }
        return $out;
    }

    /** @param array<string, mixed> $parts keys L / C / R: text or ['content' => .., 'font-size' => .., 'font-style' => 'BI', 'color' => ..] */
    private function mpdfBarHtml(array $parts, bool $header, bool $line): string
    {
        $cells = '';
        $any = false;
        foreach (['L' => 'left', 'C' => 'center', 'R' => 'right'] as $k => $align) {
            $p = $parts[$k] ?? '';
            $content = is_array($p) ? (string) ($p['content'] ?? '') : (string) $p;
            $style = 'font-size:' . (is_array($p) && isset($p['font-size']) ? (float) $p['font-size'] : 8) . 'pt;';
            $fs = is_array($p) ? strtoupper((string) ($p['font-style'] ?? 'BI')) : 'BI';
            $style .= (str_contains($fs, 'B') ? 'font-weight:bold;' : '') . (str_contains($fs, 'I') ? 'font-style:italic;' : '');
            if (is_array($p) && isset($p['font-family'])) {
                $style .= 'font-family:' . $p['font-family'] . ';';
            }
            if (is_array($p) && isset($p['color'])) {
                $style .= 'color:' . $p['color'] . ';';
            }
            $any = $any || $content !== '';
            $cells .= '<td width="33%" style="text-align:' . $align . ';' . $style . 'padding:0 0 2pt 0">' . $content . '</td>';
        }
        if (!$any) {
            return '';
        }
        $border = $line ? ($header ? 'border-bottom:0.1mm solid #000;' : 'border-top:0.1mm solid #000;') : '';
        return '<table width="100%" style="' . $border . 'border-collapse:collapse"><tr>' . $cells . '</tr></table>';
    }

    /** mPDF DefHTMLHeaderByName: defines a reusable named header (switch with SetHTMLHeaderByName or `<sethtmlpageheader>`). */
    public function DefHTMLHeaderByName(string $name, string $html): self
    {
        $this->mpdfDefHeaders[$name] = $this->convertMpdfPlaceholders($html);
        $this->renderedPdf = null;
        return $this;
    }

    public function DefHTMLFooterByName(string $name, string $html): self
    {
        $this->mpdfDefFooters[$name] = $this->convertMpdfPlaceholders($html);
        $this->renderedPdf = null;
        return $this;
    }

    public function SetHTMLHeaderByName(string $name, string $side = 'O', bool $write = false): self
    {
        return $this->setHeaderHtml($this->mpdfDefHeaders[$name] ?? '', $side === '' ? 'all' : $side);
    }

    public function SetHTMLFooterByName(string $name, string $side = 'O'): self
    {
        return $this->setFooterHtml($this->mpdfDefFooters[$name] ?? '', $side === '' ? 'all' : $side);
    }

    /** Default CSS for the body element (`SetDefaultBodyCSS('background', "#eee")`), overridden by the document's own CSS. */
    public function SetDefaultBodyCSS(string $property, string|int|float $value): self
    {
        $this->mpdfBodyCss[trim($property)] = (string) $value;
        $this->renderedPdf = null;
        return $this;
    }

    /** Initial view: zoom 'fullpage' | 'fullwidth' | 'real' | 'default' | percent; layout 'single' | 'continuous' | 'two' | 'default'. */
    public function SetDisplayMode(string|int|float $zoom = 'default', string $layout = 'continuous'): self
    {
        $this->mpdfZoom = (string) $zoom;
        $this->mpdfLayout = $layout;
        $this->renderedPdf = null;
        return $this;
    }

    public function SetDirectionality(string $dir = 'ltr'): self
    {
        $this->mpdfRtl = strtolower(trim($dir)) === 'rtl';
        $this->renderedPdf = null;
        return $this;
    }

    public function SetCompression(bool $compress = true): self
    {
        $this->options->compress = $compress;
        $this->renderedPdf = null;
        return $this;
    }

    /** Document-level JavaScript (catalog /Names /JavaScript); ignored with PDF/A. */
    public function SetJS(string $script): self
    {
        $this->mpdfJs = $script;
        $this->renderedPdf = null;
        return $this;
    }

    public function SetDefaultFont(string $font): self
    {
        $this->options->defaultFont = $font;
        $this->renderedPdf = null;
        return $this;
    }

    public function SetDefaultFontSize(float $size): self
    {
        $this->options->defaultFontSize = $size;
        $this->renderedPdf = null;
        return $this;
    }

    /** Base folder for relative image / stylesheet paths. */
    public function SetBasePath(string $path): self
    {
        $this->overrideBasePath = $path;
        $this->imageLoader = new ImageLoader($path, $this->options->isRemoteEnabled, $this->options);
        $this->renderedPdf = null;
        return $this;
    }

    /** Image watermark on every page (mPDF `SetWatermarkImage($src, $alpha, $size, $position)`). */
    public function SetWatermarkImage(string $src, float $alpha = 0.2, string|array $size = 'D', string|array $position = 'P'): self
    {
        $fmt = static fn(string|array $v): string => is_array($v) ? implode(',', $v) : $v;
        $this->mpdfWatermarkImage = ['src' => $src, 'alpha' => max(0.01, min(1.0, $alpha)), 'size' => $fmt($size), 'pos' => $fmt($position)];
        $this->renderedPdf = null;
        return $this;
    }

    /** mPDF `Bookmark($title, $level)`: an outline entry at the current position (levels start at 0). */
    public function Bookmark(string $txt, int $level = 0, float $y = -1): self
    {
        return $this->WriteHTML('<bookmark content="' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '" level="' . max(0, $level) . '" />');
    }

    /** mPDF `TOC_Entry($txt, $level)`: a manual table-of-contents entry. */
    public function TOC_Entry(string $txt, int $level = 0): self
    {
        return $this->WriteHTML('<tocentry content="' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '" level="' . max(0, $level) . '" />');
    }

    /** mPDF `IndexEntry($txt)`: an index entry at the current position. */
    public function IndexEntry(string $txt, string $xref = ''): self
    {
        return $this->WriteHTML('<indexentry content="' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '" />');
    }

    /** mPDF `InsertIndex($usedivletters, $links, $collation)`: places the index here. */
    public function InsertIndex(bool $usedivletters = true, bool $links = true, string $collation = '', string $collationGroup = ''): self
    {
        return $this->WriteHTML('<indexinsert usedivletters="' . ($usedivletters ? 'on' : 'off') . '" links="' . ($links ? 'on' : 'off') . '" collation="' . htmlspecialchars($collation, ENT_QUOTES, 'UTF-8') . '" />');
    }

    /** mPDF `SetColumns($n, $vAlign, $gap)`: starts (or with 1 ends) a multi-column section. */
    public function SetColumns(int $cols, string $vAlign = '', float $gap = 5): self
    {
        return $this->WriteHTML('<columns column-count="' . max(1, $cols) . '" vAlign="' . htmlspecialchars($vAlign, ENT_QUOTES, 'UTF-8') . '" column-gap="' . $gap . '" />');
    }

    public function AddColumn(): self
    {
        return $this->WriteHTML('<columnbreak />');
    }
}
