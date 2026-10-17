<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Identity shared by a ComputedStyle and its clones for as long as none of them is modified (see
 * ComputedStyle::shareToken()). It also holds the text style derived from a shared style, so all text nodes of the
 * cells that share a style share one text style as well.
 *
 * @internal
 */
final class StyleToken
{
    public ?ComputedStyle $textStyle = null;
}
