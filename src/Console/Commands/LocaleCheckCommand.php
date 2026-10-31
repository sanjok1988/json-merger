<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Console\Commands;

use Illuminate\Console\Command;
use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Exceptions\LocaleFileException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Throwable;

final class LocaleCheckCommand extends Command
{
    protected $signature = 'locale:check
                            {master : Path to the master (English) locale JSON file}
                            {child : Path to the child locale JSON file}
                            {--json : Output machine-readable JSON only}
                            {--no-sort : Disable alphabetical key sorting}';

    protected $description = 'Compare a child locale JSON file against the English master locale';

    public function handle(JsonLocaleFileService $fileService): int
    {
        $masterPath = (string) $this->argument('master');
        $childPath = (string) $this->argument('child');
        $asJson = (bool) $this->option('json');

        if ($this->option('no-sort')) {
            $sort = false;
        } else {
            $sort = (bool) config('locale-json-merger.sort_keys', true);
        }

        try {
            $result = $fileService->checkFiles($masterPath, $childPath, $sort);
        } catch (LocaleFileException|InvalidJsonException $exception) {
            return $this->failWithError($exception->getMessage(), $asJson);
        } catch (Throwable $exception) {
            return $this->failWithError(
                'Unexpected error while checking locale files: '.$exception->getMessage(),
                $asJson,
            );
        }

        $payload = $result->toArray();

        if ($asJson) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $result->hasMissingKeys() ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Locale check complete');
        $this->line(sprintf('Master: <comment>%s</comment>', $masterPath));
        $this->line(sprintf('Child:  <comment>%s</comment>', $childPath));
        $this->newLine();

        $this->line(sprintf(
            'Missing keys: <fg=%s>%d</>',
            $result->missingCount() > 0 ? 'red' : 'green',
            $result->missingCount(),
        ));

        foreach ($result->missingKeys() as $key) {
            $this->line('  <fg=red>-</> '.$key);
        }

        $this->newLine();
        $this->line(sprintf(
            'Extra keys: <fg=%s>%d</>',
            $result->extraCount() > 0 ? 'yellow' : 'green',
            $result->extraCount(),
        ));

        foreach ($result->extraKeys() as $key) {
            $this->line('  <fg=yellow>+</> '.$key);
        }

        if ($result->typeConflictCount() > 0) {
            $this->newLine();
            $this->line(sprintf('Type conflicts: <fg=magenta>%d</>', $result->typeConflictCount()));

            foreach ($result->typeConflicts() as $conflict) {
                $this->line(sprintf(
                    '  <fg=magenta>!</> %s (master: %s, child: %s)',
                    $conflict['path'],
                    $conflict['master_type'],
                    $conflict['child_type'],
                ));
            }
        }

        $this->newLine();

        if ($result->hasMissingKeys()) {
            $this->error('Child locale is missing keys from the master locale.');

            return self::FAILURE;
        }

        $this->info('Child locale contains all master keys.');

        return self::SUCCESS;
    }

    private function failWithError(string $message, bool $asJson): int
    {
        if ($asJson) {
            $this->line(json_encode([
                'success' => false,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
