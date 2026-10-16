<?php

declare(strict_types=1);

namespace IvoPdf\Laravel\Tests;

use IvoPdf\Laravel\Facades\Pdf;
use IvoPdf\Laravel\IvoPdfServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [IvoPdfServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Pdf' => Pdf::class, 'PDF' => Pdf::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/views']);
        $app['config']->set('ivopdf.base_path', __DIR__ . '/public');
        $app['config']->set('ivopdf.allowed_paths', [__DIR__ . '/public']);
    }
}
