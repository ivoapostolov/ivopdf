<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Raised when a source PDF cannot be read or imported (corrupt, encrypted, over the safety limits).
 */
class PdfImportException extends \RuntimeException
{
}
