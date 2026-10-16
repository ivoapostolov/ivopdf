<?php

declare(strict_types=1);

namespace IvoPdf\Symfony;

use IvoPdf\IvoPdf;
use LogicException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

/**
 * Autowirable service: HTML / Twig to PDF plus Symfony responses.
 *
 * ```php
 * public function invoice(PdfGenerator $pdf): Response
 * {
 *     $doc = $pdf->fromTwig('invoice/show.html.twig', ['invoice' => $invoice]);
 *     return $pdf->toResponse($doc, 'invoice.pdf');
 * }
 * ```
 */
final class PdfGenerator
{
    public const INLINE = ResponseHeaderBag::DISPOSITION_INLINE;
    public const ATTACHMENT = ResponseHeaderBag::DISPOSITION_ATTACHMENT;

    public function __construct(private readonly PdfFactory $factory, private readonly ?Environment $twig = null)
    {
    }

    /**
     * Creates an engine with the HTML loaded. Further IvoPdf calls (setPaper, setFooterHtml, setProtection...) are possible
     * before it is rendered by toResponse() / output().
     *
     * @param array<string, mixed> $options overrides for the bundle configuration
     */
    public function fromHtml(string $html, array $options = []): IvoPdf
    {
        $engine = $this->factory->create($options);
        $engine->loadHtml($html);
        return $engine;
    }

    /**
     * Renders a Twig template and loads the result.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options overrides for the bundle configuration
     */
    public function fromTwig(string $template, array $context = [], array $options = []): IvoPdf
    {
        if ($this->twig === null) {
            throw new LogicException('fromTwig() requires Twig: composer require symfony/twig-bundle');
        }
        return $this->fromHtml($this->twig->render($template, $context), $options);
    }

    /** Raw PDF bytes of an HTML string (KnpSnappy: getOutputFromHtml). */
    public function getOutputFromHtml(string $html, array $options = []): string
    {
        return $this->fromHtml($html, $options)->output();
    }

    /** Raw PDF bytes of a Twig template. */
    public function getOutputFromTwig(string $template, array $context = [], array $options = []): string
    {
        return $this->fromTwig($template, $context, $options)->output();
    }

    /**
     * Response carrying the PDF with a UTF-8 safe Content-Disposition.
     *
     * @param IvoPdf|string $pdf an engine (rendered here) or ready PDF bytes
     * @param string $disposition self::INLINE or self::ATTACHMENT
     */
    public function toResponse(IvoPdf|string $pdf, string $filename = 'document.pdf', string $disposition = self::ATTACHMENT, int $status = 200): Response
    {
        $bytes = $pdf instanceof IvoPdf ? $pdf->output() : $pdf;
        return new Response($bytes, $status, $this->headers($bytes, $filename, $disposition));
    }

    /** Same as toResponse() but sent through a StreamedResponse (bytes are produced on send). */
    public function toStreamedResponse(IvoPdf|string $pdf, string $filename = 'document.pdf', string $disposition = self::ATTACHMENT): StreamedResponse
    {
        $bytes = $pdf instanceof IvoPdf ? $pdf->output() : $pdf;
        return new StreamedResponse(static function () use ($bytes): void {
            echo $bytes;
        }, 200, $this->headers($bytes, $filename, $disposition));
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $bytes, string $filename, string $disposition): array
    {
        $filename = trim(str_replace(["\r", "\n", "\0"], '', $filename));
        $filename = $filename !== '' ? $filename : 'document.pdf';
        $fallback = preg_replace('/[^\x20-\x7E]|[%\\\\\/"]/u', '_', $filename) ?: 'document.pdf';
        return [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $fallback),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ];
    }
}
