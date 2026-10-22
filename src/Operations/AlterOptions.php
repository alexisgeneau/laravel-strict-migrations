<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

/** The ALGORITHM and LOCK clauses of a MySQL ALTER TABLE, which make it fail rather than block. */
final readonly class AlterOptions
{
    public function __construct(
        public ?string $algorithm = null,
        public ?string $lock = null,
    ) {}

    /** Whether MySQL will refuse the statement instead of blocking writes while it runs. */
    public function keepsWrites(): bool
    {
        return $this->lock === 'none' || $this->algorithm === 'instant';
    }
}
