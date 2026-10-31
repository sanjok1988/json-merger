<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vendor\LocaleJsonMerger\LocaleJsonMergerServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LocaleJsonMergerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('locale-json-merger.api.enabled', false);
        $app['config']->set('locale-json-merger.sort_keys', true);
        $app['config']->set('locale-json-merger.allow_overwrite', false);
    }

    protected function tempPath(string $filename): string
    {
        $directory = sys_get_temp_dir().'/locale-json-merger-tests';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory.'/'.$filename;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeTempJson(string $filename, array $data): string
    {
        $path = $this->tempPath($filename);
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        file_put_contents($path, $encoded."\n");

        return $path;
    }
}
