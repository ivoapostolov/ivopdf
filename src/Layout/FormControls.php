<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use IvoPdf\Font\FontManager;

/**
 * User-agent styling and intrinsic sizing of form controls (replaced inline-block boxes).
 * Browser-like defaults (Chrome / Edge): 1px #767676 border, white background, 10pt Arial-metric font (Liberation
 * Sans; DejaVu Sans Mono in a textarea), no margins;
 * checkbox/radio 9.75pt; text input 132.75pt wide (20 characters); textarea in a monospace font, 20 columns x
 * 2 rows, sitting on the baseline with its bottom edge; buttons shrink-to-fit their caption.
 */
final class FormControls
{
    public const DEFAULT_INPUT_WIDTH = 132.75;
    public const CHECK_SIZE = 9.75;
    /** Width reserved on the right of a combo box for the drop-down arrow. */
    public const ARROW_WIDTH = 14.0;

    public function __construct(private FontManager $fontManager)
    {
    }

    /**
     * Applies UA defaults for <input>, <textarea>, <select> and <button>; author CSS overrides them afterwards.
     */
    public static function applyDefaults(ComputedStyle $s, string $tag, string $type = ''): void
    {
        $type = strtolower(trim($type));
        $s->set('display', 'inline-block');
        $s->set('font-size', '10pt');
        $s->set('font-weight', 'normal');
        $s->set('font-style', 'normal');
        $s->set('color', '#000000');
        $s->set('text-align', 'left');
        $s->set('text-transform', 'none');
        $s->set('text-indent', '0');
        $s->set('white-space', 'normal');
        $s->set('letter-spacing', '0');
        $s->set('text-decoration', 'none');
        $s->set('vertical-align', 'baseline');
        $s->set('margin-left', '0');
        $s->set('margin-right', '0');

        if ($tag === 'input' && $type === 'hidden') {
            $s->set('display', 'none');
            return;
        }
        if ($tag === 'input' && ($type === 'checkbox' || $type === 'radio')) {
            $s->set('border', '0 none transparent');
            $s->set('padding', '0');
            $s->set('background-color', 'transparent');
            $s->set('border-radius', '0');
            $s->set('width', self::CHECK_SIZE . 'pt');
            $s->set('height', self::CHECK_SIZE . 'pt');
            // Chrome: margin 3px 3px 3px 4px
            $s->set('margin-left', '3pt');
            $s->set('margin-right', '2.25pt');
            $s->set('margin-top', '2.25pt');
            $s->set('margin-bottom', '2.25pt');
            return;
        }

        $s->set('border', '1px solid #767676');
        $s->set('background-color', '#ffffff');
        $s->set('border-radius', '2pt');

        $isButton = $tag === 'button' || ($tag === 'input' && in_array($type, ['submit', 'reset', 'button', 'image'], true));
        if ($isButton) {
            $s->set('background-color', '#efefef');
            $s->set('padding', '1.5pt 5.25pt');
            $s->set('text-align', 'center');
            $s->set('border-radius', '3pt');
        } elseif ($tag === 'select') {
            $s->set('padding', '0.75pt 1.5pt');
        } else {
            $s->set('padding', '1.5pt 2.25pt');
        }
        // Controls do not inherit the page font: browsers use Arial (Liberation Sans has its metrics) 13.33px, and a
        // monospace font for <textarea>
        $s->set('font-family', $tag === 'textarea' ? 'DejaVu Sans Mono' : 'Liberation Sans');
    }

    /**
     * Content line height (ascent + descent) and ascent of the control's font, in pt.
     *
     * @return array{0: float, 1: float} [lineHeight, ascent]
     */
    public function lineMetrics(LayoutBox $box): array
    {
        $ttf = $this->fontManager->resolveFont(
            $box->style->get('font-family', 'DejaVu Sans'),
            $box->style->get('font-weight', 'normal'),
            $box->style->get('font-style', 'normal')
        );
        $asc = ($ttf->ascent / 1000.0) * $box->fontSizePt;
        $desc = (abs($ttf->descent) / 1000.0) * $box->fontSizePt;
        return [$asc + $desc, $asc];
    }

    public function textWidth(LayoutBox $box, string $text): float
    {
        return $this->fontManager->measureText(
            $text,
            $box->style->get('font-family', 'DejaVu Sans'),
            $box->style->get('font-weight', 'normal'),
            $box->style->get('font-style', 'normal'),
            $box->fontSizePt
        );
    }

    /**
     * Default border-box size and baseline offset (from the box top) of a control whose metrics
     * (font size, border, padding) are already resolved.
     *
     * @return array{w: float, h: float, baseline: float}
     */
    public function defaultSize(LayoutBox $box): array
    {
        $c = $box->formControl;
        $hs = $box->getHorizontalSpacing();
        $vs = $box->getVerticalSpacing();
        $fs = $box->fontSizePt;
        [$lineH, $asc] = $this->lineMetrics($box);

        if ($c === null) {
            return ['w' => 0.0, 'h' => 0.0, 'baseline' => 0.0];
        }
        if ($c->isCheckable()) {
            return ['w' => self::CHECK_SIZE + $hs, 'h' => self::CHECK_SIZE + $vs, 'baseline' => self::CHECK_SIZE + $vs];
        }
        if ($c->isButton()) {
            $w = $this->textWidth($box, $c->caption) + $hs;
            $h = $lineH + $vs;
            return ['w' => max($w, 2 * $fs), 'h' => $h, 'baseline' => $box->borderTop + $box->paddingTop + $asc];
        }
        if ($c->kind === FormControl::TEXTAREA) {
            return [
                'w' => $c->cols * $this->textWidth($box, '0') + $hs,
                'h' => $c->rows * $lineH + $vs,
                'baseline' => $box->borderTop + $box->paddingTop + $asc,
            ];
        }
        if ($c->kind === FormControl::SELECT) {
            $maxLabel = 0.0;
            foreach ($c->options as $o) {
                $maxLabel = max($maxLabel, $this->textWidth($box, $o['label']));
            }
            $w = max($maxLabel + ($c->isListBox() ? 0.0 : self::ARROW_WIDTH) + $hs, 3 * $fs);
            if ($c->isListBox()) {
                $rows = $c->size > 1 ? $c->size : 4;
                return ['w' => $w, 'h' => $rows * $lineH + $vs, 'baseline' => $box->borderTop + $box->paddingTop + $asc];
            }
            return ['w' => $w, 'h' => $lineH + $vs, 'baseline' => $box->borderTop + $box->paddingTop + $asc];
        }

        $w = $c->size > 0 ? $c->size * 0.55 * $fs + $hs : self::DEFAULT_INPUT_WIDTH;
        $contentH = $lineH;
        return [
            'w' => $w,
            'h' => $contentH + $vs,
            'baseline' => $box->borderTop + $box->paddingTop + $asc,
        ];
    }
}
