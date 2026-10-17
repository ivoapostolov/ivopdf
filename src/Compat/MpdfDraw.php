<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\Compat\Dompdf\Canvas;
use IvoPdf\Compat\Dompdf\FontMetrics;

/**
 * mPDF's imperative API on top of the HTML engine (mixed into IvoPdf through MpdfApi). Units are mPDF's user units:
 * millimetres from the top-left corner of the page.
 *
 *  - Drawing on "the current page" (Image(), WriteFixedPosHTML(), Text() / WriteText(), Line(), Rect(), Circle(),
 *    Ellipse(), RoundedRect(), Arrow(), Link(), Rotate(), StartTransform() / transform*(), SetAlpha(), ...): the
 *    current page is the page on which the HTML written so far ends (as in mPDF, after AddPage() it is the new page).
 *    A zero-size marker is placed in the document at the call; after pagination the drawing is painted on the
 *    marker's page, above the content.
 *  - Cell(), MultiCell(), Write(), Ln(): after SetXY() / SetY() they draw at that absolute position and move it like
 *    mPDF; otherwise they add the equivalent HTML at the end of the document (they then flow with the content).
 *  - SetFont() / SetFontSize() / Set*Color() / SetLineWidth() / SetDash() set the pen of these calls (not of WriteHTML,
 *    whose text follows CSS, as in mPDF).
 *  - SetAutoPageBreak($auto, $margin) sets the bottom margin; DeletePages() / MovePages() rearrange the output pages;
 *    PageNo() renders what was written so far and returns its page count.
 *  - Any other public method of mPDF 8 is accepted by __call(): it does nothing, returns null and adds one warning per
 *    method (getWarnings()). Names that mPDF does not have still fail with the usual "undefined method" Error.
 */
trait MpdfDraw
{
    private ?Canvas $mpdfCanvas = null;
    /** Canvas object that records the drawing calls since the last WriteHTML() (null: none open) */
    private ?int $mpdfSegment = null;
    /** @var array<string, int> marker element id => canvas object id */
    private array $mpdfSegments = [];
    private int $mpdfSegCounter = 0;
    /** @var array{family: string, style: string, size: float, text: list<float>, fill: list<float>, draw: list<float>, lw: float, dash: list<float>, cap: string} */
    private array $mpdfPen = ['family' => '', 'style' => '', 'size' => 0.0, 'text' => [0.0, 0.0, 0.0], 'fill' => [0.0, 0.0, 0.0], 'draw' => [0.0, 0.0, 0.0], 'lw' => 0.2, 'dash' => [], 'cap' => 'butt'];
    /** explicit position (mm) set by SetXY() / SetY() since the last WriteHTML(); null: cursor follows the HTML flow */
    private ?float $mpdfX = null;
    private ?float $mpdfY = null;
    private float $mpdfLastH = 5.0;
    private bool $mpdfRotated = false;
    /** @var list<array{0: string, 1: int, 2: int, 3: int}> DeletePages / MovePages operations (1-based pages) */
    private array $mpdfPageOps = [];
    /** @var array<string, true> */
    private array $mpdfReportedCalls = [];
    private int $mpdfLinkCounter = 0;

