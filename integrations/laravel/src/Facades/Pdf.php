<?php

declare(strict_types=1);

namespace IvoPdf\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IvoPdf\Laravel\PdfWrapper;

/**
 * @method static PdfWrapper loadView(string $view, array $data = [], array $mergeData = [], ?string $encoding = null)
 * @method static PdfWrapper loadHTML(string $html, ?string $encoding = null)
 * @method static PdfWrapper loadFile(string $file)
 * @method static PdfWrapper setPaper(string|array $paper, string $orientation = 'portrait')
 * @method static PdfWrapper setOrientation(string $orientation)
 * @method static PdfWrapper setBasePath(?string $path)
 * @method static PdfWrapper setOption(string $key, mixed $value)
 * @method static PdfWrapper setOptions(array $options)
 * @method static PdfWrapper setProtection(array $permissions = ['print', 'copy'], string $userPassword = '', ?string $ownerPassword = null, string|int $method = 'aes256')
 * @method static PdfWrapper setPdfA(string|bool $conformance = '2b')
 * @method static PdfWrapper setHeaderHtml(?string $html, string $pages = 'all')
 * @method static PdfWrapper setFooterHtml(?string $html, string $pages = 'all')
 * @method static PdfWrapper save(string $filename, ?string $disk = null)
 * @method static \Illuminate\Http\Response stream(string $filename = 'document.pdf')
 * @method static \Illuminate\Http\Response download(string $filename = 'document.pdf')
 * @method static string output()
 *
 * @see PdfWrapper
 */
class Pdf extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ivopdf.wrapper';
    }

    /** A fresh wrapper per call chain, so state never leaks between PDFs (like barryvdh/laravel-dompdf). */
    public static function getFacadeRoot(): mixed
    {
        return static::$app->make(static::getFacadeAccessor());
    }
}
