<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Css\CssColor;
use IvoPdf\Font\FontManager;
use IvoPdf\Layout\FormControl;
use IvoPdf\Layout\FormControls;
use IvoPdf\Layout\LayoutBox;

/**
 * Draws form controls: the static frame (check box / radio outline, drop-down arrow) goes into the page content,
 * the value text / check mark becomes the widget appearance stream (or, with active forms off, is drawn on the page).
 */
final class FormAppearance
{
    private const KAPPA = 0.5522847498;

    public function __construct(
        private FontManager $fontManager,
        private PdfDocument $pdf,
        private bool $activeForms
    ) {
    }

    public function setActiveForms(bool $active): void
    {
        $this->activeForms = $active;
    }

    /**
     * Returns the page-content operators for $box and (when $register) adds its widget to the document.
     */
    public function render(LayoutBox $box, float $yShift, float $pageHeight, int $pageIndex, bool $register): string
    {
        $c = $box->formControl;
        if ($c === null || $box->width < 0.5 || $box->height < 0.5) {
            return '';
        }
        $w = $box->width;
        $h = $box->height;
        $x = $box->x;
        $pdfBottom = $pageHeight - ($box->y - $yShift + $h);
        if ($c->inputType === 'range') {
            // a slider is drawn, as browsers print it; PDF forms have no slider field
            return FormGauges::slider($box, $x + $box->borderLeft + $box->paddingLeft, $pdfBottom + $box->borderBottom + $box->paddingBottom, $box->getContentWidth(), $box->getContentHeight());
        }

        $widget = new FormWidget($c);
        $widget->page = $pageIndex;
        $widget->width = $w;
        $widget->height = $h;
        $widget->rect = [$x, $pdfBottom, $x + $w, $pdfBottom + $h];
        $widget->fontSize = $box->fontSizePt;
        $color = $this->textColor($box, $c);
        $widget->colorOps = ColorOps::fillOp($color, false);
        $ta = strtolower($box->style->get('text-align', 'left'));
        $widget->align = $ta === 'center' ? 1 : ($ta === 'right' ? 2 : 0);

        // Static page content (always drawn)
        $page = '';
        if ($c->isCheckable()) {
            $page .= $this->checkFrame($c, $x, $pdfBottom, $w, $h);
        } elseif ($c->kind === FormControl::SELECT && !$c->isListBox()) {
            $page .= $this->dropArrow($box, $x, $pdfBottom, $h);
        }

        // Value / state appearance in widget-local coordinates
        if ($c->isCheckable()) {
            $widget->normalAp = $this->checkMark($c, $w, $h, $color);
            $widget->offAp = '';
        } else {
            $widget->normalAp = $this->textAppearance($box, $c, $widget, $color);
        }

        if ($this->activeForms && $register) {
            if ($c->placeholder !== '' && $c->value === '' && !$c->isButton() && $c->kind !== FormControl::SELECT) {
                // The placeholder is page content, as browsers print it; it is not the field's value, so the
                // widget appearance stays empty and the field gets an opaque background (/MK /BG) that hides the
                // placeholder once a viewer draws a typed value
                $page .= sprintf("q 1 0 0 1 %.3F %.3F cm\n%sQ\n", $x, $pdfBottom, $widget->normalAp);
                $empty = clone $c;
                $empty->placeholder = '';
                $widget->normalAp = $this->textAppearance($box, $empty, $widget, $color);
                $bg = CssColor::parse($box->style->get('background-color', '#ffffff'));
                $widget->background = $bg !== null && $bg[3] > 0.5 ? sprintf('%.3F %.3F %.3F', $bg[0], $bg[1], $bg[2]) : '1 1 1';
            }
            $this->pdf->addFormWidget($pageIndex, $widget->rect, $widget);
            return $page;
        }

        // Flat ("print view") rendering: draw the appearance straight into the page
        $inner = $c->isCheckable() ? ($c->checked ? $widget->normalAp : '') : $widget->normalAp;
        if ($inner !== '') {
            $page .= sprintf("q 1 0 0 1 %.3F %.3F cm\n%sQ\n", $x, $pdfBottom, $inner);
        }
        return $page;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function textColor(LayoutBox $box, FormControl $c): array
    {
        if ($c->disabled) {
            return [0.4, 0.4, 0.4, 1.0];
        }
        return CssColor::parse($box->style->get('color', '#000000')) ?? [0.0, 0.0, 0.0, 1.0];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function textAppearance(LayoutBox $box, FormControl $c, FormWidget $widget, array $color): string
    {
        $w = $box->width;
        $h = $box->height;
        $fs = $box->fontSizePt;
        $family = $box->style->get('font-family', 'DejaVu Sans');
        $weight = $box->style->get('font-weight', 'normal');
        $fstyle = $box->style->get('font-style', 'normal');
        $ttf = $this->fontManager->resolveFont($family, $weight, $fstyle);
        $fontId = $this->fontManager->markFontUsed($ttf);
        $widget->apFontId = $fontId;

        $texts = [$c->value, $c->caption, $c->placeholder];
        foreach ($c->options as $o) {
            $texts[] = $o['label'];
            $texts[] = $o['value'];
        }
        foreach ($texts as $t) {
            if (preg_match('/[^\x20-\x7E\r\n\t]/', $t) === 1) {
                $widget->needsEmbeddedFont = true;
                break;
            }
        }

        $ascent = ($ttf->ascent / 1000.0) * $fs;
        $descent = (abs($ttf->descent) / 1000.0) * $fs;
        $lineH = $ascent + $descent;

        $left = $box->borderLeft + $box->paddingLeft;
        $right = $box->borderRight + $box->paddingRight;
        $arrow = ($c->kind === FormControl::SELECT && !$c->isListBox()) ? FormControls::ARROW_WIDTH : 0.0;
        $cw = max(1.0, $w - $left - $right - $arrow);
        $measure = fn(string $s): float => $this->fontManager->measureText($s, $family, $weight, $fstyle, $fs);

        $draw = function (string $text, float $tx, float $baselineY, array $rgb) use ($ttf, $fontId, $fs): string {
            if ($text === '') {
                return '';
            }
            $hex = $this->fontManager->encodeTextToHexGids($ttf, $text);
            if ($hex === '') {
                return '';
            }
            return sprintf(
                "BT /%s %.2F Tf %s %.3F %.3F Td <%s> Tj ET\n",
                $fontId,
                $fs,
                ColorOps::fillOp($rgb, false),
                $tx,
                $baselineY,
                $hex
            );
        };
        $alignX = function (float $tw) use ($left, $cw, $widget): float {
            return match ($widget->align) {
                1 => $left + max(0.0, ($cw - $tw) / 2.0),
                2 => $left + max(0.0, $cw - $tw),
                default => $left,
            };
        };

        $ops = '';
        $clipX = $box->borderLeft;
        $clipY = $box->borderBottom;
        $clipW = max(1.0, $w - $box->borderLeft - $box->borderRight);
        $clipH = max(1.0, $h - $box->borderTop - $box->borderBottom);
        $body = '';

        if ($c->isListBox() || $c->kind === FormControl::TEXTAREA) {
            $top = $box->borderTop + $box->paddingTop;
            if ($c->kind === FormControl::TEXTAREA) {
                $text = $c->value !== '' ? $c->value : $c->placeholder;
                // Only what the box can show is drawn: a 600 KB value would otherwise be measured and wrapped in full
                $text = mb_substr($text, 0, (int) min((float) strlen($text), (ceil($h / max(1.0, $lineH)) + 1) * (ceil($cw / max(0.5, $fs * 0.1)) + 1)));
                $rgb = $c->value !== '' ? $color : [0.46, 0.46, 0.46];
                $i = 0;
                foreach ($this->wrap($text, $cw, $measure) as $line) {
                    $by = $h - ($top + $i * $lineH + $ascent);
                    $body .= $draw($line, $alignX($measure($line)), $by, $rgb);
                    $i++;
                }
            } else {
                foreach ($c->options as $i => $o) {
                    $rowTop = $top + $i * $lineH;
                    if ($rowTop > $h) {
                        break;
                    }
                    if ($o['selected']) {
                        $body .= sprintf(
                            "0.780 0.839 0.961 rg %.3F %.3F %.3F %.3F re f\n",
                            $clipX,
                            $h - $rowTop - $lineH,
                            $clipW,
                            $lineH
                        );
                    }
                    $body .= $draw($o['label'], $left, $h - ($rowTop + $ascent), $color);
                }
            }
        } else {
            $text = $c->displayText();
            $rgb = $color;
            if ($text === '' && $c->placeholder !== '' && !$c->isButton() && $c->kind !== FormControl::SELECT) {
                $text = $c->placeholder;
                $rgb = [0.46, 0.46, 0.46];
            }
            $baselineFromTop = $box->formBaseline ?? ($box->borderTop + $box->paddingTop + $ascent);
            $body .= $draw($text, $alignX($measure($text)), $h - $baselineFromTop, $rgb);
        }

        if ($body === '') {
            return '';
        }
        $ops .= sprintf("q %.3F %.3F %.3F %.3F re W n\n", $clipX, $clipY, $clipW, $clipH);
        $ops .= $body . "Q\n";
        return $ops;
    }

    /**
     * Greedy word wrap honouring explicit newlines.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $maxWidth, callable $measure): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $para) {
            $cur = '';
            foreach (preg_split('/(?<= )/u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
                $try = $cur . $word;
                if ($cur !== '' && $measure(rtrim($try)) > $maxWidth) {
                    $lines[] = rtrim($cur);
                    $cur = $word;
                } else {
                    $cur = $try;
                }
                // Break over-long words character-wise
                while ($measure(rtrim($cur)) > $maxWidth && mb_strlen($cur) > 1) {
                    $n = mb_strlen($cur);
                    $k = $n - 1;
                    while ($k > 1 && $measure(mb_substr($cur, 0, $k)) > $maxWidth) {
                        $k--;
                    }
                    $lines[] = mb_substr($cur, 0, $k);
                    $cur = mb_substr($cur, $k);
                }
            }
            $lines[] = rtrim($cur);
        }
        return $lines;
    }

    private function checkFrame(FormControl $c, float $x, float $y, float $w, float $h): string
    {
        $bw = 0.75;
        $stroke = $c->disabled ? '0.6 0.6 0.6 RG' : '0.463 0.463 0.463 RG';
        $fill = $c->disabled ? '0.94 0.94 0.94 rg' : '1 1 1 rg';
        if ($c->kind === FormControl::RADIO) {
            $path = $this->ellipsePath($x + $w / 2.0, $y + $h / 2.0, $w / 2.0 - $bw / 2.0, $h / 2.0 - $bw / 2.0);
        } else {
            $path = $this->roundRectPath($x + $bw / 2.0, $y + $bw / 2.0, $w - $bw, $h - $bw, 2.0);
        }
        return sprintf("q %s %s %.2F w\n%sB\nQ\n", $fill, $stroke, $bw, $path);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    private function checkMark(FormControl $c, float $w, float $h, array $color): string
    {
        if ($c->kind === FormControl::RADIO) {
            $r = min($w, $h) * 0.27;
            return sprintf("q %.3F %.3F %.3F rg\n%sf\nQ\n", $color[0], $color[1], $color[2], $this->ellipsePath($w / 2.0, $h / 2.0, $r, $r));
        }
        return sprintf(
            "q %.3F %.3F %.3F RG %.2F w 1 J 1 j\n%.3F %.3F m %.3F %.3F l %.3F %.3F l S\nQ\n",
            $color[0],
            $color[1],
            $color[2],
            max(1.0, min($w, $h) * 0.16),
            $w * 0.22,
            $h * 0.50,
            $w * 0.43,
            $h * 0.26,
            $w * 0.79,
            $h * 0.77
        );
    }

    private function dropArrow(LayoutBox $box, float $x, float $y, float $h): string
    {
        $cx = $x + $box->width - $box->borderRight - 8.0;
        $cy = $y + $h / 2.0;
        return sprintf(
            "q 0.267 0.267 0.267 rg %.3F %.3F m %.3F %.3F l %.3F %.3F l f Q\n",
            $cx - 3.5,
            $cy + 1.6,
            $cx + 3.5,
            $cy + 1.6,
            $cx,
            $cy - 2.4
        );
    }

    private function ellipsePath(float $cx, float $cy, float $rx, float $ry): string
    {
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;
        return sprintf(
            "%.3F %.3F m\n%.3F %.3F %.3F %.3F %.3F %.3F c\n%.3F %.3F %.3F %.3F %.3F %.3F c\n%.3F %.3F %.3F %.3F %.3F %.3F c\n%.3F %.3F %.3F %.3F %.3F %.3F c\n",
            $cx + $rx,
            $cy,
            $cx + $rx,
            $cy + $ky,
            $cx + $kx,
            $cy + $ry,
            $cx,
            $cy + $ry,
            $cx - $kx,
            $cy + $ry,
            $cx - $rx,
            $cy + $ky,
            $cx - $rx,
            $cy,
            $cx - $rx,
            $cy - $ky,
            $cx - $kx,
            $cy - $ry,
            $cx,
            $cy - $ry,
            $cx + $kx,
            $cy - $ry,
            $cx + $rx,
            $cy - $ky,
            $cx + $rx,
            $cy
        );
    }

    private function roundRectPath(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = max(0.0, min($r, $w / 2.0, $h / 2.0));
        $k = $r * (1.0 - self::KAPPA);
        return sprintf(
            "%.3F %.3F m %.3F %.3F l %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F l %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F l %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F l %.3F %.3F %.3F %.3F %.3F %.3F c h\n",
            $x + $r,
            $y,
            $x + $w - $r,
            $y,
            $x + $w - $k,
            $y,
            $x + $w,
            $y + $k,
            $x + $w,
            $y + $r,
            $x + $w,
            $y + $h - $r,
            $x + $w,
            $y + $h - $k,
            $x + $w - $k,
            $y + $h,
            $x + $w - $r,
            $y + $h,
            $x + $r,
            $y + $h,
            $x + $k,
            $y + $h,
            $x,
            $y + $h - $k,
            $x,
            $y + $h - $r,
            $x,
            $y + $r,
            $x,
            $y + $k,
            $x + $k,
            $y,
            $x + $r,
            $y
        );
    }
}
