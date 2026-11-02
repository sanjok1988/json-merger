<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Services;

use Vendor\LocaleJsonMerger\Contracts\LocaleTranslator;
use Vendor\LocaleJsonMerger\Data\MergeCheckResult;
use Vendor\LocaleJsonMerger\Data\MergeResult;
use Vendor\LocaleJsonMerger\Support\RecursiveJsonMerger;

/**
 * Compare and merge locale JSON structures using English (master) as the source of truth.
 */
final class LocaleJsonMerger
{
    public function __construct(
        private readonly RecursiveJsonMerger $merger = new RecursiveJsonMerger(),
        private readonly ?LocaleTranslator $translator = null,
    ) {
    }

    /**
     * Compare master and child locale arrays without mutating either input.
     *
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     */
    public function check(array $master, array $child, bool $sort = true): MergeCheckResult
    {
        $masterCopy = $this->deepCopy($master);
        $childCopy = $this->deepCopy($child);

        $result = $this->merger->check($masterCopy, $childCopy, $sort);

        return new MergeCheckResult(
            $result['missing_keys'],
            $result['extra_keys'],
            $result['type_conflicts'],
        );
    }

    /**
     * Merge master keys into the child locale, preserving existing child translations.
     *
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     */
    public function merge(
        array $master,
        array $child,
        bool $sort = true,
        ?string $targetLanguage = null,
    ): MergeResult
    {
        $masterCopy = $this->deepCopy($master);
        $childCopy = $this->deepCopy($child);

        $result = $this->merger->merge($masterCopy, $childCopy, $sort);

        if ($targetLanguage !== null && $targetLanguage !== '') {
            if ($this->translator === null) {
                throw new \RuntimeException('No locale translator is configured.');
            }

            $result['merged'] = $this->translateMixedValues(
                $masterCopy,
                $childCopy,
                $result['merged'],
                $targetLanguage,
            );
        }

        return new MergeResult(
            $result['merged'],
            $result['missing_keys'],
            $result['extra_keys'],
            $result['type_conflicts'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deepCopy(array $data): array
    {
        $copy = [];

        foreach ($data as $key => $value) {
            $copy[$key] = is_array($value) ? $this->deepCopy($value) : $value;
        }

        return $copy;
    }

    /**
    * Translate only child values that still equal English.
    * Existing non-English child translations are never sent to the model.
     *
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private function translateMixedValues(
        array $master,
        array $child,
        array $merged,
        string $targetLanguage,
        string $prefix = '',
    ): array {
        foreach ($master as $key => $masterValue) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! array_key_exists($key, $child)) {
                continue;
            }

            if ($this->isAssociativeArray($masterValue) && $this->isAssociativeArray($child[$key])) {
                /** @var array<string, mixed> $masterValue */
                /** @var array<string, mixed> $childValue */
                /** @var array<string, mixed> $mergedValue */
                $childValue = $child[$key];
                $mergedValue = $merged[$key];
                $merged[$key] = $this->translateMixedValues(
                    $masterValue,
                    $childValue,
                    $mergedValue,
                    $targetLanguage,
                    $path,
                );

                continue;
            }

            if (is_string($masterValue) && $masterValue === $child[$key]) {
                $merged[$key] = $this->translator?->translate($masterValue, $targetLanguage, $path);
            }

        }

        return $merged;
    }

    private function isAssociativeArray(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}
