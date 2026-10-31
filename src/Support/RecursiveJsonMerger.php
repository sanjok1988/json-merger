<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Support;

/**
 * Framework-agnostic recursive JSON locale merge and comparison logic.
 *
 * Conflict policy:
 * - Master object + child scalar: report type conflict, use master object structure
 *   for nested missing-key detection and merge output (child scalar is not kept
 *   as the value because it cannot satisfy the master object shape).
 * - Master scalar + child object: report type conflict, preserve the child object
 *   so existing data is never silently discarded.
 * - Arrays (list/numeric) are treated as opaque values and are never deep-merged.
 */
final class RecursiveJsonMerger
{
    /**
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     * @return array{
     *     merged: array<string, mixed>,
     *     missing_keys: list<string>,
     *     extra_keys: list<string>,
     *     type_conflicts: list<array{path: string, master_type: string, child_type: string}>
     * }
     */
    public function merge(array $master, array $child, bool $sort = true): array
    {
        $missingKeys = [];
        $extraKeys = [];
        $typeConflicts = [];

        $merged = $this->mergeRecursive(
            $master,
            $child,
            '',
            $missingKeys,
            $extraKeys,
            $typeConflicts,
        );

        if ($sort) {
            $merged = $this->sortKeysRecursive($merged);
            sort($missingKeys, SORT_STRING);
            sort($extraKeys, SORT_STRING);
        }

        return [
            'merged' => $merged,
            'missing_keys' => array_values($missingKeys),
            'extra_keys' => array_values($extraKeys),
            'type_conflicts' => $typeConflicts,
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     * @return array{
     *     missing_keys: list<string>,
     *     extra_keys: list<string>,
     *     type_conflicts: list<array{path: string, master_type: string, child_type: string}>
     * }
     */
    public function check(array $master, array $child, bool $sort = true): array
    {
        $result = $this->merge($master, $child, $sort);

        return [
            'missing_keys' => $result['missing_keys'],
            'extra_keys' => $result['extra_keys'],
            'type_conflicts' => $result['type_conflicts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $child
     * @param  list<string>  $missingKeys
     * @param  list<string>  $extraKeys
     * @param  list<array{path: string, master_type: string, child_type: string}>  $typeConflicts
     * @return array<string, mixed>
     */
    private function mergeRecursive(
        array $master,
        array $child,
        string $prefix,
        array &$missingKeys,
        array &$extraKeys,
        array &$typeConflicts,
    ): array {
        $merged = [];

        foreach ($master as $key => $masterValue) {
            $path = $this->buildPath($prefix, (string) $key);
            $keyExistsInChild = array_key_exists($key, $child);

            if (! $keyExistsInChild) {
                $this->collectMissingPaths($masterValue, $path, $missingKeys);
                $merged[$key] = $this->copyValue($masterValue);

                continue;
            }

            $childValue = $child[$key];
            $masterIsObject = $this->isAssociativeArray($masterValue);
            $childIsObject = $this->isAssociativeArray($childValue);
            $masterIsList = $this->isListArray($masterValue);
            $childIsList = $this->isListArray($childValue);

            if ($masterIsObject && $childIsObject) {
                /** @var array<string, mixed> $masterValue */
                /** @var array<string, mixed> $childValue */
                $merged[$key] = $this->mergeRecursive(
                    $masterValue,
                    $childValue,
                    $path,
                    $missingKeys,
                    $extraKeys,
                    $typeConflicts,
                );

                continue;
            }

            if ($masterIsObject && ! $childIsObject) {
                $typeConflicts[] = [
                    'path' => $path,
                    'master_type' => $this->describeType($masterValue),
                    'child_type' => $this->describeType($childValue),
                ];
                $extraKeys[] = $path;

                /** @var array<string, mixed> $masterValue */
                $this->collectMissingPaths($masterValue, $path, $missingKeys);
                $merged[$key] = $this->copyValue($masterValue);

                continue;
            }

            if (! $masterIsObject && $childIsObject) {
                $typeConflicts[] = [
                    'path' => $path,
                    'master_type' => $this->describeType($masterValue),
                    'child_type' => $this->describeType($childValue),
                ];
                $extraKeys[] = $path;
                // Preserve child object so data is never silently discarded.
                $merged[$key] = $this->copyValue($childValue);

                continue;
            }

            // Both are scalars, null, or list arrays — child wins.
            if ($masterIsList || $childIsList) {
                if ($masterIsList !== $childIsList) {
                    $typeConflicts[] = [
                        'path' => $path,
                        'master_type' => $this->describeType($masterValue),
                        'child_type' => $this->describeType($childValue),
                    ];
                }
            }

            $merged[$key] = $this->copyValue($childValue);
        }

        foreach ($child as $key => $childValue) {
            if (array_key_exists($key, $master)) {
                continue;
            }

            $path = $this->buildPath($prefix, (string) $key);
            $this->collectExtraPaths($childValue, $path, $extraKeys);
            $merged[$key] = $this->copyValue($childValue);
        }

        return $merged;
    }

    /**
     * Collect all leaf (and object) paths that are missing from the child.
     */
    private function collectMissingPaths(mixed $value, string $path, array &$missingKeys): void
    {
        if ($this->isAssociativeArray($value)) {
            /** @var array<string, mixed> $value */
            if ($value === []) {
                $missingKeys[] = $path;

                return;
            }

            foreach ($value as $key => $nested) {
                $this->collectMissingPaths($nested, $this->buildPath($path, (string) $key), $missingKeys);
            }

            return;
        }

        $missingKeys[] = $path;
    }

    /**
     * Collect all leaf (and object) paths that exist only in the child.
     */
    private function collectExtraPaths(mixed $value, string $path, array &$extraKeys): void
    {
        if ($this->isAssociativeArray($value)) {
            /** @var array<string, mixed> $value */
            if ($value === []) {
                $extraKeys[] = $path;

                return;
            }

            foreach ($value as $key => $nested) {
                $this->collectExtraPaths($nested, $this->buildPath($path, (string) $key), $extraKeys);
            }

            return;
        }

        $extraKeys[] = $path;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sortKeysRecursive(array $data): array
    {
        if ($this->isListArray($data)) {
            return $data;
        }

        ksort($data, SORT_STRING);

        foreach ($data as $key => $value) {
            if ($this->isAssociativeArray($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = $this->sortKeysRecursive($value);
            }
        }

        return $data;
    }

    private function buildPath(string $prefix, string $key): string
    {
        return $prefix === '' ? $key : $prefix.'.'.$key;
    }

    /**
     * Deep-copy a value so caller inputs are never mutated.
     */
    private function copyValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $copy = [];

        foreach ($value as $key => $item) {
            $copy[$key] = $this->copyValue($item);
        }

        return $copy;
    }

    /**
     * Associative (object-like) JSON arrays — including empty objects.
     */
    public function isAssociativeArray(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            // Empty arrays decoded from JSON `{}` vs `[]` are ambiguous in PHP.
            // Callers that decode JSON should normalize empty objects; treat empty
            // PHP arrays that arrived as object values as associative via context.
            // Here: empty array is treated as associative object for merge of `{}`.
            return ! array_is_list($value) || $this->wasEmptyObject($value);
        }

        return ! array_is_list($value);
    }

    /**
     * Empty PHP arrays from JSON objects are indistinguishable from empty lists.
     * We treat empty arrays as associative objects (common for locale JSON `{}`).
     *
     * @param  array<mixed>  $value
     */
    private function wasEmptyObject(array $value): bool
    {
        return $value === [];
    }

    /**
     * Numeric / list arrays that must be treated as opaque values.
     */
    public function isListArray(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_is_list($value);
    }

    private function describeType(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        if (is_string($value)) {
            return 'string';
        }

        if ($this->isListArray($value)) {
            return 'array';
        }

        if ($this->isAssociativeArray($value)) {
            return 'object';
        }

        return get_debug_type($value);
    }
}
