<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Services;

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
    public function merge(array $master, array $child, bool $sort = true): MergeResult
    {
        $masterCopy = $this->deepCopy($master);
        $childCopy = $this->deepCopy($child);

        $result = $this->merger->merge($masterCopy, $childCopy, $sort);

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
}
