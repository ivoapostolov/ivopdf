# IvoPDF for Laravel

Laravel integration for [IvoPDF](../../README.md), the pure-PHP HTML/CSS to PDF engine with Flexbox, CSS Grid and full Cyrillic support (no Chrome, no wkhtmltopdf). The API mirrors `barryvdh/laravel-dompdf`, so most code only needs the `use` line changed.

Requires PHP >= 8.1 and Laravel 10, 11, 12 or 13. License: AGPL-3.0-or-later, same as IvoPDF ([LICENSE](LICENSE)).

## Install

```bash
composer require ivopdf/laravel
php artisan vendor:publish --tag=ivopdf-config   # optional: config/ivopdf.php
```

The service provider and the `Pdf` / `PDF` facade aliases are registered by package auto-discovery.

## Usage

```php
use IvoPdf\Laravel\Facades\Pdf;   // or the global aliases \Pdf / \PDF

return Pdf::loadView('invoices.show', $data)
    ->setPaper('a4', 'landscape')
    ->download('invoice.pdf');           // attachment

return Pdf::loadView('invoices.show', $data)->stream('invoice.pdf');   // inline in the browser

Pdf::loadHTML('<h1>Здравей</h1>')->save(storage_path('app/invoice.pdf'));
Pdf::loadView('invoices.show', $data)->save('invoices/2026-001.pdf', 's3');   // through Storage disks

$bytes = Pdf::loadFile(resource_path('html/report.html'))->output();
```

Every call chain starts from a fresh wrapper (config defaults), so state never leaks between PDFs. Dependency injection works too:

```php
public function show(\IvoPdf\Laravel\PdfWrapper $pdf) { return $pdf->loadView('invoices.show')->stream(); }
public function core(\IvoPdf\IvoPdf $engine) { /* plain core engine with config applied */ }
```

### Wrapper API

| Method | Notes |
|---|---|
| `loadView($view, $data = [], $mergeData = [])`, `loadHTML($html)`, `loadFile($path)` | Blade is rendered with `view()->make()->render()` |
| `setPaper($size, $orientation)`, `setOrientation()`, `setBasePath()` | `'a4'`, `'letter'`, or `[widthPt, heightPt]` |
| `setOption($key, $value)`, `setOptions([...])` | IvoPdf option names and dompdf/mPDF names (`isRemoteEnabled`, `chroot`, `defaultFont`, `format`, ...) |
| `addInfo(['Title' => ..., 'Author' => ...])`, `setEncryption($user, $owner, $perms)` | dompdf-compatible helpers |
| `output()`, `save($path, $disk = null)`, `stream($name)`, `download($name)`, `render()` | `stream`/`download` return `Illuminate\Http\Response` with `Content-Disposition` including an RFC 5987 UTF-8 filename (`Фактура.pdf` works) |
| `getWarnings()`, `getPageCount()`, `getEngine()` | `getEngine()` returns the configured `IvoPdf\IvoPdf` |
| any other `IvoPdf` method | `setHeaderHtml()`, `setFooterHtml()`, `setProtection()`, `setPdfA()`, `setSignature()`, `attachFile()`, `setWatermarkText()`, `registerFontFamily()`, ... are forwarded and stay chainable |

### Assets in views

Relative paths resolve against `public_path()` (`base_path` in config) and only `allowed_paths` are readable:

```blade
<img src="img/logo.png">            {{-- public/img/logo.png --}}
<img src="{{ asset('img/logo.png') }}">   {{-- rewritten to the local file, no HTTP request --}}
```

`asset()` URLs of the current app are rewritten to local files (`rewrite_asset_urls`), so remote loading can stay disabled. Third-party URLs need `'remote_enabled' => true` (SSRF-protected).

## Configuration

`config/ivopdf.php`: `paper`, `orientation`, `default_font`, `default_font_size`, `margins`, `base_path`, `allowed_paths`, `rewrite_asset_urls`, `remote_enabled`, `compress`, `subset_fonts`, `bookmarks`, `form_fields`, `lang`, `creator`, `pdfa`, `fonts`, `fallback_fonts`, and `options` (raw `IvoPdf\Options` keys applied last). Example custom font:

```php
'fonts' => ['Inter' => ['regular' => resource_path('fonts/Inter.ttf'), 'bold' => resource_path('fonts/Inter-Bold.ttf')]],
```

## Mail attachments

```php
use IvoPdf\Laravel\Concerns\AttachesPdf;
use IvoPdf\Laravel\Facades\Pdf;

class InvoiceMail extends Mailable
{
    use AttachesPdf;

    public function attachments(): array
    {
        return [Pdf::loadView('invoices.show', $this->data)->toMailAttachment('invoice.pdf')];
    }
}

// or the classic way
$this->attachData(Pdf::loadView('invoices.show', $data)->output(), 'invoice.pdf', ['mime' => 'application/pdf']);
// or via the trait inside build():  return $this->attachPdf(Pdf::loadView(...), 'invoice.pdf');
```

## Migrating from barryvdh/laravel-dompdf

```bash
composer remove barryvdh/laravel-dompdf
composer require ivopdf/laravel
```

| dompdf code | Change |
|---|---|
| `use Barryvdh\DomPDF\Facade\Pdf;` | `use IvoPdf\Laravel\Facades\Pdf;` (global `\PDF` / `\Pdf` aliases keep working) |
| `Pdf::loadView()`, `loadHTML()`, `loadFile()`, `setPaper()`, `setOption()`, `setOptions()`, `output()`, `save()`, `stream()`, `download()` | unchanged |
| `config/dompdf.php` (`options.chroot`, `isRemoteEnabled`, `defaultFont`, `defaultPaperSize`) | `config/ivopdf.php`; the old option names also work in `options` / `setOption()` |
| `$pdf->getDomPDF()` | `$pdf->getEngine()` |
| `->setWarnings(false)` | accepted and ignored; use `getWarnings()` |
| CSS `float` / table layouts | still work; you can now also use `display: flex` and `display: grid` |
| `@page`, `counter(page)` | supported; also `setFooterHtml('{PAGE_NUM}/{PAGE_COUNT}')` |

Differences: IvoPDF reads local files only from `allowed_paths`; DejaVu Sans (Cyrillic-ready) is the default font; PDF encryption, PDF/A and digital signatures are available through `setProtection()`, `setPdfA()` and `setSignature()`.

## Tests

```bash
cd integrations/laravel
composer install
vendor/bin/phpunit      # Orchestra Testbench
```

For local development against the repository checkout, `composer.json` contains a `path` repository pointing at `../..` (ignored when installed as a dependency).
