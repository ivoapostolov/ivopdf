# IvoPDF Symfony Bundle

Symfony bundle for [IvoPDF](../../README.md), the pure-PHP HTML/CSS to PDF engine with Flexbox, CSS Grid and full Cyrillic support (no wkhtmltopdf, no Chrome). Replaces KnpSnappyBundle and dompdf-based code without any external binary.

Requires PHP >= 8.1 and Symfony 6.4, 7.x or 8.x. License: AGPL-3.0-or-later, same as IvoPDF ([LICENSE](LICENSE)).

## Install

```bash
composer require ivopdf/symfony-bundle
```

Without Flex, register the bundle in `config/bundles.php` and copy the config:

```php
IvoPdf\Symfony\IvoPdfBundle::class => ['all' => true],
```

```bash
cp vendor/ivopdf/symfony-bundle/recipe/config/packages/ivopdf.yaml config/packages/ivopdf.yaml
```

`config/packages/ivopdf.yaml` (all keys optional):

```yaml
ivopdf:
    paper: A4
    orientation: portrait
    default_font: 'DejaVu Sans'
    default_font_size: 12
    base_path: '%kernel.project_dir%/public'        # default
    allowed_paths: ['%kernel.project_dir%/public', '%kernel.project_dir%/templates']   # default
    remote_enabled: false
    pdfa: ~                                         # '2b' | '3b'
    fonts:
        Inter: { regular: '%kernel.project_dir%/assets/fonts/Inter.ttf' }
    options: {}                                     # raw IvoPdf\Options keys (dompdf / mPDF names accepted)
```

Other keys: `margins` (points), `compress`, `subset_fonts`, `bookmarks`, `form_fields`, `lang`, `creator`, `fallback_fonts`. See `bin/console config:dump-reference ivopdf`.

## Usage

```php
use IvoPdf\Symfony\PdfGenerator;

final class InvoiceController extends AbstractController
{
    #[Route('/invoice/{id}')]
    public function show(Invoice $invoice, PdfGenerator $pdf): Response
    {
        $doc = $pdf->fromTwig('invoice/show.html.twig', ['invoice' => $invoice])   // needs symfony/twig-bundle
            ->setPaper('A4', 'landscape')
            ->setFooterHtml('<div style="text-align:right">{PAGE_NUM} / {PAGE_COUNT}</div>');

        return $pdf->toResponse($doc, 'Фактура '.$invoice->getNumber().'.pdf', PdfGenerator::ATTACHMENT);  // or INLINE
    }
}
```

| Service / method | Purpose |
|---|---|
| `PdfGenerator::fromHtml($html, $options = [])` / `fromTwig($tpl, $context, $options = [])` | returns a configured `IvoPdf\IvoPdf` with the HTML loaded; chain `setPaper()`, `setProtection()`, `setPdfA()`, `setSignature()`, `attachFile()`... |
| `toResponse($pdfOrBytes, $filename, $disposition)` | `Response`, UTF-8 safe `Content-Disposition` |
| `toStreamedResponse(...)` | `StreamedResponse` |
| `getOutputFromHtml()` / `getOutputFromTwig()` | raw bytes (e.g. for Mailer attachments, KnpSnappy naming) |
| `PdfFactory::create($options = [])` | fresh engine without HTML |
| `IvoPdf\IvoPdf` | autowirable, non-shared (new configured instance per injection) |

Images referenced as `<img src="{{ asset('img/logo.png') }}">` (`/img/logo.png`) or `img/logo.png` resolve against `base_path` (`public/`), so no HTTP request is made and remote loading stays off.

Mailer attachment:

```php
$email->attach($pdf->getOutputFromTwig('invoice/show.html.twig', $ctx), 'invoice.pdf', 'application/pdf');
```

## Migrating from KnpSnappyBundle

| KnpSnappy | IvoPDF |
|---|---|
| `Knp\Snappy\Pdf $snappy` | `IvoPdf\Symfony\PdfGenerator $pdf` |
| `$snappy->getOutputFromHtml($html, ['orientation' => 'Landscape', 'page-size' => 'A4'])` | `$pdf->getOutputFromHtml($html, ['orientation' => 'landscape', 'paperSize' => 'A4'])` |
| `new PdfResponse($snappy->getOutputFromHtml($html), 'file.pdf')` | `$pdf->toResponse($pdf->fromHtml($html), 'file.pdf')` |
| `$twig->render(...)` then `getOutputFromHtml` | `$pdf->fromTwig('tpl.html.twig', $ctx)` |
| `--margin-top 10mm` | `margins` config (points) or `$engine->setMargins(28)` |
| `--header-html` / `--footer-html` | `$engine->setHeaderHtml()` / `setFooterHtml()` with `{PAGE_NUM}` / `{PAGE_COUNT}` |
| `--enable-javascript`, JS-driven layouts | not supported (static HTML/CSS, Flexbox and Grid are) |
| `knp_snappy.pdf.binary` | none required |

## Migrating from dompdf

```php
// before
$dompdf = new Dompdf(new Options(['isRemoteEnabled' => true]));
$dompdf->loadHtml($html); $dompdf->setPaper('A4', 'landscape'); $dompdf->render();
$output = $dompdf->output();

// after
$engine = $pdf->fromHtml($html, ['isRemoteEnabled' => true])->setPaper('A4', 'landscape');
$output = $engine->output();     // render() is automatic
```

dompdf / mPDF option names (`isRemoteEnabled`, `chroot`, `defaultFont`, `format`, `margin_top`, ...) are accepted.

## Tests

```bash
cd integrations/symfony
composer install
vendor/bin/phpunit
```

For local development against the repository checkout, `composer.json` contains a `path` repository pointing at `../..` (ignored when installed as a dependency).
