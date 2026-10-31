<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Vendor\LocaleJsonMerger\Tests\TestCase;

final class ArtisanCommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/locale-json-artisan-'.uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
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

    public function test_locale_check_command_fails_when_missing_keys(): void
    {
        $master = $this->write('en.json', ['welcome' => 'Welcome', 'bye' => 'Bye']);
        $child = $this->write('fr.json', ['welcome' => 'Bienvenue']);

        $this->artisan('locale:check', [
            'master' => $master,
            'child' => $child,
        ])->assertFailed();
    }

    public function test_locale_check_command_succeeds_when_complete(): void
    {
        $master = $this->write('en.json', ['welcome' => 'Welcome']);
        $child = $this->write('fr.json', ['welcome' => 'Bienvenue']);

        $this->artisan('locale:check', [
            'master' => $master,
            'child' => $child,
        ])->assertSuccessful();
    }

    public function test_locale_check_json_output(): void
    {
        $this->withoutMockingConsoleOutput();

        $master = $this->write('en.json', [
            'auth' => ['login' => ['password' => 'Password']],
        ]);
        $child = $this->write('fr.json', [
            'legacy' => ['title' => 'Old'],
        ]);

        $exitCode = Artisan::call('locale:check', [
            'master' => $master,
            'child' => $child,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(['auth.login.password'], $payload['missing_keys']);
        $this->assertSame(['legacy.title'], $payload['extra_keys']);
        $this->assertSame(1, $payload['missing_count']);
        $this->assertSame(1, $payload['extra_count']);
    }

    public function test_locale_check_no_sort_option(): void
    {
        $this->withoutMockingConsoleOutput();

        $master = $this->write('en.json', [
            'zebra' => 'Z',
            'apple' => 'A',
        ]);
        $child = $this->write('fr.json', []);

        $exitCode = Artisan::call('locale:check', [
            'master' => $master,
            'child' => $child,
            '--json' => true,
            '--no-sort' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['zebra', 'apple'], $payload['missing_keys']);
    }

    public function test_locale_check_invalid_json(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->path('bad.json');
        file_put_contents($child, '{bad');

        $this->artisan('locale:check', [
            'master' => $master,
            'child' => $child,
        ])->assertFailed();
    }

    public function test_locale_merge_requires_output(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
        ])->assertFailed();
    }

    public function test_locale_merge_writes_output(): void
    {
        $master = $this->write('en.json', ['a' => 'A', 'b' => 'B']);
        $child = $this->write('fr.json', ['a' => 'Alpha']);
        $output = $this->path('merged.json');

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);
        $decoded = json_decode((string) file_get_contents($output), true);
        $this->assertSame('Alpha', $decoded['a']);
        $this->assertSame('B', $decoded['b']);
        $this->assertStringEndsWith("\n", (string) file_get_contents($output));
    }

    public function test_locale_merge_dry_run_does_not_write(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $output = $this->path('should-not-exist.json');

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($output);
    }

    public function test_locale_merge_dry_run_without_output(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    public function test_locale_merge_protects_against_overwrite(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $output = $this->write('exists.json', ['old' => true]);

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
        ])->assertFailed();
    }

    public function test_locale_merge_force_overwrite(): void
    {
        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $output = $this->write('exists.json', ['old' => true]);

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
            '--force' => true,
        ])->assertSuccessful();

        $decoded = json_decode((string) file_get_contents($output), true);
        $this->assertSame(['a' => 'A'], $decoded);
    }

    public function test_locale_merge_json_output(): void
    {
        $this->withoutMockingConsoleOutput();

        $master = $this->write('en.json', ['a' => 'A']);
        $child = $this->write('fr.json', []);
        $output = $this->path('out.json');

        $exitCode = Artisan::call('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['success']);
        $this->assertSame($output, $payload['output']);
        $this->assertArrayHasKey('merged', $payload);
        $this->assertSame(['a' => 'A'], $payload['merged']);
    }

    public function test_locale_merge_no_sort_option(): void
    {
        $master = $this->write('en.json', [
            'zebra' => 'Z',
            'apple' => 'A',
        ]);
        $child = $this->write('fr.json', []);
        $output = $this->path('unsorted.json');

        $this->artisan('locale:merge', [
            'master' => $master,
            'child' => $child,
            '--output' => $output,
            '--no-sort' => true,
        ])->assertSuccessful();

        $decoded = json_decode((string) file_get_contents($output), true);
        $this->assertSame(['zebra', 'apple'], array_keys($decoded));
    }
}