    // ------------------------------------------------------------------
    // fallback for the rest of mPDF's API
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (!in_array(strtolower($method), MpdfMethods::NAMES, true)) {
            throw new \Error('Call to undefined method ' . static::class . '::' . $method . '()');
        }
        if (!isset($this->mpdfReportedCalls[strtolower($method)])) {
            $this->mpdfReportedCalls[strtolower($method)] = true;
            $this->options->addStickyWarning("mPDF method {$method}() is not supported by IvoPDF and was ignored.");
        }
        return null;
    }

    // ------------------------------------------------------------------
    // document / page control
    // ------------------------------------------------------------------

    /** mPDF: automatic page breaks with the given bottom margin in mm (mPDF sets the margin to $margin, default 0). */
    public function SetAutoPageBreak(bool $auto, float|int|string $margin = 0): self
    {
        $this->options->margins['bottom'] = max(0.0, (float) $margin) * 72 / 25.4;
        if (!$auto) {
            $this->options->addStickyWarning('SetAutoPageBreak(false): IvoPDF always continues content on the next page; content is not clipped at the bottom margin.');
        }
        $this->renderedPdf = null;
        return $this;
    }

    /** Current page number: renders what has been written so far (the page on which it ends). */
    public function PageNo(): int
    {
        if ($this->renderedPdf === null) {
            $this->render();
        }
        return $this->pageCount;
    }

    /** mPDF 7 FPDI switch, not needed any more (imports always work): accepted and ignored. */
    public function SetImportUse(): void
    {
    }

    /** Pages are added by AddPage() / page breaks; kept for FPDF compatibility. */
    public function Open(): void
    {
    }

    public function Close(): void
    {
    }

    /** `{nb}` / `{nbpg}` are always replaced by the page count. */
    public function AliasNbPages(string $alias = '{nb}'): void
    {
    }

    public function AliasNbPageGroups(string $alias = '{nbpg}'): void
    {
    }

    /** mPDF: deletes pages $startPage..$endPage (1-based, inclusive) of the output. */
    public function DeletePages(int $startPage, int $endPage = -1): self
    {
        $this->mpdfPageOps[] = ['delete', $startPage, $endPage < 1 ? $startPage : $endPage, 0];
        $this->renderedPdf = null;
        return $this;
    }

    /** mPDF: moves pages $startPage..$endPage so that they start at $targetPage ($targetPage < $startPage). */
    public function MovePages(int $targetPage, int $startPage, int $endPage = -1): self
    {
        $this->mpdfPageOps[] = ['move', $startPage, $endPage < 1 ? $startPage : $endPage, $targetPage];
        $this->renderedPdf = null;
        return $this;
    }

    /**
     * mPDF TOCpagebreak(): a page break, the table of contents (on its own page(s)) and a page break; the arguments
     * follow mPDF's order (tocfont, tocfontsize, tocindent, TOCusePaging, TOCuseLinking, toc_orientation, toc margins,
     * toc header / footer names and values, toc_preHTML, toc_postHTML, toc_bookmarkText, resetpagenum, pagenumstyle,
     * suppress, orientation, margins, header / footer names and values, toc_id, pagesel, toc_pagesel, sheetsize,
     * toc_sheetsize, tocoutdent).
     */
    public function TOCpagebreak(mixed ...$args): self
    {
        $names = ['font', 'font-size', 'indent', 'paging', 'links', 'toc-orientation', 'toc-margin-left', 'toc-margin-right',
            'toc-margin-top', 'toc-margin-bottom', 'toc-margin-header', 'toc-margin-footer', 'toc-odd-header-name',
            'toc-even-header-name', 'toc-odd-footer-name', 'toc-even-footer-name', 'toc-odd-header-value', 'toc-even-header-value',
            'toc-odd-footer-value', 'toc-even-footer-value', 'toc-preHTML', 'toc-postHTML', 'toc-bookmarkText', 'resetpagenum',
            'pagenumstyle', 'suppress', 'orientation', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom', 'margin-header',
            'margin-footer', 'odd-header-name', 'even-header-name', 'odd-footer-name', 'even-footer-name', 'odd-header-value',
            'even-header-value', 'odd-footer-value', 'even-footer-value', 'name', 'pagesel', 'toc-pagesel', 'sheet-size',
            'toc-sheet-size', 'outdent'];
        $a = [];
        foreach (array_values($args) as $i => $v) {
            if (isset($names[$i])) {
                $a[$names[$i]] = $v;
            }
        }
        return $this->mpdfTocTag('tocpagebreak', $a);
    }

    /** mPDF TOCpagebreakByArray(['toc-preHTML' => .., 'resetpagenum' => 1, 'links' => true, ...]). */
    public function TOCpagebreakByArray(array $a = []): self
    {
        $alias = ['tocfont' => 'font', 'tocfontsize' => 'font-size', 'tocindent' => 'indent', 'tocoutdent' => 'outdent',
            'TOCusePaging' => 'paging', 'TOCuseLinking' => 'links', 'toc_id' => 'name', 'toc_preHTML' => 'toc-preHTML',
            'toc_postHTML' => 'toc-postHTML', 'toc_bookmarkText' => 'toc-bookmarkText', 'mgl' => 'margin-left', 'mgr' => 'margin-right',
            'mgt' => 'margin-top', 'mgb' => 'margin-bottom', 'mgh' => 'margin-header', 'mgf' => 'margin-footer',
            'ohname' => 'odd-header-name', 'ehname' => 'even-header-name', 'ofname' => 'odd-footer-name', 'efname' => 'even-footer-name',
            'ohvalue' => 'odd-header-value', 'ehvalue' => 'even-header-value', 'ofvalue' => 'odd-footer-value', 'efvalue' => 'even-footer-value',
            'sheetsize' => 'sheet-size', 'toc_sheetsize' => 'toc-sheet-size', 'toc_pagesel' => 'toc-pagesel', 'toc_orientation' => 'toc-orientation'];
        $out = [];
        foreach ($a as $k => $v) {
            $k = (string) $k;
            if (preg_match('/^toc_(mg[lrtbhf]|[oe][hf](?:name|value))$/', $k, $m) === 1) {
                $k = 'toc-' . ($alias[$m[1]] ?? $m[1]);
            }
            $out[$alias[$k] ?? $k] = $v;
        }
        return $this->mpdfTocTag('tocpagebreak', $out);
    }

    /** mPDF TOC(): marks where the table of contents goes (without the page breaks of TOCpagebreak()). */
    public function TOC(mixed ...$args): self
    {
        $names = ['font', 'font-size', 'indent', 'resetpagenum', 'pagenumstyle', 'suppress', 'toc-orientation', 'paging', 'links', 'name', 'outdent'];
        $a = [];
        foreach (array_values($args) as $i => $v) {
            if (isset($names[$i])) {
                $a[$names[$i]] = $v;
            }
        }
        return $this->mpdfTocTag('toc', $a);
    }

    /** @param array<string, mixed> $a */
    private function mpdfTocTag(string $tag, array $a): self
    {
        $s = '';
        foreach ($a as $k => $v) {
            if ($v === '' || $v === null || ($v === 0 && !str_contains($k, 'margin'))) {
                continue;
            }
            $v = is_bool($v) ? ($v ? 'on' : 'off') : (string) $v;
            $s .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $this->WriteHTML('<' . $tag . $s . ' />');
    }

    /** mPDF margins in mm (they apply to the whole document). */
    public function SetLeftMargin(float|int $margin): self
    {
        return $this->mpdfMargin('left', $margin);
    }

    public function SetRightMargin(float|int $margin): self
    {
        return $this->mpdfMargin('right', $margin);
    }

    public function SetTopMargin(float|int $margin): self
    {
        return $this->mpdfMargin('top', $margin);
    }

    private function mpdfMargin(string $side, float|int $mm): self
    {
        $this->options->margins[$side] = max(0.0, (float) $mm) * 72 / 25.4;
        $this->renderedPdf = null;
        return $this;
    }

    /** mPDF AddFontDirectory(): an extra folder searched for `fontdata` files. */
    public function AddFontDirectory(string $directory): self
    {
        $dirs = (array) ($this->options->compat['fontDir'] ?? []);
        $dirs[] = $directory;
        $this->options->compat['fontDir'] = $dirs;
        MpdfConfig::registerFonts($this->options, $this->fontManager);
        $this->renderedPdf = null;
        return $this;
    }

    /** mPDF DefHeaderByName($name, $spec): a named header from the array / 'L|C|R' form of SetHeader(). */
    public function DefHeaderByName(string $name, string|array $spec): self
    {
        $this->mpdfDefHeaders[$name] = $this->mpdfBarVariants($spec, true)['all'] ?? $this->mpdfBarVariants($spec, true)['odd'] ?? '';
        $this->renderedPdf = null;
        return $this;
    }

    public function DefFooterByName(string $name, string|array $spec): self
    {
        $this->mpdfDefFooters[$name] = $this->mpdfBarVariants($spec, false)['all'] ?? $this->mpdfBarVariants($spec, false)['odd'] ?? '';
        $this->renderedPdf = null;
        return $this;
    }

    public function SetHeaderByName(string $name, string $side = 'O', bool $write = false): self
    {
        return $this->SetHTMLHeaderByName($name, $side, $write);
    }

    public function SetFooterByName(string $name, string $side = 'O'): self
    {
        return $this->SetHTMLFooterByName($name, $side);
    }

    /** mPDF Annotation(): a sticky note at ($x, $y) mm, or at the current position when both are 0. */
    public function Annotation(string $text, float|int $x = 0, float|int $y = 0, string $icon = 'Note', string $author = '', string $subject = '', float|int $opacity = 0, mixed $colarray = false, mixed $popup = '', mixed $file = ''): self
    {
        $attrs = ['content' => $text, 'title' => $author, 'icon' => $icon];
        if ($opacity > 0) {
            $attrs['opacity'] = (string) $opacity;
        }
        if (is_array($colarray) && count($colarray) >= 3) {
            $attrs['color'] = sprintf('rgb(%d,%d,%d)', (int) $colarray[0], (int) $colarray[1], (int) $colarray[2]);
        }
        if ($x > 0 || $y > 0) {
            $attrs['pos-x'] = (string) $x;
            $attrs['pos-y'] = (string) $y;
        }
        $s = '';
        foreach ($attrs as $k => $v) {
            $s .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $this->WriteHTML('<annotation' . $s . ' />');
    }

    // ------------------------------------------------------------------
    // output helpers of mPDF 8
    // ------------------------------------------------------------------

    public function OutputFile(string $fileName): void
    {
        $this->save($fileName);
    }

    public function OutputBinaryData(): string
    {
        return $this->output();
    }

    public function OutputHttpInline(): void
    {
        $this->stream('document.pdf', ['Attachment' => false]);
    }

    public function OutputHttpDownload(string $fileName): void
    {
        $this->stream($fileName, ['Attachment' => true]);
    }

    // ------------------------------------------------------------------
    // pen
    // ------------------------------------------------------------------

    /** mPDF SetFont($family, $style 'B' / 'I' / 'BI' / 'U', $size pt): the font of Cell(), Write(), Text() ... */
    public function SetFont(string $family, string $style = '', float|int $size = 0, bool $write = true, bool $forcewrite = false): self
    {
        $this->mpdfPen['family'] = $family;
        $this->mpdfPen['style'] = strtoupper($style);
        if ($size > 0) {
            $this->mpdfPen['size'] = (float) $size;
        }
        return $this;
    }

    public function SetFontSize(float|int $size, bool $write = true): self
    {
        if ($size > 0) {
            $this->mpdfPen['size'] = (float) $size;
        }
        return $this;
    }

    /** Colors: (r, g, b) 0..255, a gray level (one value), an [r, g, b] array or a CSS color string. */
    public function SetTextColor(mixed $r, mixed $g = -1, mixed $b = -1, mixed $col4 = -1, bool $return = false): self
    {
        $this->mpdfPen['text'] = self::mpdfColor($r, $g, $b);
        return $this;
    }

    public function SetFillColor(mixed $r, mixed $g = -1, mixed $b = -1, mixed $col4 = -1, bool $return = false): self
    {
        $this->mpdfPen['fill'] = self::mpdfColor($r, $g, $b);
        return $this;
    }

    public function SetDrawColor(mixed $r, mixed $g = -1, mixed $b = -1, mixed $col4 = -1, bool $return = false): self
    {
        $this->mpdfPen['draw'] = self::mpdfColor($r, $g, $b);
        return $this;
    }

    /** Line width in mm. */
    public function SetLineWidth(float|int $width): self
    {
        $this->mpdfPen['lw'] = max(0.0, (float) $width);
        return $this;
    }

    /** Dashed lines: $black mm drawn, $white mm gap; no arguments for solid lines. */
    public function SetDash(mixed $black = false, mixed $white = false): self
    {
        $k = 72 / 25.4;
        $this->mpdfPen['dash'] = is_numeric($black) && (float) $black > 0 ? [(float) $black * $k, (is_numeric($white) ? (float) $white : (float) $black) * $k] : [];
        return $this;
    }

    public function SetLineCap(int $mode = 2): self
    {
        $this->mpdfPen['cap'] = [0 => 'butt', 1 => 'round', 2 => 'square'][$mode] ?? 'butt';
        return $this;
    }

    public function SetLineJoin(int $mode = 0): self
    {
        return $this;
    }

    /** Opacity (0..1) and blend mode of the drawing calls that follow. */
    public function SetAlpha(float|int $alpha, string $bm = 'Normal', bool $return = false, string $mode = 'B'): self
    {
        $this->mpdfCanvas()->set_opacity((float) $alpha, $bm);
        return $this;
    }

    /** Width in mm of a string in the current font (SetFont()). */
    public function GetStringWidth(string $s, mixed ...$ignored): float
    {
        [$family, $weight, $style] = $this->mpdfFontCss();
        return $this->fontManager->measureText($s, $family, $weight, $style, $this->mpdfFontSize()) * 25.4 / 72;
    }

    // ------------------------------------------------------------------
    // position
    // ------------------------------------------------------------------

    public function SetXY(float|int $x, float|int $y): self
    {
        $this->SetY($y);
        return $this->SetX($x);
    }

    public function SetX(float|int $x): self
    {
        $w = $this->mpdfPageMm()[0];
        $this->mpdfX = $x >= 0 ? (float) $x : $w + (float) $x;
        return $this;
    }

    /** Sets the position (and moves x back to the left margin, as mPDF). */
    public function SetY(float|int $y, bool $resetx = true): self
    {
        $h = $this->mpdfPageMm()[1];
        $this->mpdfY = $y >= 0 ? (float) $y : $h + (float) $y;
        if ($resetx || $this->mpdfX === null) {
            $this->mpdfX = $this->options->margins['left'] * 25.4 / 72;
        }
        return $this;
    }

    public function GetX(): float
    {
        return $this->mpdfX ?? $this->options->margins['left'] * 25.4 / 72;
    }

    public function GetY(): float
    {
        return $this->mpdfY ?? $this->options->margins['top'] * 25.4 / 72;
    }

    // ------------------------------------------------------------------
    // drawing on the current page
    // ------------------------------------------------------------------

    /**
     * mPDF Image(): an image at ($x, $y) mm, $w x $h mm (0 = natural size / keep the aspect ratio). The file follows the
     * usual image rules (basePath / allowedPaths, isRemoteEnabled). $watermark = true makes it the image watermark.
     *
     * @return array<string, mixed>|null
     */
    public function Image(string $file, float|int $x, float|int $y, float|int $w = 0, float|int $h = 0, string $type = '', string $link = '', bool $paint = true, bool $constrain = true, bool $watermark = false, bool $shownoimg = true, bool $allowvector = true): ?array
    {
        if ($watermark) {
            $this->SetWatermarkImage($file);
            return null;
        }
        if (!$paint) {
            return null;
        }
        $k = 72 / 25.4;
        $c = $this->mpdfCanvas();
        $c->image($file, (float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k);
        if ($link !== '' && $w > 0 && $h > 0) {
            $c->add_link($link, (float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k);
        }
        return null;
    }

    /** mPDF WriteFixedPosHTML(): HTML in a $w x $h mm box at ($x, $y) mm of the current page; $overflow 'hidden' clips it. */
    public function WriteFixedPosHTML(string $html, float|int $x, float|int $y, float|int $w, float|int $h, string $overflow = 'visible', array $bounding = []): self
    {
        $k = 72 / 25.4;
        $this->mpdfCanvas()->html($this->convertMpdfPlaceholders($html), (float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k, $overflow);
        return $this;
    }

    /** mPDF Text() / WriteText(): one line of text whose baseline is at ($x, $y) mm. */
    public function Text(float|int $x, float|int $y, string $txt, mixed ...$ignored): self
    {
        $k = 72 / 25.4;
        $font = $this->mpdfFontDescriptor();
        $c = $this->mpdfCanvas();
        $metrics = $this->mpdfMetrics();
        $size = $this->mpdfFontSize();
        $c->text((float) $x * $k, (float) $y * $k - $metrics->getFontBaseline($font, $size), $txt, $font, $size, $this->mpdfPen['text']);
        if (str_contains($this->mpdfPen['style'], 'U')) {
            $w = $metrics->getTextWidth($txt, $font, $size);
            $c->line((float) $x * $k, (float) $y * $k + $size * 0.12, (float) $x * $k + $w, (float) $y * $k + $size * 0.12, $this->mpdfPen['text'], max(0.5, $size * 0.06));
        }
        return $this;
    }

    public function WriteText(float|int $x, float|int $y, string $txt): self
    {
        return $this->Text($x, $y, $txt);
    }

    public function Line(float|int $x1, float|int $y1, float|int $x2, float|int $y2): self
    {
        $k = 72 / 25.4;
        $this->mpdfCanvas()->line((float) $x1 * $k, (float) $y1 * $k, (float) $x2 * $k, (float) $y2 * $k, $this->mpdfPen['draw'], $this->mpdfPen['lw'] * $k, $this->mpdfPen['dash'], $this->mpdfPen['cap']);
        return $this;
    }

    /** $style: '' / 'D' outline, 'F' fill, 'DF' / 'FD' both. */
    public function Rect(float|int $x, float|int $y, float|int $w, float|int $h, string $style = ''): self
    {
        $k = 72 / 25.4;
        [$fill, $stroke] = self::mpdfPaint($style);
        $c = $this->mpdfCanvas();
        if ($fill) {
            $c->filled_rectangle((float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k, $this->mpdfPen['fill']);
        }
        if ($stroke) {
            $c->rectangle((float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k, $this->mpdfPen['draw'], $this->mpdfPen['lw'] * $k, $this->mpdfPen['dash']);
        }
        return $this;
    }

    public function Circle(float|int $x, float|int $y, float|int $r, string $style = 'S'): self
    {
        return $this->Ellipse($x, $y, $r, $r, $style);
    }

    public function Ellipse(float|int $x, float|int $y, float|int $rx, float|int $ry, string $style = 'S'): self
    {
        $k = 72 / 25.4;
        $pts = [];
        for ($i = 0; $i < 72; $i++) {
            $a = M_PI * 2 * $i / 72;
            $pts[] = ((float) $x + (float) $rx * cos($a)) * $k;
            $pts[] = ((float) $y + (float) $ry * sin($a)) * $k;
        }
        return $this->mpdfShape($pts, $style);
    }

    public function RoundedRect(float|int $x, float|int $y, float|int $w, float|int $h, float|int $r, string $style = ''): self
    {
        $r = max(0.0, min((float) $r, min((float) $w, (float) $h) / 2));
        $pts = [];
        foreach ([[(float) $x + $r, (float) $y + $r, 180.0], [(float) $x + (float) $w - $r, (float) $y + $r, 270.0], [(float) $x + (float) $w - $r, (float) $y + (float) $h - $r, 0.0], [(float) $x + $r, (float) $y + (float) $h - $r, 90.0]] as [$cx, $cy, $from]) {
            for ($i = 0; $i <= 9; $i++) {
                $a = deg2rad($from + 10.0 * $i);
                $pts[] = ($cx + $r * cos($a)) * 72 / 25.4;
                $pts[] = ($cy + $r * sin($a)) * 72 / 25.4;
            }
        }
        return $this->mpdfShape($pts, $style);
    }

    /** mPDF Arrow(): a line from ($x1, $y1) to ($x2, $y2) mm with a triangular head at the end. */
    public function Arrow(float|int $x1, float|int $y1, float|int $x2, float|int $y2, float|int $headsize = 3, string $fill = 'B', float|int $angle = 25): self
    {
        $this->Line($x1, $y1, $x2, $y2);
        $dir = atan2((float) $y2 - (float) $y1, (float) $x2 - (float) $x1);
        $a = deg2rad((float) $angle);
        $pts = [(float) $x2, (float) $y2];
        foreach ([-1, 1] as $sgn) {
            $pts[] = (float) $x2 - (float) $headsize * cos($dir + $sgn * $a);
            $pts[] = (float) $y2 - (float) $headsize * sin($dir + $sgn * $a);
        }
        return $this->mpdfShape(array_map(static fn(float $v): float => $v * 72 / 25.4, $pts), $fill === 'F' ? 'F' : ($fill === 'B' ? 'DF' : 'D'));
    }

    /** mPDF Link(): a clickable area ($link: URL, '#name' or an AddLink() id). */
    public function Link(float|int $x, float|int $y, float|int $w, float|int $h, string|int $link): self
    {
        $k = 72 / 25.4;
        $url = is_int($link) ? '#ivo-mlink-' . $link : $link;
        $this->mpdfCanvas()->add_link($url, (float) $x * $k, (float) $y * $k, (float) $w * $k, (float) $h * $k);
        return $this;
    }

    /** mPDF AddLink(): an internal link id for Link() / Cell(); SetLink() places its target. */
    public function AddLink(): int
    {
        return ++$this->mpdfLinkCounter;
    }

    /** mPDF SetLink(): the target of an AddLink() id, $y mm on the current page ($page is ignored: the current page). */
    public function SetLink(int $link, float|int $y = 0, int $page = -1): self
    {
        $this->mpdfCanvas()->namedDestAt('ivo-mlink-' . $link, max(0.0, (float) $y) * 72 / 25.4);
        return $this;
    }

    /** mPDF Rotate(): rotates the drawing calls that follow $angle degrees counter-clockwise around ($x, $y) mm; 0 ends it. */
    public function Rotate(float|int $angle, float|int $x = -1, float|int $y = -1): self
    {
        $c = $this->mpdfCanvas();
        if ($this->mpdfRotated) {
            $c->restore();
            $this->mpdfRotated = false;
        }
        if ((float) $angle != 0.0) {
            $x = $x == -1 ? $this->GetX() : (float) $x;
            $y = $y == -1 ? $this->GetY() : (float) $y;
            $c->save();
            $this->mpdfRotated = true;
            $this->mpdfTransformAt(deg2rad((float) $angle), (float) $x, (float) $y, 'rotate');
        }
        return $this;
    }

    public function StartTransform(bool $returnstring = false): ?string
    {
        $this->mpdfCanvas()->save();
        return null;
    }

    public function StopTransform(bool $returnstring = false): ?string
    {
        $this->mpdfCanvas()->restore();
        return null;
    }

    /** Scales by $s_x / $s_y percent around ($x, $y) mm (default: the current position). */
    public function transformScale(float|int $s_x, float|int $s_y, mixed $x = '', mixed $y = '', bool $returnstring = false): ?string
    {
        if ((float) $s_x == 0.0 || (float) $s_y == 0.0) {
            throw new \InvalidArgumentException('Please do not use values equal to zero for scaling');
        }
        [$px, $py] = $this->mpdfPivot($x, $y);
        $sx = (float) $s_x / 100;
        $sy = (float) $s_y / 100;
        $this->mpdfCanvas()->transform($sx, 0, 0, $sy, $px * (1 - $sx), $py * (1 - $sy));
        return null;
    }

    public function transformTranslate(float|int $t_x, float|int $t_y, bool $returnstring = false): ?string
    {
        $this->mpdfCanvas()->transform(1, 0, 0, 1, (float) $t_x * 72 / 25.4, -(float) $t_y * 72 / 25.4);
        return null;
    }

    /** Rotates $angle degrees around ($x, $y) mm, with mPDF's matrix. */
    public function transformRotate(float|int $angle, mixed $x = '', mixed $y = '', bool $returnstring = false): ?string
    {
        [$px, $py] = $this->mpdfPivot($x, $y);
        $a = deg2rad(-(float) $angle);
        $cos = cos($a);
        $sin = sin($a);
        $this->mpdfCanvas()->transform($cos, $sin, -$sin, $cos, $px + $sin * $py - $cos * $px, $py - $cos * $py - $sin * $px);
        return null;
    }

    public function transformSkew(float|int $angle_x, float|int $angle_y, mixed $x = '', mixed $y = '', bool $returnstring = false): ?string
    {
        [$px, $py] = $this->mpdfPivot($x, $y);
        $tb = tan(deg2rad(-(float) $angle_y));
        $tc = tan(deg2rad(-(float) $angle_x));
        $this->mpdfCanvas()->transform(1, $tb, $tc, 1, -$tc * $py, -$tb * $px);
        return null;
    }

    // ------------------------------------------------------------------
    // cells and flowing text
    // ------------------------------------------------------------------

    /**
     * mPDF Cell(): a $w x $h mm box with text. $border 1 / 'LTRB', $ln 0 = right, 1 = next line, 2 = below,
     * $align 'L' / 'C' / 'R', $fill true = SetFillColor() background, $link URL / '#name' / AddLink() id.
     */
    public function Cell(float|int $w, float|int $h = 0, string $txt = '', int|string $border = 0, int $ln = 0, string $align = '', bool|int $fill = false, string|int $link = '', mixed ...$ignored): self
    {
        $this->mpdfLastH = (float) $h > 0 ? (float) $h : $this->mpdfLastH;
        if ($this->mpdfY === null) {
            $html = $this->mpdfCellHtml((float) $w, (float) $h, $txt, $border, $align, (bool) $fill, $link, $w == 0 || $ln > 0);
            return $this->WriteHTML($html . ($ln > 0 && $w != 0 ? '<br>' : ''));
        }
        $x = $this->GetX();
        $y = (float) $this->mpdfY;
        if ((float) $w == 0.0) {
            $w = $this->mpdfPageMm()[0] - $this->options->margins['right'] * 25.4 / 72 - $x;
        }
        $this->mpdfAbsoluteCell($x, $y, (float) $w, (float) $h, $txt, $border, $align, (bool) $fill, $link);
        if ($ln === 1) {
            $this->mpdfX = $this->options->margins['left'] * 25.4 / 72;
            $this->mpdfY = $y + (float) $h;
        } elseif ($ln === 2) {
            $this->mpdfY = $y + (float) $h;
        } else {
            $this->mpdfX = $x + (float) $w;
        }
        return $this;
    }

    /** mPDF WriteCell(): Cell() without the right-to-left / OTL processing. */
    public function WriteCell(float|int $w, float|int $h = 0, string $txt = '', int|string $border = 0, int $ln = 0, string $align = '', bool|int $fill = false, string|int $link = '', mixed ...$ignored): self
    {
        return $this->Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
    }

    /** mPDF MultiCell(): text wrapped in a $w mm wide box (0 = to the right margin), $h mm per line. */
    public function MultiCell(float|int $w, float|int $h, string $txt, int|string $border = 0, string $align = '', bool|int $fill = false, string|int $link = '', mixed ...$ignored): self
    {
        $k = 72 / 25.4;
        $style = $this->mpdfTextCss() . sprintf('line-height:%.3Fmm;', (float) $h) . self::mpdfBorderCss($border, $this->mpdfPen['draw'], $this->mpdfPen['lw'])
            . ($fill ? 'background:' . self::mpdfCss($this->mpdfPen['fill']) . ';' : '') . 'text-align:' . self::mpdfAlign($align, 'J') . ';margin:0;padding:0 1mm;';
        $body = nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8'), false);
        if ($this->mpdfY === null) {
            return $this->WriteHTML('<div style="' . ((float) $w > 0 ? sprintf('width:%.3Fmm;box-sizing:border-box;', (float) $w) : '') . $style . '">' . $body . '</div>');
        }
        $x = $this->GetX();
        if ((float) $w == 0.0) {
            $w = $this->mpdfPageMm()[0] - $this->options->margins['right'] * 25.4 / 72 - $x;
        }
        $lines = $this->mpdfCountLines($txt, max(1.0, ((float) $w - 2.0) * $k));
        $this->mpdfCanvas()->html('<div style="box-sizing:border-box;width:100%;' . $style . '">' . $body . '</div>', $x * $k, (float) $this->mpdfY * $k, (float) $w * $k, $lines * (float) $h * $k);
        $this->mpdfX = $this->options->margins['left'] * 25.4 / 72;
        $this->mpdfY = (float) $this->mpdfY + $lines * (float) $h;
        return $this;
    }

    /** mPDF Write(): flowing text, $h mm line height. */
    public function Write(float|int $h, string $txt, mixed $currentx = 0, string|int $link = '', mixed ...$ignored): self
    {
        $this->mpdfLastH = (float) $h > 0 ? (float) $h : $this->mpdfLastH;
        $text = nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8'), false);
        if ($this->mpdfY === null) {
            $span = '<span style="' . $this->mpdfTextCss() . sprintf('line-height:%.3Fmm', (float) $h) . '">' . $text . '</span>';
            return $this->WriteHTML($link !== '' ? '<a href="' . htmlspecialchars(is_int($link) ? '#ivo-mlink-' . $link : $link, ENT_QUOTES, 'UTF-8') . '">' . $span . '</a>' : $span);
        }
        $x = $this->GetX();
        $y = (float) $this->mpdfY;
        $this->mpdfAbsoluteCell($x, $y, $this->GetStringWidth($txt), (float) $h, $txt, 0, 'L', false, $link, false);
        $this->mpdfX = $x + $this->GetStringWidth($txt);
        return $this;
    }

    /** mPDF Ln(): line break ($h mm, default: the height of the last cell). */
    public function Ln(mixed $h = '', mixed $collapsible = 0): self
    {
        if ($this->mpdfY === null) {
            return $this->WriteHTML(is_numeric($h) && (float) $h > 0 ? sprintf('<div style="height:%.3Fmm;margin:0;padding:0"></div>', (float) $h) : '<br>');
        }
        $this->mpdfX = $this->options->margins['left'] * 25.4 / 72;
        $this->mpdfY = (float) $this->mpdfY + (is_numeric($h) && (float) $h > 0 ? (float) $h : $this->mpdfLastH);
        return $this;
    }

    // ------------------------------------------------------------------
    // engine side
    // ------------------------------------------------------------------

    /** Called by WriteHTML(): drawing after it lands on the page where the new HTML ends; the cursor follows the flow again. */
    private function mpdfDrawBreak(): void
    {
        if ($this->mpdfSegment !== null) {
            $this->mpdfCanvas?->close_object();
            $this->mpdfSegment = null;
        }
        $this->mpdfX = null;
        $this->mpdfY = null;
    }

    /** Overlay HTML (page-sized box) with the drawing calls that belong to a page (1-based), '' when none. */
    private function mpdfDrawHtml(int $pageNumber, int $pageCount, float $w, float $h): string
    {
        if ($this->mpdfCanvas === null || $this->mpdfSegments === []) {
            return '';
        }
        $ids = [];
        foreach ($this->mpdfSegments as $marker => $obj) {
            $page = $this->mpdfState?->markerPages[$marker] ?? null;
            if ($page !== null && $page + 1 === $pageNumber) {
                $ids[] = $obj;
            }
        }
        return $ids === [] ? '' : $this->mpdfCanvas->renderObjectsHtml($ids, $pageNumber, $pageCount, $w, $h);
    }

    /**
     * Output page order after DeletePages() / MovePages(): list of 0-based source page indexes, or null when unchanged.
     *
     * @return list<int>|null
     */
    private function mpdfPageOrder(int $pageCount): ?array
    {
        if ($this->mpdfPageOps === []) {
            return null;
        }
        $order = range(0, max(0, $pageCount - 1));
        foreach ($this->mpdfPageOps as [$op, $from, $to, $target]) {
            $from = max(1, $from);
            $to = min(count($order), max($from, $to));
            if ($from > count($order)) {
                $this->options->addWarning("mPDF {$op} pages: page {$from} does not exist");
                continue;
            }
            $block = array_slice($order, $from - 1, $to - $from + 1);
            array_splice($order, $from - 1, $to - $from + 1);
            if ($op === 'move') {
                $at = max(1, min($target, count($order) + 1));
                array_splice($order, $at - 1, 0, $block);
            }
        }
        if ($order === []) {
            $this->options->addWarning('mPDF DeletePages(): all pages were deleted; the first page was kept');
            return [0];
        }
        return $order;
    }

    private function mpdfCanvas(): Canvas
    {
        if ($this->mpdfCanvas === null) {
            [$w, $h] = $this->options->getPageDimensions();
            $metrics = new FontMetrics(null);
            $metrics->useFontManager($this->fontManager);
            $this->mpdfCanvas = new Canvas($metrics, $w, $h);
        }
        if ($this->mpdfSegment === null) {
            $marker = 'ivo-dw-' . (++$this->mpdfSegCounter);
            // zero-size marker: after pagination its page is the page the drawing belongs to
            $this->html .= '<span id="' . $marker . '" style="display:block;height:0;margin:0;padding:0;border:0;overflow:hidden;font-size:0;line-height:0"></span>';
            $this->mpdfSegment = $this->mpdfCanvas->open_object();
            $this->mpdfSegments[$marker] = $this->mpdfSegment;
        }
        $this->renderedPdf = null;
        return $this->mpdfCanvas;
    }

    private function mpdfMetrics(): FontMetrics
    {
        $m = new FontMetrics(null);
        $m->useFontManager($this->fontManager);
        return $m;
    }

    /** @return array{0: float, 1: float} page size in mm */
    private function mpdfPageMm(): array
    {
        [$w, $h] = $this->options->getPageDimensions();
        return [$w * 25.4 / 72, $h * 25.4 / 72];
    }

    /** @return array{0: float, 1: float} pivot point in pt, PDF space (y up) for mPDF's transform matrices */
    private function mpdfPivot(mixed $x, mixed $y): array
    {
        $k = 72 / 25.4;
        $x = $x === '' ? $this->GetX() : (float) $x;
        $y = $y === '' ? $this->GetY() : (float) $y;
        return [$x * $k, $this->mpdfCanvas()->get_height() - $y * $k];
    }

    /** Rotate(): mPDF's matrix [cos, sin, -sin, cos] around the point. */
    private function mpdfTransformAt(float $a, float $x, float $y, string $kind): void
    {
        $k = 72 / 25.4;
        $cx = $x * $k;
        $cy = $this->mpdfCanvas()->get_height() - $y * $k;
        $c = cos($a);
        $s = sin($a);
        // q c s -s c cx cy cm 1 0 0 1 -cx -cy cm
        $this->mpdfCanvas()->transform($c, $s, -$s, $c, $cx - $c * $cx + $s * $cy, $cy - $s * $cx - $c * $cy);
    }

    /** @param list<float> $pts points in pt */
    private function mpdfShape(array $pts, string $style): self
    {
        [$fill, $stroke] = self::mpdfPaint($style);
        $c = $this->mpdfCanvas();
        if ($fill) {
            $c->polygon($pts, $this->mpdfPen['fill'], null, [], true);
        }
        if ($stroke) {
            $c->polygon($pts, $this->mpdfPen['draw'], $this->mpdfPen['lw'] * 72 / 25.4, $this->mpdfPen['dash'], false);
        }
        return $this;
    }

    /** @return array{0: bool, 1: bool} [fill, stroke] */
    private static function mpdfPaint(string $style): array
    {
        $s = strtoupper($style);
        $fill = str_contains($s, 'F') || $s === 'B';
        $stroke = $s === '' || str_contains($s, 'D') || str_contains($s, 'S') || $s === 'B';
        return [$fill, $stroke];
    }

    private function mpdfAbsoluteCell(float $x, float $y, float $w, float $h, string $txt, int|string $border, string $align, bool $fill, string|int $link, bool $padding = true): void
    {
        $k = 72 / 25.4;
        $c = $this->mpdfCanvas();
        if ($fill) {
            $c->filled_rectangle($x * $k, $y * $k, $w * $k, $h * $k, $this->mpdfPen['fill']);
        }
        $b = is_int($border) || is_numeric($border) ? ((int) $border === 1 ? 'LTRB' : '') : strtoupper($border);
        $lw = $this->mpdfPen['lw'] * $k;
        foreach (['L' => [$x, $y, $x, $y + $h], 'T' => [$x, $y, $x + $w, $y], 'R' => [$x + $w, $y, $x + $w, $y + $h], 'B' => [$x, $y + $h, $x + $w, $y + $h]] as $side => [$x1, $y1, $x2, $y2]) {
            if (str_contains($b, $side)) {
                $c->line($x1 * $k, $y1 * $k, $x2 * $k, $y2 * $k, $this->mpdfPen['draw'], $lw, $this->mpdfPen['dash']);
            }
        }
        if ($txt !== '') {
            $font = $this->mpdfFontDescriptor();
            $size = $this->mpdfFontSize();
            $metrics = $this->mpdfMetrics();
            $tw = $metrics->getTextWidth($txt, $font, $size);
            $pad = $padding ? 1.0 * $k : 0.0; // mPDF cMarg: 1 mm
            $a = strtoupper($align);
            $tx = $a === 'R' ? $x * $k + $w * $k - $pad - $tw : ($a === 'C' ? $x * $k + ($w * $k - $tw) / 2 : $x * $k + $pad);
            [$asc, $desc] = $metrics->ascentDescent($font, $size);
            $ty = $y * $k + ($h * $k - ($asc + $desc)) / 2; // vertically centred, as mPDF's valign 'M'
            $c->text($tx, $ty - $desc, $txt, $font, $size, $this->mpdfPen['text']);
        }
        if ($link !== '' && $link !== 0) {
            $c->add_link(is_int($link) ? '#ivo-mlink-' . $link : $link, $x * $k, $y * $k, $w * $k, $h * $k);
        }
    }

    private function mpdfCellHtml(float $w, float $h, string $txt, int|string $border, string $align, bool $fill, string|int $link, bool $block): string
    {
        $style = $this->mpdfTextCss() . self::mpdfBorderCss($border, $this->mpdfPen['draw'], $this->mpdfPen['lw'])
            . ($fill ? 'background:' . self::mpdfCss($this->mpdfPen['fill']) . ';' : '')
            . sprintf('height:%.3Fmm;line-height:%.3Fmm;', $h, $h) . 'text-align:' . self::mpdfAlign($align, 'L') . ';padding:0 1mm;box-sizing:border-box;white-space:nowrap;overflow:hidden;margin:0;vertical-align:top;'
            . ($w > 0 ? sprintf('width:%.3Fmm;', $w) : '') . ($block && $w <= 0 ? 'display:block;' : 'display:inline-block;');
        $text = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
        if ($link !== '' && $link !== 0) {
            $text = '<a href="' . htmlspecialchars(is_int($link) ? '#ivo-mlink-' . $link : $link, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
        }
        return '<div style="' . $style . '">' . $text . '</div>';
    }

    private static function mpdfBorderCss(int|string $border, array $rgb, float $lwMm): string
    {
        $b = is_int($border) || is_numeric($border) ? ((int) $border === 1 ? 'LTRB' : '') : strtoupper($border);
        $css = '';
        foreach (['L' => 'left', 'T' => 'top', 'R' => 'right', 'B' => 'bottom'] as $k => $side) {
            if (str_contains($b, $k)) {
                $css .= sprintf('border-%s:%.3Fmm solid %s;', $side, max(0.05, $lwMm), self::mpdfCss($rgb));
            }
        }
        return $css;
    }

    private static function mpdfAlign(string $align, string $default): string
    {
        return match (strtoupper($align !== '' ? $align : $default)) {
            'C' => 'center',
            'R' => 'right',
            'J' => 'justify',
            default => 'left',
        };
    }

    private function mpdfTextCss(): string
    {
        $css = '';
        if ($this->mpdfPen['family'] !== '') {
            $css .= "font-family:'" . str_replace(["'", '"', ';', '\\'], '', $this->mpdfPen['family']) . "';";
        }
        if ($this->mpdfPen['size'] > 0) {
            $css .= sprintf('font-size:%.2Fpt;', $this->mpdfPen['size']);
        }
        $st = $this->mpdfPen['style'];
        $css .= 'font-weight:' . (str_contains($st, 'B') ? 'bold' : 'normal') . ';font-style:' . (str_contains($st, 'I') ? 'italic' : 'normal') . ';';
        if (str_contains($st, 'U')) {
            $css .= 'text-decoration:underline;';
        }
        return $css . 'color:' . self::mpdfCss($this->mpdfPen['text']) . ';';
    }

    /** @return array{0: string, 1: string, 2: string} css family, weight, style of the pen */
    private function mpdfFontCss(): array
    {
        $st = $this->mpdfPen['style'];
        return [$this->mpdfPen['family'] !== '' ? $this->mpdfPen['family'] : $this->options->defaultFont, str_contains($st, 'B') ? 'bold' : 'normal', str_contains($st, 'I') ? 'italic' : 'normal'];
    }

    private function mpdfFontDescriptor(): string
    {
        [$family, $weight, $style] = $this->mpdfFontCss();
        return $family . '|' . $weight . '|' . $style;
    }

    private function mpdfFontSize(): float
    {
        return $this->mpdfPen['size'] > 0 ? $this->mpdfPen['size'] : ($this->options->defaultFontSize > 0 ? (float) $this->options->defaultFontSize : 11.0);
    }

    private function mpdfCountLines(string $txt, float $widthPt): int
    {
        [$family, $weight, $style] = $this->mpdfFontCss();
        $size = $this->mpdfFontSize();
        $lines = 0;
        foreach (preg_split('/\R/u', $txt) ?: [''] as $para) {
            $lines++;
            $lineW = 0.0;
            foreach (preg_split('/(\s+)/u', $para, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [] as $word) {
                $ww = $this->fontManager->measureText($word, $family, $weight, $style, $size);
                if ($lineW > 0 && $lineW + $ww > $widthPt && trim($word) !== '') {
                    $lines++;
                    $lineW = 0.0;
                }
                $lineW += trim($word) === '' && $lineW === 0.0 ? 0.0 : $ww;
            }
        }
        return max(1, $lines);
    }

    /** @return list<float> rgb 0..1 */
    private static function mpdfColor(mixed $r, mixed $g, mixed $b): array
    {
        if (is_array($r)) {
            $v = array_values($r);
            if (count($v) >= 4 && is_numeric($v[0]) && (int) $v[0] >= 1 && (int) $v[0] <= 6) {
                // mPDF ColorConverter arrays: [type, c1, c2, c3]; type 3 = RGB, 1 = gray
                $v = (int) $v[0] === 1 ? [$v[1], $v[1], $v[1]] : array_slice($v, 1, 3);
            }
            return [max(0.0, min(1.0, (float) ($v[0] ?? 0) / 255)), max(0.0, min(1.0, (float) ($v[1] ?? 0) / 255)), max(0.0, min(1.0, (float) ($v[2] ?? 0) / 255))];
        }
        if (is_string($r) && !is_numeric($r)) {
            $c = \IvoPdf\Css\CssColor::parse($r);
            return $c !== null ? [$c[0], $c[1], $c[2]] : [0.0, 0.0, 0.0];
        }
        $n = static fn(mixed $v): float => max(0.0, min(1.0, (float) $v / 255));
        if ((is_numeric($g) && (float) $g == -1.0) || !is_numeric($g)) {
            return [$n($r), $n($r), $n($r)];
        }
        return [$n($r), $n($g), $n($b)];
    }

    /** @param list<float> $rgb */
    private static function mpdfCss(array $rgb): string
    {
        return sprintf('rgb(%d,%d,%d)', (int) round($rgb[0] * 255), (int) round($rgb[1] * 255), (int) round($rgb[2] * 255));
    }
}
