<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Exceptions\LocaleFileException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;

final class JsonLocaleFileServiceTest extends TestCase
{
    private JsonLocaleFileService $service;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JsonLocaleFileService();
        $this->directory = sys_get_temp_dir().'/locale-json-file-service-'.uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $name, array $data): string
    {
        $path = $this->path($name);
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );

        return $path;
    }

    public function test_check_files_detects_missing_and_extra_keys(): void
    {
        $master = $this->write('en.json', [
            'welcome' => 'Welcome',
            'menu' => ['home' => 'Home'],
        ]);
        $child = $this->write('fr.json', [
            'welcome' => 'Bienvenue',
            'legacy' => 'old',
        ]);

        $result = $this->service->checkFiles($master, $child);

        $this->assertSame(['menu.home'], $result->missingKeys());
        $this->assertSame(['legacy'], $result->extraKeys());
    }

    public function test_merge_files_writes_output(): void
    {
        $master = $this->write('en.json', ['a' => 'A', 'b' => 'B']);
        $child = $this->write('fr.json', ['a' => 'Alpha']);
        $output = $this->path('fr.merged.json');

        $result = $this->service->mergeFiles($master, $child, $output, true, false);

        $this->assertFileExists($output);
        $this->assertSame(['b'], $result->missingKeys());
        $this->assertSame('Alpha', $result->merged()['a']);
        $this->assertSame('B', $result->merged()['b']);
    }

    public function test_invalid_json_throws(): void
    {
        $path = $this->path('bad.json');
        file_put_contents($path, '{invalid');

        $this->expectException(InvalidJsonException::class);
        $this->service->readAssociativeObject($path);
    }

    public function test_missing_file_throws(): void
    {
        $this->expectException(LocaleFileException::class);
        $this->service->readAssociativeObject($this->path('missing.json'));
    }

    public function test_root_must_be_associative_object(): void
    {
        $path = $this->path('list.json');
        file_put_contents($path, '["a","b"]');

        $this->expectException(InvalidJsonException::class);
        $this->service->readAssociativeObject($path);
    }

    public function test_final_newline_and_unicode_output(): void
    {
        $output = $this->path('out.json');
        $this->service->writeJsonFile($output, [
            'ja' => 'こんにちは',
            'path' => 'https://example.com/a/b',
        ], true);

        $contents = file_get_contents($output);
        $this->assertIsString($contents);
        $this->assertStringEndsWith("\n", $contents);
        $this->assertStringContainsString('こんにちは', $contents);
        $this->assertStringContainsString('https://example.com/a/b', $contents);
        $this->assertStringNotContainsString('\\u', $contents);
        $this->assertStringNotContainsString('\\/', $contents);
    }

    public function test_protection_against_accidental_overwrite(): void
    {
        $output = $this->path('exists.json');
        file_put_contents($output, "{}\n");

        $this->expectException(LocaleFileException::class);
        $this->service->writeJsonFile($output, ['a' => 'A'], false);
    }

    public function test_forced_overwrite_behavior(): void
    {
        $output = $this->path('exists.json');
        file_put_contents($output, "{\"old\":true}\n");

        $this->service->writeJsonFile($output, ['new' => true], true);

        $decoded = json_decode((string) file_get_contents($output), true);
        $this->assertSame(['new' => true], $decoded);
    }

    public function test_merge_files_without_output_does_not_write_by_default(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $childBefore = (string) file_get_contents($child);

        $result = $this->service->mergeFiles($master, $child, null, true, false);

        $this->assertSame(['a'], $result->missingKeys());
        $this->assertSame($childBefore, (string) file_get_contents($child));
    }

    public function test_merge_files_can_overwrite_child_when_allowed(): void
    {
        $master = $this->write('en.json', ['a' => 'A', 'b' => 'B']);
        $child = $this->write('fr.json', ['a' => 'Alpha']);

        $this->service->mergeFiles($master, $child, null, true, true);

        $decoded = json_decode((string) file_get_contents($child), true);
        $this->assertSame('Alpha', $decoded['a']);
        $this->assertSame('B', $decoded['b']);
    }

    public function test_creates_output_directory_when_needed(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $output = $this->directory.'/nested/dir/out.json';

        $this->service->mergeFiles($master, $child, $output, true, false);

        $this->assertFileExists($output);
    }
}
