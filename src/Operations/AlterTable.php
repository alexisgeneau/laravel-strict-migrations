<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;

/**
 * A change to a table that only touches its definition and holds its lock for an instant:
 * a default, a comment, a dropped constraint or index on MySQL, a renamed index. The wait
 * for that lock is what the lock timeout bounds.
 */
final readonly class AlterTable implements Operation
{
    public function __construct(
        public string $table,
    ) {}

    public function assess(Context $context): array
    {
        return [];
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
