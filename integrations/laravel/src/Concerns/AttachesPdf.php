<?php

declare(strict_types=1);

namespace IvoPdf\Laravel\Concerns;

use Illuminate\Mail\Mailables\Attachment;
use IvoPdf\Laravel\PdfWrapper;

/**
 * Helpers for mailables: `use AttachesPdf;` then `$this->attachPdf($pdf, 'invoice.pdf')` in build(),
 * or return `[$this->pdfAttachment($pdf, 'invoice.pdf')]` from attachments().
 *
 * @mixin \Illuminate\Mail\Mailable
 */
trait AttachesPdf
{
    /** Attaches a rendered PDF (classic build() style). */
    protected function attachPdf(PdfWrapper $pdf, string $filename = 'document.pdf'): static
    {
        return $this->attachData($pdf->output(), $filename, ['mime' => 'application/pdf']);
    }

    /** Attachment object for the Laravel 9+ `attachments()` method. */
    protected function pdfAttachment(PdfWrapper $pdf, string $filename = 'document.pdf'): Attachment
    {
        return $pdf->toMailAttachment($filename);
    }
}
