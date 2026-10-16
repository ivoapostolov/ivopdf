<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use DOMElement;
use IvoPdf\Css\CssColor;
use IvoPdf\Pdf\PdfDocument;

/**
 * PDF-side effects of the marker elements produced by MpdfTags (`<bookmark>` outline entries, `<annotation>` notes).
 * Called by the renderer for every element that starts on the page being painted.
 */
final class MpdfRenderHooks
{
    /** @var array<string, int> marker id => 0-based page it really belongs to (inline boxes can be painted on a neighbouring page too) */
    public static array $markerPages = [];

    private const ICONS = ['comment', 'help', 'insert', 'key', 'newparagraph', 'note', 'paragraph'];

    /**
     * @param float $x     left edge of the element in page space (pt)
     * @param float $pdfY  top edge of the element in PDF page space (bottom-left origin)
     */
    public static function apply(PdfDocument $pdf, DOMElement $el, int $pageIndex, float $x, float $pdfY): void
    {
        if (!$el->hasAttribute('data-ivo-bookmark') && !$el->hasAttribute('data-ivo-annot')) {
            return;
        }
        $id = $el->getAttribute('id');
        if ($id !== '' && isset(self::$markerPages[$id]) && self::$markerPages[$id] !== $pageIndex) {
            return;
        }
        if ($el->hasAttribute('data-ivo-bookmark')) {
            $title = trim($el->getAttribute('data-ivo-bookmark'));
            if ($title !== '') {
                $pdf->addOutline(max(0, (int) $el->getAttribute('data-ivo-level')) + 1, $title, $pageIndex, $pdfY);
            }
        }
        if ($el->hasAttribute('data-ivo-annot')) {
            $px = $el->hasAttribute('data-ivo-pos-x') ? (float) $el->getAttribute('data-ivo-pos-x') * 72 / 25.4 : $x;
            $py = $pdfY;
            if ($el->hasAttribute('data-ivo-pos-y')) {
                $py = $pdf->pageHeightOf($pageIndex) - (float) $el->getAttribute('data-ivo-pos-y') * 72 / 25.4;
            }
            $icon = strtolower($el->getAttribute('data-ivo-icon'));
            $icon = in_array($icon, self::ICONS, true) ? ($icon === 'newparagraph' ? 'NewParagraph' : ucfirst($icon)) : 'Note';
            $color = CssColor::parse($el->getAttribute('data-ivo-color') ?: '#ffff00');
            $opacity = (float) $el->getAttribute('data-ivo-opacity');
            $pdf->addTextAnnotation(
                $pageIndex,
                $px,
                $py,
                $el->getAttribute('data-ivo-annot'),
                $el->getAttribute('data-ivo-title'),
                $icon,
                $color !== null ? [$color[0], $color[1], $color[2]] : [1.0, 1.0, 0.0],
                $opacity > 0 ? min(1.0, $opacity) : 1.0
            );
        }
    }
}
