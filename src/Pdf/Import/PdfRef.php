<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * An indirect reference ("12 0 R").
 */
final class PdfRef
{
    public function __construct(public readonly int $num, public readonly int $gen = 0)
    {
    }
}
