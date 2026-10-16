<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * A PDF stream: dictionary plus the raw (still filtered) bytes.
 */
final class PdfStream extends PdfDict
{
    /** @param array<string, mixed> $items */
    public function __construct(array $items, public readonly string $raw)
    {
        parent::__construct($items);
    }
}
