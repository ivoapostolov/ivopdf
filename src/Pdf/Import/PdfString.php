<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * A PDF string object holding its decoded bytes.
 */
final class PdfString
{
    public function __construct(public readonly string $bytes)
    {
    }
}
