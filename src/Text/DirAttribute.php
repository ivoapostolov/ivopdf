<?php

declare(strict_types=1);

namespace IvoPdf\Text;

use DOMElement;
use IvoPdf\Css\ComputedStyle;

/**
 * Maps the HTML `dir` attribute (and <bdi>/<bdo>) to CSS direction / unicode-bidi, and mirrors the
 * user-agent list indentation for right-to-left lists.
 */
final class DirAttribute
{
    /**
     * Applies dir="ltr|rtl|auto" (presentational hint, overridable by CSS) to the element's style.
     */
    public static function apply(DOMElement $el, ComputedStyle $style, string $tag): void
    {
        $dir = $el->hasAttribute('dir') ? strtolower(trim($el->getAttribute('dir'))) : ($tag === 'bdi' ? 'auto' : '');
        if ($dir !== 'ltr' && $dir !== 'rtl' && $dir !== 'auto') {
            if ($tag === 'bdi') {
                $style->set('unicode-bidi', 'isolate');
            }
            return;
        }
        if ($dir === 'auto') {
            $first = Bidi::firstStrong($el->textContent);
            $dir = $first === 1 ? 'rtl' : 'ltr';
        }
        $style->set('direction', $dir);
        $style->set('unicode-bidi', $tag === 'bdo' ? 'bidi-override' : 'isolate');
    }

    /**
     * After the cascade: right-to-left lists indent on the right.
     */
    public static function finish(ComputedStyle $style, string $tag): void
    {
        if (($tag === 'ul' || $tag === 'ol' || $tag === 'menu')
            && strtolower(trim($style->get('direction', 'ltr'))) === 'rtl'
            && trim($style->get('padding-left', '0')) === '30pt'
            && in_array(trim($style->get('padding-right', '0')), ['0', '0pt', '0px', ''], true)) {
            $style->set('padding-right', '30pt');
            $style->set('padding-left', '0');
        }
    }
}
