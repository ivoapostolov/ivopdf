<?php

declare(strict_types=1);

namespace IvoPdf\Symfony\Tests;

use IvoPdf\IvoPdf;
use IvoPdf\Symfony\IvoPdfBundle;
use IvoPdf\Symfony\PdfFactory;
use IvoPdf\Symfony\PdfGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly array $ivopdfConfig = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new IvoPdfBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/ivopdf-sf-cache/' . md5(serialize($this->ivopdfConfig));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', ['secret' => 'x', 'test' => true, 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]]);
        $container->loadFromExtension('twig', ['default_path' => __DIR__ . '/templates']);
        $container->loadFromExtension('ivopdf', $this->ivopdfConfig);
    }
}

final class BundleTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/ivopdf-sf-cache');
    }

    private function boot(array $config = []): TestKernel
    {
        $kernel = new TestKernel($config);
        $kernel->boot();
        return $kernel;
    }

    public function testServicesAreRegistered(): void
    {
        $c = $this->boot()->getContainer();
        $this->assertInstanceOf(PdfFactory::class, $c->get(PdfFactory::class));
        $this->assertInstanceOf(PdfGenerator::class, $c->get(PdfGenerator::class));
        $this->assertNotSame($c->get(IvoPdf::class), $c->get(IvoPdf::class));
    }

    public function testConfigurationIsApplied(): void
    {
        $c = $this->boot(['paper' => 'Letter', 'orientation' => 'landscape', 'default_font_size' => 10, 'options' => ['title' => 'T']])->getContainer();
        $o = $c->get(IvoPdf::class)->getOptions();
        $this->assertSame('landscape', $o->orientation);
        $this->assertSame(__DIR__ . '/public', $o->basePath);
        $this->assertSame(10.0, $o->defaultFontSize);
        $this->assertSame('T', $o->title);
        [$w, $h] = $o->getPageDimensions();
        $this->assertSame(792.0, $w);
        $this->assertSame(612.0, $h);
    }

    public function testFromHtmlAndResponse(): void
    {
        $gen = $this->boot()->getContainer()->get(PdfGenerator::class);
        $response = $gen->toResponse($gen->fromHtml('<h1>Привет</h1>'), 'Фактура.pdf', PdfGenerator::INLINE);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('Фактура.pdf'), (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testFromTwigWithCyrillicGridAndImage(): void
    {
        $gen = $this->boot()->getContainer()->get(PdfGenerator::class);
        $pdf = $gen->fromTwig('invoice.html.twig', ['number' => '№ 1', 'client' => 'Иван']);
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(1, $pdf->getPageCount());
        $this->assertSame([], $pdf->getWarnings());
    }

    public function testStreamedResponseAndAttachment(): void
    {
        $gen = $this->boot()->getContainer()->get(PdfGenerator::class);
        $response = $gen->toStreamedResponse($gen->getOutputFromHtml('<p>x</p>'), 'a.pdf');
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $this->assertStringStartsWith('%PDF-', (string) ob_get_clean());
    }

    public function testOptionOverridesPerCall(): void
    {
        $gen = $this->boot()->getContainer()->get(PdfGenerator::class);
        $o = $gen->fromHtml('x', ['orientation' => 'landscape'])->getOptions();
        $this->assertSame('landscape', $o->orientation);
    }

    public function testFactoryWithoutTwigThrows(): void
    {
        $gen = new PdfGenerator(new PdfFactory());
        $this->expectException(\LogicException::class);
        $gen->fromTwig('x.twig');
    }
}
