<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Layout\FormControl;

/**
 * One placed form control: the field description plus its pre-rendered appearance streams.
 * Appearance content is expressed in widget-local coordinates (origin bottom-left, size width x height).
 */
final class FormWidget
{
    /** Widget rectangle in PDF page coordinates: [x1, y1, x2, y2]. */
    public array $rect = [0.0, 0.0, 0.0, 0.0];
    public int $page = 0;
    public float $width = 0.0;
    public float $height = 0.0;

    /** Normal appearance of text/choice/button fields; the "on" state of check boxes and radio buttons. */
    public string $normalAp = '';
    /** "Off" state appearance of check boxes and radio buttons. */
    public string $offAp = '';

    /** Resource name of the embedded Type0 font used inside the appearance streams (e.g. 'F1'), '' when none. */
    public string $apFontId = '';
    /** True when the value needs the embedded Unicode font (non-ASCII text), so /DA references it instead of Helvetica. */
    public bool $needsEmbeddedFont = false;
    public float $fontSize = 10.0;
    /** Text colour as PDF operator operands, e.g. "0 0 0 rg". */
    public string $colorOps = '0 g';
    /** Quadding: 0 left, 1 centre, 2 right. */
    public int $align = 0;
    /** Background colour operands ("1 1 1") for /MK /BG: an appearance the viewer regenerates covers the page below */
    public string $background = '';

    public function __construct(public FormControl $control)
    {
    }
}
