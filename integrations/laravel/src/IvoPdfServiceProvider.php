<?php

declare(strict_types=1);

namespace IvoPdf\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use IvoPdf\IvoPdf;

class IvoPdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ivopdf.php', 'ivopdf');

        // New wrapper per resolution: every PDF starts from the configured defaults.
        $this->app->bind('ivopdf.wrapper', static function (Application $app): PdfWrapper {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('ivopdf', []);
            return new PdfWrapper($config, $app);
        });
        $this->app->alias('ivopdf.wrapper', PdfWrapper::class);

        // Plain engine (config applied) for code that wants the core API directly.
        $this->app->bind(IvoPdf::class, static fn (Application $app): IvoPdf => $app->make('ivopdf.wrapper')->getEngine());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/ivopdf.php' => config_path('ivopdf.php')], 'ivopdf-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return ['ivopdf.wrapper', PdfWrapper::class, IvoPdf::class];
    }
}
