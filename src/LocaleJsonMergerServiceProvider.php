<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Vendor\LocaleJsonMerger\Console\Commands\LocaleCheckCommand;
use Vendor\LocaleJsonMerger\Console\Commands\LocaleMergeCommand;
use Vendor\LocaleJsonMerger\Contracts\LocaleTranslator;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;
use Vendor\LocaleJsonMerger\Services\OpenAiLocaleTranslator;
use Vendor\LocaleJsonMerger\Support\RecursiveJsonMerger;

final class LocaleJsonMergerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/locale-json-merger.php',
            'locale-json-merger',
        );

        $this->app->singleton(RecursiveJsonMerger::class);
        $this->app->singleton(LocaleTranslator::class, OpenAiLocaleTranslator::class);
        $this->app->singleton(LocaleJsonMerger::class);
        $this->app->singleton(JsonLocaleFileService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/locale-json-merger.php' => config_path('locale-json-merger.php'),
            ], 'locale-json-merger-config');

            $this->commands([
                LocaleCheckCommand::class,
                LocaleMergeCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if (! (bool) config('locale-json-merger.api.enabled', false)) {
            return;
        }

        Route::group([
            'prefix' => config('locale-json-merger.api.prefix', 'api/locale-json'),
            'middleware' => config('locale-json-merger.api.middleware', ['api']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }
}
