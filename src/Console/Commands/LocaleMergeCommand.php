<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Console\Commands;

use Illuminate\Console\Command;
use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Exceptions\LocaleFileException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;
use Throwable;

final class LocaleMergeCommand extends Command
{
    protected $signature = 'locale:merge
                            {master : Path to the master (English) locale JSON file}
                            {child : Path to the child locale JSON file}
                            {--output= : Path to write the merged JSON file (required unless --dry-run)}
                            {--force : Allow overwriting an existing output file}
                            {--dry-run : Merge and report without writing a file}
                            {--json : Output machine-readable JSON only}
                            {--no-sort : Disable alphabetical key sorting}';

    protected $description = 'Merge missing master locale keys into a child locale JSON file';

    public function handle(
        JsonLocaleFileService $fileService,
        LocaleJsonMerger $merger,
    ): int {
        $masterPath = (string) $this->argument('master');
        $childPath = (string) $this->argument('child');
        $outputPath = $this->option('output');
        $outputPath = is_string($outputPath) && $outputPath !== '' ? $outputPath : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');
        $allowOverwrite = $force || (bool) config('locale-json-merger.allow_overwrite', false);

        if ($this->option('no-sort')) {
            $sort = false;
        } else {
            $sort = (bool) config('locale-json-merger.sort_keys', true);
        }

        if (! $dryRun && $outputPath === null) {
            return $this->failWithError(
                'The --output option is required unless --dry-run is used.',
                $asJson,
            );
        }

        try {
            $master = $fileService->readAssociativeObject($masterPath);
            $child = $fileService->readAssociativeObject($childPath);
            $result = $merger->merge($master, $child, $sort);
        } catch (LocaleFileException|InvalidJsonException $exception) {
            return $this->failWithError($exception->getMessage(), $asJson);
        } catch (Throwable $exception) {
            return $this->failWithError(
                'Unexpected error while merging locale files: '.$exception->getMessage(),
                $asJson,
            );
        }

        if ($dryRun) {
            $payload = [
                'success' => true,
                'dry_run' => true,
                'output' => $outputPath,
                'missing_keys' => $result->missingKeys(),
                'extra_keys' => $result->extraKeys(),
                'missing_count' => $result->missingCount(),
                'extra_count' => $result->extraCount(),
                'type_conflicts' => $result->typeConflicts(),
                'type_conflict_count' => $result->typeConflictCount(),
                'merged' => $result->merged(),
            ];

            if ($asJson) {
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }

            $this->printSummary($masterPath, $childPath, $result->toArray(), null, true);
            $this->info('Dry run complete. No files were written.');

            return self::SUCCESS;
        }

        try {
            $fileService->writeJsonFile(
                (string) $outputPath,
                $result->merged(),
                $allowOverwrite,
                config('locale-json-merger.json'),
            );
        } catch (LocaleFileException|InvalidJsonException $exception) {
            return $this->failWithError($exception->getMessage(), $asJson);
        } catch (Throwable $exception) {
            return $this->failWithError(
                'Unexpected error while writing merged locale file: '.$exception->getMessage(),
                $asJson,
            );
        }

        $payload = [
            'success' => true,
            'output' => $outputPath,
            'missing_keys' => $result->missingKeys(),
            'extra_keys' => $result->extraKeys(),
            'missing_count' => $result->missingCount(),
            'extra_count' => $result->extraCount(),
            'type_conflicts' => $result->typeConflicts(),
            'type_conflict_count' => $result->typeConflictCount(),
            'merged' => $result->merged(),
        ];

        if ($asJson) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->printSummary($masterPath, $childPath, $result->toArray(), $outputPath, false);
        $this->info(sprintf('Merged locale written to: %s', $outputPath));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printSummary(
        string $masterPath,
        string $childPath,
        array $result,
        ?string $outputPath,
        bool $dryRun,
    ): void {
        $this->info($dryRun ? 'Locale merge dry run' : 'Locale merge summary');
        $this->line(sprintf('Master: <comment>%s</comment>', $masterPath));
        $this->line(sprintf('Child:  <comment>%s</comment>', $childPath));

        if ($outputPath !== null) {
            $this->line(sprintf('Output: <comment>%s</comment>', $outputPath));
        }

        $this->newLine();
        $this->line(sprintf('Missing keys that will be added: <fg=cyan>%d</>', $result['missing_count']));

        foreach ($result['missing_keys'] as $key) {
            $this->line('  <fg=cyan>+</> '.$key);
        }

        $this->newLine();
        $this->line(sprintf('Extra child keys preserved: <fg=yellow>%d</>', $result['extra_count']));

        foreach ($result['extra_keys'] as $key) {
            $this->line('  <fg=yellow>~</> '.$key);
        }

        if (($result['type_conflict_count'] ?? 0) > 0) {
            $this->newLine();
            $this->line(sprintf('Type conflicts: <fg=magenta>%d</>', $result['type_conflict_count']));

            foreach ($result['type_conflicts'] as $conflict) {
                $this->line(sprintf(
                    '  <fg=magenta>!</> %s (master: %s, child: %s)',
                    $conflict['path'],
                    $conflict['master_type'],
                    $conflict['child_type'],
                ));
            }
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function failWithError(string $message, bool $asJson): int
    {
        if ($asJson) {
            $this->line($this->encode([
                'success' => false,
                'error' => $message,
            ]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
