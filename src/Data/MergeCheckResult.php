<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Data;

/**
 * Result of comparing a master locale against a child locale.
 */
final class MergeCheckResult
{
    /**
     * @param  list<string>  $missingKeys  Dot-notation paths missing from the child.
     * @param  list<string>  $extraKeys  Dot-notation paths present only in the child.
     * @param  list<array{path: string, master_type: string, child_type: string}>  $typeConflicts
     */
    public function __construct(
        private readonly array $missingKeys,
        private readonly array $extraKeys,
        private readonly array $typeConflicts = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return $this->missingKeys;
    }

    /**
     * @return list<string>
     */
    public function extraKeys(): array
    {
        return $this->extraKeys;
    }

    public function missingCount(): int
    {
        return count($this->missingKeys);
    }

    public function extraCount(): int
    {
        return count($this->extraKeys);
    }

    /**
     * @return list<array{path: string, master_type: string, child_type: string}>
     */
    public function typeConflicts(): array
    {
        return $this->typeConflicts;
    }

    public function typeConflictCount(): int
    {
        return count($this->typeConflicts);
    }

    public function hasMissingKeys(): bool
    {
        return $this->missingCount() > 0;
    }

    /**
     * @return array{
     *     missing_keys: list<string>,
     *     extra_keys: list<string>,
     *     missing_count: int,
     *     extra_count: int,
     *     type_conflicts: list<array{path: string, master_type: string, child_type: string}>,
     *     type_conflict_count: int
     * }
     */
    public function toArray(): array
    {
        return [
            'missing_keys' => $this->missingKeys,
            'extra_keys' => $this->extraKeys,
            'missing_count' => $this->missingCount(),
            'extra_count' => $this->extraCount(),
            'type_conflicts' => $this->typeConflicts,
            'type_conflict_count' => $this->typeConflictCount(),
        ];
    }
}
