<?php

declare(strict_types=1);

namespace IvoPdf\Laravel\Tests;

use Illuminate\Http\Response;
use IvoPdf\IvoPdf;
use IvoPdf\Laravel\Facades\Pdf;
use IvoPdf\Laravel\PdfWrapper;

final class PdfWrapperTest extends TestCase
{
    private const HTML = '<h1>Здравей, свят</h1><p>Hello</p>';

    public function testContainerResolvesFreshWrappers(): void
    {
        $a = $this->app->make('ivopdf.wrapper');
        $b = $this->app->make(PdfWrapper::class);
        $this->assertInstanceOf(PdfWrapper::class, $a);
        $this->assertNotSame($a, $b);
        $this->assertInstanceOf(IvoPdf::class, $this->app->make(IvoPdf::class));
    }

    public function testConfigIsAppliedToEngine(): void
    {
        $this->app['config']->set('ivopdf.paper', 'letter');
        $this->app['config']->set('ivopdf.orientation', 'landscape');
        $engine = Pdf::loadHTML(self::HTML)->getEngine();
        $this->assertSame('landscape', $engine->getOptions()->orientation);
        $this->assertSame(__DIR__ . '/public', $engine->getOptions()->basePath);
        [$w, $h] = $engine->getOptions()->getPageDimensions();
        $this->assertSame(792.0, $w);
        $this->assertSame(612.0, $h);
    }

    public function testOutputIsPdf(): void
    {
        $bytes = Pdf::loadHTML(self::HTML)->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
    }

    public function testLoadViewRendersBladeWithCyrillicGridAndImage(): void
    {
        $pdf = Pdf::loadView('invoice', ['number' => '№ 1001', 'client' => 'Иван Петров']);
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        $this->assertSame(1, $pdf->getPageCount());
        $this->assertSame([], $pdf->getWarnings(), 'logo.png must resolve against base_path');
    }

    public function testSetPaperAndOptionsAreOrderIndependent(): void
    {
        $pdf = Pdf::setPaper('a4', 'landscape')->setOption('defaultFont', 'DejaVu Serif')->setOptions(['isRemoteEnabled' => true])->loadHTML(self::HTML);
        $o = $pdf->getEngine()->getOptions();
        $this->assertSame('landscape', $o->orientation);
        $this->assertSame('DejaVu Serif', $o->defaultFont);
        $this->assertTrue($o->isRemoteEnabled);
    }

    public function testDownloadResponseHasUtf8Filename(): void
    {
        $response = Pdf::loadHTML(self::HTML)->download('Фактура 100%.pdf');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('Фактура 100%.pdf'), $disposition);
        $this->assertStringContainsString('filename="_______ 100_.pdf"', $disposition);
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    public function testStreamIsInline(): void
    {
        $response = Pdf::loadHTML(self::HTML)->stream('x.pdf');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    public function testSaveToPathAndDisk(): void
    {
        $path = sys_get_temp_dir() . '/ivopdf-laravel-' . uniqid() . '/out.pdf';
        Pdf::loadHTML(self::HTML)->save($path);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        unlink($path);
        rmdir(dirname($path));

        $this->app['config']->set('filesystems.disks.pdfs', ['driver' => 'local', 'root' => sys_get_temp_dir() . '/ivopdf-disk-' . uniqid()]);
        Pdf::loadHTML(self::HTML)->save('a/b.pdf', 'pdfs');
        $root = $this->app['config']->get('filesystems.disks.pdfs.root');
        $this->assertFileExists($root . '/a/b.pdf');
    }

    public function testCoreMethodsAreForwardedAndChainable(): void
    {
        $pdf = Pdf::loadHTML(self::HTML)
            ->setFooterHtml('<div>{PAGE_NUM}/{PAGE_COUNT}</div>')
            ->setProtection(['print'], 'user', 'owner')
            ->addInfo(['Title' => 'Doc']);
        $this->assertInstanceOf(PdfWrapper::class, $pdf);
        $bytes = $pdf->output();
        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertSame(1, $pdf->getPageCount());
    }

    public function testUnknownMethodThrows(): void
    {
        $this->expectException(\BadMethodCallException::class);
        Pdf::loadHTML('x')->doesNotExist();
    }

    public function testAssetUrlsAreRewrittenToLocalFiles(): void
    {
        $html = '<img src="' . asset('logo.png') . '" width="20" height="20">';
        $pdf = Pdf::loadHTML($html);
        $pdf->output();
        $this->assertSame([], $pdf->getWarnings());
    }

    public function testMailAttachment(): void
    {
        $attachment = Pdf::loadHTML(self::HTML)->toMailAttachment('a.pdf');
        $this->assertInstanceOf(\Illuminate\Mail\Mailables\Attachment::class, $attachment);
    }

    public function testWrapperStateDoesNotLeakBetweenFacadeChains(): void
    {
        Pdf::setPaper('a3', 'landscape');
        $o = Pdf::loadHTML('x')->getEngine()->getOptions();
        $this->assertSame('portrait', $o->orientation);
    }
}
