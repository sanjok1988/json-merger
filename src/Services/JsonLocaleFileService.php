<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Services;

use JsonException;
use Vendor\LocaleJsonMerger\Data\MergeCheckResult;
use Vendor\LocaleJsonMerger\Data\MergeResult;
use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Exceptions\LocaleFileException;

/**
 * Read, check, merge, and write locale JSON files safely.
 */
final class JsonLocaleFileService
{
    public function __construct(
        private readonly LocaleJsonMerger $merger = new LocaleJsonMerger(),
    ) {
    }

    /**
     * Compare two locale JSON files.
     *
     * @throws LocaleFileException
     * @throws InvalidJsonException
     */
    public function checkFiles(
        string $masterPath,
        string $childPath,
        bool $sort = true,
    ): MergeCheckResult {
        $master = $this->readAssociativeObject($masterPath);
        $child = $this->readAssociativeObject($childPath);

        return $this->merger->check($master, $child, $sort);
    }

    /**
     * Merge two locale JSON files and optionally write the result.
     *
     * When $outputPath is null, the merged data is returned without writing
     * unless $allowOverwrite is true — in which case the child file is updated.
     *
     * @throws LocaleFileException
     * @throws InvalidJsonException
     */
    public function mergeFiles(
        string $masterPath,
        string $childPath,
        ?string $outputPath = null,
        bool $sort = true,
        bool $allowOverwrite = false,
        ?string $targetLanguage = null,
    ): MergeResult {
        $master = $this->readAssociativeObject($masterPath);
        $child = $this->readAssociativeObject($childPath);

        $result = $this->merger->merge($master, $child, $sort, $targetLanguage);

        $targetPath = $outputPath ?? ($allowOverwrite ? $childPath : null);

        if ($targetPath === null) {
            return $result;
        }

        $this->writeJsonFile($targetPath, $result->merged(), $allowOverwrite);

        return $result;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LocaleFileException
     * @throws InvalidJsonException
     */
    public function readAssociativeObject(string $path): array
    {
        if (! is_file($path)) {
            throw LocaleFileException::notFound($path);
        }

        if (! is_readable($path)) {
            throw LocaleFileException::notReadable($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw LocaleFileException::notReadable($path);
        }

        return $this->decodeAssociativeObject($contents, $path);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidJsonException
     */
    public function decodeAssociativeObject(string $json, ?string $path = null): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($path !== null) {
                throw InvalidJsonException::forPath($path, $exception->getMessage());
            }

            throw InvalidJsonException::forPayload($exception->getMessage());
        }

        if (! is_array($decoded) || $this->isListArray($decoded)) {
            throw InvalidJsonException::rootMustBeObject($path);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LocaleFileException
     * @throws InvalidJsonException
     */
    public function writeJsonFile(
        string $path,
        array $data,
        bool $allowOverwrite = false,
        ?array $jsonOptions = null,
    ): void {
        if (is_file($path) && ! $allowOverwrite) {
            throw LocaleFileException::alreadyExists($path);
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw LocaleFileException::cannotCreateDirectory($directory);
            }
        }

        $flags = $this->resolveEncodeFlags($jsonOptions);
        $finalNewline = $jsonOptions['final_newline'] ?? true;

        try {
            $encoded = json_encode($data, $flags);
        } catch (JsonException $exception) {
            throw InvalidJsonException::forPath($path, $exception->getMessage());
        }

        if (! is_string($encoded)) {
            throw InvalidJsonException::forPath($path, 'Failed to encode JSON.');
        }

        if ($finalNewline) {
            $encoded .= "\n";
        }

        $written = file_put_contents($path, $encoded, LOCK_EX);

        if ($written === false) {
            throw LocaleFileException::notWritable($path);
        }
    }

    /**
     * @param  array<string, mixed>|null  $jsonOptions
     */
    public function resolveEncodeFlags(?array $jsonOptions = null): int
    {
        $options = $jsonOptions ?? [
            'pretty_print' => true,
            'unescaped_unicode' => true,
            'unescaped_slashes' => true,
        ];

        $flags = JSON_THROW_ON_ERROR;

        if ($options['pretty_print'] ?? true) {
            $flags |= JSON_PRETTY_PRINT;
        }

        if ($options['unescaped_unicode'] ?? true) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        if ($options['unescaped_slashes'] ?? true) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }

        return $flags;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isListArray(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }
}
