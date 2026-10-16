<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * A PDF name object (without the leading slash, #xx escapes already decoded).
 */
final class PdfName
{
    public function __construct(public readonly string $name)
    {
    }
}
