<?php

declare(strict_types=1);

namespace IvoPdf\Symfony;

use IvoPdf\IvoPdf;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Registers the IvoPDF services: PdfFactory, PdfGenerator and a non-shared IvoPdf\IvoPdf (configured per injection).
 */
final class IvoPdfBundle extends AbstractBundle
{
    protected string $extensionAlias = 'ivopdf';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('paper')->defaultValue('A4')->info('A0-A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio')->end()
                ->enumNode('orientation')->values(['portrait', 'landscape'])->defaultValue('portrait')->end()
                ->scalarNode('default_font')->defaultValue('DejaVu Sans')->end()
                ->floatNode('default_font_size')->defaultValue(12)->end()
                ->arrayNode('margins')
                    ->info('Page margins in points')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('top')->defaultValue(36)->end()
                        ->floatNode('right')->defaultValue(36)->end()
                        ->floatNode('bottom')->defaultValue(36)->end()
                        ->floatNode('left')->defaultValue(36)->end()
                    ->end()
                ->end()
                ->scalarNode('base_path')->defaultNull()->info('Folder for relative images/CSS/fonts. Default: %kernel.project_dir%/public')->end()
                ->arrayNode('allowed_paths')
                    ->info('Readable local folders. Default: public/ and templates/')
                    ->scalarPrototype()->end()
                ->end()
                ->booleanNode('remote_enabled')->defaultFalse()->info('Allow http(s) resources (SSRF-protected)')->end()
                ->booleanNode('compress')->defaultTrue()->end()
                ->booleanNode('subset_fonts')->defaultTrue()->end()
                ->booleanNode('bookmarks')->defaultTrue()->end()
                ->booleanNode('form_fields')->defaultTrue()->end()
                ->scalarNode('lang')->defaultValue('')->end()
                ->scalarNode('creator')->defaultValue('IvoPDF')->end()
                ->scalarNode('pdfa')->defaultNull()->info("null (off), '2b' or '3b'")->end()
                ->arrayNode('fonts')
                    ->info("Extra font families: Inter: { regular: '%kernel.project_dir%/assets/Inter.ttf', bold: ... }")
                    ->useAttributeAsKey('family')
                    ->variablePrototype()->end()
                ->end()
                ->arrayNode('fallback_fonts')->scalarPrototype()->end()->end()
                ->arrayNode('options')
                    ->info('Raw IvoPdf\Options keys (dompdf / mPDF names accepted); applied last')
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $projectDir = (string) $builder->getParameter('kernel.project_dir');
        $config['base_path'] ??= $projectDir . '/public';
        if (($config['allowed_paths'] ?? []) === []) {
            $config['allowed_paths'] = [$projectDir . '/public', $projectDir . '/templates'];
        }

        $services = $container->services();
        $services->set(PdfFactory::class)
            ->args([$config])
            ->public();

        $services->set(PdfGenerator::class)
            ->args([new Reference(PdfFactory::class), new Reference('twig', ContainerBuilder::NULL_ON_INVALID_REFERENCE)])
            ->public();

        $services->set(IvoPdf::class)
            ->factory([new Reference(PdfFactory::class), 'create'])
            ->share(false)
            ->public();
    }
}
